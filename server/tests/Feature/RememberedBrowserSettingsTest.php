<?php

namespace Tests\Feature;

use App\Auth\RememberedBrowser;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Card#12186 — the "Remembered browsers" section of the recovery codes page (`two-factor.codes`): a
 * user lists their own remembered browsers and forgets one, or all, without changing their password.
 *
 * ⛔ "FORGOTTEN" IS OBSERVED THROUGH FORTIFY'S REAL LOGIN ROUTE, as `RememberedBrowserTest` does for
 * every other revocation: the password POST from the forgotten browser is answered with the
 * challenge, and the arm's control is a second remembered browser that still skips it.
 *
 * Rows a test only needs to LIST are written by `RememberedBrowser::issue()` directly — the one
 * writer, so the row is the shape a sign-in writes — which keeps each arm inside the `login`
 * limiter's five password POSTs a minute. The label captured at a real sign-in has its own arm.
 */
class RememberedBrowserSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private function enrolled(string $email = 'ops@example.com'): User
    {
        return User::factory()->twoFactorConfirmed()->create(['email' => $email]);
    }

    /** Write a remembered-browser row for `$user` and return the token its browser would hold. */
    private function remember(User $user, ?string $userAgent): string
    {
        return (string) RememberedBrowser::issue($user, $userAgent)->getValue();
    }

    /** @return list<int> */
    private function idsFor(User $user): array
    {
        return DB::table(RememberedBrowser::TABLE)->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
    }

    private function idFor(string $token): int
    {
        return (int) DB::table(RememberedBrowser::TABLE)->where('token_hash', hash('sha256', $token))->value('id');
    }

    private function signedInAndConfirmed(User $user): void
    {
        $this->actingAs($user);
        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();
    }

    private function signOut(): void
    {
        if ($this->isAuthenticated()) {
            $this->post(route('logout'));
        }

        $this->assertGuest();
    }

    private function postPassword(User $user, string $cookie): TestResponse
    {
        $this->signOut();
        $this->withCookie(RememberedBrowser::COOKIE, $cookie);

        return $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()]);
    }

    private function assertChallenged(User $user, string $cookie, string $why): void
    {
        $response = $this->postPassword($user, $cookie);

        $this->assertSame(route('two-factor.login'), $response->headers->get('Location'), $why);
        $this->assertGuest();
    }

    private function assertSkipped(User $user, string $cookie, string $why): void
    {
        $response = $this->postPassword($user, $cookie);

        $this->assertSame(url('/dashboard'), $response->headers->get('Location'), $why);
        $this->assertAuthenticatedAs($user);
    }

    // ── The label, captured at remember time ────────────────────────────────────────────────

    public function test_a_real_sign_in_with_the_box_ticked_stores_a_label_and_never_the_user_agent(): void
    {
        $user = $this->enrolled();
        $this->withHeader('User-Agent', self::FIREFOX_LINUX);

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));
        $engine = app(Google2FA::class);
        $code = $engine->oathTotp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret), $engine->getTimestamp());
        $this->post('/two-factor-challenge', ['code' => $code, RememberedBrowser::FIELD => '1'])->assertRedirect('/dashboard');

        $row = (array) DB::table(RememberedBrowser::TABLE)->where('user_id', $user->id)->sole();
        $this->assertSame('Firefox on Linux', $row['label']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString('Gecko', (string) $value, "the raw user agent is in no column ({$column})");
        }
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function userAgents(): array
    {
        return [
            'Firefox on Linux' => [self::FIREFOX_LINUX, 'Firefox on Linux'],
            'Safari on an iPhone' => [self::SAFARI_IPHONE, 'Safari on iOS'],
            'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome on Windows'],
            'Edge on Windows, which also says Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge on Windows'],
            'Chrome on Android, which also says Linux' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome on Android'],
            'Safari on a Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari on macOS'],
            'a browser with no known name on Linux' => ['SomeBrowser/1.0 (X11; Linux x86_64)', 'A browser on Linux'],
            'a known browser on no known system' => ['Firefox/132.0', 'Firefox'],
            'text that names nothing known' => ['curl/8.5.0', null],
            'markup in the header is never echoed' => ['<script>alert(1)</script>', null],
            'no header at all' => [null, null],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_the_label_is_drawn_from_a_closed_vocabulary(?string $userAgent, ?string $label): void
    {
        $this->assertSame($label, RememberedBrowser::label($userAgent));
    }

    // ── The list ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_lists_the_users_own_live_browsers_with_label_dates_and_this_browser_marked(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
        $user = $this->enrolled();
        $other = $this->enrolled('other@example.com');
        $expired = $this->remember($user, self::FIREFOX_LINUX);
        // A row from before the label column: what the upgrade leaves every existing row as.
        $legacy = $this->remember($user, self::FIREFOX_LINUX);
        DB::table(RememberedBrowser::TABLE)->where('id', $this->idFor($legacy))->update(['label' => null]);
        $this->travel(1)->days();
        $phone = $this->remember($user, self::SAFARI_IPHONE);
        $this->remember($other, 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0');
        // Expired by its own row, after the last `issue()`, which deletes the account's expired rows.
        DB::table(RememberedBrowser::TABLE)->where('id', $this->idFor($expired))->update(['expires_at' => now()->subMinute()]);
        $this->assertCount(3, $this->idsFor($user), 'the control: the expired row is still in the table');

        $this->signedInAndConfirmed($user);
        $html = $this->withCookie(RememberedBrowser::COOKIE, $phone)
            ->get(route('two-factor.codes'))->assertOk()->getContent();

        $this->assertStringContainsString('<h2>Remembered browsers</h2>', $html);
        $this->assertStringContainsString('Safari on iOS', $html);
        $this->assertStringContainsString('(this browser)', $html);
        $this->assertStringContainsString('Browser remembered on 1 November 2026', $html, 'a row from before labels reads neutrally');
        $this->assertStringContainsString('2026-12-02T09:00:00', $html, 'the phone row shows when it expires');
        $this->assertStringNotContainsString('Edge on Windows', $html, 'another account\'s browser is not listed');
        $this->assertStringNotContainsString('Firefox on Linux', $html, 'an expired browser is not listed');
        $this->assertSame(1, substr_count($html, '(this browser)'));
        $this->assertStringContainsString(route('two-factor.browsers.forget', $this->idFor($phone)), $html);
        $this->assertStringContainsString(route('two-factor.browsers.forget', $this->idFor($legacy)), $html);
        $this->assertStringNotContainsString(route('two-factor.browsers.forget', $this->idFor($expired)).'"', $html);
    }

    public function test_an_account_with_no_remembered_browser_is_told_so_and_offered_no_forget_all(): void
    {
        $this->signedInAndConfirmed($this->enrolled());

        $this->get(route('two-factor.codes'))->assertOk()
            ->assertSee('No browser is remembered for your account.')
            ->assertDontSee(route('two-factor.browsers.forget-all'));
    }

    public function test_neither_the_token_nor_its_hash_reaches_the_page_a_response_or_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $user = $this->enrolled();
        $first = $this->remember($user, self::FIREFOX_LINUX);
        $second = $this->remember($user, self::SAFARI_IPHONE);
        $secrets = [$first, hash('sha256', $first), $second, hash('sha256', $second)];

        $this->signedInAndConfirmed($user);
        $bodies = [
            $this->withCookie(RememberedBrowser::COOKIE, $first)->get(route('two-factor.codes'))->assertOk()->getContent(),
        ];
        $forget = $this->delete(route('two-factor.browsers.forget', $this->idFor($first)));
        $bodies[] = $forget->getContent().json_encode($forget->headers->all());
        $bodies[] = $this->get(route('two-factor.codes'))->assertOk()->getContent();
        $all = $this->delete(route('two-factor.browsers.forget-all'));
        $bodies[] = $all->getContent().json_encode($all->headers->all());
        $bodies[] = $this->get(route('two-factor.codes'))->assertOk()->getContent();

        foreach ($secrets as $secret) {
            foreach ($bodies as $i => $body) {
                $this->assertStringNotContainsString($secret, $body, "response {$i}");
            }
            foreach ($logged as $line) {
                $this->assertStringNotContainsString($secret, $line, 'a log line');
            }
        }
        $this->assertSame([], $this->idsFor($user), 'the control: both forgets ran');
    }

    // ── Forget one ──────────────────────────────────────────────────────────────────────────

    public function test_forgetting_one_browser_challenges_it_at_its_next_sign_in_and_keeps_the_others(): void
    {
        $user = $this->enrolled();
        $lost = $this->remember($user, self::FIREFOX_LINUX);
        $kept = $this->remember($user, self::SAFARI_IPHONE);

        $this->signedInAndConfirmed($user);
        $this->delete(route('two-factor.browsers.forget', $this->idFor($lost)))
            ->assertRedirect(route('two-factor.codes'))
            ->assertSessionHas('status', 'Forgot Firefox on Linux. It is asked for a code at its next sign-in.');
        $this->assertAuthenticatedAs($user); // forgetting a browser leaves this session signed in

        $this->assertSame([$this->idFor($kept)], $this->idsFor($user));
        $this->assertChallenged($user, $lost, 'the forgotten browser is asked for the code');
        $this->assertSkipped($user, $kept, 'the control: the browser that was not forgotten still skips it');
    }

    public function test_forging_another_accounts_row_id_is_not_found_and_deletes_nothing(): void
    {
        $victim = $this->enrolled('victim@example.com');
        $victimToken = $this->remember($victim, self::FIREFOX_LINUX);
        $attacker = $this->enrolled('attacker@example.com');
        $this->assertSame(User::OPERATOR, $attacker->role, 'the forger is an operator: the admin role grants nothing here');
        $own = $this->remember($attacker, self::SAFARI_IPHONE);

        $this->signedInAndConfirmed($attacker);
        $this->delete(route('two-factor.browsers.forget', $this->idFor($victimToken)))->assertNotFound();

        $this->assertSame([$this->idFor($victimToken)], $this->idsFor($victim), 'the other account\'s row survives');
        $this->assertSame([$this->idFor($own)], $this->idsFor($attacker), 'and so does the forger\'s own');
        $this->assertSkipped($victim, $victimToken, 'the other account\'s browser still skips the code');
    }

    public function test_forgetting_an_already_forgotten_browser_from_a_stale_page_is_harmless(): void
    {
        $user = $this->enrolled();
        $gone = $this->remember($user, self::FIREFOX_LINUX);
        $kept = $this->remember($user, self::SAFARI_IPHONE);
        $goneId = $this->idFor($gone);

        $this->signedInAndConfirmed($user);
        $this->delete(route('two-factor.browsers.forget', $goneId))->assertRedirect(route('two-factor.codes'));
        $this->delete(route('two-factor.browsers.forget', $goneId))->assertNotFound();

        $this->assertSame([$this->idFor($kept)], $this->idsFor($user), 'the second press deleted nothing');
        $this->assertAuthenticatedAs($user);
        $this->get(route('two-factor.codes'))->assertOk();
    }

    // ── Forget all ──────────────────────────────────────────────────────────────────────────

    public function test_forget_all_forgets_every_browser_this_one_included_and_keeps_this_session_signed_in(): void
    {
        $user = $this->enrolled();
        $other = $this->enrolled('other@example.com');
        $here = $this->remember($user, self::FIREFOX_LINUX);
        $there = $this->remember($user, self::SAFARI_IPHONE);
        $othersToken = $this->remember($other, self::FIREFOX_LINUX);

        $this->signedInAndConfirmed($user);
        $this->withCookie(RememberedBrowser::COOKIE, $here)
            ->delete(route('two-factor.browsers.forget-all'))
            ->assertRedirect(route('two-factor.codes'))
            ->assertSessionHas('status');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->assertSame([], $this->idsFor($user));
        $this->assertSame([$this->idFor($othersToken)], $this->idsFor($other), 'another account keeps its browser');

        $this->assertChallenged($user, $here, 'this browser is asked for the code at its next sign-in too');
        $this->assertChallenged($user, $there, 'and so is every other');
    }

    // ── The gates ───────────────────────────────────────────────────────────────────────────

    public function test_an_unauthenticated_request_reaches_neither_forget_route(): void
    {
        $user = $this->enrolled();
        $token = $this->remember($user, self::FIREFOX_LINUX);

        $this->delete(route('two-factor.browsers.forget', $this->idFor($token)))->assertRedirect(route('login'));
        $this->delete(route('two-factor.browsers.forget-all'))->assertRedirect(route('login'));

        $this->assertSame([$this->idFor($token)], $this->idsFor($user));
    }

    public function test_a_session_waiting_for_its_code_reaches_neither_forget_route(): void
    {
        $user = $this->enrolled();
        $token = $this->remember($user, self::FIREFOX_LINUX);
        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));

        $this->delete(route('two-factor.browsers.forget', $this->idFor($token)))->assertRedirect(route('login'));
        $this->delete(route('two-factor.browsers.forget-all'))->assertRedirect(route('login'));
        $this->get(route('two-factor.codes'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame([$this->idFor($token)], $this->idsFor($user));
    }

    public function test_an_account_without_a_confirmed_second_factor_is_sent_to_enrol(): void
    {
        $user = User::factory()->twoFactorUnenrolled()->create();
        $token = $this->remember($user, self::FIREFOX_LINUX);

        $this->actingAs($user)
            ->delete(route('two-factor.browsers.forget', $this->idFor($token)))
            ->assertRedirect(route('two-factor.enroll'));
        $this->delete(route('two-factor.browsers.forget-all'))->assertRedirect(route('two-factor.enroll'));

        $this->assertSame([$this->idFor($token)], $this->idsFor($user));
    }

    public function test_a_session_that_has_not_re_proved_the_password_is_sent_to_confirm_it(): void
    {
        $user = $this->enrolled();
        $token = $this->remember($user, self::FIREFOX_LINUX);

        $this->actingAs($user)
            ->delete(route('two-factor.browsers.forget', $this->idFor($token)))
            ->assertRedirect(route('password.confirm'));
        $this->delete(route('two-factor.browsers.forget-all'))->assertRedirect(route('password.confirm'));

        $this->assertSame([$this->idFor($token)], $this->idsFor($user));
    }

    public function test_a_get_to_either_forget_route_deletes_nothing(): void
    {
        $user = $this->enrolled();
        $token = $this->remember($user, self::FIREFOX_LINUX);

        $this->signedInAndConfirmed($user);
        $this->get(route('two-factor.browsers.forget', $this->idFor($token)))->assertMethodNotAllowed();
        $this->get(route('two-factor.browsers.forget-all'))->assertMethodNotAllowed();

        $this->assertSame([$this->idFor($token)], $this->idsFor($user));
    }

    /**
     * Laravel's CSRF middleware stands down under unit tests (`PreventRequestForgery::runningUnitTests()`),
     * so this arm puts the real check back by binding a subclass that does not.
     */
    public function test_a_forget_without_the_csrf_token_is_refused_and_deletes_nothing(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $user = $this->enrolled();
        $first = $this->remember($user, self::FIREFOX_LINUX);
        $second = $this->remember($user, self::SAFARI_IPHONE);

        $this->actingAs($user)->withSession(['_token' => 'the-session-token', 'auth.password_confirmed_at' => time()]);

        $this->delete(route('two-factor.browsers.forget', $this->idFor($first)))->assertStatus(419);
        $this->delete(route('two-factor.browsers.forget-all'))->assertStatus(419);
        $this->delete(route('two-factor.browsers.forget-all'), ['_token' => 'a-forged-token'])->assertStatus(419);
        $this->assertSame([$this->idFor($first), $this->idFor($second)], $this->idsFor($user));

        // THE CONTROL: the same request with the session's token goes through, so the refusals above
        // were the CSRF check.
        $this->delete(route('two-factor.browsers.forget', $this->idFor($first)), ['_token' => 'the-session-token'])
            ->assertRedirect(route('two-factor.codes'));
        $this->assertSame([$this->idFor($second)], $this->idsFor($user));
    }

    public function test_the_dashboard_link_names_the_remembered_browsers(): void
    {
        $this->actingAs($this->enrolled())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('remembered browsers')
            ->assertSee(route('two-factor.codes'));
    }
}

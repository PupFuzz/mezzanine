<?php

namespace Tests\Feature;

use App\Admin\UserProvisioning;
use App\Admin\UserRetirement;
use App\Auth\RememberedBrowser;
use App\Models\User;
use App\Notifications\TwoFactorResetCode;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Card#12179 — "Remember this browser for 30 days" on the two-factor code page.
 *
 * ⛔ EVERY SKIP IS OBSERVED THROUGH FORTIFY'S REAL LOGIN ROUTE, never `actingAs()`, because the
 * decision under test lives in the login PIPELINE (`App\Auth\RedirectIfTwoFactorAuthenticatable`).
 * "Challenged" means the password POST redirects to `two-factor.login` and leaves the browser a
 * guest; "skipped" means it lands on the dashboard signed in. Each refusal arm first shows the same
 * browser being remembered, so a refusal can only mean what the arm says it means.
 *
 * ⚠ ONE-TIME CODES: Fortify refuses a code it has already accepted, so an arm that enters a second
 * code from the same secret takes the next period's (`otp(..., 1)`), as
 * `TwoFactorMoveAuthenticatorTest` does.
 */
class RememberedBrowserTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(string $email = 'ops@example.com'): User
    {
        return User::factory()->twoFactorConfirmed()->create(['email' => $email]);
    }

    private function otp(User $user, int $periodsAhead = 0): string
    {
        $engine = app(Google2FA::class);
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

        return $engine->oathTotp($secret, $engine->getTimestamp() + $periodsAhead);
    }

    private function signOut(): void
    {
        // Only when signed in: a guest's POST to `logout` is refused by `auth`, which stores the
        // previous URL as the intended one and so moves where the next sign-in lands.
        if ($this->isAuthenticated()) {
            $this->post(route('logout'));
        }

        $this->assertGuest();
    }

    private function postPassword(User $user, ?string $cookie): TestResponse
    {
        $this->signOut();

        if ($cookie !== null) {
            $this->withCookie(RememberedBrowser::COOKIE, $cookie);
        }

        return $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()]);
    }

    private function assertChallenged(User $user, ?string $cookie, string $why): void
    {
        $response = $this->postPassword($user, $cookie);

        $this->assertSame(route('two-factor.login'), $response->headers->get('Location'), $why);
        $this->assertGuest();
    }

    private function assertSkipped(User $user, string $cookie): void
    {
        $this->postPassword($user, $cookie)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    /**
     * Sign in through the password and the code, with the box ticked or not. Returns the response to
     * the accepted challenge.
     *
     * @param  array<string, string>  $factor  `['code' => …]` or `['recovery_code' => …]`
     */
    private function passTheChallenge(User $user, array $factor, bool $ticked): TestResponse
    {
        $this->postPassword($user, null)->assertRedirect(route('two-factor.login'));

        $response = $this->post('/two-factor-challenge', $factor + ($ticked ? [RememberedBrowser::FIELD => '1'] : []));
        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        return $response;
    }

    private function rememberedCookie(TestResponse $response): ?Cookie
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === RememberedBrowser::COOKIE) {
                return $cookie;
            }
        }

        return null;
    }

    /** Remember this browser for `$user` and return the token the browser holds. */
    private function rememberFor(User $user): string
    {
        $response = $this->passTheChallenge($user, ['code' => $this->otp($user)], true);
        $this->assertNotNull($this->rememberedCookie($response), 'the control: the browser was remembered');

        return (string) $response->getCookie(RememberedBrowser::COOKIE)->getValue();
    }

    private function rowsFor(User $user): int
    {
        return DB::table(RememberedBrowser::TABLE)->where('user_id', $user->id)->count();
    }

    // ── Ticked, unticked ────────────────────────────────────────────────────────────────────

    public function test_a_ticked_box_on_an_accepted_code_lets_the_next_sign_in_skip_the_code(): void
    {
        $user = $this->enrolled();

        $response = $this->passTheChallenge($user, ['code' => $this->otp($user)], true);

        $cookie = $this->rememberedCookie($response);
        $this->assertNotNull($cookie, 'an accepted, ticked challenge sets the remembered-browser cookie');
        $this->assertTrue($cookie->isHttpOnly(), 'HttpOnly');
        $this->assertTrue($cookie->isSecure(), 'Secure');
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(RememberedBrowser::LIFETIME_DAYS)->getTimestamp(), $cookie->getExpiresTime(), 5);

        $token = (string) $response->getCookie(RememberedBrowser::COOKIE)->getValue();
        $row = DB::table(RememberedBrowser::TABLE)->where('user_id', $user->id)->sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash, 'the store holds the digest');
        $this->assertSame(0, DB::table(RememberedBrowser::TABLE)->where('token_hash', $token)->count(), 'and never the token');
        $this->assertNull($row->last_used_at);

        $this->assertSkipped($user, $token);
        $this->assertNotNull(DB::table(RememberedBrowser::TABLE)->where('id', $row->id)->value('last_used_at'));
    }

    public function test_a_ticked_box_on_an_accepted_recovery_code_lets_the_next_sign_in_skip_the_code(): void
    {
        $user = $this->enrolled();

        $response = $this->passTheChallenge($user, ['recovery_code' => $user->recoveryCodes()[0]], true);

        $this->assertNotNull($this->rememberedCookie($response));
        $this->assertSkipped($user, (string) $response->getCookie(RememberedBrowser::COOKIE)->getValue());
    }

    public function test_an_unticked_box_remembers_nothing_and_the_next_sign_in_is_challenged(): void
    {
        $user = $this->enrolled();

        $response = $this->passTheChallenge($user, ['code' => $this->otp($user)], false);

        $this->assertNull($this->rememberedCookie($response));
        $this->assertSame(0, $this->rowsFor($user));
        $this->assertChallenged($user, null, 'an unticked box leaves the sign-in as it was');
    }

    public function test_a_rejected_code_with_the_box_ticked_remembers_nothing(): void
    {
        $user = $this->enrolled();
        $this->postPassword($user, null)->assertRedirect(route('two-factor.login'));

        $response = $this->post('/two-factor-challenge', ['code' => '000000', RememberedBrowser::FIELD => '1']);

        $this->assertGuest();
        $this->assertNull($this->rememberedCookie($response));
        $this->assertSame(0, $this->rowsFor($user));
    }

    public function test_a_remembered_browser_still_needs_the_password(): void
    {
        $user = $this->enrolled();
        $token = $this->rememberFor($user);

        $this->signOut();
        $this->withCookie(RememberedBrowser::COOKIE, $token)
            ->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ── Whose browser, and for how long ─────────────────────────────────────────────────────

    public function test_a_browser_remembered_for_one_account_is_challenged_for_another(): void
    {
        $first = $this->enrolled('first@example.com');
        $second = $this->enrolled('second@example.com');
        $token = $this->rememberFor($first);

        $this->assertChallenged($second, $token, 'the first account\'s cookie does nothing for the second');
        $this->assertSkipped($first, $token);
    }

    public function test_the_expiry_is_fixed_from_the_tick_and_use_does_not_extend_it(): void
    {
        $user = $this->enrolled();
        $token = $this->rememberFor($user);

        $this->travel(RememberedBrowser::LIFETIME_DAYS)->days();
        $this->travel(-1)->minutes();
        $this->assertSkipped($user, $token);

        $this->travel(2)->minutes();
        $this->assertChallenged($user, $token, 'past the fixed expiry the browser is challenged, though it was used a minute before');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forgeries(): array
    {
        return [
            'a random token the server never issued' => ['random'],
            'the issued token with one character changed' => ['tampered'],
            'the stored digest presented as the token' => ['digest'],
            'the account id in place of a token' => ['user id'],
            'the issued token sent without the application\'s cookie encryption' => ['unencrypted'],
        ];
    }

    /**
     * One forgery per run: the `login` limiter allows five password POSTs a minute for one address,
     * and each arm spends three.
     */
    #[DataProvider('forgeries')]
    public function test_a_forged_or_tampered_cookie_is_challenged(string $forgery): void
    {
        $user = $this->enrolled();
        $token = $this->rememberFor($user);
        $digest = (string) DB::table(RememberedBrowser::TABLE)->where('user_id', $user->id)->value('token_hash');

        $this->signOut();

        if ($forgery === 'unencrypted') {
            $this->withUnencryptedCookie(RememberedBrowser::COOKIE, $token);
        } else {
            $this->withCookie(RememberedBrowser::COOKIE, match ($forgery) {
                'random' => bin2hex(random_bytes(32)),
                'tampered' => substr($token, 0, -1).($token[-1] === '0' ? '1' : '0'),
                'digest' => $digest,
                'user id' => (string) $user->id,
            });
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()]);

        $this->assertSame(route('two-factor.login'), $response->headers->get('Location'), $forgery);
        $this->assertGuest();

        // THE CONTROL: the genuine cookie still skips, so the refusal above was the forgery's. The
        // test client keeps unencrypted cookies apart from encrypted ones and sends both, so the
        // forged one is dropped first.
        $this->unencryptedCookies = [];
        $this->assertSkipped($user, $token);
    }

    // ── Revocation ──────────────────────────────────────────────────────────────────────────

    public function test_a_password_change_forgets_every_remembered_browser_for_the_account(): void
    {
        $user = $this->enrolled();
        $other = $this->enrolled('other@example.com');
        $token = $this->rememberFor($user);
        $this->rememberFor($other);

        UserProvisioning::update($user->fresh(), $user->name, $user->email, 'a-brand-new-password-1');

        $this->assertSame(0, $this->rowsFor($user));
        $this->assertSame(1, $this->rowsFor($other), 'the control: another account keeps its browser');

        $this->signOut();
        $this->withCookie(RememberedBrowser::COOKIE, $token)
            ->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-password-1'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_an_account_edit_that_keeps_the_password_keeps_the_remembered_browsers(): void
    {
        $user = $this->enrolled();
        $token = $this->rememberFor($user);

        UserProvisioning::update($user->fresh(), 'A New Name', $user->email);

        $this->assertSame(1, $this->rowsFor($user));
        $this->assertSkipped($user, $token);
    }

    public function test_an_emailed_two_factor_reset_forgets_every_remembered_browser_for_the_account(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();

        $user = $this->enrolled();
        $other = $this->enrolled('other@example.com');
        $this->rememberFor($user);
        $this->rememberFor($other);
        $this->signOut();

        $this->post(route('two-factor.reset.send'), ['email' => $user->email]);
        $captured = null;
        Notification::assertSentTo($user, TwoFactorResetCode::class, function (TwoFactorResetCode $n) use (&$captured) {
            $captured = (new ReflectionProperty($n, 'code'))->getValue($n);

            return true;
        });
        $this->post(route('two-factor.reset.consume'), ['code' => $captured])->assertRedirect(route('login'));

        $this->assertFalse($user->fresh()->hasCompletedTwoFactorEnrolment(), 'the control: the reset ran');
        $this->assertSame(0, $this->rowsFor($user));
        $this->assertSame(1, $this->rowsFor($other));
    }

    public function test_disabling_two_factor_forgets_every_remembered_browser_for_the_account(): void
    {
        $user = $this->enrolled();
        $this->rememberFor($user);

        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()]);
        $this->delete(route('two-factor.disable'));

        $this->assertNull($user->fresh()->two_factor_secret, 'the control: two-factor was disabled');
        $this->assertSame(0, $this->rowsFor($user));
    }

    public function test_re_enrolling_two_factor_forgets_every_remembered_browser_for_the_account(): void
    {
        $user = $this->enrolled();
        $token = $this->rememberFor($user);
        $before = $user->fresh()->two_factor_secret;

        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()]);
        $this->post(route('two-factor.enable'), ['force' => true]);

        $this->assertNotSame($before, $user->fresh()->two_factor_secret, 'the control: a new secret was written');
        $this->assertSame(0, $this->rowsFor($user));
        $this->assertChallenged($user, $token, 'the re-enrolled account is challenged on the remembered browser');
    }

    public function test_moving_to_a_new_authenticator_forgets_every_remembered_browser_for_the_account(): void
    {
        $user = $this->enrolled();
        $token = $this->rememberFor($user);

        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()]);
        $this->post(route('two-factor.move.start'))->assertRedirect(route('two-factor.move'));
        $html = $this->get(route('two-factor.move'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#Setup key: <code>([A-Z2-7]+)</code>#', $html, $key));
        $engine = app(Google2FA::class);
        $this->post(route('two-factor.move.confirm'), ['code' => $engine->oathTotp($key[1], $engine->getTimestamp())])
            ->assertRedirect(route('two-factor.codes'));

        $this->assertSame($key[1], Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret), 'the control: the move landed');
        $this->assertSame(0, $this->rowsFor($user));
        $this->assertChallenged($user, $token, 'the moved account is challenged on the remembered browser');
    }

    public function test_retiring_the_account_forgets_every_remembered_browser_for_it(): void
    {
        $user = $this->enrolled();
        $other = $this->enrolled('other@example.com');
        $this->rememberFor($user);
        $this->rememberFor($other);

        $this->assertSame(UserRetirement::RETIRED, UserRetirement::retire($user, 'operator@example.com', 'left the company'));

        $this->assertSame(0, $this->rowsFor($user));
        $this->assertSame(1, $this->rowsFor($other));
    }

    // ── The page ────────────────────────────────────────────────────────────────────────────

    public function test_both_challenge_forms_carry_a_labelled_remember_this_browser_checkbox(): void
    {
        $user = $this->enrolled();
        $this->postPassword($user, null)->assertRedirect(route('two-factor.login'));

        $html = $this->get(route('two-factor.login'))->assertOk()->getContent();

        foreach (['code', 'recovery_code'] as $field) {
            $id = $field === 'code' ? 'remember_browser_code' : 'remember_browser_recovery';
            $this->assertSame(1, preg_match(
                '#<input id="'.$field.'" name="'.$field.'"[^>]*>\s*<label for="'.$id.'"><input id="'.$id.'" name="remember_browser" type="checkbox" value="1"> Remember this browser for 30 days</label>\s*<button type="submit">Verify</button>#',
                $html,
            ), "the {$field} form carries the checkbox, inside its label, before its button");
        }
    }
}

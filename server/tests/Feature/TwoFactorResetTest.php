<?php

namespace Tests\Feature;

use App\Admin\UserRetirement;
use App\Auth\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorResetCode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Notifications\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Card#9077's emailed reset, end to end through the real routes.
 *
 * ⛔ THE STORE IS A HARD PREREQUISITE FOR EVERY ARM IN THIS FILE, and the split is deliberate:
 * `TwoFactorResetSurfaceTest` holds the routing, gating, mail-content and mail-availability
 * properties, which need none. The PR body says which set was executed where it was written.
 *
 * ⚠ `mail.default` IS FORCED TO `smtp` AND NOTHING IS SENT. `phpunit.xml` pins `MAIL_MAILER=array`,
 * which `TwoFactorReset::isAvailable()` refuses — correctly, since `array` delivers nothing — so
 * every arm below would exercise the refusal branch instead of the feature. `Notification::fake()`
 * intercepts before any transport is constructed, so no connection is attempted to anything.
 */
class TwoFactorResetTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp']);

        Notification::fake();

        // The same live-log capture `AuthSecretsNeverSurfaceTest` uses, including its canary — an
        // empty log file proves nothing until the file has been shown to capture something.
        $this->logFile = tempnam(sys_get_temp_dir(), 'mezzanine-9077-').'.log';

        config([
            'logging.default' => 'single',
            'logging.channels.single' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);

        Log::forgetChannel();
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    private function log(): string
    {
        Log::error('canary-line-proving-this-file-is-live');

        $contents = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';

        $this->assertStringContainsString('canary-line-proving-this-file-is-live', $contents,
            'the log capture is not live, so nothing below is a measurement');

        return $contents;
    }

    /**
     * An enrolled account, plus a second one so that `UserRetirement`'s last-active-operator
     * refusal (D4) never fires in an arm that is about something else.
     */
    private function enrolledUser(string $email = 'ops@example.com'): User
    {
        User::factory()->twoFactorConfirmed()->create(['email' => 'other@example.com']);

        return User::factory()->twoFactorConfirmed()->create(['email' => $email]);
    }

    /**
     * The code that was actually mailed, read off the faked notification.
     *
     * ⚠ REFLECTION RATHER THAN A RETURN VALUE, and that is the point: `TwoFactorReset::request()`
     * returns `void` precisely so no caller can learn whether a code was minted. A test helper that
     * needed the value returned would be asking for the enumeration oracle back.
     */
    private function mailedCode(User $user): string
    {
        $captured = [];

        // `assertSentTo`'s callback is evaluated against EVERY notification sent to this notifiable,
        // in the order they were sent, so the collection is complete and the LAST entry is the most
        // recent code. Taking the first would silently test a superseded value in the one arm that
        // requests twice.
        Notification::assertSentTo($user, TwoFactorResetCode::class,
            function (TwoFactorResetCode $notification) use (&$captured) {
                $captured[] = (new ReflectionProperty($notification, 'code'))->getValue($notification);

                return true;
            });

        $this->assertNotEmpty($captured, 'no code was captured from the notification');

        return (string) end($captured);
    }

    /**
     * Put an account back into the enrolled state so that a SECOND clearing would be observable.
     *
     * ⚠ THE THREE COLUMNS ARE TAKEN FROM THE FACTORY'S OWN STATE rather than written out here —
     * `UserFactory::twoFactorConfirmed()` owns what "enrolled" means (a real encrypted secret from
     * the real provider), and a second hand-written copy would be free to drift into a shape the
     * decrypt path never sees. `Arr::only` is what keeps `raw()`'s name and email from overwriting
     * the account this test is about.
     */
    private function reEnrol(User $user): void
    {
        $user->forceFill(Arr::only(User::factory()->twoFactorConfirmed()->raw(), [
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ]))->save();
    }

    private function requestAResetFor(User $user): string
    {
        $this->post(route('two-factor.reset.send'), ['email' => $user->email])
            ->assertRedirect(route('two-factor.reset.confirm'));

        return $this->mailedCode($user);
    }

    // ── The destination ─────────────────────────────────────────────────────────────────────

    /**
     * ⛔ THE CODE GOES TO THE ACCOUNT'S OWN ADDRESS. There is no request field that could redirect
     * it (`TwoFactorResetSurfaceTest` asserts the notification has no destination parameter at
     * all); this is the same property observed on the live path.
     */
    public function test_the_code_is_sent_to_the_account_and_to_nobody_else(): void
    {
        $user = $this->enrolledUser();

        $this->post(route('two-factor.reset.send'), ['email' => $user->email]);

        Notification::assertSentTo($user, TwoFactorResetCode::class);
        Notification::assertCount(1);
    }

    // ── What a consumed reset does, and what it must not do ─────────────────────────────────

    /**
     * ⛔ THE ARM THE CARD NAMES FIRST: a consumed reset CLEARS the enrolment and AUTHENTICATES
     * NOBODY. A build that logged the user in would be a password-less login built by accident, and
     * this reds against it on the `assertGuest()` line.
     */
    public function test_a_consumed_reset_clears_the_enrolment_and_signs_nobody_in(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        $this->post(route('two-factor.reset.consume'), ['code' => $code])
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    /**
     * ⛔ AND THE ACCOUNT IS THEN ON THE FORCED-ENROLMENT PATH — the same one a brand-new account
     * takes. Driven through the real login route rather than `actingAs()`, because what is being
     * asserted is that the login PIPELINE no longer challenges and the `mfa` gate now bounces.
     */
    public function test_after_a_reset_the_account_must_re_enrol_before_reaching_the_dashboard(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        $this->post(route('two-factor.reset.consume'), ['code' => $code]);

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user->fresh());

        $this->get('/dashboard')->assertRedirect(route('two-factor.enroll'));
    }

    // ── Single use, expiry, supersession ────────────────────────────────────────────────────

    public function test_a_code_cannot_be_used_a_second_time(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        $this->post(route('two-factor.reset.consume'), ['code' => $code])
            ->assertRedirect(route('login'));

        // Re-enrol so that a second success would be observable as a state change rather than
        // being indistinguishable from the first one's result.
        $this->reEnrol($user);

        $this->from(route('two-factor.reset.confirm'))
            ->post(route('two-factor.reset.consume'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at,
            'a replayed code cleared the enrolment a second time');
    }

    public function test_a_code_expires(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        // THE CONTROL FIRST, on its own code: inside the window the same request succeeds, so the
        // arm below is measuring the EXPIRY rather than a code that never worked.
        $this->travel(TwoFactorReset::TTL_MINUTES - 1)->minutes();
        $this->post(route('two-factor.reset.consume'), ['code' => $code])->assertRedirect(route('login'));
        $this->travelBack();

        $this->reEnrol($user);
        $expiring = $this->requestAResetFor($user);

        $this->travel(TwoFactorReset::TTL_MINUTES + 1)->minutes();

        $this->from(route('two-factor.reset.confirm'))
            ->post(route('two-factor.reset.consume'), ['code' => $expiring])
            ->assertSessionHasErrors('code');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->travelBack();
    }

    /**
     * ⛔ AT MOST ONE LIVE CODE PER ACCOUNT. Without this, requesting repeatedly would WIDEN the set
     * of values that work — so an attempt to bury a real reset in noise would also be an attempt to
     * multiply the attacker's own chances.
     */
    public function test_requesting_again_invalidates_the_code_already_sent(): void
    {
        $user = $this->enrolledUser();
        $first = $this->requestAResetFor($user);

        $second = $this->requestAResetFor($user);

        $this->assertNotSame($first, $second, 'the control: a second request mints a different code');

        $this->from(route('two-factor.reset.confirm'))
            ->post(route('two-factor.reset.consume'), ['code' => $first])
            ->assertSessionHasErrors('code');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // And the SECOND one still works — or this arm would pass against a build where requesting
        // twice broke the feature entirely.
        $this->post(route('two-factor.reset.consume'), ['code' => $second])->assertRedirect(route('login'));
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    // ── Retirement ──────────────────────────────────────────────────────────────────────────

    /**
     * ⛔ RETIREMENT IS NOT REVERSIBLE BY ANYONE HOLDING THE MAILBOX. The account is retired AFTER
     * the code was minted, which is the window a single check at request time would miss.
     */
    public function test_a_code_minted_before_a_retirement_is_refused_after_it(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        $this->assertSame(UserRetirement::RETIRED,
            UserRetirement::retire($user, 'operator@example.com', 'left the company'));

        $this->from(route('two-factor.reset.confirm'))
            ->post(route('two-factor.reset.consume'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at,
            'a retired account\'s second factor was removed');
    }

    public function test_a_retired_account_is_never_sent_a_code(): void
    {
        $user = $this->enrolledUser();
        UserRetirement::retire($user, 'operator@example.com', 'left the company');

        $this->post(route('two-factor.reset.send'), ['email' => $user->email]);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table(TwoFactorReset::TABLE)->count());
    }

    public function test_an_account_with_no_confirmed_second_factor_is_never_sent_a_code(): void
    {
        $user = User::factory()->twoFactorUnenrolled()->create(['email' => 'fresh@example.com']);

        $this->post(route('two-factor.reset.send'), ['email' => $user->email]);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table(TwoFactorReset::TABLE)->count());
    }

    // ── No enumeration oracle ───────────────────────────────────────────────────────────────

    /**
     * ⛔ FOUR DIFFERENT TRUTHS, ONE ANSWER — status, location and flashed message all identical.
     * Read immediately after each request, because the harness shares one session and reading at
     * the end would compare the last answer with itself (the same trap
     * `AuthSecretsNeverSurfaceTest` records for the login path).
     */
    public function test_every_request_is_answered_identically_whatever_is_true_of_the_address(): void
    {
        $enrolled = $this->enrolledUser();
        $unenrolled = User::factory()->twoFactorUnenrolled()->create(['email' => 'fresh@example.com']);
        $retired = User::factory()->twoFactorConfirmed()->create(['email' => 'gone@example.com']);
        UserRetirement::retire($retired, 'operator@example.com', 'left the company');

        $answers = [];

        foreach ([
            'an enrolled account' => $enrolled->email,
            'an account with no second factor' => $unenrolled->email,
            'a retired account' => $retired->email,
            'no account at all' => 'nobody-has-this-address@example.com',
        ] as $why => $address) {
            $this->flushSession();

            $response = $this->post(route('two-factor.reset.send'), ['email' => $address]);

            $answers[$why] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'flash' => session('status'),
            ];
        }

        $this->assertNotNull($answers['an enrolled account']['flash'],
            'the control: there IS a flashed answer to compare');

        foreach ($answers as $why => $answer) {
            $this->assertSame($answers['an enrolled account'], $answer,
                "the answer for {$why} differs — that is a user-enumeration oracle");
        }
    }

    // ── Canon #20 ───────────────────────────────────────────────────────────────────────────

    /**
     * ⛔ THE CODE REACHES NO LOG AND NO RESPONSE BODY, ON THE HAPPY PATH AND ON THE FAILING ONE.
     * `TwoFactorReset` writes two log lines around this act by design — a request and a
     * consumption — so "nothing is logged" is not the property; "the credential is not in what is
     * logged" is, and the control below proves the lines are really there.
     */
    public function test_the_code_reaches_no_log_line_and_no_response_body(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        $this->get(route('two-factor.reset.confirm'))->assertOk()->assertDontSee($code);

        // The failing path first, because it is the one that renders the submitted value back.
        $this->from(route('two-factor.reset.confirm'))
            ->post(route('two-factor.reset.consume'), ['code' => 'AAAAA-BBBBB-CCCCC-DDDDD']);
        $this->get(route('two-factor.reset.confirm'))->assertOk()->assertDontSee('AAAAA-BBBBB-CCCCC-DDDDD');

        $this->post(route('two-factor.reset.consume'), ['code' => $code])->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertDontSee($code);

        $log = $this->log();

        // THE CONTROL: the audit lines this act owes ARE in the log, so the absences below are
        // measurements rather than the shape of an empty file.
        $this->assertStringContainsString('two-factor reset requested', $log);
        $this->assertStringContainsString('two-factor enrolment cleared by an emailed reset', $log);

        $this->assertStringNotContainsString($code, $log);
        $this->assertStringNotContainsString(TwoFactorReset::format($code), $log);
        $this->assertStringNotContainsString(TwoFactorReset::fingerprint($code), $log,
            'even the stored digest has no reason to be in a log line');
    }

    /**
     * And the flashed input — the surface a re-rendered form draws from. Laravel flashes the whole
     * request body on a validation failure; `bootstrap/app.php` excludes `code` for this reason.
     */
    public function test_a_rejected_code_is_not_flashed_into_the_session(): void
    {
        $this->enrolledUser();

        // Long enough to fail the `max:64` rule, so the failure is a VALIDATION one — the branch
        // that flashes input at all.
        $tooLong = str_repeat('A', 100);

        $this->from(route('two-factor.reset.confirm'))
            ->post(route('two-factor.reset.consume'), ['code' => $tooLong])
            ->assertSessionHasErrors('code');

        $this->assertArrayNotHasKey('code', session('_old_input', []));
    }

    // ── The audit record ────────────────────────────────────────────────────────────────────

    /**
     * ⛔ CONSUMING A CODE IS THE ONE ACT IN THIS APPLICATION THAT REMOVES A SECURITY CONTROL, so
     * who, when and from where must survive it — in the row as well as in the log, because a log is
     * rotated and a row is not.
     */
    public function test_the_consumption_records_who_when_and_from_where(): void
    {
        $user = $this->enrolledUser();
        $code = $this->requestAResetFor($user);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['User-Agent' => 'MezzanineTest/1.0'])
            ->post(route('two-factor.reset.consume'), ['code' => $code])
            ->assertRedirect(route('login'));

        $row = DB::table(TwoFactorReset::TABLE)->where('user_id', $user->getKey())->first();

        $this->assertNotNull($row, 'the consumed row was deleted — there is no audit trail');
        $this->assertNotNull($row->consumed_at);
        $this->assertSame('198.51.100.7', $row->consumed_ip);
        $this->assertSame('MezzanineTest/1.0', $row->consumed_user_agent);

        // And the code itself is not in the row — only its digest.
        $this->assertSame(TwoFactorReset::fingerprint($code), $row->token_hash);
        $this->assertStringNotContainsString($code, json_encode($row));
    }

    // ── Rate limiting ───────────────────────────────────────────────────────────────────────

    /**
     * The per-account limit, observed refusing. Three an hour is the budget; the fourth is a 429.
     *
     * ⚠ THIS ARM DEPENDS ON A CACHE STORE THAT PERSISTS ACROSS THE REQUESTS IN ONE TEST, which
     * `phpunit.xml`'s `CACHE_STORE=array` does within a single booted application. It is the same
     * dependency the live limiter has on `CACHE_STORE` in production — `docs/PLAN.md § 5`.
     */
    public function test_the_request_route_refuses_a_fourth_attempt_within_the_hour(): void
    {
        $user = $this->enrolledUser();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('two-factor.reset.send'), ['email' => $user->email])
                ->assertRedirect(route('two-factor.reset.confirm'));
        }

        $this->post(route('two-factor.reset.send'), ['email' => $user->email])
            ->assertStatus(429);

        // ⛔ AND THE THROTTLE IS NOT AN ENUMERATION ORACLE EITHER: an address with no account
        // consumes its own budget and is refused the same way, so "did I get throttled" cannot be
        // read as "does this account exist".
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('two-factor.reset.send'), ['email' => 'nobody@example.com']);
        }

        $this->post(route('two-factor.reset.send'), ['email' => 'nobody@example.com'])
            ->assertStatus(429);
    }

    /**
     * ⛔ A BROKEN TRANSPORT MUST NOT BECOME AN ENUMERATION ORACLE, and without the catch in
     * `TwoFactorReset::request()` it is the loudest one in the application: the send is the LAST
     * thing that happens on the branch where an enrolled account was found, so a raised transport
     * error 500s for exactly those addresses and 302s for every other. Watched red against a build
     * with the try/catch removed.
     *
     * ⚠ THE FAILURE IS SIMULATED BY POINTING AT A CLOSED PORT rather than by mocking the mailer,
     * because what is being asserted is the behaviour of the real send path when the real transport
     * refuses. `127.0.0.1:2525` is `.env.example`'s own placeholder and nothing listens on it.
     */
    public function test_a_transport_that_refuses_answers_identically_and_leaves_no_live_token(): void
    {
        $user = $this->enrolledUser();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 2525, 'timeout' => 1],
        ]);

        // ⛔ THE REAL CHANNEL MANAGER, PUT BACK. `setUp()` fakes notifications for every other arm;
        // this one is about what the REAL send path does when the transport refuses, so the three
        // bindings `Illuminate\Notifications\NotificationServiceProvider` makes are restored — the
        // same three `Notification::fake()` replaced.
        $real = new ChannelManager($this->app);
        $this->app->instance(ChannelManager::class, $real);
        $this->app->instance(Dispatcher::class, $real);
        $this->app->instance(Factory::class, $real);
        Notification::swap($real);

        $unknown = $this->post(route('two-factor.reset.send'), ['email' => 'nobody@example.com']);
        $this->flushSession();
        $known = $this->post(route('two-factor.reset.send'), ['email' => $user->email]);

        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode(),
            'a refused transport makes the answer differ for an address that HAS an account');
        $this->assertSame($unknown->headers->get('Location'), $known->headers->get('Location'));

        // And nothing usable was left behind: the row is discarded with the undelivered code.
        $this->assertSame(0, DB::table(TwoFactorReset::TABLE)->whereNull('consumed_at')->count());

        $this->assertStringContainsString('two-factor reset code could not be sent', $this->log());
    }

    // ── The mail prerequisite, on the live path ─────────────────────────────────────────────

    /**
     * ⛔ ON A HOST WITH NO OUTBOUND MAIL, NOTHING IS MINTED — not "minted and undeliverable". A row
     * that exists on a host that cannot send is a live credential nobody can use and nobody can
     * see, and under the `log` transport the message would additionally have been written to disk.
     */
    public function test_a_host_with_no_outbound_mail_mints_nothing_and_says_so(): void
    {
        config(['mail.default' => 'log']);

        $user = $this->enrolledUser();

        $this->from(route('two-factor.reset'))
            ->post(route('two-factor.reset.send'), ['email' => $user->email])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table(TwoFactorReset::TABLE)->count());
    }
}

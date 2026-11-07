<?php

namespace Tests\Feature;

use App\Admin\UserRetirement;
use App\Models\User;
use App\Notifications\TwoFactorResetCode;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Card#12964 — "Sign out everywhere else" on the recovery codes page, and every credential change
 * ending the account's other sessions.
 *
 * ⛔ EACH BROWSER IS ITS COOKIES. Before each request `visit()` clears the in-memory state the test
 * application would otherwise carry from one request to the next — the guards, the session store's
 * attributes, the database handler's "this row exists" flag and the cookie jar's queue — so the user
 * a request is signed in as comes from the session or remember-me cookie it carries, read from
 * `web_sessions` and `users`. Without that, the one session store the test application shares across
 * requests carries a signed-in user from one "browser" into the next, and an ended session would look
 * alive.
 * Every sign-in goes through Fortify's real login and challenge routes, so each session row is one
 * `Illuminate\Session\DatabaseSessionHandler` wrote for a real sign-in. Recovery codes pass the
 * challenge because each is single-use per code, where a TOTP code may be accepted once per period.
 *
 * "Signed out" is observed as the next request of that browser being sent to the login page. Every
 * arm that signs a browser out first shows that browser reaching the dashboard, and
 * `test_the_harness_authenticates_by_cookie_alone` shows each cookie signing a browser in and a guest
 * being sent to the login page, so the redirect means the session ended.
 */
class SignOutEverywhereElseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `phpunit.xml` pins `array`; the driver a host runs is `database` (`config/session.php`,
        // `.env.example`), and it is the one whose sessions are rows that can be ended.
        config(['session.driver' => 'database']);
    }

    private function enrolled(string $email = 'ops@example.com'): User
    {
        return User::factory()->twoFactorConfirmed()->create(['email' => $email]);
    }

    private function sessionCookie(): string
    {
        return config('session.cookie');
    }

    private function recallerCookie(): string
    {
        return Auth::guard()->getRecallerName();
    }

    /**
     * Send one request as the browser whose cookies are `$jar`, and keep the cookies the response sets.
     *
     * @param  array<string, string>  $jar
     * @param  array<string, mixed>  $data
     */
    private function visit(array &$jar, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $store = $this->app['session']->driver();
        $store->flush();
        $store->getHandler()->setExists(false);
        $this->app['cookie']->flushQueuedCookies();
        $this->defaultCookies = $jar;

        $response = $this->call($method, $uri, $data, $this->prepareCookiesForRequest());

        foreach ([$this->sessionCookie(), $this->recallerCookie()] as $name) {
            $cookie = $response->getCookie($name);
            if ($cookie === null) {
                continue;
            }
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
                unset($jar[$name]);
            } else {
                $jar[$name] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * Sign `$user` in as a new browser, through the password and a recovery code.
     *
     * @return array<string, string> the browser's cookies
     */
    private function signIn(User $user, bool $rememberMe = false, bool $confirmPassword = true): array
    {
        $jar = [];
        $this->visit($jar, 'POST', '/login', ['email' => $user->email, 'password' => UserFactory::password()]
            + ($rememberMe ? ['remember' => 'on'] : []))
            ->assertRedirect(route('two-factor.login'));
        $this->visit($jar, 'POST', '/two-factor-challenge', ['recovery_code' => $user->fresh()->recoveryCodes()[0]])
            ->assertRedirect('/dashboard');

        if ($confirmPassword) {
            $this->visit($jar, 'POST', route('password.confirm.store'), ['password' => UserFactory::password()])
                ->assertSessionHasNoErrors();
        }

        return $jar;
    }

    /** @param  array<string, string>  $jar */
    private function assertSignedIn(array $jar, string $why): void
    {
        $this->assertSame(200, $this->visit($jar, 'GET', '/dashboard')->getStatusCode(), $why);
    }

    /** @param  array<string, string>  $jar */
    private function assertSignedOut(array $jar, string $why): void
    {
        $response = $this->visit($jar, 'GET', '/dashboard');

        $this->assertSame(route('login'), $response->headers->get('Location'), $why);
    }

    /**
     * Only the remember-me cookie: what a browser presents once its session has expired.
     *
     * @param  array<string, string>  $jar
     * @return array<string, string>
     */
    private function rememberMeOnly(array $jar): array
    {
        $this->assertArrayHasKey($this->recallerCookie(), $jar, 'the browser holds a remember-me cookie');

        return [$this->recallerCookie() => $jar[$this->recallerCookie()]];
    }

    private function rowsFor(User $user): int
    {
        return DB::table(config('session.table'))->where('user_id', $user->id)->count();
    }

    /** @param  array<string, string>  $jar */
    private function signOutEverywhereElse(array &$jar): TestResponse
    {
        return $this->visit($jar, 'DELETE', route('sessions.sign-out-others'));
    }

    // ── The harness, shown able to fail ─────────────────────────────────────────────────────

    public function test_the_harness_authenticates_by_cookie_alone(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);

        $this->assertSignedIn($laptop, 'the session cookie signs the browser in');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and so does the remember-me cookie alone');
        $this->assertSignedOut([], 'a browser with no cookie is a guest');
        $this->assertSignedOut([$this->sessionCookie() => str_repeat('a', 40)], 'and so is one with an unknown session id');
    }

    // ── Sign out everywhere else ────────────────────────────────────────────────────────────

    public function test_it_signs_out_every_other_session_of_the_account_and_keeps_this_one(): void
    {
        $user = $this->enrolled();
        $bystander = $this->enrolled('other@example.com');
        $laptop = $this->signIn($user, rememberMe: true);
        $phone = $this->signIn($user);
        $theirs = $this->signIn($bystander, rememberMe: true);
        $here = $this->signIn($user);

        $this->assertSignedIn($laptop, 'the control: the lost laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');
        $this->assertSignedIn($phone, 'and so is the phone');

        $this->signOutEverywhereElse($here)
            ->assertRedirect(route('two-factor.codes'))
            ->assertSessionHas('status', 'Signed out every other browser and device. This browser is still signed in.');

        $this->assertSignedOut($laptop, 'the lost laptop\'s session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->assertSignedOut($phone, 'and the phone\'s session ended');
        $this->assertSignedIn($here, 'this browser is still signed in');
        $this->visit($here, 'GET', route('two-factor.codes'))->assertOk();
        $this->assertSame(1, $this->rowsFor($user), 'one session row is left: this browser\'s');

        $this->assertSignedIn([$this->sessionCookie() => $theirs[$this->sessionCookie()]], 'another account\'s session is untouched');
        $this->assertSame(1, $this->rowsFor($bystander), 'and its one session row is still there');
        $this->assertSignedIn($this->rememberMeOnly($theirs), 'and so is its remember-me cookie');
    }

    /**
     * ⛔ THIS BROWSER KEEPS SIGNING IN, UNDER A NEW SESSION ID AND A NEW REMEMBER-ME COOKIE: a copy of
     * its old session cookie or old remember-me cookie, taken from this browser, signs nothing in.
     */
    public function test_this_browser_moves_to_a_new_session_id_and_remember_me_cookie(): void
    {
        $user = $this->enrolled();
        $here = $this->signIn($user, rememberMe: true);
        $copy = $here;

        $this->signOutEverywhereElse($here)->assertRedirect(route('two-factor.codes'));

        $this->assertNotSame($copy[$this->sessionCookie()], $here[$this->sessionCookie()]);
        $this->assertNotSame($copy[$this->recallerCookie()], $here[$this->recallerCookie()]);
        $this->assertSignedIn($here, 'this browser is still signed in');
        $this->assertSignedIn($this->rememberMeOnly($here), 'and its new remember-me cookie signs it in');
        $this->assertSignedOut($copy, 'a copy of its old cookies signs nothing in');
        $this->assertSignedOut($this->rememberMeOnly($copy), 'nor does the old remember-me cookie alone');
    }

    public function test_a_browser_without_remember_me_is_not_given_a_remember_me_cookie(): void
    {
        $user = $this->enrolled();
        $here = $this->signIn($user);

        $this->signOutEverywhereElse($here)->assertRedirect(route('two-factor.codes'));

        $this->assertArrayNotHasKey($this->recallerCookie(), $here);
        $this->assertSignedIn($here, 'this browser is still signed in');
    }

    public function test_the_page_offers_it_and_says_what_forget_does_not_do(): void
    {
        $user = $this->enrolled();
        $here = $this->signIn($user);

        $flat = preg_replace('/\s+/', ' ', $this->visit($here, 'GET', route('two-factor.codes'))->assertOk()->getContent());

        $this->assertStringContainsString('No browser is remembered for your account.', $flat, 'the control: no remembered browser');
        $this->assertStringNotContainsString('Forgetting a browser does not end a session it is already signed in with.', $flat);
        $this->assertStringContainsString('Forgetting a browser does not sign it out.', $flat);
        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="'.preg_quote(route('sessions.sign-out-others'), '#').'">\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="_method" value="DELETE">\s*<button type="submit">Sign out everywhere else</button>#',
            $flat,
            'the button is offered with no remembered browser too',
        );
    }

    public function test_no_session_id_or_token_reaches_a_response_or_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $here = $this->signIn($user, rememberMe: true);
        $secrets = [
            $laptop[$this->sessionCookie()],
            $here[$this->sessionCookie()],
            $user->fresh()->remember_token,
        ];

        $bodies = [];
        $bodies[] = $this->visit($here, 'GET', route('two-factor.codes'))->getContent();
        $press = $this->signOutEverywhereElse($here);
        $bodies[] = $press->getContent();
        $bodies[] = (string) $press->headers->get('Location');
        $bodies[] = $this->visit($here, 'GET', route('two-factor.codes'))->getContent();
        $secrets[] = $here[$this->sessionCookie()];
        $secrets[] = $user->fresh()->remember_token;

        foreach ($secrets as $secret) {
            $this->assertIsString($secret);
            $this->assertNotSame('', $secret);
            foreach ($bodies as $i => $body) {
                $this->assertStringNotContainsString($secret, $body, "response {$i}");
            }
            foreach ($logged as $line) {
                $this->assertStringNotContainsString($secret, $line, 'a log line');
            }
        }
        $this->assertSignedOut($laptop, 'the control: the press ran');
    }

    // ── The gates ───────────────────────────────────────────────────────────────────────────

    public function test_an_unauthenticated_request_is_sent_to_sign_in_and_ends_nothing(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $token = $user->fresh()->remember_token;

        $guest = [];
        $this->signOutEverywhereElse($guest)->assertRedirect(route('login'));

        $this->assertSignedIn($laptop, 'nothing was signed out');
        $this->assertSame($token, $user->fresh()->remember_token);
    }

    public function test_a_session_waiting_for_its_code_is_sent_to_sign_in_and_ends_nothing(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $token = $user->fresh()->remember_token;

        $pending = [];
        $this->visit($pending, 'POST', '/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));
        $this->signOutEverywhereElse($pending)->assertRedirect(route('login'));

        $this->assertSignedIn($laptop, 'nothing was signed out');
        $this->assertSame($token, $user->fresh()->remember_token);
    }

    public function test_an_account_without_a_confirmed_second_factor_is_sent_to_enrol(): void
    {
        $user = User::factory()->twoFactorUnenrolled()->create();
        $token = $user->remember_token;
        $this->assertNotNull($token);

        $this->actingAs($user)->delete(route('sessions.sign-out-others'))->assertRedirect(route('two-factor.enroll'));
        $this->assertSame($token, $user->fresh()->remember_token, 'nothing was voided');
    }

    public function test_a_session_that_has_not_re_proved_the_password_is_sent_to_confirm_it(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $token = $user->fresh()->remember_token;
        $here = $this->signIn($user, confirmPassword: false);

        $this->signOutEverywhereElse($here)->assertRedirect(route('password.confirm'));

        $this->assertSignedIn($laptop, 'nothing was signed out');
        $this->assertSame($token, $user->fresh()->remember_token);
    }

    public function test_a_get_or_post_ends_nothing(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user);
        $here = $this->signIn($user);

        $this->visit($here, 'GET', route('sessions.sign-out-others'))->assertMethodNotAllowed();
        $this->visit($here, 'POST', route('sessions.sign-out-others'))->assertMethodNotAllowed();

        $this->assertSignedIn($laptop, 'nothing was signed out');
    }

    /**
     * Laravel's CSRF middleware stands down under unit tests (`PreventRequestForgery::runningUnitTests()`),
     * so this arm puts the real check back by binding a subclass that does not.
     */
    public function test_a_request_without_the_csrf_token_is_refused_and_ends_nothing(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $here = $this->signIn($user);
        $token = $user->fresh()->remember_token;

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->signOutEverywhereElse($here)->assertStatus(419);
        $this->visit($here, 'DELETE', route('sessions.sign-out-others'), ['_token' => 'not-the-session-token'])->assertStatus(419);

        $this->assertSignedIn($laptop, 'nothing was signed out');
        $this->assertSame($token, $user->fresh()->remember_token);

        // The control: the page's own form token is accepted, so the 419s above are the CSRF check.
        $page = $this->visit($here, 'GET', route('two-factor.codes'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#action="'.preg_quote(route('sessions.sign-out-others'), '#').'">\s*<input type="hidden" name="_token" value="([^"]+)"#', $page, $form));
        $this->visit($here, 'DELETE', route('sessions.sign-out-others'), ['_token' => $form[1]])->assertRedirect(route('two-factor.codes'));
        $this->assertSignedOut($laptop, 'and it signed the laptop out');
    }

    // ── Every credential change ends the account's other sessions ───────────────────────────

    public function test_moving_to_a_new_authenticator_signs_out_every_other_session(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $here = $this->signIn($user);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');

        $this->visit($here, 'POST', route('two-factor.move.start'))->assertRedirect(route('two-factor.move'));
        $html = $this->visit($here, 'GET', route('two-factor.move'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#Setup key: <code>([A-Z2-7]+)</code>#', $html, $key));
        $engine = app(Google2FA::class);
        $this->visit($here, 'POST', route('two-factor.move.confirm'), ['code' => $engine->oathTotp($key[1], $engine->getTimestamp())])
            ->assertRedirect(route('two-factor.codes'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at, 'the control: the move landed');

        $this->assertSignedOut($laptop, 'the other session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->assertSignedIn($here, 'the browser that moved is still signed in');
    }

    public function test_re_enrolling_signs_out_every_other_session(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $here = $this->signIn($user);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');
        $secret = $user->fresh()->two_factor_secret;

        $this->visit($here, 'POST', route('two-factor.enable'), ['force' => true])->assertRedirect();
        $this->assertNotSame($secret, $user->fresh()->two_factor_secret, 'the control: the secret was replaced');

        $this->assertSignedOut($laptop, 'the other session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->visit($here, 'GET', route('two-factor.enroll'))->assertOk();
    }

    public function test_turning_two_factor_off_signs_out_every_other_session(): void
    {
        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $here = $this->signIn($user);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');

        $this->visit($here, 'DELETE', route('two-factor.disable'))->assertRedirect();
        $this->assertNull($user->fresh()->two_factor_confirmed_at, 'the control: two-factor is off');

        $this->assertSignedOut($laptop, 'the other session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->assertSame(route('two-factor.enroll'), $this->visit($here, 'GET', '/dashboard')->headers->get('Location'),
            'the browser that turned it off is still signed in, and sent to enrol');
    }

    /**
     * ⛔ THE EMAILED RESET ENDS EVERY SESSION. It runs signed out, so there is no current session to
     * keep. A session that outlived it would be sent to enrolment, and one that had re-proved the
     * password within `auth.password_timeout` could enrol an authenticator of its holder's choosing.
     */
    public function test_an_emailed_reset_signs_out_every_session(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();

        $user = $this->enrolled();
        $laptop = $this->signIn($user, rememberMe: true);
        $phone = $this->signIn($user);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');

        $guest = [];
        $this->visit($guest, 'POST', route('two-factor.reset.send'), ['email' => $user->email])
            ->assertRedirect(route('two-factor.reset.confirm'));
        $code = null;
        Notification::assertSentTo($user, TwoFactorResetCode::class, function (TwoFactorResetCode $n) use (&$code) {
            $code = (new ReflectionProperty($n, 'code'))->getValue($n);

            return true;
        });
        $this->visit($guest, 'POST', route('two-factor.reset.consume'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertNull($user->fresh()->two_factor_confirmed_at, 'the control: the reset landed');

        $this->assertSignedOut($laptop, 'the laptop\'s session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->assertSignedOut($phone, 'and the phone\'s');
        $this->assertSame(0, $this->rowsFor($user));
    }

    /** Already true before card#12964 (`App\Admin\UserProvisioning::update()`); held here on the same harness. */
    public function test_a_password_change_signs_out_every_session_of_the_account(): void
    {
        $user = $this->enrolled();
        $operator = $this->enrolled('operator@example.com');
        $laptop = $this->signIn($user, rememberMe: true);
        $console = $this->signIn($operator);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');

        $this->visit($console, 'PATCH', route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'a-new-password-for-this-test',
            'password_confirmation' => 'a-new-password-for-this-test',
            'role' => User::OPERATOR,
        ])->assertSessionHasNoErrors();

        $this->assertSignedOut($laptop, 'the session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->assertSignedIn($console, 'the operator who changed it is still signed in');
    }

    /**
     * Already true before card#12964, and kept: a recovery act ends the session making the request too,
     * because a stolen copy of a session cookie is that session's id (`App\Admin\UserProvisioning::update()`).
     */
    public function test_an_operator_changing_their_own_password_is_signed_out_everywhere_including_here(): void
    {
        $operator = $this->enrolled('operator@example.com');
        $this->enrolled('second-operator@example.com');
        $laptop = $this->signIn($operator, rememberMe: true);
        $console = $this->signIn($operator);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($console, 'and so is the console');

        $this->visit($console, 'PATCH', route('admin.users.update', $operator), [
            'name' => $operator->name,
            'email' => $operator->email,
            'password' => 'a-new-password-for-this-test',
            'password_confirmation' => 'a-new-password-for-this-test',
            'role' => User::OPERATOR,
        ])->assertSessionHasNoErrors();

        $this->assertSignedOut($laptop, 'the laptop\'s session ended');
        $this->assertSignedOut($console, 'and so did the one that changed the password');
        $this->assertSame(0, $this->rowsFor($operator));
    }

    /** Already true before card#12964 (`App\Auth\ActiveUserProvider`); held here on the same harness. */
    public function test_retiring_an_account_signs_out_every_session_of_it(): void
    {
        $user = $this->enrolled();
        $operator = $this->enrolled('operator@example.com');
        $laptop = $this->signIn($user, rememberMe: true);
        $this->assertSignedIn($laptop, 'the control: the laptop is signed in before');
        $this->assertSignedIn($this->rememberMeOnly($laptop), 'and its remember-me cookie signs it in');

        $this->assertSame(UserRetirement::RETIRED, UserRetirement::retire($user, 'operator@example.com', 'left'));

        $this->assertSignedOut($laptop, 'the session ended');
        $this->assertSignedOut($this->rememberMeOnly($laptop), 'and its remember-me cookie signs nothing in');
        $this->assertSame(1, User::query()->active()->whereKey($operator->id)->count());
    }
}

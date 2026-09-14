<?php

namespace Tests\Feature;

use App\Auth\TwoFactorIssuer;
use App\Http\Controllers\Auth\TwoFactorMoveController;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Card#9471 — a signed-in account with a confirmed second factor moves to a new authenticator,
 * and keeps its current one until the new one is proven.
 *
 * ⛔ THE HAZARD THIS FILE IS WRITTEN AGAINST IS A WINDOW WITH NO SECOND FACTOR. The first build of
 * this card posted to Fortify's `DELETE /user/two-factor-authentication`, which clears the secret
 * the moment the button is pressed. A move abandoned there leaves an account that signs in on its
 * password alone, and a leaked password is exactly what mandatory two-factor exists to stop. The
 * move is now confirm-then-swap (`App\Http\Controllers\Auth\TwoFactorMoveController`): the new
 * secret lives in the session until a code from it is entered, and only then replaces the old one.
 * So the arms below assert the account's state at EVERY step, not only at the end.
 *
 * ⚠ THE FULL-MOVE ARM DRIVES THE FORMS THE PAGES RENDER. It reads each form's target out of the
 * HTML, and the new secret out of the move page, before using them, so a page that pointed its
 * button somewhere else, or showed a different secret from the one confirmed, would red.
 *
 * ⚠ ONE-TIME CODES ARE MINTED PER 30-SECOND PERIOD AND FORTIFY REFUSES A CODE IT HAS ALREADY
 * ACCEPTED (`TwoFactorAuthenticationProvider::verify` caches each accepted code). An arm that
 * enters a second code from the same secret takes the NEXT period's code (`otp(..., 1)`), which the
 * default window of one period accepts, so a refusal can only mean what the arm says it means.
 */
class TwoFactorMoveAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    private function confirmPassword(): void
    {
        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();
    }

    private function secretOf(User $user): string
    {
        return Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    }

    private function otp(string $secret, int $periodsAhead = 0): string
    {
        $engine = app(Google2FA::class);

        return $engine->oathTotp($secret, $engine->getTimestamp() + $periodsAhead);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(User $user): array
    {
        return (array) DB::table('users')->where('id', $user->id)->first();
    }

    private function assertEnrolled(User $user, string $step): void
    {
        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment(), "enrolment reads false {$step}");
    }

    /**
     * Press the start button the recovery codes page renders.
     */
    private function startTheMoveFromTheCodesPage(): void
    {
        $html = $this->get(route('two-factor.codes'))->assertOk()->getContent();

        $this->assertSame(1, preg_match(
            '#<form method="POST" action="([^"]+)">\s*<input type="hidden" name="_token"[^>]*>\s*<button type="submit">Move to a new authenticator</button>#',
            $html,
            $form,
        ), 'the codes page renders the start form');
        $this->assertSame(route('two-factor.move.start'), $form[1]);

        $this->post($form[1])->assertRedirect(route('two-factor.move'));
    }

    /**
     * The setup key the move page shows, and the confirm form's target.
     *
     * @return array{secret: string, confirm: string, html: string}
     */
    private function theMovePage(): array
    {
        $html = $this->get(route('two-factor.move'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#Setup key: <code>([A-Z2-7]+)</code>#', $html, $key), 'the move page shows a setup key');
        $this->assertSame(1, preg_match(
            '#<form method="POST" action="([^"]+)">\s*<input type="hidden" name="_token"[^>]*>\s*<label for="code">#',
            $html,
            $form,
        ), 'the move page renders a code form');

        return ['secret' => $key[1], 'confirm' => $form[1], 'html' => $html];
    }

    private function signInPastThePassword(User $user): void
    {
        $this->post(route('logout'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    // ── (a) The whole move ──────────────────────────────────────────────────────────────────

    /**
     * ⛔ THE CARD'S CORE ARM. Start, and the old authenticator still signs in while the move is
     * pending. Confirm with a code from the new one, and the old code and an old recovery code are
     * refused while the new ones are accepted.
     */
    public function test_a_confirmed_account_moves_to_a_new_authenticator_and_keeps_the_old_one_until_it_confirms(): void
    {
        Event::fake([
            TwoFactorAuthenticationConfirmed::class,
            RecoveryCodesGenerated::class,
            TwoFactorAuthenticationEnabled::class,
            TwoFactorAuthenticationDisabled::class,
        ]);

        $user = User::factory()->twoFactorConfirmed()->create();
        $oldSecret = $this->secretOf($user);
        $oldCodes = $user->recoveryCodes();

        $this->actingAs($user);
        $this->confirmPassword();

        // The codes page states the consequences before the start button, and no longer links the
        // route that turns two-factor off.
        $codesPage = $this->get(route('two-factor.codes'))->assertOk();
        $codesPage->assertDontSee(route('two-factor.disable'), false);
        $codesPage->assertDontSee('password alone');
        // Whitespace is collapsed first so the view's line wrapping is free to move.
        $flat = preg_replace('/\s+/', ' ', $codesPage->getContent());
        $offset = 0;
        foreach ([
            '<h2>Move to a new authenticator</h2>',
            'Your current authenticator keeps working until the new one is confirmed.',
            'Once the move is confirmed, the entry in your current authenticator app stops working, and every recovery code on this page stops working.',
            '<button type="submit">Move to a new authenticator</button>',
        ] as $phrase) {
            $at = strpos($flat, $phrase, $offset);
            $this->assertNotFalse($at, "the codes page says, in this order: {$phrase}");
            $offset = $at + strlen($phrase);
        }

        $this->startTheMoveFromTheCodesPage();
        $this->assertEnrolled($user, 'after starting the move');
        $this->assertSame($oldSecret, $this->secretOf($user->fresh()));

        $move = $this->theMovePage();
        $newSecret = $move['secret'];
        $this->assertNotSame($oldSecret, $newSecret);
        $this->assertSame(route('two-factor.move.confirm'), $move['confirm']);

        // The page's QR code is the PENDING secret's, under the issuer the enrolment page uses.
        $this->assertStringContainsString($user->twoFactorQrCodeSvgFor($newSecret), $move['html']);
        $this->assertStringNotContainsString($user->fresh()->twoFactorQrCodeSvg(), $move['html']);
        parse_str((string) parse_url($user->twoFactorQrCodeUrlFor($newSecret), PHP_URL_QUERY), $otpauth);
        $this->assertSame($newSecret, $otpauth['secret']);
        $this->assertSame(TwoFactorIssuer::resolve(), $otpauth['issuer']);

        // While the move is pending, the OLD authenticator signs in — from a second browser, so the
        // session holding the pending move is put back afterwards.
        $tab = $this->app['session']->all();
        $this->signInPastThePassword($user);
        $this->post('/two-factor-challenge', ['code' => $this->otp($oldSecret)]);
        $this->assertAuthenticatedAs($user);
        $this->assertEnrolled($user, 'while the move is pending');
        $this->flushSession();
        $this->withSession($tab);

        $this->post($move['confirm'], ['code' => $this->otp($newSecret)])
            ->assertRedirect(route('two-factor.codes'));

        $moved = $user->fresh();
        $this->assertTrue($moved->hasCompletedTwoFactorEnrolment());
        $this->assertSame($newSecret, $this->secretOf($moved));
        $newCodes = $moved->recoveryCodes();
        $this->assertCount(8, $newCodes);
        $this->assertSame([], array_values(array_intersect($oldCodes, $newCodes)), 'no old recovery code survives');

        Event::assertDispatchedTimes(TwoFactorAuthenticationConfirmed::class, 1);
        Event::assertDispatchedTimes(RecoveryCodesGenerated::class, 1);
        Event::assertNotDispatched(TwoFactorAuthenticationEnabled::class);
        Event::assertNotDispatched(TwoFactorAuthenticationDisabled::class);

        // The landing shows the NEW codes and says what happened.
        $landing = $this->get(route('two-factor.codes'))->assertOk();
        $landing->assertSee('Your new authenticator is confirmed.');
        foreach ($newCodes as $code) {
            $landing->assertSee($code);
        }
        foreach ($oldCodes as $code) {
            $landing->assertDontSee($code);
        }

        // The move is over: its page has nothing to show.
        $this->get(route('two-factor.move'))->assertRedirect(route('two-factor.codes'));

        // The old authenticator and an old recovery code no longer sign in; the new ones do.
        $this->signInPastThePassword($user);

        $oldCode = $this->otp($oldSecret, 1);
        $this->assertTrue(app(Google2FA::class)->verifyKey($oldSecret, $oldCode), 'the control: this code is valid for the OLD secret');
        $this->post('/two-factor-challenge', ['code' => $oldCode]);
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => $oldCodes[0]]);
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $this->otp($newSecret, 1)]);
        $this->assertAuthenticatedAs($moved);

        $this->signInPastThePassword($user);
        $this->post('/two-factor-challenge', ['recovery_code' => $newCodes[0]]);
        $this->assertAuthenticatedAs($moved);
    }

    // ── (b) An abandoned move ───────────────────────────────────────────────────────────────

    /**
     * ⛔ THE HAZARD ITSELF. A move started and never confirmed leaves the account exactly as it was:
     * the old authenticator and the old recovery codes sign in, and enrolment never reads false.
     */
    public function test_an_abandoned_move_leaves_the_account_enrolled_on_the_old_secret_and_codes(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $oldSecret = $this->secretOf($user);
        $oldCodes = $user->recoveryCodes();

        $this->actingAs($user);
        $this->confirmPassword();
        $this->assertEnrolled($user, 'before the move');

        $this->startTheMoveFromTheCodesPage();
        $this->assertEnrolled($user, 'after starting the move');

        $this->theMovePage();
        $this->assertEnrolled($user, 'with the move page open');

        $this->signInPastThePassword($user);
        $this->assertEnrolled($user, 'after signing out mid-move');

        $this->post('/two-factor-challenge', ['code' => $this->otp($oldSecret)]);
        $this->assertAuthenticatedAs($user);
        $this->assertEnrolled($user, 'after signing in again');
        $this->get(route('dashboard'))->assertOk();

        $after = $user->fresh();
        $this->assertSame($oldSecret, $this->secretOf($after));
        $this->assertSame($oldCodes, $after->recoveryCodes());

        // The abandoned move went with the session it lived in.
        $this->confirmPassword();
        $this->get(route('two-factor.move'))->assertRedirect(route('two-factor.codes'));

        $this->signInPastThePassword($user);
        $this->post('/two-factor-challenge', ['recovery_code' => $oldCodes[0]]);
        $this->assertAuthenticatedAs($user);
        $this->assertEnrolled($user, 'after signing in with an old recovery code');
    }

    // ── (c) A wrong code ────────────────────────────────────────────────────────────────────

    public function test_a_wrong_code_changes_nothing_and_keeps_the_pending_secret(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $oldSecret = $this->secretOf($user);

        $this->actingAs($user);
        $this->confirmPassword();
        $this->startTheMoveFromTheCodesPage();
        $newSecret = $this->theMovePage()['secret'];
        $before = $this->rowOf($user);

        // The OLD authenticator's code is the likeliest wrong code a person enters here — checked,
        // not assumed, to be one the new secret refuses.
        $wrong = $this->otp($oldSecret);
        $this->assertFalse(app(Google2FA::class)->verifyKey($newSecret, $wrong), 'the control: the new secret refuses this code');

        $landing = $this->followingRedirects()
            ->post(route('two-factor.move.confirm'), ['code' => $wrong])
            ->assertOk();
        $this->assertSame(route('two-factor.move'), url()->current());
        $landing->assertSee('The provided two factor authentication code was invalid.');
        $landing->assertSee("Setup key: <code>{$newSecret}</code>", false);

        $this->assertSame($before, $this->rowOf($user));
        $this->assertEnrolled($user, 'after a wrong code');
        $this->assertSame($newSecret, $this->theMovePage()['secret'], 'the pending secret is kept');

        // The control: the kept secret still completes the move.
        $this->post(route('two-factor.move.confirm'), ['code' => $this->otp($newSecret)])
            ->assertRedirect(route('two-factor.codes'));
        $this->assertSame($newSecret, $this->secretOf($user->fresh()));
    }

    // ── (d) No move in progress ─────────────────────────────────────────────────────────────

    /**
     * The code sent is valid for the account's CURRENT secret, so a confirm that fell back to the
     * stored secret would pass verification and rewrite the codes — and the row comparison reds.
     */
    public function test_confirm_with_no_move_in_progress_is_refused_and_changes_nothing(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $before = $this->rowOf($user);

        $this->actingAs($user);
        $this->confirmPassword();

        $this->followingRedirects()
            ->post(route('two-factor.move.confirm'), ['code' => $this->otp($this->secretOf($user))])
            ->assertOk()
            ->assertSee('No move to a new authenticator is in progress, so nothing was changed.');
        $this->assertSame(route('two-factor.codes'), url()->current());

        $this->assertSame($before, $this->rowOf($user));
        $this->assertEnrolled($user, 'after a confirm with no move');
    }

    // ── (e) The gates ───────────────────────────────────────────────────────────────────────

    /**
     * ⛔ A SESSION ALONE MUST NOT START OR FINISH A MOVE — neither one that never re-proved the
     * password nor one whose confirmation has aged past `auth.password_timeout` (`config/auth.php`).
     * This stops a session with no password confirmation inside that window from enrolling a device
     * of its own; a session that confirmed the password inside it passes.
     */
    public function test_the_move_is_refused_without_a_recent_password_confirmation(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $before = $this->rowOf($user);

        $this->actingAs($user);

        $this->post(route('two-factor.move.start'))->assertRedirect(route('password.confirm'));
        $this->assertFalse(session()->has(TwoFactorMoveController::PENDING_SECRET));
        $this->get(route('two-factor.move'))->assertRedirect(route('password.confirm'));
        $this->post(route('two-factor.move.confirm'), ['code' => '123456'])->assertRedirect(route('password.confirm'));
        $this->assertSame($before, $this->rowOf($user));

        // A move started under a confirmation that has since expired cannot be finished, even with
        // the right code.
        $this->confirmPassword();
        $this->startTheMoveFromTheCodesPage();
        $newSecret = $this->theMovePage()['secret'];
        $this->travel(config('auth.password_timeout') + 1)->seconds();

        $this->post(route('two-factor.move.confirm'), ['code' => $this->otp($newSecret)])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame($before, $this->rowOf($user));

        $this->post(route('two-factor.move.start'))->assertRedirect(route('password.confirm'));
        $this->assertSame($before, $this->rowOf($user));
    }

    public function test_a_guest_cannot_start_see_or_confirm_a_move(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $before = $this->rowOf($user);

        $this->post(route('two-factor.move.start'))->assertRedirect(route('login'));
        $this->get(route('two-factor.move'))->assertRedirect(route('login'));
        $this->post(route('two-factor.move.confirm'), ['code' => '123456'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse(session()->has(TwoFactorMoveController::PENDING_SECRET));
        $this->assertSame($before, $this->rowOf($user));
    }

    /**
     * The three routes sit behind the recovery codes page's gates, and the confirm is throttled by a
     * limiter that exists and keys on the signed-in account.
     */
    public function test_the_move_routes_carry_auth_mfa_password_confirm_and_a_throttle(): void
    {
        foreach ([['two-factor.move.start', 'POST'], ['two-factor.move', 'GET'], ['two-factor.move.confirm', 'POST']] as [$name, $method]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route) => $route->getName() === $name && in_array($method, $route->methods(), true));
            $this->assertNotNull($route, "the route {$name} ({$method}) is not registered");

            $middleware = $route->gatherMiddleware();
            foreach (['auth', 'mfa', 'password.confirm'] as $gate) {
                $this->assertContains($gate, $middleware, "{$name} lacks {$gate}");
            }
        }

        $this->assertContains('throttle:two-factor-move', Route::getRoutes()->getByName('two-factor.move.confirm')->gatherMiddleware());

        $user = User::factory()->twoFactorConfirmed()->make(['id' => 4242]);
        $request = Request::create('/two-factor/move/confirm', 'POST');
        $request->setUserResolver(fn () => $user);

        $limit = call_user_func(app(RateLimiter::class)->limiter('two-factor-move'), $request);
        $this->assertSame('two-factor-move:user:4242', $limit->key);
    }

    // ── (f) The pending secret stays out of the users table ────────────────────────────────

    public function test_the_pending_secret_lives_encrypted_in_the_session_and_never_in_the_users_table(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $before = $this->rowOf($user);

        $this->actingAs($user);
        $this->confirmPassword();
        $this->startTheMoveFromTheCodesPage();

        $this->assertSame($before, $this->rowOf($user), 'starting a move wrote to the users row');

        $newSecret = $this->theMovePage()['secret'];
        $stored = session(TwoFactorMoveController::PENDING_SECRET);
        $this->assertSame($user->getKey(), $stored['user_id'], 'the pending move names the user who started it');
        $this->assertIsString($stored['secret']);
        $this->assertStringNotContainsString($newSecret, serialize($stored), 'the session holds the secret in plaintext');
        $this->assertSame($newSecret, Fortify::currentEncrypter()->decrypt($stored['secret']));

        // Starting again replaces the pending secret, still without touching the row.
        $this->startTheMoveFromTheCodesPage();
        $this->assertNotSame($newSecret, $this->theMovePage()['secret']);
        $this->assertSame($before, $this->rowOf($user));
    }

    // ── (g) Another account in the same session ────────────────────────────────────────────

    /**
     * ⛔ A PENDING MOVE BELONGS TO THE ACCOUNT THAT STARTED IT, NOT TO THE SESSION. A session can
     * outlive its account's sign-in and be carried into a second account's (a guest session, then a
     * login on that browser). That second account must not be shown the first one's pending secret
     * as its own new authenticator, and must not be able to confirm it onto either row.
     */
    public function test_a_move_started_by_one_account_is_not_shown_or_confirmed_for_another_in_the_same_session(): void
    {
        $starter = User::factory()->twoFactorConfirmed()->create();
        $other = User::factory()->twoFactorConfirmed()->create();
        $starterBefore = $this->rowOf($starter);
        $otherBefore = $this->rowOf($other);

        $this->actingAs($starter);
        $this->confirmPassword();
        $this->startTheMoveFromTheCodesPage();
        $pendingSecret = $this->theMovePage()['secret'];

        // The same session, now authenticated as the other account.
        $this->actingAs($other);
        $this->assertTrue(session()->has(TwoFactorMoveController::PENDING_SECRET), 'the control: the session still holds the starter\'s move');

        $this->get(route('two-factor.move'))
            ->assertRedirect(route('two-factor.codes'))
            ->assertDontSee($pendingSecret, false);

        $this->followingRedirects()
            ->post(route('two-factor.move.confirm'), ['code' => $this->otp($pendingSecret)])
            ->assertOk()
            ->assertSee('No move to a new authenticator is in progress, so nothing was changed.')
            ->assertDontSee($pendingSecret, false);

        $this->assertSame($otherBefore, $this->rowOf($other));
        $this->assertSame($starterBefore, $this->rowOf($starter));

        // The control: the starter's move is still pending and still completes for the starter.
        $this->actingAs($starter);
        $this->assertSame($pendingSecret, $this->theMovePage()['secret']);
        $this->post(route('two-factor.move.confirm'), ['code' => $this->otp($pendingSecret, 1)])
            ->assertRedirect(route('two-factor.codes'));
        $this->assertSame($pendingSecret, $this->secretOf($starter->fresh()));
        $this->assertSame($otherBefore, $this->rowOf($other));
    }
}

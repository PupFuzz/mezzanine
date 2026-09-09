<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

/**
 * ⛔ THE THING CARD#9070 SAYS IS MOST LIKELY TO SHIP BROKEN: **a newly created account must be
 * able to traverse log in → forced enrolment → dashboard**, and a new account that cannot reach
 * the enrolment screen is a locked-out account that looks exactly like a working console until
 * somebody tries it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE ACCOUNT HERE IS MINTED BY `mezzanine:user:create` AND BY NOTHING ELSE — not by
 * `User::factory()`, whose `twoFactorUnenrolled()` state sets the same three columns to null but
 * would prove only that the FIXTURE is unenrolled. The defect this arm exists to catch is one
 * where the CREATION path leaves an account in a state the enrolment path cannot serve, and a
 * factory-made user cannot show that either way.
 *
 * ⚠ THE ENROLMENT IS DRIVEN THROUGH FORTIFY'S REAL ROUTES, INCLUDING THE PASSWORD CONFIRMATION IN
 * FRONT OF THEM (`confirmPassword => true` in `config/fortify.php`), AND THE CODE IS A REAL TOTP
 * COMPUTED FROM THE SECRET THE SERVER GENERATED. A hand-set `two_factor_confirmed_at` would skip
 * exactly the steps that can be broken.
 */
class FreshUserReachesTheDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'fresh@example.com';

    private const PASSWORD = 'the freshly minted passphrase';

    public function test_an_account_created_by_the_bootstrap_command_logs_in_enrols_and_reaches_the_dashboard(): void
    {
        // ── 1. the account exists because the operator ran the command, on an empty table ──
        $this->assertSame(0, User::query()->count());

        $this->artisan('mezzanine:user:create', ['--name' => 'Fresh', '--email' => self::EMAIL])
            ->expectsQuestion('Password (not echoed)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $user = User::query()->sole();
        $this->assertNull($user->two_factor_secret, 'a genuinely fresh account: no second factor at all');
        $this->assertFalse($user->hasCompletedTwoFactorEnrolment());

        // ── 2. log in. Fortify lets a user with no second factor straight in on the password ──
        $this->post('/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);

        // ── 3. and every gated surface bounces them to enrolment rather than serving them ──
        $this->get('/dashboard')->assertRedirect(route('two-factor.enroll'));
        $this->get(route('admin.users.index'))->assertRedirect(route('two-factor.enroll'));

        // ── 4. the enrolment screen itself is REACHABLE. This is the arm that reds if enrolment
        //       is ever put behind `mfa`: it would redirect to itself and the account would be
        //       locked out with no way to clear the refusal. ──
        $this->get(route('two-factor.enroll'))->assertOk();

        // ── 5. enrol, through Fortify's own routes, past its password confirmation ──
        $this->post('/user/confirm-password', ['password' => self::PASSWORD])->assertRedirect();
        $this->post('/user/two-factor-authentication')->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret, 'the server generated a secret');
        $this->assertFalse($user->hasCompletedTwoFactorEnrolment(), 'and it is not confirmed yet');

        // The enrolment page now shows the QR code the operator scans — asserted because a page
        // that renders the "generate" button forever is the same lockout in a different shape.
        $this->get(route('two-factor.enroll'))->assertOk()->assertSee('<svg', false);

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $this->post('/user/confirmed-two-factor-authentication', [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment());

        // ── 6. and only now does the floor open ──
        $this->get('/dashboard')->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.index'))->assertOk();
    }

    /**
     * THE CONTROL FOR STEP 4 — the enrolment screen is reachable because it is OUTSIDE the `mfa`
     * middleware, not because that middleware is asleep. Asserted on the route's own stack, so a
     * change that moves the route into the gated group reds here as well as in the traverse above.
     */
    public function test_the_enrolment_route_is_behind_auth_and_deliberately_outside_mfa(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'two-factor.enroll');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth', $middleware, 'it is not a public page');
        $this->assertNotContains('mfa', $middleware, 'gating it would make it a redirect loop');
    }
}

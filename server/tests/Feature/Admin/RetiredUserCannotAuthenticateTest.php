<?php

namespace Tests\Feature\Admin;

use App\Admin\UserRetirement;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ THE HALF OF D2 THAT MAKES RETIREMENT MEAN ANYTHING: a retired account cannot authenticate.
 *
 * "Retire, do not delete" is only the substance of a delete if the account actually stops working.
 * A build where retirement merely hides the row from the console's list would pass every arm of
 * `UserManagementTest` — the row is retired, the author and reason are recorded, the record
 * survives — and would leave a dismissed operator able to sign in. That build is what this file
 * exists to red, so every arm here drives a REAL credential path rather than the console.
 *
 * The three paths a check written at the login route would leave open:
 *   1. the login form                          — `retrieveByCredentials`
 *   2. an already-live browser session         — `retrieveById`, on every request
 *   3. a `remember me` cookie                  — `retrieveByToken`
 * All three go through the guard's user provider, which is why the filter lives there
 * (`App\Auth\ActiveUserProvider`) and not in a controller. A FOURTH — Fortify's pending
 * two-factor challenge — does NOT go through the provider, and the last two arms of this file
 * carry that measurement and the reason it is unreachable rather than open.
 */
class RetiredUserCannotAuthenticateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The factory's password, read from the factory rather than restated. It is minted at random
     * per run (`Database\Factories\UserFactory::password()`), so there is no literal to keep in
     * step here — which is the point: the previous spelling of this line WAS the literal, in a
     * third place the review that removed it from two did not reach.
     */
    private static function password(): string
    {
        return UserFactory::password();
    }

    private function retired(): User
    {
        $user = User::factory()->twoFactorUnenrolled()->create();

        // Retired through the real act, with a second account present so D4 does not fire.
        User::factory()->create();
        $this->assertSame(UserRetirement::RETIRED, UserRetirement::retire($user, 'ops@example.com', 'left'));

        return $user->fresh();
    }

    public function test_a_retired_account_cannot_sign_in(): void
    {
        $user = $this->retired();

        $this->post('/login', ['email' => $user->email, 'password' => self::password()])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * THE CONTROL: the same password, the same route, an account that was NOT retired. Without it
     * the arm above would pass against a build whose login is simply broken.
     */
    public function test_control_an_active_account_with_the_same_password_signs_in(): void
    {
        $active = User::factory()->twoFactorUnenrolled()->create();

        $this->post('/login', ['email' => $active->email, 'password' => self::password()]);

        $this->assertAuthenticatedAs($active);
    }

    /**
     * ⛔ THE THREE PATHS A LOGIN-ROUTE CHECK WOULD MISS, ASSERTED ON THE PROVIDER ITSELF.
     *
     * `SessionGuard` resolves the signed-in user on EVERY request with `retrieveById()`, a
     * `remember me` cookie with `retrieveByToken()`, and Fortify's pending two-factor challenge
     * with `retrieveById()` again. All three must answer "nobody" for a retired account — that is
     * what makes retirement take effect on the next request rather than at the next login.
     *
     * ⚠ WHY THIS IS ASSERTED AT THE PROVIDER AND NOT THROUGH TWO HTTP REQUESTS, stated because
     * the HTTP version is the obvious one to write and it is a FALSE PASS. Measured while writing
     * this file: `phpunit.xml` pins `SESSION_DRIVER=array`, which is a null handler — nothing is
     * persisted between two requests in one test — and the only reason a second request looks
     * authenticated at all is that the guard instance caches the user in memory. Drop that cache
     * (`forgetGuards()`) and an ACTIVE user's second request redirects to the login page too, so
     * the "retired user is locked out" arm would pass against a build with no filter whatsoever.
     * A recaller-cookie arm measured the same way: 302 for an active account as well. Each
     * assertion below therefore carries its own active-account control on the same call.
     */
    public function test_the_provider_answers_nobody_for_a_retired_account_on_every_lookup_path(): void
    {
        $retired = $this->retired();
        $active = User::factory()->twoFactorConfirmed()->create();

        $provider = app('auth')->guard('web')->getProvider();

        // ⚠ EVERY ASSERTION BELOW COMPARES A KEY, NEVER A MODEL — INCLUDING THE `assertNull`s,
        // WHICH IS WHERE THIS FILE GOT IT WRONG. A PHPUnit failure prints the value it was given:
        // `assertNull($model)` on a found model prints the whole object — `password`, a bcrypt
        // hash, and `remember_token` — into the test output and from there into a CI log. Measured,
        // not assumed: under the mutant that drops the provider's filter, the round-1 version of
        // this file printed exactly that. The `?->getKey()` on each one is what makes the sentence
        // above true; comparing ids says the same thing and cannot print a secret.

        // 1 — the live session, resolved on every request.
        $this->assertNull($provider->retrieveById($retired->getKey())?->getKey());
        $this->assertSame($active->getKey(), $provider->retrieveById($active->getKey())?->getKey(), 'control');

        // 2 — the login form.
        $this->assertNull($provider->retrieveByCredentials(['email' => $retired->email])?->getKey());
        $this->assertSame(
            $active->getKey(),
            $provider->retrieveByCredentials(['email' => $active->email])?->getKey(),
            'control',
        );

        // 3 — `remember me`.
        $retired->setRememberToken($token = 'a-remember-token-value');
        $retired->save();
        $active->setRememberToken($token);
        $active->save();

        $this->assertNull($provider->retrieveByToken($retired->getKey(), $token)?->getKey());
        $this->assertSame(
            $active->getKey(),
            $provider->retrieveByToken($active->getKey(), $token)?->getKey(),
            'control',
        );
    }

    /**
     * ⛔ A RETIRED ACCOUNT NEVER EVEN OPENS A PENDING TWO-FACTOR CHALLENGE, and that is what
     * closes the one path the provider does not cover.
     *
     * ⚠ NAMED RESIDUAL, MEASURED IN FORTIFY 1.38's SOURCE RATHER THAN ASSUMED:
     * `Laravel\Fortify\Http\Requests\TwoFactorLoginRequest::challengedUser()` resolves the
     * challenged account with `$model::find($session->get('login.id'))` — the raw Eloquent model,
     * NOT the guard's provider — and the controller then calls `$guard->login($user)` with that
     * object. So the retirement filter does not apply to the challenge step itself. What makes
     * that unreachable is the step BEFORE it: `login.id` is written only after
     * `RedirectIfTwoFactorAuthenticatable` has validated the credentials THROUGH the provider,
     * which answers "nobody" for a retired account. The only surviving window is an account
     * retired BETWEEN its password step and its code step, and it buys nothing — the login
     * response is a redirect, and the very next request resolves the session through
     * `retrieveById()`, which refuses. Written down rather than left as a gap somebody re-derives.
     */
    public function test_a_retired_account_never_opens_a_pending_two_factor_challenge(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        User::factory()->create();
        UserRetirement::retire($user, 'ops@example.com', 'left');

        $this->post('/login', ['email' => $user->email, 'password' => self::password()])
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('login.id');

        $this->assertGuest();
    }

    /**
     * THE CONTROL for the arm above: an ACTIVE enrolled account on the same route DOES open a
     * pending challenge. Without it, "no `login.id`" would also be the answer from a build whose
     * login is broken for everybody.
     */
    public function test_control_an_active_enrolled_account_does_open_one(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        $this->post('/login', ['email' => $user->email, 'password' => self::password()])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->getKey());

        $this->assertGuest();
    }
}

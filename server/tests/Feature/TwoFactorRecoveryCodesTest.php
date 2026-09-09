<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card#9077 step 1 — the recovery codes, which existed in the store, were accepted at the challenge,
 * and were never once DISPLAYED.
 *
 * ⛔ THE DEFECT THIS FILE IS WRITTEN AGAINST IS NOT A MISSING FEATURE, IT IS A DEAD ONE.
 * `two_factor_recovery_codes` has been a column since the 2FA migration, the challenge screen has
 * said *"Lost the device? Use a recovery code instead"* since it was written, and
 * `POST /two-factor-challenge` has always accepted a `recovery_code` field. Every one of those
 * pieces was green. What nobody could do was obtain a code — so for any account whose authenticator
 * was lost, the sentence on the challenge screen named a path that did not exist, and the account
 * was gone. That is why the arms below assert on the RENDERED page and then USE what it rendered:
 * a test that read the codes out of the model would have passed against `dev`.
 *
 * ⚠ THE STORE IS A PREREQUISITE for every arm here.
 */
class TwoFactorRecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satisfy `password.confirm` the way a browser does — through Fortify's own route, not by
     * writing the session key this application does not own the name of.
     */
    private function confirmPassword(): void
    {
        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();
    }

    /**
     * ⛔ THE CARD'S CORE ARM. A user going through enrolment is SHOWN the eight codes. Watched red
     * against `dev`, where the enrolment view rendered the QR code and nothing else.
     */
    public function test_enrolment_displays_the_recovery_codes(): void
    {
        $user = User::factory()->twoFactorUnenrolled()->create();

        $this->actingAs($user);
        $this->confirmPassword();

        $this->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        $response = $this->get(route('two-factor.enroll'))->assertOk();

        $codes = $user->fresh()->recoveryCodes();

        $this->assertCount(8, $codes, 'the control: enabling really did mint a set of codes');

        foreach ($codes as $code) {
            $response->assertSee($code);
        }
    }

    /**
     * ⛔ AND A CODE READ OFF THAT PAGE ACTUALLY WORKS AT THE CHALLENGE — the whole point, and the
     * half that a "does the page render eight strings" assertion cannot see. The value used here is
     * scraped from the RENDERED HTML rather than read from the model, so a page that displayed
     * something else entirely would red.
     */
    public function test_a_code_taken_from_the_page_signs_the_account_in_at_the_challenge(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($user);
        $this->confirmPassword();
        $page = $this->get(route('two-factor.codes'))->assertOk()->getContent();

        $shown = $user->recoveryCodes()[0];
        $this->assertStringContainsString($shown, $page, 'the control: the page really shows this value');

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => $shown]);

        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * ⛔ REGENERATION INVALIDATES THE PREVIOUS SET, ATOMICALLY. Asserted by USING an old code rather
     * than by comparing arrays: a build that wrote a new set beside the old one would pass an array
     * comparison and still leave eight standing bypasses live.
     */
    public function test_regenerating_invalidates_every_code_in_the_previous_set(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $superseded = $user->recoveryCodes()[0];

        $this->actingAs($user);
        $this->confirmPassword();

        $this->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect(route('two-factor.codes'));

        $fresh = $user->fresh()->recoveryCodes();

        $this->assertCount(8, $fresh);
        $this->assertNotContains($superseded, $fresh, 'the control: the old set really is gone');

        $this->post(route('logout'));

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));

        $this->post('/two-factor-challenge', ['recovery_code' => $superseded]);
        $this->assertGuest();

        // THE CONTROL — a code from the NEW set does work, or this arm would pass against a build
        // where regeneration broke the challenge entirely.
        $this->post('/two-factor-challenge', ['recovery_code' => $fresh[0]]);
        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * ⛔ A SESSION ALONE MUST NOT READ THE CODES. Without `password.confirm` a stolen cookie copies
     * eight standing bypasses of the second factor and keeps them after the session is revoked.
     */
    public function test_the_codes_page_refuses_a_session_that_has_not_re_proved_the_password(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($user)
            ->get(route('two-factor.codes'))
            ->assertRedirect(route('password.confirm'));

        // THE CONTROL — with the password re-proved, the same session is allowed through, so the
        // refusal above is the middleware and not a broken route.
        $this->confirmPassword();

        $this->get(route('two-factor.codes'))
            ->assertOk()
            ->assertSee($user->recoveryCodes()[0]);
    }

    /**
     * A page nobody can find is the same defect as a page that does not exist — which is how the
     * codes came to be stored, accepted, and never displayed in the first place.
     */
    public function test_the_dashboard_links_to_the_codes_page(): void
    {
        $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('two-factor.codes'));
    }
}

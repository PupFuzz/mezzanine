<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three surfaces card #7334 gates, each observed REFUSING before it is observed allowing.
 *
 * There are three states a request can be in, and only the third may pass:
 *
 *   1. no session at all                          — `auth` refuses
 *   2. a session that proved a password only      — `auth` PASSES, `mfa` refuses
 *   3. a session whose account has a confirmed
 *      second factor                              — both pass
 *
 * State 2 is the one that matters. Fortify logs an un-enrolled user straight in
 * (RedirectIfTwoFactorAuthenticatable.php:54-62), so a suite that only ever exercised state 1
 * would pass identically against an application with no second factor at all.
 */
class MfaGateTest extends TestCase
{
    use RefreshDatabase;

    private function unenrolled(): User
    {
        return User::factory()->twoFactorUnenrolled()->create();
    }

    private function enrolled(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    // ── GATE 1 — the browser page ────────────────────────────────────────────────────────

    public function test_gate1_browser_page_refuses_a_session_with_no_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_gate1_browser_page_refuses_a_password_only_session(): void
    {
        $this->actingAs($this->unenrolled())
            ->get('/dashboard')
            ->assertRedirect(route('two-factor.enroll'));
    }

    public function test_gate1_browser_page_allows_a_second_factor_session(): void
    {
        $this->actingAs($this->enrolled())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('confirmed second factor');
    }

    // ── GATE 2 — the websocket handshake (private-channel authorization) ─────────────────

    public function test_gate2_websocket_handshake_refuses_a_session_with_no_login(): void
    {
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-fleet',
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }

    public function test_gate2_websocket_handshake_refuses_a_password_only_session(): void
    {
        $this->actingAs($this->unenrolled())
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-fleet',
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'two_factor_required');
    }

    public function test_gate2_websocket_handshake_allows_a_second_factor_session(): void
    {
        $this->actingAs($this->enrolled())
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-fleet',
                'socket_id' => '1234.5678',
            ])
            ->assertOk();
    }

    // ── GATE 3 — the REST snapshot ───────────────────────────────────────────────────────

    public function test_gate3_rest_snapshot_refuses_a_session_with_no_login(): void
    {
        $response = $this->getJson('/api/fleet/snapshot');

        $response->assertUnauthorized();

        // Asserted explicitly, not implied by the status: a redirect here would answer any
        // client that follows it with 200 and a login page.
        $this->assertFalse($response->isRedirect(), 'The snapshot must never refuse by redirect.');
    }

    public function test_gate3_rest_snapshot_refuses_a_password_only_session(): void
    {
        $this->actingAs($this->unenrolled())
            ->getJson('/api/fleet/snapshot')
            ->assertForbidden()
            ->assertJsonPath('error', 'two_factor_required');
    }

    public function test_gate3_rest_snapshot_allows_a_second_factor_session(): void
    {
        // ⚠ THIS ASSERTION USED TO BE `501 not_implemented` and now is `200`, because the body
        // card #7827 landed is the thing the 501 stood in for. What is being tested is unchanged
        // and is the same property either way: the request REACHED THE ACTION. A refusal would be
        // 401 or 403 and would never get here.
        //
        // `api_version` rather than `installs`: the fleet is legitimately empty in this fixture
        // (no seat has been provisioned), and asserting on a member that is present WHATEVER the
        // fleet contains is what keeps this a gate test rather than a snapshot test — the
        // snapshot's own content is `FleetReadPlaneTest`'s.
        $this->actingAs($this->enrolled())
            ->getJson('/api/fleet/snapshot')
            ->assertOk()
            ->assertJsonPath('api_version', 1);
    }

    // ── The gate's own preconditions ────────────────────────────────────────────────────

    public function test_an_unenrolled_user_can_still_reach_the_enrolment_screen(): void
    {
        // If this ever refuses, the mandatory-MFA posture becomes a lockout: the only screen
        // that clears the refusal would itself be refused.
        $this->actingAs($this->unenrolled())
            ->get('/two-factor-enroll')
            ->assertOk();
    }

    public function test_a_password_only_login_does_not_reach_the_dashboard(): void
    {
        // End to end through Fortify's real login route rather than actingAs(), because the
        // fail-open being guarded against lives in the login PIPELINE.
        $user = $this->unenrolled();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);

        $this->get('/dashboard')->assertRedirect(route('two-factor.enroll'));
    }

    public function test_an_enrolled_user_is_challenged_rather_than_logged_in(): void
    {
        $user = $this->enrolled();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }
}

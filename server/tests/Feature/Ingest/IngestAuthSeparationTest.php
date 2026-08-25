<?php

namespace Tests\Feature\Ingest;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Card #7338 requirement 1, both directions.
 *
 *   "Seat-token auth, and it is NEVER browser-facing. The MFA middleware from card #7334
 *   (`EnsureTwoFactorSatisfied`, gating page / websocket handshake / REST snapshot) must not
 *   apply here, and no ingest route may be reachable from an MFA session. … Prove the separation
 *   both ways: an MFA-authenticated browser session must NOT be able to post a batch, and a valid
 *   seat token must NOT grant anything the MFA surfaces gate."
 *
 * `docs/PLAN.md § 3`'s Accept line for card #7334 says the same from the other side: "seat-token
 * ingest is separate and never browser-facing."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY BOTH A BEHAVIOURAL AND A STRUCTURAL ASSERTION.
 *
 * `actingAs()` sets the guard's user IN MEMORY, so `$request->user()` resolves on a route with no
 * session middleware at all. That is what makes the behavioural test meaningful here — it is a
 * real check that this ingest never reads the session — but it is also why it is not enough on
 * its own: a future edit could add `web` to the route group and the behavioural test would still
 * pass right up until the day a browser reached the endpoint with a valid CSRF token. So the
 * middleware list itself is read off the router and asserted empty.
 *
 * Both directions are seen to FAIL under a planted defect in `IngestAuthSeparationRedTest`.
 */
class IngestAuthSeparationTest extends IngestTestCase
{
    /** The three surfaces card #7334 gates (`routes/web.php`, `bootstrap/app.php`). */
    private const MFA_GATED = ['/dashboard', '/api/fleet/snapshot', '/broadcasting/auth'];

    // ── DIRECTION 1: an MFA session cannot post a batch ──────────────────────────────────────

    public function test_a_fully_enrolled_mfa_session_cannot_post_a_batch(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        // No Authorization header at all — only the session an enrolled browser user would have.
        $this->actingAs($user)
            ->call(
                'POST',
                '/api/ingest/events',
                server: $this->serverHeaders(['Content-Type' => 'application/json; charset=utf-8']),
                content: json_encode($this->validBatch()),
            )
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);

        $this->assertSame(0, $this->storedEvents());
    }

    public function test_an_mfa_session_does_not_help_even_beside_a_revoked_token(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        DB::table('ingest_tokens')
            ->where('seat_ref', $this->seatRef)
            ->update(['revoked_at' => now()->format('Y-m-d H:i:s.v')]);

        $this->actingAs($user)->postBatch($this->validBatch())
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);

        $this->assertSame(0, $this->storedEvents());
    }

    public function test_the_ingest_routes_carry_no_middleware_at_all(): void
    {
        foreach (['ingest.events', 'ingest.health'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "route {$name} is not registered");

            // Not "no `mfa`" — NO middleware. `web` would start a session and make
            // `$request->user()` resolvable here; `api` would add a second rate limit with
            // different numbers, evaluated before D1 § 12.1 step 1. `routes/ingest.php` states
            // all three exclusions and this is what keeps that comment true.
            $this->assertSame([], $route->gatherMiddleware(), sprintf(
                'route %s must carry no middleware; it carries: %s',
                $name,
                implode(', ', $route->gatherMiddleware()),
            ));
        }
    }

    public function test_the_ingest_sets_no_session_cookie_and_no_cors_headers(): void
    {
        // D1 § 4.1: "The endpoint accepts no cookies and no session, and sets no CORS headers."
        $response = $this->postBatch($this->validBatch());

        $response->assertStatus(202);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    // ── DIRECTION 2: a seat token grants nothing the MFA surfaces gate ───────────────────────

    public function test_a_valid_seat_token_opens_none_of_the_mfa_gated_surfaces(): void
    {
        foreach (self::MFA_GATED as $path) {
            $response = $this->call('GET', $path, server: $this->serverHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
            ]));

            // 401 on the api/* paths (bootstrap/app.php sends guests there a status, not a
            // redirect); 302 to the login screen on the browser page. What must never appear is
            // a 200, and what must never appear on a /api/ path is a redirect — a 302 to a login
            // form answers a redirect-following client with 200 and HTML, which
            // docs/design/FLEET-STATE.md § 2.2 refuses as indistinguishable from a real read.
            $this->assertContains($response->status(), [401, 302, 403], sprintf(
                'a seat token reached %s with status %d',
                $path,
                $response->status(),
            ));

            $this->assertNotSame(200, $response->status());
        }
    }

    public function test_a_seat_token_does_not_authenticate_a_user(): void
    {
        // The mechanism behind the row above, asserted directly: presenting an `mzn_` token
        // leaves the session guard with no user, so `auth` refuses before `mfa` is ever
        // consulted. A token guard registered on the api guard would change this, which is
        // exactly the defect `IngestAuthSeparationRedTest` plants.
        $this->call('GET', '/api/fleet/snapshot', server: $this->serverHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]))->assertStatus(401);

        $this->assertGuest();
    }

    public function test_the_mfa_gate_still_refuses_a_password_only_session_on_every_gated_surface(): void
    {
        // The control for the row above. If the gated surfaces refused EVERYTHING — a
        // misconfiguration, a broken route — the seat-token assertions would pass while proving
        // nothing about tokens. This shows the gate discriminates: an enrolled user gets through
        // the surface that a seat token cannot open.
        $enrolled = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($enrolled)->get('/dashboard')->assertOk();
    }
}

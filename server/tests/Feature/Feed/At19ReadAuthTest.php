<?php

namespace Tests\Feature\Feed;

use App\Fold\Clock;
use App\Http\Middleware\FleetReadGate;
use App\Ingest\Counters;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **AT-D2-19 — read-side auth refuses correctly** (`docs/design/FLEET-STATE.md § 11`, § 9, § 8.6).
 *
 * § 11's BUILD: "four requests to `GET /api/fleet/snapshot` — a valid `mzr_` token, an expired
 * one, a revoked one, and a valid **`mzn_` ingest** token — plus a browser session without MFA."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ ONE DIVERGENCE FROM § 11's GREEN TEXT, AND IT IS D2 DISAGREEING WITH D2 RATHER THAN THE CODE
 * DISAGREEING WITH D2. REPORTED IN CARD #7827's PR BODY.
 *
 * AT-D2-19's GREEN says the MFA-less browser session gets "a redirect to the MFA challenge". On
 * an `/api/fleet/*` path this application answers `403 two_factor_required` instead, and § 2.2 is
 * why: a `302` to a login form "answers any client that follows it with 200 and a login page,
 * which is indistinguishable from a successful read" — the exact shape § 2.2 forbids on this
 * surface. Card #7334 made that call and `App\Http\Middleware\EnsureTwoFactorSatisfied` records
 * it; this test asserts § 2.2's answer, and asserts the property AT-D2-19 actually cares about —
 * "assert the BODY is free of install and seat names, not merely that the status is non-200" —
 * on every refusing branch including that one.
 */
class At19ReadAuthTest extends FeedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fleet with something in it, so "no fleet data in the body" is a claim with teeth: a
        // refusal that leaks nothing from an empty store proves nothing.
        $this->deliver($this->cleanTurn());
        $this->fold();
    }

    // ── the four token branches ──────────────────────────────────────────────────────────────

    public function test_a_valid_read_token_is_served(): void
    {
        $response = $this->asMachine($this->readToken('watchdog'), '/api/fleet/snapshot');

        $response->assertOk()
            ->assertJsonPath('api_version', 1)
            ->assertJsonPath('installs.0.install_id', self::INSTALL)
            ->assertJsonPath('installs.0.seats.0.seat_id', self::SEAT);

        $this->assertSame(1, $this->globalCounter('snapshot_served'));
        $this->assertSame(0, $this->globalCounter('snapshot_denied'));
    }

    public function test_an_expired_token_is_refused_and_leaks_no_fleet(): void
    {
        $token = $this->readToken();

        DB::table('feed_tokens')->update(['expires_at' => Clock::sql(now()->copy()->subSecond())]);

        $response = $this->asMachine($token, '/api/fleet/snapshot');

        $response->assertUnauthorized()->assertJsonPath('error', 'token_expired');
        $this->assertNoFleetData($response->getContent());
    }

    public function test_a_revoked_token_is_refused_with_section_86s_exact_shape(): void
    {
        $token = $this->readToken('watchdog');

        $this->artisan('mezzanine:feed-token:revoke', [
            'prefix' => substr($token, 0, 12),
            '--reason' => 'rotated',
        ])->assertSuccessful();

        $revokedAt = DB::table('feed_tokens')->value('revoked_at');

        $response = $this->asMachine($token, '/api/fleet/snapshot');

        // § 8.6's worked exchange, member for member.
        $response->assertUnauthorized()
            ->assertJsonPath('error', 'token_revoked')
            ->assertJsonPath('revoked_at', Clock::wire($revokedAt))
            ->assertJsonStructure(['error', 'message', 'revoked_at', 'server_time']);

        $this->assertStringContainsString(Clock::wire($revokedAt), $response->json('message'));
        $this->assertNoFleetData($response->getContent());

        // § 8.6, as REQUIRED BEHAVIOUR rather than a status code: "`feed_tokens.last_used_at` and
        // `last_used_ip` are **not** updated (a revoked token's use is recorded in
        // `global_counters` and the log, not on the row, SO A REVOKED ROW CANNOT BE MADE TO LOOK
        // LIVE)".
        $row = DB::table('feed_tokens')->first();
        $this->assertNull($row->last_used_at, 'a revoked token\'s use was recorded on its row');
        $this->assertNull($row->last_used_ip);

        $this->assertSame(1, $this->globalCounter(Counters::REVOKED_TOKEN_PRESENTED));
        $this->assertSame(1, $this->globalCounter('snapshot_denied'));
        $this->assertSame(0, $this->globalCounter('snapshot_served'));
    }

    /**
     * § 11's fourth request, and § 9's rule: "an `mzn_` ingest token is never valid here … a token
     * presented on the wrong surface is `401`, counting `token_wrong_surface`, and an operator
     * alert."
     */
    public function test_an_ingest_token_is_refused_and_counts_the_operator_alert(): void
    {
        $response = $this->asMachine($this->token, '/api/fleet/snapshot');

        $response->assertUnauthorized()->assertJsonPath('error', 'token_wrong_surface');
        $this->assertNoFleetData($response->getContent());

        $this->assertSame(1, $this->globalCounter(Counters::TOKEN_WRONG_SURFACE));
    }

    /** § 11's fifth request. See the class docblock for why this is `403` and not a redirect. */
    public function test_a_browser_session_without_mfa_is_refused_and_leaks_no_fleet(): void
    {
        $unenrolled = User::factory()->create();

        $response = $this->actingAs($unenrolled)->getJson('/api/fleet/snapshot');

        $response->assertForbidden()->assertJsonPath('error', 'two_factor_required');
        $this->assertFalse($response->isRedirect(), '§ 2.2: never a redirect on this surface');
        $this->assertNoFleetData($response->getContent());
    }

    public function test_an_mfa_session_is_served(): void
    {
        $this->actingAs($this->enrolled())
            ->getJson('/api/fleet/snapshot')
            ->assertOk()
            ->assertJsonPath('installs.0.seats.0.seat_id', self::SEAT);
    }

    /**
     * GREEN — NO REVOCATION CACHE. "Revoke a token mid-run and issue the next request immediately
     * → it is refused on the FIRST ATTEMPT, not after a TTL."
     *
     * § 9: revocation is "checked **per request**, never cached — a revoked credential that keeps
     * working for a cache TTL is a revocation that did not happen."
     */
    public function test_a_revocation_bites_on_the_very_next_request(): void
    {
        $token = $this->readToken();

        $this->asMachine($token, '/api/fleet/snapshot')->assertOk();

        DB::table('feed_tokens')->update(['revoked_at' => Clock::sql(now())]);

        // No clock movement, no cache flush, no new process — the immediately following request.
        $this->asMachine($token, '/api/fleet/snapshot')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'token_revoked');
    }

    // ── § 9's two rate limits, each seen to fire and each seen NOT to ────────────────────────

    public function test_the_token_limit_is_120_a_minute(): void
    {
        $token = $this->readToken();

        for ($i = 0; $i < FleetReadGate::TOKEN_LIMIT; $i++) {
            $this->asMachine($token, '/api/fleet/health')->assertOk();
        }

        $this->asMachine($token, '/api/fleet/health')
            ->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limited',
                'limit' => FleetReadGate::TOKEN_LIMIT,
                'window_s' => 60,
                'retry_after_s' => FleetReadGate::RETRY_AFTER_S,
            ]);

        // ⛔ THE CONTROL. § 9 sets the session limit FIVE TIMES higher precisely because "a browser
        // opening drill-downs bursts", so a limiter keyed on the surface rather than on the
        // credential would refuse this session too. It must not.
        $this->actingAs($this->enrolled())->getJson('/api/fleet/health')->assertOk();
    }

    public function test_the_session_limit_is_600_a_minute_and_is_keyed_on_the_user(): void
    {
        $this->actingAs($user = $this->enrolled());

        for ($i = 0; $i < FleetReadGate::SESSION_LIMIT; $i++) {
            $this->getJson('/api/fleet/health')->assertOk();
        }

        $this->getJson('/api/fleet/health')
            ->assertStatus(429)
            ->assertJsonPath('limit', FleetReadGate::SESSION_LIMIT);

        // A DIFFERENT user is unaffected — the limit is keyed on the credential, not on the route.
        $this->actingAs($this->enrolled())->getJson('/api/fleet/health')->assertOk();
    }

    // ── § 8.2's surface table: the timeline is session-only ──────────────────────────────────

    public function test_a_read_token_cannot_reach_the_timeline(): void
    {
        $path = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline';

        $this->asMachine($this->readToken(), $path)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated');

        // The same path, the credential § 8.2 says it takes.
        $this->actingAs($this->enrolled())->getJson($path)->assertOk();
    }

    // ── § 8.6's forbidden outcome, asserted directly ─────────────────────────────────────────

    /**
     * "The one outcome forbidden everywhere on this surface: **a `200` with an empty fleet.**"
     *
     * ⚠ DRIVEN WITH A REAL RAISE, AND HERE IS EXACTLY HOW FAITHFUL IT IS. `seat_state` — the table
     * every read surface's query drives from — is dropped, so the query raises a genuine
     * `QueryException` from the real driver on the real path. What that reproduces is the SHAPE of
     * a store failure (the read raises where the controller must catch it); what it does NOT
     * reproduce is a whole-host outage, in which the token lookup and the counter write would also
     * fail. That second case is named in card #7827's MySQL-unexercised list rather than claimed.
     */
    public function test_a_store_failure_is_a_503_and_never_a_200_with_an_empty_fleet(): void
    {
        $token = $this->readToken();

        Schema::drop('seat_state');

        $response = $this->asMachine($token, '/api/fleet/snapshot');

        $response->assertStatus(503)->assertJsonPath('error', 'fleet_unavailable');
        $this->assertNull($response->json('installs'), 'a refusal carried an installs member');
        $this->assertNoFleetData($response->getContent());

        // § 7.2's pair: "what tells a fleet-health reader that a read plane refusing everything is
        // refusing rather than idle."
        $this->assertSame(1, $this->globalCounter('snapshot_denied'));
        $this->assertSame(0, $this->globalCounter('snapshot_served'));
    }

    /**
     * § 8.2.4's `db: "down"` with `counters: null`, on the one endpoint that carries `counters`.
     *
     * "It is `null` — and **only** — when `db` is `down` … reporting `0` there would be
     * `docs/KANBAN.md § G-1`'s clean zero … `null` says *we could not read these*; `0` would say
     * *nothing has happened*."
     */
    public function test_fleet_health_answers_db_down_with_null_counters_and_never_zeroes(): void
    {
        $token = $this->readToken();

        Schema::drop('seat_state');

        $response = $this->asMachine($token, '/api/fleet/health');

        $response->assertStatus(503)
            ->assertJsonPath('error', 'fleet_unavailable')
            ->assertJsonPath('fleet.db', 'down')
            ->assertJsonPath('fleet.counters', null);

        // ⛔ AND NOT A ZERO ANYWHERE ON THE OBJECT. § 8.2.4 declares five further members non-null
        // and every one of them is read from the store that is down; card #7827 reports that gap
        // and ABSENTS them rather than inventing an `ok` or a `0`, which is the clean zero this
        // whole read posture exists to prevent.
        foreach (['fold', 'sweep', 'max_fold_lag_ms', 'seats_total', 'seats_live'] as $member) {
            $this->assertArrayNotHasKey($member, $response->json('fleet'),
                'a store-down health object carried a member it cannot know');
        }
    }

    // ── helper ───────────────────────────────────────────────────────────────────────────────

    /**
     * § 8.6: "zero seat data appears in the body — NOT A COUNT, NOT AN INSTALL LIST, NOT A SEAT
     * NAME". Asserted on the raw body rather than on decoded members, because a leak that arrives
     * inside a message string is still a leak.
     */
    private function assertNoFleetData(string $body): void
    {
        foreach ([self::INSTALL, self::SEAT, 'installs', 'seats', 'render_state'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'a refusal body carried fleet data: '.$needle);
        }
    }
}

<?php

namespace Tests\Feature\Feed;

use App\Fold\Clock;
use App\Http\Middleware\FleetReadGate;
use App\Ingest\Counters;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

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

    /**
     * ⛔ THE REFUSAL PATH TAKES A SLOT TOO — D1 § 12.3's fourth limit, applied to this plane.
     *
     * Before this, `limit()` sat only on the two SUCCESS branches, so a caller looping bad bearers
     * was never throttled at all: each attempt cost an indexed `feed_tokens` SELECT plus a hot-row
     * `global_counters` UPDATE, unbounded, unauthenticated. This is hardening and not a § 9
     * requirement — § 9 states no read-side failed-auth limit — and D1 is explicit about how
     * little such a limit buys ("not a defence against guessing"); what it bounds is the DB work.
     *
     * The 61st is the one that must refuse, which is § 12.1 step 4's counted-before-read order.
     */
    public function test_a_bad_bearer_takes_a_rate_limit_slot_on_the_refusal_path(): void
    {
        // EVERY request in this test is from ONE address, including both controls. The limit is
        // keyed on the source address, so a control sent from a different one would pass whatever
        // the gate does — which is the shape that makes a control a decoration.
        $ip = self::MACHINE_IP;

        for ($i = 0; $i < FleetReadGate::FAILED_AUTH_LIMIT; $i++) {
            $this->asMachine('mzr_never-existed-'.$i, '/api/fleet/health', $ip)
                ->assertUnauthorized()
                ->assertJsonPath('error', 'unauthenticated');
        }

        $this->asMachine('mzr_never-existed-61', '/api/fleet/health', $ip)
            ->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limited',
                'limit' => FleetReadGate::FAILED_AUTH_LIMIT,
                'window_s' => FleetReadGate::FAILED_AUTH_WINDOW_S,
                'retry_after_s' => FleetReadGate::FAILED_AUTH_RETRY_AFTER_S,
            ]);

        // ⛔ CONTROL 1 — a VALID token from THAT SAME address is unaffected. A limiter that
        // refused this would have turned a hardening measure into an outage lever: anyone able to
        // send 60 bad bearers could darken the watchdog.
        $this->asMachine($this->readToken(), '/api/fleet/health', $ip)->assertOk();

        // ⛔ CONTROL 2 — a caller from that same address presenting NO credential is unaffected.
        // It is unauthenticated, not a failed authentication, and per-IP throttling of the
        // pre-login path would refuse a shared office its own floor.
        $this->asMachine(null, '/api/fleet/health', $ip)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated');
    }

    /**
     * ⛔ A REVOKED OR EXPIRED TOKEN IS NOT A FAILED AUTHENTICATION — D1 § 12.3's own exclusion,
     * and it has to hold on this plane too because the two now share one budget.
     *
     * "A presented token that resolves to a revoked row is counted per token row and alerted on …
     * It is NOT counted as a failed authentication, so it does not consume the log-volume budget."
     * Spending the budget on these would also replace the answer that names the fault
     * (`401 token_revoked`, with `revoked_at`) with one that hides it behind a retry.
     */
    public function test_a_revoked_or_expired_token_does_not_spend_the_failed_auth_budget(): void
    {
        $ip = self::MACHINE_IP;

        $revoked = $this->readToken('revoked');
        $expired = $this->readToken('expired');

        DB::table('feed_tokens')->where('prefix', substr($revoked, 0, 12))
            ->update(['revoked_at' => Clock::sql(now())]);
        DB::table('feed_tokens')->where('prefix', substr($expired, 0, 12))
            ->update(['expires_at' => Clock::sql(now()->copy()->subSecond())]);

        // Far past the limit, on credentials that RESOLVE. Every one must keep naming its fault.
        for ($i = 0; $i <= FleetReadGate::FAILED_AUTH_LIMIT; $i++) {
            $this->asMachine($revoked, '/api/fleet/health', $ip)
                ->assertUnauthorized()->assertJsonPath('error', 'token_revoked');
            $this->asMachine($expired, '/api/fleet/health', $ip)
                ->assertUnauthorized()->assertJsonPath('error', 'token_expired');
        }

        // The budget is untouched, which only a request that DOES spend it can show: one bad
        // bearer must still come back `401`, not `429`.
        $this->asMachine('mzr_never-existed', '/api/fleet/health', $ip)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated');
    }

    /**
     * ⛔ ONE PER-ADDRESS BUDGET ACROSS BOTH PLANES — the reuse asserted, in both directions.
     *
     * D1 § 12.3 keys its failed-auth limit on the SOURCE ADDRESS, and the gate spends that same
     * bucket rather than minting a `read:` twin. The first half proves the bucket really is
     * shared (a read-plane failure moves the INGEST's count), which no assertion inside one plane
     * could show. The second half is the reassurance that sharing costs no telemetry, and it is
     * not a tautology: `hitFailedAuth()` is consulted only from `TokenResolver::fail()`, so a
     * VALID seat token never reads this limit — move that check earlier in `resolve()` and this
     * arm goes red.
     */
    public function test_both_planes_spend_one_failed_auth_budget_per_source_address(): void
    {
        // ⛔ THE REPORTER'S OWN ADDRESS, read from the constant `deliver()` posts from rather than
        // re-typed — the whole test is the claim that these are the SAME address.
        $ip = self::REPORTER_IP;

        for ($i = 0; $i < FleetReadGate::FAILED_AUTH_LIMIT; $i++) {
            $this->asMachine('mzr_never-existed-'.$i, '/api/fleet/health', $ip)->assertUnauthorized();
        }

        // The budget is spent. A BAD INGEST token from that address is now over the limit — and
        // this is a request on the OTHER plane, refused by the OTHER plane's step 4, so a `429`
        // here can only mean the two read one counter.
        $this->postBatchAs('mzn_never-existed', $ip)
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited');

        // …and the seat at that address, holding a VALID token, still delivers. `deliver()`
        // asserts the `202` itself.
        $this->deliver($this->cleanTurn());
    }

    /** A raw ingest POST with a chosen bearer and source address — auth is decided before the body. */
    private function postBatchAs(string $token, string $ip): TestResponse
    {
        return $this->call('POST', '/api/ingest/events', server: [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['schema_version' => 1, 'events' => []]));
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

<?php

namespace Tests\Feature\Feed;

use App\Feed\FeedHeartbeat;
use App\Feed\FleetHealthMessage;
use App\Feed\FleetReload;
use App\Feed\SeatDelta;
use App\Read\FleetHealth;
use App\Read\Snapshot;
use App\Sweep\Purge;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s message table and § 8.2.4's object, as contracts — the
 * things a consumer is told to expect and would otherwise discover by inspection.
 *
 * ⚠ See `CapturingBroadcaster` for what a broadcast assertion in this suite is and is not evidence
 * of. In particular AT-D2-15 (per-connection backpressure) is NOT here and is NOT claimed: it is a
 * property of a socket server that is not installed, and a "backpressure test" written against
 * this rig would close a queue the rig itself invented.
 */
class FeedSurfaceTest extends FeedTestCase
{
    /**
     * ⛔ THE CHANNEL NAME HAS TWO SPELLINGS THAT MUST AGREE, AND NEITHER IS § 8.3's LITERAL TEXT.
     *
     * § 8.3 declares `private-fleet.{install_id}`. Laravel's `PrivateChannel` adds the `private-`
     * prefix on the wire and `Broadcast::channel()` registers the name WITHOUT it, so both halves
     * of this application must spell it `fleet.{install}` and the WIRE must read
     * `private-fleet.…`. Getting either literal-minded produces `private-private-fleet.…`, which
     * fails silently: the publisher publishes to a channel nothing has authorised, and the client
     * subscribes to one nothing publishes to.
     */
    public function test_the_wire_channel_is_private_fleet_install_and_the_authorisation_matches(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        foreach ($this->wire->sent as $message) {
            $this->assertSame(['private-fleet.'.self::INSTALL], $message['channels'],
                '§ 8.3: one channel per install, and the wire name carries the private- prefix');
        }

        $this->assertNotEmpty($this->wire->sent, 'the control failed: nothing was published');

        // The AUTHORISATION half spells the same name, unprefixed. Read off the router's
        // registered channel rather than restated, so the two cannot drift: `routes/channels.php`
        // registers `fleet.{install}` and `FeedEnvelope::broadcastOn()` builds
        // `new PrivateChannel('fleet.'.$install)`, and it is `PrivateChannel` that adds the
        // prefix seen above.
        $this->actingAs($this->enrolled())
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-fleet.'.self::INSTALL,
                'socket_id' => '1234.5678',
            ])
            ->assertOk();
    }

    /** § 8.3's envelope: "every message: `{"feed_version":1,"t":…,"server_time":"…", …}`". */
    public function test_every_message_carries_section_83s_envelope(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();
        $this->retire();

        $types = [];

        foreach ($this->wire->sent as $message) {
            $this->assertSame(1, $message['payload']['feed_version']);
            $this->assertArrayHasKey('t', $message['payload']);
            $this->assertArrayHasKey('server_time', $message['payload']);

            // The event name a client binds to IS `t`. A message whose broadcast name and whose
            // declared type differ is one no consumer can subscribe to from the document.
            $this->assertSame($message['payload']['t'], $message['event']);

            $types[$message['payload']['t']] = true;
        }

        // The four of § 8.3's five this card can produce. `fleet.reload`'s producer is a deploy
        // step that does not exist — see `App\Feed\FleetReload`.
        $seen = array_keys($types);
        sort($seen);

        $this->assertSame(['feed.heartbeat', 'fleet.health', 'seat.delta', 'seat.retired'], $seen);
    }

    /**
     * § 8.3's `feed.heartbeat`: "every **15 s**, per channel, **UNCONDITIONALLY**".
     *
     * The unconditional half is the one worth a test: a heartbeat that is suppressed when nothing
     * is happening stops exactly when a client most needs it, and "a socket that has silently died
     * is indistinguishable from a fleet where nothing is happening".
     */
    public function test_the_heartbeat_is_published_on_a_fleet_where_nothing_at_all_has_happened(): void
    {
        // A provisioned seat, no events, no fold, nothing.
        $this->wire->forget();

        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();

        $beats = $this->wire->ofType('feed.heartbeat');

        $this->assertCount(1, $beats, 'a quiet fleet published no heartbeat');
        $this->assertSame(['private-fleet.'.self::INSTALL], $beats[0]['channels']);

        // § 8.2.4: the eight health fields ride it, and `counters` NEVER does — "nine monotonic
        // integers on that path would be permanent bytes carrying, almost always, no news".
        $this->assertArrayNotHasKey('counters', $beats[0]['payload']['fleet']);
        $this->assertSame(
            ['db', 'fold', 'sweep', 'sweep_last_run_at', 'ingest_last_receipt_at',
                'max_fold_lag_ms', 'seats_total', 'seats_live'],
            array_keys($beats[0]['payload']['fleet']),
        );

        // …and 15 s / 45 s are the pair § 8.3 states, read from the code rather than re-typed.
        $this->assertSame(15, FeedHeartbeat::INTERVAL_S);
        $this->assertSame(45, FeedHeartbeat::CLIENT_DEAD_AFTER_S);
        $this->assertSame(3, intdiv(FeedHeartbeat::CLIENT_DEAD_AFTER_S, FeedHeartbeat::INTERVAL_S));
    }

    /**
     * § 8.3's `fleet.health`: "whenever `db`, `fold` or `sweep` CHANGES VALUE" — and, by
     * implication, not otherwise. A message published on every tick would be `feed.heartbeat`
     * under another name and would cost the separation § 8.3 built it for.
     */
    public function test_fleet_health_publishes_on_a_change_and_not_on_every_tick(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();
        $this->assertCount(1, $this->wire->ofType('fleet.health'), 'the first tick published no health');

        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();
        $this->assertCount(1, $this->wire->ofType('fleet.health'), 'an unchanged triple published again');

        // Now MOVE it: the fold stops while the ingest keeps working, and `fleet.fold` degrades.
        $this->deliver($this->heartbeats(1));
        $this->advanceServerClock(FleetHealth::FOLD_LAGGING_MS / 1000 + 1);

        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();

        $health = $this->wire->ofType('fleet.health');
        $this->assertCount(2, $health, 'a changed triple published nothing');
        $this->assertSame('lagging', $health[1]['payload']['fleet']['fold']);

        // The three watched fields are § 8.3's three, read from the code.
        $this->assertSame(['db', 'fold', 'sweep'], FleetHealthMessage::WATCHED);
    }

    /**
     * § 2.2's WebSocket-connect row: with the store unreachable the connection is "accepted and
     * IMMEDIATELY SENT `fleet.health` WITH `db: "down"`, … which is the whole reason the socket
     * stays up in that posture".
     *
     * The connect half needs a socket server this card does not install; what IS driven is that
     * the daemon reaches the `db: "down"` object rather than exiting, which is the half that would
     * take the messenger down with the message.
     */
    public function test_a_store_failure_publishes_db_down_rather_than_killing_the_daemon(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();
        $this->wire->forget();

        Schema::drop('seat_state');

        // It must not raise. A heartbeat daemon that exits on a QueryException is one that goes
        // silent at the exact moment its silence is indistinguishable from a calm fleet.
        $this->artisan('mezzanine:feed-heartbeat', ['--once' => true])->assertSuccessful();

        $health = $this->wire->ofType('fleet.health');
        $this->assertCount(1, $health, 'the transition into the outage published nothing');
        $this->assertSame('down', $health[0]['payload']['fleet']['db']);

        // ⛔ AND NO `counters` MEMBER, not even a null one. § 8.2.4 gives `counters` to
        // `GET /api/fleet/health` ALONE — "the snapshot and the feed never do" — and separately
        // makes `null` its db-down value on that endpoint. The two rules compose; they do not
        // cancel. A `null` here would put a member on the feed that § 8.2.4 says never rides it.
        $this->assertArrayNotHasKey('counters', $health[0]['payload']['fleet']);
        $this->assertArrayNotHasKey('counters', $beats[0]['payload']['fleet'] ?? []);

        $beats = $this->wire->ofType('feed.heartbeat');
        $this->assertCount(1, $beats);
        $this->assertSame('down', $beats[0]['payload']['fleet']['db']);
    }

    /**
     * ⛔ § 6.5's HEADLINE RULE ON THE FEED: "an **ordinary** `reporter.heartbeat` — one that moves
     * nothing but the six `delivery` bookkeeping members and `reporter.uptime_s` — MOVES NO
     * VERSION-BEARING MEMBER, so it emits no delta … Emitting a delta per heartbeat would add
     * 1,440/seat/day of pure noise, a 16 % increase in feed traffic carrying no information."
     *
     * ⚠ DRIVEN ON A SEAT WITH NO OPEN CALL, AND THAT RESTRICTION IS A CARD #7339 DEFECT THIS TEST
     * FOUND rather than a fixture nicety: `StateRecompute::taskTier3()` re-stamps `task_as_of` to
     * `now()` on every recompute while a title exists, and `task` is version-bearing — so a seat
     * WITH an open call emits a delta on every fold pass, which is exactly the noise the rule
     * above forbids. Reported in card #7827's PR body, not patched: it is Part A's derivation, and
     * `as_of`'s correct semantics interact with § 4.9's tier-1/2 freshness bounds whose producers
     * are not built.
     */
    public function test_an_ordinary_heartbeat_emits_no_delta(): void
    {
        $this->deliver($this->cleanTurn());       // no open call, no title
        $this->stayAlive();                       // settles `enabled` and the reporter fields

        $mark = count($this->wire->sent);

        for ($i = 0; $i < 20; $i++) {
            $this->stayAlive();
        }

        $this->assertSame([], $this->wire->ofTypeFrom('seat.delta', $mark),
            'an ordinary heartbeat minted a delta — 1,440 a seat-day of pure noise');

        // …and a heartbeat that carries NEWS still emits one, which is what makes the line above
        // a finding about bookkeeping rather than about heartbeats. § 6.5's exception set includes
        // `enabled`, and an `enabled` flip is a rendered change.
        //
        // What that delta CARRIES is a different question, and a defect — see
        // `test_a_projector_written_member_reaches_the_delta` below.
        $this->deliver([$this->disablingHeartbeat()]);
        $this->fold();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);
        $this->assertCount(1, $deltas, 'a heartbeat carrying news emitted no delta');
        $this->assertSame('disabled', $deltas[0]['payload']['patch']->render_state);
    }

    /**
     * ⛔ A CARD #7339 DEFECT THIS CARD FOUND AND DID NOT CROSS THE SEAM TO FIX. RECORDED HERE AS
     * AN INCOMPLETE TEST SO IT CANNOT GO QUIET: the assertions below are what § 6.5 REQUIRES, and
     * they fail today.
     *
     * ── THE MECHANISM, MEASURED ──────────────────────────────────────────────────────────────
     *
     * `Fold::foldSeat()` runs `$this->projector->apply($event)` and THEN
     * `$this->recompute->after($event)`. `StateRecompute::after()` samples its "before"
     * fingerprint at its own first line — i.e. AFTER the projector has already written that
     * event's columns. So EVERY VERSION-BEARING MEMBER THE PROJECTOR WRITES IS IDENTICAL IN
     * `$before` AND `$after`, and is invisible both to the bump decision and to the patch.
     *
     * Measured on this rig:
     *   a `reporter.heartbeat` flipping `enabled` true → false
     *     store:  enabled 1 → 0, state_version 4 → 5, link_state live → disabled
     *     wire:   changed ["link_state","render_state"]     ← `enabled` ABSENT
     *   a `context.sample`
     *     store:  context_used_pct null → 73.2, state_version 4 → 5
     *     wire:   changed ["badges"]                        ← `context`, `model_label` ABSENT
     *
     * In both cases the version bumped ONLY because an unrelated derived member moved. A client
     * therefore holds `enabled: true` and a null context gauge indefinitely — the snapshot is
     * correct, so a page reload heals it and nothing else does.
     *
     * ── THE POPULATION (a sibling audit over `SeatFacts::versionBearing()`) ──────────────────
     *
     * Affected — written by `Projector` before the fingerprint is sampled:
     *   `context.*` · `model_label` · `enabled` · `selftest_failed` · D1's `reporter_degraded`
     *   badges · a `subagents[].title` filled by a later `subagent.spawn` (§ 10's E2)
     * Unaffected — written by `StateRecompute` after it:
     *   `render_state` · `link_state` · `activity_state` · `unknown_reason` · `open_calls` ·
     *   `open_turn` · `activity.*` · `reporter.version` / `.platform` · the server badge set
     *
     * ── WHY IT IS NOT FIXED HERE ─────────────────────────────────────────────────────────────
     *
     * The fix is to sample the fingerprint BEFORE `Projector::apply()`, which is a change to the
     * fold loop — card #7339's derivation, and this card's brief fences it: "if you cannot publish
     * the state without changing how it is derived, the seam is in the wrong place — SAY SO."
     * It also moves Part A's own version-bump semantics fleet-wide (strictly more bumps), which
     * `tools/design/verify-fleet-state.py` re-derives § 10's "ten events, ten deltas" against, so
     * it is Part A's owner's review. Card #7827's PR body carries it as the headline finding.
     *
     * It is NOT blocking this card: the SNAPSHOT carries every one of these members correctly, so
     * the watchdog's entire interface and § 8.4's join are unaffected, and the damage is bounded
     * to a browser's live view between reloads.
     */
    public function test_a_projector_written_member_reaches_the_delta(): void
    {
        $this->markTestIncomplete(
            'card #7339: `StateRecompute::after()` samples its `before` fingerprint AFTER '
            .'`Projector::apply()` has written the event, so every projector-written '
            .'version-bearing member is invisible to the delta. See this test\'s docblock for the '
            .'measured evidence and the affected population; reported in card #7827\'s PR body.'
        );

        $this->deliver($this->cleanTurn());
        $this->stayAlive();

        $mark = count($this->wire->sent);

        $this->deliver([$this->disablingHeartbeat()]);
        $this->fold();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);

        $this->assertCount(1, $deltas);
        $this->assertContains('enabled', $deltas[0]['payload']['changed'],
            '§ 6.5: `enabled` is version-bearing and an `enabled` flip must ride the delta');
        $this->assertFalse($deltas[0]['payload']['patch']->enabled);
    }

    /** § 8.3.1's patch is a SHALLOW merge and an empty one must serialize as `{}`, never `[]`. */
    public function test_a_patch_is_an_object_on_the_wire_and_nests_whole(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        foreach ($this->wire->ofType('seat.delta') as $message) {
            $this->assertIsObject($message['payload']['patch'],
                'a patch serialized as a JSON array — a client merging it gets a type error');

            // `changed` is exactly `patch`'s keys (§ 8.3.1), and sorted so the wire is diffable.
            $keys = array_keys((array) $message['payload']['patch']);
            $this->assertSame($message['payload']['changed'], $keys);

            $sorted = $keys;
            sort($sorted);
            $this->assertSame($sorted, $keys, 'changed is not in a stable order');

            // A nested member is replaced WHOLE — never a partial object.
            if (in_array('delivery', $keys, true)) {
                $this->assertSame(
                    ['last_receipt_at', 'last_heartbeat_at', 'no_data_since', 'clock_skew_ms',
                        'spool_lag_events', 'oldest_unsent_age_s', 'seq_epoch', 'last_seq'],
                    array_keys((array) $message['payload']['patch']->delivery),
                );
            }
        }
    }

    /** § 8.2's four endpoints exist, are named, and carry the gate. */
    public function test_the_four_rest_endpoints_are_registered_behind_the_read_gate(): void
    {
        foreach (['fleet.snapshot', 'fleet.health', 'fleet.seat', 'fleet.timeline'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, 'route '.$name.' is not registered');
            $this->assertContains('fleet.read', $route->gatherMiddleware(),
                $name.' is not behind § 9\'s gate');
        }

        // § 8.1: the REST surface carries `api_version`, and the feed carries `feed_version`.
        $this->assertSame(1, Snapshot::API_VERSION);
        $this->assertSame(1, SeatDelta::FEED_VERSION);
        $this->assertSame(SeatDelta::FEED_VERSION, FleetReload::FEED_VERSION);
    }

    /** § 8.2.3's `detail`, including the member card #7827 reports as undeclared by § 8.2.3. */
    public function test_the_seat_detail_carries_the_drill_downs_own_members(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();
        $this->sweep();

        $body = $this->asMachine($this->readToken(),
            '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT)->assertOk()->json();

        $this->assertSame('blocked', $body['render_state'], 'the detail is the seat object PLUS a member');

        foreach (['heartbeat_counters', 'heartbeat_predicates', 'counters', 'predicates',
            'open_calls', 'attention', 'session'] as $member) {
            $this->assertArrayHasKey($member, $body['detail']);
        }

        // § 8.2.3's "the open call list IN FULL (not capped at 8)".
        $this->assertNotEmpty($body['detail']['open_calls']);
        $this->assertNotNull($body['detail']['attention']);

        // ⚠ `predicates` — card #7833's `cannot_evaluate` reaches a consumer here, and § 8.2.3
        // does not declare the member. Reported in card #7827's PR body; § 8.2.4 was NOT its home.
        $names = array_column($body['detail']['predicates'], 'name');
        $this->assertContains('fold_current', $names);
        $this->assertContains('ingest_receiving', $names);

        $scopes = array_column($body['detail']['predicates'], 'scope');
        $this->assertContains('seat', $scopes);
        $this->assertContains('fleet', $scopes, 'the reserved seat_ref 0 sentinel is not distinguishable');

        // card #7832's `sweep_seat_error` needs no new home: it is a `seat_counters` row, and
        // "this plane's `seat_counters` rows" is already what this member returns.
        $this->assertIsArray($body['detail']['counters']);
    }

    /** § 8.2's timeline: renderable kinds only, newest first, `limit` ≤ 200, default 50. */
    public function test_the_timeline_is_a_bounded_query_over_the_renderable_kinds(): void
    {
        $this->deliver($this->clearKill());
        $this->deliver($this->heartbeats(3));
        $this->fold();

        $path = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline';

        $body = $this->actingAs($this->enrolled())->getJson($path)->assertOk()->json();

        $this->assertSame(50, $body['limit']);
        $this->assertNotEmpty($body['events']);

        // § 3.2's activity set is what "renderable" means here; a heartbeat is not in it.
        $kinds = array_column($body['events'], 'kind');
        $this->assertNotContains('reporter.heartbeat', $kinds);
        $this->assertContains('turn.end', $kinds);

        // Newest first.
        $received = array_column($body['events'], 'received_at');
        $sorted = $received;
        rsort($sorted);
        $this->assertSame($sorted, $received);

        // The bound CLAMPS rather than refuses.
        $this->assertSame(200, $this->actingAs($this->enrolled())
            ->getJson($path.'?limit=5000')->assertOk()->json('limit'));
        $this->assertSame(3, count($this->actingAs($this->enrolled())
            ->getJson($path.'?limit=3')->assertOk()->json('events')));
    }

    /**
     * ⛔ A MALFORMED `before` CURSOR REFUSES, AND A VALID ONE PAGES. Both halves, because a `422`
     * on everything would pass the first assertion on its own.
     *
     * The defect this guards is the one shape this whole design refuses: an unparseable cursor
     * compared against a `DATETIME(3)` column matches no row, so the surface would answer `200`
     * with `events: []` and a drill-down would say "this desk has done nothing".
     */
    public function test_a_malformed_paging_cursor_refuses_rather_than_paging_to_nothing(): void
    {
        $this->deliver($this->clearKill());
        $this->fold();

        $path = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline';

        $this->actingAs($this->enrolled())
            ->getJson($path.'?before=yesterday')
            ->assertStatus(422)
            ->assertJsonPath('error', 'bad_cursor');

        // …and a WELL-FORMED cursor pages, which is what makes the line above a finding about the
        // cursor rather than about the parameter existing at all.
        $all = $this->actingAs($this->enrolled())->getJson($path)->assertOk()->json('events');
        $this->assertGreaterThan(2, count($all));

        $paged = $this->actingAs($this->enrolled())
            ->getJson($path.'?before='.$all[1]['received_at'])
            ->assertOk()
            ->json('events');

        $this->assertLessThan(count($all), count($paged), 'a valid cursor paged nothing');
    }

    /**
     * § 4.10: "the READ QUERIES stop selecting it" — plural. All three seat-scoped surfaces agree.
     */
    public function test_every_read_surface_stops_selecting_a_long_retired_seat_together(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->retire();

        $this->advanceServerClock(Purge::RETENTION_DAYS * 86400 + 60);

        $token = $this->readToken();
        $base = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT;

        $this->assertSame([], $this->asMachine($token, '/api/fleet/snapshot')
            ->assertOk()->json('installs'));

        $this->asMachine($token, $base)->assertNotFound()->assertJsonPath('error', 'seat_not_found');

        $this->actingAs($this->enrolled())->getJson($base.'/timeline')
            ->assertNotFound()->assertJsonPath('error', 'seat_not_found');
    }

    /** A heartbeat carrying `enabled: false` — § 6.5's exception set, and a rendered change. */
    private function disablingHeartbeat(): array
    {
        return $this->event('reporter.heartbeat', [
            'uptime_s' => 99_000, 'spool_bytes' => 0, 'spool_files' => 1, 'spool_lag_events' => 0,
            'oldest_unsent_age_s' => null, 'last_hook_at' => null, 'open_calls' => 0,
            'open_sessions' => 0, 'open_attention' => 0, 'enabled' => false, 'degraded' => [],
            'counters' => ['batches_sent' => 9], 'counters_omitted' => 0, 'predicates' => [],
            'selftest' => ['spool_writable' => 'pass'], 'config_fingerprint' => '9f2c41a7be03d518',
        ]);
    }
}

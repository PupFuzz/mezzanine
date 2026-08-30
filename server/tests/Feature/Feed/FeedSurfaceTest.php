<?php

namespace Tests\Feature\Feed;

use App\Feed\FeedHeartbeat;
use App\Feed\FleetHealthMessage;
use App\Feed\FleetReload;
use App\Feed\SeatDelta;
use App\Read\FleetHealth;
use App\Read\Snapshot;
use App\Sweep\Purge;
use Illuminate\Support\Facades\DB;
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

        $beats = $this->wire->ofType('feed.heartbeat');
        $this->assertCount(1, $beats);
        $this->assertSame('down', $beats[0]['payload']['fleet']['db']);

        // ⚠ THIS LINE USED TO SIT ABOVE, BEFORE `$beats` EXISTED. `$beats[0][…] ?? []` on an
        // undefined variable is `[]` and raises nothing — the `??` swallows the diagnostic — so
        // it asserted that an empty array has no `counters` key, on every run, for ever. It is
        // here rather than deleted because the property is real; what was wrong was that the
        // check could not fail.
        $this->assertArrayNotHasKey('counters', $beats[0]['payload']['fleet']);
    }

    /**
     * ⛔ § 8.2.4's `counters` ASYMMETRY, ON THE SURFACE THE FEED TESTS ABOVE DO NOT REACH: the
     * REST snapshot.
     *
     * "**`GET /api/fleet/health` only.** The nine fleet-scoped counters … the snapshot and the
     * feed never do." Both halves are asserted here because a negative assertion alone is passed
     * by an application that has no counters at all — the positive half is what makes the
     * negative one a finding about the ASYMMETRY. § 8.2.4's other term rides the same read: the
     * nine are all-or-none, "a per-member omission is forbidden, because an omitted counter and a
     * zero counter are the same wire shape to a consumer and only one of them is true".
     *
     * The expected member list is `FleetHealth::COUNTERS` rather than nine literals: the class
     * docblock argues that the closed set must be a constant and not a query result, and a
     * hand-typed list here would be the third copy of it.
     */
    public function test_the_nine_counters_ride_fleet_health_alone_and_never_the_snapshot(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $token = $this->readToken();

        // The positive half — and the control for the negative one below.
        $counters = $this->asMachine($token, '/api/fleet/health')->assertOk()->json('fleet.counters');

        $this->assertSame(FleetHealth::COUNTERS, array_keys($counters), '§ 8.2.4: all nine or none');

        // The negative half. `Snapshot::build()` calls `FleetHealth::build()` WITHOUT
        // `withCounters`, and the default is what carries this contract term — so this assertion
        // is the whole of the guard, and mutating that one call site is what it must catch.
        $snapshot = $this->asMachine($token, '/api/fleet/snapshot')->assertOk()->json('fleet');

        $this->assertArrayNotHasKey('counters', $snapshot);
        $this->assertSame(
            ['db', 'fold', 'sweep', 'sweep_last_run_at', 'ingest_last_receipt_at',
                'max_fold_lag_ms', 'seats_total', 'seats_live'],
            array_keys($snapshot),
        );
    }

    /**
     * ⛔ § 6.5's HEADLINE RULE ON THE FEED: "an **ordinary** `reporter.heartbeat` — one that moves
     * nothing but the six `delivery` bookkeeping members and `reporter.uptime_s` — MOVES NO
     * VERSION-BEARING MEMBER, so it emits no delta … Emitting a delta per heartbeat would add
     * 1,440/seat/day of pure noise, a 16 % increase in feed traffic carrying no information."
     *
     * ⚠ DRIVEN ON A SEAT WITH NO OPEN CALL, AND THAT RESTRICTION WAS A CARD #7339 DEFECT THIS TEST
     * FOUND rather than a fixture nicety: `StateRecompute::taskTier3()` re-stamped `task_as_of` to
     * `now()` on every recompute while a title existed, and `task` is version-bearing — so a seat
     * WITH an open call emitted a delta on every fold pass, which is exactly the noise the rule
     * above forbids. FIXED ON CARD #7837, and the restriction is therefore no longer load-bearing:
     * `test_a_seat_with_an_open_call_is_as_quiet_as_one_without` below drives the same rule with
     * the open call this fixture avoids, and is what would go red if the re-stamp came back. This
     * case keeps the no-open-call fixture so the two are independent.
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
     * ⛔ CARD #7827 RECORDED THIS AS AN INCOMPLETE TEST RATHER THAN CROSSING THE PART A / PART B
     * SEAM TO FIX IT. CARD #7837 IS THAT FIX, AND THIS IS ITS ACCEPTANCE.
     *
     * ── THE MECHANISM, MEASURED (card #7827's rig, re-measured here as this card's RED) ──────
     *
     * `Fold::window()` ran `$this->projector->apply($event)` and THEN
     * `$this->recompute->after($event)`, and `StateRecompute::after()` sampled its "before"
     * fingerprint at its own first line — i.e. AFTER the projector had already written that
     * event's columns. So EVERY VERSION-BEARING MEMBER THE PROJECTOR WRITES was identical in
     * `$before` and `$after`, and invisible both to the bump decision and to § 8.3's patch:
     *
     *   a `reporter.heartbeat` flipping `enabled` true → false
     *     store:  enabled 1 → 0, state_version 4 → 5, link_state live → disabled
     *     wire:   changed ["link_state","render_state"]     ← `enabled` ABSENT
     *   a `context.sample`
     *     store:  context_used_pct null → 73.2, state_version 4 → 5
     *     wire:   changed ["badges"]                        ← `context`, `model_label` ABSENT
     *
     * In both cases the version bumped ONLY because an unrelated derived member moved. A client
     * held `enabled: true` and a null context gauge indefinitely — the snapshot was correct, so a
     * page reload healed it and nothing else did.
     *
     * ── WHY BOTH ARMS, AND WHY THESE TWO ─────────────────────────────────────────────────────
     *
     * They enter through DIFFERENT projector paths — `Projector::heartbeat()` and
     * `Projector::contextSample()` — each with its own last-write-wins guard and its own column
     * group, so one arm passing is no evidence for the other. Between them they cover the two
     * distinct failure signatures the defect had: a member the recompute's own derivation happens
     * to shadow (`enabled`, behind `link_state`) and one it does not (`context`, which reached the
     * wire only because an unrelated badge moved on the same pass).
     *
     * The SNAPSHOT arm is asserted beside each: it was correct before this fix and must stay
     * correct after it, because a fix that traded the delta's silence for a wrong snapshot would
     * be a worse defect on the surface § 8.4's join and the autonomy watchdog both read.
     */
    public function test_a_projector_written_member_reaches_the_delta(): void
    {
        $this->deliver($this->cleanTurn());
        $this->stayAlive();

        $token = $this->readToken();
        $seatPath = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT;

        // ── ARM 1: `context` and `model_label`, written by `Projector::contextSample()` ───────
        $mark = count($this->wire->sent);

        $this->deliver([$this->event('context.sample', [
            'used_pct' => 73.2, 'used_tokens' => 146401, 'total_tokens' => 200000,
            'used_pct_source' => 'harness', 'model_label' => 'claude-opus-5',
            'sample_reason' => 'threshold_cross',
        ])]);
        $this->fold();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);

        $this->assertCount(1, $deltas, 'a context sample emitted no delta at all');

        $changed = $deltas[0]['payload']['changed'];
        $patch = $deltas[0]['payload']['patch'];

        $this->assertContains('context', $changed,
            '§ 6.5: `context` is version-bearing and a `context.sample` must ride the delta');
        $this->assertContains('model_label', $changed,
            '§ 6.5: `model_label` moves with the sample that carries it');

        $this->assertSame(73.2, $patch->context['used_pct']);
        $this->assertSame(146401, $patch->context['used_tokens']);
        $this->assertSame('claude-opus-5', $patch->model_label);

        // The SNAPSHOT was already correct and stays correct — the control that keeps this fix
        // from having traded one defect for a worse one.
        $seat = $this->asMachine($token, $seatPath)->assertOk()->json();
        $this->assertSame(73.2, $seat['context']['used_pct']);
        $this->assertSame('claude-opus-5', $seat['model_label']);

        // ── ARM 2: `enabled`, written by `Projector::heartbeat()` ────────────────────────────
        $mark = count($this->wire->sent);

        $this->deliver([$this->disablingHeartbeat()]);
        $this->fold();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);

        $this->assertCount(1, $deltas);
        $this->assertContains('enabled', $deltas[0]['payload']['changed'],
            '§ 6.5: `enabled` is version-bearing and an `enabled` flip must ride the delta');
        $this->assertFalse($deltas[0]['payload']['patch']->enabled);

        // …and the store agrees it really flipped, so the assertion above is about the WIRE and
        // not about a fixture that never disabled anything.
        $this->assertSame(0, (int) $this->state()->enabled);
        $this->assertFalse($this->asMachine($token, $seatPath)->assertOk()->json('enabled'));
    }

    /**
     * ⛔ THE SAME DEFECT IN THE SWEEPER — card #7837's sibling audit, driven rather than reported.
     *
     * `Sweep::quiesce()` closes every open `calls` row and only then settles, and `subagents` /
     * `subagents_open` read `calls` DIRECTLY (`SeatFacts::openSubagents()`, `closed_at IS NULL`).
     * A fingerprint sampled after those UPDATEs therefore had the intern already gone from BOTH
     * sides, so the delta announcing the seat went offline never carried `subagents` and a desk
     * kept rendering a subagent the server had closed.
     *
     * ⚠ THE `subagents_open` ASSERTION IS THE LOAD-BEARING ONE. `subagents` alone could be
     * satisfied by a patch carrying an unchanged array; the count moving 1 → 0 is what says the
     * member the client holds was actually corrected.
     */
    public function test_a_subagent_closed_by_the_sweeper_reaches_the_delta(): void
    {
        $dispatch = $this->ulid();

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('tool.start', [
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [
                'call_id' => $dispatch, 'title' => 'audit the fold', 'title_truncated' => false,
                'subagent_type' => 'coder',
            ]),
        ]);
        $this->fold();

        // The control: the intern IS open and IS on the wire before the sweeper acts. Without
        // this the assertions below pass on a fixture that never had a subagent.
        $token = $this->readToken();
        $seatPath = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT;

        $this->assertSame(1, $this->asMachine($token, $seatPath)->assertOk()->json('subagents_open'));

        $this->advanceServerClock(1000);          // past § 4.5's 900 s `offline`
        $this->fold();

        $mark = count($this->wire->sent);

        $this->sweep();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);

        $this->assertNotEmpty($deltas, 'offline quiescence emitted no delta at all');

        $quiesce = $deltas[0]['payload'];

        $this->assertContains('subagents', $quiesce['changed'],
            '§ 6.5: `subagents` is version-bearing and quiescence closing the dispatch moved it');
        $this->assertContains('subagents_open', $quiesce['changed']);
        $this->assertSame([], $quiesce['patch']->subagents);
        $this->assertSame(0, $quiesce['patch']->subagents_open);

        // The snapshot agrees, on the surface that was already correct.
        $this->assertSame(0, $this->asMachine($token, $seatPath)->assertOk()->json('subagents_open'));
    }

    /**
     * ⛔ THE SAME DEFECT IN THE SWEEPER'S OTHER CALL-CLOSING JOB — `Sweep::orphanCloses()`.
     *
     * DRIVEN SEPARATELY FROM THE QUIESCENCE ARM ABOVE AND NOT FOLDED INTO IT, because the two are
     * different jobs on different triggers: job 2 fires on a call's own materialized
     * `orphan_due_at` while the seat is STILL LIVE, job 6 only once the seat is `offline`. A fix
     * applied to one and reasoned about for the other is a fix with one instance of evidence.
     *
     * ⚠ 61 MINUTES AND A HEARTBEAT, WHICH IS THE FIXTURE'S WHOLE DIFFICULTY: a dispatch call gets
     * § 4.6's 60-minute ceiling rather than the ordinary 15, and 60 minutes of silence would take
     * the seat past § 4.5's 900 s `offline` and hand the close to job 6 instead — measuring the
     * wrong job. The heartbeat keeps `link_state` at `live` so job 2 is the writer under test.
     */
    public function test_a_subagent_closed_by_the_orphan_ceiling_reaches_the_delta(): void
    {
        $dispatch = $this->ulid();

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('tool.start', [
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [
                'call_id' => $dispatch, 'title' => 'audit the sweeper', 'title_truncated' => false,
                'subagent_type' => 'coder',
            ]),
        ]);
        $this->fold();

        $token = $this->readToken();
        $seatPath = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT;

        $this->assertSame(1, $this->asMachine($token, $seatPath)->assertOk()->json('subagents_open'));

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();

        $mark = count($this->wire->sent);

        $this->sweep();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);

        $this->assertNotEmpty($deltas, 'the orphan ceiling emitted no delta at all');

        // The control that says job 2 was the writer: job 6's cause is `offline_quiesce`, and a
        // seat that went offline instead would produce that one and pass every assertion below.
        $this->assertContains('orphan_timeout', $this->causes());
        $this->assertSame('live', $this->state()->link_state);

        $orphan = $deltas[0]['payload'];

        $this->assertContains('subagents', $orphan['changed'],
            '§ 6.5: the orphan ceiling closing a dispatch moved `subagents` and it must ride');
        $this->assertContains('subagents_open', $orphan['changed']);
        $this->assertSame([], $orphan['patch']->subagents);
        $this->assertSame(0, $orphan['patch']->subagents_open);

        $this->assertSame(0, $this->asMachine($token, $seatPath)->assertOk()->json('subagents_open'));
    }

    /**
     * ⛔ THE SAME DEFECT IN `mezzanine:retire` — card #7837's sibling audit, second instance.
     *
     * The command sets `seats.retired_at` / `retired_by` / `retired_reason` and THEN calls the
     * shared recompute, whose self-sampled `$before` therefore already had them. `retired` is the
     * § 8.2.1 member that reads exactly those three columns, so the `seat.delta` announcing the
     * retirement carried `render_state: "retired"` and left the client's `retired` object `null`
     * — who retired the seat, when and why, permanently absent until a resync.
     *
     * ⚠ ASSERTED ON `seat.delta` AND NOT ON `seat.retired`. § 8.3 gives retirement its own
     * message and card #7712 already drives that one; it is a DIFFERENT message with a different
     * body, and a client applying deltas to its seat object is corrected by neither if the delta
     * omits the member. Asserting the retired message here would pass over the defect.
     */
    public function test_the_retired_member_reaches_the_delta_that_announces_it(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $mark = count($this->wire->sent);

        $this->retire();

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);

        $this->assertCount(1, $deltas, 'retirement emitted no delta');

        $changed = $deltas[0]['payload']['changed'];

        $this->assertContains('retired', $changed,
            '§ 6.5: `retired` is version-bearing and `mezzanine:retire` is what moves it');
        $this->assertContains('render_state', $changed);

        $retired = $deltas[0]['payload']['patch']->retired;

        $this->assertSame('operator@aimla', $retired['by']);
        $this->assertSame('decommissioned', $retired['reason']);
        $this->assertSame('retired', $deltas[0]['payload']['patch']->render_state);
    }

    /**
     * ⛔ THE `task_as_of` NOISE — card #7837's SECOND, SEPARATE defect, and the direct inverse of
     * the three cases above: they are changes that failed to emit, this is an emission with no
     * change behind it.
     *
     * `StateRecompute::taskTier3()` wrote `task_as_of => now()` on EVERY recompute while a title
     * existed. `task` is version-bearing (§ 6.5's subtraction excludes ten named members and this
     * is not one), so a seat with one open call emitted a `seat.delta` on every fold pass and
     * every sweep pass with nothing meaningful moved — 1,440/seat/day from heartbeats alone, the
     * "16 % increase in feed traffic carrying no information" § 8.3 refuses in terms.
     *
     * ⚠ THIS IS `test_an_ordinary_heartbeat_emits_no_delta`'s RULE ON THE FIXTURE THAT ONE HAD TO
     * AVOID. Two tests rather than a widened one, because they fail for different reasons: that
     * one goes red if the SUBTRACTION drifts, this one if the re-stamp comes back.
     */
    public function test_a_seat_with_an_open_call_is_as_quiet_as_one_without(): void
    {
        $this->deliver($this->openCall());
        $this->fold();
        $this->stayAlive();      // settles `enabled` and the reporter fields, as the case above does

        // The control: this seat really does have the title the defect needed. Without it the
        // silence below is the silence of a seat with nothing to re-stamp.
        $this->assertNotNull($this->state()->task_title, 'the fixture opened no titled call');
        $this->assertNotNull($this->state()->task_as_of);

        $stamped = $this->state()->task_as_of;
        $mark = count($this->wire->sent);

        for ($i = 0; $i < 20; $i++) {
            $this->stayAlive();
            $this->sweep();
        }

        $this->assertSame([], $this->wire->ofTypeFrom('seat.delta', $mark),
            'a seat with an open call minted a delta per pass — § 8.3\'s pure-noise class');

        // And the stamp itself did not move: the assertion above could also be satisfied by a
        // `task_as_of` that moved while some other member masked it, and this is what separates
        // "no delta" from "no change".
        $this->assertSame($stamped, $this->state()->task_as_of,
            '`task_as_of` was re-stamped on a pass that re-read the same title');
        $this->assertSame(1, (int) $this->state()->open_calls, 'the call closed — wrong fixture');
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
        // cursor rather than about the parameter existing at all. The well-formed cursor is the
        // one the SERVER issued: `next_before`, never a value the client assembled.
        $first = $this->actingAs($this->enrolled())->getJson($path.'?limit=3')->assertOk()->json();

        $this->assertCount(3, $first['events']);
        $this->assertNotNull($first['next_before'], 'a page that was cut short carried no cursor');

        $paged = $this->actingAs($this->enrolled())
            ->getJson($path.'?limit=3&before='.urlencode($first['next_before']))
            ->assertOk()
            ->json('events');

        $this->assertNotEmpty($paged, 'a valid cursor paged nothing');
        $this->assertNotContains(
            $paged[0]['event_id'],
            array_column($first['events'], 'event_id'),
            'the second page repeated a row from the first',
        );
    }

    /**
     * ⛔ PAGING ACROSS ONE BATCH'S SHARED `received_at` REACHES EVERY EVENT — the blocking defect
     * of this card's first round, and the reason the cursor is `(received_at, id)` and not
     * `received_at` alone.
     *
     * `App\Ingest\BatchWriter` stamps ONE `received_at` for a WHOLE batch, and `App\Ingest\Wire`
     * admits up to `MAX_EVENTS_PER_BATCH = 200` events, so a batch of >`limit` renderable events
     * is a single value of the column the timeline used to page on. A strict `received_at < ?`
     * cursor derived from the last row of page 1 therefore skips **every event that shares that
     * timestamp** — measured before the fix at 120 events in one batch: page 1 returned 50, page 2
     * returned `200 {"events": []}`, and 70 events were unreachable by any cursor the response
     * offered. That is exactly the shape `ReadRefusal::badCursor()` exists to refuse, produced
     * from a WELL-FORMED cursor on ordinary traffic.
     *
     * The denominator is stated in the fixture rather than assumed: the assertions below check
     * that all 120 events really do share one `received_at` (without which this test would pass
     * over a fixture that never reproduces the defect) and then that following `next_before` to
     * exhaustion yields all 120 exactly once.
     */
    public function test_the_timeline_pages_through_a_batch_whose_events_share_one_receipt(): void
    {
        // 30 clean turns = 120 renderable events, in ONE batch and so under ONE `received_at`.
        $events = [];

        for ($turn = 0; $turn < 30; $turn++) {
            $events = array_merge($events, $this->cleanTurn());
        }

        $this->deliver($events);
        $this->fold();

        // The fixture's own control: without this, a batch that somehow split its receipt would
        // let the assertions below pass while never reproducing the defect.
        $this->assertSame(120, DB::table('events')->where('seat_ref', $this->seatRef)->count());
        $this->assertSame(1, DB::table('events')->where('seat_ref', $this->seatRef)
            ->distinct()->count('received_at'), 'the fixture did not build one shared receipt');

        // 50 leaves a short last page; 60 divides 120 EXACTLY, which is the case the `limit + 1`
        // look-ahead exists for — a server that guessed "a full page probably has more" would
        // hand out a cursor after the last row and answer the follow-up with an empty page.
        foreach ([50 => 3, 60 => 2] as $limit => $expectedPages) {
            [$pages, $seen] = $this->pageTimelineThrough($limit);

            $this->assertSame($expectedPages, $pages,
                '120 events at '.$limit.'/page is '.$expectedPages.' pages and no empty tail');
            $this->assertCount(120, $seen, 'the pages did not cover the batch');
            $this->assertCount(120, array_unique($seen), 'a row was served on two pages');
        }
    }

    /**
     * Follow `next_before` to exhaustion.
     *
     * @return array{int, list<string>} pages fetched, and every `event_id` served in order
     */
    private function pageTimelineThrough(int $limit): array
    {
        $path = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline';

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $body = $this->actingAs($this->enrolled())
                ->getJson($path.'?limit='.$limit.($cursor === null ? '' : '&before='.urlencode($cursor)))
                ->assertOk()
                ->json();

            $this->assertNotEmpty($body['events'], 'a cursor the server itself issued paged to nothing');

            $seen = array_merge($seen, array_column($body['events'], 'event_id'));
            $cursor = $body['next_before'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        return [$pages, $seen];
    }

    /**
     * ⛔ A CURSOR THE CLIENT ASSEMBLED FROM `received_at` IS REFUSED, NOT ANSWERED WITH AN EMPTY
     * PAGE — the other half of the fix above, and the half that makes the defect unconstructable
     * rather than merely avoidable.
     *
     * Supplying a correct `next_before` fixes the client that reads it. This refusal is what stops
     * the next client — D3's floor, a watchdog, an operator with `curl` — from re-deriving the
     * lossy cursor out of the one column the response puts in front of it, silently, on a surface
     * whose whole job is to say what a desk has been doing.
     */
    public function test_a_bare_timestamp_is_not_a_cursor_and_is_refused(): void
    {
        $this->deliver($this->clearKill());
        $this->fold();

        $path = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline';

        $all = $this->actingAs($this->enrolled())->getJson($path)->assertOk()->json('events');
        $this->assertGreaterThan(2, count($all));

        $this->actingAs($this->enrolled())
            ->getJson($path.'?before='.urlencode($all[1]['received_at']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'bad_cursor');
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

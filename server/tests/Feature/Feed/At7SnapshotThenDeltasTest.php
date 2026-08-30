<?php

namespace Tests\Feature\Feed;

use App\Feed\SeatDelta;
use App\Read\SeatObject;

/**
 * **AT-D2-7 — snapshot-then-deltas has no window** (`docs/design/FLEET-STATE.md § 11`, § 8.4).
 *
 * § 8.4 states the hazard and the reason it is written out at all: "The hazard is ordinary and the
 * protocol is the ordinary answer, stated exactly because GETTING IT SUBTLY WRONG PRODUCES A
 * CLIENT THAT IS PERMANENTLY AND INVISIBLY WRONG ABOUT ONE DESK."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE "FORCED 500 ms DELAY" § 11's BUILD ASKS FOR IS DRIVEN AS AN ORDERING, NOT AS A SLEEP.
 *
 * § 11: "with a **forced 500 ms delay** injected between the subscribe and the snapshot query,
 * and a state change driven inside that window." What the delay exists to create is one
 * condition: A STATE CHANGE THAT HAPPENS AFTER THE SUBSCRIBE AND BEFORE THE SNAPSHOT'S READ.
 * This suite drives the server and the client in one process on a pinned clock, so that condition
 * is produced by ORDER — subscribe, fold, GET — which is the same condition exactly and is
 * deterministic rather than a race the suite hopes to win. A real 500 ms sleep would make the
 * window PROBABLE; ordering makes it CERTAIN, which is the stronger of the two.
 *
 * See `ClientHarness` for what a test built on it is and is not evidence of.
 */
class At7SnapshotThenDeltasTest extends FeedTestCase
{
    /**
     * GREEN — "the client's final state equals the server's `seat_state` exactly, WHETHER THE
     * CHANGE LANDED BEFORE OR AFTER THE SNAPSHOT'S READ."
     *
     * Both orders are driven, because a protocol that only works when the change lands on one
     * side of the read is the defect rather than the fix.
     */
    public function test_a_change_inside_the_window_reaches_the_client(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $client = new ClientHarness;

        // 1 + 2 — connect and subscribe. From here on every delta is buffered.
        $client->subscribe();
        $mark = count($this->wire->sent);

        // ⛔ THE WINDOW. A state change lands while the snapshot query is "in flight".
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();
        $this->deliverBufferedDeltas($client, $mark);

        // 3 + 4 — the snapshot, read AFTER that change.
        $client->applySnapshot($this->snapshot());

        // 5 — drain, discarding at or below the per-seat watermark.
        $client->drain();

        $this->assertClientMatchesServer($client);
        $this->assertSame('blocked', $client->seat(self::INSTALL, self::SEAT)['render_state']);
    }

    /**
     * The other half of the same GREEN: the change lands AFTER the snapshot's read. The buffered
     * delta is then strictly above the watermark and must be APPLIED, not discarded.
     */
    public function test_a_change_after_the_snapshots_read_reaches_the_client(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $client = new ClientHarness;
        $client->subscribe();
        $mark = count($this->wire->sent);

        $client->applySnapshot($this->snapshot());

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();
        $this->deliverBufferedDeltas($client, $mark);

        $client->drain();

        $this->assertClientMatchesServer($client);
        $this->assertSame('blocked', $client->seat(self::INSTALL, self::SEAT)['render_state']);
    }

    /**
     * ⛔ RED — ORDER. "snapshot first, subscribe after → the change made in the window is IN
     * NEITHER, and the desk stays wrong until something unrelated changes it. ASSERT THE
     * DIVERGENCE EXPLICITLY; ON A QUIET DESK IT IS PERMANENT."
     *
     * This is the one RED that cannot be driven by mutating production code, because the mistake
     * IS the client's ordering. It is driven here by performing the wrong order and asserting the
     * damage — and then by asserting the damage is PERMANENT, which is the half that makes it a
     * defect rather than a delay.
     */
    public function test_red_fetching_before_subscribing_leaves_the_desk_permanently_wrong(): void
    {
        // The seat is settled AND heartbeating before the join, so the quiet stretch below is
        // genuinely quiet: `enabled` and the reporter fields are version-bearing and their FIRST
        // value is a real change, which would otherwise emit a delta and heal the divergence.
        $this->deliver($this->cleanTurn());
        $this->stayAlive();

        $client = new ClientHarness;

        // WRONG ORDER — the snapshot is read first.
        $body = $this->snapshot();

        // ⛔ THE CHANGE THAT LANDS IN THE WINDOW LEAVES NO CALL OPEN, and that is a property of
        // the fixture rather than a coincidence — see the quiet-desk note below. § 10's
        // `clear_kill` ends `unknown` with zero open calls, which is the state change this test
        // needs and the quiet stretch needs.
        $this->deliver($this->clearKill());
        $this->fold();

        $client->applySnapshot($body);

        // …and only NOW does the client subscribe. Deltas emitted in the window are not replayed:
        // § 8.5 is explicit that "there is no per-seat delta-replay buffer on the server and
        // deliberately so".
        $client->subscribe();
        $client->drain();

        $this->assertSame('idle', $client->seat(self::INSTALL, self::SEAT)['render_state'],
            'the client still holds the pre-change state');
        $this->assertSame('unknown', $this->state()->render_state);

        // ⛔ AND IT IS PERMANENT ON A QUIET DESK — the half that makes this a defect rather than a
        // delay, and § 11 says so in terms: "on a quiet desk it is permanent".
        //
        // The argument has to be made ON THE WIRE: what makes the divergence permanent is that NO
        // FURTHER DELTA IS EVER EMITTED for this seat, so nothing exists that could correct it.
        //
        // A "quiet desk" here is NOT a silent one. A silent seat goes `stale` at 300 s and that
        // transition IS a delta, which would heal the client through § 8.5's gap path. It is a
        // seat that keeps heartbeating and does nothing else — § 6.5's own case: "the heartbeat
        // that moves nothing but bookkeeping emits no delta".
        //
        // ⚠ AND IT HAS NO OPEN CALL, which WAS a CARD #7339 DEFECT this test found rather than a
        // fixture nicety: `StateRecompute::taskTier3()` re-stamped `task_as_of` to `now()` on
        // EVERY recompute while a title existed, `task` is version-bearing, so a seat with an open
        // call emitted a delta on every fold pass — 1,440 a seat-day from heartbeats alone, which
        // is precisely the noise § 8.3 refuses ("a 16 % increase in feed traffic carrying no
        // information"). FIXED ON CARD #7837: `as_of` is now stamped when the tier's value moves
        // and not when a pass re-reads it, and `FeedSurfaceTest::
        // test_a_seat_with_an_open_call_is_as_quiet_as_one_without` drives the open-call fixture
        // directly. The fixture here stays open-call-free anyway, because THIS test's subject is
        // § 8.4's window and it should not go red for a § 4.9 regression that has its own case.
        $quietFrom = count($this->wire->sent);

        for ($i = 0; $i < 10; $i++) {
            $this->stayAlive();
            $this->sweep();
        }

        $this->assertSame([], $this->wire->ofTypeFrom('seat.delta', $quietFrom),
            'the desk was not quiet — something emitted a delta that would have healed the client');

        $this->assertSame('idle', $client->seat(self::INSTALL, self::SEAT)['render_state'],
            'the divergence healed by itself, so this fixture is not the permanent case § 11 names');
        $this->assertSame('unknown', $this->state()->render_state, 'the server moved on its own');
    }

    /**
     * ⛔ SECOND RED — NO WATERMARK. "Apply every buffered delta unconditionally → a delta already
     * included in the snapshot is re-applied. ASSERT A CASE WHERE THAT IS *VISIBLE* (a patch that
     * clears `action` followed by a snapshot that already has it cleared, then a NEWER delta that
     * sets it) — a re-application that happens to be idempotent PROVES NOTHING."
     *
     * The fixture is built to § 11's letter, because § 11 is right that any other one is vacuous:
     *
     *   d1        CLEARS `action` (a call closes)        ← buffered; at or below the watermark
     *   snapshot  `action` is already null               ← step 4
     *   d2        SETS `action` (a new call opens)       ← arrives in STEADY STATE and is applied
     *   drain     replays the buffer                     ← d1 lands ON TOP of d2
     *
     * ⚠ THE LAZY DRAIN IS WHAT MAKES IT VISIBLE, and it is not a contrivance: § 8.4's step 5 and
     * step 6 are separate steps, so a client that has begun applying live deltas while its buffer
     * is still un-drained is inside the protocol as written. Draining the buffer FIRST would hide
     * the defect behind arrival order rather than fixing it — which is the "re-application that
     * happens to be idempotent" § 11 says proves nothing.
     */
    public function test_second_red_replaying_a_delta_below_the_watermark_undoes_a_newer_one(): void
    {
        $this->deliver($this->openCall());         // a call is OPEN — `action` is set
        $this->fold();

        $client = new ClientHarness;
        $client->useWatermark = false;             // ← the mutation, in the client, per § 11
        $client->subscribe();
        $mark = count($this->wire->sent);

        // d1 — the call closes. This delta CLEARS `action`, and it is at or below the version the
        // snapshot below will carry.
        $this->deliver($this->closeOpenCall());
        $this->fold();
        $this->deliverBufferedDeltas($client, $mark);

        $body = $this->snapshot();
        $this->assertNull($body['installs'][0]['seats'][0]['action'], 'the fixture must snapshot a cleared action');
        $client->applySnapshot($body);

        // d2 — strictly ABOVE the snapshot's version: a new call opens and `action` is set. It
        // arrives in steady state and is applied immediately, correctly.
        $mark2 = count($this->wire->sent);
        $this->deliver($this->openCall());
        $this->fold();

        foreach ($this->wire->ofTypeFrom('seat.delta', $mark2) as $m) {
            $client->apply($m['payload']);
        }

        $this->assertNotNull($client->seat(self::INSTALL, self::SEAT)['action'],
            'the fixture did not put an action on the client before the drain');

        // …and only NOW is the stale buffer drained. Unconditionally.
        $client->drain();

        $this->assertNotNull($this->serverSeat()['action'], 'the fixture did not leave a call open');
        $this->assertNull($client->seat(self::INSTALL, self::SEAT)['action'],
            'the RED did not bite — the replayed delta was idempotent and § 11 says that proves nothing');

        // ⛔ THE SAME RUN WITH THE WATERMARK CONVERGES, which is what makes the line above a
        // finding about the watermark rather than about the fixture.
        $correct = new ClientHarness;
        $correct->subscribe();
        $this->deliverBufferedDeltas($correct, $mark);
        $correct->applySnapshot($body);

        foreach ($this->wire->ofTypeFrom('seat.delta', $mark2) as $m) {
            $correct->apply($m['payload']);
        }

        $correct->drain();

        $this->assertNotNull($correct->seat(self::INSTALL, self::SEAT)['action']);
        $this->assertClientMatchesServer($correct);
    }

    /**
     * GREEN — "running the same scenario **100 times** yields 100 identical results."
     *
     * The property under test is DETERMINISM of step 5, so the 100 runs vary the one thing the
     * protocol is allowed to see vary — the order deltas arrive in the buffer — and require the
     * drained result to be identical every time. A hundred identical runs of an identical input
     * would measure nothing.
     */
    public function test_the_drain_is_deterministic_over_a_hundred_arrival_orders(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $mark = count($this->wire->sent);

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $body = $this->snapshot();
        $deltas = array_map(
            fn ($m) => $m['payload'],
            array_slice($this->wire->ofTypeFrom('seat.delta', $mark), 0),
        );

        $this->assertGreaterThanOrEqual(2, count($deltas), 'the fixture produced too few deltas to shuffle');

        $results = [];

        for ($run = 0; $run < 100; $run++) {
            $client = new ClientHarness;
            $client->subscribe();

            $shuffled = $deltas;
            shuffle($shuffled);

            foreach ($shuffled as $d) {
                $client->buffer($d);
            }

            $client->applySnapshot($body);
            $client->drain();

            $results[] = json_encode($client->seat(self::INSTALL, self::SEAT));
        }

        $this->assertCount(1, array_unique($results), '100 arrival orders produced more than one result');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** Hand the client every `seat.delta` the wire has carried since `$mark` (step 2). */
    private function deliverBufferedDeltas(ClientHarness $client, int $mark): void
    {
        foreach ($this->wire->ofTypeFrom('seat.delta', $mark) as $message) {
            $client->buffer($message['payload']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();
    }

    /** @return array<string, mixed> the server's own object for this seat, right now */
    private function serverSeat(): array
    {
        return SeatObject::forSeatRef($this->seatRef, $this->nowMs());
    }

    /**
     * "the client's final state equals the server's `seat_state` EXACTLY" — field by field, over
     * the population a client is entitled to be right about.
     *
     * ⛔ THAT POPULATION IS § 6.5's VERSION-BEARING SET, NOT EVERY FIELD, AND THE DIFFERENCE IS
     * THE DESIGN RATHER THAN A WEAKENING. § 6.5 excludes TEN BOOKKEEPING MEMBERS from the set that
     * mints a delta — `delivery.last_receipt_at`, `last_heartbeat_at`, `last_seq`,
     * `clock_skew_ms`, `spool_lag_events`, `oldest_unsent_age_s`, `reporter.uptime_s`,
     * `derivation.computed_at`, `cursor_event_id`, `fold_lag_ms` — precisely so that a heartbeat
     * does not mint 1,440 deltas a seat-day. A client between deltas therefore HOLDS AN OLDER
     * COPY OF THOSE TEN BY CONSTRUCTION, and § 6.5 argues at length that this costs a consumer
     * nothing: "the ten ride the object on every snapshot and every detail response and are
     * simply never a *reason* to emit."
     *
     * Asserting equality on them would be asserting the opposite of the design, and it is how
     * this test first went red: the client legitimately held a `last_receipt_at` three seconds
     * behind the server's.
     *
     * The list below is DERIVED from `SeatDelta::WIRE_MEMBER` for the 1:1 members and states the
     * nested version-bearing sub-members explicitly, because the map's fingerprint keys
     * (`reporter_version`) and the wire's sub-keys (`reporter.version`) are spelled differently
     * and nothing in the code carries that translation — `SeatObject` does it inline.
     */
    private function assertClientMatchesServer(ClientHarness $client): void
    {
        $server = $this->serverSeat();
        $held = $client->seat(self::INSTALL, self::SEAT);

        $this->assertNotNull($held, 'the client holds no such seat');
        $this->assertSame($server['state_version'], $held['state_version'], 'client and server versions differ');

        foreach (SeatDelta::WIRE_MEMBER as $fingerprintKey => $member) {
            if ($fingerprintKey === $member) {              // the 1:1 members, compared whole
                $this->assertEquals($server[$member], $held[$member],
                    'the client diverged from the server on `'.$member.'`');
            }
        }

        // The nested version-bearing sub-members — the ones inside an object whose OTHER members
        // are among § 6.5's excluded ten.
        foreach ([
            ['delivery', 'no_data_since'],
            ['delivery', 'seq_epoch'],
            ['reporter', 'version'],
            ['reporter', 'platform'],
            ['reporter', 'selftest_failed'],
        ] as [$object, $sub]) {
            $this->assertEquals($server[$object][$sub], $held[$object][$sub],
                'the client diverged from the server on `'.$object.'.'.$sub.'`');
        }
    }
}

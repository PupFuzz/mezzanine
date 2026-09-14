<?php

namespace Tests\Feature\Feed;

use App\Feed\Outbox;
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
 * ⭐ RE-POINTED AT THE SSE TRANSPORT (card#9300). The client harness now OPENS `GET /api/fleet/stream`
 * and consumes the `text/event-stream` the route writes, frame by frame as it arrives
 * (`FeedTestCase::openStream()`), and the snapshot is fetched from INSIDE that open stream, between two
 * of the handler's ticks. What it replaced captured a broadcast the transport no longer makes; keeping
 * it green would have tested retired code.
 *
 * ⚠ THE "FORCED 500 ms DELAY" § 11's BUILD ASKS FOR IS DRIVEN AS AN ORDERING, NOT AS A SLEEP.
 *
 * What the delay exists to create is one condition: A STATE CHANGE THAT HAPPENS AFTER THE STREAM
 * OPENS AND BEFORE THE SNAPSHOT'S READ. The handler's clock is stepped one tick at a time
 * (`ScriptedStreamClock`), so that condition is produced by ORDER — open, change, GET — which is the
 * same condition exactly and is deterministic rather than a race the suite hopes to win. The
 * transport adds the ordering § 8.4 is really about: the delta is written behind a 2 s visibility
 * lag, so a change made in the window is ordinarily delivered AFTER the snapshot came back — both
 * arrival orders are driven below.
 *
 * See `ClientHarness` for what a test built on it is and is not evidence of.
 */
class At7SnapshotThenDeltasTest extends FeedTestCase
{
    /**
     * GREEN — "the client's final state equals the server's `seat_state` exactly, WHETHER THE
     * CHANGE LANDED BEFORE OR AFTER THE SNAPSHOT'S READ" — here, the change lands BEFORE the read,
     * and its delta reaches the client in both of the orders the lag allows: after the snapshot came
     * back (the ordinary case — discarded at the watermark), and before it (buffered, then drained).
     */
    public function test_a_change_inside_the_window_reaches_the_client(): void
    {
        foreach (['the delta arrives after the snapshot' => false, 'the delta arrives before the snapshot' => true] as $leg => $arrivesFirst) {
            $this->deliver($this->cleanTurn());
            $this->fold();

            $client = new ClientHarness;
            $client->subscribe();            // 1 + 2 — the stream opens and buffering begins
            $snapshotted = false;
            $arrivedBeforeSnapshot = 0;

            $snapshot = function () use ($client, &$snapshotted) {
                $client->applySnapshot($this->snapshot());   // 3 + 4
                $client->drain();                             // 5
                $snapshotted = true;
            };

            $this->quietPastTheLag();

            $stream = $this->openStream($this->enrolled(), [
                // ⛔ THE WINDOW. A state change lands after the stream opened and before the read.
                fn () => $this->deliver($this->blockedPair(requestOnly: true)),
                fn () => $this->fold(),
                ...($arrivesFirst ? [...$this->idle(12), $snapshot] : [$snapshot, ...$this->idle(12)]),
                $this->reloadStep(),
                ...$this->idle(10),
            ], function (array $envelope) use ($client, &$snapshotted, &$arrivedBeforeSnapshot) {
                if ($envelope['t'] !== 'seat.delta') {
                    return;
                }

                if ($snapshotted) {
                    $client->apply($envelope);      // 6 — steady state
                } else {
                    $arrivedBeforeSnapshot++;
                    $client->buffer($envelope);     // 2 — buffered
                }
            });

            $this->assertNotEmpty($stream->ofType('seat.delta'), $leg.': the change never reached the stream');
            $this->assertSame($arrivesFirst, $arrivedBeforeSnapshot > 0, $leg.': the fixture did not produce this arrival order');
            $this->assertSame('reload', $stream->last()['reason']);

            $this->assertClientMatchesServer($client);
            $this->assertSame('blocked', $client->seat(self::INSTALL, self::SEAT)['render_state'], $leg);
            $this->assertSame([], $client->resynced, $leg.': a window delta was mistaken for a gap');

            $this->refreshSeat();
        }
    }

    /**
     * The other half of the same GREEN: the change lands AFTER the snapshot's read. Its delta is then
     * strictly above the watermark and must be APPLIED, not discarded.
     */
    public function test_a_change_after_the_snapshots_read_reaches_the_client(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $client = new ClientHarness;
        $client->subscribe();
        $snapshotted = false;
        $this->quietPastTheLag();

        $stream = $this->openStream($this->enrolled(), [
            function () use ($client, &$snapshotted) {
                $client->applySnapshot($this->snapshot());
                $client->drain();
                $snapshotted = true;
            },
            fn () => $this->deliver($this->blockedPair(requestOnly: true)),
            fn () => $this->fold(),
            ...$this->idle(12),
            $this->reloadStep(),
            ...$this->idle(10),
        ], function (array $envelope) use ($client, &$snapshotted) {
            if ($envelope['t'] === 'seat.delta') {
                $snapshotted ? $client->apply($envelope) : $client->buffer($envelope);
            }
        });

        $this->assertNotEmpty($stream->ofType('seat.delta'));
        $this->assertClientMatchesServer($client);
        $this->assertSame('blocked', $client->seat(self::INSTALL, self::SEAT)['render_state']);
    }

    /**
     * ⛔ RED — ORDER. "snapshot first, stream after → the change made in the window is IN NEITHER, and
     * the desk stays wrong until something unrelated changes it. ASSERT THE DIVERGENCE EXPLICITLY; ON
     * A QUIET DESK IT IS PERMANENT."
     *
     * This is the one RED that cannot be driven by mutating production code, because the mistake IS
     * the client's ordering. It is driven by performing the wrong order against the real stream and
     * asserting the damage — and then that the damage is PERMANENT: the stream stays open across a
     * quiet stretch and delivers nothing that could heal it.
     */
    public function test_red_fetching_before_opening_the_stream_leaves_the_desk_permanently_wrong(): void
    {
        // The seat is settled AND heartbeating before the join, so the quiet stretch below is
        // genuinely quiet: `enabled` and the reporter fields are version-bearing and their FIRST
        // value is a real change, which would otherwise emit a delta and heal the divergence.
        $this->deliver($this->cleanTurn());
        $this->stayAlive();

        $client = new ClientHarness;

        // WRONG ORDER — the snapshot is read first.
        $body = $this->snapshot();

        // ⛔ THE CHANGE THAT LANDS IN THE WINDOW LEAVES NO CALL OPEN (§ 10's `clear_kill` ends
        // `unknown` with zero open calls), which is the state change this test needs and the quiet
        // stretch needs.
        $this->deliver($this->clearKill());
        $this->fold();

        $client->applySnapshot($body);

        // …and only NOW does the client open the stream. Nothing written before it is replayed: § 8.5
        // "there is no per-seat delta-replay buffer on the server and deliberately so", and the
        // handler's cursor starts at the head behind the lag.
        $client->subscribe();
        $this->quietPastTheLag();

        // ⛔ AND IT IS PERMANENT ON A QUIET DESK. A "quiet desk" is NOT a silent one — a silent seat
        // goes `stale` at 300 s and that transition IS a delta. It is a seat that keeps heartbeating
        // and does nothing else: § 6.5's "the heartbeat that moves nothing but bookkeeping emits no
        // delta". Ten of them, with a sweep after each, while the stream is open.
        $quiet = [];

        for ($i = 0; $i < 10; $i++) {
            $quiet[] = fn () => $this->stayAlive();
            $quiet[] = fn () => $this->sweep();
        }

        $stream = $this->openStream($this->enrolled(), [
            ...$this->idle(12),       // anything the window wrote would be visible by now
            ...$quiet,
            ...$this->idle(12),
            $this->reloadStep(),
            ...$this->idle(10),
        ], function (array $envelope) use ($client) {
            if ($envelope['t'] === 'seat.delta') {
                $client->apply($envelope);
            }
        });

        $this->assertSame('reload', $stream->last()['reason'], 'the stream did not stay open across the quiet stretch');
        $this->assertSame([], $stream->ofType('seat.delta'),
            'the desk was not quiet — the stream delivered a delta that would have healed the client');

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
     *   d1        CLEARS `action` (a call closes)        ← arrives before the snapshot: buffered
     *   snapshot  `action` is already null               ← step 4, buffer NOT yet drained
     *   d2        SETS `action` (a new call opens)       ← arrives in STEADY STATE and is applied
     *   drain     replays the buffer                     ← d1 lands ON TOP of d2
     *
     * ⚠ THE LAZY DRAIN IS WHAT MAKES IT VISIBLE, and it is inside the protocol as written: § 8.4's step
     * 5 and step 6 are separate steps. Every delta here arrives off the real stream.
     */
    public function test_second_red_replaying_a_delta_below_the_watermark_undoes_a_newer_one(): void
    {
        $this->deliver($this->openCall());         // a call is OPEN — `action` is set
        $this->fold();

        $client = new ClientHarness;
        $client->useWatermark = false;             // ← the mutation, in the client, per § 11
        $client->subscribe();

        $snapshotted = false;
        $body = null;
        $buffered = [];
        $applied = [];
        $this->quietPastTheLag();

        $stream = $this->openStream($this->enrolled(), [
            // d1 — the call closes; its delta CLEARS `action`.
            fn () => $this->deliver($this->closeOpenCall()),
            fn () => $this->fold(),
            ...$this->idle(12),
            function () use ($client, &$snapshotted, &$body) {
                $body = $this->snapshot();
                $this->assertNull($body['installs'][0]['seats'][0]['action'], 'the fixture must snapshot a cleared action');
                $client->applySnapshot($body);
                $snapshotted = true;
            },
            // d2 — strictly ABOVE the snapshot's version: a new call opens and `action` is set.
            fn () => $this->deliver($this->openCall()),
            fn () => $this->fold(),
            ...$this->idle(12),
            function () use ($client) {
                $this->assertNotNull($client->seat(self::INSTALL, self::SEAT)['action'],
                    'the fixture did not put an action on the client before the drain');
                $client->drain();                  // …and only NOW is the stale buffer drained. Unconditionally.
            },
            $this->reloadStep(),
            ...$this->idle(10),
        ], function (array $envelope) use ($client, &$snapshotted, &$buffered, &$applied) {
            if ($envelope['t'] !== 'seat.delta') {
                return;
            }

            if ($snapshotted) {
                $applied[] = $envelope;
                $client->apply($envelope);
            } else {
                $buffered[] = $envelope;
                $client->buffer($envelope);
            }
        });

        $this->assertNotEmpty($buffered, 'd1 did not arrive before the snapshot');
        $this->assertNotEmpty($applied, 'd2 did not arrive in steady state');
        $this->assertSame('reload', $stream->last()['reason']);

        $this->assertNotNull($this->serverSeat()['action'], 'the fixture did not leave a call open');
        $this->assertNull($client->seat(self::INSTALL, self::SEAT)['action'],
            'the RED did not bite — the replayed delta was idempotent and § 11 says that proves nothing');

        // ⛔ THE SAME FRAMES WITH THE WATERMARK CONVERGE, which is what makes the line above a finding
        // about the watermark rather than about the fixture.
        $correct = new ClientHarness;
        $correct->subscribe();

        foreach ($buffered as $d) {
            $correct->buffer($d);
        }

        $correct->applySnapshot($body);

        foreach ($applied as $d) {
            $correct->apply($d);
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
     * drained result to be identical every time. The deltas are the `feed_outbox` rows the fold
     * committed, which are the bytes every stream writes.
     */
    public function test_the_drain_is_deterministic_over_a_hundred_arrival_orders(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $mark = $this->wire->mark();

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $body = $this->snapshot();
        $deltas = array_map(fn ($m) => $m['payload'], $this->wire->ofTypeFrom('seat.delta', $mark));

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

    /**
     * Let every row written so far age past § 8.3's visibility lag before the stream opens.
     *
     * ⚠ NOT A CONVENIENCE: the handler's connect read is the head BEHIND the lag (§ 8.3), so a stream
     * opened within 2 s of a write also delivers that write — "at most one lag-window of rows it may
     * also see in its snapshot — harmless, because § 8.4's per-seat watermark is what discards
     * those". These tests measure the window § 8.4 closes, so the fixture's own setup writes must be
     * behind the stream's starting cursor, or every arrival-order assertion counts them too.
     */
    private function quietPastTheLag(): void
    {
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);
    }

    /** A fresh seat state for the next leg of a two-leg test: retire nothing, just move the desk on. */
    private function refreshSeat(): void
    {
        $this->deliver($this->cleanTurn('leg-'.$this->ulid()));
        $this->fold();
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

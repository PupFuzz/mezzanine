<?php

namespace Tests\Feature\Feed;

use App\Feed\SeatDelta;
use App\Read\SeatObject;

/**
 * **AT-D2-8 — a delta gap is detected and resynced** (`docs/design/FLEET-STATE.md § 11`, § 8.5).
 *
 * § 8.5's rule: "a client applies a delta iff `delta.state_version == local.state_version + 1`. If
 * it is greater, deltas were lost: the client **re-syncs that one seat** via
 * `GET /api/fleet/seats/{install}/{seat}?resync_from=<its last applied version>`."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SERVER PROPERTY THIS TEST IS REALLY ABOUT: ONE DELTA IS EXACTLY ONE VERSION.
 *
 * § 11's GREEN is "the client sees `state_version` **jump by 2**" after exactly one delta is
 * dropped. That sentence is only evidence of a gap if the server never skips a version and never
 * repeats one — so `test_every_delta_is_exactly_one_version_above_its_predecessor` below asserts
 * that directly, and it is the premise everything else here rests on.
 *
 * ⚠ IT IS ALSO WHY CARD #7827 DOES NOT COALESCE, and the reason is a D2 incoherence reported in
 * that card's PR body rather than resolved here: § 8.3's "one delta per seat per 250 ms … merged
 * into one message" and § 8.5's `== local + 1` cannot both hold, because a merged message at v6
 * is byte-indistinguishable, to a client holding v4, from a lost delta at v5. AT-D2-8 is the
 * document's own strongest statement of which of the two it means.
 *
 * See `ClientHarness` for what a test built on it is and is not evidence of.
 */
class At8DeltaGapTest extends FeedTestCase
{
    /**
     * ⛔ THE PREMISE. Every delta a seat emits is its predecessor's version plus exactly one — no
     * skips, no repeats — across a fixture that drives ten state changes.
     */
    public function test_every_delta_is_exactly_one_version_above_its_predecessor(): void
    {
        $this->deliver($this->clearKill());     // § 10's ten events, ten deltas
        $this->fold();
        $this->deliver($this->blockedPair());
        $this->fold();

        $versions = array_map(
            fn ($m) => $m['payload']['state_version'],
            $this->wire->deltasFor(self::INSTALL, self::SEAT),
        );

        $this->assertGreaterThanOrEqual(10, count($versions), 'the fixture produced too few deltas');

        $this->assertSame(
            range($versions[0], $versions[0] + count($versions) - 1),
            $versions,
            'a delta skipped or repeated a version — § 8.5\'s gap check cannot mean anything',
        );

        // …and the last one equals the seat's own stored version, so a client that applied every
        // delta is exactly where the server is.
        $this->assertSame((int) $this->state()->state_version, end($versions));
    }

    /**
     * GREEN — drop exactly one delta in flight.
     *
     * "the client sees `state_version` jump by 2, fetches `?resync_from=…`, converges to the
     * server's state, and **the server** increments `feed_gap_detected` — assert the counter ON
     * THE SERVER after the request, because the counter has exactly one write path and it is that
     * query parameter."
     */
    public function test_one_dropped_delta_is_detected_resynced_and_counted(): void
    {
        [$otherToken, $otherRef] = $this->secondSeat();
        $this->deliver($this->cleanTurn(), token: $otherToken, seat: 'aimla-impl');
        $this->fold();

        $this->deliver($this->cleanTurn());
        $this->fold();

        $client = new ClientHarness;
        $client->subscribe();
        $client->applySnapshot($this->snapshot());

        $otherVersionBefore = (int) $this->state($otherRef)->state_version;
        $mark = count($this->wire->sent);

        // Two further changes on THIS seat, so there is a delta to drop and one to notice it with.
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $deltas = array_map(fn ($m) => $m['payload'], $this->wire->ofTypeFrom('seat.delta', $mark));
        $this->assertGreaterThanOrEqual(2, count($deltas), 'the fixture produced too few deltas to drop one');

        // ⛔ DROP EXACTLY ONE, IN FLIGHT — the first of the run, so every later one is above the
        // hole and the FIRST of them is the one that reveals it.
        $dropped = array_shift($deltas);
        $this->assertNotNull($dropped);

        foreach ($deltas as $delta) {
            $client->apply($delta, $this->resyncUsing());
        }

        // 1 — THE CLIENT SAW A JUMP AND RESYNCED THAT ONE SEAT.
        $this->assertSame([self::INSTALL.'/'.self::SEAT], $client->resynced);

        // 2 — AND CONVERGED.
        $this->assertSame(
            (int) $this->state()->state_version,
            $client->seat(self::INSTALL, self::SEAT)['state_version'],
        );
        $this->assertSame('blocked', $client->seat(self::INSTALL, self::SEAT)['render_state']);

        // 3 — THE SERVER COUNTED IT. One write path, and it is the query parameter.
        $this->assertSame(1, $this->globalCounter('feed_gap_detected'));

        // 4 — "The rest of the fleet is untouched — assert other seats' versions DID NOT MOVE."
        $this->assertSame($otherVersionBefore, (int) $this->state($otherRef)->state_version);
        $this->assertNotContains(self::INSTALL.'/aimla-impl', $client->resynced);
    }

    /**
     * ⛔ DISCRIMINATING CONTROL — "drop **zero** deltas in an otherwise identical run → NO RESYNC,
     * NO COUNTER. Without it, a client that resyncs constantly would pass the GREEN."
     */
    public function test_control_dropping_nothing_resyncs_nothing_and_counts_nothing(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $client = new ClientHarness;
        $client->subscribe();
        $client->applySnapshot($this->snapshot());

        $mark = count($this->wire->sent);

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        foreach ($this->wire->ofTypeFrom('seat.delta', $mark) as $m) {
            $client->apply($m['payload'], $this->resyncUsing());
        }

        $this->assertSame([], $client->resynced, 'a client with no gap resynced anyway');
        $this->assertSame(0, $this->globalCounter('feed_gap_detected'));

        // …and it converged without one, which is what makes the line above a control rather than
        // a test of a client that simply does nothing.
        $this->assertSame(
            (int) $this->state()->state_version,
            $client->seat(self::INSTALL, self::SEAT)['state_version'],
        );
        $this->assertSame('blocked', $client->seat(self::INSTALL, self::SEAT)['render_state']);
    }

    /**
     * ⛔ SECOND CONTROL — "fetch the same endpoint **without** `resync_from` (an ordinary
     * drill-down open) → `feed_gap_detected` does not move, WHICH IS WHAT PROVES THE COUNTER
     * MEASURES GAPS AND NOT PANEL OPENS."
     */
    public function test_second_control_an_ordinary_drill_down_open_moves_no_counter(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $token = $this->readToken();

        for ($i = 0; $i < 5; $i++) {
            $this->asMachine($token, '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT)->assertOk();
        }

        $this->assertSame(0, $this->globalCounter('feed_gap_detected'));

        // A CLIENT ONE BEHIND IS NOT A GAP EITHER — § 8.5 counts only when "the seat's current
        // version EXCEEDS IT BY MORE THAN 1".
        $current = (int) $this->state()->state_version;
        $this->asMachine($token, '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'?resync_from='.($current - 1))
            ->assertOk();

        $this->assertSame(0, $this->globalCounter('feed_gap_detected'));

        // …and one that IS behind by more than 1 does move it, or the two lines above measure a
        // counter nothing can increment.
        $this->asMachine($token, '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'?resync_from='.($current - 3))
            ->assertOk();

        $this->assertSame(1, $this->globalCounter('feed_gap_detected'));
    }

    /**
     * § 8.5: "The server also validates it: `resync_from` GREATER than the seat's current version
     * is ignored and counted nowhere, BECAUSE A CLIENT CANNOT BE AHEAD OF THE SERVER."
     */
    public function test_a_client_claiming_to_be_ahead_of_the_server_is_counted_nowhere(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $current = (int) $this->state()->state_version;

        $this->asMachine($this->readToken(),
            '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'?resync_from='.($current + 500))
            ->assertOk();

        $this->assertSame(0, $this->globalCounter('feed_gap_detected'));
    }

    /**
     * ⛔ RED — "remove `state_version` from the delta and apply on arrival → the client diverges
     * SILENTLY and renders the pre-drop state indefinitely. ASSERT THE DIVERGENCE BY COMPARING THE
     * CLIENT'S OBJECT TO `seat_state` FIELD BY FIELD; a test that only checks 'no error was
     * thrown' would pass here."
     */
    public function test_red_a_version_blind_client_diverges_silently_and_stays_wrong(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $client = new ClientHarness;
        $client->checkVersion = false;        // ← the mutation § 11 names, in the client
        $client->subscribe();
        $client->applySnapshot($this->snapshot());

        $mark = count($this->wire->sent);

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $deltas = array_map(fn ($m) => $m['payload'], $this->wire->ofTypeFrom('seat.delta', $mark));
        $dropped = array_shift($deltas);      // the same single drop as the GREEN above

        foreach ($deltas as $delta) {
            $client->apply($delta, $this->resyncUsing());
        }

        // 1 — NOTHING WAS DETECTED. No resync, no counter: the loss is invisible.
        $this->assertSame([], $client->resynced, 'the RED did not bite — a gap was detected anyway');
        $this->assertSame(0, $this->globalCounter('feed_gap_detected'));

        // 2 — AND THE CLIENT IS WRONG, FIELD BY FIELD. § 11 is explicit that asserting "no error
        // was thrown" would pass here, so the assertion is a comparison against the server's own
        // object over the version-bearing members.
        $server = SeatObject::forSeatRef($this->seatRef, $this->nowMs());
        $held = $client->seat(self::INSTALL, self::SEAT);

        $diverged = [];

        foreach (SeatDelta::WIRE_MEMBER as $fingerprintKey => $member) {
            if ($fingerprintKey === $member && $server[$member] != $held[$member]) {
                $diverged[] = $member;
            }
        }

        $this->assertNotEmpty($diverged,
            'the RED produced no divergence — the dropped delta carried nothing, so this fixture proves nothing');

        // …and what diverged is what the DROPPED delta carried, derived from its own `changed`
        // list rather than guessed. Asserting a hand-picked member name here would be a test that
        // silently stops measuring the drop the day the fixture's first delta changes shape.
        $this->assertNotEmpty(
            array_intersect($dropped['changed'], $diverged),
            'the client diverged on something the dropped delta did not carry — the fixture is not measuring the drop',
        );

        // 3 — AND IT STAYS WRONG. "renders the pre-drop state INDEFINITELY": ten more quiet
        // minutes and the client's object has not moved.
        $frozen = $client->seat(self::INSTALL, self::SEAT);

        $quietFrom = count($this->wire->sent);

        for ($i = 0; $i < 10; $i++) {
            $this->stayAlive();
            $this->sweep();
        }

        foreach ($this->wire->ofTypeFrom('seat.delta', $quietFrom) as $m) {
            $client->apply($m['payload'], $this->resyncUsing());
        }

        $member = array_values(array_intersect($dropped['changed'], $diverged))[0];

        $this->assertEquals($frozen[$member], $client->seat(self::INSTALL, self::SEAT)[$member],
            'the divergence healed by itself, so this is not the indefinite case § 11 names');
        $this->assertNotEquals($server[$member], $client->seat(self::INSTALL, self::SEAT)[$member],
            'the client caught up to the server without ever detecting the loss');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * The client's resync: § 8.5's drill-down `GET` WITH the parameter, through the real HTTP
     * surface — because the counter's one write path is that parameter and a helper that called
     * the controller directly would not exercise it.
     */
    private function resyncUsing(): \Closure
    {
        $token = $this->readToken();

        return function (string $installId, string $seatId, int $from) use ($token): array {
            return $this->asMachine(
                $token,
                '/api/fleet/seats/'.$installId.'/'.$seatId.'?resync_from='.$from,
            )->assertOk()->json();
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();
    }
}

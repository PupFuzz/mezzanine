<?php

namespace Tests\Feature\Feed;

use App\Sweep\Purge;
use Illuminate\Support\Facades\DB;

/**
 * **AT-D2-23's PRIMARY RED — "the vanishing desk" — which card #7712 shipped UNDRIVEN because it
 * is a wire-surface assertion** (`docs/design/FLEET-STATE.md § 11`, § 4.10, § 8.3).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 11's RED, verbatim: "**drop retired seats from the snapshot query AT `retired_at`** → a
 * browser that reloads sees a seat that existed a second ago SIMPLY GONE, which is the 'vanishing
 * between two refreshes' § 4.5 forbids, and there is no rendered state that says why."
 *
 * § 11's GREEN, the half #7712 could not reach: "connected clients receive `seat.retired`; THE
 * NEXT SNAPSHOT carries the seat with `render_state: "retired"` and a populated `retired` object …
 * `fleet.seats_total` still counts it. Past 14 days … the seat is ABSENT FROM THE SNAPSHOT WHILE
 * ITS ROW IS STILL IN `seats`: assert both, because THE DISAPPEARANCE MUST BE A READ FILTER AND
 * NOT A DELETION."
 *
 * `Tests\Feature\Fold\At23RetiredSeatTest` owns the store half — the command, the transaction, the
 * `cause: operator` row, the second and third REDs, the axes deriving underneath, the row
 * surviving the purge. It named this file's contents as out of scope in terms. Both halves
 * together are AT-D2-23.
 */
class At23WireSurfaceTest extends FeedTestCase
{
    /**
     * GREEN — "connected clients receive `seat.retired`", on § 8.3's channel with § 8.3's payload.
     *
     * Card #7712 built `App\Events\SeatRetired` as "the publication POINT, not the publication"
     * and left the wire to Part B. This is the wire.
     */
    public function test_retiring_publishes_seat_retired_and_the_delta_at_the_same_version(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->wire->forget();

        $this->retire();

        $retired = $this->wire->ofType('seat.retired');
        $this->assertCount(1, $retired, 'no seat.retired reached the wire');

        // § 8.3's channel: `private-fleet.{install_id}`, one per install.
        $this->assertSame(['private-fleet.'.self::INSTALL], $retired[0]['channels']);

        // § 8.3's envelope, on every message.
        $this->assertSame(1, $retired[0]['payload']['feed_version']);
        $this->assertSame('seat.retired', $retired[0]['payload']['t']);
        $this->assertArrayHasKey('server_time', $retired[0]['payload']);

        // § 8.3's declared payload for this row: install_id, seat_id, reason, at.
        $this->assertSame(self::INSTALL, $retired[0]['payload']['install_id']);
        $this->assertSame(self::SEAT, $retired[0]['payload']['seat_id']);
        $this->assertSame('decommissioned', $retired[0]['payload']['reason']);
        $this->assertNotNull($retired[0]['payload']['at']);

        // § 4.10: "the `seat.retired` feed message AND THE DELTA carrying `render_state:
        // "retired"`, both published by `mezzanine:retire` in the transaction that sets the
        // columns". Both, at one version — which is what lets a consumer see it has both.
        $deltas = $this->wire->deltasFor(self::INSTALL, self::SEAT);
        $this->assertCount(1, $deltas, 'retirement published no delta');
        $this->assertSame('retired', $deltas[0]['payload']['patch']->render_state);
        $this->assertContains('render_state', $deltas[0]['payload']['changed']);
        $this->assertSame(
            $retired[0]['payload']['state_version'],
            $deltas[0]['payload']['state_version'],
            '§ 8.5: the two announcements of one transaction sit at different versions',
        );
    }

    /**
     * ⛔ THE PRIMARY RED's GREEN: the desk is STILL THERE, and it says why it went.
     *
     * The mutation that drives it is `App\Read\RetirementFilter` dropping the seat at
     * `retired_at`; the assertion here is the state that mutation destroys.
     */
    public function test_the_next_snapshot_still_carries_the_seat_and_says_an_operator_retired_it(): void
    {
        [$liveToken, $liveRef] = $this->secondSeat();

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $before = $this->snapshotSeats();
        $this->assertArrayHasKey(self::SEAT, $before);

        $this->retire();

        $body = $this->snapshot();
        $seats = $this->index($body);

        // 1 — STILL THERE. Not gone between two refreshes.
        $this->assertArrayHasKey(self::SEAT, $seats, '§ 11 AT-D2-23 RED: the desk vanished');

        // 2 — AND IT SAYS WHY. § 4.10: "with `render_state: "retired"` and a `retired` object
        // carrying `at`, `by` and `reason`."
        $this->assertSame('retired', $seats[self::SEAT]['render_state']);
        $this->assertNotNull($seats[self::SEAT]['retired']);
        $this->assertSame('operator@aimla', $seats[self::SEAT]['retired']['by']);
        $this->assertSame('decommissioned', $seats[self::SEAT]['retired']['reason']);
        $this->assertNotNull($seats[self::SEAT]['retired']['at']);

        // 3 — "at THAT snapshot `link_state` / `activity_state` still carry what the seat was
        // doing when it was retired". Retirement is an administrative fact, not a transport one.
        $this->assertSame('live', $seats[self::SEAT]['link_state']);
        $this->assertSame('blocked', $seats[self::SEAT]['activity_state']);

        // 4 — "`fleet.seats_total` still counts it."
        $this->assertSame(2, $body['fleet']['seats_total']);

        // DISCRIMINATING CONTROL — "a live seat in the same fleet is unaffected at every step."
        $this->assertArrayHasKey('aimla-impl', $seats);
        $this->assertNull($seats['aimla-impl']['retired']);
    }

    /**
     * GREEN — past 14 days: "the seat is absent from the snapshot **while its row is still in
     * `seats`**: assert BOTH, because the disappearance must be a READ FILTER and not a deletion."
     */
    public function test_past_fourteen_days_the_read_filter_drops_it_and_the_row_remains(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->retire();

        $this->assertArrayHasKey(self::SEAT, $this->snapshotSeats(), 'the filter fired too early');

        // Just inside the window — the boundary, from the rendered side.
        $this->advanceServerClock(Purge::RETENTION_DAYS * 86400 - 60);
        $this->assertArrayHasKey(self::SEAT, $this->snapshotSeats(),
            'the seat left the snapshot before its 14 days were up');

        $this->advanceServerClock(120);

        $seats = $this->snapshotSeats();
        $this->assertArrayNotHasKey(self::SEAT, $seats, 'the read filter never fires');

        // …AND THE ROW IS STILL THERE, with its reason. § 4.10: "an operator query can still find
        // the row and its reason." Both halves, because a deletion would satisfy the line above.
        $row = DB::table('seats')->where('id', $this->seatRef)->first();
        $this->assertNotNull($row, 'the disappearance was a DELETION, not a read filter');
        $this->assertSame('decommissioned', $row->retired_reason);

        // The seat-detail endpoint agrees with the snapshot, because § 4.10 says "the READ
        // QUERIES stop selecting it" — plural. A filter on one surface and not the other is a
        // desk that is gone from the floor and reachable by URL.
        $this->asMachine($this->readToken(), '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT)
            ->assertNotFound()
            ->assertJsonPath('error', 'seat_not_found');
    }

    /**
     * § 8.2.4's population follows the same filter — "excluding seats retired more than 14 days
     * ago" — so `seats_total` cannot disagree with the seat list beside it.
     */
    public function test_the_fleet_counts_follow_the_same_read_filter_as_the_seat_list(): void
    {
        $this->secondSeat();
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->retire();

        $this->advanceServerClock(Purge::RETENTION_DAYS * 86400 + 60);

        $body = $this->snapshot();

        $this->assertCount(1, $body['installs'][0]['seats']);
        $this->assertSame(1, $body['fleet']['seats_total'],
            'the seat list and `seats_total` read different populations');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();
    }

    /** @return array<string, array<string, mixed>> seat objects keyed by `seat_id` */
    private function snapshotSeats(): array
    {
        return $this->index($this->snapshot());
    }

    /** @return array<string, array<string, mixed>> */
    private function index(array $body): array
    {
        $out = [];

        foreach ($body['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $out[$seat['seat_id']] = $seat;
            }
        }

        return $out;
    }
}

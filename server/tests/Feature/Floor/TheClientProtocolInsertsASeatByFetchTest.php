<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md` **AT-D3-17's protocol half** — a delta for a seat the client does not hold
 * is not patched into an empty object: the client FETCHES the seat, holds the whole thing, and
 * narrates the arrival. Appendix B row 3's gate; fixture `fx-membership`, run `leg_a`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EACH DELIVERED DELTA PATCHES ONLY `context`, WHICH IS WHAT MAKES THE RED VISIBLE. A client
 * that built the seat out of the delta's own members would hold an object with a `context` and
 * nothing else — no `render_state`, no `link_state` — and § 2.2's "never a partial object" is
 * exactly that shape. Had the delta patched `render_state`, the planted client would hold
 * something that looks enough like a seat for every assertion to pass.
 *
 * ⛔ THE ENVELOPE IS NOT THE SEAT. `FleetController::seat()` answers `api_version`, `server_time`,
 * the seat object's members, then `detail`; three of those are REST packaging. A held object
 * carrying `server_time` puts it one shallow merge away from being read as a seat member by
 * every later consumer, and nothing downstream could tell it from one.
 *
 * ⚠ WHAT IS NOT HERE: AT-D3-17's *rendered desk* assertion and its Second RED, both of which read
 * the floor renderer built at Appendix B step 6.
 */
class TheClientProtocolInsertsASeatByFetchTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /** G5 — one fetch, the whole seat, the in-flight delta drained onto it, and the record's line. */
    public function test_an_unheld_seats_delta_fetches_the_seat_and_drains_onto_it(): void
    {
        $fixture = $this->fixture('leg_a');
        $result = $this->replay('leg_a');

        $this->assertSame(['/api/fleet/seats/aimla/aimla-impl-3'], $this->seatRequests($result),
            'the insert fetch was not issued exactly once');

        $this->assertSame($fixture['final'], $this->finalSeats($result),
            'the fetched seat plus the delta that arrived while the fetch was in flight is not the '
            .'stated map');

        $seat = $this->finalSeats($result)['aimla/aimla-impl-3'];

        // The fetch answered 5001; 5002 was delivered while it was in flight and drained onto it.
        $this->assertSame(5002, $seat['state_version']);
        $this->assertSame(13.1, $seat['context']['used_pct']);

        foreach (['api_version', 'server_time', 'detail'] as $member) {
            $this->assertArrayNotHasKey($member, $seat,
                "the REST envelope member `{$member}` entered the held seat object");
        }

        $this->assertStringContainsString('seat added to the floor: aimla/aimla-impl-3',
            $result['final']['event_log'][0] ?? '',
            'the record’s newest line does not narrate the arrival');

        // § 2.4: the SEAT RESPONSE carries `server_time` too, so it refreshes the offset. The
        // fixture states what the offset must be, and what it would be had the response been
        // skipped — the two are 400 ms apart, which is what makes this assertion discriminating.
        $this->assertSame($fixture['final_clock_offset_ms'], $result['final']['clock_offset_ms'],
            'the offset was not refreshed from the seat response');
    }

    /** C2 — the seat is already held: no fetch, no arrival line. */
    public function test_a_held_seats_delta_fetches_nothing_and_narrates_nothing(): void
    {
        $fixture = $this->fixture('leg_a.held');
        $result = $this->replay('leg_a.held');

        $this->assertSame([], $this->seatRequests($result),
            'the client fetched a seat it already held');

        $this->assertSame([], array_values(array_filter(
            $result['final']['event_log'],
            static fn (string $line): bool => str_contains($line, 'seat added'),
        )), 'an arrival was narrated for a seat that was already on the floor');

        $this->assertSame($fixture['final'], $this->finalSeats($result));
    }

    /**
     * H5 — a failed insert fetch drops the buffer it could not chain from, and the NEXT delta for
     * the key re-fetches. The seat recovers; it is not stuck.
     */
    public function test_a_failed_insert_fetch_is_retried_by_the_next_delta(): void
    {
        $result = $this->replay('seat_fetch_fails');

        $this->assertCount(2, $this->seatRequests($result),
            'the failed insert fetch was not retried on the next delta');
        $this->assertSame(5003, $this->finalSeats($result)['aimla/aimla-impl-3']['state_version'],
            'the seat did not recover to the version the second fetch answered');
    }

    /** H6 — a failed FIRST snapshot stops the client: nothing held, nothing fetched, phase fixed. */
    public function test_a_failed_first_snapshot_stops_buffering_and_applies_nothing(): void
    {
        $result = $this->replay('first_snapshot_fails');

        $this->assertSame([], $this->seatRequests($result),
            'the client fetched a seat with no snapshot to chain it onto');
        $this->assertSame([], $this->finalSeats($result),
            'the client built a seat map out of deltas alone — § 2.2’s forbidden partial object');
        $this->assertSame('snapshot-failed', $result['final']['phase']);
    }

    /** Every planted client for this test, each diverging on the field its row names. */
    public function test_the_planted_clients_each_diverge_on_the_field_their_row_names(): void
    {
        // P6 — the RED: patch into an empty object, no fetch. The seat exists and has no state.
        $empty = $this->replay('leg_a', $this->plantedClient(FleetClientPlants::EMPTY[0]));
        $emptySeat = $this->finalSeats($empty)['aimla/aimla-impl-3'];

        $this->assertArrayNotHasKey('render_state', $emptySeat,
            'P6 did not bite: the fetch was removed and the client still ended holding a whole seat');
        $this->assertSame([], $this->seatRequests($empty));

        // P7 — `detail` kept.
        $keepdetail = $this->replay('leg_a', $this->plantedClient(FleetClientPlants::KEEPDETAIL[0]));

        $this->assertSame(['detail'], $this->envelopeMembersHeld($keepdetail),
            'P7 did not bite: the strip was narrowed and `detail` still did not reach the held object');

        // P20 — `api_version` and `server_time` kept.
        $envelope = $this->replay('leg_a', $this->plantedClient(FleetClientPlants::ENVELOPE[0]));

        $this->assertSame(['api_version', 'server_time'], $this->envelopeMembersHeld($envelope),
            'P20 did not bite: the strip was narrowed and the envelope still did not reach the held '
            .'object');

        // P8 — the seat's deltas are not buffered during its own fetch, so it is fetched twice and
        // the in-flight delta is lost. ⚠ The second request is unscripted BY CONSTRUCTION.
        $nopending = $this->replay('leg_a', $this->plantedClient(FleetClientPlants::NOPENDING[0]), [], true);

        $this->assertCount(2, $this->seatRequests($nopending),
            'P8 did not bite: buffering was removed and the client still issued one request');
        $this->assertSame(5001, $this->finalSeats($nopending)['aimla/aimla-impl-3']['state_version'],
            'P8 did not bite on the map: the drained delta is still applied');

        // P21 — the seat response is not read for `server_time`, so the offset is one message old.
        $nooffset = $this->replay('leg_a', $this->plantedClient(FleetClientPlants::NOOFFSET[0]));

        $this->assertNotSame($this->fixture('leg_a')['final_clock_offset_ms'], $nooffset['final']['clock_offset_ms'],
            'P21 did not bite: the seat response was cut out of § 2.4’s refresh and the offset did '
            .'not move');

        // P13 — the buffer is deleted rather than released, so the in-flight delta is dropped.
        $nodrain = $this->replay('leg_a', $this->plantedClient(FleetClientPlants::NODRAIN[0]));

        $this->assertSame(5001, $this->finalSeats($nodrain)['aimla/aimla-impl-3']['state_version'],
            'P13 did not bite: the release was replaced by a delete and the buffered delta still '
            .'reached the map');

        // P16 — a failed fetch leaves the key pending forever, so no later delta can retry it.
        $m9seat = $this->replay('seat_fetch_fails', $this->plantedClient(FleetClientPlants::M9SEAT[0]));

        $this->assertCount(1, $this->seatRequests($m9seat),
            'P16 did not bite: the key stayed pending and the client retried anyway');
        $this->assertArrayNotHasKey('aimla/aimla-impl-3', $this->finalSeats($m9seat));

        // P17 — a failed first snapshot goes live and releases its buffers. ⚠ Those releases issue
        // requests the fixture scripts nothing for, which is the defect, not an accident.
        $m9snap = $this->replay('first_snapshot_fails', $this->plantedClient(FleetClientPlants::M9SNAP[0]), [], true);

        $this->assertSame('live', $m9snap['final']['phase'],
            'P17 did not bite: the failed snapshot still stopped the client');
        $this->assertNotSame([], $this->seatRequests($m9snap),
            'P17 did not bite: the buffers were released and nothing was fetched');
    }

    /**
     * Which REST-envelope members reached the held seat object — the field P7 and P20 diverge on.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function envelopeMembersHeld(array $result): array
    {
        $seat = $this->finalSeats($result)['aimla/aimla-impl-3'] ?? [];

        return array_values(array_filter(
            ['api_version', 'server_time', 'detail'],
            static fn (string $member): bool => array_key_exists($member, $seat),
        ));
    }
}

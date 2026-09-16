<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md` **AT-D3-7's protocol half** — a dropped delta leaves a gap, the client
 * notices it from the version alone, resyncs from the last version it APPLIED, and converges on
 * the state the dropped delta carried. Appendix B row 3's gate; fixture `fx-gap`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE DROPPED DELTA PATCHES A MEMBER NEITHER DELIVERED DELTA TOUCHES (`context.used_pct`), and
 * that is what makes this test able to fail at all. Were the dropped delta's members re-patched by
 * a later one, a client that never resynced would reach the same map and every assertion below
 * would pass against it.
 *
 * ⛔ THE SECOND RED IS ABOUT THE REQUEST, NOT THE MAP. A client that omits `?resync_from=` STILL
 * CONVERGES — the endpoint answers the whole seat object either way — so the map is not an
 * observable for it, and the test asserts the request list instead. `noparam`'s recorded
 * divergence is exactly that: the same final map, a different request.
 *
 * ⚠ WHAT IS NOT HERE: AT-D3-7's strip half (*resyncs: N*), which reads the connection strip built
 * at Appendix B step 8. § 11's rule gates a test at or after the step that builds every artifact
 * its GREEN reads, which is what splits this test in two.
 */
class TheClientProtocolConvergesOnAGapTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /** G1 — the GREEN, and the one resync that produces it. */
    public function test_a_gap_resyncs_from_the_last_applied_version_and_converges(): void
    {
        $fixture = $this->fixture('fx-gap');
        $result = $this->replay('fx-gap');

        $this->assertSame($fixture['final'], $this->finalSeats($result),
            'the client did not converge on the state the DROPPED delta carried — a gap the resync '
            .'should have closed is still open');

        // The last version APPLIED, not the newest version SEEN: 48222 arrived and was buffered,
        // 48220 was applied, so the resync must ask from 48220 or the server re-sends what the
        // client already has.
        $this->assertSame(['/api/fleet/seats/aimla/aimla-pm?resync_from=48220'], $this->seatRequests($result),
            'the resync did not name the last version the client applied');

        $newest = $result['final']['event_log'][0] ?? '';

        $this->assertStringContainsString('aimla/aimla-pm', $newest, 'the record’s newest line does not name the seat');
        $this->assertStringContainsString('resync', $newest, 'the record’s newest line does not name the resync');
    }

    /** C1 — the discriminating control: nothing dropped, nothing resynced. */
    public function test_no_gap_issues_no_request_and_writes_no_resync_line(): void
    {
        $result = $this->replay('fx-gap.no_drop');

        $this->assertSame([], $this->seatRequests($result),
            'every delta arrived in order and the client fetched anyway — the resync is not being '
            .'driven by the gap');

        $this->assertSame([], array_values(array_filter(
            $result['final']['event_log'],
            static fn (string $line): bool => str_contains($line, 'resync'),
        )), 'a resync line was written for a run with no gap');
    }

    /** C3 — B2(a): a delta that arrives DURING the resync is buffered, never a second fetch. */
    public function test_a_delta_during_an_in_flight_resync_is_buffered_not_re_fetched(): void
    {
        $fixture = $this->fixture('fx-gap.in_flight');
        $result = $this->replay('fx-gap.in_flight');

        $this->assertCount(1, $this->seatRequests($result),
            'a delta that landed while the resync was in flight started a second fetch');
        $this->assertSame($fixture['final'], $this->finalSeats($result));
    }

    /** H4 — B2(d): a gap INSIDE the connect buffer starts a resync when the buffer drains. */
    public function test_a_gap_inside_the_connect_buffer_starts_a_resync(): void
    {
        $fixture = $this->fixture('fx-gap.connect_window');
        $result = $this->replay('fx-gap.connect_window');

        $this->assertSame(['/api/fleet/seats/aimla/aimla-pm?resync_from=48220'], $this->seatRequests($result),
            'the drain applied a buffered gap without noticing it was one');
        $this->assertSame($fixture['final'], $this->finalSeats($result));
    }

    /**
     * The planted clients. Each names the field it diverges on, and each is asserted to diverge on
     * exactly that field — a control that merely "fails" tells a later reader nothing about what it
     * was guarding.
     */
    public function test_the_planted_clients_each_diverge_on_the_field_their_row_names(): void
    {
        $fixture = $this->fixture('fx-gap');

        // P4 — AT-D3-7's RED: apply every live delta unconditionally. The delta above the gap is
        // applied over the held object, so the dropped delta's member keeps the snapshot's value.
        $uncond = $this->replay('fx-gap', $this->plantedClient(FleetClientPlants::UNCOND[0]));

        $this->assertSame(73.2, $uncond['final']['seats']['aimla/aimla-pm']['context']['used_pct'],
            'P4 did not bite: a client applying every delta unconditionally still ended on the '
            .'dropped delta’s value, so the convergence assertion is not measuring the resync');
        $this->assertSame([], $this->seatRequests($uncond));

        // P5 — the Second RED: the resync omits the parameter. The map STILL converges, which is
        // why the request list is the observable.
        $noparam = $this->replay('fx-gap', $this->plantedClient(FleetClientPlants::NOPARAM[0]));

        $this->assertSame(['/api/fleet/seats/aimla/aimla-pm'], $this->seatRequests($noparam),
            'P5 did not bite: the parameter was removed and the request still carried it');
        $this->assertSame($fixture['final'], $this->finalSeats($noparam),
            'P5’s own premise is that the map still converges — if it no longer does, the request '
            .'list has stopped being the only thing this RED can be measured on');

        // P14 — B2(a): a pending fetch stops buffering, so the seat is fetched twice. ⚠ THE SECOND
        // REQUEST IS UNSCRIPTED BY CONSTRUCTION — the fixture scripts one response because one is
        // correct — so this control opts out of the no-unscripted-request assertion. It is the
        // only way the extra request can be asserted as the divergence rather than killing the run.
        $nopending = $this->replay('fx-gap.in_flight', $this->plantedClient(FleetClientPlants::NOPENDING[0]), [], true);

        $this->assertCount(2, $this->seatRequests($nopending),
            'P14 did not bite: buffering during an in-flight fetch was removed and the client still '
            .'issued one request');
        $this->assertSame(48222, $nopending['final']['seats']['aimla/aimla-pm']['state_version']);

        // P15 — B2(d): the drain merges without the version comparison, so the gap is never seen.
        $nowm = $this->replay('fx-gap.connect_window', $this->plantedClient(FleetClientPlants::NOWM[0]));

        $this->assertSame([], $this->seatRequests($nowm),
            'P15 did not bite: the drain’s version comparison was removed and the client still '
            .'resynced');
        $this->assertSame(73.2, $nowm['final']['seats']['aimla/aimla-pm']['context']['used_pct']);
    }
}

<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md` **AT-D3-9's protocol half, and its mid-session leg** — the client opens
 * the stream BEFORE it reads the snapshot, holds every delta that lands in the window, drains them
 * against the snapshot's own versions, and discovers an install it has never heard of with ONE
 * fetch. Appendix B row 3's gate; fixtures `fx-snapshot-4` and `fx-membership`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE WINDOW IS SCRIPTED, AND THAT IS NOT A WEAKENING. Against a live D2 the stream delivers a
 * message no sooner than one visibility lag after its row is inserted, so the 500 ms race would
 * need a snapshot response slower than that to be reachable at all. The harness schedules
 * deliveries and response times on its own clock; the client cannot tell a scripted 500 ms from a
 * real 2.5 s, and the ordering it is being tested on is the same ordering either way.
 *
 * ⛔ THREE DELTAS, THREE SIDES OF THE WATERMARK. `watermark` delivers one BELOW the snapshot's
 * version, one AT it, and one ABOVE, because "discard what the snapshot already contains" has two
 * boundaries and a client can get either wrong on its own. The `lt` and `eqonly` plants are each
 * red on exactly one of them.
 *
 * ⛔ THE DISCOVERY FETCH IS ADMISSION, AND ONE FETCH IS ENOUGH. Every row it carries is applied
 * under the same version rule every other row takes, so a snapshot row, a seat fetch's object and
 * a live delta for one seat are ordered by one monotonic integer whichever lands first — which is
 * what makes the second, scoped read this design used to carry unnecessary. `leg_c`'s discovery
 * deliberately carries a STALE `aimla-pm`, and the guard is what keeps it out.
 *
 * ⚠ WHAT IS NOT HERE: AT-D3-9's Third RED (the animating snapshot) and its render GREEN, both of
 * which read the floor renderer built at Appendix B step 6.
 */
class TheClientProtocolOpensBeforeItReadsTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /** G2 — every in-window delta is held, and the watermark decides which of them survives. */
    public function test_the_connect_window_is_buffered_and_drained_against_the_snapshot(): void
    {
        $fixture = $this->fixture('watermark');
        $result = $this->replay('watermark');

        $this->assertSame($fixture['final'], $this->finalSeats($result),
            'the drained connect buffer did not end on the stated map');

        // The observable for "the at- and below-watermark deltas were DISCARDED" is that no resync
        // was needed: a client that took either as a gap would have fetched.
        $this->assertSame([], $this->seatRequests($result),
            'a delta the snapshot already contained was taken as a gap');
    }

    /** G2 — exactly one `mezzanine` listener, and no `onmessage`. */
    public function test_exactly_one_mezzanine_listener_is_registered(): void
    {
        $this->assertSame([['mezzanine' => 1]], $this->replay('watermark')['listeners'],
            'the stream is not registered as § 2.2 and D2 § 8.4 require');
    }

    /** G3 — the Second RED's own run: a below-watermark clear, and nothing newer in the window. */
    public function test_a_below_watermark_clear_never_reaches_the_map(): void
    {
        $fixture = $this->fixture('no_watermark');
        $result = $this->replay('no_watermark');

        $this->assertSame($fixture['final'], $this->finalSeats($result),
            'a delta below the snapshot’s own version was merged into it');
        $this->assertNotNull($this->finalSeats($result)['aimla/aimla-pm']['action'],
            'the snapshot’s `action` was cleared by a delta older than the snapshot');
    }

    /** C4 — T40: a heartbeat OLDER than the held `fleet{}` is dropped, so no check runs. */
    public function test_a_stale_heartbeats_fleet_object_is_not_admitted(): void
    {
        $this->assertCount(1, $this->snapshotRequests($this->replay('stale_heartbeat')),
            'an out-of-order heartbeat walked `seats_total` backwards and minted a disagreement');
    }

    /**
     * G4 — the mid-session leg: an install the client has never held is discovered with ONE fetch,
     * a delta that arrives while that fetch is in flight takes its own insert fetch, and the
     * version rule orders the two answers.
     */
    public function test_a_disagreement_discovers_the_install_with_one_fetch(): void
    {
        $fixture = $this->fixture('leg_c');
        $result = $this->replay('leg_c');

        $this->assertSame($fixture['final'], $this->finalSeats($result),
            'the discovered install is not held as the run states it');

        $this->assertCount(2, $this->snapshotRequests($result),
            'the discovery is not ONE fetch: the connect read plus the discovery is two, and any '
            .'more is the scoped re-read this design removed');

        $this->assertSame(['/api/fleet/seats/aimla-win/win-1'], $this->seatRequests($result),
            'the delta for the unheld seat did not take its own insert fetch');

        // THE BUFFER HELD IT AND THE DRAIN APPLIED IT — asserted in the MIDDLE of the run, not only
        // at its end. At 5500 ms the discovery has just inserted win-1 at 7001 and the delta that
        // will carry it to 7002 is still buffered behind its own in-flight fetch.
        $this->assertSame(7001, $this->recordAt($result, 5500)['seats']['aimla-win/win-1']['state_version'],
            'win-1 was not at the discovery’s own version while its fetch was still in flight');
        $this->assertSame(7002, $this->finalSeats($result)['aimla-win/win-1']['state_version']);
        $this->assertSame(41.3, $this->finalSeats($result)['aimla-win/win-1']['context']['used_pct']);

        // The discovery's `aimla-pm` row is STALE (48219) and the stream has already advanced the
        // seat to 48220. The version rule is what keeps it out.
        $this->assertSame(48220, $this->finalSeats($result)['aimla/aimla-pm']['state_version'],
            'a stale discovery row lowered a version the stream had already advanced');

        $this->assertSame($fixture['final_clock_offset_ms'], $result['final']['clock_offset_ms']);
    }

    /** H7 — a failed discovery spends no budget, and the next heartbeat retries it. */
    public function test_a_failed_discovery_is_retried_on_the_next_heartbeat(): void
    {
        foreach (['discovery_fails', 'discovery_non_object'] as $run) {
            $fixture = $this->fixture($run);
            $result = $this->replay($run);

            $this->assertSame($fixture['final'], $this->finalSeats($result), "[{$run}] final map");
            $this->assertCount(3, $this->snapshotRequests($result),
                "[{$run}] the refused discovery spent its (N, M) pair, so the disagreement never got "
                .'its one check');
        }
    }

    /**
     * H7's second half — a `200` whose body is NOT an object is a failure, not a success. Read as
     * a success it throws inside the client's own continuation, and a discovery that throws never
     * clears its in-flight pair: no discovery would run again on that connection.
     */
    public function test_a_200_carrying_a_non_object_body_is_a_failure(): void
    {
        $this->assertSame([], $this->replay('discovery_non_object')['rejections'],
            'a maintenance page answered 200 reached the row walk and threw');
    }

    /** H3 — an older discovery row against a newer live delta: the guard keeps the newer one. */
    public function test_an_older_discovery_row_does_not_overwrite_a_newer_live_delta(): void
    {
        $fixture = $this->fixture('discovery_overwrite');
        $result = $this->replay('discovery_overwrite');

        $this->assertSame($fixture['final'], $this->finalSeats($result));
        $this->assertSame(7002, $this->finalSeats($result)['aimla-win/win-1']['state_version'],
            'the discovery’s older row replaced the version the stream had already advanced');
        $this->assertCount(2, $this->snapshotRequests($result));
        $this->assertCount(1, $this->seatRequests($result));
    }

    /** H8 — one discovery at a time, and the check re-runs once when it ends. */
    public function test_one_discovery_runs_at_a_time_and_the_check_re_runs_when_it_ends(): void
    {
        $fixture = $this->fixture('discovery_overlap');
        $result = $this->replay('discovery_overlap');

        $this->assertSame($fixture['final'], $this->finalSeats($result));

        // At 5500 ms a second, newer `fleet{}` is admitted while the first discovery is still in
        // flight. It must spend nothing and issue nothing: two requests so far, not three.
        $this->assertCount(2, array_filter($this->recordAt($result, 5500)['requests'],
            static fn (string $p): bool => $p === '/api/fleet/snapshot'),
            'a second discovery was issued while one was already in flight');

        // And when the first ends without resolving the newer disagreement, the check runs once
        // more against what the client then holds.
        $this->assertCount(3, $this->snapshotRequests($result),
            'the disagreement the finished discovery did not resolve was never re-examined');
    }

    /**
     * H12 / H13 — the four interleavings of a per-seat fetch FAILING near a discovery. Each is a
     * delta the discovery has just made applicable, or is about to; each is lost if a failed fetch
     * simply throws its buffer away.
     */
    public function test_a_seat_fetch_failing_near_a_discovery_keeps_the_deltas_the_discovery_makes_applicable(): void
    {
        foreach (['x1', 'x2', 'x3', 'x4'] as $run) {
            $this->assertSame($this->fixture($run)['final'], $this->finalSeats($this->replay($run)),
                "[{$run}] a delta the discovery made applicable was discarded by the failed fetch");
        }

        // X1's whole point is that the FAILURE costs nothing observable: its requests and its map
        // are `leg_c`'s exactly.
        $this->assertSame(
            $this->seatRequests($this->replay('leg_c')),
            $this->seatRequests($this->replay('x1')),
            'x1 did not end on leg_c’s own request list',
        );
    }

    /** Every planted client for this test, each diverging on the field its row names. */
    public function test_the_planted_clients_each_diverge_on_the_field_their_row_names(): void
    {
        // P1 — the order: fetch first, open the stream in the continuation. Every in-window delta
        // is dropped by a stream that did not exist when they were delivered.
        $order = $this->replay('watermark', $this->plantedClientWith(FleetClientPlants::ORDER));
        $pm = $this->finalSeats($order)['aimla/aimla-pm'];

        $this->assertSame(48219, $pm['state_version'],
            'P1 did not bite: the stream was opened after the fetch and the window’s deltas still '
            .'arrived');
        $this->assertSame('working', $pm['render_state']);

        // P2 — the delta AT the watermark is taken as a gap. ⚠ THE RESYNC IS UNSCRIPTED BY
        // CONSTRUCTION: `watermark` scripts no seat response because the correct client issues no
        // seat request, and the request itself IS this row's divergence — so this control opts out
        // of the no-unscripted-request assertion rather than dying on it for the right reason at
        // the wrong surface. The same holds for P2b below.
        $lt = $this->replay('watermark', $this->plantedClient(FleetClientPlants::LT[0]), [], true);

        $this->assertSame(['/api/fleet/seats/aimla/aimla-pm?resync_from=48219'], $this->seatRequests($lt),
            'P2 did not bite: the boundary was moved and the at-watermark delta was still discarded');

        // P2b — the delta BELOW the watermark is taken as a gap.
        $eqonly = $this->replay('watermark', $this->plantedClient(FleetClientPlants::EQONLY[0]), [], true);

        $this->assertSame(['/api/fleet/seats/aimla/aimla-pm?resync_from=48219'], $this->seatRequests($eqonly),
            'P2b did not bite: only equality was discarded and the below-watermark delta still did '
            .'not resync');

        // P3 — the drain merges without the comparison: the below-watermark clear lands.
        $nowm = $this->replay('no_watermark', $this->plantedClient(FleetClientPlants::NOWM[0]));

        $this->assertNull($this->finalSeats($nowm)['aimla/aimla-pm']['action'],
            'P3 did not bite: the comparison was removed and the stale clear still did not land');
        $this->assertSame(48218, $this->finalSeats($nowm)['aimla/aimla-pm']['state_version']);

        // P3′ — THE SAME PLANT CANNOT FAIL ON `watermark`, which is why `no_watermark` exists. Every
        // buffered delta there is above the snapshot's version, so merging without the comparison
        // reaches the same map. Recorded DID NOT RED, and asserted as such: a row claiming a RED it
        // cannot produce is how a register stops describing the code.
        $this->assertSame(
            $this->fixture('watermark')['final'],
            $this->finalSeats($this->replay('watermark', $this->plantedClient(FleetClientPlants::NOWM[0]))),
            'P3′ is recorded DID NOT RED on `watermark` — if it now reds there, `no_watermark` has '
            .'stopped being the run that discriminates this defect and the register is wrong',
        );

        // P12 — T40 removed: the stale heartbeat's `fleet{}` is admitted and mints a disagreement.
        $not40 = $this->replay('stale_heartbeat', $this->plantedClient(FleetClientPlants::NOT40[0]), [], true);

        $this->assertCount(2, $this->snapshotRequests($not40),
            'P12 did not bite: the ordering filter was removed and the stale heartbeat still issued '
            .'nothing');

        // P9 — a delta for an unheld seat is dropped while a discovery is in flight.
        $dropunheld = $this->replay('leg_c', $this->plantedClient(FleetClientPlants::DROPUNHELD[0]));

        $this->assertSame(7001, $this->finalSeats($dropunheld)['aimla-win/win-1']['state_version'],
            'P9 did not bite: the in-window delta was dropped and the seat still reached 7002');
        $this->assertSame([], $this->seatRequests($dropunheld));

        // P13 — the buffer is deleted rather than released.
        $nodrain = $this->replay('leg_c', $this->plantedClient(FleetClientPlants::NODRAIN[0]));

        $this->assertSame(7001, $this->finalSeats($nodrain)['aimla-win/win-1']['state_version'],
            'P13 did not bite on leg_c');

        // P10 — a snapshot row replaces unconditionally, so the discovery's stale row wins.
        $stale = $this->replay('leg_c', $this->plantedClient(FleetClientPlants::STALE[0]));

        $this->assertSame(48219, $this->finalSeats($stale)['aimla/aimla-pm']['state_version'],
            'P10 did not bite: the version guard was removed and the stale row still lost');

        // P11 — the trigger never fires, so the install is never discovered.
        $notrigger = $this->replay('leg_c', $this->plantedClient(FleetClientPlants::NOTRIGGER[0]));

        $this->assertCount(1, $this->snapshotRequests($notrigger),
            'P11 did not bite: the discovery was removed and a second snapshot was still fetched');
        $this->assertArrayNotHasKey('aimla-win/win-2', $this->finalSeats($notrigger));

        // P6b — G4's per-record assertion: patching into an empty object makes win-1 reach 7002 at
        // 5500 ms, where the correct client has the discovery's 7001 and a still-buffered delta.
        $empty = $this->replay('leg_c', $this->plantedClient(FleetClientPlants::EMPTY[0]));

        $this->assertSame(7002, $this->recordAt($empty, 5500)['seats']['aimla-win/win-1']['state_version'],
            'P6b did not bite: the mid-run record is identical with the fetch removed, so the '
            .'per-record assertion is not measuring the buffer');

        // P22 — a failed discovery keeps the pair spent.
        $spend = $this->replay('discovery_fails', $this->plantedClient(FleetClientPlants::SPEND[0]));

        $this->assertCount(2, $this->snapshotRequests($spend),
            'P22 did not bite: the refund was removed and the disagreement was still re-checked');
        $this->assertArrayNotHasKey('aimla-win/win-1', $this->finalSeats($spend));

        // P23 — the failure rule reads `ok` alone. ⚠ The defect IS an unhandled rejection.
        $okonly = $this->replay('discovery_non_object', $this->plantedClient(FleetClientPlants::OKONLY[0]), [], false, true);

        $this->assertNotSame([], $okonly['rejections'],
            'P23 did not bite: the body check was removed and the non-object body still did not throw');
        $this->assertCount(2, $this->snapshotRequests($okonly),
            'P23 did not bite on the request count: the throw did not strand the in-flight pair');

        // P24 — a second discovery is issued while one is in flight.
        $overlap = $this->replay('discovery_overlap', $this->plantedClient(FleetClientPlants::OVERLAP[0]), [], true);

        $this->assertCount(3, array_filter($this->recordAt($overlap, 5500)['requests'],
            static fn (string $p): bool => $p === '/api/fleet/snapshot'),
            'P24 did not bite: the one-at-a-time guard was removed and no extra fetch was issued');

        // P25 — the check never re-runs when a discovery ends.
        $norecheck = $this->replay('discovery_overlap', $this->plantedClient(FleetClientPlants::NORECHECK[0]));

        $this->assertCount(2, $this->snapshotRequests($norecheck),
            'P25 did not bite: the re-check was removed and the third discovery still ran');
        $this->assertArrayNotHasKey('aimla-win/win-2', $this->finalSeats($norecheck));

        // P26 / P26b — the discovery's rows bypass the guard, two ways: at the discovery's own
        // apply, and at the one shared guard every row takes.
        foreach ([FleetClientPlants::DISCOVERYUNGUARDED[0], FleetClientPlants::STALE[0]] as $plant) {
            $unguarded = $this->replay('discovery_overwrite', $this->plantedClient($plant));

            $this->assertSame(7001, $this->finalSeats($unguarded)['aimla-win/win-1']['state_version'],
                'P26/P26b did not bite: the guard was removed and the older discovery row still lost');
            $this->assertSame(40.2, $this->finalSeats($unguarded)['aimla-win/win-1']['context']['used_pct']);
        }

        // P18 — the wrong listener name: the client hears nothing and looks healthy doing it.
        $listener = $this->replay('watermark', $this->plantedClient(FleetClientPlants::LISTENER[0]));

        $this->assertSame([['message' => 1]], $listener['listeners'],
            'P18 did not bite: the listener name was changed and the registration did not move');

        // P27–P30 — the pre-fix failure branch, on each of the four interleavings.
        foreach ([['x1', 'aimla-win/win-1', 7001], ['x2', 'aimla-win/win-1', 7001],
            ['x3', 'aimla/aimla-pm', 48220], ['x4', 'aimla-win/win-1', 7001]] as [$run, $key, $planted]) {
            $s7 = $this->replay($run, $this->plantedClient(FleetClientPlants::S7DISCARD[0]));

            $this->assertSame($planted, $this->finalSeats($s7)[$key]['state_version'],
                "P27–P30 did not bite on {$run}: the unconditional discard was restored and the "
                .'delta the discovery made applicable still reached the map');
        }
    }
}

<?php

namespace Tests\Feature\Feed;

use App\Fold\Badges;
use App\Read\FleetHealth;

/**
 * **AT-D2-21's PRIMARY RED, which card #7712 shipped UNDRIVEN because it is a wire-surface
 * assertion** (`docs/design/FLEET-STATE.md § 11`, § 2.3, § 2.2, § 8.2.4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 11's RED, verbatim: "**omit `fold_lag_ms` from the seat object** → the floor renders hours-old
 * states as current WITH FRESH RECEIPT AGES BESIDE THEM, and every instrument on the page agrees
 * that everything is fine. This is § 3's defect arriving through the derivation plane, and IT IS
 * THE ONE DEGRADATION IN THIS DESIGN THAT IS INVISIBLE WITHOUT A DELIBERATE INSTRUMENT."
 *
 * Card #7712 drove three of AT-D2-21's four REDs and said so plainly: the store-side ones. The
 * primary one is a claim about the PUBLISHED OBJECT, so it needed a published object to omit the
 * field from. This file is that, and with it AT-D2-21 is complete.
 *
 * ⚠ WHAT THIS FILE DOES NOT RE-TEST. `Tests\Feature\Fold\At21FrozenFoldTest` owns the badge, the
 * episode counter, the never-folded seat, the unseeded-cursor raise, the stored-lag RED and the
 * wrong-operand RED — all store-side, all driven there. Re-asserting them here would be a second
 * copy of a test, which is a second thing to keep true. What is here is the half that file
 * explicitly scoped out.
 */
class At21WireSurfaceTest extends FeedTestCase
{
    /**
     * ⛔ THE WHOLE DEFECT IN ONE ASSERTION PAIR: a FRESH receipt beside a RISING derivation lag.
     *
     * § 2.3: "If the fold stops while the ingest keeps working, `last_receipt_at` KEEPS MOVING and
     * the desk keeps showing whatever it was doing when derivation stopped. That is a floor that
     * LOOKS ALIVE AND IS LYING."
     */
    public function test_a_frozen_fold_publishes_a_rising_lag_beside_a_fresh_receipt(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame(0, $this->publishedLag($this->snapshotSeat()),
            'a caught-up seat must publish 0, or a rising number proves nothing');

        // ⛔ THE FIXTURE IS THE DEFECT, SO IT HAS TO HAVE BOTH HALVES. The ingest keeps working
        // and the fold does not run (§ 2.2's "Fold worker / dead or lagging" row): a batch lands,
        // 400 s pass with no fold pass, and THEN A SECOND BATCH LANDS. The cursor's basis is
        // still the first batch's receipt — so the lag is 400 s — while `last_receipt_at` is the
        // second batch's, seconds old. A fixture that merely advanced the clock after one batch
        // would age the receipt too and would prove only that a SILENT seat looks silent.
        $this->deliver($this->heartbeats(1));
        $this->advanceServerClock(400);
        $this->deliver($this->heartbeats(1));
        $this->sweep();

        $object = $this->snapshotSeat();

        // 1 — THE LAG IS PUBLISHED, IS A NUMBER, AND IS RISING.
        $this->assertGreaterThan(Badges::FOLD_LAG_MS, $this->publishedLag($object));

        // 2 — AND THE RECEIPT BESIDE IT IS FRESH. Without field 1 this object says a healthy desk.
        $this->assertLessThan(
            60_000,
            $this->wireToMs($object['server_time']) - $this->wireToMs($object['delivery']['last_receipt_at']),
            'the fixture did not reproduce the fresh-receipt half of the defect',
        );

        // 3 — "every seat object says how stale its derivation is": the badge rides it too.
        $this->assertContains('fold_lag', $object['badges']);
    }

    /**
     * § 2.2's fold row is OPEN, LABELLED — "serve the state, with `derivation.fold_lag_ms` per seat
     * and `fleet.fold` ≠ `ok`", because "refusing the whole fleet because one seat's derivation is
     * behind would turn a partial degradation into a total outage".
     *
     * § 11's GREEN: "past 300 s `fleet.fold` reports `stalled` … the REST snapshot STILL SERVES".
     */
    public function test_the_snapshot_still_serves_and_fleet_fold_reports_stalled(): void
    {
        [$otherToken] = $this->secondSeat();

        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->deliver($this->heartbeats(1));
        $this->advanceServerClock(FleetHealth::FOLD_STALLED_MS / 1000 + 1);

        $body = $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();

        $this->assertSame('stalled', $body['fleet']['fold']);
        $this->assertGreaterThan(FleetHealth::FOLD_STALLED_MS, $body['fleet']['max_fold_lag_ms']);

        // OPEN: the fleet is still served, and BOTH seats are in it. A partial degradation must
        // not become a total outage, and it must not become a partial ANSWER either.
        $this->assertCount(2, $body['installs'][0]['seats']);
    }

    /**
     * The three thresholds of `fleet.fold`, each seen — because a field that only ever reads `ok`
     * is a decoration, and one that only ever reads `stalled` is an alarm nobody will keep.
     */
    public function test_fleet_fold_reaches_all_three_of_its_values(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame('ok', $this->health()['fold']);

        $this->deliver($this->heartbeats(1));

        $this->advanceServerClock(FleetHealth::FOLD_LAGGING_MS / 1000 + 1);
        $this->assertSame('lagging', $this->health()['fold']);

        $this->advanceServerClock(FleetHealth::FOLD_STALLED_MS / 1000);
        $this->assertSame('stalled', $this->health()['fold']);

        // …and it comes BACK. A one-way instrument would pass every assertion above.
        $this->fold();
        $this->assertSame('ok', $this->health()['fold']);
    }

    /**
     * § 8.2.4's `max_fold_lag_ms` population, which the section argues in terms: it is "the
     * maximum over **the same population `seats_total` counts** — every seat not retired more than
     * 14 days ago, NOT ONLY THE LIVE ONES … a `stale` seat 117 s behind would set `fleet.fold` to
     * `lagging` while `max_fold_lag_ms` read `0`."
     *
     * Driven with exactly that seat: one that has gone silent AND is behind.
     */
    public function test_the_aggregate_counts_a_stale_seat_and_not_only_the_live_ones(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        // The seat goes quiet — past § 4.5's 300 s — with unfolded events behind it.
        $this->deliver($this->heartbeats(1));
        $this->advanceServerClock(400);
        $this->sweep();

        $this->assertSame('stale', $this->state()->link_state, 'the fixture did not produce a stale seat');

        $health = $this->health();

        $this->assertSame(0, $health['seats_live'], 'the control failed: a live seat is carrying this');
        $this->assertSame(1, $health['seats_total']);
        $this->assertGreaterThan(FleetHealth::FOLD_LAGGING_MS, $health['max_fold_lag_ms'],
            'the aggregate read 0 over a seat that is behind — the two-population defect');

        // `stalled` and not `lagging`, and the reason is worth stating rather than tuning around:
        // § 4.5 needs 300 s OF SILENCE to reach `stale`, and § 2.3 measures the fold lag from the
        // same clock — so a seat that is stale AND behind is necessarily past 300 s on both, and
        // `stalled` is the only value `fleet.fold` can honestly take here. The three-value sweep
        // is the test above; what THIS one is about is the POPULATION, and the population defect
        // it guards would read `max_fold_lag_ms: 0` with `fleet.fold` non-`ok` — two fields of
        // one object disagreeing, which is exactly what § 8.2.4 names.
        $this->assertSame('stalled', $health['fold']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Read `derivation.fold_lag_ms` off a published object, FAILING WITH § 11's OWN SENTENCE if it
     * is not there or is not a number.
     *
     * Every read of the field goes through here rather than through `$object['derivation'][…]`,
     * because AT-D2-21's RED is a DESIGN defect and a test whose failure message is
     * `Undefined array key` does not name it. The two shapes it catches are the two shapes the
     * defect actually takes: the member absent, and the member present but `null` — and § 2.3 says
     * why the second is not milder, "a `null` here reads to a client as *no lag reported* and to a
     * chart as zero".
     */
    private function publishedLag(array $object): int
    {
        $this->assertArrayHasKey('fold_lag_ms', $object['derivation'],
            '§ 11 AT-D2-21 RED: the seat object omits `fold_lag_ms` — the floor now renders '
            .'hours-old states as current with fresh receipt ages beside them, and every '
            .'instrument on the page agrees that everything is fine');

        $this->assertNotNull($object['derivation']['fold_lag_ms'],
            '§ 2.3: a null `fold_lag_ms` reads to a client as "no lag reported" and to a chart as zero');

        $this->assertIsInt($object['derivation']['fold_lag_ms']);

        return $object['derivation']['fold_lag_ms'];
    }

    /** @return array<string, mixed> */
    private function snapshotSeat(): array
    {
        $body = $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();

        return $body['installs'][0]['seats'][0] + ['server_time' => $body['server_time']];
    }

    /** @return array<string, mixed> § 8.2.4's object, as `GET /api/fleet/health` serves it */
    private function health(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/health')->assertOk()->json('fleet');
    }

    private function wireToMs(string $wire): int
    {
        return (int) round((float) \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z', $wire, new \DateTimeZone('UTC')
        )->format('U.u') * 1000);
    }
}

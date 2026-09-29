<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-6 — the feed dying is visible within 45 s, the PANEL half.** `docs/design/FLOOR.md § 11`,
 * gated at Appendix B **step 10** (card#7342; the floor half is step 8's,
 * `TheFeedDyingIsVisibleWithinFortyFiveSecondsTest`). "The same run **with the drill-down open on
 * `aimla-pm`** … the drill-down's `fetch-fresh` blocks are **re-stamped** by each poll rather than
 * ticked: a transport block whose numbers moved between two polls would be rendering a value nothing
 * delivered, which is the same defect as a frozen age wearing the opposite face." **Reads:** the
 * harness, the drill-down, the stream recovery.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ TWO CLAUSES, BOTH READ OFF THE RENDERED BLOCKS. (1) Nothing moves without a delivery: between any
 * two frames, a `fetch-fresh` block whose values changed has a changed *as of* stamp. (2) Each poll IS a
 * delivery: after every poll of the silence, the three blocks carry that poll's own `server_time`. The
 * polls answer `aimla-pm` at the version the client already holds, which the protocol's map drops under
 * § 2.2's version rule — so (2) is exactly the reading a client that stamped only from its held map
 * would fail.
 *
 * ⛔ AND THE STAMP DATES THE NUMBERS: the receipt age is the stamp minus `delivery.last_receipt_at`,
 * § 2.4's format over two SERVER instants, and it is asserted as that — never as a function of the
 * scenario's clock.
 */
class TheDrillDownIsRestampedByEachPollTest extends TestCase
{
    use DrivesTheDrillDown;

    private const DIES = 'panel_feed_dies';

    private const LIVES = 'panel_feed_lives';

    /** RED — the ticked block: the receipt age measured at the ticking clock, not at the stamp. */
    private const TICKED = ['../drilldown/drilldown-model.js',
        'receipt_age: receipt === null ? NO_DATA_YET : receiptAgeAt(receipt, at),',
        'receipt_age: receipt === null ? NO_DATA_YET : receiptAgeAt(receipt, nowMs),'];

    /** Second RED — the poll that delivers nothing: a same-version row's blocks are not taken. */
    private const UNSTAMPED = ['../drilldown/drilldown-panel.js',
        "if ((entry.t === 'snapshot' || entry.t === 'seat.fetch') && entry.row !== undefined) {",
        "if ((entry.t === 'snapshot' || entry.t === 'seat.fetch') && entry.row !== undefined && entry.outcome === 'applied') {"];

    public function test_green_each_poll_re_stamps_the_blocks_and_nothing_moves_between_them(): void
    {
        $this->assertSame([], $this->defects(self::DIES));
    }

    /** The control: a feed that never dies polls nothing, so the blocks keep the detail response's stamp. */
    public function test_control_with_the_feed_alive_the_stamp_never_moves_after_the_open(): void
    {
        $this->assertSame([], $this->defects(self::LIVES));

        $stamps = array_unique(array_map(static fn (array $f): string => (string) $f[1]['transport']['as_of'],
            $this->answeredFrames($this->floorRun(self::LIVES))));

        $this->assertCount(1, $stamps, 'the blocks were re-stamped on a run that issued no poll: '.json_encode($stamps));
    }

    public function test_red_the_ticked_block_is_caught(): void
    {
        $defects = $this->defects(self::DIES, $this->mutatedModules(self::TICKED));

        $this->assertArrayHasKey('moved', $defects, 'the ticked-block RED did not bite: '.json_encode($defects));
    }

    public function test_red_the_poll_that_delivers_nothing_is_caught(): void
    {
        $defects = $this->defects(self::DIES, $this->mutatedModules(self::UNSTAMPED));

        $this->assertArrayHasKey('restamp', $defects, 'the unstamped-poll RED did not bite: '.json_encode($defects));
    }

    /** @return array<string, string> */
    private function defects(string $run, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $fixture = $this->fixture($run);
        $frames = $this->answeredFrames($result);
        $defects = [];

        $this->assertGreaterThan(3, count($frames), "[{$run}] the drill-down drew almost no frame — the clauses read nothing");

        // ── (1) Nothing moves without a delivery. ──
        $blocks = [
            'transport' => ['receipt_age', 'heartbeat', 'spool_lag_events', 'oldest_unsent', 'last_seq', 'no_data_since'],
            'reporter' => ['uptime', 'version', 'platform'],
            'derivation' => ['lag_line', 'computed_at', 'cursor_event_id'],
        ];
        $prev = null;

        foreach ($frames as [$at, $panel]) {
            if ($prev !== null) {
                foreach ($blocks as $block => $members) {
                    $values = array_intersect_key($panel[$block], array_flip($members));
                    $before = array_intersect_key($prev[$block], array_flip($members));

                    if ($values !== $before && $panel[$block]['as_of'] === $prev[$block]['as_of']) {
                        $defects['moved'] ??= "at {$at} the {$block} block's values moved under an unchanged stamp ({$panel[$block]['as_of']}): "
                            .json_encode([$before, $values]);
                    }
                }
            }

            $prev = $panel;

            // The stamp dates the numbers: the receipt age is the stamp minus the receipt, both server clocks.
            $want = $this->receiptAgeAt($panel['transport']['as_of'], $fixture, $result);

            if ($panel['transport']['receipt_age'] !== $want) {
                $defects['moved'] ??= "at {$at} the receipt age reads `{$panel['transport']['receipt_age']}`, not `{$want}` — the age at its own stamp";
            }
        }

        // ── (2) Each poll is a delivery. ──
        $base = $this->ms($fixture['http']['/api/fleet/snapshot'][0]['body']['server_time']);

        $polls = $this->pollTimes($result);

        foreach ($polls as $i => $poll) {
            $want = 'as of '.gmdate('H:i:s', intdiv($base + $poll, 1000));
            $until = $polls[$i + 1] ?? PHP_INT_MAX;
            // The LAST frame before the next poll: the poll's own timer draws a frame before its
            // response settles, and the frame after the response is the one the delivery shows in.
            $window = array_values(array_filter($frames, static fn (array $f): bool => $f[0] >= $poll && $f[0] < $until));
            $next = $window === [] ? null : $window[count($window) - 1];

            if ($next === null) {
                continue;
            }

            foreach (array_keys($blocks) as $block) {
                if ($next[1][$block]['as_of'] !== $want) {
                    $defects['restamp'] ??= "the poll at {$poll} did not re-stamp the {$block} block: it reads `{$next[1][$block]['as_of']}`, not `{$want}`";
                }
            }
        }

        return $defects;
    }

    /**
     * The panel frames after its detail response answered — before that the blocks carry the stamp of the
     * snapshot the floor was drawn from, which is also correct and is not what this test is about.
     *
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    private function answeredFrames(array $result): array
    {
        return array_values(array_filter($this->panelFrames($result), static fn (array $f): bool => $f[1]['loading'] === false));
    }

    /** Every snapshot request after the connect's — the polls — by the instant it was issued. */
    private function pollTimes(array $result): array
    {
        $times = [];
        $seen = 0;

        foreach ($result['records'] as $record) {
            $count = count(array_filter($record['requests'], static fn (string $p): bool => $p === '/api/fleet/snapshot'));

            for (; $seen < $count; $seen++) {
                $times[] = $record['at'];
            }
        }

        return array_slice($times, 1);
    }

    /**
     * § 2.4's receipt age at a block's own stamp: `no data for` the stamp minus the fixture's receipt,
     * over the EXACT instant the stamp names. Every delivery in these runs is `clocked`, so its
     * `server_time` is the snapshot's own plus the scenario instant it settled at; the rendered stamp is
     * that instant to the second, and every settle instant that renders to one second must give one
     * age, or the stamp would not determine the age at all.
     */
    private function receiptAgeAt(string $asOf, array $fixture, array $result): string
    {
        $base = $this->ms($fixture['http']['/api/fleet/snapshot'][0]['body']['server_time']);
        $receipt = null;

        foreach ($fixture['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                if ($seat['seat_id'] === 'aimla-pm') {
                    $receipt = $this->ms($seat['delivery']['last_receipt_at']);
                }
            }
        }

        $ages = [];

        foreach (array_unique(array_merge([0], array_column($result['records'], 'at'))) as $t) {
            $instant = $base + $t;

            if ('as of '.gmdate('H:i:s', intdiv($instant, 1000)) === $asOf) {
                $ages[] = intdiv($instant - $receipt, 1000);
            }
        }

        $ages = array_values(array_unique($ages));

        $this->assertCount(1, $ages, "the stamp `{$asOf}` names no delivery this run made, or names two that disagree");

        return $this->wording('receipt age', $this->formatDurations($ages)[0]);
    }
}

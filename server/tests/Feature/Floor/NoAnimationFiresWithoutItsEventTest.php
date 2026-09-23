<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-1 — no animation without its event.** `docs/design/FLOOR.md § 11`'s headline test and the
 * gate on trusting the floor at all, gated at Appendix B **step 6, unqualified — both halves**.
 * card#7341 step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE INSTRUMENT IS § 11's ANIMATION LOG, WRITTEN BY THE SHIPPED RENDERER. Every row below came
 * out of `wire/animation-log.js` after `desk/desk-floor.js` handed `wire/animation-set.js` the real
 * client's journal and its real frames — no row is constructed here, and nothing in this file knows
 * how to write one.
 *
 * ⛔ EVERY EXPECTED ROW IS RE-DERIVED FROM THE DOCUMENT, NOT LISTED HERE. Which § 6.2 row a seat
 * holds comes from that table's own hold conditions (`ReadsTheAnimationTable::heldRowFor()`); which
 * `changed[]` member gates an `edge` row comes from its Driving fact cell; and the `fx-clear-trace`
 * episode walk is parsed out of this test's own § 11 walk table. A list of expected rows typed here
 * would be a second copy of § 6.2 that could agree with a wrong implementation.
 *
 * ⛔ THE PAIRING IS OVER `episode_id`, NEVER OVER `(animation_id, install_id, seat_id)` (§ 11). A4 is
 * entered TWICE on `aimla-pm` in `fx-clear-trace`, so the triple names two entries and two exits with
 * nothing to say which ended which — and a predicate asserting the hold condition on BOTH phases
 * would fail a correct client on that same fixture, because A4's first episode ends at E1 where
 * `open_calls` is 1 and A4's condition is therefore false in exactly the object that ended it.
 */
class NoAnimationFiresWithoutItsEventTest extends TestCase
{
    use DrivesTheDeskFloor;
    use ReadsTheAnimationTable;

    /** The closed-set half's own Build bullet: these four, end to end, in this order. */
    private const CLOSED_SET_RUNS = ['ages', 'fx-clear-trace', 'degraded', 'fx-interns'];

    /**
     * ⛔ THE INSTRUMENT HALF, AND ITS GREEN IS A TWO-SIDED CONTROL. `fx-snapshot-4` alone, then
     * silence: without the *exactly four rows* half a log-writing bug that recorded nothing would
     * pass, and without the *no `edge` row* half a client that fired arrivals on the snapshot would.
     */
    public function test_a_snapshot_then_silence_logs_the_four_held_entries_section_62_predicts_and_no_more(): void
    {
        $result = $this->silentSnapshotRun();
        $rows = $result['animation_log'];

        $this->assertSame([], array_values(array_filter($rows, static fn (array $r): bool => $r['class'] === 'edge')),
            'a snapshot apply fired an `edge` row — § 6.5, and the honesty principle at its headline case');
        $this->assertSame([], array_values(array_filter($rows, static fn (array $r): bool => $r['phase'] === 'left')),
            'a `phase: left` row was written on a fixture where nothing ended, because nothing arrived');

        // The expectation is the DOCUMENT's: for each seat the snapshot delivers, the § 6.2 row its
        // own object holds, in the order the floor entered them.
        $expected = [];

        foreach ($this->snapshotSeats('instrument') as $key => $seat) {
            $expected[$key] = $this->heldRowFor($seat);
        }

        $this->assertNotContains(null, $expected,
            'a seat in `fx-snapshot-4` holds no § 6.2 row at all — the expectation below would be silently short');

        $actual = [];

        foreach ($rows as $row) {
            $this->assertSame('entered', $row['phase']);
            $actual["{$row['install_id']}/{$row['seat_id']}"] = $row['animation_id'];
        }

        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual,
            'the held renders the log carries are not the § 6.2 rows the delivered states predict');
        $this->assertCount(count($expected), $rows, 'the log carries a fifth row over four delivered states');

        // Each with that seat's own `state_version` as its cause, and each opening a DISTINCT episode.
        foreach ($rows as $row) {
            $key = "{$row['install_id']}/{$row['seat_id']}";

            $this->assertSame($this->snapshotSeats('instrument')[$key]['state_version'], $row['cause'],
                "[{$key}] the held entry's cause is not the `state_version` of the object holding it");
        }

        $episodes = array_column($rows, 'episode_id');

        $this->assertSame($episodes, array_unique($episodes),
            'two held entries share an `episode_id` — the field that pairs an exit with its entry');
    }

    /**
     * ⛔ THE CLOSED-SET HALF. Four fixtures end to end, one predicate per clause of § 11's GREEN.
     */
    public function test_every_row_the_four_fixtures_write_obeys_section_62(): void
    {
        $runs = $this->closedSetRuns();
        $table = $this->documentAnimationRows();
        $checked = ['edge' => 0, 'entered' => 0, 'left' => 0];

        foreach ($runs as $run => $result) {
            foreach ($result['animation_log'] as $row) {
                $this->assertArrayHasKey($row['animation_id'], $table,
                    "[{$run}] the log carries `{$row['animation_id']}`, which has no row in § 6.2 — an animation with no row is a defect");
                $this->assertSame($table[$row['animation_id']]['class'], $row['class'],
                    "[{$run}] {$row['animation_id']} was written through the other class's entry point");

                if ($row['class'] === 'edge') {
                    $this->assertEdgeRowNamesItsCausingMessage($run, $row, $result);
                    $checked['edge']++;

                    continue;
                }

                $checked[$row['phase']]++;
            }

            $this->assertHoldConditionsPointOppositeWays($run, $result);
            $this->assertEpisodesPairExactlyOnce($run, $result);
        }

        // CONTROLS ON THE WALK: a clause asserted over an empty population is a clause that passed
        // without being able to fail, and all three populations are non-empty on these four fixtures.
        $this->assertGreaterThan(0, $checked['edge'], 'no `edge` row was written across four fixtures — the edge clauses are vacuous');
        $this->assertGreaterThan(0, $checked['entered'], 'no held entry was written — the entry clause is vacuous');
        $this->assertGreaterThan(0, $checked['left'], 'no held exit was written — the exit clause and the pairing are vacuous');
    }

    /**
     * ⛔ THE `fx-clear-trace` EPISODE WALK, PARSED OUT OF § 11's OWN TABLE. That table is the
     * document's claim about this fixture, so the fixture is replayed against it rather than against
     * a transcription — and the two figures § 11 states over it, *five held episodes* and *nine held
     * rows*, are counted from what the shipped renderer actually wrote.
     */
    public function test_the_clear_trace_walks_the_episodes_section_11_predicts(): void
    {
        $result = $this->deskRun('fx-clear-trace');
        $walk = $this->documentEpisodeWalk();

        $this->assertNotSame([], $walk, '§ 11\'s `fx-clear-trace` walk table did not parse — the expectation below is unread');

        $rows = $this->heldRows($result, 'aimla/aimla-pm');
        $observed = [];

        foreach ($rows as $row) {
            $observed[] = [$row['animation_id'], $row['phase']];
        }

        $this->assertSame($walk, $observed,
            'the held episodes `aimla-pm` walked are not the ones § 11\'s own walk table predicts');

        // § 11's two figures, counted from the rows rather than read off the sentence.
        $entered = array_filter($rows, static fn (array $r): bool => $r['phase'] === 'entered');

        $this->assertSame(1, preg_match('/\*\*(\w+) `held` episodes, (\w+) `held` rows\*\*/', $this->floorMd(), $m),
            '§ 11\'s held-episode figures are not published in the form this check reads');

        // § 11 spells both figures as words and the sentence opens with a capital, so the lookup is
        // case-folded rather than duplicated for one of them.
        $words = ['four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11];
        $m[1] = strtolower($m[1]);
        $m[2] = strtolower($m[2]);

        $this->assertSame($words[$m[1]] ?? null, count($entered),
            '§ 11 states a different number of held EPISODES than `aimla-pm` opened');
        $this->assertSame($words[$m[2]] ?? null, count($rows),
            '§ 11 states a different number of held ROWS than `aimla-pm` wrote');

        // A9 is still held when the fixture ends, so it has no exit — *at most one* `left` row per
        // episode and not *exactly one*, which is the other half of the pairing rule.
        $open = array_values(array_diff(
            array_column($entered, 'episode_id'),
            array_column(array_filter($rows, static fn (array $r): bool => $r['phase'] === 'left'), 'episode_id'),
        ));

        $this->assertCount(1, $open, 'the trace ended with a number of unclosed episodes § 11 does not predict');
        $this->assertSame('A9', $rows[count($rows) - 1]['animation_id']);
    }

    /**
     * ⛔ RED — THE AMBIENT IDLE-BREATHING LOOP, started as a claim-bearing entry THROUGH the log, as
     * § 11's ruling and AT-D3-1's own RED bullet require: an `animation_id` outside § 6.2's table and
     * a `null` `cause`. A raw loop that never touches the log writes nothing here and is § 11's own
     * NOT MECHANIZED limit, not this RED's.
     *
     * It fails TWICE OVER, and both are asserted: the id has no § 6.2 row, and the cause is `null`
     * under either class's rule.
     */
    public function test_red_an_ambient_breathing_loop_started_through_the_log_fails_both_ways(): void
    {
        $breathing = $this->plantedSet(
            "        for (const [k, desk] of Object.entries(desks)) {\n            if (desk.held === null || this.#episodes.has(k)) {",
            "        for (const [k, desk] of Object.entries(desks)) {\n            this.#log.enterHeld({\n"
            ."                animation_id: 'breathe', cause: null, install_id: desk.install_id,\n"
            ."                seat_id: desk.seat_id, motion: true, at,\n            });\n\n"
            ."            if (desk.held === null || this.#episodes.has(k)) {",
        );

        $rows = $this->deskRun('ages', $breathing)['animation_log'];
        $table = $this->documentAnimationRows();
        $outside = array_values(array_filter($rows, static fn (array $r): bool => ! isset($table[$r['animation_id']])));

        $this->assertNotSame([], $outside,
            'the RED did not bite: an ambient loop was started through the log and every row still had a § 6.2 row');
        $this->assertNull($outside[0]['cause'],
            'the planted loop carried a cause, so it would not have failed the second way this test fails it');

        // And the GREEN's own predicate reds on it, which is the point of planting it at all.
        foreach ($rows as $row) {
            if (! isset($table[$row['animation_id']])) {
                $this->assertTrue(true, 'the closed-set clause has a row to reject');

                return;
            }
        }

        $this->fail('the closed-set clause had nothing to reject');
    }

    /**
     * ⛔ SECOND RED — THE LOOP RATE DRIVEN FROM `open_calls`, a *busier seats type faster* change that
     * looks like a feature. § 6.1 rule 2 fixes the rate for every claim-bearing loop on the floor and
     * every seat, so the assertion is that the frame interval is CONSTANT across every seat and every
     * fixture. A rate that varies is a quantity the wire never sent (§ 6.3's third forbidden form).
     */
    public function test_second_red_a_loop_rate_that_varies_with_open_calls_is_a_quantity_nothing_sent(): void
    {
        $intervals = $this->everyFrameInterval($this->closedSetRuns());

        $this->assertNotSame([], $intervals, 'no running loop was drawn across four fixtures — the rate assertion is vacuous');
        $this->assertSame([250], array_values(array_unique($intervals)),
            'a running loop was drawn at more than one frame interval — § 6.1 rule 2 fixes it for every loop and every seat');

        // The plant lives in the DESK RENDER, because that is the layer holding the seat and could
        // therefore reach for the quantity: a *busier seats type faster* edit divides the row's fixed
        // interval by this desk's own `open_calls`.
        $faster = $this->plantedDesk(
            'held: heldId === null ? null : heldRendering(heldId, permitted, options.reduce === true),',
            <<<'JS'
            held: heldId === null ? null : (() => {
                    const drawn = heldRendering(heldId, permitted, options.reduce === true);

                    return drawn.frame_interval_ms === null ? drawn : {
                        ...drawn,
                        frame_interval_ms: drawn.frame_interval_ms / Math.max(1, seat.open_calls ?? 1),
                    };
                })(),
            JS,
        );

        $planted = $this->everyFrameInterval($this->closedSetRuns($faster));

        $this->assertNotSame([], $planted, 'the RED drew no running loop at all, so it proves nothing about the rate');
        $this->assertGreaterThan(1, count(array_unique($planted)),
            'the RED did not bite: the loop rate was driven from `open_calls` and every seat still drew at one interval');
    }

    /**
     * Each `edge` row against § 11's `cause` column and § 6.2's Driving fact cell: a non-null cause
     * that is one of the four causing messages, and the driving field in the causing delta's
     * `changed[]`.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     */
    private function assertEdgeRowNamesItsCausingMessage(string $run, array $row, array $result): void
    {
        $this->assertNotNull($row['cause'],
            "[{$run}] {$row['animation_id']} fired with a `null` cause — an edge animation with no causing message");
        $this->assertSame('fired', $row['phase'], "[{$run}] an `edge` row carries a phase other than `fired`");

        // A row whose DRIVER is a message type carries that type as its cause (§ 11, AT-D3-1's own
        // parenthetical). Over these four fixtures the only such rows are the heartbeat's.
        if (is_string($row['cause'])) {
            $this->assertContains($row['cause'], ['feed.heartbeat', 'seat.retired'],
                "[{$run}] {$row['animation_id']}'s cause is a string that is none of § 11's causing message types");

            return;
        }

        $delta = $this->deltaAt($run, $row['install_id'], $row['seat_id'], $row['cause']);

        $this->assertNotNull($delta,
            "[{$run}] {$row['animation_id']}'s cause {$row['cause']} is no `seat.delta` this fixture delivered for that seat");

        $members = $this->documentDrivingMembers($row['animation_id']);

        $this->assertNotSame([], array_intersect($members, $delta['changed']),
            "[{$run}] {$row['animation_id']} fired on a delta whose `changed[]` carries none of § 6.2's driving members for it ("
            .implode(', ', $members).' against '.implode(', ', $delta['changed']).')');
    }

    /**
     * § 11's two phases asserted in OPPOSITE directions — which is what makes both satisfiable on a
     * correct client. In an `entered` row's object the fact its § 6.2 row names HAS the value its hold
     * condition states; in a `left` row's object it does NOT.
     *
     * ⛔ THE PREDICATE IS § 6.2's TABLE AS A TOTAL FUNCTION — *which row does THIS object hold* — and
     * not one row's equalities read alone. A3's condition is `render_state == "working"` **and not
     * A4's condition**, so on `fx-clear-trace` A3 is left at E0 against an object that is still
     * `working`: read one row at a time the exit looks illegitimate, and the client is correct. Asking
     * the table which row the object holds carries that exclusion without a second copy of it, and it
     * is what § 6.2 means by *the held rows this table predicts a single answer rather than two*.
     *
     * ⚠ THE ONE SHAPE THIS CLAUSE AS § 11 WRITES IT WOULD REJECT, named rather than left to be found:
     * a § 7.3 CURRENCY-TREATMENT exit, where a `fold_lag` badge arrives and stops a loop while the
     * § 6.2 condition stays true. The episode legitimately ends (its `motion` changed) and the object
     * still holds the same row. No fixture at this step delivers a badge mid-run — `fx-degraded`'s
     * lagged seat carries its badge in the snapshot and never transitions — so the strong form is what
     * runs here, and AT-D3-5 is where the treatment path is asserted.
     *
     * @param  array<string, mixed>  $result
     */
    private function assertHoldConditionsPointOppositeWays(string $run, array $result): void
    {
        $conditions = $this->documentHoldConditions();

        foreach ($result['animation_log'] as $row) {
            if ($row['class'] !== 'held' || ! isset($conditions[$row['animation_id']])) {
                continue;
            }

            $key = "{$row['install_id']}/{$row['seat_id']}";
            $object = $this->seatAtVersion($result, $key, $row['cause']);

            $this->assertNotNull($object,
                "[{$run}] {$row['animation_id']}'s {$row['phase']} row names `state_version` {$row['cause']}, which this client never held for {$key}");

            $holds = $this->heldRowFor($object);

            if ($row['phase'] === 'entered') {
                $this->assertSame($row['animation_id'], $holds,
                    "[{$run}] {$row['animation_id']} was entered against an object § 6.2 says holds "
                    .($holds ?? 'no row at all'));

                continue;
            }

            $this->assertNotSame($row['animation_id'], $holds,
                "[{$run}] {$row['animation_id']} was left against an object that still holds it — a render the client "
                .'stopped drawing while the wire still said to draw it');
            $this->assertFalse($row['motion'], "[{$run}] a `left` row claims motion, and nothing is drawn by a render that has been left");
        }
    }

    /**
     * § 11's pairing rule: an `episode_id` on at most one `entered` row and at most one `left` row, a
     * `left` row matching an `entered` row EARLIER in the log for the same triple, and
     * `left.at > entered.at`.
     *
     * @param  array<string, mixed>  $result
     */
    private function assertEpisodesPairExactlyOnce(string $run, array $result): void
    {
        $seen = [];

        foreach ($result['animation_log'] as $index => $row) {
            $slot = "{$row['episode_id']}/{$row['phase']}";

            $this->assertArrayNotHasKey($slot, $seen, "[{$run}] `{$row['episode_id']}` carries two `{$row['phase']}` rows");
            $seen[$slot] = $index + 0;

            if ($row['phase'] !== 'left') {
                $seen[$row['episode_id']] = $row;

                continue;
            }

            $entry = $seen[$row['episode_id']] ?? null;

            $this->assertNotNull($entry, "[{$run}] a `left` row's `episode_id` matches no earlier `entered` row");
            $this->assertSame(
                [$entry['animation_id'], $entry['install_id'], $entry['seat_id']],
                [$row['animation_id'], $row['install_id'], $row['seat_id']],
                "[{$run}] a `left` row pairs with an entry for another animation or another seat",
            );
            $this->assertGreaterThan($entry['at'], $row['at'], "[{$run}] a `left` row is dated at or before its own entry");
        }

        // An `edge` row's episode is one row long: no `left` row ever carries its id.
        foreach ($result['animation_log'] as $row) {
            if ($row['class'] === 'edge') {
                $this->assertArrayNotHasKey("{$row['episode_id']}/left", $seen,
                    "[{$run}] an `edge` row's episode was left, and an edge animation is an instant");
            }
        }
    }

    /** `fx-snapshot-4` alone, then silence — the instrument half's own checked-in run. */
    private function silentSnapshotRun(): array
    {
        return $this->deskRun('instrument');
    }

    /**
     * The closed-set half's four fixtures, each replayed once.
     *
     * @return array<string, array<string, mixed>>
     */
    private function closedSetRuns(?string $moduleDir = null): array
    {
        $runs = [];

        foreach (self::CLOSED_SET_RUNS as $run) {
            $runs[$run] = $this->deskRun($run, $moduleDir);
        }

        return $runs;
    }

    /**
     * § 11's `fx-clear-trace` walk table, as `[[animation_id, phase], …]` in the order it predicts —
     * parsed out of the Episodes column's own `A_n **entered** (episode N)` phrases.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function documentEpisodeWalk(): array
    {
        $md = $this->floorMd();
        $open = strpos($md, '| At | The facts that moved | Episodes |');

        if ($open === false) {
            return [];
        }

        $walk = [];

        foreach (explode("\n", substr($md, $open, (int) strpos($md, "\n\n", $open) - $open)) as $line) {
            $cells = $this->tableCells($line);

            if (count($cells) !== 3 || $cells[0] === 'At' || str_starts_with($cells[0], '---')) {
                continue;
            }

            preg_match_all('/\b(A\d+) \*\*(entered|left)\*\*/', $cells[2], $found, PREG_SET_ORDER);

            foreach ($found as [, $id, $phase]) {
                $walk[] = [$id, $phase];
            }
        }

        return $walk;
    }

    /**
     * The object this client HELD for a key at one `state_version`, out of the run's own records — so
     * the hold predicate is asserted against what the client applied and not against the fixture.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function seatAtVersion(array $result, string $key, int|string|null $version): ?array
    {
        foreach ($result['records'] as $record) {
            $seat = $record['seats'][$key] ?? null;

            if ($seat !== null && $seat['state_version'] === $version) {
                return $seat;
            }
        }

        return null;
    }

    /**
     * The `seat.delta` one run delivered for a key at a version, out of the checked-in fixture.
     *
     * @return array<string, mixed>|null
     */
    private function deltaAt(string $run, string $install, string $seat, int $version): ?array
    {
        foreach ($this->fixture($run)['messages'] ?? [] as $message) {
            $envelope = $message['envelope'];

            if (($envelope['t'] ?? null) === 'seat.delta'
                && $envelope['install_id'] === $install
                && $envelope['seat_id'] === $seat
                && $envelope['state_version'] === $version) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * Every frame interval a RUNNING loop was drawn at, across a set of runs — the population § 6.1
     * rule 2's *fixed for every loop and every seat* is asserted over.
     *
     * @param  array<string, array<string, mixed>>  $runs
     * @return list<int>
     */
    private function everyFrameInterval(array $runs): array
    {
        $intervals = [];

        foreach ($runs as $result) {
            foreach ($result['desk_renders'] as $render) {
                foreach ($render['frame']['desks'] as $desk) {
                    if (($desk['held']['motion'] ?? false) === true) {
                        $intervals[] = $desk['held']['frame_interval_ms'];
                    }
                }
            }
        }

        return $intervals;
    }

    /** A copy of the shipped tree with one anchored edit in `wire/animation-set.js`. */
    private function plantedSet(string $anchor, string $replacement): string
    {
        return $this->mutatedModules(['animation-set.js', $anchor, $replacement]);
    }
}

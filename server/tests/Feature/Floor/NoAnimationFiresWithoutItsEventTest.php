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

    /**
     * The fixture files the closed-set half's own Build bullet names, each end to end — every run
     * each holds, derived from the file rather than listed here.
     */
    private const CLOSED_SET_FILES = ['fx-snapshot-4', 'fx-clear-trace', 'fx-degraded', 'fx-interns'];

    /**
     * ⛔ AND THE RUNS APPENDIX B ROW 15 (e) WIDENS IT TO: `refusal_401_warm`, the one checked-in
     * run that stills a floor with a loop running, whose exits are § 11's (4); and
     * `missing_persistent`, the unconfirmed desk's, whose exit is (3).
     */
    private const CLOSED_SET_ADDED_RUNS = ['refusal_401_warm', 'missing_persistent'];

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
     * ⛔ THE CLOSED-SET HALF. The Build bullet's fixture files end to end and row 15 (e)'s runs, one
     * predicate per clause of § 11's GREEN.
     */
    public function test_every_row_the_closed_set_runs_write_obeys_section_62(): void
    {
        $runs = $this->closedSetRuns();
        $table = $this->documentAnimationRows();
        $checked = ['edge' => 0, 'entered' => 0, 'left' => 0];
        $steps = ['(1)' => 0, '(2a)' => 0, '(3)' => 0, '(2b)' => 0, '(4)' => 0];

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

            $this->assertEveryEntryHoldsItsRow($run, $result);

            foreach ($this->assertEveryExitHasThePrecedencesCause($run, $result) as $step) {
                $steps[$step]++;
            }

            $this->assertEpisodesPairExactlyOnce($run, $result);
        }

        // CONTROLS ON THE WALK: a clause asserted over an empty population is a clause that passed
        // without being able to fail, and all three populations are non-empty on these runs.
        $this->assertGreaterThan(0, $checked['edge'], 'no `edge` row was written across the closed-set runs — the edge clauses are vacuous');
        $this->assertGreaterThan(0, $checked['entered'], 'no held entry was written — the entry clause is vacuous');
        $this->assertGreaterThan(0, $checked['left'], 'no held exit was written — the exit clause and the pairing are vacuous');

        // And every step of § 11's precedence names at least one exit on these runs, so no step's
        // answer is a clause the predicate carries without it ever being asked.
        foreach ($steps as $step => $n) {
            $this->assertGreaterThan(0, $n, "no desk exit on the closed-set runs is § 11's step {$step} — that step of the predicate is vacuous");
        }
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
            .'            if (desk.held === null || this.#episodes.has(k)) {',
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

        $this->assertNotSame([], $intervals, 'no running loop was drawn across the closed-set runs — the rate assertion is vacuous');
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
     * that is one of § 11's causing messages, and the driving field in the causing delta's
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
        // parenthetical). Over these runs the only such rows are the heartbeat's.
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
     * The `entered` half of § 11's clause on a `held` row's `cause`: in an `entered` row's object the
     * fact its § 6.2 row names HAS the value its hold condition states.
     *
     * ⛔ THE PREDICATE IS § 6.2's TABLE AS A TOTAL FUNCTION — *which row does THIS object hold* — and
     * not one row's equalities read alone. A3's condition is `render_state == "working"` **and not
     * A4's condition**, so asking the table which row the object holds carries that exclusion without
     * a second copy of it, and it is what § 6.2 means by *the held rows this table predicts a single
     * answer rather than two*. The `left` half is not the opposite of this one: it is § 11's
     * precedence, asserted below.
     *
     * @param  array<string, mixed>  $result
     */
    private function assertEveryEntryHoldsItsRow(string $run, array $result): void
    {
        $conditions = $this->documentHoldConditions();

        foreach ($result['animation_log'] as $row) {
            if ($row['class'] !== 'held' || $row['phase'] !== 'entered' || ! isset($conditions[$row['animation_id']])) {
                continue;
            }

            $key = "{$row['install_id']}/{$row['seat_id']}";
            $object = $this->seatAtVersion($result, $key, $row['cause']);

            $this->assertNotNull($object,
                "[{$run}] {$row['animation_id']}'s entered row names `state_version` {$row['cause']}, which this client never held for {$key}");

            $holds = $this->heldRowFor($object);

            $this->assertSame($row['animation_id'], $holds,
                "[{$run}] {$row['animation_id']} was entered against an object § 6.2 says holds "
                .($holds ?? 'no row at all'));
        }
    }

    /**
     * ⛔ AT-D3-1's ONE PREDICATE ON AN EXIT, AND THE `stilled` RE-ENTRY CHECK BESIDE IT. Every `left`
     * row of a desk episode has the `cause` § 11's precedence gives for the render that wrote it —
     * the first step, in the precedence's order, that applies to that seat in that render compared
     * with the render before it, and that step's answer; a render in which no step applies ends no
     * episode, so a `left` row written there reds whatever it names. And every `left` row whose
     * `cause` is `stilled` leaves an episode entered at `motion: true`, with the same `animation_id`
     * entered again on that seat in that render at `motion: false`.
     *
     * ⛔ IT IS EVALUATED FROM THE PROBE'S PER-RENDER RECORDS, because the previous render's conditions
     * are not on the log (Appendix B row 15 (c)): each apply render's stilled floor, each held seat's
     * object and § 2.3 row 5 state, the removals its journal applied, and the rows it wrote. The rows
     * are first asserted to partition the log, so an exit no record owns cannot escape the predicate.
     *
     * [A18](§ 6.2) is outside the precedence — its exit `cause` is its `thread_ref`, or `stilled` for
     * a drawn line the floor stills, which AT-D3-8 holds — and its rows carry no seat, which is how
     * they are told apart here.
     *
     * @param  array<string, mixed>  $result
     * @return list<string> the step that named each desk exit checked, for the caller's controls
     */
    private function assertEveryExitHasThePrecedencesCause(string $run, array $result): array
    {
        $renders = array_values(array_filter($result['desk_renders'], static fn (array $r): bool => $r['trigger'] === 'apply'));

        $this->assertSame($result['animation_log'], array_merge([], ...array_column($renders, 'rows')),
            "[{$run}] the per-render records' rows are not the log in order — an exit no record owns would escape the predicate");

        $entries = [];
        $steps = [];
        $previous = null;

        foreach ($renders as $render) {
            foreach ($render['rows'] as $row) {
                if ($row['class'] !== 'held') {
                    continue;
                }

                if ($row['phase'] === 'entered') {
                    $entries[$row['episode_id']] = $row;

                    continue;
                }

                if ($row['seat_id'] === null) {
                    continue;
                }

                $entry = $entries[$row['episode_id']] ?? null;

                $this->assertNotNull($entry, "[{$run}] a desk `left` row's `episode_id` matches no earlier `entered` row");

                $key = "{$row['install_id']}/{$row['seat_id']}";
                $given = $this->precedenceGives($run, $render, $previous, $entry);

                $this->assertNotNull($given,
                    "[{$run}] {$row['animation_id']} on {$key} was left at {$render['at']} ms, in a render where no step of § 11's "
                    .'precedence applies — that render ended no episode, so the row reds whatever it names ('.json_encode($row['cause']).')');

                [$step, $cause] = $given;

                $this->assertSame($cause, $row['cause'],
                    "[{$run}] {$row['animation_id']} on {$key} was left at {$render['at']} ms naming ".json_encode($row['cause'])
                    ." where § 11's precedence gives step {$step}'s answer, ".json_encode($cause));

                if ($row['cause'] === 'stilled') {
                    $this->assertTrue($entry['motion'],
                        "[{$run}] a `stilled` exit on {$key} splits an episode entered at `motion: false` — a desk already drawn static is not left when the floor stills");

                    $reentered = array_filter($render['rows'], static fn (array $r): bool => $r['class'] === 'held'
                        && $r['phase'] === 'entered' && $r['animation_id'] === $row['animation_id']
                        && $r['install_id'] === $row['install_id'] && $r['seat_id'] === $row['seat_id'] && $r['motion'] === false);

                    $this->assertNotSame([], $reentered,
                        "[{$run}] {$row['animation_id']} on {$key} was left `stilled` and not entered again in that render at `motion: false`");
                }

                $steps[] = $step;
            }

            $previous = $render;
        }

        return $steps;
    }

    /**
     * § 11's precedence over one desk episode in one render, compared with the render before it:
     * `[step, cause]` for the first step that applies, or `null` where none does.
     *
     * ⚠ (2b)'s `motion` IS READ WHERE THE RECORDS CARRY IT. § 11 asks at what `motion` the object held
     * now draws the episode's row with every client condition at its previous value. Where the stilled
     * floor is the same in both renders, that drawing IS this render's frame for the seat, and the
     * frame is read. Where the floor was stilled before, every desk is drawn static. Where it stills in
     * THIS render and the object is unchanged, the object draws what the previous render drew, which is
     * the episode's own `motion`. The one remaining case — a render that stills the floor AND applies
     * an object that keeps the row — asks for a drawing no record carries, and it fails by name rather
     * than guess; no replayed run reaches it.
     *
     * @param  array<string, mixed>  $render
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: int|string|null}|null
     */
    private function precedenceGives(string $run, array $render, ?array $previous, array $entry): ?array
    {
        $key = "{$entry['install_id']}/{$entry['seat_id']}";
        $held = $render['held'][$key] ?? null;

        if ($held === null) {
            // (1): the removal's answer, § 2.3 row 4's `snapshot` or the retired object's version.
            foreach ($render['removals'] as $removal) {
                if ($removal['key'] === $key) {
                    $this->assertTrue($removal['cause'] === 'snapshot' || is_int($removal['cause']),
                        "[{$run}] the removal of {$key} journalled ".json_encode($removal['cause']).', which is neither `snapshot` nor a version');

                    return ['(1)', $removal['cause']];
                }
            }

            return null;
        }

        // An episode open in this render was drawn in the previous one, so that render held the seat.
        $was = $previous['held'][$key];

        $object = $held['object'];
        $changed = $was['object'] !== $object;
        $version = $object['state_version'];

        // (2a): the object held now, drawn with row 5's condition at its previous value — a
        // missing seat draws the empty chair, which holds no row.
        $row = $was['missing'] ? null : $this->heldRowFor($object);

        if ($changed && $row !== $entry['animation_id']) {
            return ['(2a)', $version];
        }

        if ($held['missing'] && ! $was['missing']) {
            return ['(3)', 'unconfirmed'];
        }

        if ($changed) {
            $motion = match (true) {
                $previous['stilled'] => false,
                $render['stilled'] === $previous['stilled'] => $render['frame']['desks'][$key]['held']['motion'] ?? null,
                default => $this->fail("[{$run}] a render stilled the floor and applied an object on {$key} that keeps its row — "
                    .'(2b) asks for a drawing no record carries'),
            };

            if ($motion !== $entry['motion']) {
                return ['(2b)', $version];
            }
        }

        if ($render['stilled'] && ! $previous['stilled']) {
            return ['(4)', 'stilled'];
        }

        return null;
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
            $this->assertFalse($row['motion'], "[{$run}] a `left` row claims motion, and nothing is drawn by a render that has been left");
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
     * The closed-set half's runs, each replayed once under the desk floor.
     *
     * ⚠ A RUN WRITTEN FOR THE FLOOR SCREEN OR THE LOBBY IS REPLAYED UNDER THE DESK FLOOR — the same
     * bytes on another page. AT-D3-1 reads the desk floor's log and its per-render records, the lobby
     * writes A17 rows alone (its sky, card#7343 — `TheLobbySkyIsTheFloorsA17Test`), and `fx-snapshot-4`'s
     * `lobby_over` is the run that reaches
     * § 11's (1) through § 2.3 row 4's `snapshot` literal.
     *
     * @return array<string, array<string, mixed>>
     */
    private function closedSetRuns(?string $moduleDir = null): array
    {
        $runs = [];

        foreach (self::CLOSED_SET_FILES as $file) {
            foreach (array_keys($this->fixtureFile($file)['runs']) as $run) {
                $runs[$run] = $this->deskRun($run, $moduleDir, ['floor' => null, 'lobby' => false]);
            }
        }

        foreach (self::CLOSED_SET_ADDED_RUNS as $run) {
            $runs[$run] = $this->deskRun($run, $moduleDir, ['floor' => null, 'lobby' => false]);
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

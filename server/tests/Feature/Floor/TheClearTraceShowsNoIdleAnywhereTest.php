<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-2 — the `/clear` trace shows no idle anywhere.** The D3 half of D1's and D2's headline test
 * (`docs/design/FLEET-STATE.md` AT-D2-2), gated at Appendix B **step 6**. card#7341 step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CLAIM IS ABOUT A TRACE, NOT ABOUT AN ABSENCE. A test that only asserted *no idle render*
 * would pass against a renderer that draws nothing at all, so the GREEN below walks what the desk DID
 * render at every applied delta, and the DISCRIMINATING CONTROL drives an ordinary seat to a clean
 * `turn.end` and requires that one to render `idle` and log its A6 row. The absence means something
 * only because the presence is measured beside it.
 *
 * ⛔ EVERY FIGURE IN `fx-clear-trace` IS RE-DERIVED HERE FROM § 14 ITEM 21's CLOSURE AND FROM
 * `fx-snapshot-4`'s OWN PUBLISHED SNAPSHOT. The fixture is checked-in bytes because no test may build
 * a snapshot in PHP, and this file is what keeps those bytes honest: the `state_version` base and its
 * run (part 1), `E0`'s three authored facts (part 2), the 1 s clock advance (part 3) and the single
 * hook order (part 4) are each read out of the document and compared against the fixture, so a figure
 * nobody could derive cannot survive here.
 */
class TheClearTraceShowsNoIdleAnywhereTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const TRACE = 'fx-clear-trace';

    private const PM = 'aimla/aimla-pm';

    /**
     * ⛔ THE FIXTURE IS THE DOCUMENT'S OWN TRACE. Part 1, part 2, part 3 and part 4 of § 14 item 21,
     * each read from the closure rather than trusted in the bytes.
     */
    public function test_the_fixture_is_the_trace_item_21_closes_over(): void
    {
        $scenario = $this->fixture(self::TRACE);
        $published = $this->fixtureFile('fx-snapshot-4')['snapshot'];
        $served = $scenario['http']['/api/fleet/snapshot'][0]['body'];

        // THE BASE. § 11: "`fx-snapshot-4`, then the ten deltas". A second hand-typed copy of that
        // snapshot is a drift surface, so the run DECLARES its base and this is the guard on it.
        $this->assertSame('fx-snapshot-4', $scenario['base'] ?? null,
            'the run does not declare the fixture its snapshot comes from, so nothing holds the copy to the original');
        $this->assertSame($published, $served,
            '`fx-clear-trace` serves a snapshot that is not `fx-snapshot-4`\'s published one — two copies of one base');

        $messages = $scenario['messages'];
        $kinds = array_map(static fn (array $m): string => $m['envelope']['t'], $messages);

        // PART 4 — one hook order, not two. "A second replay of byte-identical input proves nothing."
        $this->assertSame(['fx-clear-trace'], array_keys($this->fixtureFile(self::TRACE)['runs']),
            'the fixture file ships more than one run — item 21 part 4 drops the second hook order');
        $this->assertSame(array_fill(0, count($messages), 'seat.delta'), $kinds,
            'the trace delivers a message that is not one of D2 § 10\'s ten deltas');

        // § 11's own word for how many, read out of the row rather than counted into it.
        $this->assertSame(1, preg_match('/`fx-clear-trace` \| `fx-snapshot-4`, then the \*\*(\w+)\*\* deltas/', $this->floorMd(), $count),
            '§ 11\'s `fx-clear-trace` row no longer states how many deltas the trace carries');
        $this->assertSame(10, ['nine' => 9, 'ten' => 10, 'eleven' => 11][strtolower($count[1])] ?? null);
        $this->assertCount(10, $messages);

        // PART 1 — the version base is `fx-snapshot-4`'s OWN `state_version`, and the run continues
        // from it. Both the derivation and item 21's stated endpoints are read.
        $base = $this->publishedSeat($published, 'aimla-pm')['state_version'];
        $versions = array_map(static fn (array $m): int => $m['envelope']['state_version'], $messages);

        $this->assertSame(range($base + 1, $base + 10), $versions,
            'the trace\'s `state_version` run does not continue from `fx-snapshot-4`\'s own value');
        $this->assertSame(1, preg_match('/`state_version` runs \*\*(\d+)\*\* through\s+\*\*(\d+)\*\*/', $this->floorMd(), $run),
            'item 21 part 1 no longer states the version run in the form this check reads');
        $this->assertSame([(int) $run[1], (int) $run[2]], [$versions[0], $versions[9]],
            'the trace\'s version run is not the one item 21 part 1 closes on');

        // PART 3 — 1 s per delta from `fx-snapshot-4`'s own `server_time`, and item 21's own endpoints.
        $from = $this->ms($published['server_time']);
        $stamps = array_map(fn (array $m): int => $this->ms($m['envelope']['server_time']), $messages);

        $this->assertSame(array_map(static fn (int $n): int => $from + 1000 * $n, range(1, 10)), $stamps,
            'the trace\'s clock does not advance 1 s per delta from `fx-snapshot-4`\'s own `server_time`');

        foreach ($messages as $message) {
            $this->assertSame($message['envelope']['server_time'], $message['envelope']['at'],
                'a delta\'s `at` and `server_time` disagree, and item 21 part 3 advances one clock');
        }

        $this->assertSame(1, preg_match('/`E0` is `(…:[\d.]+Z)`, and `E9` is `(…:[\d.]+Z)`/u', $this->floorMd(), $ends),
            'item 21 part 3 no longer states E0\'s and E9\'s own instants');

        // The published instants are elided — `…:15.400Z` — and the ellipsis is a THREE-BYTE
        // character, so the tail is taken with `mb_substr`; `substr` would leave half of one.
        foreach ([[0, $ends[1]], [9, $ends[2]]] as [$index, $tail]) {
            $this->assertStringEndsWith(mb_substr($tail, 1), $messages[$index]['envelope']['server_time'],
                'the trace\'s E'.$index.' is not the instant item 21 part 3 closes on');
        }

        // PART 2 — `E0`'s three authored facts, as § 11's row states them.
        $after = $this->seatAfter($messages, 0, $this->publishedSeat($published, 'aimla-pm'));

        $this->assertSame(1, preg_match(
            '/`open_calls: 0`, `open_turn: true`, `action: null`, `subagents: \[\]`, `subagents_open: 0` immediately after/',
            $this->floorMd()), '§ 11\'s `fx-clear-trace` row no longer states E0\'s resulting facts');
        $this->assertSame(
            ['open_calls' => 0, 'open_turn' => true, 'action' => null, 'subagents' => [], 'subagents_open' => 0],
            [
                'open_calls' => $after['open_calls'], 'open_turn' => $after['open_turn'],
                'action' => $after['action'], 'subagents' => $after['subagents'],
                'subagents_open' => $after['subagents_open'],
            ],
            'E0 does not leave the seat in the state item 21 part 2 authored it to',
        );
    }

    /** ⛔ THE GREEN, clause by clause, over what the shipped renderer drew at every applied delta. */
    public function test_the_desk_works_then_goes_unknown_and_is_never_idle(): void
    {
        $result = $this->deskRun(self::TRACE);
        $walk = $this->renderedWalk($result);

        $this->assertCount(10, $walk, 'the run did not draw one apply frame per delta');

        foreach ($walk as $index => $frame) {
            $expected = $index <= 6 ? 'working' : 'unknown';

            $this->assertSame($expected, $frame['render_state'],
                "at E{$index} the desk renders {$frame['render_state']} where the trace's own facts say {$expected}");
            $this->assertNotSame('idle', $frame['render_state'], "the desk rendered `idle` at E{$index}");
        }

        // ⛔ SCOPED TO `aimla-pm`, WHICH IS THE SEAT THE TRACE IS ABOUT, AND THE SCOPE IS FORCED BY
        // THE DOCUMENT RATHER THAN CHOSEN. § 11's `fx-snapshot-4` row states `aimla-impl-2` is `idle`,
        // so the log over this fixture necessarily carries an A6 row for THAT desk — and it must: a
        // renderer suppressing it would make the floor go still on a seat that really has finished
        // (§ 6.3, decision 3). What AT-D3-2 forbids is an A6 row on the seat whose work was KILLED,
        // which is also why its own control drives a DIFFERENT seat to a clean finish and requires one.
        $pm = array_column($this->rowsFor($result, self::PM), 'animation_id');

        $this->assertNotContains('A6', $pm, 'the log carries an `idle` row (A6) for the seat the `/clear` killed');
        $this->assertNotContains('A2', $pm, 'the log carries a `depart` row (A2) on a seat that never went offline');

        // The control on that scope: the idle row the fixture DOES carry is another desk's, so the
        // clause above is a statement about `aimla-pm` and not about an empty log.
        $this->assertContains('A6', array_column($result['animation_log'], 'animation_id'),
            '`fx-snapshot-4`\'s own idle seat logged no A6 row, so the scoped clause above proves nothing');
    }

    /**
     * ⛔ THE SIDE TABLE, which is step 5's artifact and this test's to read (§ 11's ordering rule put it
     * at step 5 for exactly that reason). The `coder` stool empties at E0 — item 21 part 2's own
     * consequence — so the table is never simultaneously occupied by it and by the E1 intern.
     */
    public function test_the_side_table_empties_then_fills_then_empties(): void
    {
        $walk = $this->renderedWalk($this->deskRun(self::TRACE));

        $this->assertSame([], $walk[0]['stools'], 'the `coder` stool did not empty at E0');
        $this->assertCount(1, $walk[1]['stools'], 'the side table did not gain an intern at E1');
        $this->assertTrue($walk[1]['stools'][0]['untitled'], 'the intern arriving at E1 is not title-less');
        $this->assertCount(1, $walk[2]['stools'], 'the side table lost its intern at E2');
        $this->assertFalse($walk[2]['stools'][0]['untitled'], 'the intern did not gain its title at E2');
        $this->assertSame($walk[1]['stools'][0]['call_id'], $walk[2]['stools'][0]['call_id'],
            'the titled intern at E2 is a different call from the one that arrived at E1');
        $this->assertSame([], $walk[5]['stools'], 'the side table did not empty again at E5');
    }

    /**
     * ⛔ FIVE A5 ROWS AND NO MORE, at the five deltas § 11 names — read out of AT-D3-2's own GREEN and
     * out of the fixture's own patches, which must agree before either is used as the expectation.
     */
    public function test_the_action_changes_five_times_and_logs_five_edge_rows(): void
    {
        $result = $this->deskRun(self::TRACE);
        $messages = $this->fixture(self::TRACE)['messages'];

        // (a) The document's own list.
        $this->assertSame(1, preg_match('/`action` changes (\w+) times — at ((?:E\d+(?:, | and )?)+) —/u', $this->floorMd(), $m),
            'AT-D3-2\'s GREEN no longer states which deltas change `action`');

        preg_match_all('/E(\d+)/', $m[2], $named);
        $stated = array_map('intval', $named[1]);

        $this->assertSame(5, ['four' => 4, 'five' => 5, 'six' => 6][strtolower($m[1])] ?? null,
            'AT-D3-2\'s GREEN states a count its own list does not have');
        $this->assertCount(5, $stated);

        // (b) The fixture's own patches — a delta patches `action` to a different `tool_name`.
        $derived = [];
        $held = $this->publishedSeat($this->fixtureFile('fx-snapshot-4')['snapshot'], 'aimla-pm');

        foreach ($messages as $index => $message) {
            $before = $held;
            $held = $this->seatAfter($messages, $index, $before);

            if (in_array('action', $message['envelope']['changed'], true)
                && ($before['action']['tool_name'] ?? null) !== ($held['action']['tool_name'] ?? null)) {
                $derived[] = $index;
            }
        }

        $this->assertSame($stated, $derived,
            'the deltas that actually change `action`\'s tool name are not the ones AT-D3-2\'s GREEN names');

        // (c) And the log carries exactly those five A5 rows, each its own one-row episode.
        $a5 = array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['animation_id'] === 'A5'));
        $base = $this->publishedSeat($this->fixtureFile('fx-snapshot-4')['snapshot'], 'aimla-pm')['state_version'];

        $this->assertSame(
            array_map(static fn (int $index): int => $base + $index + 1, $stated),
            array_column($a5, 'cause'),
            'the A5 rows the log carries are not caused by the five deltas that change `action`',
        );

        $episodes = array_column($a5, 'episode_id');
        $left = array_column(array_filter($result['animation_log'], static fn (array $r): bool => $r['phase'] === 'left'), 'episode_id');

        foreach ($a5 as $row) {
            $this->assertSame('edge', $row['class']);
            $this->assertSame('fired', $row['phase']);
        }

        $this->assertSame($episodes, array_unique($episodes), 'two A5 firings share an episode, and an edge animation is an instant');
        $this->assertSame([], array_intersect($episodes, $left), 'an A5 firing was left, and an edge animation has no exit');
    }

    /**
     * ⛔ THE DISCRIMINATING CONTROL — `aimla-impl-1`, whose `fx-snapshot-4` state is `working`, driven
     * to a CLEAN `turn.end`. It DOES render `idle` and DOES log an A6 `entered` row with `motion: true`
     * (A6 holds the sleeping loop since the 2026-08-27 amendment, and the value is what § 6.2 says
     * rather than a constant), preceded by the A3 `left` row whose cause is that delta's own
     * `state_version`.
     *
     * ⛔ THE PAIRING IS ASSERTED, NOT MERELY BOTH ROWS' PRESENCE. Two rows in the right order with
     * unrelated episodes is a log that cannot say the loop this seat was running is the loop that
     * stopped, so the A3 `left` row must carry the very `episode_id` A3's `entered` row opened at the
     * snapshot apply.
     */
    public function test_control_a_clean_turn_end_does_render_idle_and_logs_its_row(): void
    {
        $result = $this->deskRun('clean_finish');
        $rows = $this->heldRows($result, 'aimla/aimla-impl-1');
        $delta = $this->fixture('clean_finish')['messages'][0]['envelope'];

        $this->assertCount(3, $rows, 'the control did not write an entry, its exit and the new entry');

        [$entered, $left, $idle] = $rows;

        $this->assertSame(['A3', 'entered'], [$entered['animation_id'], $entered['phase']]);
        $this->assertSame(['A3', 'left'], [$left['animation_id'], $left['phase']]);
        $this->assertSame(['A6', 'entered'], [$idle['animation_id'], $idle['phase']],
            'a clean `turn.end` did not enter A6 — the control cannot show the trace\'s absence means anything');

        $this->assertSame($entered['episode_id'], $left['episode_id'],
            'the A3 exit does not carry the episode A3\'s entry opened at the snapshot apply');
        $this->assertSame($delta['state_version'], $left['cause'],
            'the A3 exit\'s cause is not the `turn.end` delta\'s own `state_version`');
        $this->assertSame($delta['state_version'], $idle['cause']);
        $this->assertTrue($idle['motion'], 'A6 was entered without motion, and § 6.2 gives it a sleeping loop');
        $this->assertNotSame($entered['episode_id'], $idle['episode_id'], 'A6 did not open a fresh episode');

        // And the desk itself renders `idle`, which is the render the trace must never produce.
        $last = $this->lastFrame($result);

        $this->assertSame('idle', $last['desks']['aimla/aimla-impl-1']['render_state']['value']);
    }

    /**
     * ⛔ RED — THE INFERRED FINISH. A *finished* render when `open_calls` reaches 0, which the E5→E7
     * window makes true while the turn is still open: the desk animates a completion for a seat whose
     * work was KILLED. That is the false idle, arriving through the render layer after D1 and D2 both
     * removed it from theirs.
     */
    public function test_red_a_finish_inferred_from_open_calls_draws_the_false_idle(): void
    {
        $inferring = $this->plantedDesk(
            "    const state = seat.render_state;",
            "    const state = seat.open_calls === 0 && seat.render_state === 'working' ? 'idle' : seat.render_state;",
        );

        $walk = $this->renderedWalk($this->deskRun(self::TRACE, $inferring));
        $drawn = array_column($walk, 'render_state');

        $this->assertContains('idle', $drawn,
            'the RED did not bite: a finish was inferred from `open_calls` and no desk rendered `idle`');

        // And the log gains an A6 row FOR `aimla-pm` — the same defect one instrument over. The scope
        // is what makes this assertion mean anything: `aimla-impl-2` contributes an A6 row on this
        // fixture whatever the renderer does, so an unscoped `assertContains` would pass unplanted.
        $planted = $this->deskRun(self::TRACE, $inferring);
        $pm = array_column($this->rowsFor($planted, self::PM), 'animation_id');

        $this->assertContains('A6', $pm, 'the inferred finish reached the render and not the log, so AT-D3-1 would not see it');
        $this->assertNotContains('A6', array_column($this->rowsFor($this->deskRun(self::TRACE), self::PM), 'animation_id'),
            'the unplanted run already logged A6 for `aimla-pm`, so this RED was never red');

        // The window § 11 names: E5 through E7, where `open_calls` is 0 and the turn is still open.
        foreach ([5, 6] as $index) {
            $this->assertSame('idle', $walk[$index]['render_state'],
                "the inferred finish did not fire at E{$index}, which is the E5→E7 window the RED exists for");
        }
    }

    /**
     * What the desk rendered at each applied delta, in delta order: the frame the APPLY path drew at
     * the instant that delta settled, beside the version the client then held.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{render_state: string, state_version: int, stools: list<array<string, mixed>>}>
     */
    private function renderedWalk(array $result): array
    {
        $walk = [];

        foreach ($this->fixture(self::TRACE)['messages'] as $message) {
            $at = $message['at_ms'];
            $frame = null;

            foreach ($result['desk_renders'] as $render) {
                if ($render['at'] === $at && $render['trigger'] === 'apply') {
                    $frame = $render['frame']['desks'][self::PM] ?? null;
                }
            }

            $this->assertNotNull($frame, "no apply frame drew `aimla-pm` at t={$at}");

            $walk[] = [
                'render_state' => $frame['render_state']['value'],
                'state_version' => $this->recordAt($result, $at)['seats'][self::PM]['state_version'],
                'stools' => $frame['side_table']['stools'],
            ];
        }

        return $walk;
    }

    /**
     * Every log row one seat wrote, whatever its class — `heldRows()` filters to `held`, and the A2
     * clause above is about an `edge` row.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $result, string $key): array
    {
        [$install, $seat] = explode('/', $key, 2);

        return array_values(array_filter(
            $result['animation_log'],
            static fn (array $row): bool => $row['install_id'] === $install && $row['seat_id'] === $seat,
        ));
    }

    /**
     * The object one delta leaves, by applying D2 § 8.3's shallow merge to the object before it.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function seatAfter(array $messages, int $index, array $before): array
    {
        return array_replace($before, $messages[$index]['envelope']['patch']);
    }

    /**
     * One seat out of a published snapshot body.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function publishedSeat(array $snapshot, string $seatId): array
    {
        foreach ($snapshot['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                if ($seat['seat_id'] === $seatId) {
                    return $seat;
                }
            }
        }

        $this->fail("the published snapshot carries no seat {$seatId}");
    }
}

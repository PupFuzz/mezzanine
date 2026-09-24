<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * APPENDIX B ROW 6's **animation set** against `docs/design/FLOOR.md § 6.2`'s CLOSED SET — the
 * table RE-DERIVED from the document on every run and set-differenced against the shipped module in
 * both directions. card#7341 step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THE GUARD EXISTS. A browser cannot read FLOOR.md, so § 6.2's Animation and Reduced-motion
 * form cells live in `wire/animation-set.js` as copies, and § 16 of the engineering canon leaves a
 * restatement two honest ends: DELETE it, or GUARD it. A module a page loads cannot follow a
 * pointer, so this is the guard. A row § 6.2 carries and the module does not is a row nobody draws;
 * a row the module carries and § 6.2 does not is claim-bearing motion with no driving fact.
 *
 * ⛔ WHAT IT ALSO CLOSES, AND WHAT THAT COST BEFORE. `desk/desk-render.js` held a hand-kept
 * `STATIC_BY_DESIGN = ['A8', 'A9']` beside a hand-kept state→id map, and NOTHING re-derived either
 * from the document — so a § 6.2 row gaining or losing a loop, or a held row changing the state it
 * is held by, moved the document and left that file's copies standing. The set now answers *does
 * this row loop* from the Animation cell it already holds under guard, and the state→id map is
 * checked here against § 6.2's own held conditions.
 *
 * ⚠ WHAT THIS DOES NOT CHECK, named rather than left as a silence: that a renderer calls the right
 * entry for an id on the right event. That is AT-D3-1's, AT-D3-2's and the two render halves', which
 * replay real fixtures through the harness; this file reads a declaration and drives the module.
 */
class TheAnimationSetIsTheDocumentsClosedSetTest extends TestCase
{
    use DrivesTheAnimationSetModule;

    /** § 6.2's own reference to the fixed rate, which § 12's *Loop frame rate* row publishes. */
    private const FPS_ROW = '| **Loop frame rate** | **4 fps** |';

    public function test_the_shipped_set_is_section_62s_table_in_both_directions(): void
    {
        $document = $this->documentAnimationRows();
        $shipped = $this->shippedSet()['set'];

        // A CONTROL ON THE WALK FIRST: an empty parse makes every assertion below vacuous.
        $this->assertNotSame([], $document, '§ 6.2\'s table did not parse — the population is unread');
        $this->assertSame(
            array_map(static fn (int $n): string => "A{$n}", range(1, count($document))),
            array_keys($document),
            '§ 6.2\'s ids did not parse as a contiguous A1…An in table order',
        );

        $this->assertSame([], array_diff(array_keys($document), array_keys($shipped)),
            'a § 6.2 row has no entry in the shipped animation set — a row nobody draws');
        $this->assertSame([], array_diff(array_keys($shipped), array_keys($document)),
            'the shipped animation set carries an id § 6.2 does not — claim-bearing motion with no driving fact');

        $defects = [];

        foreach ($document as $id => $row) {
            foreach (['class', 'animation', 'reduced'] as $cell) {
                if (($shipped[$id][$cell] ?? null) !== $row[$cell]) {
                    $defects[$id.'/'.$cell] = ['document' => $row[$cell], 'module' => $shipped[$id][$cell] ?? null];
                }
            }
        }

        $this->assertSame([], $defects,
            'the shipped set\'s words are not § 6.2\'s — a restatement that has drifted from the table it copies');
    }

    /**
     * § 6.1 rule 2, § 12's *Loop frame rate* row, and § 11's *two states with no motion by design*:
     * one fixed rate, and WHICH rows run at it read off each row's own Animation cell.
     */
    public function test_which_rows_loop_and_how_fast_is_derived_from_the_cells_the_module_carries(): void
    {
        $document = $this->documentAnimationRows();
        $shipped = $this->shippedSet();

        $this->assertStringContainsString(self::FPS_ROW, $this->floorMd(),
            '§ 12\'s Loop frame rate row is not published in the form this check reads — the module\'s rate is unguarded');
        $this->assertSame(4, $shipped['loop_fps'], '§ 12 publishes 4 fps and the module runs at another rate');

        $expected = [];

        foreach ($document as $id => $row) {
            $expected[$id] = str_contains($row['animation'], $shipped['loop_fps'].' fps loop');
        }

        $this->assertSame($expected, $shipped['loops'],
            'a row the module calls a loop names none in § 6.2\'s Animation cell, or the other way about');

        foreach ($document as $id => $row) {
            $this->assertSame($expected[$id] ? 250 : null, $shipped['frame_interval_ms'][$id],
                "{$id}'s frame interval is not one frame per § 12's rate");
        }

        // § 11 names the population in words — *the **two** states with no motion by design
        // (`stalled` and `unknown`)* — and it is those two held rows that name no loop. Both halves
        // are read, so neither the count nor the members can drift alone.
        $byState = $this->documentHeldByState();
        $motionless = array_keys(array_filter(
            $expected,
            static fn (bool $loop, string $id): bool => ! $loop && ($document[$id]['class'] ?? '') === 'held'
                && in_array($id, $byState, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame(1, preg_match('/the \*\*(two)\*\* states with no motion by design \(`([a-z_]+)` and `([a-z_]+)`/', $this->floorMd(), $m),
            '§ 11\'s sentence naming the states with no motion by design is not in the form this check reads');
        $this->assertCount(2, $motionless, '§ 11 says two held rows carry no motion by design and § 6.2\'s cells name a different number');
        $this->assertSame([$byState[$m[2]], $byState[$m[3]]], $motionless,
            '§ 11\'s two motionless states are not the two held rows whose § 6.2 cell names no loop');
    }

    /**
     * § 6.4's condition and § 7.3's treatment, in the ONE place either decides `motion` — and the
     * form each row is drawn in, which is the other half of *a first-class rendering*.
     */
    public function test_every_rows_rendering_carries_its_own_form_and_section_64s_condition(): void
    {
        $document = $this->documentAnimationRows();
        $shipped = $this->shippedSet();

        foreach ($document as $id => $row) {
            $this->assertSame($row['animation'], $shipped['forms'][$id]['motion'], "{$id}'s ordinary form is not § 6.2's Animation cell");
            $this->assertSame($row['reduced'], $shipped['forms'][$id]['reduced'], "{$id}'s reduced form is not § 6.2's Reduced-motion form cell");

            $rendering = $shipped['held_renderings'][$id];

            $this->assertSame($row['reduced'], $rendering['permitted_reduced']['form'],
                "{$id} under `reduce` does not draw its reduced-motion form");
            $this->assertFalse($rendering['permitted_reduced']['motion'],
                "{$id} draws motion under `prefers-reduced-motion: reduce`");
            $this->assertNull($rendering['permitted_reduced']['frame_interval_ms'],
                "{$id} carries a frame interval under `reduce`, which is a rate nothing is using");
            $this->assertFalse($rendering['treated']['motion'],
                "{$id} draws motion with § 7.3's treatment refusing it");
            // ⛔ WHETHER A HELD ROW MOVES IS ITS REDUCED CELL AND NOT ITS LOOP, and the two part
            // company at A18 (card#7341 step 7). § 6.4's reduced form REPLACES the motion, so a cell
            // reading *identical* is a row with no motion to replace — § 11's *two states with no
            // motion by design* — while A18's says *drawn static … no travel along it*, which is a
            // removal and therefore a row that moves without running frames at § 12's rate. Reading
            // `loops()` here answers *does it run frames* and logged that line as drawn still,
            // claiming one of § 11's three reasons for no motion where none applied.
            $this->assertSame(
                $row['class'] === 'edge' || $row['reduced'] !== 'identical',
                $rendering['permitted']['motion'],
                "{$id}'s ordinary rendering disagrees with whether its own § 6.2 cells name motion",
            );
        }

        // ⛔ AN ID OUTSIDE THE TABLE GETS ONE ANSWER FROM EVERY READER: no form, no rate, no loop and
        // no motion — never a plausible form, never a rate nothing is using, and never a throw from one
        // reader while its neighbours answer. A caller passing one has a bug either way; what this holds
        // is that the bug looks the same wherever it lands.
        $this->assertSame([
            'form' => null,
            'loops' => false,
            'frame_interval_ms' => null,
            'held_rendering' => [
                'animation_id' => 'A999', 'motion' => false, 'form' => null, 'frame_interval_ms' => null,
            ],
        ], $shipped['outside'], 'the set answered for an id § 6.2 does not carry, which is a form it invented');
    }

    /**
     * `FIRED_BY` is total over the set, and the four rows it defers name the artifact that owes
     * them — so *declared and not fired here* is a statement a reader can check rather than trust.
     */
    public function test_every_row_declares_what_starts_it(): void
    {
        $shipped = $this->shippedSet();
        $document = $this->documentAnimationRows();

        $this->assertSame(array_keys($document), array_keys($shipped['fired_by']),
            'the set\'s FIRED_BY is not total over § 6.2\'s rows');
        $this->assertSame([], array_diff(array_values($shipped['fired_by']), ['delta', 'heartbeat', 'desk', 'slot', 'coord']),
            'FIRED_BY names a trigger class this test does not know about');

        // § 11: the two rows that belong to no seat are the two the heartbeat fires, and § 6.2's own
        // note says they are the only rows conditional on no seat's state at all.
        $this->assertSame(['A14', 'A17'], $shipped['heartbeat_rows'],
            '§ 11\'s two seatless rows are not the two the set fires on the heartbeat');
        $this->assertSame('feed.heartbeat', $shipped['heartbeat']);

        // Every `desk` row is a held row § 6.2 holds by a `render_state`, and every such row is a
        // `desk` row: the two populations are one, derived from the table rather than paired by hand.
        // A4 is in it — its condition names `working` and two further facts — which is why this leg
        // reads the CONDITIONS and not `documentHeldByState()`'s one-row-per-state projection.
        $deskRows = array_keys(array_filter($shipped['fired_by'], static fn (string $by): bool => $by === 'desk'));
        $fromDocument = array_keys(array_filter(
            $document,
            static fn (array $row): bool => $row['class'] === 'held' && str_starts_with($row['condition'], 'render_state == "'),
        ));

        sort($deskRows);
        sort($fromDocument);

        $this->assertSame($fromDocument, $deskRows,
            'the rows the set says a desk holds are not § 6.2\'s held rows keyed by a `render_state`');

        // Every `delta` row names the `changed[]` member its own § 6.2 row is gated on.
        foreach ($shipped['delta_row_drivers'] as $id => $member) {
            $this->assertSame('delta', $shipped['fired_by'][$id], "{$id} is gated on a `changed[]` member and FIRED_BY does not say `delta`");
            $this->assertStringContainsString($member, $document[$id]['driver'],
                "{$id} is gated on `{$member}`, which is not among § 6.2's Driving fact cell for it");
        }

        $this->assertSame(
            array_keys(array_filter($shipped['fired_by'], static fn (string $by): bool => $by === 'delta')),
            array_keys($shipped['delta_row_drivers']),
            'a row FIRED_BY calls delta-driven has no `changed[]` member gating it, or the other way about',
        );
    }

    /**
     * A16's own row, written through the set rather than through a second answer at step 7. § 11's
     * `cause` for it is *the seat-set change, recorded as the arriving seat's key*, and the row names
     * the desk that MOVED.
     */
    public function test_a_displacement_writes_a16s_row_with_section_11s_own_cause(): void
    {
        $driven = $this->shippedSet(['ops' => [[
            'op' => 'displaced', 'install_id' => 'aimla', 'seat_id' => 'aimla-pm',
            'arriving' => 'aimla/aimla-impl-4', 'at' => 9000,
        ]]]);

        $this->assertSame([], $driven['errors']);
        $this->assertCount(1, $driven['rows']);
        $this->assertSame([
            'animation_id' => 'A16', 'episode_id' => 'ep-1', 'install_id' => 'aimla',
            'seat_id' => 'aimla-pm', 'class' => 'edge', 'phase' => 'fired',
            'cause' => 'aimla/aimla-impl-4', 'motion' => true, 'at' => 9000,
        ], $driven['rows'][0]);

        // § 6.4, one class over: the same displacement under `reduce` draws A16's reduced form and
        // says so on the row.
        $reduced = $this->shippedSet(['reduce' => true, 'ops' => [[
            'op' => 'displaced', 'install_id' => 'aimla', 'seat_id' => 'aimla-pm',
            'arriving' => 'aimla/aimla-impl-4', 'at' => 9000,
        ]]]);

        $this->assertFalse($reduced['rows'][0]['motion']);
    }

    /**
     * ⛔ A1's EXCLUSION OF A13, WHICH IS A WRONG RENDER ON A REAL WIRE TRANSITION (card#7341 step 6).
     * An `offline → retired` delta LEAVES `offline` and is not an arrival: A13 removes the desk, so a
     * client firing both plays a character walking in to a desk it is deleting. § 6.2 hosts the
     * exclusion on A1 — the row that yields — as it hosts A3's exclusion of A4, and this reads the
     * document's cell AND the shipped predicate so neither can move alone.
     *
     * ⚠ A2 IS NOT IMPLICATED AND NO EXCLUSION IS STATED ON IT. Its condition is *a delta whose new
     * `render_state` is `offline`*, which `offline → retired` does not satisfy; the fourth transition
     * below is the converse control that shows A2 still fires where it should.
     */
    public function test_a_retirement_is_not_also_an_arrival(): void
    {
        $condition = $this->documentAnimationRows()['A1']['condition'];

        $this->assertStringContainsString('and not A13', $condition,
            '§ 6.2\'s A1 row no longer states its exclusion of A13 — the shipped predicate below would be stricter than the table');

        $transitions = [
            'offline->working' => ['offline', 'working', ['A1']],
            'offline->retired' => ['offline', 'retired', ['A13']],
            'working->retired' => ['working', 'retired', ['A13']],
            'working->offline' => ['working', 'offline', ['A2']],
        ];

        foreach ($transitions as $name => [$before, $after, $expected]) {
            $this->assertSame($expected, $this->rowsFiredBy($before, $after),
                "[{$name}] the § 6.2 rows this transition fires are not the ones the table predicts");
        }

        // ⛔ THE CONTROL: the exclusion removed from A1's predicate, and the double fire observed.
        // ⚠ THE ANCHOR IS THE EXCLUSION CLAUSE AND NOTHING MORE. An anchor spanning the whole of A1's
        // predicate drifts on any edit to any other part of it — measured: it did, on a whitespace
        // change during this very build — and a drifted anchor mutates nothing and then "proves" the
        // control can fail by running unmodified code. The smallest text that still expresses the
        // planted defect is the right anchor.
        $unexcluded = $this->plantedSet(' && !retiring(before, after)', '');

        $this->assertSame(['A1', 'A13'], $this->rowsFiredBy('offline', 'retired', $unexcluded),
            'the control did not bite: A1\'s exclusion of A13 was removed and a retirement still fired one row');
    }

    /**
     * ⛔ THE CONTROLS. Each rule above is planted once and seen to red — against the document, so a
     * walk that stopped reading is caught, and against the shipped module, so a guard that agrees
     * with itself is caught.
     */
    public function test_the_guard_reds_on_each_defect_it_exists_to_catch(): void
    {
        $md = $this->floorMd();

        // CONTROL 1 — a column renamed: the walk must stop answering rather than read a neighbour.
        $renamed = str_replace('| Reduced-motion form |', '| Reduced motion |', $md);
        $this->assertNotSame($md, $renamed, 'CONTROL 1 mutated nothing — § 6.2\'s column has been renamed already');
        $this->assertSame([], $this->documentAnimationRows($renamed),
            'CONTROL 1 did not bite: a § 6.2 column was renamed and the walk still answered');

        // CONTROL 2 — one row's Animation cell reworded: the words must come from the cell.
        $reworded = str_replace('`attention` — a raised hand and a marker above the desk, 4 fps loop',
            '`attention` — a raised hand and a marker above the desk, 9 fps loop', $md);
        $this->assertNotSame($md, $reworded, 'CONTROL 2 mutated nothing — A7\'s Animation cell has been reworded');
        $this->assertSame('attention — a raised hand and a marker above the desk, 9 fps loop',
            $this->documentAnimationRows($reworded)['A7']['animation'],
            'CONTROL 2 did not bite: A7\'s cell was reworded and the walk did not follow it');

        // CONTROL 3 — a row deleted from the module: the closed set must red in that direction.
        $short = $this->plantedSet("    A20: Object.freeze({ class: 'edge',", "    A20x: Object.freeze({ class: 'edge',");
        $this->assertArrayNotHasKey('A20', $this->shippedSet([], $short)['set'],
            'CONTROL 3 mutated nothing — the set\'s A20 entry is not spelled the way this plant anchors on');
        $this->assertNotSame([], array_diff(array_keys($this->documentAnimationRows()), array_keys($this->shippedSet([], $short)['set'])),
            'CONTROL 3 did not bite: a § 6.2 row lost its entry and the set-difference stayed empty');

        // CONTROL 4 — the loop test widened in the module: a static row starts claiming a loop, so
        // the derivation must red rather than follow the module.
        $looping = $this->plantedSet("return (ANIMATION_SET[animationId]?.animation ?? '').includes(`\${LOOP_FPS} fps loop`);",
            'return ANIMATION_SET[animationId] !== undefined;');
        $planted = $this->shippedSet([], $looping);
        $this->assertTrue($planted['loops']['A9'],
            'CONTROL 4 mutated nothing — `loops()` is not spelled the way this plant anchors on');
        $this->assertSame(250, $planted['frame_interval_ms']['A9'],
            'CONTROL 4 did not reach the rate: A9 claims a loop and carries no interval');

        // CONTROL 5 — § 6.4's condition removed from the one place it decides: every row would then
        // draw motion under `reduce`, which is AT-D3-13's own claim broken at its source.
        $moving = $this->plantedSet("    if (cls === null || !permitted || reduce) {\n        return false;\n    }",
            "    if (cls === null || !permitted) {\n        return false;\n    }");
        $this->assertTrue($this->shippedSet([], $moving)['held_renderings']['A3']['permitted_reduced']['motion'],
            'CONTROL 5 did not bite: § 6.4\'s condition was removed and A3 still drew no motion under `reduce`');

        // CONTROL 6 — a held row's motion derived from its LOOP rather than from its reduced cell,
        // which is the defect card#7341 step 7 corrected: A18 moves along its length and runs no
        // frames, so under this plant the line is drawn static and the log claims it was.
        $byLoop = $this->plantedSet('return cls === \'edge\' || !staticByDesign(animationId);',
            'return cls === \'edge\' || loops(animationId);');
        $planted = $this->shippedSet([], $byLoop);
        $this->assertFalse($planted['held_renderings']['A18']['permitted']['motion'],
            'CONTROL 6 mutated nothing — `motionOf` is not spelled the way this plant anchors on');
        $this->assertTrue($this->shippedSet()['held_renderings']['A18']['permitted']['motion'],
            'the shipped set draws A18 static, so § 11\'s two-states-with-no-motion population has a third member');
        $this->assertFalse($planted['held_renderings']['A8']['permitted']['motion'],
            'CONTROL 6 moved a row the correction must not move: A8 is static by design under either derivation');
    }

    /**
     * The § 6.2 rows one `render_state` transition fires, driven through the SHIPPED set over the
     * SHIPPED log. The journal entry is the shape `wire/fleet-client.js` writes for an applied delta,
     * whose end-to-end fidelity AT-D3-1 asserts by replaying four real fixtures through it; what is
     * driven directly here is the two-field predicate those replays cannot reach, because no fixture
     * at this step delivers a retirement.
     *
     * @return list<string>
     */
    private function rowsFiredBy(string $before, string $after, ?string $moduleDir = null): array
    {
        $driven = $this->shippedSet(['ops' => [[
            'op' => 'edges',
            'at' => 1000,
            'journal' => [[
                't' => 'seat.delta', 'outcome' => 'applied',
                'install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'state_version' => 7,
                'changed' => ['render_state'],
                'before' => ['render_state' => $before],
                'after' => ['render_state' => $after],
            ]],
        ]]], $moduleDir);

        $this->assertSame([], $driven['errors'], 'driving the set over one transition threw');

        return array_column($driven['rows'], 'animation_id');
    }

    /**
     * `desk/desk-render.js`'s own state→id map, against § 6.2's held conditions. It is a second
     * restatement of the same table in a second file, so it gets the same treatment as the first.
     */
    public function test_the_desk_renders_state_to_row_map_is_section_62s_held_conditions(): void
    {
        $source = $this->moduleSource('../desk/desk-render.js');
        $byState = $this->documentHeldByState();

        $this->assertNotSame([], $byState, '§ 6.2\'s held conditions named no `render_state` — the map below is unchecked');
        $this->assertSame(1, preg_match('/const HELD = Object\.freeze\(\{(.*?)\}\);/s', $source, $m),
            'desk-render.js\'s HELD map is not in the form this check reads');

        preg_match_all('/([a-z_]+):\s*\'(A\d+)\'/', $m[1], $pairs, PREG_SET_ORDER);
        $shipped = [];

        foreach ($pairs as $pair) {
            $shipped[$pair[1]] = $pair[2];
        }

        ksort($shipped);
        ksort($byState);

        $this->assertSame($byState, $shipped,
            'the desk render holds a `render_state`→§ 6.2 row map that is not the table\'s own held conditions');

        // And A4, which that map cannot hold because its condition names `working` too: the desk
        // render picks it separately, so the id it picks is checked against the document as well.
        $this->assertSame(1, preg_match('/desk === THINKING \? \'(A\d+)\'/', $source, $think),
            'desk-render.js no longer picks A4\'s row where this check reads it');

        $working = array_keys(array_filter(
            $this->documentAnimationRows(),
            static fn (array $row): bool => $row['class'] === 'held'
                && str_starts_with($row['condition'], 'render_state == "working"'),
        ));

        $this->assertSame([$byState['working'], $think[1]], $working,
            '§ 6.2\'s two `working` held rows are not the pair the desk render draws');
    }
}

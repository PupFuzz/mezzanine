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

    /** One seat's A3 episode, entered at `motion: true` against v5 — the render every pair case follows. */
    private const ENTER_A3 = [
        'op' => 'held', 'at' => 1000,
        'desks' => ['aimla/aimla-pm' => ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'held' => ['animation_id' => 'A3', 'motion' => true]]],
        'seats' => ['aimla/aimla-pm' => ['state_version' => 5]],
    ];

    /**
     * Each adjacent pair of § 11's ruled order: the render after `ENTER_A3` in which both steps apply
     * — v6 applied and held — and the `cause` the higher step gives it.
     */
    private const PRECEDENCE_PAIRS = [
        // v6 draws A6, and row 5's condition newly draws the empty chair.
        '(2a)+(3)' => [[
            'desks' => ['aimla/aimla-pm' => ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'held' => null]],
            'seats' => ['aimla/aimla-pm' => ['state_version' => 6]],
            'conditions' => ['aimla/aimla-pm' => ['prior' => ['animation_id' => 'A6', 'motion' => true], 'unconfirmed' => true, 'stilled' => false]],
        ], 6],
        // v6 keeps A3 and withholds its loop, and row 5's condition newly draws the empty chair.
        '(3)+(2b)' => [[
            'desks' => ['aimla/aimla-pm' => ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'held' => null]],
            'seats' => ['aimla/aimla-pm' => ['state_version' => 6]],
            'conditions' => ['aimla/aimla-pm' => ['prior' => ['animation_id' => 'A3', 'motion' => false], 'unconfirmed' => true, 'stilled' => false]],
        ], 'unconfirmed'],
        // v6 keeps A3 and withholds its loop, and the floor newly stills.
        '(2b)+(4)' => [[
            'desks' => ['aimla/aimla-pm' => ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'held' => ['animation_id' => 'A3', 'motion' => false]]],
            'seats' => ['aimla/aimla-pm' => ['state_version' => 6]],
            'conditions' => ['aimla/aimla-pm' => ['prior' => ['animation_id' => 'A3', 'motion' => false], 'unconfirmed' => false, 'stilled' => true]],
        ], 6],
    ];

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
     * § 6.1 rule 2, § 12's *Loop frame rate* row, and § 11's *states with no motion by design*:
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

        // § 11 names the population in words — *the **one** state with no motion by design
        // (`stalled` …)*, its count spelled as a word and its members in backticks — and it is those held
        // rows that name no loop. Both halves are read, so neither the count nor the members can drift
        // alone (card#12444 took `unknown` out of it: A9's marker pulses).
        $byState = $this->documentHeldByState();
        $motionless = array_keys(array_filter(
            $expected,
            static fn (bool $loop, string $id): bool => ! $loop && ($document[$id]['class'] ?? '') === 'held'
                && in_array($id, $byState, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame(1, preg_match('/the \*\*(one|two|three|four)\*\* states? with no motion by design \(((?:`[a-z_]+`(?:,? and |, )?)+)/', $this->floorMd(), $m),
            '§ 11\'s sentence naming the states with no motion by design is not in the form this check reads');
        $count = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4][$m[1]];
        preg_match_all('/`([a-z_]+)`/', $m[2], $members);
        $this->assertCount($count, $members[1], "§ 11 counts {$m[1]} motionless state(s) and names a different number");
        $this->assertCount($count, $motionless, "§ 11 says {$m[1]} held row(s) carry no motion by design and § 6.2's cells name a different number");
        $this->assertSame(array_map(static fn (string $state): string => $byState[$state], $members[1]), $motionless,
            '§ 11\'s motionless states are not the held rows whose § 6.2 cell names no loop');
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
            // ⛔ WHETHER A HELD ROW MOVES IS ITS REDUCED CELL AND NOT ITS LOOP (card#7341 step 7).
            // § 6.4's reduced form REPLACES the motion, so a cell reading *identical* is a row with no
            // motion to replace — § 11's *states with no motion by design*. ⛔ AND A `retired` ROW
            // NEVER MOVES (card#12239): it has no form at all, so whatever its cells say it draws
            // nothing, and the set answers *no motion* for it in every state.
            $this->assertSame(
                $row['class'] !== 'retired' && ($row['class'] === 'edge' || $row['reduced'] !== 'identical'),
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
        $this->assertSame([], array_diff(array_values($shipped['fired_by']), ['delta', 'heartbeat', 'desk', 'slot', 'coord', 'retired']),
            'FIRED_BY names a trigger class this test does not know about');

        // ⛔ `retired` IS A DEDICATED, EXPLICIT STATUS (card#12239, the seat's ruling): the rows FIRED_BY
        // calls retired are exactly the rows § 6.2's Class column calls retired — never a live row
        // excused from having a trigger, and never a retired row given one.
        $this->assertSame(
            array_keys(array_filter($document, static fn (array $row): bool => $row['class'] === 'retired')),
            array_keys(array_filter($shipped['fired_by'], static fn (string $by): bool => $by === 'retired')),
            'the rows FIRED_BY calls retired are not the rows § 6.2 calls retired',
        );

        // § 11: the two rows that belong to no seat are the two the heartbeat fires, and § 6.2's own
        // note says they are the only rows conditional on no seat's state at all.
        $this->assertSame(['A14', 'A17'], $shipped['heartbeat_rows'],
            '§ 11\'s two seatless rows are not the two the set fires on the heartbeat');
        $this->assertSame('feed.heartbeat', $shipped['heartbeat']);

        // Every `desk` row is a held row of § 6.2, and every held row is a `desk` row: a held row is
        // held by the seat object the desk model reads, whether its condition names a `render_state`
        // (A3, A4, A6, A7, A8, A9, A15 — the desk's own) or the side table (A23, card#12448, held
        // beside it under `INTERNS`'s derived key). The two populations are one, derived from the
        // table rather than paired by hand. The desk's own are held to its state→row map below.
        $deskRows = array_keys(array_filter($shipped['fired_by'], static fn (string $by): bool => $by === 'desk'));
        $fromDocument = array_keys(array_filter($document, static fn (array $row): bool => $row['class'] === 'held'));

        sort($deskRows);
        sort($fromDocument);

        $this->assertSame($fromDocument, $deskRows,
            'the rows the set says a desk holds are not § 6.2\'s held rows');

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
            'op' => 'displaced', 'install_id' => 'aimla', 'seat_id' => 'aimla-impl-2',
            'arriving' => 'aimla/aimla-mac-1', 'at' => 9000,
        ]]]);

        $this->assertSame([], $driven['errors']);
        $this->assertCount(1, $driven['rows']);
        $this->assertSame([
            'animation_id' => 'A16', 'episode_id' => 'ep-1', 'install_id' => 'aimla',
            'seat_id' => 'aimla-impl-2', 'class' => 'edge', 'phase' => 'fired',
            'cause' => 'aimla/aimla-mac-1', 'motion' => true, 'at' => 9000,
        ], $driven['rows'][0]);

        // § 6.4, one class over: the same displacement under `reduce` draws A16's reduced form and
        // says so on the row.
        $reduced = $this->shippedSet(['reduce' => true, 'ops' => [[
            'op' => 'displaced', 'install_id' => 'aimla', 'seat_id' => 'aimla-impl-2',
            'arriving' => 'aimla/aimla-mac-1', 'at' => 9000,
        ]]]);

        $this->assertFalse($reduced['rows'][0]['motion']);
    }

    /**
     * ⛔ § 11's PRECEDENCE IS A TOTAL ORDER, AND ITS ORDER IS CHECKED HERE — one direct case per
     * ADJACENT pair of the ruled order, since a total order over its steps is pinned by its adjacent
     * pairs. AT-D3-1's replayed runs name an exit by every step but hold no render in which two steps
     * apply together, so they would pass a client that asked them in another order; such renders are
     * reachable all the same, because `wire/live-page.js` coalesces a delta and the refused read that
     * crosses § 2.3 row 5's threshold, or a delta and a `401`, into one render. Each case drives one
     * render in which both steps apply and reads the `cause` the higher step gives.
     *
     * ⚠ THE PAIR (1)/(2a) HAS NO CASE, BECAUSE THE TWO NEVER APPLY TOGETHER. (1) is *the client no
     * longer holds the seat*; (2a) and every step after it ask about *the object the client now
     * holds*, and the desk floor hands `held()` a `conditions` entry only for a seat it still holds
     * (`desk/desk-floor.js`, `#conditions()`). A render that applied an object and then removed the
     * seat reaches `held()` as a removal and nothing else.
     *
     * `conditions` is what `desk/desk-floor.js` hands `held()`: `prior` is the object held now drawn
     * with row 5's condition and the stilled floor at their previous values.
     */
    public function test_the_exit_precedence_is_asked_in_its_ruled_order(): void
    {
        foreach (self::PRECEDENCE_PAIRS as $pair => [$render, $cause]) {
            $this->assertSame($cause, $this->exitCauseIn($render), "{$pair}: the exit does not name the higher step's cause");
        }

        // The probe asks for what the precedence is asked of rather than defaulting it: an episode
        // left on a seat still held, with no `conditions` for that seat, fails by name.
        $bare = self::PRECEDENCE_PAIRS['(2a)+(3)'][0];
        unset($bare['conditions']);
        $driven = $this->shippedSet(['ops' => [self::ENTER_A3, ['op' => 'held', 'at' => 2000] + $bare]]);

        $this->assertSame('MissingConditions', $driven['errors'][0]['name'] ?? null,
            'a held op that leaves a held seat\'s episode with no `conditions` did not fail by name');
    }

    /**
     * § 6.2 A23 (card#12448): the side table's held render is its OWN episode beside the desk's, keyed
     * `<seat key>~interns` in the set's episode map, on the seat's own row — so seating, stilling or
     * emptying the side table never leaves or re-enters the desk's episode, and the side table's exit asks
     * § 11's precedence of ITS prior (`prior_interns`), not the desk's.
     */
    public function test_the_side_tables_a23_is_its_own_episode_beside_the_desks(): void
    {
        $desk = static fn (?array $interns): array => ['aimla/aimla-pm' => [
            'install_id' => 'aimla', 'seat_id' => 'aimla-pm',
            'held' => ['animation_id' => 'A3', 'motion' => true],
            'interns_held' => $interns,
        ]];
        $typing = ['animation_id' => 'A23', 'motion' => true];
        $conditions = static fn (?array $priorInterns, bool $stilled = false): array => ['aimla/aimla-pm' => [
            'prior' => ['animation_id' => 'A3', 'motion' => true], 'prior_interns' => $priorInterns, 'unconfirmed' => false, 'stilled' => $stilled,
        ]];
        $render = static fn (int $at, ?array $interns, int $version, ?array $conditions = null): array => ['op' => 'held', 'at' => $at,
            'desks' => $desk($interns), 'seats' => ['aimla/aimla-pm' => ['state_version' => $version]]] + ($conditions === null ? [] : ['conditions' => $conditions]);
        $ops = [
            // v5: the desk works with an intern typing beside it — two episodes, one seat.
            $render(1000, $typing, 5),
            // v6: the subagent stopped — the side table empties and the desk works on: (2a), the side table's alone.
            $render(2000, null, 6, $conditions(null)),
            // v7: a new subagent; then the floor newly stilled while the object still draws it typing: (4).
            $render(3000, $typing, 7, $conditions($typing)),
            $render(4000, ['animation_id' => 'A23', 'motion' => false], 7, $conditions($typing, true)),
        ];

        $driven = $this->shippedSet(['ops' => $ops]);

        $this->assertSame([], $driven['errors'], 'driving the set over the side table threw');
        $this->assertSame([
            'A3 entered 5 true aimla-pm',
            'A23 entered 5 true aimla-pm',
            'A23 left 6 false aimla-pm',
            'A23 entered 7 true aimla-pm',
            'A23 left stilled false aimla-pm',
            'A23 entered 7 false aimla-pm',
        ], $this->rowLines($driven['rows']), 'the side table\'s A23 is not its own episode beside the desk\'s A3, on the seat\'s row, left by its own prior');

        // CONTROL — the side table held under the desk's own key: the two holds collide, and the run above reds.
        $planted = $this->plantedSet("Object.freeze({ suffix: INTERNS, field: 'interns_held', prior: 'prior_interns' }),",
            "Object.freeze({ suffix: '', field: 'interns_held', prior: 'prior_interns' }),");

        $this->assertNotSame($this->rowLines($driven['rows']), $this->rowLines($this->shippedSet(['ops' => $ops], $planted)['rows']),
            'CONTROL did not bite: the side table held under the desk\'s own key drew the same episodes');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function rowLines(array $rows): array
    {
        return array_map(static fn (array $r): string => "{$r['animation_id']} {$r['phase']} {$r['cause']} ".json_encode($r['motion'])." {$r['seat_id']}", $rows);
    }

    /**
     * ⛔ THE ORDER'S CONTROLS: each adjacent swap planted in `exitCause()` reds the pair case that
     * pins it. The swap of (1) and (2a) is not among them — see the order test's header.
     */
    public function test_each_adjacent_swap_of_the_precedence_reds_its_pair(): void
    {
        $step = [
            '(2a)' => "    if (prior === null || prior.animation_id !== open.animation_id) {\n        return version; // (2a)\n    }\n",
            '(3)' => "    if (unconfirmed) {\n        return UNCONFIRMED; // (3)\n    }\n",
            '(2b)' => "    if (prior.motion !== open.motion) {\n        return version; // (2b)\n    }\n",
            '(4)' => "    if (stilled) {\n        return STILLED; // (4)\n    }\n",
        ];

        foreach ([['(2a)', '(3)'], ['(3)', '(2b)'], ['(2b)', '(4)']] as [$higher, $lower]) {
            $swapped = $this->plantedSet("{$step[$higher]}\n{$step[$lower]}", "{$step[$lower]}\n{$step[$higher]}");
            [$render, $cause] = self::PRECEDENCE_PAIRS["{$higher}+{$lower}"];

            $this->assertNotSame($cause, $this->exitCauseIn($render, $swapped),
                "the swap of {$higher} and {$lower} did not red: a client asking {$lower} first still named the higher step's cause");
        }
    }

    /**
     * The `cause` of the one `left` row the render writes after `ENTER_A3`.
     *
     * @param  array<string, mixed>  $render
     */
    private function exitCauseIn(array $render, ?string $moduleDir = null): int|string|null
    {
        $driven = $this->shippedSet(['ops' => [self::ENTER_A3, ['op' => 'held', 'at' => 2000] + $render]], $moduleDir);

        $this->assertSame([], $driven['errors'], 'driving the set over a pair case threw');

        $left = array_values(array_filter($driven['rows'], static fn (array $r): bool => $r['phase'] === 'left'));

        $this->assertCount(1, $left, 'a pair case did not leave exactly the one episode it entered');

        return $left[0]['cause'];
    }

    /**
     * ⛔ A1's EXCLUSION OF A13, WHICH IS A WRONG RENDER ON A REAL WIRE TRANSITION (card#7341 step 6).
     * An `offline → retired` delta LEAVES `offline` and is not an arrival: A13 removes the desk, so a
     * client firing both plays a character walking in to a desk it is deleting. § 6.2 hosts the
     * exclusion on A1 — the row that yields — as it hosts A3's exclusion of A4, and this reads the
     * document's cell AND the shipped predicate so neither can move alone.
     *
     * ⛔ AND A2's, WHICH card#9566 STATES ON A2's ROW TOO. Since card#9566 both hold BY CONSTRUCTION:
     * A1 and A2 are keyed on § 7.1's two sides (the walk note's item 1) and `retired` is on neither, so
     * `offline → retired` and `working → retired` fire A13 alone. The plant that would break either is
     * not a deleted clause but one side read as the other's complement — the reading the walk note
     * refuses — planted below for each.
     *
     * ⚠ THE OTHER TRANSITIONS ARE THE WALK NOTE's EDGES: staffed → empty is A2 at the `stale` edge as at the
     * `offline` one, empty → staffed is A1 from either, and an edge between two values of one side —
     * `stale → offline`, `working → idle` — or into an unrecognised value fires neither.
     */
    public function test_a_retirement_is_not_also_an_arrival(): void
    {
        $rows = $this->documentAnimationRows();

        $this->assertStringContainsString('and not A13', $rows['A1']['condition'],
            '§ 6.2\'s A1 row no longer states its exclusion of A13 — the shipped predicate below would be stricter than the table');
        $this->assertStringContainsString('and not A13', $rows['A2']['condition'],
            '§ 6.2\'s A2 row no longer states its exclusion of A13');

        $transitions = [
            'offline->working' => ['offline', 'working', ['A1']],
            'stale->working' => ['stale', 'working', ['A1']],
            'offline->retired' => ['offline', 'retired', ['A13']],
            'working->retired' => ['working', 'retired', ['A13']],
            'working->offline' => ['working', 'offline', ['A2']],
            'working->stale' => ['working', 'stale', ['A2']],
            'stale->offline' => ['stale', 'offline', []],
            'offline->offline' => ['offline', 'offline', []],
            'working->idle' => ['working', 'idle', []],
            'working->unrecognised' => ['working', 'on_fire', []],
        ];

        foreach ($transitions as $name => [$before, $after, $expected]) {
            $this->assertSame($expected, $this->rowsFiredBy($before, $after),
                "[{$name}] the § 6.2 rows this transition fires are not the ones the table predicts");
        }

        // ⛔ THE CONTROLS: A1's exclusion removed, and the double fire observed; and A2's empty chair read
        // as *not staffed*, and a retirement walking out beside its A13.
        // ⚠ EACH ANCHOR IS THE SMALLEST TEXT THAT STILL EXPRESSES ITS PLANTED DEFECT. An anchor spanning a
        // whole predicate drifts on any edit to any other part of it — measured: it did, on a whitespace
        // change during card#7341's build — and a drifted anchor mutates nothing and then "proves" the
        // control can fail by running unmodified code.
        $unexcluded = $this->plantedSet('emptyChair(before.render_state) && staffed(after.render_state)', 'emptyChair(before.render_state) && !emptyChair(after.render_state)');

        $this->assertSame(['A1', 'A13'], $this->rowsFiredBy('offline', 'retired', $unexcluded),
            'the control did not bite: A1\'s staffed side was read as not empty and a retirement still fired one row');

        $complement = $this->plantedSet('staffed(before.render_state) && emptyChair(after.render_state)', 'staffed(before.render_state) && !staffed(after.render_state)');

        $this->assertSame(['A2', 'A13'], $this->rowsFiredBy('working', 'retired', $complement),
            'the control did not bite: A2\'s empty chair was read as not staffed and a retirement still fired one row');
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
        $reworded = str_replace("`attention` — a raised hand and a marker above the desk, its glow lit and dark in turn by the sleeper's pulse of brightness, phase-offset per seat, 4 fps loop",
            "`attention` — a raised hand and a marker above the desk, its glow lit and dark in turn by the sleeper's pulse of brightness, phase-offset per seat, 9 fps loop", $md);
        $this->assertNotSame($md, $reworded, 'CONTROL 2 mutated nothing — A7\'s Animation cell has been reworded');
        $this->assertSame("attention — a raised hand and a marker above the desk, its glow lit and dark in turn by the sleeper's pulse of brightness, phase-offset per seat, 9 fps loop",
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
        // A8, the row static by design (A9 was the other until card#12444 gave its marker the sleeper's pulse)
        $this->assertTrue($planted['loops']['A8'],
            'CONTROL 4 mutated nothing — `loops()` is not spelled the way this plant anchors on');
        $this->assertSame(250, $planted['frame_interval_ms']['A8'],
            'CONTROL 4 did not reach the rate: A8 claims a loop and carries no interval');

        // CONTROL 5 — § 6.4's condition removed from the one place it decides: every row would then
        // draw motion under `reduce`, which is AT-D3-13's own claim broken at its source.
        $moving = $this->plantedSet("    if (cls === null || cls === 'retired' || !permitted || reduce) {\n        return false;\n    }",
            "    if (cls === null || cls === 'retired' || !permitted) {\n        return false;\n    }");
        $this->assertTrue($this->shippedSet([], $moving)['held_renderings']['A3']['permitted_reduced']['motion'],
            'CONTROL 5 did not bite: § 6.4\'s condition was removed and A3 still drew no motion under `reduce`');

        // CONTROL 6 — a RETIRED row given motion (card#12239): `motionOf()` without its retired clause
        // derives A18's motion from cells that name no form, and the rendering assertion must red.
        $unretired = $this->plantedSet("    if (cls === null || cls === 'retired' || !permitted || reduce) {",
            '    if (cls === null || !permitted || reduce) {');
        $this->assertTrue($this->shippedSet([], $unretired)['held_renderings']['A18']['permitted']['motion'],
            'CONTROL 6 did not bite: the retired clause was removed and A18 still drew no motion');
        $this->assertFalse($this->shippedSet()['held_renderings']['A18']['permitted']['motion'],
            'the shipped set draws motion for a retired row');

        // CONTROL 7 — a retired row given a live trigger: FIRED_BY's retired set must stop matching
        // § 6.2's Class column.
        $fired = $this->shippedSet([], $this->plantedSet("    A18: 'retired',", "    A18: 'coord',"))['fired_by'];
        $this->assertSame('coord', $fired['A18'], 'CONTROL 7 mutated nothing — FIRED_BY\'s A18 entry is not spelled the way this plant anchors on');
        $this->assertNotSame(
            array_keys(array_filter($this->documentAnimationRows(), static fn (array $row): bool => $row['class'] === 'retired')),
            array_keys(array_filter($fired, static fn (string $by): bool => $by === 'retired')),
            'CONTROL 7 did not bite: A18 was given a live trigger and FIRED_BY\'s retired rows still matched § 6.2\'s',
        );

        // ⚠ The earlier CONTROL 6 (a held row's motion derived from its loop) is gone with A18's held
        // form: it was the one held row whose loop and reduced cell disagreed, so no row is left on
        // which the two derivations part company and the plant could only mutate nothing.
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

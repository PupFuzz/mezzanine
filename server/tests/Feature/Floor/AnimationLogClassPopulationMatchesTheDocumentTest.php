<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * ⛔ BOUND (v) OF `docs/design/FLOOR.md § 11`'s MODULE CONTRACT — the § 6.2 id→class table is
 * RE-DERIVED from the document by the suite, never hand-copied into the module or a test.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS WHEN THE MODULE HOLDS NO TABLE: the module writes `class` from the entry point it
 * is called through — `edge` writes `edge`, `enterHeld`/`leaveHeld` write `held` — and bound (iii)
 * forbids it from checking an `animation_id` against § 6.2. So which entry point an id belongs to
 * is a fact only the document holds, and every test that drives the log for a § 6.2 row (this
 * one and `TheAnimationLogRecordsEveryClaimBearingEpisodeTest` now; the closed-set half of AT-D3-1
 * at step 6) reads it from
 * `DrivesTheAnimationLogModule::documentAnimationClasses()` rather than from a list of its own.
 * A table edit in § 6.2 is then what moves every such test, and a stale copy has nowhere to live.
 *
 * ⚠ WHAT THIS DOES NOT CHECK: that a renderer calls the right entry point for an id. No renderer
 * exists at step 2; this asserts that the population parses, that it is the whole table, and that
 * driving each row through its class's entry point yields a row of that class.
 */
class AnimationLogClassPopulationMatchesTheDocumentTest extends TestCase
{
    use DrivesTheAnimationLogModule;

    public function test_every_section_62_row_driven_through_its_class_writes_a_row_of_that_class(): void
    {
        $classes = $this->documentAnimationClasses();

        // A CONTROL ON THE PARSER FIRST: an empty parse makes every assertion below vacuous. The
        // ids must run A1…An with no gap — a row the parse skipped is a gap — and both classes
        // must be present, because § 6.2 says the table carries two.
        $this->assertNotSame([], $classes, '§ 6.2\'s table did not parse — the population is unread');
        $this->assertSame(
            array_map(static fn (int $n): string => "A{$n}", range(1, count($classes))),
            array_keys($classes),
            '§ 6.2\'s ids did not parse as a contiguous A1…An in table order',
        );
        $seen = array_values(array_unique($classes));
        sort($seen);
        $this->assertSame(['edge', 'held'], $seen, '§ 6.2\'s Class column did not parse to both `edge` and `held`');

        $this->assertSame([], $this->classDefects($classes),
            'a § 6.2 row driven through its own class\'s entry point wrote a row of another class');
    }

    /**
     * ⛔ THE CONTROLS — the parse's bounds, the parse's reading of the Class CELL, and the module.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $md = $this->floorMd();

        // CONTROL 1 — the closing anchor renamed: the parse must stop answering, not widen.
        $moved = str_replace('### 6.3 ', '### 6.3b ', $md);
        $this->assertNotSame($md, $moved, 'CONTROL 1 mutated nothing — § 6.3\'s heading has been renamed');
        $this->assertSame([], $this->documentAnimationClasses($moved),
            'CONTROL 1 did not bite: § 6.2\'s closing anchor was renamed and the parse still answered');

        // CONTROL 2 — one row's Class CELL reworded: the derivation must follow the cell, not a
        // table of ids it could have been written against.
        $reworded = str_replace('| **A6** | `held` |', '| **A6** | `edge` |', $md);
        $this->assertNotSame($md, $reworded, 'CONTROL 2 mutated nothing — § 6.2\'s A6 row has been reworded');
        $this->assertSame('edge', $this->documentAnimationClasses($reworded)['A6'] ?? null,
            'CONTROL 2 did not bite: A6\'s Class cell was reworded to `edge` and the derivation did not follow it');

        // CONTROL 3 — the module: `edge` writes the other class.
        $defects = $this->classDefects($this->documentAnimationClasses(), $this->mutatedModules([
            'animation-log.js', "opening('edge', 'fired',", "opening('held', 'fired',",
        ]));
        $this->assertNotSame([], $defects,
            'CONTROL 3 did not bite: `edge` wrote `held` rows and every § 6.2 edge row still matched its class');
    }

    /**
     * @param  array<string, string>  $classes
     * @return array<string, string>
     */
    private function classDefects(array $classes, ?string $moduleDir = null): array
    {
        $ops = [];

        foreach ($classes as $id => $class) {
            $args = ['animation_id' => $id, 'cause' => 'v-8', 'install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'motion' => true, 'at' => 2000];

            if ($class === 'edge') {
                $ops[] = ['op' => 'edge', 'args' => $args];

                continue;
            }

            $ops[] = ['op' => 'enterHeld', 'args' => $args];
            $ops[] = ['op' => 'leaveHeld', 'episode' => ['returned_by' => count($ops) - 1], 'args' => ['cause' => 'v-9', 'at' => 3000]];
        }

        $defects = [];

        foreach ($this->drive($ops, $moduleDir)['rows'] as $row) {
            if ($row['class'] !== ($classes[$row['animation_id']] ?? null)) {
                $defects["{$row['animation_id']}/{$row['phase']}"] = "wrote class {$row['class']}, § 6.2 says "
                    .($classes[$row['animation_id']] ?? 'nothing');
            }
        }

        return $defects;
    }
}

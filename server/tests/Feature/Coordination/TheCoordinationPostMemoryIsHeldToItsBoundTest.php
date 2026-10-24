<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * **The floor's `post_ref` memory** — `docs/design/FLOOR.md § 5.7`'s *the post, drawn once* row and
 * § 12's *Remembered coordination post identities* row, built by card#12239 in place of the retired
 * envelope hold this file's predecessor guarded (the operator's ruling of 2026-10-23: nothing
 * coordination-related is held, so the one state left is which posts were already drawn).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE FIGURE IS § 12's, READ HERE, AND THE MODULE's CONSTANT IS HELD TO IT. A bound nobody
 * re-derives is a bound that drifts from the document that chose it.
 *
 * ⛔ EACH CLAUSE HAS A RED WATCHED TO FAIL: no memory at all (a repeat draws again), no bound (the
 * memory grows past § 12's figure for as long as the page stays open), and the wrong end forgotten
 * (the NEWEST post forgotten first, so a redelivery minutes later animates twice).
 *
 * ⚠ WHAT THIS DOES NOT CHECK: that the floor asks the memory before it fires an edge. That half is the
 * floor's — `test_the_floor_asks_one_memory_at_the_documents_bound` reads the wiring, and AT-D3-29's
 * duplicate leg is the floor-level replay.
 */
class TheCoordinationPostMemoryIsHeldToItsBoundTest extends TestCase
{
    use DrivesTheCoordClient;

    /** RED — no memory: every sight is a first one. */
    private const FORGETFUL = ['post-memory.js', "        if (this.#refs.has(ref)) {\n            return false;\n        }\n", ''];

    /** RED — no bound: nothing is ever forgotten. */
    private const UNBOUNDED = ['post-memory.js', 'while (this.#refs.size > this.#bound) {', 'while (false) {'];

    /** RED — the NEWEST forgotten first. */
    private const NEWEST_FIRST = ['post-memory.js', 'this.#refs.delete(this.#refs.values().next().value);', 'this.#refs.delete(ref);'];

    private function documentBound(): int
    {
        $this->assertSame(1, preg_match('/^\| Remembered coordination post identities \| \*\*([\d,]+)\*\* `post_ref`s \|/m', $this->floorMd(), $m),
            '§ 12\'s *Remembered coordination post identities* row did not parse');

        return (int) str_replace(',', '', $m[1]);
    }

    public function test_the_modules_bound_is_section_12s_figure(): void
    {
        $this->assertSame($this->documentBound(), $this->probe(['memory' => ['refs' => []]])['memory']['bound'],
            'the floor\'s post memory is not § 12\'s figure');
    }

    public function test_the_floor_asks_one_memory_at_the_documents_bound(): void
    {
        $screen = (string) file_get_contents($this->moduleDir().'/../floor/floor-screen.js');

        $this->assertSame(1, substr_count($screen, 'new PostMemory()'),
            'the floor screen does not hold exactly one post memory at the module\'s own bound');
        $this->assertSame(2, substr_count($screen, 'if (!this.#drawn.first(round.post_ref)) {'),
            'the floor screen does not ask the memory before both its install-scope and its fleet-scope edges');
    }

    public function test_green_a_repeat_inside_the_memory_draws_nothing_and_one_older_draws_again(): void
    {
        $bound = $this->documentBound();
        $this->assertSame([], $this->memoryDefects($bound));
    }

    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $bound = $this->documentBound();

        foreach (['FORGETFUL' => self::FORGETFUL, 'UNBOUNDED' => self::UNBOUNDED, 'NEWEST_FIRST' => self::NEWEST_FIRST] as $name => $plant) {
            $this->assertNotSame([], $this->memoryDefects($bound, $this->mutatedModules($plant)),
                "CONTROL {$name} did not bite: the planted memory still passed every clause");
        }
    }

    /**
     * The three clauses over one sequence: `p0` drawn, repeated at once (nothing), then `$bound` other
     * posts, then `p0` again (drawn — it was the oldest and is forgotten), then the most recent other
     * post again (nothing — it is still remembered).
     *
     * @return array<string, string>
     */
    private function memoryDefects(int $bound, ?string $moduleDir = null): array
    {
        $others = array_map(static fn (int $i): string => "synthetic-org/roundtable-fixture#9-c{$i}", range(1, $bound));
        $refs = ['synthetic-org/roundtable-fixture#9', 'synthetic-org/roundtable-fixture#9', ...$others, 'synthetic-org/roundtable-fixture#9', $others[$bound - 1]];
        $memory = $this->probe(['memory' => ['refs' => $refs]], $moduleDir)['memory'];
        $first = $memory['first'];
        $defects = [];

        if ($first[0] !== true) {
            $defects['first'] = 'a post never seen was not drawn';
        }

        if ($first[1] !== false) {
            $defects['repeat'] = 'a repeat of a post just drawn was drawn again';
        }

        if ($first[$bound + 2] !== true) {
            $defects['forgotten'] = "the oldest post was still remembered after {$bound} newer ones — the memory is not bounded at § 12's figure";
        }

        if ($first[$bound + 3] !== false) {
            $defects['recent'] = 'the most recent post was forgotten — the memory forgets the wrong end';
        }

        if ($memory['size'] > $bound) {
            $defects['size'] = "the memory holds {$memory['size']} identities, past § 12's {$bound}";
        }

        return $defects;
    }
}

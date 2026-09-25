<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **§ 11's retention bound — the constructing caller's opt-in, and nobody else's.** `docs/design/
 * FLOOR.md § 11` and § 14 item 26: `createAnimationLog(n)` keeps the most recent `n` rows written,
 * the oldest dropped first; with no argument it keeps every row, which is how the harness and every
 * acceptance test construct it. card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THREE CLAUSES, EACH WITH A RED. The window holds exactly the newest `n` rows, in call order and
 * unamended; retention drops ROWS and never the registry of open episodes, so an episode whose
 * `entered` row the window has dropped still leaves (bound (i)'s refusals are unchanged by it); and
 * a log constructed with NO bound keeps every row — the case AT-D3-1 and AT-D3-2 depend on.
 */
class TheAnimationLogKeepsItsCallersRetentionWindowTest extends TestCase
{
    use DrivesTheAnimationLogModule;

    private const WINDOW = 3;

    /** RED — the bound ignored: the window grows without limit. */
    private const IGNORED = ["        if (keep !== null && written.length > keep) {", '        if (false) {'];

    /** RED — the episode registry dropped with the rows: an episode the window lost can no longer leave. */
    private const REGISTRY_DROPPED = ["            written.splice(0, written.length - keep);\n",
        "            written.splice(0, written.length - keep);\n            open.clear();\n"];

    public function test_green_a_bounded_log_keeps_its_newest_rows_and_its_open_episodes(): void
    {
        $this->assertSame([], $this->defects($this->window()));
    }

    public function test_green_a_log_with_no_bound_keeps_every_row(): void
    {
        $out = $this->probe(['ops' => $this->ops()]);

        $this->assertCount(count($this->ops()), $out['rows'], 'a log constructed with no bound dropped a row');
    }

    public function test_red_an_ignored_bound_is_caught(): void
    {
        $this->assertArrayHasKey('window', $this->defects($this->window($this->mutatedModules(['animation-log.js', ...self::IGNORED]))));
    }

    public function test_red_a_dropped_registry_is_caught(): void
    {
        $this->assertArrayHasKey('registry', $this->defects($this->window($this->mutatedModules(['animation-log.js', ...self::REGISTRY_DROPPED]))));
    }

    /** One held entry, four edges past it (so its `entered` row leaves the window), then its exit. */
    private function ops(): array
    {
        $row = static fn (string $id, int $at): array => ['animation_id' => $id, 'cause' => 1, 'install_id' => 'i', 'seat_id' => 's', 'motion' => true, 'at' => $at];

        return [
            ['op' => 'enterHeld', 'args' => $row('A3', 1)],
            ['op' => 'edge', 'args' => $row('A12', 2)],
            ['op' => 'edge', 'args' => $row('A12', 3)],
            ['op' => 'edge', 'args' => $row('A12', 4)],
            ['op' => 'edge', 'args' => $row('A12', 5)],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 0], 'args' => ['cause' => 2, 'at' => 6]],
        ];
    }

    private function window(?string $dir = null): array
    {
        return $this->probe(['ops' => $this->ops(), 'retention' => self::WINDOW], $dir);
    }

    /** @return array<string, string> */
    private function defects(array $out): array
    {
        $defects = [];
        $leave = $out['results'][5];

        if ($leave['error'] !== null) {
            $defects['registry'] = 'the episode whose entry the window dropped could not leave: '.$leave['error']['message'];
        }

        $ats = array_column($out['rows'], 'at');
        $want = $leave['error'] === null ? [4, 5, 6] : [3, 4, 5];

        if ($ats !== $want) {
            $defects['window'] = 'the window holds rows at ['.implode(', ', $ats).'], not the newest '.self::WINDOW;
        }

        return $defects;
    }
}

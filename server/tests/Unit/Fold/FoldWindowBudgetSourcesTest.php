<?php

namespace Tests\Unit\Fold;

use App\Fold\Fold;
use App\Ingest\IngestPipeline;
use PHPUnit\Framework\TestCase;

/**
 * card#9464 — `Fold::WINDOW_BUDGET_MS` is derived from the ingest's session bounds, so it is only right
 * while it still IS that derivation: this holds the constant to its formula over `IngestPipeline`'s
 * constants, and to the figure `docs/design/FLEET-STATE.md § 12` states.
 *
 * A literal written in its place would pass every other test until the ingest's bounds moved, and then
 * leave the fold holding a seat's lock past the point a queued post gives up.
 */
class FoldWindowBudgetSourcesTest extends TestCase
{
    private const REPO = __DIR__.'/../../../..';

    public function test_the_window_budget_is_the_ingest_idle_bound_less_its_processing_target(): void
    {
        $this->assertSame(
            IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S * 1000 - IngestPipeline::PROCESSING_TARGET_MS,
            Fold::WINDOW_BUDGET_MS,
            "Fold::WINDOW_BUDGET_MS is not IngestPipeline's idle-transaction bound less its processing target",
        );
    }

    public function test_the_design_states_the_figure_the_fold_uses(): void
    {
        $path = self::REPO.'/docs/design/FLEET-STATE.md';
        $text = file_get_contents($path);
        $this->assertIsString($text, "could not read $path");

        // Lists, so a copy that vanished (`[]`) or was duplicated reds as surely as one that changed.
        $this->assertSame([Fold::WINDOW_BUDGET_MS], $this->figures($text, '/^\| Fold window budget \(`Fold::WINDOW_BUDGET_MS`\) \| ([\d,]+) ms \|/m'),
            "§ 12's fold window budget row does not state Fold::WINDOW_BUDGET_MS");
        $this->assertSame([Fold::WINDOW_BUDGET_MS], $this->figures($text, '/`Fold::WINDOW_BUDGET_MS` = \*\*([\d,]+) ms\*\*/'),
            '§ 6.5 does not state Fold::WINDOW_BUDGET_MS');
    }

    /** @return list<int> */
    private function figures(string $text, string $pattern): array
    {
        preg_match_all($pattern, $text, $m);

        return array_map(fn (string $n) => (int) str_replace(',', '', $n), $m[1]);
    }
}

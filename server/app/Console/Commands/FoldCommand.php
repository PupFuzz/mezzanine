<?php

namespace App\Console\Commands;

use App\Fold\Fold;
use Illuminate\Console\Command;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **fold** process: a long-lived supervised daemon, polling
 * at most once a second when idle.
 *
 * IF IT DIES, "states FREEZE while receipts keep arriving — the one degradation that could look
 * healthy". That is why the instrument that detects it (§ 2.3's `fold_lag_ms`) is computed from a
 * basis the INGEST also writes, and why nothing in this command maintains a health timestamp of
 * its own: a number this process wrote would freeze with this process.
 */
class FoldCommand extends Command
{
    protected $signature = 'mezzanine:fold
        {--once : run a single pass and exit — the shape the suite drives}
        {--max-passes= : stop after this many passes (diagnostics; unbounded by default)}';

    protected $description = 'Fold accepted events into seat state (docs/design/FLEET-STATE.md § 6.5)';

    public function handle(Fold $fold): int
    {
        $max = $this->option('max-passes') !== null ? (int) $this->option('max-passes') : null;
        $passes = 0;

        do {
            $applied = $fold->pass();
            $passes++;

            if ($this->option('once') || ($max !== null && $passes >= $max)) {
                break;
            }

            // § 2.1: "continuous, ≤ 1 s idle poll". Only a pass that applied NOTHING sleeps — a pass
            // that applied anything may have stopped at `Fold::BATCH` or at `Fold::WINDOW_BUDGET_MS`
            // with more waiting, and must not add a second of lag per window during a drain. A pass
            // whose every claimed seat was held by another transaction applied nothing, and sleeps.
            if ($applied === 0) {
                usleep(1_000_000);
            }
        } while (true);

        return self::SUCCESS;
    }
}

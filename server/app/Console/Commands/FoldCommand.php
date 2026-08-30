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

            // § 2.1: "continuous, ≤ 1 s idle poll". Only an EMPTY pass sleeps — a pass that applied
            // its full window has more waiting and must not add a second of lag per 500 events
            // during a drain, which is the one time the batch size binds at all.
            if ($applied === 0) {
                usleep(1_000_000);
            }
        } while (true);

        return self::SUCCESS;
    }
}

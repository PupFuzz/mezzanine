<?php

namespace App\Console\Commands;

use App\Fleet\InternSpecies;
use App\Fold\Fold;
use App\Ingest\Counters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

        $this->seedInternSpecies();

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

    /**
     * card#13258's deploy window, closed before the first claim: `bin/deploy.sh` migrates before it restarts the
     * daemons, so the previous release's worker can insert open dispatch rows after the migration's backfill and
     * allocate them nothing. This worker is the only one running (`bin/supervision.sh`'s `flock -n`) and has written
     * nothing yet, so `InternSpecies::seed()` — the migration's own backfill — is the one writer of those rows here.
     *
     * ⛔ A THROW HERE NEVER STOPS THE FOLD. The seed's rows are a rendering fact; the fold is the plane's
     * derivation, and a worker that died on its seed would freeze every seat to give a few interns a body. So it is
     * logged and counted (`intern_species_seed_failed`, § 7.2), the interns it did not reach render `null` — FLOOR.md
     * § 9 F14 — and the worker folds. A store too broken for the count to be written is one the first pass fails on
     * too.
     */
    private function seedInternSpecies(): void
    {
        try {
            $written = InternSpecies::seed();
        } catch (\Throwable $e) {
            Log::error('mezzanine.fold: the intern species seed failed, so any open intern it did not reach has no '
                .'species and renders as missing art (F14); the fold proceeds: '.$e->getMessage());
            Counters::global(InternSpecies::SEED_FAILED);

            return;
        }

        if ($written['open'] !== [] || $written['closed'] > 0) {
            Log::info(sprintf('mezzanine.fold: the intern species seed gave %d open and %d closed dispatch call(s) a species.',
                count($written['open']), $written['closed']));
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Sweep\Purge;
use Illuminate\Console\Command;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **purge** process: a scheduled command, HOURLY.
 *
 * A COMMAND AND NOT A DAEMON, deliberately: § 2.1 gives the two long-lived jobs (fold, sweep) a
 * supervisor and gives this one a schedule, because the work is bounded and the failure posture is
 * different. § 2.2: if it dies the store simply grows — "OPEN — data accumulates; alarmed at table
 * size at the stated threshold." Nothing derived goes wrong, which is why a missed hour costs
 * nothing and the retention chain carries a FOUR-DAY margin over the dedup window for exactly this
 * (§ 6.7: "the hourly job can be dead for four days before the guarantee is at risk, and a four-day
 * outage of an hourly job is visible in `purge_last_run_at` ~96 times over").
 *
 * The schedule entry is in `routes/console.php`.
 */
class PurgeCommand extends Command
{
    protected $signature = 'mezzanine:purge
        {--retention-days= : override the retention window (diagnostics; refused below the dedup window)}
        {--budget-seconds= : override the per-pass wall-clock budget (diagnostics)}';

    protected $description = 'Delete rows past retention in bounded batches (docs/design/FLEET-STATE.md § 6.7)';

    public function handle(Purge $purge): int
    {
        $days = $this->option('retention-days') !== null ? (int) $this->option('retention-days') : null;
        $budget = $this->option('budget-seconds') !== null ? (int) $this->option('budget-seconds') : null;

        // THE REFUSAL IS THE POINT AND IT IS REPORTED, NOT SWALLOWED. `Purge::guard()` raises when
        // the retention would fall below `D2-MUST` #3's 10-day dedup window; catching it here to
        // return a non-zero exit code is what makes a scheduler notice. Letting it escape as an
        // uncaught exception would work too — this shape exists so the operator reads the sentence
        // rather than a stack trace.
        try {
            $deleted = $purge->pass($days, $budget);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($deleted as $table => $rows) {
            $this->line(sprintf('%-24s %d', $table, $rows));
        }

        return self::SUCCESS;
    }
}

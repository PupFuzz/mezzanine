<?php

namespace App\Console\Commands;

use App\Feed\FeedHeartbeat;
use App\Feed\Publisher;
use App\Fold\Clock;
use App\Read\FleetHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`feed.heartbeat`**, "every **15 s**, one row fleet-wide,
 * **unconditionally**" — and § 8.3's `fleet.health` on the change half of its trigger.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS IS ITS OWN DAEMON AND NOT A JOB ON THE SWEEPER'S 15 s PASS.
 *
 * The two cadences are the same number and that is a coincidence, not a shared clock. § 2.2's
 * sweep row makes the sweeper's death a REPORTABLE CONDITION — `fleet.sweep` goes `stalled` past
 * 60 s since `sweep_last_run_at`, and a client learns that from the heartbeat. A heartbeat riding
 * the sweeper's pass would stop at exactly the moment it has news: the fleet would go silent, and
 * § 8.3's whole argument is that "a stream that has silently died is indistinguishable from a
 * fleet where nothing is happening". The instrument cannot share a process with the thing it
 * reports on — the same argument § 2.3 makes for `fold_lag_ms`'s basis, one layer out.
 *
 * ⛔ AND WHY IT SWALLOWS ITS OWN ERRORS RATHER THAN EXITING.
 *
 * A read of the store that fails builds `FleetHealth::down()` rather than exiting: where the store
 * still takes writes, that `db: "down"` reaches every open stream, and a daemon that exited on a
 * `QueryException` would be a crash loop under cron's supervision. Where the store takes no writes
 * this daemon can say nothing — see the note in `tick()` — and it keeps looping until it can.
 */
class FeedHeartbeatCommand extends Command
{
    protected $signature = 'mezzanine:feed-heartbeat
        {--once : run a single tick and exit (the scheduler and the suite both use this)}';

    protected $description = 'Publish the fleet feed heartbeat every 15 s (docs/design/FLEET-STATE.md § 8.3)';

    public function handle(): int
    {
        do {
            $this->tick();

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep(FeedHeartbeat::INTERVAL_S);
        } while (true);
    }

    private function tick(): void
    {
        try {
            $fleet = FleetHealth::build(Clock::toMs(Clock::sql(now())));
        } catch (\Throwable $e) {
            Log::error('mezzanine.feed: the store could not be read; publishing db=down', [
                'error' => $e->getMessage(),
            ]);

            $fleet = FleetHealth::down();
        }

        // ⛔ ORDER: `fleet.health` BEFORE `feed.heartbeat`, and only on the tick where the triple
        // moved — `Publisher::heartbeatTick()` writes both rows in one transaction, the change
        // first, so the news leads the routine message rather than trailing it by one interval.
        //
        // ⚠ ON A STORE FAILURE THIS WRITES NOTHING, and that is stated rather than hidden:
        // `feed_outbox` is in the store that failed (docs/design/FLEET-STATE.md § 2.1's heartbeat
        // row). A connected browser learns of a full outage from its own stream's
        // `feed.close{reason:"unavailable"}` and a connecting one from the handler's on-connect
        // `fleet.health` — never from this daemon. Where the store is readable-but-degraded the
        // insert succeeds and `db: "down"` does reach every open stream. The loop survives either way.
        try {
            Publisher::heartbeatTick($fleet);
        } catch (\Throwable $e) {
            Log::error('mezzanine.feed: could not write feed.heartbeat', ['error' => $e->getMessage()]);
        }
    }
}

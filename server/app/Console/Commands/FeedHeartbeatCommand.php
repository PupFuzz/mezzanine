<?php

namespace App\Console\Commands;

use App\Feed\FeedHeartbeat;
use App\Feed\Publisher;
use App\Fold\Clock;
use App\Read\FleetHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`feed.heartbeat`**, "every **15 s**, per channel,
 * **unconditionally**" — and § 8.3's `fleet.health` on the change half of its trigger.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS IS ITS OWN DAEMON AND NOT A JOB ON THE SWEEPER'S 15 s PASS.
 *
 * The two cadences are the same number and that is a coincidence, not a shared clock. § 2.2's
 * sweep row makes the sweeper's death a REPORTABLE CONDITION — `fleet.sweep` goes `stalled` past
 * 60 s since `sweep_last_run_at`, and a client learns that from the heartbeat. A heartbeat riding
 * the sweeper's pass would stop at exactly the moment it has news: the fleet would go silent, and
 * § 8.3's whole argument is that "a socket that has silently died is indistinguishable from a
 * fleet where nothing is happening". The instrument cannot share a process with the thing it
 * reports on — the same argument § 2.3 makes for `fold_lag_ms`'s basis, one layer out.
 *
 * ⛔ AND WHY IT SWALLOWS ITS OWN ERRORS RATHER THAN EXITING.
 *
 * With the store unreachable, § 8.2.4's object is `db: "down"` — which is the ONE message a
 * client in that posture is waiting for (§ 2.2's WebSocket-connect row: the socket "stays up
 * deliberately, because it is the channel that tells the browser *why* there is nothing"). A
 * heartbeat daemon that exited on a `QueryException` would take the messenger down with the
 * message. So a failed read publishes `FleetHealth::down()` and the loop continues.
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
        // moved. § 8.3 keeps them apart precisely so a client "would [not] learn about a store
        // outage up to 15 s late" — publishing the change first means the news leads the routine
        // message rather than trailing it by one whole interval on the tick they coincide.
        //
        // On a store failure `Publisher::healthChanged()` is reached with `FleetHealth::down()`,
        // whose `db` is `down` — so the transition INTO the outage publishes, which is the case
        // § 2.2 built the message for. `installs()` reads the store too, so this is best-effort
        // in that posture and the heartbeat below is the one that is not.
        try {
            Publisher::healthChanged($fleet);
        } catch (\Throwable $e) {
            Log::warning('mezzanine.feed: could not publish fleet.health', ['error' => $e->getMessage()]);
        }

        try {
            Publisher::heartbeat($fleet);
        } catch (\Throwable $e) {
            Log::error('mezzanine.feed: could not publish feed.heartbeat', ['error' => $e->getMessage()]);
        }
    }
}

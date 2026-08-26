<?php

namespace App\Console\Commands;

use App\Sweep\Sweep;
use Illuminate\Console\Command;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **sweep** process: a long-lived supervised daemon, every
 * **15 s**.
 *
 * IF IT DIES, "time-derived states stop advancing; a dead seat keeps rendering its last activity
 * state." § 2.2 states that posture separately from the fold's "rather than inherited because the
 * CONSEQUENCE differs: a dead fold freezes wire-driven transitions, a dead sweep freezes
 * time-driven ones, and only the second one can leave a dead seat rendering `working`."
 *
 * It is detected the same way a frozen fold is, and by the same argument (§ 2.3: "a reader can age
 * a timestamp whose writer has died, and cannot age a number"): every completed pass stamps
 * `sweep_last_run_at`, and `fleet.sweep` goes `stalled` past 60 s since it (§ 8.2.4). Nothing in
 * this command maintains a health flag of its own.
 *
 * SUPERVISION IS THE DEPLOY'S, NOT THIS FILE'S. § 2.1 requires the process to be "individually
 * restartable without losing or double-applying anything", which is a property of the pass — every
 * job is guarded on the fact it closes still being open, and the per-seat recompute is a pure
 * function of stored facts — rather than of a supervisor's configuration.
 *
 * ⛔ AND RESTARTABILITY IS NOT THE SAME PROPERTY AS SURVIVING ONE BAD SEAT. A supervisor restarts a
 * process that exits; it does not stop the process exiting again on the same seat, which is a crash
 * loop that freezes the WHOLE fleet's time-derived transitions rather than one desk's. The error
 * boundary that makes one seat's raise cost one desk lives in `Sweep::pass()`, per seat, inside the
 * loop — not here, and not in the per-seat transaction, which bounds what is written and not where
 * a throw goes. This command's job is to make the resulting partial pass VISIBLE.
 */
class SweepCommand extends Command
{
    protected $signature = 'mezzanine:sweep
        {--once : run a single pass and exit — the shape the suite drives}
        {--max-passes= : stop after this many passes (diagnostics; unbounded by default)}';

    protected $description = 'Apply the seven time-derived jobs (docs/design/FLEET-STATE.md § 2.1)';

    public function handle(Sweep $sweep): int
    {
        $max = $this->option('max-passes') !== null ? (int) $this->option('max-passes') : null;
        $passes = 0;

        do {
            $started = microtime(true);
            $result = $sweep->pass();
            $passes++;

            // A PARTIALLY-FAILING PASS IS THE ONE SHAPE THIS DAEMON CAN NOW HAVE AND COULD NOT
            // BEFORE, so it gets a surface. `Sweep::pass()` catches per seat and continues — which
            // is what stops one desk's raise from crash-looping the process and freezing every
            // seat's time-derived transitions — and the consequence is that a pass can succeed
            // overall while some desks silently did not advance. `sweep_last_run_at` cannot say
            // that: it is a liveness timestamp and the pass really did run. This line and the
            // per-seat `sweep_seat_error` counter are what make it visible instead.
            if ($result->partial()) {
                $this->error(sprintf(
                    'sweep pass %d: %d of %d seats failed and were skipped — see the log and each '
                    .'seat\'s `sweep_seat_error` counter',
                    $passes,
                    $result->failed,
                    $result->seats,
                ));
            }

            if ($this->option('once') || ($max !== null && $passes >= $max)) {
                break;
            }

            // SLEEP THE REMAINDER OF THE CADENCE, NOT A FLAT 15 s. § 2.1 derives the cadence from
            // the `stale` threshold — "a 15 s cadence bounds lateness at 15 s = 5 % of that
            // threshold" — and that bound is on the interval between PASSES. Sleeping a flat 15 s
            // after the work would make the real interval 15 s + the pass duration, so a fleet
            // whose passes take 3 s would be running an 18 s cadence and a 6 % lateness nobody
            // chose. Clamped at zero: a pass that overran its own cadence starts the next one
            // immediately rather than borrowing time from the following interval.
            $remaining = Sweep::CADENCE_S - (microtime(true) - $started);

            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        } while (true);

        return self::SUCCESS;
    }
}

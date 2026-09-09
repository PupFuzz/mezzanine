<?php

namespace App\Console\Commands;

use App\Fleet\SeatRetirement;
use App\Fleet\SeatRetirementOutcome;
use App\Support\RetirementAttribution;
use Illuminate\Console\Command;

/**
 * The operator's SHELL surface for `docs/design/FLEET-STATE.md § 2.1` / § 4.10's retirement act.
 *
 * ⚠ THE ACT ITSELF IS NOT HERE ANY MORE — it is `App\Fleet\SeatRetirement`, which owns the whole
 * of § 4.10 (the three columns, the recompute, the `cause: operator` transition row, the version
 * bump and the publish, in one transaction) and owns the argument for every line of it. It moved
 * there in card#9070 when the admin console became its second caller, so that the console and this
 * command perform the SAME act rather than two copies free to diverge. Read that class; this file
 * is argument parsing, refusal messages and an exit code.
 *
 * What stays here, because it is the SHELL's half of the contract:
 *
 * ⛔ `--by` AND `--reason` ARE REQUIRED, NOT DEFAULTED. § 4.5 calls retirement "an act with an
 * AUTHOR and a REASON", and § 4.10 puts both on the wire in the `retired` object. A default would
 * put a fabricated author on an administrative record, which is the same class of act as
 * synthesizing a wire event (§ 4.8) — the server putting words in somebody's mouth.
 * `seats.retired_by` and `retired_reason` are nullable in § 6.4 because a COLUMN cannot be
 * non-null before the act; the ACT still owes both.
 */
class RetireCommand extends Command
{
    protected $signature = 'mezzanine:retire
        {--seat= : <install>/<seat>}
        {--by= : the operator performing the retirement}
        {--reason= : why}';

    protected $description = 'Retire a seat — the shell entry point for App\\Fleet\\SeatRetirement (docs/design/FLEET-STATE.md § 4.10)';

    public function handle(SeatRetirement $retirement): int
    {
        $seat = (string) $this->option('seat');
        $by = (string) $this->option('by');
        $reason = (string) $this->option('reason');

        if (! str_contains($seat, '/')) {
            $this->error('--seat=<install>/<seat> is required');

            return self::INVALID;
        }

        // ⛔ THE PREDICATE IS `App\Support\RetirementAttribution`'s, NOT A LOCAL `=== ''`.
        // A local one is what this command had, and it disagreed with the act's `trim()` on
        // whitespace: `--by="   "` passed here and threw inside `SeatRetirement`, so an operator
        // who typed a space got a stack trace instead of this sentence. The MESSAGE stays here —
        // a shell wants the option names — and only the test is shared.
        if (RetirementAttribution::missing($by, $reason)) {
            $this->error('--by and --reason are both required: '.RetirementAttribution::MESSAGE);

            return self::INVALID;
        }

        [$installId, $seatId] = explode('/', $seat, 2);

        $result = $retirement->retire($installId, $seatId, $by, $reason);

        if ($result->outcome === SeatRetirementOutcome::NO_SUCH_SEAT) {
            $this->error('no such seat: '.$seat);

            return self::FAILURE;
        }

        if ($result->outcome === SeatRetirementOutcome::ALREADY_RETIRED) {
            $this->info(sprintf('%s is already retired (at %s) — no-op', $seat, $result->at));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'retired %s at %s (by %s) — state_version %d',
            $seat, $result->at, $by, $result->version,
        ));

        return self::SUCCESS;
    }
}

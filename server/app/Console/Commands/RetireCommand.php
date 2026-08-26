<?php

namespace App\Console\Commands;

use App\Events\SeatRetired;
use App\Fold\Clock;
use App\Fold\StateRecompute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 2.1` / § 4.10 — the **only** writer of retirement.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO TIMEOUT MAY EVER STAND IN FOR AN OPERATOR RETIREMENT. § 4.10: "A seat leaves the floor by
 * one act and one act only… Nothing else — no timeout, no purge, no silence — ever removes a row
 * from the fleet." § 2.1 states the same rule from the failure side: if this command is never run,
 * "nothing retires — WHICH IS CORRECT, because retirement is an operator act and no timeout may
 * ever stand in for one."
 *
 * ⛔ THE WHOLE ACT IN ONE TRANSACTION, AND § 4.10 NAMES IT BECAUSE THREE OF THE FOUR THINGS
 * RETIREMENT IS SUPPOSED TO PRODUCE HAD NO PRODUCER:
 *
 *   `render_state`   the sweeper WOULD reach `retired` — but "up to a sweep pass late, and writing
 *                    a transition row whose `cause` says `staleness_sweep` for a change an operator
 *                    made".
 *   `cause: operator` that § 6.4 ENUM member "had no writer at all".
 *   `seat.retired`   "nothing in this document emitted [it]" — a wire message a consumer is told to
 *                    expect and no path produces.
 *
 * So this command does the three columns, the recomputed `render_state`, the `cause: operator`
 * transition row, the `state_version` bump and the publish, in ONE transaction, and it is the only
 * producer of the last three. "The sweeper's own recompute then AGREES with it on every later pass
 * rather than racing it, because § 4.2 makes `retired` a function of `retired_at`, which by then is
 * set."
 *
 * The publish is `ShouldDispatchAfterCommit`, so "in the transaction" is literal — it is ordered by
 * the transaction — while a rollback still reaches no client. `App\Events\SeatRetired`'s docblock
 * owns that argument; it is not restated here.
 *
 * ⛔ AND WHAT IS NOT A DELETION. "Is it purged? **No.** `seats` is retained forever (§ 6.7); the 14
 * days is a READ FILTER, not a deletion, so an operator query can still find the row and its
 * reason." Nothing here deletes anything, and `Purge` has no plan row for `seats`.
 */
class RetireCommand extends Command
{
    protected $signature = 'mezzanine:retire
        {--seat= : <install>/<seat>}
        {--by= : the operator performing the retirement}
        {--reason= : why}';

    protected $description = 'Retire a seat — the only writer of retirement (docs/design/FLEET-STATE.md § 4.10)';

    public function handle(StateRecompute $recompute): int
    {
        $seat = (string) $this->option('seat');
        $by = (string) $this->option('by');
        $reason = (string) $this->option('reason');

        if (! str_contains($seat, '/')) {
            $this->error('--seat=<install>/<seat> is required');

            return self::INVALID;
        }

        // ⛔ `--by` AND `--reason` ARE REQUIRED, NOT DEFAULTED. § 4.5 calls retirement "an act with
        // an AUTHOR and a REASON", and § 4.10 puts both on the wire in the `retired` object. A
        // default would put a fabricated author on an administrative record, which is the same
        // class of act as synthesizing a wire event (§ 4.8) — the server putting words in
        // somebody's mouth. `seats.retired_by` and `retired_reason` are nullable in § 6.4 because a
        // COLUMN cannot be non-null before the act; the ACT still owes both.
        if ($by === '' || $reason === '') {
            $this->error('--by and --reason are both required: retirement is an act with an author and a reason (§ 4.5)');

            return self::INVALID;
        }

        [$installId, $seatId] = explode('/', $seat, 2);

        $row = DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $installId)
            ->where('seats.seat_id', $seatId)
            ->first(['seats.id', 'seats.retired_at']);

        if ($row === null) {
            $this->error('no such seat: '.$seat);

            return self::FAILURE;
        }

        // § 2.1: "Re-running it on an already-retired seat is a NO-OP." Not an error — an operator
        // re-running a command they are unsure landed must not be told the fleet is broken — and
        // not a second act either: a second `cause: operator` row and a second `seat.retired` at a
        // new version would tell every connected client a seat was retired twice, and would
        // OVERWRITE the original author, reason and timestamp with the re-run's. The record of who
        // retired a seat is written once.
        if ($row->retired_at !== null) {
            $this->info(sprintf('%s is already retired (at %s) — no-op', $seat, $row->retired_at));

            return self::SUCCESS;
        }

        $seatRef = (int) $row->id;
        $at = Clock::sql(now());

        $version = DB::transaction(function () use ($seatRef, $installId, $seatId, $at, $by, $reason, $recompute) {
            DB::table('seats')->where('id', $seatRef)->update([
                'retired_at' => $at,
                'retired_by' => $by,
                'retired_reason' => $reason,
            ]);

            // The recompute is the SHARED one (§ 6.5's per-writer rule names this command as one of
            // the three writers), so `render_state` collapses through § 4.2's precedence rather
            // than being assigned here — `retired` is a FUNCTION of `retired_at`, which the line
            // above just set, and writing the literal would be a second implementation of the
            // collapse free to disagree with the sweeper's.
            //
            // `owesRow: true` because the row is owed for the CAUSE and not for the render change:
            // § 4.10's whole complaint is that the sweeper's eventual row "carries
            // `cause: staleness_sweep` for a change an operator made", and AT-D2-23's third RED
            // asserts the cause value rather than the eventual `render_state` precisely because
            // "the render does converge, which is exactly why this defect is invisible from the
            // desk and has to be asserted on the wire and on the ledger."
            $recompute->forSeat(
                $seatRef,
                'operator',
                ['retired_by' => $by, 'retired_reason' => $reason],
                owesRow: true,
            );

            $version = (int) DB::table('seat_state')->where('seat_ref', $seatRef)->value('state_version');

            // IN THE TRANSACTION, WHICH IS WHERE § 4.10 PUTS IT — and delivered only if that
            // transaction commits, because `SeatRetired` is `ShouldDispatchAfterCommit`. That
            // contract is the whole resolution: the publish is ordered by the same act that sets
            // the columns, so no crash can land one without the other, and a rollback invokes no
            // listener, so no client is ever told a seat retired when it did not. `SeatRetired`'s
            // own docblock carries the argument; a rollback arm in the suite drives it.
            //
            // ⚠ AN EARLIER REVISION PUBLISHED HERE FROM OUTSIDE THE TRANSACTION AND ITS COMMENT
            // SAID THE DEPARTURE WAS "FLAGGED IN THE PR BODY". IT WAS NOT FLAGGED ANYWHERE. The
            // departure is now gone rather than better-disclosed, but the false pointer is recorded
            // because it is the more dangerous half: a reviewer who reads "flagged" stops looking.
            SeatRetired::dispatch($seatRef, $installId, $seatId, $at, $by, $reason, $version);

            return $version;
        });

        $this->info(sprintf('retired %s at %s (by %s) — state_version %d', $seat, $at, $by, $version));

        return self::SUCCESS;
    }
}

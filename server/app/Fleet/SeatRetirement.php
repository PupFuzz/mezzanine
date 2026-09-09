<?php

namespace App\Fleet;

use App\Events\SeatRetired;
use App\Fold\Clock;
use App\Fold\SeatFacts;
use App\Fold\StateRecompute;
use App\Support\RetirementAttribution;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 2.1` / § 4.10 — the **only** writer of retirement.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ EXTRACTED FROM `App\Console\Commands\RetireCommand` AT THE SECOND CALLER (card#9070), WHICH IS
 * THE ADMIN CONSOLE'S AGENT MODULE. It is an extraction and not a re-implementation: the console
 * must perform THE SAME ACT, and the one thing that must never exist is a second copy of the
 * transaction below — a console that set the three columns without the recompute, the
 * `cause: operator` row and the publish would retire a seat that stays on every connected floor
 * until a sweep pass, which is the precise defect § 4.10 was written against.
 *
 * ⛔ NO TIMEOUT MAY EVER STAND IN FOR AN OPERATOR RETIREMENT. § 4.10: "A seat leaves the floor by
 * one act and one act only… Nothing else — no timeout, no purge, no silence — ever removes a row
 * from the fleet." § 2.1 states the same rule from the failure side: if this is never run,
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
 * So this does the three columns, the recomputed `render_state`, the `cause: operator` transition
 * row, the `state_version` bump and the publish, in ONE transaction, and it is the only producer
 * of the last three. "The sweeper's own recompute then AGREES with it on every later pass rather
 * than racing it, because § 4.2 makes `retired` a function of `retired_at`, which by then is set."
 *
 * The publish is `ShouldDispatchAfterCommit`, so "in the transaction" is literal — it is ordered by
 * the transaction — while a rollback still reaches no client. `App\Events\SeatRetired`'s docblock
 * owns that argument; it is not restated here.
 *
 * ⛔ AND WHAT IS NOT A DELETION. "Is it purged? **No.** `seats` is retained forever (§ 6.7); the 14
 * days is a READ FILTER, not a deletion, so an operator query can still find the row and its
 * reason." Nothing here deletes anything, and `Purge` has no plan row for `seats`.
 *
 * ⛔ `$by` AND `$reason` ARE THIS ACT'S OBLIGATION, HELD HERE, AND ARE NOT DEFAULTED ANYWHERE.
 * § 4.5 calls retirement "an act with an AUTHOR and a REASON", and § 4.10 puts both on the wire in
 * the `retired` object. A default would put a fabricated author on an administrative record, which
 * is the same class of act as synthesizing a wire event (§ 4.8) — the server putting words in
 * somebody's mouth.
 *
 * ⚠ THE REFUSAL MOVED HERE IN CARD#9070's FIRST REVIEW ROUND, and the reason is this class's own
 * argument turned on itself: the extraction exists so there is ONE implementation of the act rather
 * than one per caller, and it had left the act's own precondition duplicated in both callers.
 *
 * ⛔ AND THAT MOVE WAS ONE LEVEL SHORT, WHICH THE SECOND REVIEW ROUND MEASURED. This class kept
 * writing the predicate out by hand (`trim($by) === ''`) and so did the command
 * (`$by === ''`), so the two disagreed on whitespace: `mezzanine:retire --by="   "` passed the
 * command's check and reached this `throw`, handing the operator a stack trace where the
 * documented answer is `INVALID`. An earlier revision of this paragraph asserted the opposite —
 * "each still refuses first, with a message better than an exception" — and that claim is recorded
 * here rather than deleted, because a docblock that states a property nothing holds is the reason
 * a reviewer stops looking. The predicate now lives once, in
 * `App\Support\RetirementAttribution`, and every caller and both acts read THAT; what each caller
 * keeps is its own refusal message, which is genuinely better than an exception.
 *
 * ⛔ AND THE THIRD REVIEW ROUND FOUND THE SAME SHAPE IN THE ACT ITSELF. The § 2.1 no-op was
 * decided on a `first()` taken before the transaction opened, with no lock, and the UPDATE was
 * keyed on `id` alone — so two retirements of one seat both read `retired_at === null`, both
 * passed the guard and both wrote, which is the second act the guard exists to prevent. The guard
 * now lives IN the UPDATE; `retire()` carries the argument at the site, including why it is that
 * shape and not the locked re-read `App\Admin\UserRetirement` uses.
 */
final class SeatRetirement
{
    public function __construct(private readonly StateRecompute $recompute) {}

    /**
     * @throws \InvalidArgumentException if the author or the reason is empty
     */
    public function retire(string $installId, string $seatId, string $by, string $reason): SeatRetirementOutcome
    {
        RetirementAttribution::demand($by, $reason);

        $row = DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $installId)
            ->where('seats.seat_id', $seatId)
            ->first(['seats.id']);

        if ($row === null) {
            return SeatRetirementOutcome::noSuchSeat();
        }

        // ⚠ THIS READ RESOLVES `<install>/<seat>` TO A ROW AND ANSWERS NOTHING ELSE. It used to
        // decide the § 2.1 no-op as well, and that is the defect the third review round measured:
        // whether the seat is already retired is a question about the state at the moment of the
        // WRITE, so it is asked there — see the UPDATE below. A row that exists here cannot stop
        // existing (§ 6.7 retains `seats` forever and nothing deletes one), so this answer does
        // not go stale in the only direction it is used.
        $seatRef = (int) $row->id;
        $at = Clock::sql(now());

        return DB::transaction(function () use ($seatRef, $installId, $seatId, $at, $by, $reason): SeatRetirementOutcome {
            // ⛔ SAMPLED BEFORE THE `seats` WRITE BELOW — card #7837, and this is a SIBLING of that
            // card's fold defect rather than a precaution.
            //
            // `retired` is a version-bearing member and it reads `seats.retired_at`,
            // `retired_by` and `retired_reason` (`SeatFacts::versionBearing()`) — the three
            // columns the UPDATE below sets. `forSeat()` used to sample its own `$before` on its
            // first line, which is AFTER that UPDATE, so the two fingerprints agreed on `retired`
            // and § 8.3's patch never carried it. The delta was still emitted (`render_state`
            // collapses to `retired`), so a connected client learned the seat's render moved while
            // its `retired` object stayed `null` — § 8.2.1's own member for who retired it, when
            // and why, permanently absent until a resync.
            //
            // The UPDATE cannot move below the recompute instead: `render_state` is DERIVED from
            // `retired_at`, so a recompute run first would derive the un-retired render.
            $before = SeatFacts::versionBearing($seatRef);

            // § 2.1: "Re-running it on an already-retired seat is a NO-OP." Not an error — an
            // operator re-running a command they are unsure landed must not be told the fleet is
            // broken — and not a second act either: a second `cause: operator` row and a second
            // `seat.retired` at a new version would tell every connected client a seat was retired
            // twice, and would OVERWRITE the original author, reason and timestamp with the
            // re-run's. The record of who retired a seat is written once.
            //
            // ⛔ SO THE NO-OP IS DECIDED BY THIS WRITE AND NOT BY A READ TAKEN BEFORE IT —
            // card#9070's THIRD review round. Until it, the test was `if ($row->retired_at !==
            // null)` against a `first()` taken outside the transaction, and the UPDATE was keyed on
            // `id` alone: two retirements of one seat both read `null`, both passed, both wrote,
            // and the paragraph above describes what the second one did. A double-clicked console
            // button reaches it. `App\Admin\UserRetirement` states the principle in this same
            // card — "deciding a no-op on a stale read is how a second act gets written" — and this
            // path had not got it.
            //
            // ⚠ IN THE UPDATE RATHER THAN A LOCKED RE-READ, WHICH IS THE OTHER SHAPE THIS REPO
            // HAS (`App\Admin\UserRetirement::retire()`), because this one's correctness does not
            // depend on the store honouring `lockForUpdate()`:
            // `App\Console\Commands\RevokeFeedToken` revokes exactly this way. `retired_at` is
            // NULL in the predicate and non-NULL in the SET, so a matched row always changes and
            // the affected count is a true answer to "did THIS act write it".
            $written = DB::table('seats')
                ->where('id', $seatRef)
                ->whereNull('retired_at')
                ->update([
                    'retired_at' => $at,
                    'retired_by' => $by,
                    'retired_reason' => $reason,
                ]);

            if ($written === 0) {
                // Another retirement got there first. Nothing below this return runs: no
                // recompute, no `cause: operator` row, no version bump, and no second
                // `seat.retired` on the wire.
                //
                // ⚠ `lockForUpdate()` HERE IS NOT THE GUARD — IT IS WHAT MAKES THIS READ SEE THE
                // OTHER ACT. Both callers print this timestamp ("was already retired (at %s)"),
                // and under the REPEATABLE READ this deploys on the transaction's snapshot was
                // already taken by the `versionBearing()` read above, so a plain SELECT here would
                // hand back the `null` this transaction started with and print an empty time. A
                // locking read is a current read. The value cannot then go stale: nothing in this
                // application un-retires a seat or rewrites those three columns.
                $already = DB::table('seats')
                    ->where('id', $seatRef)
                    ->lockForUpdate()
                    ->value('retired_at');

                return SeatRetirementOutcome::alreadyRetired((string) $already);
            }

            // The recompute is the SHARED one (§ 6.5's per-writer rule names this act as one of
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
            $this->recompute->forSeat(
                $seatRef,
                $before,
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

            return SeatRetirementOutcome::retired($at, $version);
        });
    }
}

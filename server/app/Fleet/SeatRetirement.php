<?php

namespace App\Fleet;

use App\Feed\Outbox;
use App\Feed\SeatRetired;
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
 * The publish is an outbox row enqueued inside `App\Feed\Outbox::transaction()`, which inserts it
 * as the transaction's LAST statement (card#9300) — so "in the transaction" is literal, and a
 * rollback leaves no row for any client to read. `App\Feed\SeatRetired`'s docblock owns that
 * argument; it is not restated here.
 *
 * ⛔ AND WHAT IS NOT A DELETION. "Is it purged? **No.** `seats` is retained forever (§ 6.7); the
 * disappearance is a READ FILTER, not a deletion, so an operator query can still find the row and
 * its reason." The `seats` row survives, every ledger row survives, and `Purge` has no plan row
 * for `seats`. ⚠ **This paragraph used to say "nothing here deletes anything", and since
 * card#7582 that is no longer literally true**: the act deletes the seat's `seat_board_task` row
 * and nulls `seats.board_user_id` (§ 4.10, § 6.7), which is a JOIN KEY into a live board rather
 * than a record of anything. The old sentence is recorded rather than quietly replaced, because
 * the property it was protecting — the retirement RECORD is never deleted — is the one a reader
 * must still be able to rely on, and the way to keep relying on it is to know which clause moved.
 *
 * ⛔ WHAT card#9078's OPERATOR RULING CHANGED, AND WHAT IT DID NOT TOUCH IN THIS CLASS. The desk
 * now goes at `retired_at` instead of fourteen days later — a change to `App\Read\RetirementFilter`
 * and to nothing here. THE ACT IS UNCHANGED: the same three columns, the same recompute, the same
 * `cause: operator` row, the same version bump, the same two publishes, in the same transaction.
 * That is the point of the ruling rather than an accident of it — "retirement is an ANNOUNCEMENT,
 * not an inference", and this class is the announcement. `seat.retired` is what a client removes a
 * desk ON; a seat's absence from a delta, a poll or a scoped read still removes nothing, ever.
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
    /**
     * The lock wait, in seconds, pinned on THIS act's own session for the length of its
     * transaction — card#9466.
     *
     * Both callers have a person waiting: `SeatController::retire()` answers an operator's browser
     * and `RetireCommand` an operator's shell. At the server default of 50 s a retirement queued
     * behind a busy seat would hold that person for minutes before it said anything, and a proxy in
     * front of the web request may give up first while PHP still commits the act behind it. 2 s is
     * far above the few milliseconds a fold window holds its rows for in the ordinary case (§ 6.5),
     * so an ordinary collision is absorbed by the wait and never raises. It is pinned the way
     * `IngestPipeline::boundTheWriteSession()` pins the ingest's: `SET SESSION`, never `SET GLOBAL`.
     *
     * THE BOUND IS PER BLOCKED STATEMENT, NOT PER ATTEMPT: at most `LOCK_WAIT_TIMEOUT_S` for any one
     * statement, across `LOCK_ATTEMPTS` attempts. The typical contention case — the seat lock is held
     * and released — costs at most `LOCK_ATTEMPTS` × `LOCK_WAIT_TIMEOUT_S`. Once this act holds the
     * seat lock, only a writer that does not lock the seat first can still hold a row it touches. A
     * fold window is such a writer today, and it is bounded by `Fold::BATCH` events rather than by a clock, so retiring a seat
     * whose fold is draining a backlog can exhaust the attempts: the callers then answer "busy — try
     * again", and nothing was changed.
     */
    public const LOCK_WAIT_TIMEOUT_S = 2;

    /**
     * Attempts at the whole transaction when it throws a concurrency error (`1020`/`1205`/`1213`):
     * the count includes the first attempt. A real `1213` is broken by MariaDB's deadlock detector in
     * milliseconds, so its retry is nearly free; a `1205` retry pays off against a holder that
     * releases inside the next attempt's wait. `RebuildCommand::REPLAY_LOCK_ATTEMPTS` is the same
     * count at the server's default wait.
     */
    public const LOCK_ATTEMPTS = 3;

    /** Test seam: runs inside the transaction, before the seat lock, once per attempt. */
    public static $beforeRetire = null;

    /** Test seam: runs right after `$before` is sampled. */
    public static $afterBefore = null;

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

        // THE SHORT WAIT IS RESTORED ON EVERY EXIT, not pinned and left: the `finally` covers the
        // retired return, the already-retired return (both return from inside the callback) and a
        // throw once the attempts are spent, so nothing run later on this connection inherits it.
        // The value restored is read from this session rather than assumed to be the server's.
        $wait = (int) DB::selectOne('SELECT @@session.innodb_lock_wait_timeout AS v')->v;
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT_S);

        try {
            return $this->act($seatRef, $installId, $seatId, $at, $by, $reason);
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$wait);
        }
    }

    private function act(int $seatRef, string $installId, string $seatId, string $at, string $by, string $reason): SeatRetirementOutcome
    {
        return Outbox::transaction(function () use ($seatRef, $installId, $seatId, $at, $by, $reason): SeatRetirementOutcome {
            if (self::$beforeRetire !== null) {
                (self::$beforeRetire)($seatRef);
            }

            // ⛔ THE SEAT'S `seat_state` ROW LOCK FIRST, BEFORE `$before` IS SAMPLED — card#9466,
            // § 6.5's lock-first rule. Without it, a writer that commits to this seat between the
            // sample and the recompute's own `seat_state` write turns that write into a `1020`
            // ("Record has changed since last read"), and a writer that holds the row and then
            // needs one this act already holds closes a deadlock.
            DB::table('seat_state')->where('seat_ref', $seatRef)->lockForUpdate()->value('seat_ref');

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

            if (self::$afterBefore !== null) {
                (self::$afterBefore)($seatRef);
            }

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
                //
                // Since card#9466 the seat lock at the top of this transaction serialises two
                // retirements of one seat, so the second one's snapshot is taken after the first
                // committed and a plain read would see the time as well. The locking read stays, so
                // that this answer does not rest on every retirement taking that lock first.
                $already = DB::table('seats')
                    ->where('id', $seatRef)
                    ->lockForUpdate()
                    ->value('retired_at');

                return SeatRetirementOutcome::alreadyRetired((string) $already);
            }

            // ⛔ THE BOARD-USER MAPPING GOES WITH THE SEAT, IN THIS TRANSACTION — § 4.10 and
            // § 6.7, ratified on card#7582 (2026-09-12). § 6.7 states the invariant this holds up:
            // a `seat_board_task` row "leaves in exactly two ways", any write of
            // `seats.board_user_id` and the seat's retirement, and until this the SECOND WAY HAD
            // NO WRITER — the sentence was true of nothing.
            //
            // ⚠ AND THE COST OF LEAVING IT IS NOT TIDINESS. `board_user_id` is UNIQUE (§ 6.4), so
            // a retired seat that keeps it holds that board user against the whole fleet: the
            // replacement seat cannot be mapped to the same person until an operator runs
            // `mezzanine:seat-board-user --clear` on a seat that has left every read surface, and
            // the only symptom is that command's bare non-zero exit. The alternative resolution —
            // make the sentence true by declaring retirement leaves the row in place — was put to
            // the operator and refused for exactly that reason.
            //
            // ⛔ IT IS NOT A DELETION OF ANYTHING THE RECORD NEEDS, which is the one thing § 4.10
            // is emphatic about ("the disappearance is a READ FILTER, not a deletion"). The three
            // retirement columns and every ledger row are untouched; what goes is a JOIN KEY into
            // a live board, which is the thing a retired seat must stop holding. `seat_board_task`
            // is an INPUT (§ 6.4) and not a projection, so nothing derives a fact from its absence
            // — the poll that would rewrite it skips retired seats anyway (BOARD-TASK.md § 7.2).
            //
            // NOT FOLDED INTO THE GUARDED UPDATE ABOVE, deliberately: that UPDATE's affected count
            // IS the § 2.1 no-op decision ("Re-running it on an already-retired seat is a no-op"),
            // and widening its SET list would leave the decision reading the same while a second
            // act's worth of columns rode along with it. This runs only on the branch that wrote.
            DB::table('seats')->where('id', $seatRef)->update(['board_user_id' => null]);
            DB::table('seat_board_task')->where('seat_ref', $seatRef)->delete();

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

            // IN THE TRANSACTION, WHICH IS WHERE § 4.10 PUTS IT — enqueued here and inserted as the
            // transaction's LAST statement by `Outbox::transaction()` (card#9300), after the delta
            // `forSeat()` above enqueued, at the one version both carry. A rollback leaves neither row.
            Outbox::enqueue(new SeatRetired($seatRef, $installId, $seatId, $at, $by, $reason, $version));

            return SeatRetirementOutcome::retired($at, $version);
        }, self::LOCK_ATTEMPTS);
    }
}

<?php

namespace App\Fold;

use App\Feed\Outbox;
use App\Ingest\Counters;
use App\Ingest\IngestPipeline;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 6.5` — one transaction per pass, per seat.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THE CURSOR NEEDS, AND WHAT BUYS IT — which is not gaplessness, and not a clock (card#9398).
 *
 * `events.id` is not gapless: InnoDB burns an `AUTO_INCREMENT` value on a rolled-back transaction,
 * and interleaves values across concurrent statements to a degree `innodb_autoinc_lock_mode` decides
 * (MariaDB's default for it is verified at provisioning, § 6.1). A cursor does not care about holes.
 * It needs NO ROW WITH `id <= cursor` TO BECOME VISIBLE AFTER THE CURSOR HAS PASSED IT — and ids are
 * assigned at INSERT while rows become visible at COMMIT, so that needs, for one seat, id-assignment
 * order and commit order to be the same order.
 *
 * The ingest makes them so. `BatchWriter::write()` takes the seat's `seat_state` row lock as its
 * transaction's first statement and is the only inserter into `events` (`EventsHaveOneWriterTest`
 * checks that), so a second same-seat write cannot be assigned an id until the first has committed
 * or rolled back; and an allocated `AUTO_INCREMENT` value is never issued again. Every uncommitted
 * id for a seat is therefore above
 * every committed one, and a plain `id > cursor ORDER BY id` read — any read view, locked or not —
 * sees a prefix of the seat's ids. No time comparison enters it, so clock skew and a clock stepping
 * backwards change nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * PER-SEAT CURSORS, NOT ONE GLOBAL CURSOR. A single global cursor makes one unprojectable event
 * freeze the entire fleet's derivation — the "one bad batch wedges the stream" shape D1 refuses in
 * the spool. Per seat, a poison event costs one desk, and that desk says so (`derivation_error`).
 */
final class Fold
{
    /** § 6.5, derived: ~2.5 batches at D1's 200-event cap, so a pass consumes a real seat's arrivals. */
    public const BATCH = 500;

    /** § 6.5, derived: keeps one worker's transaction footprint small enough to add a second worker. */
    public const CLAIM = 8;

    /**
     * § 6.5, derived (card#9464): how long one window may hold its seat's `seat_state` lock, alongside
     * `BATCH` — a window stops at whichever it reaches first.
     *
     *     = IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S × 1000 − IngestPipeline::PROCESSING_TARGET_MS
     *
     * The ingest's own arithmetic already assumes every unit of server-store work completes inside
     * `PROCESSING_TARGET_MS`. A window that stops with that much of the idle bound still unspent — for
     * its commit tail and the one event that may still be in flight when the check fires (`window()`
     * checks after each applied event, never mid-event) — releases the lock before a post that queued
     * at the window's lock grant would give up. If `PROCESSING_TARGET_MS` is false under real drain
     * I/O, the ingest misses its own deadline whatever the fold does; a drain run with no concurrent
     * ingest can falsify that assumption and cannot confirm it.
     *
     * Derived from `IngestPipeline`'s constants by name, so a change there re-flows here;
     * `FoldWindowBudgetSourcesTest` holds this formula and § 12's row to them.
     */
    public const WINDOW_BUDGET_MS = (IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S * 1000)
        - IngestPipeline::PROCESSING_TARGET_MS;

    /**
     * Test seam (card#9464): invoked at the top of `lockSeatOrYield()`, before the lock statement, so
     * a test can hold the seat's row from another connection at exactly one of the fold's lock
     * attempts — which is the only way to make a recovery or quarantine transaction find the seat
     * contended while the claim that selected it did not.
     *
     * @var null|callable(int): void
     */
    public static $beforeLock = null;

    /**
     * Test seam (card#9464): invoked in `window()` right after `readable()`, before the first
     * `$before` sample. A write another connection commits from here is the write a stale `$before`
     * would miss; with the seat lock taken first it cannot commit at all.
     *
     * @var null|callable(int): void
     */
    public static $afterReadable = null;

    /**
     * Test seam for AT-D2-22's purged-window arm: invoked between the emptiness proof and the
     * guarded cursor write, which is the only window in which an interleaved ingest commit can
     * change the answer. It exists because that interleaving is the branch's entire hazard and a
     * branch tested only in the quiet case is a branch tested where it cannot fail.
     *
     * @var null|callable(int): void
     */
    public static $afterEmptinessProof = null;

    public function __construct(
        private readonly Projector $projector = new Projector,
        private readonly StateRecompute $recompute = new StateRecompute,
        private readonly int $windowBudgetMs = self::WINDOW_BUDGET_MS,
        private readonly WindowClock $clock = new WindowClock,
    ) {}

    /**
     * One pass over the claim. Returns the number of events applied.
     */
    public function pass(): int
    {
        $applied = 0;

        foreach ($this->claim() as $seat) {
            $applied += $this->foldSeat((int) $seat->seat_ref, (int) $seat->fold_cursor_event_id);
        }

        return $applied;
    }

    /**
     * § 6.5's claim: the seats with unfolded events, FURTHEST BEHIND FIRST.
     *
     * `ORDER BY fold_cursor_received_at ASC` and not by a stored lag, because there is no stored
     * lag (§ 2.3 — a lag whose only writer is the fold freezes with the thing it detects). The
     * column is never NULL for a seat this WHERE clause can select: it is NULL only for a seat
     * that has never received an event, and such a seat has `head_event_id = 0`.
     *
     * ⚠ `FOR UPDATE SKIP LOCKED` IS THE FOLD'S CONCURRENCY CORRECTNESS, AND THE SUITE EXERCISES
     * ONE ARM OF IT. It is what makes two fold workers partition themselves — another worker's
     * seats are skipped rather than waited on. The one skip of the claim's that the suite drives is
     * `At22LockFirstIngestTest`'s: a fold pass on its own connection skips the seat an open ingest
     * transaction holds. Two FOLD
     * workers partitioning the claim is UNTESTED, not merely untested-here (card#7523 owns it).
     *
     * @return Collection<int, object>
     */
    private function claim(): Collection
    {
        return DB::table('seat_state')
            ->select(['seat_ref', 'fold_cursor_event_id'])
            ->whereColumn('fold_cursor_event_id', '<', 'head_event_id')
            ->orderBy('fold_cursor_received_at')
            ->limit(self::CLAIM)
            ->lock('for update skip locked')
            ->get();
    }

    /**
     * The seat's `seat_state` row lock, taken as the FIRST statement of every fold transaction that
     * samples `$before` — the window, a one-event recovery attempt and the quarantine (card#9464).
     *
     * ⛔ THIS IS WHAT MAKES `$before` INDEPENDENT OF HOW THE STORE IS CONFIGURED. `Outbox::transaction()`
     * issues nothing before its closure, and `START TRANSACTION` reads nothing; a locking read is not a
     * consistent read, so the transaction's snapshot is fixed by its first PLAIN read, which runs after
     * this lock is granted. Every `$before` therefore sees every write committed before the grant, and
     * no same-seat writer that takes this lock can commit until this transaction ends — under either
     * setting of `innodb_snapshot_isolation` and under REPEATABLE READ or READ COMMITTED
     * (`FoldLockFirstTest` drives each combination). A plain read placed first instead fixes the
     * snapshot before the lock, and whether a write landing in between is then refused (`1020`),
     * silently missed, or read fresh depends on exactly those settings.
     *
     * `FOR UPDATE SKIP LOCKED`, the claim's own idiom above: a contended seat yields at once with a
     * clean miss rather than waiting out a lock timeout, because the fold has no wait budget to spend.
     * The claim already proved the row exists, so a miss can only mean another transaction holds it.
     *
     * @return bool false: another transaction holds the seat, and this transaction must write nothing
     */
    private function lockSeatOrYield(int $seatRef): bool
    {
        if (self::$beforeLock !== null) {
            (self::$beforeLock)($seatRef);
        }

        return DB::table('seat_state')
            ->where('seat_ref', $seatRef)
            ->lock('for update skip locked')
            ->value('seat_ref') !== null;
    }

    private function foldSeat(int $seatRef, int $cursor): int
    {
        try {
            return Outbox::transaction(fn () => $this->window($seatRef, $cursor));
        } catch (CursorRaced) {
            // Another worker folded this seat's window while this pass was working on it. The
            // transaction rolled back, so nothing was applied twice — the projections are
            // idempotent but the COUNTERS are not, which is exactly what the guard protects.
            return 0;
        } catch (\Throwable $e) {
            if ($this->contended($e)) {
                // Lock contention, not a poison event — the transaction rolled back and nothing
                // was applied. Yield the seat for this pass, as a lost cursor race does.
                return 0;
            }

            // § 6.5's POISON-EVENT RULE, first half: "if `project()` raises, the transaction is
            // rolled back, the event is retried alone once". Re-running the window one event at a
            // time is what isolates the offender — the whole-window attempt cannot say which event
            // raised, and quarantining the window would discard its innocent neighbours.
            return $this->recoverOneAtATime($seatRef, $cursor);
        }
    }

    /**
     * The window of one pass, inside one transaction. § 6.5: "the cursor advance is in the same
     * transaction as the projections, so a crash mid-pass rolls back both — an event is applied
     * exactly once."
     *
     * ⛔ ITS FIRST STATEMENT IS THE SEAT LOCK (`lockSeatOrYield()`, which argues why), before
     * `readable()` and before any `$before` sample. A contended seat yields the pass having written
     * nothing — the same outcome as a lost claim race — and a seat that keeps yielding shows as
     * `fold_lag`, the fold's one surface.
     *
     * BOUNDED BY `BATCH` OR `WINDOW_BUDGET_MS`, WHICHEVER IS REACHED FIRST. The budget clock starts at
     * the lock grant, when the hold begins, and is checked after each applied event and never before
     * the first, so every window makes progress. A stopped window advances the cursor to the last
     * APPLIED row and never to the last row read: ids are a prefix (the class docblock), so no later
     * window re-selects an id at or below the cursor, and advancing past an unapplied tail would
     * strand it.
     *
     * LOCK ORDER. The window takes `seat_state` before any other row of the seat's, and so does every
     * transaction that samples `SeatFacts::versionBearing()` (`git grep -n "versionBearing(" --
     * server/app` lists them; § 6.5 states the rule), so none of them holds a row of this seat's while
     * it is still to take `seat_state`. This window takes the lock `SKIP LOCKED` and never waits for
     * it. A row written outside those transactions can still raise a concurrency error here, and the
     * pass yields through `contended()`. § 6.5 names the writers outside the lock, among them
     * `Predicates::alarm()`, which updates the `seat_predicates` rows this window upserts.
     */
    private function window(int $seatRef, int $cursor): int
    {
        if (! $this->lockSeatOrYield($seatRef)) {
            return 0;
        }

        $windowStartedMs = $this->clock->nowMs();

        $rows = $this->readable($seatRef, $cursor);

        if (self::$afterReadable !== null) {
            (self::$afterReadable)($seatRef);
        }

        if ($rows->isEmpty()) {
            $this->emptyWindow($seatRef, $cursor);

            return 0;
        }

        $last = null;
        $applied = 0;

        foreach ($rows as $row) {
            $event = FoldEvent::fromRow($row);

            // ⛔ SAMPLED BEFORE `apply()`, AND PER EVENT — card #7837. See
            // `StateRecompute::after()` for the defect: the projector writes `enabled`,
            // `context.*`, `model_label`, `selftest_failed`, the reporter badge array and the
            // `calls` rows behind `subagents`, so a fingerprint sampled after it is identical to
            // the one sampled after the recompute on exactly those members and they never reach
            // § 8.3's patch.
            //
            // PER EVENT AND NOT ONCE PER WINDOW, which is the same reading `StateRecompute`'s
            // class docblock argues for the recompute itself: § 4.8 row 4 is "one derivation pass
            // per applied event" and § 10 is "ten events, ten deltas". One fingerprint per window
            // would make a window's ten deltas share one `before` and re-report every earlier
            // event's change on each of them.
            $before = SeatFacts::versionBearing($event->seatRef);

            $this->projector->apply($event);
            $this->recompute->after($event, $before);

            $last = $row;
            $applied++;

            if ($this->clock->nowMs() - $windowStartedMs >= $this->windowBudgetMs) {
                break;
            }
        }

        $this->advance($seatRef, $cursor, (int) $last->id, $last->received_at);

        return $applied;
    }

    /**
     * @return Collection<int, object>
     */
    private function readable(int $seatRef, int $cursor): Collection
    {
        return DB::table('events')
            ->where('seat_ref', $seatRef)
            ->where('id', '>', $cursor)     // by id alone: the class docblock is why no age term is needed
            ->orderBy('id')                 // receipt (lock) order for visiting; § 6.5 applies by the triple
            ->limit(self::BATCH)
            ->get();
    }

    /**
     * § 6.5's empty read: the seat is on the claim (`fold_cursor_event_id < head_event_id`) and
     * `readable()` returned nothing.
     *
     * The cause the branch exists for is a PURGED window (§ 6.7's 14-day retention outlived the
     * fold's downtime, or a `rebuild --since` left the cursor below a window that has since aged
     * out). Here doing nothing is the defect: the claim still matches, the read still returns
     * nothing, and the seat is re-claimed on EVERY pass forever, never advancing, permanently
     * frozen while `fold_lag_ms` grows without bound — § 2.3's frozen fold arriving one seat at a
     * time, badging and alarming correctly while being unfixable by waiting.
     *
     * The other cause — rows above the cursor that the read did not return — is unreachable under
     * REPEATABLE READ (the server default; not pinned by config): `readable()` and the proof below
     * are the same predicate, `id > cursor`, read from the same transaction's read view. Under READ
     * COMMITTED a commit landing between the two statements makes it reachable, and waiting for the
     * next pass is then right, so the branch stays.
     *
     * `window()` reaches this with the seat lock held (card#9464), and the ingest — `events`' one
     * inserter and `head_event_id`'s writer — takes that lock first, so it cannot commit between
     * `readable()`, the proof and the guarded write. The READ COMMITTED branch and the guard stay: they
     * keep this correct without resting on that.
     */
    private function emptyWindow(int $seatRef, int $cursor): void
    {
        // ONE STATEMENT, so the bound H and the emptiness proof come from ONE SNAPSHOT. Two reads
        // would let an ingest commit between them and make H a head the proof never covered.
        $probe = DB::selectOne(
            'SELECT head_event_id AS h,'
            .' NOT EXISTS (SELECT 1 FROM events WHERE seat_ref = ? AND id > ?) AS window_empty'
            .' FROM seat_state WHERE seat_ref = ?',
            [$seatRef, $cursor, $seatRef],
        );

        if (! $probe || ! (int) $probe->window_empty) {
            return;   // rows committed after the read (READ COMMITTED only — see above). The next pass reads them.
        }

        // COUNTED ON THE PROOF, NEVER ON THE WRITE BELOW. `window_empty` IS the purge, and a lost
        // race does not un-purge it. Counted on the write instead, the lost race admits nothing —
        // and the next pass jumps that same interval through the ordinary branch, which is the
        // SILENT skip this counter exists to prevent. One worker cannot count the episode twice:
        // once the interleaved batch is committed the window is no longer empty, so this branch is
        // not reached for it again.
        Counters::seat($seatRef, 'fold_window_purged');

        if (self::$afterEmptinessProof !== null) {
            (self::$afterEmptinessProof)($seatRef);
        }

        // ADVANCE TO H — the head the proof covers — AND NEVER TO `head_event_id` RE-READ AT WRITE
        // TIME. That column is the INGEST's, written in the same transaction as its events, so a
        // commit landing between the proof and the write would put the cursor on the id of an
        // event this pass never folded and never will, stranding it and every lower id of its
        // batch while `fold_lag_ms` reads 0 because the cursor is at the head.
        //
        // The guard is what makes that interleaving harmless rather than a lock held across the
        // loop's per-seat COMMITs: head still H ⇒ no ingest committed since the proof ⇒ (cursor, H]
        // is still empty; head moved ⇒ zero rows match, nothing advances, and the next pass folds
        // the new rows through the ordinary branch.
        DB::table('seat_state')
            ->where('seat_ref', $seatRef)
            ->where('head_event_id', (int) $probe->h)
            ->update([
                'fold_cursor_event_id' => (int) $probe->h,
                'fold_cursor_received_at' => Clock::sql(now()),
                'updated_at' => Clock::sql(now()),
            ]);
    }

    /**
     * The cursor advance, guarded on the cursor this pass actually read.
     *
     * ⚠ THE GUARD IS AN ADDITION TO § 6.5's PSEUDOCODE, IN § 6.5's OWN IDIOM, AND IT IS FLAGGED IN
     * THE PR BODY. The document's claim takes `FOR UPDATE SKIP LOCKED` and then opens a SEPARATE
     * per-seat transaction — so under autocommit the claim's row locks are released at the end of
     * the claiming statement and do not survive into the transaction that does the work. Two
     * workers can therefore claim one seat. The projections are idempotent by construction and
     * would survive that; THE COUNTERS ARE NOT — `duplicate_open`, `seq_gap` and the rest would
     * double. Guarding the advance on the cursor value the pass read makes the loser's whole
     * transaction roll back, which is the same shape the purged-window branch already uses ("the
     * guard is about the CURSOR alone") and costs one WHERE clause.
     */
    private function advance(int $seatRef, int $cursor, int $to, string $receivedAt): void
    {
        $updated = DB::table('seat_state')
            ->where('seat_ref', $seatRef)
            ->where('fold_cursor_event_id', $cursor)
            ->update([
                'fold_cursor_event_id' => $to,
                'fold_cursor_received_at' => $receivedAt,
                'updated_at' => Clock::sql(now()),
            ]);

        if ($updated === 0) {
            throw new CursorRaced;
        }
    }

    /**
     * § 6.5's poison-event rule, in full: "the event is retried alone once, and on a second raise
     * the cursor advances past it, `fold_error` increments, `seat_state.fold_errors` increments,
     * the seat badges `derivation_error` and a transition row records the cause. The event stays
     * in `events`: the fix plus `mezzanine:rebuild --seat` recovers the seat exactly, which is only
     * true because the log is the source of truth and the projections are derived."
     */
    private function recoverOneAtATime(int $seatRef, int $cursor): int
    {
        $applied = 0;

        foreach ($this->readable($seatRef, $cursor) as $row) {
            $event = FoldEvent::fromRow($row);
            $ok = false;

            // "Retried ALONE ONCE" — two attempts, and only the second failure quarantines. One
            // attempt would quarantine a transient failure (a lost connection) as though the event
            // were malformed, and the event's own row is what a `--seat` rebuild would then be
            // replaying against a cursor that had already skipped it. Lock contention does not
            // spend an attempt at all: it yields below.
            foreach ([1, 2] as $attempt) {
                try {
                    Outbox::transaction(function () use ($event, $seatRef, $cursor, $row) {
                        if (! $this->lockSeatOrYield($seatRef)) {
                            throw new SeatLockYielded;
                        }

                        // Card #7837, and sampled INSIDE the transaction rather than above the
                        // retry loop: attempt 1 may have written and rolled back, so the only
                        // fingerprint this attempt can honestly call "before" is the one it reads
                        // after its own BEGIN.
                        $before = SeatFacts::versionBearing($event->seatRef);

                        $this->projector->apply($event);
                        $this->recompute->after($event, $before);
                        $this->advance($seatRef, $cursor, (int) $row->id, $row->received_at);
                    });

                    $ok = true;

                    break;
                } catch (CursorRaced) {
                    return $applied;
                } catch (SeatLockYielded) {
                    // BEFORE `\Throwable`, which would catch it too: there it would spend this
                    // attempt and send an innocent event to the quarantine.
                    return $applied;
                } catch (\Throwable $e) {
                    if ($this->contended($e)) {
                        return $applied;   // not this event's fault; the next pass retries it whole
                    }

                    // Attempt 1 falls through and retries; attempt 2 falls out to the quarantine.
                }
            }

            if ($ok) {
                $applied++;
            } else {
                try {
                    $this->quarantine($seatRef, $cursor, $event, $row->received_at);
                } catch (CursorRaced) {
                    return $applied;
                } catch (SeatLockYielded) {
                    // BEFORE `\Throwable`, which would rethrow it out of the pass as a fault.
                    return $applied;
                } catch (\Throwable $e) {
                    if (! $this->contended($e)) {
                        throw $e;
                    }

                    // The quarantine's own transaction rolled back: no `fold_error`, no advance.
                    // The event is retried from the top on the next pass.
                    return $applied;
                }
            }

            // THE CURSOR HAS MOVED TO THIS EVENT, so the next iteration's guard must read from
            // here. Both branches above advanced it — the success inside its transaction and the
            // quarantine inside its own — and a stale value here makes every later event of the
            // recovery lose its own guard and roll back, which is a recovery that recovers nothing.
            $cursor = (int) $row->id;
        }

        return $applied;
    }

    /**
     * Whether `$e` is the store's concurrency error — `1020` (a row changed since this snapshot
     * read it), `1205` (lock wait timeout), `1213` (deadlock) — rather than a fault of the event.
     *
     * Asked of Laravel's `ConcurrencyErrorDetector`, the same one the connection consults, so there
     * is no second list of engine messages here. It matches on the message, which Laravel keeps when
     * it rethrows a nested transaction's concurrency error as `DeadlockException`, so the answer is
     * the same at any transaction depth: production folds at the top level (`QueryException`), and
     * the suite folds inside `RefreshDatabase`'s transaction (`DeadlockException`).
     */
    private function contended(\Throwable $e): bool
    {
        return app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e);
    }

    private function quarantine(int $seatRef, int $cursor, FoldEvent $event, string $receivedAt): void
    {
        Outbox::transaction(function () use ($seatRef, $cursor, $event, $receivedAt) {
            if (! $this->lockSeatOrYield($seatRef)) {
                throw new SeatLockYielded;
            }

            // ⛔ SAMPLED AFTER THE SEAT LOCK AND BEFORE ANY WRITE, AND THE ANSWER TO "WHAT IS `$before`
            // HERE" IS THAT NOTHING WROTE BEFORE IT — card #7837, stated rather than left to be inferred
            // from the absence of an `apply()` call.
            //
            // This path quarantines an event that RAISED, and both attempts ran inside their own
            // transactions which rolled back — so no projection of this event survives and the
            // seat's state is exactly what the last good event left. The two writes below are the
            // `fold_error` counter and `seat_state.fold_errors`, and NEITHER is a member of the
            // fingerprint: `Badges::render()` reads the STORED `server_badges` column, and
            // `derivation_error` reaches it only through `Badges::serverFor()` inside
            // `writeDerivedColumns()`, which runs after this sample either way. So this sample is
            // VALUE-IDENTICAL to the one `after()` used to take for itself; what changes is that
            // the rule is now the same one at all four fold call sites instead of three plus a
            // coincidence.
            $before = SeatFacts::versionBearing($seatRef);

            Counters::seat($seatRef, 'fold_error');

            DB::table('seat_state')->where('seat_ref', $seatRef)
                ->update(['fold_errors' => DB::raw('fold_errors + 1')]);

            // § 4.3: `derivation_error` is a BADGE and never a state. "Overwriting a seat's derived
            // state with `unknown` because one event failed to project would DESTROY the reading
            // its other facts still support" — so the poison-event path labels and never collapses,
            // which is the same discipline the rest of the document applies. The recompute below
            // therefore leaves the axes exactly where the last good event left them and only the
            // badge moves.
            //
            // THE `fold_error` CAUSE IS THE WHOLE OF THIS CALL'S REQUEST FOR A ROW. § 6.5 requires
            // "a transition row records the cause" whether or not the render moved, because the row
            // is the drill-down's only record that an event was skipped — and `StateRecompute` owns
            // that, including the version bump that makes the row reachable through the feed. This
            // method used to write the no-render-change row itself, off a second pair of
            // `render_state` reads and without a bump, which is how a repeat quarantine on an
            // already-badged seat wrote three rows at one version.
            $this->recompute->after($event, $before, 'fold_error');

            $this->advance($seatRef, $cursor, $event->id, $receivedAt);
        });
    }
}

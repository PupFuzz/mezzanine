<?php

namespace App\Fleet;

use Illuminate\Support\Facades\DB;

/**
 * Lock an existing seat's `seat_state` row and its `seats` row, in THE ONE ORDER — card#12461.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ORDER: THE SEAT'S `seat_state` ROW FIRST, THEN ITS `seats` ROW. Every transaction that locks
 * or writes both rows of one seat takes them through `forUpdate()`, before it writes either.
 *
 * `seat_state` first is not a choice made here: it is `docs/design/FLEET-STATE.md § 6.5`'s lock-first
 * rule, which the ingest, the fold, the rebuild, the sweep and retirement already follow, each taking the
 * seat's `seat_state` row as its first lock. None of those takes the `seats` row before it, so a
 * transaction that took `seats` first and then needed `seat_state` would be the only one holding the
 * pair the other way round — and that is the deadlock card#12461 measured: `SeatTokens::issue()`'s
 * re-issue path held `seats` and waited on `seat_state` while `SeatRetirement` held `seat_state` and
 * waited on `seats`, and MariaDB broke the cycle with `1213`.
 *
 * WHO CALLS THIS. `SeatTokens::issue()` (re-issue of an existing seat) and `SeatRetirement` (the act).
 * The population is re-derived, not listed: `git grep -nE "lockForUpdate|FOR UPDATE|sharedLock" --
 * server/app` lists every locking read, and `docs/design/FLEET-STATE.md § 6.5` gives the command that
 * lists every write statement. A transaction that locks or writes a seat's `seat_state` row only, or its
 * `seats` row only, does not need this and does not use it.
 *
 * ⚠ AN EXISTING SEAT ONLY. A seat being created has no rows to lock: `issue()` inserts both inside the
 * species mutex (§ 6.13), and nothing can hold a row that does not exist yet.
 */
final class SeatLock
{
    /**
     * Lock `$seatRef`'s `seat_state` row, then its `seats` row, for the rest of the caller's transaction,
     * and return the `seats` columns asked for. The `seats` read is a locking read, so it is a current
     * read: it sees a retirement that committed while this transaction waited, whatever the snapshot.
     *
     * @param  list<string>  $seatColumns
     */
    public static function forUpdate(int $seatRef, array $seatColumns): object
    {
        DB::table('seat_state')->where('seat_ref', $seatRef)->lockForUpdate()->value('seat_ref');

        return DB::table('seats')->where('id', $seatRef)->lockForUpdate()->first($seatColumns);
    }
}

<?php

namespace App\Feed;

use App\Fold\Clock;
use App\Ingest\Counters;
use App\Sweep\Purge;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s outbox read as a VISIBLE PREFIX — card#9467. Every read of
 * `feed_outbox` that a stream's cursor moves on goes through here: the connect read (`boundary()`,
 * behind `Outbox::headBehindLag()`), the tick read (`after()`, behind `Outbox::after()`), and the
 * sweeper's stall watch (`countStalled()`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE PREFIX, AND WHY A FILTER WAS WRONG.
 *
 *     HOLD   = MIN(id) WHERE created_at > now − Outbox::VISIBILITY_LAG_S   (the lowest young id)
 *     prefix = every id below HOLD — or every id, when no row is that young
 *
 * The tick read returns `id > cursor AND id < HOLD ORDER BY id` and STOPS there. The read it replaces
 * returned `id > cursor AND created_at <= now − lag` — a FILTER — and the stream moved its cursor to
 * the highest id it saw. `feed_outbox` has several independent writer processes, and each stamps
 * `created_at` in PHP before its INSERT takes an id, so two writers can leave a LOWER id with a LATER
 * stamp. The filter then returned the higher id and skipped the lower one while it was still young,
 * and the cursor passed it: that row was never delivered. The prefix stops below it instead — a delay
 * of at most one lag, never a loss.
 *
 * ⚠ THE CONNECT READ IS `MAX(id)` BELOW HOLD, NOT `HOLD − 1`. The card's first statement of the
 * boundary was `HOLD − 1`, which is right as a bound on a read (a stream's cursor moves only to ids it
 * actually read) and wrong as a cursor: the id just below HOLD can be one a writer holds uncommitted,
 * and a stream that STARTS there never looks below it. AT-D2-25's between-the-commits connect is the
 * leg that goes red on `HOLD − 1`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT IS SAFE, AND THE CONDITIONS IT RESTS ON (D2 § 8.3 names them; nothing in this tree can check
 * them, so they are NAMED, not asserted):
 *
 *   (a) a row's stamp is taken at most δ before its INSERT assigns the id — `Outbox::transaction()`
 *       stamps immediately before its one INSERT, so δ is that statement's round trip;
 *   (b) every writer's COMMIT lands within `lag − δ` of its stamp — the INSERT is the transaction's
 *       last statement, so the id is held uncommitted for one COMMIT round trip;
 *   (c) the writers' clocks and the reader's agree to well inside the lag — one application host, or
 *       app hosts whose clocks are synchronized. The sandbox host ran 162 s fast on 2026-09-13/14.
 *
 * Argument: take any id x still uncommitted when a read runs at `now`, and any committed id r > x.
 * By (b), x's stamp is later than `now − lag + δ`. r was inserted after x, so by (a) r's stamp is
 * later than x's stamp minus δ — later than `now − lag`: r is young. So every committed row above an
 * uncommitted one is at or above HOLD, outside the prefix: a read never returns a row with an
 * uncommitted id below it, and a cursor never passes one. A COMMITTED young row below an aged one (the
 * reversal) is itself HOLD. Where (a) or (b) fails, the uncommitted id is invisible to `MIN(id)` and
 * the loss returns — the prefix does not defend a writer that holds its id longer than the lag. Where
 * (c) fails, a row stamped in the reader's future holds the prefix for as long as it stays "young":
 * the observables below.
 *
 * ⛔ ONE STATEMENT PER READ — NEVER HOLD IN ONE SELECT AND THE ROWS IN A SECOND. The argument is about
 * ONE snapshot. Computed in one autocommit statement and applied in the next, HOLD belongs to an older
 * snapshot than the rows: a row uncommitted (so invisible to HOLD) at the first statement and committed
 * by the second is returned while a lower id still open is skipped. Each read below is one statement.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE TWO OBSERVABLES (D2 § 7.2). Only a row stamped in the reader's FUTURE can hold the prefix longer
 * than the lag, so that is what each one watches:
 *
 *   · `feed_prefix_future` — every evaluation of the prefix (a connect read, a tick read, a sweep's
 *     stall watch) whose HOLD row's `created_at` is later than the evaluating process's clock.
 *     Counted once per evaluation, and logged with how far ahead the stamp is. Monotone; no gauge.
 *   · `feed_outbox_boundary_stalled` — a sweep pass that finds a committed row at or above HOLD whose
 *     `created_at` is `STALLED_AGE_S` or more in the past. § 6.7's purge deletes by each row's own
 *     `created_at` whether or not any stream read it, so such a row is one lag short of being deleted
 *     undelivered. Counted once per pass that finds one, not per row.
 */
final class VisiblePrefix
{
    /**
     * `feed_outbox_boundary_stalled`'s age: the outbox's retention less one visibility lag — the last
     * age at which a row held outside the prefix still has a full lag of retention left for the prefix
     * to reach it once the holding row ages. DERIVED from the two constants, never typed.
     */
    public const STALLED_AGE_S = Purge::FEED_OUTBOX_RETENTION_S - Outbox::VISIBILITY_LAG_S;

    /** HOLD, and its row joined back for its stamp. Always exactly one row (`m` is an aggregate). */
    private const HOLD = '(SELECT MIN(id) AS id FROM feed_outbox WHERE created_at > ?) m
        LEFT JOIN feed_outbox hold ON hold.id = m.id';

    /** § 8.3's connect read: the highest committed id inside the prefix — a stream's starting cursor. */
    public static function boundary(): int
    {
        $now = now();

        $row = DB::selectOne(
            'SELECT hold.id AS hold_id, hold.created_at AS hold_at,
                    (SELECT MAX(h.id) FROM feed_outbox h WHERE h.id < COALESCE(m.id, ~0)) AS head
             FROM '.self::HOLD,
            [self::youngAfter($now)],
        );

        self::countFuture($row, $now);

        return (int) $row->head;
    }

    /**
     * § 8.3's tick read: every row past `$cursor` inside the prefix, in `id` order — HOLD and the rows in
     * ONE statement (the class docblock says why).
     *
     * @return Collection<int, object{id: int, t: string, install_id: ?string, message: string}>
     */
    public static function after(int $cursor): Collection
    {
        $now = now();

        $rows = DB::select(
            'SELECT hold.id AS hold_id, hold.created_at AS hold_at, o.id, o.t, o.install_id, o.message
             FROM '.self::HOLD.'
             LEFT JOIN feed_outbox o ON o.id > ? AND o.id < COALESCE(m.id, ~0)
             ORDER BY o.id',
            [self::youngAfter($now), $cursor],
        );

        self::countFuture($rows[0], $now);

        return collect($rows)
            ->filter(fn (object $row) => $row->id !== null)
            ->map(fn (object $row) => (object) ['id' => (int) $row->id, 't' => $row->t, 'install_id' => $row->install_id, 'message' => $row->message])
            ->values();
    }

    /** The sweeper's stall watch: `feed_outbox_boundary_stalled`, once per pass that finds a stuck row. */
    public static function countStalled(): void
    {
        $now = now();

        $row = DB::selectOne(
            'SELECT hold.id AS hold_id, hold.created_at AS hold_at,
                    EXISTS (SELECT 1 FROM feed_outbox s WHERE s.id > m.id AND s.created_at <= ?) AS stalled
             FROM '.self::HOLD,
            [Clock::sql($now->copy()->subSeconds(self::STALLED_AGE_S)), self::youngAfter($now)],
        );

        self::countFuture($row, $now);

        if ((bool) $row->stalled) {
            Log::warning('mezzanine.feed: a feed_outbox row outside the visible prefix is one lag from its purge age, undelivered', [
                'hold_id' => (int) $row->hold_id,
                'hold_created_at' => $row->hold_at,
            ]);

            Counters::global('feed_outbox_boundary_stalled');
        }
    }

    /** The lag's cutoff: a row stamped after this is young. */
    private static function youngAfter(CarbonInterface $now): string
    {
        return Clock::sql($now->copy()->subSeconds(Outbox::VISIBILITY_LAG_S));
    }

    private static function countFuture(object $row, CarbonInterface $now): void
    {
        if ($row->hold_at === null) {
            return;
        }

        $aheadMs = Clock::toMs($row->hold_at) - Clock::toMs(Clock::sql($now));

        if ($aheadMs > 0) {
            Log::warning('mezzanine.feed: the feed_outbox prefix is held by a row stamped in this process\'s future', [
                'hold_id' => (int) $row->hold_id,
                'ahead_ms' => $aheadMs,
            ]);

            Counters::global('feed_prefix_future');
        }
    }
}

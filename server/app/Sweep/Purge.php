<?php

namespace App\Sweep;

use App\Feed\FeedHeartbeat;
use App\Feed\FeedStream;
use App\Fold\Clock;
use App\Ingest\Counters;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 6.7` — retention and purge.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RETENTION CHAIN IS ONE INEQUALITY AND THIS CLASS REFUSES TO BREAK IT.
 *
 *     spool residency   8 days  (D1 § 11.3, the oldest event a seat can still deliver)
 *          <  dedup window  10 days  (D2-MUST #3)
 *          <  event retention 14 days  (§ 6.7)
 *
 * "A retention below the dedup window silently re-ingests re-sent events as new ones — the single
 * most confusing possible corruption of a timeline." The dedup guarantee IS `events.uq_dedup`, so
 * an event purged early can be re-inserted by a re-send and would double-count: AT-D2-17's RED is
 * exactly that, "set event retention to 7 days, purge, then re-deliver an 8-day-old event → it
 * inserts as new, the timeline double-counts it, and the ledger gains a second open for a call that
 * closed a week ago."
 *
 * So `guard()` REFUSES A PASS whose effective retention is below the dedup window, rather than
 * trusting a constant nobody re-checks. § 2.2's posture for this path is the reason the refusal is
 * the right direction and not merely a cautious one: "Retaining too much costs disk; deleting on a
 * broken assumption costs the dedup guarantee. THE SAFE DIRECTION IS TO KEEP." A purge that has
 * been dead for four days is visible in `purge_last_run_at` ~96 times over; a purge that ran with
 * a wrong constant is invisible until a re-send arrives.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * BOUNDED BATCHES AND A WALL-CLOCK BUDGET, § 6.7's own mechanics: 5,000 rows a statement, looped
 * "until it deletes fewer than the limit or a 60-second wall-clock budget expires, then the next
 * table". Bounded batches keep the transaction and the binlog small and keep the store responsive
 * during the pass; the budget means "a purge that cannot keep up FALLS BEHIND VISIBLY
 * (`purge_backlog_rows` is counted) rather than holding a long transaction."
 */
final class Purge
{
    /** § 6.7 — the floor plus a four-day failure budget for the hourly job. */
    public const RETENTION_DAYS = 14;

    /** `D2-MUST` #3's dedup window, the FLOOR the retention constant may never go under. */
    public const DEDUP_WINDOW_DAYS = 10;

    /** § 6.7's batch bound. */
    public const BATCH = 5000;

    /** § 6.7's wall-clock budget for one pass. */
    public const BUDGET_S = 60;

    /**
     * § 6.7: `feed_outbox` is retained **60 s** after `created_at` — "the stall bound (45 s) plus one
     * heartbeat interval (15 s): a stream that resumes at the edge of its bound finds every row it may
     * still deliver". DERIVED from the two constants rather than typed, because it is a sum of two
     * numbers that live elsewhere and a literal here would not move when either did (card#9300).
     */
    public const FEED_OUTBOX_RETENTION_S = FeedStream::STALL_BOUND_S + FeedHeartbeat::INTERVAL_S;

    /**
     * The purge plan, one row per purgeable table, in § 6.7's own order and with its own predicate.
     *
     * `sessions`, `calls` and `attention_requests` are retained "**14 days** AFTER THE ROW CLOSED;
     * OPEN ROWS ARE NEVER PURGED", and the reason is AT-D2-10: "a closed fact older than the log it
     * was derived from cannot be re-derived, so purging it early would make a rebuild produce a
     * DIFFERENT answer than the live fold — breaking AT-D2-10's equality for a reason that is not a
     * defect." A `WHERE closed_at < ?` is null-safe in SQL, so an open row is excluded by the
     * comparison itself rather than by a second clause.
     *
     * ⛔ WHAT IS DELIBERATELY ABSENT. `seat_state`, `seat_counters`, `global_counters`,
     * `seat_predicates`, `installs`, `seats`, `feed_tokens` are retained **for ever** (§ 6.7).
     * "A seat row outlives its events deliberately: a provisioned seat that has never reported must
     * render, not vanish. A RETIRED SEAT IS LIKEWISE NEVER PURGED; it drops out of the read
     * surfaces at `retired_at` by a QUERY FILTER, not by a deletion (§ 4.10, as reversed by
     * card#9078's operator ruling), so an operator question about why it went can still be
     * answered." That filter is `App\Read\RetirementFilter`'s; this class's contribution to it
     * is refusing to make the row unavailable — and it is what the console's retired-seat list
     * reads, which is the record's home now that the desk goes at once.
     *
     * @var array<string, string> table => the column its retention is measured on
     */
    private const PLAN = [
        'events' => 'received_at',
        // Aligned with `events` so a forensic question about an event can always reach its batch.
        // D1 § 10.4's 24 h idempotency memory is a TIMESTAMP COMPARISON, not a deletion (§ 6.4) —
        // "a policy expressed as a deletion is indistinguishable from data loss".
        'batches' => 'received_at',
        'sessions' => 'ended_at',
        'calls' => 'closed_at',
        'attention_requests' => 'resolved_at',
        // The drill-down's history horizon; same number, one home.
        'seat_state_transitions' => 'at',
        // § 6.7's one transient table, on its own boundary — `boundaryFor()` (card#9300). Nothing
        // reads a row behind a stream's cursor, so a row that lingers until the next hourly pass
        // costs disk and nothing else (§ 6.7: ~7.5 MB at the 50-seat ceiling).
        'feed_outbox' => 'created_at',
    ];

    /**
     * One pass. Returns rows deleted per table.
     *
     * @return array<string, int>
     */
    public function pass(?int $retentionDays = null, ?int $budgetSeconds = null): array
    {
        $retentionDays ??= self::RETENTION_DAYS;

        // ⚠ THE BUDGET IS A PARAMETER SO THAT THE OVER-BUDGET PATH CAN BE SEEN TO FIRE. § 6.7 makes
        // `purge_backlog_rows` the instrument that lets a falling-behind purge "fall behind
        // VISIBLY", and a counter that no test has watched increment is a counter nobody has
        // established can. On a frozen test clock — which is how every test in this suite drives an
        // age (§ 11) — wall-clock time never passes, so a hardcoded 60 s could not be reached by
        // waiting; a budget of `0` reaches it exactly. Production never passes this.
        $budgetSeconds ??= self::BUDGET_S;

        $this->guard($retentionDays);

        $nowSql = Clock::sql(now());
        $deadlineMs = Clock::toMs($nowSql) + $budgetSeconds * 1000;
        $deleted = [];

        foreach (self::PLAN as $table => $column) {
            $deleted[$table] = $this->drain($table, $column, $this->boundaryFor($table, $retentionDays), $deadlineMs);
        }

        // § 6.7's visible-fall-behind instrument. Counted AFTER every table has had its turn, over
        // whatever is still past the boundary, so a pass that ran out of budget on `events` still
        // reports the whole backlog rather than one table's share of it.
        $backlog = 0;

        foreach (self::PLAN as $table => $column) {
            $backlog += DB::table($table)->where($column, '<', $this->boundaryFor($table, $retentionDays))->count();
        }

        if ($backlog > 0) {
            Counters::global('purge_backlog_rows', $backlog);
        }

        // § 6.7 / § 8.2.4. Stamped at the END of the pass, for the same reason the sweeper's is:
        // this timestamp is read as "the purge ran", and a stamp written before the work would
        // assert a pass that never finished.
        PlaneClock::stamp(PlaneClock::PURGE, $nowSql);

        return $deleted;
    }

    /**
     * The retention boundary for one plan table. Every table is on the event retention (and on the
     * `--retention-days` diagnostic override, which the dedup guard polices) EXCEPT `feed_outbox`,
     * whose 60 s is not a retention of history at all but the longest a stream may lag (§ 6.7) — so
     * the override does not reach it.
     */
    private function boundaryFor(string $table, int $retentionDays): string
    {
        return $table === 'feed_outbox'
            ? Clock::sql(now()->subSeconds(self::FEED_OUTBOX_RETENTION_S))
            : Clock::sql(now()->subDays($retentionDays));
    }

    /**
     * `D2-MUST` #3's floor, checked rather than assumed.
     *
     * SEEN TO FAIL is what makes this a check and not a comment: the test lowers the retention to
     * AT-D2-17's 7 days and watches the pass refuse. Without the refusal the same call deletes an
     * 8-day-old event, a re-send re-inserts it as new, and nothing anywhere says so.
     */
    private function guard(int $retentionDays): void
    {
        if ($retentionDays < self::DEDUP_WINDOW_DAYS) {
            throw new \InvalidArgumentException(sprintf(
                'refusing to purge: retention of %d days is below D2-MUST #3\'s %d-day dedup window'
                .' (docs/design/FLEET-STATE.md § 6.7). An event purged inside the window can be'
                .' re-inserted by a re-send and would double-count. Retaining too much costs disk;'
                .' deleting on a broken assumption costs the guarantee — the safe direction is to keep.',
                $retentionDays,
                self::DEDUP_WINDOW_DAYS,
            ));
        }
    }

    /**
     * `DELETE … WHERE <retention column> < ? ORDER BY <that table's retention index key> LIMIT 5000`,
     * looped until it deletes fewer than the limit or the pass budget expires.
     *
     * ⚠ THE ORDER IS PART OF THE BOUND, NOT DECORATION. Deleting the OLDEST rows first is what
     * makes an interrupted pass leave a contiguous retained window rather than holes: a pass that
     * ran out of budget has deleted a prefix of the expired rows in retention-column order, so every
     * row it kept is at or above every row it deleted, and the next hour's pass resumes from where it
     * stopped. An unordered `LIMIT` would let the engine pick, and a purge that removes rows from the
     * middle of a seat's history is a rebuild that produces a different answer for a reason nobody
     * can see.
     *
     * ⛔ THE ORDER IS THE RETENTION INDEX'S OWN KEY, NOT `id` — card#9466. The `WHERE` is a range on
     * the index that leads with the retention column, and ordering by that index's full key (its
     * columns, then `id`, which InnoDB carries at the end of every secondary index entry) lets the
     * engine read each batch off the index in order and stop at the `LIMIT`. `ORDER BY id` over the
     * same range does not match the index's order, so the engine either read every expired row and
     * sorted them for each 5,000-row batch or walked `PRIMARY` from the start of the table — which one
     * depending on the rows present — and that per-batch cost grew with the backlog, on exactly the
     * pass § 6.7 says falls behind visibly rather than holding a long transaction. Measured on
     * MariaDB 11.8.6 at 200,000 rows with half expired, on every plan table: `ORDER BY id` planned a
     * range over the retention index plus a filesort of the whole expired range; this order planned
     * the same range with no filesort, estimated at 5,000 rows (§ 6.7 carries the timings). `id`
     * last also makes the order total, so a batch boundary between rows with an equal retention value
     * is deterministic.
     */
    private function drain(string $table, string $column, string $boundary, int $deadlineMs): int
    {
        $total = 0;
        $order = $this->orderColumns($table);

        while (true) {
            if (Clock::toMs(Clock::sql(now())) >= $deadlineMs) {
                return $total;
            }

            $query = DB::table($table)->where($column, '<', $boundary);

            foreach ($order as $orderColumn) {
                $query->orderBy($orderColumn);
            }

            $rows = $query->limit(self::BATCH)->delete();

            $total += $rows;

            if ($rows < self::BATCH) {
                return $total;
            }
        }
    }

    /**
     * The order `drain()` deletes each plan table in: the full key of the index that leads with the
     * table's retention column, then the primary key. Read off the migrated schema's
     * `information_schema.STATISTICS`, and held to it by
     * `PurgeTest::test_the_drain_orders_each_table_by_its_retention_index_key()`, which derives the
     * same key from the schema and reds when a declaration here stops matching it.
     *
     * Stated as a lookup so that adding a purgeable table without declaring its order fails HERE,
     * loudly, instead of silently falling back to an order no index serves.
     *
     * @return list<string>
     */
    private function orderColumns(string $table): array
    {
        return match ($table) {
            'events', 'batches' => ['received_at', 'id'],                 // ix_purge
            'sessions' => ['ended_at', 'id'],                             // ix_purge
            'calls' => ['closed_at', 'orphan_due_at', 'id'],              // ix_orphan
            'attention_requests' => ['resolved_at', 'ceiling_at', 'id'],  // ix_ceiling
            'seat_state_transitions' => ['at', 'id'],                     // ix_purge
            'feed_outbox' => ['created_at', 'id'],                        // ix_created
            default => throw new \LogicException('no purge order declared for '.$table),
        };
    }
}

<?php

namespace App\Read;

use App\Fold\Clock;
use App\Sweep\Purge;
use Illuminate\Database\Query\Builder;

/**
 * `docs/design/FLEET-STATE.md § 4.10`'s read filter — **the only place in this application that
 * decides whether a retired seat is still rendered.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY IT IS A CLASS AND NOT A `where()` AT EACH READ SITE.
 *
 * § 4.10 answers two questions with one predicate: "Does a retired seat appear in the snapshot?
 * **Yes**, for **14 days** after `retired_at` … After 14 days the read queries stop selecting
 * it", and "Is it purged? **No.** … the 14 days is a READ FILTER, not a deletion". Two read
 * sites need it — the snapshot's seat list and § 8.2.4's `seats_total` / `seats_live` /
 * `max_fold_lag_ms` population — and § 8.2.4 states in terms what happens when two fields of one
 * object read two populations: "a `stale` seat 117 s behind would set `fleet.fold` to `lagging`
 * while `max_fold_lag_ms` read `0`". A second hand-written copy of this predicate is exactly that
 * disagreement waiting to be written.
 *
 * It is also AT-D2-23's PRIMARY RED made into a single mutation point: "drop retired seats from
 * the snapshot query AT `retired_at`" is `>` becoming `IS NULL` here, and the RED is driven by
 * mutating this line rather than by describing what would happen if someone did.
 *
 * ⚠ THE BOUNDARY IS `>` AND NOT `>=`, AND THE DIRECTION IS THE SAFE ONE: a seat retired EXACTLY
 * 14 days ago is still rendered for the instant the two values are equal. § 4.10's "for 14 days
 * AFTER `retired_at`" is inclusive of the window, and erring toward rendering a desk one tick too
 * long costs a row on a dashboard, while erring the other way is the vanishing this test forbids.
 */
final class RetirementFilter
{
    /**
     * Restrict a query that has `seats` joined (or is `seats`) to the seats § 4.10 still renders.
     */
    public static function renderable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('seats.retired_at')
            ->orWhere('seats.retired_at', '>', self::cutoff()));
    }

    /**
     * § 4.10: "Why 14 days? THE RETENTION WINDOW, ONE HOME: a retired seat stays visible for
     * exactly as long as the events that explain what it was doing."
     *
     * So the number is read from `Purge::RETENTION_DAYS` — the constant that owns § 6.7's window
     * — rather than written again here. Two independent 14s would be free to answer that one
     * sentence differently, and the first thing they would disagree about is a seat whose reason
     * an operator can no longer see.
     */
    public static function cutoff(): string
    {
        return Clock::sql(now()->copy()->subDays(Purge::RETENTION_DAYS));
    }
}

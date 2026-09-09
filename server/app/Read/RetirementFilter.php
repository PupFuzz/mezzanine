<?php

namespace App\Read;

use Illuminate\Database\Query\Builder;

/**
 * `docs/design/FLEET-STATE.md § 4.10`'s read filter — **the only place in this application that
 * decides whether a retired seat is still rendered.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ REVERSED BY OPERATOR RULING, card#9078 (2026-09-09): A RETIRED SEAT LEAVES THE READ SURFACES
 * **AT `retired_at`**, NOT FOURTEEN DAYS LATER.
 *
 * The operator, verbatim: *"An absence and removal are very obviously different because if an
 * agent is not reporting, it is assumed to be an absence. A removal is a deliberate action by the
 * operator. When an agent is removed, its seat and desk should go away immediately."*
 *
 * The 14-day window this class used to hold is **gone rather than kept as a backstop**, and that
 * is the whole shape of the change: two removal paths for one act — an immediate one on the
 * announcement and a delayed one on a timer — would leave the second as dead code nobody re-reads,
 * free to disagree with the first about which seats exist. `Purge::RETENTION_DAYS` still owns
 * § 6.7's EVENT retention; it no longer decides anything about rendering, and this class no longer
 * reads it.
 *
 * ⛔ WHAT THE REVERSAL DID NOT WIDEN, AND MUST NEVER: **removal is driven by the EXPLICIT
 * retirement, never by absence.** `retired_at` is written by one act (`App\Fleet\SeatRetirement`,
 * § 2.1's two operator entry points), and § 4.10's first sentence is unchanged — "nothing else —
 * no timeout, no purge, no silence — ever removes a row from the fleet". A seat that has merely
 * stopped reporting still renders, degraded: `stale` at 300 s, `offline` at 900 s
 * ([FLOOR.md § 7.1](../../../docs/design/FLOOR.md)). That is what the predicate below is keyed on
 * — `retired_at`, an announcement — and never on staleness, a delta's contents, or a seat's
 * absence from anything.
 *
 * ⚠ THE PM's COUNTER-ARGUMENT IS RECORDED HERE BECAUSE IT IS THE ONE A MAINTAINER WILL RE-DERIVE.
 * It was that a retired desk must LINGER so that *"we removed it"* stays distinguishable from
 * *"it went quiet"*. It is false on the design's own render table: a quiet seat is visibly present
 * and degraded, so a removed seat being GONE is maximally different from it. The nameplate was
 * never doing that work.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY IT IS STILL A CLASS AND NOT A `where()` AT EACH READ SITE.
 *
 * Five read sites need the predicate — the snapshot's seat list, § 8.2.4's `seats_total` /
 * `seats_live` / `max_fold_lag_ms` population, the seat-detail endpoint, the timeline endpoint and
 * the admin console's agent list — and § 8.2.4 states in terms what happens when two fields of one
 * object read two populations: "a `stale` seat 117 s behind would set `fleet.fold` to `lagging`
 * while `max_fold_lag_ms` read `0`". A second hand-written copy of this predicate is exactly that
 * disagreement waiting to be written. The predicate got SIMPLER under card#9078 and that is
 * precisely when a maintainer inlines it; `whereNull` at five sites is five places for the sixth
 * surface to be forgotten.
 *
 * It is also AT-D2-23's RED made into a single mutation point — the RED is now the OPPOSITE
 * mutation from the one this class was built against, and both are one line here: `renderable()`
 * widened to keep retired seats is the vanished-ruling RED, and `retired()` narrowed to a window
 * is the 14-day behaviour returning.
 */
final class RetirementFilter
{
    /**
     * Restrict a query that has `seats` joined (or is `seats`) to the seats § 4.10 renders — the
     * ones no operator has retired.
     */
    public static function renderable(Builder $query): Builder
    {
        return $query->whereNull('seats.retired_at');
    }

    /**
     * The exact complement: the seats an operator HAS retired.
     *
     * ⛔ THE COMPLEMENT LIVES HERE, BESIDE THE PREDICATE IT NEGATES, because card#9078 moved the
     * retirement record's home to the admin console (§ 4.10) — the record does not disappear, it
     * MOVES — and a console that hand-wrote `whereNotNull('seats.retired_at')` would be the second
     * copy of one boundary. Two spellings of one line is one spelling too many; the first thing
     * they would disagree about is a seat that is on neither list.
     *
     * There is no window on this one either, deliberately: the console view is not bounded by 14
     * days ("it is queryable, and it does not consume a slot on a floor whose slot count is
     * finite"), and § 6.7 retains `seats` forever, so every retirement this install ever performed
     * is answerable here.
     */
    public static function retired(Builder $query): Builder
    {
        return $query->whereNotNull('seats.retired_at');
    }
}

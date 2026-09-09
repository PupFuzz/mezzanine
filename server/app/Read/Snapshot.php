<?php

namespace App\Read;

use App\Fold\Clock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 8.2`'s `GET /api/fleet/snapshot` body — "the whole fleet: every
 * install, every seat, current state. The snapshot half of snapshot-then-deltas, and the
 * watchdog's entire interface."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ § 4.10's DISAPPEARANCE IS A READ FILTER AND NOT A DELETION, AND `seats()` BELOW IS THAT
 * FILTER — the whole of it, in one place.
 *
 * "Does a retired seat appear in the snapshot? **No** — it leaves at `retired_at`, on the
 * announcement" (card#9078's operator ruling; the 14-day window it replaced is recorded in
 * `App\Read\RetirementFilter`). "Is it purged? **No.** `seats` is retained forever; the filter is
 * a READ FILTER, not a deletion, so an operator query — and the console's retired list, which is
 * `retiredSeats()` below — can still find the row and its reason."
 *
 * AT-D2-23's PRIMARY RED — a retired seat that is STILL in the snapshot, or a seat removed for any
 * reason other than the announcement — is a mutation of the predicate in `RetirementFilter` and of
 * nothing else, which is why the predicate is not spread across the queries that need it. It is
 * `FleetHealth::population()` for the seat set, so the snapshot's seat list and
 * `fleet.seats_total` cannot count different populations; § 8.2.4 argues that case in terms and
 * this is the code-side of it.
 *
 * ⚠ NO PAGINATION, DELIBERATELY, AND THE TRIGGER IS STATED. § 8.2.1: "A 50-seat snapshot is
 * ~91 KB, which is one response. Past **200 seats** (~362 KB typical) the snapshot should page by
 * install — stated now as the trigger, and deliberately NOT BUILT, because building pagination
 * for a four-seat fleet is mechanism for a case that does not exist."
 */
final class Snapshot
{
    /** § 8.1: the REST surface "carries `api_version`". */
    public const API_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function build(int $nowMs): array
    {
        $installs = [];

        foreach (self::seats() as $row) {
            $installs[$row->install_id] ??= ['install_id' => $row->install_id, 'seats' => []];
            $installs[$row->install_id]['seats'][] = SeatObject::build($row, $row, $nowMs);
        }

        return [
            'api_version' => self::API_VERSION,
            'server_time' => Clock::wire(Clock::sql(now())),
            'fleet' => FleetHealth::build($nowMs),
            'installs' => array_values($installs),
        ];
    }

    /**
     * Every renderable seat, joined to its install and its `seat_state`, in a stable order.
     *
     * ONE ROW OBJECT CARRIES BOTH the `seats`/`installs` columns `SeatObject::build()` takes as
     * its `$seat` and the `seat_state` columns it takes as its `$state`. That is why the select
     * list is explicit: a `seat_state.*` alone would not carry `install_id`, and a bare `*` would
     * let `seats.id` overwrite `seat_state.seat_ref`'s sibling columns silently.
     *
     * @return Collection<int, object>
     */
    public static function seats(): Collection
    {
        return RetirementFilter::renderable(self::query())
            ->orderBy('installs.install_id')
            ->orderBy('seats.seat_id')
            ->get(self::COLUMNS);
    }

    /**
     * The complement of `seats()`: every seat an operator has retired, newest retirement first.
     *
     * ⛔ THE RETIREMENT RECORD DOES NOT DISAPPEAR — IT MOVES, and this is where it moved to
     * (card#9078). The desk goes at `retired_at`; `retired_at` / `retired_by` / `retired_reason`
     * stay in the store forever (§ 6.7) and the admin console's agent module renders them from
     * here. That is a better home than a ghost desk: it is queryable, it is not bounded by 14
     * days, and it does not consume a slot on a floor whose slot count is finite.
     *
     * ⚠ SAME JOIN, SAME COLUMNS, OPPOSITE PREDICATE — `self::query()` and `self::COLUMNS` are
     * shared with `seats()` above rather than written a second time. The two lists are one list
     * because a console that selected its own would be free to disagree with the floor about what
     * a seat IS, and the retirement record is exactly the field set that would go missing.
     *
     * @return Collection<int, object>
     */
    public static function retiredSeats(): Collection
    {
        return RetirementFilter::retired(self::query())
            ->orderByDesc('seats.retired_at')
            ->orderBy('installs.install_id')
            ->orderBy('seats.seat_id')
            ->get(self::COLUMNS);
    }

    /**
     * The one join both reads above are built on. `seat_state` first, so that a seat with no
     * `seat_state` row cannot appear on either list — there is no such seat (§ 6.4 writes the row
     * at token-issue time), and this is the shape that keeps it true rather than a guard for it.
     */
    private static function query(): Builder
    {
        return DB::table('seat_state')
            ->join('seats', 'seats.id', '=', 'seat_state.seat_ref')
            ->join('installs', 'installs.id', '=', 'seats.install_ref');
    }

    /** @var list<string> */
    private const COLUMNS = [
        'seat_state.*',
        'installs.install_id',
        'seats.seat_id',
        'seats.retired_at',
        'seats.retired_by',
        'seats.retired_reason',
    ];
}

<?php

namespace App\Read;

use App\Fold\Clock;
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
 * "Does a retired seat appear in the snapshot? **Yes**, for **14 days** after `retired_at`, with
 * `render_state: "retired"` and a `retired` object … After 14 days the read queries stop
 * selecting it." "Is it purged? **No.** `seats` is retained forever; the 14 days is a READ
 * FILTER, not a deletion, so an operator query can still find the row and its reason."
 *
 * AT-D2-23's PRIMARY RED — "the vanishing desk: drop retired seats from the snapshot query AT
 * `retired_at`" — is a mutation of the predicate below and of nothing else, which is why the
 * predicate is not spread across the two queries that need it. It is `FleetHealth::population()`
 * for the seat set, so the snapshot's seat list and `fleet.seats_total` cannot count different
 * populations; § 8.2.4 argues that case in terms and this is the code-side of it.
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
        return RetirementFilter::renderable(
            DB::table('seat_state')
                ->join('seats', 'seats.id', '=', 'seat_state.seat_ref')
                ->join('installs', 'installs.id', '=', 'seats.install_ref')
        )
            ->orderBy('installs.install_id')
            ->orderBy('seats.seat_id')
            ->get([
                'seat_state.*',
                'installs.install_id',
                'seats.seat_id',
                'seats.retired_at',
                'seats.retired_by',
                'seats.retired_reason',
            ]);
    }
}

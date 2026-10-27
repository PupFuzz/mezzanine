<?php

namespace Tests\Support;

use App\Characters\SpeciesList;
use Illuminate\Support\Facades\DB;

/**
 * The ONE way a test inserts a `seats` row directly rather than through `App\Fleet\SeatTokens::issue()`
 * (card#12443, `docs/design/FLEET-STATE.md` Appendix B row 15).
 *
 * `seats.species` is `NOT NULL` and is allocated by `issue()` (§ 6.13); a test that writes the row by hand
 * names the species here, so three hand-written inserts cannot each invent a value. It writes the species
 * the seat's key DRAWS — the allocation's first preference, and what an uncontended allocation gives it.
 * `git grep -ln "table('seats')->insert" -- server/tests` re-derives who should be calling this instead.
 */
final class SeatRows
{
    /**
     * @param  array<string, mixed>  $columns  any further `seats` columns (`created_at`, `retired_at`, …)
     */
    public static function insert(int $installRef, string $seatId, array $columns = []): int
    {
        $installId = (string) DB::table('installs')->where('id', $installRef)->value('install_id');

        return (int) DB::table('seats')->insertGetId([
            'install_ref' => $installRef,
            'seat_id' => $seatId,
            'species' => SpeciesList::current()->drawOf($installId.'/'.$seatId),
        ] + $columns);
    }
}

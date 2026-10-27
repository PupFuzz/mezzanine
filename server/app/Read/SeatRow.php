<?php

namespace App\Read;

use Illuminate\Support\Facades\DB;

/**
 * One renderable seat's row — the `seat_state` ⋈ `seats` ⋈ `installs` selection, hoisted out of
 * `FleetController::seat()` at its second caller (`docs/design/BOARD-TASK.md § 15.9`): the compose
 * path resolves its target from the SAME row the desk is rendered from, through the same retirement
 * filter, so a retired seat is a `404` on both.
 *
 * ⚠ WIDENED BY ONE COLUMN, `seats.board_user_id`, which the compose path's live read needs to pick the
 * seat's § 4.2 winner. `FleetController` publishes what it published — `SeatObject` reads no such
 * member — so the widening changes no wire.
 */
final class SeatRow
{
    public static function find(string $installId, string $seatId): ?object
    {
        return RetirementFilter::renderable(
            DB::table('seat_state')
                ->join('seats', 'seats.id', '=', 'seat_state.seat_ref')
                ->join('installs', 'installs.id', '=', 'seats.install_ref')
                ->where('installs.install_id', $installId)
                ->where('seats.seat_id', $seatId)
        )->first([
            'seat_state.*', 'installs.install_id', 'seats.seat_id', 'seats.species',
            'seats.retired_at', 'seats.retired_by', 'seats.retired_reason', 'seats.board_user_id',
        ]);
    }
}

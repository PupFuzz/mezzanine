<?php

namespace App\Read;

/**
 * One renderable seat's row — the `seat_state` ⋈ `seats` ⋈ its room (`App\Read\SeatIdentity`) selection, hoisted out of
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
    /** The live seat of this id — there is at most one (`uq_live_seat`, card#13117). */
    public static function find(string $seatId): ?object
    {
        return RetirementFilter::renderable(
            SeatIdentity::query()
                ->join('seat_state', 'seat_state.seat_ref', '=', 'seats.id')
                ->where('seats.seat_id', $seatId)
        )->first([
            'seat_state.*', SeatIdentity::installId(), 'seats.seat_id', 'seats.species',
            'seats.retired_at', 'seats.retired_by', 'seats.retired_reason', 'seats.board_user_id',
        ]);
    }
}

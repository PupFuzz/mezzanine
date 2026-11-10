<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Put a seat in a room, for a test whose subject is a seat that has one (card#13117).
 *
 * Since card#13117 a seat is issued in NO room (`seats.install_ref` NULL) and the console places it.
 * Until the console's *Room* control and its move transaction land (`docs/design/plan-13117-seat-identity.md`
 * § 4.2, slice S3), nothing in the application writes a seat's room, so a fixture that needs one writes
 * it here: the room's `installs` row if it is new, and the seat's `install_ref`. It is a fixture's write
 * and nothing more — no recompute, no delta — which is what a seat issued into a room would have been
 * before this card.
 */
final class Rooms
{
    /** @return int the seat's `seats.id` */
    public static function place(string $seatId, string $roomKey): int
    {
        DB::table('installs')->insertOrIgnore(['install_id' => $roomKey, 'created_at' => now()->format('Y-m-d H:i:s.v')]);

        $seatRef = (int) DB::table('seats')->where('seat_id', $seatId)->whereNull('retired_at')->value('id');

        DB::table('seats')->where('id', $seatRef)->update([
            'install_ref' => DB::table('installs')->where('install_id', $roomKey)->value('id'),
        ]);

        return $seatRef;
    }
}

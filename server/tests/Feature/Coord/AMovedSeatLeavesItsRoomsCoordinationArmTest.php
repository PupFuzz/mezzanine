<?php

namespace Tests\Feature\Coord;

use App\Building\Rooms;
use App\Fleet\SeatMove;
use App\Fleet\SeatTokens;
use App\Read\SeatObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\OutboxWire;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * T10 — `docs/design/plan-13117-seat-identity.md § 9`, card#13117: D2 § 8.3.3 rule 1's ROOM arm resolves a name to
 * the one live seat CURRENTLY in the room declaring it. AT-D2-24's install-arm GREEN, with the declaring seat moved
 * out of the room between its declaration and a post: the post's name resolves to its desk before the move and to
 * no desk after it — and to the desk in the room it moved to.
 *
 * End to end across the boundary the move crosses: the server's act (`App\Fleet\SeatMove`) publishes the delta, and
 * the SHIPPED join (`public/js/floor/coord-join.js`) is run over the seat objects a client holds before it and after
 * merging it. ⛔ THE RED is a move whose delta does not carry the room — `install_id` missing from
 * `SeatFacts::versionBearing()` — under which the client keeps the seat in its old room and the post still draws to
 * a desk that has left; that plant was run against this file once by hand (the card's plant table).
 */
class AMovedSeatLeavesItsRoomsCoordinationArmTest extends TestCase
{
    use DrivesAShippedClientModule;
    use RefreshDatabase;

    protected function moduleDir(): string
    {
        return $this->jsRoot().'/floor';
    }

    protected function probeScript(): string
    {
        return __DIR__.'/room-move-join-probe.mjs';
    }

    public function test_a_name_resolves_to_the_seat_in_the_room_and_to_nothing_once_it_has_moved_out(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        Rooms::create('sola', 'Sola', null, 'suite');
        $move = app(SeatMove::class);
        $seats = [];

        foreach (['seat-pm', 'seat-coder'] as $seatId) {
            $seats[$seatId] = SeatTokens::issue($seatId, 'suite')->seatRef;
            $move->move($seatId, 'aimla', 'suite');
        }

        DB::table('seat_state')->where('seat_ref', $seats['seat-pm'])
            ->update(['protocol_agent_name' => 'pm', 'protocol_agent_name_check' => 'checked']);

        // What a client holds: each seat's § 8.2.1 object, as the snapshot serves it.
        $held = array_map(fn (int $ref): array => SeatObject::forSeatRef($ref, 0), array_values($seats));

        $wire = new OutboxWire;
        $wire->forget();
        $move->move('seat-pm', 'sola', 'suite');
        [$delta] = $wire->ofType('seat.delta');

        $aimla = $this->probe(['seats' => $held, 'delta' => $delta['payload'], 'room' => 'aimla']);
        $this->assertSame('seat-pm', $aimla['before']['join']['pm'] ?? null, 'before the move, pm did not resolve to its desk in aimla');
        $this->assertArrayNotHasKey('pm', $aimla['after']['join'], 'after the move out, pm still resolves in aimla');

        $sola = $this->probe(['seats' => $held, 'delta' => $delta['payload'], 'room' => 'sola']);
        $this->assertArrayNotHasKey('pm', $sola['before']['join']);
        $this->assertSame('seat-pm', $sola['after']['join']['pm'] ?? null, 'after the move, pm does not resolve to its desk in sola');
    }
}

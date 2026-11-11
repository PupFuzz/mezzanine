<?php

namespace Tests\Feature\Fleet;

use App\Building\Rooms;
use App\Coord\HookBinding;
use App\Fleet\SeatMove;
use App\Fleet\SeatMoveOutcome;
use App\Fleet\SeatMoveRefused;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Read\SeatIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\OutboxWire;
use Tests\TestCase;

/**
 * T8's server half — `docs/design/plan-13117-seat-identity.md § 9`, card#13117: a room move (`App\Fleet\SeatMove`,
 * D2 § 4.10) is ONE `seat.delta` whose `changed[]` names `install_id`, whose patch carries the new room, at
 * `state_version` + 1, with the act's author on the transitions ledger.
 *
 * ⛔ THE RED IS `install_id` MISSING FROM `SeatFacts::versionBearing()`: the recompute then sees nothing move, no
 * version is bumped and no delta is sent, so every connected floor keeps drawing the seat in its old room. That
 * plant was run against this file once by hand (the card's plant table); the property it guards is the first
 * assertion of the first test.
 */
class ARoomMoveIsOneDeltaTest extends TestCase
{
    use RefreshDatabase;

    private const SEAT = 'seat-w';

    private OutboxWire $wire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wire = new OutboxWire;
    }

    private function issue(string $seatId = self::SEAT): int
    {
        $issued = SeatTokens::issue($seatId, 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $issued);

        return $issued->seatRef;
    }

    private function move(string $seatId, string $room): SeatMoveOutcome
    {
        return app(SeatMove::class)->move($seatId, $room, 'ops@example.com');
    }

    private function version(int $seatRef): int
    {
        return (int) DB::table('seat_state')->where('seat_ref', $seatRef)->value('state_version');
    }

    /** Declare `$name` on a seat as its heartbeat would, `checked`. */
    private function declare(int $seatRef, string $name): void
    {
        DB::table('seat_state')->where('seat_ref', $seatRef)
            ->update(['protocol_agent_name' => $name, 'protocol_agent_name_check' => 'checked']);
    }

    public function test_a_move_is_one_delta_naming_the_room_at_the_next_version(): void
    {
        $seatRef = $this->issue();
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $before = $this->version($seatRef);
        $this->wire->forget();

        $outcome = $this->move(self::SEAT, 'aimla');

        $deltas = $this->wire->ofType('seat.delta');
        $this->assertCount(1, $deltas, 'a move is not exactly one seat.delta');
        $this->assertSame([], array_values(array_filter($this->wire->all(), fn ($m) => $m['t'] !== 'seat.delta')),
            'a move put something other than its delta on the feed');
        [$delta] = $deltas;

        $this->assertContains('install_id', $delta['payload']['changed']);
        $this->assertSame('aimla', $delta['payload']['patch']['install_id']);
        $this->assertSame($before + 1, $delta['payload']['state_version']);
        $this->assertSame($before + 1, $this->version($seatRef));
        // The delta's own room — the outbox row's tenancy key — is the new room's from the delta that announces it.
        $this->assertSame('aimla', $delta['payload']['install_id']);
        $this->assertSame('aimla', $delta['install_id']);

        $this->assertTrue($outcome->moved);
        $this->assertSame([SeatIdentity::UNASSIGNED, 'aimla'], [$outcome->from, $outcome->to]);
        $this->assertSame('aimla', SeatIdentity::byId(self::SEAT)->install_id);

        // The act's author, on the ledger: a `cause: operator` row whose render did not move.
        $row = DB::table('seat_state_transitions')->where('seat_ref', $seatRef)->orderByDesc('id')->first();
        $this->assertSame('operator', $row->cause);
        $this->assertSame($before + 1, (int) $row->state_version);
        $this->assertSame($row->from_render_state, $row->to_render_state);
        $this->assertSame(['from_room' => SeatIdentity::UNASSIGNED, 'moved_by' => 'ops@example.com', 'to_room' => 'aimla'],
            collect(json_decode($row->detail, true))->sortKeys()->all());
    }

    public function test_a_move_back_to_no_room_is_one_delta_naming_unassigned(): void
    {
        $seatRef = $this->issue();
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $this->move(self::SEAT, 'aimla');
        $this->wire->forget();

        $this->move(self::SEAT, SeatIdentity::UNASSIGNED);

        [$delta] = $this->wire->ofType('seat.delta');
        $this->assertSame(SeatIdentity::UNASSIGNED, $delta['payload']['patch']['install_id']);
        $this->assertSame(SeatIdentity::UNASSIGNED, $delta['install_id']);
        $this->assertNull(DB::table('seats')->where('id', $seatRef)->value('install_ref'));
    }

    public function test_a_move_into_the_room_it_is_in_changes_nothing(): void
    {
        $seatRef = $this->issue();
        $before = $this->version($seatRef);
        $this->wire->forget();

        $outcome = $this->move(self::SEAT, SeatIdentity::UNASSIGNED);

        $this->assertFalse($outcome->moved);
        $this->assertSame([], $this->wire->all());
        $this->assertSame($before, $this->version($seatRef));
    }

    public function test_a_move_is_refused_into_no_room_a_retired_room_or_for_no_live_seat(): void
    {
        $this->issue();
        Rooms::create('gone', 'Gone', null, 'suite');
        Rooms::retire('gone', 'suite');
        $this->wire->forget();

        foreach ([[self::SEAT, 'nowhere', 'There is no room nowhere'], [self::SEAT, 'gone', 'Room gone is retired'],
            ['seat-nobody', SeatIdentity::UNASSIGNED, 'No live seat seat-nobody']] as [$seat, $room, $says]) {
            try {
                $this->move($seat, $room);
                $this->fail("the move of {$seat} into {$room} was not refused");
            } catch (SeatMoveRefused $e) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }

        $this->assertSame([], $this->wire->all(), 'a refused move wrote to the feed');
        $this->assertNull(DB::table('seats')->where('seat_id', self::SEAT)->value('install_ref'));
    }

    /**
     * § 4.2's two warnings, and neither refuses: into a room where another live seat declares the name, and out of
     * the room a room-scope binding is bound to while the seat declares a name.
     */
    public function test_the_two_coordination_warnings_are_given_and_the_move_still_happens(): void
    {
        $moving = $this->issue();
        $namesake = $this->issue('seat-pm');
        Rooms::create('aimla', 'Aimla', null, 'suite');
        Rooms::create('sola', 'Sola', null, 'suite');
        $this->move('seat-pm', 'sola');
        $this->declare($moving, 'pm');
        $this->declare($namesake, 'pm');

        $into = $this->move(self::SEAT, 'sola');
        $this->assertTrue($into->moved);
        $this->assertCount(1, $into->warnings);
        $this->assertStringContainsString('so does seat-pm in room sola', $into->warnings[0]);

        HookBinding::issue('acme/team', 'room', 'sola', false, 'suite');
        $out = $this->move(self::SEAT, 'aimla');
        $this->assertTrue($out->moved);
        $this->assertCount(1, $out->warnings);
        $this->assertStringContainsString('coordination binding for acme/team', $out->warnings[0]);

        // The control: a seat that declares nothing is warned about nothing.
        $quiet = $this->issue('seat-quiet');
        $this->assertSame([], $this->move('seat-quiet', 'sola')->warnings);
        $this->assertNotNull($quiet);
    }
}

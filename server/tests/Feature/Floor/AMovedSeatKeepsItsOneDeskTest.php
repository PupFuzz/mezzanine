<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * T8's client half — `docs/design/plan-13117-seat-identity.md § 9`, card#13117: a room move is one `seat.delta`
 * whose `changed[]` names `install_id`, and the client — keyed by `seat_id` (`public/js/wire/seat-key.js`) —
 * merges it into the seat it already holds. The desk floor then draws ONE desk for the seat, in its new room.
 *
 * ⛔ THE RED IS THE PAIR KEY. A client keyed by `install_id/seat_id` holds no key for the seat's new room, answers
 * the delta as a seat it does not hold (FLOOR.md § 2.3 row 1's insert fetch), and ends holding the seat twice: the
 * old room's object and the fetched one. `fx-room-move`'s served seat body is that client's answer.
 */
class AMovedSeatKeepsItsOneDeskTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const RUN = 'room_move';

    private const SEAT = 'seat-w';

    /**
     * The desks the run's last frame draws for the moved seat, each as its room.
     *
     * @return list<string>
     */
    private function roomsOfTheMovedSeat(?string $dir = null): array
    {
        $desks = $this->lastFrame($this->deskRun(self::RUN, $dir))['desks'];

        return array_values(array_map(static fn (array $desk): string => (string) $desk['install_id'],
            array_filter($desks, static fn (array $desk): bool => $desk['seat_id'] === self::SEAT)));
    }

    public function test_a_room_move_leaves_one_desk_for_the_seat_in_its_new_room(): void
    {
        $result = $this->deskRun(self::RUN);

        $this->assertSame(['aimla'], $this->roomsOfTheMovedSeat(), 'the moved seat is not exactly one desk, in room aimla');
        $this->assertSame([], $this->seatRequests($result), 'the client fetched a seat it already held — the delta was not merged');
    }

    public function test_red_a_client_keyed_by_the_pair_holds_the_moved_seat_twice(): void
    {
        $dir = $this->mutatedModules(['seat-key.js', '    return seat.seat_id;', '    return `${seat.install_id}/${seat.seat_id}`;']);

        $rooms = $this->roomsOfTheMovedSeat($dir);
        sort($rooms);

        $this->assertSame(['aimla', 'unassigned'], $rooms, 'the plant did not bite: a pair-keyed client did not hold the moved seat twice');
    }
}

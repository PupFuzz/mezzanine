<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * card#13117's room move, on the room the seat LEFT — `docs/design/plan-13117-seat-identity.md § 4.2`: *"the old
 * room's chain re-probes as it does on a retirement (D3 § 3.5)"*. AT-D3-16's collision chain is the scenario
 * (`fixtures/fx-room-move.json`'s `move_collision`): `aimla-win-6` arrives and displaces § 3.3's worked seat, then a
 * console move takes `aimla-win-6` out of `aimla` into no room. The displaced desk moves back into the freed slot,
 * and the move is an A16 row whose `cause` names the moved seat — the row a retirement of that seat writes.
 *
 * ⛔ THE RED IS TODAY'S CLIENT BEFORE card#13117's S3: `#displacements` attributed a cause only to a removal, an
 * arrival or a relay, so the old room re-slotted with no row at all — a desk moving with nothing to say why.
 */
class AMovedSeatsOldRoomReProbesAsOnARetirementTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const RUN = 'move_collision';

    /** The plant: a room move is no departure — the client as it stood before this slice. */
    private const NO_DEPARTURE = [self::FLOOR_SCREEN,
        "            ...applied.filter((entry) => entry.before.install_id !== entry.after.install_id),\n",
        ''];

    public function test_the_displaced_desk_moves_back_under_an_a16_naming_the_moved_seat(): void
    {
        $this->assertSame([], $this->defects());
    }

    public function test_red_a_move_read_as_no_departure_re_slots_with_no_row(): void
    {
        $defects = $this->defects($this->mutatedModules(self::NO_DEPARTURE));

        $this->assertArrayHasKey('a16', $defects, 'the plant did not bite: '.json_encode($defects));
        $this->assertArrayNotHasKey('chain', $defects, 'the plant changed the slots, not only the row: '.json_encode($defects));
    }

    /**
     * The room it ENTERS: `move_in_collision` — the seat held in no room, then moved into `aimla`. It is an arrival there,
     * as an inserted seat is, so § 3.3's worked seat probes off the slot it takes under one A16 naming it — and the
     * client fetched nothing, since it already held the seat.
     */
    public function test_in_the_room_it_enters_the_moved_seat_displaces_as_an_arrival_does(): void
    {
        $result = $this->floorRun('move_in_collision');
        $collision = $this->documentCollision();
        $slots = $this->slotsOf($this->lastFloor($result), 'aimla');

        $this->assertSame($collision['slot'], $slots[$collision['arriving']] ?? null, 'the moved seat does not hold the slot it hashes to');
        $this->assertSame($collision['moves_to'], $slots[$collision['displaced']] ?? null, 'the displaced seat did not probe on');

        $moves = $this->rowsFor($result, 'A16');
        $this->assertCount(1, $moves, 'A16 rows: '.json_encode($moves));
        $this->assertSame([$collision['displaced'], $collision['arriving']], [$moves[0]['seat_id'], $moves[0]['cause']]);
        $this->assertSame([], $this->rowsFor($result, 'A1'), 'the moved seat walked in, as a seat coming online does');
    }

    /** @return array<string, string> */
    private function defects(?string $dir = null): array
    {
        $result = $this->floorRun(self::RUN, $dir);
        $move = $this->fixture(self::RUN)['messages'][1];
        $key = $move['envelope']['seat_id'];
        $this->assertSame(['install_id'], $move['envelope']['changed'], 'the run\'s second message is not the room move');

        $before = null;
        $after = null;

        foreach ($result['floor_renders'] as $render) {
            if (! isset($render['frame']['rooms'][0]['desks'])) {
                continue;
            }

            $slots = $this->slotsOf($render['frame'], 'aimla');

            if ($render['at'] < $move['at_ms'] && isset($slots[$key])) {
                $before = $slots;
            }

            if ($render['at'] >= $move['at_ms']) {
                $after = $slots;
            }
        }

        $this->assertNotNull($before, 'no frame drew the collision before the move');
        $this->assertNotNull($after, 'no frame was drawn after the move');
        $this->assertArrayNotHasKey($key, $after, 'the moved seat is still drawn in the room it left');

        $collision = $this->documentCollision();
        $displaced = $collision['displaced'];
        $defects = [];

        if (($before[$displaced] ?? null) !== $collision['moves_to'] || ($after[$displaced] ?? null) !== $collision['slot']) {
            $defects['chain'] = "{$displaced} did not move from slot {$collision['moves_to']} back into the freed slot {$collision['slot']}: "
                .json_encode([$before[$displaced] ?? null, $after[$displaced] ?? null]);
        }

        unset($before[$key], $before[$displaced], $after[$displaced]);

        if ($before !== $after) {
            $defects['others'] = 'a desk outside the collision chain moved: '.json_encode([$before, $after]);
        }

        // One A16 for the arrival (§ 3.3) and one for the move out, both naming the moved seat and both moving
        // the displaced desk and nothing else — as AT-D3-16's collision chain holds for a retirement. A row's `at`
        // is the corrected server clock, not the run's, so the two are told apart by count, as there.
        $moves = $this->rowsFor($result, 'A16');
        $named = array_filter($moves, static fn (array $r): bool => $r['cause'] === $key && $r['seat_id'] === $displaced);

        if (count($named) !== 2 || count($moves) !== 2) {
            $defects['a16'] = 'A16 rows: '.json_encode($moves);
        }

        return $defects;
    }
}

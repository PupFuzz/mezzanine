<?php

namespace Tests\Feature\Floor;

use App\Floor\FloorMap;
use Tests\TestCase;

/**
 * **A ROOM MAP'S `desks` LAYER INSIDE A TILED `group` IS SEATED** (card#11187).
 * `docs/design/FLOOR.md` § 10.3 reads `layers` "at every level Tiled nests it", and the server's
 * reader (`App\Floor\FloorMap`) finds the `desks` object layer inside a `group` — so the console
 * ACCEPTS such a map and stores it with its `S`. The client must then seat that map's desks: a
 * reader that looked at the top level only rendered `S = 0` and sent every seat of the room to the
 * overflow row under a *floor map is short* notice, for a map the server had just called valid.
 *
 * ⛔ THE TWO READERS ARE HELD TO ONE ANSWER OVER ONE DOCUMENT. The map is the one the
 * `plan_grouped_desks` run serves, decoded from the checked-in fixture file — never an inline copy —
 * and the server's `S` for it is compared with what the SHIPPED floor screen drew for that room.
 *
 * ⛔ AND THE GROUPING IS A NO-OP FOR THE FLOOR. `plan_grouped_desks` is `plan_default` with only
 * `aimla`'s `desks` layer moved into a group, so the room's desks — key, slot and pixel — must be
 * exactly `plan_default`'s. `beta`, left at the top level, is the same run's control.
 */
class ADeskLayerInsideAGroupIsSeatedTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const GROUPED = 'plan_grouped_desks';

    private const FLAT = 'plan_default';

    private const ROOM = 'aimla';

    /**
     * The map the run serves for `aimla`, re-encoded from an OBJECT-mode decode of the fixture
     * file, so `{}` and `[]` reach the server's reader as the bytes the fixture holds.
     */
    private function servedMap(): \stdClass
    {
        $file = json_decode((string) file_get_contents(__DIR__.'/fixtures/fx-floor-plan.json'), false, 512, JSON_THROW_ON_ERROR);
        $responses = $file->runs->{self::GROUPED}->http->{'/api/building/rooms/'.self::ROOM.'/map'};

        $this->assertCount(1, $responses, 'the run no longer scripts exactly one map response for the room');

        return $responses[0]->body->map;
    }

    /**
     * THE CONTROL: the run's map really does hold its `desks` layer inside a group and nowhere at the
     * top level — otherwise every assertion below would pass over a flat map and prove nothing.
     */
    public function test_control_the_served_map_holds_its_desks_layer_only_inside_a_group(): void
    {
        $top = $this->servedMap()->layers;

        $flat = array_filter($top, static fn (\stdClass $l): bool => $l->type === 'objectgroup' && $l->name === FloorMap::SLOT_LAYER);
        $groups = array_filter($top, static fn (\stdClass $l): bool => $l->type === 'group'
            && array_filter($l->layers, static fn (\stdClass $c): bool => $c->type === 'objectgroup' && $c->name === FloorMap::SLOT_LAYER) !== []);

        $this->assertSame([], array_values($flat), 'the served map carries a top-level `desks` layer, so it does not exercise a group');
        $this->assertCount(1, $groups, 'the served map carries no group holding the `desks` layer');
    }

    /**
     * GREEN — the server accepts the map, and the floor screen draws the room with exactly the `S`
     * the server counted, every seat in a slot and none in the overflow row.
     */
    public function test_green_the_client_seats_the_desks_the_server_accepted(): void
    {
        $serverSlots = FloorMap::parse(json_encode($this->servedMap(), JSON_THROW_ON_ERROR))->slots;

        $this->assertGreaterThan(0, $serverSlots, 'the server counted no desk slot in the grouped map');

        $room = $this->roomOf($this->lastFloor($this->floorRun(self::GROUPED)), self::ROOM);

        $this->assertSame($serverSlots, $room['slots'],
            "the floor screen read S = {$room['slots']} for a map the server accepted with S = {$serverSlots}");
        $this->assertSame([], $room['overflow'], 'seats of a room whose map has free desks were sent to the overflow row');
        $this->assertSame([], array_values(array_filter($room['notices'],
            static fn (string $n): bool => str_contains($n, 'short'))),
            'a *floor map is short* notice was drawn over a map with a desk for every seat');
    }

    /**
     * GREEN — moving the layer into a group moves no desk: the room's desks, slots and pixels are
     * `plan_default`'s, and the control room `beta` is untouched.
     */
    public function test_green_a_grouped_desks_layer_draws_the_same_room_as_a_flat_one(): void
    {
        $grouped = $this->lastFloor($this->floorRun(self::GROUPED));
        $flat = $this->lastFloor($this->floorRun(self::FLAT));

        foreach ([self::ROOM, 'beta'] as $installId) {
            $this->assertSame($this->roomOf($flat, $installId)['desks'], $this->roomOf($grouped, $installId)['desks'],
                "[{$installId}] its desks differ between the flat and the grouped `desks` layer");
            $this->assertSame($this->roomOf($flat, $installId)['slots'], $this->roomOf($grouped, $installId)['slots'],
                "[{$installId}] its S differs between the flat and the grouped `desks` layer");
        }
    }
}

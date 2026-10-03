<?php

namespace Tests\Feature\Floor;

use App\Floor\FloorMap;
use Tests\Feature\Admin\FloorMapFixture;
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
 *
 * ⛔ THE TILES WALK THE SAME TREE. `floor/scene.js`'s `mapTiles()` and `mapDesks()` both read the
 * layer tree through `floor/floor-layout.js`'s `mapLayers()`, so the last test holds the tile half
 * of that walk — a group's offset summed, its opacity multiplied, a hidden group's tiles left out —
 * over a map the server's reader also accepts.
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

    /**
     * GREEN — a tile layer two groups deep is drawn at the groups' summed offset with their
     * multiplied opacity, and a tile layer inside a HIDDEN group draws nothing, over a map the
     * server accepts. The flat map is the control: the same tile count, at no offset, opaque.
     */
    public function test_green_a_tile_layer_inside_groups_is_drawn_through_the_same_walk(): void
    {
        $flat = FloorMapFixture::decoded(1);
        $room = $flat['layers'][0];
        $hidden = ['id' => 7, 'name' => 'hidden-room'] + $room;

        $grouped = $flat;
        $grouped['nextlayerid'] = 8;
        $grouped['layers'][0] = [
            'id' => 3, 'type' => 'group', 'name' => 'scenery', 'offsetx' => 32, 'opacity' => 0.5, 'visible' => true,
            'layers' => [[
                'id' => 4, 'type' => 'group', 'name' => 'floor', 'offsety' => 16, 'opacity' => 0.5, 'visible' => true,
                'layers' => [$room],
            ]],
        ];
        $grouped['layers'][] = [
            'id' => 6, 'type' => 'group', 'name' => 'drafts', 'opacity' => 1, 'visible' => false, 'layers' => [$hidden],
        ];

        $this->assertSame(1, FloorMap::parse(FloorMapFixture::encode($grouped))->slots, 'the server refused the grouped tile map');

        [$flatTiles, $groupedTiles] = $this->tilesOf([$flat, $grouped]);

        $this->assertNotSame([], $flatTiles, 'the flat control map drew no tile, so the comparison asserts nothing');
        $this->assertCount(count($flatTiles), $groupedTiles, 'the grouped map drew a different number of tiles than the flat one');
        $this->assertSame(['room'], array_values(array_unique(array_column($groupedTiles, 'layer'))),
            'a layer other than the visible group\'s tile layer was drawn — a hidden group\'s tiles leaked');

        foreach ($flatTiles as $i => $tile) {
            $this->assertSame($tile['x'] + 32, $groupedTiles[$i]['x'], "tile {$i} is not at the groups' summed x offset");
            $this->assertSame($tile['y'] + 16, $groupedTiles[$i]['y'], "tile {$i} is not at the groups' summed y offset");
            $this->assertEqualsWithDelta(0.25, $groupedTiles[$i]['opacity'], 1e-9, "tile {$i} does not carry the groups' multiplied opacity");
            $this->assertEqualsWithDelta(1.0, $tile['opacity'], 1e-9, "the flat control's tile {$i} is not opaque");
        }
    }

    /**
     * The SHIPPED `mapTiles()` over each map, at the floor's origin with no tileset loaded.
     *
     * @param  list<array<string, mixed>>  $maps
     * @return list<list<array<string, mixed>>>
     */
    private function tilesOf(array $maps): array
    {
        $scene = json_encode('file://'.realpath(__DIR__.'/../../../public/js/floor/scene.js'));
        $script = 'import { readFileSync } from "node:fs";'
            .'const { mapTiles } = await import('.$scene.');'
            .'const maps = JSON.parse(readFileSync(0, "utf8"));'
            .'console.log(JSON.stringify(maps.map((m) => mapTiles(m, { x: 0, y: 0 }, () => null, "room"))));';

        $process = proc_open(['node', '--input-type=module', '-e', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'node could not be started');
        fwrite($pipes[0], (string) json_encode($maps));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "the mapTiles node run failed:\n".$stderr);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "the mapTiles node run printed something that is not JSON:\n".$stdout);
        $this->assertCount(count($maps), $decoded);

        return $decoded;
    }
}

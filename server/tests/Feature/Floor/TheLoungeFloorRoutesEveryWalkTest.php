<?php

namespace Tests\Feature\Floor;

use App\Floor\FloorMap;
use App\Floor\FurnitureBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeatRows;
use Tests\TestCase;

/**
 * card#12846 — A SECOND ROOM LAYOUT, `fixtures/maps/lounge-floor.json` (`docs/design/FLOOR.md § 10.3`'s *The lounge
 * floor*): the arrangement the aimla floor is authored with, so it no longer shares the sola floor's. It is a room map
 * like any an operator authors, applied through the console; this test holds it to everything the shipped default is
 * held to.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CONSOLE's OWN WRITE, NOT A COPY OF ITS RULES. The map is saved through `admin.floors.store` — the form an
 * operator pastes it into, `App\Floor\TiledMap` over `App\Floor\FloorMap::parse()` and the store's write — and read back
 * from the room-map surface the floor fetches, byte for byte.
 *
 * ⛔ EVERY WALK, BOTH WAYS, ON ITS WALKER's OWN LATTICE (`walk-probe.mjs`'s `walks`): each seat to and from the elevator
 * (A2, A1), the tall snack table (A25) and the restroom door (A26), the PM's through its office's doorway among them, and
 * each of each desk's intern stools to and from the elevator (A10), the low table (A25) and the door (A26). Every one
 * routes at the `clear` tier and every seat's keeps off its own couch (`furniture: kept`, card#12513) — what the shipped
 * default routes, which the same probe measures here first. Along every drawn polyline the body keeps clear of every wall,
 * standing tile and other desk, the margins kept (`ChecksAWalkersBody`, card#12442), and no seat's feet stand on its couch.
 *
 * ⛔ THE PLACEMENT RULES (§ 6.6 item 3, § 6.7 item 3, § 10.3, § 10.6 item 6), re-derived from the file: one tall and one
 * low snack table and one restroom door; every wall cell and every standing piece's cell rect inside the grid and outside
 * every `desks` object; the two tables' spot 0s and the door's threshold found, the spot 0s at least the tall table's
 * spacing apart and the threshold at least *Queue spacing* from both.
 *
 * ⛔ THE PROBE's SPOTS ARE THE SCENE's. Its restroom threshold is checked against the shipped scene's own on a real floor
 * run over the shipped default, and its snack spot 0s against § 12's *Snack spot*, before this test relies on either.
 *
 * ⛔ EACH RED IS A PLANT seen to break the GREEN it guards: the PM office's doorway walled shut (the PM's walks stop
 * routing), and the restroom door moved under a desk (the placement rule refuses it).
 */
class TheLoungeFloorRoutesEveryWalkTest extends TestCase
{
    use ChecksAWalkersBody;
    use DrivesTheFloorScreen;
    use RefreshDatabase;

    private const LOUNGE = __DIR__.'/fixtures/maps/lounge-floor.json';

    private const TSX = __DIR__.'/../../../../resources/floor/tiles/floor-plane.tsx';

    /** The PM office's doorway on the lounge floor: the unpainted cells of its back wall's row, against the partition. */
    private const DOORWAY = ['row' => 47, 'from' => 64, 'to' => 75];

    private function defaultMapPath(): string
    {
        return (string) realpath(base_path('../resources/floor/default.tmj'));
    }

    /** @var array<string, array<string, mixed>> one probe per map and plant, shared across this class's tests */
    private static array $walked = [];

    /** @return array<string, mixed> every walk of a floor (`walk-probe.mjs`'s `walks`) */
    private function walks(?string $map, array $walls = []): array
    {
        return self::$walked[json_encode([$map, $walls])] ??= $this->probe(['mode' => 'walks', 'map' => $map, 'walls' => $walls], null, __DIR__.'/walk-probe.mjs');
    }

    /**
     * Every walk of a probed floor that does not route at `clear`, that a seat routes across its own couch, or whose drawn
     * body meets a wall, a standing tile or another desk — bare, and with § 12's margins — or whose seat's feet stand on
     * its own side table.
     *
     * @return list<string>
     */
    private function walkDefects(array $floor): array
    {
        $c = $this->clearance();
        $defects = [];
        $solid = array_column($floor['desks'], 'solid', 'key');

        foreach ($floor['walks'] as $w) {
            $what = "{$w['who']} {$w['kind']} of {$w['desk']}".($w['stool'] === null ? '' : " stool {$w['stool']}");

            if ($w['tier'] !== 'clear') {
                $defects[] = "{$what}: routed at ".json_encode($w['tier']).', not clear';

                continue;
            }

            if ($w['who'] === 'seat' && $w['furniture'] !== 'kept') {
                $defects[] = "{$what}: routed across its own couch ({$w['furniture']})";
            }

            $body = $floor['bodies'][$w['who']];
            $as = ['body' => $body] + $floor;
            $desk = ['key' => $w['desk'], 'clearance' => $w['tier'], 'points' => $w['points']];

            array_push($defects, ...array_map(static fn ($d) => "{$what}: {$d}", $this->bodyDefects($as, $desk, $body['w'] / 2, 0.0)));
            array_push($defects, ...array_map(static fn ($d) => "{$what}: {$d}", $this->bodyDefects($as, $desk, $body['w'] / 2 + $c['margin'], $c['depth'])));

            if ($w['who'] === 'seat') {
                array_push($defects, ...$this->couchCrossing($what, $w['points'], $solid[$w['desk']]));
            }
        }

        return $defects;
    }

    /**
     * The placement rules, re-derived from a map document: tile kinds and sizes from the shipped tileset, each standing
     * piece's rect bottom-aligned on its cell (`mapTiles()`), every wall cell and piece inside the grid and outside every
     * `desks` object; one tall table, one low table and one restroom door.
     *
     * @return list<string>
     */
    private function placementDefects(array $doc): array
    {
        $tiles = [];

        foreach (simplexml_load_file(self::TSX)->tile as $t) {
            $kind = null;

            foreach ($t->properties->property ?? [] as $p) {
                if ((string) $p['name'] === 'kind') {
                    $kind = (string) $p['value'];
                }
            }

            $tiles[1 + (int) $t['id']] = ['kind' => $kind, 'w' => (int) $t->image['width'], 'h' => (int) $t->image['height']];
        }

        $tw = $doc['tilewidth'];
        $gw = $doc['width'] * $tw;
        $gh = $doc['height'] * $doc['tileheight'];
        $desks = [];
        $placed = [];
        $defects = [];

        foreach ($doc['layers'] as $l) {
            if ($l['type'] === 'objectgroup' && $l['name'] === 'desks') {
                $desks = $l['objects'];
            }
        }

        $meets = static fn (array $r, array $o): bool => $r['x'] < $o['x'] + $o['width'] && $o['x'] < $r['x'] + $r['w']
            && $r['y'] < $o['y'] + $o['height'] && $o['y'] < $r['y'] + $r['h'];

        foreach ($doc['layers'] as $l) {
            if ($l['type'] !== 'tilelayer') {
                continue;
            }

            foreach ($l['data'] as $i => $gid) {
                if ($gid === 0) {
                    continue;
                }

                $t = $tiles[$gid] ?? null;
                $col = $i % $doc['width'];
                $row = intdiv($i, $doc['width']);

                if ($t === null || $t['kind'] === null) {
                    $defects[] = "{$l['name']} ({$col}, {$row}): GID {$gid} is no kind of the shipped tileset";

                    continue;
                }

                $r = $t['kind'] === 'wall' || $t['kind'] === 'accent'
                    ? ['x' => $col * $tw, 'y' => $row * $tw, 'w' => $tw, 'h' => $tw]
                    : ['x' => $col * $tw, 'y' => ($row + 1) * $tw - $t['h'], 'w' => $t['w'], 'h' => $t['h']];

                if ($t['kind'] === 'accent') {
                    continue;
                }

                $placed[$t['kind']] = ($placed[$t['kind']] ?? 0) + 1;

                if ($r['x'] < 0 || $r['y'] < 0 || $r['x'] + $r['w'] > $gw || $r['y'] + $r['h'] > $gh) {
                    $defects[] = "a `{$t['kind']}` at ({$col}, {$row}) leaves the grid";
                }

                foreach ($desks as $o) {
                    if ($meets($r, $o)) {
                        $defects[] = "a `{$t['kind']}` at ({$col}, {$row}) meets `desks` object id {$o['id']}";
                    }
                }
            }
        }

        foreach (['snack-table', 'snack-table-low', 'restroom-door'] as $kind) {
            if (($placed[$kind] ?? 0) !== 1) {
                $defects[] = 'the map places '.($placed[$kind] ?? 0)." `{$kind}`, not one";
            }
        }

        return $defects;
    }

    /** The spot 0s and the threshold found, and held apart as § 6.6 and § 6.7 hold them. @return list<string> */
    private function spotDefects(array $floor): array
    {
        $s = $floor['spots'];
        $c = $this->clearance();
        $spacing = $floor['bodies']['seat']['w'] + $c['margin'];
        $defects = [];

        foreach ($s as $kind => $p) {
            if ($p === null) {
                $defects[] = "the `{$kind}` serves nobody — no spot 0 or threshold is open";
            }
        }

        if ($defects !== []) {
            return $defects;
        }

        $apart = static fn (array $a, array $b): float => hypot($a['x'] - $b['x'], $a['y'] - $b['y']);

        if ($apart($s['snack-table'], $s['snack-table-low']) < $spacing) {
            $defects[] = 'the two snack tables\' spot 0s lie under the tall table\'s spacing apart';
        }

        foreach (['snack-table', 'snack-table-low'] as $kind) {
            if ($apart($s['restroom-door'], $s[$kind]) < $spacing) {
                $defects[] = "the restroom threshold lies under *Queue spacing* from `{$kind}`'s spot 0";
            }
        }

        return $defects;
    }

    // ── The console ─────────────────────────────────────────────────────────────────────────────

    /** Saved through the console's form for `aimla`, served back byte for byte, its five desks and the PM's reserved. */
    public function test_green_the_console_saves_it_for_aimla_and_serves_it(): void
    {
        $document = (string) file_get_contents(self::LOUNGE);
        $map = FloorMap::parse($document);

        $this->assertSame(5, $map->slots);
        $this->assertSame('id 3 for pm', $map->reservationLabel());

        $now = now()->format('Y-m-d H:i:s.v');
        $install = DB::table('installs')->insertGetId(['install_id' => 'aimla', 'created_at' => $now]);
        $seat = SeatRows::insert($install, 'aimla-pm', ['created_at' => $now]);
        DB::table('seat_state')->insert([
            'seat_ref' => $seat, 'render_state' => 'offline', 'link_state' => 'offline', 'activity_state' => 'unknown',
            'unknown_reason' => 'no_data_yet', 'state_computed_at' => $now, 'updated_at' => $now,
        ]);
        $operator = User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']);

        $this->actingAs($operator)
            ->post(route('admin.floors.store'), ['install_id' => 'aimla', 'map' => $document])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.floors.index'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'aimla now has a map declaring 5 desk slots'));

        $served = $this->actingAs($operator)->getJson('/api/building/rooms/aimla/map')->assertOk();

        $this->assertEquals(json_decode($document, true), $served->json('map'), 'the floor is served another document than the one saved');
    }

    // ── The walks ───────────────────────────────────────────────────────────────────────────────

    /**
     * The probe's spots are the ones the scene walks to. The restroom threshold: the shipped scene's own, read off a real
     * floor run over the shipped default (`scene_default`). Each snack table's spot 0: § 12's *Snack spot* — half the
     * visitor's drawn width left of the table's front-left corner, *Walker clearance*'s depth plus 1 px below its foot,
     * snapped to its 8 px cell's centre, the rule `ACharacterVisitsTheSnackCornerTest` holds the scene's trips to — on the
     * shipped default and on the lounge floor, where both tables stand with that corner open.
     */
    public function test_green_the_probes_spots_are_the_scenes(): void
    {
        $door = $this->lastFloor($this->floorRun('scene_default'))['scene']['restroom_doors'][0] ?? null;

        $this->assertNotNull($door, 'scene_default drew no restroom door');
        $this->assertEquals($door['threshold'], $this->walks($this->defaultMapPath())['spots']['restroom-door'], 'the probe\'s restroom threshold is not the scene\'s');

        $depth = $this->clearance()['depth'];
        $snap = static fn (float $v): float => floor($v / 8) * 8 + 4;

        foreach ([$this->defaultMapPath(), self::LOUNGE] as $map) {
            $floor = $this->walks($map);
            $tables = array_filter($floor['obstacles'], static fn ($o) => in_array($o['kind'], ['snack-table', 'snack-table-low'], true));

            $this->assertCount(2, $tables);

            foreach ($tables as $t) {
                $w = $floor['bodies'][$t['kind'] === 'snack-table' ? 'seat' : 'intern']['w'];

                $this->assertEquals(['x' => $snap($t['x'] - $w / 2), 'y' => $snap($t['y'] + $t['h'] + $depth + 1)], $floor['spots'][$t['kind']],
                    basename($map).": the probe's `{$t['kind']}` spot 0 is not § 12's *Snack spot*");
            }
        }
    }

    /** The shipped default, measured by the same probe: every walk routes at `clear`, every seat's off its couch. */
    public function test_green_the_shipped_default_routes_every_walk(): void
    {
        $floor = $this->walks($this->defaultMapPath());

        $this->assertNotSame([], $floor['walks']);
        $this->assertSame([], $this->walkDefects($floor));
    }

    /** The lounge floor routes every walk the shipped default does, as the default routes it. */
    public function test_green_the_lounge_floor_routes_every_walk(): void
    {
        $floor = $this->walks(self::LOUNGE);
        $kinds = array_unique(array_map(static fn ($w) => "{$w['who']} {$w['kind']}", $floor['walks']));

        sort($kinds);
        $this->assertSame(['intern A10 in', 'intern A10 out', 'intern A25 home', 'intern A25 there', 'intern A26 home', 'intern A26 there',
            'seat A1', 'seat A2', 'seat A25 home', 'seat A25 there', 'seat A26 home', 'seat A26 there'], $kinds);
        $this->assertSame(['desk-1', 'desk-2', 'desk-3', 'desk-4', 'desk-5'], array_column($floor['desks'], 'key'));
        $this->assertSame('pm', $floor['desks'][2]['reserved_for'], 'the PM office\'s desk is not the reserved one');
        $this->assertSame([], $this->walkDefects($floor));
    }

    /** The snack corner and the restroom door, placed by their rules; every wall and piece off every desk. */
    public function test_green_the_lounge_floor_places_its_corner_and_door_by_their_rules(): void
    {
        $doc = json_decode((string) file_get_contents(self::LOUNGE), true);
        $box = FurnitureBox::current();

        $this->assertSame([], $this->placementDefects($doc));
        $this->assertSame([], $this->spotDefects($this->walks(self::LOUNGE)));
        $default = json_decode((string) file_get_contents($this->defaultMapPath()), true);
        $grid = static fn (array $m): array => [$m['width'] * $m['tilewidth'], $m['height'] * $m['tileheight']];

        $this->assertSame($grid($default), $grid($doc),
            'the lounge floor\'s grid is not the shipped default\'s — the camera\'s fit and a planned floor\'s footprint move with it');

        foreach ($doc['layers'] as $l) {
            foreach ($l['type'] === 'objectgroup' ? $l['objects'] : [] as $o) {
                $this->assertGreaterThanOrEqual($box->width, $o['width']);
                $this->assertGreaterThanOrEqual($box->height, $o['height']);
            }
        }
    }

    // ── The REDs ────────────────────────────────────────────────────────────────────────────────

    /** The PM office's doorway walled shut: the PM's walks stop routing, and the walk guard sees it. */
    public function test_red_the_office_doorway_walled_shut(): void
    {
        $walls = array_map(static fn (int $col): array => [$col, self::DOORWAY['row']], range(self::DOORWAY['from'], self::DOORWAY['to']));
        $defects = $this->walkDefects($this->walks(self::LOUNGE, $walls));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_starts_with($d, 'seat A2 of desk-3:')),
            'CONTROL (the doorway walled shut) left the PM\'s walk to the elevator routed at clear');
        $this->assertSame([], array_filter($defects, static fn (string $d): bool => ! str_contains($d, 'of desk-3')),
            'walling the office\'s doorway broke a walk outside the office');
    }

    /** The restroom door moved onto a desk: the placement guard refuses it. */
    public function test_red_the_door_moved_under_a_desk(): void
    {
        $doc = json_decode((string) file_get_contents(self::LOUNGE), true);

        foreach ($doc['layers'] as &$l) {
            if ($l['type'] === 'tilelayer' && in_array(16, $l['data'], true)) {
                $l['data'][array_search(16, $l['data'], true)] = 0;
                // desk 2's slot spans x 1,136–1,576, y 24–311: the door's foot cell at (1,200, 296)
                $l['data'][36 * $doc['width'] + 150] = 16;
            }
        }
        unset($l);

        $this->assertNotSame([], array_filter($this->placementDefects($doc), static fn (string $d): bool => str_contains($d, '`restroom-door`')
            && str_contains($d, 'meets `desks` object id 2')), 'CONTROL (the door under desk 2) was not refused');
    }
}

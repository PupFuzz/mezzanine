<?php

namespace Tests\Feature\Admin;

use App\Building\Revisions;
use App\Floor\RetiredArt;
use App\Fold\Clock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **A stored map that names art the repository no longer ships is LISTED, never refused** —
 * `docs/design/FLOOR.md § 14` item 36(5), the extension of item 28(1)(iii)'s re-validation listing adopted with
 * card#11046's Appendix B row 22 (§ 10.6 item 9). Row 22 retired the bridge kit's tileset and the floor
 * tileset's plank and rug tiles; a room map stored before it may name either. The floors module lists the room
 * with what it names, and the map stays current and served until its author saves one that names only what
 * ships.
 *
 * ⚠ SUCH A MAP CAN ONLY EXIST ONE WAY: it was stored before the art left the tree, so the console's write —
 * which refuses a tileset the repository does not ship — cannot produce it. It is planted here as the store
 * holds it.
 */
class TheConsoleListsMapsNamingRetiredArtTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = 'aimla';

    private ?User $operator = null;

    public function test_a_map_naming_the_retired_kit_is_listed_with_the_tileset_and_still_served(): void
    {
        $this->provisionTheRoom();
        $this->plantACurrentMap($this->naming('tiles/furniture-kit.tsx', 1));

        $this->index()
            ->assertSee('rooms-naming-retired-art')
            ->assertSee('names the tileset `tiles/furniture-kit.tsx`, which the repository no longer ships', false);

        $this->actingAs($this->operator())->getJson('/api/building/rooms/'.self::INSTALL.'/map')->assertOk();
    }

    public function test_a_map_placing_the_retired_planks_is_listed_with_the_tile_and_its_cells(): void
    {
        $this->provisionTheRoom();
        $this->plantACurrentMap($this->naming('tiles/floor-plane.tsx', 1));

        $this->index()
            ->assertSee('rooms-naming-retired-art')
            ->assertSee('places tile 0 of `tiles/floor-plane.tsx`', false);
    }

    /** THE CONTROL: a map naming only what ships is not listed — the listing is not satisfied by listing every room. */
    public function test_a_map_naming_only_what_ships_is_not_listed(): void
    {
        $this->provisionTheRoom();
        $this->plantACurrentMap(FloorMapFixture::valid(2));

        $this->assertSame([], RetiredArt::of(FloorMapFixture::valid(2)));
        $this->index()->assertDontSee('rooms-naming-retired-art');
    }

    public function test_the_reader_names_each_tileset_and_each_retired_tile_once_with_its_count(): void
    {
        $map = FloorMapFixture::decoded(2);
        $map['tilesets'] = [['firstgid' => 1, 'source' => 'tiles/floor-plane.tsx'], ['firstgid' => 100, 'source' => 'tiles/furniture-kit.tsx']];
        $map['layers'][0]['data'] = array_merge([1, 1, 2, 3 | 0x80000000, 100], array_fill(0, count($map['layers'][0]['data']) - 5, 0));

        $this->assertSame([
            'names the tileset `tiles/furniture-kit.tsx`, which the repository no longer ships',
            'places tile 0 of `tiles/floor-plane.tsx` on 2 cells, a tile the repository no longer ships',
            'places tile 1 of `tiles/floor-plane.tsx` on 1 cell, a tile the repository no longer ships',
        ], RetiredArt::of(FloorMapFixture::encode($map)));
    }

    /** The valid fixture with its one tileset re-pointed and its every cell set to `$gid`. */
    private function naming(string $source, int $gid): string
    {
        $map = FloorMapFixture::decoded(2);
        $map['tilesets'] = [['firstgid' => 1, 'source' => $source]];
        $map['layers'][0]['data'] = array_fill(0, count($map['layers'][0]['data']), $gid);

        return FloorMapFixture::encode($map);
    }

    private function index(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator())->get(route('admin.floors.index'))->assertOk();
    }

    private function operator(): User
    {
        return $this->operator ??= User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']);
    }

    /** A seat in the room, so the floors module lists it. */
    private function provisionTheRoom(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $installRef = DB::table('installs')->insertGetId(['install_id' => self::INSTALL, 'created_at' => $now]);
        $seatRef = DB::table('seats')->insertGetId(['install_ref' => $installRef, 'seat_id' => 'aimla-pm', 'created_at' => $now]);

        DB::table('seat_state')->insert([
            'seat_ref' => $seatRef,
            'render_state' => 'offline',
            'link_state' => 'offline',
            'activity_state' => 'unknown',
            'unknown_reason' => 'no_data_yet',
            'state_computed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** A current map as a store written before row 22 holds it — appended to the log and made current. */
    private function plantACurrentMap(string $document): void
    {
        $at = Clock::sql(now());

        DB::table('authored_revisions')->insert([
            'kind' => Revisions::ROOM_MAP,
            'subject' => self::INSTALL,
            'revision' => 1,
            'document' => $document,
            'restored_from' => null,
            'authored_by' => 'an operator before row 22',
            'authored_at' => $at,
            'furniture_box' => null,
        ]);
        DB::table('floors')->insert([
            'install_id' => self::INSTALL,
            'map' => $document,
            'map_version' => 1,
            'updated_by' => 'an operator before row 22',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}

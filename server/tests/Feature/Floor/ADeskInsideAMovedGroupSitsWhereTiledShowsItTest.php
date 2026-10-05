<?php

namespace Tests\Feature\Floor;

use App\Floor\DeskSlots;
use App\Floor\FloorMap;
use App\Floor\FurnitureBox;
use App\Floor\InvalidFloorMap;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **A DESK INSIDE A MOVED TILED GROUP SITS WHERE TILED SHOWS IT** (card#11252, operator ruling
 * 2026-10-04). `docs/design/FLOOR.md` § 10.3's `desks` row: a slot's position is its object's own
 * `x`/`y` plus the summed `offsetx`/`offsety` of the `desks` layer and every `group` above it — the
 * sum Tiled draws it at, and the sum the client already drew the room's TILES at. Before this card
 * the tiles moved with a dragged group and the desks did not, so an author saw desks misplaced
 * against their furniture, and the server's *wholly inside the grid* check judged a position the
 * floor never drew.
 *
 * ⛔ THE TWO READERS ARE HELD TO ONE ANSWER OVER ONE DOCUMENT. Each map is parsed by the server's
 * `App\Floor\FloorMap` (the console's write) and read by the SHIPPED `floor/floor-layout.js`'s
 * `mapDesks()` (what the floor screen and the scene place desks from), and both must state the
 * shifted position.
 *
 * ⛔ EVERY MAP IS ONE MUTATION OF THE FIXTURE'S VALID ONE-DESK MAP — a 640 × 256 px grid with one
 * desk at the furniture box from the corner — so the offsets below are the only difference.
 */
class ADeskInsideAMovedGroupSitsWhereTiledShowsItTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const MODULE = 'floor-layout.js';

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor')
            ?: $this->fail('server/public/js/floor does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/map-desks-probe.mjs';
    }

    // ── the maps ────────────────────────────────────────────────────────────────────────────

    /**
     * The fixture's one-desk map with its `desks` layer moved inside a group offset by
     * `($gx, $gy)`, the layer itself offset by `($lx, $ly)`.
     *
     * @return array<string, mixed>
     */
    private function grouped(float|int $gx, float|int $gy, float|int $lx = 0, float|int $ly = 0): array
    {
        $map = FloorMapFixture::decoded(1);
        $desks = $map['layers'][1] + ['offsetx' => $lx, 'offsety' => $ly];

        $map['nextlayerid'] = 4;
        $map['layers'][1] = [
            'id' => 3, 'type' => 'group', 'name' => 'office', 'offsetx' => $gx, 'offsety' => $gy,
            'opacity' => 1, 'visible' => true, 'layers' => [$desks],
        ];

        return $map;
    }

    /**
     * The fixture's one-desk map with only the `desks` layer's own offset — no group.
     *
     * @return array<string, mixed>
     */
    private function layerOffset(float|int $lx, float|int $ly): array
    {
        $map = FloorMapFixture::decoded(1);
        $map['layers'][1] += ['offsetx' => $lx, 'offsety' => $ly];

        return $map;
    }

    /** @param array<string, mixed> $map */
    private function serverDesk(array $map): array
    {
        $desks = FloorMap::parse(FloorMapFixture::encode($map))->desks;

        $this->assertCount(1, $desks, 'the server read a different number of desks than the one the map declares');

        return $desks[0];
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function clientDesk(array $map, ?string $dir = null): array
    {
        $out = $this->probe([$map], $dir);

        $this->assertCount(1, $out);
        $this->assertCount(1, $out[0], 'the client read a different number of desks than the one the map declares');

        return $out[0][0];
    }

    private function refusal(array $map): ?string
    {
        try {
            FloorMap::parse(FloorMapFixture::encode($map));
        } catch (InvalidFloorMap $e) {
            return $e->getMessage();
        }

        return null;
    }

    // ── controls ────────────────────────────────────────────────────────────────────────────

    /**
     * THE CONTROL: the flat map puts its desk at the corner on both readers, and a group with no
     * offset moves nothing — so every shift asserted below is the offset's and nothing else's.
     */
    public function test_control_with_no_offset_the_desk_is_at_its_own_x_and_y_on_both_readers(): void
    {
        $box = FurnitureBox::current();

        foreach ([FloorMapFixture::decoded(1), $this->grouped(0, 0)] as $map) {
            $server = $this->serverDesk($map);
            $client = $this->clientDesk($map);

            $this->assertSame([0.0, 0.0, (float) $box->width, (float) $box->height], [$server['x'], $server['y'], $server['w'], $server['h']]);
            $this->assertSame([0, 0], [$client['x'], $client['y']]);
        }
    }

    // ── the server: the write judges the shifted desk ──────────────────────────────────────

    /** GREEN — a desk inside a group offset by (96, 16) whose layer is offset by (64, 8) is at (160, 24). */
    public function test_green_the_server_reads_a_grouped_desk_at_the_groups_and_the_layers_summed_offset(): void
    {
        $desk = $this->serverDesk($this->grouped(96, 16, 64, 8));

        $this->assertSame([160.0, 24.0], [$desk['x'], $desk['y']]);
    }

    /** GREEN — the `desks` layer's own offset, with no group, moves the desk too. */
    public function test_green_the_server_reads_a_desks_layers_own_offset(): void
    {
        $desk = $this->serverDesk($this->layerOffset(32.5, 4));

        $this->assertSame([32.5, 4.0], [$desk['x'], $desk['y']]);
    }

    /**
     * GREEN — a desk the offset carries past the grid is refused at its SHIFTED span; the same
     * desk with no offset is inside (the control above), so the refusal is the offset's.
     */
    public function test_green_a_desk_a_group_moves_out_of_the_grid_is_refused_at_its_shifted_span(): void
    {
        $box = FurnitureBox::current();

        // 640 px of grid, a 440 px desk: a 256 px shift ends it at 696.
        $right = $this->refusal($this->grouped(256, 0));

        $this->assertNotNull($right, 'a desk a group moved past the right edge of the grid was accepted');
        $this->assertStringContainsString('is not wholly inside this room\'s grid', $right);
        $this->assertStringContainsString(sprintf('spans 256–%d', 256 + $box->width), $right);

        // A NEGATIVE layer offset carries it past the left edge.
        $left = $this->refusal($this->layerOffset(-1, 0));

        $this->assertNotNull($left, 'a desk a layer offset moved past the left edge of the grid was accepted');
        $this->assertStringContainsString('spans -1–', $left);

        // The two offsets SUM: neither alone leaves the grid, together they do (y: 256 − 228 = 28).
        $this->assertNull($this->refusal($this->grouped(0, 20, 0, 0)));
        $this->assertNull($this->refusal($this->grouped(0, 0, 0, 8)));
        $this->assertNotNull($this->refusal($this->grouped(0, 20, 0, 9)),
            'a desk the group and the layer offsets together moved past the bottom edge was accepted');
    }

    /** GREEN — the furniture-box checks name the desks at their shifted positions. */
    public function test_green_the_furniture_box_refusals_report_the_shifted_positions(): void
    {
        $map = $this->grouped(10, 4);
        $map['layers'][1]['layers'][0]['objects'][] = ['id' => 2, 'x' => 100, 'y' => 0, 'width' => 440, 'height' => 228];
        $map['width'] = 40;
        $map['layers'][0]['width'] = 40;
        $map['layers'][0]['data'] = array_fill(0, 40 * 8, 1);

        $refusals = DeskSlots::refusals(FloorMap::parse(FloorMapFixture::encode($map)), FurnitureBox::current());

        $this->assertCount(1, $refusals, 'the two overlapping desks were not refused exactly once');
        $this->assertStringContainsString('id 1 spans 10–450 × 4–232 and id 2 spans 110–550 × 4–232', $refusals[0]);
    }

    /** GREEN — an offset on the desks' path that is not a number is refused by name. */
    public function test_green_an_offset_on_the_desks_path_that_is_not_a_number_is_refused_naming_it(): void
    {
        $map = $this->grouped(0, 0);
        $map['layers'][1]['offsetx'] = '96';

        $message = $this->refusal($map);

        $this->assertNotNull($message, 'a group offset written as a string was accepted');
        $this->assertStringContainsString('Layer `office` declares `offsetx` as `96` rather than a number', $message);

        // A group the desks are NOT inside is not the desks' to refuse.
        $elsewhere = FloorMapFixture::decoded(1);
        $elsewhere['layers'][] = ['id' => 3, 'type' => 'group', 'name' => 'drafts', 'offsetx' => '96', 'layers' => []];
        $elsewhere['nextlayerid'] = 4;

        $this->assertNull($this->refusal($elsewhere));
    }

    // ── the client: the floor places the shifted desk ──────────────────────────────────────

    /**
     * GREEN — the shipped `mapDesks()` places each desk where the server judged it: the grouped map,
     * the layer-offset map, and a group two deep.
     */
    public function test_green_the_client_places_each_desk_where_the_server_judged_it(): void
    {
        $nested = $this->grouped(96, 16, 64, 8);
        $nested['layers'][1] = [
            'id' => 4, 'type' => 'group', 'name' => 'wing', 'offsetx' => 20, 'offsety' => 2,
            'opacity' => 1, 'visible' => true, 'layers' => [$nested['layers'][1]],
        ];
        $nested['nextlayerid'] = 5;

        foreach ([[$this->grouped(96, 16, 64, 8), 160, 24], [$this->layerOffset(32.5, 4), 32.5, 4], [$nested, 180, 26]] as [$map, $x, $y]) {
            $server = $this->serverDesk($map);
            $client = $this->clientDesk($map);

            $this->assertEquals([$x, $y], [$server['x'], $server['y']], 'the server did not judge the desk at the summed offset');
            $this->assertEquals([$server['x'], $server['y']], [$client['x'], $client['y']],
                'the client placed the desk somewhere other than where the server judged it');
        }
    }

    /** RED — a client that reads the object's own `x`/`y` (today's defect) misplaces the grouped desk. */
    public function test_red_a_client_that_ignores_the_offset(): void
    {
        $dir = $this->mutatedModules([self::MODULE,
            '            x: offset.x + (object?.x ?? 0),',
            '            x: object?.x ?? 0,',
        ]);

        $map = $this->grouped(96, 16, 64, 8);

        $this->assertNotEquals($this->serverDesk($map)['x'], $this->clientDesk($map, $dir)['x'],
            'the RED did not bite: a client ignoring the offset placed the desk where the server judged it');
    }
}

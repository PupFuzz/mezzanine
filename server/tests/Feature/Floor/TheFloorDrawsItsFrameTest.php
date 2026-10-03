<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\ReadsTheRenderStates;
use Tests\TestCase;

/**
 * **The floor draws its frame — around and under any map, never over an author's grid.** card#11045
 * PR-D: `docs/design/FLOOR.md` § 4.2's frame list, § 9 F13's wrapping bench, § 10.4's room theme and
 * AT-D3-20's clock clause, read from the scene's emitted rects.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE THE ROOM SHAPES A CONFIGURABLE PLAN PRODUCES (`fixtures/fx-frame.json`): the smallest
 * room a valid map makes (one desk on a 448 px grid, its bench wrapping), a sparse map (eight slots, four
 * seats), a planned floor of two ABUTTING rooms over a hallway, and a mapless room (F16) — plus
 * `fx-scene.json`'s clean runs on the shipped default. Every expectation is re-derived from the frame the
 * same render drew (its rooms' footprints, its hallway, its extent), never transcribed.
 *
 * ⛔ THE BAND IS ALSO SWEPT, WIDTH BY WIDTH FROM 1 px, THROUGH THE SHIPPED `backWall()` (`frame-probe.mjs`),
 * because no fixture reaches every width a stored map can have: maps saved before the console's refusals
 * (F21) can be narrower than one furniture box, and design review r3 MINOR-1 is that such a band draws no
 * window rather than one inside the reserved zone.
 *
 * ⛔ EVERY RED IS PLANTED IN THE SHIPPED MODULE THE DEFECT WOULD LIVE IN — a mutated copy of the tree — and
 * watched failing on the clause it names.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence the frame LOOKS right — there is no browser on the build host. The
 * screenshots on the PR's review round are where the look is checked.
 */
class TheFloorDrawsItsFrameTest extends TestCase
{
    use DrivesTheScene;
    use ReadsTheRenderStates;

    private const ONE_DESK = 'frame_one_desk';

    private const SPARSE = 'frame_sparse';

    private const PLANNED = 'frame_planned';

    private const MAPLESS = 'frame_mapless';

    private const FRAME_RUNS = [self::ONE_DESK, self::SPARSE, self::PLANNED, self::MAPLESS];

    /** `fx-scene.json`'s runs whose maps hold no F21 defect — every desk at rest inside its slot. */
    private const SHIPPED_RUNS = ['scene_default', 'scene_overflow', 'interns_cap', 'interns_bound'];

    /**
     * AT-D3-20's runs the clock clause reads besides those: every one but the undersized map, whose desk is
     * drawn past its slot's edge by F21's ruling.
     */
    private const CLOCK_RUNS = ['scene_default_reordered', 'scene_crowded', 'scene_crowded_reordered'];

    /** The sweep's widths: every one from 1 px to the widest floor a reasonable plan composes. */
    private const SWEEP_TO = 4000;

    /** WCAG 2's AA ratio for body text — the bar each state chip's fill holds its word to (card#11058 Q4 a). */
    private const CHIP_INK_FLOOR = 4.5;

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The band's parts in their places: the elevator and the clock inside the reserved zone, every window
     * and sill on the wall past it, the slab under the floor — and NOTHING the frame draws over an author's
     * grid (a room's footprint or the hallway's): each room's plane is exactly its own grid, under its tiles.
     */
    public function test_green_the_frame_stands_around_and_under_every_map_and_over_no_authors_grid(): void
    {
        foreach ([...self::FRAME_RUNS, ...self::SHIPPED_RUNS] as $run) {
            $result = $this->floorRun($run);

            $this->assertSame([], $this->frameDefects($this->lastScene($result, $run), $this->lastFloor($result)), "[{$run}]");
        }
    }

    /**
     * AT-D3-20's clock clause, scoped to elements AT REST: no band primitive and no scene element at rest
     * meets the wall clock's face — over every run here, and over the band at every width of the sweep.
     */
    public function test_green_nothing_at_rest_meets_the_clock_face(): void
    {
        foreach ([...self::FRAME_RUNS, ...self::SHIPPED_RUNS, ...self::CLOCK_RUNS] as $run) {
            $this->assertSame([], $this->clockDefects($this->sceneOf($run)), "[{$run}]");
        }

        $this->assertSame([], $this->sweepDefects());
    }

    /** Each room's plane takes its seeded theme — `fnv1a32(install_id) mod 4` over § 10.4's four. */
    public function test_green_each_room_takes_its_own_seeded_theme(): void
    {
        $scene = $this->sceneOf(self::PLANNED);

        $this->assertSame([], $this->themeDefects($scene));
        $this->assertNotSame($scene['planes'][0]['theme'], $scene['planes'][1]['theme'],
            'the two rooms of the planned run take one theme, so a theme keyed on anything but the room would pass here');
    }

    /** Every theme keeps the scene's ink at least as legible as oak, the default, on both ends of its shade. */
    public function test_green_every_theme_keeps_the_desk_text_as_legible_as_the_default(): void
    {
        $this->assertSame([], $this->contrastDefects($this->sheet()));
    }

    /**
     * Every state chip's fill holds the chip's word: each `--state-<member>` of § 7.1's members — the set
     * `RENDER_STATES` carries and the painter generates the chip rules from — keeps `--state-ink` at
     * 4.5:1 or better at full light (card#11218).
     */
    public function test_green_every_state_chip_holds_its_ink(): void
    {
        $this->assertSame([], $this->stateInkDefects($this->sheet()));
    }

    /** Q1: an unused slot is plain floor — no desk is drawn but a seat's. */
    public function test_green_an_unused_slot_is_plain_floor(): void
    {
        $result = $this->floorRun(self::SPARSE);

        $this->assertSame([], $this->spareDeskDefects($this->lastScene($result, self::SPARSE), $this->lastFloor($result)));
    }

    /** § 9 F13: the bench wraps at the floor's width, its desks disjoint and inside the strip. */
    public function test_green_the_bench_wraps_at_the_floors_width(): void
    {
        $result = $this->floorRun(self::ONE_DESK);

        $this->assertSame([], $this->benchDefects($this->lastScene($result, self::ONE_DESK), $this->lastFloor($result)));
    }

    /**
     * § 6.5: every frame run is a snapshot and fires no `edge` row and no effect, and the frame itself carries
     * no motion member. (A snapshot's held `entered` rows are the states it delivered, and stand.)
     */
    public function test_green_the_frame_runs_animate_nothing(): void
    {
        foreach (self::FRAME_RUNS as $run) {
            $result = $this->floorRun($run);
            $scene = $this->lastScene($result, $run);

            $this->assertSame([], array_values(array_filter($result['animation_log'], fn ($r) => $r['class'] === 'edge')),
                "[{$run}] a snapshot wrote an edge row");
            $this->assertSame([], $scene['effects'], "[{$run}] a snapshot drew an effect");
            $this->assertSame([], $this->motionIn([$scene['band'], $scene['slab'], $scene['planes']]),
                "[{$run}] the frame carries a motion member");
        }
    }

    /**
     * § 12's frame rows and `scene.js`'s constants are two homes of one figure each — the document states and
     * reasons about them, the scene draws them — so they are held equal here rather than trusted to stay so.
     */
    public function test_green_section_12s_frame_figures_are_the_scenes(): void
    {
        $this->assertSame([], $this->figureDefects($this->floorMd(), (string) file_get_contents($this->jsRoot().'/floor/scene.js')));
    }

    // ── RED — each defect planted in the shipped module it would live in ──────────────────────

    public function test_red_a_frame_figure_moved_in_one_home(): void
    {
        $scene = (string) file_get_contents($this->jsRoot().'/floor/scene.js');
        $md = $this->floorMd();

        foreach ([
            'the band' => ['export const BAND_H = 160;', 'export const BAND_H = 161;'],
            'the zone' => ['const ZONE_W = 272;', 'const ZONE_W = 273;'],
            'the pitch' => ['pitch: 360,', 'pitch: 361,'],
            'the glazing' => ['h: 124, dy: 16,', 'h: 125, dy: 16,'],
        ] as $what => [$from, $to]) {
            $this->assertSame(1, substr_count($scene, $from), "the control's anchor for {$what} is not in scene.js exactly once");
            $this->assertNotSame([], $this->figureDefects($md, str_replace($from, $to, $scene)), "RED ({$what} moved in scene.js alone) did not bite");
        }
    }

    /**
     * MINOR-1: the design's formula as first written — *at least one* window at every width, never narrower
     * than the minimum — which below the threshold can only stand that window in the reserved zone.
     */
    public function test_red_a_window_drawn_inside_the_zone_on_a_narrow_band(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            "    const n = remaining < WINDOW.min_w + WINDOW.gap ? 0 : Math.max(1, Math.floor(remaining / WINDOW.pitch));\n\n    for (let i = 0; i < n; i++) {\n        const cell = remaining / n;\n        // At least `min_w`: the threshold above holds one minimum window and its gap in a cell.\n        const ww = Math.min(WINDOW.w, cell - WINDOW.gap);",
            "    const n = Math.max(1, Math.floor(remaining / WINDOW.pitch));\n\n    for (let i = 0; i < n; i++) {\n        const cell = remaining / n;\n        const ww = Math.max(WINDOW.min_w, Math.min(WINDOW.w, cell - WINDOW.gap));"]);

        $this->assertNotSame([], $this->sweepDefects($dir), 'RED (a window inside the zone on a narrow band) did not bite');
        $this->assertSame([], $this->clockDefects($this->sceneOf('scene_default', $dir)),
            'the plant reached the shipped default, whose band is wide — it should bite only below the threshold');
    }

    public function test_red_windows_laid_from_the_bands_edge_over_the_clock(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'const x = span.x + ZONE_W + cell * (i + 0.5) - ww / 2;', 'const x = span.x + cell * (i + 0.5) - ww / 2;']);

        $this->assertNotSame([], $this->sweepDefects($dir), 'RED (windows over the clock) did not fail the sweep');
        $this->assertNotSame([], $this->clockDefects($this->sceneOf(self::SPARSE, $dir)), 'RED (windows over the clock) did not fail the clock clause');
    }

    public function test_red_a_band_left_narrower_than_its_zone(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js', 'const w = Math.max(span.width, ZONE_W);', 'const w = span.width;']);

        $this->assertNotSame([], $this->sweepDefects($dir), 'RED (a band narrower than its zone, the clock off the wall) did not bite');
    }

    public function test_red_the_elevator_hung_over_the_clock(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js', 'const ELEVATOR = { dx: 32,', 'const ELEVATOR = { dx: 120,']);

        $this->assertNotSame([], $this->clockDefects($this->sceneOf(self::ONE_DESK, $dir)), 'RED (the elevator over the clock) did not bite');
    }

    public function test_red_a_plane_drawn_over_the_band(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'planes.push(Object.freeze({ install_id: installId, x: f.x, y: f.y, w: f.width, h: f.height,',
            'planes.push(Object.freeze({ install_id: installId, x: f.x, y: f.y - BAND_H, w: f.width, h: f.height + BAND_H,']);
        $result = $this->floorRun(self::PLANNED, $dir);

        $this->assertNotSame([], $this->frameDefects($this->lastScene($result, self::PLANNED), $this->lastFloor($result)),
            'RED (a plane over the band) did not bite');
    }

    public function test_red_a_plane_for_the_mapless_room(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            "        if (!room.mapless && map !== null) {\n            if (room.footprint !== null) {",
            "        if (room.footprint !== null || room.mapless) {\n            planes.push(Object.freeze({ install_id: installId, x: room.origin.x, y: room.origin.y, w: 440, h: 228, theme: 'oak' }));\n        }\n\n        if (!room.mapless && map !== null) {\n            if (room.footprint !== null) {"]);
        $result = $this->floorRun(self::MAPLESS, $dir);

        $this->assertNotSame([], $this->frameDefects($this->lastScene($result, self::MAPLESS), $this->lastFloor($result)),
            'RED (a plane drawn for a room F16 draws no room for) did not bite');
    }

    public function test_red_the_slab_laid_over_the_grid(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js', '        y: extent.y + extent.height,', '        y: extent.y + extent.height - SLAB_H,']);
        $result = $this->floorRun(self::SPARSE, $dir);

        $this->assertNotSame([], $this->frameDefects($this->lastScene($result, self::SPARSE), $this->lastFloor($result)),
            'RED (the slab over the grid) did not bite');
    }

    public function test_red_a_theme_keyed_on_the_floor_and_not_the_room(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js', 'theme: roomTheme(installId) }));', 'theme: roomTheme(frame.floor.key) }));']);

        $this->assertNotSame([], $this->themeDefects($this->sceneOf(self::PLANNED, $dir)), 'RED (the floor\'s theme on every room) did not bite');
    }

    public function test_red_a_theme_too_dark_for_the_desk_text(): void
    {
        $sheet = $this->sheet();
        $dark = (string) preg_replace('/--room-walnut-2:\s*#[0-9a-f]{6};/i', '--room-walnut-2: #5a3d2b;', $sheet);

        $this->assertNotSame($sheet, $dark, 'the contrast control\'s anchor is gone — it mutated nothing');
        $this->assertNotSame([], $this->contrastDefects($dark), 'RED (a theme too dark for the desk text) did not bite');
    }

    /** The card#11058 design's dark `offline` grey, which the chip's word cannot be read on. */
    public function test_red_a_state_chip_too_dark_for_its_ink(): void
    {
        $sheet = $this->sheet();
        $dark = (string) preg_replace('/--state-offline:\s*#[0-9a-f]{6};/i', '--state-offline: #5c6270;', $sheet);

        $this->assertNotSame($sheet, $dark, 'the chip contrast control\'s anchor is gone — it mutated nothing');
        $this->assertNotSame([], $this->stateInkDefects($dark), 'RED (a state chip too dark for its ink) did not bite');
    }

    public function test_red_a_bench_that_runs_past_the_floor(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'const perRow = Math.max(1, Math.floor(extent.width / W));', 'const perRow = frame.overflow.length;']);
        $result = $this->floorRun(self::ONE_DESK, $dir);

        $this->assertNotSame([], $this->benchDefects($this->lastScene($result, self::ONE_DESK), $this->lastFloor($result)),
            'RED (a bench in one row past the floor\'s width) did not bite');
    }

    public function test_red_a_spare_desk_at_an_unused_slot(): void
    {
        $spare = <<<'JS'
            if (model === undefined) {
                continue;
            }

            const free = objects.findIndex((o, i) => !room.desks.some((x) => x.slot === i));

            if (free >= 0 && !desks.some((x) => x.key.endsWith('/spare'))) {
                const spare = objects[free];

                desks.push(placeDesk(model, `${d.key}/spare`, installId, null, { x: room.origin.x + spare.x, y: room.origin.y + spare.y }, {
                    box, measure: input.measure, character: input.character, sprite, placeholder: true, failed,
                }, { overflow: false, slot: free, object_id: spare.id }));
            }
JS;
        $dir = $this->mutatedModules(['../floor/scene.js',
            "            if (model === undefined) {\n                continue;\n            }", rtrim($spare, "\n")]);
        $result = $this->floorRun(self::SPARSE, $dir);

        $this->assertNotSame([], $this->spareDeskDefects($this->lastScene($result, self::SPARSE), $this->lastFloor($result)),
            'RED (a spare desk at an unused slot) did not bite');
    }

    // ── The clauses, each a list of defects so a GREEN and its RED read one check ─────────────

    /** @return list<string> */
    private function frameDefects(array $scene, array $frame): array
    {
        $defects = [];
        $band = $scene['band'];
        $extent = $frame['extent'];

        $this->assertNotNull($band, 'the scene drew no band — every check below would read nothing');

        // The band: over the floor's whole span, at least the zone wide, standing on the extent's top.
        if ($band['x'] !== $frame['band']['x'] || $band['w'] < $frame['band']['width'] || $band['w'] < $band['zone']['w']) {
            $defects[] = 'the band does not span the floor (and its reserved zone)';
        }

        if ($extent['y'] !== $band['y'] + $band['h']) {
            $defects[] = 'the band does not stand on the floor\'s top edge';
        }

        $defects = [...$defects, ...$this->bandDefects($band)];

        // The slab: under the floor, spanning the band.
        $slab = $scene['slab'];

        if ($slab === null || $slab['y'] !== $extent['y'] + $extent['height'] || $slab['x'] !== $band['x'] || $slab['w'] !== $band['w']) {
            $defects[] = 'the slab is not under the floor across the band';
        }

        // Nothing the frame draws over an author's grid — every room's footprint and the hallway's.
        $grids = [];

        foreach ($frame['rooms'] as $room) {
            if ($room['footprint'] !== null) {
                $grids[$room['install_id']] = $this->rect($room['footprint']);
            }
        }

        if ($frame['hallway'] !== null) {
            $grids['the hallway'] = ['x' => 0, 'y' => 0, 'w' => $frame['hallway']['pixel_width'], 'h' => $frame['hallway']['pixel_height']];
        }

        $this->assertNotSame([], $grids, 'the frame has no grid on it — the over-a-grid check would read nothing');

        foreach ($this->bandParts($band) + ['the slab' => $slab ?? ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0]] as $part => $rect) {
            foreach ($grids as $owner => $grid) {
                if ($this->intersect($rect, $grid)) {
                    $defects[] = "{$part} sits over {$owner}'s grid";
                }
            }
        }

        // Each plane: exactly its own room's grid, for every room whose map is drawn, and no other room's.
        $planes = array_column($scene['planes'], null, 'install_id');

        foreach ($frame['rooms'] as $room) {
            $drawn = ! $room['mapless'] && $room['footprint'] !== null;
            $plane = $planes[$room['install_id']] ?? null;

            if ($drawn && ($plane === null || array_intersect_key($plane, ['x' => 1, 'y' => 1, 'w' => 1, 'h' => 1]) !== $this->rect($room['footprint']))) {
                $defects[] = "{$room['install_id']}'s plane is not exactly its grid";
            }

            if (! $drawn && $plane !== null) {
                $defects[] = "{$room['install_id']} has a plane though its map is not drawn (F16: every fact, no room)";
            }
        }

        if (count($planes) !== count($scene['planes'])) {
            $defects[] = 'a room has two planes';
        }

        // The planes come in the frame's draw order, so the painter puts each under its own room's tiles.
        $order = array_values(array_intersect($frame['draw_order'], array_keys($planes)));

        if (array_column($scene['planes'], 'install_id') !== $order) {
            $defects[] = 'the planes are not in the floor\'s draw order';
        }

        return $defects;
    }

    /**
     * One band's own parts in their places: the elevator and the clock inside the reserved zone, each window
     * and sill on the wall past the zone and inside the band, the windows pairwise apart.
     *
     * @return list<string>
     */
    private function bandDefects(array $band): array
    {
        $defects = [];
        $wall = ['x' => $band['x'], 'y' => $band['y'], 'w' => $band['w'], 'h' => $band['h']];
        $zone = $band['zone'];

        if ($zone['x'] !== $band['x'] || ! $this->inside($zone, $wall)) {
            $defects[] = "at width {$band['w']} the reserved zone is not the band's left";
        }

        foreach (['the elevator' => $band['elevator']['frame'], 'the clock' => $band['clock']] as $part => $rect) {
            if (! $this->inside($rect, $zone)) {
                $defects[] = "at width {$band['w']} {$part} is not inside the reserved zone";
            }
        }

        foreach ($band['windows'] as $i => $window) {
            foreach (['window' => $window, 'sill' => $window['sill']] as $part => $rect) {
                if ($rect['x'] < $zone['x'] + $zone['w']) {
                    $defects[] = "at width {$band['w']} {$part} {$i} enters the reserved zone";
                }

                if (! $this->inside($rect, $wall)) {
                    $defects[] = "at width {$band['w']} {$part} {$i} leaves the wall";
                }
            }

            foreach (array_slice($band['windows'], $i + 1) as $j => $other) {
                if ($this->intersect($window['sill'], $other['sill']) || $this->intersect($window, $other)) {
                    $defects[] = "at width {$band['w']} windows {$i} and ".($i + 1 + $j).' meet';
                }
            }
        }

        return $defects;
    }

    /** @return array<string, array{x: float, y: float, w: float, h: float}> every primitive the band draws but the clock */
    private function bandParts(array $band): array
    {
        $parts = [
            'the wall\'s skirting' => $band['skirting'],
            'the elevator' => $band['elevator']['frame'],
        ];

        foreach ($band['windows'] as $i => $window) {
            $parts["window {$i}"] = $window;
            $parts["window {$i}'s sill"] = $window['sill'];
        }

        return array_map(fn (array $r): array => ['x' => $r['x'], 'y' => $r['y'], 'w' => $r['w'], 'h' => $r['h']], $parts);
    }

    /**
     * AT-D3-20's clock clause at rest: the band's own primitives, and every scene element at rest — each
     * plane, the slab, each tile, each desk's furniture (re-derived from its elements) and bubble, the strip
     * and its header. ⚠ The thread line's label is outside this population: the scene states where it
     * starts and not how wide it is, so its rect cannot be read here.
     *
     * @return list<string>
     */
    private function clockDefects(array $scene): array
    {
        $clock = $scene['band']['clock'];
        $face = ['x' => $clock['x'], 'y' => $clock['y'], 'w' => $clock['w'], 'h' => $clock['h']];
        $population = $this->bandParts($scene['band']);

        foreach ($scene['planes'] as $plane) {
            $population["{$plane['install_id']}'s plane"] = $plane;
        }

        if ($scene['slab'] !== null) {
            $population['the slab'] = $scene['slab'];
        }

        foreach ($scene['tiles'] as $i => $tile) {
            $population["tile {$i} ({$tile['image']})"] = $tile;
        }

        foreach ($scene['desks'] as $desk) {
            $population["{$desk['key']}'s furniture"] = $this->furniture($desk);

            if ($desk['bubble'] !== null) {
                $population["{$desk['key']}'s bubble"] = $desk['bubble'];
            }
        }

        if ($scene['strip'] !== null) {
            $population['the overflow strip'] = $scene['strip'];
            $population['the strip\'s header'] = $scene['strip']['header'];
        }

        $defects = [];

        foreach ($population as $name => $rect) {
            if ($this->intersect($rect, $face)) {
                $defects[] = "{$name} meets the clock's face";
            }
        }

        return $defects;
    }

    /**
     * The band at every width from 1 px: its parts in place (`bandDefects()`), the clock's face met by none
     * of them, and — from the narrowest width that ever draws a window up — at least one window at every
     * width, the smallest box-wide floor included.
     *
     * @return list<string>
     */
    private function sweepDefects(?string $dir = null): array
    {
        $widths = range(1, self::SWEEP_TO);
        $bands = $this->probe(['widths' => $widths], $dir, __DIR__.'/frame-probe.mjs')['bands'];
        $box = $this->box()['width'];
        $first = null;
        $defects = [];

        $this->assertCount(count($widths), $bands, 'the sweep came back short');

        foreach ($bands as $i => $band) {
            $width = $widths[$i];

            if ($band['w'] < $width) {
                $defects[] = "at width {$width} the band is narrower than its span";
            }

            foreach ([...$this->bandDefects($band), ...$this->clockDefects(['band' => $band, 'planes' => [], 'slab' => null, 'tiles' => [], 'desks' => [], 'strip' => null])] as $defect) {
                $defects[] = "{$defect} (span {$width})";
            }

            if ($band['windows'] !== []) {
                $first ??= $width;
            } elseif ($first !== null) {
                $defects[] = "at width {$width} the band draws no window, though a narrower one ({$first}) did";
            }
        }

        if ($first === null || $first > $box) {
            $defects[] = "the smallest floor one furniture box wide ({$box} px) draws no window";
        }

        return array_slice($defects, 0, 20);
    }

    /** @return list<string> */
    private function themeDefects(array $scene): array
    {
        $this->assertSame(1, preg_match('/export const ROOM_THEMES = Object\.freeze\(\[([^\]]*)\]\);/',
            (string) file_get_contents($this->jsRoot().'/floor/scene.js'), $m), 'scene.js\'s ROOM_THEMES did not parse');
        preg_match_all("/'([a-z]+)'/", $m[1], $names);
        $themes = $names[1];
        $defects = [];

        $this->assertNotSame([], $scene['planes'], 'the scene drew no plane — the theme check would read nothing');

        foreach ($scene['planes'] as $plane) {
            $expected = $themes[$this->fnv1a32($plane['install_id']) % count($themes)];

            if ($plane['theme'] !== $expected) {
                $defects[] = "{$plane['install_id']}'s plane is `{$plane['theme']}`, not its seeded `{$expected}`";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function contrastDefects(string $sheet): array
    {
        $root = $this->rootBlock($sheet);
        $tokens = $this->tokensOf($root);
        preg_match_all('/^\s*--room-([a-z]+):/m', $root, $themes);

        $this->assertContains('oak', $themes[1], 'the sheet declares no oak theme — the default every theme is held to');
        $this->assertCount(4, array_unique($themes[1]), 'the sheet does not declare the four room themes');

        $ink = $tokens['scene-ink'];
        $floor = [$this->contrast($ink, $tokens['room-oak']), $this->contrast($ink, $tokens['room-oak-2'])];
        $defects = [];

        foreach (array_unique($themes[1]) as $theme) {
            foreach (['' => 0, '-2' => 1] as $suffix => $end) {
                $ratio = $this->contrast($ink, $tokens["room-{$theme}{$suffix}"]);

                if ($ratio < $floor[$end] - 0.005) {
                    $defects[] = sprintf('--room-%s%s holds the scene\'s ink at %.2f:1, under oak\'s %.2f:1', $theme, $suffix, $ratio, $floor[$end]);
                }
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function stateInkDefects(string $sheet): array
    {
        $tokens = $this->tokensOf($this->rootBlock($sheet));
        $members = $this->documentMembers();

        $this->assertNotSame([], $members, '§ 7.1 parsed to no render_state members — the population this check reads is empty');
        $this->assertArrayHasKey('state-ink', $tokens, 'the sheet declares no --state-ink — the chip\'s word');

        $defects = [];

        foreach ($members as $member) {
            $fill = $tokens["state-{$member}"] ?? null;

            if ($fill === null) {
                $defects[] = "--state-{$member} is not declared on the sheet";

                continue;
            }

            $ratio = $this->contrast($tokens['state-ink'], $fill);

            if ($ratio < self::CHIP_INK_FLOOR) {
                $defects[] = sprintf('--state-%s (%s) holds --state-ink at %.2f:1, under %.1f:1', $member, $fill, $ratio, self::CHIP_INK_FLOOR);
            }
        }

        return $defects;
    }

    /** The sheet's `:root` block — the one home of the palette (§ 10.4). */
    private function rootBlock(string $sheet): string
    {
        $this->assertSame(1, preg_match('/:root\s*\{([^}]*)\}/', $sheet, $root), 'the sheet declares no :root block');

        return $root[1];
    }

    /** @return array<string, string> every `#rrggbb` custom property the block declares, by name less its `--` */
    private function tokensOf(string $root): array
    {
        preg_match_all('/--([a-z0-9_-]+)\s*:\s*(#[0-9a-f]{6})\s*;/i', $root, $m);

        return array_combine($m[1], $m[2]);
    }

    /** WCAG 2's contrast ratio between two `#rrggbb` colours. */
    private function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channel = static function (int $c): float {
                $s = $c / 255;

                return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
            };
            [$r, $g, $bl] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($bl);
        };
        $la = $luminance($a);
        $lb = $luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Q1: every desk on the floor is a seat's; no element of the scene stands in an unused slot. @return list<string> */
    private function spareDeskDefects(array $scene, array $frame): array
    {
        $room = $this->roomOf($frame, 'aimla');
        $seats = array_column($room['desks'], 'key');
        $used = array_column($room['desks'], 'slot');
        $defects = [];

        $this->assertGreaterThan(count($used), $room['slots'], 'the sparse run uses every slot — Q1 would measure nothing');

        foreach ($scene['desks'] as $desk) {
            if (! $desk['overflow'] && ! in_array($desk['key'], $seats, true)) {
                $defects[] = "a desk `{$desk['key']}` is drawn that is no seat's";
            }
        }

        // The unused slots' rects, from the map the run delivered, at the room's origin.
        $map = $this->fixture(self::SPARSE)['http']['/api/building/rooms/aimla/map'][0]['body']['map'];
        $box = $this->box();

        foreach ($map['layers'][0]['objects'] as $index => $object) {
            if (in_array($index, $used, true)) {
                continue;
            }

            $slot = ['x' => $room['origin']['x'] + $object['x'], 'y' => $room['origin']['y'] + $object['y'], 'w' => $box['width'], 'h' => $box['height']];

            foreach ($scene['desks'] as $desk) {
                if ($this->intersect($this->furniture($desk), $slot)) {
                    $defects[] = "{$desk['key']} draws in the unused slot {$index}";
                }
            }
        }

        return $defects;
    }

    /** § 9 F13: the bench wraps — every overflow desk inside the strip, the strip no wider than the floor or one box. @return list<string> */
    private function benchDefects(array $scene, array $frame): array
    {
        $extent = $frame['extent'];
        $strip = $scene['strip'];
        $box = $this->box();
        $bench = array_values(array_filter($scene['desks'], fn ($d) => $d['overflow']));
        $defects = [];

        $this->assertGreaterThan(1, count($bench), 'the run benches fewer than two seats — wrapping would measure nothing');
        $this->assertLessThan(count($bench) * $box['width'], $extent['width'], 'the floor holds the whole bench in a row — wrapping would measure nothing');

        $right = $extent['x'] + max($extent['width'], $box['width']);

        if ($right < $strip['x'] + $strip['w']) {
            $defects[] = 'the strip runs past the floor\'s width';
        }

        foreach ($bench as $i => $desk) {
            if (! $this->inside($desk['box'], $strip) || $desk['box']['x'] + $desk['box']['w'] > $right) {
                $defects[] = "{$desk['key']} is not on the strip inside the floor's width";
            }

            foreach (array_slice($bench, $i + 1) as $other) {
                if ($this->intersect($desk['box'], $other['box'])) {
                    $defects[] = "{$desk['key']} and {$other['key']} meet on the bench";
                }
            }
        }

        return $defects;
    }

    /** Every `motion` / `animation` / `frames` member anywhere in the given values. @return list<string> */
    private function motionIn(array $values, string $path = ''): array
    {
        $found = [];

        foreach ($values as $key => $value) {
            if (in_array($key, ['motion', 'animation', 'frames', 'cycle_ms'], true)) {
                $found[] = "{$path}{$key}";
            }

            if (is_array($value)) {
                $found = [...$found, ...$this->motionIn($value, "{$path}{$key}.")];
            }
        }

        return $found;
    }

    /** § 12's four frame figures against `scene.js`'s constants. @return list<string> */
    private function figureDefects(string $md, string $scene): array
    {
        $row = function (string $value) use ($md): string {
            $this->assertSame(1, preg_match('/^\| '.preg_quote($value, '/').' \| ([^|]+) \|/m', $md, $m), "§ 12's `{$value}` row did not parse");

            return $m[1];
        };
        $this->assertSame(1, preg_match('/\*\*(\d+) px\*\*/', $row('Back-wall band height'), $band), 'the band row\'s figure did not parse');
        $this->assertSame(1, preg_match('/\*\*(\d+) px\*\*/', $row('Back wall\'s reserved zone'), $zone), 'the zone row\'s figure did not parse');
        $this->assertSame(1, preg_match('/\*\*(\d+) px\*\* \/ \*\*(\d+) × (\d+) px\*\*/', $row('Window pitch / glazing'), $window), 'the window row\'s figures did not parse');

        $this->assertSame(1, preg_match('/^export const BAND_H = (\d+);$/m', $scene, $h), 'scene.js\'s BAND_H did not parse');
        $this->assertSame(1, preg_match('/^const ZONE_W = (\d+);$/m', $scene, $z), 'scene.js\'s ZONE_W did not parse');
        $this->assertSame(1, preg_match('/^const WINDOW = \{([^}]*)\};$/m', $scene, $w), 'scene.js\'s WINDOW did not parse');
        preg_match_all('/(\w+): ([\d.]+)/', $w[1], $members);
        $win = array_combine($members[1], $members[2]);

        $defects = [];

        foreach ([
            'the band\'s height' => [$band[1], $h[1]],
            'the reserved zone' => [$zone[1], $z[1]],
            'the window pitch' => [$window[1], $win['pitch'] ?? null],
            'the glazing\'s width' => [$window[2], $win['w'] ?? null],
            'the glazing\'s height' => [$window[3], $win['h'] ?? null],
        ] as $what => [$doc, $code]) {
            if ((string) $doc !== (string) $code) {
                $defects[] = "§ 12 states {$what} as {$doc} and scene.js draws it at ".($code ?? 'nothing');
            }
        }

        return $defects;
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    private function rect(array $r): array
    {
        return ['x' => $r['x'], 'y' => $r['y'], 'w' => $r['width'], 'h' => $r['height']];
    }

    private function sheet(): string
    {
        return (string) file_get_contents(public_path('css/mezzanine.css'));
    }
}

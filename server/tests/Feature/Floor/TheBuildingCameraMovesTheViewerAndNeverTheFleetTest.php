<?php

namespace Tests\Feature\Floor;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\ModuleSpecifiers;
use Tests\TestCase;

/**
 * **AT-D3-21 — the camera moves the viewer and never the fleet, building half.** `docs/design/FLOOR.md § 11`, gated at
 * Appendix B row 24 (card#12113, which re-cut the half for the drawn room; until then it read the cross-section's plates,
 * the whole-building framing and the ride to a plate, built at row 16): row 15's camera (`wire/camera.js`) at room scale,
 * held by the lobby screen (`lobby/lobby-screen.js`) over the room `lobby/building-scene.js` places. The floor half is
 * `TheCameraMovesTheViewerAndNeverTheFleetTest`, beside this file.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE `fixtures/fx-camera.json`'s `building_room` and `building_room_reduced`: `fx-snapshot-4` under a layout of
 * two floors, one of them labelled, entered at the lobby through the harness (`fleet-client-probe.mjs` with
 * `lobby.camera`) — the wheel, a Ctrl+wheel, a pinch, a drag and an arrow key over the room, *Fit the lobby*, the
 * keyboard's focus on the elevator and on a plaque's link, in view and out of it — and the same under
 * `prefers-reduced-motion`. A wheel or a drag begun over the DIRECTORY is the gesture wire's
 * (`wire/camera-gestures.js`'s scroller rule), held where that wire is driven, `TheCameraWireIsOneForBothPagesTest`.
 *
 * ⛔ THE LOG CLAUSE HAS ITS TEETH IN THE IMPORT GRAPH, AND THIS SAYS WHY. The lobby's one § 6.2 row is A17 — the room's
 * window, fired by each `feed.heartbeat` (§ 4.1) — so the harness's log holds the run's heartbeats' A17 rows, and the
 * SAME rows with the camera acts and without them. What makes *the animation log gains no row* true of a camera act is
 * that the lobby reaches the log and the set by exactly the paths `PATH` names — the page's bounded log from
 * `wire/live-page.js`, handed to `lobby/lobby-screen.js`, the one module that constructs the set, drawing A17 alone
 * (`TheLobbySkyIsTheFloorsA17Test` holds the rows that set writes); and, since card#12113, `wire/ride.js`, which reads one
 * CONSTANT out of the set's module (`LOOP_FPS`, the ride's fade) and is held to importing that name and no other, so it
 * can neither construct a set nor write a row — and no other module the page loads can IMPORT the log or the set; that
 * is asserted over the page's whole import graph from `lobby/main.js`, as its edges into the two modules. Two things the
 * graph cannot see are held elsewhere: `lobby/main.js` holds the page's log without importing it, and
 * `LobbyPageWiringTest` holds it to handing that log to the screen and using it nowhere else; and a camera act writing a
 * row through the lobby's own set, on the one path, is caught by the replayed log (the planted *Fit the lobby* below).
 * The walk reads, in each module's code (its comments blanked), every string-literal specifier after `from`, after a
 * bare `import` and inside `import(` (`Tests\Feature\Support\ModuleSpecifiers`) and follows each that starts `./` or `../`;
 * every other specifier is a DEFECT it cannot follow, never one it skips (card#7343 r2-1) — with ONE declared exception,
 * followed rather than skipped: `floor/cab-paint.js`'s `loadHousePalette()`, whose two dynamic imports load the asset
 * route's theme registry and the house theme it names (the cab's palette, § 4.5), are walked into
 * `resources/floor/themes/` by name, the registry's `HOUSE_THEME` read off its own line. Its REDs plant the import in
 * each form into the room's drawing (`lobby/building-scene.js`), each held to the reason it must red for.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: the room drawn as the reference's room and the directory painted on its board —
 * `Tests\Feature\Lobby\TheLobbyIsADrawnRoomTest`, where they are drawn — and the elevator's ride
 * (`Tests\Feature\Lobby\TheLobbysElevatorIsTheFloorsRideTest`). As on the floor, no test here can see a wheel, a drag or a
 * click reach these acts in a browser: `lobby/main.js` wires them and decides nothing, and `LobbyPageWiringTest` holds
 * the wiring.
 */
class TheBuildingCameraMovesTheViewerAndNeverTheFleetTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    private const RUN = 'building_room';

    private const REDUCED = 'building_room_reduced';

    private const SCREEN = '../lobby/lobby-screen.js';

    /** The module the import-form REDs are planted in — one the lobby page loads that is on no path. */
    private const DRAWING = '../lobby/building-scene.js';

    /**
     * The lobby page's paths to the log and the set: importer => the module it may load. `live-page.js` constructs the
     * page's bounded log; `lobby-screen.js` constructs the set over it; `ride.js` reads `LOOP_FPS` out of the set's
     * module and nothing else (`RIDE_IMPORT`).
     */
    private const PATH = [
        'wire/live-page.js' => 'wire/animation-log.js',
        'lobby/lobby-screen.js' => 'wire/animation-set.js',
        'wire/ride.js' => 'wire/animation-set.js',
    ];

    /** The ride's one import from the set's module — a constant, never the set (card#12113). */
    private const RIDE_IMPORT = "import { LOOP_FPS } from './animation-set.js';";

    /** The one module whose dynamic imports the walk follows into the asset route, and the two it holds them to. */
    private const ASSET_LOADER = 'floor/cab-paint.js';

    private const ASSET_IMPORTS = ['await import(THEME_REGISTRY)', 'await import(themeModule(registry.HOUSE_THEME))'];

    private const EPSILON = 1e-6;

    /** The acts that move the camera wherever it stands — the wheel, Ctrl+wheel, pinch, drag and a key's zoom. */
    private const MOVES = ['scroll', 'ctrl_wheel', 'pinch', 'drag', 'zoom'];

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    /** The Build bullet's run, as the fixture states it — so every clause below reads the run § 11 names. */
    public function test_green_the_runs_are_the_build_bullets(): void
    {
        foreach ([self::RUN, self::REDUCED] as $run) {
            $fixture = $this->fixture($run);
            $floors = $fixture['http']['/api/building'][0]['body']['layout']['floors'];

            $this->assertCount(2, $floors, "[{$run}] the layout is not two floors");
            $this->assertSame(1, count(array_filter($floors, static fn (array $f): bool => isset($f['label']))), "[{$run}] not exactly one floor is labelled");
            $acts = array_unique(array_column($fixture['lobby']['camera'], 'act'));

            foreach ([...self::MOVES, 'fit', 'focus_elevator', 'focus_board'] as $act) {
                $this->assertContains($act, $acts, "[{$run}] the run has no {$act}");
            }
        }

        $this->assertTrue($this->fixture(self::REDUCED)['reduce'] ?? false, 'the reduced run is not under prefers-reduced-motion');
        $this->assertFalse($this->fixture(self::RUN)['reduce'] ?? false, 'the moving run is under prefers-reduced-motion');
        $this->assertSame($this->fixture(self::RUN)['lobby']['camera'], $this->fixture(self::REDUCED)['lobby']['camera'], 'the reduced run\'s acts are not the moving run\'s');
    }

    /** § 4.1: the room is drawn once a full snapshot has applied, at fit, and two buildings draw one room. */
    public function test_green_the_room_is_drawn_at_fit_and_reads_no_fact(): void
    {
        $this->assertSame([], $this->roomDefects($this->replay(self::RUN), $this->replay(self::RUN, null, $this->threeFloors())));
    }

    /** The directory lists the composed floors ascending, each plaque the link to its KEY's route, none drawing a desk. */
    public function test_green_the_directory_lists_the_composed_floors_linked_by_key_and_draws_no_desk(): void
    {
        $this->assertSame([], $this->plaqueDefects($this->replay(self::RUN)));
    }

    /** AT-D3-15's assertions hold over the directory verbatim: the fleet totals are the wire's, and the intact run disagrees with nothing. */
    public function test_green_the_directory_recounts_nothing(): void
    {
        $this->assertSame([], $this->countDefects($this->replay(self::RUN)));
    }

    /** *Fit the lobby* frames the whole room, and every camera act moves the camera over it. */
    public function test_green_fit_frames_the_room_and_every_act_moves_the_camera(): void
    {
        $this->assertSame([], $this->actDefects($this->replay(self::RUN)));
    }

    /** The keyboard's focus on the elevator or a plaque outside the view brings the camera to it, and moves nothing in view. */
    public function test_green_a_focus_out_of_view_is_brought_into_it_and_one_in_view_moves_nothing(): void
    {
        $this->assertSame([], $this->focusDefects($this->replay(self::RUN)));
    }

    public function test_green_a_render_after_a_camera_act_leaves_the_camera_where_the_viewer_put_it(): void
    {
        $this->assertSame([], $this->survivalDefects($this->replay(self::RUN)));
    }

    public function test_green_the_log_gains_no_row_from_any_camera_act(): void
    {
        $this->assertSame([], $this->logDefects(self::RUN));
    }

    public function test_green_no_module_the_lobby_page_loads_but_the_paths_can_write_the_log_or_start_the_set(): void
    {
        $this->assertSame([], $this->graphDefects($this->jsRoot()));
    }

    public function test_green_under_reduced_motion_the_camera_cuts_rather_than_glides(): void
    {
        $this->assertSame([], $this->glideDefects($this->replay(self::REDUCED), $this->replay(self::RUN)));
    }

    // ── RED — each defect planted in the shipped module it would live in, and watched failing ──

    /** § 11's sixth RED: a plaque linking to `/floor/{label}`. */
    public function test_red_the_label_in_the_link(): void
    {
        $dir = $this->mutatedModules(['../lobby/lobby-model.js', 'href: `/floor/${encodeURIComponent(floor)}`,', 'href: `/floor/${encodeURIComponent(label ?? floor)}`,']);

        $this->assertNotSame([], $this->plaqueDefects($this->replay(self::RUN, $dir)), 'the sixth RED (the label in the link) did not bite');
    }

    /** § 11's seventh RED: the floor's desks drawn on its plaque. */
    public function test_red_the_desks_on_the_plaque(): void
    {
        $dir = $this->mutatedModules(['../lobby/lobby-model.js', 'held: seats.length,', "held: seats.length,\n        desks: seats,"]);

        $this->assertNotSame([], $this->plaqueDefects($this->replay(self::RUN, $dir)), 'the seventh RED (the desks on the plaque) did not bite');
    }

    /** § 11's seventh RED, its other half: the room drawn from a plaque's count — two buildings draw two rooms. */
    public function test_red_a_room_drawn_from_a_fact(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'const room = feed.applied ? lobbyScene() : null;',
            'const room = feed.applied ? { ...lobbyScene(), extent: { ...lobbyScene().extent, h: 772 + 100 * (this.#building.floors?.length ?? 0) } } : null;']);

        $this->assertNotSame([], $this->roomDefects($this->replay(self::RUN, $dir), $this->replay(self::RUN, $dir, $this->threeFloors())),
            'the seventh RED (a room drawn from a fact) did not bite');
    }

    /** § 11's RED — the logged zoom: a camera act writing a row through the lobby's own set, on the one path. */
    public function test_red_a_camera_act_that_writes_a_row_through_the_lobbys_set(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        this.#camera = fit(from);\n", "        this.#camera = fit(from);\n        this.#set.edges([{ t: 'feed.heartbeat' }], 0);\n"]);

        $this->assertSame([], $this->graphDefects(dirname($dir)), 'the plant is not on the one path — the graph reds it, and the log clause is not what caught it');
        $this->assertNotSame([], array_filter($this->logDefects(self::RUN, $dir), static fn (string $d): bool => str_contains($d, 'the camera acts changed the animation log')),
            'CONTROL (a camera act writing an A17 row through the lobby\'s set) did not bite for its reason');
    }

    /** § 11's second RED — the reset camera: re-fit on every render. */
    public function test_red_the_reset_camera(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'this.#camera = room === null ? unframe(this.#camera) : frameOn(this.#camera, room.extent);',
            'this.#camera = room === null ? unframe(this.#camera) : fit(frameOn(this.#camera, room.extent));']);

        $this->assertNotSame([], $this->survivalDefects($this->replay(self::RUN, $dir)), 'the second RED (the reset camera) did not bite');
    }

    /** *Fit the lobby* that frames the board alone — the room cut. */
    public function test_red_a_fit_that_cuts_the_room(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        this.#camera = fit(from);\n", "        this.#camera = focusOn(from, this.#drawn.room.board);\n"]);

        $this->assertNotSame([], $this->actDefects($this->replay(self::RUN, $dir)), 'CONTROL (a fit that cuts the room) did not bite');
    }

    public function test_red_a_focus_that_never_brings_its_rect_into_view(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        this.#camera = focusOn(from, rect);\n", "        this.#camera = from;\n"]);

        $this->assertNotSame([], $this->focusDefects($this->replay(self::RUN, $dir)), 'CONTROL (a focus that never brings the camera) did not bite');
    }

    public function test_red_a_focus_that_moves_a_rect_already_in_view(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'if (rect === null || within(from.view, rect)) {', 'if (rect === null) {']);

        $this->assertNotSame([], $this->focusDefects($this->replay(self::RUN, $dir)), 'CONTROL (a focus that moves a rect in view) did not bite');
    }

    public function test_red_a_fit_that_glides_under_reduced_motion(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        this.#camera = fit(from);\n\n        return { from, to: this.#camera, glide_ms: glideMs(this.#reduce) };",
            "        this.#camera = fit(from);\n\n        return { from, to: this.#camera, glide_ms: 850 };"]);

        $this->assertNotSame([], $this->glideDefects($this->replay(self::REDUCED, $dir), $this->replay(self::RUN, $dir)), 'CONTROL (a fit gliding under reduced motion) did not bite');
    }

    /**
     * The logged act: the room's drawing given the animation log to write a row into, or the set to start one through, off
     * the paths (`PATH`) — planted once per specifier form. Each control is held to the REASON it reds: a relative literal
     * reds because the walk followed it to the log or the set, and every other form reds because the walk could not
     * follow it (card#7343 r2-1: fail closed).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function importForms(): array
    {
        $reached = 'writes the animation log or starts the set';
        $unfollowable = 'which this walk cannot follow';

        return [
            'a static import' => ["import { createAnimationLog } from '../wire/animation-log.js';", $reached],
            'a static import, double-quoted' => ['import { createAnimationLog } from "../wire/animation-log.js";', $reached],
            'a re-export' => ["export { createAnimationLog } from '../wire/animation-log.js';", $reached],
            'a bare import' => ["import '../wire/animation-set.js';", $reached],
            'a bare import, double-quoted' => ['import "../wire/animation-set.js";', $reached],
            'a dynamic import' => ["const loadLog = () => import('../wire/animation-log.js');", $reached],
            'a dynamic import, double-quoted' => ['const loadLog = () => import("../wire/animation-log.js");', $reached],
            'a dynamic import, backtick' => ['const loadLog = () => import(`../wire/animation-log.js`);', $reached],
            'an absolute path' => ["import { createAnimationLog } from '/js/wire/animation-log.js';", $unfollowable],
            'an absolute path, dynamic' => ["const loadLog = () => import('/js/wire/animation-log.js');", $unfollowable],
            'a URL' => ["import 'https://mezzanine.test/js/wire/animation-set.js';", $unfollowable],
            'a bare name' => ["import 'animation-log';", $unfollowable],
            'a template with ${}' => ['const loadLog = (m) => import(`../wire/${m}.js`);', $unfollowable],
            'a dynamic import of a variable' => ['const loadLog = (m) => import(m);', $unfollowable],
            'a dynamic import of a concatenation' => ["const loadLog = (m) => import('../wire/' + m);", $unfollowable],
        ];
    }

    #[DataProvider('importForms')]
    public function test_red_a_lobby_that_loads_the_animation_log_in_any_import_form(string $line, string $reason): void
    {
        $dir = $this->mutatedModules([self::DRAWING,
            "import { skyPaint } from '../floor/floor-layout.js';\n",
            "import { skyPaint } from '../floor/floor-layout.js';\n{$line}\n"]);

        $defects = $this->graphDefects(dirname($dir));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "CONTROL (the lobby loading the log or the set by `{$line}`) did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /** The one path gone — the lobby screen no longer loads the set — is a walk holding nothing, not a clean one. */
    public function test_red_a_lobby_whose_one_path_to_the_set_is_gone(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "import { AnimationSet } from '../wire/animation-set.js';\n", '']);

        $this->assertNotSame([], array_filter($this->graphDefects(dirname($dir)), static fn (string $d): bool => str_contains($d, 'no longer loads')),
            'CONTROL (the lobby screen no longer loading the set) did not bite for its reason');
    }

    /** The ride reaching for the set itself, beside its constant (card#12113's deliberate edge, held narrow). */
    public function test_red_a_ride_that_imports_the_set_beside_its_constant(): void
    {
        $dir = $this->mutatedModules(['ride.js', self::RIDE_IMPORT, "import { AnimationSet, LOOP_FPS } from './animation-set.js';"]);

        $this->assertNotSame([], array_filter($this->graphDefects(dirname($dir)), static fn (string $d): bool => str_contains($d, 'wire/ride.js imports')),
            'CONTROL (the ride importing the set) did not bite for its reason');
    }

    /** A dynamic import beside the asset loader's two — one the walk was never told of — is a defect, never followed. */
    public function test_red_a_third_dynamic_import_in_the_asset_loader(): void
    {
        $dir = $this->mutatedModules(['../floor/cab-paint.js', "        return house.PALETTE?.elevator ?? null;\n",
            "        await import(registry.HOUSE_THEME + '.js');\n        return house.PALETTE?.elevator ?? null;\n"]);

        $this->assertNotSame([], array_filter($this->graphDefects(dirname($dir)), static fn (string $d): bool => str_contains($d, 'cannot follow')),
            'CONTROL (a third dynamic import in the asset loader) did not bite for its reason');
    }

    /** A commented-out import is no import: the walk reads code, and documentation naming a module does not red. */
    public function test_green_an_import_in_a_comment_is_not_read(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            "import { lobbyScene } from './building-scene.js';\n",
            "import { lobbyScene } from './building-scene.js';\n// import '../wire/animation-log.js';\n/* import('/js/wire/animation-set.js') from `x` */\n"]);

        $this->assertSame([], $this->graphDefects(dirname($dir)));
    }

    // ── the measurements ───────────────────────────────────────────────────────────────────────

    /** Every frame that drew the room, with its time. @return list<array<string, mixed>> */
    private function drawn(array $result): array
    {
        return array_values(array_filter(
            array_map(static fn (array $r): array => $r['frame'] + ['at' => $r['at']], $result['lobby_renders']),
            static fn (array $f): bool => $f['room'] !== null,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function acts(array $result, string $act): array
    {
        return array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === $act));
    }

    /** The run's own overrides with a layout of THREE floors — another building, which must draw the same room. */
    private function threeFloors(): array
    {
        $http = $this->fixture(self::RUN)['http'];
        $floors = $http['/api/building'][0]['body']['layout']['floors'];
        $floors[] = ['floor' => 'zz-annexe', 'label' => 'the far annexe', 'rooms' => [['install' => 'zz-annexe', 'form' => 'office']]];
        $http['/api/building'][0]['body']['layout']['floors'] = $floors;

        return ['http' => $http];
    }

    /** @return list<string> */
    private function roomDefects(array $two, array $three): array
    {
        $defects = [];
        $a = $this->drawn($two);
        $b = $this->drawn($three);

        if ($a === [] || $b === []) {
            return ['a run never drew the room'];
        }

        if (count($a[count($a) - 1]['summary']['floors']) === count($b[count($b) - 1]['summary']['floors'])) {
            $defects[] = 'the two runs list the same number of floors — the two-buildings clause compared nothing';
        }

        // The first frame that drew the room is at fit, the room framed; every frame before it drew none and held no summary.
        $first = $a[0];

        if ($first['camera']['fitted'] !== true || ! $this->contains($first['camera']['view'], $first['room']['extent'])) {
            $defects[] = "the room's first frame at {$first['at']} ms is not at fit on the room";
        }

        foreach ($two['lobby_renders'] as $r) {
            if (($r['frame']['room'] === null) !== ($r['frame']['summary'] === null)) {
                $defects[] = "the frame at {$r['at']} ms draws the room without a summary, or a summary without the room";
            }
        }

        // The drawing reads no plaque's key, name or count: every frame of both buildings draws one room.
        $rooms = array_unique(array_map(static fn (array $f): string => json_encode(array_diff_key($f['room'], ['ride' => null])), [...$a, ...$b]));

        if (count($rooms) !== 1) {
            $defects[] = 'two buildings draw '.count($rooms).' rooms — the room is drawn from a fact';
        }

        // The directory and the readings stand on the board's wall, the directory inside the board.
        $room = $a[0]['room'];

        if (! $this->contains($room['board'], $room['directory'])) {
            $defects[] = "the directory's box is not inside the board";
        }

        return $defects;
    }

    /** @return list<string> */
    private function plaqueDefects(array $result): array
    {
        $drawn = $this->drawn($result);

        if ($drawn === []) {
            return ['the lobby never drew the room'];
        }

        $defects = [];

        foreach ($drawn as $f) {
            $plaques = $f['summary']['floors'];
            $keys = array_column($plaques, 'floor');
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            if ($keys !== $sorted) {
                $defects[] = "at {$f['at']} ms the plaques are not the composed floors, ascending";
            }

            foreach ($plaques as $plaque) {
                if ($plaque['href'] !== '/floor/'.rawurlencode($plaque['floor'])) {
                    $defects[] = "at {$f['at']} ms the plaque {$plaque['floor']} links to {$plaque['href']}, not its key's route";
                }

                if ($plaque['name'] !== ($plaque['label'] ?? $plaque['floor'])) {
                    $defects[] = "at {$f['at']} ms the plaque {$plaque['floor']} does not read as its label, else its key";
                }

                if ($this->carriesASeat($plaque)) {
                    $defects[] = "at {$f['at']} ms the plaque {$plaque['floor']} carries a desk — a count a viewer counts by eye (§ 4.1)";
                }
            }
        }

        $last = $drawn[count($drawn) - 1]['summary']['floors'];

        if (count($last) < 2 || array_filter(array_column($last, 'label')) === []) {
            $defects[] = 'the run draws no directory of two floors with one labelled';
        }

        return $defects;
    }

    /** @return list<string> */
    private function countDefects(array $result): array
    {
        $defects = [];
        $fleet = $this->fixture(self::RUN)['http']['/api/fleet/snapshot'][0]['body']['fleet'] ?? null;

        if ($fleet === null) {
            return ['the run carries no fleet object'];
        }

        foreach ($this->drawn($result) as $f) {
            if (! str_contains($f['summary']['totals'], (string) $fleet['seats_total']) || ! str_contains($f['summary']['totals'], (string) $fleet['seats_live'])) {
                $defects[] = "at {$f['at']} ms the fleet totals ({$f['summary']['totals']}) are not the wire's {$fleet['seats_total']} / {$fleet['seats_live']}";
            }

            if ($f['discrepancy'] !== null) {
                $defects[] = "at {$f['at']} ms the intact run renders a discrepancy: {$f['discrepancy']}";
            }

            if ($f['summary']['held'] !== array_sum(array_column($f['summary']['floors'], 'held'))) {
                $defects[] = "at {$f['at']} ms the held count is not the plaques' own";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function actDefects(array $result): array
    {
        $defects = [];
        $room = $this->drawn($result)[0]['room'] ?? null;

        if ($room === null) {
            return ['the lobby never drew the room'];
        }

        foreach (self::MOVES as $move) {
            $acts = $this->acts($result, $move);

            if ($acts === []) {
                $defects[] = "the run has no {$move}";
            }

            foreach ($acts as $a) {
                if ($a['before'] == $a['after']) {
                    $defects[] = "the {$move} at {$a['at']} ms moved nothing";
                }
            }
        }

        foreach (['scroll', 'ctrl_wheel'] as $wheel) {
            foreach ($this->acts($result, $wheel) as $a) {
                if (($a['consumed'] ?? null) !== true) {
                    $defects[] = "the {$wheel} at {$a['at']} ms over the room was not taken from the page";
                }
            }
        }

        $fits = $this->acts($result, 'fit');

        if ($fits === []) {
            $defects[] = 'the run never fits the lobby';
        }

        foreach ($fits as $a) {
            if ($a['after']['fitted'] !== true || ! $this->contains($a['after']['view'], $room['extent'])) {
                $defects[] = "*Fit the lobby* at {$a['at']} ms does not frame the whole room";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function focusDefects(array $result): array
    {
        $defects = [];
        $room = $this->drawn($result)[0]['room'] ?? null;

        if ($room === null) {
            return ['the lobby never drew the room'];
        }

        $moved = ['focus_elevator' => 0, 'focus_board' => 0];
        $stayed = ['focus_elevator' => 0, 'focus_board' => 0];

        foreach (['focus_elevator' => 'elevator', 'focus_board' => 'board'] as $act => $part) {
            foreach ($this->acts($result, $act) as $a) {
                $inView = $this->contains($a['before']['view'], $room[$part]);

                if ($inView) {
                    $stayed[$act]++;

                    if ($a['before'] != $a['after'] || $a['glide_ms'] !== null) {
                        $defects[] = "the {$act} at {$a['at']} ms moved the camera though the {$part} was in view";
                    }

                    continue;
                }

                $moved[$act]++;

                if (! $this->contains($a['after']['view'], $room[$part])) {
                    $defects[] = "the {$act} at {$a['at']} ms did not bring the {$part} into view";
                }
            }
        }

        foreach ($moved as $act => $n) {
            if ($n === 0 || $stayed[$act] === 0) {
                $defects[] = "the run has no {$act} out of view and in view both — the clause measured half of itself";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function survivalDefects(array $result): array
    {
        $defects = [];
        $drawn = $this->drawn($result);
        $acts = $result['camera_acts'];
        $checked = 0;

        foreach ($acts as $i => $a) {
            $next = $acts[$i + 1]['at'] ?? PHP_INT_MAX;
            $after = array_values(array_filter($drawn, static fn (array $f): bool => $f['at'] >= $a['at'] && $f['at'] < $next));

            foreach ($after as $f) {
                $checked++;
                $c = $f['camera'];

                if (abs($c['zoom'] - $a['after']['zoom']) > self::EPSILON || abs($c['x'] - $a['after']['x']) > self::EPSILON || abs($c['y'] - $a['after']['y']) > self::EPSILON) {
                    $defects[] = sprintf('the render at %d ms moved the camera from zoom %.4f at (%.2f, %.2f) to zoom %.4f at (%.2f, %.2f)',
                        $f['at'], $a['after']['zoom'], $a['after']['x'], $a['after']['y'], $c['zoom'], $c['x'], $c['y']);
                }
            }
        }

        // A heartbeat rendered after the viewer moved the camera off the fit — or this clause held nothing.
        $last = $acts[count($acts) - 1] ?? null;

        if ($checked === 0 || $last === null || $last['after']['fitted'] === true) {
            $defects[] = 'no render followed a camera act that left the camera off the fit';
        }

        return $defects;
    }

    /** @return list<string> */
    private function logDefects(string $run, ?string $dir = null): array
    {
        $lobby = $this->fixture($run)['lobby'];
        unset($lobby['camera']);

        $touched = $this->replay($run, $dir);
        $untouched = $this->replay($run, $dir, ['lobby' => $lobby]);
        $defects = [];

        if ($touched['camera_acts'] === [] || $untouched['camera_acts'] !== []) {
            $defects[] = 'the pair is not a run with camera acts and the same run without them';
        }

        if ($touched['animation_log'] !== $untouched['animation_log']) {
            $defects[] = sprintf('the camera acts changed the animation log: %d rows with them, %d without',
                count($touched['animation_log']), count($untouched['animation_log']));
        }

        if (array_values(array_unique(array_column($untouched['animation_log'], 'animation_id'))) !== ['A17']) {
            $defects[] = 'the run\'s log is not A17 rows alone';
        }

        // § 11's discriminating control: a client that never touches the camera reads the room at fit.
        $drawn = $this->drawn($untouched);

        if ($drawn === []) {
            $defects[] = 'the control drew no room';
        }

        foreach ($drawn as $f) {
            if ($f['camera']['fitted'] !== true || ! $this->contains($f['camera']['view'], $f['room']['extent'])) {
                $defects[] = "the control's frame at {$f['at']} ms is not at fit on the room";
            }
        }

        return $defects;
    }

    /**
     * Every module the lobby page loads, from `lobby/main.js` through every specifier each module names
     * (`ModuleSpecifiers::of()`) — and a defect for each import of the animation log or the animation set by any module but
     * its importer on `PATH`, for each module on `PATH` that no longer loads its one, for the ride's import from the set
     * being anything but its constant, and for each specifier the walk cannot follow but the asset loader's two, which it
     * follows into `resources/floor/themes/` by name.
     *
     * ⛔ AN UNFOLLOWABLE SPECIFIER IS A DEFECT, NEVER SKIPPED (card#7343 r2-1).
     *
     * @return list<string>
     */
    private function graphDefects(string $root): array
    {
        // The asset route's tree is the repository's `resources/`, whichever copy of the module tree is walked.
        $resources = dirname($this->jsRoot(), 3).'/resources';
        $seen = [];
        $queue = ['lobby/main.js'];
        $defects = [];
        $edges = [];

        while ($queue !== []) {
            $file = array_shift($queue);

            if (isset($seen[$file])) {
                continue;
            }

            // A key `@resources/…` is the asset route's tree (`resources/`), walked with its own relative imports.
            $path = str_starts_with($file, '@resources/') ? $resources.'/'.substr($file, 11) : $root.'/'.$file;
            $this->assertFileExists($path, "the lobby page imports {$file}, which is not there");
            $seen[$file] = true;
            $source = (string) file_get_contents($path);
            $unfollowable = [];

            foreach (ModuleSpecifiers::of($source) as $s) {
                if (! $s['followable']) {
                    $unfollowable[] = $s;

                    continue;
                }

                $base = str_starts_with($file, '@resources/') ? '@resources/'.$this->normalise(dirname(substr($file, 11)).'/'.$s['specifier']) : $this->normalise(dirname($file).'/'.$s['specifier']);
                $edges[] = [$file, $base];
                $queue[] = $base;
            }

            // The asset loader's two dynamic imports, and only those two, are followed into the asset route by name.
            if ($file === self::ASSET_LOADER && count($unfollowable) === count(self::ASSET_IMPORTS)
                && array_filter(self::ASSET_IMPORTS, static fn (string $needle): bool => ! str_contains(ModuleSpecifiers::withoutComments($source), $needle)) === []) {
                $registry = (string) file_get_contents($resources.'/floor/themes/index.js');
                $this->assertSame(1, preg_match("/^export const HOUSE_THEME = '([a-z0-9-]+)';$/m", $registry, $house), 'the theme registry names no house theme');
                $queue[] = '@resources/floor/themes/index.js';
                $queue[] = "@resources/floor/themes/{$house[1]}/theme.js";
                $edges[] = [$file, '@resources/floor/themes/index.js'];
                $unfollowable = [];
            }

            foreach ($unfollowable as $s) {
                $defects[] = sprintf('%s loads %s by %s, which this walk cannot follow: %s',
                    $file, $s['specifier'] === null ? 'a non-literal' : "`{$s['specifier']}`", $s['form'], $s['why']);
            }

            if ($file === 'wire/ride.js' && ! str_contains($source, self::RIDE_IMPORT)) {
                $defects[] = 'wire/ride.js imports from the set\'s module something beside its constant — `'.self::RIDE_IMPORT.'` is its one import there';
            }
        }

        $this->assertContains('lobby/lobby-screen.js', array_keys($seen), 'the walk never reached the lobby screen — it read nothing');
        $this->assertContains('wire/camera.js', array_keys($seen), 'the walk never reached the camera — it read nothing');
        $this->assertContains('wire/ride.js', array_keys($seen), 'the walk never reached the ride — it read nothing');

        foreach ($edges as [$from, $to]) {
            if (in_array(basename($to), ['animation-log.js', 'animation-set.js'], true) && (self::PATH[$from] ?? null) !== $to) {
                $defects[] = "{$from} loads {$to}, which writes the animation log or starts the set off the lobby's paths to it";
            }
        }

        foreach (self::PATH as $from => $to) {
            if (! in_array([$from, $to], $edges, true)) {
                $defects[] = "{$from} no longer loads {$to} — the lobby's paths to the log are not the ones this walk holds";
            }
        }

        return $defects;
    }

    private function normalise(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /** @return list<string> */
    private function glideDefects(array $reduced, array $moving): array
    {
        $defects = [];

        foreach (['fit', 'focus_elevator', 'focus_board'] as $act) {
            $cuts = array_filter($this->acts($reduced, $act), static fn (array $a): bool => $a['glide_ms'] !== null);
            $glides = array_filter($this->acts($moving, $act), static fn (array $a): bool => $a['glide_ms'] !== null);

            if ($cuts === [] || $glides === []) {
                $defects[] = "a run has no {$act} that moves the camera";
            }

            foreach ($cuts as $a) {
                if ($a['glide_ms'] !== 0) {
                    $defects[] = "under prefers-reduced-motion the {$act} at {$a['at']} ms glides for {$a['glide_ms']} ms";
                }
            }

            foreach ($glides as $a) {
                if (! ($a['glide_ms'] > 0)) {
                    $defects[] = "without reduced motion the {$act} at {$a['at']} ms does not glide — the cut clause measured nothing";
                }
            }
        }

        return $defects;
    }

    /** Whether any member of a plaque, at any depth, is a seat object. */
    private function carriesASeat(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (array_key_exists('seat_id', $node)) {
            return true;
        }

        foreach ($node as $child) {
            if ($this->carriesASeat($child)) {
                return true;
            }
        }

        return false;
    }

    private function contains(array $outer, array $inner): bool
    {
        return $inner['x'] >= $outer['x'] - self::EPSILON && $inner['y'] >= $outer['y'] - self::EPSILON
            && $inner['x'] + $inner['w'] <= $outer['x'] + $outer['w'] + self::EPSILON
            && $inner['y'] + $inner['h'] <= $outer['y'] + $outer['h'] + self::EPSILON;
    }
}

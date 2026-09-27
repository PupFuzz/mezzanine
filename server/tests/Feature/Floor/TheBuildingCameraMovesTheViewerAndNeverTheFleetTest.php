<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **AT-D3-21 — the camera moves the viewer and never the fleet, building half.** `docs/design/FLOOR.md
 * § 11`, gated at Appendix B row 16 (card#7343): row 15's camera (`wire/camera.js`) at building scale,
 * held by the lobby screen (`lobby/lobby-screen.js`) over the plates `lobby/building-scene.js` places,
 * and the elevator ride's arrival at `/floor/{key}`. The floor half is
 * `TheCameraMovesTheViewerAndNeverTheFleetTest`, beside this file.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE `fixtures/fx-camera.json`'s `building_rides` and `building_rides_reduced`:
 * `fx-snapshot-4` under a layout of two floors, one of them labelled, entered at the lobby through the
 * harness (`fleet-client-probe.mjs` with `lobby.camera`) — ride to the labelled floor, ride to the other,
 * whole-building — and the same under `prefers-reduced-motion`. A ride's arrival is a route change the
 * page performs; the harness stays on the lobby and records the route, and where the floor page's own
 * `resolveRoute()` lands it over the building the lobby drew.
 *
 * ⛔ THE LOG CLAUSE HAS ITS TEETH IN THE IMPORT GRAPH, AND THIS SAYS WHY. The lobby holds no animation
 * log — it animates nothing (§ 6.5; § 4.1's plates carry no § 6.2 row) — so the harness's log is empty
 * with the camera acts and without them, and no plant inside the lobby can reach it. What makes *the
 * animation log gains no row* true is that no module the lobby page loads can write one or start
 * anything through the set; that is asserted over the page's whole import graph from `lobby/main.js`,
 * and its RED plants the import. The replayed log comparison stays as the harness's own reading of the
 * GREEN, beside the discriminating control § 11 names.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: the plate drawn as the reference's section, the cab drawn and glided, the
 * roof sign, ground lobby and sky — Appendix B row 16's slice B. The seventh RED is held here over the
 * plate the MODEL hands the page (`lobby/main.js` draws each plate's text and nothing else); a drawn
 * section that painted desks would need its own check where it is drawn. And, as on the floor, no test
 * here can see a wheel, a drag or a click reach these acts in a browser: `lobby/main.js` wires them and
 * decides nothing, and `LobbyPageWiringTest` holds its elements and the ride's wiring.
 */
class TheBuildingCameraMovesTheViewerAndNeverTheFleetTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    private const RUN = 'building_rides';

    private const REDUCED = 'building_rides_reduced';

    private const SCREEN = '../lobby/lobby-screen.js';

    private const EPSILON = 1e-6;

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    /** The Build bullet's run, as the fixture states it — so every clause below reads the run § 11 names. */
    public function test_green_the_runs_are_the_build_bullets(): void
    {
        foreach ([self::RUN, self::REDUCED] as $run) {
            $fixture = $this->fixture($run);
            $floors = $fixture['http']['/api/building'][0]['body']['layout']['floors'];

            $this->assertCount(2, $floors, "[{$run}] the layout is not two floors");
            $this->assertSame(1, count(array_filter($floors, static fn (array $f): bool => isset($f['label']))), "[{$run}] not exactly one floor is labelled");
            $this->assertSame(['ride', 'ride', 'building'], array_column($fixture['lobby']['camera'], 'act'), "[{$run}] the acts are not ride, ride, whole-building");
        }

        $this->assertTrue($this->fixture(self::REDUCED)['reduce'] ?? false, 'the reduced run is not under prefers-reduced-motion');
        $this->assertFalse($this->fixture(self::RUN)['reduce'] ?? false, 'the moving run is under prefers-reduced-motion');
    }

    public function test_green_the_plates_are_the_composed_floors_carrying_the_lobby_rows_verbatim_and_no_desk(): void
    {
        $this->assertSame([], $this->plateDefects($this->replay(self::RUN)));
    }

    public function test_green_whole_building_frames_every_plate(): void
    {
        $this->assertSame([], $this->wholeBuildingDefects($this->replay(self::RUN)));
    }

    public function test_green_each_ride_ends_at_the_floor_route_by_its_key_on_the_labelled_floor_too(): void
    {
        $this->assertSame([], $this->rideDefects($this->replay(self::RUN)));
    }

    /** § 4.4: the route a ride arrives at "must still deep-link on a cold start" — row 8 serves it. */
    public function test_green_each_rides_route_deep_links_on_a_cold_start(): void
    {
        $rides = $this->rides($this->replay(self::RUN));
        $this->assertNotSame([], $rides, 'the run rode nowhere');

        $user = User::factory()->twoFactorConfirmed()->create();

        foreach ($rides as $a) {
            $html = (string) $this->actingAs($user)->get($a['ride']['route'])->assertOk()->getContent();

            $this->assertStringContainsString('data-floor="'.e($a['ride']['cab']).'"', $html,
                "the cold start at {$a['ride']['route']} does not serve the floor page for {$a['ride']['cab']}");
        }
    }

    public function test_green_a_render_after_a_camera_act_leaves_the_camera_where_the_viewer_put_it(): void
    {
        $this->assertSame([], $this->survivalDefects($this->replay(self::RUN)));
    }

    public function test_green_the_log_gains_no_row_from_any_ride_or_the_whole_building_control(): void
    {
        $this->assertSame([], $this->logDefects(self::RUN));
    }

    public function test_green_no_module_the_lobby_page_loads_can_write_the_log_or_start_the_set(): void
    {
        $this->assertSame([], $this->graphDefects($this->jsRoot()));
    }

    public function test_green_under_reduced_motion_the_cab_and_the_camera_cut_rather_than_glide(): void
    {
        $this->assertSame([], $this->glideDefects($this->replay(self::REDUCED), $this->replay(self::RUN)));
    }

    // ── RED — each defect planted in the shipped module it would live in, and watched failing ──

    /** § 11's sixth RED: ride to `/floor/{label}`. */
    public function test_red_the_label_in_the_link(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            'return { cab: plate.floor, route: plate.href,',
            'return { cab: plate.floor, route: `/floor/${encodeURIComponent(plate.name)}`,']);

        $this->assertNotSame([], $this->rideDefects($this->replay(self::RUN, $dir)), 'the sixth RED (the label in the link) did not bite');
    }

    /** § 11's seventh RED: draw the floor's desks on its plate. */
    public function test_red_the_desks_on_the_plate(): void
    {
        $dir = $this->mutatedModules(['../lobby/lobby-model.js', 'held: seats.length,', 'held: seats.length,
        desks: seats,']);

        $this->assertNotSame([], $this->plateDefects($this->replay(self::RUN, $dir)), 'the seventh RED (the desks on the plate) did not bite');
    }

    /** The logged ride: the lobby given the animation log to write a ride into. */
    public function test_red_a_lobby_that_loads_the_animation_log(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            "import { buildingScene } from './building-scene.js';\n",
            "import { buildingScene } from './building-scene.js';\nimport { createAnimationLog } from '../wire/animation-log.js';\n"]);

        $this->assertNotSame([], $this->graphDefects(dirname($dir)), 'CONTROL (the lobby loading the animation log) did not bite');
    }

    /** A ride that glides under `prefers-reduced-motion`. */
    public function test_red_a_ride_that_glides_under_reduced_motion(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            'route: plate.href, from, to: this.#camera, glide_ms: glideMs(this.#reduce) };',
            'route: plate.href, from, to: this.#camera, glide_ms: glideMs(false) };']);

        $this->assertNotSame([], $this->glideDefects($this->replay(self::REDUCED, $dir), $this->replay(self::RUN, $dir)),
            'CONTROL (a ride gliding under prefers-reduced-motion) did not bite');
    }

    /** § 11's second RED at building scale: re-fit the building on every render. */
    public function test_red_the_reset_camera(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            ': frameOn(this.#camera, scene.extent);',
            ': fit(frameOn(this.#camera, scene.extent));']);

        $this->assertNotSame([], $this->survivalDefects($this->replay(self::RUN, $dir)), 'CONTROL (a camera reset by every render) did not bite');
    }

    /** A whole building that frames one plate: the building's extent taken as the first plate's. */
    public function test_red_a_whole_building_that_cuts_a_plate(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            ': frameOn(this.#camera, scene.extent);',
            ': frameOn(this.#camera, scene.plates[0].rect);']);

        $this->assertNotSame([], $this->wholeBuildingDefects($this->replay(self::RUN, $dir)), 'CONTROL (a whole building that cuts a plate) did not bite');
    }

    /** A ride whose camera never goes to the plate — the cab moves and the view stays on the building. */
    public function test_red_a_ride_that_does_not_zoom_to_the_plate(): void
    {
        $dir = $this->mutatedModules([self::SCREEN,
            'this.#camera = focusOn(from, this.#drawn.scene.plates[plate.level].rect);',
            'this.#camera = from;']);

        $this->assertNotSame([], $this->rideDefects($this->replay(self::RUN, $dir)), 'CONTROL (a ride with no zoom to the plate) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /**
     * Every lobby frame that drew the building.
     *
     * @return list<array<string, mixed>>
     */
    private function built(array $result): array
    {
        return array_values(array_filter(
            array_map(static fn (array $r): array => $r['frame'] + ['at' => $r['at']], $result['lobby_renders']),
            static fn (array $f): bool => ($f['building']['composed'] ?? false) === true,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function acts(array $result, string $act): array
    {
        return array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === $act));
    }

    /** @return list<array<string, mixed>> */
    private function rides(array $result): array
    {
        return $this->acts($result, 'ride');
    }

    /** @return list<string> */
    private function plateDefects(array $result): array
    {
        $built = $this->built($result);

        if ($built === []) {
            return ['the lobby never drew the building'];
        }

        $defects = [];

        foreach ($built as $f) {
            $plates = $f['building']['plates'];
            $keys = array_column($plates, 'floor');
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            if ($keys !== $sorted || $keys !== array_column($f['summary']['floors'], 'floor')) {
                $defects[] = "at {$f['at']} ms the plates are not the composed floors, ascending";
            }

            foreach ($plates as $i => $plate) {
                $row = $plate;
                unset($row['level']);

                // AT-D3-15 over the plates verbatim: each plate IS the lobby's row, so the cross-section
                // recounts nothing and every assertion over the rows holds over it.
                if ($row !== ($f['summary']['floors'][$i] ?? null)) {
                    $defects[] = "at {$f['at']} ms the plate {$plate['floor']} is not the lobby's row for that floor";
                }

                if ($plate['name'] !== ($plate['label'] ?? $plate['floor'])) {
                    $defects[] = "at {$f['at']} ms the plate {$plate['floor']} does not read as its label, else its key";
                }

                if ($plate['rooms'] === [] || in_array('', array_column($plate['rooms'], 'install_id'), true)) {
                    $defects[] = "at {$f['at']} ms the plate {$plate['floor']} names no room";
                }

                if ($this->carriesASeat($plate)) {
                    $defects[] = "at {$f['at']} ms the plate {$plate['floor']} carries a desk — a count a viewer counts by eye (§ 4.1)";
                }
            }
        }

        $last = $built[count($built) - 1]['building']['plates'];

        if (count($last) < 2 || array_filter(array_column($last, 'label')) === []) {
            $defects[] = 'the run draws no building of two floors with one labelled';
        }

        return $defects;
    }

    /** Whether any member of a plate, at any depth, is a seat object. */
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

    /** @return list<string> */
    private function wholeBuildingDefects(array $result): array
    {
        $built = $this->built($result);
        $wholes = $this->acts($result, 'building');

        if ($built === [] || $wholes === []) {
            return ['the run draws no building or never asks for the whole of it'];
        }

        $first = $built[0];
        $defects = $this->framesEveryPlate($first['camera'], $first['scene'], 'the first framing');

        if ($first['camera']['fitted'] !== true) {
            $defects[] = 'the first framing is not at fit — whole-building is how the lobby is entered';
        }

        foreach ($result['camera_acts'] as $a) {
            if ($a['at'] <= $first['at']) {
                $defects[] = "a camera act at {$a['at']} ms came before the first framing — the run cannot tell a fit from a move";
            }
        }

        $scene = $built[count($built) - 1]['scene'];

        foreach ($wholes as $a) {
            array_push($defects, ...$this->framesEveryPlate($a['after'], $scene, "the whole-building control at {$a['at']} ms"));

            if ($this->framesEveryPlate($a['before'], $scene, '') === []) {
                $defects[] = "before the whole-building control at {$a['at']} ms every plate was already in view — it measured nothing";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function framesEveryPlate(array $camera, array $scene, string $what): array
    {
        $defects = [];

        foreach ($scene['plates'] as $p) {
            if (! $this->contains($camera['view'], $p['rect'])) {
                $defects[] = "{$what} leaves the plate {$p['floor']} out of view";
            }
        }

        if (! $this->contains($camera['view'], $scene['extent'])) {
            $defects[] = "{$what} does not frame the building's whole extent";
        }

        return $defects;
    }

    /** @return list<string> */
    private function rideDefects(array $result): array
    {
        $rides = $this->rides($result);
        $built = $this->built($result);

        if (count($rides) < 2 || $built === []) {
            return ['the run needs two rides over a drawn building'];
        }

        $defects = [];
        $labels = [];

        foreach ($rides as $a) {
            $ride = $a['ride'];

            if ($ride === null) {
                $defects[] = "the ride at {$a['at']} ms was refused on a building of two floors";

                continue;
            }

            // The frame the ride drew: the cab at the plate it rode to.
            $drawn = array_values(array_filter($built, static fn (array $f): bool => $f['at'] === $a['at']));
            $frame = $drawn[count($drawn) - 1] ?? null;
            $key = $ride['cab'];
            $plates = $frame === null ? [] : array_column($frame['building']['plates'], null, 'floor');
            $plate = $plates[$key] ?? null;

            if ($frame === null || $plate === null) {
                $defects[] = "the ride at {$a['at']} ms drew no building holding the floor {$key}";

                continue;
            }

            if ($frame['building']['elevator']['at'] !== $key) {
                $defects[] = "the ride at {$a['at']} ms did not bring the cab to {$key}";
            }

            if ($ride['route'] !== '/floor/'.rawurlencode($key)) {
                $defects[] = "the ride at {$a['at']} ms ends at {$ride['route']}, not at /floor/{$key} — the floor's KEY";
            }

            if ($ride['resolves_to'] !== $key) {
                $defects[] = "the ride at {$a['at']} ms ends at a route the floor page does not resolve to {$key}";
            }

            $labels[] = $plate['label'];

            // Zoom-to-a-plate is the ride: the plate whole in the view, and the view tight on it.
            $rect = array_column($frame['scene']['plates'], 'rect', 'floor')[$key];
            $view = $a['after']['view'];

            if (! $this->contains($view, $rect)) {
                $defects[] = "after the ride at {$a['at']} ms the plate {$key} is not in view";
            }

            if (abs($view['w'] - $rect['w']) > self::EPSILON && abs($view['h'] - $rect['h']) > self::EPSILON) {
                $defects[] = "after the ride at {$a['at']} ms the camera is not on the plate {$key} — the view is not tight on it";
            }
        }

        if (! in_array(null, $labels, true) || array_filter($labels) === []) {
            $defects[] = 'the run does not ride to both the labelled floor and the other';
        }

        return $defects;
    }

    /** @return list<string> */
    private function survivalDefects(array $result): array
    {
        $defects = [];
        $built = $this->built($result);

        foreach ($result['camera_acts'] as $i => $a) {
            $next = $result['camera_acts'][$i + 1]['at'] ?? PHP_INT_MAX;
            // The renders after this act and before the next one — a ride's own draw included.
            $after = array_values(array_filter($built, static fn (array $f): bool => $f['at'] >= $a['at'] && $f['at'] < $next));

            foreach ($after as $f) {
                $c = $f['camera'];

                if (abs($c['zoom'] - $a['after']['zoom']) > self::EPSILON || abs($c['x'] - $a['after']['x']) > self::EPSILON || abs($c['y'] - $a['after']['y']) > self::EPSILON) {
                    $defects[] = sprintf('the render at %d ms moved the camera from zoom %.4f at (%.2f, %.2f) to zoom %.4f at (%.2f, %.2f)',
                        $f['at'], $a['after']['zoom'], $a['after']['x'], $a['after']['y'], $c['zoom'], $c['x'], $c['y']);
                }
            }
        }

        // A heartbeat rendered while the camera stood on a plate — or this clause held nothing.
        $rides = $this->rides($result);
        $last = $rides[count($rides) - 1] ?? null;
        $held = $last === null ? [] : array_filter($built, static fn (array $f): bool => $f['at'] > $last['at'] && ! $f['camera']['fitted']);

        if ($held === []) {
            $defects[] = 'no render followed a ride with the camera on the plate';
        }

        return $defects;
    }

    /** @return list<string> */
    private function logDefects(string $run): array
    {
        $lobby = $this->fixture($run)['lobby'];
        unset($lobby['camera']);

        $touched = $this->replay($run);
        $untouched = $this->replay($run, null, ['lobby' => $lobby]);
        $defects = [];

        if ($touched['camera_acts'] === [] || $untouched['camera_acts'] !== []) {
            $defects[] = 'the pair is not a run with camera acts and the same run without them';
        }

        if ($touched['animation_log'] !== $untouched['animation_log']) {
            $defects[] = sprintf('the camera acts changed the animation log: %d rows with them, %d without',
                count($touched['animation_log']), count($untouched['animation_log']));
        }

        // § 11's discriminating control: a client that never touches the camera reads the building at fit.
        $built = $this->built($untouched);

        if ($built === []) {
            $defects[] = 'the control drew no building';
        }

        foreach ($built as $f) {
            if ($f['camera']['fitted'] !== true) {
                $defects[] = "the control's frame at {$f['at']} ms is not at fit";
            }

            array_push($defects, ...$this->framesEveryPlate($f['camera'], $f['scene'], "the control's frame at {$f['at']} ms"));
        }

        return $defects;
    }

    /**
     * Every module the lobby page loads, from `lobby/main.js` through every relative import — and each
     * that is the animation log or the animation set, the only two ways to write a row or start one.
     *
     * @return list<string>
     */
    private function graphDefects(string $root): array
    {
        $seen = [];
        $queue = ['lobby/main.js'];

        while ($queue !== []) {
            $file = array_shift($queue);

            if (isset($seen[$file])) {
                continue;
            }

            $path = $root.'/'.$file;
            $this->assertFileExists($path, "the lobby page imports {$file}, which is not there");
            $seen[$file] = true;

            preg_match_all("/from '(\.\.?\/[A-Za-z0-9._\/-]+)'/", (string) file_get_contents($path), $m);

            foreach ($m[1] as $import) {
                $queue[] = $this->normalise(dirname($file).'/'.$import);
            }
        }

        $this->assertContains('lobby/lobby-screen.js', array_keys($seen), 'the walk never reached the lobby screen — it read nothing');
        $this->assertContains('wire/camera.js', array_keys($seen), 'the walk never reached the camera — it read nothing');

        return array_values(array_map(
            static fn (string $f): string => "the lobby page loads {$f}, which writes the animation log or starts the set",
            array_filter(array_keys($seen), static fn (string $f): bool => in_array(basename($f), ['animation-log.js', 'animation-set.js'], true)),
        ));
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

        foreach (['ride', 'building'] as $act) {
            if ($this->acts($reduced, $act) === [] || $this->acts($moving, $act) === []) {
                $defects[] = "a run has no {$act}";
            }

            foreach ($this->acts($reduced, $act) as $a) {
                if ($a['glide_ms'] !== 0) {
                    $defects[] = "under prefers-reduced-motion the {$act} at {$a['at']} ms glides for {$a['glide_ms']} ms";
                }
            }

            foreach ($this->acts($moving, $act) as $a) {
                if (! ($a['glide_ms'] > 0)) {
                    $defects[] = "without reduced motion the {$act} at {$a['at']} ms does not glide — the cut clause measured nothing";
                }
            }
        }

        return $defects;
    }

    private function contains(array $outer, array $inner): bool
    {
        return $inner['x'] >= $outer['x'] - self::EPSILON && $inner['y'] >= $outer['y'] - self::EPSILON
            && $inner['x'] + $inner['w'] <= $outer['x'] + $outer['w'] + self::EPSILON
            && $inner['y'] + $inner['h'] <= $outer['y'] + $outer['h'] + self::EPSILON;
    }
}

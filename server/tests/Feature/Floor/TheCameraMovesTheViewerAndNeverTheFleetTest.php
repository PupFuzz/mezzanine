<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-21 — the camera moves the viewer and never the fleet, floor half.** `docs/design/FLOOR.md
 * § 11`, gated at Appendix B row 15 (card#7341): § 4.5's capability floor and its camera-is-navigation
 * rule, read from the floor screen's frames and its camera through the harness — `wire/camera.js`, the
 * camera, held and framed by `floor/floor-screen.js`, which also decides the capability floor.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE `fixtures/fx-camera.json`'s, each a byte-for-byte replay of § 11's fixtures at a
 * viewport it states, with the viewer's camera acts on the scenario clock (`fleet-client-probe.mjs`).
 * F is § 12's viewport floor, READ FROM § 12 here on every run and held against each run's stated
 * viewport — so a figure moved in the document reds this test until the fixture and
 * `floor-screen.js`'s `VIEWPORT_FLOOR` move with it, and a `VIEWPORT_FLOOR` moved alone reds the
 * capability clause (F must draw the floor, one pixel under it in either dimension the list).
 *
 * ⛔ THE LOG CLAUSE COMPARES AGAINST THE SAME RUN WITH THE CAMERA ACTS REMOVED — § 11's discriminating
 * control: a client that never touches the camera reads the scene at fit and the log carrying exactly
 * the rows the fixture's messages write. Equal logs are what *the rows it gained are the messages'
 * own* means; a count would pass a camera row that displaced a message's.
 *
 * ⚠ WHAT THIS DOES NOT HOLD, AND WHERE IT IS: what a list-view row SAYS — the `fold_lag` seat's lag
 * line, the `catching_up` seat's currency label, the `config_invalid` seat's *sending nothing* note,
 * and § 11's fifth RED — is the list module's (row 15's list view, built beside `desk/desk-render.js`
 * with its own guard). This test holds that below the floor the route renders the list view, one row
 * per seat and no map, over the desk models every row is built from.
 *
 * ⚠ AND WHAT NO TEST HERE CAN: that a wheel event or a drag in a browser reaches these acts. The page
 * half (`floor/main.js`) is wired to them and decides nothing, and there is no browser on the build
 * host; `FloorPageWiringTest` holds its elements.
 */
class TheCameraMovesTheViewerAndNeverTheFleetTest extends TestCase
{
    use DrivesTheScene;

    private const FLOOR = 'camera_floor';

    private const OVERFLOW = 'camera_overflow';

    private const SHORT = 'camera_short';

    private const REDUCED = 'camera_reduced';

    private const PROPORTIONAL = 'camera_proportional';

    private const NOTHING_MEASURABLE = 'camera_nothing_measurable';

    private const ENTRY_SHORT = ['camera_entry_short_width', 'camera_entry_short_height'];

    private const DEGRADED_SHORT = ['camera_degraded_short_width', 'camera_degraded_short_height'];

    private const CAMERA = 'camera.js';

    private const EPSILON = 1e-6;

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_every_run_states_a_viewport_at_section_12s_floor_or_one_pixel_under_it(): void
    {
        [$w, $h] = $this->viewportFloor();

        foreach ([self::FLOOR, self::OVERFLOW, self::SHORT, self::REDUCED, self::PROPORTIONAL, self::NOTHING_MEASURABLE] as $run) {
            $this->assertSame(['width' => $w, 'height' => $h], $this->fixture($run)['floor']['viewport'], "[{$run}] is not entered at F");
        }

        foreach ([self::ENTRY_SHORT, self::DEGRADED_SHORT] as [$short_w, $short_h]) {
            $this->assertSame(['width' => $w - 1, 'height' => $h], $this->fixture($short_w)['floor']['viewport'], "[{$short_w}]");
            $this->assertSame(['width' => $w, 'height' => $h - 1], $this->fixture($short_h)['floor']['viewport'], "[{$short_h}]");
        }

        $resizes = array_values(array_filter($this->fixture(self::SHORT)['floor']['camera'], static fn (array $a): bool => $a['act'] === 'resize'));
        $this->assertSame([[$w - 1, $h], [$w, $h], [$w, $h - 1], [$w, $h]],
            array_map(static fn (array $a): array => [$a['width'], $a['height']], $resizes),
            '[camera_short] does not shrink to F less one pixel in each dimension and grow back');
    }

    public function test_green_the_first_render_frames_the_floor_at_fit_with_no_transition(): void
    {
        $this->assertSame([], $this->firstFramingDefects($this->floorRun(self::FLOOR)));
    }

    public function test_green_a_wheel_zoom_keeps_the_scene_point_under_the_cursor(): void
    {
        $this->assertSame([], $this->wheelDefects($this->floorRun(self::FLOOR)));
    }

    /**
     * The wheel zooms in proportion to its scroll — a trackpad's burst of small deltas by the distance
     * it covers, `deltaMode` normalised, one event at most one notch — and never a step per event.
     */
    public function test_green_a_wheel_zooms_in_proportion_to_its_scroll_and_a_trackpad_burst_stays_bounded(): void
    {
        $this->assertSame([], $this->wheelScaleDefects($this->floorRun(self::PROPORTIONAL)));
    }

    /** The keyboard's and the zoom buttons' zoom: a step per notch about the surface's centre. */
    public function test_green_a_zoom_step_zooms_a_notch_about_the_surface_centre(): void
    {
        $this->assertSame([], $this->zoomStepDefects($this->floorRun(self::PROPORTIONAL)));
    }

    /** A floor with nothing measurable on it draws, strip and all, and leaves the camera unframed. */
    public function test_green_a_scene_with_no_extent_paints_and_frames_nothing(): void
    {
        $this->assertSame([], $this->nullExtentDefects());
    }

    public function test_green_a_drag_pans_by_the_offset_and_the_clamp_keeps_the_floor_in_view(): void
    {
        $this->assertSame([], $this->dragDefects($this->floorRun(self::FLOOR)));
    }

    public function test_green_fit_floor_frames_the_floors_whole_extent_and_the_overflow_strip(): void
    {
        $this->assertSame([], $this->fitDefects($this->floorRun(self::OVERFLOW)));
    }

    public function test_green_every_message_applied_afterwards_leaves_zoom_and_pan_exactly_as_they_were(): void
    {
        $this->assertSame([], $this->survivalDefects($this->floorRun(self::FLOOR)));
    }

    public function test_green_the_log_gains_no_row_from_any_camera_act_and_the_rows_it_gains_are_the_messages_own(): void
    {
        foreach ([self::FLOOR, self::SHORT, self::PROPORTIONAL] as $run) {
            $this->assertSame([], $this->logDefects($run), "[{$run}]");
        }
    }

    public function test_green_below_the_floor_in_either_dimension_the_route_renders_the_list_view_and_grown_back_the_floor_at_fit(): void
    {
        $this->assertSame([], $this->capabilityDefects(), 'the capability floor');
    }

    public function test_green_under_reduced_motion_the_camera_cuts_rather_than_glides(): void
    {
        $this->assertSame([], $this->glideDefects($this->floorRun(self::REDUCED), $this->floorRun(self::OVERFLOW)));
    }

    /**
     * ⭐ § 12's viewport row, MEASURED (Appendix B row 15 owes it): the camera's fit zoom at F over the
     * shipped default, and what it draws the desk text at — the scene's font size times that zoom. The
     * row states both; this re-derives both from the camera and `floor/desk-layout.js`'s `FONT` on
     * every run, so the row can drift from the camera only by this test going red.
     */
    public function test_section_12s_viewport_row_states_what_the_camera_measures_at_the_floor(): void
    {
        $this->assertSame([], $this->measurementDefects($this->floorRun(self::FLOOR), $this->floorMd()));
    }

    // ── RED — each defect planted in the shipped module it would live in, and watched failing ──

    /** § 11's RED: write an `edge` row for the zoom transition. */
    public function test_red_the_logged_zoom(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            "        this.#camera = wheel(this.#camera, point, delta);\n",
            "        this.#camera = wheel(this.#camera, point, delta);\n        this.#tap.log.edge({ animation_id: 'A14', cause: null, install_id: null, seat_id: null, motion: true, at: this.#clock.now() });\n"]);

        $this->assertNotSame([], $this->logDefects(self::FLOOR, $dir), 'the RED (a logged zoom) did not bite');
    }

    /** § 11's second RED: re-fit the floor on every render. */
    public function test_red_the_reset_camera(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            'this.#camera = frameOn(this.#camera, scene.extent);',
            'this.#camera = fit(frameOn(this.#camera, scene.extent));']);

        $this->assertNotSame([], $this->survivalDefects($this->floorRun(self::FLOOR, $dir)), 'the second RED (a camera reset by every render) did not bite');
    }

    /** § 11's third RED: one pixel under F, draw the floor scaled to fit. */
    public function test_red_the_scaled_down_floor(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN, "? 'floor' : 'list';", "? 'floor' : 'floor';"]);

        $this->assertNotSame([], $this->capabilityDefects($dir), 'the third RED (a floor scaled down below F) did not bite');
    }

    /** § 11's fourth RED: fit-floor frames the floor's extent alone (card#7965). */
    public function test_red_the_fit_that_cuts_the_strip(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            'this.#camera = frameOn(this.#camera, scene.extent);',
            'this.#camera = frameOn(this.#camera, { x: frame.extent.x, y: frame.extent.y, w: frame.extent.width, h: frame.extent.height });']);

        $this->assertNotSame([], $this->fitDefects($this->floorRun(self::OVERFLOW, $dir)), 'the fourth RED (a fit that cuts the strip) did not bite');
    }

    /** A zoom that does not hold the cursor's point — the wheel clause's own control. */
    public function test_red_a_zoom_about_the_corner_not_the_cursor(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'x: at.x - point.x / zoom, y: at.y - point.y / zoom, fitted: false',
            'x: camera.x, y: camera.y, fitted: false']);

        $this->assertNotSame([], $this->wheelDefects($this->floorRun(self::FLOOR, $dir)), 'CONTROL (a zoom about the corner) did not bite');
    }

    /** Round-1 F1's defect: a notch per wheel event, whatever its scroll — the scale clause's control. */
    public function test_red_a_wheel_that_steps_a_notch_per_event(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'return zoomAt(camera, point, ZOOM_STEP ** (-scroll / NOTCH_PX));',
            'return zoomAt(camera, point, scroll < 0 ? ZOOM_STEP : 1 / ZOOM_STEP);']);

        $this->assertNotSame([], $this->wheelScaleDefects($this->floorRun(self::PROPORTIONAL, $dir)), 'CONTROL (a notch per event) did not bite');
    }

    /** A wheel that reads `deltaY` as pixels whatever its `deltaMode`. */
    public function test_red_a_wheel_that_ignores_delta_mode(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'const unit = deltaMode === 2 ? camera.surface.height : deltaMode === 1 ? LINE_PX : 1;',
            'const unit = 1;']);

        $this->assertNotSame([], $this->wheelScaleDefects($this->floorRun(self::PROPORTIONAL, $dir)), 'CONTROL (deltaMode ignored) did not bite');
    }

    /** A wheel event with no per-event bound. */
    public function test_red_a_wheel_event_that_leaps_past_a_notch(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'const scroll = Math.min(NOTCH_PX, Math.max(-NOTCH_PX, deltaY * unit));',
            'const scroll = deltaY * unit;']);

        $this->assertNotSame([], $this->wheelScaleDefects($this->floorRun(self::PROPORTIONAL, $dir)), 'CONTROL (an unbounded event) did not bite');
    }

    /** A zoom step about the corner and not the centre. */
    public function test_red_a_zoom_step_about_the_corner(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'return zoomAt(camera, { x: camera.surface.width / 2, y: camera.surface.height / 2 }, ZOOM_STEP ** notches);',
            'return zoomAt(camera, { x: 0, y: 0 }, ZOOM_STEP ** notches);']);

        $this->assertNotSame([], $this->zoomStepDefects($this->floorRun(self::PROPORTIONAL, $dir)), 'CONTROL (a zoom step about the corner) did not bite');
    }

    /** Round-1 F4's defect: frame every scene, a `null` extent among them — the render throws. */
    public function test_red_a_render_that_frames_a_scene_with_no_extent(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            '} else if (scene !== null && scene.extent !== null) {',
            '} else if (scene !== null) {']);

        $this->assertNotSame([], $this->nullExtentDefects($dir), 'CONTROL (a null extent framed) did not bite');
    }

    /** A drag with no clamp — the clamp clause's own control. */
    public function test_red_a_drag_that_loses_the_floor(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'return clamp({ ...camera, x: camera.x - dx / camera.zoom',
            'return settle({ ...camera, x: camera.x - dx / camera.zoom']);

        $this->assertNotSame([], $this->dragDefects($this->floorRun(self::FLOOR, $dir)), 'CONTROL (an unclamped drag) did not bite');
    }

    /** A glide under reduced motion — the cut clause's own control. */
    public function test_red_a_glide_under_reduced_motion(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN, 'glideMs(this.#options.reduce === true)', 'glideMs(false)']);

        $this->assertNotSame([], $this->glideDefects($this->floorRun(self::REDUCED, $dir), $this->floorRun(self::OVERFLOW, $dir)),
            'CONTROL (a glide under prefers-reduced-motion) did not bite');
    }

    /** A first framing that is not the fit. */
    public function test_red_a_first_render_that_is_not_at_fit(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            "        return fit({ ...camera, bounds: rectOf(bounds) });\n",
            "        return clamp({ ...camera, bounds: rectOf(bounds) });\n"]);

        $this->assertNotSame([], $this->firstFramingDefects($this->floorRun(self::FLOOR, $dir)), 'CONTROL (a first framing not at fit) did not bite');
    }

    /** A § 12 row whose measurement is not the camera's. */
    public function test_red_a_viewport_row_that_states_another_measurement(): void
    {
        $md = $this->floorMd();
        $drifted = preg_replace('/(the nameplate and the badges\' text at \*\*)[\d.]+( CSS px\*\*)/', '${1}9.9${2}', $md, 1);

        $this->assertNotSame($md, $drifted, 'the plant found no measurement in § 12 to move');
        $this->assertNotSame([], $this->measurementDefects($this->floorRun(self::FLOOR), (string) $drifted), 'CONTROL (a drifted measurement) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} § 12's viewport floor, read from the row on every run */
    private function viewportFloor(): array
    {
        $this->assertSame(1, preg_match('/^\| Floor viewport floor \| \*\*([\d,]+) × ([\d,]+) CSS px\*\* \|/m', $this->floorMd(), $m),
            '§ 12\'s viewport-floor row did not parse');

        return [(int) str_replace(',', '', $m[1]), (int) str_replace(',', '', $m[2])];
    }

    /**
     * The frames that drew a scene.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function framed(array $result): array
    {
        return array_values(array_filter(array_map(static fn (array $r): array => $r['frame'] + ['at' => $r['at']], $result['floor_renders']),
            static fn (array $f): bool => $f['scene'] !== null));
    }

    /** @return list<string> */
    private function firstFramingDefects(array $result): array
    {
        $framed = $this->framed($result);

        if ($framed === []) {
            return ['no frame drew the floor'];
        }

        $first = $framed[0];
        $defects = [];

        foreach ($result['camera_acts'] as $act) {
            if ($act['at'] <= $first['at']) {
                $defects[] = "a camera act at {$act['at']} ms came before the first framing — the run cannot tell a fit from a move";
            }
        }

        return array_merge($defects, $this->atFitDefects($first['camera'], $first['scene']['extent'], 'the first framing'));
    }

    /**
     * Whether a camera frames `extent` at fit: the extent whole in the view, and tight in one axis.
     *
     * @param  array<string, mixed>  $camera
     * @param  array<string, mixed>  $extent
     * @return list<string>
     */
    private function atFitDefects(array $camera, array $extent, string $what): array
    {
        $defects = [];
        $view = $camera['view'];

        if (! $this->contains($view, $extent)) {
            $defects[] = "{$what} does not show the scene's whole extent";
        }

        $tight = abs($view['w'] - $extent['w']) < self::EPSILON || abs($view['h'] - $extent['h']) < self::EPSILON;

        if (! $tight) {
            $defects[] = "{$what} is not at fit: the view is wider and taller than the extent (zoom {$camera['zoom']})";
        }

        if ($camera['fitted'] !== true) {
            $defects[] = "{$what}'s camera does not say it is at fit";
        }

        return $defects;
    }

    /** @return list<string> */
    private function wheelDefects(array $result): array
    {
        $wheels = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'wheel'));
        $defects = $wheels === [] ? ['the run turned the wheel nowhere'] : [];

        foreach ($wheels as $a) {
            $p = ['x' => $a['act']['x'], 'y' => $a['act']['y']];
            $before = $this->toScene($a['before'], $p);
            $after = $this->toScene($a['after'], $p);

            if (! ($a['after']['zoom'] > $a['before']['zoom'])) {
                $defects[] = "the wheel at {$a['at']} ms did not zoom in";
            }

            if (abs($before['x'] - $after['x']) > self::EPSILON || abs($before['y'] - $after['y']) > self::EPSILON) {
                $defects[] = sprintf('the wheel at %d ms moved the scene point under the cursor from (%.3f, %.3f) to (%.3f, %.3f)',
                    $a['at'], $before['x'], $before['y'], $after['x'], $after['y']);
            }
        }

        return $defects;
    }

    /**
     * The camera module's own constants, read from the shipped file — the zoom step, the notch's
     * scroll and a line's height — so no figure here is this test's.
     *
     * @return array{step: float, notch: float, line: float}
     */
    private function wheelConstants(): array
    {
        $src = (string) file_get_contents($this->jsRoot().'/wire/camera.js');
        $read = function (string $name) use ($src): float {
            $this->assertSame(1, preg_match("/export const {$name} = ([\\d.]+);/", $src, $m), "camera.js's {$name} did not parse");

            return (float) $m[1];
        };

        return ['step' => $read('ZOOM_STEP'), 'notch' => $read('NOTCH_PX'), 'line' => $read('LINE_PX')];
    }

    /** @return list<string> */
    private function wheelScaleDefects(array $result): array
    {
        $c = $this->wheelConstants();
        $wheels = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'wheel'));
        $defects = [];
        $modes = [];
        $bounded = false;
        $burst = ['n' => 0, 'px' => 0.0, 'factor' => 1.0];

        foreach ($wheels as $a) {
            $mode = $a['act']['delta_mode'] ?? 0;
            $unit = match ($mode) {
                2 => $a['before']['surface']['height'],
                1 => $c['line'],
                default => 1,
            };
            $px = $a['act']['delta_y'] * $unit;
            $scroll = max(-$c['notch'], min($c['notch'], $px));
            $expected = $a['before']['zoom'] * $c['step'] ** (-$scroll / $c['notch']);
            $modes[$mode] = true;
            $bounded = $bounded || abs($px) > $c['notch'];

            if (abs($a['after']['zoom'] - $expected) > self::EPSILON) {
                $defects[] = sprintf('the wheel at %d ms (delta %s, mode %d) zoomed %.6f → %.6f; its scroll makes it %.6f',
                    $a['at'], $a['act']['delta_y'], $mode, $a['before']['zoom'], $a['after']['zoom'], $expected);
            }

            // The trackpad's burst: the small pixel-mode deltas, whose sum is the gesture's scroll.
            if ($mode === 0 && abs($px) < $c['notch'] / 10) {
                $burst['n']++;
                $burst['px'] += $px;
                $burst['factor'] *= $a['after']['zoom'] / $a['before']['zoom'];
            }
        }

        if ($burst['n'] < 20) {
            $defects[] = "the run's trackpad burst is {$burst['n']} events — too few to tell a step per event from a scroll";
        } elseif (abs($burst['factor'] - $c['step'] ** (-$burst['px'] / $c['notch'])) > self::EPSILON) {
            $defects[] = sprintf('a trackpad burst of %d events scrolling %.0f px in all zoomed ×%.4f; its scroll is ×%.4f',
                $burst['n'], $burst['px'], $burst['factor'], $c['step'] ** (-$burst['px'] / $c['notch']));
        }

        foreach ([0 => 'pixel', 1 => 'line', 2 => 'page'] as $mode => $name) {
            if (! isset($modes[$mode])) {
                $defects[] = "the run turns the wheel in no {$name}-mode event";
            }
        }

        if (! $bounded) {
            $defects[] = 'the run turns the wheel in no event past a notch, so the per-event bound was never asked to bite';
        }

        return $defects;
    }

    /** @return list<string> */
    private function zoomStepDefects(array $result): array
    {
        $step = $this->wheelConstants()['step'];
        $zooms = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'zoom'));
        $defects = [];
        $signs = [];

        foreach ($zooms as $a) {
            $n = $a['act']['notches'];
            $signs[$n <=> 0] = true;
            $centre = ['x' => $a['before']['surface']['width'] / 2, 'y' => $a['before']['surface']['height'] / 2];
            $before = $this->toScene($a['before'], $centre);
            $after = $this->toScene($a['after'], $centre);

            if (abs($a['after']['zoom'] - $a['before']['zoom'] * $step ** $n) > self::EPSILON) {
                $defects[] = "the zoom step at {$a['at']} ms did not zoom {$n} notch(es)";
            }

            if (abs($before['x'] - $after['x']) > self::EPSILON || abs($before['y'] - $after['y']) > self::EPSILON) {
                $defects[] = sprintf('the zoom step at %d ms moved the scene point at the centre from (%.3f, %.3f) to (%.3f, %.3f)',
                    $a['at'], $before['x'], $before['y'], $after['x'], $after['y']);
            }
        }

        if (! isset($signs[1], $signs[-1])) {
            $defects[] = 'the run steps the zoom not both in and out';
        }

        return $defects;
    }

    /**
     * A floor with nothing measurable: the run must complete (a throw inside `render()` is the
     * defect), draw the scene with a `null` extent and the strip, leave the camera unframed, and every
     * act on that camera move nothing.
     *
     * @return list<string>
     */
    private function nullExtentDefects(?string $dir = null): array
    {
        [$status, $stdout, $stderr] = $this->runProbe($this->fixture(self::NOTHING_MEASURABLE), $dir);

        if ($status !== 0) {
            $lines = array_values(array_filter(explode("\n", $stderr), static fn (string $l): bool => str_starts_with($l, 'Error')));

            return ['the render threw: '.($lines[0] ?? trim($stderr))];
        }

        $result = json_decode($stdout, true)['runs'][0];
        $defects = [];
        $drawn = array_values(array_filter($result['floor_renders'], static fn (array $r): bool => $r['frame']['scene'] !== null));

        if ($drawn === []) {
            return ['no frame drew a scene — the run does not reach a scene with no extent'];
        }

        foreach ($drawn as $r) {
            $f = $r['frame'];

            if ($f['scene']['extent'] !== null) {
                $defects[] = "the scene at {$r['at']} ms has an extent — the run measures a floor with something on it";
            }

            if ($f['strip'] === null) {
                $defects[] = "the frame at {$r['at']} ms carries no status strip";
            }

            if ($f['camera']['bounds'] !== null) {
                $defects[] = "the frame at {$r['at']} ms framed a rect on a floor with none";
            }
        }

        if ($result['camera_acts'] === []) {
            $defects[] = 'the run touched no camera';
        }

        foreach ($result['camera_acts'] as $a) {
            if ($a['after'] !== $a['before']) {
                $defects[] = "the {$a['act']['act']} at {$a['at']} ms moved a camera with nothing framed";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function dragDefects(array $result): array
    {
        $drags = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'drag'));

        if (count($drags) < 2) {
            return ['the run needs a drag inside the clamp and one past it'];
        }

        $defects = [];
        $first = $drags[0];
        $z = $first['before']['zoom'];

        // The first drag stays inside the clamp: the pan moved by the offset, exactly.
        if (abs($first['after']['x'] - ($first['before']['x'] - $first['act']['dx'] / $z)) > self::EPSILON
            || abs($first['after']['y'] - ($first['before']['y'] - $first['act']['dy'] / $z)) > self::EPSILON
            || abs($first['after']['zoom'] - $z) > self::EPSILON) {
            $defects[] = "the drag at {$first['at']} ms did not pan by its offset";
        }

        // Every camera the run held keeps the floor in view — the far drag's above all.
        foreach ($result['camera_acts'] as $a) {
            if ($a['after']['bounds'] !== null && ! $this->inView($a['after'])) {
                $defects[] = "after the {$a['act']['act']} at {$a['at']} ms the floor is not in view";
            }
        }

        $far = array_values(array_filter($drags, static fn (array $a): bool => abs($a['act']['dx']) >= 10000));

        if ($far === []) {
            $defects[] = 'the run drags nowhere past the floor, so the clamp was never asked to bite';
        }

        return $defects;
    }

    /** @return list<string> */
    private function fitDefects(array $result): array
    {
        $fits = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'fit'));
        $frame = $this->lastFloor($result);
        $scene = $frame['scene'];

        if ($fits === [] || $scene === null || $scene['strip'] === null) {
            return ['the run fits no floor with an overflow strip'];
        }

        $after = $fits[count($fits) - 1]['after'];
        $defects = [];
        $floor = ['x' => $frame['extent']['x'], 'y' => $frame['extent']['y'], 'w' => $frame['extent']['width'], 'h' => $frame['extent']['height']];

        if (! $this->contains($after['view'], $floor)) {
            $defects[] = 'fit-floor does not frame the floor\'s whole extent';
        }

        if (! $this->contains($after['view'], $scene['strip'])) {
            $defects[] = 'fit-floor cuts the overflow strip — the seats it exists to show are outside the frame';
        }

        if ($fits[count($fits) - 1]['before']['zoom'] === $after['zoom']) {
            $defects[] = 'the fit moved nothing — the run zooms in before it fits, or it measured nothing';
        }

        return $defects;
    }

    /** @return list<string> */
    private function survivalDefects(array $result): array
    {
        $acts = $result['camera_acts'];

        if ($acts === []) {
            return ['the run touched no camera'];
        }

        $last = $acts[count($acts) - 1];
        $defects = [];
        $after = array_values(array_filter($result['floor_renders'], static fn (array $r): bool => $r['at'] > $last['at']));

        // The messages were applied — or this clause measured renders of nothing.
        $labels = array_map(static fn (array $r): string => $r['label'].' → '.($r['outcome'] ?? ''), $result['records']);
        foreach (['message seat.delta aimla-pm 48220 → delivered', 'message seat.delta aimla-pm 48222 → delivered',
            'message room.map   → delivered', 'stream end → ended'] as $applied) {
            if (! in_array($applied, $labels, true)) {
                $defects[] = "the run never applied `{$applied}`";
            }
        }

        $requests = $result['final']['requests'];
        $count = static fn (string $prefix): int => count(array_filter($requests, static fn (string $r): bool => str_starts_with($r, $prefix)));

        foreach (['/api/fleet/snapshot' => 2, '/api/fleet/seats/aimla/aimla-pm' => 1, '/api/building/rooms/aimla/map' => 2] as $path => $n) {
            if ($count($path) < $n) {
                $defects[] = "the run asked for {$path} fewer than {$n} times — that re-render never happened";
            }
        }

        if (count($after) < 4) {
            $defects[] = 'too few renders followed the last camera act to hold anything';
        }

        foreach ($after as $r) {
            $c = $r['frame']['camera'];

            if (abs($c['zoom'] - $last['after']['zoom']) > self::EPSILON
                || abs($c['x'] - $last['after']['x']) > self::EPSILON
                || abs($c['y'] - $last['after']['y']) > self::EPSILON) {
                $defects[] = sprintf('the render at %d ms moved the camera from zoom %.4f at (%.2f, %.2f) to zoom %.4f at (%.2f, %.2f)',
                    $r['at'], $last['after']['zoom'], $last['after']['x'], $last['after']['y'], $c['zoom'], $c['x'], $c['y']);
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function logDefects(string $run, ?string $dir = null): array
    {
        $floor = $this->fixture($run)['floor'];
        unset($floor['camera']);

        $touched = $this->floorRun($run, $dir);
        $untouched = $this->floorRun($run, $dir, ['floor' => $floor]);
        $defects = [];

        if ($touched['camera_acts'] === [] || $untouched['camera_acts'] !== []) {
            $defects[] = 'the pair is not a run with camera acts and the same run without them';
        }

        if ($untouched['animation_log'] === []) {
            $defects[] = 'the control\'s log is empty — a clean log is not known to be reachable';
        }

        if ($touched['animation_log'] !== $untouched['animation_log']) {
            $defects[] = sprintf('the camera acts changed the animation log: %d rows with them, %d without',
                count($touched['animation_log']), count($untouched['animation_log']));
        }

        // The control reads the scene at fit throughout.
        foreach ($this->framed($untouched) as $f) {
            array_push($defects, ...$this->atFitDefects($f['camera'], $f['scene']['extent'], "the control's frame at {$f['at']} ms"));
        }

        return $defects;
    }

    /** @return list<string> */
    private function capabilityDefects(?string $dir = null): array
    {
        $defects = [];

        foreach ([...self::ENTRY_SHORT, ...self::DEGRADED_SHORT] as $run) {
            $seats = count($this->snapshotSeats($run));

            $renders = $this->floorRun($run, $dir)['floor_renders'];

            foreach ($renders as $r) {
                array_push($defects, ...$this->listDefects($r['frame'], $seats, "[{$run}] at {$r['at']} ms"));
            }

            if (count($this->lastFloor(['floor_renders' => $renders])['desks']['desks']) !== $seats) {
                $defects[] = "[{$run}] the list view's last render does not carry one row per seat";
            }
        }

        $result = $this->floorRun(self::SHORT, $dir);
        $seats = count($this->snapshotSeats(self::SHORT));
        $fit = $this->framed($result)[0]['camera'] ?? null;
        $resizes = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'resize'));

        foreach ($resizes as $a) {
            $frame = null;

            foreach ($result['floor_renders'] as $r) {
                $frame = $r['at'] === $a['at'] ? $r['frame'] : $frame;
            }

            if ($frame === null) {
                $defects[] = "no render followed the resize at {$a['at']} ms";

                continue;
            }

            [$w, $h] = $this->viewportFloor();
            $short = $a['act']['width'] < $w || $a['act']['height'] < $h;

            if ($short) {
                array_push($defects, ...$this->listDefects($frame, $seats, "[camera_short] at {$a['at']} ms"));
            } elseif ($frame['capability'] !== 'floor' || $frame['scene'] === null) {
                $defects[] = "[camera_short] grown back at {$a['at']} ms, the route does not draw the floor";
            } else {
                array_push($defects, ...$this->atFitDefects($frame['camera'], $frame['scene']['extent'], "[camera_short] grown back at {$a['at']} ms"));

                if ($fit !== null && abs($frame['camera']['zoom'] - $fit['zoom']) > self::EPSILON) {
                    $defects[] = "[camera_short] grown back at {$a['at']} ms, the floor is not at the fit it was entered at";
                }
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function listDefects(array $frame, int $seats, string $where): array
    {
        $defects = [];

        if ($frame['capability'] !== 'list') {
            $defects[] = "{$where}: below the viewport floor the route renders `{$frame['capability']}`, not the list view";
        }

        if ($frame['scene'] !== null || $frame['camera']['bounds'] !== null) {
            $defects[] = "{$where}: the list view carries a map";
        }

        $rows = count($frame['desks']['desks']);

        if ($rows !== 0 && $rows !== $seats) {
            $defects[] = "{$where}: the list view has {$rows} rows for {$seats} seats";
        }

        return $defects;
    }

    /** @return list<string> */
    private function glideDefects(array $reduced, array $moving): array
    {
        $defects = [];
        $fits = static fn (array $result): array => array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'fit'));

        if ($fits($reduced) === [] || $fits($moving) === []) {
            return ['a run fits nothing'];
        }

        foreach ($fits($reduced) as $a) {
            if ($a['glide_ms'] !== 0) {
                $defects[] = "under prefers-reduced-motion the fit glides for {$a['glide_ms']} ms";
            }
        }

        foreach ($fits($moving) as $a) {
            if (! ($a['glide_ms'] > 0)) {
                $defects[] = 'without reduced motion the fit does not glide — the cut clause measured nothing';
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function measurementDefects(array $result, string $md): array
    {
        $first = $this->framed($result)[0] ?? null;

        if ($first === null) {
            return ['no frame drew the floor'];
        }

        $layout = (string) file_get_contents($this->jsRoot().'/floor/desk-layout.js');
        $this->assertSame(1, preg_match("/export const FONT = '(\\d+)px /", $layout, $f), 'desk-layout.js\'s FONT did not parse');

        $zoom = $first['camera']['zoom'];
        $px = (int) $f[1] * $zoom;

        if (preg_match('/^\| Floor viewport floor \|[^\n]*?the camera\'s fit at this floor is \*\*([\d.]+)\*\*[^\n]*?draws the scene\'s (\d+) px text — the nameplate and the badges\' text at \*\*([\d.]+) CSS px\*\*/m', $md, $m) !== 1) {
            return ['§ 12\'s viewport row states no measurement in the form this check reads'];
        }

        $defects = [];

        if ($m[1] !== sprintf('%.4f', $zoom)) {
            $defects[] = sprintf('§ 12 states the camera\'s fit at the floor as %s and the camera measures %.4f', $m[1], $zoom);
        }

        if ((int) $m[2] !== (int) $f[1]) {
            $defects[] = "§ 12 states the scene's text at {$m[2]} px and desk-layout.js draws it at {$f[1]} px";
        }

        if ($m[3] !== sprintf('%.1f', $px)) {
            $defects[] = sprintf('§ 12 states the text drawn at %s CSS px and the camera draws it at %.1f', $m[3], $px);
        }

        return $defects;
    }

    // ── Geometry ───────────────────────────────────────────────────────────────────────────────

    /** @return array{x: float, y: float} */
    private function toScene(array $camera, array $point): array
    {
        return ['x' => $camera['x'] + $point['x'] / $camera['zoom'], 'y' => $camera['y'] + $point['y'] / $camera['zoom']];
    }

    private function contains(array $outer, array $inner): bool
    {
        return $inner['x'] >= $outer['x'] - self::EPSILON && $inner['y'] >= $outer['y'] - self::EPSILON
            && $inner['x'] + $inner['w'] <= $outer['x'] + $outer['w'] + self::EPSILON
            && $inner['y'] + $inner['h'] <= $outer['y'] + $outer['h'] + self::EPSILON;
    }

    /** Per axis: the view on the floor where it is the smaller, the floor inside the view where it is. */
    private function inView(array $camera): bool
    {
        $v = $camera['view'];
        $b = $camera['bounds'];
        $axis = fn (float $vs, float $vl, float $bs, float $bl): bool => $vl <= $bl + self::EPSILON
            ? $vs >= $bs - self::EPSILON && $vs + $vl <= $bs + $bl + self::EPSILON
            : $bs >= $vs - self::EPSILON && $bs + $bl <= $vs + $vl + self::EPSILON;

        return $axis($v['x'], $v['w'], $b['x'], $b['w']) && $axis($v['y'], $v['h'], $b['y'], $b['h']);
    }
}

<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **AT-D3-21 — the camera moves the viewer and never the fleet, floor half.** `docs/design/FLOOR.md
 * § 11`, gated at Appendix B row 15 (card#7341): § 4.5's any-size rule and its camera-is-navigation
 * rule, read from the floor screen's frames and its camera through the harness — `wire/camera.js`, the
 * camera, held and framed by `floor/floor-screen.js`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE `fixtures/fx-camera.json`'s, each a byte-for-byte replay of § 11's fixtures at a
 * viewport it states, with the viewer's camera acts on the scenario clock (`fleet-client-probe.mjs`).
 * F is § 12's reference viewport — the size § 12's measurement of the camera's fit is taken at — READ
 * FROM § 12 here on every run and held against each run entered at it, so a figure moved in the
 * document reds this test until the fixture moves with it. F is no minimum: the any-size runs are
 * entered in windows smaller than F in at least one dimension — a phone's 390 × 700 and a low
 * 1,280 × 240 — and § 4.5 (the operator's ruling of 2026-10-01 on card#7341) requires the room drawn
 * there under the camera, panned and zoomed exactly as at F, with no substitute view.
 *
 * ⛔ THE LOG CLAUSE COMPARES AGAINST THE SAME RUN WITH THE CAMERA ACTS REMOVED — § 11's discriminating
 * control: a client that never touches the camera reads the scene at fit and the log carrying exactly
 * the rows the fixture's messages write. Equal logs are what *the rows it gained are the messages'
 * own* means; a count would pass a camera row that displaced a message's.
 *
 * ⚠ WHAT THIS DOES NOT HOLD, AND WHERE IT IS: what a list-view row SAYS — the `fold_lag` seat's lag
 * line, the `catching_up` seat's currency label, the `config_invalid` seat's *sending nothing* note,
 * and § 11's fifth RED — is the list module's (row 15's list view, built beside `desk/desk-render.js`
 * with its own guard). This test holds that in a small window the route draws a desk for every seat
 * AND carries the desk models the list view's rows are built from, one per seat.
 *
 * ⛔ THE WHEEL PANS, CTRL+WHEEL AND THE PINCH ZOOM (§ 4.5, the operator's ruling of 2026-10-01 on
 * card#11045). A `scroll` act is a plain wheel event — the screen's `pan()`: the view moves by its
 * `deltaX`/`deltaY`, `deltaMode` normalised, and the act answers whether it consumed the event — yes
 * while the view can move the wheel's way on an axis the wheel moves along, no at an edge it can pass
 * no further, where the page scrolls on (Q3, the operator's ruling of 2026-10-01). A `ctrl_wheel` act is a Ctrl+wheel —
 * a mouse's Ctrl+notch or a trackpad's pinch — the screen's `zoom()` about the cursor, in proportion to
 * its scroll after the camera's `PINCH_GAIN`. A `pinch` act is one step of a touch screen's two-finger
 * pinch — the screen's `pinch()`, zooming by its factor about the fingers' midpoint.
 *
 * ⚠ AND WHAT NO TEST HERE CAN: that a wheel event, a pinch or a drag in a browser reaches these acts. The
 * page half (`floor/main.js`) is wired to them and decides nothing; `TheCameraWireIsOneForBothPagesTest`
 * holds the gesture wire under `node`, `FloorPageWiringTest` the page's elements, and card#11045 PR-B
 * drove the page in headless Chromium by hand — never a real trackpad, touch screen or Safari.
 */
class TheCameraMovesTheViewerAndNeverTheFleetTest extends TestCase
{
    use DrivesTheScene;

    private const FLOOR = 'camera_floor';

    /** `camera_floor`'s replay with the camera's surface at the page's own drawing box at F — § 12's page surface. */
    private const PAGE = 'camera_page';

    private const OVERFLOW = 'camera_overflow';

    private const RESIZE = 'camera_resize';

    private const REDUCED = 'camera_reduced';

    private const PROPORTIONAL = 'camera_proportional';

    private const NOTHING_MEASURABLE = 'camera_nothing_measurable';

    /** The any-size runs: entered in a window smaller than F, narrower in the first and lower in the second. */
    private const ENTRY_SMALL = ['camera_entry_narrow', 'camera_entry_low'];

    private const DEGRADED_SMALL = ['camera_degraded_narrow', 'camera_degraded_low'];

    private const CAMERA = 'camera.js';

    private const EPSILON = 1e-6;

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_every_run_states_a_viewport_at_section_12s_reference_or_a_window_smaller_than_it(): void
    {
        [$w, $h] = $this->referenceViewport();

        foreach ([self::FLOOR, self::OVERFLOW, self::RESIZE, self::REDUCED, self::PROPORTIONAL, self::NOTHING_MEASURABLE] as $run) {
            $this->assertSame(['width' => $w, 'height' => $h], $this->fixture($run)['floor']['viewport'], "[{$run}] is not entered at F");
        }

        // The page run: the drawing box the page gives the camera at F, as § 12 states it — as wide as F and
        // lower by the page chrome above the drawing (`tools/design/floor-chrome.browser.mjs` holds the
        // figure to the rendered page).
        $this->assertSame([], $this->pageSurfaceDefects($this->floorMd()));

        // Each pair: the first window narrower than F, the second lower — so each dimension is asked alone.
        foreach ([self::ENTRY_SMALL, self::DEGRADED_SMALL] as [$narrow, $low]) {
            $n = $this->fixture($narrow)['floor']['viewport'];
            $l = $this->fixture($low)['floor']['viewport'];
            $this->assertTrue($n['width'] < $w && $n['height'] < $h, "[{$narrow}] is not a window narrower and lower than F");
            $this->assertTrue($l['width'] === $w && $l['height'] < $h, "[{$low}] is not a window as wide as F and lower");
        }

        $resizes = array_values(array_filter($this->fixture(self::RESIZE)['floor']['camera'], static fn (array $a): bool => $a['act'] === 'resize'));
        $this->assertCount(4, $resizes, '[camera_resize] does not shrink twice and grow back twice');

        foreach ($resizes as $i => $a) {
            $this->assertSame($i % 2 === 1, $a['width'] === $w && $a['height'] === $h,
                "[camera_resize]'s resize #{$i} is not ".($i % 2 === 1 ? 'back to F' : 'a window smaller than F'));
        }
    }

    public function test_green_the_first_render_frames_the_floor_at_fit_with_no_transition(): void
    {
        $this->assertSame([], $this->firstFramingDefects($this->floorRun(self::FLOOR)));
    }

    public function test_green_a_ctrl_wheel_zoom_keeps_the_scene_point_under_the_cursor(): void
    {
        $this->assertSame([], $this->ctrlWheelDefects($this->floorRun(self::FLOOR)));
    }

    /**
     * A plain wheel pans (card#11045): the view moves by the event's `deltaX` and `deltaY` in CSS px over
     * the zoom, `deltaMode` normalised — lines at the camera's `LINE_PX`, pages at the surface's width or
     * height — with the zoom unchanged; a wheel far past the floor is clamped with the floor in view; and a
     * wheel is consumed while the view can move its way and released to the page at the edge (Q3).
     */
    public function test_green_a_plain_wheel_pans_by_its_scroll_in_any_direction_and_releases_at_the_edge(): void
    {
        $this->assertSame([], $this->panDefects($this->floorRun(self::FLOOR)));
    }

    /** A touch pinch's step (card#11045): the zoom by its factor, the scene under the fingers' midpoint carried with it. */
    public function test_green_a_touch_pinch_zooms_about_the_fingers_midpoint(): void
    {
        $this->assertSame([], $this->pinchDefects($this->floorRun(self::FLOOR)));
    }

    /**
     * A Ctrl+wheel zooms in proportion to its scroll — a trackpad pinch's burst of small deltas by the
     * distance it covers, `deltaMode` normalised, the scroll multiplied by the camera's `PINCH_GAIN`, one
     * event at most one notch — and never a step per event. The gain's figure is d3-zoom's; how a real
     * trackpad's pinch feels at it is not something this run can see.
     */
    public function test_green_a_ctrl_wheel_zooms_in_proportion_to_its_scroll_and_a_pinch_burst_stays_bounded(): void
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
        foreach ([self::FLOOR, self::RESIZE, self::PROPORTIONAL, ...self::ENTRY_SMALL] as $run) {
            $this->assertSame([], $this->logDefects($run), "[{$run}]");
        }
    }

    /**
     * § 4.5's first rule (the operator's ruling of 2026-10-01 on card#7341): in a window smaller than F
     * in either dimension the route draws the room at fit from its first frame, and the camera pans and
     * zooms it there as at F; every seat has its drawn desk and its desk model (the list view's row);
     * and a window resized smaller and back keeps the room drawn and the viewer's zoom.
     */
    public function test_green_at_every_window_size_the_route_draws_the_room_and_the_camera_pans_and_zooms_it(): void
    {
        $this->assertSame([], $this->anySizeDefects(), 'the any-size rule');
    }

    public function test_green_under_reduced_motion_the_camera_cuts_rather_than_glides(): void
    {
        $this->assertSame([], $this->glideDefects($this->floorRun(self::REDUCED), $this->floorRun(self::OVERFLOW)));
    }

    /**
     * ⭐ § 12's viewport row, MEASURED (Appendix B row 15 owes it): the camera's fit zoom at F over the
     * shipped default, and what it draws the desk text at in each of the desk's two type roles — each
     * role's font size times that zoom: the facts' text (`floor/desk-layout.js`'s `FONT`) and the
     * nameplate (its `FONT_NAME`, card#11058 Q2). The row states all three; this re-derives them from the
     * camera and those two declarations on every run, so the row can drift from either only by this test
     * going red. ⛔ THE SURFACE IS THE
     * PAGE's (card#11045 PR-E): the run is entered at the drawing box the floor page gives the camera at
     * F, which the test above holds to § 12's stated page surface — not at the whole window, which fits a
     * height-bound default higher than any viewer's page does.
     */
    public function test_section_12s_viewport_row_states_what_the_camera_measures_at_the_floor(): void
    {
        $this->assertSame([], $this->measurementDefects($this->floorRun(self::PAGE), $this->floorMd()));
    }

    // ── RED — each defect planted in the shipped module it would live in, and watched failing ──

    /**
     * The act each § 11 RED logs from: the Ctrl+wheel's zoom, the plain wheel's pan and the touch pinch.
     *
     * @return array<string, array{0: string}>
     */
    public static function navigationActs(): array
    {
        return [
            'a logged Ctrl+wheel zoom' => ["        const { camera, consumed } = zoom(this.#camera, point, delta);\n"],
            'a logged wheel pan' => ["        const { camera, consumed } = pan(this.#camera, delta);\n"],
            'a logged touch pinch' => ["        this.#camera = pinch(this.#camera, from, to, factor);\n"],
        ];
    }

    /** § 11's RED: write an `edge` row for the zoom transition — and for the pan and the pinch (card#11045). */
    #[DataProvider('navigationActs')]
    public function test_red_the_logged_zoom(string $act): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN, $act,
            $act."        this.#tap.log.edge({ animation_id: 'A14', cause: null, install_id: null, seat_id: null, motion: true, at: this.#clock.now() });\n"]);

        $this->assertNotSame([], $this->logDefects(self::FLOOR, $dir), 'the RED (a logged navigation act) did not bite');
    }

    /** § 11's second RED: re-fit the floor on every render. */
    public function test_red_the_reset_camera(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            'this.#camera = frameOn(this.#camera, scene.extent);',
            'this.#camera = fit(frameOn(this.#camera, scene.extent));']);

        $this->assertNotSame([], $this->survivalDefects($this->floorRun(self::FLOOR, $dir)), 'the second RED (a camera reset by every render) did not bite');
    }

    /**
     * § 11's third RED: a minimum window size returns — below F the frame carries no scene, the list
     * view standing in for the room. That is the capability floor this rule replaced, planted back.
     */
    public function test_red_the_substitute_view_below_a_minimum_size(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            'const scene = this.#scene(frame, rows);',
            'const scene = this.#camera.surface.width < 1280 || this.#camera.surface.height < 800 ? null : this.#scene(frame, rows);']);

        $this->assertNotSame([], $this->anySizeDefects($dir), 'the third RED (a substitute view below a minimum size) did not bite');
    }

    /** § 11's fourth RED: fit-floor frames the floor's extent alone (card#7965). */
    public function test_red_the_fit_that_cuts_the_strip(): void
    {
        $dir = $this->mutatedModules([self::FLOOR_SCREEN,
            'this.#camera = frameOn(this.#camera, scene.extent);',
            'this.#camera = frameOn(this.#camera, { x: frame.extent.x, y: frame.extent.y, w: frame.extent.width, h: frame.extent.height });']);

        $this->assertNotSame([], $this->fitDefects($this->floorRun(self::OVERFLOW, $dir)), 'the fourth RED (a fit that cuts the strip) did not bite');
    }

    /** A zoom that does not hold the cursor's point — the Ctrl+wheel clause's own control. */
    public function test_red_a_zoom_about_the_corner_not_the_cursor(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'x: at.x - to.x / zoom, y: at.y - to.y / zoom, fitted: false',
            'x: camera.x, y: camera.y, fitted: false']);

        $this->assertNotSame([], $this->ctrlWheelDefects($this->floorRun(self::FLOOR, $dir)), 'CONTROL (a zoom about the corner) did not bite');
    }

    /**
     * The plain wheel's defects (card#11045), each planted in the camera and watched red on the pan clause:
     * the wheel that still zooms — the behaviour the operator's ruling replaced — a pan the wrong way, a
     * pan that reads every delta as pixels, a wheel whose `deltaX` is dropped, and the edge (Q3): a wheel
     * there kept from the page, as before Q3 was ruled, and an edge read on one axis or the wrong way.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function panPlants(): array
    {
        return [
            'a plain wheel that zooms' => ['return { camera: panBy(camera, -deltaX * ux, -deltaY * uy), consumed };',
                'return zoom(camera, { x: 0, y: 0 }, { deltaY, deltaMode });'],
            'a pan the wrong way' => ['panBy(camera, -deltaX * ux, -deltaY * uy)', 'panBy(camera, deltaX * ux, deltaY * uy)'],
            'a pan that ignores deltaMode' => ["const ux = deltaMode === 2 ? camera.surface.width : deltaMode === 1 ? LINE_PX : 1;\n    const uy = deltaMode === 2 ? camera.surface.height : deltaMode === 1 ? LINE_PX : 1;",
                "const ux = 1;\n    const uy = 1;"],
            'a pan with no deltaX' => ['panBy(camera, -deltaX * ux, -deltaY * uy)', 'panBy(camera, 0, -deltaY * uy)'],
            // Q3 (card#11045, the operator's ruling of 2026-10-01): the edge releases the wheel to the page.
            'a wheel at the edge kept from the page — the behaviour before Q3 was ruled' => ['const consumed = room(deltaX, camera.x, xs) || room(deltaY, camera.y, ys);', 'const consumed = true;'],
            'an edge read on the vertical axis alone' => ['const consumed = room(deltaX, camera.x, xs) || room(deltaY, camera.y, ys);', 'const consumed = room(deltaY, camera.y, ys);'],
            'an edge read the wrong way' => ['(delta > 0 && pos < high) || (delta < 0 && pos > low)', '(delta > 0 && pos > low) || (delta < 0 && pos < high)'],
        ];
    }

    #[DataProvider('panPlants')]
    public function test_red_each_plain_wheel_defect(string $anchor, string $replacement): void
    {
        $dir = $this->mutatedModules([self::CAMERA, $anchor, $replacement]);

        $this->assertNotSame([], $this->panDefects($this->floorRun(self::FLOOR, $dir)), 'the planted plain-wheel defect did not bite');
    }

    /** A touch pinch that ignores its factor, and one that holds the midpoint still while the fingers move. */
    public function test_red_each_touch_pinch_defect(): void
    {
        $factor = $this->mutatedModules([self::CAMERA, 'Math.max(low, camera.zoom * factor)', 'Math.max(low, camera.zoom)']);
        $this->assertNotSame([], $this->pinchDefects($this->floorRun(self::FLOOR, $factor)), 'CONTROL (a pinch that ignores its factor) did not bite');

        $still = $this->mutatedModules([self::CAMERA, 'x: at.x - to.x / zoom, y: at.y - to.y / zoom', 'x: at.x - from.x / zoom, y: at.y - from.y / zoom']);
        $this->assertNotSame([], $this->pinchDefects($this->floorRun(self::FLOOR, $still)), 'CONTROL (a pinch whose midpoint never moves the view) did not bite');
    }

    /** Round-1 F1's defect: a notch per wheel event, whatever its scroll — the scale clause's control. */
    public function test_red_a_wheel_that_steps_a_notch_per_event(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'return { camera: zoomAt(camera, point, ZOOM_STEP ** (-scroll / NOTCH_PX)), consumed: true };',
            'return { camera: zoomAt(camera, point, scroll < 0 ? ZOOM_STEP : 1 / ZOOM_STEP), consumed: true };']);

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

    /** Round-2 M1's defect: a pinch zoomed at a scroll's rate, its small deltas taking many pinches to go anywhere. */
    public function test_red_a_pinch_at_a_scrolls_rate(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'deltaY * unit * PINCH_GAIN',
            'deltaY * unit']);

        $this->assertNotSame([], $this->wheelScaleDefects($this->floorRun(self::PROPORTIONAL, $dir)), 'CONTROL (a pinch at a scroll\'s rate) did not bite');
    }

    /** A wheel event with no per-event bound. */
    public function test_red_a_wheel_event_that_leaps_past_a_notch(): void
    {
        $dir = $this->mutatedModules([self::CAMERA,
            'const scroll = Math.min(NOTCH_PX, Math.max(-NOTCH_PX, deltaY * unit * PINCH_GAIN));',
            'const scroll = deltaY * unit * PINCH_GAIN;']);

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
            '        if (scene !== null && scene.extent !== null) {',
            '        if (scene !== null) {']);

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
        $drifted = preg_replace('/(the facts\' text at \*\*)[\d.]+( CSS px\*\*)/', '${1}9.9${2}', $md, 1);

        $this->assertNotSame($md, $drifted, 'the plant found no measurement in § 12 to move');
        $this->assertNotSame([], $this->measurementDefects($this->floorRun(self::PAGE), (string) $drifted), 'CONTROL (a drifted measurement) did not bite');
    }

    /** The second type role's figure (card#11058 Q2): the nameplate's measurement, and its size, each drifted. */
    public function test_red_a_viewport_row_that_states_another_nameplate_measurement(): void
    {
        $md = $this->floorMd();
        $result = $this->floorRun(self::PAGE);

        foreach ([
            'the nameplate drawn at another CSS size' => ['/(name role at \*\*)[\d.]+( CSS px\*\*)/', '${1}9.9${2}'],
            'the name role at another font size' => ['/(the nameplate\'s )\d+( px name role at)/', '${1}12${2}'],
        ] as $what => [$pattern, $replacement]) {
            $drifted = preg_replace($pattern, $replacement, $md, 1);

            $this->assertNotSame($md, $drifted, "the plant ({$what}) found nothing in § 12 to move");
            $this->assertNotSame([], $this->measurementDefects($result, (string) $drifted), "CONTROL ({$what}) did not bite");
        }
    }

    /** A page surface the camera's run is not entered at: § 12's figure moved and the fixture not. */
    public function test_red_a_page_surface_the_run_is_not_entered_at(): void
    {
        $md = $this->floorMd();
        $drifted = preg_replace('/(on the page at this viewport the drawing is \*\*[\d,]+ × )([\d,]+)( CSS px\*\*)/', '${1}640${3}', $md, 1);

        $this->assertNotSame($md, $drifted, 'the plant found no page surface in § 12 to move');
        $this->assertNotSame([], $this->pageSurfaceDefects((string) $drifted), 'CONTROL (a page surface the run is not entered at) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /**
     * `camera_page` is entered at § 12's page surface, and that surface is as wide as F and lower.
     *
     * @return list<string>
     */
    private function pageSurfaceDefects(string $md): array
    {
        [$w, $h] = $this->referenceViewport();
        [$pw, $ph] = $this->pageSurface($md);
        $defects = [];

        if ($this->fixture(self::PAGE)['floor']['viewport'] !== ['width' => $pw, 'height' => $ph]) {
            $defects[] = "[camera_page] is not entered at the page surface § 12 states ({$pw} × {$ph})";
        }

        if (! ($pw === $w && $ph < $h)) {
            $defects[] = "§ 12's page surface {$pw} × {$ph} is not as wide as F and lower than it";
        }

        return $defects;
    }

    /**
     * § 12's page surface — the drawing box the floor page gives the camera at F — read from the row.
     *
     * @return array{0: int, 1: int}
     */
    private function pageSurface(?string $md = null): array
    {
        $this->assertSame(1, preg_match('/^\| Floor reference viewport \|[^\n]*?on the page at this viewport the drawing is \*\*([\d,]+) × ([\d,]+) CSS px\*\*/m', $md ?? $this->floorMd(), $m),
            '§ 12\'s viewport row states no page surface in the form this test reads');

        return [(int) str_replace(',', '', $m[1]), (int) str_replace(',', '', $m[2])];
    }

    /** @return array{0: int, 1: int} § 12's reference viewport, read from the row on every run */
    private function referenceViewport(): array
    {
        $this->assertSame(1, preg_match('/^\| Floor reference viewport \| \*\*([\d,]+) × ([\d,]+) CSS px\*\* \|/m', $this->floorMd(), $m),
            '§ 12\'s reference-viewport row did not parse');

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
    private function ctrlWheelDefects(array $result): array
    {
        $wheels = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'ctrl_wheel'));
        $defects = $wheels === [] ? ['the run turned the Ctrl+wheel nowhere'] : [];

        foreach ($wheels as $a) {
            $p = ['x' => $a['act']['x'], 'y' => $a['act']['y']];
            $before = $this->toScene($a['before'], $p);
            $after = $this->toScene($a['after'], $p);

            if (! ($a['after']['zoom'] > $a['before']['zoom'])) {
                $defects[] = "the Ctrl+wheel at {$a['at']} ms did not zoom in";
            }

            if (abs($before['x'] - $after['x']) > self::EPSILON || abs($before['y'] - $after['y']) > self::EPSILON) {
                $defects[] = sprintf('the Ctrl+wheel at %d ms moved the scene point under the cursor from (%.3f, %.3f) to (%.3f, %.3f)',
                    $a['at'], $before['x'], $before['y'], $after['x'], $after['y']);
            }

            if (($a['consumed'] ?? null) !== true) {
                $defects[] = "the Ctrl+wheel at {$a['at']} ms was not consumed — the browser's page zoom would take it too";
            }
        }

        return $defects;
    }

    /**
     * Every plain wheel (`scroll`) of the run: a pan by its scroll over the zoom, the zoom unchanged — every
     * one inside the clamp exactly, and each far one (a scroll of 10,000 px or more) clamped with the floor
     * in view — and consumed exactly when it moved the view: a wheel the view could follow is taken from the
     * page, and one at an edge the view can pass no further is released to it and moves nothing (Q3). The
     * run must scroll in all three modes, on both axes, and be released at an edge on each axis, and take a
     * diagonal wheel at an edge on one axis that the other axis can still follow.
     *
     * @return list<string>
     */
    private function panDefects(array $result, bool $edge = true): array
    {
        $line = $this->wheelConstants()['line'];
        $scrolls = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'scroll'));
        $defects = $scrolls === [] ? ['the run turned the plain wheel nowhere'] : [];
        $modes = [];
        $axes = [];
        $far = 0;
        $released = [];
        $diagonal = false;

        foreach ($scrolls as $a) {
            $mode = $a['act']['delta_mode'] ?? 0;
            $s = $a['before']['surface'];
            [$ux, $uy] = match ($mode) {
                2 => [$s['width'], $s['height']],
                1 => [$line, $line],
                default => [1, 1],
            };
            $dx = ($a['act']['delta_x'] ?? 0) * $ux;
            $dy = ($a['act']['delta_y'] ?? 0) * $uy;
            $z = $a['before']['zoom'];
            $modes[$mode] = true;
            $axes['x'] = ($axes['x'] ?? false) || $dx !== 0 && $dx !== 0.0;
            $axes['y'] = ($axes['y'] ?? false) || $dy !== 0 && $dy !== 0.0;

            $movedX = abs($a['after']['x'] - $a['before']['x']) > self::EPSILON;
            $movedY = abs($a['after']['y'] - $a['before']['y']) > self::EPSILON;

            if (($a['consumed'] ?? null) !== ($movedX || $movedY)) {
                $defects[] = ($movedX || $movedY)
                    ? "the wheel at {$a['at']} ms moved the view and was not consumed — the page would scroll as the floor pans"
                    : "the wheel at {$a['at']} ms could move the view no further its way and was consumed — the page's scroll stops at the drawing's edge";
            }

            if (($a['consumed'] ?? null) === false) {
                $released[$dx !== 0 && $dx !== 0.0 ? ($dy !== 0 && $dy !== 0.0 ? 'xy' : 'x') : 'y'] = true;
            } elseif (($dx !== 0 && $dx !== 0.0) && ($dy !== 0 && $dy !== 0.0) && ! $movedX && $movedY) {
                $diagonal = true;
            }

            if (abs($a['after']['zoom'] - $z) > self::EPSILON) {
                $defects[] = sprintf('the wheel at %d ms zoomed %.6f → %.6f — a plain wheel pans', $a['at'], $z, $a['after']['zoom']);
            }

            if (max(abs($dx), abs($dy)) >= 10000 || ($a['consumed'] ?? null) === false) {
                $far++;

                if (! $this->inView($a['after'])) {
                    $defects[] = "after the wheel at {$a['at']} ms the floor is not in view";
                }

                continue;
            }

            // An axis the wheel pushes against its edge stays put (the diagonal case); every other moves exactly.
            $heldX = ! $movedX && $dx != 0 && $movedY;
            $heldY = ! $movedY && $dy != 0 && $movedX;

            if ((! $heldX && abs($a['after']['x'] - ($a['before']['x'] + $dx / $z)) > self::EPSILON)
                || (! $heldY && abs($a['after']['y'] - ($a['before']['y'] + $dy / $z)) > self::EPSILON)) {
                $defects[] = sprintf('the wheel at %d ms (delta %s, %s, mode %d) moved the view from (%.3f, %.3f) to (%.3f, %.3f); its scroll makes it (%.3f, %.3f)',
                    $a['at'], $a['act']['delta_x'] ?? 0, $a['act']['delta_y'] ?? 0, $mode, $a['before']['x'], $a['before']['y'],
                    $a['after']['x'], $a['after']['y'], $a['before']['x'] + $dx / $z, $a['before']['y'] + $dy / $z);
            }
        }

        if ($edge) {
            foreach ([0 => 'pixel', 1 => 'line', 2 => 'page'] as $mode => $name) {
                if (! isset($modes[$mode])) {
                    $defects[] = "the run turns the plain wheel in no {$name}-mode event";
                }
            }

            foreach (['x', 'y'] as $axis) {
                if (! ($axes[$axis] ?? false)) {
                    $defects[] = "the run turns the plain wheel on no {$axis} delta";
                }
            }

            foreach (['x' => 'horizontal', 'y' => 'vertical'] as $axis => $name) {
                if (! isset($released[$axis])) {
                    $defects[] = "the run releases no {$name} wheel at the floor's edge, so the edge (Q3) was never asked on that axis";
                }
            }

            if (! $diagonal) {
                $defects[] = 'the run takes no diagonal wheel at one axis\'s edge that the other axis still follows';
            }

            if ($far < 2) {
                $defects[] = 'the run does not scroll past the edge, so the clamp was never asked';
            }
        }

        return $defects;
    }

    /**
     * Every touch pinch step: the zoom by its factor, and the scene point that was under the fingers'
     * midpoint `from` under `to` after it. The run must pinch out and in.
     *
     * @return list<string>
     */
    private function pinchDefects(array $result, bool $both = true): array
    {
        $pinches = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'pinch'));
        $defects = $pinches === [] ? ['the run pinches nowhere'] : [];
        $signs = [];

        foreach ($pinches as $a) {
            $f = $a['act']['factor'];
            $signs[$f <=> 1] = true;
            $before = $this->toScene($a['before'], $a['act']['from']);
            $after = $this->toScene($a['after'], $a['act']['to']);

            if (abs($a['after']['zoom'] - $a['before']['zoom'] * $f) > self::EPSILON) {
                $defects[] = sprintf('the pinch at %d ms (×%s) zoomed %.6f → %.6f', $a['at'], $f, $a['before']['zoom'], $a['after']['zoom']);
            }

            if (abs($before['x'] - $after['x']) > self::EPSILON || abs($before['y'] - $after['y']) > self::EPSILON) {
                $defects[] = sprintf('the pinch at %d ms moved the scene point under its midpoint from (%.3f, %.3f) to (%.3f, %.3f)',
                    $a['at'], $before['x'], $before['y'], $after['x'], $after['y']);
            }
        }

        if ($both && ! isset($signs[1], $signs[-1])) {
            $defects[] = 'the run pinches not both out and in';
        }

        return $defects;
    }

    /**
     * The camera module's own constants, read from the shipped file — the zoom step, the notch's
     * scroll, a line's height and a pinch's gain — so no figure here is this test's.
     *
     * @return array{step: float, notch: float, line: float, pinch: float}
     */
    private function wheelConstants(): array
    {
        $src = (string) file_get_contents($this->jsRoot().'/wire/camera.js');
        $read = function (string $name) use ($src): float {
            $this->assertSame(1, preg_match("/export const {$name} = ([\\d.]+);/", $src, $m), "camera.js's {$name} did not parse");

            return (float) $m[1];
        };

        return ['step' => $read('ZOOM_STEP'), 'notch' => $read('NOTCH_PX'), 'line' => $read('LINE_PX'), 'pinch' => $read('PINCH_GAIN')];
    }

    /** @return list<string> */
    private function wheelScaleDefects(array $result): array
    {
        $c = $this->wheelConstants();
        $wheels = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'ctrl_wheel'));
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
            $px = $a['act']['delta_y'] * $unit * $c['pinch'];
            $scroll = max(-$c['notch'], min($c['notch'], $px));
            $expected = $a['before']['zoom'] * $c['step'] ** (-$scroll / $c['notch']);
            $modes[$mode] = true;
            $bounded = $bounded || abs($px) > $c['notch'];

            if (abs($a['after']['zoom'] - $expected) > self::EPSILON) {
                $defects[] = sprintf('the Ctrl+wheel at %d ms (delta %s, mode %d) zoomed %.6f → %.6f; its scroll makes it %.6f',
                    $a['at'], $a['act']['delta_y'], $mode, $a['before']['zoom'], $a['after']['zoom'], $expected);
            }

            // The trackpad pinch's burst: the small pixel-mode deltas, whose sum is the gesture's scroll.
            if ($mode === 0 && abs($px) < $c['notch'] / 10) {
                $burst['n']++;
                $burst['px'] += $px;
                $burst['factor'] *= $a['after']['zoom'] / $a['before']['zoom'];
            }
        }

        if ($burst['n'] < 20) {
            $defects[] = "the run's trackpad pinch burst is {$burst['n']} events — too few to tell a step per event from a scroll";
        } elseif (abs($burst['factor'] - $c['step'] ** (-$burst['px'] / $c['notch'])) > self::EPSILON) {
            $defects[] = sprintf('a trackpad pinch burst of %d events scrolling %.0f px in all zoomed ×%.4f; its scroll is ×%.4f',
                $burst['n'], $burst['px'], $burst['factor'], $c['step'] ** (-$burst['px'] / $c['notch']));
        }

        foreach ([0 => 'pixel', 1 => 'line', 2 => 'page'] as $mode => $name) {
            if (! isset($modes[$mode])) {
                $defects[] = "the run turns the Ctrl+wheel in no {$name}-mode event";
            }
        }

        if (! $bounded) {
            $defects[] = 'the run turns the Ctrl+wheel in no event past a notch, so the per-event bound was never asked to bite';
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

            // A wheel over a camera that frames nothing is the page's: the act consumes nothing.
            if (array_key_exists('consumed', $a) && $a['consumed'] !== false) {
                $defects[] = "the {$a['act']['act']} at {$a['at']} ms consumed a wheel with nothing framed";
            }
        }

        foreach (['scroll', 'ctrl_wheel', 'pinch'] as $act) {
            if (! in_array($act, array_map(static fn (array $a): string => $a['act']['act'], $result['camera_acts']), true)) {
                $defects[] = "the run has no {$act} on the camera with nothing framed";
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

    /**
     * § 4.5's any-size rule, over the any-size runs, the degraded runs in the same windows and the
     * resize run.
     *
     * @return list<string>
     */
    private function anySizeDefects(?string $dir = null): array
    {
        $defects = [];

        foreach (self::ENTRY_SMALL as $run) {
            $result = $this->floorRun($run, $dir);
            $framed = $this->framed($result);

            if ($framed === []) {
                $defects[] = "[{$run}] no frame drew the room — the route substituted something for it in a window this size";

                continue;
            }

            // Once a render has drawn the room, every later render draws it too (renders in order; several
            // can share one scenario millisecond, so the order is the index and never the time).
            $drawing = false;

            foreach ($result['floor_renders'] as $r) {
                $drawn = $r['frame']['scene'] !== null && $r['frame']['camera']['bounds'] !== null;

                if ($drawing && ! $drawn) {
                    $defects[] = "[{$run}] the render at {$r['at']} ms stopped drawing the room";
                }

                $drawing = $drawing || $drawn;
            }

            $checks = [...$this->firstFramingDefects($result), ...$this->ctrlWheelDefects($result), ...$this->panDefects($result, false),
                ...$this->pinchDefects($result, false), ...$this->dragDefects($result)];
            $fits = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'fit'));

            if ($fits === []) {
                $checks[] = 'the run fits nothing, so fit-floor was never asked';
            } else {
                $checks = [...$checks, ...$this->atFitDefects($fits[0]['after'], $framed[count($framed) - 1]['scene']['extent'], 'fit-floor')];
            }

            foreach ($checks as $d) {
                $defects[] = "[{$run}] {$d}";
            }
        }

        foreach (self::DEGRADED_SMALL as $run) {
            $seats = count($this->snapshotSeats($run));
            $last = $this->lastFloor($this->floorRun($run, $dir));

            if ($last['scene'] === null) {
                $defects[] = "[{$run}] the last render draws no room";

                continue;
            }

            if (count($last['scene']['desks']) !== $seats) {
                $defects[] = sprintf('[%s] the room draws %d desks for %d seats', $run, count($last['scene']['desks']), $seats);
            }

            if (count($last['desks']['desks']) !== $seats) {
                $defects[] = sprintf('[%s] the frame carries %d desk models for the list view for %d seats', $run, count($last['desks']['desks']), $seats);
            }

            array_push($defects, ...array_map(static fn (string $d): string => "[{$run}] {$d}",
                $this->atFitDefects($last['camera'], $last['scene']['extent'], 'the room')));
        }

        $result = $this->floorRun(self::RESIZE, $dir);
        $resizes = array_values(array_filter($result['camera_acts'], static fn (array $a): bool => $a['act']['act'] === 'resize'));

        if ($resizes === []) {
            $defects[] = '[camera_resize] the run resizes nothing';
        }

        foreach ($resizes as $a) {
            $frame = null;

            foreach ($result['floor_renders'] as $r) {
                $frame = $r['at'] === $a['at'] ? $r['frame'] : $frame;
            }

            $where = sprintf('[camera_resize] at %d ms (%d × %d)', $a['at'], $a['act']['width'], $a['act']['height']);

            if ($frame === null) {
                $defects[] = "{$where}: no render followed the resize";

                continue;
            }

            if ($frame['scene'] === null || $frame['camera']['bounds'] === null) {
                $defects[] = "{$where}: the route stopped drawing the room";

                continue;
            }

            $camera = $frame['camera'];

            if (! $this->inView($camera)) {
                $defects[] = "{$where}: the floor is not in view";
            }

            // A camera the viewer moved keeps its zoom across a resize, raised only to the new fit.
            $kept = max($a['before']['zoom'], $this->fitZoom($camera['surface'], $camera['bounds']));

            if ($a['before']['fitted'] !== true && abs($camera['zoom'] - $kept) > self::EPSILON) {
                $defects[] = sprintf('%s: the viewer\'s zoom %.4f became %.4f across the resize', $where, $a['before']['zoom'], $camera['zoom']);
            }
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
        $this->assertSame(1, preg_match("/export const FONT_NAME = 'bold (\\d+)px /", $layout, $n), 'desk-layout.js\'s FONT_NAME did not parse');

        $zoom = $first['camera']['zoom'];
        $px = (int) $f[1] * $zoom;
        $namePx = (int) $n[1] * $zoom;

        if (preg_match('/^\| Floor reference viewport \|[^\n]*?the camera\'s fit at this viewport is \*\*([\d.]+)\*\*[^\n]*?draws the scene\'s (\d+) px text — the facts\' text at \*\*([\d.]+) CSS px\*\*[^\n]*?the nameplate\'s (\d+) px name role at \*\*([\d.]+) CSS px\*\*/m', $md, $m) !== 1) {
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

        if ((int) $m[4] !== (int) $n[1]) {
            $defects[] = "§ 12 states the nameplate's name role at {$m[4]} px and desk-layout.js draws it at {$n[1]} px";
        }

        if ($m[5] !== sprintf('%.1f', $namePx)) {
            $defects[] = sprintf('§ 12 states the nameplate drawn at %s CSS px and the camera draws it at %.1f', $m[5], $namePx);
        }

        return $defects;
    }

    // ── Geometry ───────────────────────────────────────────────────────────────────────────────

    /** @return array{x: float, y: float} */
    private function toScene(array $camera, array $point): array
    {
        return ['x' => $camera['x'] + $point['x'] / $camera['zoom'], 'y' => $camera['y'] + $point['y'] / $camera['zoom']];
    }

    /** The zoom at which `$bounds` just fits `$surface` — `wire/camera.js`'s own fit, recomputed. */
    private function fitZoom(array $surface, array $bounds): float
    {
        return min($surface['width'] / $bounds['w'], $surface['height'] / $bounds['h']);
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

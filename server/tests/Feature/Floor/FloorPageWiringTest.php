<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The floor page's own gate** — `docs/design/FLOOR.md` Appendix B row 8: "every element the DOM
 * entry addresses exists on the Blade view and every element the view declares is written into, the
 * view serves the entry as a module whose every import resolves, and a control plants each defect
 * the check exists to catch and watches it red". Shaped like `Tests\Feature\Lobby\LobbyPageWiringTest`.
 * card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT READS THE FLOOR PAGE ALONE — the served view and `public/js/floor/main.js` — and names no
 * fixture and not the harness, as the row says. Every fact the page draws is asserted headlessly
 * elsewhere; what this holds is the contract between the page's two halves, which no browser on the
 * build host can witness.
 *
 * ⛔ AND THE TWO THINGS ROW 8 SAYS THE PAGE MUST CARRY THAT NOTHING ELSE CAN CHECK: that the client
 * protocol is constructed WITH its scheduler — a real `EventSource` without the stream recovery
 * inherits the browser's own reconnect (row 8's ⛔) — and that the animation log is `wire/live-page.js`'s,
 * constructed with § 12's retention figure (§ 14 item 26), re-derived from § 12 rather than copied here.
 *
 * ⛔ WIDENED TO THE PAINTER's ELEMENTS (Appendix B row 14, card#7341 step 11): the page's drawing is
 * `public/js/floor/painter.js`'s, which addresses the view's drawing element itself, so the ids it
 * addresses join the entry's in the both-directions check, and the entry must construct the painter
 * from that module — a painter nobody constructs addresses nothing and would pass vacuously.
 *
 * ⛔ AND TO THE CAMERA's (Appendix B row 15): the entry hands the screen the drawing surface's size and
 * wires the wheel (a plain wheel's pan, a Ctrl+wheel's zoom), the touch pinch and the drag through
 * `wire/camera-gestures.js` (the lobby's too since card#7343 r1; the pan and the pinch card#11045's)
 * and the keyboard and the zoom buttons through `wire/camera-keys.js` (the lobby's too since card#7343
 * r2-2) — the rules of both — `deltaMode` and `ctrlKey`, the primary button only, the end on a
 * `pointercancel` or a buttonless move, which key zooms and which way an arrow pans — are held and
 * planted by `TheCameraWireIsOneForBothPagesTest`, and here only that the entry hands those modules its
 * drawing, its zoom buttons and the screen's acts, with no copy of its own — and the fit-floor control
 * and a resize (which
 * re-shows the camera at once, stopping a glide) to the screen's camera acts — a camera no event
 * reaches is a floor that never moves and would pass AT-D3-21, which drives the acts directly. And the
 * drawing is reachable: it takes focus, and neither it nor the painter's `<svg>` is an image, whose
 * children — the desks — would be presentational; a desk activates on Enter and Space, and the desk the
 * keyboard was on keeps focus across the painter's rebuild of the `<svg>` — restored only when the
 * keyboard was inside the drawing.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that anything renders, lays out or is legible.
 */
class FloorPageWiringTest extends TestCase
{
    use DrivesAShippedClientModule;
    use RefreshDatabase;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor')
            ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/fleet-client-probe.mjs';
    }

    public function test_every_element_the_entry_addresses_exists_on_the_page_and_the_reverse(): void
    {
        $this->assertSame([], $this->wiringDefects($this->floorPage(), $this->mainJs()));
    }

    /** Appendix B row 14: the entry constructs the painter from `floor/painter.js`, whose ids are checked above. */
    public function test_the_entry_paints_the_room_with_the_painter_module(): void
    {
        $this->assertSame([], $this->painterDefects($this->mainJs()));
    }

    /** Appendix B row 15: the surface's size reaches the screen and every viewer act reaches the camera. */
    public function test_the_entry_wires_the_surface_and_the_viewers_acts_to_the_screens_camera(): void
    {
        $this->assertSame([], $this->cameraDefects($this->mainJs()));
    }

    /** Appendix B row 15: the drawing takes the keyboard's focus and exposes the desks inside it. */
    public function test_the_drawing_is_focusable_and_never_an_image_so_its_desks_are_exposed(): void
    {
        $this->assertSame([], $this->exposureDefects($this->floorPage(), $this->painterJs()));
    }

    /**
     * card#11045 PR-A (the operator's ruling; design review r3 MINOR-6): the sections below the room
     * are `<details>`, closed by default, each holding its list root with the root's heading in its
     * `<summary>`; the drill-down panel and the drawing are inside none of them.
     */
    public function test_the_sections_below_the_room_are_closed_details_and_the_panel_is_outside_them(): void
    {
        $this->assertSame([], $this->sectionDefects($this->floorPage()));
    }

    /**
     * card#11045 PR-A: the header's building counts are the lobby's `fleetTotals()` — `fleet.seats_total`
     * and `fleet.seats_live` read from the wire, never recounted (§ 4.1 row 3, AT-D3-15) — carried on the
     * status strip the page already paints, and painted into `#floor-fleet-counts`.
     */
    public function test_the_header_counts_are_the_lobbys_totals_never_recounted(): void
    {
        $this->assertSame([], $this->countDefects($this->mainJs()));
    }

    public function test_the_page_serves_the_entry_as_a_module_and_every_import_resolves(): void
    {
        $html = $this->floorPage();

        $this->assertStringContainsString('type="module"', $html);
        $this->assertStringContainsString('/js/floor/main.js', $html, 'the page does not load the floor entry');
        $this->assertStringContainsString('data-floor="aimla"', $html, 'the route did not hand the page its floor segment');
        $this->assertGreaterThan(0, $this->assertEveryRelativeImportResolves($this->moduleDir()),
            'no relative import was found — the check measured nothing');
    }

    /** § 4.4: the route is inside the `auth` + `mfa` group, as the lobby is. */
    public function test_the_route_is_behind_login_and_the_second_factor(): void
    {
        $this->get('/floor/aimla')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->twoFactorUnenrolled()->create())
            ->get('/floor/aimla')
            ->assertRedirect(route('two-factor.enroll'));
    }

    public function test_the_entry_constructs_the_protocol_with_its_recovery_and_the_log_with_section_12s_bound(): void
    {
        $this->assertSame([], $this->bindingDefects($this->mainJs()));
    }

    /** ⛔ THE CONTROLS — each re-mints one defect this file exists to catch. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $html = $this->floorPage();
        $js = $this->mainJs();

        $renamed = str_replace('id="floor-feed"', 'id="floor-feeed"', $html);
        $this->assertNotSame($renamed, $html);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($renamed, $js),
            'CONTROL (renamed element) did not bite');

        $orphaned = str_replace('<p id="floor-kept" hidden></p>', '<p id="floor-kept" hidden></p><p id="floor-orphan"></p>', $html);
        $this->assertNotSame($orphaned, $html);
        $this->assertArrayHasKey('unwritten', $this->wiringDefects($orphaned, $js),
            'CONTROL (an element nothing writes into) did not bite');

        $widened = $this->mutatedModules(['../lobby/lobby-model.js',
            "    return [\n        {\n            key: 'store',",
            "    return [\n        { key: 'purge', label: 'purge', member: 'fleet.purge', value: 'ok', detail: null },\n        {\n            key: 'store',"]);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($html, $js, $widened),
            'CONTROL (a fifth indicator with no element) did not bite — the computed ids are not derived from the model');

        // The construction moved to `wire/live-page.js` at the lobby's step (card#7341 step 9), so the
        // scheduler plant is planted THERE, and a second plant is the page walking around it.
        $livePage = (string) file_get_contents($this->jsRoot().'/wire/live-page.js');
        $unscheduled = str_replace('new FleetClient(pageFetch, PageEventSource, clock, timers)', 'new FleetClient(pageFetch, PageEventSource, clock)', $livePage);
        $this->assertNotSame($unscheduled, $livePage);
        $this->assertArrayHasKey('recovery', $this->livePageDefects($js, $unscheduled),
            'CONTROL (the protocol constructed without its scheduler) did not bite');

        $bypassed = str_replace('livePage(() => screen.render())', 'new FleetClient(fetch, EventSource, { now: Date.now }) && livePage(() => screen.render())', $js);
        $this->assertNotSame($bypassed, $js);
        $this->assertArrayHasKey('recovery', $this->bindingDefects($bypassed),
            'CONTROL (the page constructing a protocol of its own beside wire/live-page.js) did not bite');

        // The log's construction moved to `wire/live-page.js` when the lobby became its second page
        // (card#7343), so the bound's plants are planted THERE, and a third is the page walking around it.
        $unbounded = str_replace('log: createAnimationLog(ANIMATION_LOG_RETENTION)', 'log: createAnimationLog()', $livePage);
        $this->assertNotSame($unbounded, $livePage);
        $this->assertArrayHasKey('retention', $this->pageLogDefects($js, $unbounded),
            'CONTROL (the log constructed with no bound) did not bite');

        $ownLog = str_replace('startFloorScreen(client, pageFetch, clock, log, paint, {', 'startFloorScreen(client, pageFetch, clock, createAnimationLog(), paint, {', $js);
        $this->assertNotSame($ownLog, $js);
        $this->assertArrayHasKey('retention', $this->bindingDefects($ownLog),
            'CONTROL (the page constructing an unbounded log of its own beside wire/live-page.js) did not bite');

        $misaddressed = str_replace("getElementById('floor-drawing')", "getElementById('floor-drawinq')", $this->painterJs());
        $this->assertNotSame($misaddressed, $this->painterJs());
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($html, $js, null, $misaddressed),
            'CONTROL (the painter addressing an element the page does not declare) did not bite');

        $undrawn = preg_replace('/<div id="floor-drawing"[^>]*><\/div>/', '', $html);
        $this->assertNotSame($undrawn, $html);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($undrawn, $js),
            'CONTROL (the view without the painter\'s drawing element) did not bite');

        $unpainted = str_replace("import { createPainter, loadArt, measurer, themeInputs } from './painter.js';", '', $js);
        $this->assertNotSame($unpainted, $js);
        $this->assertArrayHasKey('painter', $this->painterDefects($unpainted),
            'CONTROL (an entry that never constructs the painter) did not bite');

        // card#11045: the plain wheel's pan, the Ctrl+wheel's zoom and the touch pinch each reach the screen.
        foreach (['pan' => 'wheel', 'zoom' => 'wheel', 'pinch' => 'pinch'] as $act => $key) {
            $unwired = str_replace("{$act}: screen.{$act},", "{$act}: screen.camera,", $js);
            $this->assertNotSame($unwired, $js);
            $this->assertArrayHasKey($key, $this->cameraDefects($unwired),
                "CONTROL (a {$act} that never reaches the camera) did not bite");
        }

        $undragged = str_replace('drag: screen.drag, camera: screen.camera }', 'drag: screen.camera, camera: screen.camera }', $js);
        $this->assertNotSame($undragged, $js);
        $this->assertArrayHasKey('drag', $this->cameraDefects($undragged),
            'CONTROL (a drag that never reaches the camera) did not bite');

        // card#7343 r3b: the gestures ask the screen's camera whether it frames the floor; one that never
        // does leaves every wheel over the drawn floor to the page's scroll.
        $unframed = str_replace('cameraGestures(drawing, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);',
            'cameraGestures(drawing, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: () => ({ bounds: null }) }, show);', $js);
        $this->assertNotSame($unframed, $js);
        $this->assertArrayHasKey('wheel', $this->cameraDefects($unframed),
            'CONTROL (a wheel handed a camera that frames nothing) did not bite');

        // card#7343 r4b: the keys ask the same camera, so one that never frames leaves every key over the
        // drawn floor to the browser; and the keys and the zoom buttons are offered from the frame's camera.
        $keysUnframed = str_replace('{ zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }', '{ zoomStep: screen.zoomStep, drag: screen.drag, camera: () => ({ bounds: null }) }', $js);
        $this->assertNotSame($keysUnframed, $js);
        $this->assertArrayHasKey('keyboard', $this->cameraDefects($keysUnframed),
            'CONTROL (keys handed a camera that frames nothing) did not bite');

        $unoffered = str_replace("    offerKeys(el('floor-drawing'), zoomButtons, frame.camera);\n", '', $js);
        $this->assertNotSame($unoffered, $js);
        $this->assertArrayHasKey('buttons', $this->cameraDefects($unoffered),
            'CONTROL (keys and zoom buttons never offered) did not bite');

        // The gestures are wire/camera-gestures.js's, whose own defects TheCameraWireIsOneForBothPagesTest
        // plants and watches red; what can drift here is a page growing its own copy back beside it.
        $copied = str_replace('cameraKeys(drawing, ', "drawing.addEventListener('pointermove', () => {});\ncameraKeys(drawing, ", $js);
        $this->assertNotSame($copied, $js);
        $this->assertArrayHasKey('gestures', $this->cameraDefects($copied),
            'CONTROL (the page wiring a pointer gesture of its own beside the shared module) did not bite');

        $stale = str_replace("    screen.resize(surface());\n    show(screen.camera());\n", "    screen.resize(surface());\n", $js);
        $this->assertNotSame($stale, $js);
        $this->assertArrayHasKey('resize', $this->cameraDefects($stale),
            'CONTROL (a resize that leaves a glide running over the old surface) did not bite');

        // The keys and the zoom buttons are wire/camera-keys.js's since card#7343 r2-2, whose own defects —
        // the ones this file planted in the inline copy — TheCameraWireIsOneForBothPagesTest plants and
        // watches red; what can drift here is the entry's hand-off, and a copy growing back beside it.
        $keyless = str_replace('cameraKeys(drawing, ', "cameraKeys(el('floor-desks'), ", $js);
        $this->assertNotSame($keyless, $js);
        $this->assertArrayHasKey('keyboard', $this->cameraDefects($keyless),
            'CONTROL (a keyboard handed an element that is not the drawing) did not bite');

        $buttonless = str_replace("zoomIn: el('floor-zoom-in')", "zoomIn: el('floor-fit')", $js);
        $this->assertNotSame($buttonless, $js);
        $this->assertArrayHasKey('buttons', $this->cameraDefects($buttonless),
            'CONTROL (a zoom button that never reaches the camera) did not bite');

        foreach ([
            'a keydown of its own' => "drawing.addEventListener('keydown', () => {});\n",
            'a zoom button of its own' => "el('floor-zoom-in').addEventListener('click', () => {\n    show(screen.zoomStep(1));\n});\n",
        ] as $what => $copy) {
            $keyCopy = str_replace('cameraKeys(drawing, ', $copy.'cameraKeys(drawing, ', $js);
            $this->assertNotSame($keyCopy, $js);
            $this->assertArrayHasKey('keys', $this->cameraDefects($keyCopy),
                "CONTROL (the page wiring {$what} beside wire/camera-keys.js) did not bite");
        }

        $painter = $this->painterJs();
        $imaged = str_replace("class: 'floor-scene', role: 'group'", "class: 'floor-scene', role: 'img'", $painter);
        $this->assertNotSame($imaged, $painter);
        $this->assertArrayHasKey('painter', $this->exposureDefects($html, $imaged),
            'CONTROL (the drawing painted as an image, its desks presentational) did not bite');

        $enterOnly = str_replace("if (event.key === 'Enter' || event.key === ' ') {", "if (event.key === 'Enter') {", $painter);
        $this->assertNotSame($enterOnly, $painter);
        $this->assertArrayHasKey('activate', $this->exposureDefects($html, $enterOnly),
            'CONTROL (a desk Space does not activate) did not bite');

        $unrestored = preg_replace('/\n\s*\(svg\.querySelector\(`\[data-key=[^\n]*\n/', "\n", $painter);
        $this->assertNotSame($unrestored, $painter);
        $this->assertArrayHasKey('refocus', $this->exposureDefects($html, $unrestored),
            'CONTROL (a rebuild that drops the focused desk\'s focus) did not bite');

        $unfocused = str_replace(' ?? host).focus({ preventScroll: true });', ' ?? host);', $painter);
        $this->assertNotSame($unfocused, $painter);
        $this->assertArrayHasKey('refocus', $this->exposureDefects($html, $unfocused),
            'CONTROL (a rebuild that finds the focused desk but never calls .focus() on it) did not bite');

        $unguarded = str_replace("        if (focusedKey !== null) {\n            (svg.querySelector", "        {\n            (svg.querySelector", $painter);
        $this->assertNotSame($unguarded, $painter);
        $this->assertArrayHasKey('refocus', $this->exposureDefects($html, $unguarded),
            'CONTROL (a rebuild that moves focus into the drawing whether or not the keyboard was in it) did not bite');

        // card#7343 comment 7692 item 1: the drawing's tab stop is `offerKeys()`'s, offered only while the camera
        // frames the floor, so the markup offers none — a drawing the keyboard cannot reach is now planted in
        // `offerKeys()` itself, `TheCameraWireIsOneForBothPagesTest`'s offer defects.
        $tabbed = str_replace(' aria-label="the room drawing" data-dimmed', ' aria-label="the room drawing" tabindex="0" data-dimmed', $html);
        $this->assertNotSame($tabbed, $html);
        $this->assertArrayHasKey('focus', $this->exposureDefects($tabbed, $painter),
            'CONTROL (a drawing that is a tab stop before any camera frames it) did not bite');

        $fitShown = str_replace('id="floor-fit" hidden>', 'id="floor-fit">', $html);
        $this->assertNotSame($fitShown, $html);
        $this->assertArrayHasKey('fit', $this->exposureDefects($fitShown, $painter),
            'CONTROL (Fit the floor shown before any camera frames the floor) did not bite');

        $fitless = str_replace("fit: el('floor-fit'),", "fit: el('floor-zoom-out'),", $js);
        $this->assertNotSame($fitless, $js);
        $this->assertArrayHasKey('buttons', $this->cameraDefects($fitless),
            'CONTROL (Fit the floor never offered or withdrawn with the camera) did not bite');

        // card#11045: the gesture hint is offered with the camera, and the markup starts with it withdrawn.
        $hintless = str_replace("hint: el('floor-hint') }", "hint: el('floor-fit') }", $js);
        $this->assertNotSame($hintless, $js);
        $this->assertArrayHasKey('buttons', $this->cameraDefects($hintless),
            'CONTROL (the gesture hint never offered or withdrawn with the camera) did not bite');

        $hintShown = str_replace('id="floor-hint" class="camera-hint" hidden>', 'id="floor-hint" class="camera-hint">', $html);
        $this->assertNotSame($hintShown, $html);
        $this->assertArrayHasKey('hint', $this->exposureDefects($hintShown, $painter),
            'CONTROL (the gesture hint shown before any camera frames the floor) did not bite');

        $blind = str_replace('    surface: surface(),', '', $js);
        $this->assertNotSame($blind, $js);
        $this->assertArrayHasKey('surface', $this->cameraDefects($blind),
            'CONTROL (a screen handed no surface) did not bite');

        // card#11045 PR-A, design review r3 MAJOR-A: the camera's surface is the drawing's own box, re-read
        // whenever that box changes — a surface read off the window fits a drawing the chrome has shortened.
        $windowed = str_replace('return { width: box.clientWidth, height: box.clientHeight };', 'return { width: box.clientWidth, height: window.innerHeight };', $js);
        $this->assertNotSame($windowed, $js);
        $this->assertArrayHasKey('surface', $this->cameraDefects($windowed),
            'CONTROL (a surface whose height is the window\'s, not the drawing\'s) did not bite');

        $unobserved = str_replace('}).observe(drawing);', '});', $js);
        $this->assertNotSame($unobserved, $js);
        $this->assertArrayHasKey('surface', $this->cameraDefects($unobserved),
            'CONTROL (a drawing whose size changes reach no camera) did not bite');

        foreach ([
            'a section left open' => ['<details class="floor-section">', '<details class="floor-section" open>'],
            'a list root outside its section' => ['<ul id="floor-coord" aria-labelledby="floor-coord-heading" hidden></ul>', ''],
            'a heading outside its summary' => ['<summary><h2 id="floor-log-heading">', '<summary><h2>'],
        ] as $what => [$from, $to]) {
            $planted = str_replace($from, $to, $html);
            $this->assertNotSame($planted, $html, "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->sectionDefects($planted), "CONTROL ({$what}) did not bite");
        }

        // Unclosed on purpose: the parser closes it at its parent's end, so the panel is inside it.
        $panelled = str_replace('<section id="floor-panel"', '<details><summary>the panel</summary><section id="floor-panel"', $html);
        $this->assertNotSame($panelled, $html);
        $this->assertArrayHasKey('panel', $this->sectionDefects($panelled),
            'CONTROL (the drill-down panel inside a section) did not bite');

        $recounted = $this->mutatedModules(['status-strip.js', 'totals: fleetTotals(fleet),', "totals: '4 seats · 4 live',"]);
        $this->assertArrayHasKey('totals', $this->countDefects($js, $recounted),
            'CONTROL (header counts that are not the lobby\'s totals) did not bite');

        $unpaintedCounts = str_replace("say('floor-fleet-counts', `building: \${strip.totals}`);", '', $js);
        $this->assertNotSame($unpaintedCounts, $js);
        $this->assertArrayHasKey('paint', $this->countDefects($unpaintedCounts),
            'CONTROL (header counts never painted) did not bite');

        $drifted = (string) preg_replace('/export const ANIMATION_LOG_RETENTION = \d+;/', 'export const ANIMATION_LOG_RETENTION = 5000;', $livePage);
        $this->assertNotSame($drifted, $livePage);
        $this->assertArrayHasKey('retention', $this->pageLogDefects($js, $drifted),
            'CONTROL (a retention figure that is not § 12\'s) did not bite');
    }

    /** @return array<string, string> */
    private function wiringDefects(string $html, string $js, ?string $jsRoot = null, ?string $painter = null): array
    {
        $declared = $this->declaredIds($html);
        $addressed = array_values(array_unique(array_merge(
            $this->addressedIds($js, $jsRoot),
            $this->painterIds($painter ?? $this->painterJs()),
        )));
        sort($addressed);

        $this->assertGreaterThan(10, count($declared), 'the page declares almost no floor elements — the parse has stopped reading it');
        $this->assertGreaterThan(10, count($addressed), 'the entry addresses almost no elements — the parse has stopped reading main.js');

        $defects = [];
        $undeclared = array_values(array_diff($addressed, $declared));
        $unwritten = array_values(array_diff($declared, $this->accountedFor($html, $addressed)));

        if ($undeclared !== []) {
            $defects['undeclared'] = 'main.js writes into elements the page does not declare: '.implode(', ', $undeclared);
        }

        if ($unwritten !== []) {
            $defects['unwritten'] = 'the page declares elements nothing writes into: '.implode(', ', $unwritten);
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function bindingDefects(string $js): array
    {
        return [...$this->livePageDefects($js), ...$this->pageLogDefects($js)];
    }

    /** @return array<string, string> */
    private function cameraDefects(string $js): array
    {
        $defects = [];
        $wired = [
            // … read off the drawing's own box and re-read on every change of it (card#11045 PR-A, MAJOR-A).
            'surface' => ['surface: surface(),', 'screen.resize(surface())', "const box = el('floor-drawing');",
                'return { width: box.clientWidth, height: box.clientHeight };', 'new ResizeObserver(() => {', '}).observe(drawing);'],
            // … and the camera as it stands, so the wheel is the page's scroll while it frames nothing (card#7343 r3b).
            // card#11045: the wheel's two acts (a plain wheel's pan, a Ctrl+wheel's zoom) and the touch pinch.
            'wheel' => ["import { cameraGestures } from '../wire/camera-gestures.js';", 'cameraGestures(drawing, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);'],
            'pinch' => ["import { cameraGestures } from '../wire/camera-gestures.js';", 'cameraGestures(drawing, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);'],
            'drag' => ["import { cameraGestures } from '../wire/camera-gestures.js';", 'cameraGestures(drawing, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);'],
            'resize' => ["    screen.resize(surface());\n    show(screen.camera());\n"],
            // … and the camera as it stands, so every key is the browser's while it frames nothing (card#7343 r4b).
            'keyboard' => ["import { cameraKeys, offerKeys } from '../wire/camera-keys.js';", 'cameraKeys(drawing, zoomButtons, { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }, show);'],
            // … and the zoom buttons, *Fit the floor*, the gesture hint (card#11045) and the drawing's keys and
            // tab stop offered from each frame's camera (card#7343 r4b, comment 7692).
            'buttons' => ["const zoomButtons = { zoomIn: el('floor-zoom-in'), zoomOut: el('floor-zoom-out'), fit: el('floor-fit'), hint: el('floor-hint') };",
                "    offerKeys(el('floor-drawing'), zoomButtons, frame.camera);"],
            'fit' => ["el('floor-fit').addEventListener('click'", 'screen.fitFloor()'],
        ];

        foreach ($wired as $act => $needles) {
            foreach ($needles as $needle) {
                if (! str_contains($js, $needle)) {
                    $defects[$act] = "the entry does not wire the {$act} to the screen's camera (`{$needle}` is missing)";
                }
            }
        }

        // One gesture wiring for both pages (card#7343 r1): a pointer, wheel or drag listener here is a copy.
        if (preg_match("/addEventListener\\('(wheel|pointerdown|pointermove|pointerup|pointercancel|dragstart)'/", $js, $m) === 1) {
            $defects['gestures'] = "the entry wires a {$m[1]} of its own beside wire/camera-gestures.js";
        }

        // And one key wiring (card#7343 r2-2): a keydown listener, or a zoom step of the entry's own, is a copy.
        if (preg_match("/addEventListener\\('keydown'|\\.zoomStep\\(/", $js, $m) === 1) {
            $defects['keys'] = "the entry wires `{$m[0]}` of its own beside wire/camera-keys.js";
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function exposureDefects(string $html, string $painter): array
    {
        $defects = [];

        if (preg_match('/<div id="floor-drawing"([^>]*)>/', $html, $m) !== 1) {
            return ['focus' => 'the page declares no #floor-drawing'];
        }

        // card#7343 comment 7692 item 1: `offerKeys()` makes the drawing a tab stop once its camera frames the
        // floor, and the markup starts with it withdrawn — the early return `offerKeys()` takes reads that.
        if (str_contains($m[1], 'tabindex')) {
            $defects['focus'] = 'the drawing is a tab stop in the markup, before any camera frames it — `offerKeys()` offers it';
        }

        if (preg_match('/<button type="button" id="floor-fit"([^>]*)>/', $html, $fit) !== 1 || preg_match('/(^|\s)hidden(\s|$)/', $fit[1]) !== 1) {
            $defects['fit'] = 'Fit the floor is shown in the markup, before any camera frames the floor — `offerKeys()` offers it';
        }

        // card#11045: the gesture hint names the camera's gestures, untrue until a camera frames the floor.
        if (preg_match('/<p id="floor-hint"([^>]*)>/', $html, $hint) !== 1 || preg_match('/(^|\s)hidden(\s|$)/', $hint[1]) !== 1) {
            $defects['hint'] = 'the gesture hint is shown in the markup, before any camera frames the floor — `offerKeys()` offers it';
        }

        if (str_contains($m[1], 'role="img"')) {
            $defects['drawing'] = 'the drawing is an image — its desks are presentational';
        }

        if (preg_match("/svg = node\\('svg', \\{[^}]*role: '(\\w+)'/", $painter, $r) !== 1 || $r[1] === 'img') {
            $defects['painter'] = 'the painter\'s <svg> is an image (or states no role) — its desks are presentational';
        }

        if (! str_contains($painter, "if (event.key === 'Enter' || event.key === ' ') {")) {
            $defects['activate'] = 'a desk activates on Enter alone — a role="button" also answers Space';
        }

        // The painter rebuilds the <svg> on every paint: the focused desk's key is noted before the
        // rebuild and focus is put back on the rebuilt desk after it, or a keyboard user on a desk
        // drops to the page's body at the next render — and only when the keyboard WAS inside the
        // drawing, or every repaint would pull focus off whatever else on the page the viewer is on.
        $noted = strpos($painter, 'const focusedKey = host.contains(document.activeElement)');
        $rebuilt = strpos($painter, 'host.replaceChildren(svg);');
        $guarded = strpos($painter, 'if (focusedKey !== null) {');
        $restored = strpos($painter, 'svg.querySelector(`[data-key="${CSS.escape(focusedKey)}"]`) ?? host).focus(');

        if ($noted === false || $rebuilt === false || $guarded === false || $restored === false
            || ! ($noted < $rebuilt && $rebuilt < $guarded && $guarded < $restored)) {
            $defects['refocus'] = 'the painter does not put focus back on the desk it rebuilt — noted before `host.replaceChildren(svg)`, and `.focus(` called on the rebuilt desk (or the drawing) after it, under `if (focusedKey !== null)`';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function sectionDefects(string $html): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $defects = [];
        $sections = ['floor-desks', 'floor-overflow', 'floor-coord', 'floor-log'];

        foreach ($sections as $id) {
            $root = $dom->getElementById($id);
            $details = $root === null ? null : $this->ancestor($root, 'details');

            if ($details === null) {
                $defects[$id] = "#{$id} is not inside a <details>";

                continue;
            }

            if ($details->hasAttribute('open')) {
                $defects[$id] = "#{$id}'s <details> is open by default";
            }

            $summary = null;

            foreach ($details->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->tagName === 'summary') {
                    $summary = $child;

                    break;
                }
            }

            $heading = $root->getAttribute('aria-labelledby');

            if ($summary === null || $heading === '' || ! $this->contains($summary, $heading)) {
                $defects[$id] = "#{$id}'s heading (#{$heading}) is not in its <details>' <summary>";
            }
        }

        foreach (['floor-panel', 'floor-drawing', 'floor-camera'] as $id) {
            $node = $dom->getElementById($id);

            if ($node === null || $this->ancestor($node, 'details') !== null) {
                $defects[$id === 'floor-panel' ? 'panel' : $id] = "#{$id} is missing or inside a <details>";
            }
        }

        return $defects;
    }

    private function ancestor(\DOMNode $node, string $tag): ?\DOMElement
    {
        for ($at = $node->parentNode; $at !== null; $at = $at->parentNode) {
            if ($at instanceof \DOMElement && $at->tagName === $tag) {
                return $at;
            }
        }

        return null;
    }

    private function contains(\DOMElement $node, string $id): bool
    {
        foreach ($node->getElementsByTagName('*') as $child) {
            if ($child->getAttribute('id') === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function countDefects(string $js, ?string $jsRoot = null): array
    {
        $defects = [];

        if (! str_contains($js, "say('floor-fleet-counts', `building: \${strip.totals}`);")) {
            $defects['paint'] = 'the entry does not paint the strip\'s totals into #floor-fleet-counts';
        }

        $dir = $jsRoot ?? $this->moduleDir();
        $script = 'const s = await import('.json_encode('file://'.$dir.'/status-strip.js').');'
            .'const l = await import('.json_encode('file://'.dirname($dir).'/lobby/lobby-model.js').');'
            .'const feed = { reload_required: false, signed_out: null, mode: "open", silent: false, last_message: null, connected: false, resyncs: 0 };'
            .'const fleets = [{ seats_total: 9, seats_live: 8 }, { seats_total: 2, seats_live: 0 }, { db: "down" }, null];'
            .'console.log(JSON.stringify(fleets.map((f) => [s.statusStrip(feed, f).totals, l.fleetTotals(f)])));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read the status strip');

        foreach (json_decode($out, true) as [$strip, $lobby]) {
            if ($strip !== $lobby) {
                $defects['totals'] = "the strip's totals read `{$strip}` where the lobby's read `{$lobby}`";
            }
        }

        return $defects;
    }

    private function floorPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/floor/aimla')
            ->assertOk()
            ->getContent() ?: '';
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    private function painterJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/painter.js');
    }

    /**
     * Every `floor-*` id the painter addresses — it finds its own drawing element.
     *
     * @return list<string>
     */
    private function painterIds(string $painter): array
    {
        preg_match_all("/getElementById\(\s*'(floor-[a-z-]+)'/", $painter, $m);

        $this->assertNotSame([], $m[1], 'the painter addresses no element — the parse has stopped reading painter.js');

        return $m[1];
    }

    /** @return array<string, string> */
    private function painterDefects(string $js): array
    {
        return str_contains($js, "import { createPainter, loadArt, measurer, themeInputs } from './painter.js';")
            && preg_match('/=\s*createPainter\(/', $js) === 1
            ? []
            : ['painter' => 'the entry does not construct the room drawing\'s painter from floor/painter.js'];
    }

    /** @return list<string> */
    private function declaredIds(string $html): array
    {
        preg_match_all('/id="(floor-[a-z-]+)"/', $html, $m);

        $ids = array_values(array_unique($m[1]));
        sort($ids);

        return $ids;
    }

    /**
     * Every `floor-*` id main.js addresses: the literals its three helpers name, plus the family it
     * builds from the lobby model's indicator keys — derived from the model, never listed.
     *
     * @return list<string>
     */
    private function addressedIds(string $js, ?string $jsRoot = null): array
    {
        preg_match_all("/(?:el|say|list)\(\s*'(floor-[a-z-]+)'/", $js, $m);
        $ids = $m[1];

        $this->assertSame(1, preg_match_all('/`floor-\$\{[A-Za-z0-9_.]+\}`/', $js),
            'main.js builds element ids from a template in a number of places this parser does not know');

        foreach ($this->indicatorKeys($jsRoot) as $key) {
            $ids[] = 'floor-'.$key;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function indicatorKeys(?string $floorDir = null): array
    {
        $model = dirname($floorDir ?? $this->moduleDir()).'/lobby/lobby-model.js';
        $script = 'const m = await import('.json_encode('file://'.$model).');'
            .'console.log(JSON.stringify(m.indicators({}).map((i) => i.key)));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read the lobby model');

        return json_decode($out, true);
    }

    /** @return list<string> */
    private function accountedFor(string $html, array $addressed): array
    {
        preg_match_all('/aria-labelledby="([^"]+)"/', $html, $m);

        $aria = [];

        foreach ($m[1] as $value) {
            foreach (preg_split('/\s+/', $value) ?: [] as $id) {
                $aria[] = $id;
            }
        }

        return array_values(array_unique(array_merge($addressed, $aria)));
    }
}

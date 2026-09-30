<?php

namespace Tests\Feature\Lobby;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ THE WIRING CHECKLIST FOR THE ONE FAILURE MODE THIS CLIENT HAS NO OTHER WITNESS FOR:
 * `document.getElementById(…)` ANSWERING `null`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * Nothing throws. The page renders, the fetch runs, the model computes every fact correctly —
 * and one of them is written into nothing at all. There is no browser on this host, so no test
 * in this repository can see the rendered page; what CAN be checked is that the two ends of the
 * contract name the same elements, and that is what this file does, IN BOTH DIRECTIONS:
 *
 *   · an id `main.js` addresses that the page does not declare → the silent no-op above;
 *   · an id the page declares that `main.js` never writes into → a cell that will sit on its
 *     placeholder forever, which reads as *waiting for the fleet snapshot* on a fleet that
 *     answered. Headings are excluded by being `aria-labelledby` targets — derived from the
 *     page's own attributes, not from a list written here.
 *
 * ⛔ THE COMPUTED IDS ARE DERIVED, NOT LISTED. `main.js` addresses the four health cells through
 * a template literal over the model's own indicator keys, so this file asks the MODEL for those
 * keys (through `node`) instead of hand-listing four ids that would then be a fifth copy of the
 * indicator set. CONTROL 11 adds an indicator to the model and requires this to red.
 *
 * ⛔ AND, SINCE card#7341 STEP 9, THE ONE THING ABOUT THE LOBBY'S CLIENT NOTHING ELSE CAN CHECK: that
 * the page constructs the client protocol WITH its stream recovery — through `wire/live-page.js`, the
 * floor page's own construction (Appendix B row 8's ⛔, which binds every page holding a real
 * `EventSource`).
 *
 * ⛔ AND THE BUILDING's CAMERA (Appendix B row 16, card#7343): that the page hands each viewer act to the
 * lobby screen — the ride and its arrival, the hold that keeps a plate link from outrunning it, the whole-building control, the wheel and the drag through
 * `wire/camera-gestures.js`, the keys and the zoom buttons through `wire/camera-keys.js`, the keyboard's
 * focus on a plate — grows no copy of either shared module, stands `plate-row.js`'s rows and writes their
 * counter-scale from the camera it shows; and that the building takes focus with the floor's keys and
 * zoom buttons. Each as the source's own lines, each with a control.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that anything renders, lays out, or is legible. It is
 * evidence that every fact the model produces has an element with its name on it.
 */
class LobbyPageWiringTest extends TestCase
{
    use DrivesTheLobbyClient;
    use RefreshDatabase;

    public function test_every_element_the_client_addresses_exists_on_the_page_and_the_reverse(): void
    {
        $html = $this->lobbyPage();
        $js = $this->mainJs();

        $declared = $this->declaredIds($html);
        $addressed = $this->addressedIds($js);

        // The parse control first: an empty set on either side makes every difference below
        // vacuously clean, which is this file's own version of the false green.
        $this->assertGreaterThan(8, count($declared), 'the page declares almost no lobby elements — the parse has stopped reading it');
        $this->assertGreaterThan(8, count($addressed), 'the client addresses almost no elements — the parse has stopped reading main.js');

        $this->assertSame([], array_values(array_diff($addressed, $declared)),
            'main.js writes a fact into an element the page does not declare — getElementById '
            .'answers null, nothing throws, and that fact is silently never rendered');

        $this->assertSame([], array_values(array_diff($declared, $this->accountedFor($html, $addressed))),
            'the page declares a lobby element nothing ever writes into — it will hold its '
            .'placeholder over a fleet that answered');
    }

    /**
     * § 2.1 row 5 / AT-D3-15's GREEN — "the per-floor summary is LABELLED as a count of held
     * seats". An unlabelled summary reads as a fleet fact, which is the confusion the discrepancy
     * check exists to expose; the label is the thing that keeps the two readouts distinguishable
     * when they agree, and they agree almost always.
     */
    public function test_the_per_floor_summary_is_labelled_as_a_count_of_held_seats(): void
    {
        $this->assertStringContainsString('counts the seats this client holds', $this->lobbyPage(),
            'the per-floor summary carries no label saying whose count it is');
    }

    /** Appendix B row 8's ⛔, on the lobby: the protocol comes from `wire/live-page.js`, with its scheduler. */
    public function test_the_page_constructs_the_protocol_with_its_stream_recovery(): void
    {
        $this->assertSame([], $this->livePageDefects($this->mainJs()));
    }

    /**
     * § 14 item 26 on the lobby (card#7343): its sky is § 6.2 A17's, so it writes an A17 row on every
     * `feed.heartbeat` — through the page's log, which `wire/live-page.js` bounds with § 12's figure.
     */
    public function test_the_page_takes_its_animation_log_bounded_from_live_page(): void
    {
        $this->assertSame([], $this->pageLogDefects($this->mainJs()));
    }

    /**
     * card#7343 r1 (impl review MINOR 1): the page holds the log without importing it, so the import-graph
     * walk (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`) cannot see a row written through it here.
     * What holds it is this: in `main.js`'s code the page's `log` is named exactly twice — taken from
     * `livePage()` and handed to `startLobbyScreen()` — and used nowhere else.
     */
    public function test_the_page_hands_the_log_to_the_screen_and_uses_it_nowhere_else(): void
    {
        $this->assertSame([], $this->logUseDefects($this->mainJs()));
    }

    public function test_the_page_serves_the_module_and_every_import_resolves(): void
    {
        $this->assertStringContainsString('type="module"', $this->lobbyPage());
        $this->assertStringContainsString('/js/lobby/main.js', $this->lobbyPage(),
            'the page no longer loads the lobby client');

        $dir = $this->moduleDir();

        $this->assertFileExists($dir.'/main.js');

        // An import path with a typo is a client that never runs at all, and the page that loads
        // it looks exactly the same as one that does. The imports are read from the modules
        // themselves rather than listed here. ⚠ THE RESOLVER MOVED to the shared rig at
        // card#8300, and it moved because it was `./`-ONLY: this module tree gained its first
        // `../` import in the same change, which would have entered the blind spot unseen.
        $this->assertGreaterThan(0, $this->assertEveryRelativeImportResolves($dir),
            'no relative import was found — the check measured nothing');
    }

    /**
     * Appendix B row 16 (card#7343): the ride ARRIVES and the building's camera reaches the screen. The
     * decisions are `lobby-screen.js`'s and held headlessly by
     * `Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`; what only this file can
     * hold is that the page hands each act to the screen and performs the arrival the ride returns —
     * there is no browser on this host, so the wiring is asserted as the source's own lines.
     */
    public function test_the_page_wires_the_ride_and_the_building_camera_to_the_screen(): void
    {
        $js = $this->mainJs();

        $this->assertSame([], $this->cameraDefects($js));

        // CONTROL — a ride that moves the cab and the camera and never arrives: the lobby as it was.
        $stays = str_replace('window.location.assign(ride.route);', '', $js);
        $this->assertNotSame($stays, $js, "the arrival control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride', $this->cameraDefects($stays),
            'CONTROL (a ride that never arrives at its route) did not bite');

        // CONTROL — a ride whose glide an interruption abandons: the ruling's "the click commits the ride".
        $uncommitted = str_replace('}, { commit: true });', '});', $js);
        $this->assertNotSame($uncommitted, $js, "the commit control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride in flight', $this->cameraDefects($uncommitted),
            'CONTROL (a ride an interruption abandons) did not bite');

        // CONTROL — a ride control left enabled while a ride is in flight.
        $enabled = str_replace('ride.disabled = building.elevator.next === null || riding;', 'ride.disabled = building.elevator.next === null;', $js);
        $this->assertNotSame($enabled, $js, "the in-flight control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride in flight', $this->cameraDefects($enabled),
            'CONTROL (a ride control enabled in flight) did not bite');

        // CONTROL — a lobby the back-forward cache restores with its ride still in flight, the control dead.
        $stranded = str_replace("    if (event.persisted) {\n        screen.returned();\n", "    if (event.persisted) {\n", $js);
        $this->assertNotSame($stranded, $js, "the return control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride in flight', $this->cameraDefects($stranded),
            'CONTROL (a restored lobby whose ride never ends) did not bite');

        // CONTROL — a ride whose glide arrived and whose hold never ended (card#7343 r2-4): a navigation
        // the browser cancels leaves the lobby's controls dead.
        $held = str_replace("        window.location.assign(ride.route);\n        screen.returned();\n", "        window.location.assign(ride.route);\n", $js);
        $this->assertNotSame($held, $js, "the arrival-hold control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride in flight', $this->cameraDefects($held),
            'CONTROL (a hold that outlives the glide) did not bite');

        // CONTROL — the gestures handed a camera that frames nothing (card#7343 r3b): every wheel over the
        // drawn building would then scroll the page instead of zooming it.
        $unframed = str_replace('cameraGestures(building, { wheel: screen.wheel, drag: screen.drag, camera: screen.camera }, show);',
            'cameraGestures(building, { wheel: screen.wheel, drag: screen.drag, camera: () => ({ bounds: null }) }, show);', $js);
        $this->assertNotSame($unframed, $js, "the framed-wheel control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('wheel', $this->cameraDefects($unframed),
            'CONTROL (a wheel handed a camera that frames nothing) did not bite');

        // CONTROL — the keys handed a camera that frames nothing (card#7343 r4b): every key over the drawn
        // building would then be the browser's.
        $keysUnframed = str_replace('{ zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }', '{ zoomStep: screen.zoomStep, drag: screen.drag, camera: () => ({ bounds: null }) }', $js);
        $this->assertNotSame($keysUnframed, $js, "the framed-keys control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('keys', $this->cameraDefects($keysUnframed),
            'CONTROL (keys handed a camera that frames nothing) did not bite');

        // CONTROL — the keys and the zoom buttons never offered from the camera shown (card#7343 r4b).
        $unoffered = str_replace("    offerKeys(el('lobby-building'), zoomButtons, screen.camera());\n", '', $js);
        $this->assertNotSame($unoffered, $js, "the offer control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('keys', $this->cameraDefects($unoffered),
            'CONTROL (keys and zoom buttons never offered) did not bite');

        // CONTROL — the offer read off the glide's step rather than the screen's camera (card#7343 c7692 item 4):
        // a building that stops framing mid-glide would keep its zoom buttons shown.
        $stepped = str_replace("    offerKeys(el('lobby-building'), zoomButtons, screen.camera());", "    offerKeys(el('lobby-building'), zoomButtons, camera);", $js);
        $this->assertNotSame($stepped, $js, "the offer-camera control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('keys', $this->cameraDefects($stepped),
            'CONTROL (the offer read off a glide step) did not bite');

        // CONTROL — the whole-building control never offered or withdrawn with the camera (card#7343 c7692 item 2).
        $wholeless = str_replace("fit: el('lobby-whole-building') }", "fit: el('lobby-zoom-out') }", $js);
        $this->assertNotSame($wholeless, $js, "the whole-building offer control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('keys', $this->cameraDefects($wholeless),
            'CONTROL (a whole-building control never offered with the camera) did not bite');

        // CONTROL — a plate link clicked mid-ride that navigates (card#7343 r3b): the hold never wired.
        $unheld = str_replace("holdPlateLinks(building, screen.riding);\n", '', $js);
        $this->assertNotSame($unheld, $js, "the link-hold control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride in flight', $this->cameraDefects($unheld),
            'CONTROL (a plate link that outruns the committed ride) did not bite');

        // CONTROL — the hold wired over something other than the screen's in-flight state.
        $blind = str_replace('holdPlateLinks(building, screen.riding);', 'holdPlateLinks(building, () => false);', $js);
        $this->assertNotSame($blind, $js, "the link-hold state control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('ride in flight', $this->cameraDefects($blind),
            'CONTROL (a hold that never sees a ride) did not bite');

        // CONTROL — the keys and the zoom buttons wired to nothing (card#7343 r2-2).
        $keyless = str_replace('cameraKeys(building, ', "cameraKeys(el('lobby-floors'), ", $js);
        $this->assertNotSame($keyless, $js, "the keys control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('keys', $this->cameraDefects($keyless),
            'CONTROL (keys handed an element that is not the drawing) did not bite');

        // CONTROL — the lobby growing its own key or zoom-button copy back beside the shared module.
        foreach ([
            'a keydown of its own' => "building.addEventListener('keydown', () => {});\n",
            'a zoom button of its own' => "el('lobby-zoom-in').addEventListener('click', () => {\n    show(screen.zoomStep(1));\n});\n",
        ] as $what => $copy) {
            $keyCopy = str_replace('cameraKeys(building, ', $copy.'cameraKeys(building, ', $js);
            $this->assertNotSame($keyCopy, $js);
            $this->assertArrayHasKey('key copy', $this->cameraDefects($keyCopy),
                "CONTROL (the lobby wiring {$what} beside wire/camera-keys.js) did not bite");
        }

        // CONTROL — focus-into-view wired to nothing, and wired to a press too (card#7343 r2-2).
        $unfocused = str_replace('const focus = screen.focusPlate(row.dataset.floor);', 'const focus = null;', $js);
        $this->assertNotSame($unfocused, $js, "the focus control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('focus', $this->cameraDefects($unfocused),
            'CONTROL (a focused plate the camera never comes to) did not bite');

        $pressed = str_replace(" || !event.target.matches(':focus-visible')", '', $js);
        $this->assertNotSame($pressed, $js, "the focus-visible control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('focus', $this->cameraDefects($pressed),
            'CONTROL (a press on a plate moving the camera to it) did not bite');

        // CONTROL — the clipping surface left scrollable out from under the camera.
        $scrolled = str_replace("    if (framed) {\n        unscroll();\n    }\n", '', $js);
        $this->assertNotSame($scrolled, $js, "the scroll control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('scroll', $this->cameraDefects($scrolled),
            'CONTROL (a camera shown over a scrolled surface) did not bite');

        // CONTROL — the lobby growing its own gesture copy back beside the shared module.
        $copied = str_replace("const building = el('lobby-building');\n", "const building = el('lobby-building');\nbuilding.addEventListener('pointerdown', () => {});\n", $js);
        $this->assertNotSame($copied, $js, "the gesture-copy control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('gestures', $this->cameraDefects($copied),
            'CONTROL (a gesture copy beside wire/camera-gestures.js) did not bite');

        // CONTROL — a plate's label never painted from the camera at all (card#7343 r4's fix round):
        // `showLabels()` never called by `view()`, so a camera move leaves every label as it was.
        $unlabelled = str_replace('    showLabels(floors, camera);', '', $js);
        $this->assertNotSame($unlabelled, $js, "the label-paint control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('plate text', $this->cameraDefects($unlabelled),
            'CONTROL (showLabels() never called from view()) did not bite');

        // ⛔ NEITHER `renderBuilding()` NOR `paint()` WRITES ANY LABEL GEOMETRY (P5's own ask): `showLabels()`
        // is the ONE writer, called only from `view()` — a second writer growing back beside it, even one
        // that agrees with it, is exactly the shape the round's whole redesign exists to make impossible.
        // r6 review round (r5 review m8): the real assertion and the moved-call CONTROL below both USED
        // TO check this by hand, as two separately written sets of assertions — so a change to what
        // "correct placement" means had to be made twice, and the control could silently stop proving
        // anything if only one copy was updated. `showLabelsPlacementDefects()` is the ONE predicate; both
        // this assertion and the control below run the SAME call against it.
        $this->assertSame([], $this->showLabelsPlacementDefects($js));

        // CONTROL — the call MOVED into paint() rather than removed: still present in the file (every
        // `cameraDefects()` substring needle still matches, since that check is substring-anywhere — r4
        // review MAJOR 3), so only a check that reads WHERE the call sits catches this.
        $movedIntoPaint = str_replace(
            ['    showLabels(floors, camera);', 'view(current(camera));'],
            ['', "view(current(camera));\n    showLabels(floors, camera);"],
            $js,
        );
        $this->assertNotSame($movedIntoPaint, $js, "the moved-call control's anchor is gone — it mutated nothing");
        $this->assertArrayNotHasKey('plate text', $this->cameraDefects($movedIntoPaint),
            'the moved-call control is meant to stay green under the OLD substring-anywhere check — every needle is still present, just relocated; '.
            'if this now fails, cameraDefects() itself changed and this proof of the r4 review MAJOR is stale');
        $this->assertNotSame([], $this->showLabelsPlacementDefects($movedIntoPaint),
            'CONTROL (showLabels() moved into paint()) did not bite');


        // CONTROL — the surface's style never applied: the page keeps whatever box it started with (r3).
        // ⛔ MOVED, card#7343 r3's fix round: applied BEFORE `surface()` is read (the render may just have
        // made the surface a drawing, or stopped it being one), so the camera below sizes off the surface
        // as it now stands, not as it stood before this render's own style change.
        $unstyled = str_replace("    Object.assign(el('lobby-building').style, surfaceStyle(frame.scene, frame.sky));\n", '', $js);
        $this->assertNotSame($unstyled, $js, "the surface control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('surface', $this->cameraDefects($unstyled),
            'CONTROL (a surface style never applied) did not bite');

        // CONTROL — a camera left sized to the surface the render replaced.
        $unsized = str_replace('view(current(camera));', 'view(current(frame.camera));', $js);
        $this->assertNotSame($unsized, $js, "the surface-size control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('surface', $this->cameraDefects($unsized),
            'CONTROL (a camera sized to the old surface) did not bite');

        // CONTROL — a plate drawn by the page itself rather than by `plate-row.js`, which the size test builds.
        $inline = str_replace('rows.append(plateRow(document, plate, scene.plates[plate.level].rect, plate.floor === building.elevator.at));',
            "rows.append(document.createElement('li'));", $js);
        $this->assertNotSame($inline, $js, "the plate-row control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('plate text', $this->cameraDefects($inline),
            'CONTROL (a plate not built by plate-row.js) did not bite');

        // CONTROLS — the ride's own cab-glide timing (Appendix B row 16, slice B): a cab that glides
        // over a time not the ride's (under reduced motion too), and a cab still gliding after the ride.
        // ⚠ A building never painted, a drawing read out to assistive technology, and a drawing rebuilt on
        // every render were checked here as STRING-PRESENCE against `$js` until the impl review's r1 finding
        // (card#7343 row 16): a plant of exactly those three shapes — the cab's `Object.assign` deleted,
        // `scenery.replaceChildren()` deleted, an unconditional `drawing.remove()` added — stayed green
        // against it, because reading main.js's own text never RUNS what it says. That construction, the
        // keeping and the painting are `building-paint.js`'s now, and a node probe RUNS them on a stand-in
        // DOM: `Tests\Feature\Lobby\TheBuildingDrawingKeepsItsElementTest`.
        foreach ([
            'a cab gliding over a time of its own' => ['    cabGlide = ride.glide_ms;', '    cabGlide = 850;'],
            'a cab still gliding after the ride' => ["        cabGlide = 0;\n        window.location.assign(ride.route);", '        window.location.assign(ride.route);'],
        ] as $what => [$anchor, $replacement]) {
            $planted = str_replace($anchor, $replacement, $js);
            $this->assertNotSame($planted, $js, "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertArrayHasKey('building drawing', $this->cameraDefects($planted), "CONTROL ({$what}) did not bite");
        }

        // CONTROL — the whole-building control wired to nothing.
        $unwired = str_replace('screen.wholeBuilding()', 'screen.camera()', $js);
        $this->assertNotSame($unwired, $js, "the whole-building control's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('whole-building', $this->cameraDefects($unwired),
            'CONTROL (a whole-building control that never reaches the camera) did not bite');
    }

    /**
     * card#7343 r2-2: the building takes the keyboard's focus, as the floor's drawing does, and its zoom
     * buttons are the floor's — the same words on the buttons, read off the floor's own view rather than
     * written here — and the drawing is never an image, whose children (the plates' links) would be
     * presentational. card#7343 r4b: neither page's markup offers the keys — no `aria-keyshortcuts`, the
     * zoom buttons hidden — because no camera frames anything before the first render; which keys are named
     * once one does is `wire/camera-keys.js`'s `offerKeys()`, one list for both pages
     * (`Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`).
     */
    public function test_the_building_takes_focus_and_its_keys_and_zoom_buttons_are_the_floors(): void
    {
        $this->assertSame([], $this->keyMarkupDefects($this->lobbyPage()));

        $html = $this->lobbyPage();

        foreach ([
            // card#7343 comment 7692 item 1: a tab stop before any camera frames the building, which then does
            // nothing — the tab stop is `offerKeys()`'s, and a building the keyboard cannot reach is planted there.
            'a tab stop in the markup' => ['aria-label="the building drawing">', 'aria-label="the building drawing" tabindex="0">'],
            'a building drawn as an image' => ['id="lobby-building" role="group"', 'id="lobby-building" role="img"'],
            // card#7343 r4b: keys named before any camera frames the building, which then do nothing.
            'keys named in the markup' => ['aria-label="the building drawing">', 'aria-label="the building drawing" aria-keyshortcuts="+ - ArrowUp ArrowDown ArrowLeft ArrowRight">'],
            'a zoom button shown in the markup' => ['id="lobby-zoom-in" hidden>', 'id="lobby-zoom-in">'],
            // card#7343 comment 7692 item 2: the whole-building control shown before any camera frames anything.
            'a whole-building control shown in the markup' => ['id="lobby-whole-building" hidden>', 'id="lobby-whole-building">'],
            // card#7343 r3: a size or a clip in the markup holds the list in a box before and without a building.
            'a surface clipped in the markup' => ['aria-label="the building drawing">', 'aria-label="the building drawing" style="height: 70vh; overflow: hidden">'],
            'a zoom button in words of its own' => ['id="lobby-zoom-in" hidden>Zoom in<', 'id="lobby-zoom-in" hidden>Closer<'],
        ] as $what => [$anchor, $replacement]) {
            $planted = str_replace($anchor, $replacement, $html);
            $this->assertNotSame($planted, $html, "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->keyMarkupDefects($planted), "CONTROL ({$what}) did not bite");
        }
    }

    /** @return list<string> */
    private function keyMarkupDefects(string $html): array
    {
        $floor = (string) file_get_contents(resource_path('views/floor.blade.php'));
        $defects = [];

        if (preg_match('/<div id="lobby-building"([^>]*)>/', $html, $m) !== 1) {
            return ['the page declares no #lobby-building'];
        }

        $this->assertSame(1, preg_match('/<div id="floor-drawing"([^>]*)>/', $floor, $f), "the floor's drawing did not parse");

        // card#7343 comment 7692 item 1: the building is a tab stop only while its camera frames something —
        // `offerKeys()`'s, and the markup starts with it withdrawn, which `offerKeys()`'s early return reads.
        if (str_contains($m[1], 'tabindex')) {
            $defects[] = 'the building is a tab stop in the markup, before any camera frames it — `offerKeys()` offers it';
        }

        if (preg_match('/<button type="button" id="lobby-whole-building"([^>]*)>/', $html, $wb) !== 1 || preg_match('/(^|\s)hidden(\s|$)/', $wb[1]) !== 1) {
            $defects[] = 'the whole-building control is shown in the markup, before any camera frames anything — `offerKeys()` offers it';
        }

        // The surface's size and clip are `surfaceStyle()`'s, applied only while a building is drawn (r3).
        if (preg_match('/\\bstyle\\s*=/i', $m[1]) === 1) {
            $defects[] = 'the building carries a style of its own in the markup — it clips the list before and without a building';
        }

        if (str_contains($m[1], 'role="img"')) {
            $defects[] = 'the building is an image — its plates\' links are presentational';
        }

        foreach (['the building' => $m[1], 'the floor\'s drawing' => $f[1]] as $which => $attributes) {
            if (str_contains($attributes, 'aria-keyshortcuts')) {
                $defects[] = "{$which} names keys in the markup, before any camera frames it — `offerKeys()` names them";
            }
        }

        foreach (['zoom-in', 'zoom-out'] as $button) {
            $this->assertSame(1, preg_match('/<button type="button" id="floor-'.$button.'"([^>]*)>([^<]*)<\/button>/', $floor, $fb), "the floor's {$button} did not parse");

            if (preg_match('/<button type="button" id="lobby-'.$button.'"([^>]*)>([^<]*)<\/button>/', $html, $lb) !== 1 || $lb[2] !== $fb[2]) {
                $defects[] = "the lobby's {$button} button is not the floor's ({$fb[2]})";

                continue;
            }

            foreach (['lobby' => $lb[1], 'floor' => $fb[1]] as $page => $attributes) {
                if (preg_match('/(^|\s)hidden(\s|$)/', $attributes) !== 1) {
                    $defects[] = "the {$page}'s {$button} button is shown in the markup, before any camera frames anything";
                }
            }
        }

        return $defects;
    }

    /** ⛔ THE CONTROLS — each re-mints one of the two directions' defects. */
    public function test_the_wiring_check_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $html = $this->lobbyPage();
        $js = $this->mainJs();
        $addressed = $this->addressedIds($js);

        // CONTROL 9 — an element renamed out from under the client. Direction one must red.
        $renamed = str_replace('id="lobby-sweep"', 'id="lobby-sweeep"', $html);
        $this->assertNotSame($renamed, $html, "CONTROL 9's anchor is gone — it mutated nothing");
        $this->assertNotSame([], array_diff($addressed, $this->declaredIds($renamed)),
            'CONTROL 9 did not bite: the sweep cell was renamed and the addressed-but-undeclared '
            .'direction stayed clean, so it is not measuring the silent no-op');

        // CONTROL 10 — an element nothing writes into. Direction two must red.
        $orphaned = str_replace('<p id="lobby-stamp">', '<p id="lobby-orphan"></p><p id="lobby-stamp">', $html);
        $this->assertNotSame($orphaned, $html, "CONTROL 10's anchor is gone — it mutated nothing");
        $this->assertNotSame([], array_diff($this->declaredIds($orphaned), $this->accountedFor($orphaned, $addressed)),
            'CONTROL 10 did not bite: an element nothing writes into was added and the '
            .'declared-but-unaddressed direction stayed clean');

        // CONTROL 11 — a FIFTH indicator in the model, with no cell for it. This is the reason
        // the computed ids are derived from the model rather than listed: a hand-written list
        // would still contain four and this would pass.
        $widened = $this->mutatedModules([
            'lobby-model.js',
            "    return [\n        {\n            key: 'store',",
            "    return [\n        { key: 'purge', label: 'purge', member: 'fleet.purge', value: 'ok', detail: null },\n        {\n            key: 'store',",
        ]);
        $grown = $this->addressedIds($js, $widened);

        $this->assertContains('lobby-purge', $grown,
            'CONTROL 11 is not testing what it claims: the added indicator did not reach the addressed set, '
            .'so the ids are not derived from the model');
        $this->assertNotSame([], array_diff($grown, $this->declaredIds($html)),
            'CONTROL 11 did not bite: a fifth indicator with no cell on the page left the wiring clean');

        // CONTROL 12 — the lobby holding a protocol of its own, which inherits the browser's reconnect
        // (Appendix B row 8's ⛔) and is the page walking around the one construction both pages share.
        $bypassed = str_replace('livePage(() => screen.render(() => cab))', 'new FleetClient(fetch, EventSource, { now: Date.now }) && livePage(() => screen.render(() => cab))', $js);
        $this->assertNotSame($bypassed, $js, "CONTROL 12's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('recovery', $this->livePageDefects($bypassed),
            'CONTROL 12 did not bite: the page constructed its own protocol and the recovery check stayed clean');

        // CONTROL 13 — the lobby constructing an unbounded log of its own for its sky's A17 rows (card#7343),
        // beside the one `wire/live-page.js` constructs with § 12's retention.
        $ownLog = str_replace('startLobbyScreen(client, pageFetch, clock, log, paint, {', 'startLobbyScreen(client, pageFetch, clock, createAnimationLog(), paint, {', $js);
        $this->assertNotSame($ownLog, $js, "CONTROL 13's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('retention', $this->pageLogDefects($ownLog),
            'CONTROL 13 did not bite: the page constructed an unbounded log and the retention check stayed clean');

        // CONTROL 14 — the page writing a row through the log it holds, beside handing it to the screen.
        $written = str_replace("client.start();\n", "log.edge({ animation_id: 'A14', cause: 'feed.heartbeat', install_id: null, seat_id: null, motion: true, at: 0 });\nclient.start();\n", $js);
        $this->assertNotSame($written, $js, "CONTROL 14's anchor is gone — it mutated nothing");
        $this->assertArrayHasKey('log', $this->logUseDefects($written),
            'CONTROL 14 did not bite: the page wrote a row through its log and the use check stayed clean');
    }

    /**
     * Every use of the page's `log` in `main.js`'s code (comments blanked, and `log` as a name of its own —
     * never inside `lobby-log` or after a `.`): exactly the two the page needs.
     *
     * @return array<string, string>
     */
    private function logUseDefects(string $js): array
    {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:\\\\])//[^\n]*#'], ['', '$1'], $js);
        $uses = preg_match_all('/(?<![\w$.\-])log\b(?!-)/', $code);
        $wanted = ['requestRender, log } = livePage(', 'startLobbyScreen(client, pageFetch, clock, log, paint, {'];

        foreach ($wanted as $needle) {
            if (! str_contains($code, $needle)) {
                return ['log' => "the page does not name its log where it should (`{$needle}` is missing)"];
            }
        }

        return $uses === count($wanted) ? [] : ['log' => "the page names its log {$uses} times, not the ".count($wanted).' it needs — a use beside handing it to the screen'];
    }

    /** @return array<string, string> */
    private function cameraDefects(string $js): array
    {
        $defects = [];
        $wired = [
            'ride' => ["el('lobby-elevator').addEventListener('click'", 'const ride = screen.ride();',
                'glideTo(ride.from, ride.to, ride.glide_ms, () => {', 'window.location.assign(ride.route);'],
            // The click commits the ride (card#7343 r1 ruling): the glide is committed, the control is
            // disabled while the frame says a ride is in flight, and the page coming back ends it.
            // … and the hold protects the glide only (r2-4): arriving asks for the route, then ends the hold.
            'ride in flight' => ['}, { commit: true });', 'ride.disabled = building.elevator.next === null || riding;',
                'renderBuilding(building, frame.scene, frame.sky, summary.unclaimed, frame.riding);',
                "window.addEventListener('pageshow', (event) => {\n    if (event.persisted) {\n        screen.returned();",
                "        window.location.assign(ride.route);\n        screen.returned();\n        screen.draw(cab);\n    }, { commit: true });",
                // … and the committed ride wins over a plate link clicked during it (card#7343 r3b): the hold
                // is `ride-hold.js`'s, on the building, over the screen's own in-flight state.
                "import { holdPlateLinks } from './ride-hold.js';", 'holdPlateLinks(building, screen.riding);'],
            'whole-building' => ["el('lobby-whole-building').addEventListener('click'", 'screen.wholeBuilding()'],
            // … and the camera as it stands, so the wheel is the page's scroll while it frames nothing (card#7343 r3b).
            'wheel' => ["import { cameraGestures } from '../wire/camera-gestures.js';", 'cameraGestures(building, { wheel: screen.wheel,', 'cameraGestures(building, { wheel: screen.wheel, drag: screen.drag, camera: screen.camera }, show);'],
            'drag' => ["import { cameraGestures } from '../wire/camera-gestures.js';", 'cameraGestures(building, { wheel: screen.wheel, drag: screen.drag, camera: screen.camera }, show);'],
            // The clipping surface is never scrolled out from under the camera (card#7343 r1).
            'scroll' => ["import { framesNothing } from '../wire/camera.js';", "function view(camera) {\n    const framed = !framesNothing(camera);\n\n    if (framed) {\n        unscroll();\n    }\n",
                "building.addEventListener('scroll', unscroll);",
                "    node.scrollTop = 0;\n    node.scrollLeft = 0;"],
            'resize' => ['show(screen.resize(surface()))'],
            // A plate's label (FLOOR.md § 4.1 states the contract), held by
            // `Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest` at every framing it drives;
            // what only this file can hold is that the page stands `plate-row.js`'s rows and calls
            // `showLabels()` — the ONE writer — from `view()`.
            'plate text' => ["import { surfaceStyle } from './building-scene.js';", "import { showLabels } from './label-paint.js';", "import { plateRow } from './plate-row.js';",
                '    showLabels(floors, camera);',
                'rows.append(plateRow(document, plate, scene.plates[plate.level].rect, plate.floor === building.elevator.at));'],
            // The keyboard and the zoom buttons (card#7343 r2-2): the floor's, through the one module.
            // … the camera as it stands, so every key is the browser's while it frames nothing, and the keys
            // and the zoom buttons offered from the camera `view()` shows (card#7343 r4b).
            'keys' => ["import { cameraKeys, offerKeys } from '../wire/camera-keys.js';",
                "const zoomButtons = { zoomIn: el('lobby-zoom-in'), zoomOut: el('lobby-zoom-out'), fit: el('lobby-whole-building') };",
                'cameraKeys(building, zoomButtons, { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }, show);',
                // … from the screen's camera, which every gate reads, never a glide's step (card#7343 c7692 item 4).
                "    offerKeys(el('lobby-building'), zoomButtons, screen.camera());"],
            // Focus-into-view (card#7343 r2-2): the keyboard's focus on a plate, and never a press's.
            'focus' => ["building.addEventListener('focusin', (event) => {", "const row = event.target.closest('li[data-floor]');",
                "if (row === null || !event.target.matches(':focus-visible')) {", 'const focus = screen.focusPlate(row.dataset.floor);',
                'glideTo(focus.from, focus.to, focus.glide_ms);'],
            // The surface is a clipping drawing only while there is a building to draw (card#7343 r3):
            // `building-scene.js`'s `surfaceStyle()`, applied on every render, and the camera sized to the
            // surface the render leaves. What the style is for each scene is
            // `Tests\Feature\Lobby\TheLobbyFetchesTheBuildingTest`'s.
            'surface' => ["import { surfaceStyle } from './building-scene.js';",
                // … with § 6.2 A17's sky, as the screen last set it, behind the building (card#7343's ruling),
                // applied BEFORE `surface()` is read (the camera below sizes off the surface as THIS render
                // leaves it, never as it stood before).
                "Object.assign(el('lobby-building').style, surfaceStyle(frame.scene, frame.sky));",
                ': screen.resize(size);', 'view(current(camera));'],
            'reduced motion' => ["reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches"],
            // Appendix B row 16, slice B: the building's drawing — `building-scene.js`'s shapes, painted under
            // the plates' labels and hidden from assistive technology, kept across renders so the cab glides,
            // and the cab gliding over the ride's glide (none under reduced motion) and cut on every other
            // render. What the shapes and the cab's style are is `TheBuildingIsDrawnAsTheReferencesSectionTest`'s.
            'building drawing' => ["import { buildingDrawing, keepDrawing, paintBuilding } from './building-paint.js';",
                'const { drawing, art, scenery, windows, cabNode } = buildingDrawing(document);',
                "    keepDrawing(rows, drawing);\n",
                "    painted = paintBuilding(document, { drawing, art, scenery, windows, cabNode }, scene, building.elevator.level, cabGlide, painted, sky);\n",
                "    cabGlide = ride.glide_ms;\n    screen.draw(cab);",
                "        cabGlide = 0;\n        window.location.assign(ride.route);"],
            // Impl review r2 (card#7343 row 16): the page reads `cab` through a THUNK, so `lobby-screen.js`'s
            // `render()` sees the current cab only after its own awaits, never one captured stale at the
            // moment the render started — held behaviourally by `Tests\Feature\Lobby\TheDrawnCabNeverStalesTest`.
            'cab read late' => ["livePage(() => screen.render(() => cab));"],
        ];

        foreach ($wired as $act => $needles) {
            foreach ($needles as $needle) {
                if (! str_contains($js, $needle)) {
                    $defects[$act] = "the lobby does not wire the {$act} to the screen (`{$needle}` is missing)";
                }
            }
        }

        // One gesture wiring for both pages (card#7343 r1): a pointer, wheel or drag listener here is a copy.
        if (preg_match("/addEventListener\\('(wheel|pointerdown|pointermove|pointerup|pointercancel|dragstart)'/", $js, $m) === 1) {
            $defects['gestures'] = "the lobby wires a {$m[1]} of its own beside wire/camera-gestures.js";
        }

        // And one key wiring (card#7343 r2-2): a keydown listener, or a zoom step of the page's own, is a copy.
        if (preg_match("/addEventListener\\('keydown'|\\.zoomStep\\(/", $js, $m) === 1) {
            $defects['key copy'] = "the lobby wires `{$m[0]}` of its own beside wire/camera-keys.js";
        }

        return $defects;
    }

    /** The rendered page, as an MFA-satisfied session actually receives it. */
    private function lobbyPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent() ?: '';
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    /**
     * A JS source snippet with every `//` line comment blanked (the code before it kept, verbatim) — so a
     * containment check reads only executable text, never prose that names a function descriptively. This
     * module's own comments write `showLabels()` in backticks to explain what a line does; a check for
     * "does this body call `showLabels(`" must not fire on that sentence.
     */
    private function stripLineComments(string $js): string
    {
        return (string) preg_replace('#//[^\n]*#', '', $js);
    }

    /**
     * `showLabels()` is called from `view()`'s own body, and from nowhere else in `main.js` —
     * `renderBuilding()`/`paint()` write no label geometry at all (P5's own ask; `showLabels()` is the
     * ONE writer). The ONE predicate for "is the call placed correctly": the real assertion and the
     * moved-call CONTROL both run THIS, so a change to what "correct" means cannot update one and leave
     * the other silently checking a stale rule (r6 review round, r5 review m8).
     *
     * @return list<string>
     */
    private function showLabelsPlacementDefects(string $js): array
    {
        $defects = [];

        $this->assertSame(1, preg_match('/function view\(.*?\n\}\n/s', $js, $vw), 'view() did not parse out of main.js');
        $this->assertSame(1, preg_match('/function renderBuilding\(.*?\n\}\n/s', $js, $rb), 'renderBuilding() did not parse out of main.js');
        $this->assertSame(1, preg_match('/function paint\(.*?\n\}\n/s', $js, $pt), 'paint() did not parse out of main.js');

        if (! str_contains($this->stripLineComments($vw[0]), 'showLabels(')) {
            $defects[] = 'view() does not call showLabels() — a camera move would leave every label as it was';
        }

        if (str_contains($this->stripLineComments($pt[0]), 'showLabels(')) {
            $defects[] = 'paint() calls showLabels() — a second writer, even one that agrees with view()\'s, is the shape r3 M1 exists to make impossible';
        }

        if (str_contains($this->stripLineComments($rb[0]), 'showLabels(')) {
            $defects[] = 'renderBuilding() calls showLabels() — a second writer, even one that agrees with view()\'s, is the shape r3 M1 exists to make impossible';
        }

        if (str_contains($pt[0], 'setProperty')) {
            $defects[] = 'paint() writes a custom property — label geometry belongs to showLabels() alone';
        }

        if (str_contains($rb[0], 'setProperty')) {
            $defects[] = 'renderBuilding() writes a custom property — label geometry belongs to showLabels() alone';
        }

        if (str_contains($pt[0], '--label-')) {
            $defects[] = 'paint() names a --label- property directly';
        }

        if (str_contains($rb[0], '--label-')) {
            $defects[] = 'renderBuilding() names a --label- property directly';
        }

        return $defects;
    }

    /**
     * Every `lobby-*` id the page declares.
     *
     * @return list<string>
     */
    private function declaredIds(string $html): array
    {
        preg_match_all('/id="(lobby-[a-z-]+)"/', $html, $m);

        $ids = array_values(array_unique($m[1]));
        sort($ids);

        return $ids;
    }

    /**
     * Every `lobby-*` id `main.js` addresses — the literal ones its three helpers name, plus the
     * family it builds from the model's indicator keys.
     *
     * @return list<string>
     */
    private function addressedIds(string $js, ?string $moduleDir = null): array
    {
        preg_match_all("/(?:el|say|list|getElementById)\(\s*'(lobby-[a-z-]+)'/", $js, $m);
        $ids = $m[1];

        // The computed family. Its ONE site is asserted, because a second template site this
        // parser did not know about would be a set of ids nothing here checks.
        $sites = preg_match_all('/`lobby-\$\{[A-Za-z0-9_.]+\}`/', $js);

        $this->assertSame(1, $sites,
            'main.js builds element ids from a template in '.$sites.' places; this parser knows about one');

        foreach ($this->indicatorKeys($moduleDir) as $key) {
            $ids[] = 'lobby-'.$key;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The model's own indicator keys, asked of the module rather than listed here.
     *
     * @return list<string>
     */
    private function indicatorKeys(?string $moduleDir = null): array
    {
        $model = $this->probe(['snapshot' => ['fleet' => [], 'installs' => []]], $moduleDir)['model'];

        return array_column($model['indicators'], 'key');
    }

    /**
     * Ids that are legitimately not written into: the ones the page's own `aria-labelledby`
     * attributes point at, which are headings and are named by the markup itself.
     *
     * @param  list<string>  $addressed
     * @return list<string>
     */
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

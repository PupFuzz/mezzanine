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
 * ⛔ AND THE ROOM's CAMERA AND ITS RIDE (Appendix B row 24, card#12113): that the page hands each viewer act to the
 * lobby screen — *Fit the lobby*, the wheel (its pan and its Ctrl+zoom), the touch pinch and the drag through
 * `wire/camera-gestures.js`, the keys and the zoom buttons through `wire/camera-keys.js`, the keyboard's focus on the
 * elevator or a plaque — grows no copy of either shared module, writes the directory's counter-scale from the camera
 * it shows and from nowhere else (`directory.js`'s `showDirectory()`), paints the plaques on the 1 s tick, and wires the
 * drawn elevator to the screen's ride through the one performer both pages share (`wire/ride-page.js`) with the cab
 * partial under the `lobby` prefix. Each as the source's own lines, each with a control.
 *
 * ⛔ AND A FLOOR's RIDE ARRIVING HERE (§ 4.5, card#11795): the view declares `data-ride-reveals`, and the page ends
 * the layout head script's hold through `wire/ride-arrival.js`'s `revealed()` — once, on the first frame that drew the
 * room (`summary !== null`), and on nothing else.
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

        $this->assertSame([], array_values(array_diff($declared, $this->accountedFor($html, [...$addressed, ...$this->cabIds()]))),
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
     * Appendix B row 24 (card#12113): the room's camera and the elevator's ride reach the screen. The decisions are
     * `lobby-screen.js`'s and held headlessly by `Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`
     * and `TheLobbysElevatorIsTheFloorsRideTest`; what only this file can hold is that the page hands each act to the
     * screen — there is no browser on this host, so the wiring is asserted as the source's own lines.
     */
    public function test_the_page_wires_the_ride_and_the_rooms_camera_to_the_screen(): void
    {
        $js = $this->mainJs();

        $this->assertSame([], $this->cameraDefects($js));
        $this->assertSame([], $this->showDirectoryPlacementDefects($js));

        foreach ([
            // The ride: the drawn elevator's press is the screen's, through the one performer and its cab.
            'a press that never reaches the screen' => ['ride', 'roomDrawing(document, () => cab.act(screen.ride()))', 'roomDrawing(document, () => {})'],
            'a cab of the page\'s own prefix' => ['ride', "    prefix: 'lobby',\n", "    prefix: 'floor',\n"],
            'the performer never told of a frame' => ['ride', "    cab.perform({\n", "    ({\n"],
            'the cab drawn by something else' => ['ride', "    paintCab,\n", "    paintCab: () => {},\n"],
            'focus back on no elevator' => ['ride', '    elevator: () => room.control,', '    elevator: () => null,'],
            'a pageswap rule reading no frame' => ['ride', "    lastRide: () => lastFrame?.ride ?? null,", "    lastRide: () => null,"],
            'a ride arriving here never told the screen' => ['ride', "    screen.arriveByRide(rideArrival);\n", ''],
            // The room's leaves are the ride's: painted from the frame's `scene.ride` and nothing else.
            'leaves painted from no ride' => ['room drawing', 'paintRoom(document, room, frame.scene.ride, frame.sky, painted)', 'paintRoom(document, room, null, frame.sky, painted)'],
            // The house theme's elevator palette on the cab and the room's cab.
            'no palette on the cab' => ['palette', '    cabTheme(cab.dialog, palette);', '    cabTheme(cab.dialog, null);'],
            'no palette on the room\'s cab' => ['palette', '    themeRoomCab(room.cab, palette);', ''],
            // *Fit the lobby*.
            'a fit wired to nothing' => ['fit', 'screen.fitLobby()', 'screen.camera()'],
            // The gestures handed a camera that frames nothing (card#7343 r3b).
            'a wheel over no camera' => ['wheel', 'cameraGestures(building, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);',
                'cameraGestures(building, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: () => ({ bounds: null }) }, show);'],
            'keys over no camera' => ['keys', '{ zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }', '{ zoomStep: screen.zoomStep, drag: screen.drag, camera: () => ({ bounds: null }) }'],
            'keys never offered' => ['keys', "    offerKeys(building, zoomButtons, screen.camera());\n", ''],
            'keys offered off a glide step' => ['keys', '    offerKeys(building, zoomButtons, screen.camera());', '    offerKeys(building, zoomButtons, camera);'],
            'a hint never offered' => ['keys', "hint: el('lobby-hint') }", "hint: el('lobby-zoom-out') }"],
            'a fit never offered' => ['keys', "fit: el('lobby-fit'),", "fit: el('lobby-zoom-out'),"],
            'keys on another element' => ['keys', 'cameraKeys(building, ', "cameraKeys(el('lobby-floors'), "],
            // Focus-into-view: the keyboard's, on the elevator and on a plaque.
            'the elevator\'s focus brought nowhere' => ['focus', '? screen.focusElevator()', '? null'],
            'a plaque\'s focus brought nowhere' => ['focus', ": event.target.closest('#lobby-floors') !== null ? screen.focusBoard() : null;", ': null;'],
            'a press moving the camera' => ['focus', "    if (!event.target.matches(':focus-visible')) {\n        return;\n    }\n", ''],
            // The clipping surface never scrolled out from under the camera.
            'a camera over a scrolled surface' => ['scroll', "    if (framed) {\n        unscroll();\n    }\n", ''],
            // The surface's style, applied before the surface is measured.
            'a surface style never applied' => ['surface', "    Object.assign(building.style, surfaceStyle(frame.scene));\n", ''],
            'a camera sized to the old surface' => ['surface', 'view(current(camera));', 'view(current(frame.camera));'],
            // The directory: painted from the frame, on the render and on the 1 s tick.
            'plaques never painted' => ['directory', "        renderDirectory(document, el('lobby-floors'), frame.summary);\n", ''],
            'no tick' => ['tick', 'startAgeTicker(client, clock, window, () => {', 'void (client, clock, window, () => {'],
            'a tick that re-reads nothing' => ['tick', '    const frame = screen.tickView();', '    const frame = lastFrame;'],
            'a pan and zoom that never reach the camera' => ['wheel', 'pan: screen.pan,', 'pan: screen.camera,'],
        ] as $what => [$key, $from, $to]) {
            $planted = str_replace($from, $to, $js);
            $this->assertNotSame($planted, $js, "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertArrayHasKey($key, $this->cameraDefects($planted), "CONTROL ({$what}) did not bite");
        }

        // The lobby growing its own gesture or key copy back beside the shared modules.
        foreach ([
            'a pointerdown of its own' => ['gestures', "building.addEventListener('pointerdown', () => {});\n"],
            'a keydown of its own' => ['key copy', "building.addEventListener('keydown', () => {});\n"],
            'a zoom button of its own' => ['key copy', "el('lobby-zoom-in').addEventListener('click', () => {\n    show(screen.zoomStep(1));\n});\n"],
        ] as $what => [$key, $copy]) {
            $planted = str_replace('cameraKeys(building, ', $copy.'cameraKeys(building, ', $js);
            $this->assertNotSame($planted, $js);
            $this->assertArrayHasKey($key, $this->cameraDefects($planted), "CONTROL (the lobby wiring {$what}) did not bite");
        }

        // ⛔ THE COUNTER-SCALE's ONE WRITER (§ 4.1): `showDirectory()` from `view()` and from nowhere else.
        $unscaled = str_replace("    showDirectory(layer, camera);\n", '', $js);
        $this->assertNotSame($unscaled, $js);
        $this->assertNotSame([], $this->showDirectoryPlacementDefects($unscaled), 'CONTROL (showDirectory() never called from view()) did not bite');
        $moved = str_replace(["    showDirectory(layer, camera);\n", "    paintScene(frame);\n"], ['', "    paintScene(frame);\n    showDirectory(layer, frame.camera);\n"], $js);
        $this->assertNotSame($moved, $js);
        $this->assertNotSame([], $this->showDirectoryPlacementDefects($moved), 'CONTROL (showDirectory() moved into paint()) did not bite');
        $second = str_replace("    paintScene(frame);\n", "    paintScene(frame);\n    layer.style.setProperty('--lobby-zoom', '1');\n", $js);
        $this->assertNotSame($second, $js);
        $this->assertNotSame([], $this->showDirectoryPlacementDefects($second), 'CONTROL (a second writer of the counter-scale) did not bite');
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
    public function test_the_drawing_takes_focus_and_its_keys_and_zoom_buttons_are_the_floors(): void
    {
        $this->assertSame([], $this->keyMarkupDefects($this->lobbyPage()));

        $html = $this->lobbyPage();

        foreach ([
            // card#7343 comment 7692 item 1: a tab stop before any camera frames the building, which then does
            // nothing — the tab stop is `offerKeys()`'s, and a building the keyboard cannot reach is planted there.
            'a tab stop in the markup' => ['aria-label="the lobby drawing">', 'aria-label="the lobby drawing" tabindex="0">'],
            'a drawing as an image' => ['id="lobby-building" role="group"', 'id="lobby-building" role="img"'],
            // card#7343 r4b: keys named before any camera frames the room, which then do nothing.
            'keys named in the markup' => ['aria-label="the lobby drawing">', 'aria-label="the lobby drawing" aria-keyshortcuts="+ - ArrowUp ArrowDown ArrowLeft ArrowRight">'],
            'a zoom button shown in the markup' => ['id="lobby-zoom-in" hidden>', 'id="lobby-zoom-in">'],
            // card#7343 comment 7692 item 2: the framing control shown before any camera frames anything.
            'a framing control shown in the markup' => ['id="lobby-fit" hidden>', 'id="lobby-fit">'],
            // card#11045: the gesture hint shown before any camera frames anything — untrue over the flowing list.
            'a gesture hint shown in the markup' => ['id="lobby-hint" class="camera-hint" hidden>', 'id="lobby-hint" class="camera-hint">'],
            // card#7343 r3: a size or a clip in the markup holds the list in a box before and without a building.
            'a surface clipped in the markup' => ['aria-label="the lobby drawing">', 'aria-label="the lobby drawing" style="height: 70vh; overflow: hidden">'],
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

        if (preg_match('/<button type="button" id="lobby-fit"([^>]*)>/', $html, $wb) !== 1 || preg_match('/(^|\s)hidden(\s|$)/', $wb[1]) !== 1) {
            $defects[] = 'the framing control is shown in the markup, before any camera frames anything — `offerKeys()` offers it';
        }

        if (preg_match('/<p id="lobby-hint"([^>]*)>/', $html, $hint) !== 1 || preg_match('/(^|\s)hidden(\s|$)/', $hint[1]) !== 1) {
            $defects[] = 'the gesture hint is shown in the markup, before any camera frames anything — `offerKeys()` offers it';
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
    /** § 4.5 (card#11795): a floor's ride arriving here is held until the first drawn room, and only by it. */
    public function test_a_ride_arriving_here_is_revealed_on_the_first_drawn_room(): void
    {
        $this->assertSame([], $this->arrivalDefects($this->mainJs(), $this->lobbyPage()));
    }

    /** ⛔ The arrival's controls. */
    public function test_the_arrival_check_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $js = $this->mainJs();
        $html = $this->lobbyPage();

        foreach ([
            'import' => ["import { arrival, revealed } from '../wire/ride-arrival.js';\n", ''],
            'reveal' => ["        // § 4.5: a floor's ride into the lobby holds the cab's snapshot until the first drawn room, and ends it there.\n        revealed(html);\n", ''],
            'site' => ["    say('lobby-feed', `feed: \${frame.strip.feed}`);\n", "    revealed(html);\n    say('lobby-feed', `feed: \${frame.strip.feed}`);\n"],
        ] as $key => [$from, $to]) {
            $planted = str_replace($from, $to, $js);
            $this->assertNotSame($planted, $js, "the arrival's {$key} control's anchor is gone — it mutated nothing");
            $this->assertArrayHasKey($key, $this->arrivalDefects($planted, $html), "CONTROL (the arrival's {$key}) did not bite");
        }

        $undeclared = str_replace(' data-ride-reveals', '', $html);
        $this->assertNotSame($undeclared, $html);
        $this->assertArrayHasKey('declared', $this->arrivalDefects($js, $undeclared), 'CONTROL (the lobby not declaring the reveal) did not bite');
    }

    /** @return array<string, string> */
    private function arrivalDefects(string $js, string $html): array
    {
        $defects = [];
        $code = (string) preg_replace('/^\s*(\/\/|\*|\/\*\*).*$/m', '', $js);

        if (! str_contains($code, "import { arrival, revealed } from '../wire/ride-arrival.js';")) {
            $defects['import'] = 'the lobby does not import wire/ride-arrival.js';
        }

        // The room is drawn exactly when the frame carries a summary (`lobby-screen.js`'s `draw()`), so the reveal sits in
        // that branch of `paint()` — the first drawn room — and in no other.
        if (preg_match('/    if \(summary !== null\) \{\n(.*?)\n    \} else \{/s', $code, $drawn) !== 1 || ! str_contains($drawn[1], '        revealed(html);')) {
            $defects['reveal'] = 'revealed() is not called on the first drawn room (`summary !== null`) alone';
        }

        if (substr_count($code, 'revealed(') !== 1) {
            $defects['site'] = 'revealed() is called at '.substr_count($code, 'revealed(').' sites — one';
        }

        if (preg_match('/<html\b[^>]*\bdata-ride-reveals\b/', $html) !== 1) {
            $defects['declared'] = 'the lobby view does not declare data-ride-reveals on <html>';
        }

        return $defects;
    }

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
        $orphaned = str_replace('<span id="lobby-stamp">', '<span id="lobby-orphan"></span><span id="lobby-stamp">', $html);
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
        $bypassed = str_replace('livePage(() => screen.render())', 'new FleetClient(fetch, EventSource, { now: Date.now }) && livePage(() => screen.render())', $js);
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
            // § 4.5's ride: the drawn elevator's press is the screen's, through the performer both pages share, with the
            // cab partial under the lobby's prefix, the cab's drawing `cab-paint.js`'s, focus back on the drawn elevator,
            // the departing page's rule reading the last painted frame, and an arrival told to the screen.
            'ride' => ["import { ridePage } from '../wire/ride-page.js';", "import { cabTheme, loadHousePalette, paintCab } from '../floor/cab-paint.js';",
                'roomDrawing(document, () => cab.act(screen.ride()))', "    prefix: 'lobby',\n", "    paintCab,\n",
                '    elevator: () => room.control,', '    lastRide: () => lastFrame?.ride ?? null,', "    cab.perform({\n",
                "    lobby_href: el('lobby').dataset.lobbyHref,", "    screen.arriveByRide(rideArrival);\n"],
            // The room's drawing and its leaves, painted from the frame's ride.
            'room drawing' => ["import { paintRoom, roomDrawing, themeRoomCab } from './building-paint.js';", 'layer.prepend(room.art);',
                'paintRoom(document, room, frame.scene.ride, frame.sky, painted)'],
            // The house theme's elevator palette on the cab dialog and the room's cab (card#12039, card#12113).
            'palette' => ['loadHousePalette().then((palette) => {', '    cabTheme(cab.dialog, palette);', '    themeRoomCab(room.cab, palette);'],
            'fit' => ["el('lobby-fit').addEventListener('click'", 'screen.fitLobby()'],
            // The camera as it stands, so the wheel is the page's scroll while it frames nothing (card#7343 r3b); the
            // wheel's two acts and the touch pinch (card#11045) — and over the directory the wire's own scroller rule.
            'wheel' => ["import { cameraGestures } from '../wire/camera-gestures.js';", 'cameraGestures(building, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);'],
            'scroll' => ["import { framesNothing } from '../wire/camera.js';", "function view(camera) {\n    const framed = !framesNothing(camera);\n\n    if (framed) {\n        unscroll();\n    }\n",
                "building.addEventListener('scroll', unscroll);", "    building.scrollTop = 0;\n    building.scrollLeft = 0;"],
            'resize' => ['show(screen.resize(surface()))'],
            'keys' => ["import { cameraKeys, offerKeys } from '../wire/camera-keys.js';",
                "const zoomButtons = { zoomIn: el('lobby-zoom-in'), zoomOut: el('lobby-zoom-out'), fit: el('lobby-fit'), hint: el('lobby-hint') };",
                'cameraKeys(building, zoomButtons, { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }, show);',
                '    offerKeys(building, zoomButtons, screen.camera());'],
            // Focus-into-view: the keyboard's focus on the elevator or on a plaque's link, and never a press's.
            'focus' => ["building.addEventListener('focusin', (event) => {", "    if (!event.target.matches(':focus-visible')) {\n        return;\n    }\n",
                "event.target.closest('[data-key=\"elevator\"]') !== null", '? screen.focusElevator()',
                ": event.target.closest('#lobby-floors') !== null ? screen.focusBoard() : null;", 'glideTo(focus.from, focus.to, focus.glide_ms);'],
            // The surface is a clipping drawing only while the room is drawn, applied BEFORE it is measured.
            'surface' => ["import { lobbyScene, surfaceStyle } from './building-scene.js';", "    Object.assign(building.style, surfaceStyle(frame.scene));\n",
                ': screen.resize(size);', 'view(current(camera));'],
            'reduced motion' => ["reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches"],
            // The directory's plaques and boxes, `directory.js`'s; painted from the frame's summary.
            'directory' => ["import { boxStyles, renderDirectory, showDirectory } from './directory.js';",
                "        renderDirectory(document, el('lobby-floors'), frame.summary);\n", 'const styles = boxStyles(rect, drawn);'],
            // § 2.5's 1 s tick: the directory's ages re-read through the screen, nothing drained.
            'tick' => ["import { startAgeTicker } from '../wire/age-readout.js';", 'startAgeTicker(client, clock, window, () => {', '    const frame = screen.tickView();'],
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

    /**
     * ⛔ THE DIRECTORY's COUNTER-SCALE HAS ONE WRITER (§ 4.1): `showDirectory()` is called from `view()`'s own body —
     * the camera hook, called on every camera the page shows — and neither `paint()` nor `paintScene()` nor
     * `paintDirectory()` calls it or writes a `--lobby-` property of its own.
     *
     * @return list<string>
     */
    private function showDirectoryPlacementDefects(string $js): array
    {
        $defects = [];
        $code = $this->stripLineComments($js);

        $this->assertSame(1, preg_match('/function view\(.*?\n\}\n/s', $code, $vw), 'view() did not parse out of main.js');

        if (! str_contains($vw[0], 'showDirectory(layer, camera);')) {
            $defects[] = 'view() does not call showDirectory() — a camera move would leave the directory scaled for the camera before it';
        }

        foreach (['paint', 'paintScene', 'paintDirectory'] as $fn) {
            $this->assertSame(1, preg_match('/function '.$fn.'\(.*?\n\}\n/s', $code, $body), "{$fn}() did not parse out of main.js");

            if (str_contains($body[0], 'showDirectory(')) {
                $defects[] = "{$fn}() calls showDirectory() — a writer called from a render, not from the camera hook";
            }

            if (str_contains($body[0], '--lobby-')) {
                $defects[] = "{$fn}() writes a --lobby- property of its own — a second writer of the counter-scale";
            }
        }

        return $defects;
    }

    /**
     * The cab dialog's ids under the lobby's prefix — `wire/ride-page.js`'s `cabIds()`, which the performer addresses
     * for the page — read out of the performer rather than listed here.
     *
     * @return list<string>
     */
    private function cabIds(): array
    {
        preg_match_all('/`\$\{prefix\}(-cab[a-z-]*)`/', (string) file_get_contents($this->jsRoot().'/wire/ride-page.js'), $m);
        $this->assertNotSame([], $m[1], 'the performer names no cab id — the parse has stopped reading wire/ride-page.js');

        return array_map(static fn (string $suffix): string => 'lobby'.$suffix, $m[1]);
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
     * module's own comments write `showDirectory()` in backticks to explain what a line does; a check for
     * "does this body call `showDirectory(`" must not fire on that sentence.
     */
    private function stripLineComments(string $js): string
    {
        return (string) preg_replace('#//[^\n]*#', '', $js);
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

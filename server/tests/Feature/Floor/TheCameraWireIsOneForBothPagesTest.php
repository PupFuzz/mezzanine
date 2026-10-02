<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The camera's page wire — one for the floor and the lobby.** `docs/design/FLOOR.md` Appendix B rows 15
 * and 16 (card#7343 r1, r2): `wire/camera-gestures.js`, the wheel, the drag and the touch pinch, and `wire/camera-keys.js`,
 * the keyboard and the zoom buttons, which both pages wire to their screen's camera acts; and
 * `wire/camera-view.js`, how each page shows the camera — at once, or as a glide, and for the lobby's ride
 * a COMMITTED glide.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SHIPPED MODULES, UNDER `node`, WITH STAND-IN ELEMENTS AND A STUBBED FRAME CLOCK
 * (`camera-wire-probe.mjs`). These files were exercised by nothing until this: the gestures and the keys
 * lived inline in the floor page's DOM entry (and the gestures in the lobby's too), checked only as source
 * lines by the pages' wiring tests, and the glide was the browser's frame clock. Each defect below is
 * planted in the shipped file and watched red — the floor's own keyboard and zoom-button checks among
 * them, moved here with the code they check (card#7343 r2-2).
 *
 * ⛔ THE WHEEL CHOOSES BY `ctrlKey`, AND TWO FINGERS PINCH (§ 4.5, the operator's ruling of 2026-10-01 on
 * card#11045): a plain wheel is the screen's `pan()` with its `deltaX`, `deltaY` and `deltaMode`; a
 * Ctrl+wheel its `zoom()` about the cursor with its `deltaY` and `deltaMode`; either taken from the page. A
 * second finger down while a press is held pinches — each move a `pinch()` about the fingers' midpoint, both
 * pointers captured, the gesture no click — and when one lifts the other drags on. A plain wheel the
 * screen's pan answers it did not consume — the camera at its edge (Q3) — is left to the page, untouched.
 * Safari's trackpad pinch, its own `gesture*` events (card#11045 PR-C), is a `pinch()` about the cursor by
 * each scale over the last, taken from the page so Safari sends no Ctrl+wheel after it; a touch pinch iOS
 * also reports as gesture events zooms once, by its pointers; and a lobby mid-ride takes the gesture as it
 * takes the wheel. Each rule is planted out in the shipped module and watched red.
 *
 * ⛔ NOTHING FRAMED, NOTHING TAKEN (card#7343 r4b, the seat's ruling, widening r3b's on the wheel): over a
 * drawing whose camera frames nothing — the uncomposed lobby, whose list flows in the page — every event is
 * left to the browser: the wheel, a `dragstart`, a press and its `user-select`, a second finger's pinch, the move's capture and pan,
 * the click after a drag, and the arrow and zoom keys, a capture a pan took being released at the move;
 * and nothing of the camera is offered — the zoom buttons, the page's framing control and its gesture hint hidden, the
 * drawing no tab stop and naming no `aria-keyshortcuts` — with an unchanged offer written nowhere
 * (`offerKeys()`; card#7343 comment 7692). Each gate is planted out alone and watched red on its own
 * step of `unframedDefects()`, and planted always-shut and watched red on the framed checks, which are the
 * controls that a framed drawing keeps every behaviour.
 *
 * ⛔ A PRESS NEVER DRAGS AN ELEMENT OUT AND NEVER SELECTS TEXT (card#7343 r2-3): a `dragstart` in the
 * drawing is refused, and the drawing is `user-select: none` from a primary press until it ends — and
 * selectable again after, however it ended.
 *
 * ⛔ THE CLICK COMMITS THE RIDE (card#7343 r1 ruling). A committed glide interrupted — by a `show()`, which
 * is what a wheel, a pinch, a key, a zoom button, a drag and a resize do, or by another `glideTo()`, which is the
 * whole-building control — cuts to its destination and runs its arrival; an
 * uncommitted one — the floor's fit, the lobby's whole-building — stops where it is, as the floor's did
 * before. The model's half — the ride held in flight until its glide arrives, a second one refused — is
 * `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that a browser delivers these events to these listeners, honours
 * `user-select` (or its WebKit-prefixed form), or paints what is applied — card#11045 PR-B drove the pages
 * in headless Chromium by hand, and card#11045 PR-C dispatched synthetic `gesture*` events in a headless
 * browser — never a real trackpad, touch screen, Mac Safari or iPhone; nor that Safari, as WebKit's own
 * layout test holds, sends no Ctrl+wheel after a prevented gesture event; the pages' wiring tests
 * hold that each page hands its drawing, its zoom buttons and its screen's acts to `cameraGestures()` and
 * `cameraKeys()`, and grows no copy of either beside them.
 */
class TheCameraWireIsOneForBothPagesTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/wire')
            ?: $this->fail('server/public/js/wire does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/camera-wire-probe.mjs';
    }

    // ── The glide ──────────────────────────────────────────────────────────────────────────────

    public function test_green_a_committed_glide_interrupted_cuts_to_the_plate_and_arrives(): void
    {
        $this->assertSame([], $this->committedDefects());
    }

    /** The floor's fit and the lobby's whole-building glide: interrupted, they stop — and arrive nowhere. */
    public function test_green_an_uncommitted_glide_interrupted_stops_where_it_is(): void
    {
        $log = $this->glideLog([['op' => 'glide', 'ms' => 850], ['op' => 'frame', 'ms' => 100], ['op' => 'show'], ['op' => 'frame', 'ms' => 1000]]);

        $this->assertSame([['applied' => 'from'], ['applied' => 'between'], ['applied' => 'other']], $log);
    }

    /** A committed glide that runs its length arrives once, and a committed cut (reduced motion) arrives at once. */
    public function test_green_a_committed_glide_arrives_once_whether_it_glides_or_cuts(): void
    {
        $glided = $this->glideLog([['op' => 'glide', 'ms' => 850, 'commit' => true], ['op' => 'frame', 'ms' => 400], ['op' => 'frame', 'ms' => 500], ['op' => 'frame', 'ms' => 500]]);

        $this->assertSame([['applied' => 'from'], ['applied' => 'between'], ['applied' => 'to'], ['done' => 'to']], $glided);

        $cut = $this->glideLog([['op' => 'glide', 'ms' => 0, 'commit' => true]]);

        $this->assertSame([['applied' => 'from'], ['applied' => 'to'], ['done' => 'to']], $cut);
    }

    /** A committed glide abandoned by an interruption, as every glide was before the ruling. */
    public function test_red_a_committed_glide_an_interruption_abandons(): void
    {
        $dir = $this->mutatedModules(['camera-view.js', 'if (!stopped.commit) {', 'if (true) {']);

        $this->assertNotSame([], $this->committedDefects($dir), 'CONTROL (a committed glide abandoned) did not bite');
    }

    // ── The gestures ───────────────────────────────────────────────────────────────────────────

    public function test_green_the_gestures_reach_the_acts_as_both_pages_need_them(): void
    {
        $this->assertSame([], $this->gestureDefects());
    }

    /**
     * Each defect the pages' wiring tests planted in the inline copies, now planted in the one module —
     * plus the two halves of the one click policy.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function gesturePlants(): array
    {
        return [
            'a drag no pointercancel ends' => ['camera-gestures.js', "element.addEventListener('pointercancel'", "element.addEventListener('pointerleave'"],
            // card#11045: a capture revoked with no pointerup or pointercancel ends the press — and only a press
            // still holding that pointer.
            'a press a lost capture leaves held' => ['camera-gestures.js', "element.addEventListener('lostpointercapture'", "element.addEventListener('lostpointercapture-unheard'"],
            'a lost capture that ends whatever press is held' => ['camera-gestures.js', 'if (drag !== null && (event.pointerId === drag.id || event.pointerId === pinch?.id)) {', 'if (drag !== null) {'],
            'a lost capture that forgets the drag was no click' => ['camera-gestures.js', "            release();\n        }\n    });", "            release();\n        }\n        dragged = false;\n    });"],
            'a drag a buttonless move does not end' => ['camera-gestures.js', 'if (drag === null || (event.buttons & 1) === 0) {', 'if (drag === null) {'],
            'a drag any pointer and button starts' => ['camera-gestures.js', ': !event.isPrimary || event.button !== 0) {', ': false) {'],
            'a press that pans before it has moved' => ['camera-gestures.js', 'Math.hypot(dx, dy) < DRAG_SLOP_PX', 'Math.hypot(dx, dy) < 0'],
            // card#11045: the plain wheel pans and the Ctrl+wheel zooms — each with its own members, about the drawing.
            'a plain wheel that zooms' => ['camera-gestures.js', 'const { camera, consumed } = event.ctrlKey', 'const { camera, consumed } = true'],
            'a Ctrl+wheel (a trackpad pinch) that pans' => ['camera-gestures.js', 'const { camera, consumed } = event.ctrlKey', 'const { camera, consumed } = false'],
            'a pan whose deltaX never reaches the camera' => ['camera-gestures.js', 'acts.pan({ deltaX: event.deltaX, deltaY: event.deltaY, deltaMode: event.deltaMode })', 'acts.pan({ deltaY: event.deltaY, deltaMode: event.deltaMode })'],
            'a pan whose deltaMode never reaches the camera' => ['camera-gestures.js', 'acts.pan({ deltaX: event.deltaX, deltaY: event.deltaY, deltaMode: event.deltaMode })', 'acts.pan({ deltaX: event.deltaX, deltaY: event.deltaY })'],
            'a zoom whose deltaMode never reaches the camera' => ['camera-gestures.js', 'acts.zoom(local(event.clientX, event.clientY), { deltaY: event.deltaY, deltaMode: event.deltaMode })', 'acts.zoom(local(event.clientX, event.clientY), { deltaY: event.deltaY })'],
            'a zoom about the page, not the drawing' => ['camera-gestures.js', 'acts.zoom(local(event.clientX, event.clientY)', 'acts.zoom({ x: event.clientX, y: event.clientY }'],
            'a wheel that also scrolls the page' => ['camera-gestures.js', "        event.preventDefault();\n        show(camera);", '        show(camera);'],
            // card#11045: the touch pinch.
            'a second finger that never pinches' => ['camera-gestures.js', 'if (second ? pinch !== null || event.button !== 0 :', 'if (second ? true :'],
            'a pinch that zooms by nothing' => ['camera-gestures.js', 'was.spread > 0 ? pinch.spread / was.spread : 1', '1'],
            'a pinch about its first finger, not its midpoint' => ['camera-gestures.js', 'local((a.x + b.x) / 2, (a.y + b.y) / 2)', 'local(a.x, a.y)'],
            'a pinch that is a click' => ['camera-gestures.js', "            drag.moved = true;\n            capture(drag.id);\n", "            capture(drag.id);\n"],
            'a pinch that leaves its first finger uncaptured' => ['camera-gestures.js', "            capture(drag.id);\n            capture(event.pointerId);\n", "            capture(event.pointerId);\n"],
            'a lifted finger that ends the other one\'s drag' => ['camera-gestures.js', 'if (pinch !== null && (event.pointerId === drag.id || event.pointerId === pinch.id)) {', 'if (false) {'],
            'a move of a pointer that is not the press, panning' => ['camera-gestures.js', "        if (finger === null) {\n            return;\n        }\n", ''],
            // card#11045 PR-C: Safari's trackpad pinch, and one touch pinch zooming once.
            'a Safari pinch that never reaches the camera' => ['camera-gestures.js', "for (const type of ['gesturestart', 'gesturechange', 'gestureend']) {", 'for (const type of []) {'],
            'a Safari pinch by its cumulative scale' => ['camera-gestures.js', 'event.scale / was.scale', 'event.scale'],
            'a Safari pinch about the page, not the drawing' => ['camera-gestures.js', 'const at = local(event.clientX, event.clientY);', 'const at = { x: event.clientX, y: event.clientY };'],
            'a Safari pinch left to the page zoom and its Ctrl+wheel' => ['camera-gestures.js', "        event.preventDefault();\n\n        // A press held is a touch screen's finger", "\n        // A press held is a touch screen's finger"],
            'a Safari pinch whose end zooms nothing' => ['camera-gestures.js', 'if (drag !== null || was === null) {', "if (drag !== null || was === null || event.type === 'gestureend') {"],
            'a touch pinch zoomed twice' => ['camera-gestures.js', 'if (drag !== null || was === null) {', 'if (was === null) {'],
            'a drag that follows the plate link it ended over' => ['camera-gestures.js', "        event.preventDefault();\n        event.stopPropagation();", '        event.stopPropagation();'],
            'a drag that selects the desk it ended over' => ['camera-gestures.js', "        event.preventDefault();\n        event.stopPropagation();", '        event.preventDefault();'],
            // card#7343 r2-3: a pan started on a plate's link is no native drag of it, and a pan selects no text.
            'a pan that drags the link it started on out' => ['camera-gestures.js', "        event.preventDefault();\n    });\n\n    element.addEventListener('wheel'", "    });\n\n    element.addEventListener('wheel'"],
            'a pan that selects the text it crosses' => ['camera-gestures.js', "        element.style.userSelect = 'none';\n", ''],
            'a press that leaves the text unselectable for good' => ['camera-gestures.js', "        element.style.userSelect = '';\n", ''],
            // card#7343 r3: the prefixed property WebKit engines read, set and reset beside the standard one.
            'a pan that selects the text it crosses on WebKit' => ['camera-gestures.js', "        element.style.webkitUserSelect = 'none';\n", ''],
            'a press that leaves the text unselectable for good on WebKit' => ['camera-gestures.js', "        element.style.webkitUserSelect = '';\n", ''],
        ];
    }

    #[DataProvider('gesturePlants')]
    public function test_red_each_gesture_defect_planted_in_the_one_module(string $file, string $anchor, string $replacement): void
    {
        $dir = $this->mutatedModules([$file, $anchor, $replacement]);

        $this->assertNotSame([], $this->gestureDefects($dir), 'the planted gesture defect did not bite');
    }

    // ── The edge (card#11045 Q3) ───────────────────────────────────────────────────────────────

    /**
     * A plain wheel the screen's pan did not consume — the camera at its edge — is the page's: no camera
     * shown and the default untouched, so the page scrolls on; a Ctrl+wheel there is still the zoom's.
     */
    public function test_green_a_wheel_the_pan_released_is_the_pages_scroll(): void
    {
        $this->assertSame([], $this->releasedDefects());
    }

    /** The wheel taken from the page whatever the pan answered — the behaviour before Q3 was ruled. */
    public function test_red_a_released_wheel_kept_from_the_page(): void
    {
        $dir = $this->mutatedModules(['camera-gestures.js', "        if (!consumed) {\n            return;\n        }\n\n", '']);

        $this->assertNotSame([], $this->releasedDefects($dir), 'CONTROL (a released wheel kept from the page) did not bite');
    }

    /** @return list<string> */
    private function releasedDefects(?string $dir = null): array
    {
        $wheel = static fn (bool $ctrl): array => ['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaX' => 0, 'deltaY' => 120, 'deltaMode' => 0, 'ctrlKey' => $ctrl];
        $expected = [
            ['event' => 'wheel', 'default_prevented' => false, 'propagation_stopped' => false, 'user_select' => '', 'webkit_user_select' => ''],
            ['show' => ['act' => 'zoom', 'point' => ['x' => 100, 'y' => 200], 'delta' => ['deltaY' => 120, 'deltaMode' => 0]]],
            ['event' => 'wheel', 'default_prevented' => true, 'propagation_stopped' => false, 'user_select' => '', 'webkit_user_select' => ''],
        ];

        return $this->logDefects($this->probe(['gestures' => [$wheel(false), $wheel(true)], 'pan_consumed' => false], $dir)['log'], $expected, 'released wheel');
    }

    // ── A lobby mid-ride (card#11045 PR-C) ─────────────────────────────────────────────────────

    /**
     * Over a lobby mid-ride the screen's acts hold the camera (`lobby/lobby-screen.js`'s ride-hold): Safari's
     * pinch is taken from the page as the wheel is — no page zoom and no Ctrl+wheel after it — and shows the
     * camera the act answers, whatever it is. The model's half, the camera held, is
     * `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s.
     */
    public function test_green_a_safari_pinch_over_a_lobby_mid_ride_is_the_rides(): void
    {
        $this->assertSame([], $this->ridingDefects());
    }

    /** The gesture left to the page — Safari's page zoom over a ride, and a Ctrl+wheel after it. */
    public function test_red_a_safari_pinch_mid_ride_left_to_the_page(): void
    {
        $dir = $this->mutatedModules(['camera-gestures.js', "        event.preventDefault();\n\n        // A press held is a touch screen's finger", "\n        // A press held is a touch screen's finger"]);

        $this->assertNotSame([], $this->ridingDefects($dir), 'CONTROL (a Safari pinch mid-ride left to the page) did not bite');
    }

    /** @return list<string> */
    private function ridingDefects(?string $dir = null): array
    {
        $taken = static fn (string $type): array => ['event' => $type, 'default_prevented' => true, 'propagation_stopped' => false, 'user_select' => '', 'webkit_user_select' => ''];
        $held = ['show' => ['act' => 'held']];
        $events = [
            ['type' => 'gesturestart', 'scale' => 1, 'clientX' => 110, 'clientY' => 220],
            ['type' => 'gesturechange', 'scale' => 2, 'clientX' => 110, 'clientY' => 220],
            ['type' => 'gestureend', 'scale' => 2, 'clientX' => 110, 'clientY' => 220],
            ['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaX' => 0, 'deltaY' => -120, 'deltaMode' => 0, 'ctrlKey' => true],
        ];
        $expected = [$taken('gesturestart'), $held, $taken('gesturechange'), $held, $taken('gestureend'), $held, $taken('wheel')];

        return $this->logDefects($this->probe(['gestures' => $events, 'riding' => true], $dir)['log'], $expected, 'riding');
    }

    // ── The framing gate ───────────────────────────────────────────────────────────────────────

    /**
     * card#7343 r4b (the seat's ruling): a camera that frames nothing leaves every event to the browser.
     * The framed half — a framed drawing keeping every behaviour — is `gestureDefects()` and `keyDefects()`.
     */
    public function test_green_over_a_drawing_that_frames_nothing_every_event_is_the_browsers(): void
    {
        $this->assertSame([], $this->unframedDefects());
    }

    /**
     * Each gate, by the comment that heads it in the shipped module, and the step of `unframedDefects()` its
     * absence must red on.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function gates(): array
    {
        return [
            'the wheel' => ['camera-gestures.js', "        // Gate: nothing framed — the wheel is the page's scroll.\n", 'if (unframed()) {', 'wheel'],
            'the dragstart' => ['camera-gestures.js', "        // Gate: nothing framed — a link in the flowing list drags as any link does.\n", 'if (unframed()) {', 'dragstart'],
            'Safari\'s gesture' => ['camera-gestures.js', "        // Gate: nothing framed — the gesture is the browser's, and so is the Ctrl+wheel Safari sends after it.\n", 'if (unframed()) {', 'gesture'],
            'the press' => ['camera-gestures.js', "        // Gate: nothing framed — a press selects text as ever, and no drag or pinch starts.\n", 'if (unframed()) {', 'press'],
            'the move' => ['camera-gestures.js', "        // Gate: nothing framed any more — the press ends here, uncaptured, and pans nothing.\n", 'if (unframed()) {', 'move'],
            'the click after a drag' => ['camera-gestures.js', "        // Gate: nothing framed any more — the click is the browser's. It follows the link it is on when the\n"
                ."        // press was never captured or moved again once nothing was framed (the move gate released it); a\n"
                ."        // press released still captured — it panned, and never moved after the frame went — clicks the\n"
                ."        // drawing, which follows nothing.\n", 'if (unframed()) {', 'click'],
            'the keys' => ['camera-keys.js', "        // Gate: nothing framed — every key is the browser's (see the header).\n", 'if (framesNothing(acts.camera())) {', 'keys'],
        ];
    }

    /** A gate planted OUT: that event is taken over a drawing that frames nothing — red on its own step, and only there. */
    #[DataProvider('gates')]
    public function test_red_each_gate_planted_out_takes_its_event_from_an_unframed_page(string $file, string $comment, string $guard, string $step): void
    {
        $dir = $this->mutatedModules([$file, $this->gateBlock($file, $comment, $guard), '']);
        $defects = $this->unframedDefects($dir);

        $this->assertNotSame([], $defects, "CONTROL (the {$step} gate planted out) did not bite");
        $this->assertSame([], array_filter($defects, static fn (string $d): bool => ! str_starts_with($d, "[{$step}]")),
            "the {$step} gate planted out bit a step not its own: ".json_encode($defects));
        $this->assertSame([], [...$this->gestureDefects($dir), ...$this->keyDefects($dir)], "the {$step} gate planted out changed a framed drawing too");
    }

    /** A gate planted SHUT: the framed drawing loses that event to the browser — the framed checks, as controls, red. */
    #[DataProvider('gates')]
    public function test_red_each_gate_planted_shut_leaves_a_framed_drawings_event_to_the_page(string $file, string $comment, string $guard, string $step): void
    {
        $dir = $this->mutatedModules([$file, $comment.'        '.$guard, $comment.'        if (true) {']);

        $this->assertNotSame([], [...$this->gestureDefects($dir), ...$this->keyDefects($dir)], "CONTROL (the {$step} gate planted shut) did not bite");
    }

    /**
     * card#7343 c7692 item 3: a press that panned keeps no capture once nothing is framed — its release and
     * its click would otherwise land on the drawing, not on the link under the pointer.
     */
    public function test_red_a_capture_kept_once_nothing_is_framed(): void
    {
        $dir = $this->mutatedModules(['camera-gestures.js', "            for (const id of drag.captured) {\n                element.releasePointerCapture(id);\n            }\n", '']);
        $defects = $this->unframedDefects($dir);

        $this->assertNotSame([], $defects, 'CONTROL (a capture kept past the frame) did not bite');
        $this->assertSame([], array_filter($defects, static fn (string $d): bool => ! str_starts_with($d, '[move]')),
            'a capture kept past the frame bit a step not the move gate\'s: '.json_encode($defects));
    }

    /** The one predicate, read backwards either way: every framed check reds, or every unframed one does. */
    public function test_red_the_one_predicate_planted_either_way(): void
    {
        $never = $this->mutatedModules(['camera.js', "    return camera.bounds === null;\n}", "    return false;\n}"]);
        $this->assertNotSame([], $this->unframedDefects($never), 'CONTROL (a camera that never frames nothing) did not bite');

        $always = $this->mutatedModules(['camera.js', "    return camera.bounds === null;\n}", "    return true;\n}"]);
        $this->assertNotSame([], [...$this->gestureDefects($always), ...$this->keyDefects($always)], 'CONTROL (a camera that always frames nothing) did not bite');
    }

    /** card#7343 r4b: the zoom buttons and `aria-keyshortcuts` are offered while the camera frames something, and only then. */
    public function test_green_the_keys_are_offered_only_while_the_camera_frames_something(): void
    {
        $this->assertSame([], $this->offerDefects());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function offerPlants(): array
    {
        return [
            'a zoom-in button never shown' => ["    buttons.zoomIn.hidden = !offered;\n", ''],
            'a zoom-out button never hidden again' => ['    buttons.zoomOut.hidden = !offered;', '    buttons.zoomOut.hidden = false;'],
            'keys never named' => ["        element.setAttribute('aria-keyshortcuts', KEY_SHORTCUTS);\n", ''],
            'keys named after the camera stopped framing' => ["        element.removeAttribute('aria-keyshortcuts');\n", ''],
            'an arrow key the drawing does not name' => ["['+', '-', ...Object.keys(KEY_PAN)]", "['+', '-', 'ArrowLeft']"],
            'a key named that no handler takes' => ["['+', '-', ...Object.keys(KEY_PAN)]", "['+', '-', 'Home', ...Object.keys(KEY_PAN)]"],
            'offered backwards' => ['    const offered = !framesNothing(camera);', '    const offered = framesNothing(camera);'],
            // card#7343 c7692 item 1 — moved here from the pages' markup: the drawing's tab stop is offered.
            'a drawing the keyboard cannot reach' => ["        element.setAttribute('tabindex', '0');\n", ''],
            'a tab stop left for a camera that frames nothing' => ["        element.removeAttribute('tabindex');\n", ''],
            // card#7343 c7692 item 2: the framing control withdrawn with the zoom buttons.
            'a framing control shown while nothing is framed' => ['    buttons.fit.hidden = !offered;', '    buttons.fit.hidden = false;'],
            // card#11045: the gesture hint withdrawn with them, and offered with them.
            'a gesture hint shown while nothing is framed' => ['    buttons.hint.hidden = !offered;', '    buttons.hint.hidden = false;'],
            'a gesture hint never shown' => ["    buttons.hint.hidden = !offered;\n", ''],
            // card#7343 c7692 item 4: an unchanged offer written again on every camera shown.
            'an offer written on every camera' => ["    if (element.hasAttribute('aria-keyshortcuts') === offered) {\n        return;\n    }\n", ''],
        ];
    }

    #[DataProvider('offerPlants')]
    public function test_red_each_offer_defect(string $anchor, string $replacement): void
    {
        $dir = $this->mutatedModules(['camera-keys.js', $anchor, $replacement]);

        $this->assertNotSame([], $this->offerDefects($dir), 'the planted offer defect did not bite');
    }

    // ── The keys and the zoom buttons ───────────────────────────────────────────────────────────

    public function test_green_the_keys_and_the_zoom_buttons_reach_the_acts_as_both_pages_need_them(): void
    {
        $this->assertSame([], $this->keyDefects());
    }

    /**
     * The floor page's keyboard and zoom-button checks, which planted their defects in its inline copy
     * (`FloorPageWiringTest`), planted in the one module both pages now call.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function keyPlants(): array
    {
        return [
            'a keyboard that never reaches the camera' => ['camera-keys.js', "element.addEventListener('keydown'", "element.addEventListener('keyup'"],
            'a key with a modifier taken from the browser' => ['camera-keys.js', 'if (event.ctrlKey || event.metaKey || event.altKey) {', 'if (false) {'],
            'an arrow key that pans the wrong way' => ['camera-keys.js', 'ArrowLeft: [PAN_STEP_PX, 0],', 'ArrowLeft: [-PAN_STEP_PX, 0],'],
            'a zoom key without `=`' => ['camera-keys.js', "'+': 1, '=': 1,", "'+': 1,"],
            'a zoom key the page also scrolls or zooms on' => ['camera-keys.js', "            event.preventDefault();\n            show(acts.zoomStep(KEY_ZOOM[event.key]));", '            show(acts.zoomStep(KEY_ZOOM[event.key]));'],
            'a zoom-in button that never reaches the camera' => ['camera-keys.js', "buttons.zoomIn.addEventListener('click', () => {\n        show(acts.zoomStep(1));", "buttons.zoomIn.addEventListener('click', () => {\n        show(acts.zoomStep(0));"],
            'a zoom-out button that zooms in' => ['camera-keys.js', "buttons.zoomOut.addEventListener('click', () => {\n        show(acts.zoomStep(-1));", "buttons.zoomOut.addEventListener('click', () => {\n        show(acts.zoomStep(1));"],
        ];
    }

    #[DataProvider('keyPlants')]
    public function test_red_each_key_defect_planted_in_the_one_module(string $file, string $anchor, string $replacement): void
    {
        $dir = $this->mutatedModules([$file, $anchor, $replacement]);

        $this->assertNotSame([], $this->keyDefects($dir), 'the planted key defect did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, string>>
     */
    private function glideLog(array $ops, ?string $dir = null): array
    {
        return $this->probe(['view' => $ops], $dir)['log'];
    }

    /**
     * A committed glide interrupted mid-flight by a `show()` and by another glide: each time the plate is
     * applied last, the arrival runs exactly once, and nothing the interruption asked for is shown.
     *
     * @return list<string>
     */
    private function committedDefects(?string $dir = null): array
    {
        $defects = [];
        $interruptions = [
            'a show (a wheel, a drag or a resize)' => ['op' => 'show'],
            'another glide (the whole-building control)' => ['op' => 'glide_other', 'ms' => 850],
        ];

        foreach ($interruptions as $what => $op) {
            $log = $this->glideLog([['op' => 'glide', 'ms' => 850, 'commit' => true], ['op' => 'frame', 'ms' => 100], $op, ['op' => 'frame', 'ms' => 1000]], $dir);
            $expected = [['applied' => 'from'], ['applied' => 'between'], ['applied' => 'to'], ['done' => 'to']];

            if ($log !== $expected) {
                $defects[] = "a committed glide interrupted by {$what} did not cut to the plate and arrive once: ".json_encode($log);
            }
        }

        return $defects;
    }

    /**
     * One scripted pass through every gesture rule: each step is an event and what must follow it —
     * the acts shown and the captures taken, then whether the event had its default prevented and its
     * propagation stopped.
     *
     * @return list<string>
     */
    private function gestureDefects(?string $dir = null): array
    {
        $down = static fn (int $x, bool $primary = true, int $button = 0, int $id = 7): array => ['type' => 'pointerdown', 'isPrimary' => $primary, 'button' => $button, 'clientX' => $x, 'clientY' => 0, 'pointerId' => $id];
        $move = static fn (int $x, int $y = 0, int $buttons = 1, int $id = 7): array => ['type' => 'pointermove', 'buttons' => $buttons, 'clientX' => $x, 'clientY' => $y, 'pointerId' => $id];
        $up = static fn (int $id = 7): array => ['type' => 'pointerup', 'pointerId' => $id];
        $lost = static fn (int $id): array => ['type' => 'lostpointercapture', 'pointerId' => $id];
        $drag = static fn (int $dx, int $dy): array => ['show' => ['act' => 'drag', 'dx' => $dx, 'dy' => $dy]];
        $wheel = static fn (bool $ctrl, float $dx, float $dy, int $mode): array => ['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaX' => $dx, 'deltaY' => $dy, 'deltaMode' => $mode, 'ctrlKey' => $ctrl];
        $gesture = static fn (string $phase, float $scale, int $x = 110, int $y = 220): array => ['type' => "gesture{$phase}", 'scale' => $scale, 'clientX' => $x, 'clientY' => $y];
        $safari = static fn (array $from, array $to, int|float $factor): array => ['show' => ['act' => 'pinch', 'from' => $from, 'to' => $to, 'factor' => $factor]];

        // [event, what it produces, default prevented, propagation stopped, the drawing's user-select after it —
        // the standard property and the WebKit-prefixed one alike]
        $steps = [
            // A plain wheel — a mouse's notch, a trackpad's two-finger scroll — pans, with both its deltas and its
            // mode, and is not the page's scroll (card#11045).
            [$wheel(false, -30, 120, 1), [['show' => ['act' => 'pan', 'delta' => ['deltaX' => -30, 'deltaY' => 120, 'deltaMode' => 1]]]], true, false, ''],
            // A Ctrl+wheel — a mouse's Ctrl+notch, or a trackpad's pinch — zooms about the cursor on the drawing,
            // with its mode, and is not the browser's page zoom.
            [$wheel(true, 0, -120, 1), [['show' => ['act' => 'zoom', 'point' => ['x' => 100, 'y' => 200], 'delta' => ['deltaY' => -120, 'deltaMode' => 1]]]], true, false, ''],
            // Nothing inside the drawing is dragged out of it — a link a pan starts on least of all.
            [['type' => 'dragstart'], [], true, false, ''],
            // A secondary button never drags, and never holds the text either.
            [$down(0, true, 2), [], false, false, ''],
            [$move(50, 0, 3), [], false, false, ''],
            [$up(), [], false, false, ''],
            // Nor does a pointer that is not the primary one.
            [$down(0, false), [], false, false, ''],
            [$move(50), [], false, false, ''],
            [$up(), [], false, false, ''],
            // A press is a click until it moves the slop; then the pointer is captured and each move pans.
            // From the press until it ends, the drawing's text is not selectable.
            [$down(0), [], false, false, 'none'],
            [$move(3), [], false, false, 'none'],
            [['type' => 'dragstart'], [], true, false, 'none'],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false, 'none'],
            [$move(15, 5), [$drag(5, 5)], false, false, 'none'],
            // Another pointer's move — a pen hovering, say — moves nothing.
            [$move(90, 0, 1, 9), [], false, false, 'none'],
            [$up(), [], false, false, ''],
            // The capture the release lets go of is lost after it (card#11045): that ends nothing twice, and the
            // click below is still the drag's.
            [$lost(7), [], false, false, ''],
            // A drag that moved is no click — neither its handlers nor its default action — and only once.
            [['type' => 'click'], [], true, true, ''],
            [['type' => 'click'], [], false, false, ''],
            // A press that never moved the slop is a click.
            [$down(0), [], false, false, 'none'],
            [$move(2), [], false, false, 'none'],
            [$up(), [], false, false, ''],
            [['type' => 'click'], [], false, false, ''],
            // A pointer the browser took back ends the drag, and is no click either.
            [$down(0), [], false, false, 'none'],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false, 'none'],
            [['type' => 'pointercancel', 'pointerId' => 7], [], false, false, ''],
            [$move(30), [], false, false, ''],
            [['type' => 'click'], [], false, false, ''],
            // A capture the browser revoked with neither a pointerup nor a pointercancel ends the press too
            // (card#11045): the next touch, under a new pointer id, is a press of its own — never the stale
            // press's pinch second finger, which would capture both and zoom.
            [$down(0), [], false, false, 'none'],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false, 'none'],
            [$lost(7), [], false, false, ''],
            [$down(50, true, 0, 12), [], false, false, 'none'],
            [$move(52, 0, 1, 12), [], false, false, 'none'],
            [$up(12), [], false, false, ''],
            // A move with the primary button no longer held ends the drag (released outside the drawing).
            [$down(0), [], false, false, 'none'],
            [$move(20, 0, 0), [], false, false, ''],
            [$move(40), [], false, false, ''],
            // Two fingers (card#11045): the second down while the first is pressed — both captured — and each
            // move a pinch: the spread 100 → 200 zooms by 2, its midpoint (50, 0) → (100, 0) on the page,
            // (40, -20) → (90, -20) on the drawing at (10, 20).
            [$down(0), [], false, false, 'none'],
            [$down(100, false, 0, 8), [['capture' => 7], ['capture' => 8]], false, false, 'none'],
            [$move(200, 0, 1, 8), [['show' => ['act' => 'pinch', 'from' => ['x' => 40, 'y' => -20], 'to' => ['x' => 90, 'y' => -20], 'factor' => 2]]], false, false, 'none'],
            // A third finger is ignored.
            [$down(300, false, 0, 9), [], false, false, 'none'],
            // The first finger lifts: the second drags on from where it is — and the lifted finger's capture,
            // lost after its pointerup, ends nothing.
            [$up(7), [], false, false, 'none'],
            [$lost(7), [], false, false, 'none'],
            [$move(210, 0, 1, 8), [$drag(10, 0)], false, false, 'none'],
            [$up(8), [], false, false, ''],
            // A pinch is no click.
            [['type' => 'click'], [], true, true, ''],
            // A pinch whose second finger lifts first, and whose first then lifts without moving: still no click.
            [$down(0), [], false, false, 'none'],
            [$down(100, false, 0, 8), [['capture' => 7], ['capture' => 8]], false, false, 'none'],
            [$move(50, 0, 1, 8), [['show' => ['act' => 'pinch', 'from' => ['x' => 40, 'y' => -20], 'to' => ['x' => 15, 'y' => -20], 'factor' => 0.5]]], false, false, 'none'],
            [$up(8), [], false, false, 'none'],
            [$up(7), [], false, false, ''],
            [['type' => 'click'], [], true, true, ''],
            // Safari's trackpad pinch (card#11045 PR-C): its own gesture events, each with the CUMULATIVE scale.
            // Every one is taken from the page — its page zoom, and the Ctrl+wheel Safari sends after a gesture
            // event left unprevented, which would zoom the pinch a second time. The start zooms nothing; each
            // change, and the end, is a pinch about the cursor on the drawing (110, 220) → (100, 200), by its
            // scale over the last one's: 1 → 1.5 → 3 → 1.5 is 1.5, 2, then 0.5.
            [$gesture('start', 1), [], true, false, ''],
            [$gesture('change', 1.5), [$safari(['x' => 100, 'y' => 200], ['x' => 100, 'y' => 200], 1.5)], true, false, ''],
            [$gesture('change', 3), [$safari(['x' => 100, 'y' => 200], ['x' => 100, 'y' => 200], 2)], true, false, ''],
            [$gesture('end', 1.5), [$safari(['x' => 100, 'y' => 200], ['x' => 100, 'y' => 200], 0.5)], true, false, ''],
            // A second gestureend (WebKit bug 233137) has no scale to step from: taken, and nothing moves.
            [$gesture('end', 1.5), [], true, false, ''],
            // ONE TOUCH PINCH ZOOMS ONCE. iOS fires gesture events for a touch screen's two fingers as well as
            // their pointer events: the pointer pinch zooms it (spread 100 → 200, by 2), and the gesture events
            // — whichever of the second finger's pointerdown and the gesturestart comes first — move nothing.
            [$down(0), [], false, false, 'none'],
            [$gesture('start', 1, 60, 20), [], true, false, 'none'],
            [$down(100, false, 0, 8), [['capture' => 7], ['capture' => 8]], false, false, 'none'],
            [$gesture('change', 2, 110, 20), [], true, false, 'none'],
            [$move(200, 0, 1, 8), [['show' => ['act' => 'pinch', 'from' => ['x' => 40, 'y' => -20], 'to' => ['x' => 90, 'y' => -20], 'factor' => 2]]], false, false, 'none'],
            [$gesture('end', 2, 110, 20), [], true, false, 'none'],
            [$up(8), [], false, false, 'none'],
            [$up(7), [], false, false, ''],
            [['type' => 'click'], [], true, true, ''],
        ];

        $expected = [];

        foreach ($steps as [$event, $produces, $prevented, $stopped, $userSelect]) {
            array_push($expected, ...$produces);
            $expected[] = ['event' => $event['type'], 'default_prevented' => $prevented, 'propagation_stopped' => $stopped, 'user_select' => $userSelect, 'webkit_user_select' => $userSelect];
        }

        return $this->logDefects($this->probe(['gestures' => array_column($steps, 0)], $dir)['log'], $expected, 'gesture');
    }

    /**
     * Every event over a camera that frames nothing, each step labelled with the gate it proves: no act,
     * show or capture, the default not prevented, the propagation not stopped and the text selectable —
     * the browser's event, untouched. From the start (`"framed": false`): the wheel; a `dragstart` (a room
     * link's native drag); a press on a room's link that moves the slop and more and is released, whose
     * click is the browser's, so the link is followed; and the arrow and zoom keys. And mid-press, the camera ceasing to
     * frame between two events: a press that has not yet panned pans nothing more (the move's gate), and
     * one that has panned is no veto on its click (the click's gate).
     *
     * @return list<string>
     */
    private function unframedDefects(?string $dir = null): array
    {
        $down = static fn (int $x, int $id = 7): array => ['type' => 'pointerdown', 'isPrimary' => $id === 7, 'button' => 0, 'clientX' => $x, 'clientY' => 0, 'pointerId' => $id];
        $move = static fn (int $x, int $id = 7): array => ['type' => 'pointermove', 'buttons' => 1, 'clientX' => $x, 'clientY' => 0, 'pointerId' => $id];
        $untouched = static fn (string $type, string $select = ''): array => ['event' => $type, 'default_prevented' => false, 'propagation_stopped' => false, 'user_select' => $select, 'webkit_user_select' => $select];
        $this->assertSame(1, preg_match('/export const DRAG_SLOP_PX = (\d+);/', (string) file_get_contents(($dir ?? $this->moduleDir()).'/camera-gestures.js'), $m),
            "camera-gestures.js's DRAG_SLOP_PX did not parse");
        $slop = (int) $m[1];

        $checks = [
            'wheel' => ['gestures', false, [
                [['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaX' => 0, 'deltaY' => 100, 'deltaMode' => 0, 'ctrlKey' => false], [$untouched('wheel')]],
                // A Ctrl+wheel over a page whose camera frames nothing is the browser's page zoom.
                [['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaX' => 0, 'deltaY' => -100, 'deltaMode' => 0, 'ctrlKey' => true], [$untouched('wheel')]],
            ]],
            'dragstart' => ['gestures', false, [
                [['type' => 'dragstart'], [$untouched('dragstart')]],
            ]],
            // Safari's trackpad pinch over a page whose camera frames nothing is the browser's — and so is the
            // Ctrl+wheel Safari then sends, which the wheel's own gate leaves to the page (card#11045 PR-C).
            'gesture' => ['gestures', false, [
                [['type' => 'gesturestart', 'scale' => 1, 'clientX' => 110, 'clientY' => 220], [$untouched('gesturestart')]],
                [['type' => 'gesturechange', 'scale' => 2, 'clientX' => 110, 'clientY' => 220], [$untouched('gesturechange')]],
                [['type' => 'gestureend', 'scale' => 2, 'clientX' => 110, 'clientY' => 220], [$untouched('gestureend')]],
            ]],
            // A press on a room's link that moves the slop, then further, and is released: the link is followed.
            'press' => ['gestures', false, [
                [$down(0), [$untouched('pointerdown')]],
                [$move($slop), [$untouched('pointermove')]],
                [$move($slop + 10), [$untouched('pointermove')]],
                [['type' => 'pointerup', 'pointerId' => 7], [$untouched('pointerup')]],
                [['type' => 'click'], [$untouched('click')]],
            ]],
            // A framed press, and then nothing framed before a second finger comes down (card#11045): no pinch
            // starts — nothing captured — and neither finger's move moves anything.
            'press then a second finger' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [$down(100, 8), [$untouched('pointerdown', 'none')]],
                // The second finger is no part of the press: its move is nothing to it.
                [$move(200, 8), [$untouched('pointermove', 'none')]],
            ], 'press'],
            // A framed press, and then nothing framed before it has panned: it pans nothing, and is not captured.
            'move' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [$move(10), [$untouched('pointermove')]],
                [$move(20), [$untouched('pointermove')]],
                [['type' => 'pointerup', 'pointerId' => 7], [$untouched('pointerup')]],
            ]],
            // A framed press that panned, and then nothing framed before it moves again (card#7343 c7692 item 3):
            // the move gate releases the capture the pan took, so the release and its click land on what is
            // under the pointer — and it pans nothing more. The move gate's own step, a second scenario.
            'move after a pan' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [$move(10), [['capture' => 7], ['show' => ['act' => 'drag', 'dx' => 10, 'dy' => 0]], $untouched('pointermove', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [$move(20), [['release' => 7], $untouched('pointermove')]],
                [['type' => 'pointerup', 'pointerId' => 7], [$untouched('pointerup')]],
                [['type' => 'click'], [$untouched('click')]],
            ], 'move'],
            // A framed press that panned, and then nothing framed before its click: the click is the browser's.
            'click' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [$move(10), [['capture' => 7], ['show' => ['act' => 'drag', 'dx' => 10, 'dy' => 0]], $untouched('pointermove', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [['type' => 'pointerup', 'pointerId' => 7], [$untouched('pointerup')]],
                [['type' => 'click'], [$untouched('click')]],
            ]],
            'keys' => ['keys', false, array_map(static fn (string $key): array => [['type' => 'keydown', 'key' => $key], [$untouched('keydown')]],
                ['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', '+', '=', '-', '_'])],
        ];

        $defects = [];

        // Each check reds on its gate's step: its own name, or the step it names as its fourth member.
        foreach ($checks as $name => $check) {
            [$mode, $framed, $steps] = $check;
            $step = $check[3] ?? $name;
            $expected = array_merge(...array_column($steps, 1));
            $log = $this->probe([$mode => array_column($steps, 0), 'framed' => $framed], $dir)['log'];

            foreach ($this->logDefects($log, $expected, "unframed {$name}") as $defect) {
                $defects[] = "[{$step}] {$defect}";
            }
        }

        return $defects;
    }

    /**
     * `offerKeys()` over a camera that frames nothing, then something, then nothing, then something: the zoom
     * buttons hidden exactly while nothing is framed, and `aria-keyshortcuts` absent then — and, while framed,
     * naming the arrow keys and `+` and `-`, and no key `keyDefects()` does not show being taken.
     *
     * @return list<string>
     */
    private function offerDefects(?string $dir = null): array
    {
        // Twice in a row each way, as a glide shows one camera after another; the markup starts withdrawn.
        $offers = [false, false, true, true, false, false, true];
        $log = $this->probe(['offer' => $offers], $dir)['log'];
        $taken = ['+', '=', '-', '_', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'];
        $defects = [];

        foreach ($log as $i => $entry) {
            $framed = $entry['framed'];

            if ($entry['zoom_in_hidden'] === $framed || $entry['zoom_out_hidden'] === $framed) {
                $defects[] = sprintf('offer %d (%s): a zoom button is %s', $i, $framed ? 'framed' : 'nothing framed', $framed ? 'hidden' : 'shown');
            }

            // card#7343 c7692 item 2: the page's framing control — Fit the floor, Whole building — with them.
            if ($entry['fit_hidden'] === $framed) {
                $defects[] = sprintf('offer %d (%s): the framing control is %s', $i, $framed ? 'framed' : 'nothing framed', $framed ? 'hidden' : 'shown');
            }

            // card#11045: the gesture hint — untrue of a page whose wheel scrolls it — with them.
            if ($entry['hint_hidden'] === $framed) {
                $defects[] = sprintf('offer %d (%s): the gesture hint is %s', $i, $framed ? 'framed' : 'nothing framed', $framed ? 'hidden' : 'shown');
            }

            // card#7343 c7692 item 1: the drawing is a tab stop while — and only while — its camera frames.
            if ($entry['tabindex'] !== ($framed ? '0' : null)) {
                $defects[] = $framed
                    ? "offer {$i} (framed): the keyboard cannot reach the drawing (tabindex ".json_encode($entry['tabindex']).')'
                    : "offer {$i} (nothing framed): the drawing is a tab stop for a camera that does nothing (tabindex {$entry['tabindex']})";
            }

            // card#7343 c7692 item 4: an offer that did not change writes nothing — a glide shows a camera every frame.
            $wrote = $entry['writes'] - ($log[$i - 1]['writes'] ?? 0);
            $changed = $framed !== ($log[$i - 1]['framed'] ?? false);

            if (! $changed && $wrote !== 0) {
                $defects[] = "offer {$i} ({$entry['framed']}): the offer did not change and was written {$wrote} times — it churns on every glide frame";
            }

            if (! $framed && $entry['keyshortcuts'] !== null) {
                $defects[] = "offer {$i} (nothing framed): the drawing names keys that do nothing: {$entry['keyshortcuts']}";
            }

            if ($framed) {
                $named = explode(' ', (string) $entry['keyshortcuts']);

                foreach (array_diff(['+', '-', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'], $named) as $missing) {
                    $defects[] = "offer {$i} (framed): the drawing does not name {$missing}";
                }

                foreach (array_diff($named, $taken) as $extra) {
                    $defects[] = "offer {$i} (framed): the drawing names {$extra}, which no key handler takes";
                }
            }
        }

        return count($log) === count($offers) ? $defects : [...$defects, 'the offer log has '.count($log).' entries, not '.count($offers)];
    }

    /** A gate's whole block in the shipped module — its comment, its guard, and its body up to the blank line after it. */
    private function gateBlock(string $file, string $comment, string $guard): string
    {
        $source = (string) file_get_contents($this->moduleDir().'/'.$file);
        $start = strpos($source, $comment.'        '.$guard);
        $this->assertNotFalse($start, "the gate {$comment} is not in {$file}");
        $end = strpos($source, "        }\n\n", $start);
        $this->assertNotFalse($end, "the gate {$comment} has no end in {$file}");

        return substr($source, $start, $end + strlen("        }\n\n") - $start);
    }

    /**
     * One scripted pass through every key and button rule — row 15's, which the floor's inline copy held
     * and `wire/camera-keys.js` holds for both pages since card#7343 r2-2: each step is an event and what
     * must follow it, then whether it had its default prevented.
     *
     * @return list<string>
     */
    private function keyDefects(?string $dir = null): array
    {
        $this->assertSame(1, preg_match('/export const PAN_STEP_PX = (\d+);/', (string) file_get_contents($this->moduleDir().'/camera.js'), $m),
            "camera.js's PAN_STEP_PX did not parse");
        $step = (int) $m[1];
        $key = static fn (string $key, array $modifiers = []): array => ['type' => 'keydown', 'key' => $key] + $modifiers;
        $zoom = static fn (int $notches): array => ['show' => ['act' => 'zoomStep', 'notches' => $notches]];
        $pan = static fn (int $dx, int $dy): array => ['show' => ['act' => 'drag', 'dx' => $dx, 'dy' => $dy]];

        // [event, what it produces, default prevented]
        $steps = [
            [$key('+'), [$zoom(1)], true],
            [$key('='), [$zoom(1)], true],
            [$key('-'), [$zoom(-1)], true],
            [$key('_'), [$zoom(-1)], true],
            // The view moves the way the arrow points: the scene is dragged the other way.
            [$key('ArrowLeft'), [$pan($step, 0)], true],
            [$key('ArrowRight'), [$pan(-$step, 0)], true],
            [$key('ArrowUp'), [$pan(0, $step)], true],
            [$key('ArrowDown'), [$pan(0, -$step)], true],
            // A key with a modifier is the browser's — Ctrl + is the page zoom — and passes through.
            [$key('+', ['ctrlKey' => true]), [], false],
            [$key('-', ['metaKey' => true]), [], false],
            [$key('ArrowLeft', ['altKey' => true]), [], false],
            // Every other key is untouched: a focused desk's or plate link's Enter and Space are theirs.
            [$key('Enter'), [], false],
            [$key(' '), [], false],
            // The zoom buttons: one notch each, about the centre.
            [['type' => 'click', 'on' => 'zoom_in'], [$zoom(1)], false],
            [['type' => 'click', 'on' => 'zoom_out'], [$zoom(-1)], false],
        ];

        $expected = [];

        foreach ($steps as [$event, $produces, $prevented]) {
            array_push($expected, ...$produces);
            $expected[] = ['event' => $event['type'], 'default_prevented' => $prevented, 'propagation_stopped' => false, 'user_select' => '', 'webkit_user_select' => ''];
        }

        return $this->logDefects($this->probe(['keys' => array_column($steps, 0)], $dir)['log'], $expected, 'key');
    }

    /**
     * @param  list<array<string, mixed>>  $log
     * @param  list<array<string, mixed>>  $expected
     * @return list<string>
     */
    private function logDefects(array $log, array $expected, string $what): array
    {
        foreach ($expected as $i => $entry) {
            if (($log[$i] ?? null) !== $entry) {
                return [sprintf('entry %d of the %s log is %s, not %s', $i, $what, json_encode($log[$i] ?? null), json_encode($entry))];
            }
        }

        return count($log) === count($expected) ? [] : [sprintf('the %s log has %d entries, not %d', $what, count($log), count($expected))];
    }
}

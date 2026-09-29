<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The camera's page wire — one for the floor and the lobby.** `docs/design/FLOOR.md` Appendix B rows 15
 * and 16 (card#7343 r1, r2): `wire/camera-gestures.js`, the wheel and the drag, and `wire/camera-keys.js`,
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
 * ⛔ NOTHING FRAMED, NOTHING TAKEN (card#7343 r4b, the seat's ruling, widening r3b's on the wheel): over a
 * drawing whose camera frames nothing — the uncomposed lobby, whose list flows in the page — every event is
 * left to the browser: the wheel, a `dragstart`, a press and its `user-select`, the move's capture and pan,
 * the click after a drag, and the arrow and zoom keys, a capture a pan took being released at the move;
 * and nothing of the camera is offered — the zoom buttons and the page's framing control hidden, the
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
 * is what a wheel, a key, a zoom button, a drag and a resize do, or by another `glideTo()`, which is the
 * whole-building control — cuts to its destination and runs its arrival; an
 * uncommitted one — the floor's fit, the lobby's whole-building — stops where it is, as the floor's did
 * before. The model's half — the ride held in flight until its glide arrives, a second one refused — is
 * `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that a browser delivers these events to these listeners, honours
 * `user-select` (or its WebKit-prefixed form), or paints what is applied. There is no browser on the build host; the pages' wiring tests
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
            'a drag a buttonless move does not end' => ['camera-gestures.js', 'if (drag === null || (event.buttons & 1) === 0) {', 'if (drag === null) {'],
            'a drag any pointer and button starts' => ['camera-gestures.js', 'if (!event.isPrimary || event.button !== 0) {', 'if (false) {'],
            'a press that pans before it has moved' => ['camera-gestures.js', 'Math.hypot(dx, dy) < DRAG_SLOP_PX', 'Math.hypot(dx, dy) < 0'],
            'a wheel whose deltaMode never reaches the camera' => ['camera-gestures.js', '{ deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }', '{ deltaY: event.deltaY, ctrlKey: event.ctrlKey }'],
            'a wheel whose ctrlKey (a pinch) never reaches the camera' => ['camera-gestures.js', '{ deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }', '{ deltaY: event.deltaY, deltaMode: event.deltaMode }'],
            'a wheel about the page, not the drawing' => ['camera-gestures.js', '{ x: event.clientX - r.left, y: event.clientY - r.top }', '{ x: event.clientX, y: event.clientY }'],
            'a wheel that also scrolls the page' => ['camera-gestures.js', "        event.preventDefault();\n\n        const r = element.getBoundingClientRect();\n", "        const r = element.getBoundingClientRect();\n"],
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
            'the press' => ['camera-gestures.js', "        // Gate: nothing framed — a press selects text as ever, and no drag starts.\n", 'if (unframed()) {', 'press'],
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
        $dir = $this->mutatedModules(['camera-gestures.js', "            if (drag.moved) {\n                element.releasePointerCapture(event.pointerId);\n            }\n", '']);
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
        $down = static fn (int $x, bool $primary = true, int $button = 0): array => ['type' => 'pointerdown', 'isPrimary' => $primary, 'button' => $button, 'clientX' => $x, 'clientY' => 0];
        $move = static fn (int $x, int $y = 0, int $buttons = 1): array => ['type' => 'pointermove', 'buttons' => $buttons, 'clientX' => $x, 'clientY' => $y, 'pointerId' => 7];
        $drag = static fn (int $dx, int $dy): array => ['show' => ['act' => 'drag', 'dx' => $dx, 'dy' => $dy]];

        // [event, what it produces, default prevented, propagation stopped, the drawing's user-select after it —
        // the standard property and the WebKit-prefixed one alike]
        $steps = [
            // The wheel: about the cursor on the drawing, with its mode and its pinch, and not the page's scroll.
            [['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaY' => -120, 'deltaMode' => 1, 'ctrlKey' => true],
                [['show' => ['act' => 'wheel', 'point' => ['x' => 100, 'y' => 200], 'delta' => ['deltaY' => -120, 'deltaMode' => 1, 'ctrlKey' => true]]]], true, false, ''],
            // Nothing inside the drawing is dragged out of it — a link a pan starts on least of all.
            [['type' => 'dragstart'], [], true, false, ''],
            // A secondary button never drags, and never holds the text either.
            [$down(0, true, 2), [], false, false, ''],
            [$move(50, 0, 3), [], false, false, ''],
            [['type' => 'pointerup'], [], false, false, ''],
            // Nor does a pointer that is not the primary one.
            [$down(0, false), [], false, false, ''],
            [$move(50), [], false, false, ''],
            [['type' => 'pointerup'], [], false, false, ''],
            // A press is a click until it moves the slop; then the pointer is captured and each move pans.
            // From the press until it ends, the drawing's text is not selectable.
            [$down(0), [], false, false, 'none'],
            [$move(3), [], false, false, 'none'],
            [['type' => 'dragstart'], [], true, false, 'none'],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false, 'none'],
            [$move(15, 5), [$drag(5, 5)], false, false, 'none'],
            [['type' => 'pointerup'], [], false, false, ''],
            // A drag that moved is no click — neither its handlers nor its default action — and only once.
            [['type' => 'click'], [], true, true, ''],
            [['type' => 'click'], [], false, false, ''],
            // A press that never moved the slop is a click.
            [$down(0), [], false, false, 'none'],
            [$move(2), [], false, false, 'none'],
            [['type' => 'pointerup'], [], false, false, ''],
            [['type' => 'click'], [], false, false, ''],
            // A pointer the browser took back ends the drag, and is no click either.
            [$down(0), [], false, false, 'none'],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false, 'none'],
            [['type' => 'pointercancel'], [], false, false, ''],
            [$move(30), [], false, false, ''],
            [['type' => 'click'], [], false, false, ''],
            // A move with the primary button no longer held ends the drag (released outside the drawing).
            [$down(0), [], false, false, 'none'],
            [$move(20, 0, 0), [], false, false, ''],
            [$move(40), [], false, false, ''],
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
        $down = static fn (int $x): array => ['type' => 'pointerdown', 'isPrimary' => true, 'button' => 0, 'clientX' => $x, 'clientY' => 0];
        $move = static fn (int $x): array => ['type' => 'pointermove', 'buttons' => 1, 'clientX' => $x, 'clientY' => 0, 'pointerId' => 7];
        $untouched = static fn (string $type, string $select = ''): array => ['event' => $type, 'default_prevented' => false, 'propagation_stopped' => false, 'user_select' => $select, 'webkit_user_select' => $select];
        $this->assertSame(1, preg_match('/export const DRAG_SLOP_PX = (\d+);/', (string) file_get_contents(($dir ?? $this->moduleDir()).'/camera-gestures.js'), $m),
            "camera-gestures.js's DRAG_SLOP_PX did not parse");
        $slop = (int) $m[1];

        $checks = [
            'wheel' => ['gestures', false, [
                [['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaY' => 100, 'deltaMode' => 0, 'ctrlKey' => false], [$untouched('wheel')]],
            ]],
            'dragstart' => ['gestures', false, [
                [['type' => 'dragstart'], [$untouched('dragstart')]],
            ]],
            // A press on a room's link that moves the slop, then further, and is released: the link is followed.
            'press' => ['gestures', false, [
                [$down(0), [$untouched('pointerdown')]],
                [$move($slop), [$untouched('pointermove')]],
                [$move($slop + 10), [$untouched('pointermove')]],
                [['type' => 'pointerup'], [$untouched('pointerup')]],
                [['type' => 'click'], [$untouched('click')]],
            ]],
            // A framed press, and then nothing framed before it has panned: it pans nothing, and is not captured.
            'move' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [$move(10), [$untouched('pointermove')]],
                [$move(20), [$untouched('pointermove')]],
                [['type' => 'pointerup'], [$untouched('pointerup')]],
            ]],
            // A framed press that panned, and then nothing framed before it moves again (card#7343 c7692 item 3):
            // the move gate releases the capture the pan took, so the release and its click land on what is
            // under the pointer — and it pans nothing more. The move gate's own step, a second scenario.
            'move after a pan' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [$move(10), [['capture' => 7], ['show' => ['act' => 'drag', 'dx' => 10, 'dy' => 0]], $untouched('pointermove', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [$move(20), [['release' => 7], $untouched('pointermove')]],
                [['type' => 'pointerup'], [$untouched('pointerup')]],
                [['type' => 'click'], [$untouched('click')]],
            ], 'move'],
            // A framed press that panned, and then nothing framed before its click: the click is the browser's.
            'click' => ['gestures', true, [
                [$down(0), [$untouched('pointerdown', 'none')]],
                [$move(10), [['capture' => 7], ['show' => ['act' => 'drag', 'dx' => 10, 'dy' => 0]], $untouched('pointermove', 'none')]],
                [['type' => 'reframe', 'framed' => false], [['reframed' => false]]],
                [['type' => 'pointerup'], [$untouched('pointerup')]],
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

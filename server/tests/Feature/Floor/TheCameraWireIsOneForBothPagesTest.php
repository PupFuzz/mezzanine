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
            'a wheel that also scrolls the page' => ['camera-gestures.js', "    element.addEventListener('wheel', (event) => {\n        event.preventDefault();\n", "    element.addEventListener('wheel', (event) => {\n"],
            'a drag that follows the plate link it ended over' => ['camera-gestures.js', "            event.preventDefault();\n            event.stopPropagation();", '            event.stopPropagation();'],
            'a drag that selects the desk it ended over' => ['camera-gestures.js', "            event.preventDefault();\n            event.stopPropagation();", '            event.preventDefault();'],
            // card#7343 r2-3: a pan started on a plate's link is no native drag of it, and a pan selects no text.
            'a pan that drags the link it started on out' => ['camera-gestures.js', "    element.addEventListener('dragstart', (event) => {\n        event.preventDefault();\n    });\n", ''],
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

<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The camera's page wire — one for the floor and the lobby.** `docs/design/FLOOR.md` Appendix B rows 15
 * and 16 (card#7343 r1): `wire/camera-gestures.js`, the wheel and the drag both pages wire to their
 * screen's camera acts, and `wire/camera-view.js`, how each page shows the camera — at once, or as a
 * glide, and for the lobby's ride a COMMITTED glide.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SHIPPED MODULES, UNDER `node`, WITH A STAND-IN ELEMENT AND A STUBBED FRAME CLOCK
 * (`camera-wire-probe.mjs`). Both files were exercised by nothing until this: the gestures lived inline
 * in each page's DOM entry, checked only as source lines by the pages' wiring tests, and the glide was
 * the browser's frame clock. Each defect below is planted in the shipped file and watched red.
 *
 * ⛔ THE CLICK COMMITS THE RIDE (card#7343 r1 ruling). A committed glide interrupted — by a `show()`, which
 * is what a wheel, a drag and a resize do, or by another `glideTo()`, which is the whole-building control
 * — cuts to its destination and runs its arrival; an uncommitted one — the floor's fit, the lobby's
 * whole-building — stops where it is, as the floor's did before. The model's half — the ride held in
 * flight, a second one refused — is `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that a browser delivers these events to these listeners, or paints what is
 * applied. There is no browser on the build host; the pages' wiring tests hold that each page hands its
 * drawing and its screen's acts to `cameraGestures()`.
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
        ];
    }

    #[DataProvider('gesturePlants')]
    public function test_red_each_gesture_defect_planted_in_the_one_module(string $file, string $anchor, string $replacement): void
    {
        $dir = $this->mutatedModules([$file, $anchor, $replacement]);

        $this->assertNotSame([], $this->gestureDefects($dir), 'the planted gesture defect did not bite');
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

        // [event, what it produces, default prevented, propagation stopped]
        $steps = [
            // The wheel: about the cursor on the drawing, with its mode and its pinch, and not the page's scroll.
            [['type' => 'wheel', 'clientX' => 110, 'clientY' => 220, 'deltaY' => -120, 'deltaMode' => 1, 'ctrlKey' => true],
                [['show' => ['act' => 'wheel', 'point' => ['x' => 100, 'y' => 200], 'delta' => ['deltaY' => -120, 'deltaMode' => 1, 'ctrlKey' => true]]]], true, false],
            // A secondary button never drags.
            [$down(0, true, 2), [], false, false],
            [$move(50, 0, 3), [], false, false],
            [['type' => 'pointerup'], [], false, false],
            // Nor does a pointer that is not the primary one.
            [$down(0, false), [], false, false],
            [$move(50), [], false, false],
            [['type' => 'pointerup'], [], false, false],
            // A press is a click until it moves the slop; then the pointer is captured and each move pans.
            [$down(0), [], false, false],
            [$move(3), [], false, false],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false],
            [$move(15, 5), [$drag(5, 5)], false, false],
            [['type' => 'pointerup'], [], false, false],
            // A drag that moved is no click — neither its handlers nor its default action — and only once.
            [['type' => 'click'], [], true, true],
            [['type' => 'click'], [], false, false],
            // A press that never moved the slop is a click.
            [$down(0), [], false, false],
            [$move(2), [], false, false],
            [['type' => 'pointerup'], [], false, false],
            [['type' => 'click'], [], false, false],
            // A pointer the browser took back ends the drag, and is no click either.
            [$down(0), [], false, false],
            [$move(10), [['capture' => 7], $drag(10, 0)], false, false],
            [['type' => 'pointercancel'], [], false, false],
            [$move(30), [], false, false],
            [['type' => 'click'], [], false, false],
            // A move with the primary button no longer held ends the drag (released outside the drawing).
            [$down(0), [], false, false],
            [$move(20, 0, 0), [], false, false],
            [$move(40), [], false, false],
        ];

        $expected = [];

        foreach ($steps as [$event, $produces, $prevented, $stopped]) {
            array_push($expected, ...$produces);
            $expected[] = ['event' => $event['type'], 'default_prevented' => $prevented, 'propagation_stopped' => $stopped];
        }

        $log = $this->probe(['gestures' => array_column($steps, 0)], $dir)['log'];

        foreach ($expected as $i => $entry) {
            if (($log[$i] ?? null) !== $entry) {
                return [sprintf('entry %d of the gesture log is %s, not %s', $i, json_encode($log[$i] ?? null), json_encode($entry))];
            }
        }

        return count($log) === count($expected) ? [] : [sprintf('the gesture log has %d entries, not %d', count($log), count($expected))];
    }
}

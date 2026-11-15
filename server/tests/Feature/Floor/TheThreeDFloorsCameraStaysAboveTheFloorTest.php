<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The 3-D floor's view limits, its near plane, the eye held in the office, its cut-away and its keys** —
 * `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 3, over `floor3d/view-limits.js` (`view-limits-probe.mjs`, which
 * drives its confinement through the vendored `OrbitControls` and the keys' acts) and `floor3d/camera-acts.js` through
 * the house keys wire (`camera-acts-probe.mjs`). The figures are `docs/design/FLOOR.md` § 12's *Floor 3-D camera* and
 * *Floor 3-D cut-away* rows; the keyboard map is the plan's § 12 item 3.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a camera on a page. The pointer's own events, the wheel at the dolly limit and the
 * renderer's frame calling the clamp are `tools/design/floor3d.browser.mjs`'s `camera` row (plan § 10's residual risks);
 * here a drag, a turn and the wheel are the library's public `pan()`, `rotateLeft()` and `dolly…()`.
 */
class TheThreeDFloorsCameraStaysAboveTheFloorTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/wire')
            ?: $this->fail('server/public/js/wire does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/view-limits-probe.mjs';
    }

    public function test_green_the_limits_keep_the_camera_above_the_floor_and_in_the_office_and_the_cutaway_names_what_it_meets(): void
    {
        $this->assertSame([], $this->defects($this->probe([])));
    }

    public function test_green_each_key_reaches_the_controls_call_the_map_names(): void
    {
        $this->assertSame([], $this->defects($this->probe([], null, __DIR__.'/camera-acts-probe.mjs')));
    }

    /**
     * ⛔ THE REDS — each the plan's plant (§ 8 item 3), seen red on the leg that names it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function plants(): array
    {
        $limits = '../floor3d/view-limits.js';
        $acts = '../floor3d/camera-acts.js';

        return [
            'the polar limit at 92°' => [$limits, 'export const MAX_POLAR = (88 * Math.PI) / 180;', 'export const MAX_POLAR = (92 * Math.PI) / 180;', 'above', 'view-limits'],
            'the near plane at 0.1' => [$limits, 'export const NEAR = 0.05;', 'export const NEAR = 0.1;', 'above', 'view-limits'],
            'the fit ignoring the stage\'s aspect' => [$limits, 'const fit = fitDistance(bounds, FOV, aspect);', 'const fit = fitDistance(bounds, FOV, 1);', 'limits', 'view-limits'],
            'the entry from straight above' => [$limits, 'entry: orbitCamera({ x: 0, y: 0, z: 0 }, HOME_POLAR, 0, fit),', 'entry: orbitCamera({ x: 0, y: 0, z: 0 }, 0, 0, fit),', 'limits', 'view-limits'],
            'the cut-away meeting nothing' => [$limits, '        if (t0 > t1) {', '        if (true) {', 'cutaway', 'view-limits'],
            'the cut-away counting a box the segment only touches at the target' => [$limits, '    return t0 < 1 ? t0 : null;', '    return t0;', 'cutaway', 'view-limits'],
            'the cut-away blind to a wall\'s height' => [$limits, "for (const [axis, size] of [['x', 'w'], ['y', 'h'], ['z', 'd']])", "for (const [axis, size] of [['x', 'w'], ['z', 'd']])", 'cutaway', 'view-limits'],
            'the cab\'s clip planes turned to keep what is outside the doorway' => [$limits, 'const sense = n.x * centre.x + n.y * centre.y + n.z * centre.z < 0 ? -1 : 1;', 'const sense = n.x * centre.x + n.y * centre.y + n.z * centre.z < 0 ? 1 : -1;', 'doorway', 'view-limits'],
            'the cab\'s clip planes through the opening\'s diagonals, not its edges' => [$limits, 'const b = rim[(k + 1) % 4];', 'const b = rim[(k + 2) % 4];', 'doorway', 'view-limits'],
            'the cab\'s clip normals left at the cross product\'s length (Three then moves each plane off the eye)' => [$limits, 'const unit = sense / Math.hypot(n.x, n.y, n.z);', 'const unit = sense;', 'doorway', 'view-limits'],
            'the cab clipped, not hidden, from behind the band' => [$limits, '    if (eye.z <= door.z) {', '    if (false) {', 'doorway', 'view-limits'],
            'the band back in the cut-away' => [$limits, '    return new Set(world.walls.filter((wall) => meets(eye, target, wall.box) !== null).map((wall) => wall.id));', "    return new Set([...world.walls, ...(world.band === null ? [] : [{ id: 'band', box: { x: world.band.x, y: 0, z: world.band.z - 0.01, w: world.band.w, h: world.band.h, d: 0.01 } }])].filter((wall) => meets(eye, target, wall.box) !== null).map((wall) => wall.id));", 'cutaway', 'view-limits'],
            'the eye left free' => [$limits, '    if (inside(lifted)) {', '    if (true) {', 'move', 'view-limits'],
            'a pan resolved along the eye\'s line, the pair never slid' => [$limits, '        slide[axis] = clampTo(carried[axis], lo, hi) - carried[axis];', '        slide[axis] = 0;', 'move', 'view-limits'],
            'a turn at the wall held rather than brought in' => [$limits, '        eye: near <= far ? add(held, scale(u, clampTo(d, near, far))) : add(held, before),', '        eye: add(held, before),', 'turn', 'view-limits'],
            'the eye\'s line uncapped at the dolly limit' => [$limits, '    let far = bound.distance;', '    let far = Infinity;', 'confine', 'view-limits'],
            'the target\'s height left free' => [$limits, '    const t = { x: target.x, y: clampTo(target.y, bound.target.min.y, bound.target.max.y), z: target.z };', '    const t = { x: target.x, y: target.y, z: target.z };', 'confine', 'view-limits'],
            'an update after the clamp (the back wall\'s drop)' => [$limits, "    camera.lookAt(target);\n", "    camera.lookAt(target);\n    controls.update();\n", 'damping', 'view-limits'],
            'the damped pan kept on a slid axis' => [$limits, "            controls._panOffset[axis] = 0;\n", '', 'damping', 'view-limits'],
            'the controls\' last pose left where their update put it (a pan at the wall wakes no frame)' => [$limits, "    controls._lastPosition.copy(camera.position);\n    controls._lastQuaternion.copy(camera.quaternion);\n    controls._lastTargetPosition.copy(target);\n", '', 'wake', 'view-limits'],
            'a walk whose slide moves the eye alone (the distance and the pitch change)' => [$limits, '    const held = add(t, slide);', '    const held = t;', 'move', 'view-limits'],
            'a pan up the screen (the shared configuration)' => [$limits, '    controls.screenSpacePanning = false;', '    controls.screenSpacePanning = true;', 'move', 'view-limits'],
            'the camera not turned to the held target' => [$limits, "    camera.lookAt(target);\n", '', 'turn', 'view-limits'],
            'no gap at the walls' => [$limits, '    const gap = NEAR * Math.hypot(1, half, half * aspect);', '    const gap = 0;', 'limits', 'view-limits'],
            'a gap of the near distance alone' => [$limits, '    const gap = NEAR * Math.hypot(1, half, half * aspect);', '    const gap = NEAR;', 'limits', 'view-limits'],
            'the front closed at the floor\'s edge' => [$limits, 'max: Object.freeze({ x: extent.w / 2 - gap, z: extent.d / 2 + max }),', 'max: Object.freeze({ x: extent.w / 2 - gap, z: extent.d / 2 }),', 'confine', 'view-limits'],
            'the far plane short of the eye\'s box' => [$limits, '        far: Math.hypot(bounds.w, bounds.h + max, bounds.d + max),', '        far: max + Math.hypot(bounds.w, bounds.h, bounds.d),', 'limits', 'view-limits'],
            'ArrowUp reaching the pan' => [$acts, '            if (modifiers.shift) {', '            if (modifiers.shift || dy !== 0) {', 'tilt', 'camera-acts'],
            'a Shift-arrow reaching the turn' => [$acts, '            if (modifiers.shift) {', '            if (false) {', 'pan', 'camera-acts'],
            'the keys wire dropping the Shift member' => ['camera-keys.js', '            show(acts.drag(...KEY_PAN[event.key], { shift: event.shiftKey }));', '            show(acts.drag(...KEY_PAN[event.key], { shift: false }));', 'pan', 'camera-acts'],
            'a dolly by a notch of its own' => [$acts, '            const scale = ZOOM_STEP ** -Math.abs(notches);', '            const scale = 1.1 ** -Math.abs(notches);', 'dolly', 'camera-acts'],
            'a notch in handed to the controls as the distance times the notch' => [$acts, '            const scale = ZOOM_STEP ** -Math.abs(notches);', '            const scale = ZOOM_STEP ** Math.abs(notches);', 'toward', 'camera-acts'],
            'Page Up lowering the eye' => ['camera-keys.js', 'const KEY_RISE = { PageUp: 1, PageDown: -1 };', 'const KEY_RISE = { PageUp: -1, PageDown: 1 };', 'rise', 'camera-acts'],
            'the keys wire never reaching the rise' => ['camera-keys.js', '        } else if (event.key in KEY_RISE && acts.riseStep !== undefined) {', '        } else if (false) {', 'rise', 'camera-acts'],
            'a key step scaled with the distance (a drag of PAN_STEP_PX)' => [$acts, '            return lift(steps * RISE_STEP);', '            return this.rise(steps * 48);', 'rise', 'camera-acts'],
            'a key step of a quarter of the room' => [$acts, 'export const RISE_STEP = ROOM_H_U / 8;', 'export const RISE_STEP = ROOM_H_U / 4;', 'rise', 'camera-acts'],
            'a rise that moves the target alone, turning the view' => [$acts, '        position.set(position.x, position.y + h, position.z);', '', 'rise', 'camera-acts'],
            'a rise at a rate of its own' => [$acts, 'Math.tan((controls.object.fov * Math.PI) / 360)', 'Math.tan((controls.object.fov * Math.PI) / 180)', 'rise', 'camera-acts'],
            'a rise with no clamp' => [$acts, "position.y + h, position.z);\n        controls.update();\n\n        return settled();", "position.y + h, position.z);\n        controls.update();\n\n        return camera();", 'rise', 'camera-acts'],
            'Fit with no clamp' => [$acts, "position.y, position.z);\n            controls.update();\n\n            return settled();", "position.y, position.z);\n            controls.update();\n\n            return camera();", 'fit', 'camera-acts'],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_plant_reds_its_leg(string $file, string $anchor, string $replacement, string $leg, string $probe): void
    {
        $legs = $this->probe([], $this->mutatedModules([$file, $anchor, $replacement]), __DIR__."/{$probe}-probe.mjs")['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }

    /**
     * @param  array{legs: array<string, list<string>>}  $out
     * @return list<string>
     */
    private function defects(array $out): array
    {
        return array_merge(...array_values($out['legs']));
    }
}

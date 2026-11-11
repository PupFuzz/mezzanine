<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The 3-D floor's view limits, its near plane, its cut-away and its keys** — `docs/design/plan-13033-stage2-3d-floor.md`
 * § 8 item 3 (card#13033 stage 2 slice 2), over `floor3d/view-limits.js` (`view-limits-probe.mjs`) and
 * `floor3d/camera-acts.js` through the house keys wire (`camera-acts-probe.mjs`). The figures are `docs/design/FLOOR.md`
 * § 12's *Floor 3-D camera* and *Floor 3-D cut-away* rows; the keyboard map is the plan's § 12 item 3.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a camera on a page. The library's own behaviour under these figures — its pan mode, its
 * wheel at the dolly limit, its damping — is slice 4's page-configuration test and browser leg (plan § 10's residual
 * risks), and the target clamp the acts call is the renderer's, slice 4's.
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

    public function test_green_the_limits_keep_the_camera_and_its_near_plane_above_the_floor_and_the_cutaway_names_what_it_meets(): void
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
            'the fit ignoring the stage\'s aspect' => [$limits, 'const fit = fitDistance(bounds, FOV, surface.width / surface.height);', 'const fit = fitDistance(bounds, FOV, 1);', 'limits', 'view-limits'],
            'the entry from straight above' => [$limits, 'entry: orbitCamera({ x: 0, y: 0, z: 0 }, HOME_POLAR, 0, fit),', 'entry: orbitCamera({ x: 0, y: 0, z: 0 }, 0, 0, fit),', 'limits', 'view-limits'],
            'the cut-away meeting nothing' => [$limits, '        if (t0 > t1) {', '        if (true) {', 'cutaway', 'view-limits'],
            'the cut-away ignoring the band' => [$limits, "...(world.band === null ? [] : [{ id: 'band',", "...(true ? [] : [{ id: 'band',", 'cutaway', 'view-limits'],
            'ArrowUp reaching the pan' => [$acts, '            if (modifiers.shift) {', '            if (modifiers.shift || dy !== 0) {', 'tilt', 'camera-acts'],
            'a Shift-arrow reaching the turn' => [$acts, '            if (modifiers.shift) {', '            if (false) {', 'pan', 'camera-acts'],
            'the keys wire dropping the Shift member' => ['camera-keys.js', '            show(acts.drag(...KEY_PAN[event.key], { shift: event.shiftKey }));', '            show(acts.drag(...KEY_PAN[event.key], { shift: false }));', 'pan', 'camera-acts'],
            'a dolly by a notch of its own' => [$acts, '            const scale = ZOOM_STEP ** Math.abs(notches);', '            const scale = 1.1 ** Math.abs(notches);', 'dolly', 'camera-acts'],
            'Fit with no clamp' => [$acts, "            controls.update();\n\n            return settled();", "            controls.update();\n\n            return camera();", 'fit', 'camera-acts'],
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

<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **The 3-D floor's light never clips, its ramp parts a top from a front from a side, and its ground shade covers the
 * walls once** — `docs/design/plan-13033-stage2-3d-floor.md` § 14.5 checks 1, 2 and 5 (card#13033), `FLOOR.md` § 12's
 * *Floor 3-D light*, *Floor 3-D ramp* and *Floor 3-D ground shade* rows. `look-probe.mjs` reads `floor3d/look.js` and
 * `floor3d/shade-rects.js` under node, and the wall runs over the floor screen's own scenes of the shipped default.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a drawing. That the page lights the scene with these figures and draws the shade over its
 * whole population is `tools/design/floor3d.browser.mjs`'s `look` row; that ONE ramp is built is
 * `Floor3dPageWiringTest`'s; how the floor looks is an eye's.
 */
class TheThreeDFloorsLightNeverClipsTest extends TestCase
{
    use DrivesTheScene;

    private const RUNS = ['scene_default'];

    /** @var list<array<string, mixed>>|null the shipped tree's scenes */
    private static ?array $scenes = null;

    public function test_green_no_face_clips_three_faces_part_and_every_wall_cell_is_in_one_run(): void
    {
        $answer = $this->look();

        $this->assertSame([], array_merge(...array_values($answer['legs'])), json_encode($answer['printed']));
    }

    /**
     * ⛔ THE REDS — each plant seen red on the leg that names it. The `band` leg's red is the probe's own control: the scenes
     * are drawn before a plant could reach them, so it plants a wall cell along the band in a copy of each scene itself.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function plants(): array
    {
        $look = '../floor3d/look.js';

        return [
            'the old rig\'s intensities, 2.2 and 1.7 (an up-facing face at 1.24)' => [$look,
                "intensity: 1.1 }),\n    /** The hemisphere light: its intensity and its ground colour, every phase. */\n    hemi: freeze({ intensity: 2.04,",
                "intensity: 2.2 }),\n    /** The hemisphere light: its intensity and its ground colour, every phase. */\n    hemi: freeze({ intensity: 1.7,", 'clip'],
            'the old sun direction, (-4, 8, 6) (a top and a front on one value)' => [$look,
                'direction: freeze([-0.24, 0.8, 0.55])', 'direction: freeze([-4, 8, 6])', 'ramp'],
            'the runs never joined (a run a cell)' => ['../floor3d/shade-rects.js',
                'if (last !== undefined && last.line === line && near(last.end, b[along])) {', 'if (false) {', 'runs'],
            'the pool round a kind the kit map does not stand the floor lamp for' => [$look,
                "pool: freeze({ kind: 'floor-lamp',", "pool: freeze({ kind: 'lamp',", 'pool'],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_plant_reds_its_leg(string $file, string $anchor, string $replacement, string $leg): void
    {
        $legs = $this->look($this->mutatedModules([$file, $anchor, $replacement]))['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }

    /**
     * The probe's answer over the shipped scenes, its client modules `$dir`'s.
     *
     * @return array{legs: array<string, list<string>>, printed: array<string, mixed>}
     */
    private function look(?string $dir = null): array
    {
        return $this->probe(['scenes' => $this->scenes()], $dir, __DIR__.'/look-probe.mjs');
    }

    /** @return list<array<string, mixed>> */
    private function scenes(): array
    {
        if (self::$scenes !== null) {
            return self::$scenes;
        }

        $scenes = [];

        foreach (self::RUNS as $run) {
            $renders = $this->floorRun($run)['floor_renders'];
            $drawn = array_values(array_filter(array_keys($renders), fn (int $i): bool => is_array($renders[$i]['frame']['scene'] ?? null)));

            $this->assertNotSame([], $drawn, "[{$run}] no frame drew a scene");

            $last = end($drawn);
            $scenes[] = ['run' => $run, 'i' => $last, 'scene' => $renders[$last]['frame']['scene']];
        }

        return self::$scenes = $scenes;
    }
}

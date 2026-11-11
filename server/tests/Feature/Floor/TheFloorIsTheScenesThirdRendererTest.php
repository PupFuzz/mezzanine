<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **AT-D3-38's world half** — `docs/design/FLOOR.md` § 11, *the floor in 3-D is the scene's third renderer*:
 * `floor/floor-world.js` lifts the scene the floor screen draws into the stage-1 world and adds no fact
 * (`docs/design/plan-13033-stage2-3d-floor.md` § 3.1, § 8 item 2; card#13033 stage 2 slice 2). The scenes are the
 * harness's own — the floor screen run over the shipped default with `fx-snapshot-4`'s seats, its overflowing run,
 * the interns' cap, a planned floor with a hallway, and the intern-walk, snack and turn-back runs whose walkers are in
 * flight — and `floor-world-probe.mjs` reads the world off each.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SCENES ARE BUILT ONCE PER TREE. The world is a pure function of a scene, so a plant in `floor-world.js` is read
 * over the shipped scenes; a plant in the scene's walls (`floor-walls.js`) rebuilds the scenes over the planted tree,
 * because that is where it moves what the world reads.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a drawing. The renderer that draws the world is slice 4's, and its browser leg owns what a
 * viewer sees; the character half of AT-D3-38 (the bodies, the clock) is slices 3 and 5's.
 */
class TheFloorIsTheScenesThirdRendererTest extends TestCase
{
    use DrivesTheScene;

    /** The runs, each with whether its walkers in flight are read (the last scene is read for every run). */
    private const RUNS = [
        'scene_default' => false,
        'scene_overflow' => false,
        'interns_cap' => false,
        'frame_planned' => false,
        'two-interns' => true,
        'idle-trip' => true,
        'intern-trip' => true,
        'turn-mid-floor' => true,
    ];

    /** How many scenes with a walker in flight each walking run hands the probe, spread across the run. */
    private const WALKING_SCENES = 3;

    /** @var array<string, list<array<string, mixed>>> the scenes, per tree */
    private static array $scenes = [];

    public function test_green_the_world_is_the_scene_lifted(): void
    {
        $this->assertSame([], $this->defects($this->world()));
    }

    /**
     * ⛔ THE REDS — each the plan's plant (§ 8 item 2), seen red on the leg that names it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    public static function plants(): array
    {
        return [
            'worldOf()\'s z sign flipped — the sitter on the front side' => ['../floor/floor-world.js',
                'z: slab.box.z + (at.y - slab.extent.y) / UNIT,', 'z: slab.box.z - (at.y - slab.extent.y) / UNIT,', 'facing', false],
            'a wall\'s edge and partition swapped' => ['../floor/floor-walls.js',
                "kind: edge ? 'edge' : 'partition' }", "kind: edge ? 'partition' : 'edge' }", 'walls', true],
            'a walker lifted from its start instead of its point now' => ['../floor/floor-world.js',
                'const p = pointAt(walker.effect, tMs);', 'const p = pointAt(walker.effect, 0);', 'walkers', false],
            'a label\'s text re-fitted' => ['../floor/floor-world.js',
                "label('name', name, { text: name.text });", "label('name', name, { text: name.text.slice(0, 6) });", 'labels', false],
            'the status reading the chip\'s word, not the screen\'s' => ['../floor/floor-world.js',
                "text: monitor.lit === 'off' ? null : screenText?.text ?? null,", "text: monitor.lit === 'off' ? null : chip?.text ?? null,", 'status', false],
            'a camera member on the world' => ['../floor/floor-world.js',
                '        slab,
        extent: Object.freeze({ w: slab.box.w, d: slab.box.d }),', '        slab,
        camera: null,
        extent: Object.freeze({ w: slab.box.w, d: slab.box.d }),', 'frame', false],
            'a bubble\'s anchor given its drawn y for depth' => ['../floor/floor-world.js',
                'return Object.freeze({ x: el.x + el.w / 2, depth: desk.depth_y,', 'return Object.freeze({ x: el.x + el.w / 2, depth: el.y,', 'labels', false],
            'a standing piece placed at its cell\'s top, not its foot' => ['../floor/floor-world.js',
                'at: at({ x: s.x + s.w / 2, y: s.y + s.h }),', 'at: at({ x: s.x + s.w / 2, y: s.y }),', 'pieces', false],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_plant_reds_its_leg(string $file, string $anchor, string $replacement, string $leg, bool $rebuild): void
    {
        $dir = $this->mutatedModules([$file, $anchor, $replacement]);
        $legs = $this->world($dir, $rebuild ? $dir : null)['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }

    /**
     * The probe's answer over the scenes of `$sceneDir`'s tree (the shipped one by default), its world module `$dir`'s.
     *
     * @return array{legs: array<string, list<string>>, printed: array<string, mixed>}
     */
    private function world(?string $dir = null, ?string $sceneDir = null): array
    {
        return $this->probe(['scenes' => $this->scenes($sceneDir)], $dir, __DIR__.'/floor-world-probe.mjs');
    }

    /** @return list<array<string, mixed>> */
    private function scenes(?string $dir): array
    {
        $tree = $dir ?? 'shipped';

        if (isset(self::$scenes[$tree])) {
            return self::$scenes[$tree];
        }

        $scenes = [];

        foreach (self::RUNS as $run => $walking) {
            $renders = $this->floorRun($run, $dir)['floor_renders'];
            $drawn = array_values(array_filter(array_keys($renders), fn (int $i): bool => is_array($renders[$i]['frame']['scene'] ?? null)));

            $this->assertNotSame([], $drawn, "[{$run}] no frame drew a scene");

            $picked = [end($drawn)];

            if ($walking) {
                $flying = array_values(array_filter($drawn, fn (int $i): bool => array_filter(
                    $renders[$i]['frame']['scene']['effects'], static fn (array $fx): bool => ($fx['legs'] ?? []) !== []) !== []));

                $this->assertNotSame([], $flying, "[{$run}] no scene has a walker in flight — the run walks nobody");

                for ($k = 0; $k < self::WALKING_SCENES; $k++) {
                    $picked[] = $flying[intdiv($k * (count($flying) - 1), max(1, self::WALKING_SCENES - 1))];
                }
            }

            foreach (array_unique($picked) as $i) {
                $scenes[] = ['run' => $run, 'i' => $i, 'scene' => $renders[$i]['frame']['scene']];
            }
        }

        return self::$scenes[$tree] = $scenes;
    }

    /**
     * @param  array{legs: array<string, list<string>>}  $world
     * @return list<string>
     */
    private function defects(array $world): array
    {
        return array_merge(...array_values($world['legs']));
    }
}

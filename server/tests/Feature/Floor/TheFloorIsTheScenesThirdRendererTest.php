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
 * flight — and `floor-world-probe.mjs` reads the world off each, the interns' seats on their couches included (the
 * couch measured off the kit's file, at one intern and at the cap).
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

    /**
     * The runs, each with what its scenes are picked for besides its last: `null` the last alone; `walkers` a walker in
     * flight; `doors` an elevator door span; `restroom` a restroom door with a visit on its timeline; `birds` a bird or a
     * ring in flight; `desk` a desk's own row in flight (A5, A11, A24). The walking runs are AT-D3-23's (`fx-elevator`), AT-D3-31's (`fx-intern-walk`), AT-D3-32's
     * (`fx-snack`), AT-D3-33's (`fx-restroom`) and AT-D3-36's (`fx-turn-back`) — replayed so their walks lift to legal
     * points (card#13033 stage 2 slice 5).
     */
    private const RUNS = [
        'scene_default' => null,
        'scene_overflow' => null,
        'interns_cap' => null,
        'frame_planned' => null,
        'leave-stale' => 'doors',
        'return-from-stale' => 'walkers',
        'two-walkers' => 'walkers',
        'crossing' => 'walkers',
        'mass-departure' => 'doors',
        'arrive' => 'walkers',
        'leave-working' => 'walkers',
        'flip' => 'desk',
        'two-interns' => 'walkers',
        'idle-trip' => 'walkers',
        'busy-trip' => 'walkers',
        'intern-trip' => 'walkers',
        'two-visitors' => 'walkers',
        'idle-visit' => 'restroom',
        'intern-visit' => 'restroom',
        'two-at-the-door' => 'walkers',
        'queue-of-three' => 'walkers',
        'turn-mid-floor' => 'walkers',
        'turn-twice' => 'walkers',
        'turn-from-restroom' => 'walkers',
        'turn-with-interns' => 'walkers',
        'coord' => 'birds',
        'coord_portal' => 'birds',
    ];

    /** How many scenes each picking run hands the probe, spread across the scenes that hold what it is picked for. */
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
            'a desk\'s chair stood in front of its slab, not behind it with its sitter' => ['../floor/floor-world.js',
                "chair: part('chair', () => sitterZ),", "chair: part('chair', () => plan.y + plan.h * 2),", 'parts', false],
            'a standing piece placed at its cell\'s top, not its foot' => ['../floor/floor-world.js',
                'at: at({ x: s.x + s.w / 2, y: s.y + s.h }),', 'at: at({ x: s.x + s.w / 2, y: s.y }),', 'pieces', false],
            'a standing walker stood a cell back and across from its spot (into its snack table)' => ['../floor/route.js',
                '        return { x: leg.at.x, y: leg.at.y, where, leg: k };', '        return { x: leg.at.x + 48, y: leg.at.y - 64, where, leg: k };', 'lattice', false],
            'the elevator\'s leaves closing after their span, not by its end' => ['../floor/floor-world.js',
                'Object.freeze({ start: d.to - ELEVATOR_DOOR.close, frames: ELEVATOR_DOOR.close, from: 1, to: 0 }),',
                'Object.freeze({ start: d.to, frames: ELEVATOR_DOOR.close, from: 1, to: 0 }),', 'doors', false],
            'a restroom door\'s rect stood from the tile\'s top, not its foot' => ['../floor/floor-world.js',
                'const onTile = (r) => Object.freeze({ x: at(r).x, w: r.w / UNIT, y: (foot - (r.y + r.h)) / UNIT, h: r.h / UNIT });',
                'const onTile = (r) => Object.freeze({ x: at(r).x, w: r.w / UNIT, y: (r.y - d.y) / UNIT, h: r.h / UNIT });', 'restroom', false],
            'A20\'s ring not carried into the world' => ['../floor/floor-world.js',
                "} else if (fx.animation_id === 'A20' && fx.at !== null && fx.at !== undefined) {",
                "} else if (fx.animation_id === 'A20-not' && fx.at !== null && fx.at !== undefined) {", 'effects', false],
            'an intern\'s laptop fade keyed by its seat, not its intern' => ['../floor/floor-world.js',
                "effects.push(Object.freeze({ kind: 'desk', key: fx.animation_id === 'A24' ? fx.intern_key : keyOf(fx), effect: fx }));",
                "effects.push(Object.freeze({ kind: 'desk', key: keyOf(fx), effect: fx }));", 'effects', false],
            'an intern stood on the floor, not on its couch\'s seat (inside the couch)' => ['../floor/floor-world.js',
                'y: topOf(slab) + internLift }),', 'y: topOf(slab) }),', 'seating', false],
            'a couch as wide as its side table\'s rect, its arms over the end interns' => ['../floor/floor-world.js',
                '        const left = p.x - COUCH_MARGIN;
        const right = p.x + (last.x + last.w - first.x) / UNIT + COUCH_MARGIN;', '        const left = at({ x: e.x, y: foot }).x + COUCH_SCALE * COUCH.seat.left;
        const right = at({ x: e.x + e.w, y: foot }).x - COUCH_SCALE * (COUCH.w - COUCH.seat.right);', 'seating', false],
            'the couch\'s seat height guessed, not the file\'s' => ['../floor/floor-world.js',
                'seat: Object.freeze({ y: 0.23,', 'seat: Object.freeze({ y: 0.2,', 'seating', false],
            'a bird flown along the chord, its arc\'s lift dropped' => ['../floor/floor-world.js',
                'y: lerp(bird.from.y, bird.to.y) + bird.lift * 4 * u * (1 - u),', 'y: lerp(bird.from.y, bird.to.y),', 'birds', false],
            'the birdhouse\'s picture row read as depth, not height' => ['../floor/floor-world.js',
                'const onWall = (p) => Object.freeze({ x: at(p).x, y: (top - p.y + BIRD_LIFT) / UNIT, z: slab.box.z + BIRD_DEPTH });',
                'const onWall = (p) => Object.freeze({ x: at(p).x, y: DESK_H_U + BIRD_LIFT / UNIT, z: at(p).z });', 'birds', false],
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

        foreach (self::RUNS as $run => $pick) {
            $renders = $this->floorRun($run, $dir)['floor_renders'];
            $drawn = array_values(array_filter(array_keys($renders), fn (int $i): bool => is_array($renders[$i]['frame']['scene'] ?? null)));

            $this->assertNotSame([], $drawn, "[{$run}] no frame drew a scene");

            $picked = [end($drawn)];

            if ($pick !== null) {
                $holds = fn (array $scene): bool => match ($pick) {
                    'walkers' => array_filter($scene['effects'], static fn (array $fx): bool => ($fx['legs'] ?? []) !== []) !== [],
                    'doors' => ($scene['doors'] ?? []) !== [],
                    'restroom' => array_filter($scene['restroom_doors'] ?? [], static fn (array $d): bool => $d['leaf'] !== []) !== [],
                    'birds' => array_filter($scene['effects'], static fn (array $fx): bool => in_array($fx['animation_id'], ['A19', 'A20', 'A22'], true)) !== [],
                    'desk' => array_filter($scene['effects'], static fn (array $fx): bool => in_array($fx['animation_id'], ['A5', 'A11', 'A24'], true)) !== [],
                };
                $holding = array_values(array_filter($drawn, fn (int $i): bool => $holds($renders[$i]['frame']['scene'])));

                $this->assertNotSame([], $holding, "[{$run}] no scene holds {$pick} — the run was not read for what it is here for");

                for ($k = 0; $k < self::WALKING_SCENES; $k++) {
                    $picked[] = $holding[intdiv($k * (count($holding) - 1), max(1, self::WALKING_SCENES - 1))];
                }
            }

            foreach (array_unique($picked) as $i) {
                $scenes[] = ['run' => $run, 'i' => $i, 'walking' => $pick === 'walkers', 'scene' => $renders[$i]['frame']['scene']];
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

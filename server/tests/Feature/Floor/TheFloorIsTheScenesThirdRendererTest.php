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
 * couch measured off the kit's file, at one intern and at the cap), and each intern walker's rise onto its seat as it
 * steps back and fall off it as it steps off (card#13033).
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
        'capped-gain' => 'walkers',
        'idle-trip' => 'walkers',
        'busy-trip' => 'walkers',
        'intern-trip' => 'walkers',
        'two-visitors' => 'walkers',
        'idle-visit' => 'restroom',
        'intern-visit' => 'restroom',
        'back-visit' => 'restroom',
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
                'return { at: at({ x: s.x + s.w / 2, y: s.y + s.h }) };', 'return { at: at({ x: s.x + s.w / 2, y: s.y }) };', 'pieces', false],
            'a side restroom door\'s piece placed without its turn, its front to the floor\'s front beside the wall' => ['../floor/floor-world.js',
                "turn: d.facing === 'right' ? -Math.PI / 2 : Math.PI / 2,", 'turn: 0,', 'pieces', false],
            'a side restroom door\'s lintel left solid, a wall cell standing through the doorway' => ['../floor/floor-world.js',
                'const y = door === undefined ? 0 : door.h / UNIT;', 'const y = 0;', 'restroom', false],
            'the obstacle rule keeping a side door\'s tile, so a walker at its threshold stands in it (the lattice read off obstaclesOf())' => ['../floor/map-tiles.js',
                "(c.kind !== RESTROOM_DOOR.kind || geometryOf(c).facing === 'back')", 'true', 'lattice', false],
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
            'a couch as deep as the file at the couch\'s height scale, over its aisle' => ['../floor/floor-world.js',
                '            d: plan.h / UNIT,', '            d: COUCH_SCALE * COUCH.d,', 'seating', false],
            'an intern lifted onto its seat only at its foot, not as it steps back (the pop)' => ['../floor/floor-world.js',
                'const lift = step ? seat.lift * Math.min(1, Math.max(0, (seat.front - p.y) / (seat.front - seat.foot.y))) : 0;',
                'const lift = step && Math.abs(p.y - seat.foot.y) < 1e-6 ? seat.lift : 0;', 'steps', false],
            'an intern never lifted off the floor as it steps onto its seat' => ['../floor/floor-world.js',
                'const lift = step ? seat.lift * Math.min(1, Math.max(0, (seat.front - p.y) / (seat.front - seat.foot.y))) : 0;',
                'const lift = 0;', 'steps', false],
            'a bird flown along the chord, its arc\'s lift dropped' => ['../floor/floor-world.js',
                'y: lerp(bird.from.y, bird.to.y) + bird.lift * 4 * u * (1 - u),', 'y: lerp(bird.from.y, bird.to.y),', 'birds', false],
            'the cab as deep as it is wide' => ['../floor/floor-world.js',
                'const [w, d, h] = [door.w, cmU(CAB.d), door.h];', 'const [w, d, h] = [door.w, door.w, door.h];', 'cab', false],
            'the cab at its own size behind the doorway, its corners behind the band' => ['../floor/floor-world.js',
                'const [w, d, h] = [door.w, cmU(CAB.d), door.h];', 'const [w, d, h] = [cmU(CAB.w), cmU(CAB.d), Math.min(cmU(CAB.h), ROOM_H_U)];', 'cab', false],
            'the cab stood in front of the band, not behind it' => ['../floor/floor-world.js',
                'const room = Object.freeze({ x: door.x, y: 0, z: z - d, w, h, d });', 'const room = Object.freeze({ x: door.x, y: 0, z, w, h, d });', 'cab', false],
            'the cab started at the leaves\' seam, not the doorway\'s left jamb' => ['../floor/floor-world.js',
                'cab: cabRoom({ x: at({ x: band.elevator.x, y: top }).x,', 'cab: cabRoom({ x: at({ x: band.elevator.seam, y: top }).x,', 'cab', false],
            'the handrail\'s height at the door view\'s scale, not fitted to the doorway' => ['../floor/floor-world.js',
                'const y = rail.y * up - s / 2;', 'const y = cmU(rail.y) - s / 2;', 'cab', false],
            'a handrail stood off the outside of its wall' => ['../floor/floor-world.js',
                'box(wall + into * off - s / 2, y,', 'box(wall - into * off - s / 2, y,', 'cab', false],
            'a rider stepping in the cab carried past its far wall' => ['../floor/route.js',
                'return { x: step.from.x + (step.to.x - step.from.x) * u, y: step.from.y + (step.to.y - step.from.y) * u, where, leg: k };',
                'return { x: step.from.x + (step.to.x - step.from.x) * u, y: step.from.y + (step.to.y - step.from.y) * u - 200, where, leg: k };', 'cab', false],
            'a rider hidden the moment it leaves the floor (the cab drawn empty as its leaves close)' => ['../floor/floor-world.js',
                "const shown = p.where === 'floor' || (ride !== null && tMs >= ride.from && tMs < ride.to);", "const shown = p.where === 'floor';", 'cab', false],
            'a rider kept in the cab once its leaves have shut (riding on, seen through the band)' => ['../floor/floor-world.js',
                "const shown = p.where === 'floor' || (ride !== null && tMs >= ride.from && tMs < ride.to);", "const shown = p.where !== 'inside';", 'cab', false],
            'the cab span read from the cab legs\' starts alone (a boarder gone as its last leg begins)' => ['../floor/route.js',
                'to: Math.max(...legs.map((l) => l.start + l.frames)) * I,', 'to: Math.max(...legs.map((l) => l.start)) * I,', 'cab', false],
            'the birdhouse\'s picture row read as depth, not height' => ['../floor/floor-world.js',
                'const onWall = (p) => Object.freeze({ x: at(p).x, y: (top - p.y + BIRD_LIFT) / UNIT, z: slab.box.z + BIRD_DEPTH });',
                'const onWall = (p) => Object.freeze({ x: at(p).x, y: DESK_H_U + BIRD_LIFT / UNIT, z: at(p).z });', 'birds', false],
        ];
    }

    /**
     * The bodies' routes the scenes must hold, so a green over them read every one: a seated character, an intern on its
     * couch, a seat and an intern walking to and from the elevator, and each on a snack and a restroom break.
     */
    private const IDENTITY_ROUTES = ['desk', 'stool', 'A1', 'A2', 'A10 intern', 'A25', 'A25 intern', 'A26', 'A26 intern'];

    /**
     * ⛔ ONE CHARACTER IN BOTH VIEWS — every body the 2-D painter draws and the 3-D floor builds over these scenes is the
     * same character, field by field (`character-identity-probe.mjs`): the operator saw a seat's interns wear its own body
     * in 3-D, where 2-D kept them off it — and, since card#13258, every intern is drawn in both in its delivered species.
     */
    public function test_green_one_character_in_both_views(): void
    {
        $answer = $this->identity();

        $this->assertSame([], $this->defects($answer));
        $this->assertSame([], array_values(array_diff(self::IDENTITY_ROUTES, array_keys($answer['printed']['routes']))),
            'a route the scenes hold no body on was read for nothing: '.json_encode($answer['printed']['routes']));
        $this->assertSame($answer['printed']['bodies'], $answer['printed']['compared'], 'a body was not compared across the views');
        // card#13258: every intern the scenes hold is delivered a species apart from its key's own draw, so a view that
        // re-drew one from its key would be seen by the delivered leg.
        $this->assertGreaterThan(0, $answer['printed']['delivered']['interns'], 'the scenes hold no intern — the delivered leg reads nothing');
        $this->assertSame($answer['printed']['delivered']['interns'], $answer['printed']['delivered']['apart'],
            'an intern of the scenes is delivered its key\'s own draw, where a re-draw from the key is invisible: '.json_encode($answer['printed']['delivered']));
    }

    /**
     * Each plant moves one view's character off the other's, and reds the leg that names it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function identityPlants(): array
    {
        return [
            'a 3-D intern built in its seat\'s body (the operator\'s report, before card#13258)' => ['../floor/floor-world.js',
                '                species: e.species,', '                species: character?.species ?? null,', 'delivered'],
            'a 3-D intern built in its key\'s own body, its delivered species dropped' => ['../floor3d/body-paint.js',
                'createCreature(document, C, key, { species: spec.species,', 'createCreature(document, C, key, { species: spec.chibi ? undefined : spec.species,', 'delivered'],
            'a 3-D walking intern built as a seat' => ['../floor3d/body-paint.js',
                'out.set(key, { key, species, chibi: w.chibi,', 'out.set(key, { key, species, chibi: false,', 'kind'],
            'a 3-D face drawn as the key\'s own species, not its body\'s' => ['../floor3d/creature-paint.js',
                'C.faceDocument(key, { species: body.species, pose, blink })', 'C.faceDocument(key, { species: undefined, pose, blink })', 'parts'],
            'a 2-D couch intern drawn under its seat\'s key' => ['../floor/painter.js',
                "characterFrames(e.key, e.asset, e.species, null, 'laptop');", "characterFrames(desk.seat_id, e.asset, e.species, null, 'laptop');", 'kind'],
            'a 2-D couch intern drawn in its key\'s own body' => ['../floor/painter.js',
                'characters.internFrames(seatId, species)', 'characters.internFrames(seatId)', 'delivered'],
        ];
    }

    #[DataProvider('identityPlants')]
    public function test_red_each_identity_plant_reds_its_leg(string $file, string $anchor, string $replacement, string $leg): void
    {
        $legs = $this->identity($this->mutatedModules([$file, $anchor, $replacement]))['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }

    /**
     * A seat object with no delivered species, and an intern with none, are § 9 F14's in both views — no body for either,
     * never the key's own draw and never the seat's, each reported under its own asset — and the 3-D guard that keeps it
     * so is seen red when it is planted away (`recipe()` draws the key's own body for an absent species).
     */
    public function test_a_character_with_no_species_has_no_body_in_either_view(): void
    {
        // The first scene with a desk that draws a character and seats an intern, that desk's seat object stripped.
        [$scene, $d] = (function (): array {
            foreach ($this->scenes(null) as $scene) {
                foreach ($scene['scene']['desks'] as $d => $desk) {
                    $kinds = array_column($desk['elements'], 'kind');

                    if (in_array('character', $kinds, true) && in_array('stool', $kinds, true)) {
                        return [$scene, $d];
                    }
                }
            }

            $this->fail('no scene holds a desk with a character and an intern — the leg reads nothing');
        })();
        $desk = &$scene['scene']['desks'][$d];
        $owed = [];

        foreach ($desk['elements'] as &$e) {
            if ($e['kind'] === 'character') {
                $e['species'] = null;
                $owed[] = 'character:'.$desk['key'];
            }

            if ($e['kind'] === 'stool') {
                $e['species'] = null;
                $owed[] = 'intern:'.$e['key'];
            }
        }
        unset($e, $desk);

        $script = __DIR__.'/character-identity-probe.mjs';
        $answer = $this->probe(['scenes' => [$scene]], null, $script);

        $this->assertSame([], $this->defects($answer));

        foreach (['2d', '3d'] as $view) {
            $this->assertSame([], array_values(array_diff($owed, $answer['printed']['failed'][$view])), "the {$view} view did not report every character with no species");
        }

        $planted = $this->probe(['scenes' => [$scene]], $this->mutatedModules(['../floor3d/body-paint.js',
            "if (typeof spec.species !== 'string') {", 'if (false) {']), $script)['legs'];

        $this->assertNotSame([], $planted['coverage'], 'the plant did not red the coverage leg: '.json_encode(array_filter($planted)));
    }

    /**
     * The tree's own drawing is read, not its rule: the tree's intern entries re-drawing an intern from its key — in 2-D
     * alone, the views part (`fields`); in both views alike (`chibiFrame()`, `internFrames()` and `chibiParts()` all
     * dropping the species they are handed), parity holds and only the `delivered` leg sees the intern out of its body.
     */
    public function test_red_the_tree_drawing_an_intern_from_its_key(): void
    {
        $twoD = [
            ['index.js', "export function chibiFrame(internKey, species) {\n  return frameDocument(seatKey(internKey), { chibi: true, species });",
                "export function chibiFrame(internKey, species) {\n  return frameDocument(seatKey(internKey), { chibi: true });"],
            ['index.js', "export function internFrames(internKey, species) {\n  const key = seatKey(internKey);",
                "export function internFrames(internKey, species) {\n  species = undefined;\n  const key = seatKey(internKey);"],
        ];
        $threeD = [['creatures-3d.js', "export function chibiParts(key, { species } = {}) {\n  return bodyParts(key, { species, chibi: true });",
            "export function chibiParts(key, { species } = {}) {\n  return bodyParts(key, { chibi: true });"]];

        $legs = $this->identity(null, $this->plantedTree($twoD))['legs'];

        $this->assertNotSame([], $legs['fields'], 'the 2-D tree plant did not red the fields leg: '.json_encode(array_filter($legs)));
        $this->assertNotSame([], $legs['delivered'], 'the 2-D tree plant did not red the delivered leg: '.json_encode(array_filter($legs)));

        $legs = $this->identity(null, $this->plantedTree([...$twoD, ...$threeD]))['legs'];

        $this->assertNotSame([], $legs['delivered'], 'the both-views tree plant did not red the delivered leg: '.json_encode(array_filter($legs)));
        $this->assertSame([], $legs['fields'], 'the both-views tree plant parted the views — the control no longer shows what parity alone misses');
    }

    /** A copy of the character tree with each `[file, anchor, replacement]` applied once. */
    private function plantedTree(array $edits): string
    {
        $tree = (string) tempnam(sys_get_temp_dir(), 'tree');
        unlink($tree);
        mkdir($tree);
        $this->scratchDirs[] = $tree;
        $this->copyTree((string) realpath(__DIR__.'/../../../../resources/characters'), $tree);

        foreach ($edits as [$file, $anchor, $replacement]) {
            $original = (string) file_get_contents($tree.'/'.$file);

            $this->assertSame(1, substr_count($original, $anchor), "the tree plant's anchor is not in {$file} exactly once");
            file_put_contents($tree.'/'.$file, str_replace($anchor, $replacement, $original));
        }

        return $tree;
    }

    /**
     * The identity probe's answer over the shipped scenes, its client modules `$dir`'s and its character tree `$tree`'s.
     *
     * @return array{legs: array<string, list<string>>, printed: array<string, mixed>}
     */
    private function identity(?string $dir = null, ?string $tree = null): array
    {
        $payload = ['scenes' => $this->scenes(null)];

        if ($tree === null) {
            return $this->probe($payload, $dir, __DIR__.'/character-identity-probe.mjs');
        }

        return $this->probe($payload + ['tree' => $tree], $dir, __DIR__.'/character-identity-probe.mjs');
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

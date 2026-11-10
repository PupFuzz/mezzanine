<?php

namespace Tests\Feature\Building;

use Tests\Feature\Floor\DrivesTheFleetClientModule;
use Tests\Feature\Support\WalksAPagesImportGraph;
use Tests\TestCase;

/**
 * **AT-D3-37 — the building view stacks the floors as the floor page draws them.** `docs/design/FLOOR.md § 11`, gated at
 * Appendix B row 47 (card#13033 slice 3; the derivation is `docs/design/plan-13033-3d-view.md` § 8 items 2 and 3): the
 * building screen (`building/building-screen.js`) over the harness's `building` entry, the stack model
 * (`building/stack-model.js`) through `stack-model-probe.mjs`, and the 3-D acts (`building/building-acts.js`) through
 * `building-acts-probe.mjs`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUN IS `fixtures/fx-camera.json`'s `building_stack` (its note states it), entered at `/building`; the floor
 * page's half of every equality is the SAME run entered at `/floor/{key}` through the harness's `floor` entry, one key at a
 * time — so an equality is known to be reachable, and the slab shows what the floor page shows.
 *
 * ⛔ THE GRAPH BOUND IS WALKED FROM THE PAGE's ENTRY, `building/main.js` (slice 4; until it landed the walk started at
 * the screen, the acts and the model). Its paths to the log and the set (`PATH`): `wire/live-page.js` constructs the
 * page's bounded log, and `desk/desk-floor.js` the set — alone; the two constants-only importers are each held to their
 * one exact import line (`CONSTANTS`). The walk follows the painter's art loader into the asset route
 * (`WalksAPagesImportGraph`), since every floor's picture is the floor page's painter's.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: the texture's pixels, the WebGL context, the labels on the stage and in the accessibility tree,
 * and the page's gated `show` over the real `wire/camera-view.js` — the browser leg's (`tools/design/building-view.browser.mjs`)
 * and `BuildingPageWiringTest`'s. The acts' probe stands in for the page's `show` as plan § 3.3 item 2 specifies it, and
 * says so in its header. The picture's two pure functions are `picture-probe.mjs`'s.
 */
class TheBuildingViewStacksTheFloorsTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use WalksAPagesImportGraph;

    private const RUN = 'building_stack';

    private const SCREEN = '../building/building-screen.js';

    private const MODEL = '../building/stack-model.js';

    private const ACTS = '../building/building-acts.js';

    /** The page's entry — the walk's root (see the header). */
    private const ROOTS = ['building/main.js'];

    /** The building's paths to the animation log and the set: importer => the module it may load. */
    private const PATH = ['wire/live-page.js' => 'wire/animation-log.js', 'desk/desk-floor.js' => 'wire/animation-set.js'];

    /** The importers of the set's module that read constants and pure readers out of it, each held to its exact line. */
    private const CONSTANTS = [
        'floor/scene.js' => "import { ANIMATION_SET, LOOP_FPS, SNACK_HELD, loops } from '../wire/animation-set.js';",
        'desk/desk-render.js' => "import { SNACK_HELD, heldRendering } from '../wire/animation-set.js';",
    ];

    /** The members a slab carries, and a label: a picture and its links, no fact. */
    private const SLAB_MEMBERS = ['floor', 'name', 'href', 'level', 'storey', 'box', 'picture', 'extent'];

    private const LABEL_MEMBERS = ['key', 'install_id', 'seat_id', 'text', 'at', 'href'];

    private const FRAME_MEMBERS = ['floors', 'stack', 'strip', 'failure', 'statement', 'layout'];

    /** How long a model probe may run: several times its measured run on this tree, and far under a hang. */
    private const PROBE_TIMEOUT_S = 120.0;

    /** @var array<string, array<string, mixed>> the shipped runs, replayed once per process */
    private static array $shipped = [];

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    /** The Build bullet's run, as the fixture states it. */
    public function test_green_the_run_is_the_build_bullets(): void
    {
        $fx = $this->fixture(self::RUN);
        $floors = $fx['http']['/api/building'][0]['body']['layout']['floors'];

        $this->assertSame(['aimla', 'zeta', 'theta'], array_column($floors, 'floor'));
        $this->assertSame(['cottage'], $fx['building']['scene']['themes']['reject']);
        $this->assertContains('cottage', array_column($floors, 'theme'), 'no floor is named in the rejected theme');
        $this->assertSame(503, $fx['http']['/api/building/rooms/theta/map'][0]['status']);
        $this->assertCount(2, $fx['http']['/api/building/rooms/aimla/map']);
        $this->assertSame(['feed.heartbeat', 'seat.delta', 'room.map'], array_values(array_filter(array_map(static fn (array $m): ?string => $m['envelope']['t'] ?? null, $fx['messages']))));
    }

    /** One slab per directory row, the first row at the top storey, the lobby the ground slab, a picture exactly where there is an extent. */
    public function test_green_one_slab_per_floor_in_the_directorys_order(): void
    {
        $this->assertSame([], $this->stackDefects($this->building()));
    }

    /** Each slab's extent is the floor page's for that floor, and its notices are the floor page's. */
    public function test_green_each_slab_is_the_floor_the_floor_page_draws(): void
    {
        $this->assertSame([], $this->floorPageDefects($this->building()));
    }

    /** The `room.map` re-fetches its room alone and the next scene is the authored map's; the failed room is asked once. */
    public function test_green_a_room_map_refreshes_its_slab_and_asks_for_nothing_else(): void
    {
        $this->assertSame([], $this->roomMapDefects($this->building()));
    }

    /** The frame and the scene input carry exactly what `buildScene()` reads, assembled by the floor screen's function. */
    public function test_green_the_scene_is_assembled_as_the_floor_screens(): void
    {
        $this->assertSame([], $this->memberDefects($this->building(), $this->jsRoot()));
    }

    /** The rejected theme's floor has failed art, in the floor page's words, and no other floor has. */
    public function test_green_the_rejected_theme_fails_its_own_floor(): void
    {
        $this->assertSame([], $this->themeDefects($this->building()));
    }

    /** One label per desk the scene holds, its nameplate's text at its centre, linked by the floor's key. */
    public function test_green_each_desk_is_labelled_from_its_nameplate(): void
    {
        $this->assertSame([], $this->labelDefects($this->building()));
    }

    /** A picture and no fact: no camera, no ride, no member beyond the picture's, every scene a still. */
    public function test_green_the_view_adds_a_picture_and_no_fact(): void
    {
        $this->assertSame([], $this->factDefects($this->building()));
    }

    /** One drain per render, and the log the fixture's own rows — a client that never enters /building writes the same. */
    public function test_green_one_drain_per_render_and_the_fixtures_own_log(): void
    {
        $this->assertSame([], $this->logDefects($this->building()));
    }

    public function test_green_the_building_reaches_the_set_only_by_its_paths(): void
    {
        $this->assertSame([], $this->graphDefects($this->jsRoot()));
    }

    /** @return array<string, array{0: string}> */
    public static function modelLegs(): array
    {
        return array_combine($legs = ['storey', 'focus', 'paths', 'corner', 'snap', 'cull', 'rest'], array_map(static fn (string $l): array => [$l], $legs));
    }

    /** Plan § 8 item 3: the storey, the focus pose, the paths, the corner, the snap table, the cull and the rest. */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelLegs')]
    public function test_green_the_stack_models_leg(string $leg): void
    {
        $this->assertSame([], $this->modelProbe($this->buildingDir($this->jsRoot()))['legs'][$leg]);
    }

    /**
     * Slice 3's review note 2: the acts read the stage from the stack, so a resize must reach them at once — the screen's
     * `resize()` answers the stack re-derived from the last draw's floors over the new stage.
     */
    public function test_green_a_resize_answers_the_stack_over_the_new_stage(): void
    {
        $this->assertSame([], $this->resizeDefects($this->resized(null)));
    }

    /** The floor picture's two pure functions — `tokenNames()` and `pictureSvg()` (plan § 3.2 steps 1 and 4). */
    public function test_green_the_picture_inlines_every_token_once(): void
    {
        $this->assertSame([], $this->pictureProbe($this->buildingDir($this->jsRoot()))['legs']['picture']);
    }

    /** The seat's ruling of 2026-11-10 on plan § 10: a floor's picture repainted at most once per `PICTURE_MIN_INTERVAL_MS`. */
    public function test_green_each_floor_is_repainted_at_most_once_an_interval(): void
    {
        $this->assertSame([], $this->pictureProbe($this->buildingDir($this->jsRoot()))['legs']['interval']);
    }

    /** Plan § 8 item 3: the acts, under stand-in gestures and a stub clock, and the mute over a stream that outlives the glide. */
    public function test_green_the_acts_snap_once_per_gesture(): void
    {
        $out = $this->actsProbe($this->buildingDir($this->jsRoot()));

        $this->assertSame([], $out['legs']['acts']);
        $this->assertSame([], $out['legs']['mute']);
    }

    // ── RED — each defect planted in the shipped module it would live in, and watched failing ──

    /** § 11's RED — the recounted floor: a slab labelled from the row's held count. */
    public function test_red_the_recounted_floor(): void
    {
        $dir = $this->mutatedModules([self::MODEL, '        name: row.name,', '        name: `${row.name} (${row.held})`,']);

        $this->assertNotSame([], $this->factDefects($this->replayed($dir)), 'the RED (the recounted floor) did not bite');
    }

    /** Second RED — the swapped order. */
    public function test_red_the_swapped_order(): void
    {
        $dir = $this->mutatedModules([self::MODEL, 'const storeyOf = (level) => n - 1 - level;', 'const storeyOf = (level) => level;']);

        $this->assertNotSame([], $this->stackDefects($this->replayed($dir)), 'the second RED (the swapped order) did not bite');
    }

    /** Third RED — the frame the floor page would not draw: a member `buildScene()` reads dropped from the frame. */
    public function test_red_a_frame_member_dropped(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "            room: this.#room.tick,\n", '']);

        $this->assertNotSame([], $this->memberDefects($this->replayed($dir), dirname($dir)), 'the third RED (a frame member dropped) did not bite');
    }

    /** Third RED, its other half: an input member dropped from the scene input's documents. */
    public function test_red_a_scene_input_member_dropped(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "            hallway: row.hallway ?? null,\n", '']);

        $this->assertNotSame([], $this->memberDefects($this->building(), dirname($dir)), 'the third RED (an input member dropped) did not bite');
    }

    /** Fourth RED — the ride on the roof. */
    public function test_red_the_ride_on_the_roof(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "            layout: this.#building.layoutState,\n",
            "            layout: this.#building.layoutState,\n            ride: new Ride({ clock: this.#client, stops: () => [] }).rideFrame(0),\n"]);
        $target = $dir.'/'.self::SCREEN;
        file_put_contents($target, "import { Ride } from '../wire/ride.js';\n".file_get_contents($target));

        $this->assertNotSame([], $this->factDefects($this->replayed($dir)), 'the fourth RED (the ride on the roof) did not bite');
        $this->assertNotSame([], $this->graphDefects(dirname($dir)), 'the fourth RED did not reach the graph bound');
    }

    /** Fifth RED — the journal drained twice: the desk floor's own `render()` in place of `renderWith(journal)`. */
    public function test_red_the_journal_drained_twice(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'const desks = this.#desks.renderWith(journal);', 'const desks = this.#desks.render();']);

        $this->assertNotSame([], array_filter($this->logDefects($this->replayed($dir)), static fn (string $d): bool => str_contains($d, 'drained the journal')),
            'the fifth RED (the journal drained twice) did not bite for its reason');
    }

    /** Seventh RED — the map that never refreshes. */
    public function test_red_the_map_that_never_refreshes(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "            if (entry.t === 'room.map') {", "            if (entry.t === 'room.map' && false) {"]);

        $this->assertNotSame([], $this->roomMapDefects($this->replayed($dir)), 'the seventh RED (the map that never refreshes) did not bite');
    }

    /** Eighth RED — the pose through a floor: the focus tilt straight down. */
    public function test_red_the_pose_through_a_floor(): void
    {
        $legs = $this->modelProbe($this->buildingDir(dirname($this->mutatedModules([self::MODEL, 'export const FOCUS_POLAR = HOME_POLAR;', 'export const FOCUS_POLAR = 0;']))))['legs'];

        $this->assertNotSame([], array_filter($legs['focus'], static fn (string $d): bool => str_contains($d, '(1)')), 'the eighth RED did not fail the in-front clause');
        $this->assertNotSame([], $legs['paths'], 'the eighth RED did not cross a slab between two poses');
    }

    /** Ninth RED — the pose that cannot be read: the focus distance doubled. */
    public function test_red_the_pose_that_cannot_be_read(): void
    {
        $legs = $this->modelProbe($this->buildingDir(dirname($this->mutatedModules([self::MODEL, '    return orbitCamera(target, FOCUS_POLAR, 0, far);', '    return orbitCamera(target, FOCUS_POLAR, 0, far * 2);']))))['legs'];

        $this->assertNotSame([], array_filter($legs['focus'], static fn (string $d): bool => str_contains($d, '(4)')), 'the ninth RED (the pose that cannot be read) did not bite');
    }

    /** Tenth RED — the glide through the stack: the camera interpolated in a straight line. */
    public function test_red_the_glide_through_the_stack(): void
    {
        $dir = $this->mutatedModules([self::MODEL,
            '            position: Object.freeze({ x: axis.x + r * Math.sin(angle), y: lerp(a.y, b.y, t), z: axis.z + r * Math.cos(angle) }),',
            '            position: Object.freeze({ x: lerp(from.camera.position.x, to.camera.position.x, t), y: lerp(a.y, b.y, t), z: lerp(from.camera.position.z, to.camera.position.z, t) }),']);

        $this->assertNotSame([], $this->modelProbe($this->buildingDir(dirname($dir)))['legs']['paths'], 'the tenth RED (the glide through the stack) did not bite');
    }

    /** Tenth RED, its other half: the acts' mute dropped — round 4's M1 as a plant. */
    public function test_red_the_acts_mute_dropped(): void
    {
        $dir = $this->mutatedModules([self::ACTS, "        muted = true;\n\n        return snapped(", "        return snapped("]);

        $this->assertNotSame([], $this->actsProbe($this->buildingDir(dirname($dir)))['legs']['mute'], 'the tenth RED (the mute dropped) did not bite');
    }

    /** Fourteenth RED, the probe's half — the planted overlap: the cull keeping every label. */
    public function test_red_the_planted_overlap(): void
    {
        $dir = $this->mutatedModules([self::MODEL, '        if (kept.some((key) => overlaps(boxes.get(key), box))) {', '        if (false) {']);

        $this->assertNotSame([], $this->modelProbe($this->buildingDir(dirname($dir)))['legs']['cull'], 'the fourteenth RED (the planted overlap) did not bite');
    }

    /** Fifteenth RED, the probe's half — the link that lies: the *Read this floor* predicate inverted. */
    public function test_red_the_link_that_lies(): void
    {
        $dir = $this->mutatedModules([self::MODEL, 'return slab === null || culled.size === 0 ?', 'return slab === null || culled.size !== 0 ?']);

        $this->assertNotSame([], $this->modelProbe($this->buildingDir(dirname($dir)))['legs']['cull'], 'the fifteenth RED (the link that lies) did not bite');
    }

    /** The review's G1: an unsized stage — a collapsed or hidden one — built into a stack, whose poses then have no aspect. */
    public function test_red_an_unsized_stage_that_builds_a_stack(): void
    {
        $dir = $this->mutatedModules([self::MODEL, 'if (held.every((extent) => extent === null) || !(surface.width > 0 && surface.height > 0)) {',
            'if (held.every((extent) => extent === null)) {']);

        $this->assertNotSame([], array_filter($this->modelProbe($this->buildingDir(dirname($dir)))['legs']['focus'], static fn (string $d): bool => str_contains($d, 'unsized')),
            'the RED (an unsized stage that builds a stack) did not bite for its reason');
    }

    /** The review's G2: a drag reading the resting view as it was, after the layout dropped the focused floor. */
    public function test_red_a_drag_that_reads_a_dropped_floor(): void
    {
        $dir = $this->mutatedModules([self::ACTS, "            resting = liveView(resting, live);\n\n", '']);

        $this->assertNotSame([], array_filter($this->actsProbe($this->buildingDir(dirname($dir)))['legs']['acts'], static fn (string $d): bool => str_contains($d, 'dropped the focused floor')),
            'the RED (a drag that reads a dropped floor) did not bite for its reason');
    }

    /** Slice 3's review note 2 as a plant: a resize that stores no stage — the acts would aim from the old one. */
    public function test_red_a_resize_the_acts_never_see(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        this.#surface = surface;\n\n        return this.#stack();", '        return this.#stack();']);

        $this->assertNotSame([], $this->resizeDefects($this->resized($dir)), 'the RED (a resize the acts never see) did not bite');
    }

    /** The interval removed: every render repaints every floor (the seat's ruling of 2026-11-10's plant). */
    public function test_red_the_interval_removed(): void
    {
        $dir = $this->mutatedModules(['../building/floor-picture.js', '        due: (key, t) => !last.has(key) || t - last.get(key) >= PICTURE_MIN_INTERVAL_MS,', '        due: () => true,']);
        $defects = $this->pictureProbe($this->buildingDir(dirname($dir)))['legs']['interval'];

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, 'more than the')), 'the RED (the interval removed) did not bite for its reason: '.json_encode($defects));
    }

    /** The gap read from the handler's clock (`performance.now()`), which a long task moves, in place of the event's time. */
    public function test_red_the_gap_read_from_the_handlers_clock(): void
    {
        $dir = $this->mutatedModules([self::ACTS, '        const t = at;', '        const t = performance.now();']);
        $defects = $this->actsProbe($this->buildingDir(dirname($dir)))['legs']['mute'];

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, 'handler stalled')), 'the RED (the gap read from the handler\'s clock) did not bite for its reason: '.json_encode($defects));
    }

    /** The keyboard's focus on another floor's label is the snap table's click on its slab (plan § 3.4): planted to stay. */
    public function test_red_a_focused_label_that_brings_no_floor(): void
    {
        $dir = $this->mutatedModules([self::MODEL, '? live : focus(intent.floor);', '? live : live;']);

        $this->assertNotSame([], array_filter($this->modelProbe($this->buildingDir(dirname($dir)))['legs']['snap'], static fn (string $d): bool => str_contains($d, 'label')),
            'the RED (a focused label that brings no floor) did not bite for its reason');
    }

    /** The picture's plants (plan § 8 item 3): an empty token written over the painter's fallback; a bare token missed; the root left at 100 %. */
    public function test_red_the_picture_plants(): void
    {
        foreach ([
            'an empty token written' => ["tokens.filter((t) => t.value !== '')", 'tokens', 'writes an empty token'],
            'the bare form missed' => ['/var\(--([a-z0-9_-]+)\s*([),])/gi', '/var\(--([a-z0-9_-]+)\s*(,)/gi', 'bare token'],
            'the root left at its percentages' => [".replace(/\\swidth=\"[^\"]*\"/, '')", '', 'is not sized'],
        ] as $what => [$from, $to, $reason]) {
            $defects = $this->pictureProbe($this->buildingDir(dirname($this->mutatedModules(['../building/floor-picture.js', $from, $to]))))['legs']['picture'];

            $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)), "CONTROL ({$what}) did not bite for its reason: ".json_encode($defects));
        }
    }

    /** The graph bound: the set constructed off the paths, and a constants-only importer reaching for the set. */
    public function test_red_a_module_that_reaches_the_set_off_the_paths(): void
    {
        foreach ([
            'the building screen importing the set' => [self::SCREEN, "import { stackModel } from './stack-model.js';\n", "import { stackModel } from './stack-model.js';\nimport { AnimationSet } from '../wire/animation-set.js';\n", 'building/building-screen.js loads wire/animation-set.js'],
            'the scene importing the set beside its constants' => ['../floor/scene.js', self::CONSTANTS['floor/scene.js'], "import { ANIMATION_SET, AnimationSet, LOOP_FPS, SNACK_HELD, loops } from '../wire/animation-set.js';", 'floor/scene.js imports'],
            'the desk floor no longer loading the set' => ['../desk/desk-floor.js', "import { AnimationSet } from '../wire/animation-set.js';\n", '', 'no longer loads'],
            'the page writing the log itself' => ['../building/main.js', "import { livePage } from '../wire/live-page.js';\n", "import { livePage } from '../wire/live-page.js';\nimport { createAnimationLog } from '../wire/animation-log.js';\n", 'building/main.js loads wire/animation-log.js'],
        ] as $what => [$file, $from, $to, $reason]) {
            $defects = $this->graphDefects(dirname($this->mutatedModules([$file, $from, $to])));

            $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)), "CONTROL ({$what}) did not bite for its reason: ".json_encode($defects));
        }
    }

    // ── the runs and the probes ────────────────────────────────────────────────────────────────

    /** The shipped `building_stack` run. @return array<string, mixed> */
    private function building(): array
    {
        return self::$shipped['building'] ??= $this->replay(self::RUN);
    }

    /** The run over a mutated tree. @return array<string, mixed> */
    private function replayed(string $wireDir): array
    {
        return $this->replay(self::RUN, $wireDir);
    }

    /** The same run entered at `/floor/{key}` — the floor page's half of every equality. @return array<string, mixed> */
    private function floorPage(string $key): array
    {
        $fx = $this->fixture(self::RUN);

        return self::$shipped["floor:{$key}"] ??= $this->replay(self::RUN, null, ['building' => null, 'floor' => [
            'key' => $key, 'local_hours' => $fx['building']['local_hours'], 'local_minutes' => $fx['building']['local_minutes'],
            'scene' => $fx['building']['scene'], 'viewport' => $fx['building']['surface'],
        ]]);
    }

    /** The building modules under a tree's root — the shipped `public/js`, or a mutated copy's (`dirname()` of its `wire/`). */
    private function buildingDir(string $root): string
    {
        return rtrim($root, '/').'/building';
    }

    /** The stack model's probe, over the run's own floor for the cull. @return array<string, mixed> */
    private function modelProbe(string $dir): array
    {
        $aimla = $this->floorOf($this->last($this->building()), 'aimla');
        $glyph = $this->fixture(self::RUN)['building']['scene']['measurer']['glyph_w']['name'];

        return $this->boundedProbe(['cull' => ['extent' => $aimla['scene']['extent'], 'labels' => $aimla['labels'], 'glyph_w' => $glyph]], $dir, __DIR__.'/stack-model-probe.mjs');
    }

    /** The floor picture's probe. @return array<string, mixed> */
    private function pictureProbe(string $dir): array
    {
        return $this->boundedProbe([], $dir, __DIR__.'/picture-probe.mjs');
    }

    /** The run, its screen resized once it ends to a stage half the run's width (`building_resized`). @return array{0: array<string, mixed>, 1: array{width: int, height: int}} */
    private function resized(?string $wireDir): array
    {
        $spec = $this->fixture(self::RUN)['building'];
        $to = ['width' => intdiv((int) $spec['surface']['width'], 2), 'height' => (int) $spec['surface']['height']];

        return [$this->replay(self::RUN, $wireDir, ['building' => [...$spec, 'resize' => $to]]), $to];
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array{width: int, height: int}}  $resized
     * @return list<string>
     */
    private function resizeDefects(array $resized): array
    {
        [$run, $to] = $resized;
        $before = $this->last($run)['stack'];
        $after = $run['building_resized'];
        $defects = [];

        if (! is_array($before) || ! is_array($after)) {
            return ['CONTROL — the run drew no stack to resize, or the resize answered none'];
        }

        if ($after['surface'] !== $to) {
            $defects[] = 'the resized stack carries the stage '.json_encode($after['surface']).', not '.json_encode($to).' — the acts would aim from the old one';
        }

        if ($after['storey'] === $before['storey']) {
            $defects[] = 'the resized stack keeps the old storey — it was not re-derived from the new stage';
        }

        if (array_column($after['slabs'], 'floor') !== array_column($before['slabs'], 'floor')) {
            $defects[] = 'the resized stack does not stand the last draw\'s floors';
        }

        return $defects;
    }

    /** @return array<string, mixed> */
    private function actsProbe(string $dir): array
    {
        return $this->boundedProbe([], $dir, __DIR__.'/building-acts-probe.mjs');
    }

    /**
     * A model probe under a time bound: a planted defect that leaves a camera search unending (a pose no distance frames)
     * is a run that does not end, and that is a red with its reason, never a suite that hangs.
     *
     * @return array<string, mixed>
     */
    private function boundedProbe(array $payload, string $dir, string $script): array
    {
        $result = null;
        $this->runProbes(['run' => $payload], 1, self::PROBE_TIMEOUT_S, static function (string $name, ?int $status, string $stdout, string $stderr) use (&$result): void {
            $result = [$status, $stdout, $stderr];
        }, $dir, $script);
        [$status, $stdout, $stderr] = $result;

        $this->assertNotNull($status, 'the model probe did not end within '.self::PROBE_TIMEOUT_S.' s — a camera search that never ends');
        $this->assertSame(0, $status, "the model probe failed:\n".$stderr);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "the model probe printed something that is not JSON:\n".$stdout);

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function renders(array $result): array
    {
        return $result['building_renders'];
    }

    /** The last frame drawn. @return array<string, mixed> */
    private function last(array $result): array
    {
        $renders = $this->renders($result);
        $this->assertNotSame([], $renders, 'the building view drew nothing');

        return $renders[count($renders) - 1]['frame'];
    }

    /** @return array<string, mixed> */
    private function floorOf(array $frame, string $key): array
    {
        foreach ($frame['floors'] as $floor) {
            if ($floor['row']['floor'] === $key) {
                return $floor;
            }
        }

        $this->fail("the building frame has no floor {$key}");
    }

    /** The floor page's last frame that drew a scene. @return array<string, mixed> */
    private function floorFrame(array $result): array
    {
        $drawn = array_values(array_filter($result['floor_renders'], static fn (array $r): bool => $r['trigger'] === 'render' && $r['frame']['scene'] !== null));
        $this->assertNotSame([], $drawn, 'the floor page drew no scene');

        return $drawn[count($drawn) - 1]['frame'];
    }

    // ── the measurements ───────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function stackDefects(array $result): array
    {
        $frame = $this->last($result);
        $stack = $frame['stack'];
        $keys = array_map(static fn (array $f): string => $f['row']['floor'], $frame['floors']);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $defects = [];

        if ($keys !== $sorted || $keys !== ['aimla', 'theta', 'zeta']) {
            $defects[] = 'the floors are not the directory\'s rows, ascending: '.json_encode($keys);
        }

        if ($stack === null || count($stack['slabs']) !== count($keys)) {
            return [...$defects, 'the stack is not one slab per floor'];
        }

        $n = count($keys);

        foreach ($stack['slabs'] as $i => $slab) {
            $floor = $frame['floors'][$i];
            $extent = $floor['scene']['extent'];

            if ($slab['floor'] !== $floor['row']['floor'] || $slab['level'] !== $floor['row']['level']) {
                $defects[] = "slab {$i} is not the directory's row {$i}";
            }

            if ($slab['storey'] !== $n - 1 - $slab['level']) {
                $defects[] = "{$slab['floor']} stands at storey {$slab['storey']}, not n − 1 − level = ".($n - 1 - $slab['level']);
            }

            if ($slab['href'] !== '/floor/'.rawurlencode($slab['floor'])) {
                $defects[] = "{$slab['floor']}'s slab links to {$slab['href']}, not its key's route";
            }

            if ($slab['picture'] !== ($extent !== null) || $slab['extent'] !== $extent) {
                $defects[] = "{$slab['floor']}'s slab has picture ".json_encode($slab['picture']).' over the extent '.json_encode($extent).' — one picture rule';
            }

            if ($extent !== null && (abs($slab['box']['w'] - $extent['w'] / 100) > 1e-9 || abs($slab['box']['d'] - $extent['h'] / 100) > 1e-9)) {
                $defects[] = "{$slab['floor']}'s footprint is not its extent";
            }
        }

        $tops = array_map(static fn (array $s): float => $s['box']['y'] + $s['box']['h'], $stack['slabs']);

        $falling = array_filter(array_keys($tops), static fn (int $i): bool => $i > 0 && ! ($tops[$i] < $tops[$i - 1])) === [];

        if (! $falling) {
            $defects[] = 'the first row is not the top storey: '.json_encode($tops);
        }

        if ($stack['ground']['href'] !== $this->fixture(self::RUN)['building']['lobby_href'] || $stack['ground']['picture'] !== false
            || abs($stack['ground']['box']['y'] + $stack['ground']['box']['h']) > 1e-9) {
            $defects[] = 'the ground slab is not the lobby: '.json_encode($stack['ground']);
        }

        $theta = $this->floorOf($frame, 'theta');

        if ($theta['scene']['extent'] !== null || ! in_array('room map could not be loaded — HTTP 503 — theta', $theta['notices'], true)) {
            $defects[] = 'theta, whose map request failed, has an extent or lacks the floor page\'s map-failure line: '.json_encode($theta['notices']);
        }

        if (array_filter($stack['slabs'], static fn (array $s): bool => $s['picture']) === [] || array_filter($stack['slabs'], static fn (array $s): bool => ! $s['picture']) === []) {
            $defects[] = 'CONTROL — the run has no slab with a picture and one without, so the picture rule compared one case';
        }

        return $defects;
    }

    /** @return list<string> */
    private function floorPageDefects(array $result): array
    {
        $frame = $this->last($result);
        $defects = [];

        foreach (['aimla', 'theta', 'zeta'] as $key) {
            $page = $this->floorPage($key);
            $mine = $this->floorOf($frame, $key);
            $theirs = $this->floorPage($key)['floor_renders'];
            $drawn = array_values(array_filter($theirs, static fn (array $r): bool => $r['trigger'] === 'render' && $r['frame']['scene'] !== null));

            if ($drawn === []) {
                $defects[] = "the floor page drew no scene for {$key}";

                continue;
            }

            $floor = $drawn[count($drawn) - 1]['frame'];

            if ($mine['scene']['extent'] !== $floor['scene']['extent']) {
                $defects[] = "{$key}'s slab extent ".json_encode($mine['scene']['extent']).' is not the floor page\'s '.json_encode($floor['scene']['extent']);
            }

            if ($mine['notices'] !== $floor['notices']) {
                $defects[] = "{$key}'s notices ".json_encode($mine['notices']).' are not the floor page\'s '.json_encode($floor['notices']);
            }

            if ($page['unscripted'] !== []) {
                $defects[] = "the floor page for {$key} made unscripted requests";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function roomMapDefects(array $result): array
    {
        $requests = $result['final']['requests'];
        $count = static fn (string $path): int => count(array_filter($requests, static fn (string $r): bool => $r === $path));
        $defects = [];

        foreach (['/api/building/rooms/theta/map' => 1, '/api/building/rooms/zeta/map' => 1, '/api/building/rooms/aimla/map' => 2] as $path => $n) {
            if ($count($path) !== $n) {
                $defects[] = "{$path} was requested {$count($path)} times, not {$n}";
            }
        }

        // After the room.map: aimla's map alone — no room entered again.
        $rooms = array_values(array_filter($requests, static fn (string $r): bool => str_starts_with($r, '/api/building/rooms/')));

        if (end($rooms) !== '/api/building/rooms/aimla/map' || count(array_unique(array_slice($rooms, 3))) !== 1) {
            $defects[] = 'the room.map entered more than its room: '.json_encode($rooms);
        }

        $before = array_values(array_filter($this->renders($result), static fn (array $r): bool => $r['at'] < 2500 && $r['frame']['stack'] !== null));
        $after = array_values(array_filter($this->renders($result), static fn (array $r): bool => $r['at'] >= 2500));

        if ($before === [] || $after === []) {
            return [...$defects, 'the run has no render before and after the room.map'];
        }

        $old = $this->floorOf($before[count($before) - 1]['frame'], 'aimla')['scene']['extent'];
        $new = $this->floorOf($after[0]['frame'], 'aimla')['scene']['extent'];

        if ($old === $new) {
            $defects[] = 'aimla\'s scene after the room.map is not built from the authored document — its extent is the default\'s';
        }

        if (! in_array('room aimla: map revision 4', $result['final']['event_log'], true) && ! array_filter($result['final']['event_log'], static fn (string $l): bool => str_contains($l, 'room aimla: map revision 4'))) {
            $defects[] = 'the room.map\'s line is not in the client\'s record';
        }

        return $defects;
    }

    /** @return list<string> */
    private function memberDefects(array $result, string $root): array
    {
        $defects = [];
        preg_match_all('/(?<![\w$.])frame\.([a-z_]+)/', \Tests\Feature\Support\ModuleSpecifiers::withoutComments((string) file_get_contents($root.'/floor/scene.js')), $m);
        $reads = array_values(array_unique($m[1]));
        sort($reads);

        if ($reads === []) {
            return ['scene.js reads no frame member — the comparison read nothing'];
        }

        foreach ($this->last($result)['floors'] as $floor) {
            $keys = array_keys($floor['frame']);
            sort($keys);

            if ($keys !== $reads) {
                $defects[] = "{$floor['row']['floor']}'s frame carries ".json_encode($keys).' and buildScene() reads '.json_encode($reads);
            }
        }

        $page = array_keys($this->floorFrame($this->floorPage('aimla')));

        if (array_diff($reads, $page) !== []) {
            $defects[] = 'the floor screen\'s frame lacks '.json_encode(array_values(array_diff($reads, $page))).' — the comparison is not the floor page\'s';
        }

        // The scene input: the documents the building screen hands `sceneInput()`, with the art's members, are SCENE_INPUT_KEYS.
        $compose = (string) file_get_contents($root.'/floor/floor-compose.js');
        $screen = \Tests\Feature\Support\ModuleSpecifiers::withoutComments((string) file_get_contents($root.'/building/building-screen.js'));
        $this->assertSame(1, preg_match('/export const SCENE_INPUT_KEYS = Object\.freeze\(\[(.*?)\]\);/s', $compose, $k), 'SCENE_INPUT_KEYS was not found');
        preg_match_all("/'([A-Za-z_]+)'/", $k[1], $declared);
        $this->assertSame(1, preg_match('/export function artInputs\(.*?\n}\n/s', $compose, $art), 'artInputs() was not found');
        preg_match_all('/^        ([A-Za-z_]+)[:,]/m', $art[0], $artKeys);
        $this->assertSame(1, preg_match('/sceneInput\(this\.#art, \{(.*?)\n        \}\)\)/s', $screen, $docs), 'the building screen hands sceneInput() no documents literal');
        preg_match_all('/^            ([A-Za-z_]+):/m', $docs[1], $docKeys);
        $given = [...$artKeys[1], ...$docKeys[1]];
        sort($given);
        $want = $declared[1];
        sort($want);

        if ($artKeys[1] === [] || $given !== $want) {
            $defects[] = 'the building screen\'s scene input is '.json_encode($given).', not SCENE_INPUT_KEYS '.json_encode($want);
        }

        if (! str_contains($screen, "from '../floor/floor-compose.js'") || ! preg_match('/import \{[^}]*\bsceneInput\b[^}]*\} from \'\.\.\/floor\/floor-compose\.js\'/', $screen)) {
            $defects[] = 'the building screen does not assemble its scene input with floor-compose.js\'s sceneInput()';
        }

        return $defects;
    }

    /** @return list<string> */
    private function themeDefects(array $result): array
    {
        $frame = $this->last($result);
        $defects = [];
        $page = $this->floorFrame($this->floorPage('zeta'));

        foreach ($frame['floors'] as $floor) {
            $failed = $floor['scene']['art_failed'];
            $rejected = ($floor['row']['theme'] ?? null) === 'cottage';

            if ($failed !== $rejected || ($rejected && ! in_array('theme:cottage', $floor['scene']['failed'], true))) {
                $defects[] = "{$floor['row']['floor']}'s art_failed is ".json_encode($failed).' and its failed assets '.json_encode($floor['scene']['failed']);
            }
        }

        if ($frame['strip']['art'] === null || $frame['strip']['art'] !== $page['strip']['art'] || $this->floorOf($frame, 'zeta')['scene']['failed'] !== $page['scene']['failed']) {
            $defects[] = 'the building\'s art line '.json_encode($frame['strip']['art']).' and failed assets are not the floor page\'s '.json_encode($page['strip']['art']);
        }

        return $defects;
    }

    /** @return list<string> */
    private function labelDefects(array $result): array
    {
        $frame = $this->last($result);
        $defects = [];
        $total = 0;

        foreach ($frame['floors'] as $floor) {
            $key = $floor['row']['floor'];
            $byKey = array_column($floor['labels'], null, 'key');

            foreach ($floor['scene']['desks'] ?? [] as $desk) {
                $plate = array_values(array_filter($desk['elements'], static fn (array $e): bool => $e['kind'] === 'nameplate' && $e['member'] === 'nameplate'))[0] ?? null;
                $label = $byKey[$desk['key']] ?? null;

                if ($plate === null) {
                    continue;
                }

                $total++;

                if ($label === null) {
                    $defects[] = "{$desk['key']} on {$key} has no label";

                    continue;
                }

                if ($label['text'] !== $plate['text'] || abs($label['at']['x'] - ($plate['x'] + $plate['w'] / 2)) > 1e-9 || abs($label['at']['y'] - ($plate['y'] + $plate['h'] / 2)) > 1e-9) {
                    $defects[] = "{$desk['key']}'s label is not its nameplate's text at its centre";
                }

                if ($label['href'] !== '/floor/'.rawurlencode($key).'/'.rawurlencode($desk['seat_id'])) {
                    $defects[] = "{$desk['key']}'s label links to {$label['href']}, not its drill-down by the floor's key";
                }
            }

            if (count($floor['labels']) !== count($floor['scene']['desks'] ?? [])) {
                $defects[] = "{$key} has ".count($floor['labels']).' labels for '.count($floor['scene']['desks'] ?? []).' desks';
            }

            foreach ($floor['labels'] as $label) {
                if (str_contains($label['key'], '~')) {
                    $defects[] = "{$key} labels the intern {$label['key']}";
                }
            }
        }

        $interns = array_sum(array_map(static fn (array $s): int => count($s['subagents'] ?? []), $this->snapshotSeats(self::RUN)));

        if ($total === 0 || $interns === 0) {
            $defects[] = 'CONTROL — the run labels no desk or holds no intern, so the clause read half of itself';
        }

        return $defects;
    }

    /** @return list<string> */
    private function factDefects(array $result): array
    {
        $defects = [];

        foreach ($this->renders($result) as $render) {
            $frame = $render['frame'];
            $members = array_keys($frame);

            if ($members !== self::FRAME_MEMBERS) {
                $defects[] = "the frame at {$render['at']} ms carries ".json_encode($members).', not '.json_encode(self::FRAME_MEMBERS).' — a camera, a ride or a fact';
            }

            foreach ($frame['stack']['slabs'] ?? [] as $i => $slab) {
                $row = $frame['floors'][$i]['row'];

                if (array_keys($slab) !== self::SLAB_MEMBERS || $slab['name'] !== $row['name']) {
                    $defects[] = "the slab {$slab['floor']} at {$render['at']} ms carries ".json_encode(array_diff_key($slab, ['box' => 0, 'extent' => 0])).' — not the row\'s name and link alone';
                }
            }

            foreach ($frame['floors'] as $floor) {
                if (isset($floor['frame']['camera']) || isset($floor['frame']['ride'])) {
                    $defects[] = "{$floor['row']['floor']}'s frame carries a camera or a ride";
                }

                foreach ($floor['labels'] as $label) {
                    if (array_keys($label) !== self::LABEL_MEMBERS) {
                        $defects[] = "a label on {$floor['row']['floor']} carries ".json_encode(array_keys($label));
                    }
                }

                if ($floor['scene'] !== null && $floor['scene']['effects'] !== []) {
                    $defects[] = "{$floor['row']['floor']}'s scene at {$render['at']} ms has effects in flight — not a still";
                }
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function logDefects(array $result): array
    {
        $defects = [];

        foreach ($this->renders($result) as $render) {
            if ($render['drains'] !== 1) {
                $defects[] = "the render at {$render['at']} ms drained the journal {$render['drains']} times";
            }
        }

        // The discriminating control: the same run with no building view — the desk floor alone, under `reduce` as the
        // building's runs it — writes the same rows, so the log's silence about the view is known to be the view's.
        $control = self::$shipped['desks'] ??= $this->replay(self::RUN, null, ['building' => null, 'desk_floor' => true, 'reduce' => true]);

        if ($result['animation_log'] !== $control['animation_log']) {
            $defects[] = sprintf('the building view\'s log (%d rows) is not the desk floor\'s alone (%d rows)', count($result['animation_log']), count($control['animation_log']));
        }

        if ($control['animation_log'] === []) {
            $defects[] = 'CONTROL — the run logs nothing, so the equality compared two empty logs';
        }

        return $defects;
    }

    /** @return list<string> */
    private function graphDefects(string $root): array
    {
        ['seen' => $seen, 'edges' => $edges, 'defects' => $defects] = $this->importGraph($root, self::ROOTS);

        $this->assertContains('desk/desk-floor.js', $seen, 'the walk never reached the desk floor — it read nothing');
        $this->assertContains('floor/scene.js', $seen, 'the walk never reached the scene — it read nothing');

        foreach ($edges as [$from, $to]) {
            if (! in_array(basename($to), ['animation-log.js', 'animation-set.js'], true) || (self::PATH[$from] ?? null) === $to) {
                continue;
            }

            if (isset(self::CONSTANTS[$from]) && basename($to) === 'animation-set.js') {
                if (! str_contains(\Tests\Feature\Support\ModuleSpecifiers::withoutComments((string) file_get_contents($root.'/'.$from)), self::CONSTANTS[$from])) {
                    $defects[] = "{$from} imports from the set's module something beside its constants — `".self::CONSTANTS[$from].'` is its one import there';
                }

                continue;
            }

            $defects[] = "{$from} loads {$to}, which writes the animation log or starts the set off the building's paths to it";
        }

        foreach (self::PATH as $from => $to) {
            if (! in_array([$from, $to], $edges, true)) {
                $defects[] = "{$from} no longer loads {$to} — the building's paths to the set are not the ones this walk holds";
            }
        }

        return $defects;
    }
}

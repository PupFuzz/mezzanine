<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The room drawing's motion is the set's, and its decoration is data** — `docs/design/FLOOR.md`
 * Appendix B row 14: § 5.7's line and A18–A20's forms "between the positions step 7 resolved —
 * under the operator's QA rulings on them (card#7341 scope addition 3): A20's ring spans the whole
 * floor before it fades, reach first and fade after, never dissipating mid-office; A19's envelope's
 * nose points along its direction of travel"; "⛔ The scene starts no claim-bearing motion … decorative
 * motion (§ 6.3) is emitted by the scene as data too — which element, where, and that it is
 * decoration under § 6.3's bound".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THIS ROW's GATE CELL NAMES NO AT FOR THESE — AT-D3-18 holds the line's resolution at step 7 and
 * AT-D3-1 the log — so what row 14 adds to them, the DRAWING, is held here, the way rows 11–13 are
 * held by their own tests. The runs are `fx-coord`'s `coord` (a thread, a broadcast and an envelope
 * on the floor) and the shipped default map's `scene_default`, whose lamps carry the tileset's
 * `decoration` property.
 */
class TheSceneDrawsOnlyWhatTheSetLoggedTest extends TestCase
{
    use DrivesTheScene;

    private const COORD = 'coord';

    private const ROOM = 'scene_default';

    private const STILLED = 'refusal_401_line';

    public function test_every_form_the_scene_draws_is_a_row_the_set_logged(): void
    {
        foreach ([self::COORD, self::ROOM] as $run) {
            $this->assertSame([], $this->unloggedDefects($this->floorRun($run)), "[{$run}]");
        }
    }

    public function test_the_ring_reaches_the_whole_floor_before_it_fades_and_the_envelope_points_where_it_travels(): void
    {
        $this->assertSame([], $this->formDefects($this->floorRun(self::COORD)));
    }

    public function test_under_reduced_motion_the_ring_and_the_envelope_are_their_static_forms(): void
    {
        $result = $this->floorRun(self::COORD, null, ['reduce' => true]);
        $effects = $this->effectsOf($result);

        $this->assertNotSame([], $effects, 'no A19/A20 was drawn under reduced motion — the forms would measure nothing');

        foreach ($effects as $fx) {
            $this->assertSame(0, $fx['frames'], "{$fx['animation_id']} moves under reduced motion");
            $this->assertFalse($fx['motion']);
        }
    }

    public function test_the_thread_line_is_drawn_between_the_desks_its_participants_resolve_to(): void
    {
        $result = $this->floorRun(self::COORD);
        $frame = $this->floorWithLine($result, 'aimla');
        $scene = $frame['scene'];

        $this->assertNotSame([], $scene['lines'], 'the frame drew a line and the scene did not');

        foreach ($scene['lines'] as $line) {
            $thread = collect($frame['coord']['aimla']['threads'])->firstWhere('thread_ref', $line['thread_ref']);
            $ends = array_map(fn ($seat) => $scene['anchors']["aimla/{$seat}"], $thread['endpoints']);

            $this->assertSame($ends, $line['ends'], 'the line is not drawn between its resolved desks');
        }
    }

    /**
     * The line moves as the set logged it: flowing on a live floor, static once § 9 F6 stills the
     * floor under it (`fx-refusals`' `refusal_401_line`, card#7341), and static throughout under reduced
     * motion. The motion is the line's `held` rendering, the one the A18 row was logged at.
     */
    public function test_the_thread_line_moves_only_where_the_set_logged_it_moving(): void
    {
        $this->assertSame([], $this->lineMotionDefects($this->floorRun(self::STILLED), true));
        $this->assertSame([], $this->lineMotionDefects($this->floorRun(self::COORD, null, ['reduce' => true]), false));
    }

    public function test_decorative_motion_is_data_bounded_by_section_6_3_and_on_no_desk(): void
    {
        $this->assertSame([], $this->decorativeDefects($this->sceneOf(self::ROOM)));

        foreach ($this->sceneOf(self::ROOM, null, ['reduce' => true])['decorative'] as $d) {
            $this->assertFalse($d['motion'], 'decoration still moves under reduced motion');
        }
    }

    /** ⛔ THE CONTROLS — each plants its defect in `floor/scene.js`. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $midOffice = $this->mutatedModules(['../floor/scene.js', 'const reach = Math.max(...[', 'const reach = 0.5 * Math.max(...[']);
        $this->assertNotSame([], $this->formDefects($this->floorRun(self::COORD, $midOffice)),
            'CONTROL (a ring that fades mid-office) did not bite');

        $noseless = $this->mutatedModules(['../floor/scene.js',
            'heading_deg: Math.atan2(to.y - origin.y, to.x - origin.x) * 180 / Math.PI,', 'heading_deg: 90,']);
        $this->assertNotSame([], $this->formDefects($this->floorRun(self::COORD, $noseless)),
            'CONTROL (an envelope whose nose does not point along its travel) did not bite');

        $unstilled = $this->mutatedModules(['../floor/scene.js',
            "...form('A18', !thread.held.motion),", "...form('A18', input.reduce === true),"]);
        $this->assertNotSame([], $this->lineMotionDefects($this->floorRun(self::STILLED, $unstilled), true),
            'CONTROL (a line drawn moving on the floor § 9 F6 stilled) did not bite');

        $invented = $this->mutatedModules(['../floor/scene.js',
            "        if (row.class !== 'edge') {\n            continue;\n        }\n", '']);
        $this->assertNotSame([], $this->unloggedDefects($this->floorRun(self::COORD, $invented)),
            'CONTROL (the scene drawing an edge form for a row that is no edge) did not bite');

        $onTheDesk = $this->mutatedModules(['../floor/scene.js',
            "    return Object.freeze({\n        extent: union(all),",
            "    for (const desk of desks) {\n        decorative.push({ decoration: 'glow', ...desk.box, w: desk.box.w, h: desk.box.h, bound: '§ 6.3', cycle_ms: DECORATIVE_CYCLE_MS, motion: true });\n    }\n\n    return Object.freeze({\n        extent: union(all),"]);
        $this->assertNotSame([], $this->decorativeDefects($this->sceneOf(self::ROOM, $onTheDesk)),
            'CONTROL (decoration drawn on a desk) did not bite');

        $spilling = $this->mutatedModules(['../floor/scene.js',
            'return Object.freeze({ cx: cell.x + cell.w / 2, cy: cell.y + ry, rx: cell.w / 2, ry });',
            'return Object.freeze({ cx: cell.x + cell.w / 2, cy: cell.y + cell.h, rx: cell.w, ry: cell.h });']);
        $this->assertNotSame([], $this->decorativeDefects($this->sceneOf(self::ROOM, $spilling)),
            'CONTROL (a glow drawn twice its tile, centred on its foot) did not bite');

        $restless = $this->mutatedModules(['../floor/scene.js',
            'export const DECORATIVE_CYCLE_MS = 2400;', 'export const DECORATIVE_CYCLE_MS = 500;']);
        $this->assertNotSame([], $this->decorativeDefects($this->sceneOf(self::ROOM, $restless)),
            'CONTROL (decoration cycling under § 12\'s minimum) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /** Every edge form drawn names a row the animation set logged — the scene fires none itself. */
    private function unloggedDefects(array $result): array
    {
        $logged = [];

        foreach ($result['animation_log'] as $row) {
            if ($row['class'] === 'edge') {
                $logged["{$row['animation_id']} {$row['cause']}"] = true;
            }
        }

        $defects = [];

        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['scene']['effects'] ?? [] as $fx) {
                if (! isset($logged["{$fx['animation_id']} {$fx['cause']}"]) || $fx['class'] !== 'edge') {
                    $defects[] = "the scene drew {$fx['animation_id']} ({$fx['cause']}) with no edge row behind it";
                }
            }
        }

        return $defects;
    }

    /**
     * Every A18 line the run drew, against the floor it was drawn on: a moving line flows (frames to
     * step) and a static one does not, and a line on a stilled floor — or under reduced motion — is
     * static. `$live` is whether the run's live floor draws the line moving at all.
     */
    private function lineMotionDefects(array $result, bool $live): array
    {
        $defects = [];
        $seen = [];

        foreach ($result['floor_renders'] as $render) {
            $stilled = $render['frame']['failure']['sign_in'] !== null;

            foreach ($render['frame']['scene']['lines'] ?? [] as $line) {
                $want = $live && ! $stilled;
                $seen[$stilled ? 'stilled' : 'live'] = true;

                if ($line['motion'] !== $want) {
                    $defects[] = "{$line['thread_ref']} is drawn ".($line['motion'] ? 'moving' : 'static')
                        .' on a '.($stilled ? 'stilled' : 'live').' floor'.($live ? '' : ' under reduced motion');
                }

                // A line carrying no `frames` at all is a scene that states no flow for the painter.
                $frames = $line['frames'] ?? null;

                if (($frames > 0) !== $line['motion']) {
                    $defects[] = "{$line['thread_ref']}'s flow frames (".json_encode($frames).') disagree with its motion';
                }
            }
        }

        $this->assertArrayHasKey('live', $seen, 'no line was drawn on a live floor — the check read nothing');

        if ($live) {
            $this->assertArrayHasKey('stilled', $seen, 'no line was drawn on the stilled floor — the check read nothing');
        }

        return $defects;
    }

    /** The operator's two QA rulings, over every A19/A20 the run drew. */
    private function formDefects(array $result): array
    {
        $defects = [];
        $effects = $this->effectsOf($result);

        $this->assertNotEmpty(array_filter($effects, fn ($fx) => $fx['animation_id'] === 'A20'), 'no ring was drawn');
        $this->assertNotEmpty(array_filter($effects, fn ($fx) => $fx['animation_id'] === 'A19'), 'no envelope was drawn');

        foreach ($effects as $fx) {
            if ($fx['animation_id'] === 'A20') {
                $e = $fx['extent'];
                $far = max(array_map(fn ($c) => hypot($c[0] - $fx['at']['x'], $c[1] - $fx['at']['y']), [
                    [$e['x'], $e['y']], [$e['x'] + $e['width'], $e['y']],
                    [$e['x'], $e['y'] + $e['height']], [$e['x'] + $e['width'], $e['y'] + $e['height']],
                ]));

                // A floating-point hair, not a short ring: `Math.hypot` and PHP's `hypot()` may differ in the last
                // place for one corner (measured: 1217.9343167839552 against …554 once the anchor moved to the
                // character's centre line, card#11046 row 20), so the reach is compared to 1e-9 of the corner.
                if ($fx['radius'] < $far * (1 - 1e-9)) {
                    $defects[] = 'the ring fades before it reaches the floor\'s far corner';
                }

                if ($fx['reach_frames'] < 1 || $fx['fade_frames'] < 1 || $fx['frames'] !== $fx['reach_frames'] + $fx['fade_frames']) {
                    $defects[] = 'the ring does not reach first and fade after';
                }
            }

            if ($fx['animation_id'] === 'A19') {
                $travel = rad2deg(atan2($fx['to']['y'] - $fx['from']['y'], $fx['to']['x'] - $fx['from']['x']));

                if (abs($travel - $fx['heading_deg']) > 1e-9) {
                    $defects[] = "the envelope's nose points {$fx['heading_deg']}° and it travels {$travel}°";
                }
            }
        }

        return $defects;
    }

    /** § 6.3's bound and § 12's minimum cycle, over the scene's decorative list. */
    private function decorativeDefects(array $scene): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Decorative motion\'s minimum cycle\*\* \| \*\*(\d+) s\*\*/m', $this->floorMd(), $m),
            '§ 12\'s decorative cycle row is not in the form this test reads');
        $this->assertNotSame([], $scene['decorative'], 'the scene emitted no decoration — the bound would measure nothing');

        $defects = [];

        foreach ($scene['decorative'] as $d) {
            if ($d['bound'] !== '§ 6.3' || $d['cycle_ms'] < (int) $m[1] * 1000) {
                $defects[] = 'a decoration is not held to § 6.3\'s bound: '.json_encode($d);
            }

            // § 6.3's third test is about the ELEMENT: motion on anything a § 6.2 row draws is
            // claim-bearing. So every decoration must be a map tile that declares itself one — a standing
            // scenery piece placed by the map (FLOOR.md § 10.6 item 6), never a desk element, whose every
            // kind a row or the desk model draws. (A rect test against the desk's box would be a proxy, and
            // a wrong one: a lamp behind a desk drawn past an undersized slot is still a lamp.)
            $tile = collect($scene['scenery'])->first(fn ($t) => [$t['x'], $t['y'], $t['w'], $t['h']] === [$d['x'], $d['y'], $d['w'], $d['h']]
                && ($t['decoration'] ?? null) === $d['decoration']);

            if ($tile === null) {
                $defects[] = 'a decoration is on no map tile that declares it one: '.json_encode($d);
            }

            // The glow the painter fills is INSIDE its tile (card#11045): a glow larger than its tile
            // reaches past the room's grid wherever an author lays a lamp at its edge — under the floor,
            // over the band, or onto a neighbour's room.
            $g = $d['glow'] ?? null;

            if (! is_array($g) || $g['rx'] <= 0 || $g['ry'] <= 0
                || $g['cx'] - $g['rx'] < $d['x'] || $g['cx'] + $g['rx'] > $d['x'] + $d['w']
                || $g['cy'] - $g['ry'] < $d['y'] || $g['cy'] + $g['ry'] > $d['y'] + $d['h']) {
                $defects[] = 'a decoration\'s glow is not drawn inside its own tile: '.json_encode($d);
            }
        }

        return $defects;
    }

    /** Every A19/A20 drawing of a run, each with the floor extent it was drawn over. */
    private function effectsOf(array $result): array
    {
        $out = [];

        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['scene']['effects'] ?? [] as $fx) {
                if (in_array($fx['animation_id'], ['A19', 'A20'], true)) {
                    $out[] = $fx + ['extent' => $render['frame']['extent']];
                }
            }
        }

        return $out;
    }
}

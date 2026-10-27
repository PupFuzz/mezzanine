<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-23 — a seat leaves by the elevator and returns by it (`docs/design/FLOOR.md § 11`, card#9566, card#12194):
 * § 6.2's walk note, every numbered item of it a replay can observe, over `fx-elevator`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A WALK IS PRESENTATION, NEVER STATE. The rows are the animation log's and are asserted there; the
 * walk is asserted on the scene each render (and each paint-only refresh) drew — `frame.scene.effects`,
 * `frame.scene.doors` and the desk's elements — because that is the model the painter draws and the
 * last thing in this tree a test can read without a browser. The browser half — the glide, the floor stack's
 * order and focus, a press on the elevator through a waiting walker, the cab — is `tools/design/floor-ride.browser.mjs`'s
 * `walker-*` rows.
 *
 * ⛔ EVERY NUMBER IS RE-DERIVED. A walk's ends are read off the scene that drew it (the desk's anchor, the elevator's
 * seam and foot); its frames are `⌈length ÷ walk speed⌉` with the length re-summed from its points and the speed, the
 * door's frames and the wait's bound read out of § 12's own rows; a departure's wait is re-drawn through the character
 * tree's own `draw()` (`walk-probe.mjs`); where a walker stands at an instant is re-derived here from its legs by arc
 * length; the cells a path may not enter are the map's `wall` and standing tiles as `walk-probe.mjs` resolves them
 * through the scene's tile reader, and the desks the scene drew — so nothing here is a copy free to agree with a wrong
 * module.
 *
 * ⛔ EACH RED IS A PLANT IN THE SHIPPED TREE, replayed against the run § 11 names for it, and seen to
 * break that run's GREEN (`test_red_*`). § 11's *Caught by* lists every run a plant breaks; each test
 * below drives the first of them.
 */
class ASeatLeavesByTheElevatorAndReturnsByItTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const IMPL_1 = 'aimla/aimla-impl-1';

    private const IMPL_2 = 'aimla/aimla-impl-2';

    private const REVIEW = 'aimla/aimla-review';

    private const PM = 'aimla/aimla-pm';

    private const ANIMATION_SET = 'animation-set.js';

    private const IN_FLIGHT = '../floor/in-flight.js';

    private const SCENE = '../floor/scene.js';

    private const ROUTE = '../floor/route.js';

    private const PAINTER = '../floor/painter.js';

    /** two-walkers' second render, `aimla-impl-1`'s delta: the first instant both walks are in flight. */
    private const BOTH_WALKING_MS = 1750;

    /** The runs that draw a routed walk over the shipped default, whose every leg the geometry guards read. */
    private const ROUTED_RUNS = ['leave-stale', 'return-from-stale', 'two-walkers', 'crossing', 'mass-departure', 'repaint', 'delta-under-walk'];

    /** The fixture's measurer, as every `fx-elevator` run states it — the scene-level H6 leg builds its scene with it. */
    private const MEASURER = ['glyph_w' => ['fact' => 6, 'name' => 8, 'screen' => 5], 'line_h' => ['fact' => 12, 'name' => 16, 'screen' => 8]];

    // ── § 12's rows, read out of the document ────────────────────────────────────────────────

    private function walkSpeed(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Walk speed\*\* \| \*\*(\d+) px per frame\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Walk speed* row in the form this test reads');

        return (int) $m[1];
    }

    /** @return array{open: int, step: int, close: int} */
    private function door(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Elevator leaves* row in the form this test reads');

        return ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
    }

    /** § 12's *Elevator wait* bound, in seconds. */
    private function waitSeconds(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator wait\*\* \| \*\*0 … (\d+) s\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Elevator wait* row in the form this test reads');

        return (int) $m[1];
    }

    /** § 12's loop rate, frames per second. */
    private function loopFps(): int
    {
        $this->assertSame(1, preg_match('/export const LOOP_FPS = (\d+);/', (string) file_get_contents($this->moduleDir().'/animation-set.js'), $m));

        return (int) $m[1];
    }

    /** The longest wait in loop frames: § 12's bound at § 12's rate. */
    private function waitMax(): int
    {
        return $this->waitSeconds() * $this->loopFps();
    }

    /** @return array{straight: int, diagonal: int} § 12's *Route lattice and cost* step costs */
    private function stepCost(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Route lattice and cost\*\* \| \*\*the floor\'s smallest tile; (\d+) \/ (\d+)\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Route lattice and cost* row in the form this test reads');

        return ['straight' => (int) $m[1], 'diagonal' => (int) $m[2]];
    }

    // ── The probes ──────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function walkProbe(string $mode, array $payload = [], ?string $dir = null): array
    {
        return $this->probe(['mode' => $mode] + $payload, $dir, __DIR__.'/walk-probe.mjs');
    }

    /** The shipped default as the scene reads it, routed (`walk-probe.mjs`'s `shipped`). */
    private function shipped(?string $dir = null, array $walls = []): array
    {
        static $cached = null;

        if ($dir === null && $walls === [] && $cached !== null) {
            return $cached;
        }

        $out = $this->walkProbe('shipped', ['repeat' => 5, 'walls' => $walls], $dir);

        if ($dir === null && $walls === []) {
            $cached = $out;
        }

        return $out;
    }

    /** A departure's wait, re-drawn through the character tree's own seeded draw. */
    private function seededWait(string $key, int $cause): int
    {
        $draws = $this->walkProbe('draw', ['cases' => [[$key, "elevator-wait:{$cause}"]]]);

        return $draws[0] % ($this->waitMax() + 1);
    }

    // ── What a run drew ─────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> the run's edge rows other than the heartbeat's two */
    private function edgeRows(array $result): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'edge'
            && ! in_array($r['animation_id'], ['A14', 'A17'], true)));
    }

    /** @return list<string> `ID key` for every A1/A2 row */
    private function walkRows(array $result): array
    {
        return array_values(array_map(static fn (array $r): string => "{$r['animation_id']} {$r['install_id']}/{$r['seat_id']}",
            array_filter($this->edgeRows($result), static fn (array $r): bool => in_array($r['animation_id'], ['A1', 'A2'], true))));
    }

    /** @return list<array<string, mixed>> the walks a frame's scene draws (A1/A2 effects with frames) */
    private function walks(array $frame): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => in_array($e['animation_id'], ['A1', 'A2'], true)
            && ($e['frames'] ?? 0) > 0));
    }

    /** @return list<array<string, mixed>> every A1/A2 effect a frame's scene carries, drawn with its walk or without */
    private function walkEffects(array $frame): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => in_array($e['animation_id'], ['A1', 'A2'], true)));
    }

    /** The drawn frames that carry a scene, each `{at, trigger, frame}`. */
    private function drawn(array $result): array
    {
        return array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['scene'] ?? null) !== null));
    }

    private function frameAt(array $result, int $at, string $trigger = 'render', bool $last = true): array
    {
        $hits = array_values(array_filter($this->drawn($result), static fn (array $r): bool => $r['at'] === $at && $r['trigger'] === $trigger));

        $this->assertNotSame([], $hits, "no {$trigger} at {$at} ms drew a scene");

        return $hits[$last ? count($hits) - 1 : 0]['frame'];
    }

    private function deskIn(array $frame, string $key): array
    {
        foreach ($frame['scene']['desks'] as $desk) {
            if ($desk['key'] === $key) {
                return $desk;
            }
        }

        $this->fail("the scene draws no desk {$key}");
    }

    /** @return list<string> the element kinds a desk draws */
    private function kinds(array $desk): array
    {
        return array_column($desk['elements'], 'kind');
    }

    /** Every A1/A2 walker any drawn frame carries for one seat, `at => trigger` included. */
    private function walkersFor(array $result, string $key): array
    {
        $out = [];

        foreach ($this->drawn($result) as $r) {
            foreach ($this->walks($r['frame']) as $w) {
                if ($this->keyOf($w) === $key) {
                    $out[] = ['at' => $r['at'], 'trigger' => $r['trigger'], 'walk' => $w];
                }
            }
        }

        return $out;
    }

    private function keyOf(array $walk): string
    {
        return "{$walk['install_id']}/{$walk['seat_id']}";
    }

    /** Each distinct walk a run drew, at the frame that first drew it (its applying render): `[frame, walk]`. */
    private function firstDraws(array $result): array
    {
        $out = [];

        foreach ($this->drawn($result) as $r) {
            foreach ($this->walks($r['frame']) as $w) {
                $id = "{$w['animation_id']} {$this->keyOf($w)} {$w['cause']}";

                if (! isset($out[$id]) && ($w['elapsed_frames'] ?? 0) === 0) {
                    $out[$id] = [$r['frame'], $w];
                }
            }
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> a walk's legs of one kind */
    private function legsOf(array $walk, string $kind): array
    {
        return array_values(array_filter($walk['legs'], static fn (array $l): bool => $l['kind'] === $kind));
    }

    private function threshold(array $frame): array
    {
        $e = $frame['scene']['band']['elevator'];

        return ['x' => $e['seam'], 'y' => $e['y'] + $e['h']];
    }

    /** A polyline's length, re-summed. */
    private function lengthOf(array $points): float
    {
        $length = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $length += hypot($points[$i]['x'] - $points[$i - 1]['x'], $points[$i]['y'] - $points[$i - 1]['y']);
        }

        return $length;
    }

    /** The defects in one walk's legs, against the scene that drew it (H4, H12, and the legs' order per row). */
    private function pathDefects(array $frame, array $walk): array
    {
        $key = $this->keyOf($walk);
        $anchor = $frame['scene']['anchors'][$key];
        $t = $this->threshold($frame);
        $d = $this->door();
        $defects = [];
        $kinds = array_column($walk['legs'], 'kind');
        $owed = $walk['animation_id'] === 'A2' ? ['walk', 'stand', 'stand', 'cab'] : ['cab', 'stand', 'walk'];

        if ($kinds !== $owed) {
            return ["{$walk['animation_id']} {$key}'s legs are ".json_encode($kinds).', not '.json_encode($owed)];
        }

        $walkLeg = $this->legsOf($walk, 'walk')[0];
        $points = $walkLeg['points'];
        [$from, $to] = $walk['animation_id'] === 'A2' ? [$anchor, $t] : [$t, $anchor];

        if ($points[0] != $from || $points[count($points) - 1] != $to) {
            $defects[] = "{$walk['animation_id']} {$key} walks ".json_encode([$points[0], end($points)]).' — not desk anchor to threshold '.json_encode([$from, $to]);
        }

        $length = $this->lengthOf($points);
        $steps = max(1, (int) ceil($length / $this->walkSpeed()));

        if ($walkLeg['frames'] !== $steps) {
            $defects[] = "{$walk['animation_id']} {$key} walks {$walkLeg['frames']} frames, not ⌈{$length} ÷ speed⌉ = {$steps}";
        }

        // Every leg starts where the one before it ends, and every stand or cab is at the threshold.
        $at = 0;

        foreach ($walk['legs'] as $i => $leg) {
            if ($leg['start'] !== $at) {
                $defects[] = "{$walk['animation_id']} {$key}'s leg {$i} starts at frame {$leg['start']}, not {$at}";
            }

            $at = $leg['start'] + $leg['frames'];

            if ($leg['kind'] !== 'walk' && $leg['at'] != $t) {
                $defects[] = "{$walk['animation_id']} {$key}'s {$leg['kind']} leg stands at ".json_encode($leg['at']).', not the threshold';
            }
        }

        if ($walk['animation_id'] === 'A2') {
            [, $wait, $doorStand, $cab] = $walk['legs'];
            $owedWait = $this->seededWait($key, $walk['cause']);

            if ($wait['why'] !== 'wait' || $wait['frames'] !== $owedWait) {
                $defects[] = "A2 {$key} waits {$wait['frames']} frames, not its seeded draw {$owedWait}";
            }

            if ($doorStand['frames'] !== $d['open'] || $cab['frames'] !== $d['step'] + $d['close']) {
                $defects[] = "A2 {$key}'s door stand and cab are not § 12's opening, step and closing";
            }

            if ($walk['door'] != ['start' => $doorStand['start'], 'frames' => $d['open'] + $d['step'] + $d['close']]) {
                $defects[] = "A2 {$key}'s door span ".json_encode($walk['door']).' does not open at the end of its wait';
            }
        } else {
            [$cab, $step] = $walk['legs'];

            if ($cab['frames'] !== $d['open'] || $step['frames'] !== $d['step']) {
                $defects[] = "A1 {$key}'s cab and step are not § 12's opening and step";
            }

            if ($walk['door'] != ['start' => 0, 'frames' => $d['open'] + $d['step'] + $d['close']]) {
                $defects[] = "A1 {$key}'s door span ".json_encode($walk['door']).' is not the opening, step and closing from its start';
            }
        }

        if ($walk['frames'] !== max($at, $walk['door']['start'] + $walk['door']['frames'])) {
            $defects[] = "{$walk['animation_id']} {$key} lasts {$walk['frames']} frames, not its legs' and door's end";
        }

        return $defects;
    }

    // ── The geometry a path is held to (§ 6.2's walk note item 3) ────────────────────────────

    /** @return array{0: int, 1: int} the lattice cell holding a point, half-open */
    private function cellOf(array $p, array $origin, int $c): array
    {
        return [(int) floor(($p['x'] - $origin['x']) / $c), (int) floor(($p['y'] - $origin['y']) / $c)];
    }

    /** @return list<string> `"x,y"` for every cell a rect meets (half-open: touching edges do not) */
    private function cellsMeeting(array $r, array $origin, int $c): array
    {
        if ($r['w'] <= 0 || $r['h'] <= 0) {
            return [];
        }

        $out = [];

        for ($y = (int) floor(($r['y'] - $origin['y']) / $c); $y * $c + $origin['y'] < $r['y'] + $r['h']; $y++) {
            for ($x = (int) floor(($r['x'] - $origin['x']) / $c); $x * $c + $origin['x'] < $r['x'] + $r['w']; $x++) {
                $out[] = "{$x},{$y}";
            }
        }

        return $out;
    }

    /**
     * What one walker may not stand in, on the scene that drew it: the shipped default's `wall` and standing tiles,
     * every other desk's slot and drawn box, its own desk off its anchor's row, and everything outside the extent.
     *
     * @return array{blocked: array<string, true>, own: array<string, true>, row: int, origin: array, c: int, cols: int, rows: int}
     */
    private function forbidden(array $frame, string $key): array
    {
        $shipped = $this->shipped();
        $c = $shipped['cell'];
        $extent = $frame['scene']['extent'];
        // The floor's extent, not the scene's (which holds the band): the room's grid at the room's origin.
        $origin = ['x' => $shipped['extent']['x'], 'y' => $shipped['extent']['y']];
        $anchor = $frame['scene']['anchors'][$key];
        $row = $this->cellOf($anchor, $origin, $c)[1];
        $blocked = [];
        $own = [];

        foreach ($shipped['obstacles'] as $o) {
            foreach ($this->cellsMeeting($o, $origin, $c) as $cell) {
                $blocked[$cell] = true;
            }
        }

        foreach ($frame['scene']['desks'] as $desk) {
            foreach (array_filter([$desk['slot_rect'], $desk['box']]) as $r) {
                foreach ($this->cellsMeeting($r, $origin, $c) as $cell) {
                    if ($desk['key'] === $key) {
                        $own[$cell] = true;
                    } else {
                        $blocked[$cell] = true;
                    }
                }
            }
        }

        foreach (array_keys($own) as $cell) {
            if ((int) explode(',', $cell)[1] !== $row) {
                $blocked[$cell] = true;
            }
        }

        unset($extent);

        return ['blocked' => $blocked, 'own' => $own, 'row' => $row, 'origin' => $origin, 'c' => $c,
            'cols' => (int) ceil($shipped['extent']['width'] / $c), 'rows' => (int) ceil($shipped['extent']['height'] / $c)];
    }

    private function inside(array $f, array $cell): bool
    {
        return $cell[0] >= 0 && $cell[1] >= 0 && $cell[0] < $f['cols'] && $cell[1] < $f['rows'];
    }

    /**
     * Every cell a segment passes through — a supercover: where it crosses a lattice corner exactly, both cells beside
     * the corner are counted too.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function supercover(array $p, array $q, array $origin, int $c): array
    {
        [$x, $y] = $this->cellOf($p, $origin, $c);
        $end = $this->cellOf($q, $origin, $c);
        $cells = [[$x, $y]];
        $dx = $q['x'] - $p['x'];
        $dy = $q['y'] - $p['y'];
        $sx = $dx <=> 0;
        $sy = $dy <=> 0;
        $tMaxX = $dx == 0 ? INF : ((($sx > 0 ? $x + 1 : $x) * $c + $origin['x']) - $p['x']) / $dx;
        $tMaxY = $dy == 0 ? INF : ((($sy > 0 ? $y + 1 : $y) * $c + $origin['y']) - $p['y']) / $dy;
        $tdx = $dx == 0 ? INF : $c / abs($dx);
        $tdy = $dy == 0 ? INF : $c / abs($dy);

        for ($guard = 0; [$x, $y] !== $end && $guard < 10000; $guard++) {
            if (min($tMaxX, $tMaxY) > 1 + 1e-9) {
                break;
            }

            if (abs($tMaxX - $tMaxY) < 1e-9) {
                $cells[] = [$x + $sx, $y];
                $cells[] = [$x, $y + $sy];
                $x += $sx;
                $y += $sy;
                $tMaxX += $tdx;
                $tMaxY += $tdy;
            } elseif ($tMaxX < $tMaxY) {
                $x += $sx;
                $tMaxX += $tdx;
            } else {
                $y += $sy;
                $tMaxY += $tdy;
            }

            $cells[] = [$x, $y];
        }

        return $cells;
    }

    /** H1, H5, H14: the defects in one walk leg's path over the cells its scene forbids it. */
    private function routeDefects(array $frame, array $walk): array
    {
        $key = $this->keyOf($walk);
        $f = $this->forbidden($frame, $key);
        $points = $this->legsOf($walk, 'walk')[0]['points'];
        $defects = [];

        // H1: no segment passes through a forbidden cell or out of the floor.
        for ($i = 1; $i < count($points); $i++) {
            foreach ($this->supercover($points[$i - 1], $points[$i], $f['origin'], $f['c']) as $cell) {
                if (! $this->inside($f, $cell) || isset($f['blocked'][implode(',', $cell)])) {
                    $defects[] = "H1 {$walk['animation_id']} {$key}: the segment ".json_encode([$points[$i - 1], $points[$i]]).' crosses cell '.implode(',', $cell);

                    break 2;
                }
            }
        }

        // The route's cells, in order (its centres between the two ends).
        $cells = array_map(fn ($p) => $this->cellOf($p, $f['origin'], $f['c']), array_slice($points, 1, -1));

        // H5: every diagonal step has both orthogonal neighbours open.
        for ($i = 1; $i < count($cells); $i++) {
            [$ax, $ay] = $cells[$i - 1];
            [$bx, $by] = $cells[$i];

            if ($ax !== $bx && $ay !== $by
                && (isset($f['blocked']["{$bx},{$ay}"]) || isset($f['blocked']["{$ax},{$by}"]))) {
                $defects[] = "H5 {$walk['animation_id']} {$key}: the diagonal {$ax},{$ay} → {$bx},{$by} cuts a corner";

                break;
            }
        }

        // H14: every own-desk cell is on the anchor's row, and the first cell off the own desk differs from the anchor's
        // cell in x only — the walker leaves and reaches its desk sideways.
        $ordered = $walk['animation_id'] === 'A2' ? $cells : array_reverse($cells);
        $anchorCell = $this->cellOf($frame['scene']['anchors'][$key], $f['origin'], $f['c']);

        foreach ($ordered as $cell) {
            $id = implode(',', $cell);

            if (isset($f['own'][$id])) {
                if ($cell[1] !== $f['row']) {
                    $defects[] = "H14 {$walk['animation_id']} {$key}: own-desk cell {$id} is off the anchor's row {$f['row']}";

                    break;
                }

                continue;
            }

            if ($cell[1] !== $anchorCell[1]) {
                $defects[] = "H14 {$walk['animation_id']} {$key}: the first cell off its desk, {$id}, is not on the anchor's row";
            }

            break;
        }

        return $defects;
    }

    /** Where a walk's feet are `$t` ms after its start, re-derived from its legs by arc length. */
    private function feetAt(array $walk, float $t): array
    {
        $I = $walk['frame_interval_ms'];
        $legs = $walk['legs'];
        $leg = end($legs);

        foreach ($legs as $l) {
            if ($t < ($l['start'] + $l['frames']) * $I) {
                $leg = $l;

                break;
            }
        }

        if ($leg['kind'] !== 'walk') {
            return ['x' => $leg['at']['x'], 'y' => $leg['at']['y'], 'where' => $leg['kind'] === 'cab' ? 'cab' : 'floor'];
        }

        $s = $this->lengthOf($leg['points']) * min(1, max(0, ($t - $leg['start'] * $I) / ($leg['frames'] * $I)));
        $points = $leg['points'];

        for ($i = 1; $i < count($points); $i++) {
            $seg = hypot($points[$i]['x'] - $points[$i - 1]['x'], $points[$i]['y'] - $points[$i - 1]['y']);

            if ($s <= $seg && $seg > 0) {
                return ['x' => $points[$i - 1]['x'] + ($points[$i]['x'] - $points[$i - 1]['x']) * $s / $seg,
                    'y' => $points[$i - 1]['y'] + ($points[$i]['y'] - $points[$i - 1]['y']) * $s / $seg, 'where' => 'floor'];
            }

            $s -= $seg;
        }

        $last = end($points);

        return ['x' => $last['x'], 'y' => $last['y'], 'where' => 'floor'];
    }

    /** Every loop frame's instant and every leg boundary of a walk, in ms. @return list<float> */
    private function instants(array $walk): array
    {
        $I = $walk['frame_interval_ms'];
        $out = [];

        for ($f = 0; $f <= $walk['frames']; $f++) {
            $out[] = $f * $I;
        }

        foreach ($walk['legs'] as $l) {
            $out[] = $l['start'] * $I;
            $out[] = ($l['start'] + $l['frames']) * $I - 0.001;
        }

        sort($out);

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** H15: the instants at which a walker's feet stand in a forbidden cell or off the floor. */
    private function feetDefects(array $frame, array $walk): array
    {
        $f = $this->forbidden($frame, $this->keyOf($walk));

        foreach ($this->instants($walk) as $t) {
            $p = $this->feetAt($walk, $t);
            $cell = $this->cellOf($p, $f['origin'], $f['c']);

            if (! $this->inside($f, $cell) || isset($f['blocked'][implode(',', $cell)])) {
                return ["H15 {$walk['animation_id']} {$this->keyOf($walk)}: at {$t} ms its feet are at ".json_encode($p).', in cell '.implode(',', $cell)];
            }
        }

        return [];
    }

    // ── The GREENs ──────────────────────────────────────────────────────────────────────────────

    public function test_green_the_rows(): void
    {
        $leave = $this->floorRun('leave-stale');
        $this->assertSame(['A2 '.self::IMPL_1], $this->walkRows($leave));
        $this->assertSame(31108, $this->edgeRows($leave)[0]['cause']);

        $return = $this->floorRun('return-from-stale');
        $this->assertSame(['A1 '.self::IMPL_1], $this->walkRows($return));

        $retire = $this->floorRun('retire');
        $this->assertSame([], $this->walkRows($retire));
        $this->assertContains('A13', array_column($this->edgeRows($retire), 'animation_id'));

        $both = $this->floorRun('stale-then-offline');
        $this->assertSame(['A2 '.self::IMPL_1], $this->walkRows($both), 'stale-then-offline writes one A2, at the `stale` edge');
        $this->assertSame(31108, $this->edgeRows($both)[0]['cause']);

        $this->assertSame([], $this->walkRows($this->floorRun('staffed-step')), 'a step between two staffed members writes neither A1 nor A2');
    }

    /** The legs of every walk (H4's seeded wait, H12's frames from the path's length), as its applying render drew them. */
    public function test_green_the_walk(): void
    {
        foreach (['leave-stale', 'return-from-stale', 'two-walkers', 'crossing', 'mass-departure'] as $run) {
            $draws = $this->firstDraws($this->floorRun($run));

            $this->assertNotSame([], $draws, "[{$run}] drew no walk to measure");

            foreach ($draws as [$frame, $walk]) {
                $this->assertSame('routed', $walk['route'], "[{$run}] a walk of the shipped default was not routed");
                $this->assertSame([], $this->pathDefects($frame, $walk), "[{$run}]");
            }
        }
    }

    /** H1, H5, H14, H15: every path of every run goes through open floor, and the feet never stand in an obstacle. */
    public function test_green_paths_go_through_open_floor(): void
    {
        $seen = 0;

        foreach (self::ROUTED_RUNS as $run) {
            foreach ($this->firstDraws($this->floorRun($run)) as [$frame, $walk]) {
                $this->assertSame([], [...$this->routeDefects($frame, $walk), ...$this->feetDefects($frame, $walk)], "[{$run}]");
                $seen++;
            }
        }

        $this->assertGreaterThan(5, $seen, 'too few walks were measured for the guard to stand for the shipped default');
    }

    /**
     * H17 and H9: every `desks` object of the committed shipped default routes to the elevator under § 2's rules, the
     * PM's through its office doorway, every path held to H1 and H5; and the lattice with every desk's route on it costs
     * less than one loop frame — the median of five runs; the first (a cold engine's) is printed beside it.
     */
    public function test_green_the_shipped_default_routes_every_desk(): void
    {
        $shipped = $this->shipped();

        $this->assertSame([], $this->unroutable($shipped), 'a desk of the committed shipped default cannot reach the elevator');
        $this->assertTrue($this->pmThroughDoorway($shipped), "the PM's route does not pass through its office doorway");

        $costs = $shipped['cost_ms'];
        $sorted = $costs;
        sort($sorted);
        $median = $sorted[intdiv(count($sorted), 2)];
        fwrite(STDERR, sprintf("\n[H9] lattice + %d routes on the shipped default: %s ms (median %.1f; one loop frame is %d ms)\n",
            count($shipped['desks']), implode(', ', array_map(static fn ($c) => sprintf('%.1f', $c), $costs)), $median, intdiv(1000, $this->loopFps())));
        $this->assertLessThan(1000 / $this->loopFps(), $median, 'the lattice and every route of the shipped default cost more than one loop frame');
    }

    /** @return list<int> the desk objects of a `shipped` answer that did not route */
    private function unroutable(array $shipped): array
    {
        return array_values(array_map(static fn ($d) => $d['id'], array_filter($shipped['desks'], static fn ($d) => $d['cells'] === null)));
    }

    /** Whether the PM's route (the reserved desk's object) passes through the open cells of its office's front wall. */
    private function pmThroughDoorway(array $shipped): bool
    {
        $pm = array_values(array_filter($shipped['desks'], fn ($d) => $d['id'] === $this->reservedDeskId()))[0];
        $wall = $this->officeWall();
        $doorway = array_map(static fn ($x) => [$x, $wall['row']], $wall['gap']);

        return array_intersect(array_map('json_encode', $pm['cells'] ?? []), array_map('json_encode', $doorway)) !== [];
    }

    /** The shipped default's reserved desk — the `desks` object whose `reserved_for` property is set. */
    private function reservedDeskId(): int
    {
        foreach ($this->defaultMap()['layers'] as $layer) {
            foreach ($layer['objects'] ?? [] as $o) {
                foreach ($o['properties'] ?? [] as $p) {
                    if ($p['name'] === 'reserved_for') {
                        return $o['id'];
                    }
                }
            }
        }

        $this->fail('the shipped default reserves no desk');
    }

    /**
     * The PM office's front wall in the shipped default's `walls` layer, re-derived: the row carrying the most wall cells
     * right of the reserved desk's slot's left edge, and the open cells of that row between its first and last wall cell.
     *
     * @return array{row: int, gap: list<int>}
     */
    private function officeWall(): array
    {
        $map = $this->defaultMap();
        $walls = array_values(array_filter($map['layers'], static fn ($l) => $l['name'] === 'walls'))[0]['data'];
        $w = $map['width'];
        $slot = array_values(array_filter(array_values(array_filter($map['layers'], static fn ($l) => $l['name'] === 'desks'))[0]['objects'], fn ($o) => $o['id'] === $this->reservedDeskId()))[0];
        $from = intdiv($slot['x'], $map['tilewidth']) - 2;
        $best = null;

        for ($y = 0; $y < $map['height']; $y++) {
            $row = array_slice($walls, $y * $w + $from, $w - $from);
            $count = count(array_filter($row));

            if ($best === null || $count > $best[1]) {
                $best = [$y, $count, $row];
            }
        }

        [$y, , $row] = $best;
        $set = array_keys(array_filter($row));
        $gap = [];

        for ($x = min($set); $x <= max($set); $x++) {
            if ($row[$x] === 0) {
                $gap[] = $from + $x;
            }
        }

        return ['row' => $y, 'gap' => $gap];
    }

    private function defaultMap(): array
    {
        return json_decode((string) file_get_contents(base_path('../resources/floor/default.tmj')), true);
    }

    /** H2 and H4: one event draws one path and one wait on every viewer — whatever order its snapshot lists the seats in. */
    public function test_green_two_viewers_draw_one_path_and_one_wait(): void
    {
        $one = $this->legsBySeat('mass-departure');

        $this->assertCount(4, $one, 'mass-departure did not draw every seat\'s walk');
        $this->assertSame(json_encode($one), json_encode($this->legsBySeat('mass-departure-reordered')), 'two snapshot orders drew two paths or two waits');
        $this->assertSame(json_encode($one), json_encode($this->legsBySeat('mass-departure')), 'one run replayed twice drew two paths or two waits');
    }

    /** @return array<string, list<array<string, mixed>>> each seat's walk legs as its applying render drew them, by seat */
    private function legsBySeat(string $run, ?string $dir = null): array
    {
        $out = [];

        foreach ($this->firstDraws($this->floorRun($run, $dir)) as [, $walk]) {
            $out[$this->keyOf($walk)] = $walk['legs'];
        }

        ksort($out);

        return $out;
    }

    public function test_green_the_desk_under_an_inbound_walk(): void
    {
        $result = $this->floorRun('return-from-stale');
        $applying = $this->frameAt($result, 1000);
        $desk = $this->deskIn($applying, self::IMPL_1);

        $this->assertNotSame([], $this->walks($applying), 'the applying render draws no A1 walker');
        $this->assertNotContains('character', $this->kinds($desk), 'the desk draws the character while its walker is on the floor');
        $this->assertContains('chair', $this->kinds($desk));
        $this->assertNull($desk['bubble'], 'the desk draws a bubble over the empty chair while its walker is on the floor');

        $byKind = [];

        foreach ($desk['elements'] as $e) {
            $byKind[$e['kind']][] = $e;
        }

        $this->assertSame('working', $byKind['chip'][0]['text'] ?? null, 'the chip is not the applied object\'s');
        $this->assertSame('working', $byKind['label'][0]['text'] ?? null, 'the label line is not the applied object\'s');
        $this->assertCount(2, $byKind['stool'] ?? [], 'the side table is not the applied object\'s');

        $end = $this->frameAt($result, $this->walkEnd($result), 'walk end');
        $seated = $this->deskIn($end, self::IMPL_1);

        $this->assertContains('character', $this->kinds($seated), 'the walk\'s last frame does not seat the character');
        $this->assertNotNull($seated['bubble']);
        $this->assertSame([], array_filter($result['floor_renders'], fn ($r) => $r['at'] === $this->walkEnd($result) && $r['trigger'] === 'render'),
            'a render ran at the walk\'s end — the refresh must be paint-only');
        $this->assertSame([], array_filter($result['animation_log'], fn ($r) => $r['seat_id'] === 'aimla-impl-1' && $r['phase'] === 'entered'
            && $r['at'] > $this->edgeRows($result)[0]['at']), 'the refresh wrote a held row');
    }

    public function test_green_cancel(): void
    {
        $rtl = $this->floorRun('return-then-leave');
        $this->assertSame(['A1 '.self::IMPL_1, 'A2 '.self::IMPL_1], $this->walkRows($rtl));
        $this->assertSame([1000], array_values(array_unique(array_column($this->walkersFor($rtl, self::IMPL_1), 'at'))),
            'return-then-leave draws a walk after the A2 cancelled the A1');
        $this->assertNotContains('character', $this->kinds($this->deskIn($this->lastFloor($rtl), self::IMPL_1)));

        $ltr = $this->floorRun('leave-then-return');
        $this->assertSame([1000], array_values(array_unique(array_column($this->walkersFor($ltr, self::IMPL_1), 'at'))),
            'leave-then-return draws a walk after the A1 cancelled the A2');
        $this->assertContains('character', $this->kinds($this->deskIn($this->frameAt($ltr, 1500), self::IMPL_1)));

        $resync = $this->floorRun('resync-cancels');
        $after = $this->frameAt($resync, 1500);
        $this->assertSame([], $this->walks($after), 'the resync\'s render still draws the A1 walker');
        $this->assertContains('character', $this->kinds($this->deskIn($after, self::IMPL_1)));

        foreach (['reslot' => 1600, 'reslot-a1' => 1600] as $run => $at) {
            $result = $this->floorRun($run);
            $arrival = $this->frameAt($result, $at);

            $this->assertSame([], $this->walks($arrival), "[{$run}] the arrival's render still draws a walker for the re-slotted seat");
            $this->assertSame(5, $this->deskIn($arrival, self::IMPL_2)['slot'], "[{$run}] the arrival did not re-slot the seat");
            $this->assertContains('A16 '.self::IMPL_2, array_map(static fn ($r) => "{$r['animation_id']} {$r['install_id']}/{$r['seat_id']}", $this->edgeRows($result)),
                "[{$run}] the arrival's render logged no A16 for the re-slotted seat");
        }

        $noRow = $this->floorRun('reslot-no-row');
        $this->assertSame([], $this->walks($this->frameAt($noRow, 1500)), 'the room.map\'s render still draws the walker');

        // Item 6's clauses that need no entry for the seat: each run touches S by one of them alone, after a render that
        // drew the walk still in flight (the same instant's first, where it has two).
        foreach (['stilled' => [1500, 1500], 'unconfirmed' => [1750, 1750], 'elevator-moves' => [1000, 1500], 'map-edit-mid-walk' => [1000, 1500]] as $run => [$was, $at]) {
            $result = $this->floorRun($run);

            $this->assertNotSame([], $this->walks($this->frameAt($result, $was, 'render', false)), "[{$run}] no walk was in flight at {$was} ms");
            $this->assertSame([], $this->walks($this->frameAt($result, $at)), "[{$run}] the render at {$at} ms still draws the walker");
        }

        $moved = $this->floorRun('elevator-moves');
        [$before, $after] = [$this->frameAt($moved, 1000)['scene'], $this->frameAt($moved, 1500)['scene']];
        $this->assertSame($before['anchors'][self::IMPL_1], $after['anchors'][self::IMPL_1], 'elevator-moves moved the desk\'s anchor too');
        $this->assertNotSame($before['band']['elevator']['y'], $after['band']['elevator']['y'], 'elevator-moves did not move the elevator');

        $edit = $this->floorRun('map-edit-mid-walk');
        $this->assertSame($this->frameAt($edit, 1000)['scene']['anchors'], $this->frameAt($edit, 1500)['scene']['anchors'], 'map-edit-mid-walk moved a desk');
    }

    /**
     * H7, clause 1: a same-side delta under an A1 leaves the walker on its way and the desk inbound; two opposite edges
     * applied in ONE render leave the side unchanged and still cancel — the two new rows draw nothing, the person is seated.
     */
    public function test_green_a_same_side_delta_keeps_the_walk_and_a_batch_of_edges_cancels_it(): void
    {
        $under = $this->floorRun('delta-under-walk');
        $at = $this->frameAt($under, 2000);

        $this->assertNotSame([], $this->walks($at), 'a `working → idle` delta cancelled the arrival walking in');
        $this->assertNotContains('character', $this->kinds($this->deskIn($at, self::IMPL_1)), 'the desk is not inbound under the walk');
        $this->assertSame(['A1 '.self::IMPL_1], $this->walkRows($under));

        $batched = $this->floorRun('batched-edges');
        $flush = $this->frameAt($batched, 1800);

        $this->assertSame(['A1 '.self::IMPL_1, 'A2 '.self::IMPL_1, 'A1 '.self::IMPL_1], $this->walkRows($batched), 'batched-edges did not apply both edges');
        $this->assertNotSame([], $this->walks($this->frameAt($batched, 1600)), 'no walk was in flight before the flush');
        $this->assertSame([], $this->walks($flush), 'the render that applied two opposite edges still draws a walker');
        $this->assertContains('character', $this->kinds($this->deskIn($flush, self::IMPL_1)), 'the person is not seated after the batch');
    }

    /**
     * H6's two legs the harness cannot replay: a tileset whose status moves with no message, and the theme registry
     * arriving. The floor screen awaits its tilesets inside a render, so no replayed render sees one pending; and a page's
     * art answers once. So each is read at the scene and the holder (`walk-probe.mjs`'s `lattice`): one drawn frame and its
     * rows built twice, and the first scene's walk handed a render carrying the second's key.
     */
    public function test_green_a_lattice_that_moves_without_a_message_cancels_the_walk(): void
    {
        $this->assertSame(['tileset' => true, 'registry' => true], array_map(static fn ($leg) => $leg['keys_differ'] && $leg['cancelled'] === [self::IMPL_1],
            $this->latticeLegs()), 'a tileset or the registry arriving left a walk routed without it in flight');
    }

    private function latticeLegs(?string $dir = null): array
    {
        $result = $this->floorRun('leave-stale');
        $frame = $this->frameAt($result, 1000);
        unset($frame['scene']);
        $rows = array_values(array_filter($result['animation_log'], static fn ($r) => $r['animation_id'] === 'A2'));

        return $this->walkProbe('lattice', ['frame' => $frame, 'rows' => $rows, 'measurer' => self::MEASURER], $dir);
    }

    /**
     * Item 4's refresh is paint-only, so it leaves the viewer where the viewer is (§ 4.5): the camera a
     * zoom left after the applying render, and the ages at the refresh's own instant, as the 1 s tick reads them.
     */
    public function test_green_the_walk_end_keeps_the_viewer(): void
    {
        $result = $this->floorRun('zoom-mid-walk');
        $zoomed = $result['camera_acts'][count($result['camera_acts']) - 1]['after'];
        $end = $this->frameAt($result, $this->walkEnd($result), 'walk end');

        $this->assertNotEquals($zoomed, $this->frameAt($result, 1000)['camera'], 'the zoom moved no camera');
        $this->assertEquals($zoomed, $end['camera'], 'the walk\'s end moved the viewer back to the applying render\'s camera');
        $this->assertGreaterThan($this->frameAt($result, 1000)['desks']['now_ms'], $end['desks']['now_ms'],
            'the walk\'s end re-paints the applying render\'s ages');
    }

    public function test_green_effects_across_paints(): void
    {
        foreach (['repaint' => ['A2', 1000, 1625, 'legs'], 'repaint-a19' => ['A19', 800, 1100, 'ends']] as $run => [$id, $wrote, $later, $geometry]) {
            $result = $this->floorRun($run);
            $pick = static fn (array $frame) => array_values(array_filter($frame['scene']['effects'], static fn ($e) => $e['animation_id'] === $id && $e['frames'] > 0));
            $first = $pick($this->frameAt($result, $wrote));
            $next = $pick($this->frameAt($result, $later));
            $shape = static fn ($e) => $geometry === 'legs' ? $e['legs'] : [$e['from'], $e['to']];

            $this->assertNotSame([], $first, "[{$run}] no {$id} was drawn");
            $this->assertNotSame([], $next, "[{$run}] the {$id} in flight did not survive the render at {$later} ms");
            $this->assertSame((int) (($later - $wrote) / 250), $next[0]['elapsed_frames'], "[{$run}] the {$id} in flight is not at its elapsed frame");
            $this->assertSame($later - $wrote, $next[0]['elapsed_ms'], "[{$run}] the {$id} in flight is not at its elapsed instant");
            $this->assertEquals($shape($first[0]), $shape($next[0]), "[{$run}] the {$id} in flight was re-aimed");
        }

        $two = $this->floorRun('two-walkers');
        $both = $this->frameAt($two, self::BOTH_WALKING_MS);
        $this->assertCount(2, $this->walks($both), 'two-walkers does not draw both walks');
        $this->assertCount(1, $both['scene']['doors'], 'the leaves open more than once over two overlapping door spans');

        $span = $both['scene']['doors'][0];
        $ends = array_map(static fn ($w) => $w['door']['start'] + $w['door']['frames'] - $w['elapsed_frames'], $this->walks($both));
        $starts = array_map(static fn ($w) => $w['door']['start'] - $w['elapsed_frames'], $this->walks($both));
        $this->assertSame([min($starts), max($ends)], [$span['from'], $span['to']], 'the leaves are not open across the union of the door frames');

        foreach ($this->walks($both) as $w) {
            $this->assertSame([], $this->pathDefects($both, $w), 'a queued walk is longer than its own legs');
        }
    }

    /**
     * H11: at an instant where both two-walkers' walkers stand on the threshold in their overlapping waits, they stack
     * by ascending code-unit key — `aimla-review` LATER in the stack, so in front, though it arrived first.
     */
    public function test_green_walkers_at_the_door_stack_by_key(): void
    {
        $this->assertSame([self::IMPL_1, self::REVIEW], $this->doorStack(), 'two walkers on the threshold do not stack by code-unit key');
    }

    /** @return list<string> the walkers' keys in stack order at the first instant both wait on the threshold */
    private function doorStack(?string $dir = null): array
    {
        $two = $this->floorRun('two-walkers', $dir);
        $both = $this->frameAt($two, self::BOTH_WALKING_MS);
        $walks = $this->walks($both);     // the holder's order: the order they arrived in
        $t = $this->threshold($both);
        $waiting = [];

        foreach ($walks as $w) {
            $wait = array_values(array_filter($w['legs'], static fn ($l) => ($l['why'] ?? null) === 'wait'))[0];
            $waiting[] = [($wait['start'] - $w['elapsed_frames']) * 250, ($wait['start'] + $wait['frames'] - $w['elapsed_frames']) * 250];
        }

        $at = max(array_column($waiting, 0));
        $this->assertLessThan(min(array_column($waiting, 1)), $at, 'two-walkers\' waits do not overlap — the fixture premise is broken');

        $walkers = [];

        foreach ($walks as $w) {
            $feet = $this->feetAt($w, $at + $w['elapsed_ms']);
            $this->assertEquals($t, ['x' => $feet['x'], 'y' => $feet['y']], "{$this->keyOf($w)} is not on the threshold in its wait");
            $walkers[] = ['key' => $this->keyOf($w), 'y' => $feet['y']];
        }

        $desks = array_map(static fn ($d) => ['key' => $d['key'], 'depth_y' => $d['depth_y']], $both['scene']['desks']);
        $order = $this->walkProbe('stack', ['cases' => [['desks' => $desks, 'walkers' => $walkers]]], $dir)[0];

        return array_values(array_map(static fn ($o) => $o['key'], array_filter($order, static fn ($o) => $o['kind'] === 'walker')));
    }

    public function test_green_reduced_motion(): void
    {
        foreach (['leave-stale', 'return-from-stale', 'two-walkers'] as $run) {
            $result = $this->floorRun($run, null, ['reduce' => true]);

            $this->assertNotSame([], $this->walkRows($result), "[{$run}] the reduced replay wrote no walk row");

            foreach ($this->edgeRows($result) as $row) {
                $this->assertFalse($row['motion'], "[{$run}] a reduced row was written with motion");
            }

            foreach ($this->drawn($result) as $r) {
                $this->assertSame([], $this->walks($r['frame']), "[{$run}] the reduced replay drew a walker");
                $this->assertSame([], $r['frame']['scene']['doors'], "[{$run}] the reduced replay opened the leaves");
            }
        }
    }

    /**
     * H10: a row the scene cannot place — no open route (`walled-in`), a desk on no grid (`overflow-leaves`), a floor with
     * no band (`no-band-leaves`) — is drawn without its walk: no legs, frames 0, no door, logged `motion: true`; and only
     * the desk inside a room writes § 5.5's line.
     */
    public function test_green_a_row_with_no_route_is_drawn_without_the_walk(): void
    {
        foreach (['walled-in' => [self::PM, true], 'overflow-leaves' => [self::IMPL_1, false], 'no-band-leaves' => [self::IMPL_1, false]] as $run => [$key, $line]) {
            $this->assertSame([], $this->noRouteDefects($this->floorRun($run), $key, $line), "[{$run}]");
        }
    }

    private function noRouteDefects(array $result, string $key, bool $line): array
    {
        $defects = [];
        $rows = array_values(array_filter($this->edgeRows($result), static fn ($r) => $r['animation_id'] === 'A2' && "{$r['install_id']}/{$r['seat_id']}" === $key));

        if (count($rows) !== 1 || $rows[0]['motion'] !== true) {
            $defects[] = 'the A2 row was not logged once, with motion';
        }

        foreach ($this->drawn($result) as $r) {
            foreach ($this->walkEffects($r['frame']) as $e) {
                if ($this->keyOf($e) === $key && ($e['frames'] !== 0 || $e['legs'] !== [] || $e['door'] !== null || $e['route'] !== 'none')) {
                    $defects[] = "the A2 at {$r['at']} ms is drawn with a walk: route {$e['route']}, {$e['frames']} frames";
                }
            }

            if ($r['frame']['scene']['doors'] !== []) {
                $defects[] = "the leaves opened at {$r['at']} ms";
            }
        }

        $written = array_values(array_filter($result['final']['event_log'], static fn ($l) => str_contains($l, "{$key}: no open route between its desk and the elevator — drawn without the walk")));

        if ($line !== ($written !== [])) {
            $defects[] = $line ? 'the event log does not name the seat with no open route' : 'the event log names a seat that is not a defect of a room\'s map';
        }

        return $defects;
    }

    /** H20's headless half, and H8's: `pointAt()` is the arc-length point at every sampled instant, continuous across every leg boundary, and `cab` at the threshold in the cab legs. */
    public function test_green_a_walker_is_one_continuous_function_of_time(): void
    {
        $cases = [];
        $owed = [];

        foreach (['leave-stale', 'return-from-stale', 'two-walkers'] as $run) {
            foreach ($this->firstDraws($this->floorRun($run)) as [$frame, $walk]) {
                $times = [];

                foreach ($walk['legs'] as $l) {
                    foreach ([-0.5, 0.0, 0.5, 37.25] as $d) {
                        $times[] = $l['start'] * $walk['frame_interval_ms'] + $d;
                    }
                }

                $times[] = $walk['frames'] * $walk['frame_interval_ms'] + 1000;
                $cases[] = ['effect' => $walk, 'times' => $times];
                $owed[] = [$frame, $walk, $times];
            }
        }

        $answers = $this->walkProbe('points', ['cases' => $cases]);
        $speed = $this->walkSpeed() / 250;

        foreach ($owed as $i => [$frame, $walk, $times]) {
            foreach ($times as $j => $t) {
                $p = $answers[$i][$j];
                $q = $this->feetAt($walk, max(0, $t));

                $this->assertEqualsWithDelta($q['x'], $p['x'], 1e-6, "{$this->keyOf($walk)} at {$t} ms is not its arc-length point");
                $this->assertEqualsWithDelta($q['y'], $p['y'], 1e-6, "{$this->keyOf($walk)} at {$t} ms is not its arc-length point");
                $this->assertSame($q['where'], $p['where'], "{$this->keyOf($walk)} at {$t} ms is drawn in the wrong place");

                if ($p['where'] === 'cab') {
                    $this->assertEquals($this->threshold($frame), ['x' => $p['x'], 'y' => $p['y']], 'a walker in the cab is not at the threshold');
                }

                // Across each boundary, one millisecond moves the feet no further than the walk speed covers.
                if ($j % 4 === 0 && isset($times[$j + 2])) {
                    $after = $answers[$i][$j + 2];

                    $this->assertLessThanOrEqual($speed * 1.0 + 1e-6, hypot($after['x'] - $p['x'], $after['y'] - $p['y']),
                        "{$this->keyOf($walk)} jumps at the leg boundary near {$t} ms");
                }
            }
        }
    }

    /**
     * H16 and H18's headless halves: at every loop frame of every run, each walker is placed after every desk whose
     * depth key is at or above its feet and before the first deeper one, walkers among themselves by `(y, code-unit key)`,
     * and the desks keep their scene order — given here in that order and REVERSED, so a stack that sorted the desks
     * would show it.
     */
    public function test_green_the_floor_stack_orders_walkers_by_depth_and_never_moves_a_desk(): void
    {
        $this->assertSame([], $this->stackDefects(), 'the floor stack is not § 6.2 item 9\'s order');
    }

    private function stackDefects(?string $dir = null): array
    {
        $cases = [];

        foreach (['crossing', 'mass-departure', 'two-walkers'] as $run) {
            foreach ($this->drawn($this->floorRun($run)) as $r) {
                $walks = $this->walks($r['frame']);

                if ($walks === []) {
                    continue;
                }

                $desks = array_map(static fn ($d) => ['key' => $d['key'], 'depth_y' => $d['depth_y']], $r['frame']['scene']['desks']);
                $end = max(array_map(static fn ($w) => ($w['frames'] - $w['elapsed_frames']) * 250, $walks));

                for ($t = 0; $t <= min($end, 20000); $t += 250) {
                    $walkers = array_map(fn ($w) => ['key' => $this->keyOf($w), 'y' => $this->feetAt($w, $w['elapsed_ms'] + $t)['y']], $walks);
                    $cases[] = ['desks' => $desks, 'walkers' => $walkers];
                    $cases[] = ['desks' => array_reverse($desks), 'walkers' => $walkers];
                }
            }
        }

        $orders = $this->walkProbe('stack', ['cases' => $cases], $dir);
        $defects = [];

        foreach ($orders as $i => $order) {
            $desks = $cases[$i]['desks'];
            $deskOrder = array_values(array_map(static fn ($o) => $o['key'], array_filter($order, static fn ($o) => $o['kind'] === 'desk')));

            if ($deskOrder !== array_column($desks, 'key')) {
                $defects[] = "H18 case {$i}: the desks were re-ordered";
            }

            $placed = [];
            $slot = 0;

            foreach ($order as $o) {
                if ($o['kind'] === 'desk') {
                    $slot++;

                    continue;
                }

                $y = array_values(array_filter($cases[$i]['walkers'], static fn ($w) => $w['key'] === $o['key']))[0]['y'];
                $first = count($desks);

                foreach ($desks as $k => $d) {
                    if ($d['depth_y'] > $y) {
                        $first = $k;

                        break;
                    }
                }

                if ($slot !== $first) {
                    $defects[] = "H16 case {$i}: {$o['key']} at y {$y} is placed before desk {$slot}, not before the first deeper desk {$first}";
                }

                $placed[] = [$slot, $y, $o['key']];
            }

            for ($k = 1; $k < count($placed); $k++) {
                [$sa, $ya, $ka] = $placed[$k - 1];
                [$sb, $yb, $kb] = $placed[$k];

                if ($sa === $sb && ($ya > $yb || ($ya === $yb && strcmp($ka, $kb) > 0))) {
                    $defects[] = "H16 case {$i}: walkers {$ka} and {$kb} are not ordered by (y, code-unit key)";
                }
            }

            if (count($defects) > 5) {
                break;
            }
        }

        return $defects;
    }

    /** § 12's rows are the code's constants (`floor/scene.js`, `floor/route.js`), held equal both ways. */
    public function test_green_section_12_is_the_scenes_constants(): void
    {
        $source = (string) file_get_contents($this->moduleDir().'/../floor/scene.js');
        $route = (string) file_get_contents($this->moduleDir().'/../floor/route.js');

        $this->assertSame(1, preg_match('/export const WALK_PX_PER_FRAME = (\d+);/', $source, $w));
        $this->assertSame($this->walkSpeed(), (int) $w[1], '§ 12\'s *Walk speed* is not scene.js\'s WALK_PX_PER_FRAME');
        $this->assertSame(1, preg_match('/export const ELEVATOR_DOOR = Object\.freeze\(\{ open: (\d+), step: (\d+), close: (\d+) \}\);/', $source, $d));
        $this->assertSame($this->door(), ['open' => (int) $d[1], 'step' => (int) $d[2], 'close' => (int) $d[3]],
            '§ 12\'s *Elevator leaves* row is not scene.js\'s ELEVATOR_DOOR');
        $this->assertSame(1, preg_match('/export const ELEVATOR_WAIT_S = (\d+);/', $source, $s));
        $this->assertSame($this->waitSeconds(), (int) $s[1], '§ 12\'s *Elevator wait* is not scene.js\'s ELEVATOR_WAIT_S');
        $this->assertSame(1, preg_match('/export const STEP_COST = Object\.freeze\(\{ straight: (\d+), diagonal: (\d+) \}\);/', $route, $c));
        $this->assertSame($this->stepCost(), ['straight' => (int) $c[1], 'diagonal' => (int) $c[2]], '§ 12\'s *Route lattice and cost* is not route.js\'s STEP_COST');
    }

    // ── The REDs: each plant, replayed against the run § 11 names, breaks that run's GREEN ─────

    public function test_red_a2_keyed_on_offline_alone(): void
    {
        $dir = $this->mutatedModules([self::ANIMATION_SET, 'staffed(before.render_state) && emptyChair(after.render_state) }', "after.render_state === 'offline' }"]);

        $this->assertSame([], $this->walkRows($this->floorRun('leave-stale', $dir)), 'the plant still wrote the `stale` edge\'s A2');
    }

    public function test_red_a2_keyed_on_the_new_value_alone(): void
    {
        $dir = $this->mutatedModules([self::ANIMATION_SET, 'staffed(before.render_state) && emptyChair(after.render_state) }', 'emptyChair(after.render_state) }']);

        $this->assertCount(3, $this->walkRows($this->floorRun('stale-then-offline', $dir)),
            'the plant did not write A2s on `stale → offline` and the re-sent `offline`');
    }

    public function test_red_a1_keyed_on_leaving_offline_alone(): void
    {
        $dir = $this->mutatedModules([self::ANIMATION_SET, 'emptyChair(before.render_state) && staffed(after.render_state) }',
            "before.render_state === 'offline' && after.render_state !== 'offline' && !retiring(before, after) }"]);

        $this->assertSame([], $this->walkRows($this->floorRun('return-from-stale', $dir)));
    }

    public function test_red_a1_keyed_on_the_new_value_alone(): void
    {
        $dir = $this->mutatedModules([self::ANIMATION_SET, 'emptyChair(before.render_state) && staffed(after.render_state) }', 'staffed(after.render_state) }']);

        $this->assertSame(['A1 '.self::IMPL_1], $this->walkRows($this->floorRun('staffed-step', $dir)));
    }

    public function test_red_a2_keyed_on_the_old_value_alone(): void
    {
        $dir = $this->mutatedModules([self::ANIMATION_SET, 'staffed(before.render_state) && emptyChair(after.render_state) }',
            'staffed(before.render_state) && !retiring(before, after) }']);

        $this->assertSame(['A2 '.self::IMPL_1], $this->walkRows($this->floorRun('staffed-step', $dir)));
    }

    public function test_red_a2_empty_read_as_not_staffed(): void
    {
        $dir = $this->mutatedModules([self::ANIMATION_SET, 'staffed(before.render_state) && emptyChair(after.render_state) }',
            'staffed(before.render_state) && !staffed(after.render_state) }']);

        $this->assertSame(['A2 '.self::IMPL_1], $this->walkRows($this->floorRun('retire', $dir)));
    }

    public function test_red_a_cancelling_a2_draws_its_own_walk(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, 'if (cancelled.includes(key) ||', "if ((cancelled.includes(key) && effect.animation_id !== 'A2') ||"]);

        $this->assertContains(1500, array_column($this->walkersFor($this->floorRun('return-then-leave', $dir), self::IMPL_1), 'at'));
    }

    public function test_red_a_cancelling_a1_draws_its_own_walk(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, 'if (cancelled.includes(key) ||', "if ((cancelled.includes(key) && effect.animation_id !== 'A1') ||"]);

        $this->assertContains(1500, array_column($this->walkersFor($this->floorRun('leave-then-return', $dir), self::IMPL_1), 'at'));
    }

    public function test_red_a_non_delta_row_that_does_not_cancel(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "entry.outcome === 'applied' && entry.t !== 'seat.delta' && typeof entry.seat_id === 'string'",
            "entry.outcome === 'applied' && entry.t === 'never' && typeof entry.seat_id === 'string'"]);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('resync-cancels', $dir), 1500)));
    }

    /** H7: the shipped clause restored — any applied entry for the seat cancels — and a same-side delta kills the arrival. */
    public function test_red_any_applied_entry_cancels(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "entry.outcome === 'applied' && entry.t !== 'seat.delta' && typeof entry.seat_id === 'string'",
            "entry.outcome === 'applied' && typeof entry.seat_id === 'string'"]);

        $this->assertSame([], $this->walks($this->frameAt($this->floorRun('delta-under-walk', $dir), 2000)));
    }

    /** H7: the side tested alone — two opposite edges in one render leave it unchanged, and the walker keeps going. */
    public function test_red_the_side_alone_misses_a_batch_of_edges(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "                || rowKeys.has(h.key)\n", '']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('batched-edges', $dir), 1800)));
    }

    public function test_red_cancel_keyed_on_the_journal_alone(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, '|| !samePoint(ctx.anchors.get(h.key) ?? null, h.anchor)', '']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('reslot', $dir), 1600)));
    }

    public function test_red_cancel_keyed_on_seat_entries_and_own_rows(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, 'const touched = touchedKeys.has(h.key)
                || rowKeys.has(h.key)
                || ctx.side(h.key) !== h.side
                || ctx.lattice_key !== h.lattice_key
                || ctx.unconfirmed(h.key) !== h.unconfirmed
                || ctx.stilled !== h.stilled
                || !samePoint(ctx.anchors.get(h.key) ?? null, h.anchor)
                || !samePoint(ctx.threshold, h.threshold);', 'const touched = touchedKeys.size > 0;']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('reslot-no-row', $dir), 1500)));
    }

    public function test_red_cancel_without_the_row_5_clause(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "                || ctx.unconfirmed(h.key) !== h.unconfirmed\n", '']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('unconfirmed', $dir), 1750)));
    }

    public function test_red_cancel_without_the_stilled_clause(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "                || ctx.stilled !== h.stilled\n", '']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('stilled', $dir), 1500)));
    }

    /**
     * The threshold clause, planted out together with the lattice clause: every layout act that moves the band also moves
     * the layout's version, which is in the lattice key, so on `elevator-moves` the lattice clause alone cancels too — the
     * threshold clause's own bite is seen only with both gone.
     */
    public function test_red_cancel_without_the_threshold_and_lattice_clauses(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "                || ctx.lattice_key !== h.lattice_key\n                || ctx.unconfirmed(h.key) !== h.unconfirmed\n                || ctx.stilled !== h.stilled\n                || !samePoint(ctx.anchors.get(h.key) ?? null, h.anchor)\n                || !samePoint(ctx.threshold, h.threshold);",
            "                || ctx.unconfirmed(h.key) !== h.unconfirmed\n                || ctx.stilled !== h.stilled\n                || !samePoint(ctx.anchors.get(h.key) ?? null, h.anchor);"]);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('elevator-moves', $dir), 1500)));
    }

    /** H6: the lattice clause planted out — a `room.map` that moves no desk leaves the walker on a floor no longer drawn. */
    public function test_red_cancel_without_the_lattice_clause(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "                || ctx.lattice_key !== h.lattice_key\n", '']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('map-edit-mid-walk', $dir), 1500)));
    }

    /** H6: the key fingerprints the map versions alone — a tileset or the registry arriving cancels nothing. */
    public function test_red_lattice_key_of_map_versions_alone(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "    return JSON.stringify([\n        input.layout_version ?? null,",
            "    return JSON.stringify(frame.rooms.map((room) => room.map?.map_version ?? null)) || JSON.stringify([\n        input.layout_version ?? null,"]);

        $this->assertNotSame(['tileset' => true, 'registry' => true],
            array_map(static fn ($leg) => $leg['keys_differ'] && $leg['cancelled'] === [self::IMPL_1], $this->latticeLegs($dir)));
    }

    /** H6: the cancel keyed on layout acts alone — a tileset arriving with no message cancels nothing. */
    public function test_red_lattice_cancel_keyed_on_layout_acts_alone(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, '                || ctx.lattice_key !== h.lattice_key',
            "                || (ctx.journal ?? []).some((entry) => entry.t === 'room.map' || entry.t === 'building.layout')"]);

        $this->assertSame([], $this->latticeLegs($dir)['tileset']['cancelled']);
    }

    public function test_red_the_walk_end_repaints_the_renders_camera(): void
    {
        $dir = $this->mutatedModules(['../floor/floor-screen.js', "            camera: this.#camera,\n            seat: this.#seatSegment,", '            seat: this.#seatSegment,']);
        $result = $this->floorRun('zoom-mid-walk', $dir);

        $this->assertEquals($this->frameAt($result, 1000)['camera'], $this->frameAt($result, $this->walkEnd($result), 'walk end')['camera']);
    }

    public function test_red_the_walk_end_repaints_the_renders_ages(): void
    {
        $dir = $this->mutatedModules(['../floor/floor-screen.js', 'this.#desks.view(floorAgeReadouts(this.#desks.seats, this.#desks.clockOffsetMs, now))', 'this.#lastFrame.desks']);
        $result = $this->floorRun('zoom-mid-walk', $dir);

        $this->assertSame($this->frameAt($result, 1000)['desks']['now_ms'], $this->frameAt($result, $this->walkEnd($result), 'walk end')['desks']['now_ms']);
    }

    public function test_red_the_desk_under_an_inbound_walk_drawn_as_its_render(): void
    {
        $dir = $this->mutatedModules(['../floor/floor-screen.js', 'if (inbound.size > 0) {', 'if (false) {']);
        $desk = $this->deskIn($this->frameAt($this->floorRun('return-from-stale', $dir), 1000), self::IMPL_1);

        $this->assertContains('character', $this->kinds($desk));
        $this->assertNotNull($desk['bubble']);
    }

    public function test_red_effects_dropped_at_a_repaint(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, 'this.#held = this.#held.filter((h) => {', 'this.#held = [].filter((h) => {']);

        $this->assertSame([], $this->walks($this->frameAt($this->floorRun('repaint', $dir), 1625)));
    }

    /** Item 9's "no queue at the door" (the shipped RED, kept): a walk lengthened by the walkers already in flight. */
    public function test_red_walkers_queued_at_the_door(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "            this.#held.push({\n                effect,\n                at: now,",
            "            this.#held.push({\n                effect: Object.freeze({ ...effect, frames: effect.frames + 5 * this.#held.filter((x) => x.key !== null).length }),\n                at: now,"]);
        $both = $this->frameAt($this->floorRun('two-walkers', $dir), self::BOTH_WALKING_MS);

        $this->assertNotSame([], array_merge(...array_map(fn ($w) => $this->pathDefects($both, $w), $this->walks($both))),
            'the plant queued no walk behind another');
    }

    /** H4: the wait from `Math.random()` — two replays of one event wait differently, and neither is the seeded draw. */
    public function test_red_the_wait_from_math_random(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'draw(seat, `elevator-wait:${cause}`) % (WAIT_MAX_FRAMES + 1)', 'Math.floor(Math.random() * (WAIT_MAX_FRAMES + 1))']);
        $draws = $this->firstDraws($this->floorRun('leave-stale', $dir));

        $this->assertNotSame([], array_merge(...array_map(fn ($fw) => $this->pathDefects($fw[0], $fw[1]), $draws)));
    }

    /** H4: a later departure's wait lengthened by the earlier walker's remaining frames — a wait that reads another walk. */
    public function test_red_the_wait_reads_another_walk(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "            this.#held.push({\n                effect,\n                at: now,",
            "            this.#held.push({\n                effect: Object.freeze({ ...effect, legs: effect.legs.map((l) => (l.why === 'wait' ? { ...l, frames: l.frames + this.#held.filter((x) => x.key !== null).reduce((n, x) => n + x.frames - this.#elapsed(x, now), 0) } : l)) }),\n                at: now,"]);
        $both = $this->frameAt($this->floorRun('two-walkers', $dir), self::BOTH_WALKING_MS);

        $this->assertNotSame([], array_merge(...array_map(fn ($w) => $this->pathDefects($both, $w), $this->walks($both))));
    }

    /** H12: the frames counted from the straight-line distance between the ends. */
    public function test_red_frames_from_the_straight_line(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'const steps = Math.max(1, Math.ceil(length / WALK_PX_PER_FRAME));',
            'const steps = Math.max(1, Math.ceil(Math.hypot(to.x - from.x, to.y - from.y) / WALK_PX_PER_FRAME));']);
        $draws = $this->firstDraws($this->floorRun('leave-stale', $dir));

        $this->assertNotSame([], array_merge(...array_map(fn ($fw) => $this->pathDefects($fw[0], $fw[1]), $draws)));
    }

    public function test_red_the_walk_drawn_under_reduce(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'if (!motion || anchor === null || door === null) {', 'if (anchor === null || door === null) {']);
        $result = $this->floorRun('leave-stale', $dir, ['reduce' => true]);

        $this->assertNotSame([], array_merge(...array_map(fn ($r) => $this->walks($r['frame']), $this->drawn($result))));
    }

    /** H1: the desks dropped from the obstacles — a path goes through a desk. */
    public function test_red_desks_dropped_from_the_obstacles(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'desks: desks.map((d) => ({ key: d.key, rects: [d.slot_rect, d.box].filter((r) => r !== null) })),', 'desks: [],']);

        $this->assertNotSame([], $this->allRouteDefects($dir), 'no path crossed a desk with the desks dropped');
    }

    /** H5: a diagonal allowed past one blocked orthogonal neighbour. */
    public function test_red_a_corner_cut(): void
    {
        $dir = $this->mutatedModules([self::ROUTE, 'if (diagonal && (!open(x + dx, y) || !open(x, y + dy))) {', 'if (diagonal && (!open(x + dx, y) && !open(x, y + dy))) {']);

        $this->assertNotSame([], [...$this->allRouteDefects($dir), ...$this->shippedCornerDefects($this->shipped($dir))], 'no path cut a corner');
    }

    /** H14: every own-desk cell open — the walker cuts across its own desk off its anchor's row. */
    public function test_red_every_own_desk_cell_open(): void
    {
        $dir = $this->mutatedModules([self::ROUTE, 'if (desk.key !== walker.key || anchor === null || Math.floor(i / grid.cols) !== anchor[1]) {', 'if (desk.key !== walker.key) {']);

        $this->assertNotSame([], array_filter($this->allRouteDefects($dir), static fn ($d) => str_starts_with($d, 'H14')), 'no walker left its desk off its anchor row');
    }

    /** H15: the walk leg shifted one cell down — the feet stand in a desk. */
    public function test_red_the_walk_leg_shifted(): void
    {
        $dir = $this->mutatedModules([self::SCENE, '...cells.map((c) => walking.centre(c)),', '...cells.map((c) => walking.centre([c[0], c[1] + 2])),']);

        $this->assertNotSame([], $this->allFeetDefects($dir));
    }

    /** H15: a stand leg's point inside the band, off the floor. */
    public function test_red_a_stand_off_the_floor(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'const at = Object.freeze({ x: door.x, y: door.y });', 'const at = Object.freeze({ x: door.x, y: door.y - 8 });']);

        $this->assertNotSame([], $this->allFeetDefects($dir));
    }

    /** H2: a tie-break reading the snapshot's seat order — two viewers' snapshots, two paths. */
    public function test_red_a_tie_break_reading_the_snapshot_order(): void
    {
        // The open set's `g` tie flipped for every seat at an even place in a Set filled in the snapshot's order.
        $dir = $this->mutatedModules([self::ROUTE, 'const before = (a, b) => a[0] - b[0] || b[1] - a[1] || a[2] - b[2] || a[3] - b[3];',
            'const before = (a, b) => a[0] - b[0] || (walker.flip ? a[1] - b[1] : b[1] - a[1]) || a[2] - b[2] || a[3] - b[3];']);
        $dir = $this->mutatedModulesOn($dir, [self::SCENE, 'return g === null ? null : route(g, from, to, { key: seat, anchor });',
            'return g === null ? null : route(g, from, to, { key: seat, anchor, flip: [...new Set(order)].indexOf(seat) % 2 === 0 });']);
        $dir = $this->mutatedModulesOn($dir, [self::SCENE, 'function walkRouting({ key, extent, cell, obstacles, desks, draw }) {', 'function walkRouting({ key, extent, cell, obstacles, desks, draw, order }) {']);
        $dir = $this->mutatedModulesOn($dir, [self::SCENE, '        draw: input.draw ?? null,', "        draw: input.draw ?? null,\n        order: Object.keys(frame.desks.desks),"]);

        $this->assertNotSame(json_encode($this->legsBySeat('mass-departure', $dir)), json_encode($this->legsBySeat('mass-departure-reordered', $dir)), 'the plant drew one path for two snapshot orders');
    }

    /** H10: an unroutable desk falls back to the straight segment. */
    public function test_red_no_route_falls_back_to_the_segment(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'const cells = walking.route(key, anchor, from, to);', 'const cells = walking.route(key, anchor, from, to) ?? [];']);

        $this->assertNotSame([], $this->noRouteDefects($this->floorRun('walled-in', $dir), self::PM, true));
    }

    /** H10: § 5.5's line written for an overflow desk too. */
    public function test_red_the_no_route_line_for_an_overflow_seat(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'return none(walking.inRoom(key));', 'return none(true);']);

        $this->assertNotSame([], $this->noRouteDefects($this->floorRun('overflow-leaves', $dir), self::IMPL_1, false));
    }

    /** H11: the walkers at the door stacked by arrival — the later arrival in front. */
    public function test_red_walkers_stacked_by_arrival(): void
    {
        $dir = $this->mutatedModules([self::ROUTE, ' || (a.w.key < b.w.key ? -1 : a.w.key > b.w.key ? 1 : 0)', '']);

        $this->assertNotSame([self::IMPL_1, self::REVIEW], $this->doorStack($dir));
    }

    /** H16: walkers sorted by key alone. */
    public function test_red_walkers_sorted_by_key_alone(): void
    {
        $dir = $this->mutatedModules([self::ROUTE, '.sort((a, b) => a.slot - b.slot || a.w.y - b.w.y ||', '.map((p) => ({ ...p, slot: 0 })).sort((a, b) =>']);

        $this->assertNotSame([], $this->stackDefects($dir));
    }

    /** H18: the desks sorted by their depth key — the floor stack re-orders desks. */
    public function test_red_desks_sorted_by_depth(): void
    {
        $dir = $this->mutatedModules([self::ROUTE, "export function floorStackOrder(desks, walkers) {\n", "export function floorStackOrder(given, walkers) {\n    const desks = [...given].sort((a, b) => a.depth_y - b.depth_y || (a.key < b.key ? -1 : 1));\n"]);

        $this->assertNotSame([], $this->stackDefects($dir));
    }

    /** H16, H19, H21 at the painter (`painter-probe.mjs`): each plant reds the A2 walker leg. */
    public function test_red_the_painters_walker(): void
    {
        $plants = [
            'the walker appended to the effects layer and never placed among the desks (H16)' => [self::PAINTER, "    function stack(layer, depths, deskNode, walkers, now, repin) {\n", "    function stack(layer, depths, deskNode, walkers, now, repin) {\n        return;\n"],
            'the cab element in the effects layer (H8)' => [self::PAINTER, '{ stack: desks, cab: cabLayer }', '{ stack: desks, cab: effectsLayer }'],
            'the pointer rule dropped (H19)' => [self::PAINTER, 'opacity:0;pointer-events:none;animation-name:walker-shown', 'opacity:0;animation-name:walker-shown'],
            'a walker stepped unconditionally (H21)' => [self::PAINTER, 'if (window !== null && !strides(window, now)) {', 'if (false) {'],
            'the cab image pushed into stepping (H21)' => [self::PAINTER, "                at(inner);\n", "                stepping.push([at(inner), drawn.uris, drawn.loopFrom, 0]);\n"],
        ];

        foreach ($plants as $what => $edit) {
            $dir = $this->mutatedModules($edit);

            if (str_contains($what, 'never placed among the desks')) {
                $dir = $this->mutatedModulesOn($dir, [self::PAINTER, '{ stack: desks, cab: cabLayer }', '{ stack: effectsLayer, cab: cabLayer }']);
            }

            $out = json_decode((string) shell_exec('node '.escapeshellarg(__DIR__.'/painter-probe.mjs').' --js '.escapeshellarg(dirname($dir)).' 2>&1'), true);

            $this->assertIsArray($out, "CONTROL ({$what}): the painter probe printed no JSON");
            $this->assertNotSame([], array_filter($out['defects'], static fn ($d) => str_contains($d, 'A2 walker') || str_contains($d, 'pointer-events')), "CONTROL ({$what}) did not red the walker leg");
        }
    }

    /** H17: one wall cell across the PM's doorway in a copy of the map; and the doorway blocked in the lattice with the map left alone. */
    public function test_red_the_shipped_default_with_its_pm_doorway_closed(): void
    {
        $wall = $this->officeWall();

        $this->assertNotSame([], $wall['gap'], 'the shipped default\'s PM office has no doorway to close');
        $this->assertNotSame([], $this->unroutable($this->shipped(null, array_map(static fn ($x) => [$x, $wall['row']], $wall['gap']))), 'CONTROL (a wall across the doorway in a copy of the map) did not bite');

        $c = $this->defaultMap()['tilewidth'];
        $door = ['x' => min($wall['gap']) * $c, 'y' => $wall['row'] * $c, 'w' => count($wall['gap']) * $c, 'h' => $c];
        $dir = $this->mutatedModules([self::ROUTE, "    for (const r of obstacles) {\n", '    for (const r of [...obstacles, '.json_encode($door)."]) {\n"]);
        $this->assertNotSame([], $this->unroutable($this->shipped($dir)), 'CONTROL (the doorway blocked in the lattice) did not bite');
    }

    /** Every H1/H5/H14 defect of every routed run's walks, on a tree. */
    private function allRouteDefects(?string $dir): array
    {
        $defects = [];

        foreach (self::ROUTED_RUNS as $run) {
            foreach ($this->firstDraws($this->floorRun($run, $dir)) as [$frame, $walk]) {
                array_push($defects, ...$this->routeDefects($frame, $walk));
            }
        }

        return $defects;
    }

    /** Every H15 defect of every routed run's walks, on a tree. */
    private function allFeetDefects(?string $dir): array
    {
        $defects = [];

        foreach (self::ROUTED_RUNS as $run) {
            foreach ($this->firstDraws($this->floorRun($run, $dir)) as [$frame, $walk]) {
                array_push($defects, ...$this->feetDefects($frame, $walk));
            }
        }

        return $defects;
    }

    /** H5 over the shipped default's routes of every desk object. */
    private function shippedCornerDefects(array $shipped): array
    {
        $c = $shipped['cell'];
        $blocked = [];

        foreach ($shipped['obstacles'] as $o) {
            foreach ($this->cellsMeeting($o, ['x' => 0, 'y' => 0], $c) as $cell) {
                $blocked[$cell] = true;
            }
        }

        $defects = [];

        foreach ($shipped['desks'] as $d) {
            $cells = $d['cells'] ?? [];

            for ($i = 1; $i < count($cells); $i++) {
                [$ax, $ay] = $cells[$i - 1];
                [$bx, $by] = $cells[$i];

                if ($ax !== $bx && $ay !== $by && (isset($blocked["{$bx},{$ay}"]) || isset($blocked["{$ax},{$by}"]))) {
                    $defects[] = "H5 desk {$d['id']}: {$ax},{$ay} → {$bx},{$by}";
                }
            }
        }

        return $defects;
    }

    /** A further anchored edit on an already-mutated copy (a plant of more than one edit). */
    private function mutatedModulesOn(string $moduleDir, array $edit): string
    {
        [$file, $anchor, $replacement] = $edit;
        $target = $moduleDir.DIRECTORY_SEPARATOR.$file;
        $original = (string) file_get_contents($target);

        $this->assertSame(1, substr_count($original, $anchor), "the control's second anchor is not in {$file} exactly once");
        file_put_contents($target, str_replace($anchor, $replacement, $original));

        return $moduleDir;
    }

    /**
     * The paint-only refresh at a run's first walk's end: the first refresh that draws no walk after a
     * frame that drew one. The refresh also fires when any other effect in flight ends (card#12239: one
     * end-timer over every held effect), so the first refresh is not necessarily a walk's.
     */
    private function walkEnd(array $result): int
    {
        $walking = false;

        foreach ($result['floor_renders'] as $r) {
            if ($walking && $r['trigger'] === 'walk end' && $this->walks($r['frame']) === []) {
                return $r['at'];
            }

            $walking = $walking || $this->walks($r['frame']) !== [];
        }

        $this->fail('the run has no walk-end refresh');
    }
}

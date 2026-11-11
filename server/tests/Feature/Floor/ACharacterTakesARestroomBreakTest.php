<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-33 — a character takes a restroom break every one to five hours, waits its turn at the door, and the sign reads
 * occupied while it is inside (`docs/design/FLOOR.md § 11`, card#12786): § 6.7's visit, § 6.2's row A26, over `fx-restroom`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A VISIT IS PRESENTATION, NEVER STATE. The rows are the animation log's and are asserted there; the visit is asserted on
 * the scene each render and each paint-only refresh drew — `frame.scene.effects` (each A26 effect with frames), the door's
 * leaf and field as the holder hands them to the painter (`frame.scene.restroom_doors`), the desk's elements and its `held`.
 * The look of the door, the sign and the line is the operator's reading of the preview and of the sandbox, and no clause
 * here asserts it.
 *
 * ⛔ EVERY NUMBER IS RE-DERIVED: the chain of restroom breaks and each break's dwell from § 12's *Restroom anchor*, *Restroom
 * gap* and *Restroom dwell* through this test's own copy of the character tree's seeded draw (held equal to the tree's
 * `draw()` through `walk-probe.mjs`), the threshold from § 12's *Restroom threshold* over the door tile the scene read, the
 * waiting spots through `route.js`'s own `spotsNear()`, a walker's point through `pointAt()`.
 *
 * ⛔ EACH RED IS A PLANT IN THE SHIPPED TREE (`test_red_*`), replayed against the run § 11 names for it and seen to break
 * that run's GREEN — the same defect list the GREEN asserts empty.
 */
class ACharacterTakesARestroomBreakTest extends TestCase
{
    use ReadsTheDrawnScenes;
    use DrivesTheFloorScreen;

    private const IMPL_1 = 'aimla-impl-1';

    private const IMPL_2 = 'aimla-impl-2';

    private const PM = 'aimla-pm';

    private const REVIEW = 'aimla-review';

    private const SCENE = '../floor/scene.js';

    private const IN_FLIGHT = '../floor/in-flight.js';

    private const SCREEN = '../floor/floor-screen.js';

    private const ROUTE = '../floor/route.js';

    private const LINE = 'no open route between its desk and the restroom door — drawn without the trip';

    /** One loop frame, ms (§ 12's loop rate). */
    private const FRAME = 250;

    /** card#12841: one display frame at 60 Hz, ms — the step the lane's sampler reads feet at. */
    private const DISPLAY_MS = 1000 / 60;

    /** @var array<string, array<string, mixed>> the shipped tree's runs, replayed once per process */
    private static array $runs = [];

    // ── what a run drew ──────────────────────────────────────────────────────────────────────

    /** One run of the shipped tree (cached), or of a planted copy, or with page conditions. */
    private function restroomRun(string $run, ?string $dir = null, array $overrides = []): array
    {
        if ($dir !== null || $overrides !== []) {
            return $this->floorRun($run, $dir, $overrides);
        }

        return self::$runs[$run] ??= $this->floorRun($run);
    }

    /** @return list<array<string, mixed>> the renders and refreshes that drew a scene */

    /** @return list<array<string, mixed>> the restroom visits a frame draws (A26 effects with frames), of one walker where named */
    private function visits(array $frame, ?string $walker = null): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => $e['animation_id'] === 'A26'
            && ($e['frames'] ?? 0) > 0 && ($walker === null || $e['walker_key'] === $walker)));
    }

    /** @return list<array<string, mixed>> the effects of one row with frames a frame draws */
    private function effectsOf(array $frame, string $id): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => $e['animation_id'] === $id && ($e['frames'] ?? 0) > 0));
    }

    /** The one restroom door the scene hands the painter, or `null`. */
    private function door(array $frame): ?array
    {
        return $frame['scene']['restroom_doors'][0] ?? null;
    }

    /** @return list<array<string, mixed>> the held rows of one id one seat wrote */
    private function held(array $result, string $key, string $id): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'held'
            && $r['animation_id'] === $id && $r['seat_id'] === $key));
    }

    /** @return list<array<string, mixed>> the edge rows of the given ids one seat wrote */
    private function edges(array $result, string $key, array $ids): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'edge'
            && in_array($r['animation_id'], $ids, true) && $r['seat_id'] === $key));
    }

    private function desk(array $frame, string $key): array
    {
        foreach ($frame['scene']['desks'] as $desk) {
            if ($desk['key'] === $key) {
                return $desk;
            }
        }

        $this->fail("the scene draws no desk {$key}");
    }

    private function drawsCharacter(array $frame, string $key): bool
    {
        return in_array('character', array_column($this->desk($frame, $key)['elements'], 'kind'), true);
    }

    /** The first frame drawing a visit of the walker of `$legKind`, its effect and its instant — or `null`. */
    private function anyVisit(array $result, string $walker, string $legKind = 'restroom'): ?array
    {
        foreach ($this->drawn($result) as $r) {
            foreach ($this->visits($r['frame'], $walker) as $v) {
                if ($v['leg_kind'] === $legKind) {
                    return [$r['frame'], $v, $r['at']];
                }
            }
        }

        return null;
    }

    /** The run's clock 0 as a corrected server instant, ms — its snapshot's `server_time`. */
    private function clockZero(string $run): int
    {
        $time = $this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['server_time'];

        return (int) round((float) (new \DateTimeImmutable($time))->format('U.u') * 1000);
    }

    /** One seat of a run's snapshot. */
    private function snapshotSeat(string $run, string $seatId): array
    {
        foreach ($this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['installs'][0]['seats'] as $seat) {
            if ($seat['seat_id'] === $seatId) {
                return $seat;
            }
        }

        $this->fail("{$run}'s snapshot holds no {$seatId}");
    }

    /** An effect's start on the run's clock, ms. */
    private function startedAt(array $effect, int $renderAt): float
    {
        return $renderAt - $effect['elapsed_ms'];
    }

    /** The legs of a visit by kind: `out`, `wait`, `board`, `door`, `inside`, `home`. */
    private function legsOf(array $visit): array
    {
        $legs = $visit['legs'];
        $find = static fn (string $kind, ?string $why = null) => array_values(array_filter($legs, static fn ($l) => $l['kind'] === $kind && ($why === null || ($l['why'] ?? null) === $why)))[0] ?? null;
        $wait = $find('stand', 'wait');

        return ['out' => $legs[0], 'wait' => $wait, 'board' => $wait === null ? null : $legs[2], 'door' => $find('stand', 'door'),
            'inside' => $find('inside'), 'home' => $legs[count($legs) - 1]];
    }

    /** Where effects stand `$ms` after their start, through `route.js`'s own `pointAt()`. @return list<array> */
    private function pointsAt(array $effect, array $ms): array
    {
        return $this->probe(['mode' => 'points', 'cases' => [['effect' => $effect, 'times' => $ms]]], null, __DIR__.'/walk-probe.mjs')[0];
    }

    private function pointAt(array $effect, float $ms): array
    {
        $p = $this->pointsAt($effect, [$ms])[0];

        return ['x' => $p['x'], 'y' => $p['y']];
    }

    /** The walker's point at the run's instant `$runMs`, from one frame's effect. */
    private function pointIn(array $effect, int $renderAt, float $runMs): array
    {
        return $this->pointAt($effect, $runMs - $this->startedAt($effect, $renderAt));
    }

    /** The character tree's own seeded draw over (key, field). */
    private function draw(string $key, string $field): int
    {
        return $this->probe(['mode' => 'draw', 'cases' => [[$key, $field]]], null, __DIR__.'/walk-probe.mjs')[0];
    }

    /**
     * The restroom as the scene module declares it, with the waiting spots `spotsNear()` finds from `$from`, clear of
     * `$clear` — and, card#12841, none in `$lane` where one is given, as the scene calls it — over the shipped default or
     * the map at the repo path `$map`.
     */
    private function restroom(?array $from = null, array $clear = [], int $count = 3, ?array $frame = null, ?array $lane = null, ?string $map = null): array
    {
        $desks = $frame === null ? [] : ['desks' => array_map(static fn (array $d): array => [
            'key' => $d['key'],
            'rects' => array_values(array_filter([$d['slot_rect'], $d['box']], static fn ($r) => $r !== null)),
        ], $frame['scene']['desks'])];

        return $this->probe(['mode' => 'restroom'] + ($from === null ? [] : ['from' => $from, 'clear' => $clear, 'count' => $count] + ($lane === null ? [] : ['lane' => $lane]))
            + ($map === null ? [] : ['map' => $map]) + $desks, null, __DIR__.'/walk-probe.mjs');
    }

    // ── § 12's rows ──────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function section12(): array
    {
        $md = $this->floorMd();
        $read = function (string $re, string $what) use ($md): array {
            $this->assertSame(1, preg_match($re, $md, $m), "§ 12 carries no {$what} row in the form this test reads");

            return $m;
        };
        $n = static fn (string $v): int => (int) str_replace(',', '', $v);
        $g = $read('/^\| \*\*Restroom gap\*\* \| \*\*([\d,]+) … ([\d,]+) s\*\*/m', '*Restroom gap*');
        $a = $read("/^\\| \\*\\*Restroom anchor\\*\\* \\| one restroom break a day, at `day × ([\\d,]+) \\+ draw\\(key, 'restroom-anchor:' \\+ day\\) mod ([\\d,]+)` s/m", '*Restroom anchor*');
        $d = $read('/^\| \*\*Restroom dwell\*\* \| \*\*(\d+) … (\d+) s\*\* \|/m', '*Restroom dwell*');
        $t = $read('/^\| \*\*Restroom door\*\* \| \*\*(\d+) × (\d+)\*\* px \|/m', '*Restroom door*');
        $p = $read('/^\| \*\*Restroom plates\*\* \| sign \*\*(\d+) × (\d+) px at \((\d+), (\d+)\)\*\*, plate \*\*(\d+) × (\d+) px at \((\d+), (\d+)\)\*\*/m', '*Restroom plates*');
        $l = $read('/^\| \*\*Restroom line\*\* \| the one string \*([^*]+)\* \|/m', '*Restroom line*');
        $e = $read('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', '*Elevator leaves*');
        $m = $read('/^\| \*\*Restroom door motion\*\* \| \*Elevator leaves\*\' \*\*(\d+) \/ (\d+) \/ (\d+)\*\* frames/m', '*Restroom door motion*');
        $c = $read('/^\| \*\*Walker clearance\*\* \| \*\*(\d+) px beside; (\d+) px in depth\*\* \|/m', '*Walker clearance*');
        $s = $read('/^\| \*\*Character rect\*\* \| \*\*([\d.]+) × ([\d.]+)\*\* px \|/m', '*Character rect*');
        $ln = $read('/^\| \*\*Restroom lane\*\* \| \*\*the leaf\'s width, (\d+) px, or the seat\'s drawn width plus both \*Walker clearance\* side margins, whichever is wider, × one drawn body\'s height\*\* — on the shipped frame \*\*([\d.]+) × (\d+) px\*\*/mu', '*Restroom lane*');

        return [
            'gap' => [$n($g[1]), $n($g[2])],
            'day' => $n($a[1]),
            'anchor' => $n($a[2]),
            'dwell' => [(int) $d[1], (int) $d[2]],
            'door' => [(int) $t[1], (int) $t[2]],
            'sign' => ['x' => (int) $p[3], 'y' => (int) $p[4], 'w' => (int) $p[1], 'h' => (int) $p[2]],
            'plate' => ['x' => (int) $p[7], 'y' => (int) $p[8], 'w' => (int) $p[5], 'h' => (int) $p[6]],
            'line' => $l[1],
            'leaves' => ['open' => (int) $e[1], 'step' => (int) $e[2], 'close' => (int) $e[3]],
            'motion' => ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]],
            'margin' => (int) $c[1],
            'depth' => (int) $c[2],
            'seat_w' => (float) $s[1],
            'seat_h' => (float) $s[2],
            'lane' => ['leaf' => (int) $ln[1], 'w' => (float) $ln[2], 'h' => (float) $ln[3]],
        ];
    }

    /**
     * § 12's *Restroom threshold* for a door the scene read, on the lattice the scene drew: under the door's centre, *Walker
     * clearance*'s depth plus 1 px below its foot, snapped; where that cell is not open at `clear` for the seat, the nearest
     * cell on its row that is, its centre within the door's width, ties to the left — `$open` says which cells are.
     */
    private function thresholdOf(array $door, callable $open): ?array
    {
        $f = $this->section12();
        $snap = static fn (float $v): float => floor($v / 8) * 8 + 4;
        $mid = $door['x'] + $door['w'] / 2;
        $y = $snap($door['y'] + $door['h'] + $f['depth'] + 1);
        $under = ['x' => $snap($mid), 'y' => $y];

        if ($open($under)) {
            return $under;
        }

        $row = [];
        for ($x = $door['x'] + 4; $x < $door['x'] + $door['w']; $x += 8) {
            $row[] = ['x' => (float) $x, 'y' => $y];
        }

        usort($row, static fn ($a, $b) => [abs($a['x'] - $mid), $a['x']] <=> [abs($b['x'] - $mid), $b['x']]);

        foreach ($row as $p) {
            if ($open($p)) {
                return $p;
            }
        }

        return null;
    }

    // ── the chain, re-derived ───────────────────────────────────────────────────────────────────────────────────────

    /** The character tree's seeded draw (`resources/characters/seed.js`): FNV-1a over `key#field`, then fmix32. */
    private function seedDraw(string $key, string $field): int
    {
        $mul = static fn (int $a, int $b): int => (($a * ($b & 0xFFFF)) + ((($a * ($b >> 16)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
        $h = 2166136261;

        foreach (unpack('C*', "{$key}#{$field}") as $byte) {
            $h = $mul($h ^ $byte, 16777619);
        }

        $h ^= $h >> 16;
        $h = $mul($h, 0x85EBCA6B);
        $h ^= $h >> 13;
        $h = $mul($h, 0xC2B2AE35);

        return $h ^ ($h >> 16);
    }

    /** @var array<string, list<int>> */
    private static array $chains = [];

    /** § 12's *Restroom anchor* and *Restroom gap*, re-derived: walker `$key`'s restroom breaks of day `$day`. @return list<int> */
    private function chainOf(string $key, int $day): array
    {
        return self::$chains["{$key} {$day}"] ??= (function () use ($key, $day): array {
            $f = $this->section12();
            $anchor = fn (int $d): int => $d * $f['day'] + $this->seedDraw($key, "restroom-anchor:{$d}") % $f['anchor'];
            $next = $anchor($day + 1);
            $starts = [$anchor($day)];

            for ($s = $starts[0]; $next - $s > $f['gap'][1];) {
                $hi = min($f['gap'][1], $next - $s - $f['gap'][0]);
                $s += $f['gap'][0] + $this->seedDraw($key, "restroom-gap:{$s}") % ($hi - $f['gap'][0] + 1);
                $starts[] = $s;
            }

            return $starts;
        })();
    }

    /** § 12's *Restroom dwell* for the break of `$key` beginning at `$start`, or `null` if no restroom break does. */
    private function dwellAt(string $key, int $start): ?int
    {
        $f = $this->section12();
        $day = intdiv($start, $f['day']);

        foreach ([$day - 1, $day] as $d) {
            if (in_array($start, $this->chainOf($key, $d), true)) {
                return $f['dwell'][0] + $this->seedDraw($key, "restroom-dwell:{$start}") % ($f['dwell'][1] - $f['dwell'][0] + 1);
            }
        }

        return null;
    }

    /** The first restroom break of `$key` after second `$after`, re-derived. */
    private function nextBreak(string $key, int $after): int
    {
        $f = $this->section12();

        for ($d = intdiv($after, $f['day']) - 1; ; $d++) {
            foreach ($this->chainOf($key, $d) as $s) {
                if ($s > $after) {
                    return $s;
                }
            }
        }
    }

    // ── GREEN — § 12's figures are the scene's ───────────────────────────────────────────────

    public function test_green_section_12s_figures_are_the_scenes(): void
    {
        $f = $this->section12();
        $r = $this->restroom();

        $this->assertSame(['min' => $f['gap'][0], 'max' => $f['gap'][1]], $r['gap_s'], "the scene's gap is not § 12's *Restroom gap*");
        $this->assertSame($f['day'], $r['day_s'], "the scene's anchor day is not § 12's *Restroom anchor*");
        $this->assertSame($f['gap'][0], $f['anchor'], "§ 12's *Restroom anchor* does not lie under one minimum gap");
        $this->assertSame(['min' => $f['dwell'][0], 'max' => $f['dwell'][1]], $r['dwell_s'], "the scene's dwell is not § 12's *Restroom dwell*");
        $this->assertSame(['kind' => 'restroom-door', 'w' => $f['door'][0], 'h' => $f['door'][1]], $r['door'], "the scene's door is not § 12's *Restroom door*");
        $this->assertSame(['sign' => $f['sign'], 'plate' => $f['plate']], $r['plates'], "the scene's plates are not § 12's *Restroom plates*");
        $this->assertSame($f['line'], $r['line'], "the scene's line is not § 12's *Restroom line*");
        $this->assertSame($f['leaves'], $f['motion'], "§ 12's *Restroom door motion* is not *Elevator leaves*");
        $this->assertSame($f['leaves'], $r['door_motion'], "the door's frames are not § 12's *Elevator leaves*");
        $this->assertEqualsWithDelta($f['seat_w'] + $f['margin'], $r['queue_spacing'], 1e-9, "the waiting spots' spacing is not *Queue spacing*");

        $tsx = (string) file_get_contents(__DIR__.'/../../../../resources/floor/tiles/floor-plane.tsx');
        $this->assertSame(1, preg_match('/value="restroom-door"\/>\s*<\/properties>\s*<image source="[^"]+" width="(\d+)" height="(\d+)"/', $tsx, $m), 'the tileset declares no `restroom-door` tile');
        $this->assertSame($f['door'], [(int) $m[1], (int) $m[2]], 'the `restroom-door` tile is not § 12\'s size');
        $this->assertCount(1, $r['placed'], 'the shipped default does not place one restroom door');
    }

    // ── GREEN — the chain ────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function chainDefects(?string $dir = null): array
    {
        $defects = [];
        $f = $this->section12();
        $keys = [self::IMPL_2, self::PM.'~01K3TA6G7H8J9K0M1N2P3Q4R5T'];
        $day = intdiv(intdiv($this->clockZero('idle-visit'), 1000), $f['day']);
        $chain = $this->probe(['mode' => 'restroom-chain', 'keys' => $keys, 'from_day' => $day - 30, 'days' => 60], $dir, __DIR__.'/walk-probe.mjs');

        foreach ($keys as $key) {
            $starts = array_column($chain[$key]['days'], 'start_s');

            for ($i = 1; $i < count($starts); $i++) {
                $gap = $starts[$i] - $starts[$i - 1];

                if ($gap < $f['gap'][0] || $gap > $f['gap'][1]) {
                    $defects[] = "[{$key}] the gap {$starts[$i - 1]} → {$starts[$i]} is {$gap} s, outside § 12's *Restroom gap*";
                }
            }

            $mine = array_merge(...array_map(fn ($d) => $this->chainOf($key, $d), range($day - 30, $day + 30)));
            $mine = array_values(array_filter($mine, fn ($s) => $s >= ($day - 30) * $f['day'] && $s < ($day + 30) * $f['day']));

            if ($starts !== $mine) {
                $defects[] = "[{$key}] the scene's restroom chain is not § 12's";
            }

            foreach ($chain[$key]['days'] as $b) {
                if ($b['dwell_s'] !== $this->dwellAt($key, $b['start_s'])) {
                    $defects[] = "[{$key}] the break at {$b['start_s']} dwells {$b['dwell_s']} s, not § 12's *Restroom dwell*";

                    break;
                }
            }

            if ($starts === array_column($chain[$key]['snack'], 'start_s')) {
                $defects[] = "[{$key}] the restroom chain is the snack chain";
            }
        }

        $first = $this->anyVisit($this->restroomRun('idle-visit', $dir), self::IMPL_2)[1] ?? null;
        $next = $this->anyVisit($this->restroomRun('next-visit', $dir), self::IMPL_2)[1] ?? null;

        if ($first === null || $next === null || $next['start_s'] !== $this->nextBreak(self::IMPL_2, $first['start_s'])
            || $next['start_s'] - $first['start_s'] < $f['gap'][0] || $next['start_s'] - $first['start_s'] > $f['gap'][1]) {
            $defects[] = 'next-visit does not draw idle-visit\'s walker\'s next break, a *Restroom gap* later';
        }

        return $defects;
    }

    public function test_green_the_chain(): void
    {
        foreach ([[self::IMPL_2, 'restroom-anchor:19900'], [self::PM, 'restroom-gap:1719376189'], ['aimla/ü', 'x']] as [$key, $field]) {
            $this->assertSame($this->draw($key, $field), $this->seedDraw($key, $field), "the test's seeded draw is not the tree's for {$key}#{$field}");
        }

        $this->assertSame([], $this->chainDefects());
    }

    // ── GREEN — the rows ─────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function rowDefects(?string $dir = null): array
    {
        $defects = [];
        $entered = static fn (array $rows): array => array_values(array_filter($rows, static fn ($r) => $r['phase'] === 'entered'));
        $left = static fn (array $rows): array => array_values(array_filter($rows, static fn ($r) => $r['phase'] === 'left'));
        $v2 = $this->snapshotSeat('idle-visit', 'aimla-impl-2')['state_version'];

        $idle = $this->restroomRun('idle-visit', $dir);

        if (array_column($entered($this->held($idle, self::IMPL_2, 'A6')), 'cause') !== [$v2]
            || array_column($entered($this->held($idle, self::IMPL_2, 'A26')), 'cause') !== [$v2]
            || array_column($entered($this->held($idle, self::IMPL_2, 'A25')), 'cause') !== [$v2]) {
            $defects[] = 'idle-visit did not enter A6, A25 and A26 once each for aimla-impl-2 against the snapshot version';
        }

        if ($left(array_merge($this->held($idle, self::IMPL_2, 'A6'), $this->held($idle, self::IMPL_2, 'A26'))) !== []
            || $this->edges($idle, self::IMPL_2, ['A1', 'A2', 'A16']) !== []) {
            $defects[] = 'idle-visit left a row or fired an edge for aimla-impl-2 through the visit';
        }

        $blocked = $this->restroomRun('blocked-visit', $dir);

        if ($this->held($blocked, self::REVIEW, 'A26') !== [] || array_column($entered($this->held($blocked, self::REVIEW, 'A7')), 'animation_id') !== ['A7']) {
            $defects[] = 'blocked-visit did not enter A7 alone for aimla-review';
        }

        $busy = $this->restroomRun('busy-visit', $dir);
        $v1 = $this->snapshotSeat('busy-visit', 'aimla-impl-1')['state_version'];

        if (array_column($entered($this->held($busy, self::IMPL_1, 'A26')), 'cause') !== [$v1]
            || array_column($entered($this->held($busy, self::IMPL_1, 'A3')), 'cause') !== [$v1]
            || $left($this->held($busy, self::IMPL_1, 'A26')) !== []
            || array_column($entered($this->held($busy, self::IMPL_1, 'A4')), 'cause') !== [$v1 + 1]) {
            $defects[] = 'busy-visit did not enter A26 and A3 for aimla-impl-1 on the snapshot, and A4 on the delta, leaving no A26';
        }

        // The intern's half: aimla-pm's seat (working) and its intern (idle) each hold one half — two A26 rows, kept.
        $intern = $this->restroomRun('intern-visit', $dir);
        $vpm = $this->snapshotSeat('intern-visit', 'aimla-pm')['state_version'];
        $a26 = $entered($this->held($intern, self::PM, 'A26'));

        if (count($a26) !== 2 || array_unique(array_column($a26, 'cause')) !== [$vpm] || $left($this->held($intern, self::PM, 'A26')) !== []) {
            $defects[] = 'intern-visit did not enter both A26 halves for aimla-pm on the snapshot and keep them';
        }

        foreach (['recall-inside' => self::IMPL_2, 'recall-waiting' => self::IMPL_1] as $run => $key) {
            $r = $this->restroomRun($run, $dir);
            $version = $this->snapshotSeat($run, $key)['state_version'] + 1;

            if (array_column($left($this->held($r, $key, 'A26')), 'cause') !== [$version]
                || array_column($entered($this->held($r, $key, 'A7')), 'cause') !== [$version]) {
                $defects[] = "[{$run}] A26 was not left and A7 entered at the applying delta's version";
            }
        }

        $leave = $this->restroomRun('leave-inside', $dir);

        if (array_column($left($this->held($leave, self::IMPL_2, 'A26')), 'cause') !== [$v2 + 1]
            || array_column($left($this->held($leave, self::IMPL_2, 'A6')), 'cause') !== [$v2 + 1]
            || count($this->edges($leave, self::IMPL_2, ['A2'])) !== 1) {
            $defects[] = 'leave-inside did not leave A6 and A26 and fire one A2';
        }

        $stop = $this->restroomRun('intern-stop-inside', $dir);

        if (count($left($this->held($stop, self::PM, 'A26'))) !== 1 || count($this->edges($stop, self::PM, ['A10'])) !== 1) {
            $defects[] = 'intern-stop-inside did not leave the interns\' A26 and fire one A10';
        }

        foreach (['feed-dies-inside', 'restroom-feed-down'] as $run) {
            if ($left($this->held($this->restroomRun($run, $dir), self::IMPL_2, 'A26')) !== []) {
                $defects[] = "[{$run}] A26 was left on a dying feed — nothing ended the hold";
            }
        }

        $stilled = $this->restroomRun('stilled-inside', $dir);
        $out = $left($this->held($stilled, self::IMPL_2, 'A26'));
        $again = array_values(array_filter($entered($this->held($stilled, self::IMPL_2, 'A26')), static fn ($r) => $r['motion'] === false));

        if (array_column($out, 'cause') !== ['stilled'] || count($again) !== 1 || $again[0]['at'] !== $out[0]['at']) {
            $defects[] = 'stilled-inside did not leave A26 `stilled` and re-enter it at `motion: false` on that render';
        }

        return $defects;
    }

    public function test_green_the_rows(): void
    {
        $this->assertSame([], $this->rowDefects());
    }

    // ── GREEN — the visit ────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function visitDefects(?string $dir = null): array
    {
        $defects = [];
        $f = $this->section12();
        $result = $this->restroomRun('idle-visit', $dir);
        $first = $this->anyVisit($result, self::IMPL_2);

        if ($first === null) {
            return ['idle-visit draws no visit'];
        }

        [$frame, $visit, $at] = $first;
        $start = $this->nextBreak(self::IMPL_2, intdiv($this->clockZero('idle-visit'), 1000) - 1);
        $dwell = $this->dwellAt(self::IMPL_2, $start);
        $M = $f['motion'];

        if ($visit['start_s'] !== $start || $this->clockZero('idle-visit') + $this->startedAt($visit, $at) !== (float) ($start * 1000)) {
            $defects[] = "the visit does not begin at the chain's next break ({$start})";
        }

        if (array_map(static fn ($l) => $l['kind'].($l['kind'] === 'stand' ? '/'.$l['why'] : ''), $visit['legs']) !== ['walk', 'stand/door', 'inside', 'walk']) {
            return [...$defects, 'idle-visit\'s legs are not walk, stand at the door, inside, walk'];
        }

        $end = 0;
        foreach ($visit['legs'] as $i => $leg) {
            if ($leg['start'] !== $end) {
                $defects[] = "leg {$i} does not start where the one before it ends";
            }
            $end = $leg['start'] + $leg['frames'];
        }

        $L = $this->legsOf($visit);
        $anchor = $frame['scene']['anchors'][self::IMPL_2];
        $door = $this->door($frame);
        $threshold = $this->thresholdOf($door, fn (array $p): bool => $this->probe(['mode' => 'restroom', 'open' => [$p], 'desks' => array_map(static fn (array $x): array => [
            'key' => $x['key'],
            'rects' => array_values(array_filter([$x['slot_rect'], $x['box']], static fn ($q) => $q !== null)),
        ], $frame['scene']['desks'])], null, __DIR__.'/walk-probe.mjs')['open'][0]);

        if ($door['threshold'] === null || $threshold != $door['threshold']) {
            $defects[] = "the scene's threshold ".json_encode($door['threshold']).' is not § 12\'s *Restroom threshold* over its door';
        }

        $length = 0.0;
        $pts = $L['out']['points'];
        for ($i = 1; $i < count($pts); $i++) {
            $length += hypot($pts[$i]['x'] - $pts[$i - 1]['x'], $pts[$i]['y'] - $pts[$i - 1]['y']);
        }

        if ($pts[0] != $anchor || end($pts) != $door['threshold'] || $L['out']['frames'] !== max(1, (int) ceil($length / 24))) {
            $defects[] = 'the walk out does not run from the desk anchor to the threshold in ⌈length ÷ walk speed⌉ frames';
        }

        if ($L['door']['frames'] !== $M['open'] || $L['inside']['frames'] !== $M['close'] + $dwell * 4 + $M['open']
            || $L['home']['points'][0] != $door['threshold'] || end($L['home']['points']) != $anchor) {
            $defects[] = "the door stand, the inside leg ({$dwell} s dwell) or the walk home is not § 6.7's";
        }

        $wantLeaf = [
            ['start' => $L['door']['start'], 'frames' => $M['open'], 'from' => 0, 'to' => 1],
            ['start' => $L['inside']['start'], 'frames' => $M['close'], 'from' => 1, 'to' => 0],
            ['start' => $L['inside']['start'] + $M['close'] + $dwell * 4, 'frames' => $M['open'], 'from' => 0, 'to' => 1],
            ['start' => $L['home']['start'] + $M['step'], 'frames' => $M['close'], 'from' => 1, 'to' => 0],
        ];

        if ($visit['leaf'] != $wantLeaf || $visit['occupied'] != ['start' => $L['inside']['start'] + $M['close'], 'frames' => $dwell * 4]) {
            $defects[] = 'the leaf does not open on the arrival, close over the walker, open at the dwell\'s end and close a step after the walk home begins, or the field is not occupied from the closing\'s end through the dwell';
        }

        // Inside, the walker is in the door's recess, never on the floor (`pointAt()`'s `where`).
        $inside = $this->pointsAt($visit, [($L['inside']['start'] + 1) * self::FRAME, ($L['inside']['start'] + $L['inside']['frames'] - 1) * self::FRAME]);

        if (array_column($inside, 'where') !== ['inside', 'inside']) {
            $defects[] = 'the walker inside is drawn on the floor, over the closed leaf';
        }

        // Every frame: the desk's empty chair and its idle facts, the line on the walker, the door's field as the visit has it.
        $started = $this->startedAt($visit, $at);
        $occ = [$started + $visit['occupied']['start'] * self::FRAME, $started + ($visit['occupied']['start'] + $visit['occupied']['frames']) * self::FRAME];
        $ends = null;
        foreach ($this->drawn($result) as $r) {
            $walking = $this->visits($r['frame'], self::IMPL_2) !== [];
            $desk = $this->desk($r['frame'], self::IMPL_2);
            $model = $r['frame']['desks']['desks'][self::IMPL_2];
            $d = $this->door($r['frame']);

            if ($walking && ($this->drawsCharacter($r['frame'], self::IMPL_2) || $desk['held'] !== null
                || $model['render_state']['value'] !== 'idle' || ! in_array('chip', array_column($desk['elements'], 'kind'), true))) {
                $defects[] = "at {$r['at']} ms the desk draws its sleeper, or not its idle facts, while its walker is away";
            }

            if ($walking && ($this->visits($r['frame'], self::IMPL_2)[0]['thought']['text'] ?? null) !== $f['line']) {
                $defects[] = "at {$r['at']} ms the walker does not carry *{$f['line']}*";
            }

            if (($desk['bubble']['text'] ?? null) === $f['line']) {
                $defects[] = "at {$r['at']} ms the desk carries the restroom line";
            }

            $want = $r['at'] >= $occ[0] && $r['at'] < $occ[1];

            if ($d === null || $d['occupied_now'] !== $want || ($want && $d['level'] != 0)) {
                $defects[] = "at {$r['at']} ms the field reads ".json_encode($d['occupied_now'] ?? null).' where the visit has it '.($want ? 'occupied' : 'vacant');
            }

            if (! $walking && $ends === null && $r['at'] > $at) {
                $ends = $r;
            }
        }

        if ($ends === null || $ends['trigger'] !== 'walk end' || ! $this->drawsCharacter($ends['frame'], self::IMPL_2)
            || ($this->desk($ends['frame'], self::IMPL_2)['held']['animation_id'] ?? null) !== 'A6') {
            $defects[] = 'no paint-only refresh at the visit\'s end seats the sleeper';
        }

        return $defects;
    }

    public function test_green_the_visit(): void
    {
        $this->assertSame([], $this->visitDefects());
    }

    // ── GREEN — the busy visit ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function busyDefects(?string $dir = null): array
    {
        $defects = [];
        $busy = $this->restroomRun('busy-visit', $dir);
        $first = $this->anyVisit($busy, self::IMPL_1);
        $line = $this->section12()['line'];

        if ($first === null) {
            return ['busy-visit draws no visit for its working seat'];
        }

        $kinds = [];
        $seen = false;
        $back = null;

        foreach ($this->drawn($busy) as $r) {
            if ($this->visits($r['frame'], self::IMPL_1) === []) {
                $back ??= $seen ? $r : null;
            }

            foreach ($this->visits($r['frame'], self::IMPL_1) as $v) {
                $seen = true;
                $kinds[] = $v['leg_kind'];

                if (($v['thought']['text'] ?? null) !== $line) {
                    $defects[] = "at {$r['at']} ms busy-visit's walker does not carry the line";
                }

                $desk = $this->desk($r['frame'], self::IMPL_1);
                $model = $r['frame']['desks']['desks'][self::IMPL_1];

                if ($model['render_state']['value'] !== 'working' || $model['monitor']['lit'] === 'off' || $this->drawsCharacter($r['frame'], self::IMPL_1)) {
                    $defects[] = "at {$r['at']} ms busy-visit's desk does not draw its working facts, its chair empty";
                }

                // card#12994: a bubble shows only while its agent is at the desk.
                if ($desk['bubble'] !== null) {
                    $defects[] = "at {$r['at']} ms busy-visit's desk draws its task bubble while its walker is away";
                }
            }
        }

        if ($back === null || ($this->desk($back['frame'], self::IMPL_1)['bubble']['text'] ?? null) === null
            || ($this->desk($back['frame'], self::IMPL_1)['bubble']['trail'] ?? []) === []) {
            $defects[] = 'busy-visit\'s task bubble, with its trail, is not back when its walker sits down again';
        }

        if (array_values(array_unique($kinds)) !== ['restroom']) {
            $defects[] = 'busy-visit\'s working → working delta cut the visit';
        }

        return $defects;
    }

    public function test_green_the_busy_visit(): void
    {
        $this->assertSame([], $this->busyDefects());
    }

    // ── GREEN — who ─────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function whoDefects(?string $dir = null): array
    {
        $defects = [];

        foreach ($this->drawn($this->restroomRun('blocked-visit', $dir)) as $r) {
            if ($this->visits($r['frame']) !== [] || ($this->door($r['frame'])['leaf'] ?? []) !== []) {
                $defects[] = "[blocked-visit] a visitor, or the leaf moving, at {$r['at']} ms";

                break;
            }
        }

        $run = $this->restroomRun('intern-visit', $dir);
        $call = $this->snapshotSeat('intern-visit', 'aimla-pm')['subagents'][0]['call_id'];
        $key = self::PM."~{$call}";
        $first = $this->anyVisit($run, $key);

        if ($first === null) {
            return [...$defects, 'intern-visit draws no visit for the intern'];
        }

        [$frame, $visit] = $first;

        if ($visit['size'] !== ['w' => 35, 'h' => 56] || array_filter($visit['legs'], static fn ($l) => $l['kind'] === 'seat') !== []
            || $visit['legs'][0]['points'][0] != $frame['scene']['slot_feet'][self::PM][$visit['slot']]
            || end($visit['legs'][0]['points']) != $this->door($frame)['threshold']) {
            $defects[] = "the intern walks at another size, carries a laptop, or does not walk from its slot's foot to the threshold";
        }

        foreach ($this->drawn($run) as $r) {
            if ($this->visits($r['frame'], $key) === []) {
                continue;
            }

            $stools = array_values(array_filter($this->desk($r['frame'], self::PM)['elements'], static fn ($e) => $e['kind'] === 'stool'));

            if (in_array($call, array_column($stools, 'call_id'), true) || ! $this->drawsCharacter($r['frame'], self::PM)
                || ($this->visits($r['frame'], $key)[0]['thought'] ?? null) === null) {
                $defects[] = "at {$r['at']} ms the intern's stool is drawn while it is away, the PM is not at its desk, or the intern carries no line";

                break;
            }
        }

        // The discriminating control: aimla-review, `blocked`, never goes, on any run.
        foreach (['idle-visit', 'two-at-the-door', 'busy-visit'] as $control) {
            foreach ($this->drawn($this->restroomRun($control, $dir)) as $r) {
                if ($this->visits($r['frame'], self::REVIEW) !== []) {
                    $defects[] = "[{$control}] the blocked aimla-review takes a visit";

                    break;
                }
            }
        }

        return $defects;
    }

    public function test_green_who(): void
    {
        $this->assertSame([], $this->whoDefects());
    }

    /**
     * card#12993 (§ 6.6 item 4's *When*): a walker on an elevator walk at its break's second takes no trip for that break —
     * seat-arrives-in-its-break's A1 is in flight when its break begins, so no visit is drawn, and the seat is at its desk
     * once its walk has ended. (The intern's half, a break that began before the intern arrived, is AT-D3-31's.)
     *
     * @return list<string>
     */
    private function arrivalDefects(?string $dir = null): array
    {
        $defects = [];
        $walked = false;
        $last = null;

        foreach ($this->drawn($this->restroomRun('seat-arrives-in-its-break', $dir)) as $r) {
            $walked = $walked || $this->effectsOf($r['frame'], 'A1') !== [];
            $last = $r['frame'];

            if ($this->visits($r['frame'], self::IMPL_2) !== []) {
                $defects[] = "[seat-arrives-in-its-break] a visit at {$r['at']} ms for a break that began while aimla-impl-2 walked in";

                break;
            }
        }

        $walked || $defects[] = '[seat-arrives-in-its-break] no A1 was drawn — the run is not the scenario it says';
        $last === null || $this->drawsCharacter($last, self::IMPL_2) || $defects[] = '[seat-arrives-in-its-break] aimla-impl-2 is not at its desk at the end';

        return $defects;
    }

    public function test_green_a_walker_arriving_at_its_break_takes_no_trip(): void
    {
        $this->assertSame([], $this->arrivalDefects());
    }

    // ── GREEN — one occupant, and the order ────────────────────────────────────────────────

    /**
     * Every visit a run drew, walker → `[effect, render instant]` of its first sighting — the door's sequence of
     * occupants by the instant each reaches the threshold, and the instant the door frees behind each.
     *
     * @return array<string, array{0: array, 1: int}>
     */
    private function visitsOf(array $result): array
    {
        $out = [];

        foreach ($this->drawn($result) as $r) {
            foreach ($this->visits($r['frame']) as $v) {
                if ($v['leg_kind'] === 'restroom') {
                    $out[$v['walker_key']] ??= [$v, $r['at']];
                }
            }
        }

        return $out;
    }

    /** A visit's claim on the door on the run's clock, ms: from its threshold stand to the leaf's close behind it. */
    private function claimOf(array $visit, int $at): array
    {
        $s = $this->startedAt($visit, $at);

        return [$s + $visit['claim']['start'] * self::FRAME, $s + $visit['claim']['end'] * self::FRAME];
    }

    /** @return list<string> */
    private function orderDefects(?string $dir = null): array
    {
        $defects = [];
        $f = $this->section12();

        // two-at-the-door: the second at spotsNear()'s spot 1 from the threshold, clear of both snack spot 0s, in after the first.
        $two = $this->restroomRun('two-at-the-door', $dir);
        $v = $this->visitsOf($two);

        if (! isset($v[self::IMPL_2], $v[self::IMPL_1])) {
            return ['two-at-the-door does not draw both visits'];
        }

        [$first, $fa] = $v[self::IMPL_2];
        [$second, $sa] = $v[self::IMPL_1];
        $frame = $this->drawn($two)[0]['frame'];
        $thr = $first['threshold'];
        $snack = $this->snackSpotZeros($frame);
        $spots = $this->restroom($thr, $snack, 2, $frame, $this->laneOf($this->door($frame)))['spots'];

        if ($first['spot']['index'] !== 0 || $second['spot']['index'] !== 1 || $second['spot']['at'] != $spots[1]
            || hypot($spots[1]['x'] - $thr['x'], $spots[1]['y'] - $thr['y']) < $f['seat_w'] + $f['margin']) {
            $defects[] = "two-at-the-door does not stand the first at the threshold and the second at spotsNear()'s spot 1";
        }

        foreach ($snack as $s0) {
            if (hypot($spots[1]['x'] - $s0['x'], $spots[1]['y'] - $s0['y']) < $f['seat_w'] + $f['margin']) {
                $defects[] = 'two-at-the-door\'s spot 1 lies within *Queue spacing* of a snack spot 0';
            }
        }

        if ($this->claimOf($second, $sa)[0] < $this->claimOf($first, $fa)[1]) {
            $defects[] = 'two-at-the-door\'s second walker reaches the door before the first\'s exit has closed the leaf';
        }

        foreach ($this->drawn($two) as $r) {
            $inside = array_filter($this->visits($r['frame']), function (array $x): bool {
                $leg = $this->legsOf($x)['inside'];
                $f = $x['elapsed_ms'] / self::FRAME;

                return $leg !== null && $f >= $leg['start'] && $f < $leg['start'] + $leg['frames'];
            });

            if (count($inside) > 1) {
                $defects[] = "two-at-the-door has two walkers inside at {$r['at']} ms";
            }
        }

        // three-at-the-door: the door serves in the order the breaks began, whatever the spot.
        $three = $this->visitsOf($this->restroomRun('three-at-the-door', $dir));
        uasort($three, fn ($a, $b) => $a[0]['start_s'] <=> $b[0]['start_s']);
        $claims = array_map(fn ($x) => $this->claimOf($x[0], $x[1]), $three);
        $byClaim = $claims;
        uasort($byClaim, static fn ($a, $b) => $a[0] <=> $b[0]);

        if (count($three) !== 4 || array_keys($byClaim) !== array_keys($claims)) {
            $defects[] = 'three-at-the-door does not send its four walkers in in the order their breaks began: '.json_encode(array_map(static fn ($x) => [$x[0]['start_s'], $x[0]['spot']['index']], $three));
        } else {
            $c = array_values($claims);

            for ($i = 1; $i < count($c); $i++) {
                if ($c[$i][0] < $c[$i - 1][1]) {
                    $defects[] = 'three-at-the-door has two occupants at once';
                }
            }

            $idx = array_values(array_map(static fn ($x) => $x[0]['spot']['index'], $three));

            if ($idx[3] >= $idx[2]) {
                $defects[] = 'three-at-the-door\'s premise did not hold: the last to come does not wait nearer the door than the one before it';
            }
        }

        // past-the-last-spot: exactly two of three; the third keeps its character and writes no line.
        $last = $this->restroomRun('restroom-past-the-last-spot', $dir);
        $walkers = [];
        foreach ($this->drawn($last) as $r) {
            $walkers = array_unique([...$walkers, ...array_column($this->visits($r['frame']), 'walker_key')]);
        }

        $stayed = array_values(array_diff([self::IMPL_1, self::IMPL_2, self::REVIEW], $walkers));

        if (count($walkers) !== 2 || count($stayed) !== 1) {
            $defects[] = 'past-the-last-spot did not send exactly two of its three idle seats';
        } else {
            foreach ($this->drawn($last) as $r) {
                if (! $this->drawsCharacter($r['frame'], $stayed[0])) {
                    $defects[] = "the third seat, {$stayed[0]}, is not at its desk at {$r['at']} ms";

                    break;
                }
            }

            if (array_filter($last['final']['event_log'], static fn ($l) => str_contains($l, 'restroom')) !== []) {
                $defects[] = 'past-the-last-spot wrote an event-log line';
            }
        }

        // reload-queue: a page loaded mid-queue gives each walker the spot and the start the continuous viewer gave it.
        $late = $this->visitsOf($this->restroomRun('reload-queue', $dir));
        $sig = static fn (array $vs): array => array_map(static fn ($x) => [$x[0]['start_s'], $x[0]['spot'], $x[0]['legs']], $vs);

        if ($sig($late) != $sig($v) || count($late) !== 2) {
            $defects[] = 'reload-queue seats its walkers otherwise than two-at-the-door\'s continuous viewer';
        }

        return $defects;
    }

    /** Both snack tables' spot 0s as the scene placed them (`snack.spot()` is not on the scene's data; the trips are). */
    private function snackSpotZeros(array $frame): array
    {
        $f = $this->section12();
        $snap = static fn (float $v): float => floor($v / 8) * 8 + 4;
        $out = [];

        foreach ($frame['scene']['snack']['tables'] as $t) {
            $w = $t['kind'] === 'snack-table' ? $f['seat_w'] : 35.0;
            $out[] = ['x' => $snap($t['x'] - $w / 2), 'y' => $snap($t['y'] + $t['h'] + $f['depth'] + 1)];
        }

        return $out;
    }

    public function test_green_one_occupant_and_the_order(): void
    {
        $this->assertSame([], $this->orderDefects());
    }

    // ── GREEN — the committed past ──────────────────────────────────────────────────────────

    /** @return list<string> */
    private function pastDefects(?string $dir = null): array
    {
        $defects = [];

        foreach (['two-at-the-door', 'three-at-the-door', 'restroom-past-the-last-spot'] as $run) {
            $before = null;

            foreach ($this->drawn($this->restroomRun($run, $dir)) as $r) {
                $now = [];
                foreach ($this->visits($r['frame']) as $t) {
                    $now[$t['walker_key']] = $t;
                }

                if ($before !== null) {
                    foreach ($now as $key => $t) {
                        $was = $before['visits'][$key] ?? null;

                        if ($was === null || $was['leg_kind'] !== $t['leg_kind']) {
                            continue;
                        }

                        if ($was['spot'] != $t['spot'] || $was['legs'] != $t['legs']
                            || $this->pointIn($was, $before['at'], $r['at']) != $this->pointIn($t, $r['at'], $r['at'])) {
                            $defects[] = "[{$run}] {$key} moved or was re-timed between the frames at {$before['at']} and {$r['at']} ms";
                        }
                    }
                }

                $before = ['at' => $r['at'], 'visits' => $now];
            }
        }

        return $defects;
    }

    public function test_green_the_committed_past(): void
    {
        $this->assertSame([], $this->pastDefects());
    }

    // ── GREEN — the interrupts ──────────────────────────────────────────────────────────────

    /** The first render at or after `$from` ms whose trigger is a render, in a run. */
    private function applying(array $result, int $from): ?array
    {
        foreach ($this->drawn($result) as $r) {
            if ($r['at'] >= $from && $r['trigger'] === 'render') {
                return $r;
            }
        }

        return null;
    }

    /** The `at_ms` of a run's first message of type `$t`. */
    private function messageAt(string $run, string $t): int
    {
        foreach ($this->fixture($run)['messages'] as $m) {
            if ($m['envelope']['t'] === $t) {
                return $m['at_ms'];
            }
        }

        $this->fail("{$run} carries no {$t}");
    }

    /** @return list<string> */
    private function interruptDefects(?string $dir = null): array
    {
        $defects = [];
        $M = $this->section12()['motion'];

        // recall-waiting: home from the spot, the desk blocked at once.
        $wait = $this->restroomRun('recall-waiting', $dir);
        $at = $this->messageAt('recall-waiting', 'seat.delta');
        $r = $this->applying($wait, $at);
        $home = $r === null ? null : ($this->visits($r['frame'], self::IMPL_1)[0] ?? null);
        $prior = $this->visitsOf($wait)[self::IMPL_1] ?? null;

        if ($home === null || $home['leg_kind'] !== 'restroom-home' || $prior === null
            || $home['legs'][0]['points'][0] != $this->pointIn($prior[0], $prior[1], $at) || end($home['legs'][0]['points']) != $prior[0]['anchor']
            || $r['frame']['desks']['desks'][self::IMPL_1]['render_state']['value'] !== 'blocked' || ($home['thought'] ?? null) !== null) {
            $defects[] = 'recall-waiting\'s walker does not walk home from its spot, with no line, its desk blocked';
        }

        // recall-inside: the field vacant on the applying render, the leaf opening, the walker out once it is open, home.
        $inside = $this->restroomRun('recall-inside', $dir);
        $at = $this->messageAt('recall-inside', 'seat.delta');
        $r = $this->applying($inside, $at);
        $home = $r === null ? null : ($this->visits($r['frame'], self::IMPL_2)[0] ?? null);
        $d = $r === null ? null : $this->door($r['frame']);

        if ($home === null || $d === null || $d['occupied_now'] || $d['occupied'] !== []
            || array_map(static fn ($l) => [$l['kind'], $l['start'], $l['frames']], $home['legs']) !== [['inside', 0, $M['open']], ['walk', $M['open'], $home['legs'][1]['frames'] ?? null]]
            || $d['leaf'] != [['start' => 0, 'frames' => $M['open'], 'from' => 0, 'to' => 1], ['start' => $M['open'] + $M['step'], 'frames' => $M['close'], 'from' => 1, 'to' => 0]]
            || end($home['legs'][1]['points']) != $home['anchor']) {
            $defects[] = 'recall-inside does not turn the field vacant, open the leaf and walk the walker home from the threshold once it is open';
        }

        // recall-closing: the leaf reverses from where it is, the field never turns.
        $closing = $this->restroomRun('recall-closing', $dir);
        $at = $this->messageAt('recall-closing', 'seat.delta');
        $r = $this->applying($closing, $at);
        $d = $r === null ? null : $this->door($r['frame']);

        if ($d === null || $d['level'] <= 0 || $d['level'] >= 1 || ($d['leaf'][0]['from'] ?? null) !== $d['level'] || ($d['leaf'][0]['to'] ?? null) !== 1) {
            $defects[] = 'recall-closing\'s leaf does not reverse from where it had closed to';
        }

        foreach ($this->drawn($closing) as $x) {
            if ($this->door($x['frame'])['occupied_now'] ?? false) {
                $defects[] = "recall-closing's field reads occupied at {$x['at']} ms";

                break;
            }
        }

        // leave-inside / intern-stop-inside: the departure begins at the threshold, inside, while the leaf opens.
        foreach (['leave-inside' => ['A2', self::IMPL_2], 'intern-stop-inside' => ['A10', null]] as $run => [$id, $key]) {
            $res = $this->restroomRun($run, $dir);
            $at = $this->messageAt($run, 'seat.delta');
            $r = $this->applying($res, $at);
            $walk = $r === null ? null : ($this->effectsOf($r['frame'], $id)[0] ?? null);
            $was = array_values($this->visitsOf($res))[0][0] ?? null;

            if ($walk === null || $was === null || $walk['legs'][0]['kind'] !== 'inside' || $walk['legs'][0]['frames'] !== $M['open']
                || $walk['legs'][0]['at'] != $was['threshold'] || $walk['legs'][1]['points'][0] != $was['threshold']
                || $this->visits($r['frame']) !== []) {
                $defects[] = "{$run}'s {$id} does not leave from the threshold once the leaf has opened";
            }
        }

        // map-removes-door and stilled-inside: the visit ends on the render that applies the change, the sleeper at its desk,
        // the door closed and vacant (or gone with the map).
        foreach (['map-removes-door' => static fn (array $f): bool => ($f['rooms'][0]['map']['map_version'] ?? null) === 5,
            'stilled-inside' => static fn (array $f): bool => ($f['failure']['sign_in'] ?? null) !== null] as $run => $from) {
            $reached = false;

            foreach ($this->drawn($this->restroomRun($run, $dir)) as $r) {
                $reached = $reached || $from($r['frame']);
                $d = $this->door($r['frame']);

                if ($reached && ($this->visits($r['frame']) !== [] || ! $this->drawsCharacter($r['frame'], self::IMPL_2)
                    || ($d !== null && ($d['occupied_now'] || $d['leaf'] !== [])))) {
                    $defects[] = "[{$run}] at {$r['at']} ms the visit is still drawn, the sleeper not at its desk, or the door not closed and vacant";

                    break;
                }
            }

            if (! $reached) {
                $defects[] = "[{$run}] no render applied the change the run is about";
            }
        }

        return $defects;
    }

    public function test_green_the_interrupts(): void
    {
        $this->assertSame([], $this->interruptDefects());
    }

    // ── GREEN — two breaks, one walker ──────────────────────────────────────────────────────

    /** @return list<string> */
    private function handOverDefects(?string $dir = null): array
    {
        $defects = [];
        $line = $this->section12()['line'];

        // snack-then-restroom: from the table's spot on the frame the snack dwell ends, the line changing there; one walk home.
        $res = $this->restroomRun('snack-then-restroom', $dir);
        $snack = null;
        foreach ($this->drawn($res) as $r) {
            foreach ($this->effectsOf($r['frame'], 'A25') as $e) {
                $snack ??= [$e, $r['at']];
            }
        }
        $visit = $this->visitsOf($res)[self::IMPL_1] ?? null;

        if ($snack === null || $visit === null) {
            $defects[] = 'snack-then-restroom does not draw both breaks';
        } else {
            [$s, $sa] = $snack;
            [$v, $va] = $visit;
            $dwellEnd = $this->startedAt($s, $sa) + ($s['legs'][1]['start'] + $s['legs'][1]['frames']) * self::FRAME;

            if ($this->startedAt($v, $va) !== $dwellEnd || $v['legs'][0]['points'][0] != $s['spot']['at'] || ($v['thought']['text'] ?? null) !== $line) {
                $defects[] = 'snack-then-restroom\'s visit does not begin at the table\'s spot on the frame the snack dwell ends, carrying the line';
            }

            foreach ($this->drawn($res) as $r) {
                if ($r['at'] >= $dwellEnd && array_filter($this->effectsOf($r['frame'], 'A25'), static fn ($e) => $e['walker_key'] === self::IMPL_1) !== []) {
                    $defects[] = "snack-then-restroom still draws the snack trip — its walk home — at {$r['at']} ms";

                    break;
                }
            }
        }

        // restroom-then-snack: from the threshold on the frame the leaf has opened for the exit, the leaf closing behind it.
        $res = $this->restroomRun('restroom-then-snack', $dir);
        $visit = $this->visitsOf($res)[self::IMPL_2] ?? null;
        $snack = null;
        foreach ($this->drawn($res) as $r) {
            foreach ($this->effectsOf($r['frame'], 'A25') as $e) {
                $snack ??= [$e, $r['at'], $r];
            }
        }

        if ($snack === null || $visit === null) {
            $defects[] = 'restroom-then-snack does not draw both breaks';
        } else {
            [$v, $va] = $visit;
            [$s, $sa, $sr] = $snack;
            $L = $this->legsOf($v);
            $out = $this->startedAt($v, $va) + ($L['inside']['start'] + $L['inside']['frames']) * self::FRAME;
            $d = $this->door($sr['frame']);
            $closes = array_filter($d['leaf'] ?? [], static fn ($m) => $m['from'] === 1 && $m['to'] === 0);

            if ($this->startedAt($s, $sa) !== $out || $s['legs'][0]['points'][0] != $v['threshold'] || end($s['legs'][2]['points']) != $s['anchor'] || $closes === []) {
                $defects[] = 'restroom-then-snack\'s snack trip does not begin at the threshold as the leaf has opened, the leaf closing behind it';
            }
        }

        return $defects;
    }

    public function test_green_two_breaks_one_walker(): void
    {
        $this->assertSame([], $this->handOverDefects());
    }

    // ── GREEN — no door, no route; the clock and the reload; the feed; reduced motion ───────

    /** @return list<string> */
    private function doorDefects(?string $dir = null): array
    {
        $defects = [];
        $none = $this->restroomRun('no-door', $dir);

        if (array_filter($none['final']['event_log'], static fn ($l) => str_contains($l, 'restroom')) !== []) {
            $defects[] = 'no-door wrote a restroom line';
        }

        if ($this->held($none, self::IMPL_2, 'A26') === []) {
            $defects[] = 'no-door did not keep idle-visit\'s A26 row';
        }

        foreach ($this->drawn($none) as $r) {
            if ($this->visits($r['frame']) !== [] || $this->door($r['frame']) !== null) {
                $defects[] = "no-door draws a door or walks to one at {$r['at']} ms";

                break;
            }
        }

        $route = $this->restroomRun('restroom-no-route', $dir);
        $lines = array_values(array_filter($route['final']['event_log'], static fn ($l) => str_contains($l, 'restroom door')));

        if (count($lines) !== 1 || ! str_ends_with($lines[0], self::IMPL_2.': '.self::LINE)) {
            $defects[] = 'restroom-no-route did not write its one line: '.json_encode($lines);
        }

        foreach ($this->drawn($route) as $r) {
            if ($this->visits($r['frame']) !== []) {
                $defects[] = "restroom-no-route walks at {$r['at']} ms";

                break;
            }
        }

        return $defects;
    }

    public function test_green_no_door_no_route(): void
    {
        $this->assertSame([], $this->doorDefects());
    }

    /** @return list<string> */
    private function clockDefects(?string $dir = null): array
    {
        $defects = [];
        $own = $this->anyVisit($this->restroomRun('idle-visit', $dir), self::IMPL_2)[1] ?? null;
        $reload = $this->restroomRun('reload-inside', $dir);
        $resync = $this->messageAt('reload-inside', 'seat.delta');
        $after = $this->applying($reload, $resync);
        $continued = $after === null ? [] : $this->visits($after['frame'], self::IMPL_2);

        if ($own === null || $continued === [] || $continued[0]['legs'] != $own['legs'] || $continued[0]['start_s'] !== $own['start_s']
            || ! ($this->door($after['frame'])['occupied_now'] ?? false)) {
            $defects[] = 'reload-inside\'s resync does not continue the visit at its phase, inside, the field occupied';
        }

        $viewer = $this->restroomRun('reload-inside-viewer', $dir);
        $first = $this->drawn($viewer)[0] ?? null;
        $v = $first === null ? null : ($this->visits($first['frame'], self::IMPL_2)[0] ?? null);

        if ($v === null || $own === null || $v['start_s'] !== $own['start_s'] || $v['legs'] != $own['legs']
            || ($this->pointsAt($v, [$v['elapsed_ms']])[0]['where'] ?? null) !== 'inside'
            || ! $this->door($first['frame'])['occupied_now'] || $this->door($first['frame'])['level'] != 0
            || $this->drawsCharacter($first['frame'], self::IMPL_2)) {
            $defects[] = 'the second viewer\'s first render does not draw the same visit inside, the leaf closed, the field occupied, the chair empty';
        }

        $twice = $dir === null ? $this->replayRepeatedly('idle-visit', 2) : $this->replayRepeatedly('idle-visit', 2, $dir);
        $visitsOf = fn (array $r): array => array_values(array_unique(array_map(static fn ($t) => json_encode([$t['start_s'], $t['legs']]),
            array_merge(...array_map(fn ($x) => $this->visits($x['frame']), $this->drawn($r))))));

        if (count($visitsOf($twice[0])) !== 1 || $visitsOf($twice[0]) !== $visitsOf($twice[1])) {
            $defects[] = 'two replays of idle-visit do not draw one visit';
        }

        return $defects;
    }

    public function test_green_the_clock_and_the_reload(): void
    {
        $this->assertSame([], $this->clockDefects());
    }

    /** @return list<string> */
    private function feedDefects(?string $dir = null): array
    {
        $defects = [];

        foreach ($this->drawn($this->restroomRun('restroom-feed-down', $dir)) as $r) {
            if ($this->visits($r['frame']) !== [] || ($this->door($r['frame'])['leaf'] ?? []) !== []) {
                $defects[] = "restroom-feed-down walks or moves the leaf at {$r['at']} ms on a feed declared down";

                break;
            }
        }

        $dies = $this->restroomRun('feed-dies-inside', $dir);
        $visit = $this->anyVisit($dies, self::IMPL_2);

        if ($visit === null) {
            return [...$defects, 'feed-dies-inside drew no visit'];
        }

        [, $v, $at] = $visit;
        $end = $this->startedAt($v, $at) + $v['frames'] * self::FRAME;
        $starts = array_unique(array_merge(...array_map(fn ($r) => array_column($this->visits($r['frame']), 'start_s'), $this->drawn($dies))));
        $refreshed = array_filter($this->drawn($dies), static fn ($r) => (float) $r['at'] === (float) $end && $r['trigger'] === 'walk end');

        if (count($starts) !== 1 || $refreshed === []) {
            $defects[] = 'feed-dies-inside did not finish its one visit';
        }

        return $defects;
    }

    public function test_green_the_feed(): void
    {
        $this->assertSame([], $this->feedDefects());
    }

    public function test_green_reduced_motion(): void
    {
        foreach (['idle-visit', 'busy-visit', 'intern-visit', 'two-at-the-door', 'recall-inside'] as $run) {
            $r = $this->floorRun($run, null, ['reduce' => true]);
            $rows = array_values(array_filter($r['animation_log'], static fn ($x) => $x['animation_id'] === 'A26'));

            $this->assertNotSame([], $rows, "[{$run}] reduced motion wrote no A26 row");

            foreach ($rows as $row) {
                $this->assertFalse($row['motion'], "[{$run}] an A26 row under reduce has motion");
            }

            foreach ($this->drawn($r) as $x) {
                $this->assertSame([], $this->visits($x['frame']), "[{$run}] a visit under reduce");
                $d = $this->door($x['frame']);
                $this->assertNotNull($d, "[{$run}] the door is not read under reduce");
                $this->assertSame([[], false, 0], [$d['leaf'], $d['occupied_now'], $d['level']], "[{$run}] the door moves or reads occupied under reduce");
                $this->assertContains('restroom-door', array_column($x['frame']['scene']['scenery'], 'kind'), "[{$run}] the door's tile is not drawn under reduce");
            }
        }
    }

    // ── GREEN — a late viewer draws the continuous viewer's timeline (card#12786 review r1) ─────

    /**
     * Every break a run drew — `<row> <walker> <start second> <leg kind>` → its start on the corrected server clock (ms), its
     * end there (its last frame, or the start of the walker's next trip, whichever is first: a hand-over ends a trip early),
     * and its legs. A walk home after a recall is no break and is not in it.
     *
     * @return array<string, array{walker: string, start: float, end: float, legs: array}>
     */
    private function timeline(string $run, ?string $dir = null): array
    {
        $zero = $this->clockZero($run);
        $out = [];

        foreach ($this->drawn($this->restroomRun($run, $dir)) as $r) {
            foreach ($r['frame']['scene']['effects'] as $e) {
                if (! in_array($e['animation_id'], ['A25', 'A26'], true) || ($e['frames'] ?? 0) <= 0 || ! in_array($e['leg_kind'], ['snack', 'restroom'], true)) {
                    continue;
                }

                $start = $zero + $this->startedAt($e, $r['at']);
                $out["{$e['animation_id']} {$e['walker_key']} {$e['start_s']} {$e['leg_kind']}"] ??= ['walker' => $e['walker_key'], 'start' => $start, 'end' => $start + $e['frames'] * self::FRAME, 'legs' => $e['legs']];
            }
        }

        foreach ($out as $k => $t) {
            foreach ($out as $o) {
                if ($o['walker'] === $t['walker'] && $o['start'] > $t['start'] && $o['start'] < $out[$k]['end']) {
                    $out[$k]['end'] = $o['start'];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function lateDefects(?string $dir = null, ?array $only = null): array
    {
        $defects = [];

        foreach ($only ?? ['queue-of-three', 'two-at-the-door', 'snack-then-restroom', 'restroom-then-snack'] as $route) {
            $continuous = $this->timeline($route, $dir);

            foreach ([150, 170, 200, 260, 330] as $off) {
                $run = "{$route}-at-{$off}";
                $loaded = $this->clockZero($run);
                $late = $this->timeline($run, $dir);

                foreach ($continuous as $k => $t) {
                    if ($t['end'] <= $loaded + self::FRAME) {
                        continue;
                    }

                    $mine = $late[$k] ?? null;

                    if ($mine === null) {
                        $defects[] = "[{$run}] does not draw {$k}, in flight when it loaded";
                    } elseif (abs($mine['start'] - $t['start']) > 1 || $mine['legs'] != $t['legs']) {
                        $defects[] = "[{$run}] draws {$k} from ".($mine['start'] - $loaded).' ms, not '.($t['start'] - $loaded).' ms, or with other legs';
                    }
                }

                foreach (array_diff_key($late, $continuous) as $k => $t) {
                    $defects[] = "[{$run}] draws {$k}, which the continuous viewer never drew";
                }
            }
        }

        return $defects;
    }

    public function test_green_a_late_viewer_draws_the_continuous_timeline(): void
    {
        $this->assertSame([], $this->lateDefects());
    }

    // ── AT-D3-35 (card#12841): the restroom queue stands clear of the door, and the one inside steps out first ──────

    /** The map the lane runs draw over: the door's forecourt a corridor down its lane. */
    private const CORRIDOR = 'server/tests/Feature/Floor/fixtures/maps/restroom-corridor.json';

    /** The runs AT-D3-35 holds to the lane: its own, over the corridor, and AT-D3-33's queues over the shipped default. */
    private const LANE_RUNS = ['lane-two-at-the-door', 'lane-straight-in', 'two-at-the-door', 'three-at-the-door', 'queue-of-three'];

    /**
     * § 12's *Restroom lane* for a door the scene read, re-derived from its tile rect and § 12 — never the lane the scene
     * hands the painter: the leaf's width or the seat's drawn width plus both *Walker clearance* side margins, whichever is
     * wider, centred on the door's centre line, from its foot down the seat's drawn height.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function laneOf(array $door): array
    {
        $f = $this->section12();
        $w = (float) max($f['lane']['leaf'], $f['seat_w'] + 2 * $f['margin']);

        return ['x' => $door['x'] + $door['w'] / 2 - $w / 2, 'y' => (float) ($door['y'] + $door['h']), 'w' => $w, 'h' => $f['seat_h']];
    }

    /** Whether feet at `$p` are in `$lane`, its edges included. */
    private function inLane(array $lane, array $p): bool
    {
        return $p['x'] >= $lane['x'] - 0.001 && $p['x'] <= $lane['x'] + $lane['w'] + 0.001 && $p['y'] >= $lane['y'] - 0.001 && $p['y'] <= $lane['y'] + $lane['h'] + 0.001;
    }

    /**
     * Where a walker's FEET are `$ms` after its effect began, and whether they are on the floor — `route.js`'s `pointAt()`
     * restated over the legs: a `walk` glides along its points by arc length over its frames, every other leg stands at its
     * `at`, an `inside` leg in the door's recess and a `cab` leg in the cab, both off the floor; past the last leg, its end.
     *
     * @return array{x: float, y: float, floor: bool}
     */
    private function feetAt(array $effect, float $ms): array
    {
        $I = $effect['frame_interval_ms'];
        $legs = $effect['legs'];
        $leg = $legs[count($legs) - 1];

        foreach ($legs as $l) {
            if ($ms < ($l['start'] + $l['frames']) * $I) {
                $leg = $l;

                break;
            }
        }

        if ($leg['kind'] !== 'walk') {
            $at = $leg['at'] ?? ['x' => NAN, 'y' => NAN];

            return ['x' => (float) $at['x'], 'y' => (float) $at['y'], 'floor' => ! in_array($leg['kind'], ['inside', 'cab'], true)];
        }

        $pts = $leg['points'];
        $seg = [];

        for ($i = 1; $i < count($pts); $i++) {
            $seg[] = hypot($pts[$i]['x'] - $pts[$i - 1]['x'], $pts[$i]['y'] - $pts[$i - 1]['y']);
        }

        $to = array_sum($seg) * min(1.0, max(0.0, ($ms - $leg['start'] * $I) / ($leg['frames'] * $I)));

        foreach ($seg as $i => $len) {
            if ($to <= $len || $i === count($seg) - 1) {
                $u = $len == 0 ? 1.0 : min(1.0, $to / $len);

                return ['x' => $pts[$i]['x'] + ($pts[$i + 1]['x'] - $pts[$i]['x']) * $u, 'y' => $pts[$i]['y'] + ($pts[$i + 1]['y'] - $pts[$i]['y']) * $u, 'floor' => true];
            }

            $to -= $len;
        }

        return ['x' => (float) $pts[0]['x'], 'y' => (float) $pts[0]['y'], 'floor' => true];
    }

    /**
     * card#12841 — THE CLEAR LANE AT THE RESTROOM DOOR (`docs/design/FLOOR.md § 6.7` item 3 rule 2 and item 4, § 12's
     * *Restroom lane*): the defects in how the walkers a frame draws keep the strip in front of the door clear, against
     * `$lane` — the TEST's lane (`laneOf()`) — and the number of instants sampled at which one walker is on its way out of
     * the door and another on its way in: every 60 Hz display frame both walk at once (`DISPLAY_MS`), and both ends — a
     * half loop frame was measured to step over a crossing a quarter of a loop frame long.
     *
     * - **Nobody waits in it:** every visit's `wait` stand is outside the lane.
     * - **The one coming out goes first:** while a walker on a walk AWAY from the threshold (a walk whose first point is
     *   the threshold: the walk home, or the A2 or A10 that took a visitor over at the door) has its feet on the floor in
     *   the lane, no other walker on a walk TOWARD it (a walk whose last point is the threshold: a visitor's walk straight
     *   to the door, or from its spot) has its feet on the floor in the lane.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function restroomLaneDefects(array $frame, int $renderAt, array $lane, array $threshold): array
    {
        $defects = [];
        $effects = array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => ($e['frames'] ?? 0) > 0 && isset($e['legs'], $e['frame_interval_ms'])));
        $same = static fn (array $p): bool => abs($p['x'] - $threshold['x']) < 0.001 && abs($p['y'] - $threshold['y']) < 0.001;
        // Each effect's walks from and to the threshold, as spans on the run's clock (ms).
        $walks = [];

        foreach ($effects as $i => $e) {
            $start = $this->startedAt($e, $renderAt);

            foreach ($e['legs'] as $l) {
                if (($l['why'] ?? null) === 'wait' && $this->inLane($lane, $l['at'])) {
                    $defects[] = "{$this->walkerOf($e)} waits at ".json_encode([$l['at']['x'], $l['at']['y']]).' inside the lane in front of the restroom door';
                }

                if ($l['kind'] === 'walk' && ($same($l['points'][0]) || $same(end($l['points'])))) {
                    $walks[] = ['out' => $same($l['points'][0]), 'e' => $i, 'start' => $start,
                        'span' => [$start + $l['start'] * $e['frame_interval_ms'], $start + ($l['start'] + $l['frames']) * $e['frame_interval_ms']]];
                }
            }
        }

        $pairs = 0;

        foreach ($walks as $o) {
            foreach ($walks as $n) {
                if (! $o['out'] || $n['out'] || $o['e'] === $n['e']) {
                    continue;
                }

                [$a, $b] = [max($o['span'][0], $n['span'][0]), min($o['span'][1], $n['span'][1])];
                $ts = $a > $b ? [] : [$a, $b];

                for ($t = $a + self::DISPLAY_MS; $t < $b; $t += self::DISPLAY_MS) {
                    $ts[] = $t;
                }

                foreach ($ts as $t) {
                    $pairs++;
                    $po = $this->feetAt($effects[$o['e']], $t - $o['start']);
                    $pn = $this->feetAt($effects[$n['e']], $t - $n['start']);

                    if ($po['floor'] && $pn['floor'] && $this->inLane($lane, $po) && $this->inLane($lane, $pn)) {
                        $defects[] = "at {$t} ms {$this->walkerOf($effects[$n['e']])} walks into the lane while {$this->walkerOf($effects[$o['e']])}, coming out, is still in it";
                    }
                }
            }
        }

        return [array_values(array_unique($defects)), $pairs];
    }

    /** The walker an effect draws: a visit's walker key, an elevator walk's seat or intern. */
    private function walkerOf(array $e): string
    {
        return $e['walker_key'] ?? $e['intern_key'] ?? $e['seat_id'];
    }

    /** The lane defects over every frame of `$runs` (the shipped tree, or a planted copy), and the instants sampled. */
    private function laneRunDefects(array $runs, ?string $dir = null): array
    {
        $defects = [];
        $pairs = 0;

        foreach ($runs as $run) {
            foreach ($this->drawn($this->restroomRun($run, $dir)) as $r) {
                $door = $this->door($r['frame']);

                if ($door === null || $door['threshold'] === null) {
                    continue;
                }

                [$d, $n] = $this->restroomLaneDefects($r['frame'], $r['at'], $this->laneOf($door), $door['threshold']);
                $pairs += $n;

                foreach ($d as $x) {
                    $defects[] = "[{$run}] at {$r['at']} ms ({$r['trigger']}): {$x}";
                }
            }
        }

        return [array_values(array_unique($defects)), $pairs];
    }

    /**
     * card#12841, § 12's figure: the lane the scene hands the painter with each door is the lane re-derived from the door's
     * tile and § 12, at least the seat's body and both margins wide, with the threshold inside it — on the shipped default
     * and over the corridor — and on the shipped frame it is the figure § 12's *Restroom lane* row prints.
     */
    public function test_green_the_restroom_lane_is_section_12s(): void
    {
        $f = $this->section12();

        foreach (['idle-visit', 'lane-two-at-the-door'] as $run) {
            $door = $this->door($this->drawn($this->restroomRun($run))[0]['frame']);
            $lane = $this->laneOf($door);

            $this->assertEqualsWithDelta($lane, $door['lane'], 0.001, "[{$run}] the lane the scene hands the painter is not § 12's *Restroom lane*");
            $this->assertGreaterThanOrEqual($f['seat_w'] + 2 * $f['margin'], $lane['w'], "[{$run}] the lane is narrower than a seat's body and both margins");
            $this->assertTrue($this->inLane($lane, $door['threshold']), "[{$run}] the threshold is not inside the lane");
        }

        $this->assertSame([$f['lane']['w'], $f['lane']['h']], [$lane['w'], $lane['h']], '§ 12\'s *Restroom lane* is not the lane the shipped frame derives');
    }

    /**
     * card#12841, THE SPOTS: over the corridor, the spot the scene stands lane-two-at-the-door's second walker at is the
     * probe's spot 1 with the test's lane — `spotsNear()` called as the scene calls it — outside the lane and at least
     * *Queue spacing* from the threshold, where with no lane spot 1 lies inside it (the map's premise: the lane moves the
     * spot); on the shipped default the lane moves none of the first four waiting spots; and in every frame of every queue
     * run, no visitor waits in the lane.
     */
    public function test_green_the_restroom_queue_stands_clear_of_the_lane(): void
    {
        $f = $this->section12();
        $run = $this->restroomRun('lane-two-at-the-door');
        [$second] = $this->visitsOf($run)[self::IMPL_1];
        $frame = $this->drawn($run)[0]['frame'];
        $door = $this->door($frame);
        $lane = $this->laneOf($door);
        $with = $this->restroom($door['threshold'], [], 2, $frame, $lane, self::CORRIDOR)['spots'];
        $without = $this->restroom($door['threshold'], [], 2, $frame, null, self::CORRIDOR)['spots'];

        $this->assertTrue($this->inLane($lane, $without[1]), 'over the corridor spot 1 with no lane is outside the lane — the map\'s premise is broken');
        $this->assertSame(1, $second['spot']['index']);
        $this->assertEqualsWithDelta($with[1], $second['spot']['at'], 0.001, 'lane-two-at-the-door\'s second walker does not wait at spot 1 as the lane places it');
        $this->assertFalse($this->inLane($lane, $with[1]), 'spot 1 is inside the lane');
        $this->assertGreaterThanOrEqual($f['seat_w'] + $f['margin'] - 0.001, hypot($with[1]['x'] - $door['threshold']['x'], $with[1]['y'] - $door['threshold']['y']), 'spot 1 is within *Queue spacing* of the threshold');

        $shipped = $this->drawn($this->restroomRun('two-at-the-door'))[0]['frame'];
        $sd = $this->door($shipped);
        $snack = $this->snackSpotZeros($shipped);
        $this->assertEquals($this->restroom($sd['threshold'], $snack, 5, $shipped)['spots'], $this->restroom($sd['threshold'], $snack, 5, $shipped, $this->laneOf($sd))['spots'],
            'the lane moved a waiting spot on the shipped default');

        $this->assertSame([], array_values(array_filter($this->laneRunDefects(self::LANE_RUNS)[0], static fn (string $d): bool => str_contains($d, 'waits at'))));
    }

    /**
     * card#12841, THE ONE INSIDE STEPS OUT FIRST: lane-two-at-the-door's second walker leaves its spot on the first frame
     * at which the first's feet, walking out down the corridor, are out of the lane — later than the door frees (the
     * premise: there the lane, not the leaf, is the later) — and lane-straight-in's second, whose break came as the first
     * was about to come out, waits at a spot outside the lane rather than walking straight up it, and leaves the spot on
     * that frame too; and in every frame of every queue run, no walker on its way to the door is in the lane while one
     * coming out is.
     */
    public function test_green_the_occupant_clears_the_lane_before_the_next_steps_in(): void
    {
        foreach (['lane-two-at-the-door', 'lane-straight-in'] as $name) {
            $result = $this->restroomRun($name);
            $v = $this->visitsOf($result);
            $this->assertTrue(isset($v[self::IMPL_2], $v[self::IMPL_1]), "[{$name}] does not draw both visits");
            [$first, $fa] = $v[self::IMPL_2];
            [$second, $sa] = $v[self::IMPL_1];
            $lane = $this->laneOf($this->door($this->drawn($result)[0]['frame']));
            $home = $this->legsOf($first)['home'];
            $I = $first['frame_interval_ms'];
            $clear = null;

            for ($fr = $home['start']; $fr <= $first['frames']; $fr++) {
                $p = $this->feetAt($first, $fr * $I);

                if (! $p['floor'] || ! $this->inLane($lane, $p)) {
                    $clear = $this->startedAt($first, $fa) + $fr * $I;

                    break;
                }
            }

            $L = $this->legsOf($second);
            $this->assertNotNull($clear, "[{$name}] the first's feet never leave the lane");
            $this->assertNotNull($L['wait'], "[{$name}] the second walker does not wait at a spot");
            $this->assertEqualsWithDelta($clear, $this->startedAt($second, $sa) + $L['board']['start'] * $I, 0.001, "[{$name}] the second does not leave its spot on the frame the first is clear of the lane");
            $this->assertGreaterThan($this->claimOf($first, $fa)[1], $clear, "[{$name}] the first is clear of the lane by the time the door frees — the run's premise is broken");
        }

        [$defects, $pairs] = $this->laneRunDefects(self::LANE_RUNS);
        $this->assertSame([], $defects);
        $this->assertGreaterThan(0, $pairs, 'no frame drew a walker coming out and one going in together — the runs\' premise is broken');
    }

    /** card#12841, A LATE VIEWER: the lane runs, each loaded late, draw every break still in flight as the continuous viewer drew it. */
    public function test_green_a_late_viewer_keeps_the_lane(): void
    {
        $this->assertSame([], $this->lateDefects(null, ['lane-two-at-the-door', 'lane-straight-in']));
    }

    // ── RED — each a plant in the shipped tree ───────────────────────────────────────────────

    /** A copy of the shipped tree with each `[file, anchor, replacement]` applied, every anchor found exactly once. */
    private function planted(array ...$edits): string
    {
        $dir = $this->mutatedModules(array_shift($edits));

        foreach ($edits as [$file, $anchor, $replacement]) {
            $target = $dir.DIRECTORY_SEPARATOR.$file;
            $original = (string) file_get_contents($target);
            $this->assertSame(1, substr_count($original, $anchor), "the plant's anchor is not in {$file} exactly once");
            file_put_contents($target, str_replace($anchor, $replacement, $original));
        }

        return $dir;
    }

    /** RED — a trip over the arrival: a break that begins while the walker's elevator walk is in flight drawn as a visit anyway. */
    public function test_red_a_trip_over_the_arrival(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, 'if (page(s.start_s) < (this.#elevatorUntil.get(w.key) ?? -Infinity)) {', 'if (false) {']);

        $this->assertNotSame([], $this->arrivalDefects($dir), 'with the elevator walk ignored, no visit was drawn over the arrival');
    }

    /** RED — the bubble over the empty chair (card#12994): the away desk drawn keeping its task bubble. */
    public function test_red_the_bubble_over_the_empty_chair(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, "    return Object.freeze({ ...model, character: false, pose: 'empty-chair', bubble: null, held: null });",
            "    return Object.freeze({ ...model, character: false, pose: 'empty-chair', held: null });"]);

        $this->assertNotSame([], array_filter($this->busyDefects($dir), static fn ($d) => str_contains($d, 'draws its task bubble while its walker is away')),
            'a desk drawing its task bubble over its empty chair and the busy guard saw nothing');
    }

    /** RED — the timer walk: the visit drawn whenever the break comes, whatever the held state. */
    public function test_red_the_timer_walk(): void
    {
        $dir = $this->planted([self::SCENE, '            restroom_eligible: model.restroom_held?.motion === true,', '            restroom_eligible: true,']);

        $this->assertNotSame([], array_filter($this->whoDefects($dir), static fn ($d) => str_starts_with($d, '[blocked-visit]')), 'a blocked seat visiting and the who guard saw nothing');
    }

    /** Second RED — the re-roll: a break's dwell from `Math.random`. */
    public function test_red_the_re_roll(): void
    {
        $dir = $this->planted([self::SCENE, '        dwell_s: RESTROOM_DWELL_S.min + (draw(key, `restroom-dwell:${start}`) % (RESTROOM_DWELL_S.max - RESTROOM_DWELL_S.min + 1)),',
            '        dwell_s: RESTROOM_DWELL_S.min + Math.floor(Math.random() * (RESTROOM_DWELL_S.max - RESTROOM_DWELL_S.min + 1)),']);

        $this->assertNotSame([], $this->clockDefects($dir), 'a re-rolled dwell and the clock guard saw nothing');
    }

    /** Third RED — two occupants: a visitor let in while the door is held. */
    public function test_red_two_occupants(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, "                turn: after('free'),\n                clear: after('clear'),", "                turn: () => 0,\n                clear: () => 0,"]);

        $this->assertContains('two-at-the-door\'s second walker reaches the door before the first\'s exit has closed the leaf', $this->orderDefects($dir));
    }

    /** Fourth RED — the queue jumped: a visit waits only for the visitor at the threshold, not for those waiting before it. */
    public function test_red_the_queue_jumped(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '...this.#doors.filter((e) => e.door === id).map((e) => e[field])', '...this.#doors.filter((e) => e.door === id && e.spot.index === 0).map((e) => e[field])']);

        $this->assertNotSame([], array_filter($this->orderDefects($dir), static fn ($d) => str_starts_with($d, 'three-at-the-door')), 'a queue jumped and the order guard saw nothing');
    }

    /** Fifth RED — the sign on a timer: the field and the leaf left on the visit's own frames when the occupant is recalled. */
    public function test_red_the_sign_on_a_timer(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '            Object.assign(entry, { at: now, leaf: exit.leaf, occupied: null, free: now + exit.free * I });', '            void exit;']);

        $this->assertContains('recall-inside does not turn the field vacant, open the leaf and walk the walker home from the threshold once it is open', $this->interruptDefects($dir));
    }

    /** Sixth RED — the jump out: a recalled occupant walks home with the leaf closed, never coming out of the door. */
    public function test_red_the_jump_out(): void
    {
        $dir = $this->planted([self::SCENE, '    if (atDoor === undefined || inside === undefined || f < atDoor.start || f >= inside.start + inside.frames) {', '    if (true) {']);

        $this->assertContains('recall-inside does not turn the field vacant, open the leaf and walk the walker home from the threshold once it is open', $this->interruptDefects($dir));
    }

    /** Seventh RED — the walker through the door: the `inside` leg drawn on the floor. */
    public function test_red_the_walker_through_the_door(): void
    {
        $dir = $this->planted([self::ROUTE, "leg.kind === 'inside' ? 'inside' : 'floor'", "'floor'"]);
        // `pointsAt()` reads the shipped route; the planted one is read through the probe over the planted tree.
        $result = $this->restroomRun('idle-visit');
        [, $visit] = $this->anyVisit($result, self::IMPL_2);
        $L = $this->legsOf($visit);
        $where = $this->probe(['mode' => 'points', 'cases' => [['effect' => $visit, 'times' => [($L['inside']['start'] + 1) * self::FRAME]]]], $dir, __DIR__.'/walk-probe.mjs')[0][0]['where'];

        $this->assertSame('floor', $where, 'the plant did not move the walker inside onto the floor');
        $this->assertSame('inside', $this->pointsAt($visit, [($L['inside']['start'] + 1) * self::FRAME])[0]['where'], 'the shipped tree draws the walker inside on the floor');
    }

    /** Eighth RED — one point: every waiting visitor at the threshold. */
    public function test_red_one_point(): void
    {
        $dir = $this->planted([self::SCENE, '                    found = { k, s, there };', '                    found = { k, s: spots(d)(0), there: routed(start, thr) };']);

        $this->assertContains("two-at-the-door does not stand the first at the threshold and the second at spotsNear()'s spot 1", $this->orderDefects($dir));
    }

    /** Ninth RED — the third squeezed in past the last spot. */
    public function test_red_the_third_squeezed_in(): void
    {
        $dir = $this->planted([self::SCENE, "                if (s === null) {\n                    return NO_SPOT;\n                }",
            "                if (s === null) {\n                    k = 0;\n                    taken = [];\n\n                    continue;\n                }"]);

        $this->assertContains('past-the-last-spot did not send exactly two of its three idle seats', $this->orderDefects($dir));
    }

    /** Tenth RED — the jump home first: a deferred break started from the anchor, not where the walker stands. */
    public function test_red_the_hand_over_from_the_desk(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '            this.#admit(w, d.chain, d.s, at, Object.freeze({ x: p.x, y: p.y }), now, ctx, lines);',
            '            this.#admit(w, d.chain, d.s, at, null, now, ctx, lines);']);

        $this->assertNotSame([], $this->handOverDefects($dir), 'a hand-over from the desk and the hand-over guard saw nothing');
    }

    /** Eleventh RED — a silent idle walker: the line drawn only on a busy one. */
    public function test_red_a_silent_idle_walker(): void
    {
        $dir = $this->planted([self::SCREEN, "fx.leg_kind === 'restroom' ? plain.restroom.thought(fx) : null",
            "fx.leg_kind === 'restroom' && walkers.get(fx.walker_key)?.busy === true ? plain.restroom.thought(fx) : null"]);

        $this->assertNotSame([], array_filter($this->visitDefects($dir), static fn ($d) => str_contains($d, 'does not carry')), 'an idle walker with no line and the visit guard saw nothing');
    }

    /** Twelfth RED — the door placed by the client: free floor found for a door the map does not place. */
    public function test_red_the_door_placed_by_the_client(): void
    {
        $dir = $this->planted([self::SCENE, '    const restroom = restroomDoor(restroomDoors, snack, walking, input, box);',
            "    const restroom = restroomDoor(restroomDoors.length > 0 ? restroomDoors : [Object.freeze({ id: 'found', room: null, kind: 'restroom-door', x: 1712, y: 512, w: 64, h: 144, index: 0 })], snack, walking, input, box);"]);

        $this->assertNotSame([], $this->doorDefects($dir), 'a door the client placed and the door guard saw nothing');
    }

    /** Thirteenth RED — the episode on A25's key. */
    public function test_red_the_episode_on_a25s_key(): void
    {
        $dir = $this->planted(['animation-set.js', "    Object.freeze({ suffix: RESTROOM, field: 'restroom_held', prior: 'prior_restroom' }),", "    Object.freeze({ suffix: SNACK, field: 'restroom_held', prior: 'prior_restroom' }),"]);

        $this->assertNotSame([], $this->rowDefects($dir), "A26 on A25's key and the row guard saw nothing");
    }

    /** Fourteenth RED — the order of two chains: the breaks due on one pass decided chain by chain, not in start order. */
    public function test_red_chain_by_chain(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '        due.sort((a, b) => a.s.start_s - b.s.start_s || byKey(a.w.key, b.w.key));',
            '        due.sort((a, b) => byKey(a.chain, b.chain) || byKey(a.w.key, b.w.key));']);

        $this->assertContains('reload-queue seats its walkers otherwise than two-at-the-door\'s continuous viewer', $this->orderDefects($dir));
    }

    /**
     * Fifteenth RED — the forgotten visit (card#12786 review r1): a trip already over when a late page decides it is not
     * recorded, so later decisions plan as if it never held the door.
     */
    public function test_red_the_forgotten_visit(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '        // A trip already over by `now` — on a page that loaded after it ended — is recorded all the same: it is drawn by',
            "        if (now >= at + effect.frames * I) {\n            return;\n        }\n\n        // A trip already over by `now` — on a page that loaded after it ended — is recorded all the same: it is drawn by"]);

        $this->assertNotSame([], $this->lateDefects($dir, ['two-at-the-door', 'restroom-then-snack']), 'a late page forgetting an ended visit and the late-viewer guard saw nothing');
    }

    /** card#12841 RED — the lane not passed: the restroom's spots found as before the lane, only the threshold skipped. */
    public function test_red_the_restroom_lane_not_passed(): void
    {
        $dir = $this->planted([self::SCENE, ', [d.threshold, ...clear], (p) => inLane(d.lane, p));', ', [d.threshold, ...clear], (p) => p.x === d.threshold.x && p.y === d.threshold.y);']);

        $this->assertNotSame([], array_filter($this->laneRunDefects(['lane-two-at-the-door'], $dir)[0], static fn ($d) => str_contains($d, 'waits at')),
            'a visitor waited in the lane and the lane guard saw nothing');
    }

    /** card#12841 Second RED — released as the leaf closes: the next visitor leaves its spot whoever is still in the lane. */
    public function test_red_released_as_the_leaf_closes(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, "                clear: after('clear'),", '                clear: () => 0,']);

        $this->assertNotSame([], $this->laneRunDefects(['lane-two-at-the-door'], $dir)[0], 'a visitor walked into the lane with the one coming out and the lane guard saw nothing');
    }

    /** card#12841 Third RED — straight in through the lane: a walk straight to the door wherever the one coming out is. */
    public function test_red_straight_in_through_the_lane(): void
    {
        $dir = $this->planted([self::SCENE, '        if (free <= walkOut.frames && cleared <= entry) {', '        if (free <= walkOut.frames) {']);

        $this->assertNotSame([], $this->laneRunDefects(['lane-straight-in'], $dir)[0], 'a visitor walked straight up the lane into the one coming out and the lane guard saw nothing');
    }

    /** card#12841 Fourth RED — the lane clear from the page: a page that loaded late times the lane from its own load. */
    public function test_red_the_lane_clear_from_the_page(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, 'lane: effect.lane, clear: at + laneFrame(effect, effect.lane, home.start, false) * I });',
            'lane: effect.lane, clear: Math.max(at, now - 30000) + laneFrame(effect, effect.lane, home.start, false) * I });']);

        $this->assertNotSame([], $this->lateDefects($dir, ['lane-two-at-the-door', 'lane-straight-in']), 'a late page timing the lane from its own load and the late-viewer guard saw nothing');
    }
}

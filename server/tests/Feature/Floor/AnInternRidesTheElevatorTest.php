<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-31 — an intern arrives by the elevator, works or rests at its laptop, and leaves by it (`docs/design/FLOOR.md
 * § 11`, card#12450, card#12451): § 6.2's *Interns ride the elevator too* block, items 13–19, and § 8's *Working and idle
 * interns*, over `fx-intern-walk`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A WALK IS PRESENTATION, NEVER STATE. The rows are the animation log's and are asserted there; an intern's walk and its
 * laptop's fade are asserted on the scene each render (and each paint-only refresh) drew — `frame.scene.effects`,
 * `frame.scene.doors`, `frame.scene.slot_feet` and the desk's stool elements — the model the painter draws. The painter's
 * own half (the cab clip, the chibi image, the fade on the node) is the browser's, recorded as frame strips on the card.
 *
 * ⛔ EVERY NUMBER IS RE-DERIVED: a walk's ends are read off the scene that drew it (the slot's foot, the elevator's seam and
 * foot), the door's frames and the laptop fade out of § 12's own rows, a departure's wait re-drawn through the character
 * tree's own `draw()` over the INTERN's key (`walk-probe.mjs`), and the turn point through `route.js`'s `pointAt()`.
 *
 * ⛔ EACH RED IS A PLANT IN THE SHIPPED TREE (`test_red_*`), replayed against the run § 11 names for it and seen to break
 * that run's GREEN.
 */
class AnInternRidesTheElevatorTest extends TestCase
{
    use ChecksTheElevatorQueue;
    use DrivesTheFloorScreen;

    private const IMPL_1 = 'aimla/aimla-impl-1';

    private const IMPL_2 = 'aimla/aimla-impl-2';

    private const PM = 'aimla/aimla-pm';

    private const BASE = 31107;

    private const SCENE = '../floor/scene.js';

    private const IN_FLIGHT = '../floor/in-flight.js';

    private const SCREEN = '../floor/floor-screen.js';

    private const ANIMATION_SET = 'animation-set.js';

    private const DESK_RENDER = '../desk/desk-render.js';

    private const DESK_FLOOR = '../desk/desk-floor.js';

    private const SLOTS = '../desk/intern-slots.js';

    private const LAYOUT = '../floor/desk-layout.js';

    // ── § 12's rows ──────────────────────────────────────────────────────────────────────────

    /** @return array{open: int, step: int, close: int} */
    private function door(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m));

        return ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
    }

    private function laptopFade(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Laptop fade\*\* \| \*\*(\d+)\*\* loop frames \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Laptop fade* row in the form this test reads');

        return (int) $m[1];
    }

    private function walkSpeed(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Walk speed\*\* \| \*\*(\d+) px per frame\*\* \|/m', $this->floorMd(), $m));

        return (int) $m[1];
    }

    private function waitMax(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator wait\*\* \| \*\*0 … (\d+) s\*\* \|/m', $this->floorMd(), $m));

        return (int) $m[1] * 4;
    }

    /** A departure's wait, re-drawn through the character tree's own seeded draw, over the given key. */
    private function seededWait(string $key, int $cause): int
    {
        $draws = $this->probe(['mode' => 'draw', 'cases' => [[$key, "elevator-wait:{$cause}"]]], null, __DIR__.'/walk-probe.mjs');

        return $draws[0] % ($this->waitMax() + 1);
    }

    /** Where an effect stands at `$ms` after its start, through `route.js`'s own `pointAt()`. */
    private function pointAt(array $effect, int $ms): array
    {
        return $this->probe(['mode' => 'points', 'cases' => [['effect' => $effect, 'times' => [$ms]]]], null, __DIR__.'/walk-probe.mjs')[0][0];
    }

    // ── what a run drew ──────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> the edge rows one seat wrote, of the given ids */
    private function rows(array $result, string $key, array $ids): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'edge'
            && in_array($r['animation_id'], $ids, true) && "{$r['install_id']}/{$r['seat_id']}" === $key));
    }

    /** @return list<array<string, mixed>> the held rows of one id for one seat */
    private function heldRows(array $result, string $key, string $id): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'held'
            && $r['animation_id'] === $id && "{$r['install_id']}/{$r['seat_id']}" === $key));
    }

    private function drawn(array $result): array
    {
        return array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['scene'] ?? null) !== null));
    }

    /** @return list<array<string, mixed>> the intern walks (A10 effects with a key) a frame draws, with frames */
    private function internWalks(array $frame, ?string $internKey = null): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => $e['animation_id'] === 'A10'
            && isset($e['intern_key']) && ($e['frames'] ?? 0) > 0 && ($internKey === null || $e['intern_key'] === $internKey)));
    }

    /** @return list<array<string, mixed>> the laptop fades (A24 effects with frames) a frame draws */
    private function fades(array $frame): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => $e['animation_id'] === 'A24'
            && ($e['frames'] ?? 0) > 0));
    }

    /** Each distinct intern walk a run drew, at the frame that first drew it: `[frame, effect]`. */
    private function firstWalks(array $result): array
    {
        $out = [];

        foreach ($this->drawn($result) as $r) {
            foreach ($this->internWalks($r['frame']) as $w) {
                $id = "{$w['intern_key']} {$w['cause']} {$w['leg_kind']}";

                if (! isset($out[$id])) {
                    $out[$id] = [$r['frame'], $w, $r['at']];
                }
            }
        }

        return array_values($out);
    }

    private function firstWalk(array $result, string $legKind): array
    {
        foreach ($this->firstWalks($result) as $hit) {
            if ($hit[1]['leg_kind'] === $legKind) {
                return $hit;
            }
        }

        $this->fail("the run drew no `{$legKind}` intern walk");
    }

    /**
     * The arrival's walker painted at its first frame and again at a repaint further on (`intern-paint-probe.mjs`):
     * the start the painter pinned on the walker's glide and on the laptop over it, per paint.
     */
    private function paintedWalk(?string $dir = null): array
    {
        [$frame, $walk] = $this->firstWalk($this->floorRun('arrive'), 'arrive');
        unset($walk['elapsed_frames'], $walk['elapsed_ms'], $walk['started_at']);
        // The render that starts the walk paints at its own instant (`doors_at` the walk's start, 0 on the page's clock);
        // a later paint carries spans read 100 ms before it, as a render's are read before its scene is built.
        $paints = [
            ['timeline_ms' => 1000, 'frame_ms' => 990, 'clock_ms' => 0, 'elapsed_ms' => 0, 'doors_at' => 0],
            ['timeline_ms' => 4000, 'frame_ms' => 3990, 'clock_ms' => 3000, 'elapsed_ms' => 3000, 'doors_at' => 2900],
        ];

        return $this->probe(['effect' => $walk, 'paints' => $paints, 'doors' => $frame['scene']['doors']],
            dirname($dir ?? $this->moduleDir()), __DIR__.'/intern-paint-probe.mjs');
    }

    /**
     * What is wrong with each paint's leaves: drawn, and pinned to the instant their spans are in frames from — on the
     * render that starts the walk, the walker's own start, so the leaves open on the frame its cab leg does.
     */
    private function leafPinDefects(array $paints): array
    {
        $defects = [];
        $expected = [1000, 4000 - (3000 - 2900)];
        foreach ($paints as $i => $p) {
            $starts = array_map(static fn (array $l): mixed => $l['start'], $p['leaves']);
            if (count($starts) !== 2) {
                $defects[] = "paint {$i} drew ".count($starts).' leaves';
            } elseif ($starts !== [$expected[$i], $expected[$i]]) {
                $defects[] = "paint {$i}: the leaves start at ".json_encode($starts)." and their spans' instant is at {$expected[$i]} on the timeline";
            }
        }
        if (($paints[0]['glide']['start'] ?? null) !== ($paints[0]['leaves'][0]['start'] ?? false)) {
            $defects[] = 'on the render that starts the walk, the leaves and the walker are pinned to different starts';
        }

        return $defects;
    }

    /** What is wrong with each paint's laptop: it must be drawn, and pinned to the start its walker's glide is. */
    private function laptopPinDefects(array $paints): array
    {
        $defects = [];
        foreach ($paints as $i => $p) {
            if ($p['glide'] === null || $p['laptop'] === null) {
                $defects[] = "paint {$i} drew no walker or no laptop";
            } elseif ($p['laptop']['start'] === null || $p['laptop']['start'] !== $p['glide']['start']) {
                $defects[] = "paint {$i}: the laptop starts at ".json_encode($p['laptop']['start']).' and its walker at '.json_encode($p['glide']['start']);
            }
        }

        return $defects;
    }

    /** @return list<array<string, mixed>> the stool elements a frame's desk draws */
    private function stools(array $frame, string $key): array
    {
        foreach ($frame['scene']['desks'] as $desk) {
            if ($desk['key'] === $key) {
                return array_values(array_filter($desk['elements'], static fn (array $e): bool => $e['kind'] === 'stool'));
            }
        }

        $this->fail("the scene draws no desk {$key}");
    }

    private function deskModel(array $frame, string $key): array
    {
        return $frame['desks']['desks'][$key];
    }

    private function lastFrame(array $result): array
    {
        $drawn = $this->drawn($result);

        return $drawn[count($drawn) - 1]['frame'];
    }

    private function threshold(array $frame): array
    {
        $e = $frame['scene']['band']['elevator'];

        return ['x' => $e['seam'], 'y' => $e['y'] + $e['h']];
    }

    private function kinds(array $walk): array
    {
        return array_column($walk['legs'], 'kind');
    }

    /** Every leg starts where the one before it ends. */
    private function assertContiguous(array $walk, string $what): void
    {
        $at = 0;

        foreach ($walk['legs'] as $i => $leg) {
            $this->assertSame($at, $leg['start'], "{$what}: leg {$i} does not start where the one before it ends");
            $at = $leg['start'] + $leg['frames'];
        }
    }

    private function internKey(array $result, string $seat, int $nth = 0): string
    {
        $calls = [];

        foreach ($result['final']['seats'] ?? [] as $k => $s) {
            if ($k === $seat) {
                foreach ($s['subagents'] as $e) {
                    $calls[] = $e['call_id'];
                }
            }
        }

        return "{$seat}~{$calls[$nth]}";
    }

    // ── GREEN — § 12's figure is the scene's ─────────────────────────────────────────────────

    public function test_green_section_12s_laptop_fade_is_the_scenes(): void
    {
        $this->assertSame(1, preg_match('/^export const LAPTOP_FADE = (\d+);$/m',
            (string) file_get_contents($this->moduleDir().'/'.self::SCENE), $m), 'scene.js declares no LAPTOP_FADE');
        $this->assertSame($this->laptopFade(), (int) $m[1], 'scene.js\'s LAPTOP_FADE is not § 12\'s *Laptop fade*');
    }

    // ── GREEN — a repaint keeps the walker's laptop where it is ─────────────────────────────────

    public function test_green_the_walkers_laptop_is_pinned_with_its_walker(): void
    {
        $this->assertSame([], $this->laptopPinDefects($this->paintedWalk()));
    }

    public function test_green_the_leaves_are_pinned_to_the_instant_their_spans_are_read(): void
    {
        $this->assertSame([], $this->leafPinDefects($this->paintedWalk()));
    }

    // ── GREEN — the rows ─────────────────────────────────────────────────────────────────────

    public function test_green_each_run_writes_the_rows_section_6_2_names(): void
    {
        foreach (['arrive', 'arrive-idle'] as $run) {
            $r = $this->floorRun($run);
            $this->assertSame([self::BASE + 1], array_column($this->rows($r, self::IMPL_1, ['A10']), 'cause'), "[{$run}] not one A10 at the delta's version");
            $this->assertSame([], $this->rows($r, self::IMPL_1, ['A24']), "[{$run}] an arrival wrote an A24");
        }

        $flip = $this->floorRun('flip');
        $this->assertSame([self::BASE + 1, self::BASE + 2], array_column($this->rows($flip, self::IMPL_1, ['A24']), 'cause'), 'flip did not write two A24 rows');
        $this->assertSame([], $this->rows($flip, self::IMPL_1, ['A10']), 'a flip wrote an A10');

        foreach (['leave-working', 'leave-idle'] as $run) {
            $this->assertCount(1, $this->rows($this->floorRun($run), self::IMPL_1, ['A10']), "[{$run}] not one A10");
        }

        $leaves = $this->floorRun('seat-leaves');
        $this->assertSame(['A2'], array_column($this->rows($leaves, self::IMPL_1, ['A1', 'A2', 'A10', 'A24']), 'animation_id'),
            'seat-leaves did not write the seat\'s A2 and nothing for its interns');

        foreach (['snapshot-seated', 'old-reporter'] as $run) {
            $r = $this->floorRun($run);
            $this->assertSame([], $this->rows($r, self::IMPL_1, ['A1', 'A2', 'A10', 'A24']), "[{$run}] a snapshot fired an edge row");
        }

        $entered = array_filter($this->heldRows($this->floorRun('snapshot-seated'), self::IMPL_1, 'A23'), static fn ($r) => $r['phase'] === 'entered');
        $this->assertCount(1, $entered, 'snapshot-seated did not enter one intern-type episode for the seat');
    }

    // ── GREEN — the arrival ──────────────────────────────────────────────────────────────────

    public function test_green_an_arrival_is_cab_stand_walk_seat_from_the_threshold_to_its_slot(): void
    {
        $d = $this->door();
        $run = $this->floorRun('arrive');
        [$frame, $walk] = $this->firstWalk($run, 'arrive');

        $this->assertSame(['cab', 'stand', 'walk', 'seat'], $this->kinds($walk));
        $this->assertContiguous($walk, 'arrive');
        $this->assertSame([$d['open'], $d['step']], [$walk['legs'][0]['frames'], $walk['legs'][1]['frames']]);

        $points = $walk['legs'][2]['points'];
        $foot = $frame['scene']['slot_feet'][self::IMPL_1][$walk['slot']];
        $this->assertEquals($this->threshold($frame), $points[0], 'the walk does not start at the threshold');
        $this->assertEquals($foot, end($points), 'the walk does not end at its slot\'s foot read off the scene');
        $length = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $length += hypot($points[$i]['x'] - $points[$i - 1]['x'], $points[$i]['y'] - $points[$i - 1]['y']);
        }
        $this->assertSame(max(1, (int) ceil($length / $this->walkSpeed())), $walk['legs'][2]['frames'], 'the walk is not ⌈length ÷ speed⌉ frames');
        $this->assertSame(['w' => 35, 'h' => 56], $walk['size'], 'the walker is not the intern at its drawn size');

        $seat = $walk['legs'][3];
        $this->assertSame([$this->laptopFade(), 0, 1], [$seat['frames'], $seat['laptop_from'], $seat['laptop_to']],
            'the seat leg is not § 12\'s Laptop fade, rising from 0 to 1');

        // The stool is drawn empty while the arrival is in flight, and seated from the paint-only refresh at its end.
        $call = explode('~', $walk['intern_key'])[1];
        foreach ($this->drawn($run) as $r) {
            $inFlight = $this->internWalks($r['frame'], $walk['intern_key']) !== [];
            $seated = in_array($call, array_column($this->stools($r['frame'], self::IMPL_1), 'call_id'), true);

            if ($r['at'] >= 1000 && $inFlight) {
                $this->assertFalse($seated, "at {$r['at']} ms the stool is drawn while its intern walks");
            }
        }
        $refresh = array_values(array_filter($this->drawn($run), static fn ($r) => $r['trigger'] === 'walk end'));
        $this->assertNotSame([], $refresh, 'no paint-only refresh ended the arrival');
        $this->assertContains($call, array_column($this->stools($refresh[count($refresh) - 1]['frame'], self::IMPL_1), 'call_id'),
            'the intern is not seated at the arrival\'s end');

        [, $idle] = $this->firstWalk($this->floorRun('arrive-idle'), 'arrive');
        $this->assertSame(['cab', 'stand', 'walk'], $this->kinds($idle), 'an idle arrival has a seat leg');
        $this->assertSame(['idle'], array_column($this->stools($this->lastFrame($this->floorRun('arrive-idle')), self::IMPL_1), 'activity'),
            'the idle intern does not sit with no laptop');
    }

    // ── GREEN — the departure ────────────────────────────────────────────────────────────────

    public function test_green_a_departure_fades_walks_waits_its_own_draw_and_takes_the_door(): void
    {
        $d = $this->door();
        [$frame, $walk] = $this->firstWalk($this->floorRun('leave-working'), 'leave');

        $this->assertSame(['seat', 'walk', 'stand', 'stand', 'cab'], $this->kinds($walk));
        $this->assertContiguous($walk, 'leave-working');
        $this->assertSame([$this->laptopFade(), 1, 0], [$walk['legs'][0]['frames'], $walk['legs'][0]['laptop_from'], $walk['legs'][0]['laptop_to']]);
        $this->assertEquals($frame['scene']['slot_feet'][self::IMPL_1][$walk['slot']], $walk['legs'][1]['points'][0], 'the departure does not start at its slot');
        $this->assertSame($this->seededWait($walk['intern_key'], $walk['cause']), $walk['legs'][2]['frames'], 'the wait is not the intern key\'s seeded draw');
        $this->assertSame([$d['open'], $d['step'] + $d['close']], [$walk['legs'][3]['frames'], $walk['legs'][4]['frames']]);

        [, $idle] = $this->firstWalk($this->floorRun('leave-idle'), 'leave');
        $this->assertSame('walk', $idle['legs'][0]['kind'], 'an idle departure fades a laptop it does not have');
    }

    // ── GREEN — the turn-round (item 16) ─────────────────────────────────────────────────────

    public function test_green_a_stop_before_the_stool_turns_the_intern_round_from_where_it_is(): void
    {
        $d = $this->door();

        [, $cab] = $this->firstWalk($r = $this->floorRun('stop-in-cab'), 'turn');
        $this->assertSame([['kind' => 'cab', 'frames' => $d['close']]], array_map(static fn ($l) => ['kind' => $l['kind'], 'frames' => $l['frames']], $cab['legs']),
            'stop-in-cab is not the leaves closing over the intern');
        $this->assertSame([], $this->fades($this->lastFrame($r)));

        [, $door] = $this->firstWalk($this->floorRun('stop-at-door'), 'turn');
        $this->assertSame(['stand', 'cab'], $this->kinds($door));
        $this->assertSame([$d['step'], $d['close']], array_column($door['legs'], 'frames'));

        $mid = $this->floorRun('stop-mid-walk');
        [$arrivalFrame, $arrival, $arrivalAt] = $this->firstWalk($mid, 'arrive');
        [$frame, $turn, $turnAt] = $this->firstWalk($mid, 'turn');
        $this->assertSame(['walk', 'stand', 'stand', 'cab'], $this->kinds($turn));
        $this->assertContiguous($turn, 'stop-mid-walk');
        $here = $this->pointAt($arrival, $turnAt - $arrivalAt);
        $this->assertEqualsWithDelta($here['x'], $turn['legs'][0]['points'][0]['x'], 0.001, 'the turn does not start where the walker is');
        $this->assertEqualsWithDelta($here['y'], $turn['legs'][0]['points'][0]['y'], 0.001);
        $this->assertEquals($this->threshold($frame), end($turn['legs'][0]['points']), 'the turn does not end at the threshold');
        $this->assertSame($this->seededWait($turn['intern_key'], $turn['cause']), $turn['legs'][1]['frames']);
        $this->assertNotContains('seat', $this->kinds($turn), 'the laptop fades in a turn-round');

        foreach ($this->drawn($mid) as $r) {
            if ($r['at'] >= $turnAt) {
                $this->assertLessThanOrEqual(1, count($this->internWalks($r['frame'], $turn['intern_key'])),
                    "at {$r['at']} ms the intern has more than one walk");
            }
        }
    }

    // ── GREEN — the laptop flips ─────────────────────────────────────────────────────────────

    public function test_green_a_flip_fades_from_the_opacity_reached_and_a_mid_walk_flip_sits_with_no_laptop(): void
    {
        $flip = $this->floorRun('flip');
        $fades = [];

        foreach ($this->drawn($flip) as $r) {
            foreach ($this->fades($r['frame']) as $f) {
                $fades["{$f['cause']}"] ??= $f;
            }
        }

        $this->assertCount(2, $fades, 'flip did not draw two fades');
        [$first, $second] = array_values($fades);
        $this->assertSame([1, 0], [$first['from'], $first['to']], 'the first fade is not 1 → 0');
        $this->assertGreaterThan(0, $second['from'], 'the second fade restarts from 0');
        $this->assertLessThan(1, $second['from'], 'the second fade does not start from the opacity the first reached');
        $this->assertSame(1, $second['to']);

        $a23 = $this->heldRows($flip, self::IMPL_1, 'A23');
        $this->assertSame(['entered', 'left', 'entered'], array_column($a23, 'phase'), 'intern-type is not left at the idle and re-entered at the working');

        $mid = $this->floorRun('flip-mid-walk');
        [, $arrival] = $this->firstWalk($mid, 'arrive');
        $seen = [];
        foreach ($this->drawn($mid) as $r) {
            foreach ($this->internWalks($r['frame'], $arrival['intern_key']) as $w) {
                $seen[] = $w['held_activity'] ?? null;
            }
        }
        $this->assertSame('idle', end($seen), 'the arrival\'s seat leg does not read the held idle');
        $this->assertSame(['idle'], array_column($this->stools($this->lastFrame($mid), self::IMPL_1), 'activity'));
    }

    // ── GREEN — several at once, the cap, one render ─────────────────────────────────────────

    /**
     * Two interns leaving on one delta each draw their own wait over their own key, and queue at the elevator as seats do
     * (item 14, card#12705): they wait at two spots at least § 12's *Queue spacing* apart, and when the leaves open for
     * the first, the other boards behind it, one at a time, before they close.
     */
    public function test_green_two_interns_walk_their_own_routes_and_waits_and_the_leaves_open_once(): void
    {
        $r = $this->floorRun('two-interns');
        $leaves = array_values(array_filter($this->firstWalks($r), static fn ($h) => $h[1]['leg_kind'] === 'leave'));
        $this->assertCount(2, $leaves, 'the two departures are not two effects');
        [[, $a], [, $b]] = $leaves;
        $this->assertNotSame($a['intern_key'], $b['intern_key']);
        $this->assertSame($this->seededWait($a['intern_key'], $a['cause']), $a['queue']['wait']);
        $this->assertSame($this->seededWait($b['intern_key'], $b['cause']), $b['queue']['wait']);

        $pairs = 0;
        $most = 0;

        foreach ($this->drawn($r) as $x) {
            [$apart, $seen] = $this->queueSpacingDefects($x['frame'], $this->queueSpacing());
            [$together, $boarded] = $this->boardingDefects($x['frame'], $this->door());
            $this->assertSame([], [...$apart, ...$together], "at {$x['at']} ms ({$x['trigger']})");
            $pairs += $seen;
            $most = max($most, $boarded);
        }

        $this->assertGreaterThan(0, $pairs, 'the two departing interns never waited at once — the run\'s premise is broken');
        $this->assertSame(2, $most, 'the two departing interns did not board one opening of the leaves');

        $arrivals = array_values(array_filter($this->firstWalks($r), static fn ($h) => $h[1]['leg_kind'] === 'arrive'));
        $this->assertCount(2, $arrivals);
        $doors = $arrivals[0][0]['scene']['doors'];
        $this->assertCount(1, $doors, 'the leaves open more than once for two interns revealed together');
    }

    /**
     * card#12705 review round 1: an intern that has left its spot for the cab is never drawn back at it — in
     * queue-gain-after-boarding a delta trades interns after intern 01 has stepped in, and its freed spot goes to a later
     * departure: no walker jumps between renders, no two wait on one point, and the queue boards together.
     */
    public function test_green_an_intern_that_has_boarded_stays_boarded(): void
    {
        $drawn = $this->drawn($this->floorRun('queue-gain-after-boarding'));
        [$jumps, $compared] = $this->continuityDefects($drawn);

        $this->assertSame([], $jumps);
        $this->assertGreaterThan(0, $compared, 'no intern walk was drawn on two renders — the run\'s premise is broken');

        foreach ($drawn as $x) {
            $this->assertSame([], [...$this->queueSpacingDefects($x['frame'], $this->queueSpacing())[0], ...$this->boardingDefects($x['frame'], $this->door())[0]],
                "at {$x['at']} ms ({$x['trigger']})");
        }
    }

    public function test_red_the_queue_re_timed_from_scratch(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '        const left = (h) => h.plan !== undefined && h.at + h.plan.approach * I <= now;', '        const left = () => false;']);
        $drawn = $this->drawn($this->floorRun('queue-gain-after-boarding', $dir));
        $spacing = array_merge(...array_map(fn ($x) => $this->queueSpacingDefects($x['frame'], $this->queueSpacing())[0], $drawn));

        $this->assertNotSame([], [...$this->continuityDefects($drawn)[0], ...$spacing], 'an intern in the cab was re-timed back to its spot and no guard saw it');
    }

    public function test_green_at_the_cap_nothing_walks(): void
    {
        foreach (['capped-gain' => 2, 'capped-loss' => 1] as $run => $step) {
            $r = $this->floorRun($run);
            $capRow = array_values(array_filter($this->rows($r, self::IMPL_1, ['A10']), static fn ($x) => $x['cause'] === self::BASE + $step));
            $this->assertCount(1, $capRow, "[{$run}] the capped delta did not log one A10");

            foreach ($this->firstWalks($r) as [, $w]) {
                $this->assertNotSame(self::BASE + $step, $w['cause'], "[{$run}] the capped delta drew a walker");
            }

            $more = array_values(array_filter($this->lastFrame($r)['scene']['desks'], static fn ($d) => $d['key'] === self::IMPL_1))[0];
            $tag = array_values(array_filter($more['elements'], static fn ($e) => $e['kind'] === 'stool-more'));
            $this->assertSame('+1 more', $tag[0]['text'] ?? null, "[{$run}] the tag does not read subagents_open − 8");
        }

        // The dropped intern's arrival, in flight, is a plain cancel: no walk of it after the capped delta.
        $r = $this->floorRun('capped-gain');
        [, $arrival] = $this->firstWalk($r, 'arrive');
        foreach ($this->drawn($r) as $d) {
            if ($d['at'] >= 2500) {
                $this->assertSame([], $this->internWalks($d['frame'], $arrival['intern_key']), "at {$d['at']} ms the dropped intern still walks");
            }
        }
    }

    public function test_green_gained_and_lost_in_one_render_is_seen_in_the_cab(): void
    {
        $d = $this->door();
        $r = $this->floorRun('gain-and-loss-one-render');
        $this->assertCount(2, $this->rows($r, self::IMPL_1, ['A10']), 'the one render did not log both A10 rows');
        $walks = $this->firstWalks($r);
        $this->assertCount(1, $walks, 'the one render did not draw exactly one effect for the intern');
        $this->assertSame(['cab'], $this->kinds($walks[0][1]));
        $this->assertSame($d['open'] + $d['close'], $walks[0][1]['frames']);

        $f = $this->floorRun('gain-and-loss-one-render-flip');
        $this->assertCount(2, $this->rows($f, self::IMPL_1, ['A24']));
        foreach ($this->drawn($f) as $x) {
            $this->assertSame([], $this->fades($x['frame']), "at {$x['at']} ms the laptop fades for two flips one render applied");
        }
    }

    // ── GREEN — the slots (item 13) ──────────────────────────────────────────────────────────

    public function test_green_each_intern_keeps_its_slot(): void
    {
        $g = $this->floorRun('gain-during-arrival');
        [, $first, $firstAt] = $this->firstWalk($g, 'arrive');
        $second = array_values(array_filter($this->firstWalks($g), static fn ($h) => $h[1]['leg_kind'] === 'arrive' && $h[1]['intern_key'] !== $first['intern_key']));
        $this->assertCount(1, $second, 'the second intern did not walk in by its own walk');
        $this->assertSame(1, $second[0][1]['slot'], 'the second intern is not at slot 1');
        $this->assertNotSame([], $this->internWalks($second[0][0], $first['intern_key']), 'the first walk did not stay in flight on the second\'s render');
        $this->assertSame(0, $this->internWalks($second[0][0], $first['intern_key'])[0]['slot']);

        // middle-leaves: on the render that applies the slot-1 departure, the survivors stay at slots 0 and 2.
        $m = $this->floorRun('middle-leaves');
        [$departed] = $this->firstWalk($m, 'leave');
        $kept = array_column($this->stools($departed, self::IMPL_1), 'index');
        sort($kept);
        $this->assertSame([0, 2], $kept, 'the survivors did not keep slots 0 and 2');
        $this->assertSame(3, $this->deskModel($departed, self::IMPL_1)['side_table']['seats'], 'the side table is not seated for three');
        $final = array_column($this->stools($this->lastFrame($m), self::IMPL_1), 'index');
        sort($final);
        $this->assertSame([0, 1, 2], $final, 'the fourth intern did not take slot 1');

        // reload-holes: the snapshot rebuilds the map in `started_at` ascending order — the older survivor at slot 0.
        $reload = $this->floorRun('reload-holes');
        $stools = $this->deskModel($this->lastFrame($reload), self::IMPL_1)['side_table']['stools'];
        usort($stools, static fn ($a, $b) => strcmp($a['started_at'], $b['started_at']));
        $this->assertSame([0, 1], array_column($stools, 'slot'), 'the snapshot did not rebuild the slots oldest-first at 0 and 1');
        $this->assertSame([], $this->firstWalks($reload), 'the snapshot drew a walker');

        // The intern-type episode is the seat's, beside the desk's own: impl-1's A3 is never left by an intern render.
        $arrive = $this->floorRun('arrive');
        $a3 = $this->heldRows($arrive, self::IMPL_1, 'A3');
        $this->assertSame(['entered'], array_column($a3, 'phase'), 'an intern render left or re-entered the seat\'s own A3');
        $this->assertNotSame([], $this->heldRows($arrive, self::IMPL_1, 'A23'), 'no intern-type episode was entered');
    }

    // ── GREEN — the seat and its interns (item 17), the default and the snapshot (§ 8, item 18) ──

    public function test_green_the_seat_leaving_moves_no_intern_and_a_desk_move_cancels_the_walk(): void
    {
        $r = $this->floorRun('seat-leaves');
        foreach ($this->drawn($r) as $x) {
            $held = array_column($this->stools($x['frame'], self::IMPL_1), 'activity');
            sort($held);
            $this->assertSame(['idle', 'working'], $held, "at {$x['at']} ms the interns are not on their stools as held");
            $this->assertSame([], $this->internWalks($x['frame']), "at {$x['at']} ms an intern walks with its seat");
        }

        // reslot-interns: the render whose scene first draws `aimla-impl-2`'s desk somewhere else — `fx-collision`'s
        // displacement, applied when the discovered seat's fetch lands — cancels the arrival and seats the intern there.
        $re = $this->floorRun('reslot-interns');
        [, $arrival] = $this->firstWalk($re, 'arrive');
        $boxOf = fn (array $frame): array => array_values(array_filter($frame['scene']['desks'], static fn ($d) => $d['key'] === self::IMPL_2))[0]['box'];
        $moved = null;
        $prev = null;
        foreach ($this->drawn($re) as $x) {
            if ($prev !== null && $boxOf($x['frame']) != $prev && $x['trigger'] === 'render') {
                $moved = $x['frame'];
                break;
            }
            $prev = $boxOf($x['frame']);
        }
        $this->assertNotNull($moved, 'no render moved `aimla-impl-2`\'s desk — the run is not the scenario it says');
        $this->assertSame([], $this->internWalks($moved, $arrival['intern_key']), 'the desk moved and the intern still walks');
        $this->assertContains(explode('~', $arrival['intern_key'])[1], array_column($this->stools($moved, self::IMPL_2), 'call_id'),
            'the cancelled intern is not drawn seated at the desk\'s new place');
    }

    public function test_green_an_old_reporter_types_and_a_snapshot_draws_every_intern_seated(): void
    {
        $old = $this->lastFrame($this->floorRun('old-reporter'));
        $this->assertSame(['working', 'working'], array_column($this->stools($old, self::IMPL_1), 'activity'));
        $this->assertContains('update_required', $this->deskModel($old, self::IMPL_1)['badges']);
        $this->assertSame('A23', $this->deskModel($old, self::IMPL_1)['interns_held']['animation_id'] ?? null, 'the old reporter\'s interns do not type');

        $snap = $this->floorRun('snapshot-seated');
        $acts = array_column($this->stools($this->lastFrame($snap), self::IMPL_1), 'activity');
        sort($acts);
        $this->assertSame(['idle', 'working', 'working'], $acts, 'the snapshot does not draw two laptops and one without');
        $this->assertSame([], $this->firstWalks($snap));
        foreach ($this->drawn($snap) as $x) {
            $this->assertSame([], $this->fades($x['frame']));
        }

        // The words beside the labels: the desk model's stools carry them, in the wire's order.
        $words = array_column($this->deskModel($this->lastFrame($snap), self::IMPL_1)['side_table']['stools'], 'activity');
        $this->assertSame(['idle', 'working', 'working'], $words, 'the list does not read idle, working, working, newest first');

        // The control: `aimla-pm`, with no interns, on every run.
        foreach (['arrive', 'two-interns', 'flip'] as $run) {
            $x = $this->floorRun($run);
            $this->assertSame([], $this->rows($x, self::PM, ['A10', 'A24']), "[{$run}] the control wrote an intern row");
            $this->assertSame([], $this->heldRows($x, self::PM, 'A23'), "[{$run}] the control entered intern-type");
        }
    }

    public function test_green_reduced_motion_draws_every_run_without_a_walker_or_a_fade(): void
    {
        foreach (['arrive', 'leave-working', 'flip', 'stop-mid-walk', 'two-interns'] as $run) {
            $r = $this->floorRun($run, null, ['reduce' => true]);
            $edges = array_merge($this->rows($r, self::IMPL_1, ['A10', 'A24']));
            $this->assertNotSame([], $edges, "[{$run}] reduced motion wrote no row");
            foreach ($edges as $e) {
                $this->assertFalse($e['motion'], "[{$run}] a row under reduce has motion");
            }
            foreach ($this->drawn($r) as $x) {
                $this->assertSame([], $this->internWalks($x['frame']), "[{$run}] a walker under reduce");
                $this->assertSame([], $this->fades($x['frame']), "[{$run}] a fade under reduce");
                $this->assertSame([], $x['frame']['scene']['doors'] ?? [], "[{$run}] the leaves move under reduce");
            }
        }
    }

    // ── RED — each a plant in the shipped tree ───────────────────────────────────────────────

    /**
     * A copy of the shipped tree with every `[file, anchor, replacement]` edit applied, each anchor found exactly once —
     * `mutatedModules()` for the first, and the rest on the same copy.
     */
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

    public function test_red_the_stool_fills_at_once(): void
    {
        $dir = $this->planted([self::SCENE, 'if (move === null || !move.exact || !moving) {', 'if (true) {']);
        $this->assertSame([], $this->firstWalks($this->floorRun('arrive', $dir)), 'the plant still drew the arrival');
    }

    public function test_red_the_arrival_finishes_first(): void
    {
        $dir = $this->planted(
            [self::SCENE, "if (flying !== null && flying.effect.leg_kind === 'arrive') {", 'if (false) {'],
            [self::IN_FLIGHT, 'return !touched && !replaced.has(h.key);', 'return !touched;'],
        );
        $r = $this->floorRun('stop-mid-walk', $dir);
        $two = false;
        foreach ($this->drawn($r) as $x) {
            $keys = array_column($this->internWalks($x['frame']), 'intern_key');
            $two = $two || count($keys) !== count(array_unique($keys));
        }
        $this->assertTrue($two, 'the plant did not leave the arrival running beside a departure');
    }

    public function test_red_the_plain_cancel(): void
    {
        $dir = $this->planted([self::SCENE, "            const turned = turnRound(fx, flying, from, door, routed, walk, leg, done, walking, row);", '            const turned = null;']);
        $r = $this->floorRun('stop-mid-walk', $dir);
        $this->assertSame([], array_values(array_filter($this->firstWalks($r), static fn ($h) => $h[1]['leg_kind'] !== 'arrive')),
            'the plant still drew an effect after the cancelling render');
    }

    public function test_red_the_laptop_held_by_the_walk(): void
    {
        $dir = $this->planted([self::SCREEN, '? Object.freeze({ ...fx, held_activity: heldOf(fx) })', '? Object.freeze({ ...fx, held_activity: fx.activity_state })']);
        $mid = $this->floorRun('flip-mid-walk', $dir);
        $seen = [];
        foreach ($this->drawn($mid) as $r) {
            foreach ($this->internWalks($r['frame']) as $w) {
                $seen[] = $w['held_activity'] ?? null;
            }
        }
        $this->assertSame('working', end($seen), 'the plant still read the held state');
    }

    public function test_red_the_fade_restarts(): void
    {
        $dir = $this->planted([self::SCENE, '        const from = interns.laptop_at(key) ?? opacity(call.from);', '        const from = opacity(call.from);']);
        $fades = [];
        foreach ($this->drawn($this->floorRun('flip', $dir)) as $r) {
            foreach ($this->fades($r['frame']) as $f) {
                $fades["{$f['cause']}"] ??= $f;
            }
        }
        $this->assertSame(0, array_values($fades)[1]['from'] ?? null, 'the plant\'s second fade did not start from 0');
    }

    public function test_red_the_unpinned_laptop(): void
    {
        $dir = $this->planted(['../floor/painter.js', '                entry.nodes.push(over);', '']);
        $this->assertNotSame([], $this->laptopPinDefects($this->paintedWalk($dir)), 'the plant\'s unpinned laptop was not caught');
    }

    public function test_red_the_unpinned_leaves(): void
    {
        $dir = $this->planted(['../floor/painter.js', '            pinned.push({ key: null, elapsed_ms: 0, nodes: walkLeaves, began: performance.now() - (clock.now() - scene.doors_at) });', '']);
        $this->assertNotSame([], $this->leafPinDefects($this->paintedWalk($dir)), 'the plant\'s unpinned leaves were not caught');
    }

    public function test_red_a_walk_at_the_cap(): void
    {
        $dir = $this->planted([self::SCENE, 'if (move === null || !move.exact || !moving) {', 'if (move === null || !moving) {']);
        $causes = array_map(static fn ($h) => $h[1]['cause'], $this->firstWalks($this->floorRun('capped-gain', $dir)));
        $this->assertContains(self::BASE + 2, $causes, 'the plant drew no walk at the cap');
    }

    public function test_red_an_intern_out_of_the_queue_and_the_seats_own_wait(): void
    {
        // An intern's departure drawn without its queue part (card#12705): it waits its own draw on the threshold — on
        // the point the other intern waits at.
        $alone = $this->planted([self::SCENE, '        return done(fx, gone.legs, gone.door, r.tier, gone.queue);', '        return done(fx, gone.legs, gone.door, r.tier);']);
        $defects = [];

        foreach ($this->drawn($this->floorRun('two-interns', $alone)) as $x) {
            $defects = [...$defects, ...$this->queueSpacingDefects($x['frame'], $this->queueSpacing())[0]];
        }

        $this->assertNotSame([], $defects, 'two interns waited out of the queue and the spacing guard saw nothing');

        $seatKey = $this->planted([self::SCENE, '            walk(F, r), walking.wait(fx.intern_key, row.cause), sill);', '            walk(F, r), walking.wait(seat, row.cause), sill);']);
        $leaves = array_values(array_filter($this->firstWalks($this->floorRun('two-interns', $seatKey)), static fn ($h) => $h[1]['leg_kind'] === 'leave'));
        $this->assertSame($leaves[0][1]['queue']['wait'], $leaves[1][1]['queue']['wait'], 'two interns of one delta waited apart under the seat key');
    }

    /** § 12's *Queue spacing*: the shipped walker's drawn width (`walk-probe.mjs`'s body) plus *Walker clearance*'s side margin. */
    private function queueSpacing(): float
    {
        static $body = null;

        $this->assertSame(1, preg_match('/^\| \*\*Walker clearance\*\* \| \*\*(\d+) px beside;/m', $this->floorMd(), $m),
            '§ 12 carries no *Walker clearance* row in the form this test reads');
        $body ??= $this->probe(['mode' => 'shipped', 'repeat' => 1, 'walls' => []], null, __DIR__.'/walk-probe.mjs')['body']['w'];

        return (float) $body + (int) $m[1];
    }

    public function test_red_the_null_default_and_the_seat_level_laptop(): void
    {
        $dir = $this->planted([self::DESK_RENDER, '            activity: s.activity_state ?? null,', '            activity: seat.activity_state ?? null,']);
        $acts = array_column($this->stools($this->lastFrame($this->floorRun('snapshot-seated', $dir)), self::IMPL_1), 'activity');
        $this->assertNotContains('idle', $acts, 'the plant still drew the idle intern idle');
    }

    /**
     * Fire A10 on a snapshot's elements: the rows GREEN reads it — a snapshot writes no edge row. (On this harness the
     * snapshot's render composes no floor yet — § 4.4's entry fetches the layout after it — so the walkers the plant
     * would draw have no scene to stand on, and the row is where it shows.)
     */
    public function test_red_the_snapshot_arrives(): void
    {
        $dir = $this->planted([self::ANIMATION_SET, "            if (entry.t !== 'seat.delta' || entry.outcome !== 'applied') {",
            "            if (entry.t !== 'seat.delta' && entry.outcome === 'applied' && (entry.row?.subagents ?? []).length > 0) {\n"
            ."                this.#edge('A10', entry.state_version, entry.install_id, entry.seat_id, at);\n            }\n\n"
            ."            if (entry.t !== 'seat.delta' || entry.outcome !== 'applied') {"]);
        $this->assertNotSame([], $this->rows($this->floorRun('snapshot-seated', $dir), self::IMPL_1, ['A10']), 'the plant fired no A10 on the snapshot');
    }

    public function test_red_the_positional_row(): void
    {
        $dir = $this->planted(
            [self::SLOTS, '    return { slots, gained, lost, exact: within(before) && within(after) };',
                "    const pos = new Map(elements(after).map((s, i) => [s.call_id, i]));\n"
                .'    return { slots: pos, gained: gained.map((g) => ({ ...g, slot: pos.get(g.call_id) })), lost, exact: within(before) && within(after) };'],
        );
        $g = $this->floorRun('gain-during-arrival', $dir);
        [, $first] = $this->firstWalk($g, 'arrive');
        $after = array_values(array_filter($this->drawn($g), static fn ($r) => $r['at'] === 2000 && $r['trigger'] === 'render'));
        $this->assertSame([], $this->internWalks($after[count($after) - 1]['frame'], $first['intern_key']),
            'the positional plant left the first arrival in flight though its slot moved');
    }

    /**
     * The interns' episode on the desk's own key: the desk's held row holds that key first, so the interns' hold never
     * opens an episode of its own — the rows GREEN's *one intern-type episode on the snapshot* is what reds.
     */
    public function test_red_the_episode_on_the_desks_key(): void
    {
        $dir = $this->planted([self::ANIMATION_SET, "Object.freeze({ suffix: INTERNS, field: 'interns_held', prior: 'prior_interns' }),",
            "Object.freeze({ suffix: '', field: 'interns_held', prior: 'prior_interns' }),"]);
        $entered = array_filter($this->heldRows($this->floorRun('snapshot-seated', $dir), self::IMPL_1, 'A23'), static fn ($r) => $r['phase'] === 'entered');
        $this->assertNotCount(1, $entered, 'the plant still entered one intern-type episode for the seat');
    }

    public function test_red_the_cap_drop_turns_round(): void
    {
        $dir = $this->planted(
            [self::SCENE, 'if (move === null || !move.exact || !moving) {', 'if (move === null || !moving) {'],
            [self::SCENE, '    for (const element of move.gained) {', '    for (const element of (move.exact ? move.gained : [])) {'],
        );
        $kinds = array_map(static fn ($h) => $h[1]['leg_kind'], $this->firstWalks($this->floorRun('capped-gain', $dir)));
        $this->assertContains('turn', $kinds, 'the plant did not turn the dropped intern round');
    }

    public function test_red_the_seats_rule_for_two_edges(): void
    {
        $dir = $this->planted([self::SCENE, '                    effects[at] = internBlink(effects[at], fx);',
            '                    effects[at] = Object.freeze({ ...effects[at], frames: 0, legs: Object.freeze([]) });']);
        $this->assertSame([], $this->firstWalks($this->floorRun('gain-and-loss-one-render', $dir)), 'the plant still drew the cab blink');
    }

    public function test_red_a_fade_inside_one_render(): void
    {
        $dir = $this->planted([self::SCENE, '                if (held !== undefined) {', '                if (false) {']);
        $faded = false;
        foreach ($this->drawn($this->floorRun('gain-and-loss-one-render-flip', $dir)) as $x) {
            $faded = $faded || $this->fades($x['frame']) !== [];
        }
        $this->assertTrue($faded, 'the plant did not fade for two flips one render applied');
    }
}

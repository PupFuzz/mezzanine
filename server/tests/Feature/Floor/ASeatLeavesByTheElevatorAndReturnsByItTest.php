<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-23 — a seat leaves by the elevator and returns by it (`docs/design/FLOOR.md § 11`, card#9566):
 * § 6.2's walk note, every numbered item of it a replay can observe, over `fx-elevator`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A WALK IS PRESENTATION, NEVER STATE. The rows are the animation log's and are asserted there; the
 * walk is asserted on the scene each render (and each paint-only refresh) drew — `frame.scene.effects`,
 * `frame.scene.doors` and the desk's elements — because that is the model the painter draws and the
 * last thing in this tree a test can read without a browser.
 *
 * ⛔ EVERY NUMBER IS RE-DERIVED. A walk's segment is read off the scene that drew it (the desk's
 * anchor, the elevator's seam and foot), its frame count is `⌈length ÷ walk speed⌉` with the speed and
 * the door's frames read out of § 12's own rows, and which values are staffed is the shipped
 * `NO_CHARACTER_STATES` — so nothing here is a copy free to agree with a wrong module.
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

    private const ANIMATION_SET = 'animation-set.js';

    private const IN_FLIGHT = '../floor/in-flight.js';

    private const SCENE = '../floor/scene.js';

    // ── § 12's two rows, read out of the document ────────────────────────────────────────────

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
                if ("{$w['install_id']}/{$w['seat_id']}" === $key) {
                    $out[] = ['at' => $r['at'], 'trigger' => $r['trigger'], 'walk' => $w];
                }
            }
        }

        return $out;
    }

    /** The defects in one walk's geometry, against the scene that drew it. */
    private function pathDefects(array $frame, array $walk): array
    {
        $key = "{$walk['install_id']}/{$walk['seat_id']}";
        $anchor = $frame['scene']['anchors'][$key];
        $e = $frame['scene']['band']['elevator'];
        $threshold = ['x' => $e['seam'], 'y' => $e['y'] + $e['h']];
        [$from, $to] = $walk['animation_id'] === 'A2' ? [$anchor, $threshold] : [$threshold, $anchor];
        $steps = max(1, (int) ceil(hypot($to['x'] - $from['x'], $to['y'] - $from['y']) / $this->walkSpeed()));
        $d = $this->door();
        $defects = [];

        if ($walk['from'] != $from || $walk['to'] != $to) {
            $defects[] = "{$walk['animation_id']} {$key} walks ".json_encode([$walk['from'], $walk['to']]).' — not desk anchor to threshold '.json_encode([$from, $to]);
        }

        if ($walk['walk']['frames'] !== $steps) {
            $defects[] = "{$walk['animation_id']} {$key} walks {$walk['walk']['frames']} frames, not ⌈length ÷ speed⌉ = {$steps}";
        }

        if ($walk['door']['frames'] !== $d['open'] + $d['step'] + $d['close']) {
            $defects[] = "{$walk['animation_id']} {$key}'s door takes {$walk['door']['frames']} frames, not § 12's";
        }

        return $defects;
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

    public function test_green_the_walk(): void
    {
        foreach (['leave-stale', 'return-from-stale', 'two-walkers'] as $run) {
            $result = $this->floorRun($run);
            $seen = 0;

            foreach ($this->drawn($result) as $r) {
                foreach ($this->walks($r['frame']) as $w) {
                    if (($w['elapsed_frames'] ?? 0) === 0) {
                        $this->assertSame([], $this->pathDefects($r['frame'], $w), "[{$run}]");
                        $seen++;
                    }
                }
            }

            $this->assertGreaterThan(0, $seen, "[{$run}] drew no walk to measure");
        }
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

        // Item 6's three clauses that need no entry for the seat: each run touches S by one of them alone,
        // after a render that drew the walk still in flight (the same instant's first, where it has two).
        foreach (['stilled' => [1500, 1500], 'unconfirmed' => [1750, 1750], 'elevator-moves' => [1000, 1500]] as $run => [$was, $at]) {
            $result = $this->floorRun($run);

            $this->assertNotSame([], $this->walks($this->frameAt($result, $was, 'render', false)), "[{$run}] no walk was in flight at {$was} ms");
            $this->assertSame([], $this->walks($this->frameAt($result, $at)), "[{$run}] the render at {$at} ms still draws the walker");
        }

        $moved = $this->floorRun('elevator-moves');
        [$before, $after] = [$this->frameAt($moved, 1000)['scene'], $this->frameAt($moved, 1500)['scene']];
        $this->assertSame($before['anchors'][self::IMPL_1], $after['anchors'][self::IMPL_1], 'elevator-moves moved the desk\'s anchor too');
        $this->assertNotSame($before['band']['elevator']['y'], $after['band']['elevator']['y'], 'elevator-moves did not move the elevator');
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
        foreach (['repaint' => ['A2', 1000, 1500], 'repaint-a19' => ['A19', 800, 1100]] as $run => [$id, $wrote, $later]) {
            $result = $this->floorRun($run);
            $pick = static fn (array $frame) => array_values(array_filter($frame['scene']['effects'], static fn ($e) => $e['animation_id'] === $id && $e['frames'] > 0));
            $first = $pick($this->frameAt($result, $wrote));
            $next = $pick($this->frameAt($result, $later));

            $this->assertNotSame([], $first, "[{$run}] no {$id} was drawn");
            $this->assertNotSame([], $next, "[{$run}] the {$id} in flight did not survive the render at {$later} ms");
            $this->assertSame((int) (($later - $wrote) / 250), $next[0]['elapsed_frames'], "[{$run}] the {$id} in flight is not at its elapsed frame");
            $this->assertEquals([$first[0]['from'], $first[0]['to']], [$next[0]['from'], $next[0]['to']], "[{$run}] the {$id} in flight was re-aimed");
        }

        $two = $this->floorRun('two-walkers');
        $both = $this->frameAt($two, 1000);
        $this->assertCount(2, $this->walks($both), 'two-walkers does not draw both walks');
        $this->assertCount(1, $both['scene']['doors'], 'the leaves open more than once over two overlapping door spans');

        $d = $this->door();
        $span = $both['scene']['doors'][0];
        $ends = array_map(static fn ($w) => $w['door']['start'] + $w['door']['frames'] - $w['elapsed_frames'], $this->walks($both));
        $starts = array_map(static fn ($w) => $w['door']['start'] - $w['elapsed_frames'], $this->walks($both));
        $this->assertSame([min($starts), max($ends)], [$span['from'], $span['to']], 'the leaves are not open across the union of the door frames');

        foreach ($this->walks($both) as $w) {
            $this->assertSame([], $this->pathDefects($both, $w), 'a queued walk is longer than its own segment');
            $this->assertSame($w['walk']['frames'] + $d['open'] + $d['step'] + $d['close'], $w['frames']);
        }
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

    /** § 12's two rows are the code's constants (`floor/scene.js`), held equal both ways. */
    public function test_green_section_12_is_the_scenes_constants(): void
    {
        $source = (string) file_get_contents($this->moduleDir().'/../floor/scene.js');

        $this->assertSame(1, preg_match('/export const WALK_PX_PER_FRAME = (\d+);/', $source, $w));
        $this->assertSame($this->walkSpeed(), (int) $w[1], '§ 12\'s *Walk speed* is not scene.js\'s WALK_PX_PER_FRAME');
        $this->assertSame(1, preg_match('/export const ELEVATOR_DOOR = Object\.freeze\(\{ open: (\d+), step: (\d+), close: (\d+) \}\);/', $source, $d));
        $this->assertSame($this->door(), ['open' => (int) $d[1], 'step' => (int) $d[2], 'close' => (int) $d[3]],
            '§ 12\'s *Elevator leaves* row is not scene.js\'s ELEVATOR_DOOR');
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

    public function test_red_a_non_animating_render_that_does_not_cancel(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "entry.outcome === 'applied' && typeof entry.seat_id === 'string'",
            "entry.outcome === 'applied' && entry.t === 'seat.delta' && typeof entry.seat_id === 'string'"]);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('resync-cancels', $dir), 1500)));
    }

    public function test_red_cancel_keyed_on_the_journal_alone(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, '|| !samePoint(ctx.anchors.get(h.key) ?? null, h.anchor)', '']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('reslot', $dir), 1600)));
    }

    public function test_red_cancel_keyed_on_seat_entries_and_own_rows(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, 'const touched = touchedKeys.has(h.key)
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

    public function test_red_cancel_without_the_threshold_clause(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "\n                || !samePoint(ctx.threshold, h.threshold);", ';']);

        $this->assertNotSame([], $this->walks($this->frameAt($this->floorRun('elevator-moves', $dir), 1500)));
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

        $this->assertSame([], $this->walks($this->frameAt($this->floorRun('repaint', $dir), 1500)));
    }

    public function test_red_walkers_queued_at_the_door(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, "            this.#held.push({\n                effect,\n                at: now,",
            "            this.#held.push({\n                effect: Object.freeze({ ...effect, frames: effect.frames + 5 * this.#held.filter((x) => x.key !== null).length }),\n                at: now,"]);
        $both = $this->frameAt($this->floorRun('two-walkers', $dir), 1000);
        $door = array_sum($this->door());

        $this->assertNotSame([], array_filter($this->walks($both), static fn ($w) => $w['frames'] !== $w['walk']['frames'] + $door),
            'the plant queued no walk behind another');
    }

    public function test_red_the_walk_drawn_under_reduce(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'if (!motion || desk === null || door === null) {', 'if (desk === null || door === null) {']);
        $result = $this->floorRun('leave-stale', $dir, ['reduce' => true]);

        $this->assertNotSame([], array_merge(...array_map(fn ($r) => $this->walks($r['frame']), $this->drawn($result))));
    }

    /** The first walk-end paint of a run. */
    private function walkEnd(array $result): int
    {
        foreach ($result['floor_renders'] as $r) {
            if ($r['trigger'] === 'walk end') {
                return $r['at'];
            }
        }

        $this->fail('the run has no walk-end refresh');
    }
}

<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-36 — a main agent that starts working again on its way out turns back to its desk (`docs/design/FLOOR.md § 11`,
 * card#12466): § 6.2's walk note item 6a, over `fx-turn-back`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A WALK IS PRESENTATION, NEVER STATE. The rows are the animation log's and are asserted there; the turn-round is asserted
 * on the scene each render and each paint-only refresh drew — `frame.scene.effects` (each walk's legs, its `door` and the
 * `door_rest` a turn carries) and the desk's elements — the model the painter draws.
 *
 * ⛔ THE COMMITTED PAST IS READ BETWEEN RENDERS, NEVER FROM THE HOLDER: on every pair of consecutive drawn frames of every
 * run, each seat's walker placed by the earlier frame's legs at the later frame's instant must stand where the later
 * frame's own legs place it (`walkerDefects()`), and the elevator's leaves, read off every walk's spans at their exact
 * elapsed instants, must be as open by the earlier frame as by the later (`leafDefects()`). A turn that starts anywhere but
 * where its walker stands, a walker that vanishes from the floor, and leaves that jump are each a defect there.
 *
 * ⛔ EVERY NUMBER IS RE-DERIVED: a walk's ends are read off the scene that drew it (the desk's anchor, the elevator's seam and
 * foot), its frames from its points and § 12's *Walk speed*, the turn point through `route.js`'s `pointAt()`
 * (`walk-probe.mjs`), and the leaves' frames from § 12's *Elevator leaves*.
 *
 * ⛔ EACH RED IS A PLANT IN THE SHIPPED TREE (`test_red_*`), replayed against the run § 11 names for it and seen to break that
 * run's GREEN.
 */
class AMainAgentTurnsBackToItsDeskTest extends TestCase
{
    use ChecksTheCommittedPast;
    use ChecksTheElevatorQueue;
    use DrivesTheFloorScreen;

    private const IMPL_1 = 'aimla-impl-1';

    private const IMPL_2 = 'aimla-impl-2';

    private const SCENE = '../floor/scene.js';

    private const IN_FLIGHT = '../floor/in-flight.js';

    /** Every run of the fixture, the fixture's own list — so a run added there is read here. */
    private function runs(): array
    {
        return array_keys($this->fixtureFile('fx-turn-back')['runs']);
    }

    // ── § 12's rows ──────────────────────────────────────────────────────────────────────────

    /** @return array{open: int, step: int, close: int} */
    private function door(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Elevator leaves* row in the form this test reads');

        return ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
    }

    private function walkSpeed(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Walk speed\*\* \| \*\*(\d+) px per frame\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Walk speed* row in the form this test reads');

        return (int) $m[1];
    }

    // ── reading a run ────────────────────────────────────────────────────────────────────────

    /** The drawn frames that carry a scene, each `{at, trigger, frame}`, in order. */
    private function drawn(array $result): array
    {
        return array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['scene'] ?? null) !== null));
    }

    private function keyOf(array $e): string
    {
        return $e['seat_id'];
    }

    /** @return list<array<string, mixed>> the seat walks (A1/A2 effects with frames) a frame draws */
    private function walks(array $frame, ?string $key = null): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], fn (array $e): bool => in_array($e['animation_id'], ['A1', 'A2'], true)
            && ($e['frames'] ?? 0) > 0 && ($key === null || $this->keyOf($e) === $key)));
    }

    /** @return list<string> `ID key` for every A1/A2 row the run logged */
    private function walkRows(array $result, string $key): array
    {
        return array_values(array_map(static fn (array $r): string => $r['animation_id'], array_filter($result['animation_log'],
            fn (array $r): bool => $r['class'] === 'edge' && in_array($r['animation_id'], ['A1', 'A2'], true) && $r['seat_id'] === $key)));
    }

    /** The seat a run turns: the seat of its last A1 or A2 row. */
    private function turned(array $result): string
    {
        $rows = array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'edge' && in_array($r['animation_id'], ['A1', 'A2'], true)));

        $this->assertNotSame([], $rows, 'the run logs no walk row');
        $last = $rows[count($rows) - 1];

        return $last['seat_id'];
    }

    /** The first render that draws `$key`'s turn-round: `[at, frame, turn, walk turned from, its frame's at]`. */
    private function firstTurn(array $result, string $key): array
    {
        $before = null;

        foreach ($this->drawn($result) as $r) {
            foreach ($this->walks($r['frame'], $key) as $w) {
                if (($w['leg_kind'] ?? null) === 'turn' && $r['trigger'] === 'render') {
                    return [$r['at'], $r['frame'], $w, $before[1] ?? null, $before[0] ?? null];
                }
            }

            $prior = $this->walks($r['frame'], $key);
            $before = $prior === [] ? $before : [$r['at'], $prior[0]];
        }

        $this->fail("the run draws no turn-round for {$key}");
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

    /** @return list<string> */
    private function kinds(array $desk): array
    {
        return array_column($desk['elements'], 'kind');
    }

    /**
     * A desk's drawn facts — every element's kind, pose, light and text, each age in a text read as a placeholder (an age is
     * the clock's, and two pages loaded at two instants read two: `nothing done for 11s`, `1m 30s`).
     */
    private function facts(array $desk): array
    {
        $ageless = static fn (?string $t): ?string => $t === null ? null : preg_replace('/\b\d+(?:h|m|s)\b(?: \d+(?:m|s)\b)*/', '<age>', $t);

        return array_map(static fn (array $e): array => [$e['kind'], $e['pose'] ?? null, $e['lit'] ?? null, $ageless($e['text'] ?? null)], $desk['elements']);
    }

    /** The `render_state` the desk's chip draws. */
    private function chipOf(array $desk): ?string
    {
        foreach ($desk['elements'] as $e) {
            if ($e['kind'] === 'chip') {
                return $e['render_state'];
            }
        }

        return null;
    }

    private function threshold(array $frame): array
    {
        $e = $frame['scene']['band']['elevator'];

        return ['x' => $e['seam'], 'y' => $e['y'] + $e['h']];
    }

    private function pointAt(array $effect, float $ms): array
    {
        return $this->probe(['mode' => 'points', 'cases' => [['effect' => $effect, 'times' => [$ms]]]], null, __DIR__.'/walk-probe.mjs')[0][0];
    }

    /** The last frame's instant at which `$key` draws no walk any more — its walk's end. */
    private function settled(array $result, string $key): array
    {
        $drawn = $this->drawn($result);
        $last = end($drawn);

        $this->assertSame([], $this->walks($last['frame'], $key), "the run ends with {$key} still walking — its horizon is too short to see it sit");

        return $last['frame'];
    }

    // ── GREEN — the rows ─────────────────────────────────────────────────────────────────────

    public function test_green_the_rows(): void
    {
        foreach ($this->runs() as $run) {
            $result = $this->floorRun($run);
            $want = match ($run) {
                'turn-twice', 'turn-again-in-reopen', 'turn-again-in-ride', 'turn-again-at-step', 'turn-again-stepping-out', 'turn-again-mid-floor',
                'turn-again-from-restroom', 'turn-out-at-ride-end' => ['A2', 'A1', 'A2', 'A1'],
                'turn-round-again-in-cab', 'turn-round-again-at-door', 'turn-round-again-queued' => ['A1', 'A2', 'A1', 'A2'],
                'turn-with-interns', 'turn-round-in-cab', 'turn-round-at-door', 'turn-round-mid-floor' => ['A1', 'A2'],
                'turn-mid-floor-late' => [],
                default => ['A2', 'A1'],
            };
            $key = $want === [] ? self::IMPL_1 : $this->turned($result);

            $this->assertSame($want, $this->walkRows($result, $key), "[{$run}] the walk rows are not the deltas' edges");
        }
    }

    // ── GREEN — the turn, leg by leg ─────────────────────────────────────────────────────────

    public function test_green_a_return_turns_the_walker_back_to_its_desk_from_where_it_is(): void
    {
        $d = $this->door();
        $speed = $this->walkSpeed();
        $cases = [
            'turn-mid-floor' => [['walk'], 'floor'],
            'turn-pressing' => [['walk'], 'floor'],
            'turn-at-threshold' => [['walk'], 'floor'],
            'turn-doors-opening' => [['walk'], 'floor'],
            'turn-queued' => [['walk'], 'floor'],
            'turn-in-open-cab' => [['cab', 'walk'], 'cab'],
            'turn-in-cab-at-a-spot' => [['cab', 'walk'], 'cab'],
            'turn-in-closing-cab' => [['cab', 'stand', 'walk'], 'cab'],
            'turn-riding-together' => [['cab', 'stand', 'walk'], 'cab'],
            'turn-from-restroom' => [['inside', 'walk'], 'inside'],
            'turn-from-snack' => [['walk'], 'floor'],
        ];

        foreach ($cases as $run => [$legs, $in]) {
            $result = $this->floorRun($run);
            $key = $this->turned($result);
            [$at, $frame, $turn, $from, $fromAt] = $this->firstTurn($result, $key);

            $this->assertSame('A1', $turn['animation_id'], "[{$run}] the turn is not the return's A1");
            $this->assertSame($legs, array_column($turn['legs'], 'kind'), "[{$run}] the turn's legs");
            $this->assertSame($in, $turn['turned_in'], "[{$run}] the turn names another place than the one the figure was drawn in");

            // It walks to its desk: the last leg ends at the scene's anchor, its frames its points' length at walk speed.
            $walk = end($turn['legs']);
            $length = 0.0;

            for ($i = 1; $i < count($walk['points']); $i++) {
                $length += hypot($walk['points'][$i]['x'] - $walk['points'][$i - 1]['x'], $walk['points'][$i]['y'] - $walk['points'][$i - 1]['y']);
            }

            $this->assertEquals($frame['scene']['anchors'][$key], end($walk['points']), "[{$run}] the walk back does not end at the desk");
            $this->assertSame(max(1, (int) ceil($length / $speed - 1e-9)), $walk['frames'], "[{$run}] the walk back's frames are not its path's");

            // From where it is: the turn's first point is where the walk it turned from had its walker at that instant.
            $here = $this->pointAt($from, $at - $fromAt + $from['elapsed_ms']);
            $first = $turn['legs'][0]['kind'] === 'walk' ? $turn['legs'][0]['points'][0] : $turn['legs'][0]['at'];

            if ($in !== 'cab') {
                $this->assertEqualsWithDelta($here['x'], $first['x'], 0.001, "[{$run}] the turn does not start where the walker is");
                $this->assertEqualsWithDelta($here['y'], $first['y'], 0.001, "[{$run}] the turn does not start where the walker is");
            }

            // The desk reads the new state at once, its chair empty under the walk back; the person sits at its end.
            $desk = $this->deskIn($frame, $key);
            $this->assertNotContains('character', $this->kinds($desk), "[{$run}] the desk draws the person while it walks back");
            $this->assertSame($run === 'turn-from-snack' || $run === 'turn-from-restroom' ? 'idle' : 'working', $this->chipOf($desk),
                "[{$run}] the desk does not read the new state on the return's render");
            $this->assertContains('character', $this->kinds($this->deskIn($this->settled($result, $key), $key)), "[{$run}] the walk back does not seat the person");
        }

        // Once the leaves are closing over it the ride completes, they stay shut a frame, and open again on it.
        foreach (['turn-in-closing-cab', 'turn-riding-together'] as $run) {
            [, , $turn] = $this->firstTurn($this->floorRun($run), self::IMPL_1);
            $rest = $turn['door_rest'];
            $this->assertIsArray($rest, "[{$run}] the turn carries no closing of the leaves it rode in");
            $this->assertGreaterThanOrEqual($rest['start'] + $rest['frames'] + 1, $turn['door']['start'], "[{$run}] the leaves do not stay shut a frame before they open again");
            $this->assertSame($d['open'] + $d['step'] + $d['close'], $turn['door']['frames'], "[{$run}] the leaves' opening on the return is not A1's");
            $this->assertSame($turn['door']['start'] + $d['open'], $turn['legs'][1]['start'], "[{$run}] it steps out before the leaves are open");
        }
    }

    public function test_green_a_leave_on_the_way_in_turns_the_walker_round_to_the_elevator(): void
    {
        $d = $this->door();

        foreach (['turn-round-in-cab' => ['cab'], 'turn-round-at-door' => ['stand', 'cab'], 'turn-round-mid-floor' => null] as $run => $legs) {
            $result = $this->floorRun($run);
            [$at, $frame, $turn, $from, $fromAt] = $this->firstTurn($result, self::IMPL_1);

            $this->assertSame('A2', $turn['animation_id'], "[{$run}] the turn is not the leaving's A2");
            $this->assertSame('offline', $this->chipOf($this->deskIn($frame, self::IMPL_1)), "[{$run}] the desk does not read the new state on the leaving's render");
            $this->assertNotContains('character', $this->kinds($this->deskIn($frame, self::IMPL_1)), "[{$run}] the desk draws the person");

            if ($legs !== null) {
                $this->assertSame($legs, array_column($turn['legs'], 'kind'), "[{$run}] the turn's legs");
                // The leaves keep the motion drawn: the span starts where the arrival's opening began, and closes once open.
                $this->assertLessThanOrEqual(0, $turn['door']['start'], "[{$run}] the leaves restart rather than finish the opening drawn");
                $this->assertEqualsWithDelta(end($turn['legs'])['start'] + end($turn['legs'])['frames'], $turn['door']['start'] + $turn['door']['frames'], 1e-9, "[{$run}] the leaves do not close over it");

                continue;
            }

            // In the walk leg: from where it is, by the main call plate, its own wait, and the full door — a departure the queue holds.
            $here = $this->pointAt($from, $at - $fromAt + $from['elapsed_ms']);
            $this->assertEqualsWithDelta($here['x'], $turn['legs'][0]['points'][0]['x'], 0.001, "[{$run}] the turn does not start where the walker is");
            $this->assertEqualsWithDelta($here['y'], $turn['legs'][0]['points'][0]['y'], 0.001, "[{$run}] the turn does not start where the walker is");
            $this->assertSame(['press', 'main'], [$turn['legs'][1]['why'] ?? null, $turn['legs'][1]['plate'] ?? null], "[{$run}] the turn does not press the main call plate");
            $this->assertNotNull($turn['queue'] ?? null, "[{$run}] the turn is not a departure the queue holds");
            $this->assertSame('cab', end($turn['legs'])['kind'], "[{$run}] the turn does not board");
            $this->assertGreaterThanOrEqual($d['open'] + $d['step'] + $d['close'], $turn['door']['frames'], "[{$run}] the turn does not take the full door");
        }
    }

    public function test_green_a_walker_waiting_its_turn_at_its_desk_is_seated_at_once(): void
    {
        $result = $this->floorRun('turn-at-desk');
        $return = array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['animation_id'] === 'A1'))[0];
        $at = null;

        foreach ($this->drawn($result) as $r) {
            if ($r['trigger'] === 'render' && array_filter($r['frame']['scene']['effects'], fn ($e) => $e['animation_id'] === 'A1' && $e['cause'] === $return['cause'])) {
                $at = $r;

                break;
            }
        }

        $this->assertNotNull($at, 'turn-at-desk draws no render of its return');
        $key = $return['seat_id'];
        $this->assertSame([], $this->walks($at['frame'], $key), 'a walker waiting at its desk walks on its return');
        $this->assertContains('character', $this->kinds($this->deskIn($at['frame'], $key)), 'a walker waiting at its desk is not seated at once');
    }

    public function test_green_after_the_ride_it_rides_back_up(): void
    {
        $result = $this->floorRun('turn-after-ride');

        foreach ($this->drawn($result) as $r) {
            foreach ($this->walks($r['frame'], self::IMPL_1) as $w) {
                $this->assertNotSame('turn', $w['leg_kind'] ?? null, 'a return after the walk out had ended was drawn as a turn-round');
            }
        }

        $arrival = array_values(array_filter($this->drawn($result), fn ($r) => $this->walks($r['frame'], self::IMPL_1) !== [] && $this->walks($r['frame'], self::IMPL_1)[0]['animation_id'] === 'A1'));
        $this->assertNotSame([], $arrival, 'turn-after-ride draws no arrival');
        $this->assertSame(['cab', 'stand', 'walk'], array_column($this->walks($arrival[0]['frame'], self::IMPL_1)[0]['legs'], 'kind'), 'the return after the ride is not A1 from the cab');
    }

    // ── GREEN — the committed past, and one walk per seat ────────────────────────────────────

    public function test_green_nothing_drawn_moves_between_renders(): void
    {
        $compared = 0;

        foreach ($this->runs() as $run) {
            $drawn = $this->drawn($this->floorRun($run));
            [$walkers, $n] = $this->walkerDefects($drawn);
            $compared += $n;

            $this->assertSame([], $walkers, "[{$run}] a walker jumped or vanished between two renders");
            $this->assertSame([], $this->leafDefects($drawn), "[{$run}] the leaves jumped between two renders");
            $this->assertSame([], $this->oneWalkDefects($drawn), "[{$run}] a seat has two walks");
            $this->assertSame([], $this->continuityDefects($drawn, true)[0], "[{$run}] an intern jumped between two renders");
        }

        $this->assertGreaterThan(0, $compared, 'no walker was compared across two renders — the guard read nothing');
    }

    public function test_green_the_queue_holds_round_a_turn(): void
    {
        $spacing = $this->queueSpacing();
        $door = $this->door();

        $boarded = 0;

        foreach (['turn-queued', 'turn-riding-together', 'turn-in-cab-at-a-spot', 'turn-at-desk'] as $run) {
            foreach ($this->drawn($this->floorRun($run)) as $r) {
                $this->assertSame([], $this->queueSpacingDefects($r['frame'], $spacing)[0], "[{$run}] at {$r['at']} ms two waiting walkers stand too close");

                // AT-D3-23's boarding guard reads the leaves' closing off the LAST BOARDER's step, and a boarder that has
                // turned back while they close is no departure any more — it rides the closing out under its `door_rest`.
                // Those frames' leaves are the committed-past GREEN's to hold (`leafDefects()`); every other frame is this one's.
                if (array_filter($r['frame']['scene']['effects'] ?? [], static fn (array $e): bool => ($e['frames'] ?? 0) > 0 && is_array($e['door_rest'] ?? null)) === []) {
                    $boarded++;
                    $this->assertSame([], $this->boardingDefects($r['frame'], $door)[0], "[{$run}] at {$r['at']} ms the boarding broke");
                }
                $this->assertSame([], $this->cabDefects($r['frame'], $this->cabApart(), $this->cabFloor($r['frame']))[0], "[{$run}] at {$r['at']} ms the cab broke");
                // card#12793's lane: nobody waits in front of the doors, and no boarder walks in the lane while a walker —
                // an arrival, or a seat turned back out of the cab — is stepping out through it.
                $lane ??= $this->elevatorLaneOf($r['frame'], $this->seatBody(), $this->clearanceMargin());
                $this->assertSame([], $this->laneDefects($r['frame'], $lane)[0], "[{$run}] at {$r['at']} ms the lane broke");
            }
        }

        $this->assertGreaterThan(0, $boarded, 'the boarding guard read no frame');

        // turn-queued: the walker ahead still boards; turn-riding-together: the one beside it still rides.
        foreach (['turn-queued', 'turn-riding-together'] as $run) {
            $result = $this->floorRun($run);
            [$at] = $this->firstTurn($result, self::IMPL_1);
            $review = array_values(array_filter($this->drawn($result), fn ($r) => $r['at'] === $at && $this->walks($r['frame'], 'aimla-review') !== []));
            $this->assertNotSame([], $review, "[{$run}] the other walker's departure was cut by the turn");
        }
    }

    private function queueSpacing(): float
    {
        return (float) $this->seatBody()['w'] + $this->clearanceMargin();
    }

    /** The shipped seat walker's drawn body (`walk-probe.mjs`'s `shipped`). @return array{w: float, h: float} */
    private function seatBody(): array
    {
        static $body = null;

        return $body ??= $this->probe(['mode' => 'shipped', 'repeat' => 1, 'walls' => []], null, __DIR__.'/walk-probe.mjs')['body'];
    }

    // ── GREEN — repeated flips, interns, trips ───────────────────────────────────────────────

    public function test_green_each_flip_turns_the_walker_from_where_it_is(): void
    {
        $result = $this->floorRun('turn-twice');
        $turns = [];

        foreach ($this->drawn($result) as $r) {
            foreach ($this->walks($r['frame'], self::IMPL_1) as $w) {
                if (($w['leg_kind'] ?? null) === 'turn' && $r['trigger'] === 'render' && ! isset($turns[$w['cause']])) {
                    $turns[$w['cause']] = $w['animation_id'];
                }
            }
        }

        $this->assertSame(['A1', 'A2', 'A1'], array_values($turns), 'turn-twice does not turn the walker round on each flip');
        $this->assertContains('character', $this->kinds($this->deskIn($this->settled($result, self::IMPL_1), self::IMPL_1)));
    }

    /** The nested runs (review round 1): flip, flip back, flip again, each landing in a leg of the turn-round before it. */
    private const NESTED = ['turn-again-in-reopen', 'turn-again-in-ride', 'turn-again-at-step', 'turn-again-stepping-out', 'turn-again-mid-floor',
        'turn-again-from-restroom', 'turn-round-again-in-cab', 'turn-round-again-at-door', 'turn-round-again-queued'];

    /**
     * Each flip of a turn-round turns the walker the way its OWN row sends it — an A1 to its desk, so its last leg walks to
     * the anchor; an A2 to the elevator, so it ends in the cab or queues there — whatever the walk it turned from was; and
     * none of them starts at the desk unless the figure stood there.
     */
    public function test_green_a_turn_round_of_a_turn_round_goes_the_way_its_row_sends_it(): void
    {
        foreach (self::NESTED as $run) {
            $result = $this->floorRun($run);
            $key = $this->turned($result);
            $rows = array_values(array_filter($result['animation_log'], fn (array $r): bool => $r['class'] === 'edge'
                && in_array($r['animation_id'], ['A1', 'A2'], true) && $r['seat_id'] === $key));
            $turns = [];

            foreach ($this->drawn($result) as $r) {
                foreach ($this->walks($r['frame'], $key) as $w) {
                    if (($w['leg_kind'] ?? null) === 'turn' && ! isset($turns[$w['cause']])) {
                        $turns[$w['cause']] = [$w, $r['frame']];
                    }
                }
            }

            foreach (array_slice($rows, 1) as $row) {
                $this->assertArrayHasKey($row['cause'], $turns, "[{$run}] the {$row['animation_id']} at version {$row['cause']} draws no turn-round");
                [$turn, $frame] = $turns[$row['cause']];
                $last = end($turn['legs']);

                $this->assertSame($row['animation_id'], $turn['animation_id'], "[{$run}] the turn-round is not its row's");

                if ($row['animation_id'] === 'A1') {
                    $this->assertSame('walk', $last['kind'], "[{$run}] the A1 at version {$row['cause']} does not walk to the desk");
                    $this->assertEquals($frame['scene']['anchors'][$key], end($last['points']), "[{$run}] the A1 at version {$row['cause']} does not end at the desk");
                } else {
                    $this->assertSame('cab', $last['kind'], "[{$run}] the A2 at version {$row['cause']} does not end in the cab");
                }
            }
        }
    }

    public function test_green_the_seat_and_its_interns_turn_together(): void
    {
        $result = $this->floorRun('turn-with-interns');
        [$at, $frame, $turn] = $this->firstTurn($result, self::IMPL_1);

        $this->assertSame('A2', $turn['animation_id'], 'the seat that left again on its way in does not turn round to the elevator');
        $this->assertNotNull($turn['queue'] ?? null, 'the seat turned round is not a departure the queue holds');
        $interns = array_filter($frame['scene']['effects'], static fn (array $e): bool => $e['animation_id'] === 'A10' && ($e['leg_kind'] ?? null) === 'turn');
        $this->assertCount(2, $interns, 'the seat\'s two interns do not turn round on the same render');
    }

    public function test_green_off_a_trip_the_break_is_over(): void
    {
        foreach (['turn-from-snack', 'turn-from-restroom'] as $run) {
            $result = $this->floorRun($run);
            [$at] = $this->firstTurn($result, self::IMPL_2);

            foreach ($this->drawn($result) as $r) {
                if ($r['at'] >= $at) {
                    $trips = array_filter($r['frame']['scene']['effects'], static fn (array $e): bool => in_array($e['animation_id'], ['A25', 'A26'], true)
                        && ($e['walker_key'] ?? null) === self::IMPL_2 && ($e['frames'] ?? 0) > 0);
                    $this->assertSame([], array_values($trips), "[{$run}] at {$r['at']} ms the trip is drawn again beside the walk home");
                }
            }
        }

        // The restroom door the A2 opened to come out closes as it began to: its leaf timeline is unchanged by the turn.
        $result = $this->floorRun('turn-from-restroom');
        [$at] = $this->firstTurn($result, self::IMPL_2);
        $before = null;

        foreach ($this->drawn($result) as $r) {
            if ($r['at'] === $at && $before !== null) {
                $shift = ($r['at'] - $before['at']) / 250;
                $was = array_map(static fn ($m) => [$m['start'] - $shift, $m['frames'], $m['from'], $m['to']], $before['frame']['scene']['restroom_doors'][0]['leaf']);
                $is = array_map(static fn ($m) => [$m['start'], $m['frames'], $m['from'], $m['to']], $r['frame']['scene']['restroom_doors'][0]['leaf']);
                $this->assertEqualsWithDelta($was, $is, 0.001, 'the restroom door\'s leaf was re-timed by the turn');
            }

            $before = $r['at'] < $at ? $r : $before;
        }
    }

    // ── GREEN — a late viewer, and reduced motion ────────────────────────────────────────────

    public function test_green_a_late_viewer_draws_what_a_continuous_viewer_draws(): void
    {
        $late = $this->drawn($this->floorRun('turn-mid-floor-late'));
        $cont = $this->settled($this->floorRun('turn-mid-floor'), self::IMPL_1);

        $this->assertSame([], $this->walks($late[0]['frame']), 'a snapshot animated a walk');

        foreach ($cont['scene']['desks'] as $desk) {
            $this->assertSame($this->facts($desk), $this->facts($this->deskIn($late[0]['frame'], $desk['key'])),
                "the late viewer draws {$desk['key']} otherwise than the continuous viewer once the walk back has ended");
        }
    }

    public function test_green_reduced_motion(): void
    {
        foreach (['turn-mid-floor', 'turn-in-closing-cab', 'turn-twice'] as $run) {
            $result = $this->floorRun($run, null, ['reduce' => true]);

            foreach ($this->drawn($result) as $r) {
                $this->assertSame([], $this->walks($r['frame']), "[{$run}] a walker is drawn under reduce");
            }

            $motions = array_map(static fn ($r) => $r['motion'], array_values(array_filter($result['animation_log'],
                static fn ($r) => in_array($r['animation_id'], ['A1', 'A2'], true))));
            $this->assertSame([false], array_values(array_unique($motions)), "[{$run}] a row under reduce is logged with motion");
            $this->assertContains('character', $this->kinds($this->deskIn($this->drawn($result)[count($this->drawn($result)) - 1]['frame'], self::IMPL_1)));
        }
    }

    // ── GREEN — the seeded fuzz: flips at any instant, nested to any depth ───────────────────

    /** The fuzz set's file (`tools/design/gen-fx-turn-back.mjs`'s `FUZZ` seeds and `DENSE` offsets). */
    private const FUZZ = 'fx-turn-back-fuzz';

    /** A probe that has not ended this long after it started is a run that never ends — the end timer spinning. */
    private const FUZZ_TIMEOUT_S = 180.0;

    /**
     * The fuzz runs the plant of review round 2's F-A must break: the mass departures seeded where the turn read a `line` —
     * re-measured over every mass run of the set when the seeded draws became the `seat_id`'s (card#13117).
     */
    private const LABEL_RUNS = ['fuzz-mass-s7-21', 'fuzz-mass-s99-35'];

    /** The shipped tree's fuzz outcome, replayed once per process and read by every test below. */
    private static ?array $fuzzed = null;

    /** How many probes run at once: the runner's own cores, as `nproc` counts them. */
    private function cores(): int
    {
        return max(1, (int) trim((string) shell_exec('nproc 2>/dev/null')));
    }

    /**
     * Replay fuzz runs — on the shipped tree, or on `$dir` — all at once, each read by the committed-past checks as it ends:
     * `[name => defects]`, a run that did not end or whose probe failed carrying that as its defect; and, on the shipped tree,
     * the walks each run drew, one case per walk at its applying render, for the `turn-blind` probe.
     *
     * @param  list<string>|null  $names  every run of the file when `null`
     * @return array{defects: array<string, list<string>>, compared: int, blind: list<array<string, mixed>>, wall_s: float}
     */
    private function fuzz(?array $names = null, ?string $dir = null): array
    {
        $runs = $this->fixtureFile(self::FUZZ)['runs'];
        $payloads = $names === null ? $runs : array_intersect_key($runs, array_flip($names));
        $out = ['defects' => [], 'compared' => 0, 'blind' => [], 'wall_s' => 0.0];
        $started = microtime(true);

        $this->assertNotSame([], $payloads, 'the fuzz set holds none of the runs asked for');

        $this->runProbes($payloads, $this->cores(), self::FUZZ_TIMEOUT_S, function (string $name, ?int $status, string $stdout, string $stderr) use (&$out, $dir): void {
            if ($status === null) {
                $out['defects'][$name] = ['the run did not end within '.self::FUZZ_TIMEOUT_S.' s'];

                return;
            }

            $result = $status === 0 ? (json_decode($stdout, true)['runs'][0] ?? null) : null;

            if ($result === null) {
                $lines = array_values(array_filter(explode("\n", $stderr), static fn (string $l): bool => str_starts_with($l, 'Error')));
                $out['defects'][$name] = ['the probe failed: '.($lines[0] ?? trim($stderr))];

                return;
            }

            $drawn = $this->drawn($result);
            [$defects, $n] = $this->committedPastDefects($drawn);
            $out['defects'][$name] = [
                ...array_map(static fn (string $r): string => "unscripted request {$r}", $result['unscripted']),
                ...array_map(static fn (string $r): string => "unhandled rejection {$r}", $result['rejections']),
                ...$defects,
            ];
            $out['compared'] += $n;

            if ($dir === null) {
                array_push($out['blind'], ...$this->blindCases($name, $drawn));
            }
        }, $dir);

        $out['wall_s'] = round(microtime(true) - $started, 1);
        ksort($out['defects']);

        return $out;
    }

    private function fuzzed(): array
    {
        return self::$fuzzed ??= $this->fuzz();
    }

    /**
     * The `turn-blind` probe's cases from one run: each walk — a seat's A1 or A2, an intern's A10 — at the render that first
     * drew it, with the anchor it walks to or from and the threshold, at two instants inside each of its legs.
     */
    private function blindCases(string $run, array $drawn): array
    {
        $cases = [];

        foreach ($drawn as $r) {
            if ($r['trigger'] !== 'render') {
                continue;
            }

            foreach ($r['frame']['scene']['effects'] ?? [] as $e) {
                if (! in_array($e['animation_id'], ['A1', 'A2', 'A10'], true) || ($e['frames'] ?? 0) <= 0 || ($e['legs'] ?? []) === []) {
                    continue;
                }

                $intern = $e['animation_id'] === 'A10';
                $id = $run.' '.($e['intern_key'] ?? $this->keyOf($e)).' '.$e['cause'].' '.($e['leg_kind'] ?? '');
                $anchor = $intern ? ($e['anchor'] ?? null) : ($r['frame']['scene']['anchors'][$this->keyOf($e)] ?? null);

                if (isset($cases[$id]) || $anchor === null) {
                    continue;
                }

                $I = $e['frame_interval_ms'];
                $times = [];

                foreach ($e['legs'] as $l) {
                    foreach ([min(0.5, $l['frames'] / 2), $l['frames'] * 0.75] as $f) {
                        $times[] = round(($l['start'] + $f) * $I);
                    }
                }

                $cases[$id] = ['name' => $id, 'effect' => $e, 'anchor' => $anchor, 'door' => $this->threshold($r['frame']), 'intern' => $intern,
                    'times' => array_values(array_unique(array_filter($times, static fn ($t): bool => $t < $e['frames'] * $I)))];
            }
        }

        return array_values($cases);
    }

    /**
     * THE SEEDED FUZZ HOLDS THE COMMITTED PAST: every run of `fx-turn-back-fuzz` — one walker flipped one to four times at
     * seeded instants over seven committed runs (a departure alone and second in a queue, an arrival, a mass departure, a
     * seat with its interns, a restroom visit and a snack trip), and the dense closing-cab runs — ends, and on every pair of
     * consecutive drawn frames no walker jumps or vanishes, the leaves keep their motion and no seat has two walks.
     */
    public function test_green_the_seeded_fuzz_moves_nothing_drawn(): void
    {
        $fuzzed = $this->fuzzed();
        $bad = array_filter($fuzzed['defects'], static fn (array $d): bool => $d !== []);

        $this->assertSame([], $bad, 'a fuzz run broke the committed past');
        $names = array_keys($this->fixtureFile(self::FUZZ)['runs']);
        sort($names);
        $this->assertSame($names, array_keys($fuzzed['defects']), 'a fuzz run was not replayed');
        $this->assertGreaterThan(0, $fuzzed['compared'], 'no walker was compared across two renders — the fuzz read nothing');
    }

    /**
     * THE TURN READS NO LABEL (the class review rounds 1 and 2 each re-minted): every walk the fuzz drew, handed to
     * `turnRound()` at two instants in each of its legs, both ways, turns exactly as the same walk does with every leg's
     * `why` and `plate`, its queue part, its `leg_kind`, its animation id and its cause stripped — and the comparison reaches
     * a figure on the floor, in the cab and inside a restroom door, each with a turn drawn.
     */
    public function test_green_the_turn_reads_no_label(): void
    {
        $answer = $this->probe(['mode' => 'turn-blind', 'cases' => $this->fuzzed()['blind']], null, __DIR__.'/walk-probe.mjs');

        $this->assertSame([], $answer['differ'], 'a turn-round read a label of the walk it turned from');
        $this->assertGreaterThan(0, $answer['compared'], 'the probe compared nothing');

        foreach (['floor', 'cab', 'inside'] as $where) {
            $this->assertGreaterThan(0, $answer['turned'][$where] ?? 0, "no case turned a figure {$where} — the comparison never reached that pose");
        }
    }

    // ── GREEN — what may replace a walk ──────────────────────────────────────────────────────

    /** The turn replaces the walk only where nothing but its row touched it; every other clause of item 6 cancels it. */
    public function test_green_only_the_row_lets_a_turn_replace_the_walk(): void
    {
        $this->assertSame(['none' => true, 'journal' => false, 'lattice' => false, 'stilled' => false, 'unconfirmed' => false, 'anchor' => false, 'threshold' => false],
            $this->touched(), 'a turn-round replaced a walk another clause of item 6 touched, or the row alone did not let it');
    }

    private function touched(?string $dir = null): array
    {
        $result = $this->floorRun('turn-mid-floor');
        [$at, $frame, $turn, $from, $fromAt] = $this->firstTurn($result, self::IMPL_1);

        return $this->probe(['mode' => 'turn-touched', 'walk' => $from, 'turn' => $turn, 'key' => self::IMPL_1, 'anchor' => $frame['scene']['anchors'][self::IMPL_1],
            'door' => $this->threshold($frame), 'lattice_key' => $turn['lattice_key'], 'at' => $at - $fromAt + $from['elapsed_ms']], $dir, __DIR__.'/walk-probe.mjs');
    }

    // ── RED ──────────────────────────────────────────────────────────────────────────────────

    /** RED — decision 51 for a seat: the turn dropped, the walker vanishes and the person is drawn seated at once. */
    public function test_red_the_vanishing_walker(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'if (flying !== null && flying.effect.animation_id !== id && motion', 'if (false && flying !== null && flying.effect.animation_id !== id && motion']);

        $this->assertNotSame([], $this->walkerDefects($this->drawn($this->floorRun('turn-mid-floor', $dir)))[0], 'a walker vanished mid-floor and the guard saw nothing');
    }

    /** Second RED — the turn from the far end: a walk back that starts at the threshold, not where the walker is. */
    public function test_red_the_turn_from_the_threshold(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "    const h = homeFrom(p, where === 'inside' ? pose.inside_left : 0);", "    const h = homeFrom(at, where === 'inside' ? pose.inside_left : 0);"]);

        $this->assertNotSame([], $this->walkerDefects($this->drawn($this->floorRun('turn-mid-floor', $dir)))[0], 'a walk back began at the threshold and the guard saw nothing');
    }

    /** Third RED — the leaves restarted: a turn that stays in an opening cab restarting the leaves, as item 16 drew an intern's. */
    public function test_red_the_leaves_restarted(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "            if (leaves.state === 'open') {\n                const door = finish(0);\n",
            "            if (leaves.state === 'open') {\n                const door = Object.freeze({ start: 0, frames: D.close });\n"]);

        $this->assertNotSame([], $this->leafDefects($this->drawn($this->floorRun('turn-round-in-cab', $dir))), 'the leaves restarted from open and the guard saw nothing');
    }

    /** Fourth RED — the closing forgotten: a ride that completes without the leaves' closing it rode in. */
    public function test_red_the_closing_forgotten(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "            door: Object.freeze({ start: R, frames: D.open + D.step + D.close }),\n            ...(leaves.state === 'closing' ? { door_rest: leaves.span } : {}),\n",
            "            door: Object.freeze({ start: R, frames: D.open + D.step + D.close }),\n"]);

        $this->assertNotSame([], $this->leafDefects($this->drawn($this->floorRun('turn-in-closing-cab', $dir))), 'the leaves snapped shut mid-closing and the guard saw nothing');
    }

    /** Fifth RED — the leaves left behind: leaves opening on a walker that turns on the threshold dropped with its walk. */
    public function test_red_the_leaves_left_behind(): void
    {
        $dir = $this->mutatedModules([self::SCENE, '    return { legs: [...inside, h.walk], door: null, ...rest, tier: h.tier };', '    return { legs: [...inside, h.walk], door: null, tier: h.tier };']);

        $this->assertNotSame([], $this->leafDefects($this->drawn($this->floorRun('turn-doors-opening', $dir))), 'the leaves snapped shut as the walker turned and the guard saw nothing');
    }

    /** Sixth RED — the rider's spot forgotten: a walker turned out of the cab steps out from the threshold. */
    public function test_red_the_rider_spot_forgotten(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, '            const was = turn ? inCab.get(key) : undefined;', '            const was = undefined;']);

        $this->assertNotSame([], $this->walkerDefects($this->drawn($this->floorRun('turn-in-cab-at-a-spot', $dir)))[0], 'a rider jumped to the threshold and the guard saw nothing');
    }

    /** Seventh RED — the desk seated under the walk back: a turn-round's desk drawn as its applied object's render. */
    public function test_red_the_desk_seated_under_the_walk_back(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, ".filter((h) => h.key !== null && h.effect.animation_id === 'A1' && !this.#ended(h, now))",
            ".filter((h) => h.key !== null && h.effect.animation_id === 'A1' && h.effect.leg_kind !== 'turn' && !this.#ended(h, now))"]);
        [, $frame] = $this->firstTurn($this->floorRun('turn-mid-floor', $dir), self::IMPL_1);

        $this->assertContains('character', $this->kinds($this->deskIn($frame, self::IMPL_1)), 'the planted desk is not seated — the plant reached nothing');
    }

    /** Eighth RED — any touch lets the turn in: the holder admitting a turn whatever else touched the walk it replaces. */
    public function test_red_any_touch_lets_the_turn_in(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, '(turn ? !turnable.has(key) : cancelled.includes(key))', '(turn ? false : cancelled.includes(key))']);

        $this->assertNotSame(['none' => true, 'journal' => false, 'lattice' => false, 'stilled' => false, 'unconfirmed' => false, 'anchor' => false, 'threshold' => false],
            $this->touched($dir), 'a turn replaced a walk the lattice moved under and the guard saw nothing');
    }

    /** Ninth RED — the walk back that never ends: the late viewer and the continuous one disagree for good. */
    public function test_red_the_walk_back_that_never_ends(): void
    {
        $dir = $this->mutatedModules([self::SCENE, '        frames: Math.max(...legs.map((l) => l.start + l.frames), ...spans.map((s) => s.start + s.frames)),',
            "        frames: (more.leg_kind === 'turn' ? 1e9 : 0) + Math.max(...legs.map((l) => l.start + l.frames), ...spans.map((s) => s.start + s.frames)),"]);
        $result = $this->floorRun('turn-mid-floor', $dir);
        $drawn = $this->drawn($result);
        $last = end($drawn)['frame'];

        $this->assertNotContains('character', $this->kinds($this->deskIn($last, self::IMPL_1)), 'the planted walk back ended — the plant reached nothing');
        $this->assertNotSame($this->facts($this->deskIn($this->drawn($this->floorRun('turn-mid-floor-late'))[0]['frame'], self::IMPL_1)), $this->facts($this->deskIn($last, self::IMPL_1)),
            'a continuous viewer drew the desk otherwise than a late one and the comparison saw nothing');
    }

    /** Tenth RED — the direction read off the walk's shape (review round 1): a turn-round of a turn-round goes the wrong way. */
    public function test_red_the_direction_from_the_shape(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "        to: id === 'A1' ? 'desk' : 'elevator',", "        to: (flying.effect.queue ?? null) !== null ? 'desk' : 'elevator',"]);
        $drawn = $this->drawn($this->floorRun('turn-again-in-reopen', $dir));

        $this->assertNotSame([], [...$this->walkerDefects($drawn)[0], ...$this->leafDefects($drawn)], 'a nested turn went the shape\'s way and no guard saw it');
    }

    /** Eleventh RED — the leaves read off `door` alone: a turn-round of a ride still closing loses that closing. */
    public function test_red_the_leaves_read_off_door_alone(): void
    {
        $dir = $this->mutatedModules([self::SCENE, '    const spans = [effect.door_rest, effect.door]', '    const spans = [effect.door]']);

        $this->assertNotSame([], $this->leafDefects($this->drawn($this->floorRun('turn-again-in-ride', $dir))), 'the closing a ride carried was lost and the guard saw nothing');
    }

    /**
     * Twelfth RED — the turn reads a label (review round 2's F-A, planted): `turnRound()` seating a walker whose leg is a `line`
     * wait, wherever it stands. The turn-blind comparison must differ, and the mass departures the fuzz seeded in a mid-floor
     * turn-round's `line` wait must draw the walker vanishing from it.
     */
    public function test_red_the_turn_reads_a_label(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "    const pose = poseOf(flying);\n    const turned = turn(pose, way);\n",
            "    const pose = poseOf(flying);\n    const turned = way.to === 'desk' && flying.effect.legs[pointAt(flying.effect, flying.elapsed_ms).leg].why === 'line' ? { seated: true } : turn(pose, way);\n"]);

        $blind = $this->probe(['mode' => 'turn-blind', 'cases' => $this->fuzzed()['blind']], $dir, __DIR__.'/walk-probe.mjs');
        $this->assertNotSame([], $blind['differ'], 'a turn read a leg\'s `why` and the turn-blind comparison saw nothing');

        $planted = $this->fuzz(self::LABEL_RUNS, $dir)['defects'];
        $runs = self::LABEL_RUNS;
        sort($runs);
        $this->assertSame($runs, array_keys(array_filter($planted, static fn (array $d): bool => array_filter($d, static fn (string $x): bool => str_contains($x, 'vanished')) !== [])),
            'the planted label read seated a walker from a mid-floor `line` wait and the fuzz saw nothing');
    }

    /**
     * Thirteenth RED — the end read two ways (review round 2's 0 ms re-arm, planted): `#ended()` reading the elapsed ms
     * against the frames' time, as it did before card#12466's end instants, while `nextEnd()` reads the end instant — so a
     * dense run's refresh, set for an end `#ended()` does not reach, re-arms at 0 ms.
     */
    public function test_red_the_end_read_two_ways(): void
    {
        $dir = $this->mutatedModules([self::IN_FLIGHT, '        return now >= this.#endAt(h);', '        return now - h.at >= h.frames * this.#intervalMs;']);
        $dense = array_values(array_filter(array_keys($this->fixtureFile(self::FUZZ)['runs']), static fn (string $n): bool => str_starts_with($n, 'dense-')));

        $this->assertNotSame([], $dense, 'the fuzz set holds no dense run');
        $spun = array_filter($this->fuzz($dense, $dir)['defects'], static fn (array $d): bool => array_filter($d, static fn (string $x): bool => str_contains($x, 're-armed at 0 ms')) !== []);
        $this->assertNotSame([], $spun, 'the end was read two ways and no dense run spun');
    }
}

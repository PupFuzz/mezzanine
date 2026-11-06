<?php

namespace Tests\Feature\Floor;

/**
 * card#12705 — THE QUEUE AT THE ELEVATOR, read off one drawn frame (`docs/design/FLOOR.md § 6.2`'s walk note item 9):
 * the two properties the operator asked for, each re-derived here from the legs the frame's scene carries and § 12's own
 * rows, never from the holder that planned them.
 *
 * - **Apart** (`queueSpacingDefects()`): at every instant two departures both stand waiting, their feet are at least
 *   § 12's *Queue spacing* apart — never one point.
 * - **Together** (`boardingDefects()`): for every opening of the leaves the frame draws, every departure standing at its
 *   spot as they begin to open steps into the cab inside that opening; the departures stepping in do so one at a time —
 *   each a step after the one before, each leaving its spot only once the one before has stepped in, and one waiting
 *   then leaving it then; one waiting on the threshold itself steps in first (nearest the door first); none steps in
 *   before the leaves are open; and the leaves close right after the last has stepped in.
 * - **The call plates first** (`callDefects()`, card#12747): every departure presses its own call plate — a seat the
 *   main one, an intern the low one — at the point re-derived from the frame's plate rect, the plate within its reach,
 *   before it waits; and while one presses, no other departure stands or walks to the plates within a walker's
 *   clearance of it.
 *
 * A departure is an effect with frames to run that waits (`departures()`: A2's walk, an intern's walk out), read off its
 * legs. Times are milliseconds from the frame's own instant, each walk's legs shifted by how far into
 * it the frame is (`elapsed_ms`).
 */
trait ChecksTheElevatorQueue
{
    /**
     * The departures a frame draws: every walk with frames to run that waits — a `stand` leg `why: 'wait'` — read off its
     * legs and never off the queue part the holder plans from, so a departure drawn outside the queue is still read.
     *
     * @return list<array<string, mixed>>
     */
    private function departures(array $frame): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => ($e['frames'] ?? 0) > 0
            && array_filter($e['legs'] ?? [], static fn (array $l): bool => ($l['why'] ?? null) === 'wait') !== []));
    }

    private function walkerKey(array $walk): string
    {
        return $walk['intern_key'] ?? "{$walk['install_id']}/{$walk['seat_id']}";
    }

    /** A leg's span in ms from the frame's instant. @return array{0: float, 1: float} */
    private function legSpan(array $walk, array $leg): array
    {
        $I = $walk['frame_interval_ms'];

        return [$leg['start'] * $I - $walk['elapsed_ms'], ($leg['start'] + $leg['frames']) * $I - $walk['elapsed_ms']];
    }

    /** The leg a walker is in at `$t` ms from the frame's instant, and where its feet are — by arc length in a walk. */
    private function queuedAt(array $walk, float $t): array
    {
        $legs = $walk['legs'];
        $leg = end($legs);

        foreach ($legs as $l) {
            if ($t < $this->legSpan($walk, $l)[1]) {
                $leg = $l;

                break;
            }
        }

        if ($leg['kind'] === 'cab' && ($step = $this->cabStep($leg)) !== null) {
            $I = $walk['frame_interval_ms'];
            $u = min(1, max(0, ($t + $walk['elapsed_ms'] - $step['start'] * $I) / ($step['frames'] * $I)));

            return ['leg' => $leg, 'x' => $step['from']['x'] + ($step['to']['x'] - $step['from']['x']) * $u,
                'y' => $step['from']['y'] + ($step['to']['y'] - $step['from']['y']) * $u];
        }

        if ($leg['kind'] !== 'walk') {
            return ['leg' => $leg, 'x' => $leg['at']['x'], 'y' => $leg['at']['y']];
        }

        [$from, $to] = $this->legSpan($walk, $leg);
        $points = $leg['points'];
        $total = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $total += hypot($points[$i]['x'] - $points[$i - 1]['x'], $points[$i]['y'] - $points[$i - 1]['y']);
        }

        $s = $total * min(1, max(0, ($t - $from) / ($to - $from)));

        for ($i = 1; $i < count($points); $i++) {
            $seg = hypot($points[$i]['x'] - $points[$i - 1]['x'], $points[$i]['y'] - $points[$i - 1]['y']);

            if ($s <= $seg && $seg > 0) {
                return ['leg' => $leg, 'x' => $points[$i - 1]['x'] + ($points[$i]['x'] - $points[$i - 1]['x']) * $s / $seg,
                    'y' => $points[$i - 1]['y'] + ($points[$i]['y'] - $points[$i - 1]['y']) * $s / $seg];
            }

            $s -= $seg;
        }

        $last = end($points);

        return ['leg' => $leg, 'x' => $last['x'], 'y' => $last['y']];
    }

    /**
     * card#12744: a `cab` leg's step inside the cab, read off the leg — a boarder's from where it `enter`s (the threshold)
     * to its `at` over the leg's first frames, an arrival's from its `at` to where it `leave`s to over its last — or `null`
     * for a rider standing at its `at` the whole leg. In the walk's frames.
     *
     * @return array{from: array, to: array, start: int, frames: int}|null
     */
    private function cabStep(array $leg): ?array
    {
        if (isset($leg['enter'])) {
            return ['from' => $leg['enter']['from'], 'to' => $leg['at'], 'start' => $leg['start'], 'frames' => $leg['enter']['frames']];
        }

        if (isset($leg['leave'])) {
            return ['from' => $leg['at'], 'to' => $leg['leave']['to'], 'start' => $leg['start'] + $leg['frames'] - $leg['leave']['frames'], 'frames' => $leg['leave']['frames']];
        }

        return null;
    }

    /** Whether a walker stands waiting at `$t`: in its `wait` stand, past its start and before its end. */
    private function waitingAt(array $walk, float $t): bool
    {
        foreach ($walk['legs'] as $l) {
            [$from, $to] = $this->legSpan($walk, $l);

            if (($l['why'] ?? null) === 'wait' && $from <= $t && $t < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * STILL (card#12705, review round 1): the defects where a walker's drawn position changes between two consecutive
     * renders — read off a run's drawn frames, each walk (keyed by row, seat, intern and cause) placed by the earlier
     * frame's legs at the later frame's instant and by the later frame's own legs at that instant, which must agree
     * within 1.5 px. A re-time that moves a walker's past — back out of the cab, back to its spot, forward along a walk —
     * is a jump on screen, and this is what sees it. `$byIntern` keys an intern's walks by its intern key alone, so item
     * 16's turn-round — a walk of another cause that replaces the arrival — is held to start where the arrival stood
     * (card#12744).
     *
     * @param  list<array{at: int, frame: array<string, mixed>}>  $drawn  a run's drawn frames, in order
     * @return array{0: list<string>, 1: int} the defects, and the number of comparisons made
     */
    private function continuityDefects(array $drawn, bool $byIntern = false): array
    {
        $key = fn (array $e): string => $byIntern && isset($e['intern_key']) ? $e['intern_key'] : "{$e['animation_id']} {$this->walkerKey($e)} {$e['cause']}";
        $before = [];
        $defects = [];
        $compared = 0;

        foreach ($drawn as $r) {
            $now = [];

            foreach ($r['frame']['scene']['effects'] ?? [] as $e) {
                if (($e['frames'] ?? 0) <= 0 || ! is_array($e['legs'] ?? null) || $e['legs'] === []) {
                    continue;
                }

                $k = $key($e);
                $now[$k] = ['at' => $r['at'], 'walk' => $e];

                if (isset($before[$k]) && $r['at'] >= $before[$k]['at']) {
                    $compared++;
                    $was = $this->queuedAt($before[$k]['walk'], $r['at'] - $before[$k]['at']);
                    $is = $this->queuedAt($e, 0);
                    $d = hypot($was['x'] - $is['x'], $was['y'] - $is['y']);

                    if ($d > 1.5) {
                        $defects[] = "at {$r['at']} ms {$k} jumped {$d} px (was in its {$was['leg']['kind']} leg, now in its {$is['leg']['kind']})";
                    }
                }
            }

            $before = $now;
        }

        return [$defects, $compared];
    }

    /**
     * APART: the defects where two departures waiting at one instant stand closer than `$spacing`, and the number of
     * waiting pairs sampled — every half loop frame over the frame's horizon, and every leg boundary.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function queueSpacingDefects(array $frame, float $spacing): array
    {
        $walks = $this->departures($frame);
        $instants = [];

        foreach ($walks as $w) {
            $end = $w['frames'] * $w['frame_interval_ms'] - $w['elapsed_ms'];

            for ($t = 0.0; $t <= $end; $t += $w['frame_interval_ms'] / 2) {
                $instants[] = $t;
            }

            foreach ($w['legs'] as $l) {
                [$from, $to] = $this->legSpan($w, $l);
                $instants[] = max(0.0, $from);
                $instants[] = max(0.0, $to - 0.001);
            }
        }

        $instants = array_values(array_unique($instants, SORT_REGULAR));
        $defects = [];
        $pairs = 0;

        foreach ($instants as $t) {
            for ($i = 0; $i < count($walks); $i++) {
                for ($j = $i + 1; $j < count($walks); $j++) {
                    if (! $this->waitingAt($walks[$i], $t) || ! $this->waitingAt($walks[$j], $t)) {
                        continue;
                    }

                    $pairs++;
                    $a = $this->queuedAt($walks[$i], $t);
                    $b = $this->queuedAt($walks[$j], $t);
                    $d = hypot($a['x'] - $b['x'], $a['y'] - $b['y']);

                    if ($d < $spacing) {
                        $defects[] = "at {$t} ms {$this->walkerKey($walks[$i])} and {$this->walkerKey($walks[$j])} wait {$d} px apart, under the {$spacing} px spacing";
                    }
                }
            }
        }

        return [array_values(array_unique($defects)), $pairs];
    }

    /**
     * The openings of the leaves a frame draws, in ms from its instant: every walk's own door frames, each placed by its
     * own exact `elapsed_ms`, merged where they meet — the spans `scene.doors` carries, read without its whole-frame
     * rounding (it counts each walk's door from the frame its elapsed time has reached, so a walk begun between two loop
     * frames lands up to one frame early there).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function openings(array $frame): array
    {
        $spans = [];

        foreach ($frame['scene']['effects'] ?? [] as $e) {
            if (($e['frames'] ?? 0) > 0 && is_array($e['door'] ?? null)) {
                $I = $e['frame_interval_ms'];
                $spans[] = [$e['door']['start'] * $I - $e['elapsed_ms'], ($e['door']['start'] + $e['door']['frames']) * $I - $e['elapsed_ms']];
            }
        }

        usort($spans, static fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($spans as $s) {
            if ($s[1] <= 0) {
                continue;
            }

            $last = count($merged) - 1;

            if ($last >= 0 && $s[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $s[1]);
            } else {
                $merged[] = $s;
            }
        }

        return $merged;
    }

    /**
     * TOGETHER: the defects in how the departures a frame draws board, against every opening of the leaves it draws
     * (`openings()`), and the most departures any one opening boards.
     *
     * @param  array{open: int, step: int, close: int}  $door  § 12's *Elevator leaves*
     * @return array{0: list<string>, 1: int}
     */
    private function boardingDefects(array $frame, array $door): array
    {
        $walks = $this->departures($frame);
        $defects = [];
        $most = 0;

        foreach ($this->openings($frame) as [$from, $to]) {
            $I = $walks[0]['frame_interval_ms'] ?? 250;
            $boarders = [];

            foreach ($walks as $w) {
                $cab = array_values(array_filter($w['legs'], static fn ($l) => $l['kind'] === 'cab'))[0];
                $in = $this->legSpan($w, $cab)[0];

                if ($in >= $from && $in < $to) {
                    // When it leaves its spot: the end of its wait.
                    $wait = array_values(array_filter($w['legs'], static fn ($l) => ($l['why'] ?? null) === 'wait'))[0];
                    $boarders[] = ['walk' => $w, 'key' => $this->walkerKey($w), 'in' => $in, 'leaves' => $this->legSpan($w, $wait)[1]];
                } elseif ($this->waitingAt($w, $from)) {
                    $defects[] = "{$this->walkerKey($w)} waits at its spot as the leaves open at {$from} ms and does not step in before they close at {$to} ms";
                }
            }

            usort($boarders, static fn ($a, $b) => $a['in'] <=> $b['in']);
            $most = max($most, count($boarders));
            // An arrival's own door frames inside the opening hold the doorway for its step out and can hold the leaves
            // open past the last boarder: the first boarder's start and the close are read only on an opening no walk
            // with fixed door frames shares.
            $shared = array_filter($frame['scene']['effects'] ?? [], fn (array $e): bool => ($e['frames'] ?? 0) > 0 && ! in_array($e, $walks, true)
                && is_array($e['door'] ?? null) && $e['door']['start'] * $I - $e['elapsed_ms'] < $to
                && ($e['door']['start'] + $e['door']['frames']) * $I - $e['elapsed_ms'] > $from);
            // card#12466: a seat turned back OUT of the cab mid-batch — an A1 turn-round from the cab, stepping out through
            // this opening after a boarder has already stepped in — holds the doorway until it has cleared the lane in front
            // of it (card#12793's `laneClear()`), so the next boarder's leaving comes after that, not a step after the one
            // before it: where one shares the opening, a later boarder's wait is not judged late here, and `laneDefects()`
            // holds that no boarder walks in the lane while it is there. A plain arrival clears the lane before the first
            // boarder is released, so for it the step rule stands and every boarder is judged.
            $out = array_filter($shared, static fn (array $e): bool => ($e['leg_kind'] ?? null) === 'turn' && $e['animation_id'] === 'A1'
                && ($e['legs'][0]['kind'] ?? null) === 'cab') !== [];

            // Nearest the door first: one waiting ON the threshold — its wait where its door stand is — as the leaves begin to
            // open is in the doorway, and steps in before every other boarder.
            foreach ($boarders as $k => $b) {
                $legs = $b['walk']['legs'];
                $wait = array_values(array_filter($legs, static fn ($l) => ($l['why'] ?? null) === 'wait'))[0];
                $atDoor = array_values(array_filter($legs, static fn ($l) => ($l['why'] ?? null) === 'door'))[0];

                if ($k > 0 && $wait['at'] == $atDoor['at'] && $this->waitingAt($b['walk'], $from - 0.001)) {
                    $defects[] = "{$b['key']} waited on the threshold as the leaves opened at {$from} ms and stepped in after {$boarders[0]['key']}";
                }
            }

            foreach ($boarders as $k => $b) {
                // When it may leave its spot: as the leaves begin to open, or once the boarder before it has stepped in.
                // One still waiting then leaves then — within the frame a walk begun between two loop frames rounds up by.
                // Never before this frame's instant: what was free before it was judged on the frames that drew it, and a
                // boarder cancelled since (item 6) is not drawn here, its follower keeping the leaving it already had.
                $ready = $k === 0 ? ($shared === [] ? $from : null) : ($out ? null : $boarders[$k - 1]['in'] + $door['step'] * $I);
                $ready = $ready === null ? null : max(0.0, $ready);

                if ($ready !== null && $this->waitingAt($b['walk'], $ready - 0.001) && $I - 0.001 <= $b['leaves'] - $ready) {
                    $defects[] = "{$b['key']} waits at its spot until {$b['leaves']} ms, past {$ready} ms when it was free to board";
                }

                if ($b['in'] < $from + $door['open'] * $I - 0.001) {
                    $defects[] = "{$b['key']} steps in at {$b['in']} ms, before the leaves opening at {$from} ms are open";
                }

                if ($k > 0) {
                    $before = $boarders[$k - 1];

                    if ($b['in'] < $before['in'] + $door['step'] * $I - 0.001) {
                        $defects[] = "{$b['key']} steps in at {$b['in']} ms, inside {$before['key']}'s step at {$before['in']} ms — not one at a time";
                    }

                    if ($b['leaves'] < $before['in'] + $door['step'] * $I - 0.001) {
                        $defects[] = "{$b['key']} leaves its spot at {$b['leaves']} ms, before {$before['key']} has stepped in at {$before['in']} ms";
                    }
                }
            }

            // Every boarder's walk ends as the leaves close, so on a frame drawn within a loop frame of their closing a
            // boarder may already have ended and be drawn no more (card#12747: a walk-end refresh at its last boarder's
            // end) — the close is read whole on the run's earlier frames, never on a frame that cannot see every boarder.
            if ($boarders !== [] && $shared === [] && $to >= $I - 0.001) {
                $last = end($boarders);
                $shut = $last['in'] + ($door['step'] + $door['close']) * $I;

                // Each walk's legs are whole frames of its own; a walk begun between two loop frames rounds up, by under one.
                if ($to < $shut - 0.001 || $to - $shut >= $I - 0.001) {
                    $defects[] = "the leaves opening at {$from} ms close at {$to} ms, not right after the last boarder {$last['key']} steps in at {$last['in']} ms (owed {$shut} ms)";
                }
            }
        }

        return [$defects, $most];
    }

    /**
     * card#12744 — APART IN THE CAB (`docs/design/FLOOR.md § 6.2`'s walk note item 10): the defects in where the riders a
     * frame draws stand inside the cab, and the number of pairs of riders sampled standing in it at one instant — every
     * half loop frame over the frame's horizon, and every leg boundary. A rider is every walk with frames to run and a
     * `cab` leg (A1's and A2's walks, an intern's arrival, departure, turn-round and blink), placed by its legs as
     * `queuedAt()` places it.
     *
     * - **Apart:** two riders STANDING in the cab at one instant — each in its cab leg, neither inside its step
     *   (`cabStep()`) — stand at least `$apart` apart, feet to feet. A boarder's step starts on the threshold, which is
     *   where the first boarder stands, so a rider stepping is passing, not standing, and is read by the next two only.
     * - **Inside:** every rider's feet, at every sampled instant of its cab leg, stepping included, are on the cab floor
     *   the band draws behind the leaves (`$floor`, `cabFloor()`).
     * - **No jump:** across each boundary between a cab leg and a floor leg, a rider moves at most 1.5 px in a
     *   millisecond — it steps between the threshold and its spot, and never appears at its spot.
     *
     * @param  array{bottom: float, seam: float, depth: float, half: callable}  $floor  `cabFloor()`
     * @return array{0: list<string>, 1: int}
     */
    private function cabDefects(array $frame, float $apart, array $floor): array
    {
        $riders = array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => ($e['frames'] ?? 0) > 0
            && array_filter($e['legs'] ?? [], static fn (array $l): bool => $l['kind'] === 'cab') !== []));
        $cabOf = static fn (array $w): array => array_values(array_filter($w['legs'], static fn (array $l): bool => $l['kind'] === 'cab'))[0];
        $instants = [];

        foreach ($riders as $w) {
            $end = $w['frames'] * $w['frame_interval_ms'] - $w['elapsed_ms'];

            for ($t = 0.0; $t <= $end; $t += $w['frame_interval_ms'] / 2) {
                $instants[] = $t;
            }

            foreach ($w['legs'] as $l) {
                [$from, $to] = $this->legSpan($w, $l);
                $instants[] = max(0.0, $from);
                $instants[] = max(0.0, $to - 0.001);
            }
        }

        $instants = array_values(array_unique($instants, SORT_REGULAR));
        $defects = [];
        $pairs = 0;
        $key = fn (array $w): string => "{$w['animation_id']} {$this->walkerKey($w)}";
        // Where a rider is at `$t` and whether it stands then (in its cab leg, not stepping) — `null` outside its cab leg.
        $inCab = function (array $w, float $t) use ($cabOf): ?array {
            $cab = $cabOf($w);
            [$from, $to] = $this->legSpan($w, $cab);

            if ($t < $from || $t >= $to) {
                return null;
            }

            $p = $this->queuedAt($w, $t);
            $step = $this->cabStep($cab);
            $I = $w['frame_interval_ms'];
            $stepping = $step !== null && $t >= $step['start'] * $I - $w['elapsed_ms'] && $t < ($step['start'] + $step['frames']) * $I - $w['elapsed_ms'];

            return ['x' => $p['x'], 'y' => $p['y'], 'standing' => ! $stepping];
        };

        foreach ($instants as $t) {
            $at = array_map(static fn (array $w) => $inCab($w, $t), $riders);

            foreach ($at as $i => $p) {
                if ($p === null) {
                    continue;
                }

                $up = $floor['bottom'] - $p['y'];

                if ($up < -0.001 || $up > $floor['depth'] + 0.001 || abs($p['x'] - $floor['seam']) > ($floor['half'])($up) + 0.001) {
                    $defects[] = "at {$t} ms {$key($riders[$i])} stands in the cab at ".json_encode([$p['x'], $p['y']]).', off the cab floor the band draws';
                }

                for ($j = $i + 1; $j < count($at); $j++) {
                    $q = $at[$j];

                    if ($q === null || ! $p['standing'] || ! $q['standing']) {
                        continue;
                    }

                    $pairs++;
                    $d = hypot($p['x'] - $q['x'], $p['y'] - $q['y']);

                    if ($d < $apart) {
                        $defects[] = "at {$t} ms {$key($riders[$i])} and {$key($riders[$j])} stand in the cab {$d} px apart, under {$apart} px";
                    }
                }
            }
        }

        foreach ($riders as $w) {
            $cab = $cabOf($w);
            [$from, $to] = $this->legSpan($w, $cab);
            $last = end($w['legs']);

            foreach ([[$from, $cab !== $w['legs'][0]], [$to, $cab !== $last]] as [$edge, $owed]) {
                if (! $owed || $edge - 0.5 < 0) {
                    continue;
                }

                $a = $this->queuedAt($w, $edge - 0.5);
                $b = $this->queuedAt($w, $edge + 0.5);
                $d = hypot($a['x'] - $b['x'], $a['y'] - $b['y']);

                if ($d > 1.5) {
                    $defects[] = "{$key($w)} jumps {$d} px between its {$a['leg']['kind']} leg and its {$b['leg']['kind']} leg at {$edge} ms";
                }
            }
        }

        return [array_values(array_unique($defects)), $pairs];
    }

    /**
     * The cab floor the band draws behind the leaves, re-derived from § 12's *Cab interior* row and the frame's elevator
     * (`scene.band.elevator`) as `drawCabInterior()` projects it from the landing: the room `W × D × H` cm fitted to the
     * doorway, seen from an eye `eye` cm over the floor and `V` cm in front of the open front, so a floor point `Z` cm in
     * is drawn at the scale `s = V ÷ (V + Z)`, `h ÷ H × eye × (1 − s)` px over the doorway's foot and at most
     * `w ÷ 2 × s` px either side of the seam. `bottom` is the foot, `depth` the far wall's foot over it, `half(up)` the
     * floor's half-width that far up.
     *
     * @return array{bottom: float, seam: float, depth: float, half: callable}
     */
    private function cabFloor(array $frame): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab interior\*\* \| \*\*(\d+) × (\d+) × (\d+) cm\*\* wide × deep × high, the eye (\d+) cm over the floor.*?from the landing, the eye (\d+) cm from the cab\'s open front \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Cab interior* row in the form this test reads');
        [, , $D, $H, $eye, $V] = array_map('intval', $m);
        $e = $frame['scene']['band']['elevator'];
        $rise = $e['h'] / $H * $eye;

        return [
            'bottom' => $e['y'] + $e['h'],
            'seam' => $e['x'] + $e['w'] / 2,
            'depth' => $rise * (1 - $V / ($V + $D)),
            'half' => static fn (float $up): float => $e['w'] / 2 * (1 - $up / $rise),
        ];
    }

    /**
     * card#12747 — THE CALL PLATES (`docs/design/FLOOR.md § 6.2`'s walk note item 9): the defects in how the departures a
     * frame draws press the elevator's call plates, and the number of pairs sampled with one of them pressing — every half
     * loop frame over the frame's horizon, and every leg boundary. Each is re-derived from the legs, the frame's band
     * (`scene.band.elevator.calls`, held to § 12's *Call plates* elsewhere) and the widths passed in, never from the
     * holder's queue part.
     *
     * - **Pressed:** every departure stands in a `press` stand — after its walk to it and before it waits — for `$press`
     *   frames (§ 12's *Call press*).
     * - **Its own plate:** a seat presses `main` and an intern (a walk with an `intern_key`) presses `low`; it stands on
     *   the threshold's floor line at `x = max(plate centre, seam + $seatW ÷ 2 + its width ÷ 2 + $margin)`; and the plate's
     *   centre is inside the walker's drawn rect (`size`) standing there — within half its width of its feet, and between
     *   its feet and its height above them.
     * - **One at a time:** while a departure presses, no other departure standing (any `stand` leg) or walking to its
     *   own plate comes within `(its width + the presser's) ÷ 2 + $margin` of the presser's feet. A departure walking
     *   elsewhere passes, as every crossing does (§ 13 row 77).
     *
     * @return array{0: list<string>, 1: int}
     */
    private function callDefects(array $frame, float $seatW, float $margin, int $press): array
    {
        $walks = $this->departures($frame);
        $e = $frame['scene']['band']['elevator'];
        $door = ['x' => $e['seam'], 'y' => $e['y'] + $e['h']];
        $defects = [];
        $instants = [];
        $pressOf = [];

        foreach ($walks as $i => $w) {
            $key = $this->walkerKey($w);
            $at = array_keys(array_filter($w['legs'], static fn (array $l): bool => ($l['why'] ?? null) === 'press'));
            $wait = array_keys(array_filter($w['legs'], static fn (array $l): bool => ($l['why'] ?? null) === 'wait'));

            if (count($at) !== 1) {
                $defects[] = "{$key} departs without pressing a call plate once (".count($at).' press stands)';

                continue;
            }

            $k = $at[0];
            $leg = $w['legs'][$k];
            $pressOf[$i] = $k;

            if ($k === 0 || $w['legs'][$k - 1]['kind'] !== 'walk' || $k > $wait[0]) {
                $defects[] = "{$key} does not walk to its call plate and press it before it waits";
            }

            if ($leg['frames'] !== $press) {
                $defects[] = "{$key} presses for {$leg['frames']} frames, not § 12's *Call press* {$press}";
            }

            $plate = isset($w['intern_key']) ? 'low' : 'main';

            if (($leg['plate'] ?? null) !== $plate) {
                $defects[] = "{$key} presses the ".json_encode($leg['plate'] ?? null)." call plate, not {$plate}";
            }

            $r = $e['calls'][$leg['plate'] ?? $plate] ?? null;

            if ($r === null) {
                $defects[] = "the band draws no {$plate} call plate";

                continue;
            }

            $cx = $r['x'] + $r['w'] / 2;
            $cy = $r['y'] + $r['h'] / 2;
            $owed = ['x' => max($cx, $door['x'] + $seatW / 2 + $w['size']['w'] / 2 + $margin), 'y' => $door['y']];

            if (abs($leg['at']['x'] - $owed['x']) > 0.001 || abs($leg['at']['y'] - $owed['y']) > 0.001) {
                $defects[] = "{$key} presses at ".json_encode($leg['at']).", not at its plate's standing point ".json_encode($owed);
            }

            if (abs($cx - $leg['at']['x']) > $w['size']['w'] / 2 + 0.001 || $cy > $leg['at']['y'] || $cy < $leg['at']['y'] - $w['size']['h']) {
                $defects[] = "{$key}'s call plate centre ".json_encode([$cx, $cy]).' is out of its reach at '.json_encode($leg['at']);
            }

            $end = $w['frames'] * $w['frame_interval_ms'] - $w['elapsed_ms'];

            for ($t = 0.0; $t <= $end; $t += $w['frame_interval_ms'] / 2) {
                $instants[] = $t;
            }

            foreach ($w['legs'] as $l) {
                [$from, $to] = $this->legSpan($w, $l);
                $instants[] = max(0.0, $from);
                $instants[] = max(0.0, $to - 0.001);
            }
        }

        $instants = array_values(array_unique($instants, SORT_REGULAR));
        $pairs = 0;
        // In its press stand at `$t`, or the leg it is in then and whether that is its walk to the plate.
        $legAt = function (array $w, float $t): ?int {
            foreach ($w['legs'] as $n => $l) {
                [$from, $to] = $this->legSpan($w, $l);

                if ($from <= $t && $t < $to) {
                    return $n;
                }
            }

            return null;
        };

        foreach ($instants as $t) {
            foreach ($pressOf as $i => $k) {
                if ($legAt($walks[$i], $t) !== $k) {
                    continue;
                }

                $a = $this->queuedAt($walks[$i], $t);

                foreach ($walks as $j => $other) {
                    if ($j === $i) {
                        continue;
                    }

                    $n = $legAt($other, $t);
                    $leg = $n === null ? null : $other['legs'][$n];
                    $toPlate = $n !== null && isset($pressOf[$j]) && $n === $pressOf[$j] - 1;

                    if ($leg === null || ($leg['kind'] !== 'stand' && ! $toPlate)) {
                        continue;
                    }

                    $pairs++;
                    $b = $this->queuedAt($other, $t);
                    $d = hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
                    $clear = ($walks[$i]['size']['w'] + $other['size']['w']) / 2 + $margin;

                    if ($d < $clear - 0.001) {
                        $defects[] = "at {$t} ms {$this->walkerKey($other)} ".($toPlate ? 'walks to its plate' : "stands ({$leg['why']})")
                            ." {$d} px from {$this->walkerKey($walks[$i])} pressing its call plate, under {$clear} px";
                    }
                }
            }
        }

        return [array_values(array_unique($defects)), $pairs];
    }

    /**
     * card#12793 — THE CLEAR LANE (`docs/design/FLOOR.md § 6.2`'s walk note item 9, § 12's *Elevator lane*): the defects in
     * how the walkers a frame draws keep the strip in front of the leaves clear, against `$lane` — the TEST's lane,
     * re-derived from the band and § 12 (`elevatorLaneOf()`), never the one the scene hands the holder — and the number of
     * rider–boarder pairs sampled — every half loop frame over the frame's horizon, and every leg boundary.
     *
     * - **Nobody waits in it:** every departure's `wait` stand is outside the lane — its edges and the threshold, on
     *   its top edge, included in it.
     * - **A rider steps out first:** while a RIDER's feet (a walk whose first leg is its `cab` and that walks out onto
     *   the floor: an arrival, or a walker turned back out of the cab) are on the floor inside the lane, no BOARDER's
     *   feet (a departure, from the instant it leaves its spot) are on the floor inside it — unless it had left its spot before the
     *   rider's walk began: a walker gliding to the door when the leaves open on a rider keeps its glide (the committed
     *   past outranks the lane, § 6.2's walk note item 9).
     *
     * Feet are placed by the legs, as `queuedAt()` places them; a walker in its `cab` leg is inside the cab, off the floor.
     *
     * @param  array{x: float, y: float, w: float, h: float}  $lane
     * @return array{0: list<string>, 1: int}
     */
    private function laneDefects(array $frame, array $lane): array
    {
        $in = static fn (array $p): bool => $p['x'] >= $lane['x'] - 0.001 && $p['x'] <= $lane['x'] + $lane['w'] + 0.001
            && $p['y'] >= $lane['y'] - 0.001 && $p['y'] <= $lane['y'] + $lane['h'] + 0.001;
        $walks = $this->departures($frame);
        $defects = [];

        foreach ($walks as $w) {
            foreach ($w['legs'] as $l) {
                if (($l['why'] ?? null) === 'wait' && $in($l['at'])) {
                    $defects[] = "{$this->walkerKey($w)} waits at ".json_encode([$l['at']['x'], $l['at']['y']]).' inside the lane in front of the doors';
                }
            }
        }

        $riders = array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => ($e['frames'] ?? 0) > 0
            && ($e['legs'][0]['kind'] ?? null) === 'cab' && array_filter($e['legs'], static fn (array $l): bool => $l['kind'] === 'walk') !== []));
        $onFloorIn = function (array $w, float $t) use ($in): bool {
            $p = $this->queuedAt($w, $t);

            return $p['leg']['kind'] !== 'cab' && $in($p);
        };
        $pairs = 0;

        foreach ($riders as $r) {
            // When the rider's walk began, in ms from this frame's instant: a boarder that left its spot before then is
            // the committed past.
            $began = -$r['elapsed_ms'];
            $instants = [];
            $end = $r['frames'] * $r['frame_interval_ms'] - $r['elapsed_ms'];

            for ($t = 0.0; $t <= $end; $t += $r['frame_interval_ms'] / 2) {
                $instants[] = $t;
            }

            foreach ($walks as $b) {
                $wait = array_values(array_filter($b['legs'], static fn (array $l): bool => ($l['why'] ?? null) === 'wait'))[0] ?? null;

                if ($wait === null || $this->legSpan($b, $wait)[1] < $began - 0.001) {
                    continue;
                }

                $ts = $instants;

                foreach ([...$r['legs'], ...$b['legs']] as $l) {
                    foreach ($this->legSpan(in_array($l, $r['legs'], true) ? $r : $b, $l) as $edge) {
                        $ts[] = max(0.0, $edge);
                        $ts[] = max(0.0, $edge - 0.001);
                    }
                }

                $leaves = $this->legSpan($b, $wait)[1];

                foreach (array_unique($ts, SORT_REGULAR) as $t) {
                    // A boarder is read from the instant it leaves its spot: its way to the plates and to its spot is
                    // walking past, not boarding.
                    if ($t > $end || $t < $leaves) {
                        continue;
                    }

                    $pairs++;

                    if ($onFloorIn($r, $t) && $onFloorIn($b, $t)) {
                        $defects[] = "at {$t} ms {$this->walkerKey($b)} walks in the lane while {$this->walkerKey($r)}, stepping out of the cab, is still in it";
                    }
                }
            }
        }

        return [array_values(array_unique($defects)), $pairs];
    }

    /**
     * § 12's *Elevator lane*, re-derived for a frame from its band and the widths passed in: the leaves' span or the seat
     * body plus both *Walker clearance* side margins, whichever is wider, centred on the seam, from the threshold's floor
     * line down the seat body's drawn height.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function elevatorLaneOf(array $frame, array $body, float $margin): array
    {
        $e = $frame['scene']['band']['elevator'];
        $w = max($e['w'], $body['w'] + 2 * $margin);

        return ['x' => $e['seam'] - $w / 2, 'y' => $e['y'] + $e['h'], 'w' => $w, 'h' => $body['h']];
    }

    /** § 12's *Call press*, in loop frames. */
    private function callPress(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Call press\*\* \| \*\*(\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Call press* row in the form this test reads');

        return (int) $m[1];
    }

    /** § 12's *Walker clearance* side margin, px. */
    private function clearanceMargin(): float
    {
        $this->assertSame(1, preg_match('/^\| \*\*Walker clearance\*\* \| \*\*(\d+) px beside;/m', $this->floorMd(), $m),
            '§ 12 carries no *Walker clearance* row in the form this test reads');

        return (float) $m[1];
    }

    /** § 12's *Cab spots*: the least distance between two riders' feet standing in the cab together. */
    private function cabApart(): float
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab spots\*\* \| \*\*.*? (\d+) px apart/m', $this->floorMd(), $m),
            '§ 12 carries no *Cab spots* row in the form this test reads');

        return (float) $m[1];
    }
}

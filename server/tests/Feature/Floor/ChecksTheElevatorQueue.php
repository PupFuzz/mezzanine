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
                $ready = $k === 0 ? ($shared === [] ? $from : null) : $boarders[$k - 1]['in'] + $door['step'] * $I;
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

            if ($boarders !== [] && $shared === []) {
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

    /** § 12's *Cab spots*: the least distance between two riders' feet standing in the cab together. */
    private function cabApart(): float
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab spots\*\* \| \*\*.*? (\d+) px apart/m', $this->floorMd(), $m),
            '§ 12 carries no *Cab spots* row in the form this test reads');

        return (float) $m[1];
    }
}

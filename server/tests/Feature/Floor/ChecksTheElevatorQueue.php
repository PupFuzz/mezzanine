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
     * TOGETHER: the defects in how the departures a frame draws board, against every opening of the leaves it draws
     * (`scene.doors`, frames from the frame's instant), and the most departures any one opening boards.
     *
     * @param  array{open: int, step: int, close: int}  $door  § 12's *Elevator leaves*
     * @return array{0: list<string>, 1: int}
     */
    private function boardingDefects(array $frame, array $door): array
    {
        $walks = $this->departures($frame);
        $defects = [];
        $most = 0;

        foreach ($frame['scene']['doors'] as $span) {
            $I = $walks[0]['frame_interval_ms'] ?? 250;
            [$from, $to] = [$span['from'] * $I, $span['to'] * $I];
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

            // Nearest the door first: one waiting ON the threshold — its wait where its cab is — as the leaves begin to
            // open is in the doorway, and steps in before every other boarder.
            foreach ($boarders as $k => $b) {
                $legs = $b['walk']['legs'];
                $wait = array_values(array_filter($legs, static fn ($l) => ($l['why'] ?? null) === 'wait'))[0];
                $cab = array_values(array_filter($legs, static fn ($l) => $l['kind'] === 'cab'))[0];

                if ($k > 0 && $wait['at'] == $cab['at'] && $this->waitingAt($b['walk'], $from - 0.001)) {
                    $defects[] = "{$b['key']} waited on the threshold as the leaves opened at {$from} ms and stepped in after {$boarders[0]['key']}";
                }
            }

            foreach ($boarders as $k => $b) {
                // When it may leave its spot: as the leaves begin to open, or once the boarder before it has stepped in.
                // One still waiting then leaves then — within the frame a walk begun between two loop frames rounds up by.
                $ready = $k === 0 ? ($shared === [] ? $from : null) : $boarders[$k - 1]['in'] + $door['step'] * $I;

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
}

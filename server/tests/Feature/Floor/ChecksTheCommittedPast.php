<?php

namespace Tests\Feature\Floor;

/**
 * card#12466 — THE COMMITTED PAST, read between two consecutive drawn frames of a run (`docs/design/FLOOR.md § 6.2`'s walk
 * note item 6a): what the earlier frame drew is what the viewer saw, so the later frame must continue it. Re-derived here
 * from the legs and door spans each frame's scene carries and § 12's own rows, never from the holder that planned them.
 *
 * - **The walker stays where it is drawn** (`walkerDefects()`): each seat walk of the earlier frame, placed by its own
 *   legs at the later frame's instant, stands where the later frame's own legs place that seat's walker (within 1.5 px) —
 *   or the later frame draws none and the hand-over is lawful: the walker was at its desk's anchor (it sat), or inside
 *   the cab with the leaves shut (it has gone, and nobody saw it go).
 * - **The leaves keep their motion** (`leafDefects()`): the elevator's leaves, read off every walk's spans at their exact
 *   elapsed instants, are as open by the earlier frame's spans as by the later frame's.
 * - **One walk per seat** (`oneWalkDefects()`).
 *
 * Used by AT-D3-36 (`AMainAgentTurnsBackToItsDeskTest`), over its runs and its seeded fuzz set, and by AT-D3-23
 * (`ASeatLeavesByTheElevatorAndReturnsByItTest`), over its queue runs. A walker is placed by `ChecksTheElevatorQueue`'s
 * `queuedAt()`, the queue checks' own reader of a walk's legs, so both sets of checks place a walker one way.
 */
trait ChecksTheCommittedPast
{
    /** Where a walker is `$t` ms from a frame's instant: `ChecksTheElevatorQueue::queuedAt()`. */
    abstract private function queuedAt(array $walk, float $t): array;

    /** A run's drawn walks held still across renders: `ChecksTheElevatorQueue::continuityDefects()`. */
    abstract private function continuityDefects(array $drawn, bool $byIntern = false): array;

    /** The seat walks a frame draws: A1 and A2 effects with frames to run. @return list<array<string, mixed>> */
    private function pastWalks(array $frame, ?string $key = null): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], fn (array $e): bool => in_array($e['animation_id'], ['A1', 'A2'], true)
            && ($e['frames'] ?? 0) > 0 && ($key === null || $this->pastKey($e) === $key)));
    }

    private function pastKey(array $e): string
    {
        return $e['seat_id'];
    }

    /** § 12's *Elevator leaves*. @return array{open: int, step: int, close: int} */
    private function pastDoor(): array
    {
        static $door = null;

        if ($door === null) {
            $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m),
                '§ 12 carries no *Elevator leaves* row in the form this check reads');
            $door = ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
        }

        return $door;
    }

    /**
     * THE WALKER STAYS WHERE IT IS DRAWN, over a run's drawn frames (each `{at, trigger, frame}`, in order).
     *
     * @return array{0: list<string>, 1: int} the defects, and the comparisons made
     */
    private function walkerDefects(array $drawn): array
    {
        $defects = [];
        $compared = 0;

        for ($i = 1; $i < count($drawn); $i++) {
            [$a, $b] = [$drawn[$i - 1], $drawn[$i]];
            $dt = $b['at'] - $a['at'];

            foreach ($this->pastWalks($a['frame']) as $w) {
                $key = $this->pastKey($w);

                // Its end, read as the holder ends it: at the whole millisecond (`floor/in-flight.js`'s `#endAt()`).
                if ($dt >= round($w['frames'] * $w['frame_interval_ms']) - $w['elapsed_ms']) {
                    // It has ended by the later frame: it must hand the figure over where the floor then draws it — an
                    // A1 at its desk's anchor (it sits), an A2 inside the cab (it has ridden away) — or it teleported.
                    $last = end($w['legs']);
                    $anchor = $b['frame']['scene']['anchors'][$key] ?? null;
                    $handed = $w['animation_id'] === 'A2'
                        ? $last['kind'] === 'cab'
                        : $last['kind'] === 'walk' && $anchor !== null && hypot(end($last['points'])['x'] - $anchor['x'], end($last['points'])['y'] - $anchor['y']) <= 1.5;

                    if (! $handed && $this->pastWalks($b['frame'], $key) === []) {
                        $compared++;
                        $defects[] = "at {$b['at']} ms {$key}'s {$w['animation_id']} ended in its {$last['kind']} leg, not ".($w['animation_id'] === 'A2' ? 'in the cab' : 'at its desk');
                    }

                    continue;
                }

                $was = $this->queuedAt($w, $dt);
                $now = $this->pastWalks($b['frame'], $key);
                $compared++;

                if ($now === []) {
                    $anchor = $b['frame']['scene']['anchors'][$key] ?? null;
                    $seated = $anchor !== null && hypot($was['x'] - $anchor['x'], $was['y'] - $anchor['y']) <= 1.5;
                    // Inside the cab behind shut leaves the figure is out of sight: a turn out of it has gone (card#12466).
                    $gone = $was['leg']['kind'] === 'cab' && $this->leafLevel($a['frame'], $dt) === 0.0;

                    if (! $seated && ! $gone) {
                        $defects[] = "at {$b['at']} ms {$key} vanished from its {$was['leg']['kind']} leg at ({$was['x']}, {$was['y']})";
                    }

                    continue;
                }

                $is = $this->queuedAt($now[0], 0);
                $d = hypot($was['x'] - $is['x'], $was['y'] - $is['y']);

                if ($d > 1.5) {
                    $defects[] = "at {$b['at']} ms {$key} jumped {$d} px (was in its {$was['leg']['kind']} leg, now in its {$is['leg']['kind']})";
                }
            }
        }

        return [$defects, $compared];
    }

    /**
     * How open the elevator's leaves are `$t` ms after a frame's instant, 0 shut to 1 open: every walk's door spans — its
     * `door` and the `door_rest` a turn-round carries — at their exact elapsed instants, merged where they meet as the
     * holder's `doors()` merges them; inside a span the leaves open over § 12's opening frames from its start and close over
     * its closing frames to its end.
     */
    private function leafLevel(array $frame, float $t): float
    {
        $d = $this->pastDoor();
        $spans = [];

        foreach ($frame['scene']['effects'] ?? [] as $e) {
            if (($e['frames'] ?? 0) <= 0) {
                continue;
            }

            $I = $e['frame_interval_ms'];

            foreach (['door_rest', 'door'] as $f) {
                if (is_array($e[$f] ?? null)) {
                    $spans[] = [$e[$f]['start'] * $I - $e['elapsed_ms'], ($e[$f]['start'] + $e[$f]['frames']) * $I - $e['elapsed_ms'], $I];
                }
            }
        }

        usort($spans, static fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($spans as $s) {
            $last = count($merged) - 1;

            if ($last >= 0 && $s[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $s[1]);
            } else {
                $merged[] = $s;
            }
        }

        foreach ($merged as [$from, $to, $I]) {
            if ($t >= $from && $t < $to) {
                $open = min(1, ($t - $from) / ($d['open'] * $I));
                $closing = $to - $d['close'] * $I;

                return (float) ($t < $closing ? $open : min($open, 1 - ($t - $closing) / ($d['close'] * $I)));
            }
        }

        return 0.0;
    }

    /** THE LEAVES KEEP THE MOTION DRAWN: between consecutive frames, the leaves as open by the earlier as by the later. */
    private function leafDefects(array $drawn): array
    {
        $defects = [];

        for ($i = 1; $i < count($drawn); $i++) {
            [$a, $b] = [$drawn[$i - 1], $drawn[$i]];
            $was = $this->leafLevel($a['frame'], $b['at'] - $a['at']);
            $is = $this->leafLevel($b['frame'], 0);

            if (abs($was - $is) > 0.01) {
                $defects[] = "at {$b['at']} ms the leaves jumped from {$was} open to {$is}";
            }
        }

        return $defects;
    }

    /** At every drawn frame, at most one walk per seat. */
    private function oneWalkDefects(array $drawn): array
    {
        $defects = [];

        foreach ($drawn as $r) {
            $keys = array_map(fn (array $w): string => $this->pastKey($w), $this->pastWalks($r['frame']));

            foreach (array_count_values($keys) as $key => $n) {
                if ($n > 1) {
                    $defects[] = "at {$r['at']} ms {$key} has {$n} walks";
                }
            }
        }

        return $defects;
    }

    /**
     * Every committed-past defect of a run's drawn frames — the three above and, for interns, `ChecksTheElevatorQueue`'s
     * continuity read by intern key (item 16's turn-round held to start where the arrival stood) — and the comparisons made.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function committedPastDefects(array $drawn): array
    {
        [$walkers, $n] = $this->walkerDefects($drawn);
        [$interns, $m] = $this->continuityDefects($drawn, true);

        return [[...$walkers, ...$this->leafDefects($drawn), ...$this->oneWalkDefects($drawn), ...$interns], $n + $m];
    }
}

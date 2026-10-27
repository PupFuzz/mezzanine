<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **A coordination bird glides to its destination** — card#12345, the operator's two readings on the sandbox,
 * 2026-10-25: *"It is a little too fast and is not smooth (the bird jumps locations)"*, and *"When a bird goes to
 * the bird house, it disappears before it reaches the birdhouse."* `docs/design/FLOOR.md` § 6.1 (what a flight
 * may mean), § 6.2's A19 and A22 rows and § 12's *Envelope speed* row. **And the portal bird waits on the ledge** —
 * card#12445, the operator, 2026-10-26: *"When the bird reaches the birdhouse, it should stop there on the birdhouse
 * ledge for a second before hopping into the hole. When it arrives, it should come out of the hole and wait on the
 * ledge for 1 second before flying."* — § 12's *Ledge dwell* and *Hole hop* rows: going out it flies to the perch,
 * stands there for the dwell and hops into the hole; coming in it hops out onto the perch, stands there for the dwell
 * and flies; its feet are on the peg; and the ledge holds one bird (`EffectsInFlight`, read through `ledge-probe.mjs`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ TWO HALVES, EACH READ WHERE IT IS DECIDED. The SCENE decides a flight's ends and its frames (`travel()`,
 * `scene.js`): read off `frame.scene.effects` over `fx-coord`'s `coord_portal` run — A22's departure into this
 * floor's birdhouse, its arrival from it, and two A19 travellers. The PAINTER decides where on that arc the bird
 * is drawn at each instant: read through `bird-paint-probe.mjs`, which paints each of the run's flights twice
 * over a fake DOM, and modelled here from the CSS the painter wrote — its timing function, its keyframes, its
 * duration, its delay and the start it pinned. A timing function the model does not know is a defect, never read
 * as linear, and so is a flight the painter did not pin.
 *
 * ⚠ WHAT THIS DOES NOT SEE: when a real browser starts a rebuilt animation, which is what made the live bird jump
 * at every repaint even between frames. Here a pin is required and its value checked against the flight's own
 * start; what a real engine then draws is `tools/design/floor-ride.browser.mjs`'s `bird-flight` row, which samples
 * the drawn bird on every animation frame across repaints.
 */
class ABirdFliesToItsDestinationTest extends TestCase
{
    use DrivesTheScene;

    private const RUN = 'coord_portal';

    private const SCENE = '../floor/scene.js';

    private const PAINTER = '../floor/painter.js';

    /**
     * The two paints of every flight: the second a repaint 480 ms on, neither on a frame boundary. Each flight started
     * at 0 on the page's clock, so at the first paint its own start on the timeline is `timeline_ms - clock_ms`.
     * `frame_ms` is `document.timeline.currentTime`, the last frame's time, trailing the live timeline as it does at
     * the end of a long task; and between the paints the page's clock steps 40 ms ahead of the timeline (a wall-clock
     * adjustment), so a start re-derived at the repaint is not the flight's start.
     */
    private const PAINTS = [
        ['timeline_ms' => 10000, 'frame_ms' => 9950, 'clock_ms' => 130, 'elapsed_ms' => 130],
        ['timeline_ms' => 10480, 'frame_ms' => 10470, 'clock_ms' => 650, 'elapsed_ms' => 610],
    ];

    /** § 12's *Loop frame rate*, frames per second. */
    private function loopFps(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Loop frame rate\*\* \| \*\*(\d+) fps\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Loop frame rate* row in the form this test reads');

        return (int) $m[1];
    }

    /** § 12's *Ledge dwell*, in loop frames: its seconds at § 12's rate. */
    private function dwellFrames(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Ledge dwell\*\* \| \*\*(\d+) s\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Ledge dwell* row in the form this test reads');

        return (int) $m[1] * $this->loopFps();
    }

    /** § 12's *Hole hop*, in loop frames. */
    private function hopFrames(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Hole hop\*\* \| \*\*(\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Hole hop* row in the form this test reads');

        return (int) $m[1];
    }

    /** § 12's *Envelope speed*, read out of the document. */
    private function envelopeSpeed(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Envelope speed\*\* \| \*\*(\d+) px per frame\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Envelope speed* row in the form this test reads');

        return (int) $m[1];
    }

    public function test_green_section_12s_envelope_speed_is_the_scenes_constant(): void
    {
        $source = (string) file_get_contents($this->moduleDir().'/'.self::SCENE);

        $this->assertSame(1, preg_match('/^const ENVELOPE_PX_PER_FRAME = (\d+);$/m', $source, $m));
        $this->assertSame($this->envelopeSpeed(), (int) $m[1], '§ 12\'s *Envelope speed* is not scene.js\'s ENVELOPE_PX_PER_FRAME');
    }

    /** card#12445: § 12's *Ledge dwell* and *Hole hop* are the scene's constants. */
    public function test_green_section_12s_ledge_dwell_and_hole_hop_are_the_scenes_constants(): void
    {
        $source = (string) file_get_contents($this->moduleDir().'/'.self::SCENE);

        $this->assertSame(1, preg_match('/^export const LEDGE_DWELL_S = (\d+);$/m', $source, $d));
        $this->assertSame(1, preg_match('/^export const LEDGE_DWELL_FRAMES = LEDGE_DWELL_S \* LOOP_FPS;$/m', $source));
        $this->assertSame(1, preg_match('/^export const HOLE_HOP_FRAMES = (\d+);$/m', $source, $h));
        $this->assertSame($this->dwellFrames(), (int) $d[1] * $this->loopFps(), '§ 12\'s *Ledge dwell* is not scene.js\'s LEDGE_DWELL_S');
        $this->assertSame($this->hopFrames(), (int) $h[1], '§ 12\'s *Hole hop* is not scene.js\'s HOLE_HOP_FRAMES');
    }

    public function test_every_flight_runs_from_its_start_to_its_destination_at_section_12s_speed(): void
    {
        $this->assertSame([], $this->sceneDefects($this->floorRun(self::RUN)));
    }

    /** § 6.4: the still envelope is shown for the moving form's own duration — its flight at § 12's speed, and its rest. */
    public function test_under_reduced_motion_the_still_envelope_lasts_the_moving_forms_duration(): void
    {
        $moving = $this->firstFlights($this->floorRun(self::RUN));
        $still = $this->firstFlights($this->floorRun(self::RUN, null, ['reduce' => true]));

        $this->assertSame(array_keys($moving), array_keys($still), 'the reduced run drew other flights than the moving one');

        foreach ($still as $key => $fx) {
            $perch = $moving[$key]['perch'];
            // card#12445: the portal bird's moving form includes its ledge dwell and its hop.
            $ledge = $perch === null ? 0 : $perch['dwell_frames'] + $perch['hop_frames'];

            $this->assertSame(0, $fx['frames'], "{$key} moves under reduced motion");
            $this->assertNull($fx['perch'], "{$key} stands on the ledge under reduced motion");
            $this->assertSame($moving[$key]['frames'] + $moving[$key]['rest_frames'] + $ledge, $fx['still_frames'],
                "{$key}'s still envelope is not shown for its moving form's flight, rest, ledge dwell and hop");
        }
    }

    /**
     * card#12445: THE TIMELINE, AS THE SCENE DECIDES IT. Going out: the flight, then the bird stands on the perch for § 12's
     * *Ledge dwell*, then hops into the hole for § 12's *Hole hop* — each part starting on the frame the one before it
     * ends. Coming in: the hop out of the hole, the dwell, then the flight and the envelope's rest. A19's traveller never
     * meets the birdhouse. The whole moving form is the sum of its parts.
     */
    public function test_the_portal_bird_stands_on_the_ledge_for_the_dwell_then_goes_in_and_the_reverse_coming_out(): void
    {
        $this->assertSame([], $this->timelineDefects($this->floorRun(self::RUN)));
    }

    /**
     * card#12445: THE TIMELINE, AS THE PAINTER DRAWS IT, from what it wrote: the standing bird is drawn on the ledge for
     * exactly the dwell, the flight ends where it begins (going out) and starts where it ends (coming in), the hop runs
     * into the hole going out and out of it coming in — and the standing bird's feet are on the peg's tip, under the hole.
     */
    public function test_the_painted_bird_stands_on_the_peg_for_the_dwell_and_hops_through_the_hole(): void
    {
        $result = $this->floorRun(self::RUN);

        $this->assertSame([], $this->ledgeDefects($this->paint($result), $result));
    }

    /**
     * card#12445: THE LEDGE HOLDS ONE BIRD. Two portal birds whose ledge parts would meet: the second waits the fewest
     * whole frames that clear the first's, so the two are never drawn on the perch together; a bird that meets no other
     * waits nothing.
     */
    public function test_a_second_bird_waits_for_the_ledge_rather_than_standing_on_the_first(): void
    {
        $this->assertSame([], $this->queueDefects($this->queue($this->floorRun(self::RUN))));
    }

    public function test_the_painted_bird_is_drawn_from_its_start_to_its_destination_and_across_a_repaint(): void
    {
        $this->assertSame([], $this->paintDefects($this->paint($this->floorRun(self::RUN))));
    }

    /** ⛔ THE CONTROLS — each plants its defect in the shipped tree and is seen to red the check it exists for. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $fast = $this->mutatedModules([self::SCENE, 'const ENVELOPE_PX_PER_FRAME = 48;', 'const ENVELOPE_PX_PER_FRAME = 96;']);
        $this->assertNotSame([], $this->sceneDefects($this->floorRun(self::RUN, $fast)), 'CONTROL (the bird at its old speed) did not bite');

        $short = $this->mutatedModules([self::SCENE, 'travel(base, from, ledge, moving, 0, ', 'travel(base, from, { x: ledge.x, y: ledge.y + 40 }, moving, 0, ']);
        $this->assertNotSame([], $this->sceneDefects($this->floorRun(self::RUN, $short)), 'CONTROL (a departure that stops under the perch) did not bite');

        $outside = $this->mutatedModules([self::SCENE, 'travel(base, ledge, to, moving, ARRIVAL_REST_FRAMES, ', 'travel(base, { x: ledge.x + 40, y: ledge.y }, to, moving, ARRIVAL_REST_FRAMES, ']);
        $this->assertNotSame([], $this->sceneDefects($this->floorRun(self::RUN, $outside)), 'CONTROL (an arrival that starts beside the perch) did not bite');

        $beside = $this->mutatedModules([self::SCENE, 'const hole = Object.freeze({ x: mouth.x, y: mouth.y });', 'const hole = Object.freeze({ x: mouth.x + 30, y: mouth.y });']);
        $this->assertNotSame([], $this->sceneDefects($this->floorRun(self::RUN, $beside)), 'CONTROL (a hop into the wall beside the hole) did not bite');

        // card#12445's timeline: the dev tree's bird went straight into the hole and straight out of it.
        $noDwell = $this->mutatedModules([self::SCENE, '        dwell_frames: LEDGE_DWELL_FRAMES,', '        dwell_frames: 0,']);
        $this->assertNotSame([], $this->timelineDefects($this->floorRun(self::RUN, $noDwell)), 'CONTROL (no ledge dwell) did not bite');

        $order = $this->mutatedModules([self::SCENE, '        hop_start: out ? flight + LEDGE_DWELL_FRAMES : 0,', '        hop_start: out ? flight : 0,']);
        $this->assertNotSame([], $this->timelineDefects($this->floorRun(self::RUN, $order)), 'CONTROL (going in before the dwell ends) did not bite');

        $early = $this->mutatedModules([self::SCENE, '        flight_start: moving && portal !== null && !out ? atPerch : 0,', '        flight_start: 0,']);
        $this->assertNotSame([], $this->timelineDefects($this->floorRun(self::RUN, $early)), 'CONTROL (flying off while it still stands on the ledge) did not bite');

        $floating = $this->mutatedModules(['../floor/bird.js', 'y: perch.tip + BIRD_LIFT - FOOT_Y }', 'y: perch.tip + BIRD_LIFT - FOOT_Y - 6 }']);
        $floated = $this->floorRun(self::RUN, $floating);
        $this->assertNotSame([], $this->ledgeDefects($this->paint($floated, $floating), $floated), 'CONTROL (a bird standing 6 px above the peg) did not bite');

        $painted = $this->floorRun(self::RUN);
        $late = $this->mutatedModules([self::PAINTER, 'animation-delay:${at(p.dwell_start)}s', 'animation-delay:${at(p.dwell_start + 1)}s']);
        $this->assertNotSame([], $this->ledgeDefects($this->paint($painted, $late), $painted), 'CONTROL (a painter drawing the dwell a frame late) did not bite');

        $stacked = $this->mutatedModules(['../floor/in-flight.js', '? this.#onTheLedge(effect, now) : effect;', '? effect : effect;']);
        $this->assertNotSame([], $this->queueDefects($this->queue($painted, $stacked)), 'CONTROL (two birds on the ledge at once) did not bite');

        $flights = $this->floorRun(self::RUN);

        // The form the card replaced: a flight in `steps(frames)`, whose last drawn point is one step short.
        $stepped = $this->mutatedModules([self::PAINTER,
            'style: `animation-duration:${seconds}s;animation-delay:${at(fx.flight_start)}s;offset-path',
            'style: `animation-duration:${seconds}s;animation-timing-function:steps(${fx.frames});animation-delay:${at(fx.flight_start)}s;offset-path']);
        $this->assertNotSame([], $this->paintDefects($this->paint($flights, $stepped)), 'CONTROL (the stepped flight) did not bite');

        // The delay in whole frames: a repaint mid-frame draws the bird back at its frame's start.
        $snapped = $this->mutatedModules([self::PAINTER, 'const flown = fx.elapsed_ms / 1000;', 'const flown = (fx.elapsed_frames * fx.frame_interval_ms) / 1000;']);
        $this->assertNotSame([], $this->paintDefects($this->paint($flights, $snapped)), 'CONTROL (the whole-frame delay) did not bite');

        // A timing function the model below does not know is a defect, never read as linear.
        $eased = $this->mutatedModules([self::PAINTER, 'animation-name:fly;animation-timing-function:linear}', 'animation-name:fly;animation-timing-function:ease-in}']);
        $this->assertNotSame([], $this->paintDefects($this->paint($flights, $eased)), 'CONTROL (an unmodelled timing function) did not bite');

        // A painter that pins nothing: every node's start is the browser's, which a rebuild moves.
        $unpinned = $this->mutatedModules([self::PAINTER, 'animation.startTime = entry.began + entry.elapsed_ms;', '']);
        $this->assertNotSame([], $this->paintDefects($this->paint($flights, $unpinned)), 'CONTROL (no pinned start) did not bite');

        // A start re-derived at every paint instead of recorded at the flight's first: a clock step moves it.
        $perPaint = $this->mutatedModules([self::PAINTER, 'origins.get(entry.key) ?? starts.get(entry.key) ?? ', '']);
        $this->assertNotSame([], $this->paintDefects($this->paint($flights, $perPaint)), 'CONTROL (a start per paint) did not bite');

        // The start dated from the last frame's time: the flight starts before its render, by however long the task ran.
        $stale = $this->mutatedModules([self::PAINTER, 'performance.now() - (clock.now() - entry.started_at)', 'document.timeline.currentTime - entry.elapsed_ms']);
        $this->assertNotSame([], $this->paintDefects($this->paint($flights, $stale)), 'CONTROL (the start from document.timeline.currentTime) did not bite');
    }

    /**
     * A browser with no `Element.getAnimations()`, or a timeline with no time, still gets its bird: the painter skips
     * the pin and keeps the negative delay (card#12345's review, F4). The control drops that guard: with no
     * `getAnimations()` the paint then throws, and with no timeline time it pins a start anyway.
     */
    public function test_a_browser_that_cannot_pin_still_draws_the_bird(): void
    {
        $effects = $this->effects($this->floorRun(self::RUN));
        $unguarded = $this->mutatedModules([self::PAINTER, "if (typeof host.getAnimations !== 'function' || !Number.isFinite(document.timeline?.currentTime)) {", 'if (false) {']);

        foreach (['no-get-animations', 'no-timeline'] as $degraded) {
            $payload = ['effects' => $effects, 'paints' => self::PAINTS, 'degraded' => $degraded];
            $out = $this->probe($payload, dirname($this->moduleDir()), __DIR__.'/bird-paint-probe.mjs');

            foreach ($out['flights'] as $f) {
                foreach ($f['paints'] as $i => $p) {
                    $this->assertNotNull($p['bird'], "{$degraded}: paint {$i} of {$f['animation_id']} {$f['cause']} drew no bird");
                    $this->assertNull($p['bird']['start'], "{$degraded}: paint {$i} of {$f['animation_id']} {$f['cause']} pinned a start");
                }
            }

            [$status, $stdout] = $this->runProbe($payload, dirname($unguarded), __DIR__.'/bird-paint-probe.mjs');
            $bit = $degraded === 'no-get-animations'
                ? $status !== 0
                : $status === 0 && in_array(false, array_map(static fn (array $f) => $f['paints'][0]['bird']['start'] === null, json_decode($stdout, true)['flights']), true);
            $this->assertTrue($bit, "CONTROL ({$degraded} with the guard removed) did not bite");
        }
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /**
     * Each flight's first drawing (one per post, leg and destination), keyed so a run's two forms pair up.
     *
     * @return array<string, array<string, mixed>>
     */
    private function firstFlights(array $result): array
    {
        $out = [];

        foreach ($result['floor_renders'] as $render) {
            $scene = $render['frame']['scene'] ?? null;

            foreach ($scene['effects'] ?? [] as $fx) {
                if (! in_array($fx['animation_id'], ['A19', 'A22'], true)) {
                    continue;
                }

                $key = "{$fx['animation_id']} {$fx['cause']} ".($fx['leg'] ?? '-')." {$fx['to']['x']},{$fx['to']['y']}";
                $out[$key] ??= $fx + ['scene' => ['anchors' => $scene['anchors'], 'mouth' => $scene['band']['portal']['mouth'] ?? null, 'perch' => $scene['band']['portal']['perch'] ?? null]];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The scene's half: each flight's ends — the desks, and for A22 the bird's place on the birdhouse's perch, straight
     * under the hole, which its hop runs to and from — and its frames.
     */
    private function sceneDefects(array $result): array
    {
        $speed = $this->envelopeSpeed();
        $flights = $this->firstFlights($result);
        $defects = [];
        $legs = [];

        $this->assertNotSame([], $flights, 'the run drew no flight — the check read nothing');

        foreach ($flights as $key => $fx) {
            $anchors = array_map(static fn (array $a): array => [$a['x'], $a['y']], $fx['scene']['anchors']);
            $mouth = $fx['scene']['mouth'] === null ? null : [$fx['scene']['mouth']['x'], $fx['scene']['mouth']['y']];
            $from = [$fx['from']['x'], $fx['from']['y']];
            $to = [$fx['to']['x'], $fx['to']['y']];
            $legs[] = $fx['animation_id'] === 'A22' ? $fx['leg'] : 'A19';
            [$start, $end] = match ($fx['animation_id'] === 'A22' ? $fx['leg'] : 'A19') {
                'departure' => ['a desk', 'the perch'],
                'arrival' => ['the perch', 'a desk'],
                default => ['a desk', 'a desk'],
            };
            $perch = $fx['perch'] === null ? null : [$fx['perch']['at']['x'], $fx['perch']['at']['y']];

            if ($fx['animation_id'] === 'A22') {
                // card#12445: the place it stands is straight under the hole, and its hop runs to and from the hole.
                if ($perch === null || $mouth === null || $perch[0] !== $mouth[0] || $perch[1] <= $mouth[1]) {
                    $defects[] = "{$key} stands at ".json_encode($perch).', not under the hole at '.json_encode($mouth);
                } elseif ([$fx['perch']['hole']['x'], $fx['perch']['hole']['y']] !== $mouth) {
                    $defects[] = "{$key} hops to ".json_encode($fx['perch']['hole']).', not the hole at '.json_encode($mouth);
                }
            } elseif ($fx['perch'] !== null) {
                $defects[] = "{$key}: an A19 traveller stands on the birdhouse's perch";
            }

            foreach ([[$start, $from, 'starts'], [$end, $to, 'ends']] as [$where, $point, $verb]) {
                $ok = $where === 'the perch' ? $perch !== null && $point === $perch : in_array($point, $anchors, true);

                if (! $ok) {
                    $defects[] = "{$key} {$verb} at ".json_encode($point).", not at {$where}";
                }
            }

            $frames = max(1, (int) ceil(hypot($to[0] - $from[0], $to[1] - $from[1]) / $speed));

            if ($fx['frames'] !== $frames) {
                $defects[] = "{$key} flies {$fx['frames']} frames, not ⌈length ÷ {$speed}⌉ = {$frames}";
            }

            // It goes in: a departure leaves no envelope at the hole, and an arrival sets its envelope down.
            if (($fx['leg'] ?? null) === 'departure' ? $fx['rest_frames'] !== 0 : $fx['rest_frames'] < 1) {
                $defects[] = "{$key} rests {$fx['rest_frames']} frames where it lands";
            }
        }

        // The run's population, so a run that stopped drawing one leg reads as such.
        $this->assertSame(['A19', 'arrival', 'departure'], $this->sorted(array_unique($legs)),
            'the run did not draw a departure, an arrival and a traveller');

        return $defects;
    }

    /**
     * card#12445: the scene's timeline for each portal bird, against § 12's *Ledge dwell* and *Hole hop* — never against
     * the effect's own figures, so a bird that carries a wrong dwell reds rather than certifying itself.
     */
    private function timelineDefects(array $result): array
    {
        $dwell = $this->dwellFrames();
        $hop = $this->hopFrames();
        $defects = [];
        $legs = [];

        foreach ($this->firstFlights($result) as $key => $fx) {
            $p = $fx['perch'];
            $f = $fx['frames'];

            if ($fx['animation_id'] === 'A19') {
                if ($p !== null || $fx['flight_start'] !== 0 || $fx['span_frames'] !== $fx['wait_frames'] + $f + $fx['rest_frames']) {
                    $defects[] = "{$key}: an A19 traveller does not simply fly and rest";
                }

                continue;
            }

            $legs[] = $fx['leg'];

            if ($p === null) {
                $defects[] = "{$key} never stands on the ledge";

                continue;
            }

            // Each part's first frame from the effect's start: going out flight → dwell → hop in; coming in hop out → dwell → flight.
            $owed = $fx['leg'] === 'departure'
                ? ['flight_start' => 0, 'dwell_start' => $f, 'hop_start' => $f + $dwell, 'hop' => 'in']
                : ['flight_start' => $hop + $dwell, 'dwell_start' => $hop, 'hop_start' => 0, 'hop' => 'out'];
            $drawn = ['flight_start' => $fx['flight_start'], 'dwell_start' => $p['dwell_start'], 'hop_start' => $p['hop_start'], 'hop' => $p['hop']];

            if ($drawn !== $owed) {
                $defects[] = "{$key}: its parts start at ".json_encode($drawn).', not '.json_encode($owed);
            }

            if ($p['dwell_frames'] !== $dwell || $p['hop_frames'] !== $hop) {
                $defects[] = "{$key} stands {$p['dwell_frames']} frames and hops {$p['hop_frames']}, not § 12's {$dwell} and {$hop}";
            }

            if ($fx['span_frames'] !== $fx['wait_frames'] + $f + $fx['rest_frames'] + $dwell + $hop) {
                $defects[] = "{$key} spans {$fx['span_frames']} frames, not its wait, flight, rest, dwell and hop";
            }
        }

        $this->assertSame(['arrival', 'departure'], $this->sorted(array_unique($legs)), 'the run did not draw a departure and an arrival');

        return $defects;
    }

    /**
     * card#12445: the painter's half of the ledge, from what it wrote for each portal bird. The standing bird's window on
     * the timeline is exactly § 12's dwell, the hop's exactly § 12's hop, each where the order puts it from the flight's
     * own start — going out, the dwell from the flight's last instant and the hop from the dwell's; coming in, the dwell
     * from the hop's last instant and the flight from the dwell's — and so on every paint. The standing bird is drawn at
     * the flight's end (going out) or start (coming in), so it never jumps; its feet are on the peg's tip, its legs
     * either side of the peg; the hop runs to the hole, into it going out and out of it coming in.
     */
    private function ledgeDefects(array $out, array $result): array
    {
        $dwell = $this->dwellFrames();
        $hop = $this->hopFrames();
        $style = $out['style'];
        $defects = [];
        $portal = null;

        foreach ($result['floor_renders'] as $render) {
            $portal ??= $render['frame']['scene']['band']['portal'] ?? null;
        }

        $this->assertNotNull($portal, 'the run drew no birdhouse');
        $this->assertSame(1, preg_match('/@keyframes hop\{from\{[^}]*\}(?:[^{}]*\{[^}]*\})*to\{[^}]*translate\(var\(--hole-x\),var\(--hole-y\)\)[^}]*\}\}/', $style),
            'the sheet\'s hop keyframes do not end on the hole (`--hole-x`, `--hole-y`)');

        $seen = 0;

        foreach ($out['flights'] as $f) {
            if ($f['animation_id'] !== 'A22') {
                continue;
            }

            $key = "{$f['animation_id']} {$f['cause']} {$f['leg']}";
            $I = $f['frame_interval_ms'];
            $start = self::PAINTS[0]['timeline_ms'] - self::PAINTS[0]['clock_ms'] + $f['wait_frames'] * $I;
            $flight = $f['frames'] * $I;
            $seen++;

            foreach ($f['paints'] as $i => $paint) {
                foreach (['bird', 'perched', 'hop'] as $part) {
                    if ($paint[$part] === null || $paint[$part]['start'] === null) {
                        $defects[] = "{$key}: paint {$i} drew no pinned {$part}";

                        continue 3;
                    }
                }

                $window = function (array $n): array {
                    $d = $this->declarations(preg_replace("/offset-path:path\\('[^']*'\\)/", '', $n['style']));
                    $from = $n['start'] + (float) $d['animation-delay'] * 1000;

                    return [round($from, 3), round($from + (float) $d['animation-duration'] * 1000, 3)];
                };
                [$flyFrom, $flyTo] = $window($paint['bird']);
                $got = ['perched' => $window($paint['perched']), 'hop' => $window($paint['hop']), 'flight' => [$flyFrom, $flyTo]];
                $owed = $f['leg'] === 'departure'
                    ? ['perched' => [$start + $flight, $start + $flight + $dwell * $I], 'hop' => [$start + $flight + $dwell * $I, $start + $flight + ($dwell + $hop) * $I], 'flight' => [$start, $start + $flight]]
                    : ['perched' => [$start + $hop * $I, $start + ($hop + $dwell) * $I], 'hop' => [$start, $start + $hop * $I], 'flight' => [$start + ($hop + $dwell) * $I, $start + ($hop + $dwell) * $I + $flight]];

                foreach ($owed as $part => [$a, $b]) {
                    if (abs($got[$part][0] - $a) > 0.5 || abs($got[$part][1] - $b) > 0.5) {
                        $defects[] = sprintf('%s: paint %d draws its %s from %.1f to %.1f ms on the timeline, not %.1f to %.1f', $key, $i, $part, $got[$part][0], $got[$part][1], $a, $b);
                    }
                }

                $direction = $f['leg'] === 'departure' ? 'hop-in' : 'hop-out';

                if ($paint['hop']['class'] !== "fx-hop {$direction}") {
                    $defects[] = "{$key}: its hop is `{$paint['hop']['class']}`, not {$direction}";
                }
            }

            // Where it stands: the flight's end going out, its start coming in — and its feet on the peg.
            $n = $f['paints'][0]['perched'];
            $this->assertSame(1, preg_match('/^translate\(([-\d.]+) ([-\d.]+)\)$/', (string) $n['at'], $at), "{$key}: the ledge is not placed by a translate");
            $this->assertSame(1, preg_match('/^translate\(0 ([-\d.]+)\)( scale\(-1 1\))?$/', (string) $n['bird'], $lift), "{$key}: the standing bird is not lifted by a translate");
            $stands = [(float) $at[1], (float) $at[2]];
            $end = $f['leg'] === 'departure' ? $f['to'] : $f['from'];

            if ($stands !== [(float) $end['x'], (float) $end['y']]) {
                $defects[] = "{$key} stands at ".json_encode($stands).', not where its flight '.($f['leg'] === 'departure' ? 'ends' : 'starts').' at '.json_encode($end);
            }

            preg_match_all('/([-\d.]+) ([-\d.]+)/', (string) $n['legs'], $pairs, PREG_SET_ORDER);
            $points = array_map(static fn (array $m): array => [(float) $m[1], (float) $m[2]], $pairs);
            $foot = max(array_column($points, 1));
            $legXs = array_map(static fn (array $q): float => ($lift[2] ?? '') === '' ? $q[0] : -$q[0], array_filter($points, static fn (array $q): bool => $q[1] < $foot));
            $feetY = $stands[1] + (float) $lift[1] + $foot;
            $peg = $portal['perch']['x'] - $stands[0];

            if ($points === [] || abs($feetY - $portal['perch']['tip']) > 0.01) {
                $defects[] = sprintf('%s: the standing bird\'s feet are at y %.2f, not on the peg\'s tip at %.2f', $key, $feetY, $portal['perch']['tip']);
            } elseif ($peg < min($legXs) || $peg > max($legXs)) {
                $defects[] = sprintf('%s: the peg is %.1f px from its legs\' middle, not between them', $key, $peg);
            }

            $this->assertSame(1, preg_match('/--hole-x:([-\d.]+)px;--hole-y:([-\d.]+)px/', (string) $f['paints'][0]['hop']['style'], $hole), "{$key}: its hop names no hole");

            if ([(float) $hole[1], (float) $hole[2]] !== [(float) $portal['mouth']['x'] - $stands[0], (float) $portal['mouth']['y'] - $stands[1]]) {
                $defects[] = "{$key} hops to ({$hole[1]}, {$hole[2]}) from where it stands, not to the hole";
            }
        }

        $this->assertGreaterThanOrEqual(2, $seen, 'the painter was handed fewer than two portal birds — the check read no departure or no arrival');

        return $defects;
    }

    /**
     * card#12445: the run's portal birds admitted to a fresh holder by the instants that test the ledge — the same bird
     * twice one frame apart (both going out), a bird coming in admitted as one going out lands, and the two far apart.
     * Each is the scene's effect as it was before any holder made it wait.
     */
    private function queue(array $result, ?string $dir = null): array
    {
        $birds = [];

        foreach ($this->firstFlights($result) as $fx) {
            if ($fx['animation_id'] === 'A22') {
                unset($fx['scene'], $fx['elapsed_frames'], $fx['elapsed_ms'], $fx['started_at']);
                $fx['span_frames'] -= $fx['wait_frames'];
                $fx['wait_frames'] = 0;
                $birds[$fx['leg']] = $fx;
            }
        }

        $this->assertSame(['arrival', 'departure'], $this->sorted(array_keys($birds)), 'the run drew no departure or no arrival to queue');

        $I = $birds['departure']['frame_interval_ms'];
        $out = $birds['departure'];
        $in = $birds['arrival'];
        $twin = ['cause' => $out['cause'].'-twin'] + $out;
        $lands = $out['frames'] * $I;

        return $this->probe(['interval_ms' => $I, 'scenarios' => [
            ['name' => 'two going out, a frame apart', 'admit' => [['at_ms' => 0, 'effect' => $out], ['at_ms' => $I, 'effect' => $twin]]],
            ['name' => 'one coming in as one going out lands', 'admit' => [['at_ms' => 0, 'effect' => $out], ['at_ms' => $lands, 'effect' => $in]]],
            ['name' => 'one coming in after one going out has gone in', 'admit' => [['at_ms' => 0, 'effect' => $out], ['at_ms' => $lands + ($this->dwellFrames() + $this->hopFrames()) * $I, 'effect' => $in]]],
        ]], dirname($dir ?? $this->moduleDir()), __DIR__.'/ledge-probe.mjs') + ['interval_ms' => $I];
    }

    /**
     * Each scenario's birds on the ledge: the parts the painter draws there (the dwell and the hop, from the scene's
     * frames, `wait_frames` later) never overlap between two birds; a bird that waited could not have waited a frame
     * less; one that met no other waited nothing; and each is held through its whole span, its wait included.
     */
    private function queueDefects(array $out): array
    {
        $I = $out['interval_ms'];
        $defects = [];
        $ledge = static function (array $b) use ($I): array {
            $p = $b['perch'];
            $from = min($p['dwell_start'], $p['hop_start']);
            $to = max($p['dwell_start'] + $p['dwell_frames'], $p['hop_start'] + $p['hop_frames']);

            return [$b['started_at'] + ($b['wait_frames'] + $from) * $I, $b['started_at'] + ($b['wait_frames'] + $to) * $I];
        };
        $meets = static fn (array $a, array $b): bool => $a[0] < $b[1] && $b[0] < $a[1];

        foreach ($out['scenarios'] as $sc) {
            [$first, $second] = $sc['held'] + [null, null];

            if ($first === null || $second === null) {
                $defects[] = "{$sc['name']}: the holder holds ".count($sc['held']).' birds, not two';

                continue;
            }

            if ($first['wait_frames'] !== 0) {
                $defects[] = "{$sc['name']}: the first bird waited {$first['wait_frames']} frames with the ledge free";
            }

            [$a, $b] = [$ledge($first), $ledge($second)];

            if ($meets($a, $b)) {
                $defects[] = sprintf('%s: both birds are on the ledge from %.0f to %.0f ms', $sc['name'], max($a[0], $b[0]), min($a[1], $b[1]));
            }

            if ($second['wait_frames'] > 0 && ! $meets($a, $ledge(['wait_frames' => $second['wait_frames'] - 1] + $second))) {
                $defects[] = "{$sc['name']}: the second bird waited {$second['wait_frames']} frames where one fewer clears the ledge";
            }

            foreach ($sc['held'] as $bird) {
                if (! $bird['held_at_end']) {
                    $defects[] = "{$sc['name']}: {$bird['cause']} is dropped before its span — wait included — ends";
                }
            }
        }

        $waits = array_map(static fn (array $sc): int => $sc['held'][1]['wait_frames'] ?? -1, $out['scenarios']);

        // The population: the first two scenarios meet on the ledge, the third does not.
        if ($waits[0] < 1 || $waits[1] < 1 || $waits[2] !== 0) {
            $defects[] = 'the second birds waited '.json_encode($waits).' frames — the first two scenarios must wait and the third must not';
        }

        return $defects;
    }

    /** @param  list<string>  $xs */
    private function sorted(array $xs): array
    {
        sort($xs);

        return array_values($xs);
    }

    /** The run's flights as the painter is handed them, without this test's scene notes. */
    private function effects(array $result): array
    {
        return array_map(static function (array $fx): array {
            unset($fx['scene']);

            return $fx;
        }, array_values($this->firstFlights($result)));
    }

    /** The run's flights through the painter, each painted at both of `PAINTS`. */
    private function paint(array $result, ?string $dir = null): array
    {
        return $this->probe(['effects' => $this->effects($result), 'paints' => self::PAINTS], dirname($dir ?? $this->moduleDir()), __DIR__.'/bird-paint-probe.mjs');
    }

    /**
     * The painter's half, from what it wrote: where the bird is drawn at each instant of its flight, and on which
     * arc. Its first drawn point is the arc's start and its last the arc's end — the desk or the hole — every paint
     * pins it to one start on the timeline, that start is the flight's own (`started_at` carried onto the timeline at
     * its first paint), and a repaint draws it where the paint before had it.
     */
    private function paintDefects(array $out): array
    {
        $rule = $this->bird($out['style']);
        $defects = [];

        $this->assertNotSame([], $out['flights'], 'the painter was handed no flight');

        foreach ($out['flights'] as $f) {
            $key = "{$f['animation_id']} {$f['cause']} ".($f['leg'] ?? '-');
            $nodes = array_map(static fn (array $p) => $p['bird'], $f['paints']);

            if (in_array(null, $nodes, true)) {
                $defects[] = "{$key}: a paint drew no bird";

                continue;
            }

            if (in_array(null, array_column($nodes, 'start'), true)) {
                $defects[] = "{$key}: a paint did not pin the flight's start";

                continue;
            }

            $timing = [];
            $origins = [];

            foreach ($nodes as $i => $n) {
                $timing[$i] = $this->timing($n['style'], $rule) + ['start' => $n['start']];
                // Where the node's animation reads the flight from: its pinned start plus its (negative) delay.
                $origins[$i] = $n['start'] + $timing[$i]['delay'];
            }

            // card#12445: the flight starts `flight_start` frames into the effect (after the hop out and the dwell, coming
            // in), and `wait_frames` later where it waited for the ledge.
            $own = self::PAINTS[0]['timeline_ms'] - self::PAINTS[0]['clock_ms'] + ($f['wait_frames'] + $f['flight_start']) * $f['frame_interval_ms'];

            if (abs($origins[0] - $own) > 0.5) {
                $defects[] = "{$key}: the first paint dates the flight from {$origins[0]} ms on the timeline, not its own start at {$own} ms";
            }

            if (abs($origins[0] - $origins[1]) > 0.5) {
                $defects[] = "{$key}: the paints date the flight from {$origins[0]} ms and {$origins[1]} ms on the timeline, not one start";
            }

            $arc = $timing[0]['arc'];

            if ($arc[0] !== [$f['from']['x'], $f['from']['y']] || $arc[2] !== [$f['to']['x'], $f['to']['y']]) {
                $defects[] = "{$key}: the bird flies an arc from ".json_encode($arc[0]).' to '.json_encode($arc[2]).', not its flight\'s ends';
            }

            if ($timing[0]['ms'] !== (float) ($f['frames'] * $f['frame_interval_ms'])) {
                $defects[] = "{$key}: the flight lasts {$timing[0]['ms']} ms, not its {$f['frames']} frames";
            }

            $length = $this->arcLength($arc);
            $model = fn (float $p): ?array => $this->drawn($rule, $timing[0]['easing'], $p);

            // Its first and last drawn points — the limit of its drawing as the flight ends, since it is not drawn after.
            foreach ([['first', 0.0, 0.0], ['last', 1 - 1e-9, 1.0]] as [$which, $p, $owed]) {
                $at = $model($p);

                if ($at === null) {
                    $defects[] = "{$key}: {$timing[0]['easing']} is a timing function this model does not know";

                    continue 2;
                }

                if (! $at['shown'] || abs($at['offset'] - $owed) * $length > 0.5) {
                    $defects[] = sprintf('%s: the bird\'s %s drawn point is %.1f px along its %.1f px arc, not at its %s',
                        $key, $which, $at['offset'] * $length, $length, $owed === 0.0 ? 'start' : 'destination');
                }
            }

            // Across the repaint: at one instant after both paints, both nodes draw the bird at one point.
            $instant = self::PAINTS[1]['timeline_ms'] + 100;
            $points = array_map(fn (array $t) => $model(min(1, max(0, ($instant - $t['start'] - $t['delay']) / $t['ms'])))['offset'] * $length, $timing);

            if (abs($points[0] - $points[1]) > 0.5) {
                $defects[] = sprintf('%s: at one instant the paint before a repaint draws the bird %.1f px along its arc and the repaint %.1f px', $key, $points[0], $points[1]);
            }
        }

        return $defects;
    }

    /** The sheet's `.fx-bird` rule and `fly` keyframes, as `{easing, keyframes: [[pct, [prop => value]]]}`. */
    private function bird(string $style): array
    {
        $this->assertSame(1, preg_match('/\.fx-bird\{([^}]*)\}/', $style, $r), 'the sheet has no .fx-bird rule');
        $this->assertSame(1, preg_match('/@keyframes fly\{((?:[^{}]+\{[^}]*\})+)\}/', $style, $k), 'the sheet has no fly keyframes');
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $k[1], $frames, PREG_SET_ORDER);

        $keyframes = [];

        foreach ($frames as [, $selector, $body]) {
            $pct = match (trim($selector)) {
                'from' => 0.0, 'to' => 1.0, default => ((float) $selector) / 100
            };
            $keyframes[] = [$pct, $this->declarations($body)];
        }

        usort($keyframes, static fn ($a, $b) => $a[0] <=> $b[0]);

        return ['easing' => $this->declarations($r[1])['animation-timing-function'] ?? 'ease', 'base' => $this->declarations($r[1]), 'keyframes' => $keyframes];
    }

    /** @return array<string, string> */
    private function declarations(string $body): array
    {
        $out = [];

        foreach (array_filter(array_map('trim', explode(';', $body))) as $d) {
            [$k, $v] = array_map('trim', explode(':', $d, 2));
            $out[$k] = $v;
        }

        return $out;
    }

    /** One node's inline timing and arc: `{easing, ms, delay, arc: [P0, P1, P2]}`. */
    private function timing(string $style, array $rule): array
    {
        $d = $this->declarations(preg_replace("/offset-path:path\\('[^']*'\\)/", '', $style));
        $this->assertSame(1, preg_match("/offset-path:path\\('M ([-\\d.]+) ([-\\d.]+) Q ([-\\d.]+) ([-\\d.]+) ([-\\d.]+) ([-\\d.]+)'\\)/", $style, $m),
            "the bird's arc is not a quadratic path this test reads: {$style}");
        $n = array_map('floatval', array_slice($m, 1));
        $int = static fn (float $v): float|int => floor($v) === $v ? (int) $v : $v;

        return [
            'easing' => $d['animation-timing-function'] ?? $rule['easing'],
            'ms' => (float) $d['animation-duration'] * 1000,
            'delay' => (float) ($d['animation-delay'] ?? 0) * 1000,
            'arc' => [[$int($n[0]), $int($n[1])], [$n[2], $n[3]], [$int($n[4]), $int($n[5])]],
        ];
    }

    /**
     * Where CSS draws the bird at progress `p` (0 ≤ p < 1) of its animation: `offset-distance` as a fraction of its
     * arc and whether it is drawn (`opacity` over 0) — each property eased per keyframe interval, as CSS eases it,
     * by `linear` or `steps(n[, jump-end|end])`. `null` for any other timing function.
     *
     * @return array{offset: float, shown: bool}|null
     */
    private function drawn(array $rule, string $easing, float $p): ?array
    {
        $ease = match (true) {
            $easing === 'linear' => static fn (float $q): float => $q,
            (bool) preg_match('/^steps\((\d+)(?:,\s*(?:jump-end|end))?\)$/', $easing, $s) => static fn (float $q): float => floor($q * (int) $s[1]) / (int) $s[1],
            default => null,
        };

        if ($ease === null) {
            return null;
        }

        $value = function (string $prop) use ($rule, $ease, $p): float {
            $points = array_values(array_filter(array_map(static fn ($k) => isset($k[1][$prop]) ? [$k[0], (float) $k[1][$prop] / ($prop === 'offset-distance' ? 100 : 1)] : null, $rule['keyframes'])));

            for ($i = 1; $i < count($points); $i++) {
                [$a, $va] = $points[$i - 1];
                [$b, $vb] = $points[$i];

                if ($p >= $a && $p < $b) {
                    return $va + ($vb - $va) * $ease(($p - $a) / ($b - $a));
                }
            }

            return $points[count($points) - 1][1];
        };

        return ['offset' => $value('offset-distance'), 'shown' => $value('opacity') > 0];
    }

    /** A quadratic arc's length, summed over a thousand chords. */
    private function arcLength(array $arc): float
    {
        [$a, $c, $b] = $arc;
        $at = static fn (float $t): array => [
            (1 - $t) ** 2 * $a[0] + 2 * (1 - $t) * $t * $c[0] + $t ** 2 * $b[0],
            (1 - $t) ** 2 * $a[1] + 2 * (1 - $t) * $t * $c[1] + $t ** 2 * $b[1],
        ];
        $length = 0.0;
        $last = $at(0.0);

        for ($i = 1; $i <= 1000; $i++) {
            $next = $at($i / 1000);
            $length += hypot($next[0] - $last[0], $next[1] - $last[1]);
            $last = $next;
        }

        return $length;
    }
}

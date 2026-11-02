<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-32 — an idle character visits the snack corner once an hour, stands at its own table, and comes straight back
 * (`docs/design/FLOOR.md § 11`, card#12749): § 6.6's trip, § 6.2's row A25, over `fx-snack`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A TRIP IS PRESENTATION, NEVER STATE. The rows are the animation log's and are asserted there; the trip is asserted on
 * the scene each render and each paint-only refresh drew — `frame.scene.effects` (each A25 effect with frames), the desk's
 * elements and its `held`, `frame.scene.snack.tables` — the model the painter draws. The look of the corner is the
 * operator's reading of the sandbox, and no clause here asserts it.
 *
 * ⛔ EVERY NUMBER IS RE-DERIVED: the window's second and the dwell through the character tree's own `draw()` over the
 * walker's key and the hour (`walk-probe.mjs`), spot 0 from § 12's *Snack spot* over the table tile the scene read and the
 * bodies and margins § 12 states, the later spots through `route.js`'s own `spotsNear()`, a walker's point through
 * `pointAt()`.
 *
 * ⛔ EACH RED IS A PLANT IN THE SHIPPED TREE (`test_red_*`), replayed against the run § 11 names for it and seen to break
 * that run's GREEN — the same defect list the GREEN asserts empty.
 *
 * ⛔ THE RECALL WALKS HOME. The operator ruled on § 14 item 44 Q2 (2026-11-02, *"Walk back"*): a walker recalled to work
 * mid-trip walks home along its route, the desk's facts rendering the new state at once. The design's Eleventh RED was the
 * walk; it is now the CUT — the trip ended on the recall and the character drawn at its desk on the applying render.
 */
class AnIdleCharacterVisitsTheSnackCornerTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const IMPL_1 = 'aimla/aimla-impl-1';

    private const IMPL_2 = 'aimla/aimla-impl-2';

    private const PM = 'aimla/aimla-pm';

    private const REVIEW = 'aimla/aimla-review';

    private const SCENE = '../floor/scene.js';

    private const IN_FLIGHT = '../floor/in-flight.js';

    private const SCREEN = '../floor/floor-screen.js';

    private const ANIMATION_SET = 'animation-set.js';

    private const DESK_RENDER = '../desk/desk-render.js';

    private const LINE = 'no open route between its desk and the snack table — drawn without the trip';

    /** @var array<string, array<string, mixed>> the shipped tree's runs, replayed once per process */
    private static array $runs = [];

    // ── what a run drew ──────────────────────────────────────────────────────────────────────

    /** One run of the shipped tree (cached), or of a planted copy. */
    private function snackRun(string $run, ?string $dir = null, array $overrides = []): array
    {
        if ($dir !== null || $overrides !== []) {
            return $this->floorRun($run, $dir, $overrides);
        }

        return self::$runs[$run] ??= $this->floorRun($run);
    }

    /** @return list<array<string, mixed>> the renders and refreshes that drew a scene */
    private function drawn(array $result): array
    {
        return array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['scene'] ?? null) !== null));
    }

    /** @return list<array<string, mixed>> the snack trips a frame draws (A25 effects with frames), of one walker where named */
    private function trips(array $frame, ?string $walker = null): array
    {
        return array_values(array_filter($frame['scene']['effects'] ?? [], static fn (array $e): bool => $e['animation_id'] === 'A25'
            && ($e['frames'] ?? 0) > 0 && ($walker === null || $e['walker_key'] === $walker)));
    }

    /** @return list<array<string, mixed>> the edge rows of the given ids one seat wrote */
    private function edges(array $result, string $key, array $ids): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'edge'
            && in_array($r['animation_id'], $ids, true) && "{$r['install_id']}/{$r['seat_id']}" === $key));
    }

    /** @return list<array<string, mixed>> the held rows of one id one seat wrote */
    private function held(array $result, string $key, string $id): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['class'] === 'held'
            && $r['animation_id'] === $id && "{$r['install_id']}/{$r['seat_id']}" === $key));
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

    /** The first frame drawing a trip of the walker, with its effect. @return array{0: array, 1: array, 2: int} */
    private function firstTrip(array $result, string $walker, string $legKind = 'snack'): array
    {
        return $this->anyTrip($result, $walker, $legKind) ?? $this->fail("the run drew no `{$legKind}` trip for {$walker}");
    }

    /** `firstTrip()`, or `null` where the run drew none — for a defect list, where no trip is a defect to name. */
    private function anyTrip(array $result, string $walker, string $legKind = 'snack'): ?array
    {
        foreach ($this->drawn($result) as $r) {
            foreach ($this->trips($r['frame'], $walker) as $t) {
                if ($t['leg_kind'] === $legKind) {
                    return [$r['frame'], $t, $r['at']];
                }
            }
        }

        return null;
    }

    /** The run's clock 0 as a corrected server instant, ms — its snapshot's `server_time`, which the run fetches at 0. */
    private function clockZero(string $run): int
    {
        $time = $this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['server_time'];

        return (int) round((float) (new \DateTimeImmutable($time))->format('U.u') * 1000);
    }

    /** One seat of a run's snapshot, as the fixture holds it. */
    private function snapshotSeat(string $run, string $seatId): array
    {
        foreach ($this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['installs'][0]['seats'] as $seat) {
            if ($seat['seat_id'] === $seatId) {
                return $seat;
            }
        }

        $this->fail("{$run}'s snapshot holds no {$seatId}");
    }

    /** The character tree's own seeded draw over (key, field). */
    private function draw(string $key, string $field): int
    {
        return $this->probe(['mode' => 'draw', 'cases' => [[$key, $field]]], null, __DIR__.'/walk-probe.mjs')[0];
    }

    /** Where an effect stands `$ms` after its start, through `route.js`'s own `pointAt()`. */
    private function pointAt(array $effect, float $ms): array
    {
        $p = $this->probe(['mode' => 'points', 'cases' => [['effect' => $effect, 'times' => [$ms]]]], null, __DIR__.'/walk-probe.mjs')[0][0];

        return ['x' => $p['x'], 'y' => $p['y']];
    }

    /**
     * The snack corner as the scene module declares it, with the spots `spotsNear()` finds from the given spot 0s — on the
     * lattice of the desks `$frame`'s scene drew (a slot nobody holds is open floor), where a frame is named.
     */
    private function corner(array $spots = [], ?array $frame = null): array
    {
        $desks = $frame === null ? [] : ['desks' => array_map(static fn (array $d): array => [
            'key' => $d['key'],
            'rects' => array_values(array_filter([$d['slot_rect'], $d['box']], static fn ($r) => $r !== null)),
        ], $frame['scene']['desks'])];

        return $this->probe(['mode' => 'snack', 'spots' => $spots] + $desks, null, __DIR__.'/walk-probe.mjs');
    }

    // ── § 12's rows ──────────────────────────────────────────────────────────────────────────

    /** @return array{window: int, dwell: array{0: int, 1: int}, tall: array{0: int, 1: int}, low: array{0: int, 1: int}, margin: int, depth: int, seat_w: float, intern_w: float} */
    private function section12(): array
    {
        $md = $this->floorMd();
        $read = function (string $re, string $what) use ($md): array {
            $this->assertSame(1, preg_match($re, $md, $m), "§ 12 carries no {$what} row in the form this test reads");

            return $m;
        };
        $w = $read("/^\\| \\*\\*Snack window\\*\\* \\| \\*\\*1\\*\\* trip per hour, beginning `draw\\(key, 'snack:' \\+ H\\) mod (\\d+)`/m", '*Snack window*');
        $d = $read('/^\| \*\*Snack dwell\*\* \| \*\*(\d+) … (\d+) s\*\* \|/m', '*Snack dwell*');
        $t = $read('/^\| \*\*Snack tables\*\* \| \*\*(\d+) × (\d+)\*\* and \*\*(\d+) × (\d+)\*\* px \|/m', '*Snack tables*');
        $c = $read('/^\| \*\*Walker clearance\*\* \| \*\*(\d+) px beside; (\d+) px in depth\*\* \|/m', '*Walker clearance*');
        $s = $read('/^\| \*\*Character rect\*\* \| \*\*([\d.]+) × ([\d.]+)\*\* px \|/m', '*Character rect*');
        $i = $read("/the intern's (\\d+) × (\\d+) px stool rect/", '*Intern frame*');

        return [
            'window' => (int) $w[1],
            'dwell' => [(int) $d[1], (int) $d[2]],
            'tall' => [(int) $t[1], (int) $t[2]],
            'low' => [(int) $t[3], (int) $t[4]],
            'margin' => (int) $c[1],
            'depth' => (int) $c[2],
            'seat_w' => (float) $s[1],
            'intern_w' => (float) $i[1],
        ];
    }

    /** § 12's *Snack spot* for a table the scene read: half the visitor's width beside its front-left corner, snapped. */
    private function spotZero(array $table, float $width): array
    {
        $f = $this->section12();
        $snap = static fn (float $v): float => floor($v / 8) * 8 + 4;

        return ['x' => $snap($table['x'] - $width / 2), 'y' => $snap($table['y'] + $table['h'] + $f['depth'] + 1)];
    }

    private function table(array $frame, string $kind): array
    {
        foreach ($frame['scene']['snack']['tables'] as $t) {
            if ($t['kind'] === $kind) {
                return $t;
            }
        }

        $this->fail("the scene reads no `{$kind}` off the map");
    }

    private function hourOf(array $trip): int
    {
        return $trip['hour'];
    }

    // ── GREEN — § 12's figures are the scene's ───────────────────────────────────────────────

    public function test_green_section_12s_figures_are_the_scenes(): void
    {
        $f = $this->section12();
        $c = $this->corner();

        $this->assertSame($f['window'], $c['window_s'], "the scene's window is not § 12's *Snack window*");
        $this->assertSame(['min' => $f['dwell'][0], 'max' => $f['dwell'][1]], $c['dwell_s'], "the scene's dwell is not § 12's *Snack dwell*");
        $this->assertSame([$f['tall'], $f['low']], [[$c['tables']['snack-table']['w'], $c['tables']['snack-table']['h']], [$c['tables']['snack-table-low']['w'], $c['tables']['snack-table-low']['h']]],
            "the scene's tables are not § 12's *Snack tables*");
        $this->assertSame(['seat', 'intern'], [$c['tables']['snack-table']['visitor'], $c['tables']['snack-table-low']['visitor']], 'a table serves the other height');
        $this->assertEqualsWithDelta($f['seat_w'], $c['bodies']['seat']['w'], 1e-9, 'the seat visitor is not the *Character rect*');
        $this->assertEqualsWithDelta($f['intern_w'], $c['bodies']['intern']['w'], 1e-9, 'the intern visitor is not the stool rect');

        foreach (['seat' => $f['seat_w'], 'intern' => $f['intern_w']] as $who => $w) {
            $this->assertEqualsWithDelta(['dx' => -$w / 2, 'mirror' => $w / 2, 'dy' => $f['depth'] + 1], $c['offset'][$who], 1e-9, "[{$who}] the spot offset is not § 12's *Snack spot*");
            $this->assertEqualsWithDelta($w + $f['margin'], $c['spacing'][$who], 1e-9, "[{$who}] the spacing is not § 12's *Snack spacing*");
        }

        // The tiles the shipped tileset declares are § 12's sizes too.
        $tsx = (string) file_get_contents(__DIR__.'/../../../../resources/floor/tiles/floor-plane.tsx');
        foreach (['snack-table' => $f['tall'], 'snack-table-low' => $f['low']] as $kind => [$w, $h]) {
            $this->assertSame(1, preg_match('/value="'.preg_quote($kind, '/').'"\/>\s*<\/properties>\s*<image source="[^"]+" width="(\d+)" height="(\d+)"/', $tsx, $m), "the tileset declares no `{$kind}` tile");
            $this->assertSame([$w, $h], [(int) $m[1], (int) $m[2]], "the `{$kind}` tile is not § 12's size");
        }
    }

    // ── GREEN — the rows ─────────────────────────────────────────────────────────────────────

    /** What is wrong with the A25 rows of the runs § 11 names (the GREEN's clauses, as a list). @return list<string> */
    private function rowDefects(?string $dir = null): array
    {
        $defects = [];
        $v2 = $this->snapshotSeat('idle-trip', 'aimla-impl-2')['state_version'];

        $idle = $this->snackRun('idle-trip', $dir);
        $entered = static fn (array $rows): array => array_values(array_filter($rows, static fn ($r) => $r['phase'] === 'entered'));
        $left = static fn (array $rows): array => array_values(array_filter($rows, static fn ($r) => $r['phase'] === 'left'));

        if (array_column($entered($this->held($idle, self::IMPL_2, 'A6')), 'cause') !== [$v2]
            || array_column($entered($this->held($idle, self::IMPL_2, 'A25')), 'cause') !== [$v2]) {
            $defects[] = 'idle-trip did not enter A6 and A25 once each for aimla-impl-2 against the snapshot version';
        }

        if ($left(array_merge($this->held($idle, self::IMPL_2, 'A6'), $this->held($idle, self::IMPL_2, 'A25'))) !== []
            || $this->edges($idle, self::IMPL_2, ['A1', 'A2', 'A16']) !== []) {
            $defects[] = 'idle-trip left a row or fired an edge for aimla-impl-2 through the trip';
        }

        $working = $this->snackRun('working-window', $dir);

        if ($this->held($working, self::IMPL_1, 'A25') !== [] || array_column($entered($this->held($working, self::IMPL_1, 'A3')), 'animation_id') !== ['A3']) {
            $defects[] = 'working-window did not enter A3 alone for aimla-impl-1';
        }

        $intern = $this->snackRun('intern-trip', $dir);
        $flip = $this->fixture('intern-trip')['messages'];
        $flipVersion = array_values(array_filter($flip, static fn ($m) => ($m['envelope']['t'] ?? null) === 'seat.delta'))[0]['envelope']['state_version'];

        if (array_column($entered($this->held($intern, self::PM, 'A25')), 'cause') !== [$flipVersion]
            || array_column($left($this->held($intern, self::PM, 'A23')), 'cause') !== [$flipVersion]
            || $left($this->held($intern, self::PM, 'A3')) !== []) {
            $defects[] = "intern-trip did not enter A25 for aimla-pm at the flip's version beside A23's exit and the seat's A3";
        }

        foreach (['recall-mid-trip' => 'A3', 'leave-mid-trip' => null] as $run => $then) {
            $r = $this->snackRun($run, $dir);
            $version = $v2 + 1;

            if (array_column($left($this->held($r, self::IMPL_2, 'A6')), 'cause') !== [$version]
                || array_column($left($this->held($r, self::IMPL_2, 'A25')), 'cause') !== [$version]) {
                $defects[] = "[{$run}] A6 and A25 were not left at the applying delta's version";
            }

            if ($then !== null && array_column($entered($this->held($r, self::IMPL_2, $then)), 'cause') !== [$version]) {
                $defects[] = "[{$run}] {$then} was not entered at the applying delta's version";
            }

            if ($then === null && count($this->edges($r, self::IMPL_2, ['A2'])) !== 1) {
                $defects[] = "[{$run}] not one A2";
            }
        }

        $stop = $this->snackRun('intern-stop-mid-trip', $dir);

        if (count($left($this->held($stop, self::PM, 'A25'))) !== 1 || count($this->edges($stop, self::PM, ['A10'])) !== 1) {
            $defects[] = 'intern-stop-mid-trip did not leave the interns\' A25 and fire one A10';
        }

        foreach (['feed-dies-mid-trip', 'feed-down'] as $run) {
            if ($left($this->held($this->snackRun($run, $dir), self::IMPL_2, 'A25')) !== []) {
                $defects[] = "[{$run}] A25 was left on a dying feed — nothing ended the hold";
            }
        }

        $stilled = $this->snackRun('stilled-mid-trip', $dir);
        $out = $left($this->held($stilled, self::IMPL_2, 'A25'));
        $again = array_values(array_filter($entered($this->held($stilled, self::IMPL_2, 'A25')), static fn ($r) => $r['motion'] === false));

        if (array_column($out, 'cause') !== ['stilled'] || count($again) !== 1 || $again[0]['at'] !== $out[0]['at']) {
            $defects[] = 'stilled-mid-trip did not leave A25 `stilled` and re-enter it at `motion: false` on that render';
        }

        return $defects;
    }

    public function test_green_the_rows(): void
    {
        $this->assertSame([], $this->rowDefects());
    }

    // ── GREEN — the trip ─────────────────────────────────────────────────────────────────────

    /** What is wrong with idle-trip's trip, against its seeded schedule and the scene it was drawn on. @return list<string> */
    private function tripDefects(array $result, string $run): array
    {
        $defects = [];
        $f = $this->section12();
        [$frame, $trip, $at] = $this->firstTrip($result, self::IMPL_2);
        $hour = $this->hourOf($trip);
        $start = $hour * $f['window'] + $this->draw(self::IMPL_2, "snack:{$hour}") % $f['window'];
        $dwell = $f['dwell'][0] + $this->draw(self::IMPL_2, "snack-dwell:{$hour}") % ($f['dwell'][1] - $f['dwell'][0] + 1);

        if ($trip['start_s'] !== $start || $this->clockZero($run) + $at - $trip['elapsed_ms'] !== $start * 1000) {
            $defects[] = "the trip does not begin at the hour's seeded second ({$start}): it carries {$trip['start_s']} and is drawn from "
                .($this->clockZero($run) + $at - $trip['elapsed_ms']);
        }

        if (array_column($trip['legs'], 'kind') !== ['walk', 'stand', 'walk']) {
            $defects[] = 'the trip is not walk, stand, walk';

            return $defects;
        }

        $end = 0;
        foreach ($trip['legs'] as $i => $leg) {
            if ($leg['start'] !== $end) {
                $defects[] = "leg {$i} does not start where the one before it ends";
            }
            $end = $leg['start'] + $leg['frames'];
        }

        [$out, $stand, $back] = $trip['legs'];
        $anchor = $frame['scene']['anchors'][self::IMPL_2];
        $spot = $this->spotZero($this->table($frame, 'snack-table'), $f['seat_w']);

        if ($out['points'][0] != $anchor || end($out['points']) != $spot) {
            $defects[] = 'the walk out does not run from the desk anchor to the tall table\'s spot 0';
        }

        $length = 0.0;
        for ($i = 1; $i < count($out['points']); $i++) {
            $length += hypot($out['points'][$i]['x'] - $out['points'][$i - 1]['x'], $out['points'][$i]['y'] - $out['points'][$i - 1]['y']);
        }

        if ($out['frames'] !== max(1, (int) ceil($length / 24))) {
            $defects[] = 'the walk out is not ⌈length ÷ walk speed⌉ frames';
        }

        if ($stand['frames'] !== $dwell * 4 || $stand['at'] != $spot) {
            $defects[] = "the stand is not the seeded dwell ({$dwell} s) at the spot";
        }

        if ($back['points'][0] != $spot || end($back['points']) != $anchor) {
            $defects[] = 'the walk home does not run from the spot to the desk anchor';
        }

        // The desk meanwhile: the empty chair, every fact, no z's; never the walker and the sleeper at once; the refresh
        // at the trip's end seats the sleeper.
        $ends = null;
        foreach ($this->drawn($result) as $r) {
            $walking = $this->trips($r['frame'], self::IMPL_2) !== [];
            $desk = $this->desk($r['frame'], self::IMPL_2);
            $model = $r['frame']['desks']['desks'][self::IMPL_2];

            if ($walking && ($this->drawsCharacter($r['frame'], self::IMPL_2) || $desk['held'] !== null)) {
                $defects[] = "at {$r['at']} ms the desk draws its sleeper while its walker is on the floor";
            }

            if ($walking && ($model['render_state']['value'] !== 'idle' || $model['monitor']['lit'] !== 'off'
                || ! in_array('chip', array_column($desk['elements'], 'kind'), true) || ! in_array('chair', array_column($desk['elements'], 'kind'), true))) {
                $defects[] = "at {$r['at']} ms the desk does not draw its idle facts and its empty chair";
            }

            if (! $walking && $ends === null && $r['at'] > $at) {
                $ends = $r;
            }
        }

        if ($ends === null || $ends['trigger'] !== 'walk end' || ! $this->drawsCharacter($ends['frame'], self::IMPL_2)
            || ($this->desk($ends['frame'], self::IMPL_2)['held']['animation_id'] ?? null) !== 'A6') {
            $defects[] = 'no paint-only refresh at the trip\'s end seats the sleeper';
        }

        return $defects;
    }

    public function test_green_the_trip(): void
    {
        $this->assertSame([], $this->tripDefects($this->snackRun('idle-trip'), 'idle-trip'));
    }

    // ── GREEN — who ─────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function whoDefects(?string $dir = null): array
    {
        $defects = [];

        foreach (['working-window', 'intern-old-reporter'] as $run) {
            foreach ($this->drawn($this->snackRun($run, $dir)) as $r) {
                if ($this->trips($r['frame']) !== []) {
                    $defects[] = "[{$run}] a walker at {$r['at']} ms";

                    break;
                }
            }
        }

        $run = $this->snackRun('intern-trip', $dir);
        $call = $this->snapshotSeat('intern-trip', 'aimla-pm')['subagents'][0]['call_id'];
        $key = self::PM."~{$call}";
        [$frame, $trip] = $this->firstTrip($run, $key);
        $f = $this->section12();

        if ($trip['size'] !== ['w' => 35, 'h' => 56] || array_filter($trip['legs'], static fn ($l) => $l['kind'] === 'seat') !== []) {
            $defects[] = 'the intern walks at another size, or carries a laptop';
        }

        if ($trip['legs'][0]['points'][0] != $frame['scene']['slot_feet'][self::PM][$trip['slot']]
            || end($trip['legs'][0]['points']) != $this->spotZero($this->table($frame, 'snack-table-low'), $f['intern_w'])) {
            $defects[] = "the intern's walk does not run from its slot's foot to the low table's spot 0";
        }

        foreach ($this->drawn($run) as $r) {
            if ($this->trips($r['frame'], $key) === []) {
                continue;
            }

            $stools = array_values(array_filter($this->desk($r['frame'], self::PM)['elements'], static fn ($e) => $e['kind'] === 'stool'));

            if (in_array($call, array_column($stools, 'call_id'), true)) {
                $defects[] = "at {$r['at']} ms the intern's stool is drawn while it is away";
            }

            if (! $this->drawsCharacter($r['frame'], self::PM)) {
                $defects[] = "at {$r['at']} ms the PM is not at its desk";
            }
        }

        return $defects;
    }

    public function test_green_who(): void
    {
        $this->assertSame([], $this->whoDefects());
    }

    // ── GREEN — the spots and the committed past ─────────────────────────────────────────────

    /** The walker's point at the server instant `$serverMs`, from one frame's effect. */
    private function pointIn(array $trip, string $run, int $renderAt, float $serverMs): array
    {
        return $this->pointAt($trip, $serverMs - ($this->clockZero($run) + $renderAt - $trip['elapsed_ms']));
    }

    /** @return list<string> */
    private function spotDefects(?string $dir = null): array
    {
        $defects = [];
        $f = $this->section12();
        $two = $this->snackRun('two-visitors', $dir);
        [$frame, $first] = $this->firstTrip($two, self::IMPL_2);
        [, $second] = $this->firstTrip($two, self::IMPL_1);
        $earlier = $first['start_s'] <= $second['start_s'] ? $first : $second;
        $later = $earlier === $first ? $second : $first;
        $s0 = $this->spotZero($this->table($frame, 'snack-table'), $f['seat_w']);
        $spots = $this->corner([['visitor' => 'seat', 'from' => $s0, 'count' => 2]], $frame)['spots'][0];

        if ($earlier['spot']['index'] !== 0 || $earlier['spot']['at'] != $s0 || $later['spot']['index'] !== 1 || $later['spot']['at'] != $spots[1]) {
            $defects[] = 'two-visitors does not stand the first at spot 0 and the second at spotsNear()\'s spot 1';
        }

        if (hypot($spots[1]['x'] - $s0['x'], $spots[1]['y'] - $s0['y']) < $f['seat_w'] + $f['margin']) {
            $defects[] = 'two-visitors\' two spots are closer than *Snack spacing*';
        }

        $tl = $this->snackRun('tall-and-low', $dir);
        [$tf, $agent] = $this->firstTrip($tl, self::IMPL_2);
        $internKey = null;
        foreach ($this->drawn($tl) as $r) {
            foreach ($this->trips($r['frame']) as $t) {
                $internKey ??= isset($t['intern_key']) ? $t['walker_key'] : null;
            }
        }
        [, $intern] = $this->firstTrip($tl, (string) $internKey);
        $low = $this->spotZero($this->table($tf, 'snack-table-low'), $f['intern_w']);

        if ($agent['spot']['at'] != $this->spotZero($this->table($tf, 'snack-table'), $f['seat_w']) || $intern['spot']['at'] != $low
            || hypot($agent['spot']['at']['x'] - $low['x'], $agent['spot']['at']['y'] - $low['y']) < $f['seat_w'] + $f['margin']) {
            $defects[] = 'tall-and-low does not stand the agent at the tall table\'s spot 0 and the intern at the low one\'s, a tall spacing apart';
        }

        $last = $this->snackRun('past-the-last-spot', $dir);
        $walkers = [];
        foreach ($this->drawn($last) as $r) {
            $here = array_column($this->trips($r['frame']), 'walker_key');
            $walkers = array_unique([...$walkers, ...$here]);

            if (count($here) > 2) {
                $defects[] = "past-the-last-spot draws ".count($here)." walkers at {$r['at']} ms on a table of two spots";
            }
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

            if (array_filter($last['final']['event_log'], static fn ($l) => str_contains($l, 'snack')) !== []) {
                $defects[] = 'past-the-last-spot wrote an event-log line';
            }
        }

        return $defects;
    }

    public function test_green_the_spots(): void
    {
        $this->assertSame([], $this->spotDefects());
    }

    /**
     * THE COMMITTED PAST: across two-visitors, tall-and-low and past-the-last-spot, a walker drawn by two consecutive frames
     * is at one point at the later frame's instant whichever frame's effect places it, and keeps its spot.
     *
     * @return list<string>
     */
    private function pastDefects(?string $dir = null): array
    {
        $defects = [];

        foreach (['two-visitors', 'tall-and-low', 'past-the-last-spot'] as $run) {
            $before = null;

            foreach ($this->drawn($this->snackRun($run, $dir)) as $r) {
                $now = [];
                foreach ($this->trips($r['frame']) as $t) {
                    $now[$t['walker_key']] = $t;
                }

                if ($before !== null) {
                    foreach ($now as $key => $t) {
                        $was = $before['trips'][$key] ?? null;

                        if ($was === null || $was['leg_kind'] !== $t['leg_kind']) {
                            continue;
                        }

                        $instant = $this->clockZero($run) + $r['at'];

                        if ($was['spot'] != $t['spot']
                            || $this->pointIn($was, $run, $before['at'], $instant) != $this->pointIn($t, $run, $r['at'], $instant)) {
                            $defects[] = "[{$run}] {$key} moved between the frames at {$before['at']} and {$r['at']} ms";
                        }
                    }
                }

                $before = ['at' => $r['at'], 'trips' => $now];
            }
        }

        return $defects;
    }

    public function test_green_the_committed_past(): void
    {
        $this->assertSame([], $this->pastDefects());
    }

    // ── GREEN — the interrupts ───────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function interruptDefects(?string $dir = null): array
    {
        $defects = [];
        $applied = 35000;

        // recall-mid-trip: the desk renders `working` at once, the walker walks home from where it stood, and sits down to
        // work at the refresh that ends the walk (the operator's ruling of 2026-11-02, *"Walk back"*).
        $recall = $this->snackRun('recall-mid-trip', $dir);
        $prior = null;
        $priorAt = null;
        $home = null;
        foreach ($this->drawn($recall) as $r) {
            if ($r['at'] < $applied) {
                if (($t = $this->trips($r['frame'], self::IMPL_2)) !== []) {
                    [$prior, $priorAt] = [$t[0], $r['at']];
                }

                continue;
            }

            if ($r['at'] === $applied && $r['trigger'] === 'render') {
                $home = $this->trips($r['frame'], self::IMPL_2)[0] ?? null;

                if ($r['frame']['desks']['desks'][self::IMPL_2]['render_state']['value'] !== 'working' || $this->drawsCharacter($r['frame'], self::IMPL_2)) {
                    $defects[] = 'recall-mid-trip\'s applying render does not draw the desk working with its walker still away';
                }
            }
        }

        if ($home === null || $home['leg_kind'] !== 'snack-home' || $prior === null
            || $home['legs'][0]['points'][0] != $this->pointIn($prior, 'recall-mid-trip', $priorAt, $this->clockZero('recall-mid-trip') + $applied)
            || end($home['legs'][0]['points']) != $prior['anchor']) {
            $defects[] = 'recall-mid-trip\'s walker does not walk home from where it stood to its desk';
        } else {
            $endAt = $applied + $home['frames'] * 250;
            $seated = array_values(array_filter($this->drawn($recall), static fn ($r) => $r['at'] === $endAt && $r['trigger'] === 'walk end'));

            if ($seated === [] || ! $this->drawsCharacter($seated[0]['frame'], self::IMPL_2)
                || ($this->desk($seated[0]['frame'], self::IMPL_2)['held']['animation_id'] ?? null) !== 'A3') {
                $defects[] = 'recall-mid-trip\'s walker is not seated typing at the refresh that ends its walk home';
            }
        }

        // leave-mid-trip: A2's walk begins at the trip's point at the applying render's instant.
        $leave = $this->snackRun('leave-mid-trip', $dir);
        $was = null;
        $wasAt = null;
        $a2 = null;
        foreach ($this->drawn($leave) as $r) {
            if ($r['at'] < $applied && ($t = $this->trips($r['frame'], self::IMPL_2)) !== []) {
                [$was, $wasAt] = [$t[0], $r['at']];
            }

            if ($r['at'] === $applied && $r['trigger'] === 'render') {
                $a2 = array_values(array_filter($r['frame']['scene']['effects'], static fn ($e) => $e['animation_id'] === 'A2' && ($e['frames'] ?? 0) > 0))[0] ?? null;

                if ($this->trips($r['frame'], self::IMPL_2) !== [] || $this->drawsCharacter($r['frame'], self::IMPL_2)) {
                    $defects[] = 'leave-mid-trip\'s applying render still draws the trip or the character at the desk';
                }
            }
        }

        if ($a2 === null || $was === null
            || $a2['legs'][0]['points'][0] != $this->pointIn($was, 'leave-mid-trip', $wasAt, $this->clockZero('leave-mid-trip') + $applied)) {
            $defects[] = 'leave-mid-trip\'s A2 walk does not begin where the walker stood';
        }

        // intern-stop-mid-trip: the A10 walk begins at the intern's point on its walk out.
        $stop = $this->snackRun('intern-stop-mid-trip', $dir);
        $was = null;
        $wasAt = null;
        $a10 = null;
        $lost = 7000;
        foreach ($this->drawn($stop) as $r) {
            if ($r['at'] < $lost && ($t = $this->trips($r['frame'])) !== []) {
                [$was, $wasAt] = [$t[0], $r['at']];
            }

            if ($r['at'] === $lost && $r['trigger'] === 'render') {
                $a10 = array_values(array_filter($r['frame']['scene']['effects'], static fn ($e) => $e['animation_id'] === 'A10' && ($e['frames'] ?? 0) > 0))[0] ?? null;
            }
        }

        if ($a10 === null || $was === null
            || $a10['legs'][0]['points'][0] != $this->pointIn($was, 'intern-stop-mid-trip', $wasAt, $this->clockZero('intern-stop-mid-trip') + $lost)) {
            $defects[] = 'intern-stop-mid-trip\'s A10 walk does not begin where the intern was';
        }

        // map-moves-table and stilled-mid-trip: the trip ends on the render that applies the map, or stills the floor, and
        // the sleeper is at its desk from it on.
        foreach (['map-moves-table' => static fn (array $f): bool => ($f['rooms'][0]['map']['map_version'] ?? null) === 4,
            'stilled-mid-trip' => static fn (array $f): bool => ($f['failure']['sign_in'] ?? null) !== null] as $run => $from) {
            $reached = false;

            foreach ($this->drawn($this->snackRun($run, $dir)) as $r) {
                $reached = $reached || $from($r['frame']);

                if ($reached && ($this->trips($r['frame']) !== [] || ! $this->drawsCharacter($r['frame'], self::IMPL_2))) {
                    $defects[] = "[{$run}] at {$r['at']} ms the trip is still drawn, or the sleeper not at its desk";

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

    // ── GREEN — no corner, no route; the clock and the reload; the feed; reduced motion ───────

    /** @return list<string> */
    private function cornerDefects(?string $dir = null): array
    {
        $defects = [];
        $none = $this->snackRun('no-corner', $dir);

        if ($none['final']['event_log'] !== [] && array_filter($none['final']['event_log'], static fn ($l) => str_contains($l, 'snack')) !== []) {
            $defects[] = 'no-corner wrote a snack line';
        }

        if ($this->held($none, self::IMPL_2, 'A25') === []) {
            $defects[] = 'no-corner did not keep idle-trip\'s A25 row';
        }

        foreach ($this->drawn($none) as $r) {
            if ($this->trips($r['frame']) !== []) {
                $defects[] = "no-corner walks at {$r['at']} ms to a corner its map does not place";

                break;
            }
        }

        $route = $this->snackRun('no-route', $dir);
        $lines = array_values(array_filter($route['final']['event_log'], static fn ($l) => str_contains($l, 'snack table')));

        if (count($lines) !== 1 || ! str_ends_with($lines[0], self::IMPL_2.': '.self::LINE)) {
            $defects[] = 'no-route did not write its one line: '.json_encode($lines);
        }

        foreach ($this->drawn($route) as $r) {
            if ($this->trips($r['frame']) !== []) {
                $defects[] = "no-route walks at {$r['at']} ms";

                break;
            }
        }

        return $defects;
    }

    public function test_green_no_corner_no_route(): void
    {
        $this->assertSame([], $this->cornerDefects());
    }

    /** @return list<string> */
    private function clockDefects(?string $dir = null): array
    {
        $defects = [];
        $resync = 46000;
        $reload = $this->snackRun('reload-mid-trip', $dir);
        $after = array_values(array_filter($this->drawn($reload), static fn ($r) => $r['at'] === $resync && $r['trigger'] === 'render'));
        $standing = $after === [] ? [] : $this->trips(end($after)['frame'], self::IMPL_2);

        if ($standing === [] || $this->pointAt($standing[0], $standing[0]['elapsed_ms']) != $standing[0]['spot']['at']) {
            $defects[] = 'reload-mid-trip\'s resync render does not draw the walker standing at its spot';
        }

        $viewer = $this->snackRun('reload-mid-trip-viewer', $dir);
        $first = $this->drawn($viewer);
        $vtrip = null;
        foreach ($first as $r) {
            $vtrip ??= $this->trips($r['frame'], self::IMPL_2)[0] ?? null;
        }
        $own = $this->anyTrip($this->snackRun('idle-trip', $dir), self::IMPL_2)[1] ?? null;

        if ($vtrip === null || $own === null || $vtrip['start_s'] !== $own['start_s'] || $vtrip['spot'] != $own['spot'] || $vtrip['legs'] != $own['legs']
            || $this->pointAt($vtrip, $vtrip['elapsed_ms']) != $vtrip['spot']['at']) {
            $defects[] = 'the second viewer does not draw the same trip — its start, its spot, its walk home — standing at its spot';
        }

        $twice = $dir === null ? $this->replayRepeatedly('idle-trip', 2) : $this->replayRepeatedly('idle-trip', 2, $dir);
        $tripsOf = fn (array $r): array => array_values(array_unique(array_map(static fn ($t) => json_encode([$t['start_s'], $t['legs']]),
            array_merge(...array_map(fn ($x) => $this->trips($x['frame']), $this->drawn($r))))));

        if (count($tripsOf($twice[0])) !== 1 || $tripsOf($twice[0]) !== $tripsOf($twice[1])) {
            $defects[] = 'two replays of idle-trip do not draw one trip';
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

        foreach ($this->drawn($this->snackRun('feed-down', $dir)) as $r) {
            if ($this->trips($r['frame']) !== []) {
                $defects[] = "feed-down walks at {$r['at']} ms on a feed declared down";

                break;
            }

            if (($this->desk($r['frame'], self::IMPL_2)['held']['motion'] ?? null) !== true) {
                $defects[] = "feed-down's sleeper is not pulsing at {$r['at']} ms";

                break;
            }
        }

        $dies = $this->snackRun('feed-dies-mid-trip', $dir);
        [, $trip, $at] = $this->firstTrip($dies, self::IMPL_2);
        $end = $at - $trip['elapsed_ms'] + $trip['frames'] * 250;
        $starts = array_unique(array_merge(...array_map(fn ($r) => array_column($this->trips($r['frame']), 'start_s'), $this->drawn($dies))));
        $refreshed = array_filter($this->drawn($dies), static fn ($r) => $r['at'] === $end && $r['trigger'] === 'walk end');

        if (count($starts) !== 1 || $refreshed === []) {
            $defects[] = 'feed-dies-mid-trip did not finish its one trip';
        }

        return $defects;
    }

    public function test_green_the_feed(): void
    {
        $this->assertSame([], $this->feedDefects());
    }

    public function test_green_reduced_motion(): void
    {
        foreach (['idle-trip', 'intern-trip', 'two-visitors', 'recall-mid-trip'] as $run) {
            $r = $this->floorRun($run, null, ['reduce' => true]);
            $rows = array_values(array_filter($r['animation_log'], static fn ($x) => $x['animation_id'] === 'A25'));

            $this->assertNotSame([], $rows, "[{$run}] reduced motion wrote no A25 row");

            foreach ($rows as $row) {
                $this->assertFalse($row['motion'], "[{$run}] an A25 row under reduce has motion");
            }

            foreach ($this->drawn($r) as $x) {
                $this->assertSame([], $this->trips($x['frame']), "[{$run}] a trip under reduce");
                $this->assertEqualsCanonicalizing(['snack-table', 'snack-table-low'], array_values(array_intersect(['snack-table', 'snack-table-low'], array_column($x['frame']['scene']['scenery'], 'kind'))),
                    "[{$run}] the corner's tiles are not drawn under reduce");
            }
        }
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

    /** RED — the timer walk: the trip drawn whenever the window opens, whatever the held state. */
    public function test_red_the_timer_walk(): void
    {
        $dir = $this->planted([self::SCENE, '            eligible: model.snack_held?.motion === true,', '            eligible: true,']);

        $this->assertContains('[working-window] a walker at 5000 ms', $this->whoDefects($dir));
    }

    /** Second RED — the re-roll: the window's second from `Math.random`. */
    public function test_red_the_re_roll(): void
    {
        $dir = $this->planted([self::SCENE, '        start_s: hour * SNACK_WINDOW_S + (draw(key, `snack:${hour}`) % SNACK_WINDOW_S),',
            '        start_s: hour * SNACK_WINDOW_S + Math.floor(Math.random() * SNACK_WINDOW_S),']);

        $this->assertNotSame([], $this->clockDefects($dir), 'a re-rolled window and the clock guard saw nothing');
    }

    /** Third RED — one point: every visitor at spot 0. */
    public function test_red_one_point(): void
    {
        $dir = $this->planted([self::SCENE, '            if (taken.some((o) => (o.table === best.t.id && o.index === k)', '            if (false && taken.some((o) => (o.table === best.t.id && o.index === k)']);

        $this->assertContains("two-visitors does not stand the first at spot 0 and the second at spotsNear()'s spot 1", $this->spotDefects($dir));
    }

    /** Fourth RED — the jump home: A2's walk started from the desk's anchor. */
    public function test_red_the_jump_home(): void
    {
        $dir = $this->planted([self::SCENE, '                at === null ? null : Object.freeze({ x: at.x, y: at.y })));', '                null));']);

        $this->assertContains("leave-mid-trip's A2 walk does not begin where the walker stood", $this->interruptDefects($dir));
    }

    /** Fifth RED — a trip on a dead feed. */
    public function test_red_a_trip_on_a_dead_feed(): void
    {
        $dir = $this->planted([self::SCREEN, '            live: feedStatus(this.#client.feed) === LIVE,', '            live: true,']);

        $this->assertNotSame([], array_filter($this->feedDefects($dir), static fn ($d) => str_starts_with($d, 'feed-down walks')), 'a trip on a dead feed and the feed guard saw nothing');
    }

    /** Sixth RED — the corner placed by the client: free floor found for a table the map does not place. */
    public function test_red_the_corner_placed_by_the_client(): void
    {
        $dir = $this->planted([self::SCENE, '    const snack = snackCorner(snackTables, desks, models, anchors, walking, input, box);',
            "    const snack = snackCorner(snackTables.length > 0 ? snackTables : [Object.freeze({ id: 'found', room: null, kind: 'snack-table', x: 1704, y: 320, w: 72, h: 96, index: 0 })], desks, models, anchors, walking, input, box);"]);

        $this->assertNotSame([], array_filter($this->cornerDefects($dir), static fn ($d) => str_starts_with($d, 'no-corner walks')), 'a corner the client placed and the corner guard saw nothing');
    }

    /** Seventh RED — the episode on the desk's key. */
    public function test_red_the_episode_on_the_desks_key(): void
    {
        $dir = $this->planted(['animation-set.js', "    Object.freeze({ suffix: SNACK, field: 'snack_held', prior: 'prior_snack' }),", "    Object.freeze({ suffix: '', field: 'snack_held', prior: 'prior_snack' }),"]);

        $this->assertNotSame([], $this->rowDefects($dir), "A25 on the desk's own key and the row guard saw nothing");
    }

    /** Eighth RED — the z's over the empty chair: the sleeper kept on the desk while its walker is away. */
    public function test_red_the_zs_over_the_empty_chair(): void
    {
        $dir = $this->planted([self::SCREEN, '            for (const key of [...inbound, ...away]) {', '            for (const key of inbound) {']);

        $this->assertNotSame([], array_filter($this->tripDefects($this->floorRun('idle-trip', $dir), 'idle-trip'), static fn ($d) => str_contains($d, 'draws its sleeper while its walker')),
            'the sleeper drawn under its own walker and the trip guard saw nothing');
    }

    /**
     * Ninth RED — an absent field read as idle: an element under a reporter too old to send `subagent.activity` (its seat
     * badged `update_required`; D2 leaves the element at its `working` default) read as resting rather than at its word.
     */
    public function test_red_an_absent_field_read_as_idle(): void
    {
        $dir = $this->planted([self::DESK_RENDER, '            activity: s.activity_state ?? null,',
            "            activity: (seat.badges ?? []).includes('update_required') ? 'idle' : (s.activity_state ?? null),"]);

        $this->assertContains('[intern-old-reporter] a walker at 5000 ms', $this->whoDefects($dir));
    }

    /** Tenth RED — the third visitor squeezed in past the last spot. */
    public function test_red_the_third_visitor_squeezed_in(): void
    {
        $dir = $this->planted([self::SCENE, "            if (s === null) {\n                return Object.freeze({ full: true });\n            }",
            "            if (s === null) {\n                k = -1;\n                taken = [];\n\n                continue;\n            }"]);

        $this->assertNotSame([], array_filter($this->spotDefects($dir), static fn ($d) => str_contains($d, 'past-the-last-spot')), 'a third visitor squeezed in and the spot guard saw nothing');
    }

    /** Eleventh RED — the recall cut home (the operator ruled the walk back, 2026-11-02): the trip ended on the recall. */
    public function test_red_the_recall_cut_home(): void
    {
        $dir = $this->planted([self::IN_FLIGHT, '            if (w.recalled) {', '            if (false) {']);

        $this->assertContains("recall-mid-trip's walker does not walk home from where it stood to its desk", $this->interruptDefects($dir));
    }
}

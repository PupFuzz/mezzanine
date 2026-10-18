<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **AT-D3-26's painter leg — the elevator is a control on the drawing** (`docs/design/FLOOR.md` § 4.5,
 * card#11795; Appendix B row 23), over a fake DOM (`elevator-control-probe.mjs`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ELEVATOR IS A BUTTON OVER ITS SURROUND: `role="button"`, a tab stop, `data-key="elevator"` (so the
 * painter's focus restore keeps the keyboard on it across a repaint), an accessible name, and a hit area that
 * is the surround — frame, header and call buttons. A click, Enter and Space each ride once; Tab rides nothing.
 * While a ride is in flight it says so (`aria-disabled`).
 *
 * ⛔ THE LEAVES ARE THE RIDE's WHILE A RIDE HOLDS THEM, PER PHASE, AND NEVER RESTART. `opening` opens them,
 * placed at the phase's elapsed time (a negative delay); `entering` to `leaving` hold them open with no
 * animation; `staying` and `arriving` draw them open and close them once the phase's fade is over — a delay of
 * the door delay less the elapsed time. A second paint 300 ms later moves every delay by 300 ms, so a
 * re-paint mid-phase lands mid-motion. A walk's door span adds nothing while a ride holds the leaves, and
 * with no ride the walks' leaves are what they were.
 *
 * ⛔ THE CAB (`paintCab()`) draws two leaves — closing on `entering`, opening on `chosen`, each after the door
 * delay — and no text; `null` empties it.
 *
 * Every motion's step count is § 12's *Elevator leaves* row, read out of the document.
 */
class TheElevatorIsAControlOnTheDrawingTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor') ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/elevator-control-probe.mjs';
    }

    public function test_the_elevator_is_a_button_over_its_surround(): void
    {
        $this->assertSame([], $this->controlDefects($this->run_()));
    }

    public function test_the_band_leaves_follow_each_phase_without_restarting(): void
    {
        $this->assertSame([], $this->leafDefects($this->run_()));
    }

    public function test_the_cab_draws_two_leaves_and_no_words(): void
    {
        $this->assertSame([], $this->cabDefects($this->run_()));
    }

    /** ⛔ THE CONTROLS — each plants one defect in a copy of the shipped painter. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        foreach ([
            'no role' => ["                role: 'button',\n                'aria-label': 'the elevator", "                'aria-label': 'the elevator", 'controlDefects'],
            'no tab stop' => ["                'data-key': 'elevator',\n                tabindex: 0,", "                'data-key': 'elevator',", 'controlDefects'],
            'no key' => ["                'data-key': 'elevator',", "                'data-key': 'lift',", 'controlDefects'],
            'no name' => ["                'aria-label': 'the elevator — ride to another floor',", '', 'controlDefects'],
            'Space does not ride' => ["            control.addEventListener('keydown', (event) => {\n                if (event.key === 'Enter' || event.key === ' ') {", "            control.addEventListener('keydown', (event) => {\n                if (event.key === 'Enter') {", 'controlDefects'],
            'the hit area the frame' => ['node(\'rect\', { x: e.surround.x, y: e.surround.y, width: e.surround.w, height: e.surround.h, class: \'elevator-hit\' }', 'node(\'rect\', { x: e.frame.x, y: e.frame.y, width: e.frame.w, height: e.frame.h, class: \'elevator-hit\' }', 'controlDefects'],
            'never disabled' => ["                'aria-disabled': riding ? 'true' : null,", '', 'controlDefects'],
            'no negative delay' => ['    return { delay: doorDelay - ride.elapsed_ms, motion: ride.ms - doorDelay };', '    return { delay: Math.max(0, doorDelay - ride.elapsed_ms), motion: ride.ms - doorDelay };', 'leafDefects'],
            'the delay without the door offset' => ['    return { delay: doorDelay - ride.elapsed_ms, motion: ride.ms - doorDelay };', '    return { delay: -ride.elapsed_ms, motion: ride.ms - doorDelay };', 'leafDefects'],
            'the leaves closing inside' => ["            // `entering`, `inside`, `chosen`, `leaving`: the floor's leaves stand open behind the cab.\n            return 'transform:scaleX(.12)';",
                '            return `animation:leaf-close ${motion}ms steps(${D.close}) ${delay}ms both`;', 'leafDefects'],
            'a walk adding to the ride' => ["            const leaves = riding\n                ? rideLeaves(scene.ride)", "            const leaves = riding && (scene.doors ?? []).length === 0\n                ? rideLeaves(scene.ride)", 'leafDefects'],
            'a word in the cab' => ["        const style = cabLeaves(ride);\n", "        const style = cabLeaves(ride);\n\n        node('text', {}, cab).textContent = 'B';\n", 'cabDefects'],
            'the cab doors not closing on entering' => ["        case 'entering':\n            return ride.ms === 0 ? null : `animation:leaf-close", "        case 'entering':\n            return ride.ms === 0 ? null : `animation:leaf-open", 'cabDefects'],
        ] as $what => [$from, $to, $check]) {
            $dir = $this->mutatedModules(['painter.js', $from, $to]);
            $this->assertNotSame([], $this->{$check}($this->run_(dirname($dir))), "CONTROL ({$what}) did not bite");
        }
    }

    /** @return array<string, mixed> */
    private function run_(?string $jsRoot = null): array
    {
        $out = [];
        exec('node '.escapeshellarg($this->probeScript()).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "the elevator probe failed:\n".implode("\n", $out));

        return json_decode(implode("\n", $out), true);
    }

    /** @return array{open: int, step: int, close: int} § 12's *Elevator leaves* row */
    private function door(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Elevator leaves* row in the form this test reads');

        return ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
    }

    /** @return list<string> */
    private function controlDefects(array $run): array
    {
        $c = $run['control'];
        $defects = [];

        if (! $c['found']) {
            return ['the band draws no [data-key="elevator"]'];
        }

        if ($c['attrs']['role'] !== 'button' || $c['attrs']['tabindex'] !== '0' || ($c['attrs']['aria_label'] ?? '') === '') {
            $defects[] = 'the elevator is not a named tab-stop button: '.json_encode($c['attrs']);
        }

        if ($c['hit'] !== $c['surround']) {
            $defects[] = 'the hit area is not the surround: '.json_encode($c['hit']);
        }

        if ($c['presses'] !== ['click' => 1, 'enter' => 1, 'space' => 1, 'tab' => 0]) {
            $defects[] = 'presses: '.json_encode($c['presses']).' — one each for a click, Enter and Space, none for Tab';
        }

        if ($c['attrs']['aria_disabled'] !== null || $c['disabled_riding'] !== 'true') {
            $defects[] = 'aria-disabled is not set while a ride is in flight, and only then';
        }

        return $defects;
    }

    /** @return list<string> */
    private function leafDefects(array $run): array
    {
        $d = $this->door();
        $e = $run['elapsed'];
        $defects = [];
        $held = 'transform:scaleX(.12)';
        // The probe's ride: 750 ms, the doors 250 ms in (opening: 500, at once).
        $expect = [
            'opening' => [fn ($t) => "animation:leaf-open 500ms steps({$d['open']}) ".(0 - $t).'ms forwards'],
            'entering' => [fn () => $held], 'inside' => [fn () => $held], 'chosen' => [fn () => $held], 'leaving' => [fn () => $held],
            'staying' => [fn ($t) => "animation:leaf-close 500ms steps({$d['close']}) ".(250 - $t).'ms both'],
            'arriving' => [fn ($t) => "animation:leaf-close 500ms steps({$d['close']}) ".(250 - $t).'ms both'],
        ];

        foreach ($expect as $phase => [$style]) {
            foreach (['at' => $e, 'later' => $e + 300] as $when => $t) {
                if ($run['leaves'][$phase][$when] !== [$style($t), $style($t)]) {
                    $defects[] = "{$phase} at {$t} ms: ".json_encode($run['leaves'][$phase][$when]).' — expected '.$style($t);
                }
            }
        }

        $walk = 'animation:leaf-open '.($d['open'] * 0.25).'s steps('.$d['open'].') '.(3 * 0.25).'s forwards,'
            .'leaf-close '.($d['close'] * 0.25).'s steps('.$d['close'].') '.((8 - $d['close']) * 0.25).'s forwards';

        if ($run['walks']['no_ride'] !== [$walk, $walk] || $run['walks']['no_doors'] !== [null, null]) {
            $defects[] = 'with no ride the walks\' leaves are not what they were: '.json_encode($run['walks']);
        }

        if ($run['walks']['riding'] !== $run['walks']['riding_no_walk']) {
            $defects[] = 'a walk\'s door span changed the leaves a ride holds';
        }

        return $defects;
    }

    /** @return list<string> */
    private function cabDefects(array $run): array
    {
        $d = $this->door();
        $e = $run['elapsed'];
        $defects = [];
        $expect = [
            'entering' => 'animation:leaf-close 500ms steps('.$d['close'].') '.(250 - $e).'ms both',
            'chosen' => 'animation:leaf-open 500ms steps('.$d['open'].') '.(250 - $e).'ms both',
            'inside' => null,
            'leaving' => 'transform:scaleX(.12)',
        ];

        foreach ($expect as $phase => $style) {
            $cab = $run['cab'][$phase];

            if ($cab['leaves'] !== [$style, $style]) {
                $defects[] = "{$phase}: the cab's leaves are ".json_encode($cab['leaves']);
            }

            if ($cab['texts'] !== 0) {
                $defects[] = "{$phase}: the cab draws {$cab['texts']} <text>";
            }
        }

        if ($run['cab']['emptied']['children'] !== 0) {
            $defects[] = 'paintCab(null) leaves the cab drawn';
        }

        return $defects;
    }
}

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
 * ⛔ THE CAB IS IN THE HOUSE THEME's ELEVATOR PALETTE, AND FITTED WHOLE (card#12039). `cabTheme()` carries the shipped
 * house theme's `PALETTE.elevator` frame and lamp onto the dialog as `--cab-frame` / `--cab-lamp` — the one carrier
 * the drawing and the button popup both read — and marks it `data-themed` (card#12047: what turns the popup from the
 * page's own panel to the frame); a painter holding no theme removes all three. The cab's frame and lamp
 * are painted from those properties, never an inline colour; its space is fitted with `meet`, never cropped with
 * `slice`, its overflow visible so the wall and floor run on past the door, and its shape handed to the layout as
 * `--cab-aspect`. That the whole door is then IN THE WINDOW is a browser's to measure: `tools/design/floor-ride.browser.mjs`'s
 * `cab-fit` row, run by hand.
 *
 * ⛔ THE RIDE's DOORS MOVE CONTINUOUSLY, AND THE WALKS' STEP (card#12038). Every ride motion — band and cab — eases
 * (`ease-in-out`) over the length the phase hands it, and no ride style carries `steps(`: the ride is § 6.3's
 * exception, which is not claim-bearing, so no loop rate binds it (§ 12's *Ride door motion*). The walks' leaves are
 * claim-bearing and keep § 12's *Elevator leaves* frames, read out of the document, at the loop rate with `steps()`.
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

    public function test_the_cab_is_in_the_house_elevator_palette_and_fitted_whole(): void
    {
        $this->assertSame([], $this->paletteDefects($this->run_()));
    }

    /** No ride door style — the band's in any phase, the cab's in any phase — is quantised with `steps(` (card#12038). */
    public function test_the_ride_doors_never_step(): void
    {
        $run = $this->run_();

        $this->assertSame([], $this->steppedDefects($run));
        $this->assertNotSame([], array_filter($this->rideStyles($run), static fn ($s) => str_contains((string) $s, 'animation:')),
            'no ride style carried an animation — the check measured nothing');
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
                '            return `animation:leaf-close ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms both`;', 'leafDefects'],
            'a walk adding to the ride' => ["            const leaves = riding\n                ? rideLeaves(scene.ride)", "            const leaves = riding && (scene.doors ?? []).length === 0\n                ? rideLeaves(scene.ride)", 'leafDefects'],
            'a word in the cab' => ["        const style = cabLeaves(ride);\n", "        const style = cabLeaves(ride);\n\n        node('text', {}, cab).textContent = 'B';\n", 'cabDefects'],
            // card#12038's RED: the ride's doors quantised again — by any step count — red the step check itself.
            'the ride doors stepped' => ["const RIDE_DOOR_EASING = 'ease-in-out';", "const RIDE_DOOR_EASING = 'steps(2)';", 'steppedDefects'],
            'the ride doors stepped, the cab alone' => ["        case 'chosen':\n".'            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING}',
                "        case 'chosen':\n".'            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms steps(4)', 'steppedDefects'],
            'the band doors linear' => ['            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms forwards`;',
                '            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms linear ${delay}ms forwards`;', 'leafDefects'],
            // card#12039's REDs: the cab cropped again, the palette not carried, a stale palette kept, the frame or the
            // lamp painted off the carrier.
            'the cab sliced' => ["preserveAspectRatio: 'xMidYMid meet'", "preserveAspectRatio: 'xMidYMid slice'", 'paletteDefects'],
            'the palette not carried' => ["        el.style.setProperty('--cab-frame', palette.frame);\n", '', 'paletteDefects'],
            'a stale palette kept' => ["            el.style.removeProperty('--cab-lamp');\n", '', 'paletteDefects'],
            // card#12047's: the dialog never marked themed, or marked with no theme loaded.
            'the dialog not marked' => ["        el.toggleAttribute('data-themed', palette !== null);\n", '', 'paletteDefects'],
            'marked with no theme' => ["        el.toggleAttribute('data-themed', palette !== null);", "        el.toggleAttribute('data-themed', true);", 'paletteDefects'],
            'the frame off the palette' => ['.cab-frame{fill:var(--cab-frame)}', '.cab-frame{fill:var(--door-frame)}', 'paletteDefects'],
            'the lamp off the palette' => ['.cab-lamp{fill:var(--cab-lamp)}', '.cab-lamp{fill:var(--lamp)}', 'paletteDefects'],
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
        // The probe's ride: 750 ms, the doors 250 ms in (opening: 500, at once) — the painter moves them over whatever
        // the phase hands it, eased; how long that is is `ridePhase()`'s (`TheFloorsRideIsNavigationTest`).
        $expect = [
            'opening' => [fn ($t) => 'animation:leaf-open 500ms ease-in-out '.(0 - $t).'ms forwards'],
            'entering' => [fn () => $held], 'inside' => [fn () => $held], 'chosen' => [fn () => $held], 'leaving' => [fn () => $held],
            'staying' => [fn ($t) => 'animation:leaf-close 500ms ease-in-out '.(250 - $t).'ms both'],
            'arriving' => [fn ($t) => 'animation:leaf-close 500ms ease-in-out '.(250 - $t).'ms both'],
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
        $e = $run['elapsed'];
        $defects = [];
        $expect = [
            'entering' => 'animation:leaf-close 500ms ease-in-out '.(250 - $e).'ms both',
            'chosen' => 'animation:leaf-open 500ms ease-in-out '.(250 - $e).'ms both',
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

    /** @return list<string> */
    private function paletteDefects(array $run): array
    {
        $p = $run['palette'];
        $defects = [];
        $palette = $p['palette'];

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) ($palette['frame'] ?? ''), 'the house theme has no elevator frame colour this check can read');

        if ($p['carried'] !== ['--cab-frame' => $palette['frame'], '--cab-lamp' => $palette['lamp']]) {
            $defects[] = 'cabTheme() carried '.json_encode($p['carried']).' onto the dialog, not the house elevator palette '.json_encode($palette);
        }

        if ($p['unthemed'] !== []) {
            $defects[] = 'with no theme, cabTheme() left '.json_encode($p['unthemed']).' on the dialog';
        }

        if ($p['marked'] !== ['data-themed' => '']) {
            $defects[] = 'with the house theme, cabTheme() left the dialog\'s attributes '.json_encode($p['marked']).', not marked data-themed';
        }

        if ($p['unmarked'] !== []) {
            $defects[] = 'with no theme, cabTheme() left '.json_encode($p['unmarked']).' on the dialog — the popup would draw as themed';
        }

        if ($p['fit'] !== 'xMidYMid meet' || $p['overflow'] !== 'visible') {
            $defects[] = "the cab is fitted {$p['fit']} with overflow {$p['overflow']} — not whole, with the wall running on";
        }

        if ($p['viewBox'] === null || $p['aspect'] !== str_replace(['0 0 ', ' '], ['', ' / '], $p['viewBox'])) {
            $defects[] = "the cab's shape handed to the layout ({$p['aspect']}) is not its space's ({$p['viewBox']})";
        }

        foreach (['frame', 'lamp'] as $part) {
            if ($p[$part] === null || $p[$part]['fill'] !== null) {
                $defects[] = "the cab's {$part} is ".json_encode($p[$part]).' — drawn, and painted by its class, never inline';
            }

            if (! str_contains($p['style'], ".cab-{$part}{fill:var(--cab-{$part})}")) {
                $defects[] = "the cab's style paints .cab-{$part} from something other than --cab-{$part}";
            }
        }

        return $defects;
    }

    /** @return list<string|null> every style the ride gives a leaf: the band's in each phase at both times, and the cab's */
    private function rideStyles(array $run): array
    {
        $styles = [];

        foreach ($run['leaves'] as $times) {
            foreach ($times as $leaves) {
                array_push($styles, ...$leaves);
            }
        }

        foreach (['entering', 'inside', 'chosen', 'leaving'] as $phase) {
            array_push($styles, ...$run['cab'][$phase]['leaves']);
        }

        return $styles;
    }

    /** @return list<string> every ride leaf style quantised with `steps(` — the walks' form, which the ride never takes */
    private function steppedDefects(array $run): array
    {
        return array_values(array_map(static fn ($s) => "a ride door is stepped: {$s}",
            array_filter($this->rideStyles($run), static fn ($s) => str_contains((string) $s, 'steps('))));
    }
}

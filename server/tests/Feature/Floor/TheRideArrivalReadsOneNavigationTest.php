<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **AT-D3-26's arrival leg — the destination reads one navigation** (`docs/design/FLOOR.md` § 4.5, card#11795;
 * Appendix B row 23). The layout's head script, read out of a page the layout rendered, and the module half
 * (`wire/ride-arrival.js`), run under `node` against stand-ins for every browser global the script reads
 * (`ride-arrival-probe.mjs`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A RIDE IS `#ride` ON A `navigate`: a reload, Back or Forward, no fragment or another one is no ride. The
 * fragment is a parameter of one navigation (§ 4.5), so it is stripped — the bare path, the query kept — and
 * only `#ride`, never another fragment; inside the `pagereveal` handler where the event exists, synchronously where
 * it does not. (The design placed the strip in the handler on a measurement of a strip before the event dropping the
 * inbound transition; `tools/design/floor-ride.browser.mjs` did not reproduce that on its headless shell, so this
 * holds the placement the design chose, which costs nothing, and not a property shown to be needed.)
 *
 * ⛔ THE HOLD IS FOR A RIDE INTO A PAGE THAT DECLARES THE REVEAL, AND NOT UNDER REDUCED MOTION: every other
 * transition is skipped. A held reveal adds `ride-holding` and arms ONE bound from the script's own `t0`
 * (`t0 + CAB_HOLD_MS − now`). The ride is consumed by the FIRST reveal, whatever it carried: a later reveal —
 * a back-forward restore — skips, adds no class and arms no timer.
 *
 * ⛔ THE MODULE HALF: `arrival()` answers the flag's remaining life from the recorded `t0`, or `null`; `revealed()`
 * swaps `ride-holding` for `ride-arrived` and does nothing to a page that never held or has already arrived; the
 * bound firing after it changes nothing.
 */
class TheRideArrivalReadsOneNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** Every gate row: [hash, navigationType, reveals, reduce, hasPageRevealEvent]. */
    private const GATES = [
        'a ride' => ['#ride', 'navigate', true, false, true],
        'a reload' => ['#ride', 'reload', true, false, true],
        'back or forward' => ['#ride', 'back_forward', true, false, true],
        'no fragment' => ['', 'navigate', true, false, true],
        'another fragment' => ['#seat', 'navigate', true, false, true],
        'a page that declares no reveal' => ['#ride', 'navigate', false, false, true],
        'reduced motion' => ['#ride', 'navigate', true, true, true],
        'no PageRevealEvent' => ['#ride', 'navigate', true, false, false],
        'no PageRevealEvent, no ride' => ['#seat', 'navigate', true, false, false],
    ];

    public function test_the_gate_the_strip_and_the_hold(): void
    {
        $this->assertSame([], $this->gateDefects($this->headScript()));
    }

    public function test_the_first_reveal_consumes_the_ride(): void
    {
        $this->assertSame([], $this->consumptionDefects($this->headScript()));
    }

    public function test_the_module_half(): void
    {
        $run = $this->run_($this->headScript(), [$this->row('a ride', ['transition'], true)]);
        $m = $run['module'];
        $defects = [];

        if ($m['arrival_ride'] !== ['expires_in_ms' => $run['clock']['t0'] + $m['cab_hold_ms'] - ($run['clock']['reveal'] + 20)]) {
            $defects[] = 'arrival() answered '.json_encode($m['arrival_ride']);
        }

        if ($m['arrival_none'] !== null) {
            $defects[] = 'arrival() answered a page entered by no ride';
        }

        if ($m['revealed_held'] !== ['ride-arrived'] || $m['revealed_arrived'] !== ['ride-arrived'] || $m['revealed_never'] !== []) {
            $defects[] = 'revealed(): '.json_encode([$m['revealed_held'], $m['revealed_arrived'], $m['revealed_never']]);
        }

        if (($run['cases'][0]['after_bound'] ?? null) !== ['ride-arrived']) {
            $defects[] = 'the bound firing after the module ended the hold changed the classes: '.json_encode($run['cases'][0]['after_bound'] ?? null);
        }

        $this->assertSame([], $defects);
    }

    /** ⛔ THE CONTROLS — each plants one defect in the script's text as the layout rendered it. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $script = $this->headScript();

        foreach ([
            'the type gate dropped' => [" && entry !== undefined && entry.type === 'navigate';", ';', 'gateDefects'],
            'the strip before the event' => ["        if (!('PageRevealEvent' in window)) {\n            strip();\n        }", '        strip();', 'gateDefects'],
            'the strip of any fragment' => ["            if (location.hash === '#ride') {\n                history.replaceState", "            if (location.hash !== '') {\n                history.replaceState", 'gateDefects'],
            'the skip not scoped to the reveal' => ['            if (!(arriving && reveals) || reduce) {', '            if (!arriving || reduce) {', 'gateDefects'],
            'the reduce skip dropped' => ['            if (!(arriving && reveals) || reduce) {', '            if (!(arriving && reveals)) {', 'gateDefects'],
            'the bound from the reveal' => ['setTimeout(revealed, Math.max(0, t0 + CAB_HOLD_MS - performance.now()));', 'setTimeout(revealed, CAB_HOLD_MS);', 'gateDefects'],
            'no arrival time' => ['            html.dataset.rideArrivalAt = String(t0);', '', 'gateDefects'],
            'the second-reveal skip dropped' => ["            var arriving = ride;\n\n            ride = false;", '            var arriving = ride;', 'consumptionDefects'],
            'the ride consumed after the first return' => ["            var arriving = ride;\n\n            ride = false;\n            strip();\n\n            if (!event.viewTransition) {\n                return;\n            }\n",
                "            var arriving = ride;\n\n            strip();\n\n            if (!event.viewTransition) {\n                return;\n            }\n\n            ride = false;\n", 'consumptionDefects'],
        ] as $what => [$from, $to, $check]) {
            $this->assertSame(1, substr_count($script, $from), "the {$what} control's anchor is not in the script exactly once");
            $this->assertNotSame([], $this->{$check}(str_replace($from, $to, $script)), "CONTROL ({$what}) did not bite");
        }

        // `revealed()` adding a class to a page that never held.
        $module = (string) file_get_contents(public_path('js/wire/ride-arrival.js'));
        $planted = str_replace("    html.classList.replace('ride-holding', 'ride-arrived');", "    html.classList.add('ride-arrived');", $module);
        $this->assertNotSame($planted, $module);
        $path = (string) tempnam(sys_get_temp_dir(), 'ride').'.mjs';
        file_put_contents($path, $planted);

        try {
            $m = $this->run_($script, [$this->row('a ride', ['transition'], true)], $path)['module'];
            $this->assertNotSame([], $m['revealed_never'], 'CONTROL (revealed() adding a class to a page that never held) did not bite');
        } finally {
            unlink($path);
        }
    }

    /** @return list<string> */
    private function gateDefects(string $script): array
    {
        $cases = [];

        foreach (array_keys(self::GATES) as $name) {
            $cases[] = $this->row($name, ['transition']);
        }

        $run = $this->run_($script, $cases);
        $defects = [];
        $hold = $run['module']['cab_hold_ms'];

        foreach (array_keys(self::GATES) as $i => $name) {
            [$hash, $type, $reveals, $reduce, $event] = self::GATES[$name];
            $r = $run['cases'][$i];
            $ride = $hash === '#ride' && $type === 'navigate';
            $strip = $hash === '#ride' ? ['/floor/zeta?x=1'] : [];

            if ($r['arrival'] !== ($ride ? 'ride' : 'none')) {
                $defects[] = "[{$name}] data-ride-arrival is {$r['arrival']}";
            }

            if ($ride && $r['arrival_at'] !== (string) $run['clock']['t0']) {
                $defects[] = "[{$name}] data-ride-arrival-at is ".json_encode($r['arrival_at']).' — the script\'s t0';
            }

            if ($r['listeners'] !== ['pagereveal']) {
                $defects[] = "[{$name}] the script registers ".json_encode($r['listeners']);
            }

            // The strip: synchronous only with no PageRevealEvent, else inside the first reveal; only `#ride`.
            if ($r['replaced_before'] !== ($event ? [] : $strip) || $r['reveals'][0]['replaced'] !== ($event ? $strip : [])) {
                $defects[] = "[{$name}] the strip: before ".json_encode($r['replaced_before']).', in the reveal '.json_encode($r['reveals'][0]['replaced']);
            }

            $holds = $ride && $reveals && ! $reduce;
            $first = $r['reveals'][0];

            if ($holds && ($first['skipped'] !== 0 || $first['classes'] !== ['ride-holding'] || $first['timers'] !== [$run['clock']['t0'] + $hold - $run['clock']['reveal']])) {
                $defects[] = "[{$name}] a ride into a reveal: ".json_encode($first).' — held, one bound from t0';
            }

            if (! $holds && ($first['skipped'] !== 1 || $first['classes'] !== [] || $first['timers'] !== [])) {
                $defects[] = "[{$name}] no hold due: ".json_encode($first).' — skipped, no class, no timer';
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function consumptionDefects(string $script): array
    {
        $run = $this->run_($script, [
            $this->row('a ride', ['none', 'transition']),
            $this->row('a ride', ['transition', 'transition']),
        ]);
        $defects = [];

        [$nullFirst, $twice] = $run['cases'];

        if ($nullFirst['reveals'][0] !== ['skipped' => 0, 'replaced' => ['/floor/zeta?x=1'], 'timers' => [], 'classes' => []]
            || $nullFirst['reveals'][1] !== ['skipped' => 1, 'replaced' => [], 'timers' => [], 'classes' => []]) {
            $defects[] = 'a reveal with no transition, then one with: '.json_encode($nullFirst['reveals']).' — the second skipped, no class, no timer';
        }

        if ($twice['reveals'][1]['skipped'] !== 1 || $twice['reveals'][1]['timers'] !== []) {
            $defects[] = 'a held reveal, then another: '.json_encode($twice['reveals'][1]).' — the second skipped, no timer';
        }

        return $defects;
    }

    /** @return array<string, mixed> one probe case */
    private function row(string $name, array $fired, bool $boundAfterModule = false): array
    {
        [$hash, $type, $reveals, $reduce, $event] = self::GATES[$name];

        return ['hash' => $hash, 'navigationType' => $type, 'reveals' => $reveals, 'reduce' => $reduce,
            'hasPageRevealEvent' => $event, 'reveals_fired' => $fired, 'bound_after_module' => $boundAfterModule];
    }

    /** @return array<string, mixed> */
    private function run_(string $script, array $cases, ?string $module = null): array
    {
        $payload = json_encode(['script' => $script, 'module' => $module ?? public_path('js/wire/ride-arrival.js'), 'cases' => $cases]);
        $process = proc_open(['node', __DIR__.'/ride-arrival-probe.mjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        $this->assertIsResource($process);
        fwrite($pipes[0], (string) $payload);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $rc = proc_close($process);

        $this->assertSame(0, $rc, "the arrival probe failed:\n{$err}");

        return json_decode((string) $out, true);
    }

    /** The head script's text, as the layout rendered it on the floor page. */
    private function headScript(): string
    {
        $html = $this->actingAs(User::factory()->twoFactorConfirmed()->create())->get('/floor/aimla')->assertOk()->getContent() ?: '';

        $this->assertSame(1, preg_match('/<head>.*?<script>(.*?)<\/script>/s', $html, $m), 'the floor page carries no classic inline script in its <head>');

        return $m[1];
    }
}

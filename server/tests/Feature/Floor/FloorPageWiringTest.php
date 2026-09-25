<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The floor page's own gate** — `docs/design/FLOOR.md` Appendix B row 8: "every element the DOM
 * entry addresses exists on the Blade view and every element the view declares is written into, the
 * view serves the entry as a module whose every import resolves, and a control plants each defect
 * the check exists to catch and watches it red". Shaped like `Tests\Feature\Lobby\LobbyPageWiringTest`.
 * card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT READS THE FLOOR PAGE ALONE — the served view and `public/js/floor/main.js` — and names no
 * fixture and not the harness, as the row says. Every fact the page draws is asserted headlessly
 * elsewhere; what this holds is the contract between the page's two halves, which no browser on the
 * build host can witness.
 *
 * ⛔ AND THE TWO THINGS ROW 8 SAYS THE PAGE MUST CARRY THAT NOTHING ELSE CAN CHECK: that the client
 * protocol is constructed WITH its scheduler — a real `EventSource` without the stream recovery
 * inherits the browser's own reconnect (row 8's ⛔) — and that the animation log is constructed with
 * § 12's retention figure (§ 14 item 26), re-derived from § 12 rather than copied here.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that anything renders, lays out or is legible.
 */
class FloorPageWiringTest extends TestCase
{
    use DrivesAShippedClientModule;
    use RefreshDatabase;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor')
            ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/fleet-client-probe.mjs';
    }

    public function test_every_element_the_entry_addresses_exists_on_the_page_and_the_reverse(): void
    {
        $this->assertSame([], $this->wiringDefects($this->floorPage(), $this->mainJs()));
    }

    public function test_the_page_serves_the_entry_as_a_module_and_every_import_resolves(): void
    {
        $html = $this->floorPage();

        $this->assertStringContainsString('type="module"', $html);
        $this->assertStringContainsString('/js/floor/main.js', $html, 'the page does not load the floor entry');
        $this->assertStringContainsString('data-floor="aimla"', $html, 'the route did not hand the page its floor segment');
        $this->assertGreaterThan(0, $this->assertEveryRelativeImportResolves($this->moduleDir()),
            'no relative import was found — the check measured nothing');
    }

    /** § 4.4: the route is inside the `auth` + `mfa` group, as the lobby is. */
    public function test_the_route_is_behind_login_and_the_second_factor(): void
    {
        $this->get('/floor/aimla')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->twoFactorUnenrolled()->create())
            ->get('/floor/aimla')
            ->assertRedirect(route('two-factor.enroll'));
    }

    public function test_the_entry_constructs_the_protocol_with_its_recovery_and_the_log_with_section_12s_bound(): void
    {
        $this->assertSame([], $this->bindingDefects($this->mainJs()));
    }

    /** ⛔ THE CONTROLS — each re-mints one defect this file exists to catch. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $html = $this->floorPage();
        $js = $this->mainJs();

        $renamed = str_replace('id="floor-feed"', 'id="floor-feeed"', $html);
        $this->assertNotSame($renamed, $html);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($renamed, $js),
            'CONTROL (renamed element) did not bite');

        $orphaned = str_replace('<p id="floor-kept" hidden></p>', '<p id="floor-kept" hidden></p><p id="floor-orphan"></p>', $html);
        $this->assertNotSame($orphaned, $html);
        $this->assertArrayHasKey('unwritten', $this->wiringDefects($orphaned, $js),
            'CONTROL (an element nothing writes into) did not bite');

        $widened = $this->mutatedModules(['../lobby/lobby-model.js',
            "    return [\n        {\n            key: 'store',",
            "    return [\n        { key: 'purge', label: 'purge', member: 'fleet.purge', value: 'ok', detail: null },\n        {\n            key: 'store',"]);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($html, $js, $widened),
            'CONTROL (a fifth indicator with no element) did not bite — the computed ids are not derived from the model');

        $unscheduled = str_replace('new FleetClient(pageFetch, PageEventSource, clock, timers)', 'new FleetClient(pageFetch, PageEventSource, clock)', $js);
        $this->assertNotSame($unscheduled, $js);
        $this->assertArrayHasKey('recovery', $this->bindingDefects($unscheduled),
            'CONTROL (the protocol constructed without its scheduler) did not bite');

        $unbounded = str_replace('createAnimationLog(ANIMATION_LOG_RETENTION)', 'createAnimationLog()', $js);
        $this->assertNotSame($unbounded, $js);
        $this->assertArrayHasKey('retention', $this->bindingDefects($unbounded),
            'CONTROL (the log constructed with no bound) did not bite');

        $drifted = str_replace('const ANIMATION_LOG_RETENTION = 2000;', 'const ANIMATION_LOG_RETENTION = 5000;', $js);
        $this->assertNotSame($drifted, $js);
        $this->assertArrayHasKey('retention', $this->bindingDefects($drifted),
            'CONTROL (a retention figure that is not § 12\'s) did not bite');
    }

    /** @return array<string, string> */
    private function wiringDefects(string $html, string $js, ?string $jsRoot = null): array
    {
        $declared = $this->declaredIds($html);
        $addressed = $this->addressedIds($js, $jsRoot);

        $this->assertGreaterThan(10, count($declared), 'the page declares almost no floor elements — the parse has stopped reading it');
        $this->assertGreaterThan(10, count($addressed), 'the entry addresses almost no elements — the parse has stopped reading main.js');

        $defects = [];
        $undeclared = array_values(array_diff($addressed, $declared));
        $unwritten = array_values(array_diff($declared, $this->accountedFor($html, $addressed)));

        if ($undeclared !== []) {
            $defects['undeclared'] = 'main.js writes into elements the page does not declare: '.implode(', ', $undeclared);
        }

        if ($unwritten !== []) {
            $defects['unwritten'] = 'the page declares elements nothing writes into: '.implode(', ', $unwritten);
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function bindingDefects(string $js): array
    {
        $defects = [];

        if (preg_match('/new FleetClient\(\s*\w+,\s*\w+,\s*\w+,\s*timers\s*\)/', $js) !== 1
            || preg_match('/const timers = \{\s*after:/', $js) !== 1) {
            $defects['recovery'] = 'the client protocol is not constructed with its scheduler';
        }

        $this->assertSame(1, preg_match('/^\| The floor page\'s animation-log retention \| \*\*([\d,]+) rows\*\*/m', $this->floorMd(), $m),
            '§ 12\'s retention row did not parse');

        if (preg_match('/const ANIMATION_LOG_RETENTION = (\d+);/', $js, $c) !== 1
            || (int) $c[1] !== (int) str_replace(',', '', $m[1])
            || ! str_contains($js, 'createAnimationLog(ANIMATION_LOG_RETENTION)')) {
            $defects['retention'] = 'the animation log is not constructed with § 12\'s retention figure';
        }

        return $defects;
    }

    private function floorPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/floor/aimla')
            ->assertOk()
            ->getContent() ?: '';
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    /** @return list<string> */
    private function declaredIds(string $html): array
    {
        preg_match_all('/id="(floor-[a-z-]+)"/', $html, $m);

        $ids = array_values(array_unique($m[1]));
        sort($ids);

        return $ids;
    }

    /**
     * Every `floor-*` id main.js addresses: the literals its three helpers name, plus the family it
     * builds from the lobby model's indicator keys — derived from the model, never listed.
     *
     * @return list<string>
     */
    private function addressedIds(string $js, ?string $jsRoot = null): array
    {
        preg_match_all("/(?:el|say|list)\(\s*'(floor-[a-z-]+)'/", $js, $m);
        $ids = $m[1];

        $this->assertSame(1, preg_match_all('/`floor-\$\{[A-Za-z0-9_.]+\}`/', $js),
            'main.js builds element ids from a template in a number of places this parser does not know');

        foreach ($this->indicatorKeys($jsRoot) as $key) {
            $ids[] = 'floor-'.$key;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function indicatorKeys(?string $floorDir = null): array
    {
        $model = dirname($floorDir ?? $this->moduleDir()).'/lobby/lobby-model.js';
        $script = 'const m = await import('.json_encode('file://'.$model).');'
            .'console.log(JSON.stringify(m.indicators({}).map((i) => i.key)));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read the lobby model');

        return json_decode($out, true);
    }

    /** @return list<string> */
    private function accountedFor(string $html, array $addressed): array
    {
        preg_match_all('/aria-labelledby="([^"]+)"/', $html, $m);

        $aria = [];

        foreach ($m[1] as $value) {
            foreach (preg_split('/\s+/', $value) ?: [] as $id) {
                $aria[] = $id;
            }
        }

        return array_values(array_unique(array_merge($addressed, $aria)));
    }
}

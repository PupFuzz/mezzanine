<?php

namespace Tests\Feature\Lobby;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ THE WIRING CHECKLIST FOR THE ONE FAILURE MODE THIS CLIENT HAS NO OTHER WITNESS FOR:
 * `document.getElementById(…)` ANSWERING `null`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * Nothing throws. The page renders, the fetch runs, the model computes every fact correctly —
 * and one of them is written into nothing at all. There is no browser on this host, so no test
 * in this repository can see the rendered page; what CAN be checked is that the two ends of the
 * contract name the same elements, and that is what this file does, IN BOTH DIRECTIONS:
 *
 *   · an id `main.js` addresses that the page does not declare → the silent no-op above;
 *   · an id the page declares that `main.js` never writes into → a cell that will sit on its
 *     placeholder forever, which reads as *waiting for the fleet snapshot* on a fleet that
 *     answered. Headings are excluded by being `aria-labelledby` targets — derived from the
 *     page's own attributes, not from a list written here.
 *
 * ⛔ THE COMPUTED IDS ARE DERIVED, NOT LISTED. `main.js` addresses the four health cells through
 * a template literal over the model's own indicator keys, so this file asks the MODEL for those
 * keys (through `node`) instead of hand-listing four ids that would then be a fifth copy of the
 * indicator set. CONTROL 11 adds an indicator to the model and requires this to red.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that anything renders, lays out, or is legible. It is
 * evidence that every fact the model produces has an element with its name on it.
 */
class LobbyPageWiringTest extends TestCase
{
    use DrivesTheLobbyClient;
    use RefreshDatabase;

    public function test_every_element_the_client_addresses_exists_on_the_page_and_the_reverse(): void
    {
        $html = $this->lobbyPage();
        $js = $this->mainJs();

        $declared = $this->declaredIds($html);
        $addressed = $this->addressedIds($js);

        // The parse control first: an empty set on either side makes every difference below
        // vacuously clean, which is this file's own version of the false green.
        $this->assertGreaterThan(8, count($declared), 'the page declares almost no lobby elements — the parse has stopped reading it');
        $this->assertGreaterThan(8, count($addressed), 'the client addresses almost no elements — the parse has stopped reading main.js');

        $this->assertSame([], array_values(array_diff($addressed, $declared)),
            'main.js writes a fact into an element the page does not declare — getElementById '
            .'answers null, nothing throws, and that fact is silently never rendered');

        $this->assertSame([], array_values(array_diff($declared, $this->accountedFor($html, $addressed))),
            'the page declares a lobby element nothing ever writes into — it will hold its '
            .'placeholder over a fleet that answered');
    }

    /**
     * § 2.1 row 5 / AT-D3-15's GREEN — "the per-floor summary is LABELLED as a count of held
     * seats". An unlabelled summary reads as a fleet fact, which is the confusion the discrepancy
     * check exists to expose; the label is the thing that keeps the two readouts distinguishable
     * when they agree, and they agree almost always.
     */
    public function test_the_per_floor_summary_is_labelled_as_a_count_of_held_seats(): void
    {
        $this->assertStringContainsString('counts the seats this client holds', $this->lobbyPage(),
            'the per-floor summary carries no label saying whose count it is');
    }

    public function test_the_page_serves_the_module_and_every_import_resolves(): void
    {
        $this->assertStringContainsString('type="module"', $this->lobbyPage());
        $this->assertStringContainsString('/js/lobby/main.js', $this->lobbyPage(),
            'the page no longer loads the lobby client');

        $dir = $this->moduleDir();

        $this->assertFileExists($dir.'/main.js');

        // An import path with a typo is a client that never runs at all, and the page that loads
        // it looks exactly the same as one that does. The imports are read from the modules
        // themselves rather than listed here.
        $found = 0;

        foreach ((array) glob($dir.'/*.js') as $file) {
            preg_match_all("/from '\.\/([A-Za-z0-9._-]+)'/", (string) file_get_contents((string) $file), $m);

            foreach ($m[1] as $import) {
                $found++;
                $this->assertFileExists($dir.'/'.$import,
                    basename((string) $file).' imports a module that is not there');
            }
        }

        $this->assertGreaterThan(0, $found, 'no relative import was found — the check measured nothing');
    }

    /** ⛔ THE CONTROLS — each re-mints one of the two directions' defects. */
    public function test_the_wiring_check_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $html = $this->lobbyPage();
        $js = $this->mainJs();
        $addressed = $this->addressedIds($js);

        // CONTROL 9 — an element renamed out from under the client. Direction one must red.
        $renamed = str_replace('id="lobby-sweep"', 'id="lobby-sweeep"', $html);
        $this->assertNotSame($renamed, $html, "CONTROL 9's anchor is gone — it mutated nothing");
        $this->assertNotSame([], array_diff($addressed, $this->declaredIds($renamed)),
            'CONTROL 9 did not bite: the sweep cell was renamed and the addressed-but-undeclared '
            .'direction stayed clean, so it is not measuring the silent no-op');

        // CONTROL 10 — an element nothing writes into. Direction two must red.
        $orphaned = str_replace('<p id="lobby-stamp">', '<p id="lobby-orphan"></p><p id="lobby-stamp">', $html);
        $this->assertNotSame($orphaned, $html, "CONTROL 10's anchor is gone — it mutated nothing");
        $this->assertNotSame([], array_diff($this->declaredIds($orphaned), $this->accountedFor($orphaned, $addressed)),
            'CONTROL 10 did not bite: an element nothing writes into was added and the '
            .'declared-but-unaddressed direction stayed clean');

        // CONTROL 11 — a FIFTH indicator in the model, with no cell for it. This is the reason
        // the computed ids are derived from the model rather than listed: a hand-written list
        // would still contain four and this would pass.
        $widened = $this->mutatedModules([
            'lobby-model.js',
            "    return [\n        {\n            key: 'store',",
            "    return [\n        { key: 'purge', label: 'purge', member: 'fleet.purge', value: 'ok', detail: null },\n        {\n            key: 'store',",
        ]);
        $grown = $this->addressedIds($js, $widened);

        $this->assertContains('lobby-purge', $grown,
            'CONTROL 11 is not testing what it claims: the added indicator did not reach the addressed set, '
            .'so the ids are not derived from the model');
        $this->assertNotSame([], array_diff($grown, $this->declaredIds($html)),
            'CONTROL 11 did not bite: a fifth indicator with no cell on the page left the wiring clean');
    }

    /** The rendered page, as an MFA-satisfied session actually receives it. */
    private function lobbyPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent() ?: '';
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    /**
     * Every `lobby-*` id the page declares.
     *
     * @return list<string>
     */
    private function declaredIds(string $html): array
    {
        preg_match_all('/id="(lobby-[a-z-]+)"/', $html, $m);

        $ids = array_values(array_unique($m[1]));
        sort($ids);

        return $ids;
    }

    /**
     * Every `lobby-*` id `main.js` addresses — the literal ones it names, plus the family it
     * builds from the model's indicator keys.
     *
     * @return list<string>
     */
    private function addressedIds(string $js, ?string $moduleDir = null): array
    {
        preg_match_all("/(?:el|getElementById)\(\s*'(lobby-[a-z-]+)'\s*\)/", $js, $m);
        $ids = $m[1];

        // The computed family. Its ONE site is asserted, because a second template site this
        // parser did not know about would be a set of ids nothing here checks.
        $sites = preg_match_all('/`lobby-\$\{[A-Za-z0-9_.]+\}`/', $js);

        $this->assertSame(1, $sites,
            'main.js builds element ids from a template in '.$sites.' places; this parser knows about one');

        foreach ($this->indicatorKeys($moduleDir) as $key) {
            $ids[] = 'lobby-'.$key;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The model's own indicator keys, asked of the module rather than listed here.
     *
     * @return list<string>
     */
    private function indicatorKeys(?string $moduleDir = null): array
    {
        $model = $this->probe(['snapshot' => ['fleet' => [], 'installs' => []]], $moduleDir)['model'];

        return array_column($model['indicators'], 'key');
    }

    /**
     * Ids that are legitimately not written into: the ones the page's own `aria-labelledby`
     * attributes point at, which are headings and are named by the markup itself.
     *
     * @param  list<string>  $addressed
     * @return list<string>
     */
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

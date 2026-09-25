<?php

namespace Tests\Feature\DrillDown;

use App\Models\User;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The drill-down client's wiring — that its modules exist, that every relative import in them
 * resolves, that it holds no second copy of a function the shared `wire/` owns, and that its DOM half
 * and the floor page that hosts it agree on every slot, both ways.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE HOST PAGE IS THE FLOOR PAGE (Appendix B row 10, card#7342). § 4: "the drill-down is a panel
 * over the floor rather than a route of its own", so `/floor/{floor}` and § 4.4's
 * `/floor/{floor}/{seat_id}` serve one view, `resources/views/floor.blade.php`, whose `floor/main.js`
 * hands each frame's panel model to `drilldown/main.js`. That module writes `[data-panel-*]` slots and
 * a slot the page does not declare is a `querySelector` answering `null` — a silent no-op, one fact
 * simply never drawn. So the slots are set-differenced BOTH ways against the served page, the way
 * `Tests\Feature\Floor\FloorPageWiringTest` holds the floor's own ids.
 */
class DrillDownModuleWiringTest extends TestCase
{
    use DrivesTheDrillDownClient;
    use RefreshDatabase;

    public function test_the_modules_exist_and_every_relative_import_resolves(): void
    {
        $dir = $this->moduleDir();

        $this->assertFileExists($dir.'/main.js');
        $this->assertFileExists($dir.'/drilldown-model.js');

        $found = $this->assertEveryRelativeImportResolves($dir);

        $this->assertGreaterThan(0, $found, 'no relative import was found — the check measured nothing');

        $model = (string) file_get_contents($dir.'/drilldown-model.js');

        $this->assertStringContainsString("from '../wire/duration.js'", $model,
            'the panel no longer imports the shared duration format — if it grew its own, that '
            .'copy is free to diverge from the one § 2.4 says there is exactly one of');
        $this->assertStringContainsString("from '../wire/clock.js'", $model,
            'the panel no longer imports the shared wire clock');
        // § 5.4's membership test comes from the ONE published member set (`lobby/render-state.js`
        // says so of itself: "every surface in this client that needs the members imports this
        // array; none of them writes a second one").
        $this->assertStringContainsString("from '../wire/task.js'", $model,
            'the panel no longer reads the shared `task` decision — if it grew its own, that '
            .'copy is free to disagree with the desk about the same seat');
        $this->assertStringContainsString("from '../lobby/render-state.js'", $model,
            'the panel no longer imports the published `render_state` member set — a second copy '
            .'of it is how the unrecognised case gets lost again');
    }

    /** One implementation of each shared wire function in the shipped tree. */
    public function test_the_shared_wire_functions_have_exactly_one_implementation_each(): void
    {
        $expected = [
            'clockTime' => ['wire/clock.js'],
            'formatDuration' => ['wire/duration.js'],
            'wireMs' => ['wire/duration.js'],
            // card#7897: the `task` member's rules — the null case, the reference and the
            // degraded wording — hoisted to `wire/` at their SECOND caller, when the desk's
            // thought bubble (FLOOR.md § 5.1) needed exactly what the panel's row needed. D3 is
            // explicit that the two surfaces are one fact at two fidelities, so a second
            // implementation here would be two clients disagreeing about one seat's task. (Its
            // link resolver left with the operator's plain-text ruling — card#7342 step 10.)
            'taskFacts' => ['wire/task.js'],
            // card#7341 step 4: the corrected clock, the seat-clock label and the two ages whose
            // wording § 2.4 publishes, hoisted to `wire/` at their SECOND caller — the floor's age
            // readout needed exactly what this panel had. One fact, one string (§ 2.4).
            'correctedNowMs' => ['wire/duration.js'],
            'seatClock' => ['wire/age-readout.js'],
            'quietAgeLine' => ['wire/age-readout.js'],
            'actionElapsedLine' => ['wire/age-readout.js'],
            // card#7342 step 10: the receipt age's and the derivation lag's § 2.4 wordings, which the
            // desk and the panel's transport and derivation blocks both draw — one string per fact.
            'receiptAgeAt' => ['wire/age-readout.js'],
            'derivationLagLine' => ['wire/age-readout.js'],
            // card#7341 step 5: the context gauge and its null render, hoisted at its second
            // caller — the desk draws the same gauge from the same member.
            'contextGauge' => ['wire/context-gauge.js'],
        ];

        foreach ($expected as $function => $home) {
            $implementations = [];

            foreach ($this->jsFiles() as $file) {
                if (str_contains((string) file_get_contents($file), "export function {$function}(")) {
                    $implementations[] = substr($file, strlen($this->jsRoot()) + 1);
                }
            }

            $this->assertSame($home, $implementations,
                "the shipped client tree holds more than one `{$function}` — § 2.4 and card#8300 "
                .'both put one copy of a wire-clock behaviour in `wire/` precisely so there is one');
        }
    }

    /**
     * Every `[data-panel-*]` slot `drilldown/main.js` writes is declared on the served floor page, and
     * every slot the page declares is written — the floor page's own ids are its own test's.
     */
    public function test_every_panel_slot_the_module_writes_is_on_the_floor_page_and_the_reverse(): void
    {
        $this->assertSame([], $this->slotDefects($this->floorPage(), $this->mainJs()));
    }

    /** § 4.4: the drill-down URL serves the floor page with the seat segment as data, behind the same gate. */
    public function test_the_drill_down_route_serves_the_floor_page_with_its_seat(): void
    {
        // Behind the same gate as the floor itself — asked first, before this test signs anyone in.
        $this->get('/floor/aimla/aimla-pm')->assertRedirect(route('login'));

        $html = $this->floorPage('/floor/aimla/aimla-pm');

        $this->assertStringContainsString('data-floor="aimla"', $html);
        $this->assertStringContainsString('data-seat="aimla-pm"', $html);
        $this->assertStringContainsString('/js/floor/main.js', $html);
        $this->assertStringContainsString('data-seat=""', $this->floorPage('/floor/aimla'),
            'the floor alone must hand the page an EMPTY seat segment, not a missing one');
    }

    /** @return array<string, string> */
    private function slotDefects(string $html, string $js): array
    {
        preg_match_all('/\sdata-panel-([a-z-]+)[\s>=]/', $html, $d);
        preg_match_all("/'\[data-panel-([a-z-]+)\]'/", $js, $w);

        $declared = array_values(array_unique($d[1]));
        $written = array_values(array_unique($w[1]));
        sort($declared);
        sort($written);

        $this->assertGreaterThan(20, count($declared), 'the page declares almost no panel slots — the parse has stopped reading it');
        $this->assertGreaterThan(20, count($written), 'the module writes almost no panel slots — the parse has stopped reading it');

        $defects = [];
        $undeclared = array_values(array_diff($written, $declared));
        $unwritten = array_values(array_diff($declared, $written));

        if ($undeclared !== []) {
            $defects['undeclared'] = 'drilldown/main.js writes slots the floor page does not declare: '.implode(', ', $undeclared);
        }

        if ($unwritten !== []) {
            $defects['unwritten'] = 'the floor page declares panel slots nothing writes: '.implode(', ', $unwritten);
        }

        return $defects;
    }

    private function floorPage(string $path = '/floor/aimla'): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get($path)
            ->assertOk()
            ->getContent() ?: '';
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    /** @return list<string> every shipped `.js`, so the sweep above has a stated population */
    private function jsFiles(): array
    {
        $out = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->jsRoot(), FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'js') {
                $out[] = $entry->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /** ⛔ THE CONTROLS. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — the population. A sweep that finds no `.js` reports one implementation of
        // nothing and passes; assert it is reading a real tree first.
        $this->assertGreaterThan(4, count($this->jsFiles()),
            'CONTROL 1: the shipped-tree sweep found almost no `.js` — every assertion above '
            .'would then be clean over an unread population');

        // CONTROL 2 — a BROKEN `../` import, which is the shape this panel is built out of: it
        // reaches into `../wire/` for two modules and `../lobby/` for a third.
        $dir = $this->mutatedModules([
            'drilldown-model.js', "from '../wire/duration.js'", "from '../wire/durations.js'",
        ]);

        $failed = false;

        try {
            $this->assertEveryRelativeImportResolves($dir);
        } catch (AssertionFailedError) {
            $failed = true;
        }

        $this->assertTrue($failed,
            'CONTROL 2 did not bite: a `../` import was pointed at a file that does not exist '
            .'and the resolver still reported every import resolved');

        // CONTROL 3 — and the client really does die on it, rather than silently degrading.
        [$status, , $stderr] = $this->runProbe([], $dir);

        $this->assertNotSame(0, $status,
            'CONTROL 3 did not bite: the module with the broken import still loaded, so the '
            .'resolver above is guarding something that cannot actually fail');
        $this->assertStringContainsString('durations.js', $stderr);

        // CONTROL 4 — a slot renamed on the page, and a slot the page declares that nothing writes.
        $html = $this->floorPage();
        $renamed = str_replace('data-panel-receipt>', 'data-panel-reciept>', $html);
        $this->assertNotSame($renamed, $html);
        $this->assertArrayHasKey('undeclared', $this->slotDefects($renamed, $this->mainJs()),
            'CONTROL 4 did not bite: a renamed slot left the module writing into nothing and the check stayed clean');

        $orphan = str_replace('<p data-panel-lag></p>', '<p data-panel-lag></p><p data-panel-orphan></p>', $html);
        $this->assertNotSame($orphan, $html);
        $this->assertArrayHasKey('unwritten', $this->slotDefects($orphan, $this->mainJs()),
            'CONTROL 4 did not bite: a slot nothing writes was declared and the check stayed clean');
    }
}

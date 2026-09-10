<?php

namespace Tests\Feature\DrillDown;

use FilesystemIterator;
use PHPUnit\Framework\AssertionFailedError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The drill-down client's wiring — that its modules exist, that every relative import in them
 * resolves, and that it holds no second copy of a function the shared `wire/` owns.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ NO PAGE SERVES THESE MODULES YET, and this file says so rather than asserting one does.
 * `docs/design/FLOOR.md § 4.4`'s `/floor/{install_id}/{seat_id}` route is not built — the floor
 * is card#9208-blocked on a D2 read surface for an authored map, and a panel opens by selecting
 * a desk that does not exist. So unlike `public/js/lobby`, whose `dashboard.blade.php` loads it,
 * this client has no host page to check against; `public/js/coord` is in the same position for
 * the same reason. What is checkable today is that the modules are internally coherent and that
 * nothing about them will fail to LOAD when that route lands; the element contract is the floor
 * page's to declare and the floor page's test to hold.
 */
class DrillDownModuleWiringTest extends TestCase
{
    use DrivesTheDrillDownClient;

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
    }
}

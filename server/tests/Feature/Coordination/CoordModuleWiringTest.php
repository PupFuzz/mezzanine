<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * The coordination client's wiring — that its modules exist, that every relative import in them
 * resolves, and that it holds no second copy of a function the shared `wire/` owns.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ NO PAGE SERVES THESE MODULES YET, and this file says so rather than asserting one does.
 * `docs/design/FLOOR.md § 4.4`'s `/floor/{install_id}` route is not built — the floor is
 * card#9208-blocked on a D2 read surface for an authored map — so unlike `public/js/lobby`,
 * whose `dashboard.blade.php` loads it, this client has no host page to check against. What is
 * checkable today is that the modules are internally coherent and that nothing about them will
 * fail to LOAD when that route lands; the element contract is the floor page's to declare and
 * the floor page's test to hold.
 */
class CoordModuleWiringTest extends TestCase
{
    use DrivesTheCoordClient;

    public function test_the_modules_exist_and_every_relative_import_resolves(): void
    {
        $dir = $this->moduleDir();

        $this->assertFileExists($dir.'/main.js');
        $this->assertFileExists($dir.'/coord-model.js');
        $this->assertFileExists($dir.'/coord-members.js');

        // An import path with a typo is a client that never runs at all. This tree carries a
        // `../wire/` import, which is exactly the shape a `./`-only resolver cannot see.
        $found = $this->assertEveryRelativeImportResolves($dir);

        $this->assertGreaterThan(0, $found, 'no relative import was found — the check measured nothing');
        $this->assertStringContainsString("from '../wire/clock.js'",
            (string) file_get_contents($dir.'/coord-model.js'),
            'the coord client no longer imports the shared wire clock — if it grew its own copy, '
            .'that copy is free to diverge from the lobby’s render of the same wire form');
    }

    /** One `clockTime` in the shipped tree, and it is `wire/clock.js`'s. */
    public function test_the_wire_clock_has_exactly_one_implementation(): void
    {
        $implementations = [];

        foreach ($this->jsFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'export function clockTime(')) {
                $implementations[] = substr($file, strlen($this->jsRoot()) + 1);
            }
        }

        $this->assertSame(['wire/clock.js'], $implementations,
            'the shipped client tree holds more than one `clockTime` — card#8300 hoisted it to '
            .'`wire/clock.js` at its second caller precisely so there is one');
    }

    /** @return list<string> every shipped `.js`, so the sweep above has a stated population */
    private function jsFiles(): array
    {
        $out = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->jsRoot(), \FilesystemIterator::SKIP_DOTS));

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

        // CONTROL 2 — a BROKEN `../` import. This is the shape the `./`-only resolver could not
        // see, so it is planted as a `../` one deliberately: a control that plants a `./` typo
        // would pass against the old resolver too and prove nothing about the repair.
        $dir = $this->mutatedModules([
            'coord-model.js', "from '../wire/clock.js'", "from '../wire/clocks.js'",
        ]);

        $failed = false;

        try {
            $this->assertEveryRelativeImportResolves($dir);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
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
        $this->assertStringContainsString('clocks.js', $stderr);
    }
}

<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Feature\Support\ModuleSpecifiers;
use Tests\TestCase;

/**
 * **The floor's composition is one, for the floor page and the building view.** `docs/design/FLOOR.md` Appendix B row 47
 * (card#13033 slice 1; `docs/design/plan-13033-3d-view.md` § 2 item 1, § 8 item 1): `floor/floor-compose.js` holds the
 * floor's composition — rooms placed, each room's desks slotted — and the scene's input assembly, hoisted out of
 * `floor/floor-screen.js` so a second screen composes a floor by the same code rather than by a copy of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT IS HELD, each planted and watched red:
 *  · the floor screen imports `floor-compose.js` (its specifiers, read by `ModuleSpecifiers` with comments blanked);
 *  · `assignSlots(` and `mapReservation(` — § 3.2's slot function and the map's reservation read, defined in
 *    `floor-layout.js` — are CALLED in `floor-compose.js` and in no other module under `public/js`, comments blanked; a
 *    second call site is a second answer to *which desk does this seat hold*;
 *  · `SCENE_INPUT_KEYS` is exactly the set of `input.<member>` reads in `floor/scene.js`, comments blanked — so a member
 *    the scene starts reading, or one it stops reading, reds here rather than leaving a caller's input short.
 *
 * ⚠ WHAT IT DOES NOT HOLD: that the hoist changed no behaviour. That is every existing floor test's, run unchanged over the
 * hoisted code (plan § 8 item 1's control). `placeRooms(` has a second legitimate caller, `floor/door-view.js` (the door
 * view composes geometry and assigns no desk), so it is not in the single-call-site set.
 */
class TheFloorCompositionIsOneForBothScreensTest extends TestCase
{
    use DrivesAShippedClientModule;

    /** The functions whose one call site is the composition. */
    private const ONE_CALL_SITE = ['assignSlots', 'mapReservation'];

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor')
            ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/floor-compose-probe.mjs';
    }

    public function test_green_the_composition_is_one(): void
    {
        $this->assertSame([], $this->compositionDefects($this->moduleDir()));
    }

    public function test_red_a_second_call_site_of_the_slot_function(): void
    {
        $dir = $this->mutatedModules(['floor-screen.js', "        const composition = composeFloor(floor, this.#building, seatsByRoom);\n",
            "        const composition = composeFloor(floor, this.#building, seatsByRoom);\n        assignSlots([], 0, null);\n"]);

        $this->assertNotSame([], $this->compositionDefects($dir), 'CONTROL (a second assignSlots call site) did not bite');
    }

    public function test_red_a_second_call_site_of_the_reservation_read(): void
    {
        $dir = $this->mutatedModules(['door-view.js', 'export function doorView(', "const unused = () => mapReservation([]);\n\nexport function doorView("]);

        $this->assertNotSame([], $this->compositionDefects($dir), 'CONTROL (a second mapReservation call site) did not bite');
    }

    public function test_red_a_floor_screen_that_composes_without_the_module(): void
    {
        $dir = $this->mutatedModules(['floor-screen.js', "import { composeFloor, roomMaps, sceneInput, splitKey } from './floor-compose.js';\n", '']);

        $this->assertNotSame([], $this->compositionDefects($dir), 'CONTROL (floor-compose.js not imported) did not bite');
    }

    public function test_green_the_scene_input_keys_are_the_scenes_reads(): void
    {
        $this->assertSame([], $this->sceneInputDefects($this->moduleDir()));
    }

    public function test_red_a_scene_input_key_dropped_or_invented(): void
    {
        foreach ([
            'a member the scene reads dropped' => ["    'maps', 'hallway', 'tilesets',", "    'maps', 'tilesets',"],
            'a member the scene never reads' => ["    'maps', 'hallway', 'tilesets',", "    'maps', 'hallway', 'tilesets', 'camera',"],
        ] as $what => [$from, $to]) {
            $this->assertNotSame([], $this->sceneInputDefects($this->mutatedModules(['floor-compose.js', $from, $to])), "CONTROL ({$what}) did not bite");
        }
    }

    /** @return list<string> */
    private function compositionDefects(string $floorDir): array
    {
        $defects = [];
        $screen = ModuleSpecifiers::of((string) file_get_contents($floorDir.'/floor-screen.js'));

        if (! in_array('./floor-compose.js', array_column($screen, 'specifier'), true)) {
            $defects[] = 'floor/floor-screen.js does not import ./floor-compose.js';
        }

        $calls = [];
        $root = dirname($floorDir);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }

            $source = ModuleSpecifiers::withoutComments((string) file_get_contents($file->getPathname()));
            $relative = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::ONE_CALL_SITE as $name) {
                $n = preg_match_all('/(?<![\w$.])(?<!function )'.$name.'\s*\(/', $source);

                if ($n > 0) {
                    $calls[$name][$relative] = $n;
                }
            }
        }

        foreach (self::ONE_CALL_SITE as $name) {
            if (($calls[$name] ?? []) !== ['floor/floor-compose.js' => 1]) {
                $defects[] = "{$name}( is called in ".json_encode($calls[$name] ?? []).', not once in floor/floor-compose.js alone';
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function sceneInputDefects(string $floorDir): array
    {
        $keys = $this->probe([], $floorDir)['scene_input_keys'];
        preg_match_all('/(?<![\w$])input\.([A-Za-z_$][\w$]*)/', ModuleSpecifiers::withoutComments((string) file_get_contents($floorDir.'/scene.js')), $m);
        $reads = array_values(array_unique($m[1]));
        sort($reads);
        $declared = $keys;
        sort($declared);

        if ($reads === []) {
            return ['scene.js reads no input member — the comparison read nothing'];
        }

        return $declared === $reads ? [] : ['SCENE_INPUT_KEYS is '.json_encode($declared).' and scene.js reads '.json_encode($reads)];
    }
}

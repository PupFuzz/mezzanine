<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The floor's walls and accents draw without seams at any zoom** — `docs/design/FLOOR.md` Appendix B row
 * 14's region pass, which since card#11046's row 22 feeds the floor's theme (§ 10.6 item 6). A run drawn cell
 * by cell has an edge at every cell, and wherever that edge fell on a fractional device pixel the antialiased
 * edges let what is under it through: a dotted ladder down an 8 px wall strip.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO BROWSER RUNS HERE, SO THE CHECK READS THE CAUSE, NOT THE PIXELS. `tile-seams-probe.mjs` runs the
 * SHIPPED default room's `wall` and `accent` cells and planted cases through `scene.js`'s `tileRegions()` and
 * reads back the regions the plane document is handed: they are exactly the cells — none missing, none extra,
 * no rect twice — two cells of one kind and size sharing an edge are ONE region, and a cell listed after one
 * it overlaps is never drawn ahead of it. It also paints a scene of the shipped room's plane and standing
 * pieces and holds the painter to drawing no tile's image: every image a theme's document, and no pattern
 * but the hatch. Whether a strip is rasterised without a seam is the browser's, and is looked at on the
 * screenshots at review, not here.
 *
 * ⛔ WHAT A TILE IS, TO THE FLOOR, IS WRITTEN ONCE, in `scene.js`'s `tileLook()` — its kind and its cell's
 * size — and the regions are joined on it.
 *
 * ⛔ EACH CHECK IS SEEN TO FAIL: the controls below re-mint one defect each in a copy of the shipped modules
 * and watch the probe name it.
 */
class TheFloorsTilesDrawWithoutSeamsTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor') ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/tile-seams-probe.mjs';
    }

    public function test_every_run_reaches_the_plane_as_itself_in_order_and_identical_neighbours_are_one_region(): void
    {
        $result = $this->probe([]);

        foreach ($result['cases'] as $name => $case) {
            $this->assertGreaterThan(0, $case['cells'], "[{$name}] the case has no cell — every check below would read nothing");
        }

        $this->assertSame([], $result['defects']);
        $this->assertGreaterThan(1, $result['images'], 'the painter drew no standing piece — the no-tile-image check read nothing');

        $walls = $result['cases']['the shipped default room\'s walls'];

        $this->assertLessThan($walls['cells'], $walls['regions'],
            'the shipped room\'s walls are a region per cell — their identical neighbours were never joined');
    }

    /** ⛔ THE CONTROLS — each re-mints one defect in the shipped modules and watches its check red. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $controls = [
            'identical neighbours never joined' => [
                ['scene.js', 'if (i !== undefined && i > first && regionOf[i] === -1 && !heldOut.has(i)) {', 'if (false) {'],
                'the edge between them is a seam',
            ],
            'the draw-order guard gone' => [
                ['scene.js', 'if (late.length === 0) {', 'if (true) {'],
                'is drawn first',
            ],
            'one look for every tile' => [
                ['scene.js', 'const kind = JSON.stringify(look);', "const kind = 'one';"],
                'is not in the regions as itself',
            ],
            'a neighbour found by its exact float coordinates' => [
                ['scene.js', 'const spot = (x, y) => `${Math.round(x * 1024)},${Math.round(y * 1024)}`;', 'const spot = (x, y) => `${x},${y}`;'],
                ['under offsets', 'the edge between them is a seam'],
            ],
            'a tile\'s image drawn' => [
                ['painter.js', '                    art(tiles, uris[0], piece, piece.doc.asset, `scenery ${piece.kind}`);',
                    '                    art(tiles, \'/art/floor/tiles/floor-plane/bookcase.svg\', piece, piece.doc.asset, `scenery ${piece.kind}`);'],
                'the floor never draws a tile\'s image',
            ],
        ];

        // One per field of `tileLook()`, as the probe reads it off the shipped module, dropped from the look in
        // turn: the merge then joins cells that differ in it.
        $look = $this->probe([])['look'];

        $this->assertNotSame([], $look, 'the probe read no field of tileLook() — no control below would run');
        $this->assertSame(1, preg_match('/^export function tileLook\(t\) \{\n.*?^\}\n/ms',
            (string) file_get_contents($this->moduleDir().'/scene.js'), $fn), 'scene.js has no tileLook() to drop a field from');

        foreach ($look as $field) {
            $edited = str_replace(["{$field}: t.{$field}, ", ", {$field}: t.{$field}"], '', $fn[0]);

            $this->assertNotSame($fn[0], $edited, "tileLook() does not read «{$field}» where the control can drop it");

            $controls["«{$field}» dropped from the look"] = [['scene.js', $fn[0], $edited], 'is not in the regions as itself'];
        }

        foreach ($controls as $name => [$edit, $named]) {
            $this->assertControlNames($name, $this->probe([], $this->mutatedModules($edit))['defects'], (array) $named);
        }
    }

    /**
     * @param  list<string>  $defects
     * @param  list<string>  $named
     */
    private function assertControlNames(string $name, array $defects, array $named): void
    {
        $this->assertNotSame([], $defects, "CONTROL ({$name}) did not bite");

        foreach ($named as $text) {
            $this->assertNotEmpty(array_filter($defects, fn (string $d): bool => str_contains($d, $text)),
                "CONTROL ({$name}) did not name «{$text}»:\n".implode("\n", array_slice($defects, 0, 3)));
        }
    }
}

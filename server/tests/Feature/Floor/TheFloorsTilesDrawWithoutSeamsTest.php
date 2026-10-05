<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The floor's tiles draw without seams at any zoom** — `docs/design/FLOOR.md` Appendix B row 14's
 * painter. Each tile used to be drawn as a primitive of its own, and wherever two neighbours' shared edge
 * fell on a fractional device pixel the antialiased edges let the plane under them through: the room's
 * theme in thin lines between the planks, a dotted ladder down an 8 px wall strip.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO BROWSER RUNS HERE, SO THE CHECK READS THE CAUSE, NOT THE PIXELS. `tile-seams-probe.mjs` paints
 * the SHIPPED default room and generated strata through the shipped painter over a fake DOM and reads back
 * what it wrote: every primitive expanded into the tiles it draws must be exactly the scene's tiles, any
 * two that overlap in the scene's order, no primitive spanning two rooms, and two identical tiles sharing
 * an edge must be ONE primitive. Whether one primitive is in fact rasterised without a seam is the
 * browser's, and was looked at on a screenshot when this landed (the PR's review round), not here.
 *
 * ⛔ WHAT A TILE LOOKS LIKE IS WRITTEN ONCE, in `scene.js`'s `tileLook()`: the regions are joined on it and
 * the painter is handed it in place of the tile. The probe's reader is closed — anything the painter
 * writes on a tile's nodes that it cannot read into the picture is a defect — and a look field the merge
 * ignores, or one the painter drops, shows as a tile not painted as itself.
 *
 * ⛔ EACH CHECK IS SEEN TO FAIL: the controls below re-mint one defect each in a copy of the shipped
 * modules (or of the probe, for its own count) and watch the probe name it.
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

    public function test_every_tile_is_painted_as_itself_in_order_and_identical_neighbours_are_one_primitive(): void
    {
        $result = $this->probe([]);

        foreach ($result['cases'] as $name => $case) {
            $this->assertGreaterThan(0, $case['tiles'], "[{$name}] the case painted no tile — every check below would read nothing");
        }

        $this->assertNotSame([], $result['strata'], 'the probe planted no stratum');

        foreach ($result['strata'] as $name => $stratum) {
            $this->assertGreaterThan(0, $stratum['cells'], "[{$name}] the stratum planted no cell");
        }

        $this->assertSame([], $result['defects']);

        $shipped = $result['cases']['the shipped default room'];

        $this->assertLessThan($shipped['tiles'], $shipped['primitives'],
            'the shipped room painted a primitive per tile — its identical neighbours were never joined');
    }

    /** ⛔ THE CONTROLS — each re-mints one defect in the shipped modules and watches its check red. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        // The neighbour lookup in `tileRegions()`, and three wrong ones: a fallback that matches a tile by its
        // image alone — looking down and up (the review's J), leftward (its L), or only from a chain's
        // second tile on (so only a chain of two before the differing tile can see it).
        $lookup = <<<'JS'
            const i = at.get(`${keys[first]}@${spot(here.x + dx * w, here.y + dy * h)}`);
            JS;
        $byImage = fn (string $when, string $x, string $y): string => substr($lookup, 0, -1)
            .' ?? ('.$when.' ? new Map(tiles.map((q, n) => [`${q.image}@${spot(q.x, q.y)}`, n]))'
            .'.get(`${here.image}@${spot('.$x.', '.$y.')}`) : undefined);';

        $controls = [
            'identical neighbours never joined' => [
                ['scene.js', 'if (i !== undefined && i > first && regionOf[i] === -1 && !heldOut.has(i)) {', 'if (false) {'],
                'the edge between them is a seam',
            ],
            'the draw-order guard gone' => [
                ['scene.js', 'if (late.length === 0) {', 'if (true) {'],
                'which the scene draws first',
            ],
            'one look for every tile' => [
                ['scene.js', 'const kind = JSON.stringify(look);', "const kind = 'one';"],
                'is not painted as itself',
            ],
            'a vertical neighbour matched by its image alone' => [
                ['scene.js', $lookup, $byImage('dy !== 0', 'here.x', 'here.y + dy * h')],
                'the merge stratum: the scene\'s tile',
            ],
            'a leftward neighbour matched by its image alone' => [
                ['scene.js', $lookup, $byImage('dx === -1', 'here.x - w', 'here.y')],
                'the merge stratum: the scene\'s tile',
            ],
            'a neighbour matched by its image alone past a chain\'s first tile' => [
                ['scene.js', $lookup, $byImage('m > 0', 'here.x + dx * w', 'here.y + dy * h')],
                'the merge stratum: the scene\'s tile',
            ],
            'neighbours of one look split by their layer' => [
                ['scene.js', "        opacity: t.opacity,\n    });", "        opacity: t.opacity,\n        layer: t.layer,\n    });"],
                ['the merge stratum: ', 'are painted as two primitives'],
            ],
            'a neighbour found by its exact float coordinates' => [
                ['scene.js', 'const spot = (x, y) => `${Math.round(x * 1024)},${Math.round(y * 1024)}`;', 'const spot = (x, y) => `${x},${y}`;'],
                ['the offsets stratum: ', 'are painted as two primitives'],
            ],
            'a tile\'s opacity dropped' => [
                ['painter.js', 'opacity: look.opacity === 1 ? null : look.opacity,', 'opacity: null,'],
                'is not painted as itself',
            ],
            'a tile\'s flip dropped' => [
                ['painter.js', 'const flip = [look.flip_h ? -1 : 1, look.flip_v ? -1 : 1];', 'const flip = [1, 1];'],
                'is not painted as itself',
            ],
            'an attribute on the path the reader does not read' => [
                ['painter.js', 'fill: `url(#${id})`,', "fill: `url(#\${id})`,\n                    'data-region': 1,"],
                'the painter writes «data-region» the probe cannot attribute to a look field',
            ],
            'a second child in the pattern' => [
                ['painter.js', "                node('path', {\n                    d: rects", "                node('rect', { width: 1, height: 1 }, fill);\n                node('path', {\n                    d: rects"],
                'the painter writes a child «rect» the probe cannot attribute to a look field',
            ],
            'every room\'s tiles merged in one pass' => [
                ['painter.js', 'pass(scene.tiles.filter((t) => t.room === null));', 'pass(scene.tiles); scene = { ...scene, tiles: [] };'],
                'one primitive paints tiles of two passes',
            ],
        ];

        // One per field of `tileLook()`, as the probe reads it off the shipped module, dropped from the look in
        // turn: the merge then ignores it and the painter draws without it.
        $look = $this->probe([])['look'];

        $this->assertNotSame([], $look, 'the probe read no field of tileLook() — no control below would run');
        $this->assertSame(1, preg_match('/^export function tileLook\(t\) \{\n.*?^\}\n/ms',
            (string) file_get_contents($this->moduleDir().'/scene.js'), $fn), 'scene.js has no tileLook() to drop a field from');

        foreach ($look as $field) {
            $line = "        {$field}: t.{$field},\n";

            $this->assertSame(1, substr_count($fn[0], $line), "tileLook() does not read «{$field}» on a line of its own");

            $controls["«{$field}» dropped from the look"] = [
                ['scene.js', $fn[0], str_replace($line, '', $fn[0])],
                'is not painted as itself',
            ];
        }

        // Each control names the defect text it must produce — every one of them, where it lists several.
        foreach ($controls as $name => [$edit, $named]) {
            $this->assertControlNames($name, $this->probe([], $this->mutatedModules($edit))['defects'], (array) $named);
        }

        // The probe's own count: one merge cell left unplanted must red as a stratum short of its product.
        $probe = (string) file_get_contents($this->probeScript());
        $anchor = 'const other = field in base ? differIn(base, field) : null;';

        $this->assertSame(1, substr_count($probe, $anchor), "the count control's anchor is not in the probe exactly once");

        $copy = dirname($this->probeScript()).'/.tile-seams-probe.control-'.bin2hex(random_bytes(6)).'.mjs';

        try {
            file_put_contents($copy, str_replace($anchor, 'const other = cells === 0 && order === ORDERS[0] ? null : '.substr($anchor, strlen('const other = ')), $probe));

            $this->assertControlNames('one merge cell left unplanted', $this->probe([], null, $copy)['defects'],
                ['the merge stratum planted', 'not the product of its factors']);
        } finally {
            @unlink($copy);
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

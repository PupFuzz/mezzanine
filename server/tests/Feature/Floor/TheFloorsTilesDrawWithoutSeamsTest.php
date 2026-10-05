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
 * the SHIPPED default room and planted cases through the shipped painter over a fake DOM and reads back
 * what it wrote: every primitive expanded into the tiles it draws must be exactly the scene's tiles, any
 * two that overlap in the scene's order, and two identical tiles sharing an edge must be ONE primitive.
 * Whether one primitive is in fact rasterised without a seam is the browser's, and was looked at on a
 * screenshot when this landed (the PR's review round), not here.
 *
 * ⛔ EVERY MEMBER OF THE MERGE KEY IS PLANTED. `tileRegions()` joins tiles whose key is equal; the probe
 * reads that key out of the shipped `scene.js` and plants, per member, two neighbours that differ in that
 * member alone, which must be painted as two primitives, each as itself.
 *
 * ⛔ EACH CHECK IS SEEN TO FAIL: the controls re-mint one defect each in a copy of the shipped modules —
 * neighbours never joined, the draw-order guard gone, a tile's opacity or flip dropped, and each member of
 * the merge key dropped from it in turn — and watch the probe name it.
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

        $this->assertSame([], $result['defects']);

        $shipped = $result['cases']['the shipped default room'];

        $this->assertLessThan($shipped['tiles'], $shipped['primitives'],
            'the shipped room painted a primitive per tile — its identical neighbours were never joined');
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
                'which the scene draws first',
            ],
            'a tile\'s opacity dropped' => [
                ['painter.js', 'opacity: t.opacity === 1 ? null : t.opacity,', 'opacity: null,'],
                'is not painted as itself',
            ],
            'a tile\'s flip dropped' => [
                ['painter.js', 'const flip = [t.flip_h ? -1 : 1, t.flip_v ? -1 : 1];', 'const flip = [1, 1];'],
                'is not painted as itself',
            ],
        ];

        // One per member of the merge key, read out of the shipped `tileRegions()` by the probe — so a member
        // added to the key later gets its control here with no edit: that member dropped, its planted pair
        // merges, and the probe must say so. A member the probe cannot plant reds the first test instead.
        $key = $this->probe([])['key'];

        $this->assertNotSame([], $key['members'], 'the probe read no member out of the merge key — no control below would run');

        foreach ($key['members'] as $member) {
            $kept = array_filter($key['members'], fn (string $m): bool => $m !== $member);

            $controls["«{$member}» dropped from the merge key"] = [
                ['scene.js', $key['expression'], 'JSON.stringify(['.implode(', ', array_map(fn (string $m): string => "t.{$m}", $kept)).'])'],
                "differ only in «{$member}» and are painted as ONE primitive",
            ];
        }

        foreach ($controls as $name => [$edit, $named]) {
            $defects = $this->probe([], $this->mutatedModules($edit))['defects'];

            $this->assertNotSame([], $defects, "CONTROL ({$name}) did not bite");
            $this->assertNotEmpty(array_filter($defects, fn (string $d): bool => str_contains($d, $named)),
                "CONTROL ({$name}) redded for another reason:\n".implode("\n", array_slice($defects, 0, 3)));
        }
    }
}

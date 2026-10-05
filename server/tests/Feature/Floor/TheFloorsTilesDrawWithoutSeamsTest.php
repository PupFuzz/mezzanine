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
 * ⛔ EVERY MEMBER OF THE MERGE KEY IS PINNED AND PLANTED. `tileRegions()` joins tiles whose key is equal;
 * the probe pins that key's members (`KEY_MEMBERS`, its one copy), reds when the key `scene.js` computes
 * lacks a pinned member or has one the pin does not name, and plants, per pinned member, two neighbours
 * that differ in that member alone, which must be painted as two primitives, each as itself.
 *
 * ⛔ EACH CHECK IS SEEN TO FAIL: the controls re-mint one defect each in a copy of the shipped modules —
 * neighbours never joined, the draw-order guard gone, a tile's opacity or flip dropped, each member of the
 * merge key dropped from it in turn, a member added to it, and a second key line planted before the real
 * one — and watch the probe name it.
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

        $key = $this->probe([])['key'];

        $this->assertNotSame([], $key['pinned'], 'the probe pins no member of the merge key — no control below would run');
        $this->assertNotNull($key['expression'], 'the probe read no merge key out of the shipped scene.js — no control below could plant');

        $keyLine = "const kind = {$key['expression']};";
        $keyOf = fn (array $members): string => 'const kind = JSON.stringify(['.implode(', ', array_map(fn (string $m): string => "t.{$m}", $members)).']);';

        $controls['a member the pin does not name added to the merge key'] = [
            ['scene.js', $keyLine, $keyOf([...$key['members'], 'room'])],
            'has «room», which the probe\'s KEY_MEMBERS does not pin',
        ];

        // A second key line ahead of the real one, inside a block comment: a reader that took the first
        // `JSON.stringify([` would read it for the key.
        $controls['a decoy key line before the real one'] = [
            ['scene.js', $keyLine, "/*\n        const kind = JSON.stringify([t.image, t.iw]);\n        */\n        {$keyLine}"],
            'has 2 lines «const kind = JSON.stringify([…]);», not exactly one',
        ];

        // One per PINNED member, dropped from the shipped key: its planted pair merges, and the probe must say
        // so on the pair itself AND as the key's drift from the pin.
        foreach ($key['pinned'] as $member) {
            $controls["«{$member}» dropped from the merge key"] = [
                ['scene.js', $keyLine, $keyOf(array_filter($key['members'], fn (string $m): bool => $m !== $member))],
                ["differ only in «{$member}» and are painted as ONE primitive", "lacks the pinned member «{$member}»"],
            ];
        }

        // Each control names the defect text it must produce — every one of them, where it lists several.
        foreach ($controls as $name => [$edit, $named]) {
            $defects = $this->probe([], $this->mutatedModules($edit))['defects'];

            $this->assertNotSame([], $defects, "CONTROL ({$name}) did not bite");

            foreach ((array) $named as $text) {
                $this->assertNotEmpty(array_filter($defects, fn (string $d): bool => str_contains($d, $text)),
                    "CONTROL ({$name}) did not name «{$text}»:\n".implode("\n", array_slice($defects, 0, 3)));
            }
        }
    }
}

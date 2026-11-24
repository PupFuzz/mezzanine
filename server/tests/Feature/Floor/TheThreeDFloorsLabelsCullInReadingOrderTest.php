<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The 3-D floor's label cull and texture size** — `floor3d/label-cull.js`'s `cullOverlaps()` keeps, of labels whose
 * boxes overlap, the first in reading order (or the named leader), and `floor3d/svg-texture.js`'s `textureSize()`
 * holds a drawing's texture under the cap, both driven under `node` by `label-cull-probe.mjs`. The labels drawn over
 * a real camera are `tools/design/floor3d.browser.mjs`'s rows.
 */
class TheThreeDFloorsLabelsCullInReadingOrderTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor3d')
            ?: $this->fail('server/public/js/floor3d does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/label-cull-probe.mjs';
    }

    public function test_green_the_cull_keeps_in_reading_order_and_the_texture_stays_under_the_cap(): void
    {
        $this->assertSame([], array_merge(...array_values($this->probe([])['legs'])));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function plants(): array
    {
        return [
            'the cull keeping every label' => ['label-cull.js', '        if (kept.some((key) => overlaps(boxes.get(key), box))) {', '        if (false) {', 'cull'],
            'the leader ignored' => ['label-cull.js', '    const led = leader === null ? order :', '    const led = true ? order :', 'cull'],
            'the order the screen\'s, not the scene\'s' => ['label-cull.js', 'a.at.y - b.at.y || a.at.x - b.at.x', 'a.at.x - b.at.x || a.at.y - b.at.y', 'cull'],
            'the texture uncapped' => ['svg-texture.js', '    const k = Math.min(s, cap / Math.max(extent.w, extent.h));', '    const k = s;', 'texture'],
            'the device\'s own limit ignored' => ['svg-texture.js', '    const cap = Math.min(maxTexture, TEXTURE_CAP);', '    const cap = TEXTURE_CAP;', 'texture'],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_plant_reds_its_leg(string $file, string $anchor, string $replacement, string $leg): void
    {
        $legs = $this->probe([], $this->mutatedModules([$file, $anchor, $replacement]))['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }
}

<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **AT-D3-38's clock** — `docs/design/plan-13033-stage2-3d-floor.md` § 3.5 and § 8 item 2 (card#13033 stage 2 slice 5),
 * `docs/design/FLOOR.md` § 6.3's timer bullet: the 3-D floor's clock (`floor3d/clip-clock.js`) names, for every held row
 * the 3-D floor steps, the loop frame the arithmetic names at an instant — `⌊T ÷ frame_interval_ms⌋` plus the key's own
 * loop offset — with its clip's pose, its blink and its marks' pulse; two keys in one state step apart; a held rendering
 * without motion is its still at every instant and asks for no step; a walk steps while it runs and stands once it has
 * ended. `clip-clock-probe.mjs` drives it over the shipped character tree and the animation set's own renderings.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a drawing. That the renderer SETS what this answers on every frame from the paint's
 * instant — and that a long task between frames moves nothing — is the browser leg's `clips` row
 * (`tools/design/floor3d.browser.mjs`), which reads the pose a page drew against the time it drew it at.
 */
class TheThreeDFloorsClockIsSetNotAccumulatedTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/wire')
            ?: $this->fail('server/public/js/wire does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/clip-clock-probe.mjs';
    }

    public function test_green_every_held_form_is_the_frame_its_time_and_offset_name(): void
    {
        $out = $this->probe([]);

        $this->assertSame([], array_merge(...array_values($out['legs'])));
        $this->assertGreaterThan(0, $out['printed']['reads'], 'the probe read no form');
    }

    /**
     * ⛔ THE REDS — each the plan's plant (§ 8 item 2), seen red on the leg that names it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function plants(): array
    {
        $clock = '../floor3d/clip-clock.js';
        $frame = '    const frame = (Math.floor(since / ms) + characters.loopOffset(key, length)) % length;';

        return [
            'the offset dropped from the loop\'s time' => [$clock, $frame, '    const frame = Math.floor(since / ms) % length;', 'offset'],
            'the loop keyed on the row, not on its rendering\'s motion' => [$clock,
                "    const moving = form !== undefined && held.motion === true && typeof held.frame_interval_ms === 'number';",
                "    const moving = form !== undefined;", 'still'],
            'the clock keeping its own time, a step a call' => [$clock, $frame,
                '    const frame = (Math.floor((globalThis.kept = Math.min(since, (globalThis.kept ?? 0) + ms)) / ms) + characters.loopOffset(key, length)) % length;', 'pose'],
            'the blink a frame late' => [$clock, '        blink: form?.blink === true && !still && f % BLINK_LOOP === BLINK_LOOP - 1,',
                '        blink: form?.blink === true && !still && f % BLINK_LOOP === 0,', 'pose'],
            'a walk drawn without its motion read as running' => [$clock, '    return { t, running: fx.frames > 0 && t < span };', '    return { t, running: t < span };', 'walk'],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_plant_reds_its_leg(string $file, string $anchor, string $replacement, string $leg): void
    {
        $legs = $this->probe([], $this->mutatedModules([$file, $anchor, $replacement]))['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }
}

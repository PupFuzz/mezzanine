<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-19 — an asset that fails to load leaves every fact on the desk.** `docs/design/FLOOR.md
 * § 11`, § 9 F14 mechanised, gated at Appendix B row 14 (card#7341 step 11) — the first row with art
 * to fail. The observable is the SCENE's, which is why the harness can read it: the painter reports
 * which assets failed (the fixture's `asset_failures`, delivered exactly as the painter delivers
 * them, through `assetsFailed()`), and the scene draws the placeholder for the desks that lost art.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS REPLAY `fx-snapshot-4` ON THE SHIPPED DEFAULT MAP ITSELF (`fixtures/fx-scene.json`,
 * `@json:resources/floor/default.tmj`) with the vendored tileset served by the asset route's URL, so
 * "every image of the room's tileset" is the vendored pack's own image list, read by the tileset
 * reader — never a list written here.
 *
 * ⛔ THE STRIP's WORDS ARE § 9 F14's, READ OUT OF THE DOCUMENT on every run.
 *
 * ⚠ NOT MECHANISED, as § 11 says: F14's recovery — *retry on reload* — is a request the browser
 * makes, which the scene cannot show.
 */
class AnAssetThatFailsToLoadLeavesEveryFactOnTheDeskTest extends TestCase
{
    use DrivesTheScene;

    private const INTACT = 'scene_default';

    private const TILESET_FAILS = 'scene_default_tileset_fails';

    private const CHARACTER_FAILS = 'scene_default_character_fails';

    /** The instant the fixture's painter reports the failure. */
    private const FAILS_AT = 50;

    public function test_green_with_the_tileset_failed_every_desk_is_the_placeholder_under_no_tiles_and_the_strip_says_so(): void
    {
        $result = $this->floorRun(self::TILESET_FAILS);
        $frame = $this->lastFloor($result);
        $scene = $this->lastScene($result, self::TILESET_FAILS);

        $this->assertSame([], $this->placeholderDefects($scene, array_column($scene['desks'], 'key')));
        $this->assertSame([], $scene['tiles'], 'tiles whose images failed are still drawn');
        $this->assertSame($this->f14Line(), $frame['strip']['art'], 'the strip does not read F14\'s line');
        $this->assertSame([], $this->logDefects($result));
    }

    public function test_green_with_one_characters_art_failed_that_desk_alone_is_the_placeholder_and_the_tiles_are_drawn(): void
    {
        $result = $this->floorRun(self::CHARACTER_FAILS);
        $scene = $this->lastScene($result, self::CHARACTER_FAILS);
        $intact = $this->sceneOf(self::INTACT);

        $this->assertSame([], $this->placeholderDefects($scene, ['aimla/aimla-pm']));
        $this->assertNotSame([], $scene['tiles'], 'the room\'s tiles are not drawn');
        $this->assertCount(count($intact['tiles']), $scene['tiles'], 'one seat\'s character failing cost the room tiles');
        $this->assertSame($this->f14Line(), $this->lastFloor($result)['strip']['art']);
        $this->assertSame([], $this->logDefects($result));
    }

    /** The discriminating control: the gate is known to be able to say *the art is there*. */
    public function test_control_with_every_asset_loaded_no_desk_is_the_placeholder_and_the_strip_says_nothing(): void
    {
        $result = $this->floorRun(self::INTACT);
        $scene = $this->lastScene($result, self::INTACT);

        $this->assertSame([], $this->placeholderDefects($scene, []));
        $this->assertNotSame([], $scene['tiles']);
        $this->assertSame([], $scene['failed']);
        $this->assertNull($this->lastFloor($result)['strip']['art']);
    }

    public function test_red_the_blank_desk(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            "    if (ctx.placeholder) {\n",
            "    if (ctx.placeholder) {\n        return { elements: [{ kind: 'placeholder', member: null, x: 0, y: 0, w: 1, h: 1 }], bubble: null };\n"]);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::TILESET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the blank desk) did not fail');
    }

    public function test_red_the_silent_strip(): void
    {
        $dir = $this->mutatedModules(['../floor/floor-screen.js',
            'art_failed: scene?.art_failed === true ||', 'art_failed: false &&']);

        $this->assertNotSame($this->f14Line(), $this->lastFloor($this->floorRun(self::TILESET_FAILS, $dir))['strip']['art'] ?? null,
            'RED (the silent strip) did not fail');
    }

    public function test_red_the_fact_dropped_with_the_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'drawn.forEach((badge, i) => {', '(ctx.placeholder ? [] : drawn).forEach((badge, i) => {']);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::TILESET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the badge cluster dropped with the art) did not fail');
    }

    /**
     * Every desk in `$expected` draws the placeholder — "a plain rectangle carrying the nameplate, the
     * state label and the badge cluster — every fact, no art" — and every other desk draws its art.
     */
    private function placeholderDefects(array $scene, array $expected): array
    {
        $defects = [];

        foreach ($scene['desks'] as $desk) {
            $kinds = array_column($desk['elements'], 'kind');
            $art = array_intersect($kinds, ['character', 'chair', 'desk-sprite', 'monitor']);

            if (! in_array($desk['key'], $expected, true)) {
                if ($desk['placeholder'] || $art === []) {
                    $defects[] = "{$desk['key']} lost its art with nothing failed for it";
                }

                continue;
            }

            if (! $desk['placeholder'] || ! in_array('placeholder', $kinds, true)) {
                $defects[] = "{$desk['key']} draws no placeholder";
            }

            if ($art !== []) {
                $defects[] = "{$desk['key']}'s placeholder still draws art: ".implode(', ', $art);
            }

            foreach (['nameplate', 'label'] as $fact) {
                if (! in_array($fact, $kinds, true)) {
                    $defects[] = "{$desk['key']}'s placeholder dropped its {$fact}";
                }
            }

            $badges = $this->seatBadges($desk['key']);

            if (count($this->elementsOf($desk, 'badge')) !== count($badges)) {
                $defects[] = "{$desk['key']}'s placeholder dropped its badge cluster";
            }
        }

        return $defects;
    }

    /** The badges the fixture's snapshot carries for one seat. */
    private function seatBadges(string $key): array
    {
        foreach ($this->fixture(self::INTACT)['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                if ("{$seat['install_id']}/{$seat['seat_id']}" === $key) {
                    return $seat['badges'];
                }
            }
        }

        $this->fail("no seat {$key} in the fixture");
    }

    /**
     * "The animation log gains no row from either failure and every held render entered before it is
     * still open, because an asset failure is not a state change."
     */
    private function logDefects(array $result): array
    {
        $defects = [];
        $failedAt = $this->correctedInstantOf($result, self::FAILS_AT);
        $rows = $result['animation_log'];

        $this->assertNotSame([], array_filter($rows, fn ($r) => $r['phase'] === 'entered'),
            'no held render was entered before the failure — "still open" would measure nothing');

        foreach ($rows as $row) {
            if ($row['at'] >= $failedAt) {
                $defects[] = "the log gained a {$row['animation_id']} row at the failure";
            }
        }

        $left = array_column(array_filter($rows, fn ($r) => $r['phase'] === 'left'), 'episode_id');

        foreach ($rows as $row) {
            if ($row['phase'] === 'entered' && $row['at'] < $failedAt && in_array($row['episode_id'], $left, true)) {
                $defects[] = "{$row['seat_id']}'s held render ended through the failure";
            }
        }

        return $defects;
    }

    /**
     * The log's `at` for a scenario instant: the corrected server clock the rows are dated on, read
     * off the run's own record at that instant (the protocol's offset plus the browser's clock).
     */
    private function correctedInstantOf(array $result, int $scenarioMs): int
    {
        $record = $this->recordAt($result, $scenarioMs);

        return $scenarioMs + (int) $record['clock_offset_ms'];
    }

    /** § 9 F14's strip line, verbatim from the failure table. */
    private function f14Line(): string
    {
        $this->assertSame(1, preg_match('/^\| F14 \|.*?the status strip reads \*([^*]+)\*/m', $this->floorMd(), $m),
            '§ 9 F14 does not publish the strip\'s words in the form this test reads');

        return $m[1];
    }
}

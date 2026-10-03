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

    private const KIT_FAILS = 'scene_default_kit_fails';

    private const CHARACTER_FAILS = 'scene_default_character_fails';

    private const INTERNS = 'interns_cap';

    private const INTERN_FAILS = 'interns_cap_one_intern_fails';

    /** The instant the fixture's painter reports the failure. */
    private const FAILS_AT = 50;

    /** The art a desk draws as images — what the placeholder stands in for. */
    private const ART = ['character', 'chair', 'desk-sprite'];

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

    /**
     * The room's two tilesets fail independently (PR #232 round 2, MINOR-D): with the bridge kit's images
     * alone failed, every desk is the placeholder — the desk sprite is the kit's — and the first-party
     * floor plane's tiles are still drawn under them, none of the kit's among them.
     */
    public function test_green_with_the_kit_alone_failed_every_desk_is_the_placeholder_over_the_planes_tiles(): void
    {
        $result = $this->floorRun(self::KIT_FAILS);
        $frame = $this->lastFloor($result);
        $scene = $this->lastScene($result, self::KIT_FAILS);
        $failed = array_column($this->fixture(self::KIT_FAILS)['floor']['asset_failures'], 'tileset_images');

        $this->assertCount(1, $failed, 'the run fails exactly one tileset — the kit');
        $this->assertSame([], $this->placeholderDefects($scene, array_column($scene['desks'], 'key')));
        $this->assertNotSame([], $scene['tiles'], 'the plane\'s tiles are not drawn');
        $this->assertSame([], array_values(array_filter($scene['tiles'], fn ($t) => in_array($t['tileset'], $failed, true))),
            'a tile of the failed kit is still drawn');
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

    /**
     * § 9 F14's PER-STOOL FALLBACK (card#11058 PR-C): one intern's art failing falls back for that stool
     * alone — it is drawn as the glyph in its own rect, every other intern on that desk and on every desk
     * keeps its sprite, the seat's own art stays (no placeholder), and the strip reads F14's line. The
     * failed asset is the fixture's, delivered as the painter delivers it.
     */
    public function test_green_with_one_interns_art_failed_that_stool_alone_falls_back(): void
    {
        $this->assertSame([], $this->internFallbackDefects($this->floorRun(self::INTERN_FAILS)));
    }

    /** The discriminating control for the per-stool leg: with nothing failed, every intern is its sprite. */
    public function test_control_with_no_interns_art_failed_every_intern_is_its_sprite(): void
    {
        $scene = $this->sceneOf(self::INTERNS);
        $stools = array_merge(...array_map(fn (array $d): array => $this->elementsOf($d, 'stool'), $scene['desks']));

        $this->assertGreaterThan(8, count($stools), 'the cap leg drew too few interns to say anything');
        $this->assertSame([], array_values(array_filter($stools, fn (array $e): bool => $e['art'] !== true)));
        $this->assertSame([], $scene['failed']);
    }

    /** Each way the per-stool fallback can be wrong, planted in the module it would live in. */
    public function test_red_the_per_stool_fallback(): void
    {
        $plants = [
            'the whole desk drawn as the placeholder' => ['../floor/scene.js',
                'return sprite === null || (desk.character && failed.has(character));',
                'return sprite === null || (desk.character && failed.has(character)) || desk.side_table.stools.some((s) => failed.has(`intern:${desk.install_id}/${desk.seat_id}~${s.call_id}`));'],
            'every intern of the desk falling back' => ['../floor/desk-layout.js',
                'art: !ctx.failed.has(asset),', 'art: ![...ctx.failed].some((id) => id.startsWith(`intern:${desk.install_id}/${desk.seat_id}~`)),'],
            'the failure ignored' => ['../floor/desk-layout.js', 'art: !ctx.failed.has(asset),', 'art: true,'],
            'the strip silent about an intern' => ['../floor/scene.js', "            if (e.kind === 'stool') {\n                used.add(e.asset);",
                "            if (e.kind === 'stool') {\n                void e;"],
        ];

        foreach ($plants as $what => $edit) {
            $this->assertNotSame([], $this->internFallbackDefects($this->floorRun(self::INTERN_FAILS, $this->mutatedModules($edit))),
                "RED ({$what}) did not fail");
        }
    }

    /** @return list<string> */
    private function internFallbackDefects(array $result): array
    {
        $scene = $this->lastScene($result, self::INTERN_FAILS);
        $failed = array_merge(...array_column($this->fixture(self::INTERN_FAILS)['floor']['asset_failures'], 'assets'));
        $defects = $this->placeholderDefects($scene, []);
        $fellBack = [];

        $this->assertCount(1, $failed, 'the run fails exactly one intern');

        foreach ($scene['desks'] as $desk) {
            foreach ($this->elementsOf($desk, 'stool') as $e) {
                $asset = "intern:{$desk['install_id']}/{$desk['seat_id']}~{$e['call_id']}";

                if ($e['art'] === in_array($asset, $failed, true)) {
                    $defects[] = $e['art'] ? "{$asset} failed and is still drawn as its sprite" : "{$asset} fell back with nothing failed for it";
                }

                if (! $e['art']) {
                    $fellBack[] = $asset;
                }
            }
        }

        if ($fellBack === []) {
            $defects[] = 'no intern fell back — the failed asset reached no stool';
        }

        if ($scene['failed'] !== $failed) {
            $defects[] = 'the scene names ['.implode(', ', $scene['failed']).'] failed, not the intern\'s asset';
        }

        if (($this->lastFloor($result)['strip']['art'] ?? null) !== $this->f14Line()) {
            $defects[] = 'the strip does not read F14\'s line for a failed intern';
        }

        return [...$defects, ...$this->logDefects($result)];
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
            "    if (ctx.placeholder) {\n        rect('placeholder', null, R.placeholder);\n",
            "    if (ctx.placeholder) {\n        return { elements: [{ kind: 'placeholder', member: null, x: 0, y: 0, w: 1, h: 1 }], bubble: null };\n"]);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::TILESET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the blank desk) did not fail');
    }

    public function test_red_the_failed_tile_drawn(): void
    {
        // A tile whose image the painter reported failed is drawn anyway: with the kit alone failed, the
        // scene puts a kit tile beside the plane's, and the kit-alone leg reds on it.
        $dir = $this->mutatedModules(['../floor/scene.js',
            'if (cell.image === null || failed.has(cell.image)) {', 'if (cell.image === null) {']);
        $failed = array_column($this->fixture(self::KIT_FAILS)['floor']['asset_failures'], 'tileset_images');
        $tiles = $this->sceneOf(self::KIT_FAILS, $dir)['tiles'];

        $this->assertNotSame([], array_values(array_filter($tiles, fn ($t) => in_array($t['tileset'], $failed, true))),
            'RED (the failed tile drawn) did not put a kit tile on the floor');
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
            'row.forEach((badge, i) => {', '(ctx.placeholder ? [] : row).forEach((badge, i) => {']);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::TILESET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the badge row dropped with the art) did not fail');
    }

    /** The monitor and its text are facts (card#11058's desk): dropped with the art, the placeholder reds. */
    public function test_red_the_monitor_dropped_with_the_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            "    rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });\n\n    if (desk.monitor.lit !== 'off') {",
            "    if (!ctx.placeholder) rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });\n\n    if (desk.monitor.lit !== 'off' && !ctx.placeholder) {"]);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::TILESET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the monitor dropped with the art) did not fail');
    }

    /** The bubble is a fact too: a placeholder desk that loses it reds. */
    public function test_red_the_bubble_dropped_with_the_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            '    return { elements, bubble: desk.bubble };', '    return { elements, bubble: ctx.placeholder ? null : desk.bubble };']);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::TILESET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the bubble dropped with the art) did not fail');
    }

    /**
     * Every desk in `$expected` draws the placeholder — § 9 F14's "plain rectangle in place of the art's
     * images only" — with EVERY FACT drawn as on the intact desk: the nameplate, the chip and the label
     * line, the badge row and the flag, the monitor and its text, the bubble and every other fact
     * (card#11058's desk); and every other desk draws its art. The intact desk is the same seat's on the
     * run with nothing failed, so the comparison is the drawing's own, not a list written here.
     */
    private function placeholderDefects(array $scene, array $expected): array
    {
        $defects = [];
        $intact = [];

        foreach ($this->sceneOf(self::INTACT)['desks'] as $desk) {
            $intact[$desk['key']] = $desk;
        }

        $this->assertNotSame([], array_filter($intact, fn (array $d): bool => $d['bubble'] !== null),
            'no intact desk draws a bubble — the bubble half of the comparison reads nothing');

        foreach ($scene['desks'] as $desk) {
            $kinds = array_column($desk['elements'], 'kind');
            $art = array_intersect($kinds, self::ART);

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

            $want = $intact[$desk['key']] ?? null;

            if ($want === null) {
                $defects[] = "{$desk['key']} has no intact desk to compare its facts with";

                continue;
            }

            if (($lost = array_values(array_diff($this->facts($want), $this->facts($desk)))) !== []) {
                $defects[] = "{$desk['key']}'s placeholder dropped facts the intact desk draws: ".implode('; ', $lost);
            }

            if (($want['bubble'] === null) !== ($desk['bubble'] === null)) {
                $defects[] = "{$desk['key']}'s placeholder dropped its bubble";
            }
        }

        return $defects;
    }

    /**
     * A desk's facts as drawn: every element that is not the art or the placeholder, by kind and the
     * value it carries.
     *
     * @return list<string>
     */
    private function facts(array $desk): array
    {
        $facts = [];

        foreach ($desk['elements'] as $e) {
            if (in_array($e['kind'], [...self::ART, 'placeholder'], true)) {
                continue;
            }

            $facts[] = $e['kind'].' '.($e['text'] ?? $e['lit'] ?? $e['badge'] ?? 'present');
        }

        sort($facts);

        return $facts;
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

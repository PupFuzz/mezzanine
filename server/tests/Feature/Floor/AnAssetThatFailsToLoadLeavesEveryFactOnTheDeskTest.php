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
 * `@json:resources/floor/default.tmj`) with the shipped tileset served by the asset route's URL and the
 * theme registry and every theme it names imported from disk, as the page imports them (card#11046,
 * Appendix B row 22). F14's causes since row 22 are the theme's (FLOOR.md § 10.6 item 8): the floor's theme
 * failed, one desk's furniture set failed, the registry's import rejected — and a seat's character, an
 * intern's sprite and a tileset, as before. A desk's art is its theme's, never a tile's, so a tileset that
 * fails costs the room its walls, accents and scenery and no desk its art.
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

    private const THEME_FAILS = 'scene_default_theme_fails';

    private const DESK_SET_FAILS = 'scene_default_desk_set_fails';

    private const REGISTRY_FAILS = 'scene_default_registry_fails';

    private const CHARACTER_FAILS = 'scene_default_character_fails';

    private const INTERNS = 'interns_cap';

    private const INTERN_FAILS = 'interns_cap_one_intern_fails';

    /** The instant the fixture's painter reports the failure. */
    private const FAILS_AT = 50;

    /** FLOOR.md § 10.6's art elements and the character (card#11046 row 20): the placeholder stands in for these. */
    private const ART = ['character', 'chair', 'desk-sprite', 'monitor-frame', 'desk-props', 'side-table'];

    /**
     * A tileset that fails (its fetch answered 404) costs the room every tile the floor draws by its kind —
     * the walls, the accent and the standing pieces — and no desk its art, because a desk's art is its theme's
     * (FLOOR.md § 10.6 item 8); the plane is still drawn, and the strip names the tileset.
     */
    public function test_green_with_the_tileset_failed_no_tile_is_drawn_and_every_desk_keeps_its_art(): void
    {
        $result = $this->floorRun(self::TILESET_FAILS);
        $frame = $this->lastFloor($result);
        $scene = $this->lastScene($result, self::TILESET_FAILS);

        $this->assertSame([], $this->placeholderDefects($scene, []));
        $this->assertSame([], $scene['scenery'], 'standing pieces of a tileset that failed are still drawn');
        $this->assertSame([], $this->runsDrawn($scene), 'walls or accents of a tileset that failed are still drawn');
        $this->assertNotSame([], $scene['planes'], 'the plane is not drawn');
        $this->assertSame(['/art/floor/tiles/floor-plane.tsx'], $scene['failed']);
        $this->assertSame($this->f14Line(), $frame['strip']['art'], 'the strip does not read F14\'s line');
        $this->assertSame([], array_values(array_filter($result['animation_log'], fn ($r) => $r['class'] === 'edge')),
            'the tileset\'s failure wrote an edge row');
    }

    /**
     * AT-D3-25's failure leg, the theme's import rejected (reported at t=50): every desk draws F14's
     * placeholder with every fact as on the intact desk, the band and every plane draw only their flat
     * fallback fills — no theme document is asked for — and the strip names `theme:studio`.
     */
    public function test_green_with_the_theme_failed_every_desk_is_the_placeholder_over_the_fallback_fills(): void
    {
        $result = $this->floorRun(self::THEME_FAILS);
        $scene = $this->lastScene($result, self::THEME_FAILS);

        $this->assertSame([], $this->themeFailedDefects($scene, ['theme:studio']));
        $this->assertSame($this->f14Line(), $this->lastFloor($result)['strip']['art'], 'the strip does not read F14\'s line');
        $this->assertSame([], $this->logDefects($result));
    }

    /** The registry's import rejected (card#11046 review finding): the same fallback, the registry named. */
    public function test_green_with_the_registry_failed_every_desk_is_the_placeholder_and_the_registry_is_named(): void
    {
        $result = $this->floorRun(self::REGISTRY_FAILS);
        $scene = $this->lastScene($result, self::REGISTRY_FAILS);

        $this->assertSame([], $this->themeFailedDefects($scene, ['/art/floor/themes/index.js']));
        $this->assertSame($this->f14Line(), $this->lastFloor($result)['strip']['art'], 'the strip does not read F14\'s line');
    }

    /** One desk's furniture set failing: that desk alone draws the placeholder, and its asset alone is named. */
    public function test_green_with_one_desks_furniture_set_failed_that_desk_alone_is_the_placeholder(): void
    {
        $result = $this->floorRun(self::DESK_SET_FAILS);
        $scene = $this->lastScene($result, self::DESK_SET_FAILS);

        $this->assertSame([], $this->placeholderDefects($scene, ['aimla/aimla-pm']));
        $this->assertSame(['theme:studio/desk:aimla/aimla-pm'], $scene['failed']);
        $this->assertNotNull($scene['band_docs'], 'one desk\'s set failing cost the band its theme');
        $this->assertSame($this->f14Line(), $this->lastFloor($result)['strip']['art']);
        $this->assertSame([], $this->logDefects($result));
    }

    public function test_green_with_one_characters_art_failed_that_desk_alone_is_the_placeholder_and_the_room_is_drawn(): void
    {
        $result = $this->floorRun(self::CHARACTER_FAILS);
        $scene = $this->lastScene($result, self::CHARACTER_FAILS);
        $intact = $this->sceneOf(self::INTACT);

        $this->assertSame([], $this->placeholderDefects($scene, ['aimla/aimla-pm']));
        $this->assertNotSame([], $scene['scenery'], 'the room\'s scenery is not drawn');
        $this->assertCount(count($intact['scenery']), $scene['scenery'], 'one seat\'s character failing cost the room scenery');
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
                'return set === null || failed.has(set) || (desk.character && failed.has(character));',
                'return set === null || failed.has(set) || (desk.character && failed.has(character)) || desk.side_table.stools.some((s) => failed.has(`intern:${desk.install_id}/${desk.seat_id}~${s.call_id}`));'],
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
        $this->assertNotSame([], $scene['scenery']);
        $this->assertNotSame([], $this->runsDrawn($scene));
        $this->assertNotNull($scene['band_docs']);
        $this->assertSame([], $scene['failed']);
        $this->assertNull($this->lastFloor($result)['strip']['art']);
    }

    public function test_red_the_blank_desk(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            "    if (ctx.placeholder) {\n        rect('placeholder', null, R.placeholder);\n",
            "    if (ctx.placeholder) {\n        return { elements: [{ kind: 'placeholder', member: null, x: 0, y: 0, w: 1, h: 1 }], bubble: null };\n"]);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::THEME_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the blank desk) did not fail');
    }

    /** A theme reported failed whose desks keep drawing its furniture: the failure leg reds. */
    public function test_red_the_failed_theme_drawn_anyway(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            "    const held = (themes.modules?.[resolved.theme] ?? null) !== null && !failed.has(asset);",
            "    const held = (themes.modules?.[resolved.theme] ?? null) !== null;"]);

        $this->assertNotSame([], $this->themeFailedDefects($this->sceneOf(self::THEME_FAILS, $dir), ['theme:studio']),
            'RED (the failed theme drawn anyway) did not fail');
    }

    /** The registry's failure surfacing nothing — card#11046 row 21's review finding 1, as it stood. */
    public function test_red_the_registry_failure_silent(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            "    if (themes.registry === null) {\n        used.add(themes.asset);\n",
            "    if (themes.registry === null) {\n"]);

        $this->assertNotSame([], $this->themeFailedDefects($this->sceneOf(self::REGISTRY_FAILS, $dir), ['/art/floor/themes/index.js']),
            'RED (the registry failure surfacing nothing) did not fail');
    }

    /** One desk's set failing that takes every desk down with it: the per-desk leg reds. */
    public function test_red_one_desks_set_failing_every_desk(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'return set === null || failed.has(set) ||',
            'return set === null || [...failed].some((id) => id.startsWith(`theme:${theme.name}/desk:`)) ||']);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::DESK_SET_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (one desk\'s set failing every desk) did not fail');
    }

    public function test_red_the_silent_strip(): void
    {
        $dir = $this->mutatedModules(['../floor/floor-screen.js',
            'art_failed: scene?.art_failed === true ||', 'art_failed: false &&']);

        $this->assertNotSame($this->f14Line(), $this->lastFloor($this->floorRun(self::THEME_FAILS, $dir))['strip']['art'] ?? null,
            'RED (the silent strip) did not fail');
    }

    public function test_red_the_fact_dropped_with_the_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'row.forEach((badge, i) => {', '(ctx.placeholder ? [] : row).forEach((badge, i) => {']);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::THEME_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the badge row dropped with the art) did not fail');
    }

    /** The monitor and its text are facts (card#11058's desk): dropped with the art, the placeholder reds. */
    public function test_red_the_monitor_dropped_with_the_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            "    rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });\n\n    if (desk.monitor.lit !== 'off') {",
            "    if (!ctx.placeholder) rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });\n\n    if (desk.monitor.lit !== 'off' && !ctx.placeholder) {"]);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::THEME_FAILS, $dir), ['aimla/aimla-pm']),
            'RED (the monitor dropped with the art) did not fail');
    }

    /** The bubble is a fact too: a placeholder desk that loses it reds. */
    public function test_red_the_bubble_dropped_with_the_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            '    return { elements, bubble: desk.bubble };', '    return { elements, bubble: ctx.placeholder ? null : desk.bubble };']);

        $this->assertNotSame([], $this->placeholderDefects($this->sceneOf(self::THEME_FAILS, $dir), ['aimla/aimla-pm']),
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
     * The theme that cannot draw (FLOOR.md § 10.6 item 8): every desk the placeholder with every fact, the band
     * and every plane with no theme document — their flat fallback fills alone — no standing piece, and the
     * strip's failed assets exactly `$named`.
     *
     * @param  list<string>  $named
     * @return list<string>
     */
    private function themeFailedDefects(array $scene, array $named): array
    {
        $defects = $this->placeholderDefects($scene, array_column($scene['desks'], 'key'));

        if ($scene['band_docs'] !== null) {
            $defects[] = 'the band still asks the failed theme for its documents';
        }

        foreach ($scene['planes'] as $plane) {
            if ($plane['doc'] !== null) {
                $defects[] = 'a plane still asks the failed theme for its document';
            }
        }

        if ($scene['planes'] === []) {
            $defects[] = 'no plane is drawn — the flat fallback fills are gone too';
        }

        if ($scene['scenery'] !== []) {
            $defects[] = 'standing pieces are still drawn by the failed theme';
        }

        if ($scene['failed'] !== $named) {
            $defects[] = 'the strip names ['.implode(', ', $scene['failed']).'], not ['.implode(', ', $named).']';
        }

        return $defects;
    }

    /** The wall and accent runs every plane document carries. @return list<array<string, mixed>> */
    private function runsDrawn(array $scene): array
    {
        return array_merge(...array_map(fn (array $p): array => $p['doc'] === null ? [] : [...$p['doc']['input']['walls'], ...$p['doc']['input']['accents']], $scene['planes']), ...[[]]);
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

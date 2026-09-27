<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **A plate's name is read at the lobby page's own body text size, whatever the camera does.**
 * `docs/design/FLOOR.md` Appendix B row 16 — the operator's ruling on card#7343's F1 (2026-09-27): at
 * whole-building fit a plate's text shrinks with the plate, and the lobby exists to pick a floor, so a
 * floor's name is drawn over its plate at a FIXED SCREEN SIZE that the camera does not scale — the page's
 * own body text size. Plates keep a storey's proportions; § 12's zoom-to-read rule is the floor's.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SIZE IS MEASURED THROUGH THE SHIPPED MODULES. The runs are `fixtures/fx-plate-names.json`'s —
 * `building_rides`' entry under layouts of 2, 4, 8 and 10 floors, on the lobby's default surface (§ 12's
 * viewport floor) — replayed through the harness's lobby (`lobby/lobby-screen.js` over `wire/camera.js`),
 * which records, for the camera of every frame and of every act, the name's font (`lobby/building-scene.js`'s
 * `LABEL_FONT`), the zoom the plates are shown at and the name's own counter-scale (its `labelScale()`) —
 * and for a glide, the same under the camera the page shows halfway through it (`camera.js`'s `between()`,
 * which `wire/camera-view.js` steps a glide with). The size on the screen is their product: the font,
 * under the plates' `scale(zoom)`, under the name's own `scale(labelScale)` — which is how `lobby/main.js`
 * draws it, and `Tests\Feature\Lobby\LobbyPageWiringTest` holds that drawing's lines.
 *
 * ⛔ THE BODY TEXT SIZE IS THE PAGE's, AND NO FIGURE IS WRITTEN HERE. The lobby page ships no stylesheet
 * and sets no font size of its own, so its body text is at the root's size — the viewer's own — which is
 * `1rem`. `LABEL_FONT` is held to exactly that, and the page is held to setting no other: a stylesheet or
 * a font size arriving on the page reds here, because `1rem` would then no longer be its body text size.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: nothing here lays out or paints — there is no browser on the build host — so
 * that the browser draws the product above at the size above is the CSS transform model, not a measurement;
 * and where two names meet (a plate on the screen shorter than one line of body text) is not asserted,
 * because a line's height is the font's and no browser here measures it.
 */
class ThePlateNameIsReadAtTheBodyTextSizeTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    /** The runs, one per stack height the ruling's measurement named (card#7343 comment 6974). */
    private const RUNS = ['plate_names_2', 'plate_names_4', 'plate_names_8', 'plate_names_10'];

    private const SCENE = '../lobby/building-scene.js';

    private const EPSILON = 1e-9;

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_name_is_the_body_text_size_at_fit_after_a_wheel_and_mid_glide_at_every_stack_height(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->sizeDefects($this->replay($run)), "[{$run}]");
        }
    }

    /** The runs are what they say: N floors, and fit's zoom differs between them — else one size was measured four times. */
    public function test_green_the_runs_are_the_stack_heights_they_name(): void
    {
        $fits = [];

        foreach (self::RUNS as $run) {
            $floors = $this->fixture($run)['http']['/api/building'][0]['body']['layout']['floors'];
            $this->assertSame('plate_names_'.count($floors), $run, "{$run} does not hold the floors its name says");

            $fits[] = $this->fitLabel($this->replay($run))['zoom'];
        }

        $this->assertCount(count(self::RUNS), array_unique(array_map(static fn (float $z): string => sprintf('%.9f', $z), $fits)),
            'two stack heights fit at one zoom — the runs measure one plate size, not the ruling\'s range');
    }

    public function test_green_the_lobby_page_sets_no_text_size_of_its_own(): void
    {
        $this->assertSame([], $this->baseDefects($this->lobbyPage()));
    }

    // ── RED — each defect planted where it would live, and watched failing ──────────────────────

    /**
     * The geometry this ruling replaced (f7cbfe7): the name in the plate's own scene text — 48 scene px —
     * and scaled by the camera with the plate, so its size is the plate's and shrinks as the stack grows.
     */
    public function test_red_the_name_in_the_plates_scene_text_scaled_with_the_plate(): void
    {
        $dir = $this->mutatedModules([self::SCENE, $this->labelBlock(), str_replace(
            ["export const LABEL_FONT = '1rem';", 'return 1 / camera.zoom;'],
            ["export const LABEL_FONT = '48px';", 'return 1;'],
            $this->labelBlock(),
        )]);

        foreach (self::RUNS as $run) {
            $this->assertNotSame([], $this->sizeDefects($this->replay($run, $dir)), "[{$run}] CONTROL (f7cbfe7's geometry) did not bite");
        }
    }

    /** A name the camera scales: the page's size at zoom 1, and the plate's everywhere else. */
    public function test_red_a_name_the_camera_scales(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'return 1 / camera.zoom;', 'return 1;']);

        foreach (self::RUNS as $run) {
            $this->assertNotSame([], $this->sizeDefects($this->replay($run, $dir)), "[{$run}] CONTROL (a name the camera scales) did not bite");
        }
    }

    /** A name drawn at a size of its own rather than the page's — a px figure where the page's base belongs. */
    public function test_red_a_name_at_a_figure_of_its_own(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "export const LABEL_FONT = '1rem';", "export const LABEL_FONT = '16px';"]);

        $this->assertNotSame([], $this->sizeDefects($this->replay(self::RUNS[0], $dir)), 'CONTROL (a name at a px figure) did not bite');
    }

    /** A page that sets its body text size — `1rem` is then no longer it. */
    public function test_red_a_page_that_sets_its_own_text_size(): void
    {
        $html = $this->lobbyPage();

        foreach ([
            'a stylesheet' => '<link rel="stylesheet" href="/build/app.css">',
            'a style block' => '<style>body { font-size: 14px }</style>',
            'a style attribute' => '<main style="font-size: 14px">',
        ] as $what => $tag) {
            $planted = str_replace('<main>', "<main>{$tag}", $html);
            $this->assertNotSame($planted, $html, "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->baseDefects($planted), "CONTROL ({$what}) did not bite");
        }
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /**
     * Every name size a run shows, each a defect unless it is `1rem` on the screen — and the run must have
     * measured something: a fit, a wheel that moved the zoom, and two glides whose midpoints are neither end.
     *
     * @return list<string>
     */
    private function sizeDefects(array $result): array
    {
        $samples = [];
        $fit = $this->fitLabel($result);

        if ($fit === null) {
            return ['the run never drew the building at fit'];
        }

        $samples['whole-building fit'] = $fit;

        foreach ($result['lobby_renders'] as $r) {
            if (($r['frame']['building']['composed'] ?? false) === true) {
                $samples["the frame at {$r['at']} ms"] = $r['label'];
            }
        }

        $defects = [];

        foreach ($result['camera_acts'] as $a) {
            $act = $a['act']['act'];
            $samples["after the {$act} at {$a['at']} ms"] = $a['label']['after'];

            if ($act === 'wheel' && abs($a['after']['zoom'] - $a['before']['zoom']) < self::EPSILON) {
                $defects[] = "the wheel at {$a['at']} ms did not move the zoom — the clause measured nothing";
            }

            if (in_array($act, ['building', 'ride'], true)) {
                $mid = $a['label']['mid'];

                if ($mid === null || abs($mid['zoom'] - $a['before']['zoom']) < self::EPSILON || abs($mid['zoom'] - $a['after']['zoom']) < self::EPSILON) {
                    $defects[] = "the {$act} at {$a['at']} ms has no glide between two zooms — mid-glide measured nothing";

                    continue;
                }

                $samples["halfway through the {$act} at {$a['at']} ms"] = $mid;
            }
        }

        foreach (['wheel', 'building', 'ride'] as $act) {
            if (! in_array($act, array_map(static fn (array $a): string => $a['act']['act'], $result['camera_acts']), true)) {
                $defects[] = "the run has no {$act}";
            }
        }

        $shown = [];

        foreach ($samples as $when => $label) {
            [$size, $unit] = $this->onScreen($label);
            $shown[] = sprintf('%.2f%s', $size, $unit);

            if ($unit !== 'rem') {
                $defects[] = "{$when} the name is set in {$unit}, a size of its own and not the page's body text size";
            } elseif (abs($size - 1) > 1e-6) {
                $defects[] = sprintf('%s the name is %.4frem on the screen, not the body text size (1rem) — zoom %.4f', $when, $size, $label['zoom']);
            }
        }

        if (count(array_unique($shown)) > 1) {
            $defects[] = 'the name changes size with the camera: '.implode(', ', array_unique($shown));
        }

        return $defects;
    }

    /** The first frame that drew the building — at fit, whole-building, as the lobby is entered. */
    private function fitLabel(array $result): ?array
    {
        foreach ($result['lobby_renders'] as $r) {
            if (($r['frame']['building']['composed'] ?? false) === true) {
                $this->assertTrue($r['frame']['camera']['fitted'], 'the first frame that drew the building is not at fit');

                return $r['label'];
            }
        }

        return null;
    }

    /**
     * A name's size on the screen: its font, under the plates' `scale(zoom)` and its own `scale(scale)`.
     *
     * @return array{0: float, 1: string}
     */
    private function onScreen(array $label): array
    {
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?(rem|px)$/', $label['font'], "the name's font {$label['font']} is not a size this check reads");
        preg_match('/^(\d+(?:\.\d+)?)(rem|px)$/', $label['font'], $m);

        return [(float) $m[1] * $label['zoom'] * $label['scale'], $m[2]];
    }

    /**
     * Whether the lobby page, as a signed-in session receives it, sets a text size of its own — a
     * stylesheet, a style block, or a font size in a style attribute. Any of them makes the body text size
     * something other than the root's, which is what `1rem` is.
     *
     * @return list<string>
     */
    private function baseDefects(string $html): array
    {
        $defects = [];

        if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']?stylesheet/i', $html) === 1) {
            $defects[] = 'the lobby page loads a stylesheet — re-derive the body text size from it';
        }

        if (preg_match('/<style[\s>]/i', $html) === 1) {
            $defects[] = 'the lobby page carries a style block — re-derive the body text size from it';
        }

        preg_match_all('/\bstyle\s*=\s*"([^"]*)"/i', $html, $m);

        foreach ($m[1] as $style) {
            if (preg_match('/\bfont(-size)?\s*:/i', $style) === 1) {
                $defects[] = "the lobby page sets a font size in a style attribute (`{$style}`)";
            }
        }

        if (! str_contains($html, 'id="lobby-building"')) {
            $defects[] = 'the page read is not the lobby — it draws no building';
        }

        return $defects;
    }

    /** `LABEL_FONT` through `labelScale()`'s body in `lobby/building-scene.js` — one anchor for a plant of both. */
    private function labelBlock(): string
    {
        $source = (string) file_get_contents($this->moduleDir().'/'.self::SCENE);
        $start = strpos($source, "export const LABEL_FONT = '1rem';");
        $end = strpos($source, 'return 1 / camera.zoom;');
        $this->assertNotFalse($start, 'LABEL_FONT is not in building-scene.js');
        $this->assertNotFalse($end, "labelScale()'s body is not in building-scene.js");

        return substr($source, $start, $end + strlen('return 1 / camera.zoom;') - $start);
    }

    private function lobbyPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent() ?: '';
    }
}

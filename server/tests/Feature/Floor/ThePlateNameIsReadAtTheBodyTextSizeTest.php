<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **The lobby's plate labels** — `docs/design/FLOOR.md` § 4.1 is the contract (placement, the three-line
 * clip, the accessible name, paint); this file holds it against the shipped construction and source
 * wiring, never restating it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS PROVES IS THE ARITHMETIC OVER THE SHIPPED CONSTRUCTION — not a measurement of pixels on a
 * screen (there is no browser on the build host; `tools/design/lobby-label-contrast.browser.mjs` is
 * where pixels are actually measured, run by hand). The runs are `fixtures/fx-plate-names.json`'s —
 * `building_rides`' entry under several floor-count layouts, on the harness's default surface — replayed
 * through the harness's lobby (`lobby/lobby-screen.js` over `wire/camera.js`), which records
 * `showLabels()`'s OWN written values for the camera of every frame and of every act through
 * `fleet-client-probe.mjs`'s `plateLabel()`, which calls `showLabels()` directly (never a copy) against a
 * stand-in element. Each drawn frame's plates are then BUILT by the shipped `lobby/plate-row.js` under
 * `node` with a stand-in `document` (`plate-row-probe.mjs`), and every painted text in each row is read
 * where it sits: its font — the nearest `fontSize` on it or above it in the row, else the page's own body
 * size — times the zoom, times the counter-scale once for each `scale(var(--label-scale))` above it.
 * `plateRow()` itself carries no geometry of its own: every position, size, line-budget and paint
 * property it sets is a `var(--label-…)` reference, so what this file reads off a built row's `style` is
 * STRUCTURAL (does the row reference the right property?) while what it reads off `showLabels()`'s own
 * return is NUMERIC (does that property resolve correctly for this camera?) — two different questions,
 * both asked. The source wiring that joins them — `view()` calling `showLabels()`, and
 * `paint()`/`renderBuilding()` writing NO label geometry — is `Tests\Feature\Lobby\LobbyPageWiringTest`'s.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: nothing here lays out or paints — there is no browser on the build host —
 * so that a browser actually clips at `max-height`, ellipsizes at the right width, and paints the ink and
 * the backing this file only checks are REFERENCED, is `tools/design/lobby-label-contrast.browser.mjs`'s,
 * run by hand.
 */
class ThePlateNameIsReadAtTheBodyTextSizeTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    /** The runs, one per stack height the ruling's measurement named (card#7343 comment 6974). */
    private const RUNS = ['plate_names_2', 'plate_names_4', 'plate_names_8', 'plate_names_10'];

    private const SCENE = '../lobby/building-scene.js';

    private const ROW = '../lobby/plate-row.js';

    private const PAINT = '../lobby/label-paint.js';

    private const EPSILON = 1e-6;

    /** The counter-scale a plate label's text is drawn under — `lobby/main.js`'s `--label-scale`. */
    private const COUNTER_SCALE = 'scale(var(--label-scale))';

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_name_and_status_line_are_the_body_text_size_at_fit_after_a_wheel_and_mid_glide_at_every_stack_height(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->sizeDefects($this->replay($run)), "[{$run}]");
        }
    }

    public function test_green_the_label_lies_outside_the_shell_wherever_it_stands_beside_the_building(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->labelOutsideBuildingDefects($this->replay($run)), "[{$run}]");
        }
    }

    /**
     * card#7343 r4 review round: the −400 control was so extreme it would have bitten on almost any
     * check; `LABEL_GAP_PX = -1` is the smallest change that still eats the stated gap without running
     * the label INTO the shell by much, so it only bites if the guard actually holds the STATED 8px gap
     * rather than merely "does not overlap".
     */
    public function test_red_a_label_that_runs_into_the_building(): void
    {
        $dir = $this->mutatedModules([self::PAINT, 'export const LABEL_GAP_PX = 8;', 'export const LABEL_GAP_PX = -1;']);

        $this->assertNotSame([], $this->labelOutsideBuildingDefects($this->replay(self::RUNS[3], $dir), $dir),
            'CONTROL (a label that runs into the building) did not bite');
    }

    public function test_green_no_labels_own_line_budget_runs_it_taller_than_its_storey(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->overlapDefects($this->replay($run)), "[{$run}]");
        }
    }

    /** The line budget ignored — every label always shows its full 4 lines, regardless of its storey's own height. */
    public function test_red_a_label_that_overlaps_the_plate_below_it(): void
    {
        $dir = $this->mutatedModules([self::PAINT,
            'return Math.max(1, Math.floor((PLATE_H * camera.zoom) / LABEL_LINE_PX));', 'return 4;']);

        $this->assertNotSame([], $this->overlapDefects($this->replay(self::RUNS[3], $dir), $dir),
            'CONTROL (a label that overlaps the plate below it) did not bite');
    }

    /** Every status part is drawn somewhere in the runs — else the status clause read a line with nothing on it. */
    /** Every plate row every run draws, against `constructionDefects()` — the green its row plants below red. */
    public function test_green_every_plate_row_is_built_to_the_construction_contract(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->constructionDefects($this->rows($this->replay($run))), "[{$run}]");
        }
    }

    public function test_green_the_runs_draw_every_part_of_the_status_line(): void
    {
        foreach (self::RUNS as $run) {
            $parts = [];

            foreach ($this->rows($this->replay($run)) as [$plate, $row]) {
                foreach ($this->texts($row) as $t) {
                    $parts[] = $this->partOf($t['text'], $plate);
                }
            }

            foreach (['name', 'summary', 'rooms', 'here'] as $part) {
                $this->assertContains($part, $parts, "[{$run}] no plate draws its {$part}");
            }
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

    /**
     * P5's own ask: a camera move WITHOUT a render — `showLabels()` called directly (never a copy) against
     * hand-built cameras, and its written values checked against hand-computed expectations. This is
     * `showLabels()`'s own arithmetic, independent of `main.js`'s wiring (`LobbyPageWiringTest`'s).
     */
    public function test_green_showlabels_written_values_match_a_hand_computed_camera_at_every_side(): void
    {
        $plateInset = $this->sourceConstant(self::SCENE, 'PLATE_INSET');
        $gap = $this->sourceConstant(self::PAINT, 'LABEL_GAP_PX');
        $minPx = $this->sourceConstant(self::PAINT, 'LABEL_SIDE_MIN_PX');
        $linePx = $this->sourceConstant(self::PAINT, 'LABEL_LINE_PX');
        $plateH = $this->sourceConstant(self::SCENE, 'PLATE_H');
        $darkInk = $this->sourceStringConstant(self::PAINT, 'LABEL_DARK_INK');

        // A camera well clear of the fallback threshold (beside), and one deep enough into a zoom that the
        // shell's own edge is off the surface (the fallback) — the SAME two cases `sideFor()`'s own docblock
        // names: a narrow surface, and a deep zoom the clamp keeps the shell off-screen for.
        $cameras = [
            'beside, plenty of room' => ['surface' => ['width' => 1280, 'height' => 800], 'bounds' => ['x' => 0, 'y' => 0, 'w' => 1648, 'h' => 10880], 'zoom' => 0.0735294117647, 'x' => -7880, 'y' => 0],
            'fallback, zoomed onto one plate' => ['surface' => ['width' => 1280, 'height' => 800], 'bounds' => ['x' => 0, 'y' => 0, 'w' => 1648, 'h' => 10880], 'zoom' => 0.8, 'x' => 12, 'y' => 360],
            'fallback, a narrow surface' => ['surface' => ['width' => 375, 'height' => 812], 'bounds' => ['x' => 0, 'y' => 0, 'w' => 1648, 'h' => 2440], 'zoom' => 0.3324, 'x' => -20.08, 'y' => 0],
        ];

        $shown = $this->probe(['show_labels' => array_values($cameras)], null, __DIR__.'/plate-row-probe.mjs')['show_labels'];

        foreach (array_values(array_keys($cameras)) as $i => $what) {
            $camera = $cameras[$what];
            $got = $shown[$i];
            $shellLeft = ($camera['bounds']['x'] - $camera['x']) * $camera['zoom'];
            $side = max(0.0, $shellLeft) >= $minPx ? 'left' : 'plate';

            $this->assertSame($side, $got['side'], "[{$what}] side");
            $this->assertEqualsWithDelta(1 / $camera['zoom'], $got['scale'], self::EPSILON, "[{$what}] scale");

            // r5 review round: "asserting every written property" was false for the fallback branch —
            // only `left`/`align` were held there. Every property `showLabels()` writes is now asserted on
            // BOTH branches; `textInk`/`backing`/`halo` are held to the STRUCTURAL contract `label-paint.js`'s
            // own docblock states (beside: a different ink for text than the link, no backing, a halo;
            // falling back: the SAME dark ink for both, a near-opaque backing, no halo) rather than a
            // hand-duplicated colour literal, which would restate the very constants this test reads back.
            if ($side === 'left') {
                $width = max(0.0, $shellLeft - $gap);
                $left = -$plateInset - ($width + $gap) / $camera['zoom'];
                $this->assertEqualsWithDelta($width, $got['width'], self::EPSILON, "[{$what}] width");
                $this->assertEqualsWithDelta($left, $got['left'], self::EPSILON, "[{$what}] left");
                $this->assertSame('#ffcf7d', $got['ink'], "[{$what}] ink");
                $this->assertSame('right', $got['align'], "[{$what}] align");
                $this->assertNotSame($got['ink'], $got['textInk'], "[{$what}] beside: textInk should differ from the link's own ink");
                $this->assertSame('transparent', $got['backing'], "[{$what}] beside: backing should be transparent");
                $this->assertNotSame('none', $got['halo'], "[{$what}] beside: halo should be set");
            } else {
                // r6 review round (r5 review m5): `assertNotSame(0.0, $got['width'], …)` compares a float
                // literal against whatever PHP's JSON decode gave `width` — a PHP `0` (int) is never
                // `!==` a PHP `0.0` (float), so this could not fail regardless of the ACTUAL width. The
                // fallback width is now hand-computed from the same arithmetic `widthFor()`'s own docblock
                // states (the room on the plate, clamped to at least `LABEL_SIDE_MIN_PX` and at most the
                // surface's own width) and held with delta, exactly as the beside branch already is.
                $plateScreenLeft = ($camera['bounds']['x'] + $plateInset - $camera['x']) * $camera['zoom'];
                $fallbackWidth = min($camera['surface']['width'], max($minPx, $camera['surface']['width'] - $plateScreenLeft));

                $this->assertEqualsWithDelta(0.0, $got['left'], self::EPSILON, "[{$what}] left");
                $this->assertSame('left', $got['align'], "[{$what}] align");
                $this->assertEqualsWithDelta($fallbackWidth, $got['width'], self::EPSILON, "[{$what}] fallback width");
                // r6 review round: `$got['ink'] === $got['textInk']` held only that the two properties
                // AGREE with each other, never that either is actually the dark ink `label-paint.js`
                // ships — both could drift to some other shared colour and this would still pass. Both are
                // now held against `LABEL_DARK_INK`, read from source rather than duplicated as a literal.
                $this->assertSame($darkInk, $got['ink'], "[{$what}] fallback: ink should be the source's own dark ink");
                $this->assertSame($darkInk, $got['textInk'], "[{$what}] fallback: textInk should be the source's own dark ink");
                $this->assertNotSame('transparent', $got['backing'], "[{$what}] fallback: backing should be the near-opaque cream, not transparent");
                $this->assertSame('none', $got['halo'], "[{$what}] fallback: halo should be none — the opaque backing needs no halo under it");
            }

            $lines = max(1, (int) floor(($plateH * $camera['zoom']) / $linePx));
            $this->assertSame($lines, $got['lines'], "[{$what}] lines");
        }
    }

    /** A `view()` that never calls `showLabels()` at all — the wiring control belongs to `LobbyPageWiringTest`; this one is the primitive's own: a camera `showLabels()` never saw writes nothing. */
    public function test_green_showlabels_writes_nothing_when_the_camera_frames_nothing(): void
    {
        $shown = $this->probe(['show_labels' => [['surface' => ['width' => 1280, 'height' => 800], 'bounds' => null, 'zoom' => 1, 'x' => 0, 'y' => 0]]],
            null, __DIR__.'/plate-row-probe.mjs')['show_labels'][0];

        foreach (['scale', 'side', 'left', 'width', 'lines', 'ink', 'textInk', 'backing', 'halo', 'align'] as $key) {
            $this->assertNull($shown[$key], "showLabels() wrote {$key} for a camera that frames nothing");
        }
    }

    // ── RED — each defect planted where it would live, and watched failing ──────────────────────

    /**
     * The geometry the F1 ruling replaced (f7cbfe7): the plate's text in its own scene text — 48 scene px —
     * and scaled by the camera with the plate, so its size is the plate's and shrinks as the stack grows.
     */
    public function test_red_the_text_in_the_plates_scene_text_scaled_with_the_plate(): void
    {
        $dir = $this->mutatedModules([self::PAINT, $this->labelBlock(), str_replace(
            ["export const LABEL_FONT = '1rem';", "style.setProperty('--label-scale', String(1 / camera.zoom));"],
            ["export const LABEL_FONT = '48px';", "style.setProperty('--label-scale', String(1));"],
            $this->labelBlock(),
        )]);

        foreach (self::RUNS as $run) {
            $this->assertNotSame([], $this->sizeDefects($this->replay($run, $dir), $dir), "[{$run}] CONTROL (f7cbfe7's geometry) did not bite");
        }
    }

    /** A label the camera scales: the page's size at zoom 1, and the plate's everywhere else. */
    public function test_red_a_label_the_camera_scales(): void
    {
        $dir = $this->mutatedModules([self::PAINT, "style.setProperty('--label-scale', String(1 / camera.zoom));", "style.setProperty('--label-scale', String(1));"]);

        foreach (self::RUNS as $run) {
            $this->assertNotSame([], $this->sizeDefects($this->replay($run, $dir), $dir), "[{$run}] CONTROL (a label the camera scales) did not bite");
        }
    }

    /** A label drawn at a size of its own rather than the page's — a px figure where the page's base belongs. */
    public function test_red_a_label_at_a_figure_of_its_own(): void
    {
        $dir = $this->mutatedModules([self::PAINT, "export const LABEL_FONT = '1rem';", "export const LABEL_FONT = '16px';"]);

        $this->assertNotSame([], $this->sizeDefects($this->replay(self::RUNS[0], $dir), $dir), 'CONTROL (a label at a px figure) did not bite');
    }

    /**
     * Each defect planted in the plate's construction (`lobby/plate-row.js`), and the reason each must red
     * for — so a control that reds for another reason does not pass for this one.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rowPlants(): array
    {
        return [
            'the link\'s own text carries the summary too (redundant with aria-label, and visually wrong)' =>
                ["link.textContent = plate.name;", "link.textContent = plate.name + ' — ' + plate.summary;", 'link text'],
            'the summary drops out of the accessible name (the operator\'s ruling, undone)' =>
                ['link.setAttribute(\'aria-label\', `${plate.name}, ${summaryText}`);', 'link.setAttribute(\'aria-label\', plate.name);', 'aria-label'],
            'the summary link is a second tab stop' => ['summaryLink.tabIndex = -1;', '', 'tab stop'],
            'the summary link is announced a second time' => ["summaryLink.setAttribute('aria-hidden', 'true');", '', 'announced a second time'],
            'the summary link does not open the same floor' => ['summaryLink.href = plate.href;', "summaryLink.href = plate.href + '?x';", 'the same floor'],
            'a status line outside the label — the camera would never counter-scale it' => ["    label.append(line1, line2);", "    label.append(line1);\n    row.append(line2);"],
            'a name that does not ellipsize' => ["    whiteSpace: 'nowrap',\n", '', 'does not ellipsize'],
            'the summary before the name' => ['    label.append(line1, line2);', '    label.append(line2, line1);', 'ordering'],
            'a cue inside the link' => ['        line1.append(cue);', '        link.append(cue);', 'carries no cue'],
            'a label that does not clip whole lines' => ["clipPath: 'inset(-4px -4px 0 -4px)',", '', 'no clip'],
            'a label with no line budget' => ['maxHeight: `calc(var(--label-lines) * ${LABEL_LINE_PX}px)`,'."\n", '', 'no line budget'],
            'a label with no width to fit within' => ["width: 'var(--label-width)',\n", '', 'no width'],
            'a label at a left of its own' => ["left: 'var(--label-left)',", "left: '0px',", 'a left of its own'],
            'a label with no halo' => ["textShadow: 'var(--label-halo)',", '', 'no halo'],
            'a link whose colour is not the variable' => ["const link = textEl(doc, 'a', 'var(--label-ink)');", "const link = textEl(doc, 'a', '#ffcf7d');", 'colour is not the variable'],
            'the summary link underlined' => ["summaryLink.style.textDecoration = 'none';", '', 'underlined'],
            'the name link\'s focus ring drawn outside its box' => ["    link.style.outlineOffset = '-2px';", '', 'name link\'s outline-offset'],
            'the summary link\'s focus ring drawn outside its box' => ["summaryLink.style.outlineOffset = '-2px';", '', 'summary link\'s outline-offset'],
            'the cue hidden from assistive technology' => ["        cue.style.flex = '0 0 auto';", "        cue.style.flex = '0 0 auto';\n        cue.setAttribute('aria-hidden', 'true');", 'hidden from assistive technology'],
            'the rooms line hidden from assistive technology' => ['        line3.append(rooms);', "        line3.append(rooms);\n        line3.setAttribute('aria-hidden', 'true');", 'hidden from assistive technology'],
        ];
    }

    #[DataProvider('rowPlants')]
    public function test_red_each_plate_construction_defect(string $anchor, string $replacement, ?string $reason = null): void
    {
        $dir = $this->mutatedModules([self::ROW, $anchor, $replacement]);
        $defects = $this->constructionDefects($this->rows($this->replay(self::RUNS[0], $dir), $dir));

        $this->assertNotSame([], $reason === null ? $defects : array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite: ".json_encode($defects));
    }

    /** The backing removed from a visible line — the property the fallback's legibility rests on, and beside relies on for the halo's own placement to stay a paint concern rather than a structural one. */
    public function test_red_a_line_with_no_backing(): void
    {
        $dir = $this->mutatedModules([self::ROW, "backgroundColor: 'var(--label-backing)',", '']);
        $defects = $this->constructionDefects($this->rows($this->replay(self::RUNS[0], $dir), $dir));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, 'backing')),
            'CONTROL (a line with no backing) did not bite: '.json_encode($defects));
    }

    /** A page that sets its body text size — `1rem` is then no longer it. */
    public function test_red_a_page_that_sets_its_own_text_size(): void
    {
        $html = $this->lobbyPage();

        foreach ([
            'a stylesheet' => '<link rel="stylesheet" href="/build/app.css">',
            'a style block' => '<style>body { font-size: 14px }</style>',
            'a style attribute' => '<main style="font-size: 14px">',
            'a single-quoted style attribute' => "<main style='font-size: 14px'>",
            'an unquoted style attribute' => '<main style=font-size:14px>',
        ] as $what => $tag) {
            $planted = str_replace('<main>', "<main>{$tag}", $html);
            $this->assertNotSame($planted, $html, "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->baseDefects($planted), "CONTROL ({$what}) did not bite");
        }
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /**
     * Every painted text of every drawn plate, under every transform a run shows it at, each a defect
     * unless it is `1rem` on the screen — and the run must have measured something: a fit, a wheel that
     * moved the zoom, and two glides whose midpoints are neither end.
     *
     * @return list<string>
     */
    private function sizeDefects(array $result, ?string $dir = null): array
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

        $rows = $this->rows($result, $dir);

        if ($rows === []) {
            $defects[] = 'no drawn frame built a plate — the text clause measured nothing';
        }

        $shown = [];

        foreach ($rows as [$plate, $row]) {
            foreach ($this->texts($row) as $t) {
                if ($t['hidden']) {
                    continue;
                }

                $part = $this->partOf($t['text'], $plate);

                foreach ($samples as $when => $label) {
                    if ($label === null) {
                        continue;
                    }

                    [$size, $unit] = $this->onScreen($t, $label);
                    $shown[$part][] = sprintf('%.2f%s', $size, $unit);

                    if ($unit !== 'rem') {
                        $defects[] = "{$when} the plate {$plate['floor']}'s {$part} is set in {$unit}, a size of its own and not the page's body text size";
                    } elseif (abs($size - 1) > 1e-6) {
                        $defects[] = sprintf("%s the plate %s's %s is %.4frem on the screen, not the body text size (1rem) — zoom %.4f",
                            $when, $plate['floor'], $part, $size, $label['zoom']);
                    }
                }
            }
        }

        foreach ($shown as $part => $sizes) {
            if (count(array_unique($sizes)) > 1) {
                $defects[] = "the {$part} changes size with the camera: ".implode(', ', array_unique($sizes));
            }
        }

        return array_values(array_unique($defects));
    }

    /**
     * Every built row's structure, as `plate-row.js` sets it: the label reads `var(--label-left)`,
     * `var(--label-width)`, a `max-height` over `--label-lines`, `clip-path: inset(-4px -4px 0 -4px)` and
     * `text-shadow: var(--label-halo)`; line 1 is the name link (its text the name alone, its `aria-label`
     * the name and the summary, its colour `var(--label-ink)`) with the cue beside it on the cab's plate
     * only; line 2 is the summary link (same `href`, `tabIndex` -1, `aria-hidden`, `text-decoration: none`);
     * line 3 is the rooms line where the plate names any; both links' `outline-offset` is `-2px`; every
     * line is `nowrap` + `ellipsis` + `overflow: hidden` and carries `var(--label-backing)`; and nothing on
     * the path to the cue or the rooms line carries `aria-hidden`. What the label must do is FLOOR.md § 4.1.
     *
     * @param  list<array{0: array, 1: array, 2: bool, 3: array}>  $rows
     * @return list<string>
     */
    private function constructionDefects(array $rows): array
    {
        $defects = [];

        foreach ($rows as [$plate, $row, $here]) {
            $label = $this->find($row, static fn (array $n): bool => ($n['tag'] ?? null) === 'div');

            if ($label === null) {
                $defects[] = "the plate {$plate['floor']}'s row has no label div — it is not the plate's label";

                continue;
            }

            $style = $label['style'] ?? [];

            if (($style['left'] ?? null) !== 'var(--label-left)') {
                $defects[] = "the plate {$plate['floor']}'s label stands at a left of its own, not var(--label-left)";
            }

            if (($style['width'] ?? null) !== 'var(--label-width)') {
                $defects[] = "the plate {$plate['floor']}'s label has no width to fit within (var(--label-width) is missing)";
            }

            if (! str_contains((string) ($style['maxHeight'] ?? ''), '--label-lines')) {
                $defects[] = "the plate {$plate['floor']}'s label has no line budget (max-height does not read --label-lines)";
            }

            // card#7343 r4 review MAJOR 2: `overflow: clip` + `overflow-clip-margin` let the NEXT line's
            // own glyph tops paint through; `clip-path` replaces both, bled on three sides only.
            if (($style['clipPath'] ?? null) !== 'inset(-4px -4px 0 -4px)') {
                $defects[] = "the plate {$plate['floor']}'s label has no clip (clip-path is not inset(-4px -4px 0 -4px))";
            }

            if (($style['textShadow'] ?? null) !== 'var(--label-halo)') {
                $defects[] = "the plate {$plate['floor']}'s label has no halo (text-shadow is not var(--label-halo))";
            }

            $line1 = $label['children'][0] ?? null;

            if (($line1['tag'] ?? null) !== 'div' || ($line1['style']['display'] ?? null) !== 'flex') {
                $defects[] = "the plate {$plate['floor']}'s label's line 1 is not on line 1 — no flex row found first";

                continue;
            }

            // Every visible line is deterministic and whole-line clipped (FLOOR.md § 4.1): never
            // wrapped, ellipsized at its own trailing edge rather than clipped mid-glyph.
            $ellipsized = static function (?array $node) use (&$defects, $plate): void {
                if ($node === null) {
                    return;
                }

                $style = $node['style'] ?? [];

                if (($style['whiteSpace'] ?? null) !== 'nowrap' || ($style['textOverflow'] ?? null) !== 'ellipsis' || ($style['overflow'] ?? null) !== 'hidden') {
                    $defects[] = "the plate {$plate['floor']}'s ".($node['text'] ?? $node['tag']).' does not ellipsize a line that overflows it';
                }
            };

            $link = $line1['children'][0] ?? null;
            $expectedSummaryText = $plate['summary'] === '' ? 'no seats held' : $plate['summary'];

            if (($link['tag'] ?? null) !== 'a') {
                $defects[] = "the plate {$plate['floor']}'s line 1 does not start with the link";
            } elseif (! array_key_exists('aria-label', $link['attrs'] ?? [])) {
                // r6 review round (r5 review m4): a swap that puts the summary link first is NOT the same
                // defect as the name link's own aria-label holding a WRONG value — the summary link never
                // carries an `aria-label` key at all, so its ABSENCE (rather than a mismatched value) is
                // the ordering check's own, undiluted signal.
                $defects[] = "the plate {$plate['floor']}'s line 1 does not carry the name link — ordering";
            } else {
                $ellipsized($link);

                // The link's OWN text stays the name alone — the operator's ruling joins the summary into
                // the accessible name through `aria-label`, never by concatenating it into the link's own
                // visible text (which would show it twice: once here, once on line 2).
                if (($link['text'] ?? null) !== $plate['name']) {
                    $defects[] = "the plate {$plate['floor']}'s link text is not its name alone — link text";
                }

                if (($link['style']['color'] ?? null) !== 'var(--label-ink)') {
                    $defects[] = "the plate {$plate['floor']}'s link colour is not the variable — a :visited rule could beat an inherited one";
                }

                if (($link['style']['backgroundColor'] ?? null) !== 'var(--label-backing)') {
                    $defects[] = "the plate {$plate['floor']}'s link has no backing";
                }

                if (($link['style']['outlineOffset'] ?? null) !== '-2px') {
                    $defects[] = "the plate {$plate['floor']}'s name link's outline-offset is not -2px — its focus ring can fall outside the label's clip";
                }

                // The operator's ruling (2026-09-30, opq-1790766555-81d4): the accessible name is the name
                // AND the summary, together, in the exact punctuation the ruling's own example uses —
                // `aria-label` (empirically chosen over `aria-labelledby`, `plate-row.js`'s own docblock
                // says why) is a literal string this test can compare byte-for-byte.
                $ariaLabel = $link['attrs']['aria-label'] ?? null;

                if ($ariaLabel !== "{$plate['name']}, {$expectedSummaryText}") {
                    $defects[] = "the plate {$plate['floor']}'s link does not name the summary through aria-label — aria-label is '{$ariaLabel}'";
                }
            }

            $cue = $line1['children'][1] ?? null;
            $line3 = $label['children'][2] ?? null;

            // The cue and the rooms line reach a screen reader as ordinary text: nothing from the label
            // down to either carries `aria-hidden` (the summary link alone is hidden, above).
            foreach (['the label' => $label, 'line 1' => $line1, 'the cue' => $cue, 'line 3' => $line3, 'the rooms line' => $line3['children'][0] ?? null] as $what => $node) {
                if ($node !== null && array_key_exists('aria-hidden', $node['attrs'] ?? [])) {
                    $defects[] = "the plate {$plate['floor']}'s {$what} is hidden from assistive technology (aria-hidden)";
                }
            }

            if ($here) {
                if ($cue === null || ($cue['text'] ?? null) !== ' — the elevator is here') {
                    $defects[] = "the plate {$plate['floor']} holds the cab but line 1 carries no cue";
                } else {
                    $ellipsized($cue);
                }
            } elseif ($cue !== null) {
                $defects[] = "the plate {$plate['floor']} does not hold the cab but line 1 carries a cue";
            }

            $line2 = $label['children'][1] ?? null;

            if (($line2['tag'] ?? null) !== 'div' || ($line2['style']['display'] ?? null) !== 'flex') {
                $defects[] = "the plate {$plate['floor']}'s line 2 is not on line 2 — no flex row found second";
            } else {
                $summaryLink = $line2['children'][0] ?? null;
                $expectedSummary = $plate['summary'] === '' ? 'no seats held' : $plate['summary'];

                if (($summaryLink['tag'] ?? null) !== 'a' || ($summaryLink['text'] ?? null) !== $expectedSummary) {
                    $defects[] = "the plate {$plate['floor']}'s line 2 is not on line 2 (its summary)";
                } else {
                    $ellipsized($summaryLink);

                    if (($summaryLink['style']['backgroundColor'] ?? null) !== 'var(--label-backing)') {
                        $defects[] = "the plate {$plate['floor']}'s summary has no backing";
                    }

                    // The operator's ruling: "clicking the summary also opens the floor" — a second link to
                    // the SAME floor, never a second tab stop, never announced a second time.
                    if (($summaryLink['href'] ?? null) !== ($link['href'] ?? null)) {
                        $defects[] = "the plate {$plate['floor']}'s summary link does not open the same floor as the name link";
                    }

                    if (($summaryLink['tabIndex'] ?? null) !== -1) {
                        $defects[] = "the plate {$plate['floor']}'s summary link is a second tab stop (tabIndex is not -1)";
                    }

                    if (($summaryLink['attrs']['aria-hidden'] ?? null) !== 'true') {
                        $defects[] = "the plate {$plate['floor']}'s summary link is announced a second time (aria-hidden is not true)";
                    }

                    if (($summaryLink['style']['textDecoration'] ?? null) !== 'none') {
                        $defects[] = "the plate {$plate['floor']}'s summary link is underlined (text-decoration is not none)";
                    }

                    if (($summaryLink['style']['outlineOffset'] ?? null) !== '-2px') {
                        $defects[] = "the plate {$plate['floor']}'s summary link's outline-offset is not -2px — its focus ring can fall outside the label's clip";
                    }
                }
            }

            $needsRooms = count($plate['rooms']) > 1 || array_any($plate['rooms'], static fn (array $r): bool => ! $r['reported']);

            if ($needsRooms) {
                $roomsSpan = ($line3['tag'] ?? null) === 'div' ? ($line3['children'][0] ?? null) : null;

                if ($roomsSpan === null || ! str_starts_with((string) ($roomsSpan['text'] ?? ''), ' — rooms: ')) {
                    $defects[] = "the plate {$plate['floor']} names rooms but line 3 does not carry them";
                } else {
                    $ellipsized($roomsSpan);

                    if (($roomsSpan['style']['backgroundColor'] ?? null) !== 'var(--label-backing)') {
                        $defects[] = "the plate {$plate['floor']}'s rooms line has no backing";
                    }
                }
            } elseif ($line3 !== null) {
                $defects[] = "the plate {$plate['floor']} names no rooms worth a line but line 3 exists";
            }
        }

        return $defects;
    }

    /**
     * `showLabels()`'s OWN `left`/`width`, read off the probe (never re-derived from the row's `var()`
     * strings — those are checked structurally, in `constructionDefects()`), against the SHELL's own
     * edge: `PLATE_INSET + left + width / zoom` must never run past `-gap / zoom` — the shell's own
     * scene-x, LESS the STATED gap — wherever `side === 'left'`. ⚠ r4 review round: the earlier check here
     * asserted only `> 0` (no overlap), which holds for ANY positive gap, including one far short of the
     * DOCUMENTED 8px — so a control that shrank the gap without pushing the label INTO the shell passed
     * silently. `$gap` is read from the SHIPPED source, never `$dir` (a mutated build's own gap): the guard
     * must hold the STATED contract even when the code under test computes a different one, or a mutation
     * to `LABEL_GAP_PX` could never disagree with a check that re-derives its own expectation from the
     * same mutated constant. The fallback's own containment is the plate's, unchecked here (it is the
     * accepted case, per the operator's ruling).
     *
     * @return list<string>
     */
    private function labelOutsideBuildingDefects(array $result, ?string $dir = null): array
    {
        $plateInset = $this->sourceConstant(self::SCENE, 'PLATE_INSET', $dir);
        $gap = $this->sourceConstant(self::PAINT, 'LABEL_GAP_PX');
        $defects = [];

        foreach ($this->rows($result, $dir) as [$plate, , , $label]) {
            if ($label['side'] !== 'left') {
                continue;
            }

            $rightScene = $plateInset + $label['left'] + $label['width'] / $label['zoom'];
            $heldGap = -$gap / $label['zoom'];

            if ($rightScene > $heldGap + self::EPSILON) {
                $defects[] = sprintf("the plate %s's label's right edge stands %.4f scene px clear of the shell, short of the stated %d px gap (%.4f scene px)",
                    $plate['floor'], -$rightScene, (int) $gap, $heldGap);
            }
        }

        return $defects;
    }

    /**
     * `showLabels()`'s own `lines × LABEL_LINE_PX` against the storey's own on-screen height
     * (`PLATE_H × zoom`) — a defect wherever the label is taller than its own storey UNLESS `lines` is
     * already at its floor of `1` (`label-paint.js`'s own docblock: the name-only bound, accepted).
     *
     * @return list<string>
     */
    private function overlapDefects(array $result, ?string $dir = null): array
    {
        $plateH = $this->sourceConstant(self::SCENE, 'PLATE_H', $dir);
        $linePx = $this->sourceConstant(self::PAINT, 'LABEL_LINE_PX', $dir);
        $defects = [];

        foreach ($this->rows($result, $dir) as [$plate, , , $label]) {
            if ($label['lines'] <= 1) {
                continue;
            }

            $storeyPx = $plateH * $label['zoom'];
            $labelPx = $label['lines'] * $linePx;

            if ($labelPx > $storeyPx + self::EPSILON) {
                $defects[] = sprintf('the plate %s\'s label (%.2fpx, %d lines) runs taller than its own storey (%.2fpx)',
                    $plate['floor'], $labelPx, $label['lines'], $storeyPx);
            }
        }

        return $defects;
    }

    /** A numeric `export const NAME = …;` read out of a shipped (or mutated) module's source. */
    private function sourceConstant(string $file, string $name, ?string $dir = null): float
    {
        $source = (string) file_get_contents(($dir === null ? $this->moduleDir() : $dir).'/'.$file);

        $this->assertSame(1, preg_match('/export const '.preg_quote($name, '/').' = ([\-0-9.]+);/', $source, $m),
            "{$name} did not parse from {$file}");

        return (float) $m[1];
    }

    /** A single-quoted string `export const NAME = '…';` read out of a shipped module's source. */
    private function sourceStringConstant(string $file, string $name, ?string $dir = null): string
    {
        $source = (string) file_get_contents(($dir === null ? $this->moduleDir() : $dir).'/'.$file);

        $this->assertSame(1, preg_match('/export const '.preg_quote($name, '/')." = '([^']*)';/", $source, $m),
            "{$name} did not parse from {$file}");

        return $m[1];
    }

    /** Which part of the plate a text is — the name, the summary, the rooms, or the cab's word. */
    private function partOf(string $text, array $plate): string
    {
        return match (true) {
            $text === $plate['name'] => 'name',
            str_starts_with($text, ' — rooms: ') => 'rooms',
            $text === ' — the elevator is here' => 'here',
            default => 'summary',
        };
    }

    /**
     * Every drawn frame's plates, built by the shipped `lobby/plate-row.js`: `[plate, row, here, label]`
     * per plate — `label` the SAME `showLabels()` result every plate in that frame shares (one write per
     * render, `label-paint.js`'s own architecture).
     *
     * @return list<array{0: array, 1: array, 2: bool, 3: array}>
     */
    private function rows(array $result, ?string $dir = null): array
    {
        $frames = array_values(array_filter(
            array_column($result['lobby_renders'], 'frame'),
            static fn (array $f): bool => ($f['building']['composed'] ?? false) === true,
        ));

        if ($frames === []) {
            return [];
        }

        $built = $this->probe(['frames' => array_map(static fn (array $f): array => ['building' => $f['building'], 'scene' => $f['scene'], 'camera' => $f['camera']], $frames)],
            $dir, __DIR__.'/plate-row-probe.mjs')['frames'];
        $rows = [];

        foreach ($frames as $i => $f) {
            foreach ($f['building']['plates'] as $j => $plate) {
                $rows[] = [$plate, $built[$i]['rows'][$j], $plate['floor'] === $f['building']['elevator']['at'], $built[$i]['label']];
            }
        }

        return $rows;
    }

    /**
     * Every text in a node, in document order, with what it is drawn under: the nearest `fontSize` on it
     * or above it, how many counter-scales and which other transforms are above it, and whether it is
     * visually hidden.
     *
     * @return list<array{text: string, font: ?string, counter: int, other: list<string>, hidden: bool}>
     */
    private function texts(array $node, ?string $font = null, int $counter = 0, array $other = [], bool $hidden = false): array
    {
        $style = $node['style'] ?? [];
        $font = $style['fontSize'] ?? $font;
        $transform = $style['transform'] ?? '';

        if ($transform === self::COUNTER_SCALE) {
            $counter++;
        } elseif ($transform !== '') {
            $other[] = $transform;
        }

        $found = [];
        $here = ['font' => $font, 'counter' => $counter, 'other' => $other, 'hidden' => $hidden];

        if (isset($node['text']) && $node['text'] !== '') {
            $found[] = ['text' => $node['text']] + $here;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (! isset($child['tag'])) {
                $found[] = ['text' => $child['text']] + $here;

                continue;
            }

            array_push($found, ...$this->texts($child, $font, $counter, $other, $hidden));
        }

        return $found;
    }

    private function find(array $node, callable $match): ?array
    {
        if ($match($node)) {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (isset($child['tag']) && ($found = $this->find($child, $match)) !== null) {
                return $found;
            }
        }

        return null;
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
     * A text's size on the screen: its font — none set in the row is the page's own `1rem` — under the
     * plates' `scale(zoom)` and one `scale(labelScale)` for each counter-scale above it.
     *
     * @return array{0: float, 1: string}
     */
    private function onScreen(array $text, array $label): array
    {
        $this->assertSame([], $text['other'], "a plate's text sits under a transform this check does not read");
        $font = $text['font'] ?? '1rem';
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?(rem|px)$/', $font, "a plate's font {$font} is not a size this check reads");
        preg_match('/^(\d+(?:\.\d+)?)(rem|px)$/', $font, $m);

        return [(float) $m[1] * $label['zoom'] * $label['scale'] ** $text['counter'], $m[2]];
    }

    /**
     * Whether the lobby page, as a signed-in session receives it, sets a text size of its own — a
     * stylesheet, a style block, or a font size in a style attribute, however it is quoted.
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

        preg_match_all('/\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $this->assertNotSame([], $m, 'the page carries no style attribute at all — the attribute clause read nothing');

        foreach ($m as $attribute) {
            $style = $attribute[1] ?? $attribute[2] ?? $attribute[3];

            if (preg_match('/\bfont(-size)?\s*:/i', $style) === 1) {
                $defects[] = "the lobby page sets a font size in a style attribute (`{$style}`)";
            }
        }

        if (! str_contains($html, 'id="lobby-building"')) {
            $defects[] = 'the page read is not the lobby — it draws no building';
        }

        return $defects;
    }

    /** `LABEL_FONT` through the `--label-scale` write, in `lobby/label-paint.js` — one anchor for a plant of both. */
    private function labelBlock(): string
    {
        $source = (string) file_get_contents($this->moduleDir().'/'.self::PAINT);
        $start = strpos($source, "export const LABEL_FONT = '1rem';");
        $end = strpos($source, "style.setProperty('--label-scale', String(1 / camera.zoom));");
        $this->assertNotFalse($start, 'LABEL_FONT is not in label-paint.js');
        $this->assertNotFalse($end, "the --label-scale write is not in label-paint.js");

        return substr($source, $start, $end + strlen("style.setProperty('--label-scale', String(1 / camera.zoom));") - $start);
    }

    private function lobbyPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent() ?: '';
    }
}

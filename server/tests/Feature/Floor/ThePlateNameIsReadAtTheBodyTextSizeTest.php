<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **A plate's label is read at the lobby page's own body text size, whatever the camera does — and it
 * stands BESIDE the building, wherever there is room for it.** `docs/design/FLOOR.md` Appendix B row 16
 * — the operator's rulings on card#7343: F1/r2 (2026-09-27, the name and the status line at the page's
 * body text size) and 2026-09-30 option A (beside the building). card#7343 r4's fix round REPLACED the
 * mechanism this file used to test (r1–r3's `labelPlan()`, computed once per render and staled by every
 * camera move between renders — the root cause both r3 review rounds named independently) with
 * `lobby/label-paint.js`'s `showLabels()`, the ONE function that ever writes a plate's label geometry or
 * paint, called on EVERY camera `lobby/main.js`'s `view()` shows. This file is rewritten to that
 * mechanism rather than deleting its properties (design review P5).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS PROVES IS THE ARITHMETIC OVER THE SHIPPED CONSTRUCTION — not a measurement of pixels on a
 * screen (there is no browser on the build host; `tools/design/lobby-label-contrast.browser.mjs` is
 * where pixels are actually measured, run by hand). The runs are `fixtures/fx-plate-names.json`'s —
 * `building_rides`' entry under layouts of 2, 4, 8 and 10 floors, on the harness's default 1280 × 800
 * surface — replayed through the harness's lobby (`lobby/lobby-screen.js` over `wire/camera.js`), which
 * records `showLabels()`'s OWN written values for the camera of every frame and of every act — the
 * camera's zoom, `showLabels()`'s counter-scale, its side, its resolved `left`/`width` in scene/real px,
 * its line budget and its paint — through `fleet-client-probe.mjs`'s `plateLabel()`, which calls
 * `showLabels()` directly (never a copy) against a stand-in element. Each drawn frame's plates are then
 * BUILT by the shipped `lobby/plate-row.js` under `node` with a stand-in `document`
 * (`plate-row-probe.mjs`), and every painted text in each row is read where it sits: its font — the
 * nearest `fontSize` on it or above it in the row, else the page's own `1rem` — times the zoom, times the
 * counter-scale once for each `scale(var(--label-scale))` above it. `plateRow()` itself carries no
 * geometry of its own: every position, size, line-budget and paint property it sets is a `var(--label-…)`
 * reference, so what this file reads off a built row's `style` is STRUCTURAL (does the row reference the
 * right property?) while what it reads off `showLabels()`'s own return is NUMERIC (does that property
 * resolve correctly for this camera?) — two different questions, both asked. The source wiring that
 * joins them — `view()` calling `showLabels()`, and `paint()`/`renderBuilding()` writing NO label
 * geometry — is `Tests\Feature\Lobby\LobbyPageWiringTest`'s.
 *
 * ⛔ THE BODY TEXT SIZE IS THE PAGE's, AND NO FIGURE IS WRITTEN HERE. The lobby page ships no stylesheet
 * and sets no font size of its own, so its body text is at the root's size — `1rem`. The plate label's
 * font is held to exactly that, and the page is held to setting no other.
 *
 * ⛔ THE LINK's ACCESSIBLE NAME IS THE PLATE'S NAME ALONE (a disclosed change from the r1–r3 mechanism,
 * whose link carried the name AND the summary) — `plate-row.js`'s own docblock derives why: a flex row
 * that lets the cab's cue ride the SAME line as the name needs the name to be exactly one flex item, and
 * the summary is now its own separate line. The summary, the rooms line and the cue are read by a screen
 * reader in ordinary document order right after the link, whether or not the line-budget currently shows
 * them — `overflow: clip` hides a clipped line VISUALLY, never from the accessibility tree.
 *
 * ⛔ NO LABEL COVERS THE BUILDING'S SHELL WHEREVER IT STANDS BESIDE IT, AND NO LABEL OVERLAPS ANOTHER
 * (the operator's own properties, both r3 rounds' MAJOR findings): the shell-edge check compares the
 * built label's own `left`/`width` — read off `showLabels()`, never re-derived from the camera alone —
 * against the SHELL's edge (`PLATE_INSET` from the plate's own, both r3 reviewers' independent MAJOR
 * against the r1–r3 mechanism, which measured from the plate); the overlap check compares
 * `showLabels()`'s own `lines × LABEL_LINE_PX` against the storey's own on-screen height, at every camera
 * a run shows, except the documented one-line floor (`label-paint.js`'s own "name-only bound").
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

    public function test_red_a_label_that_runs_into_the_building(): void
    {
        $dir = $this->mutatedModules([self::PAINT, 'export const LABEL_GAP_PX = 8;', 'export const LABEL_GAP_PX = -400;']);

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

            if ($side === 'left') {
                $width = max(0.0, $shellLeft - $gap);
                $left = -$plateInset - ($width + $gap) / $camera['zoom'];
                $this->assertEqualsWithDelta($width, $got['width'], self::EPSILON, "[{$what}] width");
                $this->assertEqualsWithDelta($left, $got['left'], self::EPSILON, "[{$what}] left");
                $this->assertSame('#ffcf7d', $got['ink'], "[{$what}] ink");
                $this->assertSame('right', $got['align'], "[{$what}] align");
            } else {
                $this->assertEqualsWithDelta(0.0, $got['left'], self::EPSILON, "[{$what}] left");
                $this->assertSame('left', $got['align'], "[{$what}] align");
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
            'the link carries the summary too' => ["link.textContent = plate.name;", "link.textContent = plate.name + ' — ' + plate.summary;", 'accessible name'],
            'a status line outside the label — the camera would never counter-scale it' => ["    label.append(line1, summary);", "    label.append(line1);\n    row.append(summary);"],
            'a name that does not ellipsize' => ["    whiteSpace: 'nowrap',\n", '', 'does not ellipsize'],
            'the summary before the name' => ['    label.append(line1, summary);', '    label.append(summary, line1);', 'is not on line 1'],
            'a cue inside the link' => ['        line1.append(cue);', '        link.append(cue);', 'carries no cue'],
            'a label that does not clip whole lines' => ["overflow: 'clip',", "overflow: 'hidden',", 'overflow'],
            'a label with no line budget' => ['maxHeight: `calc(var(--label-lines) * ${LABEL_LINE_PX}px)`,'."\n", '', 'no line budget'],
            'a label with no width to fit within' => ["width: 'var(--label-width)',\n", '', 'no width'],
            'a label at a left of its own' => ["left: 'var(--label-left)',", "left: '0px',", 'a left of its own'],
            'a label with no halo' => ["textShadow: 'var(--label-halo)',", '', 'no halo'],
            'a link whose colour is not the variable' => ["color: 'var(--label-ink)',", "color: '#ffcf7d',", 'colour is not the variable'],
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
     * Every built row against the STRUCTURAL contract `plate-row.js`'s own docblock states: the link's
     * text is the plate's name alone, the cue rides line 1 beside it (never inside the link, never absent
     * on the cab's plate, never present elsewhere), the summary is line 2 and the rooms line, where the
     * plate names any, is line 3 — in that DOM order, so a screen reader reaches them in that sequence
     * regardless of the line budget — every visible line clips whole (`overflow: clip`, a line budget, a
     * width to fit within), carries the backing property and the halo property, and the link's own colour
     * is set directly (never merely inherited — the `:visited` reasoning `plate-row.js`'s own docblock
     * gives).
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

            if (($style['overflow'] ?? null) !== 'clip') {
                $defects[] = "the plate {$plate['floor']}'s label's overflow is not clip — {$style['overflow']}";
            }

            if (($style['textShadow'] ?? null) !== 'var(--label-halo)') {
                $defects[] = "the plate {$plate['floor']}'s label has no halo (text-shadow is not var(--label-halo))";
            }

            $line1 = $label['children'][0] ?? null;

            if (($line1['tag'] ?? null) !== 'div' || ($line1['style']['display'] ?? null) !== 'flex') {
                $defects[] = "the plate {$plate['floor']}'s label's line 1 is not on line 1 — no flex row found first";

                continue;
            }

            // Every visible line is deterministic and whole-line clipped (design review P2): never
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

            if (($link['tag'] ?? null) !== 'a') {
                $defects[] = "the plate {$plate['floor']}'s line 1 does not start with the link";
            } else {
                $ellipsized($link);

                if (($link['text'] ?? null) !== $plate['name']) {
                    $defects[] = "the plate {$plate['floor']}'s link text is not its name alone — accessible name changed";
                }

                if (($link['style']['color'] ?? null) !== 'var(--label-ink)') {
                    $defects[] = "the plate {$plate['floor']}'s link colour is not the variable — a :visited rule could beat an inherited one";
                }

                if (($link['style']['backgroundColor'] ?? null) !== 'var(--label-backing)') {
                    $defects[] = "the plate {$plate['floor']}'s link has no backing";
                }
            }

            $cue = $line1['children'][1] ?? null;

            if ($here) {
                if ($cue === null || ($cue['text'] ?? null) !== ' — the elevator is here') {
                    $defects[] = "the plate {$plate['floor']} holds the cab but line 1 carries no cue";
                } else {
                    $ellipsized($cue);
                }
            } elseif ($cue !== null) {
                $defects[] = "the plate {$plate['floor']} does not hold the cab but line 1 carries a cue";
            }

            $summaryDiv = $label['children'][1] ?? null;
            $expectedSummary = $plate['summary'] === '' ? 'no seats held' : $plate['summary'];

            if (($summaryDiv['text'] ?? null) !== $expectedSummary) {
                $defects[] = "the plate {$plate['floor']}'s line 2 is not on line 2 (its summary)";
            } else {
                $ellipsized($summaryDiv);

                if (($summaryDiv['style']['backgroundColor'] ?? null) !== 'var(--label-backing)') {
                    $defects[] = "the plate {$plate['floor']}'s summary has no backing";
                }
            }

            $needsRooms = count($plate['rooms']) > 1 || array_any($plate['rooms'], static fn (array $r): bool => ! $r['reported']);
            $roomsDiv = $label['children'][2] ?? null;

            if ($needsRooms) {
                if ($roomsDiv === null || ! str_starts_with((string) ($roomsDiv['text'] ?? ''), ' — rooms: ')) {
                    $defects[] = "the plate {$plate['floor']} names rooms but line 3 does not carry them";
                } else {
                    $ellipsized($roomsDiv);

                    if (($roomsDiv['style']['backgroundColor'] ?? null) !== 'var(--label-backing)') {
                        $defects[] = "the plate {$plate['floor']}'s rooms line has no backing";
                    }
                }
            } elseif ($roomsDiv !== null) {
                $defects[] = "the plate {$plate['floor']} names no rooms worth a line but line 3 exists";
            }
        }

        return $defects;
    }

    /**
     * `showLabels()`'s OWN `left`/`width`, read off the probe (never re-derived from the row's `var()`
     * strings — those are checked structurally, in `constructionDefects()`), against the SHELL's own
     * edge: `PLATE_INSET + left + width / zoom` must never exceed `0` — the shell's own scene-x — wherever
     * `side === 'left'`. The fallback's own containment is the plate's, unchecked here (it is the
     * accepted case, per the operator's ruling).
     *
     * @return list<string>
     */
    private function labelOutsideBuildingDefects(array $result, ?string $dir = null): array
    {
        $plateInset = $this->sourceConstant(self::SCENE, 'PLATE_INSET', $dir);
        $defects = [];

        foreach ($this->rows($result, $dir) as [$plate, , , $label]) {
            if ($label['side'] !== 'left') {
                continue;
            }

            $rightScene = $plateInset + $label['left'] + $label['width'] / $label['zoom'];

            if ($rightScene > self::EPSILON) {
                $defects[] = "the plate {$plate['floor']}'s label's right edge runs {$rightScene} scene px past the shell";
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

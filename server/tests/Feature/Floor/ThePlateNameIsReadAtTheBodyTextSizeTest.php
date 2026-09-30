<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **A plate's name and its status line are read at the lobby page's own body text size, whatever the
 * camera does.** `docs/design/FLOOR.md` Appendix B row 16 — the operator's rulings on card#7343
 * (2026-09-27): F1, that at whole-building fit a plate's text shrinks with the plate and the lobby exists
 * to pick a floor, so a floor's name is drawn over its plate at a FIXED SCREEN SIZE the camera does not
 * scale — the page's own body text size; and r2, that the plate's status line (the summary, the rooms, *the
 * elevator is here*) is drawn the same way, stacked under the name. Plates keep a storey's proportions;
 * § 12's zoom-to-read rule is the floor's.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS PROVES IS THE TRANSFORM ARITHMETIC OVER THE SHIPPED CONSTRUCTION — not a measurement of
 * pixels on a screen. The runs are `fixtures/fx-plate-names.json`'s — `building_rides`' entry under
 * layouts of 2, 4, 8 and 10 floors, on the harness's default 1280 × 800 surface — replayed through the
 * harness's lobby (`lobby/lobby-screen.js` over `wire/camera.js`), which records the transform the plates'
 * text is shown under for the camera of every frame and of every act: the camera's zoom (the plates'
 * `scale(zoom)`, `lobby/main.js`'s `view()`) and the label's counter-scale (`lobby/building-scene.js`'s
 * `labelScale()`, which `view()` writes as `--label-scale`) — and for a glide, the same under the camera
 * the page shows halfway through it (`camera.js`'s `between()`, which `wire/camera-view.js` steps a glide
 * with). Each drawn frame's plates are then BUILT by the shipped `lobby/plate-row.js` under `node` with a
 * stand-in `document` (`plate-row-probe.mjs`), and every painted text in each row is read where it sits:
 * its font — the nearest `fontSize` on it or above it in the row, else the page's own `1rem` — times the
 * zoom, times the counter-scale once for each `scale(var(--label-scale))` above it. The source wiring
 * that joins the two — `view()` writing `--label-scale` from the camera it shows, and the page standing
 * `plateRow()`'s rows in `#lobby-floors` — is `Tests\Feature\Lobby\LobbyPageWiringTest`'s.
 *
 * ⛔ THE BODY TEXT SIZE IS THE PAGE's, AND NO FIGURE IS WRITTEN HERE. The lobby page ships no stylesheet
 * and sets no font size of its own, so its body text is at the root's size — the viewer's own — which is
 * `1rem`. The plate label's font is held to exactly that, and the page is held to setting no other: a
 * stylesheet or a font size arriving on the page reds here, because `1rem` would then no longer be its body
 * text size.
 *
 * ⛔ AND THE PLATE READS AS IT DID. The two lines are stacked name first (the name a line of its own), and
 * the link's text — the plate's accessible name — is the name, ` — ` and the summary, as it always was:
 * the separator is kept for assistive technology and visually hidden, never painted between two lines.
 *
 * ⛔ AND THE LABEL WRAPS WITHIN WHAT IS VISIBLE (card#7343 r3, then r4b, the seat's rulings): bounded by
 * `--label-max`, with every painted text free to break — so a status line wider than that takes more lines
 * rather than running past the drawing's clip (`wrapDefects()`). `--label-max` is `building-scene.js`'s
 * `labelMax()`: the surface to the right of the plates' on-screen left edge, never wider than the surface
 * and never narrower than its `LABEL_MIN_PX` unless the surface is — so at whole-building fit on the
 * harness's surface no label runs past the surface's right edge (`visibleDefects()`, over the fit camera
 * the harness recorded), and the clamp is held on cameras chosen for each of its branches
 * (`labelMaxDefects()`).
 *
 * ⛔ AND THE TOP FLOOR's NAME IS NEVER ABOVE THE SURFACE's TOP AT FIT (card#7343 r3b, the seat's ruling): the
 * label stands at its plate's top-left and grows down, so a label taller than its plate runs down over the
 * plate below it and the clip cuts its last lines first, never the top floor's name (`anchorDefects()`,
 * which sweeps the label's height because nothing here measures it).
 *
 * ⚠ WHAT THIS DOES NOT HOLD: nothing here lays out or paints — there is no browser on the build host — so
 * that a browser draws the product above at the size above, and wraps it where the width says, is the CSS
 * model, not a measurement; and where two plates' labels meet (a plate on the screen shorter than its
 * label's lines of body text, however many the wrap makes) is not asserted, because a line's height and
 * where a line breaks are the font's and no browser here measures them.
 */
class ThePlateNameIsReadAtTheBodyTextSizeTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    /** The runs, one per stack height the ruling's measurement named (card#7343 comment 6974). */
    private const RUNS = ['plate_names_2', 'plate_names_4', 'plate_names_8', 'plate_names_10'];

    private const SCENE = '../lobby/building-scene.js';

    private const ROW = '../lobby/plate-row.js';

    private const EPSILON = 1e-9;

    /** The counter-scale a plate label's text is drawn under — `lobby/main.js`'s `--label-scale`. */
    private const COUNTER_SCALE = 'scale(var(--label-scale))';

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_name_and_the_status_line_are_the_body_text_size_at_fit_after_a_wheel_and_mid_glide_at_every_stack_height(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->sizeDefects($this->replay($run)), "[{$run}]");
        }
    }

    /**
     * card#7343 r3b (the seat's ruling): a plate's label stands at its plate's top-left and grows down, so the
     * top floor's name is never above the surface's top at whole-building fit, however tall its label is.
     */
    public function test_green_the_top_plates_name_is_never_above_the_surface_top_at_fit(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->anchorDefects($this->replay($run)), "[{$run}]");
        }
    }

    /**
     * The anchor the r3b ruling refused and replaced — the label at its plate's bottom-left, growing up, as
     * r1–r3 built it (§ 13 decision 38) — and each half of it alone: past the point where labels meet, each
     * puts the top floor's name above the surface's top, where the clip cuts it.
     *
     * @return array<string, array{0: list<array{0: string, 1: string}>}>
     */
    public static function anchorPlants(): array
    {
        $bottom = ["        top: '0',\n", "        bottom: '0',\n"];
        $origin = ["        transformOrigin: '0 0',", "        transformOrigin: '0 100%',"];

        return [
            'the refused anchor: the plate\'s bottom-left, growing up' => [[$bottom, $origin]],
            'a label anchored at the plate\'s bottom' => [[$bottom]],
            'a label counter-scaled about its bottom' => [[$origin]],
        ];
    }

    /** @param  list<array{0: string, 1: string}>  $edits */
    #[DataProvider('anchorPlants')]
    public function test_red_a_top_plates_name_above_the_surface_top(array $edits): void
    {
        // One anchored edit per plant, so the edits are applied to the one span of `plate-row.js` that holds them all.
        $source = (string) file_get_contents($this->moduleDir().'/'.self::ROW);
        $start = strpos($source, $edits[0][0]);
        $last = end($edits)[0];
        $this->assertNotFalse($start, 'the anchor plant\'s first line is not in plate-row.js');
        $this->assertNotFalse(strpos($source, $last, $start), 'the anchor plant\'s last line is not in plate-row.js after its first');
        $span = substr($source, $start, strpos($source, $last, $start) + strlen($last) - $start);
        $planted = $span;

        foreach ($edits as [$from, $to]) {
            $this->assertSame(1, substr_count($planted, $from), "the anchor plant's line {$from} is not in the span once");
            $planted = str_replace($from, $to, $planted);
        }

        $dir = $this->mutatedModules([self::ROW, $span, $planted]);

        $this->assertNotSame([], array_filter($this->anchorDefects($this->replay(self::RUNS[0], $dir), $dir),
            static fn (string $d): bool => str_contains($d, 'above the surface\'s top')), 'CONTROL (the name above the surface top) did not bite');
    }

    /**
     * card#7343 r4b (the seat's ruling): at whole-building fit on the harness's 1280 × 800 surface, no plate's
     * label runs past the surface's right edge — at 2, 4, 8 and 10 floors, each a height-bound fit that insets
     * the building from the surface's left edge.
     */
    public function test_green_a_label_at_fit_ends_inside_the_surface(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame([], $this->visibleDefects($this->replay($run)), "[{$run}]");
        }
    }

    /** `labelMax()`'s clamp, on a camera for each branch: what is visible, the surface, the minimum, and a surface narrower than it. */
    public function test_green_the_label_width_is_clamped_to_the_surface_and_the_minimum(): void
    {
        $this->assertSame([], $this->labelMaxDefects());
    }

    /**
     * Each defect planted in `labelMax()`, and which check it must red: the r3 width (the whole surface) and a
     * left edge read backwards run a label past the surface's right edge at fit; an unclamped width, a width
     * with no minimum and a minimum that outgrows the surface break the clamp.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function labelMaxPlants(): array
    {
        $body = '    return Math.min(width, Math.max(LABEL_MIN_PX, width - left));';

        return [
            'the whole surface\'s width, as r3 had it' => [$body, '    return width;', 'visible'],
            'the plates\' left edge read backwards' => ['    const left = (camera.bounds.x + PLATE_INSET - camera.x) * camera.zoom;', '    const left = (camera.x - camera.bounds.x - PLATE_INSET) * camera.zoom;', 'visible'],
            'a width wider than the surface' => [$body, '    return Math.max(LABEL_MIN_PX, width - left);', 'clamp'],
            'a width with no minimum' => [$body, '    return Math.min(width, width - left);', 'clamp'],
            'a minimum wider than the surface' => [$body, '    return Math.max(LABEL_MIN_PX, Math.min(width, width - left));', 'clamp'],
        ];
    }

    #[DataProvider('labelMaxPlants')]
    public function test_red_each_label_width_defect(string $anchor, string $replacement, string $check): void
    {
        $dir = $this->mutatedModules([self::SCENE, $anchor, $replacement]);

        if ($check === 'visible') {
            foreach (self::RUNS as $run) {
                $this->assertNotSame([], $this->visibleDefects($this->replay($run, $dir)), "[{$run}] CONTROL (a label past the surface's right edge) did not bite");
            }

            return;
        }

        $this->assertNotSame([], $this->labelMaxDefects($dir), 'CONTROL (the label width\'s clamp broken) did not bite');
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

    /** Every status part is drawn somewhere in the runs — else the status clause read a line with nothing on it. */
    public function test_green_the_runs_draw_every_part_of_the_status_line(): void
    {
        foreach (self::RUNS as $run) {
            $parts = [];

            foreach ($this->rows($this->replay($run)) as [$plate, $row, $here]) {
                foreach ($this->texts($row) as $t) {
                    $parts[] = $this->partOf($t['text'], $plate);
                }
            }

            foreach (['name', 'summary', 'rooms', 'here'] as $part) {
                $this->assertContains($part, $parts, "[{$run}] no plate draws its {$part}");
            }
        }
    }

    public function test_green_the_lobby_page_sets_no_text_size_of_its_own(): void
    {
        $this->assertSame([], $this->baseDefects($this->lobbyPage()));
    }

    // ── RED — each defect planted where it would live, and watched failing ──────────────────────

    /**
     * The geometry the F1 ruling replaced (f7cbfe7): the plate's text in its own scene text — 48 scene px —
     * and scaled by the camera with the plate, so its size is the plate's and shrinks as the stack grows.
     */
    public function test_red_the_text_in_the_plates_scene_text_scaled_with_the_plate(): void
    {
        $dir = $this->mutatedModules([self::SCENE, $this->labelBlock(), str_replace(
            ["export const LABEL_FONT = '1rem';", 'return 1 / camera.zoom;'],
            ["export const LABEL_FONT = '48px';", 'return 1;'],
            $this->labelBlock(),
        )]);

        foreach (self::RUNS as $run) {
            $this->assertNotSame([], $this->sizeDefects($this->replay($run, $dir), $dir), "[{$run}] CONTROL (f7cbfe7's geometry) did not bite");
        }
    }

    /** A label the camera scales: the page's size at zoom 1, and the plate's everywhere else. */
    public function test_red_a_label_the_camera_scales(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'return 1 / camera.zoom;', 'return 1;']);

        foreach (self::RUNS as $run) {
            $this->assertNotSame([], $this->sizeDefects($this->replay($run, $dir), $dir), "[{$run}] CONTROL (a label the camera scales) did not bite");
        }
    }

    /** A label drawn at a size of its own rather than the page's — a px figure where the page's base belongs. */
    public function test_red_a_label_at_a_figure_of_its_own(): void
    {
        $dir = $this->mutatedModules([self::SCENE, "export const LABEL_FONT = '1rem';", "export const LABEL_FONT = '16px';"]);

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
            // The r2 ruling's own red: the status line left in the plate, which the camera scales.
            'a status line the camera scales' => ["    label.append(link, ...status);\n    row.append(label);", "    label.append(link);\n    row.append(label, ...status);", 'on the screen, not the body text size'],
            'a status line at a figure of its own' => ['        status.push(cab);', "        cab.style.fontSize = '48px';\n        status.push(cab);", 'a size of its own'],
            'a name on the status line, not above it' => ["    name.style.display = 'block';\n", '', 'is not a line of its own'],
            'the status line first' => ['    link.append(name, separator, summary);', '    link.append(summary, separator, name);', 'is not the name'],
            'a separator painted between the lines' => ["    Object.assign(separator.style, VISUALLY_HIDDEN);\n", '', 'is painted'],
            'a plate whose accessible name lost its separator' => ['    link.append(name, separator, summary);', '    link.append(name, summary);', 'accessible name'],
            // The r3 ruling's reds: a status line wider than the surface must wrap within it, or the drawing's
            // clip hides its end from every pan.
            'a label that does not wrap' => ["        whiteSpace: 'normal',\n", "        whiteSpace: 'nowrap',\n", 'does not wrap within the surface'],
            'a status line that does not wrap' => ['        status.push(rooms);', "        rooms.style.whiteSpace = 'nowrap';\n        status.push(rooms);", 'does not wrap within the surface'],
            'a label with no width to wrap within' => ["        maxWidth: 'var(--label-max)',\n", '', 'can run wider than the surface'],
            'a label wrapped at a width of its own' => ["maxWidth: 'var(--label-max)'", "maxWidth: '1280px'", 'can run wider than the surface'],
            'a word that never breaks' => ["        overflowWrap: 'anywhere',\n", '', 'a word wider than the surface'],
            // Design review r2, row 16 F1: the label carries its own halo, inside the same counter-scaled
            // element, so a label crossing the shaft or the cab at whole-building fit stays legible without
            // hiding what it crosses (r1's opaque plaque, refused).
            'a label with no halo' => ["        textShadow: LABEL_TEXT_SHADOW,\n", '', 'no halo'],
        ];
    }

    #[DataProvider('rowPlants')]
    public function test_red_each_plate_construction_defect(string $anchor, string $replacement, string $reason): void
    {
        $dir = $this->mutatedModules([self::ROW, $anchor, $replacement]);
        $defects = $this->sizeDefects($this->replay(self::RUNS[0], $dir), $dir);

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /** A halo too dark to contrast the plate's own link and body text — WCAG 2.1 SC 1.4.3's 4.5:1 unmet. */
    public function test_red_a_halo_with_low_contrast(): void
    {
        $dir = $this->mutatedModules([self::SCENE, 'export const LABEL_HALO = INK.wall;', 'export const LABEL_HALO = INK.sign;']);
        $defects = $this->sizeDefects($this->replay(self::RUNS[0], $dir), $dir);

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, 'WCAG')),
            'CONTROL (a halo with low contrast) did not bite: '.json_encode($defects));
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
     * Every painted text of every drawn plate, under every transform a run shows it at, each a defect unless
     * it is `1rem` on the screen — and the run must have measured something: a fit, a wheel that moved the
     * zoom, and two glides whose midpoints are neither end. Beside the size, each row's order, its lines and
     * its accessible name.
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
            array_push($defects, ...$this->readingDefects($plate, $row), ...$this->wrapDefects($plate, $row), ...$this->haloDefects($plate, $row));

            foreach ($this->texts($row) as $t) {
                if ($t['hidden']) {
                    continue;
                }

                $part = $this->partOf($t['text'], $plate);

                foreach ($samples as $when => $label) {
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
     * One row read as the viewer and assistive technology read it: the name first and a line of its own,
     * every status part after it, the link's text the name, ` — ` and the summary, and the separator never
     * painted.
     *
     * @return list<string>
     */
    private function readingDefects(array $plate, array $row): array
    {
        $defects = [];
        $painted = array_values(array_filter($this->texts($row), static fn (array $t): bool => ! $t['hidden']));

        if (($painted[0]['text'] ?? null) !== $plate['name']) {
            $defects[] = "the plate {$plate['floor']}'s first line is not the name: ".json_encode($painted[0]['text'] ?? null);
        } elseif (($painted[0]['display'] ?? null) !== 'block') {
            $defects[] = "the plate {$plate['floor']}'s name is not a line of its own — the status line runs on beside it";
        }

        foreach (array_slice($painted, 1) as $t) {
            if ($this->partOf($t['text'], $plate) === 'name') {
                $defects[] = "the plate {$plate['floor']} paints its name twice";
            }

            if (trim($t['text']) === '—') {
                $defects[] = "the plate {$plate['floor']}'s separator is painted between its two lines";
            }
        }

        $link = $this->find($row, static fn (array $n): bool => ($n['tag'] ?? null) === 'a');
        $summary = $plate['summary'] === '' ? 'no seats held' : $plate['summary'];
        $expected = "{$plate['name']} — {$summary}";

        if ($link === null || ($link['href'] ?? null) !== $plate['href']) {
            $defects[] = "the plate {$plate['floor']} is not the link to its floor";
        } elseif (implode('', array_column($this->texts($link), 'text')) !== $expected) {
            $defects[] = sprintf("the plate %s's accessible name is %s, not %s", $plate['floor'],
                json_encode(implode('', array_column($this->texts($link), 'text'))), json_encode($expected));
        }

        return $defects;
    }

    /**
     * card#7343 r3 (the seat's ruling): a plate's label wraps within the surface. The label is no wider than
     * `--label-max` — `building-scene.js`'s `labelMax()`, never wider than the surface (r4b), which
     * `lobby/main.js`'s `view()` writes (held by `Tests\Feature\Lobby\LobbyPageWiringTest`) — and its px are
     * screen px under its one counter-scale (the size check), so a pan can bring the whole of it into view;
     * every painted text in it may wrap, and a word longer than that width breaks rather than running past it.
     * Nothing here lays text out, so a line wider than the surface is not measured: what is held is that no
     * painted text can be one.
     *
     * @return list<string>
     */
    private function wrapDefects(array $plate, array $row): array
    {
        $label = $this->find($row, static fn (array $n): bool => ($n['style']['transform'] ?? null) === self::COUNTER_SCALE);

        if ($label === null) {
            return ["the plate {$plate['floor']} has no counter-scaled label — the wrap clause read nothing"];
        }

        $defects = [];

        if (($label['style']['maxWidth'] ?? null) !== 'var(--label-max)') {
            $defects[] = "the plate {$plate['floor']}'s label is not bounded by `--label-max`, so it can run wider than the surface: "
                .json_encode($label['style']['maxWidth'] ?? null);
        }

        if (! in_array($label['style']['overflowWrap'] ?? null, ['anywhere', 'break-word'], true)) {
            $defects[] = "the plate {$plate['floor']}'s label keeps a word wider than the surface whole";
        }

        foreach ($this->texts($label) as $t) {
            if (! $t['hidden'] && ! $t['wraps']) {
                $defects[] = "the plate {$plate['floor']}'s {$this->partOf($t['text'], $plate)} does not wrap within the surface";
            }
        }

        return $defects;
    }

    /**
     * card#7343 row 16 design review r2 F1: the plate's label carries its own halo — a `text-shadow`, set
     * on the same counter-scaled element as the text, so it scales with the text and never with the scene,
     * and NEVER a background: a box was tried first (r1) and blanked the drawing under it. Its colour's
     * contrast against both the link's default colour and the plate's own body text clears WCAG 2.1
     * SC 1.4.3's 4.5:1, computed rather than eyeballed — read off the halo's own `#rrggbb`, since a
     * `text-shadow` carries no separate background to read instead.
     *
     * @return list<string>
     */
    private function haloDefects(array $plate, array $row): array
    {
        $label = $this->find($row, static fn (array $n): bool => ($n['style']['transform'] ?? null) === self::COUNTER_SCALE);

        if ($label === null) {
            return ["the plate {$plate['floor']} has no counter-scaled label — the halo clause read nothing"];
        }

        $shadow = $label['style']['textShadow'] ?? '';

        if ($shadow === '' || preg_match('/#[0-9a-fA-F]{6}/', $shadow, $m) !== 1) {
            return ["the plate {$plate['floor']}'s label carries no halo (`textShadow` is ".json_encode($shadow).')'];
        }

        $halo = $m[0];
        $defects = [];

        // No background left to hide the drawing under — the property `plaqueDefects()` used to check
        // for a background/padding/radius has no halo-era equivalent; there is nothing else to assert
        // structurally once the colour itself is present and legible.
        if (($label['style']['backgroundColor'] ?? '') !== '') {
            $defects[] = "the plate {$plate['floor']}'s label still carries a background — the halo is meant to replace it, not sit beside it";
        }

        foreach (['#0000EE' => 'the link', '#000000' => 'the body text'] as $fg => $what) {
            $ratio = $this->contrast($halo, $fg);

            if ($ratio < 4.5) {
                $defects[] = sprintf("the plate %s's halo (%s) contrasts %s (%s) at %.2f:1, under WCAG 2.1 SC 1.4.3's 4.5:1",
                    $plate['floor'], $halo, $what, $fg, $ratio);
            }
        }

        return $defects;
    }

    /** WCAG 2.1's relative luminance (§ 1.4.3, Appendix G) of a `#rrggbb` colour. */
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $lin = static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $lin((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $lin((int) hexdec(substr($hex, 4, 2)));
    }

    /** WCAG 2.1's contrast ratio (§ 1.4.3) between two `#rrggbb` colours, ≥ 1. */
    private function contrast(string $a, string $b): float
    {
        [$lighter, $darker] = $this->luminance($a) > $this->luminance($b)
            ? [$this->luminance($a), $this->luminance($b)]
            : [$this->luminance($b), $this->luminance($a)];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * card#7343 r4b: at whole-building fit, every plate's label — no wider than `--label-max`, in screen px
     * under its counter-scale — ends at or before the surface's right edge: the plate's on-screen left edge
     * (its scene `x` under the fit camera) plus the width the harness recorded for that camera. The run must
     * inset the building (a height-bound fit), or the clause measured the case r3's surface width already met.
     *
     * @return list<string>
     */
    private function visibleDefects(array $result): array
    {
        $fit = null;

        foreach ($result['lobby_renders'] as $r) {
            if (($r['frame']['building']['composed'] ?? false) === true) {
                $fit = $r;

                break;
            }
        }

        if ($fit === null) {
            return ['the run never drew the building at fit'];
        }

        $camera = $fit['frame']['camera'];
        $this->assertTrue($camera['fitted'], 'the first frame that drew the building is not at fit');
        $width = $camera['surface']['width'];
        $defects = [];
        $inset = false;

        foreach ($fit['frame']['scene']['plates'] as $plate) {
            $left = ($plate['rect']['x'] - $camera['x']) * $camera['zoom'];
            $inset = $inset || $left > 1e-6;
            $right = $left + $fit['label']['max'];

            if ($right > $width + 1e-6) {
                $defects[] = sprintf("at whole-building fit the plate %s's label may run to %.1f px, past the surface's right edge at %d px (its left edge at %.1f px, --label-max %.1f px)",
                    $plate['floor'], $right, $width, $left, $fit['label']['max']);
            }
        }

        if (! $inset) {
            $defects[] = 'the fit does not inset the building from the surface\'s left edge — the clause measured nothing r3 did not';
        }

        return $defects;
    }

    /**
     * `labelMax()` on a camera for each branch of its clamp, each held to the rule the seat ruled: the visible
     * width to the right of the plates' on-screen left edge, at most the surface's width, at least
     * `LABEL_MIN_PX` — read from the module — unless the surface is narrower still; and the surface's width for
     * a camera that frames nothing.
     *
     * @return list<string>
     */
    private function labelMaxDefects(?string $dir = null): array
    {
        $source = (string) file_get_contents(($dir ?? $this->moduleDir()).'/'.self::SCENE);
        $this->assertSame(1, preg_match('/export const LABEL_MIN_PX = (\d+);/', $source, $m), 'LABEL_MIN_PX did not parse');
        $min = (int) $m[1];
        // Design review r2 F2: `labelMax()` reads a plate's own left edge, `bounds.x + PLATE_INSET`, not
        // the extent's `bounds.x` alone — read from the source rather than restated, as `$min` is above.
        $this->assertSame(1, preg_match('/export const PLATE_INSET = (\d+);/', $source, $mi), 'PLATE_INSET did not parse');
        $inset = (int) $mi[1];
        $camera = static fn (int $width, ?int $boundsX, float $zoom, float $x): array => [
            'surface' => ['width' => $width, 'height' => 800],
            'bounds' => $boundsX === null ? null : ['x' => $boundsX, 'y' => 0, 'w' => 1600, 'h' => 2000],
            'zoom' => $zoom, 'x' => $x, 'y' => 0,
        ];
        // [what, the camera, the width the rule gives it]
        $cases = [
            // left = (bounds.x + PLATE_INSET - x) * zoom = (0 + $inset - -800) * 0.4.
            ['a height-bound fit: the building inset by 320 px', $camera(1280, 0, 0.4, -800), 1280 - (0 + $inset + 800) * 0.4],
            ['the plates\' left edge off the surface\'s left', $camera(1280, 0, 1, 400), 1280],
            ['the plates\' left edge near the surface\'s right', $camera(1280, 0, 1, -(1280 - $min / 2)), $min],
            ['a surface narrower than the minimum', $camera((int) ($min / 2), 0, 1, 0), (int) ($min / 2)],
            ['a camera that frames nothing', $camera(1280, null, 1, 0), 1280],
        ];
        $got = $this->probe(['label_max' => array_column($cases, 1)], $dir, __DIR__.'/plate-row-probe.mjs')['label_max'];
        $defects = [];

        foreach ($cases as $i => [$what, , $expected]) {
            if (abs(($got[$i] ?? -1) - $expected) > 1e-6) {
                $defects[] = sprintf('for %s, --label-max is %s, not %d', $what, json_encode($got[$i] ?? null), $expected);
            }
        }

        return $defects;
    }

    /**
     * card#7343 r3b (the seat's ruling): at whole-building fit the top plate's name — its label's first line,
     * which `readingDefects()` holds — is never above the surface's top, whatever the label's height. Nothing
     * here lays text out, so the label's height is not measured: it is swept, at the plate's own height on the
     * screen (the point where labels meet) and at two and eight times it (past that point), and the name's
     * top is placed from the shipped construction's own styles — the row's top, the label's `top` or `bottom`,
     * and its counter-scale about its `transformOrigin` — under the fit camera the harness recorded.
     *
     * @return list<string>
     */
    private function anchorDefects(array $result, ?string $dir = null): array
    {
        $fit = null;

        foreach ($result['lobby_renders'] as $r) {
            if (($r['frame']['building']['composed'] ?? false) === true) {
                $fit = $r['frame'];

                break;
            }
        }

        if ($fit === null) {
            return ['the run never drew the building at fit'];
        }

        $this->assertTrue($fit['camera']['fitted'], 'the first frame that drew the building is not at fit');
        $built = $this->probe(['frames' => [['building' => $fit['building'], 'scene' => $fit['scene']]]], $dir, __DIR__.'/plate-row-probe.mjs')['frames'][0]['rows'];
        $camera = $fit['camera'];
        $zoom = $camera['zoom'];
        $scale = 1 / $zoom;
        $tops = array_map(fn (array $row): float => $this->px($row['style']['top'] ?? null, 'the row\'s top'), $built);
        $i = array_keys($tops, min($tops), true)[0];
        $row = $built[$i];
        $floor = $fit['building']['plates'][$i]['floor'];
        $label = $this->find($row, static fn (array $n): bool => ($n['style']['transform'] ?? null) === self::COUNTER_SCALE);

        if ($label === null) {
            return ["the top plate {$floor} has no counter-scaled label — the anchor clause read nothing"];
        }

        $rowTop = $tops[$i];
        $rowHeight = $this->px($row['style']['height'] ?? null, 'the row\'s height');
        $defects = [];

        // The label's px are screen px under its counter-scale, so its height is the same figure in its own box.
        foreach ([1, 2, 8] as $times) {
            $height = $rowHeight * $zoom * $times;
            $style = $label['style'];
            $boxTop = match (true) {
                isset($style['top']) && ! isset($style['bottom']) => $rowTop + $this->px($style['top'], 'the label\'s top'),
                isset($style['bottom']) && ! isset($style['top']) => $rowTop + $rowHeight - $this->px($style['bottom'], 'the label\'s bottom') - $height,
                default => $this->fail('the label is anchored by both its top and its bottom, or by neither: '.json_encode($style)),
            };
            $origin = $this->originY($style['transformOrigin'] ?? '50% 50%', $height);
            $nameTop = ($boxTop + $origin * (1 - $scale) - $camera['y']) * $zoom;

            if ($nameTop < -1e-6) {
                $defects[] = sprintf("at whole-building fit the top plate %s's name stands %.1f px above the surface's top once its label is %.1f px tall — the clip cuts the floor's name",
                    $floor, -$nameTop, $height);
            }
        }

        return $defects;
    }

    /** A length the construction sets — `0` or a px figure — as a number of px. */
    private function px(?string $length, string $what): float
    {
        $this->assertNotNull($length, "{$what} is not set");
        $this->assertMatchesRegularExpression('/^-?\d+(\.\d+)?(px)?$/', $length, "{$what} ({$length}) is not a length this check reads");
        $this->assertTrue(str_ends_with($length, 'px') || (float) $length === 0.0, "{$what} ({$length}) is a unitless figure other than 0");

        return (float) $length;
    }

    /** Where a `transformOrigin` puts its vertical origin in a box `$height` px tall, in px from the box's top. */
    private function originY(string $origin, float $height): float
    {
        $parts = preg_split('/\s+/', trim($origin));
        // One value names the horizontal origin unless it is `top` or `bottom`; the vertical is then `center`.
        $y = count($parts) === 1 ? (in_array($parts[0], ['top', 'bottom'], true) ? $parts[0] : 'center') : $parts[1];

        return match (true) {
            $y === 'top' || $y === '0' => 0.0,
            $y === 'bottom' => $height,
            $y === 'center' => $height / 2,
            preg_match('/^(\d+(?:\.\d+)?)%$/', $y, $m) === 1 => $height * (float) $m[1] / 100,
            preg_match('/^(\d+(?:\.\d+)?)px$/', $y, $m) === 1 => (float) $m[1],
            default => $this->fail("the label's transformOrigin ({$origin}) is not one this check reads"),
        };
    }

    /** Which part of the plate a text is — the name, the summary, the rooms, the cab's word, or the separator. */
    private function partOf(string $text, array $plate): string
    {
        return match (true) {
            $text === $plate['name'] => 'name',
            str_starts_with($text, ' — rooms: ') => 'rooms',
            $text === ' — the elevator is here' => 'here',
            trim($text) === '—' => 'separator',
            default => 'summary',
        };
    }

    /**
     * Every drawn frame's plates, built by the shipped `lobby/plate-row.js`: `[plate, row, here]` per plate.
     *
     * @return list<array{0: array, 1: array, 2: bool}>
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

        $built = $this->probe(['frames' => array_map(static fn (array $f): array => ['building' => $f['building'], 'scene' => $f['scene']], $frames)],
            $dir, __DIR__.'/plate-row-probe.mjs')['frames'];
        $rows = [];

        foreach ($frames as $i => $f) {
            foreach ($f['building']['plates'] as $j => $plate) {
                $rows[] = [$plate, $built[$i]['rows'][$j], $plate['floor'] === $f['building']['elevator']['at']];
            }
        }

        return $rows;
    }

    /**
     * Every text in a node, in document order, with what it is drawn under: the nearest `fontSize` on it
     * or above it, how many counter-scales and which other transforms are above it, whether it is visually
     * hidden, the `display` of the element that holds it, and whether the nearest `whiteSpace` on the path
     * lets its lines break.
     *
     * @return list<array{text: string, font: ?string, counter: int, other: list<string>, hidden: bool, display: ?string, wraps: bool}>
     */
    private function texts(array $node, ?string $font = null, int $counter = 0, array $other = [], bool $hidden = false, bool $wraps = true): array
    {
        $style = $node['style'] ?? [];
        $font = $style['fontSize'] ?? $font;
        // `white-space` inherits: the nearest one set on the path decides whether a line may break.
        $wraps = isset($style['whiteSpace']) ? ! in_array($style['whiteSpace'], ['nowrap', 'pre'], true) : $wraps;
        $transform = $style['transform'] ?? '';

        if ($transform === self::COUNTER_SCALE) {
            $counter++;
        } elseif ($transform !== '') {
            $other[] = $transform;
        }

        $hidden = $hidden || (($style['clipPath'] ?? null) === 'inset(50%)' && ($style['width'] ?? null) === '1px'
            && ($style['height'] ?? null) === '1px' && ($style['overflow'] ?? null) === 'hidden');
        $found = [];
        $here = ['font' => $font, 'counter' => $counter, 'other' => $other, 'hidden' => $hidden, 'display' => $style['display'] ?? null, 'wraps' => $wraps];

        if (isset($node['text']) && $node['text'] !== '') {
            $found[] = ['text' => $node['text']] + $here;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (! isset($child['tag'])) {
                $found[] = ['text' => $child['text']] + $here;

                continue;
            }

            array_push($found, ...$this->texts($child, $font, $counter, $other, $hidden, $wraps));
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
     * stylesheet, a style block, or a font size in a style attribute, however it is quoted. Any of them
     * makes the body text size something other than the root's, which is what `1rem` is.
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

        // A style attribute in double quotes, single quotes, or none (up to the next space or `>`).
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

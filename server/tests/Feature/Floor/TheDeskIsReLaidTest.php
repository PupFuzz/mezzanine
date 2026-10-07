<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-25's RE-LAID HALF — `docs/design/FLOOR.md` § 10.6's *The desk, re-laid*, built at Appendix B row 20
 * (card#11046): the desk's rects, the chair and the side table at every desk with art, the paint order of
 * § 10.6's rule 2, the bare-text half of its rule 3, the screen type role and its ink, and the one anchor —
 * and since card#11468 the facts plate (its paint order and that the facts column meets no art), the anchor's
 * height below every plate, and § 5.1's thought bubble as painted: its trail and its cloud.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RECTS ARE READ OUT OF § 10.6's TABLE, NEVER WRITTEN HERE. Each row of the table states an element's
 * rect at the box; this test parses those rows and holds the scene's own rects to them, so the table and the
 * layout are two homes held equal and neither is a copy this file keeps.
 *
 * ⛔ THE PAINT ORDER IS READ OFF WHAT THE PAINTER PAINTS (`painter-probe.mjs`, which consumes the painted
 * nodes in element order and reds when a fact's node precedes an art node), and the ink off what the
 * painter's stylesheet names — `public/css/mezzanine.css`'s tokens, through `painter.js`'s rules.
 *
 * Every RED is planted in the shipped module the defect would live in, a mutated copy of the tree.
 */
class TheDeskIsReLaidTest extends TestCase
{
    use DrivesTheScene;

    private const SHIPPED = 'scene_default';

    private const CAP = 'interns_cap';

    /** § 10.6's art elements and the character; every other element of a desk is a fact. */
    private const ART = ['chair', 'character', 'desk-sprite', 'monitor-frame', 'desk-props', 'side-table'];

    /**
     * The facts column, which meets no art (§ 10.6's rule 3, card#11468): the facts plate, the facts on it — whose
     * backdrop is the plate — and the one bare text left on the floor, the *+N more* tag.
     */
    private const OFF_ART = ['facts-plate', 'label', 'currency', 'lag', 'gauge-bar', 'gauge-pct', 'badge', 'flag', 'quiet-age', 'stool-more'];

    /** § 10.6's table's element names → the layout's element kinds. */
    private const TABLE_KINDS = [
        'the character' => 'character', 'the chair' => 'chair', 'the desk' => 'desk-sprite',
        "the monitor's frame" => 'monitor-frame', 'the screen' => 'monitor', "the screen's text" => 'monitor-text',
        'the desk props' => 'desk-props', 'the side table' => 'side-table',
    ];

    // ── GREEN ─────────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_table_leg(): void
    {
        foreach ([self::SHIPPED, self::CAP] as $run) {
            $this->assertSame([], $this->tableDefects($this->sceneOf($run)), "[{$run}] the table leg");
        }
    }

    public function test_green_the_paint_order_leg(): void
    {
        $run = $this->painterRun();

        $this->assertGreaterThan(0, $run['painted'], 'the painter probe read no painted node');
        $this->assertSame([], $this->orderDefects($run));
    }

    public function test_green_the_bare_text_leg(): void
    {
        foreach ([self::SHIPPED, self::CAP] as $run) {
            $this->assertSame([], $this->bareDefects($this->sceneOf($run)), "[{$run}] the facts-off-art leg");
        }
    }

    public function test_green_the_plate_and_bubble_legs(): void
    {
        $run = $this->painterRun();

        $this->assertGreaterThan(0, $run['bubbles'], 'the painter probe painted no bubble — the bubble leg read nothing');
        $this->assertSame([], $this->plateDefects($run));
        $this->assertSame([], $this->bubbleDefects($run));
    }

    public function test_green_the_anchor_height_leg(): void
    {
        foreach ([self::SHIPPED, self::CAP] as $run) {
            $this->assertSame([], $this->anchorHeightDefects($this->sceneOf($run)), "[{$run}] the anchor-height leg");
        }
    }

    public function test_green_the_screen_leg(): void
    {
        foreach ([self::SHIPPED, self::CAP] as $run) {
            $this->assertSame([], $this->screenDefects($this->sceneOf($run)), "[{$run}] the screen leg");
        }

        $this->assertSame([], $this->inkDefects($this->jsRoot().'/floor/painter.js'));
        $this->assertSame([], $this->plateInkDefects($this->jsRoot().'/floor/painter.js'));
    }

    // ── RED ───────────────────────────────────────────────────────────────────────────────────────

    public function test_red_art_painted_over_a_fact(): void
    {
        foreach ([
            'the chair emitted after the screen text' => ["        rect('chair', 'character', R.chair, { pose: desk.pose, unconfirmed: desk.unconfirmed, occupied: desk.character, doc: doc('chair') });\n", '',
                "    // Q4 (a): the chip reads the model's glyph", "    if (!ctx.placeholder) { rect('chair', 'character', R.chair, { pose: desk.pose, unconfirmed: desk.unconfirmed, occupied: desk.character }); }\n    // Q4 (a): the chip reads the model's glyph"],
            "today's order, the side table after the flag" => ["        rect('side-table', 'side_table', R['side-table'], {", "        false && rect('side-table', 'side_table', R['side-table'], {",
                '    // § 8: one sprite per intern, in front of the side table', "    if (!ctx.placeholder) { rect('side-table', 'side_table', R['side-table'], { seats: 4, seat_dx: [], seat: { dy: 36, w: 20, h: 6 } }); }\n    // § 8: one sprite per intern, in front of the side table"],
        ] as $what => [$from1, $to1, $from2, $to2]) {
            $dir = $this->mutatedModules(['../floor/desk-layout.js', $from1, $to1]);
            $this->mutateAgain($dir, '../floor/desk-layout.js', $from2, $to2);

            $this->assertNotSame([], $this->orderDefects($this->painterRun(dirname($dir))), "RED ({$what}) did not fail the paint-order leg");
        }
    }

    public function test_red_a_rect_that_is_not_the_tables(): void
    {
        foreach ([
            'the frame 4 px further left' => ['const frame = { x: desk.x + desk.w - 96, y: slab - 46, w: 96, h: 46 };', 'const frame = { x: desk.x + desk.w - 100, y: slab - 46, w: 96, h: 46 };', 'monitor-frame'],
            'the creature centred again' => ['const sitter = desk.x + desk.w / 3 - cw / 2;', 'const sitter = mid - cw / 2;', 'character'],
        ] as $what => [$from, $to, $kind]) {
            $defects = $this->tableDefects($this->sceneOf(self::SHIPPED, $this->mutatedModules(['../floor/desk-layout.js', $from, $to])));

            $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_contains($d, "`{$kind}`")), "RED ({$what}) did not fail the table leg on `{$kind}`");
        }
    }

    public function test_red_a_facts_row_over_the_table(): void
    {
        // The stack laid from 12 px BELOW the table's top rather than above it (card#11468).
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const bottom = table.y - 2 - FACTS_PLATE_PAD_Y;', 'const bottom = table.y + 12 - FACTS_PLATE_PAD_Y;']);
        $defects = $this->bareDefects($this->sceneOf(self::SHIPPED, $dir));

        foreach (['facts-plate', 'quiet-age'] as $kind) {
            $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_contains($d, "`{$kind}` meets the art `side-table`")),
                "RED (the facts stack over the side table) did not fail the facts-off-art leg on `{$kind}`");
        }
    }

    public function test_red_the_plate_painted_after_a_fact(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js', '        elements.splice(plateAt, 0, {', '        elements.push({']);

        $this->assertNotSame([], $this->plateDefects($this->painterRun(dirname($dir))),
            'RED (the facts plate emitted after the facts) did not fail the plate-order leg');
    }

    public function test_red_the_trail_missing_or_not_growing(): void
    {
        foreach ([
            'the trail not painted' => ['../floor/painter.js', "                node('circle', { cx: c.x, cy: c.y, r: c.r, class: 'bubble-trail' }, g);\n", '', 'paints 0 circles'],
            'two circles only' => ['../floor/desk-layout.js', 'export const TRAIL_RADII = Object.freeze([2, 3, 4]);', 'export const TRAIL_RADII = Object.freeze([2, 3]);', 'paints 2 circles'],
            'largest at the creature' => ['../floor/desk-layout.js', 'export const TRAIL_RADII = Object.freeze([2, 3, 4]);', 'export const TRAIL_RADII = Object.freeze([4, 3, 2]);', 'does not grow'],
            'one size' => ['../floor/desk-layout.js', 'export const TRAIL_RADII = Object.freeze([2, 3, 4]);', 'export const TRAIL_RADII = Object.freeze([3, 3, 3]);', 'does not grow'],
        ] as $what => [$file, $from, $to, $says]) {
            $dir = $this->mutatedModules([$file, $from, $to]);

            $this->assertNotSame([], array_filter($this->bubbleDefects($this->painterRun(dirname($dir))), fn (string $d): bool => str_contains($d, $says)),
                "RED ({$what}) did not fail the bubble leg with «{$says}»");
        }
    }

    public function test_red_a_trail_circle_on_the_creature(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js', '    let bottom = head.y - TRAIL_GAP;', '    let bottom = head.y + 8;']);

        $this->assertNotSame([], array_filter($this->bubbleDefects($this->painterRun(dirname($dir))), fn (string $d): bool => str_contains($d, "meets the character's rect")),
            'RED (the trail started 8 px inside the character\'s rect) did not fail the bubble leg');
    }

    public function test_red_the_cloud_escaping_its_box(): void
    {
        // The puffs centred on the rect's own edge rather than the inset one: every crown leaves the rect.
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            '    const [x0, y0, x1, y1] = [rect.x + p, rect.y + p, rect.x + rect.w - p, rect.y + rect.h - p];',
            '    const [x0, y0, x1, y1] = [rect.x, rect.y, rect.x + rect.w, rect.y + rect.h];']);
        $defects = $this->painterRun(dirname($dir))['defects'];

        $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_contains($d, "the bubble's cloud leaves the bubble's rect")),
            'RED (the cloud past its rect) did not fail the bubble leg');
        $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_contains($d, "a painted path (bubble)") && str_contains($d, "leaves the desk's box")),
            'RED (the cloud past its rect) did not fail the probe\'s containment on the desk\'s box');
    }

    public function test_red_the_anchor_back_at_the_box_mid_height(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            '    return Object.freeze({ x: desk.anchor_x, y: desk.anchor_y });', '    return Object.freeze({ x: desk.anchor_x, y: desk.box.y + desk.box.h / 2 });']);

        $this->assertNotSame([], $this->anchorHeightDefects($this->sceneOf(self::SHIPPED, $dir)),
            'RED (the thread anchor back at the box\'s mid-height, inside the plate\'s rows) did not fail the anchor-height leg');
    }

    public function test_red_the_tables_seats_and_feet(): void
    {
        foreach ([
            'the empty table with no seats' => ['const seats = Math.max(SIDE_TABLE_SEATS, shown.length);', 'const seats = shown.length;', 'seats'],
            'its foot line 4 px above the interns\'' => ['const table = { x: colB - 8, y: foot - 42, w: W - colB + 8, h: 42 };', 'const table = { x: colB - 8, y: foot - 46, w: W - colB + 8, h: 42 };', 'foot line'],
        ] as $what => [$from, $to, $says]) {
            $defects = $this->tableDefects($this->sceneOf(self::CAP, $this->mutatedModules(['../floor/desk-layout.js', $from, $to])));

            $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_contains($d, $says)), "RED ({$what}) did not fail the table leg");
        }
    }

    public function test_red_the_dimmed_screen_todays_defect(): void
    {
        $dir = $this->mutatedModules(['../floor/painter.js', '.t-monitor-text.lit-dimmed{fill:var(--scene-screen-ink-dim)}', '.t-monitor-text.lit-dimmed{fill:var(--scene-ink)}']);

        $this->assertNotSame([], array_filter($this->inkDefects(dirname($dir).'/floor/painter.js'), fn (string $d): bool => str_contains($d, 'dimmed')),
            'RED (the dimmed screen in the scene\'s ink, 2.25:1) did not fail the screen leg');
    }

    public function test_red_the_plate_in_the_facts_ink(): void
    {
        $dir = $this->mutatedModules(['../floor/painter.js', '.facts-plate{fill:var(--scene-plate);', '.facts-plate{fill:var(--scene-ink);']);

        $this->assertNotSame([], $this->plateInkDefects(dirname($dir).'/floor/painter.js'),
            'RED (the facts plate filled in the facts\' ink) did not fail the plate leg\'s contrast');
    }

    public function test_red_the_screen_text_in_the_facts_role(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js', "{ role: 'screen', lit: desk.monitor.lit }", '{ lit: desk.monitor.lit }']);

        $this->assertNotSame([], array_filter($this->screenDefects($this->sceneOf(self::SHIPPED, $dir)), fn (string $d): bool => str_contains($d, 'role')),
            'RED (the screen text drawn in the fact role) did not fail the screen leg');
    }

    public function test_red_the_anchor_left_behind(): void
    {
        // The art column's centre — where the tail stood before row 20 — restored for the bubble's trail.
        $dir = $this->mutatedModules(['../floor/desk-layout.js', '    const x = head.x + head.w / 2;', '    const x = ART_W / 2;']);

        $this->assertNotSame([], array_filter($this->screenDefects($this->sceneOf(self::SHIPPED, $dir)), fn (string $d): bool => str_contains($d, 'anchor')),
            'RED (the bubble\'s trail back on the art column\'s centre) did not fail the anchor half of the screen leg');
    }

    // ── the legs ──────────────────────────────────────────────────────────────────────────────────

    /**
     * § 10.6's table, parsed: element name → [x, y, w|null, h|null].
     *
     * @return array<string, array{0: int, 1: int, 2: ?int, 3: ?int}>
     */
    private function table(): array
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../../docs/design/FLOOR.md');
        $start = strpos($doc, '#### The desk, re-laid');
        $this->assertNotFalse($start, '§ 10.6 has no *The desk, re-laid* subsection');
        $section = substr($doc, $start, (int) strpos($doc, '**How a desk', $start) - $start);
        $rows = [];

        preg_match_all('/^\| (the [^|]+?) \| (\d+), (\d+), (\d+)(?: × (\d+)| wide)/mu', $section, $m, PREG_SET_ORDER);

        foreach ($m as $row) {
            $rows[$row[1]] = [(int) $row[2], (int) $row[3], (int) $row[4], isset($row[5]) && $row[5] !== '' ? (int) $row[5] : null];
        }

        $this->assertSame(array_keys(self::TABLE_KINDS), array_keys($rows), "§ 10.6's table names other elements than the ones this leg reads");

        return $rows;
    }

    /** @return list<string> */
    private function tableDefects(array $scene): array
    {
        $defects = [];
        $table = $this->table();
        $seen = [];

        foreach ($scene['desks'] as $desk) {
            if ($desk['placeholder']) {
                continue;
            }

            $b = $desk['box'];

            foreach (['chair', 'side-table', 'desk-sprite', 'monitor-frame', 'desk-props', 'monitor'] as $kind) {
                if (count($this->elementsOf($desk, $kind)) !== 1) {
                    $defects[] = "{$desk['key']} draws ".count($this->elementsOf($desk, $kind))." `{$kind}`, not one";
                }
            }

            foreach (self::TABLE_KINDS as $name => $kind) {
                foreach ($this->elementsOf($desk, $kind) as $e) {
                    $seen[$kind] = true;
                    [$x, $y, $w, $h] = $table[$name];
                    $got = [$e['x'] - $b['x'], $e['y'] - $b['y'], $kind === 'monitor-text' ? null : $e['w'], $kind === 'monitor-text' ? null : $e['h']];

                    foreach (['x' => [$got[0], $x], 'y' => [$got[1], $y], 'w' => [$got[2], $w], 'h' => [$got[3], $h]] as $k => [$have, $want]) {
                        if ($want !== null && $have !== null && abs($have - $want) > 1e-6) {
                            $defects[] = "{$desk['key']}'s `{$kind}` has {$k} {$have}, and § 10.6's table states {$want}";
                        }
                    }
                }
            }

            // The side table: § 12's least seat count, one more per intern drawn past it, and its foot line —
            // its bottom edge — the interns' own.
            $table0 = $this->elementsOf($desk, 'side-table')[0] ?? null;
            $stools = $this->elementsOf($desk, 'stool');

            if ($table0 !== null) {
                if ($table0['seats'] !== max(4, count($stools))) {
                    $defects[] = "{$desk['key']}'s side table has {$table0['seats']} seats, not max(4, ".count($stools).')';
                }

                foreach ($stools as $s) {
                    if (abs(($s['y'] + $s['h']) - ($table0['y'] + $table0['h'])) > 1e-6) {
                        $defects[] = "{$desk['key']}'s intern {$s['index']} does not end on the side table's foot line";
                    }
                }
            }
        }

        foreach (['character', 'chair', 'side-table', 'monitor-frame'] as $kind) {
            if (! isset($seen[$kind])) {
                $defects[] = "no desk drew a `{$kind}` — the table leg read nothing for it";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function orderDefects(array $run): array
    {
        return array_values(array_filter($run['defects'], fn (string $d): bool => str_contains($d, 'paint order')));
    }

    /** @return list<string> */
    private function plateDefects(array $run): array
    {
        return array_values(array_filter($run['defects'], fn (string $d): bool => str_contains($d, 'plate order') || str_contains($d, '(facts-plate)')));
    }

    /** @return list<string> */
    private function bubbleDefects(array $run): array
    {
        return array_values(array_filter($run['defects'], fn (string $d): bool => str_contains($d, 'bubble')));
    }

    /**
     * The thread's anchor below every facts plate of its desk (card#11468): a line meets a desk at the desk's
     * own mid-height, and no plate reaches it, so a line between two desks of one row runs under no plate.
     *
     * @return list<string>
     */
    private function anchorHeightDefects(array $scene): array
    {
        $defects = [];
        $read = 0;

        foreach ($scene['desks'] as $desk) {
            $anchor = $scene['anchors'][$desk['key']] ?? null;

            foreach ($this->elementsOf($desk, 'facts-plate') as $plate) {
                $read++;

                if ($anchor === null || $anchor['y'] <= $plate['y'] + $plate['h']) {
                    $defects[] = "{$desk['key']}'s thread anchor at y ".($anchor['y'] ?? 'none').' is not below its facts plate, which ends at '.($plate['y'] + $plate['h']);
                }
            }
        }

        if ($read === 0) {
            $defects[] = 'no facts plate was read — the leg read nothing';
        }

        return $defects;
    }

    /** @return list<string> */
    private function bareDefects(array $scene): array
    {
        $defects = [];
        $read = 0;

        foreach ($scene['desks'] as $desk) {
            foreach ($desk['elements'] as $e) {
                if (! in_array($e['kind'], self::OFF_ART, true)) {
                    continue;
                }

                $read++;

                foreach ($desk['elements'] as $art) {
                    if (in_array($art['kind'], self::ART, true) && $this->intersect($e, $art)) {
                        $defects[] = "{$desk['key']}'s facts-column `{$e['kind']}` meets the art `{$art['kind']}`";
                    }
                }
            }
        }

        if ($read === 0) {
            $defects[] = 'no facts-column element was read — the leg read nothing';
        }

        return $defects;
    }

    /** @return list<string> */
    private function screenDefects(array $scene): array
    {
        $defects = [];
        $read = 0;

        foreach ($scene['desks'] as $desk) {
            foreach ($this->elementsOf($desk, 'monitor-text') as $e) {
                $read++;

                if (($e['role'] ?? 'fact') !== 'screen') {
                    $defects[] = "{$desk['key']}'s monitor text is drawn in the «".($e['role'] ?? 'fact').'» role, not the screen role';
                }
            }

            $character = $this->elementsOf($desk, 'character')[0] ?? $this->elementsOf($desk, 'chair')[0] ?? null;

            if ($character !== null) {
                $centre = $character['x'] + $character['w'] / 2;

                if (abs($desk['anchor_x'] - $centre) > 1e-6) {
                    $defects[] = "{$desk['key']}'s anchor is at {$desk['anchor_x']}, not the character's centre line {$centre}";
                }

                foreach ($desk['bubble']['trail'] ?? [] as $i => $c) {
                    if (abs($c['x'] - $centre) > 1e-6) {
                        $defects[] = "{$desk['key']}'s bubble trail circle {$i} is at {$c['x']}, not the anchor {$centre}";
                    }
                }

                $anchor = $scene['anchors'][$desk['key']] ?? null;

                if ($anchor !== null && abs($anchor['x'] - $centre) > 1e-6) {
                    $defects[] = "{$desk['key']}'s thread and walk anchor is at {$anchor['x']}, not the character's centre line {$centre}";
                }
            }
        }

        if ($read === 0) {
            $defects[] = 'no monitor text was read — the leg read nothing';
        }

        return $defects;
    }

    /**
     * The screen's ink on each lit fill, at full and desaturated light (§ 10.6's floor-independent columns): the
     * token the painter's rule names for each, read out of the sheet.
     *
     * @return list<string>
     */
    private function inkDefects(string $painter): array
    {
        $style = (string) file_get_contents($painter);
        $css = (string) file_get_contents(base_path('public/css/mezzanine.css'));
        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m, PREG_SET_ORDER);
        $tok = [];

        foreach ($m as $row) {
            $tok[$row[1]] = $row[2];
        }

        $defects = [];

        foreach (['on' => 'lit-on', 'dimmed' => 'lit-dimmed'] as $lit => $class) {
            $ink = preg_match('/\.t-monitor-text\.'.$class.'\{fill:var\(--([a-z0-9-]+)\)\}/', $style, $a) === 1 ? $a[1] : null;
            $fill = preg_match('/\.monitor\.'.$class.'\{fill:var\(--([a-z0-9-]+)\)\}/', $style, $b) === 1 ? $b[1] : null;

            if ($ink === null || $fill === null || ! isset($tok[$ink], $tok[$fill])) {
                $defects[] = "the painter names no ink or no fill token for the {$lit} screen";

                continue;
            }

            foreach (['full' => 1.0, 'desaturated' => 0.3] as $light => $sat) {
                $c = $this->contrast($this->saturate($this->rgb($tok[$ink]), $sat), $this->saturate($this->rgb($tok[$fill]), $sat));

                if ($c < 4.5) {
                    $defects[] = sprintf('the %s screen holds its text at %.2f:1 at %s light, under 4.5:1', $lit, $c, $light);
                }
            }
        }

        return $defects;
    }

    /**
     * The facts' ink on the facts plate (§ 10.6's rule 3, card#11468): the token the painter's `text` rule names, on
     * the token its `.facts-plate` rule fills with, at full and desaturated light. The plate is opaque — a plate
     * rule with any opacity reds, because its backdrop would then be part floor.
     *
     * @return list<string>
     */
    private function plateInkDefects(string $painter): array
    {
        $style = (string) file_get_contents($painter);
        $css = (string) file_get_contents(base_path('public/css/mezzanine.css'));
        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m, PREG_SET_ORDER);
        $tok = array_column(array_map(fn (array $r): array => [$r[1], $r[2]], $m), 1, 0);
        $ink = preg_match('/(?:^|\n)text\{font:[^;]*;fill:var\(--([a-z0-9-]+)\)\}/', $style, $a) === 1 ? $a[1] : null;
        $rule = preg_match('/\.facts-plate\{([^}]*)\}/', $style, $b) === 1 ? $b[1] : null;
        $fill = $rule !== null && preg_match('/(?:^|;)fill:var\(--([a-z0-9-]+)\)/', $rule, $c) === 1 ? $c[1] : null;

        if ($ink === null || $fill === null || ! isset($tok[$ink], $tok[$fill])) {
            return ['the painter names no facts ink or no facts-plate fill token'];
        }

        $defects = str_contains((string) $rule, 'fill-opacity') || str_contains((string) $rule, ';opacity') ? ['the facts plate is not opaque'] : [];

        foreach (['full' => 1.0, 'desaturated' => 0.3] as $light => $sat) {
            $ratio = $this->contrast($this->saturate($this->rgb($tok[$ink]), $sat), $this->saturate($this->rgb($tok[$fill]), $sat));

            if ($ratio < 4.5) {
                $defects[] = sprintf('the facts on their plate hold %.2f:1 at %s light, under 4.5:1', $ratio, $light);
            }
        }

        return $defects;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
    }

    /** Filter Effects Level 1's saturate() matrix, in sRGB. */
    private function saturate(array $c, float $s): array
    {
        [$r, $g, $b] = $c;
        $m = [[0.213 + 0.787 * $s, 0.715 - 0.715 * $s, 0.072 - 0.072 * $s],
            [0.213 - 0.213 * $s, 0.715 + 0.285 * $s, 0.072 - 0.072 * $s],
            [0.213 - 0.213 * $s, 0.715 - 0.715 * $s, 0.072 + 0.928 * $s]];

        return array_map(fn (array $row): float => max(0.0, min(1.0, $row[0] * $r + $row[1] * $g + $row[2] * $b)), $m);
    }

    private function contrast(array $a, array $b): float
    {
        $l = function (array $c): float {
            $lin = fn (float $v): float => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $lin($c[0]) + 0.7152 * $lin($c[1]) + 0.0722 * $lin($c[2]);
        };
        [$x, $y] = [$l($a), $l($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

    /** A second edit into a tree `mutatedModules()` already copied. */
    private function mutateAgain(string $moduleDir, string $file, string $anchor, string $replacement): void
    {
        $target = $moduleDir.DIRECTORY_SEPARATOR.$file;
        $original = (string) file_get_contents($target);

        $this->assertSame(1, substr_count($original, $anchor), "the control's second anchor is not in {$file} exactly once");
        file_put_contents($target, str_replace($anchor, $replacement, $original));
    }

    /** @return array{desks: int, painted: int, bubbles: int, defects: list<string>} */
    private function painterRun(?string $jsRoot = null): array
    {
        $out = shell_exec('node '.escapeshellarg(__DIR__.'/painter-probe.mjs').($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "the painter probe printed something that is not JSON:\n".substr((string) $out, 0, 500));

        return $decoded;
    }
}

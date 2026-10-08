<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-25's RE-LAID HALF — `docs/design/FLOOR.md` § 10.6's *The desk, re-laid*, built at Appendix B row 20
 * (card#11046): the desk's rects, the chair and the side table at every desk with art, the paint order of
 * § 10.6's rule 2, the bare-text half of its rule 3, the screen type role and its ink, and the one anchor.
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

    /** Rule 3's bare text facts — the ones that stand on the floor rather than on a backdrop of their own. */
    private const BARE = ['label', 'currency', 'lag', 'gauge-pct', 'stool-more', 'quiet-age'];

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
            $this->assertSame([], $this->bareDefects($this->sceneOf($run)), "[{$run}] the bare-text leg");
        }
    }

    public function test_green_the_screen_leg(): void
    {
        foreach ([self::SHIPPED, self::CAP] as $run) {
            $this->assertSame([], $this->screenDefects($this->sceneOf($run)), "[{$run}] the screen leg");
        }

        $this->assertSame([], $this->inkDefects($this->jsRoot().'/floor/painter.js'));
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

    public function test_red_bare_text_over_art(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            "'side-table': { x: colB - 8, y: top + 94, w: widthB + 8, h: 42 },", "'side-table': { x: colB - 8, y: top + 50, w: widthB + 8, h: 42 },"]);

        $this->assertNotSame([], array_filter($this->bareDefects($this->sceneOf(self::SHIPPED, $dir)), fn (string $d): bool => str_contains($d, 'gauge-pct')),
            'RED (the side table lifted into the gauge row) did not fail the bare-text leg on the gauge\'s percentage');
    }

    public function test_red_the_tables_seats_and_feet(): void
    {
        foreach ([
            'the empty table with no seats' => ['const seats = Math.max(SIDE_TABLE_SEATS, shown.length);', 'const seats = shown.length;', 'seats'],
            'its foot line 4 px above the interns\'' => ["'side-table': { x: colB - 8, y: top + 94, w: widthB + 8, h: 42 },", "'side-table': { x: colB - 8, y: top + 90, w: widthB + 8, h: 42 },", 'foot line'],
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

    public function test_red_the_screen_text_in_the_facts_role(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js', "{ role: 'screen', lit: desk.monitor.lit }", '{ lit: desk.monitor.lit }']);

        $this->assertNotSame([], array_filter($this->screenDefects($this->sceneOf(self::SHIPPED, $dir)), fn (string $d): bool => str_contains($d, 'role')),
            'RED (the screen text drawn in the fact role) did not fail the screen leg');
    }

    public function test_red_the_anchor_left_behind(): void
    {
        // One of the sites `grep -n 'ART_W / 2' server/public/js/floor/scene.js` found before row 20, restored.
        $dir = $this->mutatedModules(['../floor/scene.js',
            'tail: Object.freeze({ x: desk.anchor_x, y: desk.box.y + BUBBLE_BAND }),', 'tail: Object.freeze({ x: desk.box.x + 108, y: desk.box.y + BUBBLE_BAND }),']);

        $this->assertNotSame([], array_filter($this->screenDefects($this->sceneOf(self::SHIPPED, $dir)), fn (string $d): bool => str_contains($d, 'anchor')),
            'RED (the bubble\'s tail back on the art column\'s centre) did not fail the anchor half of the screen leg');
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
    private function bareDefects(array $scene): array
    {
        $defects = [];
        $read = 0;

        foreach ($scene['desks'] as $desk) {
            foreach ($desk['elements'] as $e) {
                if (! in_array($e['kind'], self::BARE, true)) {
                    continue;
                }

                $read++;

                foreach ($desk['elements'] as $art) {
                    if (in_array($art['kind'], self::ART, true) && $this->intersect($e, $art)) {
                        $defects[] = "{$desk['key']}'s bare `{$e['kind']}` meets the art `{$art['kind']}`";
                    }
                }
            }
        }

        if ($read === 0) {
            $defects[] = 'no bare text was read — the leg read nothing';
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

                if ($desk['bubble'] !== null && abs($desk['bubble']['tail']['x'] - $centre) > 1e-6) {
                    $defects[] = "{$desk['key']}'s bubble tail is at {$desk['bubble']['tail']['x']}, not the anchor {$centre}";
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

    /** @return array{desks: int, painted: int, defects: list<string>} */
    private function painterRun(?string $jsRoot = null): array
    {
        $out = shell_exec('node '.escapeshellarg(__DIR__.'/painter-probe.mjs').($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "the painter probe printed something that is not JSON:\n".substr((string) $out, 0, 500));

        return $decoded;
    }
}

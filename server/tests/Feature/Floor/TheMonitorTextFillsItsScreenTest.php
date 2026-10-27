<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The monitor's text fills its screen** (card#12446, the operator's "Reduce monitor font size by 1 point and allow
 * text to wrap lines to fill the entire screen"). The screen role is one pixel smaller than it was (7 px, from 8), on
 * an 8 px line, and the screen's text rect is the screen inset `SCREEN_INSET` on every side — so the lines the
 * screen holds are ⌊the rect's height ÷ the line⌋, derived and never written as a figure in code. The text wraps at
 * word boundaries through the scene's one wrap (`desk-layout.js`'s `wrap()`), breaks a word with no space in it
 * (a path, a URL) inside the width, and ends in the mark on its last line when it does not fit.
 *
 * ⛔ THE LEGS READ THE SHIPPED MODULE: `screen-wrap-probe.mjs` imports `floor/desk-layout.js` and the harness's one
 * measurer, and the scene leg reads what the floor screen drew for `fx-scene.json`'s runs. Each RED plants its
 * defect in a mutated copy of the shipped tree and is watched failing on the leg it names.
 */
class TheMonitorTextFillsItsScreenTest extends TestCase
{
    use DrivesTheScene;

    private const SHIPPED = 'scene_default';

    /** `fx-interns`' cap leg: its seats carry descriptors at D2's bound, which no screen holds whole. */
    private const CAP = 'interns_cap';

    private const LAYOUT = '../floor/desk-layout.js';

    /** The mark § 5.1 rule 4 and `fit()` draw. */
    private const MARK = '…';

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_screen_type_is_seven_px_on_an_eight_px_line_as_section_12_states(): void
    {
        $probe = $this->screenProbe([]);
        [$px, $line, $lines] = $this->section12();

        $this->assertSame("{$px}px sans-serif", $probe['font_screen'], "§ 12's *Screen type size* row and FONT_SCREEN disagree");
        $this->assertSame(7, $px, 'the screen role is not one pixel under the 8 px it was before card#12446');
        $this->assertSame($line, $probe['line_screen'], "§ 12's *Screen type size* row and the screen role's line disagree");
        $this->assertSame($lines, $this->screenLines($probe), "§ 12's *Screen type size* row states a line count the screen's rect does not derive");
    }

    public function test_green_the_text_rect_is_the_screen_inset_and_holds_the_lines_its_height_derives(): void
    {
        $probe = $this->screenProbe([]);
        $screen = $probe['rects']['monitor'];
        $rect = $probe['rects']['monitor-text'];

        $this->assertTrue($this->inside($rect, $screen), 'the screen\'s text rect leaves the screen');
        // Filling the screen: the rect's height is the screen's less the inset top and bottom, so a line more of the
        // role would not fit and a line fewer would leave a line's room empty.
        $this->assertSame($screen['h'] - 2 * ($rect['y'] - $screen['y']), $rect['h'], 'the text rect is not inset equally top and bottom');
        $this->assertSame($screen['w'] - 2 * ($rect['x'] - $screen['x']), $rect['w'], 'the text rect is not inset equally left and right');
        $this->assertGreaterThanOrEqual(3, $this->screenLines($probe), 'the screen holds fewer than the three or four lines the operator expected');
        $this->assertLessThan($probe['line_screen'], $rect['h'] - $this->screenLines($probe) * $probe['line_screen'],
            'the text rect leaves a whole line of the screen empty');
    }

    public function test_green_the_wrap_breaks_at_word_boundaries_and_cuts_with_the_mark_on_the_last_line(): void
    {
        $this->assertSame([], $this->wrapDefects($this->screenProbe($this->cases())['wrapped']));
    }

    public function test_green_every_monitor_draws_inside_its_screen_and_a_long_descriptor_fills_every_line(): void
    {
        $probe = $this->screenProbe([]);

        foreach ([self::SHIPPED, self::CAP] as $run) {
            $this->assertSame([], $this->sceneDefects($this->sceneOf($run), $run, $this->screenLines($probe), $probe['line_screen']), "[{$run}]");
        }
    }

    // ── RED ────────────────────────────────────────────────────────────────────────────────────

    public function test_red_the_one_line_monitor(): void
    {
        $dir = $this->mutatedModules([self::LAYOUT,
            "const cut = rows > 1 ? wrap(String(value), r.w, measure, role, rows) : fit(String(value), r.w, measure, role);",
            "const cut = fit(String(value), r.w, measure, role);"]);

        $probe = $this->screenProbe([]);

        $this->assertNotSame([], $this->sceneDefects($this->sceneOf(self::CAP, $dir), self::CAP, $this->screenLines($probe), $probe['line_screen']),
            'RED (the one-line monitor, dev\'s) did not fail the scene leg');
    }

    public function test_red_the_break_inside_a_word(): void
    {
        $dir = $this->mutatedModules([self::LAYOUT, 'const stop = space > start ? space : end;', 'const stop = end;']);

        $this->assertNotSame([], $this->wrapDefects($this->screenProbe($this->cases(), $dir)['wrapped']),
            'RED (a line broken inside a word that had a space to break at) did not fail the wrap leg');
    }

    public function test_red_the_line_past_the_width(): void
    {
        $dir = $this->mutatedModules([self::LAYOUT,
            'const end = start + Math.max(1, longestRun(points, start, width, measure, role));',
            'const end = start + Math.max(1, longestRun(points, start, width, measure, role)) + 2;']);

        $this->assertNotSame([], $this->wrapDefects($this->screenProbe($this->cases(), $dir)['wrapped']),
            'RED (a line drawn past the width) did not fail the wrap leg');
    }

    public function test_red_the_unmarked_last_line(): void
    {
        $dir = $this->mutatedModules([self::LAYOUT,
            'const cut = fit(rest, width, measure, role);',
            'const cut = { text: rest, truncated: false, w: measure(rest, role).w };']);

        $this->assertNotSame([], $this->wrapDefects($this->screenProbe($this->cases(), $dir)['wrapped']),
            'RED (the last line not cut with the mark) did not fail the wrap leg');
    }

    // ── the legs ───────────────────────────────────────────────────────────────────────────────

    /**
     * Each case, at the screen's text width in the screen role, on the lines the screen holds — the harness's glyph is
     * 5 px in that role (`fx-scene.json`'s measurer), so a line of the 82 px rect holds 16 glyphs and the screen three
     * lines. Each states the lines it must draw.
     *
     * @return list<array{text: string, want: list<string>, truncated: bool}>
     */
    private function cases(): array
    {
        return [
            // 1 line: the whole string fits.
            ['text' => 'Bash: composer', 'want' => ['Bash: composer'], 'truncated' => false],
            // 2 lines, broken at the space before the word that does not fit.
            ['text' => 'Bash: composer test --filter', 'want' => ['Bash: composer', 'test --filter'], 'truncated' => false],
            // 3 lines exactly, every break at a word boundary.
            ['text' => 'waiting on a human since 14:19', 'want' => ['waiting on a', 'human since', '14:19'], 'truncated' => false],
            // more than 3: the third line is cut with the mark.
            ['text' => 'waiting on a human since 14:19:40 (seat clock)', 'want' => ['waiting on a', 'human since', '14:19:40 (seat …'], 'truncated' => true],
            // a token with no space in it is broken inside the width.
            ['text' => 'server/app/Feed/SeatDelta/Fold.php', 'want' => ['server/app/Feed/', 'SeatDelta/Fold.p', 'hp'], 'truncated' => false],
            // and past the last line it is cut with the mark too.
            ['text' => 'https://example.test/a/very/long/path/that/no/screen/holds', 'want' => ['https://example.', 'test/a/very/long', '/path/that/no/s…'], 'truncated' => true],
        ];
    }

    /** @param  list<array<string, mixed>>  $wrapped */
    private function wrapDefects(array $wrapped): array
    {
        $defects = [];
        $cases = $this->cases();
        $width = $this->screenProbe([])['rects']['monitor-text']['w'];

        $this->assertCount(count($cases), $wrapped, 'the probe wrapped another number of cases than it was handed');

        foreach ($cases as $i => $case) {
            $got = $wrapped[$i];
            $lines = array_column($got['lines'], 'text');

            if ($lines !== $case['want']) {
                $defects[] = "«{$case['text']}» draws [".implode(' | ', $lines).'], not ['.implode(' | ', $case['want']).']';
            }

            if ($got['truncated'] !== $case['truncated']) {
                $defects[] = "«{$case['text']}» is ".($got['truncated'] ? '' : 'not ').'marked cut';
            }

            if ($got['truncated'] !== str_ends_with($got['text'], self::MARK)) {
                $defects[] = "«{$case['text']}»'s text «{$got['text']}» carries the mark exactly when it is not cut";
            }

            // The drawn text read as one string is the source's own prefix — nothing reworded on the way.
            if (! str_starts_with($case['text'], $got['truncated'] ? mb_substr($got['text'], 0, -1) : $got['text'])) {
                $defects[] = "«{$case['text']}»'s text «{$got['text']}» is not a prefix of it";
            }

            foreach ($got['lines'] as $line) {
                if ($line['w'] > $width) {
                    $defects[] = "«{$case['text']}»'s line «{$line['text']}» is {$line['w']} px, past the {$width} px width";
                }
            }
        }

        return $defects;
    }

    /**
     * Every monitor text a run drew: in the screen role, inside the screen's text rect and the box, on at most the
     * lines the screen holds; a cut one carries the mark on its last line and fills every line the screen holds.
     *
     * @param  array<string, mixed>  $scene
     */
    private function sceneDefects(array $scene, string $run, int $lines, int $line): array
    {
        $defects = [];
        $read = 0;
        $filled = 0;

        foreach ($scene['desks'] as $desk) {
            $screen = $this->elementsOf($desk, 'monitor')[0] ?? null;

            foreach ($this->elementsOf($desk, 'monitor-text') as $e) {
                $read++;
                $filled += $e['truncated'] ? 1 : 0;
                $drawn = $e['lines'] ?? null;

                if ($drawn === null) {
                    $defects[] = "{$desk['key']}'s monitor text is drawn as one line, not wrapped to the screen";

                    continue;
                }

                if (count($drawn) > $lines || $e['h'] !== count($drawn) * $line) {
                    $defects[] = "{$desk['key']}'s monitor text draws ".count($drawn)." lines {$e['h']} px tall on a screen of {$lines}";
                }

                if ($screen === null || ! $this->inside($e, $screen) || ! $this->inside($e, $desk['box'])) {
                    $defects[] = "{$desk['key']}'s monitor text leaves its screen or its box";
                }

                foreach ($drawn as $one) {
                    if ($one['w'] > $e['w'] || ($screen !== null && $e['x'] + $one['w'] > $screen['x'] + $screen['w'])) {
                        $defects[] = "{$desk['key']}'s monitor line «{$one['text']}» runs past the screen";
                    }
                }

                if ($e['truncated'] && (count($drawn) !== $lines || ! str_ends_with((string) end($drawn)['text'], self::MARK))) {
                    $defects[] = "{$desk['key']}'s cut monitor text does not fill the screen's {$lines} lines and end in the mark";
                }
            }
        }

        $this->assertGreaterThan(0, $read, "[{$run}] no desk drew a monitor text — the leg read nothing");

        if ($run === self::CAP) {
            $this->assertGreaterThan(0, $filled, "[{$run}] no monitor text was cut — the descriptor at its bound no longer reaches the leg");
        }

        return $defects;
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────

    /**
     * The probe over `$dir` (the shipped tree when null): the screen's figures, and each case wrapped at the shipped
     * screen's text width on the lines the shipped screen holds — read from the probe itself, never written here.
     *
     * @param  list<array<string, mixed>>  $cases
     */
    private function screenProbe(array $cases, ?string $dir = null): array
    {
        $scene = $this->fixture(self::CAP)['floor']['scene'];
        $input = ['measurer' => $scene['measurer'], 'box' => $this->box(), 'character' => $scene['character'], 'cases' => []];
        $shipped = $this->probe($input, null, __DIR__.'/screen-wrap-probe.mjs');
        $width = $shipped['rects']['monitor-text']['w'];
        $lines = $this->screenLines($shipped);

        return $this->probe([
            ...$input,
            'cases' => array_map(static fn (array $c): array => ['text' => $c['text'], 'width' => $width, 'role' => 'screen', 'lines' => $lines], $cases),
        ], $dir, __DIR__.'/screen-wrap-probe.mjs');
    }

    /** The lines the screen holds, derived: ⌊the text rect's height ÷ the screen role's line⌋. */
    private function screenLines(array $probe): int
    {
        return intdiv($probe['rects']['monitor-text']['h'], $probe['line_screen']);
    }

    /** § 12's *Screen type size* row: `[px, line, lines]`. */
    private function section12(): array
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../../docs/design/FLOOR.md');

        $this->assertSame(1, preg_match('/^\| \*\*Screen type size\*\* \| \*\*(\d+) px\*\* on an \*\*(\d+) px\*\* line, \*\*(\d+)\*\* lines/mu', $doc, $m),
            "§ 12 has no *Screen type size* row in the shape this leg reads");

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /** @param  array<string, mixed>  $a  @param  array<string, mixed>  $b */
    private function inside(array $a, array $b): bool
    {
        return $a['x'] >= $b['x'] - 1e-6 && $a['y'] >= $b['y'] - 1e-6
            && $a['x'] + $a['w'] <= $b['x'] + $b['w'] + 1e-6 && $a['y'] + $a['h'] <= $b['y'] + $b['h'] + 1e-6;
    }
}

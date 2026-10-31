<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The thought bubble wraps to three lines, and its trail grows toward it** (card#12446, the operator's *"allow the
 * text in the thought bubble to wrap to 3 lines"*, ruling (B) of 2026-10-28 — the bubble no wider than the art column
 * — and *"There should be fewer small bubbles from the character's head to the actual thought bubble. Instead, each one
 * should grow progressively in size"*). The title wraps at word boundaries through the scene's one wrap
 * (`desk-layout.js`'s `wrap()`) on at most `BUBBLE_TITLE_LINES` lines, breaks a token with no space in it inside the
 * width, ends in the mark on its last line when it does not fit, and the bubble is as tall as the lines it draws and its
 * source line. The trail is `TRAIL_RADII`'s circles, each larger than the one under it, from where `bubbleSeat()` starts
 * it up to the bubble — at its least reach and spaced out over a longer gap.
 *
 * ⛔ THE LEGS READ THE SHIPPED MODULES: `bubble-wrap-probe.mjs` imports `floor/scene.js`'s `placeBubbles()` and
 * `floor/desk-layout.js`, with the harness's one measurer. Each RED plants its defect in a mutated copy of the shipped
 * tree and is watched failing on the leg it names.
 */
class TheThoughtBubbleWrapsToThreeLinesTest extends TestCase
{
    use DrivesTheScene;

    private const SCENE = '../floor/scene.js';

    private const LAYOUT = '../floor/desk-layout.js';

    private const RUN = 'scene_default';

    /** The mark § 5.1 rule 4 and `fit()` draw. */
    private const MARK = '…';

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_bubble_figures_are_section_12s(): void
    {
        $c = $this->bubbleProbe([])['constants'];
        [$lines, $line, $band] = $this->section12();

        $this->assertSame($lines, $c['title_lines'], "§ 12's *Bubble title lines* row and BUBBLE_TITLE_LINES disagree");
        $this->assertSame(3, $c['title_lines'], 'the title does not wrap to the three lines the operator asked for');
        $this->assertSame($line, $c['line'], "§ 12's *Bubble title lines* row states another line than the fact role's");
        $this->assertSame($band, $c['band'], "§ 12's *Bubble title lines* row states another band than BUBBLE_BAND");
        $this->assertSame($c['title_lines'] + 1, $c['bubble_lines'], 'the tallest bubble is not its title lines and the source line');
        $this->assertSame($c['bubble_lines'] * $c['line'] + 2 * $c['pad'], $c['band'], 'BUBBLE_BAND is not the tallest bubble, padded');
        $this->assertSame($c['art_w'], $c['max_w'], 'the bubble may be wider than the art column, so it can reach the facts plate (ruling B)');
    }

    public function test_green_the_title_wraps_to_one_two_or_three_lines_and_cuts_with_the_mark_after_the_third(): void
    {
        $this->assertSame([], $this->wrapDefects($this->bubbleProbe($this->cases())));
    }

    public function test_green_the_trail_is_its_circles_growing_from_the_head_to_the_bubble(): void
    {
        $this->assertSame([], $this->trailDefects($this->bubbleProbe($this->trailCases())));
    }

    // ── RED ────────────────────────────────────────────────────────────────────────────────────

    public function test_red_the_one_line_bubble(): void
    {
        $dir = $this->mutatedModules([self::SCENE,
            "const title = wrap(b.text, inner, measure, 'fact', BUBBLE_TITLE_LINES);",
            "const title = (({ text: t, truncated, w, h }) => ({ text: t, truncated, w, h, lines: [{ text: t, w }] }))(fit(b.text, inner, measure));"]);

        $this->assertNotSame([], $this->wrapDefects($this->bubbleProbe($this->cases(), $dir)),
            'RED (the title on one line, cut — dev\'s bubble) did not fail the wrap leg');
    }

    public function test_red_the_bubble_as_wide_as_the_box(): void
    {
        $dir = $this->mutatedModules([self::SCENE,
            'const inner = Math.min(W, BUBBLE_MAX_W) - 2 * BUBBLE_PAD;',
            'const inner = W - 2 * BUBBLE_PAD;']);

        $this->assertNotSame([], $this->wrapDefects($this->bubbleProbe($this->cases(), $dir)),
            'RED (a bubble as wide as the box, reaching the facts plate) did not fail the wrap leg');
    }

    public function test_red_the_trail_that_shrinks_toward_the_bubble(): void
    {
        $dir = $this->mutatedModules([self::LAYOUT,
            'return Object.freeze(TRAIL_RADII.map((r) => {',
            'return Object.freeze([...TRAIL_RADII].reverse().map((r) => {']);

        $this->assertNotSame([], $this->trailDefects($this->bubbleProbe($this->trailCases(), $dir)),
            'RED (the trail\'s circles shrinking from the head to the bubble) did not fail the trail leg');
    }

    // ── the legs ───────────────────────────────────────────────────────────────────────────────

    /**
     * Each case, wrapped at the bubble's text width — the art column less the padding, 204 px, which the harness's 6 px
     * fact glyph fills with 34 glyphs a line. Each states the lines it must draw.
     *
     * @return list<array{text: string, source: ?string, want: list<string>, truncated: bool}>
     */
    private function cases(): array
    {
        return [
            // 1 line: the whole title fits.
            ['text' => 'ingest endpoint', 'source' => 'telemetry', 'want' => ['ingest endpoint'], 'truncated' => false],
            // 2 lines: a line of exactly 34 glyphs, then the rest.
            ['text' => 'the thought bubble wraps its title to three lines', 'source' => 'board_card',
                'want' => ['the thought bubble wraps its title', 'to three lines'], 'truncated' => false],
            // 3 lines, every break at a word boundary — and no source line.
            ['text' => 'card#12446: the thought bubble wraps its title on three lines and grows', 'source' => null,
                'want' => ['card#12446: the thought bubble', 'wraps its title on three lines and', 'grows'], 'truncated' => false],
            // more than 3: the third line is cut with the mark.
            ['text' => 'Fix the fleet reporter so that subagent activity is reported per call and the floor shows working or idle interns now',
                'source' => 'todo',
                'want' => ['Fix the fleet reporter so that', 'subagent activity is reported per', 'call and the floor shows working …'], 'truncated' => true],
            // a token with no space in it is broken inside the width.
            ['text' => 'server/app/Feed/SeatDelta/FoldTheTitleOfTheTask.php', 'source' => 'telemetry',
                'want' => ['server/app/Feed/SeatDelta/FoldTheT', 'itleOfTheTask.php'], 'truncated' => false],
        ];
    }

    /** @param  array<string, mixed>  $probe */
    private function wrapDefects(array $probe): array
    {
        $defects = [];
        $c = $probe['constants'];
        $width = $c['max_w'] - 2 * $c['pad'];
        $cases = $this->cases();

        $this->assertCount(count($cases), $probe['bubbles'], 'the probe placed another number of bubbles than it was handed');

        foreach ($cases as $i => $case) {
            $b = $probe['bubbles'][$i]['bubble'];
            $box = $probe['bubbles'][$i]['box'];
            $lines = array_column($b['lines'], 'text');

            if ($lines !== $case['want']) {
                $defects[] = "«{$case['text']}» draws [".implode(' | ', $lines).'], not ['.implode(' | ', $case['want']).']';
            }

            if ($b['truncated'] !== $case['truncated'] || $b['truncated'] !== str_ends_with((string) end($lines), self::MARK)) {
                $defects[] = "«{$case['text']}» is ".($b['truncated'] ? '' : 'not ').'marked cut, its last line «'.end($lines).'»';
            }

            // The lines read as one string are the title's own prefix — nothing reworded on the way.
            if (! str_starts_with($case['text'], $b['truncated'] ? mb_substr($b['text'], 0, -1) : $b['text'])) {
                $defects[] = "«{$case['text']}»'s text «{$b['text']}» is not a prefix of it";
            }

            foreach ($b['lines'] as $line) {
                if ($line['w'] > $width) {
                    $defects[] = "«{$case['text']}»'s line «{$line['text']}» is {$line['w']} px, past the {$width} px width";
                }
            }

            // As tall as the lines it draws and its source line, padded; never wider than the art column.
            $h = count($b['lines']) * $c['line'] + ($case['source'] === null ? 0 : $c['line']) + 2 * $c['pad'];

            if (abs($b['h'] - $h) > 1e-6 || ($case['source'] === null) !== ($b['second'] === null)) {
                $defects[] = "«{$case['text']}»'s bubble is {$b['h']} px tall, not the {$h} px its lines and source line take";
            }

            if ($b['w'] > $c['max_w'] + 1e-6 || $b['x'] < $box['x'] - 1e-6 || $b['x'] + $b['w'] > $box['x'] + $c['art_w'] + 1e-6) {
                $defects[] = "«{$case['text']}»'s bubble [{$b['x']}, +{$b['w']}] leaves the art column, toward the facts plate";
            }
        }

        return $defects;
    }

    /**
     * Where a trail runs: with no tree answer (set as high as any bubble is), at its least reach under a bubble set
     * over the head's top, and over the longer gap under a sleeper's bubble — every one `TRAIL_RADII`'s circles.
     *
     * @return list<array<string, mixed>>
     */
    private function trailCases(): array
    {
        $text = 'ingest endpoint';

        return [
            ['text' => $text, 'source' => 'telemetry', 'head' => null, 'crest' => 0],
            ['text' => $text, 'source' => 'telemetry', 'head' => ['face' => 80, 'mark' => null], 'crest' => 20],
            ['text' => $text, 'source' => 'telemetry', 'head' => ['face' => null, 'mark' => -33.6], 'crest' => 70],
        ];
    }

    /** @param  array<string, mixed>  $probe */
    private function trailDefects(array $probe): array
    {
        $defects = [];
        $radii = $probe['constants']['trail_radii'];
        $spans = [];

        foreach ($probe['bubbles'] as $i => $placed) {
            $b = $placed['bubble'];
            $foot = $placed['seat']['foot'];
            $trail = $b['trail'];
            $spans[] = $foot - ($b['y'] + $b['h']);

            if (count($trail) !== count($radii)) {
                $defects[] = "case {$i}: the trail draws ".count($trail).' circles, not '.count($radii);
            }

            foreach ($trail as $j => $circle) {
                // smallest at the head, each larger than the one under it and above it
                if ($j > 0 && ! ($circle['r'] > $trail[$j - 1]['r'] && $circle['y'] + $circle['r'] < $trail[$j - 1]['y'] - $trail[$j - 1]['r'])) {
                    $defects[] = "case {$i}: circle {$j} (r {$circle['r']}) is not larger than and above circle ".($j - 1)." (r {$trail[$j - 1]['r']})";
                }

                if ($circle['y'] + $circle['r'] > $foot + 1e-6 || $circle['y'] - $circle['r'] < $b['y'] + $b['h'] - 1e-6) {
                    $defects[] = "case {$i}: circle {$j} is outside the gap from the trail's foot to the bubble";
                }
            }
        }

        // the cases do span different gaps, so the count holding is the spacing's doing, not one gap's
        $this->assertGreaterThan(min($spans) + 10, max($spans), 'the trail cases span one gap — the longer trail is not exercised');

        return $defects;
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────

    /**
     * The probe over `$dir` (the shipped tree when null), at the shipped box and `fx-scene.json`'s measurer.
     *
     * @param  list<array<string, mixed>>  $cases
     */
    private function bubbleProbe(array $cases, ?string $dir = null): array
    {
        $scene = $this->fixture(self::RUN)['floor']['scene'];

        return $this->probe([
            'measurer' => $scene['measurer'],
            'box' => $this->box(),
            'character' => $scene['character'],
            'cases' => array_map(static fn (array $c): array => [
                'text' => $c['text'], 'source' => $c['source'], 'head' => $c['head'] ?? null, 'crest' => $c['crest'] ?? 0,
            ], $cases),
        ], $dir, __DIR__.'/bubble-wrap-probe.mjs');
    }

    /** § 12's *Bubble title lines* row: `[lines, line, band]`. */
    private function section12(): array
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../../docs/design/FLOOR.md');

        $this->assertSame(1, preg_match('/^\| \*\*Bubble title lines\*\* \| \*\*(\d+)\*\* lines on the \*\*(\d+) px\*\* fact line, the tallest bubble \*\*(\d+)\*\* px/mu', $doc, $m),
            '§ 12 has no *Bubble title lines* row in the shape this leg reads');

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}

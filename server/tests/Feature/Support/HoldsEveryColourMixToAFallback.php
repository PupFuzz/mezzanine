<?php

namespace Tests\Feature\Support;

/**
 * ⛔ EVERY `color-mix()` HAS A PLAIN FALLBACK A BROWSER WITHOUT IT PAINTS (card#12052's drawing, card#12070's sheet).
 *
 * A declaration holding `var()` is accepted when it is parsed and fails only when its value is computed, so a browser
 * without `color-mix()` keeps a mixed declaration and paints nothing — and a plain value declared before it in the same
 * rule is thrown away. The one mechanism, in `server/public/css/mezzanine.css` and in `painter.js`'s `STYLE` alike: the
 * mixed rule sits in an `@supports (<property>: color-mix(…))` block, and BEFORE that block a plain rule of the same
 * selector sets the same property to the mixed value with each `color-mix()` replaced by one of its own colours.
 *
 * This reads one stylesheet's text, comments dropped, and answers each break of that shape: a `color-mix()` outside
 * such a block; a block with no rule; a mixed declaration whose selector has no plain rule, whose plain rule sets no
 * such property, or sets it to a colour that is not one of the mix's own; and a plain one that comes only after the
 * block (it would override the mix where `color-mix()` is supported). ⚠ What this does not see: a cascade by
 * specificity — a plain rule of ANOTHER selector out-ranking the fallback — and an `@supports` block nested in
 * another block of its kind. Whether the fallback then reads well is each surface's own contrast check.
 */
trait HoldsEveryColourMixToAFallback
{
    /** An `@supports` block whose condition is a `color-mix()`, holding plain rules (one nesting level). */
    private const COLOUR_MIX_BLOCK = '/@supports\s*\(\s*[a-z-]+\s*:\s*color-mix\([^{]*\)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/i';

    /** @return list<string> */
    protected function colourMixDefects(string $css, string $where): array
    {
        $css = $this->withoutCssComments($css);
        $blocks = $this->colourMixBlocks($css);
        $outside = $this->withoutColourMixBlocks($css);
        $defects = [];

        foreach ($this->cssRules($outside) as [$selector, $declarations]) {
            foreach ($declarations as [$property, $value]) {
                if (str_contains($value, 'color-mix(')) {
                    $defects[] = "{$where}: {$selector} mixes its `{$property}` outside an @supports (color-mix) block";
                }
            }
        }

        foreach ($blocks as [$start, $end, $inner]) {
            $rules = $this->cssRules($inner);

            if ($rules === []) {
                $defects[] = "{$where}: an @supports (color-mix) block holds no rule";
            }

            foreach ($rules as [$selector, $declarations]) {
                $before = $this->plainDeclarations(substr($outside, 0, $this->outsideOffset($blocks, $start)), $selector);
                $after = $this->plainDeclarations(substr($outside, $this->outsideOffset($blocks, $start)), $selector);

                foreach ($declarations as [$property, $value]) {
                    if (! str_contains($value, 'color-mix(')) {
                        continue;
                    }

                    if (isset($after[$property])) {
                        $defects[] = "{$where}: {$selector}'s plain `{$property}` comes after its @supports block, overriding the mix where color-mix() is supported";
                    }

                    if ($before === []) {
                        $defects[] = "{$where}: {$selector} mixes its colour with no plain rule outside the block to fall back on";

                        continue;
                    }

                    if (! isset($before[$property])) {
                        $defects[] = "{$where}: {$selector}'s plain rule sets no `{$property}` — the mixed `{$property}` has no fallback";

                        continue;
                    }

                    $choices = array_map(fn (string $v): string => $this->normalisedCssValue($v), $this->colourMixChoices($value));

                    if (array_intersect(array_map(fn (string $v): string => $this->normalisedCssValue($v), $before[$property]), $choices) === []) {
                        $defects[] = "{$where}: {$selector}'s fallback `{$property}: ".end($before[$property])."` is not `{$value}` with each color-mix() replaced by one of its own colours";
                    }
                }
            }
        }

        return $defects;
    }

    /** The stylesheet a browser without `color-mix()` applies: comments dropped and every `@supports (color-mix)` block skipped. */
    protected function withoutColourMixBlocks(string $css): string
    {
        return (string) preg_replace(self::COLOUR_MIX_BLOCK, '', $this->withoutCssComments($css));
    }

    private function withoutCssComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** @return list<array{int, int, string}> each block's start and end offsets and its inner text */
    private function colourMixBlocks(string $css): array
    {
        preg_match_all(self::COLOUR_MIX_BLOCK, $css, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $b): array => [$b[0][1], $b[0][1] + strlen($b[0][0]), $b[1][0]], $m);
    }

    /**
     * Where a block that started at `start` sits in the text with every block removed.
     *
     * @param  list<array{int, int, string}>  $blocks
     */
    private function outsideOffset(array $blocks, int $start): int
    {
        $removed = 0;

        foreach ($blocks as [$s, $e]) {
            if ($s < $start) {
                $removed += $e - $s;
            }
        }

        return $start - $removed;
    }

    /** @return array<string, list<string>> every value each property takes in the plain rules of `selector`, in order */
    private function plainDeclarations(string $css, string $selector): array
    {
        $out = [];

        foreach ($this->cssRules($css) as [$s, $declarations]) {
            if ($s === $selector) {
                foreach ($declarations as [$property, $value]) {
                    $out[$property][] = $value;
                }
            }
        }

        return $out;
    }

    /** @return list<array{string, list<array{string, string}>}> each rule's normalised selector and its declarations, in order */
    private function cssRules(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        $rules = [];

        foreach ($m as [, $selector, $body]) {
            $declarations = [];

            foreach (explode(';', $body) as $declaration) {
                $colon = strpos($declaration, ':');

                if ($colon !== false) {
                    $declarations[] = [strtolower(trim(substr($declaration, 0, $colon))), trim(substr($declaration, $colon + 1))];
                }
            }

            $rules[] = [(string) preg_replace(['/\s+/', '/\s*,\s*/'], [' ', ','], trim($selector)), $declarations];
        }

        return $rules;
    }

    /** @return list<string> `value` with each `color-mix()` replaced by one of its two colours, every combination */
    private function colourMixChoices(string $value): array
    {
        $at = strpos($value, 'color-mix(');

        if ($at === false) {
            return [$value];
        }

        $open = $at + strlen('color-mix(');
        [$depth, $args, $from] = [1, [], $open];

        for ($i = $open; $i < strlen($value) && $depth > 0; $i++) {
            if ($value[$i] === '(') {
                $depth++;
            } elseif ($value[$i] === ')' && --$depth === 0) {
                $args[] = substr($value, $from, $i - $from);
            } elseif ($value[$i] === ',' && $depth === 1) {
                $args[] = substr($value, $from, $i - $from);
                $from = $i + 1;
            }
        }

        if ($depth !== 0 || count($args) !== 3) {
            return [];
        }

        $choices = [];

        foreach ([$args[1], $args[2]] as $colour) {
            $colour = trim((string) preg_replace('/(^|\s)\d+(?:\.\d+)?%(\s|$)/', ' ', $colour));
            array_push($choices, ...$this->colourMixChoices(substr($value, 0, $at).$colour.substr($value, $i)));
        }

        return $choices;
    }

    private function normalisedCssValue(string $value): string
    {
        return (string) preg_replace(['/\s+/', '/\s*,\s*/'], [' ', ','], trim($value));
    }
}

<?php

namespace Tests\Feature\Support;

/**
 * WCAG 2's contrast ratio, in sRGB, for a test that holds a pairing of the sheet's colours to a floor.
 *
 * ⚠ `TheFloorDrawsItsFrameTest::contrast()` and `TheDeskIsReLaidTest::contrast()` are two earlier copies of this
 * formula; they are this trait's to absorb (card#12047 names the consolidation), not a second home for it.
 */
trait MeasuresContrast
{
    /** @return array{float, float, float} a `#rrggbb` colour's channels, each 0 to 1 */
    protected function rgbOf(string $hex): array
    {
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $hex, "{$hex} is not a #rrggbb colour");

        return array_map(fn (string $c): float => hexdec($c) / 255, str_split(substr($hex, 1), 2));
    }

    /**
     * @param  array{float, float, float}  $a
     * @param  array{float, float, float}  $b
     */
    protected function contrastRatio(array $a, array $b): float
    {
        $luminance = static function (array $c): float {
            $lin = static fn (float $v): float => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $lin($c[0]) + 0.7152 * $lin($c[1]) + 0.0722 * $lin($c[2]);
        };
        [$x, $y] = [$luminance($a), $luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}

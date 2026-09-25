<?php

namespace App\Floor;

/**
 * **The furniture box** — `docs/design/FLOOR.md § 10.3`'s `desks` row and Appendix B row 14: the
 * rect everything the scene draws for one desk at rest (except the bubble) lies inside, at the
 * worst case the cap allows. A `desks` object smaller than it cannot hold a desk (§ 9 F21).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE SOURCE, TWO READERS — review finding M-0 on PR #227 (card#7341 comment 6497). The box is
 * declared ONCE, as data, in `resources/floor/furniture-box.js`: the browser's scene takes it as an
 * input (the painter imports that file through the asset route; the harness imports it from disk),
 * and this class reads the SAME FILE for the console — item 28(1)'s refusals and its re-validation
 * listing, Appendix B row 14's slice C. Nothing here holds the numbers, so there is no second copy
 * to drift; what could drift is the two PARSERS, and
 * `Tests\Feature\Floor\TheFurnitureBoxHasOneSourceTest` holds this reading equal to what `node`
 * imports from the same bytes.
 *
 * ⛔ THE PARSE IS STRICT AND A MISS IS A THROW, never a default. The file's one declaration line
 * has a fixed shape (its own header says so); a box this class cannot read is a box the console
 * would otherwise validate maps against by guessing, which is the silent number § 12 exists to
 * prevent.
 *
 * ⭐ WHAT RECORDS THE BOX A MAP WAS VALIDATED AGAINST (defined here, acted on by slice C): the box
 * is identified by its value, `signature()` below — `"<width>x<height>"` — and slice C stores that
 * signature beside the revision it validates. A change of the box (§ 14 item 28(1)(iii)) is this
 * file's signature differing from the one a room's current revision was validated against.
 */
final class FurnitureBox
{
    /** The data module, under the floor tree — `FloorAssets` owns where that tree is. */
    public const FILE = 'furniture-box.js';

    /** The declaration line's one admitted shape. */
    private const DECLARATION = '/^export const FURNITURE_BOX = Object\.freeze\(\{ width: ([1-9]\d*), height: ([1-9]\d*) \}\);$/m';

    private function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * The box, read from the one file that declares it.
     *
     * @throws \RuntimeException naming what could not be read
     */
    public static function current(): self
    {
        $path = FloorAssets::resolve(self::FILE);

        if ($path === null) {
            throw new \RuntimeException('The furniture box is declared in resources/floor/'.self::FILE
                .' and that file is not in this checkout (docs/design/FLOOR.md Appendix B row 14).');
        }

        return self::parse((string) file_get_contents($path));
    }

    /**
     * The box one module's source declares.
     *
     * @throws \RuntimeException when the source declares it zero times, twice, or in another shape
     */
    public static function parse(string $source): self
    {
        $found = preg_match_all(self::DECLARATION, $source, $m);

        if ($found !== 1) {
            throw new \RuntimeException(sprintf(
                'resources/floor/%s declares the furniture box %d times in the one shape this reader '
                .'admits — `export const FURNITURE_BOX = Object.freeze({ width: N, height: N });`, '
                .'integers, nothing computed — and it must declare it exactly once.',
                self::FILE,
                $found === false ? 0 : $found,
            ));
        }

        return new self((int) $m[1][0], (int) $m[2][0]);
    }

    /** The box's identity, as slice C records it beside the revision it validated. */
    public function signature(): string
    {
        return $this->width.'x'.$this->height;
    }
}

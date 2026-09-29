<?php

namespace App\Building;

/**
 * ⭐ THE HALF-OPEN FOOTPRINT TEST — the one PHP answer to *do these two rectangles share a pixel*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A SHARED EDGE IS NOT AN INTERSECTION (`docs/design/FLOOR.md § 4.6`). A footprint is the
 * HALF-OPEN rectangle `[x, x + w) × [y, y + h)`, so two rects may share an edge and may never share
 * a pixel — "because *a floor subdivided into two rooms* is naturally drawn with one wall between
 * them". A closed-interval comparison would refuse exactly the building the operator asked for.
 *
 * ⛔ ONE TEST, TWO CALLERS — and it was hoisted here from `App\Building\FloorPlan` at the second
 * (canon #5), rather than copied: the floor plan asks it of two ROOMS on one floor (§ 4.6,
 * card#9292), and `App\Floor\DeskSlots` asks it of two `desks` objects in one room (§ 14 item
 * 28(1)(i), Appendix B row 14). The browser's scene asks the same question of the same objects
 * through `floor/floor-layout.js`'s `footprintsIntersect()` (step 7's, F18's) — the same
 * inequalities, so the console refuses exactly the pairs the page draws F21's notice for.
 */
final class Footprint
{
    /**
     * @param  array{x: int|float, y: int|float, w: int|float, h: int|float}  $a
     * @param  array{x: int|float, y: int|float, w: int|float, h: int|float}  $b
     */
    public static function intersect(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w']
            && $b['x'] < $a['x'] + $a['w']
            && $a['y'] < $b['y'] + $b['h']
            && $b['y'] < $a['y'] + $a['h'];
    }
}

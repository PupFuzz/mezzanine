<?php

namespace App\Floor;

use App\Building\Footprint;

/**
 * ⭐ `docs/design/FLOOR.md § 14` item 28(1), operator-ruled 2026-09-25 (card#7341 comment 6488),
 * built by Appendix B row 14's slice C: what § 10.3's `desks` row refuses about a map's slots
 * against **the furniture box** — (i) two objects whose half-open rects intersect, naming both, and
 * (ii) an object smaller than § 12's box at the cap.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A WRITE-TIME RULE, AND DELIBERATELY NOT `FloorMap::parse()`'s. The parser is also every
 * READER's — the room map endpoint, the console's inventory, `S` for the revisions list, and the
 * deploy's refusal of a current document the readers refuse (card#9322's migration,
 * `server/database/migrations/2026_09_14_000000_refuse_a_current_authored_document_the_readers_refuse.php`,
 * run by `bin/deploy.sh`'s `migrate --force` until it is recorded). Put there, a box that grew
 * would make every room saved against the old one unreadable at once, blanking it: the refusal
 * *after the fact* item 28(1)(iii) rules out, because it would blank a room for a document the console
 * accepted (§ 9 F16's *Never*). So the SAME judgement is applied two ways:
 *
 *   - at a save AND at a restore (`App\Floor\Floors`), `refuse()` — the document does not become
 *     current;
 *   - on the room index (`App\Floor\FloorInventory`), `refusals()` — a current map that fails a box
 *     it was not validated against is LISTED, stays on the floor under F21's notice, and is refused
 *     nothing.
 *
 * ⛔ THE SAME TWO TESTS THE PAGE RUNS. The browser's scene emits § 5.5's *desk objects intersect*
 * and *desk object is smaller than the furniture box* for any map it holds (F21), comparing the
 * objects pairwise on `floor/floor-layout.js`'s half-open test and each against the box with
 * `width < box.width || height < box.height`. This class asks `App\Building\Footprint` — the PHP
 * form of that half-open test, one primitive shared with the floor plan — and the same two
 * inequalities, reading the box from the one file both runtimes read (`App\Floor\FurnitureBox`).
 */
final class DeskSlots
{
    /**
     * Every refusal item 28(1) makes of this map against this box: each intersecting pair, then
     * each undersized object. An empty list is a map that passes.
     *
     * @return list<string>
     */
    public static function refusals(FloorMap $map, FurnitureBox $box): array
    {
        $refusals = [];
        $desks = $map->desks;
        $count = count($desks);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (Footprint::intersect($desks[$i], $desks[$j])) {
                    $refusals[] = self::intersecting($desks[$i], $desks[$j]);
                }
            }
        }

        foreach ($desks as $desk) {
            if ($desk['w'] < $box->width || $desk['h'] < $box->height) {
                $refusals[] = self::undersized($desk, $box);
            }
        }

        return $refusals;
    }

    /**
     * The write's form: a map item 28(1) refuses does not become current.
     *
     * @throws InvalidFloorMap naming every refused object
     */
    public static function refuse(FloorMap $map, FurnitureBox $box): void
    {
        $refusals = self::refusals($map, $box);

        if ($refusals !== []) {
            throw new InvalidFloorMap(implode(' ', $refusals));
        }
    }

    /**
     * @param  array{name: string, x: float, y: float, w: float, h: float}  $a
     * @param  array{name: string, x: float, y: float, w: float, h: float}  $b
     */
    private static function intersecting(array $a, array $b): string
    {
        return sprintf(
            'Desk slots %s and %s intersect: %s spans %g–%g × %g–%g and %s spans %g–%g × %g–%g. '
            .'Every desk is drawn inside its own slot (docs/design/FLOOR.md § 10.3, Appendix B row '
            .'14), so two slots that share a pixel draw one desk over another; they may share an '
            .'EDGE and never a pixel (§ 14 item 28(1)(i)). Move one of them and save again.',
            $a['name'],
            $b['name'],
            $a['name'], $a['x'], $a['x'] + $a['w'], $a['y'], $a['y'] + $a['h'],
            $b['name'], $b['x'], $b['x'] + $b['w'], $b['y'], $b['y'] + $b['h'],
        );
    }

    /** @param array{name: string, x: float, y: float, w: float, h: float} $desk */
    private static function undersized(array $desk, FurnitureBox $box): string
    {
        return sprintf(
            'Desk slot %s is %g × %g pixels, smaller than the furniture box of %d × %d '
            .'(docs/design/FLOOR.md § 12, Appendix B row 14). Everything drawn for one desk at the '
            .'cap lies inside that box, so a smaller slot cannot hold a desk (§ 14 item 28(1)(ii)). '
            .'Make it at least %d × %d and save again.',
            $desk['name'],
            $desk['w'],
            $desk['h'],
            $box->width,
            $box->height,
            $box->width,
            $box->height,
        );
    }
}

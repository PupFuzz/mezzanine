<?php

namespace App\Building;

/**
 * ⭐ THE FLOOR PLAN's one geometric rule — `docs/design/FLOOR.md § 4.6`, card#9292: **two rooms on
 * one floor whose footprints would intersect are refused by name, naming both.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A PURE FUNCTION OF A LAYOUT AND A SET OF EXTENTS, AND IT READS NO STORE. That is what lets
 * the same rule stand at BOTH of the write sites `docs/design/FLEET-STATE.md § 6.11` names — the
 * layout's save or restore, and a room map's save, restore or removal — without either of them
 * holding its own copy of the arithmetic. Where the extents come from is
 * `App\Building\RoomExtents`'s question; whether they collide is this one's.
 *
 * ⛔ A SHARED EDGE IS NOT AN INTERSECTION (§ 4.6). A footprint is the HALF-OPEN rectangle
 * `[x, x + w) × [y, y + h)`, so two rooms may share an edge and may never share a pixel —
 * "because *a floor subdivided into two rooms* is naturally drawn with one wall between them".
 * A closed-interval comparison would refuse exactly the building the operator asked for, which is
 * why the half-open form is the rule rather than an implementation detail of it.
 *
 * ⚠ EXTENT LIVES IN THE ROOM AND POSITION ON THE FLOOR (§ 4.6 rule 1). Nothing here reads a size
 * off the layout, because the layout carries none: a room's footprint is its map's grid
 * (`App\Floor\FloorMap`), and the plan says only where its corner goes. An "authored rectangle
 * per room" is the shape that design refused, "because the two would disagree the first time an
 * operator resized either".
 *
 * ⚠ WHAT THIS CANNOT REACH IS § 9's F18, and it is a READ-time case by construction: a deploy
 * that changes the shipped default's grid while a planned floor holds a room that renders it
 * meets an overlap no save produced. § 4.6 draws both rooms and names them rather than refusing
 * the layout, and that render is the floor's (Appendix B step 7), not this class's.
 */
final class FloorPlan
{
    /**
     * Every room the plan PLACES — the rooms whose extent the overlap check needs, and the exact
     * set `App\Building\RoomExtents` must be able to answer for.
     *
     * @param  list<array{floor: string, rooms: list<array<string, mixed>>}>  $floors
     * @return list<string>
     */
    public static function placedRooms(array $floors): array
    {
        $placed = [];

        foreach ($floors as $floor) {
            foreach ($floor['rooms'] as $room) {
                if (isset($room['origin'])) {
                    $placed[] = (string) $room['install'];
                }
            }
        }

        return $placed;
    }

    /**
     * @param  list<array{floor: string, rooms: list<array<string, mixed>>}>  $floors  normalised floors, as `App\Building\BuildingLayout` produces them
     * @param  array<string, array{width: int, height: int}>  $extents  each placed room's footprint in pixels
     *
     * @throws InvalidBuildingLayout naming both rooms, their floor and both footprints
     */
    public static function refuseOverlaps(array $floors, array $extents): void
    {
        foreach ($floors as $floor) {
            $placed = [];

            foreach ($floor['rooms'] as $room) {
                if (! isset($room['origin'])) {
                    continue;
                }

                $install = (string) $room['install'];

                // A placed room with no extent is not this class's refusal to make: the caller
                // that resolved the extents said what it could and could not answer for, and a
                // silent skip here would be this check reporting clean over a room it never
                // measured. `App\Building\RoomExtents` refuses first, by name.
                if (! isset($extents[$install])) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` is placed on floor `%s` and no extent was resolved for it, so '
                        .'the overlap check would have passed over it without measuring it '
                        .'(docs/design/FLOOR.md § 4.6). This is a bug in the caller rather than a '
                        .'defect in the layout: App\Building\RoomExtents owns the answer and '
                        .'refuses by name when it has none.',
                        $install,
                        $floor['floor'],
                    ));
                }

                $placed[] = [
                    'install' => $install,
                    'x' => (int) $room['origin']['x'],
                    'y' => (int) $room['origin']['y'],
                    'w' => $extents[$install]['width'],
                    'h' => $extents[$install]['height'],
                ];
            }

            $count = count($placed);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (! self::intersect($placed[$i], $placed[$j])) {
                        continue;
                    }

                    throw new InvalidBuildingLayout(sprintf(
                        'Rooms `%s` and `%s` would share pixels on floor `%s`: `%s` covers '
                        .'%d–%d × %d–%d and `%s` covers %d–%d × %d–%d. Two rooms on one floor may '
                        .'share an EDGE and never a pixel (docs/design/FLOOR.md § 4.6, card#9292). '
                        .'A room\'s size is its own map\'s grid and the plan only places it, so '
                        .'move one room\'s `origin` — or make its map smaller — and save again.',
                        $placed[$i]['install'],
                        $placed[$j]['install'],
                        $floor['floor'],
                        $placed[$i]['install'],
                        $placed[$i]['x'], $placed[$i]['x'] + $placed[$i]['w'],
                        $placed[$i]['y'], $placed[$i]['y'] + $placed[$i]['h'],
                        $placed[$j]['install'],
                        $placed[$j]['x'], $placed[$j]['x'] + $placed[$j]['w'],
                        $placed[$j]['y'], $placed[$j]['y'] + $placed[$j]['h'],
                    ));
                }
            }
        }
    }

    /**
     * § 4.6's half-open rectangles: they intersect when each axis' open intervals overlap.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $a
     * @param  array{x: int, y: int, w: int, h: int}  $b
     */
    private static function intersect(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w']
            && $b['x'] < $a['x'] + $a['w']
            && $a['y'] < $b['y'] + $b['h']
            && $b['y'] < $a['y'] + $a['h'];
    }
}

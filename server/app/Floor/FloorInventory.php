<?php

namespace App\Floor;

use App\Building\Building;
use App\Building\BuildingLayout;
use App\Read\Snapshot;

/**
 * What the floors module SHOWS: every floor this deploy has, the map it was given, and the two
 * disagreements between the two that an operator can only fix here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SEAT SIDE IS `App\Read\Snapshot::seats()` — THE READ THE FLOOR ITSELF USES — AND NOT A
 * SECOND QUERY. `docs/design/FLOOR.md § 4.1` builds the lobby's floor list from
 * `installs[].install_id` in the snapshot, and `App\Read\RetirementFilter` is what decides which
 * seats that is. A hand-written `select … from installs` here would list floors the floor screen
 * does not draw and count seats it does not render, and the first thing the two would disagree
 * about is `docs/design/FLEET-STATE.md § 4.10`'s 14-day read filter. The agent module states the
 * same rule for the same reason.
 *
 * ⚠ THE POPULATION IS THE UNION of the installs the snapshot renders, the installs a map has been
 * authored for, and — since card#9267 — the rooms the BUILDING LAYOUT places, so a map whose room
 * is no longer drawn and a room the operator composed onto a floor but which reports nothing are
 * both SURFACED rather than dropped off the page. `card#9071` records the posture for the
 * analogous case — "a pin naming a retired or unknown seat is surfaced as a defect, never silently
 * ignored" — and a list that silently hid such a row would answer "why is my map not showing" with
 * nothing at all. `docs/design/FLOOR.md § 4.6` states the same rule for the floor itself: a room
 * the fleet reports no seat for is drawn and labelled there too, never omitted.
 *
 * ⚠ A ROW IS A ROOM, NOT A FLOOR — card#9267's ruling: a room is an install, and a floor is an
 * operator-composed set of rooms (`docs/design/FLOOR.md § 3.1`, `§ 4.6`). The module is still
 * called `floors` because its routes, its table and the operator's bookmark are; what it authors
 * is one map per ROOM, and the `floor` column below is where that room sits.
 */
final class FloorInventory
{
    /**
     * @return list<array{
     *     install_id: string, floor: string|null, form: string|null, seats: int, renders: bool,
     *     authored: bool, slots: int|null, unreadable: string|null, short_by: int,
     *     updated_at: string|null, updated_by: string|null
     * }>
     */
    public static function rows(): array
    {
        $seats = Snapshot::seats()->groupBy('install_id');
        $floors = Floors::all();

        // ⛔ ONE DERIVATION OF WHERE A ROOM SITS, AND IT IS THE COMPOSER'S. Reading the layout
        // directly here — "its floor if it is placed, else its own id" — would be a second copy of
        // `App\Building\Building::compose()`'s rule, and the two would disagree the first time one
        // of them learned about a new case. So the building is composed once and flattened.
        $placement = [];

        foreach (Building::compose(BuildingLayout::fromConfig(), $seats->keys()->map(strval(...))->all()) as $floor) {
            foreach ($floor['rooms'] as $room) {
                $placement[$room['install']] = ['floor' => $floor['floor'], 'form' => $room['form']];
            }
        }

        $ids = $seats->keys()
            ->merge($floors->keys())
            ->merge(array_keys($placement))
            ->unique()
            // § 2.1 row 6: rooms by `install_id` ascending.
            ->sort()
            ->values();

        $rows = [];

        foreach ($ids as $installId) {
            $count = $seats->has($installId) ? $seats->get($installId)->count() : 0;
            $floor = $floors->get($installId);

            $slots = null;
            $unreadable = null;

            if ($floor !== null) {
                try {
                    $slots = FloorMap::parse($floor->map)->slots;
                } catch (InvalidFloorMap $e) {
                    // Reachable, and named rather than swallowed: the write validates with the
                    // rules of the day, so a later TIGHTENING of them leaves a stored map this
                    // parser refuses. Rendering `S` as 0 there would read as "this floor has no
                    // desks", which is a different claim and a false one.
                    $unreadable = $e->getMessage();
                }
            }

            $rows[] = [
                'install_id' => (string) $installId,
                // null for a room that is on no floor at all: the fleet reports nothing for it
                // and the layout places it nowhere, so nothing draws it. That is a true and
                // actionable answer, and it is why this is not defaulted to the install's own id.
                'floor' => $placement[(string) $installId]['floor'] ?? null,
                'form' => $placement[(string) $installId]['form'] ?? null,
                'seats' => $count,
                'renders' => $count > 0,
                'authored' => $floor !== null,
                'slots' => $slots,
                'unreadable' => $unreadable,
                // § 3.2's overflow: "If the floor's seat count exceeds `S`… the floor shows a
                // persistent notice reading *floor map is short N desks*". Shown here too,
                // because here is where the map can actually be made longer.
                'short_by' => $slots === null ? 0 : max(0, $count - $slots),
                'updated_at' => $floor?->updated_at,
                'updated_by' => $floor?->updated_by,
            ];
        }

        return $rows;
    }

    /**
     * The install ids a map may be authored for: the floors this deploy actually renders.
     *
     * ⛔ THIS IS ALSO THE WRITE-SIDE GUARD, and it is deliberately the same list the form offers
     * rather than a second rule beside it. A `<select>` is a read-time constraint and the console
     * takes POSTs; the refusal has to be at the write or it is not a refusal.
     *
     * @return list<string>
     */
    public static function renderedInstalls(): array
    {
        return Snapshot::seats()
            ->pluck('install_id')
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** @return list<string> the rendered installs with no map yet — what "add a floor" offers */
    public static function installsWithoutAMap(): array
    {
        $authored = Floors::all();

        return array_values(array_filter(
            self::renderedInstalls(),
            fn (string $id) => ! $authored->has($id),
        ));
    }
}

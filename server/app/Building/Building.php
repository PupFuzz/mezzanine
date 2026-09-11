<?php

namespace App\Building;

/**
 * The building, composed — `docs/design/FLOOR.md § 4.6`. One authored layout plus the installs the
 * fleet reports, in, and the floors this deployment draws, out.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A PURE FUNCTION OF ITS TWO INPUTS, AND IT READS NOTHING ELSE. It opens no channel, issues no
 * fetch and names no seat — `docs/design/FLOOR.md § 4.6` puts `ADMIT`'s population with the
 * SNAPSHOT and never with the layout, because a client that opened `private-fleet.{install_id}`
 * on the strength of a layout naming a room would be asking for an install that may not exist.
 * The layout decides WHERE a room is drawn and contributes no count, no state, no membership and
 * no message.
 *
 * ⛔ EVERY INSTALL GIVEN TO IT COMES BACK ON A FLOOR. § 4.6 draws an install the layout does not
 * place on a floor of its own, alone, in the `open` form — which makes the layout a DEPARTURE from
 * a default rather than an enumeration anything depends on being complete, so provisioning an
 * install renders it without a deploy and a floor can never have a hole where a room is.
 *
 * ⛔ AND EVERY ROOM THE LAYOUT DECLARES COMES BACK TOO, reported or not — § 4.6 draws such a room
 * and labels it rather than omitting it. A room whose install the fleet reports nothing for is
 * returned with `reported: false` and is NEVER dropped: omitting it would make the floor silently
 * narrower than the operator authored it, and they would then be debugging a room that renders as
 * nothing.
 *
 * ⚠ THIS RULE HAS A SECOND HOME, AND THE TWO ARE PINNED TO ONE FIXTURE. The lobby composes the
 * same floors in the browser (`public/js/lobby/lobby-model.js`, `floors()`), because § 4.1's
 * discrepancy check discovers an install AFTER the page was served and that install still owes a
 * floor. Two runtimes, one rule: `tests/fixtures/building/compose-cases.json` is the one statement
 * of it, and both `Tests\Feature\Building` and `Tests\Feature\Lobby` are held to it.
 *
 * ⚠ IT MINTS NO RENDERED STRING. `reported: false` is the FACT; § 4.6's wording for it — *no seats
 * reported for this room* — is the client's own narration (§ 5.5) and belongs to whatever draws
 * the room. A sentence composed here would be a second home for it, and the two would disagree the
 * first time one was edited.
 */
final class Building
{
    /**
     * The floors this deployment draws, floor keys ascending, each floor's rooms by `install_id`
     * ascending — `docs/design/FLOOR.md § 2.1` row 6's sort orders. The layout's own authoring
     * order is deliberately NOT the stack order: § 4.1 fixes the floor list as an ascending one and
     * "which end of the stack is the top is not a fact this document ratifies", and an implicit
     * floor has no authored position to honour anyway.
     *
     * @param  list<string>  $installs  the installs the fleet reports, in any order
     * @return list<array{floor: string, rooms: list<array{install: string, form: string, reported: bool}>}>
     */
    public static function compose(BuildingLayout $layout, array $installs): array
    {
        $reported = array_fill_keys(array_map(strval(...), $installs), true);

        $floors = [];

        foreach ($layout->floors as $floor) {
            $floors[$floor['floor']] = array_map(
                fn (array $room) => $room + ['reported' => isset($reported[$room['install']])],
                $floor['rooms'],
            );
        }

        foreach (array_keys($reported) as $installId) {
            $installId = (string) $installId;

            if ($layout->floorOf($installId) !== null) {
                continue;
            }

            // The unplaced install's own floor. § 4.6's derived key is what makes this safe to
            // mint: every floor key in the layout is an install the layout PLACES, so an install
            // it does not place cannot collide with one, and this line can never overwrite an
            // authored floor.
            $floors[$installId] = [[
                'install' => $installId,
                'form' => BuildingLayout::DEFAULT_FORM,
                'reported' => true,
            ]];
        }

        ksort($floors, SORT_STRING);

        $out = [];

        foreach ($floors as $floorKey => $rooms) {
            $out[] = ['floor' => (string) $floorKey, 'rooms' => $rooms];
        }

        return $out;
    }
}

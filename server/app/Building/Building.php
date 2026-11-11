<?php

namespace App\Building;

/**
 * The building, composed — `docs/design/FLOOR.md § 4.6`. One authored layout, the installs the fleet
 * reports and the rooms with an authored map, in, and the floors this deployment draws, out.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A PURE FUNCTION OF ITS THREE INPUTS, AND IT READS NOTHING ELSE. It opens no stream, issues no
 * fetch and names no seat — `docs/design/FLOOR.md § 4.6` puts `ADMIT`'s population with the
 * SNAPSHOT and never with the layout, because a client that admitted an install on the strength
 * of a layout naming a room would be asking for an install that may not exist. (The feed is one
 * fleet-wide stream since card#9287; there is no per-install channel for a layout to open.)
 * The layout decides WHERE a room is drawn and contributes no count, no state, no membership and
 * no message.
 *
 * ⭐ EVERY ROOM IT CAN KNOW OF COMES BACK ON A FLOOR — the layout's, or the UNASSIGNED floor (card#13117, plan
 * § 4.4, REVERSING card#9267's implicit floor per install). A room the layout does not place, that the fleet REPORTS
 * or that has an AUTHORED map, is drawn on one floor keyed `unassigned`, named *Unassigned*, in the house theme,
 * sorted after every named floor and present only while it holds a room — and so is the snapshot's virtual install
 * `unassigned`, the seats in no room. That population is `App\Floor\FloorInventory`'s own union, and the only one
 * the browser can know without a new member: a room that is neither reported nor authored is drawn nowhere, and the
 * console's Rooms module is where it is listed. So provisioning still renders without a layout edit — on Unassigned,
 * where the operator places it — and a floor can never have a hole where a room is.
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
 * ⭐ IT CARRIES THE FLOOR'S NAME AND ITS FORMER ADDRESSES (card#13117): `name` is what every surface renders and
 * `aliases` is what the floor route redirects from (§ 4.4's precedence: current slug, alias, room key) — the
 * browser's composition is what the floor route resolves against, so the aliases ride it.
 *
 * ⭐ IT CARRIES THE ROOM'S `origin` AND NOT THE FLOOR'S `hallway` (card#9292, § 4.6), and the
 * asymmetry is the two members' own. `origin` is where a room is DRAWN — three integers' worth of
 * the composition every reader of a composed floor needs, and the fixture pins both runtimes to
 * carrying it. A `hallway` is a whole Tiled document that only the floor SCREEN draws (§ 4.2,
 * Appendix B step 7); putting it on every composed floor would page it into the lobby, which draws
 * plates, for a screen that does not exist yet. It stays in the layout document, where the floor
 * route will read it.
 *
 * ⚠ IT MINTS NO RENDERED STRING. `reported: false` is the FACT; § 4.6's wording for it — *no seats
 * reported for this room* — is the client's own narration (§ 5.5) and belongs to whatever draws
 * the room. A sentence composed here would be a second home for it, and the two would disagree the
 * first time one was edited.
 */
final class Building
{
    /** The Unassigned floor's key — the reserved address no named floor may take (`FloorSlug::RESERVED`). */
    public const UNASSIGNED_FLOOR = FloorSlug::RESERVED;

    /** The Unassigned floor's name, as every surface renders it. */
    public const UNASSIGNED_NAME = 'Unassigned';

    /**
     * The floors this deployment draws: the layout's, by key (the slug of each name) ascending, then the Unassigned
     * floor when it holds a room; each floor's rooms by `install_id` ascending — `docs/design/FLOOR.md § 2.1` row 6's
     * sort orders. The layout's own authoring order is deliberately NOT the stack order: § 4.1 fixes the floor list as
     * an ascending one and "which end of the stack is the top is not a fact this document ratifies".
     *
     * @param  list<string>  $installs  the installs the fleet reports, in any order — the snapshot's groups, `unassigned` included
     * @param  list<string>  $authored  the rooms with an authored map (`GET /api/building`'s `rooms[]`), in any order
     * @return list<array{floor: string, name: string, aliases: list<string>, theme: string|null, rooms: list<array{install: string, form: string, origin?: array{x: int, y: int}, reported: bool}>}>
     */
    public static function compose(BuildingLayout $layout, array $installs, array $authored = []): array
    {
        $reported = array_fill_keys(array_map(strval(...), $installs), true);

        $out = [];

        foreach ($layout->floors as $floor) {
            $out[] = [
                'floor' => $floor['floor'],
                'name' => $floor['name'],
                'aliases' => $floor['aliases'],
                // § 4.6 (card#11046): absent on the layout's floor is the house theme, null here.
                'theme' => $floor['theme'] ?? null,
                'rooms' => array_map(
                    fn (array $room) => $room + ['reported' => isset($reported[$room['install']])],
                    $floor['rooms'],
                ),
            ];
        }

        $unplaced = [];

        foreach ([...array_keys($reported), ...$authored] as $installId) {
            $installId = (string) $installId;

            if ($layout->floorOf($installId) === null) {
                $unplaced[$installId] = [
                    'install' => $installId,
                    'form' => BuildingLayout::DEFAULT_FORM,
                    'reported' => isset($reported[$installId]),
                ];
            }
        }

        if ($unplaced !== []) {
            ksort($unplaced, SORT_STRING);

            $out[] = [
                'floor' => self::UNASSIGNED_FLOOR,
                'name' => self::UNASSIGNED_NAME,
                'aliases' => [],
                // No entry, so no theme: the house theme (docs/design/FLOOR.md § 10.6 item 5, card#11046).
                'theme' => null,
                'rooms' => array_values($unplaced),
            ];
        }

        return $out;
    }
}

/**
 * The building's floors as the plaques the directory and the elevator's cab both read — `docs/design/FLOOR.md § 4.1`'s
 * floor list, as a pure function over the same snapshot body `lobby-model.js` already reads.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO DOM AND NO `fetch` IN THIS FILE, for `lobby-model.js`'s reason, stated once there: there is no browser on the
 * build host, so every DECISION lives in a module `node` can drive.
 *
 * ⛔ THIS MODULE READS NO WIRE MEMBER AND COMPUTES NO COUNT. `plates()` is `lobby-model.js`'s own `floors()` with each
 * floor's place in § 4.1's order added, and every figure on a plaque — its name, its summary, its tally, its href —
 * arrives already decided there. A second derivation here would be two orders and two summaries for one floor.
 *
 * ⭐ ONE LIST FOR THE DIRECTORY AND BOTH CABS (§ 4.5's ride, card#12113): the floor screen's cab (`floor/floor-screen.js`)
 * and the lobby's (`lobby/lobby-screen.js`) take their stops from `plates()`, re-derived on every frame, and the
 * lobby's directory draws a plaque per row of the same `floors()` — so a floor is named, ordered and linked one way
 * wherever the viewer meets it. ⚠ Until card#12113 this module also stood the cross-section's elevator: `elevator()`,
 * its `NO_STOPS` / `ONE_STOP` notices and `buildingModel()`; the lobby's elevator is now § 4.5's ride, which has no
 * next stop and no cab position, and they went with the cross-section.
 */

import { floors } from './lobby-model.js';

/**
 * § 4.1's floor list: the SAME rows `floors()` composes, in the SAME ascending floor-key order, each carrying `level`,
 * its index in that order and nothing more. `null` when `floors()` composes no building, which is its answer when no
 * layout is held (§ 9 F17). `nowMs` is the corrected clock `floors()` reads the plaque's ages at, handed through so a
 * row stays the lobby's row for its floor.
 */
export function plates(snapshot, layout, nowMs = null) {
    const rows = floors(snapshot, layout, nowMs);

    return rows === null ? null : rows.map((floor, level) => ({ ...floor, level }));
}

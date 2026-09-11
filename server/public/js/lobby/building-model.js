/**
 * The building cross-section and its elevator — `docs/design/FLOOR.md § 4.1`'s ratified
 * *rendering* of the lobby table, as pure functions over the same snapshot body `lobby-model.js`
 * already reads.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO DOM AND NO `fetch` IN THIS FILE, for `lobby-model.js`'s reason, stated once there: there
 * is no browser on the build host, so every DECISION lives in a module `node` can drive and
 * `main.js` is the thin layer that puts the result into elements.
 *
 * ⛔ THIS MODULE READS NO WIRE MEMBER AND COMPUTES NO COUNT. § 4.1: "The ratified building
 * cross-section is a *rendering* of this table, and changes nothing in it … each plate carries
 * the same **per-floor state summary** … **the plate is the link** exactly as the list row was.
 * No new field is read, no count is recomputed." So `plates()` is `lobby-model.js`'s own
 * `floors()` with a stack position added, and the summary, the held count and the href arrive
 * already decided. A second derivation here would be two orders and two summaries for one
 * floor — and they would agree until the day one of them was edited.
 *
 * ⭐ A PLATE IS A FLOOR, AND SINCE card#9267 A FLOOR IS AN OPERATOR-COMPOSED SET OF ROOMS
 * (§ 3.1, § 4.6) — one room per floor until the building layout the page delivers says
 * otherwise. The stack is the composed floors, keyed by floor; the cab's stop is a floor key;
 * and none of that is decided here, because `floors()` already decided it.
 *
 * ⛔ THE ELEVATOR IS NAVIGATION, AND NAVIGATION IS NEVER STATE (§ 4.5). The cab's position is not
 * a fact about the fleet: it renders no D2 field, it takes no row in § 6.2's animation table
 * ("a camera move animates nothing in § 6.2's sense … it is the viewer moving their own head"),
 * and nothing on the page may read a seat's state out of where the cab is standing. That is also
 * why the cab's stop is a PARAMETER here rather than something derived from the snapshot: the
 * viewer owns it, the fleet does not.
 *
 * ⛔ AN ELEVATOR WITH NOWHERE TO GO SAYS SO. § 4.1 stacks one floor plate per floor, so a
 * building with one floor has nowhere to ride and a building with none — no install reported and
 * no floor composed — is no building at all; the ride must then be REFUSED and the refusal rendered, never softened into a ride
 * that lands back where it started. `(level + 1) % stack.length` is the exact expression that fakes
 * it: at one stop it is arithmetic that always "succeeds", and a viewer clicking it would watch a
 * working elevator on a building that has no second floor. `nextStop()` below returns `null`
 * there instead, and `notices()` says which of the two dark cases it is.
 *
 * ⚠ WHERE THE RIDE ARRIVES IS NOT BUILT. § 4.1: an elevator ride and a zoom-to-floor are § 4.5's
 * camera arriving at the floor route — and that route does not exist (card#9208: the floor map is
 * a build artifact and none is vendored; § 14 item 7's tileset is still open). So a ride moves the
 * cab between the plates of this screen and nothing else, and the plate keeps the published link
 * `floors()` already gives it — D3's own route, never one minted here.
 */

import { floors } from './lobby-model.js';

/**
 * § 4.1's floor list, as the cross-section's stacked plates: the SAME rows, in the SAME ascending
 * floor-key order, each carrying its position in the stack.
 *
 * `level` is an index into that ascending order and nothing more. **Which end of the stack is the
 * top is not a fact this document ratifies** — § 4.1 fixes the ORDER and says the plates are
 * "stacked"; the ratified reference artifact (`docs/design/floor-preview/`) draws the first of the
 * ascending order at the top, and `main.js` follows it. Keeping the direction out of the model is
 * what lets that be a rendering choice rather than a second ruling invented here.
 */
export function plates(snapshot, layout = []) {
    return floors(snapshot, layout).map((floor, level) => ({ ...floor, level }));
}

/**
 * The two dark cases, as the sentences they are rendered in. They are about THE ELEVATOR — what
 * the control can and cannot do — and deliberately not a second telling of the floor list's own
 * "no installs are provisioned" sentence, which `main.js` already renders over the stack.
 */
export const NO_STOPS = 'the elevator has no stops — the snapshot carries no installs and the layout composes no floor';

export const ONE_STOP = 'the elevator has one stop — a single-floor building has nowhere to ride';

/**
 * Where a ride from `level` arrives, or `null` when there is nowhere to ride.
 *
 * The wrap at the top of the stack is the reference artifact's behaviour
 * (`elevatorTo(FLOORS[(fi+1)%FLOORS.length])`) and it is kept — with the one stop count at which
 * that expression stops being a ride excluded, which is this module's header.
 *
 * `level` indexes the stack because `plates()` MINTS it as that index — one definition of a
 * plate's position, used both to report where the cab is and to find where it goes next. A second
 * position computed here would be a `findIndex` that agreed with `level` until one of them moved.
 */
function nextStop(stack, level) {
    if (stack.length < 2 || level === null) {
        return null;
    }

    return stack[(level + 1) % stack.length].floor;
}

/**
 * The elevator, for a stack of plates and the stop the viewer last rode to.
 *
 * `at` is the requested stop when the building still has it. **When it does not, the cab is put at
 * the first plate and SAYS SO** — that is a reachable observation and not a paranoia case: an
 * unplaced install's floor leaves the building when its last seat is retired (§ 3.5), and a client holding a cab position
 * across that render would otherwise report the viewer as standing on a floor the building no
 * longer has. Moving the cab quietly is the same defect § 4.1 refuses in the discrepancy check —
 * picking a winner instead of rendering the disagreement.
 *
 * `notices` is a LIST because two of these can be true at once: a stranded cab on a building that
 * has since shrunk to one floor is both stranded and unable to ride, and a single-string notice
 * would have to drop one of them.
 */
export function elevator(stack, requested = null) {
    const rows = Array.isArray(stack) ? stack : [];
    const asked = rows.find((plate) => plate.floor === requested);
    // The requested plate, else the first plate in § 4.1's order, else there is no building for
    // the cab to stand in at all.
    const plate = asked ?? rows[0] ?? null;
    const at = plate === null ? null : plate.floor;
    const level = plate === null ? null : plate.level;
    const stranded = requested !== null && asked === undefined && rows.length > 0;

    const notices = [];

    if (stranded) {
        notices.push(`the floor ${requested} is no longer in the building — the elevator is at ${at}`);
    }

    if (rows.length === 0) {
        notices.push(NO_STOPS);
    } else if (rows.length === 1) {
        notices.push(ONE_STOP);
    }

    return { at, level, next: nextStop(rows, level), stranded, stops: rows.length, notices };
}

/**
 * The whole cross-section, from one snapshot body and the viewer's own cab position — the shape
 * `main.js` renders and the shape the probe asserts.
 */
export function buildingModel(snapshot, at = null, layout = []) {
    const stack = plates(snapshot, layout);

    return { plates: stack, elevator: elevator(stack, at) };
}

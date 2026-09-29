/**
 * THE VIEWER'S CAB POSITION FOR A RENDER — Appendix B row 16 (card#7343), impl review r1 finding 2.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `cab` IS THE VIEWER'S OWN ACT AND NEVER MODEL STATE (§ 4.5: "navigation is never state"), so
 * `main.js` keeps it in a closure and every COMPOSED render re-seats it on what the model resolved —
 * `building.elevator.at` — so a cab stranded by a layout change (its plate removed from under it)
 * reports itself once and the next render is an ordinary one (§ 9's building rows).
 *
 * ⛔ BUT A RIDE COMMITS THE CAB ON THE CLICK, BEFORE THE MODEL HAS EVER HEARD OF IT: `lobby-screen.js`'s
 * `ride()` names the destination and the page moves `cab` there at once, while the glide runs. A render
 * whose fetch was already in flight when the click landed (an awaited `building.layout`, a delta) lands
 * AFTERWARDS, carrying the model's PRE-ride facts — `elevator.at` still the ride's ORIGIN — and re-seating
 * `cab` unconditionally on that render sends the cab gliding BACKWARDS to the floor the viewer just left,
 * mid-glide. A ride in flight (the frame's `riding`, `lobby-screen.js`'s) owns the cab until it arrives:
 * such a render must leave `cab` exactly where the ride put it.
 *
 * Driven under `node`, with no DOM, by `Tests\\Feature\\Lobby\\TheRideOwnsTheCabDuringItsGlideTest`.
 */

/**
 * @param {string|null} cab the viewer's cab position before this render
 * @param {{composed: boolean, elevator: {at: string|null}}} building the frame's building
 * @param {boolean} riding whether a ride is in flight (the frame's `riding`)
 * @returns {string|null} the cab position to hold through this render
 */
export function resolveCab(cab, building, riding) {
    return building.composed && !riding ? building.elevator.at : cab;
}

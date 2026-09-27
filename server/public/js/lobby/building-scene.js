/**
 * THE BUILDING'S SCENE — where each plate stands in the building's own coordinates, so that row 15's
 * camera (`../wire/camera.js`) can frame the whole building and zoom to one plate of it.
 * `docs/design/FLOOR.md` Appendix B row 16, slice A (card#7343).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ GEOMETRY AND NOTHING ELSE. A plate here is its key and a rect, and its name's size on the screen is
 * `LABEL_FONT` under `labelScale()` (below); every fact the plate carries —
 * its name, its rooms, its summary, its link — is `building-model.js`'s `plates()`, which this module
 * reads for the stack position alone (`level`) and never re-derives. So the cross-section still reads
 * no field the table does not and recounts nothing (row 16; AT-D3-15 stays at row 9).
 *
 * ⛔ THE STACK RUNS FIRST-AT-THE-TOP, because `level` is an index into § 4.1's ascending order and the
 * ratified reference draws the first of it at the top (`building-model.js`'s `plates()` says why that is
 * a rendering choice and not a ruling). A plate is `PLATE_H` below the one before it.
 *
 * ⛔ THE NUMBERS ARE THE DRAWING's (§ 10.4's last bullet): a plate's size carries no fact. The plate is
 * a storey's proportion — the reference's storey has about the proportions of § 12's viewport
 * floor — so a zoom to one plate fills the surface rather than panning a strip across it. Slice B,
 * which draws the plate as the reference's section, owns these numbers from then on.
 */

/** A plate's width in scene px. */
export const PLATE_W = 1600;

/** A plate's height in scene px — one storey. */
export const PLATE_H = 1000;

/**
 * ⛔ A PLATE'S NAME IS DRAWN AT A FIXED SCREEN SIZE, AND THAT SIZE IS THE PAGE'S OWN (operator ruling on
 * card#7343 F1, 2026-09-27, Appendix B row 16). The lobby exists to pick a floor, so its names are read
 * before any zoom: at whole-building fit a plate's text shrinks with the plate — a storey's proportions,
 * height-bound, so the more floors the smaller — and a name drawn in scene px would too. So the name is a
 * label over its plate that the camera MOVES and never SCALES: `LABEL_FONT` is the lobby page's base
 * font size — `1rem`, the root's size, which the page's body text is set in because the page ships no
 * stylesheet that sets another (`Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest` reds if
 * one arrives) — and `labelScale()` is the counter-scale that undoes the camera's zoom on it. The
 * product is the one the page draws: a name at `LABEL_FONT`, under the camera's `scale(zoom)`, under its
 * own `scale(labelScale(camera))`, is `LABEL_FONT` on the screen at every zoom — at fit, after a wheel or
 * a drag, mid-glide and after a resize. No figure is chosen here: the size is the viewer's own base size.
 *
 * ⚠ § 12's zoom-to-read rule is the FLOOR's, and it does not carry over to a plate's name. The rest of a
 * plate's text — its summary, its rooms, the cab's word — is still drawn in scene px and scales with it.
 */
export const LABEL_FONT = '1rem';

/**
 * The scale a plate's name is drawn at inside the camera's transform: the inverse of its zoom, so the
 * name's size on the screen is `LABEL_FONT` whatever the camera does (`LABEL_FONT`'s ruling).
 *
 * @param {{zoom: number}} camera a `wire/camera.js` camera — the one the plates are shown under
 */
export function labelScale(camera) {
    return 1 / camera.zoom;
}

/**
 * The scene for a stack of plates: `{ extent, plates: [{ floor, rect }] }`, `extent` the whole
 * building's rect — `null` when there is no plate to frame — and each plate's `rect` at its `level`.
 *
 * @param {Array<{floor: string, level: number}>} plates `building-model.js`'s `plates()`, in its order
 */
export function buildingScene(plates) {
    return {
        extent: plates.length === 0 ? null : { x: 0, y: 0, w: PLATE_W, h: PLATE_H * plates.length },
        plates: plates.map((plate) => ({
            floor: plate.floor,
            rect: { x: 0, y: plate.level * PLATE_H, w: PLATE_W, h: PLATE_H },
        })),
    };
}

/**
 * THE BUILDING'S SCENE — where each plate stands in the building's own coordinates, so that row 15's
 * camera (`../wire/camera.js`) can frame the whole building and zoom to one plate of it.
 * `docs/design/FLOOR.md` Appendix B row 16, slice A (card#7343).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ GEOMETRY AND NOTHING ELSE. A plate here is its key and a rect, and its text's size on the screen is
 * `LABEL_FONT` under `labelScale()` (below; `plate-row.js` sets it); every fact the plate carries —
 * its name, its rooms, its summary, its link — is `building-model.js`'s `plates()`, which this module
 * reads for the stack position alone (`level`) and never re-derives. So the cross-section still reads
 * no field the table does not and recounts nothing (row 16; AT-D3-15 stays at row 9).
 *
 * ⛔ THE STACK RUNS FIRST-AT-THE-TOP, because `level` is an index into § 4.1's ascending order and the
 * ratified reference draws the first of it at the top (`building-model.js`'s `plates()` says why that is
 * a rendering choice and not a ruling). A plate is `PLATE_H` below the one before it.
 *
 * ⛔ THE SURFACE IS A DRAWING ONLY WHILE THERE IS A BUILDING TO DRAW (card#7343 r3, the seat's ruling).
 * `surfaceStyle()` is `#lobby-building`'s own style: with a scene that has an extent it is the drawing —
 * a fixed height that clips the plates, which the camera alone moves; with none — no snapshot yet, § 9
 * F17's cold start with no layout (its rooms listed with no floor claimed), or no install — it is no
 * style at all, and the list flows in the page as the lobby's list always did, every row reachable by
 * the page's own scroll — the wheel's and the arrow keys' included, which `wire/camera-gestures.js` and
 * `wire/camera-keys.js` leave to the browser, with every other camera event, while the camera frames
 * nothing (card#7343 r3b, r4b).
 *
 * ⛔ THE NUMBERS ARE THE DRAWING's (§ 10.4's last bullet): a plate's size carries no fact. The plate is
 * a storey's proportion — the reference's storey has about the proportions of § 12's viewport
 * floor — so a zoom to one plate fills the surface rather than panning a strip across it. Slice B,
 * which draws the plate as the reference's section, owns these numbers from then on.
 */

import { framesNothing } from '../wire/camera.js';

/** A plate's width in scene px. */
export const PLATE_W = 1600;

/** A plate's height in scene px — one storey. */
export const PLATE_H = 1000;

/**
 * ⛔ A PLATE'S TEXT IS DRAWN AT A FIXED SCREEN SIZE, AND THAT SIZE IS THE PAGE'S OWN (operator rulings on
 * card#7343, 2026-09-27, Appendix B row 16: F1 for the name, then r2 for its status line — the summary,
 * the rooms and the cab's word). The lobby exists to pick a floor, so a plate is read before any zoom:
 * at whole-building fit a plate shrinks — a storey's proportions, height-bound, so the more floors the
 * smaller — and text drawn in scene px would shrink with it. So the name and the status line are one
 * label over the plate that the camera MOVES and never SCALES: `LABEL_FONT` is the lobby page's base
 * font size — `1rem`, the root's size, which the page's body text is set in because the page ships no
 * stylesheet that sets another (`Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest` reds if
 * one arrives) — and `labelScale()` is the counter-scale that undoes the camera's zoom on it. The
 * product is the one the page draws: text at `LABEL_FONT`, under the camera's `scale(zoom)`, under the
 * label's own `scale(labelScale(camera))`, is `LABEL_FONT` on the screen at every zoom — at fit, after a
 * wheel, a key or a drag, mid-glide and after a resize. No figure is chosen here: the size is the
 * viewer's own base size.
 *
 * ⚠ § 12's zoom-to-read rule is the FLOOR's, and it does not carry over to a plate's text.
 */
export const LABEL_FONT = '1rem';

/**
 * The scale a plate's label is drawn at inside the camera's transform: the inverse of its zoom, so the
 * text's size on the screen is `LABEL_FONT` whatever the camera does (`LABEL_FONT`'s ruling).
 *
 * @param {{zoom: number}} camera a `wire/camera.js` camera — the one the plates are shown under
 */
export function labelScale(camera) {
    return 1 / camera.zoom;
}

/**
 * The narrowest a plate's label is ever wrapped to, in CSS px: 320, the width WCAG 2.1's Reflow criterion
 * (SC 1.4.10) requires content to read at without scrolling in two dimensions — "a width equivalent to
 * 320 CSS pixels" (w3.org/WAI/WCAG21/Understanding/reflow.html, read 2026-09-29) — so a label wrapped
 * this narrow is still text laid out at a width the web's own reflow rule treats as readable. A figure
 * of the drawing's, carrying no fact.
 */
export const LABEL_MIN_PX = 320;

/**
 * The width a plate's label wraps within, in CSS px on the screen — `lobby/main.js`'s `--label-max` (the
 * seat's r4b ruling on card#7343, refining r3's "the surface's width"): what is VISIBLE of the surface to
 * the right of the plates' on-screen left edge, so at whole-building fit — where a height-bound fit insets
 * the building from the surface's left edge — a label reads to its end without a pan wherever at least
 * `LABEL_MIN_PX` is visible to the right of the plates. Clamped: never wider than the surface, so a pan
 * can always bring the whole label into view (a plate whose left edge is off the surface's left), and
 * never narrower than `LABEL_MIN_PX` (a plate panned towards the surface's right edge), unless the
 * surface itself is narrower still. Every plate stands at the building's left edge
 * (`buildingScene()`), which is the camera's framed `bounds.x`; a camera that frames nothing has no
 * plate, and its labels — none — wrap within the surface.
 *
 * @param {{surface: {width: number}, bounds: {x: number}|null, zoom: number, x: number}} camera a
 *        `wire/camera.js` camera — the one the plates are shown under
 */
export function labelMax(camera) {
    const width = camera.surface.width;

    if (framesNothing(camera)) {
        return width;
    }

    const left = (camera.bounds.x - camera.x) * camera.zoom;

    return Math.min(width, Math.max(LABEL_MIN_PX, width - left));
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

/** The drawing surface's height while it draws a building — the drawing's, carrying no fact. */
export const SURFACE_H = '70vh';

/**
 * `#lobby-building`'s style for a scene: the clipping drawing surface the camera looks in when the scene
 * has an extent, and nothing — the list flowing in the page — when it has none or there is no scene.
 * Every member is set either way, so a lobby whose building goes away stops clipping.
 *
 * @param {{extent: object|null}|null} scene `buildingScene()`'s, or `null` where nothing is composed
 */
export function surfaceStyle(scene) {
    const drawn = (scene?.extent ?? null) !== null;

    return {
        height: drawn ? SURFACE_H : '',
        overflow: drawn ? 'hidden' : '',
        touchAction: drawn ? 'none' : '',
        cursor: drawn ? 'grab' : '',
    };
}

/**
 * WHERE A DESK's SLAB STANDS IN ITS FURNITURE BOX — the art column's width, the desk's own rect and its monitor's frame
 * (§ 2.2, § 10.6), pure and importing nothing, so a page that loads none of the desk's rendering (the lobby's) places a
 * desk where the floor page draws it. `floor/desk-layout.js` reads all three and re-exports `ART_W`.
 */

/** The art column's width — the character, the desk, the monitor, the chip and the plate (§ 2.2: Chosen). */
export const ART_W = 216;

/**
 * THE DESK's SLAB (§ 10.6): 180 × 60 px under the art column's centre, its top 100 px above the furniture box's foot —
 * from the box's BOTTOM-LEFT point `foot`, so the box's height cancels and a reader needs only where the box stands.
 * `deskRects()` reads it box-relative (`{ x: 0, y: box.height }`).
 *
 * @param {{x: number, y: number}} foot the furniture box's bottom-left corner
 * @returns {{x: number, y: number, w: number, h: number}}
 */
export function deskSlab(foot) {
    return { x: foot.x + ART_W / 2 - 90, y: foot.y - 100, w: 180, h: 60 };
}

/**
 * THE MONITOR's FRAME (§ 12's monitor row): 96 × 46 px, its right edge the slab's right edge, standing on the slab's top —
 * so the floor page's monitor and the cab's door view's monitor back (`floor/door-view.js`) are one rect.
 *
 * @param {{x: number, y: number, w: number}} slab `deskSlab()`'s rect
 * @returns {{x: number, y: number, w: number, h: number}}
 */
export function monitorFrame(slab) {
    return { x: slab.x + slab.w - 96, y: slab.y - 46, w: 96, h: 46 };
}

/**
 * WHERE A DESK's SLAB STANDS IN ITS FURNITURE BOX — the art column's width and the desk's own rect (§ 2.2, § 10.6),
 * pure and importing nothing, so a page that loads none of the desk's rendering (the lobby's) places a desk where the
 * floor page draws it. `floor/desk-layout.js` reads both and re-exports `ART_W`.
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

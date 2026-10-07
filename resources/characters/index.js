// The character tree's public surface (docs/design/FLOOR.md § 10.2, § 10.4). Everything the floor
// needs is here; nothing else in this directory should be imported directly by app code.
//
// Two layers under this one: `seed.js`, the seed machinery (first-party), and `creatures.js`, the
// creature generator (first-party, card#11046). This file adds the frame contract's three entry
// points and the size the scene reads — no cache: the generator is pure and the PAINTER caches the
// URIs it makes per asset, and drops an intern's when it stops drawing it (`floor/painter.js`).
//
// ⛔ Every entry point returns standalone SVG DOCUMENTS. The painter turns each into a
// SVG `data:` URI (UTF-8, encodeURIComponent) — this tree carries no URI, and no text naming one.

import { frameDocument } from './creatures.js';
import { seatKey } from './seed.js';

export { seatKey, fnv1a32, draw } from './seed.js';
export {
  SPECIES, SPECIES_KEYS, SPECIES_FIELD, WITHHELD, EYES, MOUTHS, BLUSH, BROWS, SIZES, STOUT, TILTS, ACCENTS, GREENS,
  RIM, RIM_W, SEAT_VIEWBOX, CHIBI_VIEWBOX, CHIBI_HEAD_SHARE, speciesOf, recipe, describe, chibiGeometry, frameDocument,
} from './creatures.js';

/**
 * The character's footprint unit, in floor pixels: the character rect is `CHARACTER_SCALE` (3) times
 * this — 54 x 96 (`floor/desk-layout.js`, FLOOR.md § 12's *Character rect*). The creatures are vector
 * and have no native size; this is the rect's aspect and scale unit, kept from the pixel tree so the
 * box and every rect stay where they are (Appendix B row 19). A seat's frame draws 108 x 192 units into
 * it — two per floor pixel.
 */
export const SCENE_W = 18;
export const SCENE_H = 32;

/**
 * THE STANDING FRAME — what a desk with a character draws for every pose (FLOOR.md § 10.4's frame
 * contract: parity with today), and phase 0 of the walk.
 * @param {string} installId @param {string} seatId @returns {string} an SVG document
 */
export function standingFrame(installId, seatId) {
  return frameDocument(seatKey(installId, seatId), { phase: 0 });
}

/**
 * THE WALK — stand, step-left, step-right, front-facing: the walker's frames, and what a held loop
 * steps through, as the painter does today.
 * @param {string} installId @param {string} seatId @returns {string[]} three SVG documents
 */
export function walkFrames(installId, seatId) {
  const key = seatKey(installId, seatId);
  return [0, 1, 2].map((phase) => frameDocument(key, { phase }));
}

/**
 * THE INTERN'S CHIBI FRAME — handed the intern key `seat~<call_id>` in the seat's place
 * (`floor/desk-layout.js`'s `internKey()`): the same recipe re-proportioned, its head at least half
 * the drawn height, for the 20 x 32 rect.
 * @param {string} installId @param {string} internKey @returns {string} an SVG document
 */
export function chibiFrame(installId, internKey) {
  return frameDocument(seatKey(installId, internKey), { chibi: true });
}

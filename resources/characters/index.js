// The character tree's public surface (docs/design/FLOOR.md § 10.2, § 10.4). Everything the floor
// needs is here; nothing else in this directory should be imported directly by app code.
//
// Two layers under this one: `seed.js`, the seed machinery (first-party), and `creatures.js`, the
// creature generator (first-party, card#11046). This file adds the frame contract's entry points —
// the standing frame, the walk, the intern's chibi frame, the sleeper, and `poseFrames()`, which a desk
// draws its pose in — and the size the scene reads. No cache: the generator is pure and the PAINTER
// caches the URIs it makes per asset and drawing, and drops an intern's when it stops drawing it
// (`floor/painter.js`).
//
// ⛔ Every entry point returns standalone SVG DOCUMENTS. The painter turns each into a
// SVG `data:` URI (UTF-8, encodeURIComponent) — this tree carries no URI, and no text naming one.

import { SLEEP_FRAMES, frameDocument, sleepDocument } from './creatures.js';
import { draw, seatKey } from './seed.js';

export { seatKey, fnv1a32, draw } from './seed.js';
export {
  SPECIES, SPECIES_KEYS, SPECIES_FIELD, WITHHELD, EYES, MOUTHS, BLUSH, BROWS, SIZES, STOUT, TILTS, ACCENTS, GREENS,
  RIM, RIM_W, SEAT_VIEWBOX, CHIBI_VIEWBOX, CHIBI_HEAD_SHARE, speciesOf, recipe, describe, chibiGeometry, frameDocument,
  SLEEP_FRAMES, SLEEP_ZS, Z_LARGE, Z_EYE_GAP, zLargeFor, sleepDocument,
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
 * THE STANDING FRAME — what a desk with a character draws for every pose but `asleep` (FLOOR.md § 10.4's
 * frame contract), and phase 0 of the walk.
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

/**
 * THE SLEEPER — A6's loop (FLOOR.md § 6.2, § 10.4's frame contract item 4): `SLEEP_FRAMES` documents of the
 * seat slumped asleep on its desk with its eyes closed and the z's rising beside its head; frame 0 is the
 * static, reduced-motion form, the same canonical frame for every seat (a moving loop starts at `loopOffset()`). `deskTop` is where the desk's top edge crosses the character's rect, as a
 * share of the rect's height — the floor's to say (`floor/desk-layout.js`), because the frame draws no desk.
 * @param {string} installId @param {string} seatId @param {number} deskTop @returns {string[]}
 */
export function sleepFrames(installId, seatId, deskTop) {
  const key = seatKey(installId, seatId);
  return Array.from({ length: SLEEP_FRAMES }, (_, frame) => sleepDocument(key, { frame, deskTop }));
}

/**
 * WHAT A DESK DRAWS FOR A POSE — the frames a desk's character is drawn in, frame 0 its static form and the
 * rest the held loop it steps when its render moves: the sleeper's for `asleep`; for every other pose the walk,
 * as before (§ 10.4's frame contract item 1). The one place a pose becomes a drawing: the painter asks, and
 * decides nothing.
 * @param {string} installId @param {string} seatId @param {{pose: string, deskTop: number}} at
 * @returns {string[]}
 */
export function poseFrames(installId, seatId, { pose, deskTop }) {
  return pose === 'asleep' ? sleepFrames(installId, seatId, deskTop) : walkFrames(installId, seatId);
}

/**
 * THE SEAT's LOOP OFFSET (FLOOR.md § 6.2's per-seat loop phase rule, § 10.4 item 4; card#12187): the frame a seat's
 * MOVING held loop starts at, of a loop `length` frames long — `draw(seatKey, 'loop-phase') % length`, a pure
 * function of the key, so it is the same on every reload and draws nothing random. The painter steps every character
 * whose held render moves from it (`floor/painter.js`); frame 0 stays the canonical, reduced-motion form, which no
 * offset reads.
 * @param {string} installId @param {string} seatId @param {number} length @returns {number} in 0..length-1
 */
export function loopOffset(installId, seatId, length) {
  return draw(seatKey(installId, seatId), 'loop-phase') % length;
}

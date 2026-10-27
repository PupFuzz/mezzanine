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

import { SLEEP_LOOP, frameDocument, internSpeciesOf, sleepDocument } from './creatures.js';
import { draw, seatKey } from './seed.js';

export { seatKey, fnv1a32, draw } from './seed.js';
export {
  SPECIES, SPECIES_KEYS, SPECIES_FIELD, WITHHELD, EYES, MOUTHS, BLUSH, BROWS, SIZES, STOUT, TILTS, ACCENTS, GREENS,
  RIM, RIM_W, SEAT_RECT, SEAT_VIEWBOX, CHIBI_VIEWBOX, CHIBI_HEAD_SHARE, speciesOf, internSpeciesOf, recipe, describe, chibiGeometry, frameDocument,
  SLEEP_LOOP, SLEEP_ZS, Z_LARGE, Z_A0, Z_A1, Z_RATIO, Z_T0, Z_GAP, Z_TOP, Z_GLOW, Z_HALO, Z_HALO_OPACITY,
  CREST_TOP, zLevels, zStand, zLargeFor, sleepDocument,
} from './creatures.js';

/**
 * The character's footprint unit, in floor pixels: the character rect is `CHARACTER_SCALE` (3) times
 * this — 54 x 96 (`floor/desk-layout.js`, FLOOR.md § 12's *Character rect*). The creatures are vector
 * and have no native size; this is the rect's aspect and scale unit, kept from the pixel tree so the
 * box and every rect stay where they are (Appendix B row 19). A seat's body is drawn into 108 x 192 units
 * over it — two per floor pixel (`SEAT_RECT`) — and its frame document is `SEAT_VIEWBOX`, that rect with a
 * margin, drawn into the character's overdraw rect (card#12259; FLOOR.md § 10.4's frame contract).
 */
export const SCENE_W = 18;
export const SCENE_H = 32;

/**
 * THE STANDING FRAME — what a desk with a character draws for every pose but `asleep` (FLOOR.md § 10.4's
 * frame contract), and phase 0 of the walk. `species` is the body: on a floor the seat object's delivered
 * `character.species` (card#12443, D2 § 6.13), which the painter always passes; omitted, the key's own draw —
 * the harness's sheet, which has nothing delivered.
 * @param {string} installId @param {string} seatId @param {string} [species] @returns {string} an SVG document
 */
export function standingFrame(installId, seatId, species) {
  return frameDocument(seatKey(installId, seatId), { phase: 0, species });
}

/**
 * THE WALK — stand, step-left, step-right, front-facing: the walker's frames, and what a held loop
 * steps through, as the painter does today. `species` as `standingFrame()`'s.
 * @param {string} installId @param {string} seatId @param {string} [species] @returns {string[]} three SVG documents
 */
export function walkFrames(installId, seatId, species) {
  const key = seatKey(installId, seatId);
  return [0, 1, 2].map((phase) => frameDocument(key, { phase, species }));
}

/**
 * THE INTERN'S CHIBI FRAME — handed the intern key `seat~<call_id>` in the seat's place
 * (`floor/desk-layout.js`'s `internKey()`): the same recipe re-proportioned, its head at least half
 * the drawn height, for the 20 x 32 rect. `seatSpecies` is the body the intern must not wear — its seat's
 * DELIVERED species (card#12443), never the seat key's own draw; omitted, the seat key's draw (the sheet).
 * @param {string} installId @param {string} internKey @param {string} [seatSpecies] @returns {string} an SVG document
 */
export function chibiFrame(installId, internKey, seatSpecies) {
  const key = seatKey(installId, internKey);
  const species = seatSpecies === undefined ? undefined : internSpeciesOf(key, seatSpecies);
  return frameDocument(key, { chibi: true, species });
}

/**
 * THE SLEEPER — A6 (FLOOR.md § 6.2, § 10.4's frame contract item 4): the seat slumped asleep on its desk with its eyes
 * closed and three z's fixed above its head, lit in turn by a pulse of brightness. `frames` is `SLEEP_LOOP + 1`
 * documents — document 0 the STILL, every z at full brightness, the reduced-motion form and the same for every seat;
 * documents 1.. the loop's frames in order — and `loopFrom` is 1: the held loop is `frames[1 ..]`, which a moving
 * render steps from the seat's own offset (`loopOffset()`). `deskTop` is where the desk's top edge crosses the
 * character's rect, as a share of the rect's height, and `clear` the floor's furniture in frame units — both the
 * floor's to say (`floor/desk-layout.js`), because the frame draws no desk. `species` as `standingFrame()`'s.
 * @param {string} installId @param {string} seatId @param {number} deskTop @param {object[]} clear
 * @param {string} [species]
 * @returns {{frames: string[], loopFrom: number}}
 */
export function sleepFrames(installId, seatId, deskTop, clear, species) {
  const key = seatKey(installId, seatId);
  return { frames: Array.from({ length: SLEEP_LOOP + 1 }, (_, frame) => sleepDocument(key, { frame, deskTop, clear, species })), loopFrom: 1 };
}

/**
 * WHAT A DESK DRAWS FOR A POSE — the tree → painter interface (§ 10.4 item 4): `{ frames, loopFrom }`, `frames[0]` the
 * pose's static form and `frames[loopFrom ..]` the held loop it steps when its render moves — the sleeper's for `asleep`
 * (`loopFrom` 1, its still ahead of the loop); for every other pose the walk, as before, with `loopFrom` 0 (the held
 * loop is the whole walk, frame 0 the static form). The one place a pose becomes a drawing: the painter asks, and
 * decides nothing. `species` is the seat's delivered body (`standingFrame()`'s).
 * @param {string} installId @param {string} seatId
 * @param {{pose: string, deskTop: number, clear: object[], species?: string}} at
 * @returns {{frames: string[], loopFrom: number}}
 */
export function poseFrames(installId, seatId, { pose, deskTop, clear, species }) {
  return pose === 'asleep' ? sleepFrames(installId, seatId, deskTop, clear, species) : { frames: walkFrames(installId, seatId, species), loopFrom: 0 };
}

/**
 * THE SEAT's LOOP OFFSET (FLOOR.md § 6.2's per-seat loop phase rule, § 10.4 item 4; card#12187): the frame a seat's
 * MOVING held loop starts at, of a loop `length` frames long — `draw(seatKey, 'loop-phase') % length`, a pure
 * function of the key, so it is the same on every reload and draws nothing random. `length` is the LOOP's, never the
 * frame list's — `frames.length − loopFrom` (`poseFrames()`). The painter steps every character whose held render
 * moves from it (`floor/painter.js`); frame 0 stays the canonical, reduced-motion form, which no offset reads.
 * @param {string} installId @param {string} seatId @param {number} length @returns {number} in 0..length-1
 */
export function loopOffset(installId, seatId, length) {
  return draw(seatKey(installId, seatId), 'loop-phase') % length;
}

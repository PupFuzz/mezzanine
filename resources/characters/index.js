// The character tree's public surface (docs/design/FLOOR.md § 10.2, § 10.4). Everything the floor
// needs is here; nothing else in this directory should be imported directly by app code.
//
// Two layers under this one: `seed.js`, the seed machinery (first-party), and `creatures.js`, the
// creature generator (first-party, card#11046). This file adds the frame contract's entry points —
// the standing frame, the walk, the intern's chibi frame, the sleeper, the marked poses' pulse, and
// `poseFrames()`, which a desk draws its pose in — and the size the scene reads. No cache: the generator is pure and the PAINTER
// caches the URIs it makes per asset and drawing, and drops an intern's when it stops drawing it
// (`floor/painter.js`).
//
// ⛔ Every entry point returns standalone SVG DOCUMENTS. The painter turns each into a
// SVG `data:` URI (UTF-8, encodeURIComponent) — this tree carries no URI, and no text naming one.

import { EXPRESSIONS, INTERN_LOOP, SLEEP_LOOP, crestOfKey, expressionOf, frameDocument, internSpeciesOf, markerLevel, sleepDocument } from './creatures.js';
import { draw, seatKey } from './seed.js';

export { seatKey, fnv1a32, draw } from './seed.js';
export {
  SPECIES, SPECIES_KEYS, SPECIES_FIELD, WITHHELD, EYES, MOUTHS, BLUSH, BROWS, SIZES, STOUT, TILTS, ACCENTS, GREENS,
  RIM, RIM_W, FOOT, SEAT_RECT, SEAT_VIEWBOX, CHIBI_STOOL, CHIBI_VIEWBOX, CHIBI_HEAD_SHARE, speciesOf, internSpeciesOf, recipe, describe, chibiGeometry, frameDocument,
  EYE_K, BROW_K, MOUTH_K, EXPRESSIONS, expressionOf,
  SLEEP_LOOP, SLEEP_ZS, Z_LARGE, Z_A0, Z_A1, Z_RATIO, Z_T0, Z_GAP, Z_TOP, Z_GLOW, Z_HALO, Z_HALO_OPACITY,
  CREST_TOP, STAND_TOP, standEntry, crestOfKey, MARKER_WEIGHT, MARKER_SPREAD, zLevels, markerLevel, zStand, zLargeFor, sleepDocument,
  LAPTOP_SHELLS, STICKER_SHAPES, STICKER_INKS, STICKER_CONTRAST, INTERN_LOOP, INTERN_SWAY_DEG, INTERN_SWAY_DX, laptopOf, LAPTOP_W, LAPTOP_TOP,
} from './creatures.js';

/**
 * The character's footprint unit, in floor pixels: the character rect is `floor/desk-layout.js`'s
 * `CHARACTER_SCALE` times this (FLOOR.md § 12's *Character rect*). The creatures are vector and have no
 * native size; this is the rect's aspect and scale unit, kept from the pixel tree (Appendix B row 19). A
 * seat's body is drawn into `SEAT_RECT`'s 108 x 192 units over that rect — `desk-layout.js`'s
 * `FRAME_UNITS_PER_PX` per floor pixel — and its frame document is `SEAT_VIEWBOX`, that rect with a margin,
 * drawn into the character's overdraw rect (card#12259; FLOOR.md § 10.4's frame contract).
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
 * (`floor/desk-layout.js`'s `internKey()`): the same recipe re-proportioned and upright, its head at least
 * half the drawn height, for the stool's rect. `seatSpecies` is the body the intern must not wear — its seat's
 * DELIVERED species (card#12443), never the seat key's own draw; omitted, the seat key's draw (the sheet).
 * @param {string} installId @param {string} internKey @param {string} [seatSpecies] @returns {string} an SVG document
 */
export function chibiFrame(installId, internKey, seatSpecies) {
  const key = seatKey(installId, internKey);
  const species = seatSpecies === undefined ? undefined : internSpeciesOf(key, seatSpecies);
  return frameDocument(key, { chibi: true, species });
}

/**
 * THE INTERN's FRAMES (card#12448; FLOOR.md § 10.4's frame contract item 3, § 6.2 A23): the chibi frame with its
 * laptop, as `{ frames, loopFrom }` in `poseFrames()`'s shape — `frames[0]` the STILL (A23's reduced-motion form and
 * its form whenever the row draws no motion: the laptop on the lap, no sway) and `frames[1 ..]` the typing sway,
 * `INTERN_LOOP` frames at the loop rate, which a moving render steps from the intern's own `loopOffset()`.
 * `seatSpecies` is `chibiFrame()`'s: the seat's delivered species, which the intern never wears (card#12443).
 * @param {string} installId @param {string} internKey @param {string} [seatSpecies]
 * @returns {{frames: string[], loopFrom: number}}
 */
export function internFrames(installId, internKey, seatSpecies) {
  const key = seatKey(installId, internKey);
  const species = seatSpecies === undefined ? undefined : internSpeciesOf(key, seatSpecies);
  const frames = [frameDocument(key, { chibi: true, laptop: true, species })];
  for (let i = 0; i < INTERN_LOOP; i++) frames.push(frameDocument(key, { chibi: true, laptop: true, sway: i, species }));
  return { frames, loopFrom: 1 };
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
 * pose's static form and `frames[loopFrom ..]` the held loop it steps when its render moves. `asleep` draws the
 * sleeper's (`loopFrom` 1, its still ahead of the loop). A pose whose EXPRESSION carries a marker (`creatures.js`'s
 * `EXPRESSIONS`, keyed by `expressionOf(pose, glyph)` — the question's `?`, the raised hand's `!`; card#12444) draws its
 * standing frame `SLEEP_LOOP + 1` times: document 0 the STILL, the marker at full brightness, the reduced-motion form,
 * and document n the marker lit at `markerLevel(n − 1)`, the z's pulse — `loopFrom` 1, nothing but the marker's
 * brightness changing. Every other pose draws the walk in its expression, `loopFrom` 0 (the held loop is the whole
 * walk, frame 0 the static form); the walker's own frames (`walkFrames()`) stay neutral. `clear` is the floor's clear
 * rects, which the z's and the marker keep out of. `species` is the seat's delivered body (`standingFrame()`'s). The
 * one place a pose becomes a drawing: the painter asks, and decides nothing.
 * @param {string} installId @param {string} seatId
 * @param {{pose: string, glyph?: string|null, deskTop: number, clear: object[], species?: string}} at the desk's pose and
 *        § 7.1 glyph
 * @returns {{frames: string[], loopFrom: number}}
 */
export function poseFrames(installId, seatId, { pose, glyph = null, deskTop, clear, species }) {
  if (pose === 'asleep') return sleepFrames(installId, seatId, deskTop, clear, species);
  const key = seatKey(installId, seatId), expression = expressionOf(pose, glyph);
  if (EXPRESSIONS[expression].marker) {
    const doc = (level) => frameDocument(key, { expression, level, clear, species });
    return { frames: [doc(3), ...Array.from({ length: SLEEP_LOOP }, (_, n) => doc(markerLevel(n)))], loopFrom: 1 };
  }
  return { frames: [0, 1, 2].map((phase) => frameDocument(key, { phase, expression, species })), loopFrom: 0 };
}

/**
 * WHERE A SEAT's DRAWN HEAD IS (card#12444) — the tree → floor interface for § 5.1's thought trail: the topmost ink of the
 * seat's character in the pose it is drawn in, in frame units from the character rect's top (`creatures.js`'s
 * `crestOfKey()`), for its delivered `species`. The page hands it to the scene, which ends the trail there.
 * @param {string} installId @param {string} seatId @param {{pose?: string, species?: string}} [at] @returns {number}
 */
export function crestTop(installId, seatId, at = {}) {
  return crestOfKey(seatKey(installId, seatId), at);
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

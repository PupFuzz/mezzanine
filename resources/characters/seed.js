// Seat -> character. First-party (not ported): upstream had 15 hand-written recipes for 15
// named show characters; Mezzanine has seats, so the recipe is DERIVED from the seat's identity.
//
// THE RULE (`docs/design/FLOOR.md` section 10.2 and section 3.1): a character is seeded from
// `(install_id, seat_id)` — the same pair that keys the desk — so a seat looks identical on
// every browser and every reload with NOTHING STORED anywhere. No random draw, no server field,
// no localStorage. The appearance IS the identity, computed.

import { SKIN, HAIR_FNS } from './portrait-art.js';

/** @typedef {import('./portrait-art.js').Recipe} Recipe */
/** @typedef {import('./portrait-art.js').RGB} RGB */

const ENC = new TextEncoder();

/**
 * FNV-1a, 32-bit, over the UTF-8 bytes — offset basis 2166136261, prime 16777619.
 *
 * This is `docs/design/FLOOR.md` section 3.2's hash, bit-for-bit, and it is exported because
 * that section's DESK SLOT function needs the same one: the floor (card #7341) must import this
 * rather than mint a second copy that can drift. `tools/design/verify-floor.py` holds a Python
 * implementation for the design doc's own worked table; the two are bound by
 * `tools/characters/selftest.mjs`, which checks this function against the four `h` values that
 * table publishes.
 *
 * Do NOT add an avalanche step here — see `mix32` below, which is a separate function precisely
 * so this one stays the spec's.
 *
 * @param {string} s @returns {number} uint32
 */
export function fnv1a32(s) {
  let h = 2166136261 >>> 0;
  for (const b of ENC.encode(s)) {
    h = (h ^ b) >>> 0;
    h = Math.imul(h, 16777619) >>> 0;
  }
  return h >>> 0;
}

/**
 * The string section 3.2 hashes: `install_id + "/" + seat_id`.
 * @param {string} installId @param {string} seatId @returns {string}
 */
export function seatKey(installId, seatId) {
  if (typeof installId !== 'string' || installId === '') throw new TypeError('install_id must be a non-empty string');
  if (typeof seatId !== 'string' || seatId === '') throw new TypeError('seat_id must be a non-empty string');
  return `${installId}/${seatId}`;
}

/**
 * Murmur3's fmix32 avalanche. Used ONLY on appearance draws, never on the desk-slot hash.
 *
 * Why it exists: every appearance field is drawn as `fnv1a32(seatKey + "#" + field) % n`, so the
 * selection reads the LOW BITS of hashes over inputs differing by a few tail bytes — and FNV-1a
 * gives its last byte exactly one xor and one multiply of mixing. Without a finaliser, `#skin`
 * and `#hair` can correlate across seats and the fleet acquires a house style nobody chose.
 * `tools/characters/selftest.mjs` asserts every bucket of every field is reachable, with a
 * control that reds the same assertion against a constant draw.
 *
 * @param {number} h uint32 @returns {number} uint32
 */
function mix32(h) {
  h = (h ^ (h >>> 16)) >>> 0;
  h = Math.imul(h, 0x85ebca6b) >>> 0;
  h = (h ^ (h >>> 13)) >>> 0;
  h = Math.imul(h, 0xc2b2ae35) >>> 0;
  h = (h ^ (h >>> 16)) >>> 0;
  return h >>> 0;
}

/**
 * One independent draw for one named appearance field.
 *
 * PER-FIELD, deliberately, rather than successive values off one PRNG stream: a stream makes
 * every field's value depend on how many draws precede it, so adding an appearance field later
 * silently re-rolls the face of every seat that already exists. Naming the field instead means a
 * new field is a new name and disturbs nothing.
 *
 * @param {string} key the seat key @param {string} field @returns {number} uint32
 */
export function draw(key, field) {
  return mix32(fnv1a32(`${key}#${field}`));
}

/** @template T @param {string} key @param {string} field @param {readonly T[]} choices @returns {T} */
function pick(key, field, choices) {
  return choices[draw(key, field) % choices.length];
}

/** @param {string} key @param {string} field @param {number} num @param {number} den @returns {boolean} */
function chance(key, field, num, den) {
  return draw(key, field) % den < num;
}

/** @param {string} key @param {string} field @param {number} lo @param {number} hi inclusive @returns {number} */
function range(key, field, lo, hi) {
  return lo + (draw(key, field) % (hi - lo + 1));
}

// --- the palettes a seat is drawn from --------------------------------------
// Every list below is a CLOSED population, and `tools/characters/selftest.mjs` asserts each one
// is fully reachable — a member no seat can ever draw is dead weight that looks like variety.

/** @type {readonly import('./portrait-art.js').SkinTone[]} */
export const SKIN_TONES = /** @type {any} */ (Object.keys(SKIN));

/** @type {readonly import('./portrait-art.js').HairStyle[]} */
export const HAIR_STYLES = /** @type {any} */ (Object.keys(HAIR_FNS));

/**
 * Natural hair colours. These values are GENERALISED FROM UPSTREAM's per-character `hairc`
 * entries — they are tuned against this module's own shading maths (`shades()` at 1.22 / 0.68),
 * which is why they are adopted rather than invented, and `LINEAGE.md` records that.
 * @type {readonly RGB[]}
 */
export const HAIR_COLORS = [
  [28, 22, 18],    // black
  [58, 42, 28],    // darkest brown
  [74, 51, 32],    // dark brown
  [92, 60, 34],    // brown
  [120, 76, 42],   // light brown
  [154, 82, 46],   // auburn
  [168, 104, 52],  // ginger
  [186, 154, 90],  // blond
  [196, 162, 110], // ash blond
  [150, 148, 144], // grey
  [200, 198, 194], // white
];

/**
 * Garment colours. FIRST-PARTY: upstream's `c1` values are the show characters' signature
 * colours and are deliberately NOT carried over — an office palette, not a cast.
 * @type {readonly RGB[]}
 */
export const GARMENT_COLORS = [
  [58, 63, 74],    // slate
  [72, 96, 112],   // steel blue
  [96, 124, 136],  // dusty teal
  [104, 116, 84],  // olive
  [70, 100, 78],   // forest
  [122, 70, 82],   // burgundy
  [160, 116, 60],  // ochre
  [172, 196, 224], // pale blue
  [206, 208, 204], // bone
  [116, 96, 132],  // plum
  [176, 118, 96],  // terracotta
  [88, 92, 116],   // indigo
];

/** @type {readonly RGB[]} */
export const TIE_COLORS = [
  [140, 46, 48], [46, 62, 108], [64, 84, 62], [96, 60, 96], [40, 40, 50], [150, 118, 48],
];

/** @type {readonly import('./portrait-art.js').Cloth[]} */
export const CLOTHS = ['suit', 'dressshirt', 'polo', 'blouse', 'cardigan', 'sweater'];

/** @type {readonly import('./portrait-art.js').Brow[]} */
export const BROWS = ['flat', 'angry', 'raised', 'soft'];

/** @type {readonly import('./portrait-art.js').Mouth[]} */
export const MOUTHS = ['neutral', 'smile', 'frown', 'grin'];

/** @type {readonly import('./portrait-art.js').Facial[]} */
export const FACIALS = ['mustache', 'mustacheSm', 'stubble', 'goatee'];

/**
 * The character for a seat. Pure, total and deterministic: same pair in, byte-identical recipe
 * out, on every engine and every reload.
 *
 * Features are drawn INDEPENDENTLY of one another. There is deliberately no gender model here
 * and no coupling of lashes / blush / garment cut to anything — a seat is an agent, and
 * inventing a gender for it in order to correlate its eyelashes with its cardigan would be
 * inventing a fact about it that nothing in the system holds.
 *
 * @param {string} installId @param {string} seatId @returns {Recipe}
 */
export function characterFor(installId, seatId) {
  const key = seatKey(installId, seatId);
  const hair = pick(key, 'hair', HAIR_STYLES);
  const cloth = pick(key, 'cloth', CLOTHS);

  /** @type {import('./portrait-art.js').HairArgs} */
  const hairargs = {};
  if (hair === 'styleShort') {
    hairargs.part = chance(key, 'part', 1, 2) ? 'L' : 'R';
    hairargs.recede = chance(key, 'recede', 1, 3) ? 1 : 0;
  } else if (hair === 'styleFrame') {
    hairargs.length = range(key, 'hairlen', 15, 20);
    hairargs.vol = range(key, 'hairvol', 1, 2);
  } else if (hair === 'styleMessy') {
    hairargs.length = range(key, 'hairlen', 8, 15);
  } else if (hair === 'styleBald') {
    hairargs.recede = chance(key, 'recede', 1, 2) ? 1 : 0;
  }

  /** @type {Recipe} */
  const r = {
    skin: pick(key, 'skin', SKIN_TONES),
    hairc: pick(key, 'hairc', HAIR_COLORS),
    hair,
    hairargs,
    cloth,
    c1: pick(key, 'c1', GARMENT_COLORS),
    brow: pick(key, 'brow', BROWS),
    mouth: pick(key, 'mouth', MOUTHS),
    blush: chance(key, 'blush', 1, 4),
    lashes: chance(key, 'lashes', 1, 2),
    glasses: chance(key, 'glasses', 1, 3),
    heavy: chance(key, 'heavy', 1, 4),
  };

  // Accent / inner layer: only the two cuts that draw one. `drawClothing` would ignore it
  // elsewhere, and a recipe carrying a field nothing reads is a fact about the seat that is not
  // true of its picture.
  if (cloth === 'polo' || cloth === 'cardigan') r.c2 = pick(key, 'c2', GARMENT_COLORS);
  // Same reasoning for the tie: only the two cuts that draw one, and not on every one of those.
  if ((cloth === 'suit' || cloth === 'dressshirt') && chance(key, 'tied', 2, 3)) r.tie = pick(key, 'tie', TIE_COLORS);
  if (chance(key, 'hasfacial', 2, 5)) r.facial = pick(key, 'facial', FACIALS);

  return r;
}

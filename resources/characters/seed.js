// The seed machinery. First-party, and always was: the character's identity is a pure function of
// its key, and these are the functions that make it one.
//
// THE RULE (`docs/design/FLOOR.md` section 3.1 and section 10.2): a character is seeded from
// `(install_id, seat_id)` — the same pair that keys the desk — so a seat looks identical on
// every browser and every reload with NOTHING STORED anywhere. No random draw, no server field,
// no localStorage. The appearance IS the identity, computed. The creatures draw every field
// through `draw()` (`creatures.js`).

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
 * gives its last byte exactly one xor and one multiply of mixing. Without a finaliser, `#pal`
 * and `#eyes` can correlate across seats and the fleet acquires a house style nobody chose.
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
export function pick(key, field, choices) {
  return choices[draw(key, field) % choices.length];
}

/** @param {string} key @param {string} field @param {number} num @param {number} den @returns {boolean} */
export function chance(key, field, num, den) {
  return draw(key, field) % den < num;
}

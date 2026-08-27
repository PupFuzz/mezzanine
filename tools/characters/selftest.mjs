#!/usr/bin/env node
// Self-test for the procedural character generator (`resources/characters/`).
//
// Node, no dependencies, no network. Run: `node tools/characters/selftest.mjs`
//
// It lives OUTSIDE `resources/characters/` on purpose: every file in that tree owes a
// provenance row (`docs/design/FLOOR.md` section 10.1 Gate 1), and a test fixture is not an
// asset — it has no upstream, no licence to record and no origin to declare. That reason is
// the durable one; the tree's admitted FORMATS have already changed once (the 2026-08-27
// art-direction amendment added `.svg` and `.png`) and are not restated here.
// It imports the tree's public entry point exactly as the app will.
//
// EVERY CHECK HERE CAN FAIL, and section 6 proves it for the one that is easiest to write as a
// decoration: the bucket-coverage assertion is re-run against a deliberately constant draw
// function and is REQUIRED to go red.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
  characterFor, seatKey, fnv1a32, draw,
  portraitBuf, sceneFrames,
  PORTRAIT_W, PORTRAIT_H, SCENE_W, SCENE_H,
} from '../../resources/characters/index.js';
import {
  SKIN_TONES, HAIR_STYLES, HAIR_COLORS, GARMENT_COLORS, TIE_COLORS, CLOTHS, BROWS, MOUTHS, FACIALS,
} from '../../resources/characters/seed.js';

const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

let failures = 0;
/** @param {boolean} cond @param {string} what */
function check(cond, what) {
  if (cond) { console.log(`  ok   ${what}`); } else { console.log(`  FAIL ${what}`); failures++; }
}
/** @param {string} s */
function section(s) { console.log(`\n${s}`); }

// --- 1. the FNV vector, taken from the design doc rather than from memory -----------------
// FLOOR.md section 3.2 publishes four `h` values, each re-derived by tools/design/verify-floor.py
// from the same definition. Parsing them here binds this JS implementation to that Python one:
// if either drifts, a seat's desk and a seat's face stop agreeing about who it is.
section('1. FNV-1a-32 against FLOOR.md section 3.2\'s published table');
const floorMd = readFileSync(join(REPO, 'docs', 'design', 'FLOOR.md'), 'utf8');
const vectors = [...floorMd.matchAll(/^\|\s*`([a-z0-9-]+\/[a-z0-9-]+)`\s*\|\s*(\d+)\s*\|/gm)]
  .map((m) => ({ key: m[1], h: Number(m[2]) }));
check(vectors.length >= 4, `parsed ${vectors.length} published (key, h) pairs — need >= 4, else nothing was measured`);
for (const v of vectors) {
  const got = fnv1a32(v.key);
  check(got === v.h, `fnv1a32(${JSON.stringify(v.key)}) = ${got}, doc says ${v.h}`);
}
check(seatKey('aimla', 'aimla-pm') === 'aimla/aimla-pm', 'seatKey joins with "/" exactly as section 3.2 hashes it');
check(fnv1a32('') === 2166136261, 'the empty string hashes to the offset basis (the definition\'s base case)');

// --- 2. an empty identity is refused, not quietly drawn ------------------------------------
section('2. the identity boundary');
for (const [a, b, why] of [['', 'x', 'empty install_id'], ['x', '', 'empty seat_id'], [null, 'x', 'null install_id']]) {
  let threw = false;
  try { characterFor(/** @type {any} */ (a), /** @type {any} */ (b)); } catch { threw = true; }
  check(threw, `${why} throws rather than producing a character for nobody`);
}

// --- 3. determinism: the whole point of seeding on identity --------------------------------
section('3. determinism and stability');
const A = ['aimla', 'aimla-pm'], B = ['aimla', 'aimla-impl-1'];
check(JSON.stringify(characterFor(...A)) === JSON.stringify(characterFor(...A)), 'same seat -> identical recipe');
check(Buffer.compare(Buffer.from(portraitBuf(...A)), Buffer.from(portraitBuf(...A))) === 0, 'same seat -> identical portrait bytes');
check(Buffer.compare(Buffer.from(portraitBuf(...A)), Buffer.from(portraitBuf(...B))) !== 0, 'different seats -> different portrait bytes');
check(fnv1a32('aimla/aimla-pm') !== fnv1a32('aimla-pm/aimla'), 'the "/" is a real separator, not decoration');

// --- 4. geometry and non-emptiness ---------------------------------------------------------
section('4. buffer geometry');
const pbuf = portraitBuf(...A);
check(pbuf.length === PORTRAIT_W * PORTRAIT_H * 4, `portrait is ${PORTRAIT_W}x${PORTRAIT_H} RGBA (${pbuf.length} B)`);
const frames = sceneFrames(...A);
check(frames.front.length === 3 && frames.back.length === 3, 'three walk phases, front and back');
check(frames.front.every((f) => f.length === SCENE_W * SCENE_H * 4), `scene frames are ${SCENE_W}x${SCENE_H} RGBA`);
check(Buffer.compare(Buffer.from(frames.front[0]), Buffer.from(frames.front[1])) !== 0, 'the walk phases actually differ (a gait, not three copies)');
check(Buffer.compare(Buffer.from(frames.front[0]), Buffer.from(frames.back[0])) !== 0, 'the back view differs from the front');
const opaque = (/** @type {Uint8ClampedArray} */ b) => { let n = 0; for (let i = 3; i < b.length; i += 4) if (b[i] > 0) n++; return n; };
check(opaque(pbuf) > 200, `the portrait draws something (${opaque(pbuf)} non-transparent px of ${PORTRAIT_W * PORTRAIT_H})`);
check(opaque(pbuf) < PORTRAIT_W * PORTRAIT_H, 'and it is not a solid rectangle — there is a silhouette');

// --- 5. every seat in a large synthetic population renders -------------------------------
section('5. a synthetic fleet');
/** @returns {[string,string][]} */
function fleet(n) {
  /** @type {[string,string][]} */ const out = [];
  for (let i = 0; i < n; i++) out.push([`install-${i % 7}`, `seat-${i}`]);
  return out;
}
const POP = fleet(400);
let rendered = 0, distinct = new Set();
for (const [inst, seat] of POP) {
  const b = portraitBuf(inst, seat);
  if (b.length === PORTRAIT_W * PORTRAIT_H * 4 && opaque(b) > 200) rendered++;
  distinct.add(Buffer.from(b).toString('base64'));
}
check(rendered === POP.length, `all ${POP.length} synthetic seats render`);
check(distinct.size > POP.length * 0.9, `${distinct.size}/${POP.length} portraits are visually distinct`);

// --- 6. closed-population coverage, WITH A CONTROL THAT MUST GO RED -----------------------
// A palette member no seat can draw is dead variety. This is the check most easily written as a
// decoration, so it is the one with a discriminating control: the same assertion is re-run over
// a constant draw function and is required to fail.
section('6. every palette member is reachable (+ the control that proves this can fail)');
/** @param {(k:string,f:string)=>number} drawFn */
function coverage(drawFn) {
  /** @type {Record<string, Set<string>>} */
  const seen = { skin: new Set(), hair: new Set(), hairc: new Set(), c1: new Set(), c2: new Set(), tie: new Set(), cloth: new Set(), brow: new Set(), mouth: new Set(), facial: new Set() };
  const fields = {
    skin: SKIN_TONES, hair: HAIR_STYLES, hairc: HAIR_COLORS, c1: GARMENT_COLORS,
    c2: GARMENT_COLORS, tie: TIE_COLORS, cloth: CLOTHS, brow: BROWS, mouth: MOUTHS, facial: FACIALS,
  };
  for (const [inst, seat] of POP) {
    const key = seatKey(inst, seat);
    for (const [field, choices] of Object.entries(fields)) {
      seen[field].add(JSON.stringify(choices[drawFn(key, field) % choices.length]));
    }
  }
  return Object.entries(fields).map(([f, choices]) => [f, seen[f].size, choices.length]);
}
for (const [field, hit, total] of coverage(draw)) {
  check(hit === total, `${field}: ${hit}/${total} members reachable`);
}
const controlGaps = coverage(() => 0).filter(([, hit, total]) => hit !== total);
check(controlGaps.length > 0, `CONTROL — a constant draw leaves ${controlGaps.length} field(s) uncovered, so the assertion above is capable of failing`);

// --- 7. the tree reads no image, and says so in bytes -------------------------------------
// This is a cheap in-language echo of Gate 2; `bin/asset-provenance.py` is the enforcing copy
// and this one exists so a developer running the selftest sees the same verdict.
section('7. no image is loaded anywhere in the tree');
for (const f of ['index.js', 'seed.js', 'portrait-art.js']) {
  const src = readFileSync(join(REPO, 'resources', 'characters', f), 'utf8');
  check(!/data:image\//.test(src), `${f} carries no data:image/ URI`);
  check(!/\b(fetch|XMLHttpRequest|new Image|\.src\s*=)/.test(src), `${f} loads nothing at run time`);
}

console.log(`\n${failures === 0 ? 'ALL CHARACTER SELFTESTS PASS' : `${failures} CHECK(S) FAILED`}`);
process.exit(failures === 0 ? 0 : 1);

#!/usr/bin/env node
// Self-test for the creature generator (`resources/characters/`) — AT-D3-24's node half
// (docs/design/FLOOR.md § 11, card#11046). Node, no dependencies, no network.
// Run: `node tools/characters/selftest.mjs`
//
// It lives OUTSIDE `resources/characters/` on purpose: every file in that tree owes a provenance row
// (FLOOR.md § 10.1 Gate 1), and a test is not an asset. It imports the tree's public entry point
// exactly as the page does. The painter's half of AT-D3-24 is `server/tests/Feature/Floor/painter-probe.mjs`.
//
// EVERY CHECK HERE CAN FAIL. Each leg's planted defect is listed in FLOOR.md's AT-D3-24 and was watched
// failing against this file; the controls in section 9 run on every pass.
//
// The populations are DERIVED ON THE RUN from a seeded generator whose seed is printed, never stored:
// the committed roster (`roster.json`), a synthetic roster of 50 seats, synthetic desks at § 8.1's cap.

import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const TREE = join(REPO, 'resources', 'characters');
const C = await import(pathToFileURL(join(TREE, 'index.js')).href);
const { draw, pick } = await import(pathToFileURL(join(TREE, 'seed.js')).href);
const { wellFormed, vectorDefect, inkBounds } = await import(pathToFileURL(join(REPO, 'tools', 'design', 'svg-document.mjs')).href);

const SEED = 11046;          // the synthetic populations' seed — printed with every measurement
const CAP = 8;               // § 8.1's cap of interns drawn (desk-layout.js's STOOL_CAP), read below
const DESKS = 400;           // synthetic desks at the cap
const FLEET = 50;            // fleet scale (§ 10.4)

let failures = 0;
const check = (cond, what) => { console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${what}`); if (!cond) failures++; };
const section = (s) => console.log(`\n${s}`);
const measure = (s) => console.log(`  MEASURED ${s}`);

// mulberry32 — a seeded generator for synthetic keys, so a run is re-runnable from its printed seed.
function rng(seed) {
  let a = seed >>> 0;
  return () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}
const ULID = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const ulid = (r) => '01J' + Array.from({ length: 23 }, () => ULID[Math.floor(r() * 32)]).join('');
const slug = (r, n) => Array.from({ length: n }, () => 'abcdefghijklmnopqrstuvwxyz'[Math.floor(r() * 26)]).join('');

const deskLayout = readFileSync(join(REPO, 'server', 'public', 'js', 'floor', 'desk-layout.js'), 'utf8');
const capMatch = deskLayout.match(/export const STOOL_CAP = (\d+);/);
check(capMatch !== null && Number(capMatch[1]) === CAP, `§ 8.1's cap read from desk-layout.js's STOOL_CAP (${capMatch?.[1]}) is this run's ${CAP}`);

// ---- the populations ----
const roster = JSON.parse(readFileSync(join(HERE, 'roster.json'), 'utf8')).seats;
const r1 = rng(SEED);
const synthetic = Array.from({ length: FLEET }, (_, i) => [`fleet-${slug(r1, 6)}`, `seat-${i}-${slug(r1, 4)}`]);
const r2 = rng(SEED + 1);
const desks = Array.from({ length: DESKS }, (_, d) => {
  const seat = [`desk-${d}`, `seat-${slug(r2, 6)}`];
  return { seat, interns: Array.from({ length: CAP }, () => `${seat[1]}~${ulid(r2)}`) };
});
console.log(`populations: committed roster ${roster.length} seats; synthetic roster ${FLEET} seats and ${DESKS} desks at the cap of ${CAP}, seed ${SEED}`);

// ---- the one strict reader of a standalone SVG document (N2) — `tools/design/svg-document.mjs` ----

// ---- 1. identity, and totality ----
section('1. identity and totality — every key of every population draws, none throws; the same key, the same document');
const keyOf = ([i, s]) => [i, s];
let drawn = 0, threw = [];
const docs = new Map();
const all = [
  ...roster.map((s) => ['seat', s.install_id, s.seat_id]),
  ...synthetic.map(([i, s]) => ['seat', i, s]),
  ...desks.flatMap(({ seat, interns }) => [['seat', ...seat], ...interns.map((k) => ['intern', seat[0], k])]),
];
for (const [kind, inst, key] of all) {
  try {
    const frames = kind === 'seat' ? [C.standingFrame(inst, key), ...C.walkFrames(inst, key)] : [C.chibiFrame(inst, key)];
    docs.set(`${kind}:${inst}/${key}`, frames);
    drawn++;
  } catch (e) { threw.push(`${inst}/${key}: ${e.message}`); }
}
check(threw.length === 0, `${drawn} keys drew and none threw${threw.length ? ` — threw: ${threw.slice(0, 3).join('; ')}` : ''}`);
const fresh = await import(pathToFileURL(join(TREE, 'index.js')).href + '?fresh');
const twiceBad = all.filter(([kind, inst, key]) => kind === 'seat'
  ? C.standingFrame(inst, key) !== fresh.standingFrame(inst, key)
  : C.chibiFrame(inst, key) !== fresh.chibiFrame(inst, key));
check(twiceBad.length === 0, `every key draws byte-identical markup again, from a fresh module load${twiceBad.length ? ` — differs: ${twiceBad[0].join(' ')}` : ''}`);

// ---- 2. the space is the document's (a guard: FLOOR.md § 10.4's table against the closed lists) ----
section("2. FLOOR.md § 10.4's appearance table equals the generator's closed lists");
const floorMd = readFileSync(join(REPO, 'docs', 'design', 'FLOOR.md'), 'utf8');
const cell = (dim) => {
  const m = floorMd.match(new RegExp(`^\\s*\\| \\*\\*${dim}\\*\\*[^|]*\\| ([^|]+) \\|`, 'm'));
  return m ? m[1] : null;
};
const bold = (s) => [...(s ?? '').matchAll(/\*\*(\d+)(?:–(\d+))?\*\*/g)].map((m) => (m[2] ? [Number(m[1]), Number(m[2])] : Number(m[1])));
const SP = C.SPECIES, KEYS = C.SPECIES_KEYS;
const range = (lists) => [Math.min(...lists.map((l) => l.length)), Math.max(...lists.map((l) => l.length))];
const variantsOf = (sp) => { const seen = new Set(); for (let i = 0; i < 400; i++) seen.add(C.recipe(`v/${sp}/${i}`, sp).variant); return [...seen].filter((v) => v !== null); };
const kinds = (k) => KEYS.filter((s) => SP[s].kind === k).length;
const pals = KEYS.map((s) => SP[s].pals.length);
const common = pals.sort((a, b) => pals.filter((x) => x === b).length - pals.filter((x) => x === a).length)[0];
const expect = {
  species: [[KEYS.length], cell('species')?.match(/(\d+) animal, (\d+) vegetable/)?.slice(1).map(Number), [kinds('animal'), kinds('vegetable')]],
  colourway: [[common, ...new Set(KEYS.filter((s) => SP[s].pals.length !== common).map((s) => SP[s].pals.length))]],
  variant: [[variantsOf('rabbit').length, variantsOf('mushroom').length]],
  girth: [[C.STOUT.length]], size: [[C.SIZES.length]], tilt: [[C.TILTS.length]], eyes: [[C.EYES.length]],
  mouth: [[C.MOUTHS.length]], blush: [[C.BLUSH.length]], brows: [[new Set(C.BROWS).size]],
  hat: [[range(KEYS.map((s) => SP[s].hats))]], neck: [[range(KEYS.map((s) => SP[s].necks))]],
  extra: [[range(KEYS.map((s) => SP[s].extras))]], accents: [[2, C.ACCENTS.length]], side: [[2]],
};
// The species the colourway row names as the exceptions are exactly the ones with fewer colourways.
const fewer = KEYS.filter((s) => SP[s].pals.length !== common).map((s) => SP[s].label).sort();
const named = KEYS.map((s) => SP[s].label).filter((l) => (cell('colourway') ?? '').includes(`the ${l}`)).sort();
check(JSON.stringify(fewer) === JSON.stringify(named), `the colourway row names the species with fewer than ${common} colourways: ${JSON.stringify(named)}, and the generator's are ${JSON.stringify(fewer)}`);
for (const [dim, [want, ...pairs]] of Object.entries(expect)) {
  const got = bold(cell(dim));
  check(JSON.stringify(got) === JSON.stringify(want), `§ 10.4's **${dim}** row states ${JSON.stringify(got)}; the generator gives ${JSON.stringify(want)}`);
  if (pairs.length === 2) {
    check(JSON.stringify(pairs[0]) === JSON.stringify(pairs[1]), `§ 10.4's **${dim}** row's split ${JSON.stringify(pairs[0])} is the generator's ${JSON.stringify(pairs[1])}`);
  }
}

// ---- 3. reach: every member of every list is drawn by some key (+ the control in section 9) ----
section('3. every member of every closed list is drawn by some key of the synthetic population');
function coverage(drawFn) {
  const fields = { [C.SPECIES_FIELD]: KEYS, eyes: C.EYES, mouth: C.MOUTHS, blush: C.BLUSH, brows: C.BROWS, size: C.SIZES, stout: C.STOUT, tilt: C.TILTS, acc1: C.ACCENTS, acc2: C.ACCENTS };
  const seen = Object.fromEntries(Object.keys(fields).map((f) => [f, new Set()]));
  const keys = [...synthetic, ...desks.map((d) => d.seat)].map(([i, s]) => `${i}/${s}`);
  for (let n = 0; n < 2000; n++) keys.push(`reach/${n}`);
  for (const key of keys) for (const [f, list] of Object.entries(fields)) seen[f].add(JSON.stringify(list[drawFn(key, f) % list.length]));
  return Object.entries(fields).map(([f, list]) => [f, seen[f].size, new Set(list.map((x) => JSON.stringify(x))).size]);
}
for (const [f, hit, total] of coverage(draw)) check(hit === total, `${f}: ${hit}/${total} members reached`);

// ---- 4. the salt (FLOOR.md § 10.4; § 14 item 33(2), the seat's ruling of 2026-10-06, option (b)) ----
// Two halves, two populations. THE GUARANTEE reads the committed fleet roster: every room of it no larger
// than the species list draws distinct bodies. THE DISCRIMINATION reads `salt-control.json`, the sample
// the salt was searched over, committed as a CONTROL and never as fleet: over it the salted draw keeps
// every room distinct and the unsalted draw does not, so this leg is known able to tell them apart.
section(`4. the salt: under ${C.SPECIES_FIELD}, every room no larger than the species list draws distinct bodies`);
check(roster.every((s) => typeof s.source === 'string' && s.source.length > 0), `every key of the committed roster states its source (${roster.length} keys)`);
const roomsOf = (keys) => { const m = new Map(); for (const [i, s] of keys) m.set(i, [...(m.get(i) ?? []), s]); return m; };
const repeated = (rooms, fn) => [...rooms].filter(([, seats]) => seats.length <= KEYS.length)
  .filter(([inst, seats]) => new Set(seats.map((s) => fn(`${inst}/${s}`))).size < seats.length).map(([inst]) => inst);
const fleetRooms = roomsOf(roster.map((s) => [s.install_id, s.seat_id]));
const salted = repeated(fleetRooms, (k) => C.speciesOf(k));
check(salted.length === 0, `THE FLEET: every eligible room of the committed roster draws distinct species${salted.length ? ` — repeats in ${salted.join(', ')}` : ''}`);
const control = JSON.parse(readFileSync(join(HERE, 'salt-control.json'), 'utf8'));
check(/CONTROL/.test(control.note ?? ''), 'salt-control.json is labelled a CONTROL, not fleet');
const controlRooms = roomsOf(control.seats.map((k) => k.split('/')));
const controlSalted = repeated(controlRooms, (k) => C.speciesOf(k));
const controlUnsalted = repeated(controlRooms, (k) => pick(k, 'species', KEYS));
check(controlSalted.length === 0, `THE CONTROL: under ${C.SPECIES_FIELD} every eligible room of the search sample draws distinct species${controlSalted.length ? ` — repeats in ${controlSalted.join(', ')}` : ''}`);
check(controlUnsalted.length > 0, controlUnsalted.length > 0
  ? `THE CONTROL: the unsalted draw repeats a body in ${controlUnsalted.join(', ')}, so the leg discriminates a salted draw from an unsalted one`
  : 'THE CONTROL IS TOO SMALL TO DISCRIMINATE: the unsalted draw repeats no body in it either, so a pass above reports nothing about the salt (FLOOR.md § 11 AT-D3-24)');

// ---- 5. collisions, measured (FLOOR.md § 10.4, § 12's *Full-drawing collisions*) ----
section('5. collisions: no two keys draw the same document, each in the frame it is drawn in');
const collisions = (keys) => { const seen = new Map(); let n = 0; for (const [i, s] of keys) { const d = C.standingFrame(i, s); if (seen.has(d)) n++; seen.set(d, s); } return n; };
const rosterCollisions = collisions(roster.map((s) => [s.install_id, s.seat_id]));
const fleetCollisions = collisions(synthetic);
measure(`full-drawing collisions: committed roster ${rosterCollisions} over ${roster.length} seats; synthetic roster ${fleetCollisions} over ${FLEET} seats (seed ${SEED})`);
check(rosterCollisions === 0, `the committed roster: ${rosterCollisions} collisions`);
check(fleetCollisions === 0, `the synthetic ${FLEET}-seat roster: ${fleetCollisions} collisions`);

// ---- 6. the interns ----
section("6. the interns: never the seat's body; no two drawings of a desk alike; the chibi head share");
let sameBody = 0, deskDup = 0, bodyRepeat = 0, bodyColourRepeat = 0;
for (const { seat, interns } of desks) {
  const seatSpecies = C.speciesOf(`${seat[0]}/${seat[1]}`);
  const bodies = interns.map((k) => C.recipe(`${seat[0]}/${k}`));
  sameBody += bodies.filter((r) => r.species === seatSpecies).length;
  const drawings = [C.standingFrame(...seat), ...interns.map((k) => C.chibiFrame(seat[0], k))];
  if (new Set(drawings).size < drawings.length) deskDup++;
  if (new Set(bodies.map((r) => r.species)).size < bodies.length) bodyRepeat++;
  if (new Set(bodies.map((r) => `${r.species}/${r.pal}`)).size < bodies.length) bodyColourRepeat++;
}
check(sameBody === 0, `no intern of ${DESKS * CAP} wears its seat's species (${sameBody} do)`);
check(deskDup === 0, `no desk of ${DESKS} draws two identical documents (${deskDup} do)`);
measure(`sibling repeat rates at the cap of ${CAP} (gate nothing): a repeated body on ${bodyRepeat}/${DESKS} desks (${(bodyRepeat / DESKS).toFixed(3)}), a repeated body and colourway on ${bodyColourRepeat}/${DESKS} (${(bodyColourRepeat / DESKS).toFixed(3)}), seed ${SEED}`);
for (const sp of KEYS) {
  for (const v of [null, ...variantsOf(sp)]) {
    let key = `chibi/${sp}`;
    for (let i = 0; v !== null && C.recipe(key, sp).variant !== v; i++) key = `chibi/${sp}/${i}`;
    const g = C.chibiGeometry(key, sp);
    check(g.share >= C.CHIBI_HEAD_SHARE, `${sp}${v ? ` (${v})` : ''}: the chibi head takes ${g.share.toFixed(3)} of the drawn height (least ${C.CHIBI_HEAD_SHARE})`);
  }
}
const chibiRoot = C.chibiFrame('desk-0', desks[0].interns[0]).match(/^<svg[^>]*>/)[0];
const vb = C.CHIBI_VIEWBOX;
check(chibiRoot.includes(`viewBox="${vb.x} ${vb.y} ${vb.w} ${vb.h}"`) && Math.abs(vb.w / vb.h - 20 / 32) < 1e-9 && vb.y + vb.h >= 188,
  `the chibi frame's viewBox has the 20 x 32 rect's aspect and holds the foot line (${vb.x} ${vb.y} ${vb.w} ${vb.h})`);

// ---- 7. the frames, at parity (the node half; the painter half is painter-probe.mjs) ----
section('7. the frames: a standing frame, three distinct walk phases (phase 0 the standing frame), a chibi frame');
let walkBad = [];
for (const sp of KEYS) {
  const key = `walk/${sp}`;
  const walk = [0, 1, 2].map((phase) => C.frameDocument(key, { species: sp, phase }));
  if (new Set(walk).size !== 3) walkBad.push(sp);
}
check(walkBad.length === 0, `every species draws three distinct walk phases${walkBad.length ? ` — not: ${walkBad.join(', ')}` : ''}`);
const anySeat = roster[0];
check(C.walkFrames(anySeat.install_id, anySeat.seat_id)[0] === C.standingFrame(anySeat.install_id, anySeat.seat_id), "the walk's phase 0 is the standing frame");

// ---- 7b. the sleeper (card#12159; § 10.4's frame contract item 4) — what a pose draws ----
// The painter's half, and the sleeper's own legs over every species, are server/tests/Feature/Floor/sleeper-probe.mjs.
section("7b. a desk's pose frames: { frames, loopFrom } — the sleeper's still and loop for asleep, the walk's for every other pose; every sleeper document is a document");
// The floor's own desk edge and clear rects, from the one function that hands them to the tree (§ 10.4 item 4).
const { sleeperOverdraw, deskRects, FRAME_UNITS_PER_PX, CHARACTER_SCALE } = await import(pathToFileURL(join(REPO, 'server', 'public', 'js', 'floor', 'desk-layout.js')).href);
const { FURNITURE_BOX } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'furniture-box.js')).href);
const { overdraw, clear: CLEAR } = sleeperOverdraw(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
const DESK_TOP = 70 / 96;
// The floor states the tree's frame units per pixel (it cannot import the tree); held equal to the tree's own, and the
// overdraw, in those units from the character rect's top-left, is the sleeper's frame (§ 12's *Sleeper frame*).
const charRect = { w: C.SCENE_W * CHARACTER_SCALE, h: C.SCENE_H * CHARACTER_SCALE };
check(C.SEAT_VIEWBOX.w / charRect.w === FRAME_UNITS_PER_PX && C.SEAT_VIEWBOX.h / charRect.h === FRAME_UNITS_PER_PX,
  `desk-layout.js's FRAME_UNITS_PER_PX (${FRAME_UNITS_PER_PX}) is the tree's frame units per character-rect pixel (${C.SEAT_VIEWBOX.w / charRect.w})`);
const sitter = deskRects(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H }).character;
const odUnits = { x: (overdraw.x - sitter.x) * FRAME_UNITS_PER_PX, y: (overdraw.y - sitter.y) * FRAME_UNITS_PER_PX, w: overdraw.w * FRAME_UNITS_PER_PX, h: overdraw.h * FRAME_UNITS_PER_PX };
check(['x', 'y', 'w', 'h'].every((k) => odUnits[k] === C.SLEEP_VIEWBOX[k]), `the overdraw in frame units (${JSON.stringify(odUnits)}) is SLEEP_VIEWBOX (${JSON.stringify(C.SLEEP_VIEWBOX)})`);
const [si, ss] = [anySeat.install_id, anySeat.seat_id];
const asleep = C.poseFrames(si, ss, { pose: 'asleep', deskTop: DESK_TOP, clear: CLEAR });
const own = C.sleepFrames(si, ss, DESK_TOP, CLEAR);
check(asleep.loopFrom === 1 && asleep.frames.length === 1 + C.SLEEP_LOOP && asleep.frames.join() === own.frames.join() && own.loopFrom === 1,
  `asleep draws the sleeper's still and its ${C.SLEEP_LOOP} loop frames, loopFrom 1 (got ${asleep.frames?.length} documents, loopFrom ${asleep.loopFrom})`);
check(asleep.frames[0] !== C.standingFrame(si, ss) && /data-pose="asleep"/.test(asleep.frames[0]), 'the sleeper is not the standing frame');
// Every pose the desk model names (desk-poses.js's DESK, and A4's think pose, which desk-render.js keeps unexported)
// but the sleeper and the empty chair, which draws no character.
const { DESK } = await import(pathToFileURL(join(REPO, 'server', 'public', 'js', 'desk', 'desk-poses.js')).href);
const otherPoses = [...new Set([...Object.values(DESK).map((d) => d.pose), 'leaning-back'])].filter((p) => p !== 'asleep' && p !== 'empty-chair');
check(otherPoses.every((pose) => { const p = C.poseFrames(si, ss, { pose, deskTop: DESK_TOP, clear: CLEAR }); return p.loopFrom === 0 && p.frames.join() === C.walkFrames(si, ss).join(); }),
  `every other pose (${otherPoses.join(', ')}) draws the walk, as before, { frames, loopFrom: 0 }`);
let edgeRefused = 0;
for (const bad of [undefined, 0, 1, Number.NaN]) { try { C.sleepFrames(si, ss, bad, CLEAR); } catch { edgeRefused++; } }
check(edgeRefused === 4, "a sleeper with no desk edge inside its frame is refused, never drawn against a guessed one");
let clearRefused = 0;
for (const bad of [undefined, [], CLEAR.filter((c) => c.name !== 'props')]) { try { C.sleepFrames(si, ss, DESK_TOP, bad); } catch { clearRefused++; } }
check(clearRefused === 3, "a sleeper handed no clear rects, or none for the desk's props, is refused, never drawn against guessed furniture");
let docRefused = 0;
for (const bad of [-1, C.SLEEP_LOOP + 1, 0.5]) { try { C.sleepDocument('sleep/x', { frame: bad, deskTop: DESK_TOP, clear: CLEAR }); } catch { docRefused++; } }
check(docRefused === 3, `a document outside 0..SLEEP_LOOP (${C.SLEEP_LOOP}) is refused`);
for (const sp of KEYS) docs.set(`sleeper:${sp}`, Array.from({ length: C.SLEEP_LOOP + 1 }, (_, frame) => C.sleepDocument(`sleep/${sp}`, { species: sp, frame, deskTop: DESK_TOP, clear: CLEAR })));

// ---- 8. vector, well-formed, self-contained (N2) ----
section('8. every frame is one well-formed, self-contained SVG document');
let docsChecked = 0;
const docDefects = [];
for (const [k, frames] of docs) {
  for (const d of frames) {
    docsChecked++;
    const bad = wellFormed(d) ?? vectorDefect(d);
    if (bad !== null && docDefects.length < 3) docDefects.push(`${k}: ${bad}`);
  }
}
check(docDefects.length === 0, `${docsChecked} frame documents are well-formed XML with an SVG root and xmlns, and carry no <image>, data: URI or outside reference${docDefects.length ? ` — ${docDefects.join('; ')}` : ''}`);
const sizes = [...docs.values()].flat().map((d) => Buffer.byteLength(encodeURIComponent(d)));
measure(`frame URI size: mean ${Math.round(sizes.reduce((a, b) => a + b, 0) / sizes.length)} B, max ${Math.max(...sizes)} B over ${sizes.length} frames`);
for (const f of ['index.js', 'seed.js', 'creatures.js']) {
  const src = readFileSync(join(TREE, f), 'utf8');
  const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  check(!/data:image\//.test(src) && !/\b(fetch|XMLHttpRequest|new Image|import\(|localStorage|Math\.random|Date\.now|new Date)/.test(code),
    `${f}: no data:image/ text, no request, no clock, no random source, no storage`);
}

// ---- 8b. the frame holds the ink (card#12212; FLOOR.md § 10.4's frame contract) ----
// A frame is shown in a clipping viewport, so ink outside its viewBox is cut off on the floor. What the frame's
// own composition promises is measured here, never assumed: every species' documents — the standing frame, both
// walk phases, the chibi frame and every sleeper document, the sleeper's against its own raised, widened frame
// (SLEEP_VIEWBOX, which the floor draws into the overdraw) — over keys enough to vary tilt, girth, size, side, hat,
// neckwear and extra, read by `inkBounds` (curves sampled, strokes widened, clips honoured). THE GATE binds the
// species drawn after the approved sheet (`added`), which this repository may redraw: each leaves its frame by no
// more than the approved sheet's own species do in the same kind of frame — that envelope is re-derived from the
// generator on every run, never written down. The approved species' own overshoot (the tilt, the walk's lean and
// the sleeper's roll carry wide or tall approved bodies past the frame's side by a few units) predates this card,
// is printed by name, and gates nothing here: redrawing an approved body or changing the frame's transforms is a
// design decision, not a test's.
section("8b. the frame holds the ink: a species drawn after the approved sheet leaves its frame by no more than the approved sheet's own species do");
const BOUNDS_KEYS = 16;
const overshoot = (doc, vb) => { const b = inkBounds(doc); return b === null ? 0 : Math.max(0, vb.x - b.x0, b.x1 - (vb.x + vb.w), vb.y - b.y0, b.y1 - (vb.y + vb.h)); };
const framesOf = (sp, key) => [
  ['standing', C.frameDocument(key, { species: sp, phase: 0 }), C.SEAT_VIEWBOX],
  ['walking', C.frameDocument(key, { species: sp, phase: 1 }), C.SEAT_VIEWBOX],
  ['walking', C.frameDocument(key, { species: sp, phase: 2 }), C.SEAT_VIEWBOX],
  ['chibi', C.frameDocument(`${key}~intern`, { species: sp, chibi: true }), C.CHIBI_VIEWBOX],
  ...Array.from({ length: C.SLEEP_LOOP + 1 }, (_, frame) => ['asleep', C.sleepDocument(key, { species: sp, frame, deskTop: DESK_TOP, clear: CLEAR }), C.SLEEP_VIEWBOX]),
];
const worstOf = (sp) => {
  const w = {};
  for (let i = 0; i < BOUNDS_KEYS; i++) for (const [kind, doc, vb] of framesOf(sp, `bounds/${sp}/${i}`)) {
    const o = overshoot(doc, vb);
    if (!w[kind] || o > w[kind].over) w[kind] = { over: o, key: `bounds/${sp}/${i}` };
  }
  return w;
};
const worst = Object.fromEntries(KEYS.map((sp) => [sp, worstOf(sp)]));
const approvedSpecies = KEYS.filter((sp) => !SP[sp].added), addedSpecies = KEYS.filter((sp) => SP[sp].added);
const FRAME_KINDS = ['standing', 'walking', 'chibi', 'asleep'];
const envelope = Object.fromEntries(FRAME_KINDS.map((kind) => [kind, approvedSpecies.map((sp) => [sp, worst[sp][kind].over]).sort((a, b) => b[1] - a[1])[0]]));
measure(`the approved sheet's own worst overshoot per frame, in units outside the frame (gates nothing; predates card#12212): ${FRAME_KINDS.map((k) => `${k} ${envelope[k][1].toFixed(1)} (${envelope[k][0]})`).join(', ')} — over ${BOUNDS_KEYS} keys per species`);
measure(`every approved species past its frame by more than 2 units: ${approvedSpecies.flatMap((sp) => FRAME_KINDS.filter((k) => worst[sp][k].over > 2).map((k) => `${sp} ${k} ${worst[sp][k].over.toFixed(1)}`)).join(', ') || 'none'}`);
for (const sp of addedSpecies) for (const kind of FRAME_KINDS) {
  const w = worst[sp][kind], e = envelope[kind];
  check(w.over <= e[1] + 0.25, `${sp}, ${kind}: ink past the frame by ${w.over.toFixed(1)} units at most (${w.key}); the approved sheet's worst there is ${e[1].toFixed(1)} (${e[0]})`);
}
// THE CREST TABLE, held by the same reader (§ 10.4 item 4; card#12187's CREST_TOP): for every species and variant, the
// sleeper's topmost ink WITHOUT its z's — the document cut before its `zs` group — lies at or under the crest top the
// tree declares, within 1 unit (the table is stated in whole units, and 1 unit stays inside Z_GAP's 2, the gap the z's
// keep above the crest line). The tool's raster scan holds the seats it picks; this holds every species on every run.
const crestFault = [];
for (const sp of KEYS) for (const v of (variantsOf(sp).length ? variantsOf(sp) : [null])) {
  const name = v === null ? sp : `${sp}/${v}`;
  let top = Infinity, who = '';
  for (let i = 0, n = 0; n < BOUNDS_KEYS && i < 2000; i++) {
    const k = `bounds/${sp}/${i}`;
    if (C.recipe(k, sp).variant !== v) continue;
    n++;
    const d = C.sleepDocument(k, { species: sp, frame: 0, deskTop: DESK_TOP, clear: CLEAR });
    const b = inkBounds(d.slice(0, d.indexOf('<g data-part="zs">')) + '</svg>');
    if (b.y0 < top) { top = b.y0; who = k; }
  }
  if (!(C.CREST_TOP[name] <= top + 1)) crestFault.push(`${name}: CREST_TOP ${C.CREST_TOP[name]} but ${who} draws up to ${top.toFixed(1)}`);
}
check(crestFault.length === 0, `CREST_TOP is at or above every species' and variant's topmost sleeper ink (within 1 unit) over ${BOUNDS_KEYS} keys each${crestFault.length ? ` — ${crestFault.slice(0, 3).join('; ')}` : ''}`);
// the control: the same leg over a frame whose drawing is pushed 30 units sideways reds — so a part drawn past the frame is a thing this leg sees
const pushed = C.frameDocument('bounds/control', { species: addedSpecies[0] ?? KEYS[0] }).replace('<g transform="translate(54 ', '<g transform="translate(84 ');
const pushedOver = overshoot(pushed, C.SEAT_VIEWBOX);
check(pushedOver > envelope.standing[1] + 0.25, `CONTROL — a standing frame pushed 30 units sideways is past its frame by ${pushedOver.toFixed(1)}, beyond the envelope, so the leg can tell a part outside the frame from one inside`);

// ---- 9. the controls — each leg above is known to be able to say both things ----
section('9. controls');
const gaps = coverage(() => 0).filter(([, hit, total]) => hit !== total);
check(gaps.length > 0, `CONTROL — a constant draw leaves ${gaps.length} list(s) unreached, so the reach assertion can fail`);
check(wellFormed('<svg xmlns="http://www.w3.org/2000/svg"><g>a & b</g></svg>') !== null, 'CONTROL — an unescaped "&" is refused');
check(wellFormed('<svg xmlns="http://www.w3.org/2000/svg"><g></svg>') !== null, 'CONTROL — an unclosed <g> is refused');
check(wellFormed('<svg viewBox="0 0 1 1"></svg>') !== null, 'CONTROL — a root with no xmlns is refused');
check(vectorDefect('<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,AA"/></svg>') !== null, 'CONTROL — a raster image inside a frame is refused');
const complex = C.frameDocument('control/complex', { species: 'hedgehog' });
check(wellFormed(complex) === null && vectorDefect(complex) === null && complex.length > 5000, `CONTROL — a genuinely complex creature drawing (${complex.length} B) passes the vector and well-formedness legs`);

console.log(failures === 0 ? '\nALL CREATURE SELFTESTS PASS' : `\n${failures} CREATURE SELFTEST FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);

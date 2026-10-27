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
//
// Since card#12443 a seat's BODY is the species D2 allocates and delivers (`character.species`, FLEET-STATE.md
// § 6.13): the tree draws the species it is handed. Section 4 holds that, and section 6 holds an intern off the
// seat's DELIVERED body. Each synthetic seat and desk is DELIVERED a species apart from its key's own draw, on
// purpose, so a drawing taken from the key is seen.

import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const TREE = join(REPO, 'resources', 'characters');
const C = await import(pathToFileURL(join(TREE, 'index.js')).href);
const { draw } = await import(pathToFileURL(join(TREE, 'seed.js')).href);
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

// ---- 4. the delivered body (card#12443; FLOOR.md § 10.4, D2 § 6.13) ----
// The salt's guarantee — every room of the committed roster draws distinct bodies — retired with card#12443: the server
// allocates the body over the live population, which AT-D2-31 holds, and the salt is only its preference order. What
// the tree owes now is to draw EXACTLY the species it is handed, keep every other field the key's own draw, and refuse
// a species it does not hold, by name — never fall back to the key's body.
section('4. the delivered body: the tree draws the species it is handed, and only the body moves');
check(roster.every((s) => typeof s.source === 'string' && s.source.length > 0), `every key of the committed roster states its source (${roster.length} keys)`);
const literal = readFileSync(join(TREE, 'creatures.js'), 'utf8').match(/^export const SPECIES_KEYS = Object\.freeze\(\[([^\]\n]*)\]\);$/m);
const literalKeys = literal === null ? null : [...literal[1].matchAll(/'([a-z0-9_-]+)'/g)].map((m) => m[1]);
check(JSON.stringify(literalKeys) === JSON.stringify(Object.keys(C.SPECIES)) && JSON.stringify(KEYS) === JSON.stringify(literalKeys),
  `SPECIES_KEYS is one literal line (the server's reader parses it) and equals the drawn species, in order (${literalKeys?.length ?? 'no'} keys)`);
const delivered = (inst, seat) => KEYS[(KEYS.indexOf(C.speciesOf(`${inst}/${seat}`)) + 1) % KEYS.length];
const OWN = ['eyes', 'mouth', 'blush', 'brows', 'size', 'stout', 'tilt', 'acc1', 'acc2'];
let bodyBad = [], ownBad = [];
for (const [i, s] of [...synthetic, ...desks.map((d) => d.seat)]) {
  const sp = delivered(i, s), key = `${i}/${s}`;
  const frames = [C.standingFrame(i, s, sp), ...C.walkFrames(i, s, sp)];
  const want = [C.frameDocument(key, { species: sp, phase: 0 }), ...[0, 1, 2].map((phase) => C.frameDocument(key, { species: sp, phase }))];
  if (frames.join() !== want.join() || frames[0] === C.standingFrame(i, s) || C.describe(key, sp).species !== sp) bodyBad.push(key);
  const forced = C.recipe(key, sp), own = C.recipe(key);
  if (OWN.some((f) => forced[f] !== own[f])) ownBad.push(key);
}
check(bodyBad.length === 0, `every synthetic seat delivered a species apart from its own draw is drawn in that species, standing and walking, and not in its own${bodyBad.length ? ` — not: ${bodyBad.slice(0, 3).join(', ')}` : ''}`);
check(ownBad.length === 0, `forcing the body moves no other field of the key's draw (${OWN.join(', ')})${ownBad.length ? ` — moved: ${ownBad.slice(0, 3).join(', ')}` : ''}`);
const [di, ds] = synthetic[0];
const sleepSp = delivered(di, ds);
const slept = C.poseFrames(di, ds, { pose: 'asleep', deskTop: 70 / 96, clear: [{ name: 'props', x: 0, y: 0, w: 1, h: 1 }], species: sleepSp });
check(slept.frames[0] === C.sleepDocument(`${di}/${ds}`, { species: sleepSp, frame: 0, deskTop: 70 / 96, clear: [{ name: 'props', x: 0, y: 0, w: 1, h: 1 }] }),
  'a sleeping desk is drawn asleep in the delivered species');
let unheld = null;
try { C.standingFrame(di, ds, 'no-such-species'); } catch (e) { unheld = e; }
check(unheld instanceof RangeError && /no-such-species/.test(unheld.message), `a species the tree does not hold is a throw naming it — the painter's F14 — never the key's own body${unheld ? '' : ' (it drew one)'}`);

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
let sameBody = 0, deskDup = 0, chibiBad = 0, bodyRepeat = 0, bodyColourRepeat = 0;
for (const { seat, interns } of desks) {
  // The seat's DELIVERED body (card#12443), apart from its key's own draw: the one an intern must not wear.
  const seatSpecies = delivered(...seat);
  const bodies = interns.map((k) => C.recipe(`${seat[0]}/${k}`, C.internSpeciesOf(`${seat[0]}/${k}`, seatSpecies)));
  sameBody += bodies.filter((r) => r.species === seatSpecies).length;
  const drawings = [C.standingFrame(...seat, seatSpecies), ...interns.map((k) => C.chibiFrame(seat[0], k, seatSpecies))];
  chibiBad += interns.filter((k, n) => drawings[n + 1] !== C.frameDocument(`${seat[0]}/${k}`, { chibi: true, species: bodies[n].species })).length;
  if (new Set(drawings).size < drawings.length) deskDup++;
  if (new Set(bodies.map((r) => r.species)).size < bodies.length) bodyRepeat++;
  if (new Set(bodies.map((r) => `${r.species}/${r.pal}`)).size < bodies.length) bodyColourRepeat++;
}
check(sameBody === 0, `no intern of ${DESKS * CAP} wears its seat's delivered species (${sameBody} do)`);
check(deskDup === 0, `no desk of ${DESKS} draws two identical documents (${deskDup} do)`);
check(chibiBad === 0, `every intern is drawn as its own chibi frame in the body it is kept to beside its seat's delivered one (${chibiBad} are not)`);
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
check(chibiRoot.includes(`viewBox="${vb.x} ${vb.y} ${vb.w} ${vb.h}"`) && vb.y + vb.h >= 188,
  `the chibi frame's viewBox is CHIBI_VIEWBOX and holds the foot line (${vb.x} ${vb.y} ${vb.w} ${vb.h}); § 7b holds it to the intern's overdraw`);

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
section("7b. a desk's pose frames: { frames, loopFrom } — the sleeper's still and loop for asleep, the marker's for a marked pose, the walk in its expression for every other; every sleeper document is a document");
// The floor's own desk edge and clear rects, from the one function that hands them to the tree (§ 10.4 item 4).
const { characterOverdraw, deskRects, stoolSlots, leastBoxHeight, STOOL_ROWS, STOOL_W, STOOL_H, STOOL_GAP, STOOL_ROW_GAP, FRAME_UNITS_PER_PX, CHARACTER_SCALE } = await import(pathToFileURL(join(REPO, 'server', 'public', 'js', 'floor', 'desk-layout.js')).href);
const { FURNITURE_BOX } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'furniture-box.js')).href);
const { overdraw, clear: CLEAR } = characterOverdraw(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
// The floor states the tree's frame units per pixel (it cannot import the tree); held equal to the tree's own over the
// character's RECT, and the overdraw, in those units from the rect's top-left, is the frame every seat document is
// drawn in (§ 12's *Character frame*; card#12187, card#12259).
const charRect = { w: C.SCENE_W * CHARACTER_SCALE, h: C.SCENE_H * CHARACTER_SCALE };
check(C.SEAT_RECT.w / charRect.w === FRAME_UNITS_PER_PX && C.SEAT_RECT.h / charRect.h === FRAME_UNITS_PER_PX,
  `desk-layout.js's FRAME_UNITS_PER_PX (${FRAME_UNITS_PER_PX}) is the tree's frame units per character-rect pixel (${C.SEAT_RECT.w / charRect.w})`);
const rects = deskRects(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
const sitter = rects.character;
// The desk's top edge as a share of the character's rect — what `deskLayout()` hands the tree as `desk_top`, derived the
// same way from the same rects, never a figure.
const DESK_TOP = (rects['desk-sprite'].y - sitter.y) / sitter.h;
const odUnits = { x: (overdraw.x - sitter.x) * FRAME_UNITS_PER_PX, y: (overdraw.y - sitter.y) * FRAME_UNITS_PER_PX, w: overdraw.w * FRAME_UNITS_PER_PX, h: overdraw.h * FRAME_UNITS_PER_PX };
// to 1e-9: the overdraw's px times a non-integer units-per-px is a float product, which the frame's own sum need not equal bit for bit
check(['x', 'y', 'w', 'h'].every((k) => Math.abs(odUnits[k] - C.SEAT_VIEWBOX[k]) < 1e-9), `the character's overdraw in frame units (${JSON.stringify(odUnits)}) is SEAT_VIEWBOX (${JSON.stringify(C.SEAT_VIEWBOX)}), the frame of every seat document`);
const seatVb = `viewBox="${C.SEAT_VIEWBOX.x} ${C.SEAT_VIEWBOX.y} ${C.SEAT_VIEWBOX.w} ${C.SEAT_VIEWBOX.h}"`;
check([C.standingFrame(roster[0].install_id, roster[0].seat_id), ...C.walkFrames(roster[0].install_id, roster[0].seat_id)].every((d) => d.includes(seatVb)),
  `the standing frame and every walk frame are drawn in SEAT_VIEWBOX (${seatVb}), the sleeper's widened, raised frame (card#12259)`);
// The tree states the intern's rect and gap (it cannot import the floor); held equal to the floor's own.
check(C.CHIBI_STOOL.w === STOOL_W && C.CHIBI_STOOL.h === STOOL_H && C.CHIBI_STOOL.gap === STOOL_GAP,
  `the tree's CHIBI_STOOL (${JSON.stringify(C.CHIBI_STOOL)}) is desk-layout.js's STOOL_W x STOOL_H and STOOL_GAP (${STOOL_W} x ${STOOL_H}, ${STOOL_GAP})`);
// The interns: every slot's chibi frame is its rect widened to the row's pitch, in the chibi frame's units (the rect's
// 108 units over its px), from the rect's top-left at the frame's top (CHIBI_VIEWBOX.y) — the same for every slot.
const slots = stoolSlots(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
const slotBad = slots.filter((slot) => {
  const io = slot.overdraw, upp = C.SEAT_RECT.w / slot.w;
  const u = { x: (io.x - slot.x) * upp, y: C.CHIBI_VIEWBOX.y + (io.y - slot.y) * upp, w: io.w * upp, h: io.h * upp };
  return !(['x', 'y', 'w', 'h'].every((k) => Math.abs(u[k] - C.CHIBI_VIEWBOX[k]) < 1e-9) && io.w > slot.w);
});
check(slots.length === CAP && slotBad.length === 0,
  `each of the ${slots.length} interns' overdraws in chibi-frame units is CHIBI_VIEWBOX (${JSON.stringify(C.CHIBI_VIEWBOX)}), wider than its ${slots[0].w} x ${slots[0].h} rect${slotBad.length ? ` — not slot ${slotBad.map((x) => x.index).join(', ')}` : ''}`);
// The slots (card#12444's ruling D): row-major — the front row on the desk's foot line first, left to right — no two
// rects meeting, every one inside the box, its overdraw too, in STOOL_ROWS rows of CAP / STOOL_ROWS.
const meet = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
const inside = (r) => r.x >= 0 && r.y >= 0 && r.x + r.w <= FURNITURE_BOX.width + 1e-9 && r.y + r.h <= FURNITURE_BOX.height;
const order = slots.every((slot, i) => slot.index === i && slot.row === Math.floor(i / (CAP / STOOL_ROWS)) && (i === 0 || (slot.row === slots[i - 1].row ? slot.x > slots[i - 1].x : slot.y < slots[i - 1].y)));
check(order && slots[0].y + slots[0].h === rects['desk-sprite'].y + rects['desk-sprite'].h && new Set(slots.map((x) => x.row)).size === STOOL_ROWS,
  `the slots are row-major in ${STOOL_ROWS} rows of ${CAP / STOOL_ROWS}, the front row first and on the desk's foot line, each row left to right`);
check(slots.every((a, i) => slots.every((b, j) => i === j || !meet(a, b))) && slots.every((x) => inside(x) && inside(x.overdraw)),
  `no two of the ${slots.length} slots meet, and every slot and its overdraw lies inside the ${FURNITURE_BOX.width} x ${FURNITURE_BOX.height} box`);
// THE ROW GAP IS THE LEAST (the operator's 2026-10-27 *"Reduce the spacing between subagents"*): a pixel less and two rows'
// rects would meet — read off the slots themselves, each row lowered by the pixel.
const closer = slots.map((x) => ({ ...x, y: x.y + x.row }));
check(STOOL_ROW_GAP >= 0 && closer.some((a, i) => closer.some((b, j) => i !== j && meet(a, b))),
  `STOOL_ROW_GAP (${STOOL_ROW_GAP}) is the least at which no two interns' rects meet — a pixel less and two rows overlap`);
// The box is derived (card#12444): its height is the least the desk needs for its width (`leastBoxHeight()`'s search
// from below, so a pixel less is a box the layout does not fit).
const least = leastBoxHeight(FURNITURE_BOX.width, { w: C.SCENE_W, h: C.SCENE_H });
check(FURNITURE_BOX.height === least,
  `furniture-box.js's height (${FURNITURE_BOX.height}) is leastBoxHeight() for its width (${least}) — the box is the desk's need, not a guess`);
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
// Every (pose, glyph) the model draws a character for, with the expression each asks the tree for; the marked ones are 7c's.
const posed = [...new Map([...Object.values(DESK), { pose: 'leaning-back', glyph: 'thinking' }]
  .filter((d) => d.pose !== 'asleep' && d.pose !== 'empty-chair').map((d) => [`${d.pose}/${d.glyph}`, d])).values()];
const unmarked = posed.filter((d) => !C.EXPRESSIONS[C.expressionOf(d.pose, d.glyph)].marker);
const walkBadPose = unmarked.filter(({ pose, glyph }) => {
  const p = C.poseFrames(si, ss, { pose, glyph, deskTop: DESK_TOP, clear: CLEAR }), expression = C.expressionOf(pose, glyph);
  const walk = [0, 1, 2].map((phase) => C.frameDocument(C.seatKey(si, ss), { phase, expression }));
  return !(p.loopFrom === 0 && p.frames.join() === walk.join() && (expression !== 'neutral' || p.frames.join() === C.walkFrames(si, ss).join()));
});
check(unmarked.length > 0 && walkBadPose.length === 0,
  `every unmarked pose (${unmarked.map((d) => `${d.pose}/${d.glyph} → ${C.expressionOf(d.pose, d.glyph)}`).join(', ')}) draws the walk in its expression, the neutral one the walk itself, { frames, loopFrom: 0 }${walkBadPose.length ? ` — not: ${walkBadPose.map((d) => d.pose).join(', ')}` : ''}`);
const faces = new Set(unmarked.filter((d) => C.expressionOf(d.pose, d.glyph) !== 'neutral').map((d) => C.poseFrames(si, ss, { pose: d.pose, glyph: d.glyph, deskTop: DESK_TOP, clear: CLEAR }).frames[0]));
check(faces.size === new Set(unmarked.map((d) => C.expressionOf(d.pose, d.glyph)).filter((x) => x !== 'neutral')).size && ![...faces].includes(C.standingFrame(si, ss)),
  `each expressed pose draws its own face, none of them the neutral standing frame (${faces.size} distinct)`);
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

// ---- 7c. the markers (card#12444; FLOOR.md § 10.4's frame contract item 5, § 12's *State marker*) ----
// The operator's ask of 2026-10-26: the question's `?` and the blocked seat's `!` "much bigger, similar to the size of the
// ZZz's", and blinking — "a backglow on and off similar to how the glow travels through Zzzs". Four legs, every species:
// SIZE — the marker's glyph, its outline included and its glow not, is as tall as the same seat's first z asleep (z1, the
// largest of its three), both measured by `inkBounds` off the documents, never a figure; PULSE — a marker pose draws a still and the
// sleeper's loop, the marker lit at z1's level of the same pulse (`zLevels()`), and nothing else changing; ONE GLOW — every
// level's halo is the z's (`Z_HALO`, `Z_HALO_OPACITY` over the same outline), `MARKER_SPREAD` times as wide on the
// operator's 2026-10-27 *"even thicker"* (the glyph's strokes `MARKER_WEIGHT` times a z's, SIZE unchanged); CLEAR — the marker at its brightest keeps a
// unit off the floor's clear rects (the monitor, the props, the desk, the thought trail's column) and inside the frame.
section("7c. the markers: the `?` and the `!` are the z's size, pulse with the z's pulse in the z's glow, and keep clear");
// One element of a document, by its `data-part`, with everything inside it (balanced <g> tags).
const partOf = (doc, name) => {
  const at = doc.indexOf(`<g data-part="${name}"`);
  if (at < 0) return null;
  let depth = 0;
  for (const m of doc.slice(at).matchAll(/<(\/?)g\b[^>]*?(\/?)>/g)) {
    if (m[2]) continue;
    depth += m[1] ? -1 : 1;
    if (depth === 0) return doc.slice(at, at + m.index + m[0].length);
  }
  return null;
};
const alone = (markup) => `<svg xmlns="http://www.w3.org/2000/svg">${markup}</svg>`;
const heightOf = (markup) => { const b = inkBounds(alone(markup)); return b === null ? 0 : b.y1 - b.y0; };
const MARKED = Object.entries(C.EXPRESSIONS).filter(([, x]) => x.marker).map(([name]) => name);
const markerBody = (doc) => { const m = partOf(doc, 'marker'); if (m === null) return null; const g = partOf(m, 'glow'); return g === null ? m : m.replace(g, ''); };
// A seat's first z (z1, the largest it draws asleep): the same key's sleeper still, its `data-k="0"` z's own group.
const z1Of = (key, sp) => C.sleepDocument(key, { species: sp, frame: 0, deskTop: DESK_TOP, clear: CLEAR }).match(/<g data-part="z" data-k="0"[^>]*>(?:<path [^>]*\/>)+<\/g>/)?.[0] ?? '';
const keyOfVariant = (prefix, sp, v) => { let key = `${prefix}/${sp}`; for (let i = 0; v !== null && C.recipe(key, sp).variant !== v; i++) key = `${prefix}/${sp}/${i}`; return key; };
const sizeBad = [], sizes7c = [];
for (const sp of KEYS) for (const v of [null, ...variantsOf(sp)]) {
  const key = keyOfVariant('marker', sp, v), z1 = heightOf(z1Of(key, sp));
  for (const expression of MARKED) {
    const body = markerBody(C.frameDocument(key, { species: sp, expression, clear: CLEAR }));
    const h = body === null ? 0 : heightOf(body);
    sizes7c.push([h, z1]);
    if (!(z1 > 0 && Math.abs(h - z1) <= 0.5)) sizeBad.push(`${sp}${v ? `/${v}` : ''} ${expression} ${h.toFixed(1)} for a z1 of ${z1.toFixed(1)}`);
  }
}
const span = (i) => `${Math.min(...sizes7c.map((x) => x[i])).toFixed(1)} to ${Math.max(...sizes7c.map((x) => x[i])).toFixed(1)}`;
measure(`marker and z1 heights, outline included, glow not, over every species and variant: the markers ${span(0)} units, the same keys' z1 asleep ${span(1)}`);
check(MARKED.length > 0 && sizeBad.length === 0, `SIZE: every species' ${MARKED.join(' and ')} marker is as tall as the same seat's first z asleep, within half a unit${sizeBad.length ? ` — not: ${sizeBad.slice(0, 4).join('; ')}` : ''}`);
// The pulse, through the tree → painter interface, on a committed seat, for every pose whose expression carries a marker.
const levelOf = (doc) => Number(partOf(doc, 'marker')?.match(/^<g data-part="marker"[^>]*data-level="(\d)"/)?.[1] ?? NaN);
const markedPoses = otherPoses.filter((pose) => MARKED.includes(C.expressionOf(pose, pose === 'present' ? 'question' : null)));
const pulseBad = [];
for (const pose of markedPoses) {
  const glyph = pose === 'present' ? 'question' : null;
  const p = C.poseFrames(si, ss, { pose, glyph, deskTop: DESK_TOP, clear: CLEAR });
  const want = [3, ...Array.from({ length: C.SLEEP_LOOP }, (_, n) => C.zLevels(n)[0])];
  const got = (p.frames ?? []).map(levelOf);
  if (p.loopFrom !== 1 || JSON.stringify(got) !== JSON.stringify(want)) pulseBad.push(`${pose}: loopFrom ${p.loopFrom}, marker levels ${JSON.stringify(got)} for ${JSON.stringify(want)}`);
  const unmarked = new Set((p.frames ?? []).map((d) => { const m = partOf(d, 'marker'); return m === null ? d : d.replace(m, ''); }));
  if (unmarked.size !== 1) pulseBad.push(`${pose}: ${unmarked.size} distinct drawings under the marker — only the marker's brightness may change`);
}
check(markedPoses.length > 0 && pulseBad.length === 0, `PULSE: ${markedPoses.join(', ')} draw a still with the marker at full brightness (the reduced-motion form) and the sleeper's ${C.SLEEP_LOOP}-frame loop with the marker lit as z1 is, loopFrom 1, nothing else changing${pulseBad.length ? ` — ${pulseBad.slice(0, 2).join('; ')}` : ''}`);
// One glow: every level's halo over the marker's own outline, read off the drawn attributes.
const glowBad = [];
for (const expression of MARKED) for (const level of [0, 1, 2, 3]) {
  const m = partOf(C.frameDocument('marker/glow', { species: KEYS[0], expression, level, clear: CLEAR }), 'marker') ?? '';
  // the glyph's first stroke is its dark outline (the z's `zSvg()` draws its outline first the same way)
  const outline = Number((m.match(/<g data-part="glyph"[^>]*><path [^>]*stroke-width="([\d.]+)"/) ?? [])[1]);
  const halos = [...m.matchAll(/<path data-part="halo"[^>]*stroke-width="([\d.]+)"[^>]*opacity="([\d.]+)"/g)];
  if (!Number.isFinite(outline)) { glowBad.push(`${expression} level ${level}: no glyph outline drawn`); continue; }
  if (level === 0 ? halos.length !== 0 : halos.length === 0) { glowBad.push(`${expression} level ${level}: ${halos.length} halos`); continue; }
  for (const [, w, op] of halos) {
    if (Math.abs(Number(w) - (outline + 2 * C.MARKER_SPREAD * C.Z_HALO[level])) > 0.02 || Math.abs(Number(op) - C.Z_HALO_OPACITY[level]) > 0.005) glowBad.push(`${expression} level ${level}: halo ${w} at ${op} over an outline of ${outline}`);
  }
}
check(glowBad.length === 0, `ONE GLOW: every marker level's halo is the z's, MARKER_SPREAD (${C.MARKER_SPREAD}) times as wide — none at level 0, else MARKER_SPREAD x Z_HALO[level] wider than the outline each side at Z_HALO_OPACITY[level]${glowBad.length ? ` — ${glowBad.slice(0, 3).join('; ')}` : ''}`);
// Clear of the furniture and the trail, and inside the frame, at the brightest glow, for every species and variant.
const clearBad = [];
for (const sp of KEYS) for (const expression of MARKED) for (const v of [null, ...variantsOf(sp)]) {
  const key = keyOfVariant('marker', sp, v);
  const m = partOf(C.frameDocument(key, { species: sp, expression, level: 3, clear: CLEAR }), 'marker');
  const b = m === null ? null : inkBounds(alone(m));
  if (b === null) { clearBad.push(`${sp} ${expression}: no marker drawn`); continue; }
  // `e`: the stand places the glow exactly a unit in, so a float's last bit is not a unit's breach
  const V = C.SEAT_VIEWBOX, e = 1e-6;
  if (b.x0 < V.x + 1 - e || b.x1 > V.x + V.w - 1 + e || b.y0 < V.y + 1 - e) clearBad.push(`${sp} ${expression}: past the frame's edge, ${JSON.stringify(b)}`);
  for (const c of CLEAR) if (b.x1 > c.x - 1 + e && b.x0 < c.x + c.w + 1 - e && b.y1 > c.y - 1 + e && b.y0 < c.y + c.h + 1 - e) clearBad.push(`${sp}${v ? `/${v}` : ''} ${expression}: meets the ${c.name}`);
}
check(clearBad.length === 0, `CLEAR: every species' marker, glow included, keeps a unit inside the frame and off the ${CLEAR.map((c) => c.name).join(', ')}${clearBad.length ? ` — ${clearBad.slice(0, 3).join('; ')}` : ''}`);

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

// ---- 8b. the frame holds the ink (card#12212, card#12259; FLOOR.md § 10.4's frame contract) ----
// A frame is shown in a clipping viewport, so ink outside its viewBox is cut off on the floor. What the frame's own
// composition promises is measured here, never assumed: for every species and variant, the standing frame, both walk
// phases, the chibi frame and every sleeper document, each against ITS OWN viewBox — the seat frame (SEAT_VIEWBOX,
// which the floor draws into the character's overdraw) and the chibi frame (CHIBI_VIEWBOX, the intern's overdraw) —
// read by `inkBounds` (curves sampled, strokes widened, clips honoured). THE GATE is ZERO ink past any side, for
// EVERY species: card#12259 drew every seat document in the sleeper's widened, raised frame, and the chibi frame to
// the stool's pitch, on the operator's ruling of 2026-10-23 (§ 14 item 33(6)), after the measurement below showed that
// no change of the tilt's pivot reaches zero — the widest girth at the largest size carries the lop rabbit's ear and
// the wide mushroom's cap past the character's own rect with no tilt at all.
// THE POPULATION is derived on the run: BOUNDS_KEYS keys per species, and WIDE_KEYS more drawn at the composition's
// widest — the largest size, the widest girth and a non-zero tilt, read off the recipe's own lists — because that is
// where the tilt and the lean carry the widest bodies furthest.
// THE MEASUREMENT beside the gate: how far each frame's ink reaches past the character's own RECT (SEAT_RECT — the
// frame every seat document was drawn in before card#12259, and the edge the floor would cut at without the margin),
// per frame kind and by name where it is more than 0, so the margin the frame actually needs is re-derived on every
// run and never written down; and the least margin left on any side of the frame, so a body creeping toward the edge
// is seen before it reaches it. The sleeper has always been drawn in the widened frame, so its rect IS its frame.
section("8b. the frame holds the ink: no species' ink leaves its frame on any side");
const BOUNDS_KEYS = 16, WIDE_KEYS = 8;
const widest = { size: Math.max(...C.SIZES), stout: Math.max(...C.STOUT), tilt: Math.max(...C.TILTS.map(Math.abs)) };
const keysOf = (sp) => {
  const keys = Array.from({ length: BOUNDS_KEYS }, (_, i) => `bounds/${sp}/${i}`);
  for (let i = 0, n = 0; n < WIDE_KEYS && i < 6000; i++) {
    const k = `wide/${sp}/${i}`, r = C.recipe(k, sp);
    if (r.size === widest.size && r.stout === widest.stout && Math.abs(r.tilt) === widest.tilt) { keys.push(k); n++; }
  }
  return keys;
};
const CHIBI_RECT = { x: 0, y: C.CHIBI_VIEWBOX.y, w: C.SEAT_RECT.w, h: C.CHIBI_VIEWBOX.h };
const past = (b, box) => b === null ? -Infinity : Math.max(box.x - b.x0, b.x1 - (box.x + box.w), box.y - b.y0, b.y1 - (box.y + box.h));
// the margin sides: left, right and top — the bottom is the foot line, which the shadow sits on by construction
const pastMargin = (b, box) => b === null ? -Infinity : Math.max(box.x - b.x0, b.x1 - (box.x + box.w), box.y - b.y0);
const framesOf = (sp, key) => [
  ['standing', C.frameDocument(key, { species: sp, phase: 0 }), C.SEAT_VIEWBOX, C.SEAT_RECT],
  ['walking', C.frameDocument(key, { species: sp, phase: 1 }), C.SEAT_VIEWBOX, C.SEAT_RECT],
  ['walking', C.frameDocument(key, { species: sp, phase: 2 }), C.SEAT_VIEWBOX, C.SEAT_RECT],
  ['chibi', C.frameDocument(`${key}~intern`, { species: sp, chibi: true }), C.CHIBI_VIEWBOX, CHIBI_RECT],
  ...Array.from({ length: C.SLEEP_LOOP + 1 }, (_, frame) => ['asleep', C.sleepDocument(key, { species: sp, frame, deskTop: DESK_TOP, clear: CLEAR }), C.SEAT_VIEWBOX, C.SEAT_VIEWBOX]),
  // every expression's standing frame — its paws, and its marker at full brightness, the widest glow (card#12444)
  ...Object.keys(C.EXPRESSIONS).filter((x) => x !== 'neutral').map((expression) => ['posed', C.frameDocument(key, { species: sp, expression, clear: CLEAR }), C.SEAT_VIEWBOX, C.SEAT_RECT]),
];
const FRAME_KINDS = ['standing', 'walking', 'chibi', 'asleep', 'posed'];
const label = (sp, key) => { const v = C.recipe(key, sp).variant; return v ? `${sp}/${v}` : sp; };
const worst = {};   // species → kind → { over: past the frame, cut: past the rect, key }
const chibiAcross = { units: -Infinity, who: '' };   // the chibi's ink past its rect's sides, the widest over the population
let boundsDocs = 0;
for (const sp of KEYS) {
  const w = worst[sp] = {};
  for (const key of keysOf(sp)) for (const [kind, doc, vb, rect] of framesOf(sp, key)) {
    boundsDocs++;
    const b = inkBounds(doc), over = past(b, vb), cut = past(b, rect);
    const e = w[kind] ??= { over: -Infinity, cut: -Infinity, side: -Infinity, key, cutKey: key, sideKey: key };
    if (over > e.over) { e.over = over; e.key = key; }
    if (cut > e.cut) { e.cut = cut; e.cutKey = key; }
    const side = pastMargin(b, vb);
    if (side > e.side) { e.side = side; e.sideKey = key; }
    if (kind === 'chibi' && b !== null) {
      const across = Math.max(rect.x - b.x0, b.x1 - (rect.x + rect.w));
      if (across > chibiAcross.units) Object.assign(chibiAcross, { units: across, who: `${label(sp, key)} ${key}` });
    }
  }
}
const worstBy = (kind, f) => KEYS.map((sp) => [sp, worst[sp][kind]]).sort((x, y) => f(y[1]) - f(x[1]))[0];
measure(`what the character's own rect would cut, per frame kind — the frame before card#12259, gates nothing — in units past it: ${FRAME_KINDS.filter((k) => k !== 'asleep' && k !== 'posed').map((k) => { const [sp, e] = worstBy(k, (e) => e.cut); return `${k} ${e.cut.toFixed(1)} (${label(sp, e.cutKey)}, ${e.cutKey})`; }).join(', ')} — over ${BOUNDS_KEYS} keys per species and ${WIDE_KEYS} at size ${widest.size}, girth ${widest.stout}, tilt ±${widest.tilt}`);
measure(`every species past its rect: ${KEYS.flatMap((sp) => FRAME_KINDS.filter((k) => k !== 'asleep' && k !== 'posed' && worst[sp][k].cut > 0).map((k) => `${label(sp, worst[sp][k].cutKey)} ${k} ${worst[sp][k].cut.toFixed(1)}`)).join(', ') || 'none'}`);
measure(`least margin left at the frame's sides and top, per frame kind: ${FRAME_KINDS.map((k) => { const [sp, e] = worstBy(k, (e) => e.side); return `${k} ${(-e.side).toFixed(1)} (${label(sp, e.sideKey)}, ${e.sideKey})`; }).join(', ')} — over ${boundsDocs} documents`);
for (const kind of FRAME_KINDS) {
  const out = KEYS.filter((sp) => worst[sp][kind].over > 0).map((sp) => `${label(sp, worst[sp][kind].key)} ${worst[sp][kind].over.toFixed(1)} (${worst[sp][kind].key})`);
  check(out.length === 0, `${kind}: no species' ink leaves its frame on any side${out.length ? ` — past it: ${out.join(', ')}` : ''}`);
}
// THE INTERNS' GAP IS DERIVED HERE (card#12444, the operator's 2026-10-27 *"Reduce the spacing between subagents"*): the
// fewest whole floor pixels whose half, each side of the intern's rect, holds the widest chibi ink past the rect's sides
// measured above — in floor pixels, the chibi frame's 108 units over the rect's STOOL_W. Any fewer and a head is clipped
// at the pitch; any more is spacing the operator asked to reclaim.
const leastGap = Math.ceil(2 * Math.max(0, chibiAcross.units) * STOOL_W / C.SEAT_RECT.w);
measure(`the chibi's ink past its rect's sides, the widest: ${chibiAcross.units.toFixed(2)} units (${chibiAcross.who}), ${(chibiAcross.units * STOOL_W / C.SEAT_RECT.w).toFixed(2)} px — the least whole-pixel gap between two interns ${leastGap}`);
check(Number.isFinite(chibiAcross.units) && STOOL_GAP === leastGap,
  `desk-layout.js's STOOL_GAP (${STOOL_GAP}) is the least whole-pixel gap whose half each side holds the widest chibi past its rect (${leastGap})`);
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
// the control: the same leg over a frame whose drawing is pushed sideways by more than the frame's margin reds — so a
// part drawn past the frame is a thing this leg sees. The push is the margin plus a third of the rect, read off the
// constants, never a figure.
const PUSH = (C.SEAT_RECT.x - C.SEAT_VIEWBOX.x) + C.SEAT_RECT.w / 3;
const pushed = C.frameDocument('bounds/control', { species: KEYS[0] }).replace('<g transform="translate(54 ', `<g transform="translate(${54 + PUSH} `);
const pushedOver = past(inkBounds(pushed), C.SEAT_VIEWBOX);
check(pushedOver > 0, `CONTROL — a standing frame pushed ${PUSH} units sideways is past its frame by ${pushedOver.toFixed(1)}, so the leg can tell a part outside the frame from one inside`);
const unpushedOver = past(inkBounds(C.frameDocument('bounds/control', { species: KEYS[0] })), C.SEAT_VIEWBOX);
check(unpushedOver <= 0, `CONTROL — the same frame unpushed is inside (${unpushedOver.toFixed(1)}), so the push alone made the difference`);

// ---- 9. the controls — each leg above is known to be able to say both things ----
section('9. controls');
const gaps = coverage(() => 0).filter(([, hit, total]) => hit !== total);
// The interns leg's control (card#12443): kept off the seat key's OWN draw rather than its delivered body, some intern
// wears the body its seat was allocated — so the leg above, reading the delivered body, is one that can fail.
const keyedOff = desks.reduce((n, { seat, interns }) => n + interns.filter((k) => C.speciesOf(`${seat[0]}/${k}`) === delivered(...seat)).length, 0);
check(keyedOff > 0, `CONTROL — interns kept off the seat key's own draw wear the seat's delivered body ${keyedOff} time(s), so section 6 discriminates`);
check(gaps.length > 0, `CONTROL — a constant draw leaves ${gaps.length} list(s) unreached, so the reach assertion can fail`);
check(wellFormed('<svg xmlns="http://www.w3.org/2000/svg"><g>a & b</g></svg>') !== null, 'CONTROL — an unescaped "&" is refused');
check(wellFormed('<svg xmlns="http://www.w3.org/2000/svg"><g></svg>') !== null, 'CONTROL — an unclosed <g> is refused');
check(wellFormed('<svg viewBox="0 0 1 1"></svg>') !== null, 'CONTROL — a root with no xmlns is refused');
check(vectorDefect('<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,AA"/></svg>') !== null, 'CONTROL — a raster image inside a frame is refused');
const complex = C.frameDocument('control/complex', { species: 'hedgehog' });
check(wellFormed(complex) === null && vectorDefect(complex) === null && complex.length > 5000, `CONTROL — a genuinely complex creature drawing (${complex.length} B) passes the vector and well-formedness legs`);

console.log(failures === 0 ? '\nALL CREATURE SELFTESTS PASS' : `\n${failures} CREATURE SELFTEST FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);

#!/usr/bin/env node
// THE SLEEPER's Z's — ABOVE ITS HEAD, FIXED AND LIT IN TURN — MEASURED ON A RENDER (card#12187; `docs/design/FLOOR.md`
// § 10.4's frame contract item 4 and § 12's *Sleeper* rows: z's, frame, clearance, brightness, pulse, overlap bound, z-on-z
// bound, face clearance).
//
// For every species and variant of the character tree — at its first `--keys` keys AND at the extremes the population holds
// (the lowest and highest eye line over `--scan-eyes` keys, the widest crest over `--scan-crest`; see `pickSeats()`) — the
// sleeper's frame is rasterised in headless Chromium, once without its z's, once per z body alone, once for the eyes alone and
// once for the halos, in the sleeper's WIDENED, RAISED frame (§ 12's *Sleeper frame*, read from the document), and six things
// are read: OVER HEAD, the z ink on the sleeper's own ink; Z ON Z, the z ink covered by two z's; THE FACE, the z ink near the
// closed eyes; CLEARANCE, the z ink inside a clear rect (the monitor's frame, the desk's props, the desk, the thought
// trail's column — read out of `floor/desk-layout.js`, never copied) and the halo ink on the sleeper; and, from the documents
// and not a raster, THE CHASE (three z's, the same places in every frame, the still at full brightness, the loop's levels
// § 10.4's table, the brightest z advancing z1, z2, z3, a halo on every lit level in the palette's lamp colour) and THE CREST
// TABLE (`creatures.js`'s `CREST_TOP` at or under every scanned seat's topmost ink). Every bound is read from FLOOR.md, never
// copied here.
//
//   node tools/design/sleeper-zs.browser.mjs                       # judge the tree's own frames against § 12
//   node tools/design/sleeper-zs.browser.mjs --layout above3       # the ruled layout laid on the tree's sleepers (before the build, or against any tree)
//   node tools/design/sleeper-zs.browser.mjs --layout <name>       # …or any other LAYOUTS / FIXED entry: ruled, card12159, r1-column, diagonal, beside, on-face, above3-reversed, above3-constant
//   node tools/design/sleeper-zs.browser.mjs --selftest            # …and require every control to red the bound it exists to (below)
//   node tools/design/sleeper-zs.browser.mjs --tree <dir>          # judge another tree
//   node tools/design/sleeper-zs.browser.mjs --keys 3 --phases 16  # first keys per species and variant (3); phases per RISING layout loop (16; a fixed layout is judged on its still)
//   …and --scan-eyes 400 --scan-crest 100: the keys scanned per species and variant for the EXTREMES the run adds, and for the crest table
//   --shot <png> [--shot-px 108]    # the sheet: each species' worst seat, the still; 49 draws a frame unit at the floor's fit size on a 1,440 window
//   --strip <png> [--strip-species fox,frog,rabbit]   # every document a seat draws, the still then the loop, in a row per species
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// ⛔ CANDIDATES AND CONTROLS ARE PLANTED BY SEMANTICS, NOT BY SOURCE EDITS. A `--layout` run keeps the tree's sleeper — its
// body, eye line and roll — and REPLACES its z's with the ones a layout places, drawn by `zMarkup()` / `z3Markup()` /
// `glowMarkup()`, which mirror `creatures.js`'s drawing; the mirror is held on every run (each z the tree drew is re-drawn from
// its own data attributes and must come out the same drawing, every number within the attributes' rounding — `MIRROR DRIFT`
// otherwise). So every figure § 10.4 and § 12 rest on is re-printed against any tree, before or after the build, and the
// selftest's controls — round 3's two-z column (`ruled`), card#12159's rising path, z's over the eyes, and the pulse reversed or
// constant — are planted whatever the tree's own code says.
//
// ⛔ WHAT IS MEASURED IS INK, NOT BOXES — and what it cannot see is whether the result LOOKS right: one figure covers a smooth cap
// edge and a leaf's tip alike. The figure bounds the worst case; the sheet and the strip are the last word. ⛔ NO BROWSER IS A
// FAILURE, NEVER A SKIP, AND THIS IS NOT WIRED IN CI (a stock runner carries no Chromium — `tools/design/README.md` names the
// browser tools and why).

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const args = process.argv.slice(2);
const arg = (name, fallback) => (args.includes(name) ? args[args.indexOf(name) + 1] : fallback);
const TREE = arg('--tree', join(ROOT, 'resources', 'characters'));
const KEYS_PER = Number(arg('--keys', 3));
const PHASES = Number(arg('--phases', 16));
// The loop draws its frames at phase f / 8 + 0.5 (§ 10.4 item 4); with two z's the set of z places that draws over a
// loop is the set phases j / 8 draw, so a layout's phases include the loop's own frames only when 8 divides them —
// which the fully-opaque figure (`opaqueLarge`, read at every PHASES / 8-th phase) rests on.
if (!(Number.isInteger(PHASES) && PHASES > 0 && PHASES % 8 === 0)) {
  console.error(`FAIL — --phases must be a positive multiple of 8 (the loop's own frames), got ${PHASES}`);
  process.exit(1);
}
/** Keys scanned per species and variant for the extremes of eye line (cheap: no raster) and of crest width (one raster each). */
const SCAN_EYES = Number(arg('--scan-eyes', 400));
const SCAN_CREST = Number(arg('--scan-crest', 100));
const LAYOUT = arg('--layout', null);
const SHOT = arg('--shot', null);
/** The sheet's cell width in CSS px: 108 draws a frame unit per px; 49 is the floor's fit at a 1,440 × 900 window (0.90 × 54). */
const SHOT_PX = Number(arg('--shot-px', 108));
const SELFTEST = args.includes('--selftest');
const STRIP = arg('--strip', null);
const STRIP_SPECIES = arg('--strip-species', 'fox,frog,rabbit').split(',');
/** The z's two colours, read out of the tree's own source — the dark outline and the pale core at full brightness. */
const zColours = readFileSync(join(TREE, 'creatures.js'), 'utf8').match(/const Z_INK = '(#[0-9a-fA-F]{6})', Z_FILL = '(#[0-9a-fA-F]{6})'/);
if (zColours === null) { console.error('FAIL — creatures.js declares no `const Z_INK = …, Z_FILL = …` line this tool can read'); process.exit(1); }
const Z_INK_TOOL = zColours[1], Z_FILL_TOOL = zColours[2];
/** `--lamp` as the stylesheet declares it, and the tree's own copy of it, which must be equal (a halo outside the palette is a defect, not a choice). */
const cssLamp = readFileSync(join(ROOT, 'server', 'public', 'css', 'mezzanine.css'), 'utf8').match(/--lamp:\s*(#[0-9a-fA-F]{6})/)?.[1] ?? null;
const treeLamp = readFileSync(join(TREE, 'creatures.js'), 'utf8').match(/const Z_LAMP = '(#[0-9a-fA-F]{6})'/)?.[1] ?? null;
if (cssLamp === null) { console.error('FAIL — mezzanine.css declares no --lamp'); process.exit(1); }
/** The desk's top edge as the floor hands it: `deskRects()`'s character rect is 96 tall with the desk's top 70 down it. */
const DESK_TOP = 70 / 96;
/** Raster px per frame unit (the sleeper's frame is 216 × 234 units; one px a unit keeps a run to a few minutes). */
const SCALE = 1;
/** A pixel is ink when its alpha is over this — so a z faded under about 0.16 opacity draws no ink at all. */
const ALPHA = 40;

/**
 * § 12's bounds, read from the document — never a copy here: the over-head bound held PER SPECIES, with its RESIDUALS —
 * each a species named in the row's first cell with a bound of its own (`the \`mushroom\` at **60 %**`), the pattern
 * `lobby-directory.browser.mjs`'s `RESIDUALS` uses: a residual is declared by name and capped, never a wider global
 * bound — and the z-on-z and face bounds.
 */
function boundsFromDocument() {
  const md = readFileSync(join(ROOT, 'docs', 'design', 'FLOOR.md'), 'utf8');
  const read = (row) => {
    const m = md.match(new RegExp(`^\\| \\*\\*${row}\\*\\* \\| \\*\\*(\\d+) %\\*\\* `, 'm'));
    if (m === null) throw new Error(`FLOOR.md § 12 carries no *${row}* row in the form this tool reads`);
    return Number(m[1]) / 100;
  };
  const overRow = md.match(/^\| \*\*Sleeper z overlap bound\*\* \| ([^|]*) \|/m);
  const residuals = [...(overRow?.[1] ?? '').matchAll(/the `([a-z]+)` at \*\*(\d+) %\*\*/g)].map((m) => ({ species: m[1], bound: Number(m[2]) / 100 }));
  const face = md.match(/^\| \*\*Sleeper z face clearance\*\* \| \*\*(\d+)\*\* frame units from the closed eyes' ink, holding at most \*\*(\d+) %\*\* /m);
  if (face === null) throw new Error('FLOOR.md § 12 carries no *Sleeper z face clearance* row in the form this tool reads');
  const frame = md.match(/^\| \*\*Sleeper frame\*\* \| \*\*(\d+) × (\d+)\*\* frame units from \(\*\*(-?\d+)\*\*, \*\*(-?\d+)\*\*\)/m);
  if (frame === null) throw new Error('FLOOR.md § 12 carries no *Sleeper frame* row in the form this tool reads');
  const clearRow = md.match(/^\| \*\*Sleeper z clearance\*\* \| \*\*(\d+) %\*\* /m);
  if (clearRow === null) throw new Error('FLOOR.md § 12 carries no *Sleeper z clearance* row in the form this tool reads');
  const glow = md.match(/^\| \*\*Sleeper z brightness\*\* \| \*\*4 levels\*\*, the core mixed toward the ink by \*\*(\d+) %\*\*, \*\*(\d+) %\*\*, \*\*(\d+) %\*\* and \*\*(\d+) %\*\*, behind a halo of the palette's lamp colour \*\*([\d.]+)\*\*, \*\*([\d.]+)\*\*, \*\*([\d.]+)\*\* and \*\*([\d.]+)\*\* units wide at \*\*(\d+) %\*\*, \*\*(\d+) %\*\*, \*\*(\d+) %\*\* and \*\*(\d+) %\*\* opacity/m);
  if (glow === null) throw new Error('FLOOR.md § 12 carries no *Sleeper z brightness* row in the form this tool reads');
  const pulse = md.match(/^\| \*\*Sleeper z pulse\*\* \| \*\*(\d+)\*\* loop frames\b/m);
  if (pulse === null) throw new Error('FLOOR.md § 12 carries no *Sleeper z pulse* row in the form this tool reads');
  // The adjacent cores' luminance ratios FLOOR.md states — § 10.4 item 4's and § 12's *Sleeper z brightness* row's — which
  // the brightness check holds equal to what it measures, so a restated figure cannot drift from the measurement.
  const stated = [...md.matchAll(/adjacent cores(?:' luminance ratios| differ in luminance by) ([\d.]+), ([\d.]+) and ([\d.]+)/g)].map((m) => m.slice(1, 4).join(', '));
  if (stated.length !== 2) throw new Error(`FLOOR.md states the adjacent cores' luminance ratios ${stated.length} time(s), not the two this tool reads (§ 10.4 item 4, § 12's *Sleeper z brightness*)`);
  return { stepsStated: stated, overHead: read('Sleeper z overlap bound'), residuals, zOnZ: read('Sleeper z-on-z bound'), faceClear: Number(face[1]), face: Number(face[2]) / 100,
    glow: glow.slice(1, 5).map((v) => Number(v) / 100), halo: glow.slice(5, 9).map(Number), haloOpacity: glow.slice(9, 13).map((v) => Number(v) / 100), pulseFrames: Number(pulse[1]),
    view: { x: Number(frame[3]), y: Number(frame[4]), w: Number(frame[1]), h: Number(frame[2]) }, clearance: Number(clearRow[1]) / 100 };
}
/** How far from the closed eyes' ink a z's ink must stay, in frame units — § 12's *Sleeper z face clearance*. */
let FACE_CLEAR = NaN;
/** The sleeper's frame — § 12's *Sleeper frame* — which every document is judged in, whatever viewBox the tree draws it with. */
let VIEW = null;
let CLEAR_NAMES = [];
/** `doc` with its root drawn over `VIEW`: the tree's own frame, or a layout's z's laid on the base sleeper, judged in one frame. */
const inView = (doc) => doc.replace(/viewBox="[^"]*" width="[^"]*" height="[^"]*"/, `viewBox="${VIEW.x} ${VIEW.y} ${VIEW.w} ${VIEW.h}" width="${VIEW.w}" height="${VIEW.h}"`);

/**
 * THE CLEAR RECTS — what the z's must stay out of, in frame units, DERIVED FROM THE FLOOR'S OWN LAYOUT and never copied
 * here: `floor/desk-layout.js`'s `sleeperOverdraw()` for a desk at the furniture box — the one function the floor hands
 * the tree its clear rects by — the monitor's frame, the desk's props, the desk, and the thought trail's column: the
 * places a z that stands beyond the character's rect would be drawn through, or under, something the desk draws.
 */
async function clearRects(C) {
  const { sleeperOverdraw, FRAME_UNITS_PER_PX } = await import(pathToFileURL(join(ROOT, 'server', 'public', 'js', 'floor', 'desk-layout.js')).href);
  const { FURNITURE_BOX } = await import(pathToFileURL(join(ROOT, 'resources', 'floor', 'furniture-box.js')).href);
  UNIT_PX = 1 / FRAME_UNITS_PER_PX;
  return sleeperOverdraw(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H }).clear.map((c) => ({ ...c }));
}

// ── the z's, drawn as creatures.js's zSvg() draws them ──────────────────────────────────────────────────────────────
const f = (n) => (Math.round(n * 100) / 100).toString();
/** The outline and the core's stroke widths of a z `h` tall — `zSvg()`'s. */
const strokes = (h) => { const sw = Math.max(1.4, h * 0.16); return { core: sw, outline: sw + 2.6 }; };
/** A z's half extent in frame units, its outline's half-width included. */
const halfBox = (h) => { const o = strokes(h).outline / 2; return { w: h * 0.4 + o, h: h / 2 + o }; };
function zMarkup(z, ink, fill) {
  const w = z.h * 0.8, s = strokes(z.h);
  const d = `M${f(z.x - w / 2)} ${f(z.y - z.h / 2)}H${f(z.x + w / 2)}L${f(z.x - w / 2)} ${f(z.y + z.h / 2)}H${f(z.x + w / 2)}`;
  const stroke = (col, width) => `<path d="${d}" stroke="${col}" stroke-width="${f(width)}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
  return `<g data-part="z" data-t="${f(z.t)}" data-x="${f(z.x)}" data-y="${f(z.y)}" data-h="${f(z.h)}" opacity="${f(z.o)}">${stroke(ink, s.outline)}${stroke(fill, s.core)}</g>`;
}
/**
 * Two z markups draw the same z: the same text with every number within 0.03 of its twin — the tree rounds its data
 * attributes to two places, so a z re-drawn from them lands up to a rounding step off.
 */
function sameDrawing(a, b) {
  const num = /-?\d+(?:\.\d+)?/g;
  if (a.replace(num, '#') !== b.replace(num, '#')) return false;
  const na = a.match(num) ?? [], nb = b.match(num) ?? [];
  return na.length === nb.length && na.every((v, i) => Math.abs(Number(v) - Number(nb[i])) <= 0.03);
}
const Z_RE = /<g data-part="z" data-t="([\d.-]+)" data-x="([\d.-]+)" data-y="([\d.-]+)" data-h="([\d.-]+)" opacity="([\d.-]+)"><path d="[^"]*" stroke="([^"]+)"[^>]*\/><path d="[^"]*" stroke="([^"]+)"[^>]*\/><\/g>/g;
const Z3_RE = /<g data-part="z" data-k="(\d+)" data-level="(\d)" data-x="([\d.-]+)" data-y="([\d.-]+)" data-h="([\d.-]+)"><path d="[^"]*" stroke="([^"]+)"[^>]*\/><path d="[^"]*" stroke="([^"]+)"[^>]*\/><\/g>/g;
const zsOf = (doc) => [
  ...[...doc.matchAll(Z_RE)].map((m) => ({ markup: m[0], t: +m[1], x: +m[2], y: +m[3], h: +m[4], o: +m[5], ink: m[6], fill: m[7] })),
  ...[...doc.matchAll(Z3_RE)].map((m) => ({ markup: m[0], k: +m[1], level: +m[2], x: +m[3], y: +m[4], h: +m[5], o: 1, ink: m[6], core: m[7] })),
];
/** The sleeper's closed eyes alone, where its document draws them (the head's own transform kept). */
function eyesOnly(doc) {
  const head = doc.match(/<g data-part="head" transform="([^"]+)">[\s\S]*?(<g data-part="eyes-closed">(?:<path[^>]*\/>)+<\/g>)/);
  if (head === null) throw new Error('the sleeper document draws no `eyes-closed` group inside its head — the face check would read nothing');
  return doc.replace(/<g data-pose="asleep"[\s\S]*<\/svg>$/, `<g transform="${head[1]}">${head[2]}</g></svg>`);
}
const withoutZs = (doc) => doc.replace(/<g data-part="zs">[\s\S]*<\/g><\/svg>$/, '</svg>');
const onlyZ = (doc, markup) => doc.replace(/<g data-pose="asleep"[\s\S]*<\/svg>$/, `<g data-part="zs">${markup}</g></svg>`);

/** The sleeper's eye line and roll side, as its document states them. */
function poseOf(doc) {
  const eyes = Number((doc.match(/data-eyes-y="([\d.-]+)"/) ?? [])[1]);
  const roll = Number((doc.match(/<g data-part="head" transform="[^"]*rotate\((-?[\d.]+)\)/) ?? [])[1]);
  const top = Number((doc.match(/data-crown="([\d.-]+)"/) ?? [])[1]);
  return { eyes, dir: Math.sign(roll) || 1, crown: Number.isFinite(top) ? top : null };
}

/** The opacity law the round-3 tree's `sleepZs()` (retired by the fixed z's) faded a z by along its rise `t` in [0, 1) — the rising layouts' controls keep it. */
const fade = (t) => Math.min(1, t / 0.15, (1 - t) / 0.4);

/**
 * THE LAYOUTS — each a pure function of the sleeper's eye line and roll side and of the loop's phase in [0, 1), placing
 * every z. `card12159` is the path the card replaced (three z's from over the head on the roll's side, 24 → 11 units;
 * its start is card#12159's `min(56, eyes - 16)`, the crown term dropped — the crown is not in the document); `r1-column`
 * is round 1's ruling (three z's, 40 → 20, in a column on the roll-away side), which the z-on-z bound refused;
 * `diagonal` (two z's crossing the frame) and `beside` (two z's born at a fixed height beside the head) are decision
 * 70's alternatives (C) and (D); `on-face` is the face control; `ruled` is § 10.4 item 4's: two z's sized per seat by
 * `zLargeFor()`, in the roll-away edge column, the lower box `Z_EYE_GAP` above the eye line. `--layout two:…`,
 * `three:…` and `col:…` (below) are the parametric forms the search ran over.
 */
const LAYOUTS = {
  card12159: ({ eyes, dir }, phase) => rise({ N: 3, L: 24, S: 11, amp: 3 }, phase,
    { x: 54 + dir * 2, y: Math.min(56, eyes - 24 / 2 - 4) }, { x: 54 + dir * 46, y: 11 }),
  'r1-column': ({ eyes, dir }, phase) => rise({ N: 3, L: 40, S: 20, amp: 3, clamp: true }, phase,
    { x: 54 - dir * (54 - 40 * 0.4 - 2), y: eyes - 40 / 2 - 2 }, { x: 54 - dir * (54 - 20 * 0.4 - 5), y: 20 / 2 + 2 }),
  diagonal: ({ eyes, dir }, phase) => rise({ N: 2, L: 40, S: 20, amp: 0, clamp: true }, phase,
    { x: 54 - dir * 38, y: eyes - 40 / 2 - 2 }, { x: 54 + dir * 38, y: halfBox(20).h + 1 }),
  beside: ({ dir }, phase) => rise({ N: 2, L: 40, S: 20, amp: 0, clamp: true }, phase,
    { x: 54 - dir * 54, y: 100 }, { x: 54 - dir * 54, y: halfBox(20).h + 1 }),
  'on-face': ({ eyes }, phase) => rise({ N: 2, L: 24, S: 20, amp: 0 }, phase, { x: 54, y: eyes + 2 }, { x: 54, y: eyes - 30 }),
  ruled: ({ eyes, dir }, phase) => {
    const L = zLargeFor(eyes);
    return rise({ N: 2, L, S: L / 2, amp: 0, clamp: true }, phase,
      { x: 54 - dir * 54, y: eyes - Z_EYE_GAP - halfBox(L).h }, { x: 54 - dir * 54, y: halfBox(L / 2).h + 1 });
  },
};

/** § 10.4's ruled z's: the cap on the largest, and the gap between the lowest z's box and the eye line, in frame units. */
const Z_LARGE = 36;
const Z_EYE_GAP = 2;
/** A z this many units tall or more is visible — `fade(t)` at least this — and counts for the boxes-never-meet rule. */
const Z_SEEN = 0.3;

/**
 * § 10.4's per-seat size: the largest z, in steps of half a unit up to `Z_LARGE`, for which the two z's of the ruled
 * column — the lowest box `Z_EYE_GAP` above the eye line, the highest at the frame's top, the smaller half the larger —
 * never have their boxes (outline included) meet at any of 64 phases while both are at least `Z_SEEN` opaque.
 */
function zLargeFor(eyes) {
  for (let L = Z_LARGE; L > 8; L -= 0.5) {
    const S = L / 2, fy = eyes - Z_EYE_GAP - halfBox(L).h, ty = halfBox(S).h + 1;
    let meets = false;
    for (let i = 0; i < 64 && !meets; i += 1) {
      const zs = [0, 1].map((k) => { const t = ((k + i / 64) % 2) / 2; return { h: L + (S - L) * t, y: fy + (ty - fy) * t, o: fade(t) }; })
        .filter((z) => z.o >= Z_SEEN);
      meets = zs.length === 2 && Math.abs(zs[0].y - zs[1].y) < halfBox(zs[0].h).h + halfBox(zs[1].h).h;
    }
    if (!meets) return L;
  }
  return 8;
}

/**
 * § 10.4's THREE z's ABOVE THE HEAD, FIXED, AND LIT IN TURN (card#12187's final rulings, 2026-10-22). The three z's stand
 * in fixed positions on one path above the head and never move; what moves is a pulse of brightness — a bubble of light
 * that starts at the character and travels up through z1, then z2, then z3, and repeats (§ 12's *Sleeper z pulse*).
 * Each z is full opacity in every frame; its brightness is its core's fill, mixed from the ink toward the pale fill by a
 * level 0–3 (§ 12's *Sleeper z brightness*), its dark outline unchanged, so the shape never leaves the floor's contrast.
 *
 * **Where they stand** is the frame minus the floor's own furniture: every box inside `VIEW`, none meeting a clear rect
 * (`clearRects()`), none below the crown line within the character's own width (`crown`, the species' or variant's
 * crest top — `creatures.js`'s `CREST_TOP`, held here against the drawing), and no two meeting. **Each seat's size** is
 * the largest z, in half-unit steps up to `Z3.large`, for which the three fixed boxes hold all of that. The path is a
 * straight line from `a0` units off the head's centre to `a1` — positive away from the side the head rolled toward — the
 * z's at `Z3.t0`, the midpoint and `1 - Z3.t0` along it, shrinking from `L` to `Z3.ratio * L`, the lowest box just above
 * the crown and the path ending with the highest z's box at the frame's raised top (`top`).
 */
const Z3 = { count: 3, large: Number(arg('--z-large', 36)), ratio: Number(arg('--z-ratio', 0.5)), gap: 2, t0: 0.17, a0: Number(arg('--a0', 10)), a1: Number(arg('--a1', 108)), top: -42 };
/**
 * The brightness levels 0 (dim) to 3 (full), each drawn two ways at once: the core mixed from the pale fill toward the ink by
 * `GLOW[level]` (a dimmer core), and a warm halo of the palette's `--lamp` behind the z, `HALO[level]` units wider than its
 * outline each side at `HALO_OPACITY[level]` — § 12's *Sleeper z brightness*.
 */
let GLOW = [0.5, 0.3, 0.12, 0];
let HALO = [0, 1.5, 3, 5];
let HALO_OPACITY = [0, 0.5, 0.8, 1];
/** The halo's colour: the palette's `--lamp`, which the tree's own constant is held equal to. */
let LAMP = '#ffcf7d';
/** The loop's length in frames: the pulse's cycle (§ 12's *Sleeper z pulse*); the still is one more document ahead of them. */
let PULSE_FRAMES = 8;
/** The adjacent cores' luminance ratios as FLOOR.md states them (`boundsFromDocument()`), each `a, b, c`. */
let STEPS_STATED = [];
/** The pulse as the rule states it: the levels of z `k` at loop frame `f` — the bubble reaches z`k+1` at frame `2k`, peaks there, and glows 2, 1 behind it; z1 is 1 the frame before it arrives. */
const pulseLevels = (f) => Array.from({ length: Z3.count }, (_, k) => {
  const d = (((f - 2 * k) % PULSE_FRAMES) + PULSE_FRAMES) % PULSE_FRAMES;
  return d === 0 ? 3 : d === 1 ? 2 : d === 2 ? 1 : d === PULSE_FRAMES - 1 && k === 0 ? 1 : 0;
});

function above3Fixed(crown, dir, L, clear) {
  const props = clear.find((c) => c.name === 'props');
  const S = L * Z3.ratio, out = [];
  const y0 = Math.min(crown - Z3.gap, props.y - Z3.gap) - halfBox(L).h - HALO[3], y1 = Math.max(Z3.top, VIEW.y) + halfBox(S).h + HALO[3] + 1;
  for (let k = 0; k < Z3.count; k += 1) {
    const t = Z3.t0 + (1 - 2 * Z3.t0) * k / (Z3.count - 1), h = L + (S - L) * t, hb = halfBox(h);
    out.push({ k, x: 54 - dir * (Z3.a0 + (Z3.a1 - Z3.a0) * t), y: y0 + (y1 - y0) * t, h, hb });
  }
  return out;
}

/**
 * The first rule a stand of size `L` breaks, or `null` when it keeps them all — § 10.4 item 4's law, rule by rule: the
 * brightest glow inside the frame on all four sides (a glow is never cut by the frame, so no z is clamped into it); no two
 * bodies' boxes meeting (a unit apart); no body's box meeting a clear rect (a unit apart; the trail's column is one of them);
 * and no z, its glow included, below the crown line within the character's own 108 units. A glow may lie over a neighbouring
 * z or a clear rect: it is drawn behind every z, and under the furniture the desk draws after the character.
 */
function above3Why(crown, dir, L, clear) {
  const zs = above3Fixed(crown, dir, L, clear);
  const halo = HALO[3];
  for (const z of zs) if (z.y - z.hb.h - halo < VIEW.y - 0.01 || z.y + z.hb.h + halo > VIEW.y + VIEW.h) return 'the frame (top or bottom)';
  for (const z of zs) if (z.x - z.hb.w - halo < VIEW.x - 0.01 || z.x + z.hb.w + halo > VIEW.x + VIEW.w + 0.01) return 'the frame (a side)';
  for (let a = 0; a < zs.length; a += 1) for (let b = a + 1; b < zs.length; b += 1) {
    const A = zs[a], B = zs[b];
    if (Math.abs(A.x - B.x) < A.hb.w + B.hb.w + 1 && Math.abs(A.y - B.y) < A.hb.h + B.hb.h + 1) return 'two z\'s meeting';
  }
  for (const z of zs) {
    for (const c of clear) if (z.x + z.hb.w > c.x - 1 && z.x - z.hb.w < c.x + c.w + 1 && z.y + z.hb.h > c.y - 1 && z.y - z.hb.h < c.y + c.h + 1) return `the ${c.name}`;
    if (z.x + z.hb.w + halo > 0 && z.x - z.hb.w - halo < 108 && z.y + z.hb.h + halo > crown - Z3.gap + 0.01) return 'the crown line';
  }
  return null;
}

/** A seat's `L`: the largest in half-unit steps up to `Z3.large` that breaks no rule, and what stops the next half unit (`'the cap'` when nothing does, `null` when nothing fits). */
function above3Plan(crown, dir, clear) {
  for (let L = Z3.large; L >= 10; L -= 0.5) if (above3Why(crown, dir, L, clear) === null) return { L, bound: L === Z3.large ? 'the cap' : above3Why(crown, dir, L + 0.5, clear) };
  return { L: 0, bound: null };
}

/** The pulse a FIXED layout runs, by name: the ruled one, its reverse (z3 first) and a constant (every z always full) — the last two are the controls. */
const FIXED = {
  above3: { levels: (f) => pulseLevels(f) },
  'above3-reversed': { levels: (f) => pulseLevels(f).reverse() },
  'above3-constant': { levels: () => [3, 3, 3] },
  // The controls on the layout itself, planted by semantics on the stand the law computes: the pulse is § 10.4's in each.
  'above3-swapped': { levels: (f) => pulseLevels(f), stand: (zs) => zs.map((z, i) => ({ ...zs[zs.length - 1 - i], k: z.k })) },
  'above3-moved': { levels: (f) => pulseLevels(f), stand: (zs) => zs.map((z) => (z.k === 1 ? { ...z, x: z.x + 7 } : z)) },
  'above3-on-trail': { levels: (f) => pulseLevels(f), stand: (zs) => zs.map((z) => (z.k === 1 ? { ...z, x: 54, y: -20 } : z)), onlyRects: ['trail'] },
  'above3-halo-wide': { levels: (f) => pulseLevels(f), halo: { widen: 60 } },
  'above3-red-halo': { levels: (f) => pulseLevels(f), halo: { colour: '#e03030' } },
};

/** `fill` mixed toward `ink` by `w`, as `creatures.js`'s own `mix(a, b, t)` computes it — each channel `round(a + (b - a) * t)`, clamped — so the tool's core colours are the tree's to the digit. */
function mixHex(fill, ink, w) {
  const c = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
  const [a, b] = [c(fill), c(ink)];
  return `#${a.map((v, i) => Math.max(0, Math.min(255, Math.round(v + (b[i] - v) * w))).toString(16).padStart(2, '0')).join('')}`;
}
const levelFill = (ink, fill, level) => mixHex(fill, ink, GLOW[level]);

const zPath = (z) => {
  const w = z.h * 0.8;
  return `M${f(z.x - w / 2)} ${f(z.y - z.h / 2)}H${f(z.x + w / 2)}L${f(z.x - w / 2)} ${f(z.y + z.h / 2)}H${f(z.x + w / 2)}`;
};
/** One FIXED z as the tree draws it — the data attributes the tool reads back, the core mixed by `level`; its halo is `glowMarkup()`'s, drawn behind every z. */
function z3Markup(z, level, ink, fill) {
  const s = strokes(z.h), d = zPath(z);
  const stroke = (col, width) => `<path d="${d}" stroke="${col}" stroke-width="${f(width)}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
  return `<g data-part="z" data-k="${z.k}" data-level="${level}" data-x="${f(z.x)}" data-y="${f(z.y)}" data-h="${f(z.h)}">${stroke(ink, s.outline)}${stroke(levelFill(ink, fill, level), s.core)}</g>`;
}
/** A FIXED z's halo at `level`: nothing at level 0, else a wider stroke of the lamp colour behind the z, translucent by level. */
function glowMarkup(z, level, over = {}) {
  if (level === 0) return '';
  const s = strokes(z.h);
  return `<path data-part="halo" data-k="${z.k}" data-level="${level}" d="${zPath(z)}" stroke="${over.colour ?? LAMP}" stroke-width="${f(s.outline + 2 * (over.widen ?? HALO[level]))}" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="${f(HALO_OPACITY[level])}"/>`;
}
const HALO_RE = /<path data-part="halo" data-k="(\d+)" data-level="(\d)" d="[^"]*" stroke="([^"]+)" stroke-width="([\d.]+)"[^>]*opacity="([\d.]+)"\/>/g;
const halosOf = (doc) => [...doc.matchAll(HALO_RE)].map((m) => ({ markup: m[0], k: +m[1], level: +m[2], colour: m[3], width: +m[4], opacity: +m[5] }));

/** The round-3 tree's `sleepZs()` law (retired; the rising controls keep it): N z's evenly spaced along from → to, shrinking L → S, fading in and out; optionally clamped into the frame. */
function rise(p, phase, from, to) {
  const out = [];
  for (let k = 0; k < p.N; k += 1) {
    const t = ((k + phase) % p.N) / p.N;
    const h = p.L + (p.S - p.L) * t;
    let x = from.x + (to.x - from.x) * t + Math.sin(t * Math.PI * 2) * p.amp * Math.sign(to.x - from.x || 1);
    if (p.clamp) { const hw = halfBox(h).w; x = Math.min(108 - hw - 0.5, Math.max(hw + 0.5, x)); }
    out.push({ t, x, y: from.y + (to.y - from.y) * t, h, o: fade(t) });
  }
  return out.sort((a, b) => a.t - b.t);
}

// ── the measurement ──────────────────────────────────────────────────────────────────────────────────────────────────
/** The widest run of ink in any one raster row above `eyesRow` and the topmost row of ink, both in frame units (`vy` the frame's top). */
const PAGE_CREST = `async ({ body, w, h, scale, alpha, eyesRow, vy }) => {
  const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(body);
  await img.decode();
  const c = document.createElement('canvas'); c.width = w * scale; c.height = h * scale;
  const g = c.getContext('2d'); g.drawImage(img, 0, 0, c.width, c.height);
  const d = g.getImageData(0, 0, c.width, c.height).data; let best = 0, top = null;
  for (let y = 0; y < c.height; y++) {
    let lo = -1, hi = -1;
    for (let x = 0; x < c.width; x++) if (d[(y * c.width + x) * 4 + 3] > alpha) { if (lo < 0) lo = x; hi = x; }
    if (lo >= 0 && top === null) top = vy + y / scale;
    if (lo >= 0 && vy + y / scale < eyesRow) best = Math.max(best, (hi - lo) / scale);
  }
  return { widest: best, top };
}`;

const PAGE_MEASURE = `async ({ body, eyes, zs, halos, w, h, scale, alpha, eyesRow, clear, vx, vy, rects }) => {
  const mask = async (svg) => {
    const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    await img.decode();
    const c = document.createElement('canvas'); c.width = w * scale; c.height = h * scale;
    const g = c.getContext('2d'); g.drawImage(img, 0, 0, c.width, c.height);
    const d = g.getImageData(0, 0, c.width, c.height).data; const out = new Uint8Array(d.length / 4);
    for (let i = 0; i < out.length; i++) out[i] = d[i * 4 + 3] > alpha ? 1 : 0;
    return out;
  };
  const b = await mask(body);
  const W = w * scale, H = h * scale, r = Math.round(clear * scale);
  // The face: the closed eyes' ink, dilated by \`clear\` units (a square, separably).
  const e0 = await mask(eyes), e1 = new Uint8Array(e0.length), face = new Uint8Array(e0.length);
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { let v = 0; for (let d = -r; d <= r && !v; d++) { const xx = x + d; if (xx >= 0 && xx < W) v = e0[y * W + xx]; } e1[y * W + x] = v; }
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { let v = 0; for (let d = -r; d <= r && !v; d++) { const yy = y + d; if (yy >= 0 && yy < H) v = e1[yy * W + x]; } face[y * W + x] = v; }
  let eyeInk = 0; for (const v of e0) eyeInk += v;
  const ms = []; for (const z of zs) ms.push(await mask(z));
  const hs = []; for (const z of halos) hs.push(await mask(z));
  let haloOnBody = 0;
  for (let i = 0; i < b.length; i++) { let n = 0; for (const m of hs) n += m[i]; if (n > 0 && b[i]) haloOnBody++; }
  let any = 0, multi = 0, onTop = 0, onAll = 0, onFace = 0, inClear = 0;
  const hit = (x, y) => rects.some((c) => x >= c.x && x < c.x + c.w && y >= c.y && y < c.y + c.h);
  for (let i = 0; i < b.length; i++) {
    let n = 0; for (const m of ms) n += m[i];
    if (n === 0) continue;
    any++; if (n > 1) multi++;
    if (face[i]) onFace++;
    const px = vx + (i % W) / scale, py = vy + Math.floor(i / W) / scale;
    if (hit(px, py)) inClear++;
    if (b[i]) { onAll++; if (py < eyesRow) onTop++; }
  }
  return { any, multi, onTop, onAll, onFace, eyeInk, inClear, haloOnBody };
}`;

/**
 * THE SEATS: not the first few keys — the EXTREMES of the population that matter, per species and variant. For each group
 * the first `--keys` keys, plus the key with the lowest eye line and the one with the highest among `--scan-eyes` keys
 * (the eye line sets the size of a seat's z's and the room they have), plus the key with the widest crest — the widest
 * run of ink above the eye line — among `--scan-crest` keys (the crest is what the z's can overlap). Round 2's review
 * found the first-three-keys sample missing both: a mushroom key 12 at 55.1 % where keys 0–2 read 46 %.
 */
async function pickSeats(b, C, env) {
  const variantsOf = (sp) => {
    const seen = new Set();
    for (let i = 0; i < 300; i += 1) seen.add(C.recipe(`v/${sp}/${i}`, sp).variant);
    return [...seen].filter((v) => v !== null);
  };
  const keyOf = (sp, v, k) => {
    let key = `sleep/${sp}/${v ?? 'any'}/${k}`;
    for (let i = 0; v !== null && C.recipe(key, sp).variant !== v && i < 400; i += 1) key = `sleep/${sp}/${v}/${k}/${i}`;
    return v === null || C.recipe(key, sp).variant === v ? key : null;
  };
  const seats = [], extremes = [], scans = [];
  for (const sp of C.SPECIES_KEYS) {
    for (const v of [null, ...variantsOf(sp)]) {
      const picked = new Map();
      const add = (key, why) => { if (key !== null && !picked.has(key)) picked.set(key, why); };
      for (let k = 0; k < KEYS_PER; k += 1) add(keyOf(sp, v, k), 'first');
      let lo = null, hi = null, wide = null;
      for (let k = 0; k < Math.max(SCAN_EYES, SCAN_CREST); k += 1) {
        const key = keyOf(sp, v, k);
        if (key === null) continue;
        const doc = C.sleepDocument(key, { species: sp, frame: 0, deskTop: DESK_TOP, clear: env.clear });
        const eyes = poseOf(doc).eyes;
        if (k < SCAN_EYES) {
          if (lo === null || eyes < lo.eyes) lo = { key, eyes };
          if (hi === null || eyes > hi.eyes) hi = { key, eyes };
        }
        if (k < SCAN_CREST) {
          const r = await b.evaluate(`(${PAGE_CREST})(${JSON.stringify({ body: withoutZs(inView(doc)), w: VIEW.w, h: VIEW.h, scale: SCALE, alpha: ALPHA, eyesRow: eyes - 8, vy: VIEW.y })})`);
          if (wide === null || r.widest > wide.w) wide = { key, w: r.widest };
          const variant = C.recipe(key, sp).variant;
          scans.push({ tkey: variant ? `${sp}/${variant}` : sp, key, top: r.top });
        }
      }
      add(lo.key, 'lowest eye line'); add(hi.key, 'highest eye line'); add(wide.key, 'widest crest');
      extremes.push({ sp, v: v ?? '-', lo, hi, wide });
      for (const [key] of picked) seats.push({ sp, v: v ?? '-', key });
    }
  }
  return { seats, extremes, scans };
}

/** Every frame to judge, for one seat: the tree's own, or the base frame's sleeper with a layout's z's at each phase. */
function framesOf(C, seat, layout, mirror, env) {
  // The tree's documents: a still and the loop (`SLEEP_LOOP`; the still is document 0). A tree that exports no `SLEEP_LOOP`
  // is not one this tool judges.
  if (!Number.isInteger(C.SLEEP_LOOP)) throw new Error('the tree exports no SLEEP_LOOP — its sleeper is not the still and the pulse this tool judges');
  const total = C.SLEEP_LOOP + 1;
  const own = Array.from({ length: total }, (_, frame) => C.sleepDocument(seat.key, { species: seat.sp, frame, deskTop: DESK_TOP, clear: env.clear }));
  const ownZs = own.map((doc) => { const hs = halosOf(doc); return zsOf(doc).map((z) => ({ ...z, glow: hs.find((h) => h.k === z.k)?.markup ?? '', halo: hs.find((h) => h.k === z.k) ?? null })); });
  const fixedOwn = ownZs[0].length > 0 && ownZs[0][0].level !== undefined;
  const pale = fixedOwn ? ownZs[0][0].core : null;
  own.forEach((doc, i) => {
    for (const z of ownZs[i]) {
      const redrawn = z.level !== undefined ? z3Markup({ ...z, k: z.k }, z.level, z.ink, pale) : zMarkup(z, z.ink, z.fill);
      if (!sameDrawing(redrawn, z.markup)) mirror.push(`${seat.key}: ${z.markup.slice(0, 120)}…`);
      if (z.level !== undefined && !sameDrawing(glowMarkup(z, z.level), z.glow)) mirror.push(`${seat.key}: halo ${z.glow.slice(0, 100)}…`);
    }
  });
  if (layout === null) return own.map((doc, i) => ({ doc: inView(doc), pose: poseOf(doc), zs: ownZs[i], fixed: fixedOwn, raster: !fixedOwn || i === 0 }));
  const base = inView(own[0]);
  const pose = poseOf(base);
  const [first] = ownZs[0];
  const ink = first.ink, fill = first.fill ?? first.core;
  const put = (zs) => base.replace(/<g data-part="zs">[\s\S]*<\/g><\/svg>$/, `<g data-part="zs"><g data-part="glow">${zs.map((z) => z.glow ?? '').join('')}</g>${zs.map((z) => z.markup).join('')}</g></svg>`);
  if (layout in FIXED) {
    const { L } = above3Plan(env.crown, pose.dir, env.clear);
    if (L === 0) throw new Error(`${seat.key}: no fixed three-z layout fits above crown ${env.crown}`);
    const law = above3Fixed(env.crown, pose.dir, L, env.clear);
    const stand = FIXED[layout].stand ? FIXED[layout].stand(law) : law;
    const levelsOf = (i) => (i === 0 ? [3, 3, 3] : FIXED[layout].levels(i - 1));
    return Array.from({ length: PULSE_FRAMES + 1 }, (_, i) => {
      const zs = stand.map((z, k) => ({ ...z, level: levelsOf(i)[k], o: 1, markup: z3Markup(z, levelsOf(i)[k], ink, fill), glow: glowMarkup(z, levelsOf(i)[k], FIXED[layout].halo) }));
      return { doc: put(zs), pose, zs, fixed: true, raster: i === 0 };
    });
  }
  return Array.from({ length: PHASES }, (_, i) => {
    const zs = LAYOUTS[layout](pose, i / PHASES, env).map((z) => ({ ...z, markup: zMarkup(z, ink, fill) }));
    return { doc: put(zs), pose, zs, fixed: false, raster: true };
  });
}

/**
 * THE CHASE, read off a seat's frames as data (no raster): the still is every z at full brightness; every frame has the three
 * z's; they stand in the SAME places in every frame (the loop moves no z); and the loop's levels equal § 10.4's pulse —
 * checked as the table AND as the property it exists for, that the brightest z's index advances z1, z2, z3 up the loop.
 */
function chaseOf(frames) {
  const defects = [];
  const zsAt = frames.map((fr) => [...fr.zs].sort((a, b) => a.k - b.k).map((z) => ({ ...z, halo: z.halo !== undefined ? z.halo : (z.glow ? halosOf(z.glow)[0] ?? null : null) })));
  if (zsAt.some((zs) => zs.length !== Z3.count)) defects.push('a frame does not draw exactly three z\'s');
  if (zsAt[0].some((z) => z.level !== 3)) defects.push('the still is not every z at full brightness');
  // "Up" is held by POSITION: the pulse reaches z1 first, so z1 must be the lowest and each next z higher — a tree that numbers
  // its z's from the top runs the pulse DOWN and still reads z1, z2, z3 by index.
  zsAt[0].forEach((z, k) => { if (k > 0 && !(z.y < zsAt[0][k - 1].y)) defects.push(`z${k + 1} (y ${z.y}) is not above z${k} (y ${zsAt[0][k - 1].y}) — the pulse would not travel up`); });
  const home = zsAt[0].map((z) => `${z.x},${z.y},${z.h}`).join('|');
  if (zsAt.some((zs) => zs.map((z) => `${z.x},${z.y},${z.h}`).join('|') !== home)) defects.push('a z moves between frames');
  zsAt.forEach((zs, i) => zs.forEach((z) => {
    const want = z.level === 0 ? null : z.level;
    if ((z.halo?.level ?? null) !== want) defects.push(`frame ${i}: z${z.k + 1}'s halo is ${z.halo?.level ?? 'absent'} at level ${z.level}`);
    else if (z.halo && (z.halo.colour !== LAMP || Math.abs(z.halo.opacity - HALO_OPACITY[z.level]) > 0.011)) defects.push(`frame ${i}: z${z.k + 1}'s halo is not the lamp colour at level ${z.level}'s opacity`);
  }));
  const loop = zsAt.slice(1).map((zs) => zs.map((z) => z.level));
  if (loop.length !== PULSE_FRAMES) defects.push(`the loop has ${loop.length} frames, not ${PULSE_FRAMES}`);
  loop.forEach((lv, f) => { if (lv.join() !== pulseLevels(f).join()) defects.push(`loop frame ${f} reads ${lv.join('')}, not ${pulseLevels(f).join('')}`); });
  const peak = (k) => loop.findIndex((lv) => lv[k] === 3);
  const peaks = [0, 1, 2].map(peak);
  if (!(peaks[0] >= 0 && peaks[0] < peaks[1] && peaks[1] < peaks[2])) defects.push(`the brightest z does not advance z1, z2, z3 up the loop (first peaks at frames ${peaks.join(', ')})`);
  return defects;
}

/**
 * THE LAW, held on the drawing: the three z's of document 0 (the loop moves none) stand where `above3Fixed()` puts them
 * for the seat's own crest top and roll, at the size `above3Plan()` gives — within the data attributes' rounding. This
 * is what makes § 10.4's layout a contract on the tree and not a description of the tool: a tree that builds another layout
 * (another path, another size, another crest line) passes every ink bound and red here, naming the z and the position.
 */
function lawOf(frames, crown, clear) {
  const pose = frames[0].pose;
  const { L } = above3Plan(crown, pose.dir, clear);
  const want = above3Fixed(crown, pose.dir, L, clear);
  const got = [...frames[0].zs].sort((a, b) => a.k - b.k);
  const defects = [];
  if (got.length !== want.length) return [`the still draws ${got.length} z's, the law ${want.length}`];
  got.forEach((z, k) => {
    const w = want[k];
    if (Math.abs(z.x - w.x) > 0.011 || Math.abs(z.y - w.y) > 0.011 || Math.abs(z.h - w.h) > 0.011) {
      defects.push(`z${k + 1} stands at (${f(z.x)}, ${f(z.y)}) ${f(z.h)} tall, not above3Fixed's (${f(w.x)}, ${f(w.y)}) ${f(w.h)} tall for crest ${crown}, roll ${pose.dir > 0 ? '+' : '-'} (L ${L})`);
    }
  });
  return defects;
}

/**
 * THE CREST TABLE'S CHECK, as a pure function so its controls can plant what the raster cannot: the tree's `CREST_TOP`
 * held at or under every scanned key's topmost ink. A tree that exports none is NOT a clean table — judging the tree's own
 * frames it is a defect (nothing holds the table the sizes rest on); judging a layout it is NOT CHECKED, said aloud, because
 * a layout is judged on the scan's own table and the tree's has not been built.
 */
function crestCheck(treeTable, scans, layout) {
  if (treeTable === null) return { defects: layout === null ? ['the tree exports no CREST_TOP — nothing holds the table the sizes rest on'] : [], checked: false };
  return { defects: scans.filter((sc) => !(sc.tkey in treeTable) || sc.top < treeTable[sc.tkey] - 0.01).map((sc) => `${sc.key} reaches ${sc.top} above ${sc.tkey}'s ${treeTable[sc.tkey]}`), checked: true };
}

/** CSS px per frame unit at the floor's fit zoom on a 1,440 × 900 window: the character rect is `SEAT_VIEWBOX.w / R.character.w` units a scene px. */
let UNIT_PX = NaN;
const FIT_ZOOM = Number(arg('--fit', 0.9));
const ZOOM_CEILING = Number(readFileSync(join(ROOT, 'server', 'public', 'js', 'wire', 'camera.js'), 'utf8').match(/export const ZOOM_CEILING = ([\d.]+);/)?.[1] ?? NaN);

/** WCAG's relative luminance and contrast ratio. */
const lum = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

/**
 * The surfaces a z is read against: the house floor's `--scene-floor` and every colour of every shipped theme's `surfaces()`
 * (`tools/floor-themes/surfaces.mjs`, read the way `state-chip-colours.py` reads it, never transcribed) — a theme's own
 * floor is a floor the desk stands on.
 */
function surfaceHexes() {
  const css = readFileSync(join(ROOT, 'server', 'public', 'css', 'mezzanine.css'), 'utf8');
  const floor = css.match(/--scene-floor:\s*(#[0-9a-fA-F]{6})/)?.[1];
  if (!floor) throw new Error('mezzanine.css declares no --scene-floor the brightness check can read');
  const themes = JSON.parse(execFileSync('node', [join(ROOT, 'tools', 'floor-themes', 'surfaces.mjs')], { encoding: 'utf8' }));
  return [['--scene-floor', floor], ...Object.entries(themes).flatMap(([t, ss]) => Object.entries(ss).map(([n, hex]) => [`${t}/${n}`, hex]))];
}

/**
 * THE BRIGHTNESS RANGE (§ 12's *Sleeper z brightness*): the WCAG contrast of the z's outline against EVERY surface a desk can
 * stand on (3 : 1, SC 1.4.11 — the shape is the outline's, at every level), of each level's core against the floor and its own
 * outline, and that each level is visibly brighter than the one below (adjacent cores' luminance ratio at least 1.15). The
 * options are the controls' to plant: another mix, another ink.
 */
function brightnessRange({ glow = GLOW, ink = Z_INK_TOOL, pale = Z_FILL_TOOL, surfaces = surfaceHexes(), stated = STEPS_STATED } = {}) {
  const floor = surfaces[0][1];
  const cores = [0, 1, 2, 3].map((l) => mixHex(pale, ink, glow[l]));
  const outlines = surfaces.map(([name, hex]) => ({ name, hex, r: ratio(ink, hex) }));
  const worstOutline = outlines.reduce((a, o) => (o.r < a.r ? o : a));
  const vsFloor = cores.map((c) => ratio(c, floor));
  const steps = [1, 2, 3].map((l) => (lum(cores[l]) + 0.05) / (lum(cores[l - 1]) + 0.05));
  const measured = steps.map((x) => x.toFixed(2)).join(', ');
  const drift = stated.filter((st) => st !== measured);
  const ok = worstOutline.r >= 3 && steps.every((x) => x >= 1.15) && drift.length === 0;
  return { ok, cores, line: `the outline ${ratio(ink, floor).toFixed(2)}:1 against the floor ${floor}, and at the worst of ${outlines.length} surfaces (the house floor and every shipped theme's) ${worstOutline.r.toFixed(2)}:1 against ${worstOutline.name} ${worstOutline.hex} (3:1 needed, SC 1.4.11 — the shape is the outline's, at every level, and the halo and the core only add to it); cores ${cores.join(' ')} (the dimmest core ${vsFloor[0].toFixed(2)}:1 against the floor, ${ratio(cores[0], ink).toFixed(2)}:1 against its own outline); adjacent cores' luminance ratios ${measured} (1.15 needed)${drift.length ? ` — FLOOR.md STATES ${drift.join(' / ')}, not what is measured` : ', as FLOOR.md states them'}` };
}

/**
 * THE FLASH (WCAG 2.2 SC 2.3.1, derived — never declared): the pulse is a general flash BY LUMINANCE when a z's core swings by
 * 0.10 or more of the maximum luminance with the darker side under 0.80, so the content passes on EITHER of SC 2.3.1's
 * thresholds — no more than three flashes in any one second, or the area of flashes in any 10-degree field (341 × 256 CSS px
 * at the guidance's 1,024 × 768 reference, so 25 % of it is 21,824 px²) at most a quarter of it. Both are computed here from the
 * pulse table and the sizes: the rate by counting each z's peaks (a rise to level 3 then a fall) in every one-second window
 * of the loop at `1000 / interval` fps, the area as the boxes of the three z's, glow included, at the fit and at the zoom ceiling
 * — one sleeper in the ceiling's field (a desk's box is wider than the field there), up to four in the fit's.
 */
function flashCheck({ levelsOf = pulseLevels, frames = PULSE_FRAMES, fps = 4, cores, sizes, unitPx = UNIT_PX, ceiling = ZOOM_CEILING, halo = HALO[3] } = {}) {
  const swing = lum(cores[3]) - lum(cores[0]);
  const byLuminance = swing >= 0.10 && lum(cores[0]) < 0.80;
  // peaks: loop frame f is a peak of z k when its level is 3 and the next frame's is lower (a rise then a fall)
  const peaks = Array.from({ length: Z3.count }, (_, k) => Array.from({ length: frames }, (_, f) => levelsOf(f)[k] === 3 && levelsOf((f + 1) % frames)[k] < 3));
  let worstRate = 0;
  for (let f = 0; f < frames; f += 1) {
    let n = 0;
    for (let j = 0; j <= fps; j += 1) n += peaks.reduce((a, pk) => a + (pk[(f + j) % frames] ? 1 : 0), 0);
    worstRate = Math.max(worstRate, n);
  }
  const boxPx = (h, zoom) => { const o = strokes(h).outline / 2 + halo; return (h * 0.8 + 2 * o) * (h + 2 * o) * (unitPx * zoom) ** 2; };
  const area = (zoom) => sizes.reduce((a, h) => a + boxPx(h, zoom), 0);
  const field = 341 * 256 * 0.25;
  const atFit = 4 * area(FIT_ZOOM), atCeiling = area(ceiling);
  const rateOk = worstRate <= 3, areaOk = atFit <= field && atCeiling <= field;
  const ok = !byLuminance || rateOk || areaOk;
  return { ok, line: `${byLuminance ? `each z's core swings ${lum(cores[0]).toFixed(3)} to ${lum(cores[3]).toFixed(3)} (${swing.toFixed(2)} of the maximum, darker side under 0.80: a general flash by luminance)` : `the core swings ${swing.toFixed(2)}, not a general flash by luminance`}; at most ${worstRate} peaks in any one second of the loop (3 allowed); the three z's and glows cover ${atFit.toFixed(0)} px² at the fit for up to four sleepers in one 10-degree field and ${atCeiling.toFixed(0)} px² at the zoom ceiling ${ceiling} for one, of ${field.toFixed(0)} px² (a quarter of 341 × 256)` };
}

async function measure(chrome, tree, layout, opts = {}) {
  const C = await import(pathToFileURL(join(tree, 'index.js')).href);
  const b = await browser(chrome, 300, 500, 'ready');
  const rows = [];
  const mirror = [];
  const picks = [];
  const env = { clear: await clearRects(C) };
  const chase = new Set(), law = new Set();
  // A control may judge one clear rect alone (`onlyRects`), so the ink it plants can only be caught by THAT rect.
  const only = FIXED[layout]?.onlyRects ?? opts.only ?? null;
  const rects = env.clear.filter((c) => only === null || only.includes(c.name)).map((c) => ({ x: c.x, y: c.y, w: c.w, h: c.h }));
  try {
    await b.load('data:text/html,<title>ready</title>');
    const { seats, extremes, scans } = await pickSeats(b, C, env);
    picks.push(...extremes);
    // The crest table: the topmost ink of each species' (and variant's) sleepers over the scan. A layout is judged on the
    // table the scan makes; the tree's own frames on its exported `CREST_TOP`, which the scan must never find undercut.
    const scanTable = {};
    for (const sc of scans) scanTable[sc.tkey] = Math.min(scanTable[sc.tkey] ?? Infinity, sc.top);
    const tree_table = C.CREST_TOP ?? null;
    const crestDefects = crestCheck(tree_table, scans, layout).defects;
    const table = layout === null ? tree_table ?? scanTable : scanTable;
    for (const seat of seats) {
      const variant = C.recipe(seat.key, seat.sp).variant;
      const crown = table[variant ? `${seat.sp}/${variant}` : seat.sp];
      const row = { ...seat, framesMeasured: 0, fixed: false, overHead: 0, overAll: 0, zOnZ: 0, onFace: 0, inClear: 0, zCount: Infinity, eyeInk: Infinity, worstFrame: -1, clipped: 0, zLarge: 0, opaqueLarge: 0, zSmall: Infinity, zpx: 0, crown };
      const frames = framesOf(C, seat, layout, mirror, { ...env, crown });
      if (frames[0].fixed) {
        for (const d of chaseOf(frames)) chase.add(`${seat.key}: ${d}`);
        for (const d of lawOf(frames, crown, env.clear)) law.add(`${seat.key}: ${d}`);
        const plan = above3Plan(crown, frames[0].pose.dir, env.clear);
        row.plan = { ...plan, h: above3Fixed(crown, frames[0].pose.dir, plan.L, env.clear).map((z) => z.h) };
      }
      for (const [i, { doc, pose, zs, raster }] of frames.entries()) {
        row.zCount = Math.min(row.zCount, zs.length);
        if (!raster) continue;
        for (const z of zs) {
          row.zLarge = Math.max(row.zLarge, z.h);
          row.zSmall = Math.min(row.zSmall, z.h);
          if (z.o >= 1 && (layout === null || i % (PHASES / 8) === 0)) row.opaqueLarge = Math.max(row.opaqueLarge, z.h);
          const hb = halfBox(z.h);
          if (z.y - hb.h < VIEW.y || z.y + hb.h > VIEW.y + VIEW.h || z.x - hb.w < VIEW.x || z.x + hb.w > VIEW.x + VIEW.w) row.clipped += 1;
        }
        const r = await b.evaluate(`(${PAGE_MEASURE})(${JSON.stringify({
          body: withoutZs(doc), eyes: eyesOnly(doc), clear: FACE_CLEAR, zs: zs.map((z) => onlyZ(doc, z.markup)), halos: zs.filter((z) => z.glow).map((z) => onlyZ(doc, z.glow)), w: VIEW.w, h: VIEW.h, scale: SCALE, alpha: ALPHA, eyesRow: pose.eyes - 8, vx: VIEW.x, vy: VIEW.y, rects,
        })})`);
        row.framesMeasured += 1;
        row.fixed = row.fixed || frames[0].fixed;
        row.zpx += r.any;
        const over = r.any ? r.onTop / r.any : 0, onz = r.any ? r.multi / r.any : 0;
        if (over > row.overHead) { row.overHead = over; row.worstFrame = i; }
        row.zOnZ = Math.max(row.zOnZ, onz);
        row.overAll = Math.max(row.overAll, r.any ? r.onAll / r.any : 0);
        row.onFace = Math.max(row.onFace, r.any ? r.onFace / r.any : 0);
        row.inClear = Math.max(row.inClear, r.any ? r.inClear / r.any : 0);
        row.haloOnBody = Math.max(row.haloOnBody ?? 0, r.haloOnBody);
        row.eyeInk = Math.min(row.eyeInk, r.eyeInk);
      }
      rows.push(row);
    }
    return { rows, picks, species: C.SPECIES_KEYS, mirror, framesPer: rows[0]?.framesMeasured ?? 1, table, crestDefects, scanTable, hasTable: tree_table !== null, chase: [...chase], law: [...law], layout, scans, fixed: rows.some((r) => r.fixed) };
  } finally {
    b.close();
  }
}

function report(label, { rows, picks, species, mirror, framesPer, crestDefects, hasTable, scanTable, chase, law, layout, fixed }, bounds) {
  const pct = (x) => `${(x * 100).toFixed(1)}%`;
  const worstBy = (k, list = rows) => list.reduce((a, r) => (r[k] > a[k] ? r : a), list[0]);
  const zpx = rows.reduce((a, r) => a + r.zpx, 0);
  const clipped = rows.reduce((a, r) => a + r.clipped, 0);
  const eyesAll = picks.flatMap((p) => [p.lo.eyes, p.hi.eyes]);
  const measured = rows.length >= species.length * KEYS_PER && zpx > 500 * rows.length * framesPer && rows.every((r) => r.eyeInk > 20);
  const over = worstBy('overHead'), onz = worstBy('zOnZ');
  const face = worstBy('onFace');
  // The over-head bound is held per species: the global bound, or the species' declared residual.
  const boundFor = (sp) => bounds.residuals.find((r) => r.species === sp)?.bound ?? bounds.overHead;
  const overSpecies = species.filter((sp) => rows.some((r) => r.sp === sp && r.overHead > boundFor(sp)));
  const unknownResidual = bounds.residuals.filter((r) => !species.includes(r.species)).map((r) => r.species);
  const okOver = overSpecies.length === 0 && unknownResidual.length === 0, okOnz = onz.zOnZ <= bounds.zOnZ, okFace = face.onFace <= bounds.face;
  // § 10.4: THREE z's in every frame, none of whose ink is in the floor's clear rects, over a crest table the drawing holds.
  const fewest = Math.min(...rows.map((r) => r.zCount));
  const okCount = fewest === Z3.count;
  const clear = worstBy('inClear');
  const okClear = clear.inClear <= bounds.clearance;
  const okCrest = crestDefects.length === 0;
  const okLaw = !fixed || law.length === 0;
  // The chase and the brightness range apply to the FIXED layouts (the tree, once built for the pulse, and the pulse layouts).
  const okChase = !fixed || chase.length === 0;
  const haloOnBody = Math.max(...rows.map((r) => r.haloOnBody ?? 0));
  const okGlow = !fixed || (haloOnBody === 0 && (treeLamp === null || treeLamp === cssLamp));
  const range = fixed ? brightnessRange() : null;
  const okRange = !fixed || range.ok;
  const planned = rows.filter((r) => r.plan !== undefined);
  const zOf = (k) => planned.map((r) => r.plan.h[k]);
  const lo = (xs) => Math.min(...xs), hi = (xs) => Math.max(...xs);
  const flash = fixed && planned.length > 0 ? flashCheck({ cores: range.cores, sizes: [hi(zOf(0)), hi(zOf(1)), hi(zOf(2))] }) : null;
  const okFlash = !fixed || (flash !== null && flash.ok);
  const ok = measured && okOver && okOnz && okFace && okCount && okClear && okCrest && okChase && okLaw && okRange && okFlash && okGlow && clipped === 0 && mirror.length === 0;
  console.log(`── ${label}: ${rows.length} seats × ${framesPer} frames, ${zpx} z px read; z heights ${Math.min(...rows.map((r) => r.zSmall)).toFixed(1)}–${Math.max(...rows.map((r) => r.zLarge)).toFixed(1)} units, the largest fully opaque AT THE LOOP'S OWN FRAMES ${Math.min(...rows.map((r) => r.opaqueLarge)).toFixed(1)}–${Math.max(...rows.map((r) => r.opaqueLarge)).toFixed(1)} by seat (the largest drawn ${Math.min(...rows.map((r) => r.zLarge)).toFixed(1)}–${Math.max(...rows.map((r) => r.zLarge)).toFixed(1)}); z boxes leaving the frame ${clipped}`);
  console.log(`   seats: ${rows.length}, chosen by the extremes of ${SCAN_EYES} keys' eye lines (${Math.min(...eyesAll).toFixed(1)}–${Math.max(...eyesAll).toFixed(1)} units over the population) and ${SCAN_CREST} keys' crests (widest ${Math.max(...picks.map((p) => p.wide.w)).toFixed(0)} units)`);
  for (const sp of species) {
    const mine = rows.filter((r) => r.sp === sp);
    console.log(`   ${sp.padEnd(9)} over head ${pct(worstBy('overHead', mine).overHead).padStart(6)} of ${pct(boundFor(sp)).padStart(6)}${overSpecies.includes(sp) ? ' OVER' : '     '}   z on z ${pct(worstBy('zOnZ', mine).zOnZ).padStart(6)}   face ${pct(worstBy('onFace', mine).onFace).padStart(6)}   anywhere on the sleeper ${pct(worstBy('overAll', mine).overAll).padStart(6)}   fully opaque ${Math.min(...mine.map((r) => r.opaqueLarge)).toFixed(1)}–${Math.max(...mine.map((r) => r.opaqueLarge)).toFixed(1)}   (${worstBy('overHead', mine).key})`);
  }
  console.log(`   over head: worst ${pct(over.overHead)} (${over.key}); every species against ${pct(bounds.overHead)}${bounds.residuals.map((r) => `, the ${r.species} a residual at ${pct(r.bound)}`).join('')} — ${okOver ? 'within' : `OVER on ${overSpecies.join(', ') || '—'}`}${unknownResidual.length ? ` — a residual names no species of the tree: ${unknownResidual.join(', ')}` : ''}`);
  console.log(`   z on z:    worst ${pct(onz.zOnZ)} (${onz.key}) against ${pct(bounds.zOnZ)} — ${okOnz ? 'within' : 'OVER'}`);
  console.log(`   the face:  worst ${pct(face.onFace)} of a frame's z ink within ${bounds.faceClear} units of the closed eyes (${face.key}) against ${pct(bounds.face)} — ${okFace ? 'clear' : 'ON THE FACE'}`);
  console.log(`   the clear rects (${CLEAR_NAMES.join(', ')}): worst ${pct(clear.inClear)} of a frame's z ink inside one (${clear.key}) against ${pct(bounds.clearance)} — ${okClear ? 'clear' : 'IN THE FURNITURE'}`);
  console.log(`   the count: the fewest z's a frame draws is ${fewest}, against ${Z3.count} — ${okCount ? 'three' : 'NOT THREE'}`);
  console.log(`   the scan's crest tops (the table a layout is judged on; \`creatures.js\`'s CREST_TOP is held at or under these): ${Object.entries(scanTable).map(([k, v]) => `${k} ${v}`).join(', ')}`);
  if (fixed) {
    console.log(`   the chase: ${chase.length === 0 ? 'every seat\'s frames hold the pulse — z\'s fixed, the still at full brightness, the loop\'s levels § 10.4\'s and the brightest z advancing z1, z2, z3' : `${chase.length} defects — ${chase.slice(0, 3).join('; ')}`} — ${okChase ? 'chasing' : 'NOT THE CHASE'}`);
    if (planned.length > 0) {
      const bounds2 = [...new Set(planned.map((r) => r.plan.bound))].join('; ');
      const px = (u) => (u * UNIT_PX * FIT_ZOOM).toFixed(1);
      const span = (xs, d = 1) => (lo(xs) === hi(xs) ? lo(xs).toFixed(d) : `${lo(xs).toFixed(d)}–${hi(xs).toFixed(d)}`);
      console.log(`   the sizes: L ${span(planned.map((r) => r.plan.L))} over ${planned.length} seats; z1 ${span(zOf(0))}, z2 ${span(zOf(1))}, z3 ${span(zOf(2))} units = ${px(lo(zOf(0)))}${lo(zOf(0)) === hi(zOf(0)) ? '' : `–${px(hi(zOf(0)))}`}, ${px(lo(zOf(1)))}${lo(zOf(1)) === hi(zOf(1)) ? '' : `–${px(hi(zOf(1)))}`}, ${px(lo(zOf(2)))}${lo(zOf(2)) === hi(zOf(2)) ? '' : `–${px(hi(zOf(2)))}`} CSS px at the fit (${FIT_ZOOM} × ${UNIT_PX} = ${(FIT_ZOOM * UNIT_PX).toFixed(3)} px a unit); the next half unit is stopped by: ${bounds2}`);
    }
    console.log(`   the law: ${law.length === 0 ? 'every seat\'s three z\'s stand where above3Fixed puts them for the seat\'s crest top and roll' : `${law.length} defects — ${law.slice(0, 3).join('; ')}`} — ${okLaw ? 'held' : 'NOT THE LAW'}`);
    console.log(`   the glow: ${haloOnBody} px of a halo on the sleeper (0 allowed); the tree's lamp colour ${treeLamp ?? 'is not declared (a layout is judged on --lamp)'} against --lamp ${cssLamp} — ${okGlow ? 'clear and in the palette' : 'ON THE SLEEPER OR OFF THE PALETTE'}`);
    console.log(`   the brightness: ${range.line} — ${okRange ? 'legible at the dim end' : 'TOO DIM OR TOO ALIKE'}`);
    if (flash !== null) console.log(`   the flash (SC 2.3.1): ${flash.line} — ${okFlash ? 'within' : 'FLASHES'}`);
  }
  console.log(`   the crest table: ${hasTable ? `${crestDefects.length} scanned keys reach above the tree's CREST_TOP` : layout === null ? 'the tree exports none' : 'NOT CHECKED — the tree exports no CREST_TOP, so a layout is judged on the scan\'s own table and nothing holds the tree\'s'}${okCrest ? '' : ` — ${crestDefects.slice(0, 3).join('; ')}`}`);
  for (const m of mirror.slice(0, 3)) console.log(`   MIRROR DRIFT — zMarkup() no longer draws what creatures.js's zSvg() drew: ${m}`);
  console.log(`   ${ok ? 'PASS' : 'FAIL'}${measured ? '' : '  — NOT MEASURED (too few seats or z pixels)'}${ok && !hasTable ? '  (the tree\'s crest table NOT CHECKED: the tree exports none)' : ''}`);
  return { ok, okOver, okOnz, okFace, okCount, okClear, okCrest, okChase, okLaw, okRange, okFlash, okGlow, measured, overSpecies, rows, chase, law };
}

/**
 * Until every image on the page has decoded — a capture taken after a fixed delay showed a strip's last rows blank while
 * their data URIs were still decoding (card#12187). An image that cannot decode throws, never captures as a blank.
 */
async function allDecoded(b) {
  await b.evaluate('Promise.all([...document.images].map((i) => i.decode())).then(() => true)');
}

/**
 * A sheet of the WORST over-head seat of every species, at the frame it measured worst — from the measured rows, so the
 * picture is the case the figure stands for, never a seat the figure did not pick.
 */
async function sheet(chrome, tree, layout, out, rows) {
  const C = await import(pathToFileURL(join(tree, 'index.js')).href);
  const env = { clear: await clearRects(C) };
  // The worst seat of a species: the one its z's overlap the sleeper most; for a fixed layout, where none does, the one whose z's are SMALLEST (the least room).
  const worse = (r, a) => (a === null ? true : r.fixed ? r.zLarge < a.zLarge : r.overHead > a.overHead);
  const worst = C.SPECIES_KEYS.map((sp) => rows.filter((r) => r.sp === sp).reduce((a, r) => (worse(r, a) ? r : a), null)).filter((r) => r !== null);
  const docs = worst.map((r) => framesOf(C, { sp: r.sp, key: r.key }, layout, [], { ...env, crown: r.crown })[r.fixed ? 0 : r.worstFrame >= 0 ? r.worstFrame : (layout === null ? 0 : PHASES / 2)].doc);
  const W = Math.round(SHOT_PX * VIEW.w / 108), H = Math.round(W * VIEW.h / VIEW.w);
  const cols = 4;
  const cell = (d) => `<div style="background:#efe4d0;border-radius:6px"><img style="width:${W}px;height:${H}px;display:block" src="data:image/svg+xml;charset=utf-8,${encodeURIComponent(d)}"></div>`;
  const html = `<!doctype html><title>x</title><body style="margin:8px;background:#fff;display:grid;grid-template-columns:repeat(${cols},${W}px);gap:6px">${docs.map(cell).join('')}</body>`;
  const b = await browser(chrome, cols * (W + 6) + 16, Math.ceil(docs.length / cols) * (H + 6) + 16, 'ready');
  try {
    await b.load(`data:text/html,${encodeURIComponent(html.replace('<title>x</title>', '<title>ready</title>'))}`);
    await allDecoded(b);
    const png = await b.shot();
    writeFileSync(out, Buffer.from(png.split(',')[1], 'base64'));
  } finally {
    b.close();
  }
  console.log(`sheet: ${out} (${docs.length} sleepers, each species' worst seat — most over the sleeper, or for a fixed layout the one with the smallest z's — at its worst frame, or at the still / canonical frame when none overlaps: ${worst.map((r) => `${r.key}#${r.worstFrame >= 0 ? r.worstFrame : 'canonical'}`).join(', ')}; ${layout ?? 'the tree\'s own'} z's)`);
}

/**
 * A FRAME STRIP: every document a seat draws — the still, then the loop's frames in order — side by side, for each species
 * named, so the chase can be read as the reader would see it step. The first key of each species, at the frames the tree (or
 * the named layout) draws; the cells at `SHOT_PX` px per 108 frame units.
 */
async function strip(chrome, tree, layout, out, species) {
  const C = await import(pathToFileURL(join(tree, 'index.js')).href);
  const env = { clear: await clearRects(C) };
  const rowsDocs = [];
  for (const sp of species) {
    const key = `sleep/${sp}/any/0`;
    const variant = C.recipe(key, sp).variant;
    let crown = (C.CREST_TOP ?? {})[variant ? `${sp}/${variant}` : sp];
    if (crown === undefined) {
      const probe = await browser(chrome, 300, 500, 'ready');
      try {
        await probe.load('data:text/html,<title>ready</title>');
        const doc = C.sleepDocument(key, { species: sp, frame: 0, deskTop: DESK_TOP, clear: env.clear });
        crown = (await probe.evaluate(`(${PAGE_CREST})(${JSON.stringify({ body: withoutZs(inView(doc)), w: VIEW.w, h: VIEW.h, scale: SCALE, alpha: ALPHA, eyesRow: poseOf(doc).eyes - 8, vy: VIEW.y })})`)).top;
      } finally {
        probe.close();
      }
    }
    rowsDocs.push(framesOf(C, { sp, key }, layout, [], { ...env, crown }).map((fr) => fr.doc));
  }
  const W = Math.round(SHOT_PX * VIEW.w / 108), H = Math.round(W * VIEW.h / VIEW.w);
  const cols = rowsDocs[0].length;
  const cell = (d, i) => `<div style="background:#e8dcc4;border-radius:4px;position:relative"><img style="width:${W}px;height:${H}px;display:block" src="data:image/svg+xml;charset=utf-8,${encodeURIComponent(d)}"><span style="position:absolute;left:3px;top:1px;font:10px sans-serif;color:#3b2f2a">${i === 0 ? 'still' : `f${i - 1}`}</span></div>`;
  const html = `<!doctype html><title>ready</title><body style="margin:6px;background:#fff;display:grid;grid-template-columns:repeat(${cols},${W}px);gap:4px">${rowsDocs.flatMap((r) => r.map(cell)).join('')}</body>`;
  const b = await browser(chrome, cols * (W + 4) + 12, rowsDocs.length * (H + 4) + 12, 'ready');
  try {
    await b.load(`data:text/html,${encodeURIComponent(html)}`);
    await allDecoded(b);
    writeFileSync(out, Buffer.from((await b.shot()).split(',')[1], 'base64'));
  } finally {
    b.close();
  }
  console.log(`strip: ${out} (${species.join(', ')}; ${cols} frames each — the still, then loop frames 0–${cols - 2}; ${layout ?? 'the tree\'s own'} z's)`);
}

// `--layout two:L,S,fx,tx` — a two-z candidate on the ruled law: from `fx` units off centre on the roll-away side just
// above the eyes, to `tx` units off centre on the roll-away side (negative: the roll's side) at the frame's top.
const custom = LAYOUT?.match(/^(two|three):(\d+),(\d+),(-?\d+),(-?\d+)(?:,(-?\d+))?$/);
if (custom) {
  const [, n, L, S, fx, tx, drop = '0'] = custom;
  LAYOUTS[LAYOUT] = ({ eyes, dir }, phase) => rise({ N: n === 'two' ? 2 : 3, L: +L, S: +S, amp: 0, clamp: true }, phase,
    { x: 54 - dir * +fx, y: eyes - +L / 2 - 2 + +drop }, { x: 54 - dir * +tx, y: halfBox(+S).h + 1 });
}
// `--layout col:L,S,Y` — two z's in the roll-away edge column, the lowest born at a fixed frame height `Y`.
const col = LAYOUT?.match(/^col:(\d+),(\d+),(\d+)$/);
if (col) {
  const [, L, S, Y] = col.map(Number);
  LAYOUTS[LAYOUT] = ({ dir }, phase) => rise({ N: 2, L, S, amp: 0, clamp: true }, phase,
    { x: 54 - dir * 54, y: Y }, { x: 54 - dir * 54, y: halfBox(S).h + 1 });
}
if (LAYOUT !== null && !(LAYOUT in LAYOUTS) && !(LAYOUT in FIXED)) {
  console.error(`FAIL — no layout «${LAYOUT}»; the layouts are ${[...Object.keys(LAYOUTS), ...Object.keys(FIXED)].join(', ')}`);
  process.exit(1);
}
const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const bounds = boundsFromDocument();
FACE_CLEAR = bounds.faceClear;
GLOW = bounds.glow;
HALO = bounds.halo;
HALO_OPACITY = bounds.haloOpacity;
if (arg('--halo-max', null) !== null) HALO[3] = Number(arg('--halo-max', null));
LAMP = cssLamp;
PULSE_FRAMES = bounds.pulseFrames;
STEPS_STATED = bounds.stepsStated;
VIEW = bounds.view;
CLEAR_NAMES = ['monitor', 'props', 'desk', 'trail'];
const judged = report(LAYOUT === null ? `the tree's own frames (${TREE})` : `layout «${LAYOUT}» over the tree's sleepers (${TREE})`, await measure(chrome, TREE, LAYOUT), bounds);
if (SHOT !== null) await sheet(chrome, TREE, LAYOUT, SHOT, judged.rows);
if (STRIP !== null) await strip(chrome, TREE, LAYOUT, STRIP, STRIP_SPECIES);
let failed = !judged.ok;

if (SELFTEST) {
  const control = report('CONTROL — the card#12159 layout; must exceed the overlap and z-on-z bounds', await measure(chrome, TREE, 'card12159'), bounds);
  const column = report('CONTROL — round 3\'s two-z column (`ruled`); must fail the count, the clearance and the overlap', await measure(chrome, TREE, 'ruled'), bounds);
  const onFace = report('CONTROL — z\'s laid over the closed eyes; must fail the face clearance', await measure(chrome, TREE, 'on-face'), bounds);
  const reversed = report('CONTROL — the pulse run top to bottom (z3 first); must fail the chase', await measure(chrome, TREE, 'above3-reversed'), bounds);
  const constant = report('CONTROL — every z always at full brightness (no pulse); must fail the chase', await measure(chrome, TREE, 'above3-constant'), bounds);
  const swapped = report('CONTROL — z1 and z3 exchanged in place (the pulse runs DOWN by position, up by index); must fail the order and the law', await measure(chrome, TREE, 'above3-swapped'), bounds);
  const moved = report('CONTROL — z2 moved 7 units off the law; must fail the law alone', await measure(chrome, TREE, 'above3-moved'), bounds);
  const onTrail = report('CONTROL — z2 laid on the thought trail\'s column, the trail the only clear rect judged; must fail the clearance', await measure(chrome, TREE, 'above3-on-trail'), bounds);
  const haloWide = report('CONTROL — a halo 60 units wide, over the sleeper; must fail the glow', await measure(chrome, TREE, 'above3-halo-wide'), bounds);
  const redHalo = report('CONTROL — a halo off the palette\'s lamp colour; must fail the chase', await measure(chrome, TREE, 'above3-red-halo'), bounds);
  // The overlap controls must red on MORE THAN ONE species: a bound one species' cap alone could trip would tell the
  // ruled z's from the control's on one crest.
  for (const [name, run, okKey, more] of [
    ['overlap (card#12159)', control, 'okOver', (r) => r.overSpecies.length > 1],
    ['z-on-z (card#12159)', control, 'okOnz', () => true],
    ['face', onFace, 'okFace', () => true],
    ['count (the two-z column)', column, 'okCount', () => true],
    ['clearance (the two-z column)', column, 'okClear', () => true],
    ['overlap (the two-z column)', column, 'okOver', (r) => r.overSpecies.length > 1],
    ['chase (reversed pulse)', reversed, 'okChase', () => true],
    ['chase (constant)', constant, 'okChase', () => true],
    ['order (positions swapped, indices kept)', swapped, 'okChase', (r) => r.chase.some((d) => /is not above/.test(d))],
    ['law (positions swapped)', swapped, 'okLaw', () => true],
    ['law (a z moved)', moved, 'okLaw', (r) => r.law.length > 0 && r.okChase && r.okClear],
    ['clearance (the trail\'s rect alone)', onTrail, 'okClear', () => true],
    ['glow (a halo on the sleeper)', haloWide, 'okGlow', () => true],
    ['glow (a halo off the palette)', redHalo, 'okChase', (r) => r.chase.some((d) => /lamp colour/.test(d))],
  ]) {
    const caught = run.measured && !run[okKey] && more(run);
    console.log(caught ? `CONTROL CAUGHT (${name})` : `CONTROL NOT CAUGHT (${name}) — the bound cannot tell the ruled z's from the control`);
    failed = failed || !caught;
  }
  // The checks that are pure functions are controlled by planting what they read: each must red on its plant, and the
  // faithful input beside it must not (a check that reds on everything is no check either).
  const surfaces = surfaceHexes();
  const pure = [
    ['brightness (cores too alike)', !brightnessRange({ glow: [0.02, 0.05, 0.08, 0], surfaces }).ok, brightnessRange({ surfaces }).ok],
    ['brightness (an outline too pale for the floor)', !brightnessRange({ ink: '#b0a8d0', surfaces }).ok, true],
    ['brightness (FLOOR.md stating ratios the tool does not measure)', !brightnessRange({ surfaces, stated: ['1.43, 1.35, 1.20'] }).ok, brightnessRange({ surfaces }).ok],
    ['crest table (a tree that exports none, judged on its own frames)', crestCheck(null, [], null).defects.length > 0, crestCheck(null, [], 'above3').defects.length === 0],
    ['crest table (a top 20 units under the drawing\'s)', crestCheck({ fox: 54 }, [{ tkey: 'fox', key: 'sleep/fox/any/0', top: 34 }], null).defects.length === 1, crestCheck({ fox: 34 }, [{ tkey: 'fox', key: 'sleep/fox/any/0', top: 34 }], null).defects.length === 0],
    ['flash (every z flashing each frame, huge)', !flashCheck({ levelsOf: (f) => (f % 2 ? [0, 0, 0] : [3, 3, 3]), cores: brightnessRange({ surfaces }).cores, sizes: [320, 260, 200] }).ok, flashCheck({ cores: brightnessRange({ surfaces }).cores, sizes: [33, 27, 21] }).ok],
  ];
  for (const [name, caught, faithful] of pure) {
    const good = caught && faithful;
    console.log(good ? `CONTROL CAUGHT (${name})` : `CONTROL NOT CAUGHT (${name}) — ${caught ? 'the faithful input reds as well' : 'the check passes the plant'}`);
    failed = failed || !good;
  }
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

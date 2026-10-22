#!/usr/bin/env node
// THE SLEEPER's Z's — AGAINST ITS OWN HEAD AND AGAINST EACH OTHER — MEASURED ON A RENDER (card#12187;
// `docs/design/FLOOR.md` § 10.4's frame contract item 4 and § 12's *Sleeper z overlap bound* and *Sleeper z-on-z bound*).
//
// For every species and variant of the character tree — at its first `--keys` keys AND at the extremes the population
// holds (the lowest and highest eye line over `--scan-eyes` keys, the widest crest over `--scan-crest`; see `pickSeats()`) — every frame of the sleeper is rasterised
// in headless Chromium — once without its z's, and once per z with nothing but that z — and two shares are read pixel
// by pixel: OVER HEAD, the share of the z's ink that lands on the sleeper's own ink above its eye line (where the ears,
// leaves and caps are); and Z ON Z, the share of the z's ink covered by two z's or more. The worst frame of the worst
// key per species is reported; each species is held under § 12's over-head bound or the residual the row names for it,
// and the worst of all under the z-on-z and face bounds — every bound read from the document, never copied here. A z's box, its outline stroke included, must also stay inside the frame.
//
//   node tools/design/sleeper-zs.browser.mjs                       # judge the tree's own frames against § 12
//   node tools/design/sleeper-zs.browser.mjs --layout <name>       # re-lay the z's by a LAYOUTS entry and judge that
//   node tools/design/sleeper-zs.browser.mjs --selftest            # …and require the card#12159 layout to red BOTH bounds, the over-head one on more than one species
//   node tools/design/sleeper-zs.browser.mjs --tree <dir>          # judge another tree
//   node tools/design/sleeper-zs.browser.mjs --keys 4 --phases 16  # first keys per species and variant (3); phases per layout loop (16)
//   …and --scan-eyes 400 --scan-crest 100: the keys scanned per species and variant for the EXTREMES the run adds (below)
//   --shot <png> [--shot-px 108]    # save the sheet: each species' worst over-head seat at its worst frame; 49 draws it at the floor's fit size on a 1,440 window
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// ⛔ CANDIDATES AND CONTROLS ARE PLANTED BY SEMANTICS, NOT BY SOURCE EDITS. A `--layout` run keeps the tree's sleeper —
// its body, eye line and roll — and REPLACES its z's with the ones a LAYOUTS entry places, drawn by `zMarkup()`, which
// mirrors `creatures.js`'s `zSvg()`; the mirror is held on every run (each z the tree drew is re-drawn from its own data
// attributes and must come out the same drawing, every number within the attributes' rounding — `MIRROR DRIFT` otherwise). So the figures § 10.4 and § 12 rest on are
// re-printed by `--layout card12159`, `--layout r1-column` and `--layout ruled` against any tree, before or after the
// build, and the selftest's control is the card#12159 layout whatever the tree's own code says.
//
// ⛔ WHAT IS MEASURED IS INK, NOT BOXES — and what it cannot see is whether the result LOOKS tangled: one figure covers a
// smooth cap edge and a leaf's tip alike. The figure bounds the worst case; the screenshot (`--shot <png>`, a sheet of
// every species at one phase) is the last word. ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP, AND THIS IS NOT WIRED IN CI (a
// stock runner carries no Chromium — `tools/design/README.md` names the browser tools and why).

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
/** The desk's top edge as the floor hands it: `deskRects()`'s character rect is 96 tall with the desk's top 70 down it. */
const DESK_TOP = 70 / 96;
/** Frame units per raster px. */
const SCALE = 2;
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
  return { overHead: read('Sleeper z overlap bound'), residuals, zOnZ: read('Sleeper z-on-z bound'), faceClear: Number(face[1]), face: Number(face[2]) / 100 };
}
/** How far from the closed eyes' ink a z's ink must stay, in frame units — § 12's *Sleeper z face clearance*. */
let FACE_CLEAR = NaN;

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
const zsOf = (doc) => [...doc.matchAll(Z_RE)].map((m) => ({ markup: m[0], t: +m[1], x: +m[2], y: +m[3], h: +m[4], o: +m[5], ink: m[6], fill: m[7] }));
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

/** The opacity law `sleepZs()` fades a z by along its rise `t` in [0, 1). */
const fade = (t) => Math.min(1, t / 0.15, (1 - t) / 0.4);

/**
 * THE LAYOUTS — each a pure function of the sleeper's eye line and roll side and of the loop's phase in [0, 1), placing
 * every z. `card12159` is the path the card replaced (three z's from over the head on the roll's side, 24 → 11 units;
 * its start is today's `min(56, eyes - 16)`, the crown term dropped — the crown is not in the document); `r1-column`
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

/** `sleepZs()`'s law: N z's evenly spaced along from → to, shrinking L → S, fading in and out; optionally clamped into the frame. */
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
/** The widest run of ink in any one raster row above `eyesRow`, in frame units. */
const PAGE_CREST = `async ({ body, w, h, scale, alpha, eyesRow }) => {
  const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(body);
  await img.decode();
  const c = document.createElement('canvas'); c.width = w * scale; c.height = h * scale;
  const g = c.getContext('2d'); g.drawImage(img, 0, 0, c.width, c.height);
  const d = g.getImageData(0, 0, c.width, c.height).data; let best = 0;
  for (let y = 0; y < Math.min(c.height, Math.floor(eyesRow * scale)); y++) {
    let lo = -1, hi = -1;
    for (let x = 0; x < c.width; x++) if (d[(y * c.width + x) * 4 + 3] > alpha) { if (lo < 0) lo = x; hi = x; }
    if (lo >= 0) best = Math.max(best, (hi - lo) / scale);
  }
  return best;
}`;

const PAGE_MEASURE = `async ({ body, eyes, zs, w, h, scale, alpha, eyesRow, clear }) => {
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
  let any = 0, multi = 0, onTop = 0, onAll = 0, onFace = 0;
  for (let i = 0; i < b.length; i++) {
    let n = 0; for (const m of ms) n += m[i];
    if (n === 0) continue;
    any++; if (n > 1) multi++;
    if (face[i]) onFace++;
    if (b[i]) { onAll++; if (Math.floor(i / (w * scale)) / scale < eyesRow) onTop++; }
  }
  return { any, multi, onTop, onAll, onFace, eyeInk };
}`;

/**
 * THE SEATS: not the first few keys — the EXTREMES of the population that matter, per species and variant. For each group
 * the first `--keys` keys, plus the key with the lowest eye line and the one with the highest among `--scan-eyes` keys
 * (the eye line sets the size of a seat's z's and the room they have), plus the key with the widest crest — the widest
 * run of ink above the eye line — among `--scan-crest` keys (the crest is what the z's can overlap). Round 2's review
 * found the first-three-keys sample missing both: a mushroom key 12 at 55.1 % where keys 0–2 read 46 %.
 */
async function pickSeats(b, C) {
  const VIEW = C.SEAT_VIEWBOX;
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
  const seats = [], extremes = [];
  for (const sp of C.SPECIES_KEYS) {
    for (const v of [null, ...variantsOf(sp)]) {
      const picked = new Map();
      const add = (key, why) => { if (key !== null && !picked.has(key)) picked.set(key, why); };
      for (let k = 0; k < KEYS_PER; k += 1) add(keyOf(sp, v, k), 'first');
      let lo = null, hi = null, wide = null;
      for (let k = 0; k < Math.max(SCAN_EYES, SCAN_CREST); k += 1) {
        const key = keyOf(sp, v, k);
        if (key === null) continue;
        const doc = C.sleepDocument(key, { species: sp, frame: 0, deskTop: DESK_TOP });
        const eyes = poseOf(doc).eyes;
        if (k < SCAN_EYES) {
          if (lo === null || eyes < lo.eyes) lo = { key, eyes };
          if (hi === null || eyes > hi.eyes) hi = { key, eyes };
        }
        if (k < SCAN_CREST) {
          const w = await b.evaluate(`(${PAGE_CREST})(${JSON.stringify({ body: withoutZs(doc), w: VIEW.w, h: VIEW.h, scale: SCALE, alpha: ALPHA, eyesRow: eyes - 8 })})`);
          if (wide === null || w > wide.w) wide = { key, w };
        }
      }
      add(lo.key, 'lowest eye line'); add(hi.key, 'highest eye line'); add(wide.key, 'widest crest');
      extremes.push({ sp, v: v ?? '-', lo, hi, wide });
      for (const [key] of picked) seats.push({ sp, v: v ?? '-', key });
    }
  }
  return { seats, extremes };
}

/** Every frame to judge, for one seat: the tree's own, or the base frame's sleeper with a layout's z's at each phase. */
function framesOf(C, seat, layout, mirror) {
  const own = Array.from({ length: C.SLEEP_FRAMES }, (_, frame) => C.sleepDocument(seat.key, { species: seat.sp, frame, deskTop: DESK_TOP }));
  for (const doc of own) {
    for (const z of zsOf(doc)) {
      if (!sameDrawing(zMarkup(z, z.ink, z.fill), z.markup)) mirror.push(`${seat.key}: ${z.markup.slice(0, 120)}…`);
    }
  }
  if (layout === null) return own.map((doc) => ({ doc, pose: poseOf(doc), zs: zsOf(doc) }));
  const base = own[0];
  const pose = poseOf(base);
  const [{ ink, fill }] = zsOf(base);
  return Array.from({ length: PHASES }, (_, i) => {
    const zs = LAYOUTS[layout](pose, i / PHASES).map((z) => ({ ...z, markup: zMarkup(z, ink, fill) }));
    return { doc: base.replace(/<g data-part="zs">[\s\S]*<\/g><\/svg>$/, `<g data-part="zs">${zs.map((z) => z.markup).join('')}</g></svg>`), pose, zs };
  });
}

async function measure(chrome, tree, layout) {
  const C = await import(pathToFileURL(join(tree, 'index.js')).href);
  const VIEW = C.SEAT_VIEWBOX;
  const b = await browser(chrome, 300, 500, 'ready');
  const rows = [];
  const mirror = [];
  const picks = [];
  try {
    await b.load('data:text/html,<title>ready</title>');
    const { seats, extremes } = await pickSeats(b, C);
    picks.push(...extremes);
    for (const seat of seats) {
      const row = { ...seat, overHead: 0, overAll: 0, zOnZ: 0, onFace: 0, eyeInk: Infinity, worstFrame: -1, clipped: 0, zLarge: 0, opaqueLarge: 0, zSmall: Infinity, zpx: 0 };
      const frames = framesOf(C, seat, layout, mirror);
      for (const [i, { doc, pose, zs }] of frames.entries()) {
        for (const z of zs) {
          row.zLarge = Math.max(row.zLarge, z.h);
          row.zSmall = Math.min(row.zSmall, z.h);
          if (z.o >= 1 && (layout === null || i % (PHASES / 8) === 0)) row.opaqueLarge = Math.max(row.opaqueLarge, z.h);
          const hb = halfBox(z.h);
          if (z.y - hb.h < VIEW.y || z.x - hb.w < VIEW.x || z.x + hb.w > VIEW.x + VIEW.w) row.clipped += 1;
        }
        const r = await b.evaluate(`(${PAGE_MEASURE})(${JSON.stringify({
          body: withoutZs(doc), eyes: eyesOnly(doc), clear: FACE_CLEAR, zs: zs.map((z) => onlyZ(doc, z.markup)), w: VIEW.w, h: VIEW.h, scale: SCALE, alpha: ALPHA, eyesRow: pose.eyes - 8,
        })})`);
        row.zpx += r.any;
        const over = r.any ? r.onTop / r.any : 0, onz = r.any ? r.multi / r.any : 0;
        if (over > row.overHead) { row.overHead = over; row.worstFrame = i; }
        row.zOnZ = Math.max(row.zOnZ, onz);
        row.overAll = Math.max(row.overAll, r.any ? r.onAll / r.any : 0);
        row.onFace = Math.max(row.onFace, r.any ? r.onFace / r.any : 0);
        row.eyeInk = Math.min(row.eyeInk, r.eyeInk);
      }
      rows.push(row);
    }
  } finally {
    b.close();
  }
  return { rows, picks, species: C.SPECIES_KEYS, mirror, framesPer: layout === null ? C.SLEEP_FRAMES : PHASES };
}

function report(label, { rows, picks, species, mirror, framesPer }, bounds) {
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
  const ok = measured && okOver && okOnz && okFace && clipped === 0 && mirror.length === 0;
  console.log(`── ${label}: ${rows.length} seats × ${framesPer} frames, ${zpx} z px read; z heights ${Math.min(...rows.map((r) => r.zSmall)).toFixed(1)}–${Math.max(...rows.map((r) => r.zLarge)).toFixed(1)} units, the largest fully opaque AT THE LOOP'S OWN FRAMES ${Math.min(...rows.map((r) => r.opaqueLarge)).toFixed(1)}–${Math.max(...rows.map((r) => r.opaqueLarge)).toFixed(1)} by seat (the largest drawn ${Math.min(...rows.map((r) => r.zLarge)).toFixed(1)}–${Math.max(...rows.map((r) => r.zLarge)).toFixed(1)}); z boxes leaving the frame ${clipped}`);
  console.log(`   seats: ${rows.length}, chosen by the extremes of ${SCAN_EYES} keys' eye lines (${Math.min(...eyesAll).toFixed(1)}–${Math.max(...eyesAll).toFixed(1)} units over the population) and ${SCAN_CREST} keys' crests (widest ${Math.max(...picks.map((p) => p.wide.w)).toFixed(0)} units)`);
  for (const sp of species) {
    const mine = rows.filter((r) => r.sp === sp);
    console.log(`   ${sp.padEnd(9)} over head ${pct(worstBy('overHead', mine).overHead).padStart(6)} of ${pct(boundFor(sp)).padStart(6)}${overSpecies.includes(sp) ? ' OVER' : '     '}   z on z ${pct(worstBy('zOnZ', mine).zOnZ).padStart(6)}   face ${pct(worstBy('onFace', mine).onFace).padStart(6)}   anywhere on the sleeper ${pct(worstBy('overAll', mine).overAll).padStart(6)}   (${worstBy('overHead', mine).key})`);
  }
  console.log(`   over head: worst ${pct(over.overHead)} (${over.key}); every species against ${pct(bounds.overHead)}${bounds.residuals.map((r) => `, the ${r.species} a residual at ${pct(r.bound)}`).join('')} — ${okOver ? 'within' : `OVER on ${overSpecies.join(', ') || '—'}`}${unknownResidual.length ? ` — a residual names no species of the tree: ${unknownResidual.join(', ')}` : ''}`);
  console.log(`   z on z:    worst ${pct(onz.zOnZ)} (${onz.key}) against ${pct(bounds.zOnZ)} — ${okOnz ? 'within' : 'OVER'}`);
  console.log(`   the face:  worst ${pct(face.onFace)} of a frame's z ink within ${bounds.faceClear} units of the closed eyes (${face.key}) against ${pct(bounds.face)} — ${okFace ? 'clear' : 'ON THE FACE'}`);
  for (const m of mirror.slice(0, 3)) console.log(`   MIRROR DRIFT — zMarkup() no longer draws what creatures.js's zSvg() drew: ${m}`);
  console.log(`   ${ok ? 'PASS' : 'FAIL'}${measured ? '' : '  — NOT MEASURED (too few seats or z pixels)'}`);
  return { ok, okOver, okOnz, okFace, measured, overSpecies, rows };
}

/**
 * A sheet of the WORST over-head seat of every species, at the frame it measured worst — from the measured rows, so the
 * picture is the case the figure stands for, never a seat the figure did not pick.
 */
async function sheet(chrome, tree, layout, out, rows) {
  const C = await import(pathToFileURL(join(tree, 'index.js')).href);
  const worst = C.SPECIES_KEYS.map((sp) => rows.filter((r) => r.sp === sp).reduce((a, r) => (a === null || r.overHead > a.overHead ? r : a), null)).filter((r) => r !== null);
  const docs = worst.map((r) => framesOf(C, { sp: r.sp, key: r.key }, layout, [])[Math.max(0, r.worstFrame)].doc);
  const W = SHOT_PX, H = Math.round(SHOT_PX * 192 / 108);
  const cell = (d) => `<div style="background:#efe4d0;border-radius:6px"><img style="width:${W}px;height:${H}px;display:block" src="data:image/svg+xml;charset=utf-8,${encodeURIComponent(d)}"></div>`;
  const html = `<!doctype html><title>x</title><body style="margin:8px;background:#fff;display:grid;grid-template-columns:repeat(8,${W}px);gap:6px">${docs.map(cell).join('')}</body>`;
  const b = await browser(chrome, 8 * (W + 6) + 16, Math.ceil(docs.length / 8) * (H + 6) + 16, 'ready');
  try {
    await b.load(`data:text/html,${encodeURIComponent(html.replace('<title>x</title>', '<title>ready</title>'))}`);
    await new Promise((r) => setTimeout(r, 300));
    const png = await b.shot();
    writeFileSync(out, Buffer.from(png.split(',')[1], 'base64'));
  } finally {
    b.close();
  }
  console.log(`sheet: ${out} (${docs.length} sleepers, each species' worst over-head seat at its worst frame: ${worst.map((r) => `${r.key}#${r.worstFrame}`).join(', ')}; ${layout ?? 'the tree\'s own'} z's)`);
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
if (LAYOUT !== null && !(LAYOUT in LAYOUTS)) {
  console.error(`FAIL — no layout «${LAYOUT}»; the layouts are ${Object.keys(LAYOUTS).join(', ')}`);
  process.exit(1);
}
const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const bounds = boundsFromDocument();
FACE_CLEAR = bounds.faceClear;
const judged = report(LAYOUT === null ? `the tree's own frames (${TREE})` : `layout «${LAYOUT}» over the tree's sleepers (${TREE})`, await measure(chrome, TREE, LAYOUT), bounds);
if (SHOT !== null) await sheet(chrome, TREE, LAYOUT, SHOT, judged.rows);
let failed = !judged.ok;

if (SELFTEST) {
  const control = report('CONTROL — the card#12159 layout; must exceed BOTH bounds', await measure(chrome, TREE, 'card12159'), bounds);
  const onFace = report('CONTROL — z\'s laid over the closed eyes; must fail the face clearance', await measure(chrome, TREE, 'on-face'), bounds);
  // The overlap control must red on MORE THAN ONE species: a bound only the mushroom's residual can trip would tell the
  // ruled z's from card#12159's on one cap alone.
  for (const [name, run, okKey, more] of [['overlap', control, 'okOver', (r) => r.overSpecies.length > 1], ['z-on-z', control, 'okOnz', () => true], ['face', onFace, 'okFace', () => true]]) {
    const caught = run.measured && !run[okKey] && more(run);
    console.log(caught ? `CONTROL CAUGHT (${name})` : `CONTROL NOT CAUGHT (${name}) — the bound cannot tell the ruled z's from the card#12159 path`);
    failed = failed || !caught;
  }
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

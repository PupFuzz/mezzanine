#!/usr/bin/env node
// THE SPECIES DISTINCTNESS CHECK — every pair of species told apart by silhouette OR by body colour, measured from
// the generator's own standing frames as headless Chromium draws them (card#12796; docs/design/FLOOR.md § 10.4's
// distinctness bullet). The operator's bar for the list: *"Try to make each character unique from others."*
//
//   node tools/characters/distinct.browser.mjs               # the closest pairs, and a red on any pair under the bar
//   node tools/characters/distinct.browser.mjs --selftest    # the same, plus the planted controls, each required to red
//   node tools/characters/distinct.browser.mjs --all         # every pair, closest first
//   node tools/characters/distinct.browser.mjs --source 3d --selftest   # the 3-D bodies' front silhouettes, the same bar
//
// THE MEASUREMENT. For each species, each of its variants and each of its colourways, ONE CANONICAL STANDING FRAME:
// the first key (`distinct/<species>/<n>`) whose recipe draws that variant and colourway at size 1, girth 1 and tilt
// 0 with no hat, no neckwear, no extra and no blush — the body alone, as `frameDocument()` draws it. Each is
// rasterised in the page exactly as the floor shows it (an <img> over the `data:` URI under encodeURIComponent,
// `floor/painter.js`'s form) at `UNIT` px per frame unit, and read back from a canvas:
// - THE SILHOUETTE: the pixels at least half opaque (the rim, at RIM_OPACITY, and the contact shadow, at most 0.42,
//   both stay under it), in SIL_CELL-unit cells of the frame — IN FRAME UNITS, never fitted to the drawing's own box,
//   because every species stands in one frame on one foot line at one scale. A pair's silhouette similarity is the mask
//   IoU, the HIGHEST over the two species' variants.
// - THE BODY COLOUR, WHERE IT IS: COL_CELL-unit cells, each the mean CIELAB (D65) of its inked pixels where at least
//   half of it is inked; a pair's colour distance is the mean CIEDE2000 ΔE over the cells both frames ink, the LEAST
//   over every colourway of the one against every colourway of the other (a seat can be drawn in any of them). The
//   dominant colour (the most populous BIN-unit CIELAB bin with its 26 neighbours) is printed beside it and gates
//   nothing: a two-tone body's dominant colour is one of its tones.
// THE BAR — a pair is TOO CLOSE when it is alike in both: IoU at least IOU_BAR AND colour ΔE at most DE_BAR. Either
// difference alone tells two creatures apart at a glance; the pair the operator named (card#12795: *"It looks too
// similar to hedgehog"*, the pale cream pumpkin beside the hedgehog's cream body) has neither, and the bar is that
// pair's figures with a margin. THE GATE holds EVERY PAIR of species (card#12842, once the four pairs among the earlier
// species that stood under the bar were redrawn apart; card#12796 had gated only the pairs with a species it appended).
// `--selftest`'s controls, each required to red: dev's whole pumpkin entry (afacf5ce) planted back into a served copy of
// the generator makes pumpkin / hedgehog too close; a species appended to that copy as the hedgehog's twin is too close;
// one difference alone clears a pair; a colour shift reads apart. Why each figure is what it is:
// FLOOR.md § 10.4's distinctness bullet and § 12's *Species distinctness bar*.
//
// `--source 3d` (card#13033 stage 2 slice 3; docs/design/plan-13033-stage2-3d-floor.md § 8 item 2): the same canonical
// keys, each drawn as its 3-D body (`server/public/js/floor3d/creature-paint.js` over `creatures-3d.js`'s part list) from
// the front through `stage3d.js`, an orthographic camera framing exactly the frame's rectangle at the same px a unit — so
// ONE measure and ONE bar judge both generators. Its control is the twin (2): a species appended as the hedgehog's copy,
// `shape` and all, is too close in 3-D too. Dev's pumpkin (1) is the 2-D drawing's control and is not run there — that
// entry predates `shape` and has no 3-D body.
//
// NO BUILD STEP, NO DEPENDENCY, NO NETWORK — `sheet.browser.mjs`'s shape: a static server on 127.0.0.1 serves the
// repository root to the browser `tools/design/headless-chromium.mjs` launches (`--chrome <path>`, `$CHROME`, or the
// Playwright cache), and a missing browser is a FAILURE, never a skip.

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browser, findChrome } from '../design/headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const argv = process.argv.slice(2);

/** Pixels per frame unit the frames are rasterised at. */
const UNIT = 2;
/** The silhouette's cell, a side, in frame units. */
const SIL_CELL = 2;
/** The colour layout's cell, a side, in frame units. */
const COL_CELL = 12;
/** The CIELAB bin, a side, the dominant colour is found in. */
const BIN = 8;
/** A pair is too close at or over this silhouette IoU … */
export const IOU_BAR = 0.82;
/** … and at or under this CIEDE2000 ΔE between its closest colourways. */
export const DE_BAR = 10;

/** The control's planted species, and the existing one it copies. */
const TWIN = 'twinplant', TWIN_OF = 'hedgehog';

/**
 * THE CONTROL's PLANT — the pumpkin as dev drew it at afacf5ce, before card#12795, its whole entry (drawing and
 * colourways) read verbatim from that commit's `resources/characters/creatures.js` into
 * `distinct-control-pumpkin.js.txt` beside this file: the pale cream `#efe2c4` is the colourway the operator saw beside
 * the hedgehog. The served copy of the generator replaces whatever pumpkin entry the tree then holds with that one, so
 * the control stays the pair the operator named however the pumpkin is later redrawn.
 */
const DEV_PUMPKIN = join(HERE, 'distinct-control-pumpkin.js.txt');

// ---- colour ----
const srgb = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
export function lab([r, g, b]) {
  const R = srgb(r), G = srgb(g), B = srgb(b);
  const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047;
  const Y = 0.2126729 * R + 0.7151522 * G + 0.0721750 * B;
  const Z = (0.0193339 * R + 0.1191920 * G + 0.9503041 * B) / 1.08883;
  const f = (t) => (t > 216 / 24389 ? Math.cbrt(t) : (24389 / 27 * t + 16) / 116);
  return [116 * f(Y) - 16, 500 * (f(X) - f(Y)), 200 * (f(Y) - f(Z))];
}
/** CIEDE2000 (Sharma, Wu & Dalal 2005, the formula's published form), kL = kC = kH = 1. */
export function deltaE2000([L1, a1, b1], [L2, a2, b2]) {
  const rad = Math.PI / 180, C1 = Math.hypot(a1, b1), C2 = Math.hypot(a2, b2), Cm = (C1 + C2) / 2;
  const G = 0.5 * (1 - Math.sqrt(Cm ** 7 / (Cm ** 7 + 25 ** 7)));
  const ap1 = a1 * (1 + G), ap2 = a2 * (1 + G), Cp1 = Math.hypot(ap1, b1), Cp2 = Math.hypot(ap2, b2);
  const hue = (b, a) => { if (a === 0 && b === 0) return 0; const h = Math.atan2(b, a) / rad; return h < 0 ? h + 360 : h; };
  const hp1 = hue(b1, ap1), hp2 = hue(b2, ap2);
  const dL = L2 - L1, dC = Cp2 - Cp1;
  let dh = 0;
  if (Cp1 * Cp2 !== 0) { dh = hp2 - hp1; if (dh > 180) dh -= 360; else if (dh < -180) dh += 360; }
  const dH = 2 * Math.sqrt(Cp1 * Cp2) * Math.sin(dh * rad / 2);
  const Lm = (L1 + L2) / 2, Cpm = (Cp1 + Cp2) / 2;
  let hm = hp1 + hp2;
  if (Cp1 * Cp2 !== 0) { hm = Math.abs(hp1 - hp2) <= 180 ? hm / 2 : (hm + 360 < 720 && hp1 + hp2 < 360 ? (hm + 360) / 2 : (hm - 360) / 2); }
  const T = 1 - 0.17 * Math.cos((hm - 30) * rad) + 0.24 * Math.cos(2 * hm * rad) + 0.32 * Math.cos((3 * hm + 6) * rad) - 0.20 * Math.cos((4 * hm - 63) * rad);
  const SL = 1 + 0.015 * (Lm - 50) ** 2 / Math.sqrt(20 + (Lm - 50) ** 2), SC = 1 + 0.045 * Cpm, SH = 1 + 0.015 * Cpm * T;
  const RT = -2 * Math.sqrt(Cpm ** 7 / (Cpm ** 7 + 25 ** 7)) * Math.sin(60 * Math.exp(-(((hm - 275) / 25) ** 2)) * rad);
  return Math.sqrt((dL / SL) ** 2 + (dC / SC) ** 2 + (dH / SH) ** 2 + RT * (dC / SC) * (dH / SH));
}

// ---- one rasterised frame → its silhouette, its colour layout and its dominant colour ----
/**
 * `rgba` a W × H RGBA byte array of one frame at `U` px per frame unit. The INKED pixels are the ones at least half
 * opaque — the rim, at RIM_OPACITY, and the contact shadow, at most 0.42, both stay under it.
 * - `mask`: the frame in SIL_CELL-unit cells, a cell inked where at least half its pixels are — IN FRAME UNITS, never
 *   fitted to the drawing's box, because every species is drawn in one frame on one foot line at one scale, and a
 *   short body beside a tall one is a difference the floor shows;
 * - `cells`: the frame in COL_CELL-unit cells, each the mean CIELAB of its inked pixels where at least half of it is
 *   inked, else null — where on the body each colour is;
 * - `lab`: the dominant colour of the inked pixels, CIELAB binned BIN units a side, the most populous bin's mean with
 *   its 26 neighbours' — reported beside the bar, never gating (a two-tone body's dominant colour is one of its tones).
 */
export function readFrame(rgba, W, H, U) {
  const sc = SIL_CELL * U, cc = COL_CELL * U;
  const sw = Math.floor(W / sc), sh = Math.floor(H / sc), cw = Math.floor(W / cc), ch = Math.floor(H / cc);
  const silN = new Uint32Array(sw * sh), colN = new Uint32Array(cw * ch), colS = new Float64Array(cw * ch * 3);
  const bins = new Map();
  let inked = 0;
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    const i = (y * W + x) * 4;
    if (rgba[i + 3] < 128) continue;
    inked++;
    const L = lab([rgba[i], rgba[i + 1], rgba[i + 2]]);
    const gx = Math.floor(x / sc), gy = Math.floor(y / sc);
    if (gx < sw && gy < sh) silN[gy * sw + gx]++;
    const cx = Math.floor(x / cc), cy = Math.floor(y / cc);
    if (cx < cw && cy < ch) { const c = cy * cw + cx; colN[c]++; colS[c * 3] += L[0]; colS[c * 3 + 1] += L[1]; colS[c * 3 + 2] += L[2]; }
    const k = L.map((v) => Math.floor(v / BIN)).join(',');
    const e = bins.get(k) ?? bins.set(k, { n: 0, s: [0, 0, 0] }).get(k);
    e.n++; e.s[0] += L[0]; e.s[1] += L[1]; e.s[2] += L[2];
  }
  if (inked === 0) return null;
  const mask = Array.from(silN, (n) => (n * 2 >= sc * sc ? 1 : 0));
  const cells = Array.from(colN, (n, c) => (n * 2 >= cc * cc ? [colS[c * 3] / n, colS[c * 3 + 1] / n, colS[c * 3 + 2] / n] : null));
  let best = null;
  for (const [key, e] of bins) {
    const [a, b, c] = key.split(',').map(Number);
    let n = 0; const s = [0, 0, 0];
    for (let da = -1; da <= 1; da++) for (let db = -1; db <= 1; db++) for (let dc = -1; dc <= 1; dc++) {
      const o = bins.get(`${a + da},${b + db},${c + dc}`);
      if (o) { n += o.n; s[0] += o.s[0]; s[1] += o.s[1]; s[2] += o.s[2]; }
    }
    if (best === null || e.n > best.own || (e.n === best.own && n > best.n)) best = { own: e.n, n, lab: s.map((v) => v / n) };
  }
  return { mask, cells, lab: best.lab };
}

export function iou(a, b) {
  let and = 0, or = 0;
  for (let i = 0; i < a.length; i++) { and += a[i] & b[i]; or += a[i] | b[i]; }
  return or === 0 ? 0 : and / or;
}

/** The colour layout distance: the mean CIEDE2000 ΔE over the cells both frames ink, Infinity where none is shared. */
export function layoutDE(a, b) {
  let n = 0, s = 0;
  for (let i = 0; i < a.length; i++) if (a[i] !== null && b[i] !== null) { n++; s += deltaE2000(a[i], b[i]); }
  return n === 0 ? Infinity : s / n;
}

/**
 * Every pair of species: its silhouette IoU, the HIGHEST over their variants, and its colour layout ΔE and dominant
 * ΔE, each the LEAST over every frame of the one against every frame of the other — the worst case a floor can show
 * on each measure, since a seat can be drawn in any variant and any colourway.
 */
export function pairsOf(species) {
  const out = [];
  const keys = Object.keys(species);
  for (let i = 0; i < keys.length; i++) for (let j = i + 1; j < keys.length; j++) {
    const A = species[keys[i]], B = species[keys[j]];
    let sil = { iou: -1 }, col = { de: Infinity }, dom = { de: Infinity };
    for (const a of A) for (const b of B) {
      const s = iou(a.mask, b.mask);
      if (s > sil.iou) sil = { iou: s, a: a.variant, b: b.variant };
      const d = layoutDE(a.cells, b.cells);
      if (d < col.de) col = { de: d, a: a.pal, b: b.pal };
      const m = deltaE2000(a.lab, b.lab);
      if (m < dom.de) dom = { de: m, a: a.pal, b: b.pal };
    }
    out.push({ a: keys[i], b: keys[j], iou: sil.iou, de: col.de, dom: dom.de, pals: [col.a, col.b], variants: [sil.a, sil.b] });
  }
  return out;
}

/** Too close: alike in silhouette AND in colour where the bodies overlap. */
export const tooClose = (p) => p.iou >= IOU_BAR && p.de <= DE_BAR;
/**
 * How far a pair stands from the bar — the larger of its two margins, each a share of its own bar, so a pair is
 * clear when either is positive; the closest pairs are the least.
 */
export const clearance = (p) => Math.max((IOU_BAR - p.iou) / IOU_BAR, (p.de - DE_BAR) / DE_BAR);

// ---- the page ----
const SOURCE = argv.includes('--source') ? argv[argv.indexOf('--source') + 1] : '2d';
if (!['2d', '3d'].includes(SOURCE)) { console.error(`--source is 2d or 3d, not ${SOURCE}`); process.exit(2); }
const PAGE = (base) => `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>loading</title><script type="importmap">{"imports":{"three":"/server/public/js/vendor/three/three.module.js"}}</script></head><body>
<script type="module">
  const C = await import('${base}/index.js');
  const SOURCE = '${SOURCE}';
  // the 3-D source: the body built by the floor's creature renderer, shot from the front over exactly the frame's rectangle
  const three = SOURCE === '3d' ? { P: await import('/server/public/js/floor3d/creature-paint.js'), S: await import('/tools/characters/stage3d.js') } : null;
  const U = ${UNIT}, SV = C.SEAT_VIEWBOX;
  const W = Math.round(SV.w * U), H = Math.round(SV.h * U);
  const canvas = document.createElement('canvas'); canvas.width = W; canvas.height = H;
  const g = canvas.getContext('2d', { willReadFrequently: true });
  const uri = (doc) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(doc);
  // the reader is this module's own readFrame(), handed to the page as source, so node and the page read alike
  const SIL_CELL = ${SIL_CELL}, COL_CELL = ${COL_CELL}, BIN = ${BIN};
  ${deltaE2000.toString()}
  const srgb = ${srgb.toString()};
  ${lab.toString()}
  ${readFrame.toString()}
  // the canonical key of a species, variant and colourway: the body alone, upright, at size 1 and girth 1
  function canonical(sp, variant, pal) {
    for (let n = 0; n < 400000; n++) {
      // the single draws first (seed.js's draw, as recipe() reads them), so the whole recipe is built only for a likely key
      const k = 'distinct/' + sp + '/' + n, S = C.SPECIES[sp];
      if (C.draw(k, 'pal') % S.pals.length !== pal || C.SIZES[C.draw(k, 'size') % C.SIZES.length] !== 1 || C.STOUT[C.draw(k, 'stout') % C.STOUT.length] !== 1
        || C.TILTS[C.draw(k, 'tilt') % C.TILTS.length] !== 0 || C.BLUSH[C.draw(k, 'blush') % C.BLUSH.length] !== 0) continue;
      const r = C.recipe(k, sp);
      if (r.variant !== variant || r.extra !== 'none') continue;
      const d = C.describe(k, sp);
      if (d.variant === variant && d.pal === pal && d.size === 1 && d.stout === 1 && d.tilt === 0 && d.blush === 0
        && d.hat === 'none' && d.neck === 'none' && d.extra === 'none') return k;
    }
    return null;
  }
  window.measure = async (only) => {
    const out = {};
    for (const sp of C.SPECIES_KEYS.filter((k) => only === null || only.includes(k))) {
      const S = C.SPECIES[sp], variants = new Set();
      for (let i = 0; i < 400; i++) variants.add(C.recipe('v/' + sp + '/' + i, sp).variant);
      out[sp] = [];
      for (const variant of variants) for (let pal = 0; pal < S.pals.length; pal++) {
        const key = canonical(sp, variant, pal);
        if (key === null) { out[sp].push({ variant, pal, key: null }); continue; }
        g.clearRect(0, 0, W, H);
        if (three === null) {
          const img = new Image(); img.width = W; img.height = H; img.src = uri(C.frameDocument(key, { species: sp }));
          await img.decode();
          g.drawImage(img, 0, 0, W, H);
        } else {
          const st = window.stage3d ??= three.S.stage(W, H);
          const body = await three.P.createCreature(document, C, key, { species: sp, height: C.SEAT_RECT.h });
          st.shoot(body.mesh, 'front', { x0: SV.x - 54, x1: SV.x + SV.w - 54, y0: C.FOOT - (SV.y + SV.h), y1: C.FOOT - SV.y });
          body.dispose();
          g.drawImage(st.canvas, 0, 0, W, H);
        }
        out[sp].push({ variant, pal, key, read: readFrame(g.getImageData(0, 0, W, H).data, W, H, U) });
      }
    }
    return JSON.stringify({ keys: C.SPECIES_KEYS, out });
  };
  document.title = 'ready';
</script></body></html>`;

const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.html': 'text/html; charset=utf-8' };
const creaturesPath = join(REPO, 'resources', 'characters', 'creatures.js');
/**
 * The control's served copy of the generator, with two plants and nothing else changed: dev's pumpkin entry put
 * back (`DEV_PUMPKIN`, its whole entry), and a species appended to the list — `TWIN`, the hedgehog's drawing and colourways under a
 * new key, `added` like every appended species — a new species copying an existing one.
 */
async function plantedCreatures() {
  const src = await readFile(creaturesPath, 'utf8');
  const at = src.indexOf('\n  pumpkin: {\n');
  const end = at < 0 ? -1 : src.indexOf('\n  },\n', at);
  const list = src.match(/^export const SPECIES_KEYS = Object\.freeze\(\[([^\]\n]*)\]\);$/m);
  const withheld = src.indexOf('\nexport const WITHHELD');
  const doc = withheld < 0 ? -1 : src.lastIndexOf('\n/**', withheld);
  if (end < 0 || list === null || doc < 0) throw new Error('the control\'s plants have no place in this generator (the pumpkin\'s entry, SPECIES_KEYS, WITHHELD)');
  const devPumpkin = (await readFile(DEV_PUMPKIN, 'utf8')).replace(/\n$/, '');
  let planted = `${src.slice(0, at + 1)}${devPumpkin}${src.slice(end + '\n  },'.length)}`;
  planted = planted.replace(list[0], list[0].replace(']);', `, '${TWIN}']);`));
  const d2 = planted.lastIndexOf('\n/**', planted.indexOf('\nexport const WITHHELD'));
  return `${planted.slice(0, d2)}\nAPPROVED['${TWIN}'] = { ...APPROVED.${TWIN_OF}, added: 'control' };${planted.slice(d2)}`;
}

function serve() {
  return createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path === '/__distinct.html') { res.writeHead(200, { 'content-type': TYPES['.html'] }); res.end(PAGE('/resources/characters')); return; }
  if (path === '/__control.html') { res.writeHead(200, { 'content-type': TYPES['.html'] }); res.end(PAGE('/__control')); return; }
  if (path.startsWith('/__control/')) {
    const name = path.slice('/__control/'.length);
    const body = name === 'creatures.js' ? await plantedCreatures() : await readFile(join(REPO, 'resources', 'characters', name)).catch(() => null);
    if (body === null) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': TYPES['.js'] }); res.end(body); return;
  }
  const file = normalize(join(REPO, path));
  if (!file.startsWith(REPO)) { res.writeHead(403); res.end(); return; }
  try { const body = await readFile(file); res.writeHead(200, { 'content-type': TYPES[extname(file)] ?? 'application/octet-stream' }); res.end(body); } catch { res.writeHead(404); res.end(); }
  });
}

/**
 * Measure the species `only` names (every species when null) on `page` — `__distinct.html`, the generator as committed,
 * or `__control.html`, its planted copy — through `server` and the browser at `chrome`.
 */
export async function measure(chrome, server, page, only = null) {
  const url = `http://127.0.0.1:${server.address().port}/${page}`;
  const b = await browser(chrome, 800, 600, 'ready');
  try {
    await b.load(url);
    const { keys, out } = JSON.parse(await b.evaluate(`window.measure(${JSON.stringify(only)})`));
    const species = {}, missing = [];
    for (const [sp, frames] of Object.entries(out)) {
      species[sp] = [];
      for (const fr of frames) {
        if (fr.key === null) { missing.push(`${sp} ${fr.variant ?? ''} colourway ${fr.pal}`); continue; }
        if (fr.read === null) { missing.push(`${sp} ${fr.key}: no ink`); continue; }
        species[sp].push({ variant: fr.variant, pal: fr.pal, key: fr.key, ...fr.read });
      }
    }
    return { species, keys, missing };
  } finally { b.close(); }
}

const fmt = (p) => `${p.a} / ${p.b}: IoU ${p.iou.toFixed(3)}${p.variants.some((v) => v) ? ` (${p.variants.map((v) => v ?? '—').join(' / ')})` : ''}, layout ΔE ${p.de.toFixed(1)} (colourways ${p.pals.join(' / ')}), dominant ΔE ${p.dom.toFixed(1)}${tooClose(p) ? '  ← TOO CLOSE' : ''}`;

let failures = 0;
const check = (cond, what) => { console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${what}`); if (!cond) failures++; };

/** A server for `measure()`, listening on 127.0.0.1. */
export async function listen() { const server = serve(); await new Promise((r) => server.listen(0, '127.0.0.1', r)); return server; }

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
const server = await listen();
try {
  const chrome = findChrome();
  if (!chrome) { console.error('no Chromium found: pass --chrome <path> or set $CHROME'); process.exit(1); }
  const { species, keys, missing } = await measure(chrome, server, '__distinct.html');
  const pairs = pairsOf(species).sort((x, y) => clearance(x) - clearance(y));
  const n = Object.keys(species).length, all = keys.length * (keys.length - 1) / 2;
  console.log(`source: ${SOURCE === '3d' ? 'the 3-D bodies (creature-paint.js), from the front' : 'the 2-D standing frames'}`);
  console.log(`species distinctness over ${n} species — too close at IoU >= ${IOU_BAR} AND colour layout ΔE2000 <= ${DE_BAR} (silhouette in ${SIL_CELL}-unit cells, colour layout in ${COL_CELL}-unit cells, at ${UNIT} px a unit)`);
  console.log(`THE GATE holds every pair of the ${keys.length} species in SPECIES_KEYS: ${pairs.length} pairs`);
  const show = (title, list) => {
    const shown = argv.includes('--all') ? list : list.slice(0, 25);
    console.log(`\n${title} — ${argv.includes('--all') ? 'every pair' : `the ${shown.length} closest`} (least clearance first):`);
    for (const p of shown) console.log(`  ${fmt(p)}`);
  };
  show('THE GATE', pairs);
  console.log('');
  check(missing.length === 0, `every species, variant and colourway drew a canonical standing frame (${missing.length ? missing.slice(0, 4).join('; ') : `${Object.values(species).reduce((sum, l) => sum + l.length, 0)} frames`})`);
  check(n === keys.length && pairs.length === all, `the gate measured every species in SPECIES_KEYS and holds every pair of them (${n} of ${keys.length} species, ${pairs.length} of ${all} pairs)`);
  const close = pairs.filter(tooClose);
  check(close.length === 0, `no pair of species is alike in both silhouette and colour, over ${pairs.length} pairs${close.length ? ` — ${close.map((p) => `${p.a}/${p.b}`).join(', ')}` : ''}`);

  if (argv.includes('--selftest')) {
    console.log('\ncontrols:');
    // (1) dev's pumpkin, planted back: the pale cream colourway beside the hedgehog is the pair the operator named.
    // (2) a planted species appended to the list, copying an existing one, is flagged.
    const planted = await measure(chrome, server, '__control.html', SOURCE === '3d' ? ['hedgehog', TWIN] : ['pumpkin', 'hedgehog', TWIN]);
    if (SOURCE === '2d') {
      const ph = pairsOf({ pumpkin: planted.species.pumpkin, hedgehog: planted.species.hedgehog })[0];
      console.log(`  dev's pumpkin (its afacf5ce entry) / hedgehog: ${fmt(ph)}`);
      check(tooClose(ph), `CONTROL — dev's pumpkin (its afacf5ce entry) planted back makes pumpkin / hedgehog TOO CLOSE (IoU ${ph.iou.toFixed(3)}, ΔE ${ph.de.toFixed(1)} at colourways ${ph.pals.join(' / ')}), so the bar flags the pair the operator named`);
    }
    const twin = pairsOf({ [TWIN]: planted.species[TWIN] ?? [], [TWIN_OF]: planted.species[TWIN_OF] })[0];
    console.log(`  a planted '${TWIN}' appended as a copy of the ${TWIN_OF}: ${fmt(twin)}`);
    check(planted.keys.includes(TWIN) && tooClose(twin), `CONTROL — a species appended to the list as a copy of the ${TWIN_OF} is TOO CLOSE (IoU ${twin.iou.toFixed(3)}, ΔE ${twin.de.toFixed(1)})`);
    // (3) each measure alone clears a pair: the same silhouette in a far colour, a far silhouette in the same colour.
    const sameShape = { iou: 1, de: DE_BAR + 1 }, sameColour = { iou: IOU_BAR - 0.01, de: 0 };
    check(!tooClose(sameShape) && !tooClose(sameColour), 'CONTROL — one difference alone (colour, or silhouette) clears a pair');
    // (4) the colour reader sees a colour change: a species' frames with every cell shifted 40 units along a* read apart.
    const sp0 = species[keys[0]];
    const shifted = sp0.map((fr) => ({ ...fr, cells: fr.cells.map((c) => (c === null ? null : [c[0], c[1] + 40, c[2]])), lab: [fr.lab[0], fr.lab[1] + 40, fr.lab[2]] }));
    const sh = pairsOf({ x: sp0, y: shifted })[0];
    check(sh.iou === 1 && sh.de > DE_BAR, `CONTROL — the ${keys[0]}'s frames shifted 40 units along a* keep IoU ${sh.iou.toFixed(3)} and read ΔE ${sh.de.toFixed(1)} apart, over the bar`);
  }
} finally { server.close(); }
console.log(failures === 0 ? '\nALL PASS' : `\n${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
}

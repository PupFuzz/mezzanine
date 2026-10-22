#!/usr/bin/env node
// THE SLEEPER's Z's AGAINST ITS OWN HEAD — MEASURED ON A RENDER (card#12187; `docs/design/FLOOR.md` § 10.4's frame
// contract item 4 and § 12's *Sleeper z overlap bound*). For every species and variant of the character tree, at `--keys`
// keys each, every frame of the sleeper is rasterised twice in headless Chromium — once without its z's and once with
// nothing but its z's — and the share of the z's ink that lands on the sleeper's own ink ABOVE ITS EYE LINE (where the
// ears, leaves and caps are, as against the cheek) is read pixel by pixel. The worst frame of the worst key per species
// is reported, and the worst of all is held under the bound § 12 states, which this tool reads from the document rather
// than carrying a copy.
//
//   node tools/design/sleeper-zs.browser.mjs                 # judge the shipped tree against § 12's bound
//   node tools/design/sleeper-zs.browser.mjs --selftest      # …and require the pre-card#12187 path to red it
//   node tools/design/sleeper-zs.browser.mjs --tree <dir>    # judge another tree (a scratch copy with other constants)
//   node tools/design/sleeper-zs.browser.mjs --keys 4        # keys per species and variant (default 4)
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// ⛔ WHAT IS MEASURED IS INK, NOT BOXES: a z's bbox over a leaf's bbox says nothing about whether the two touch, so this
// tool draws them. ⛔ WHAT IT CANNOT SEE: whether the result LOOKS tangled — a 40 % overlap on a smooth cap edge and a
// 10 % overlap through a leaf's tip are different pictures with one figure; the figure bounds the worst case, the
// screenshot the coder posts is the last word. ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP, AND IT IS NOT WIRED IN CI (a
// stock runner carries no Chromium — `.github/workflows/design-doc-verifiers.yml` names the browser tools and why).
//
// ⛔ THE SELFTEST PLANTS THE PATH card#12187 REPLACED — the z's starting over the head on the roll's side — and requires
// the bound to red on it; the plant is anchored on the column's own `from` line in `creatures.js`, and an anchor that is
// gone is a loud failure, never a vacuous pass. It also reports the per-species and per-frame reads, so a run that
// rasterised nothing (0 glyph px) reads as such.

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const args = process.argv.slice(2);
const arg = (name, fallback) => (args.includes(name) ? args[args.indexOf(name) + 1] : fallback);
const TREE = arg('--tree', join(ROOT, 'resources', 'characters'));
const KEYS_PER = Number(arg('--keys', 4));
const SELFTEST = args.includes('--selftest');
/** The desk's top edge as the floor hands it: `deskRects()`'s character rect is 96 tall with the desk's top 70 down it. */
const DESK_TOP = 70 / 96;
/** Frame units per raster px. */
const SCALE = 2;

/** § 12's *Sleeper z overlap bound*, read from the document — never a copy here. */
function boundFromDocument() {
  const md = readFileSync(join(ROOT, 'docs', 'design', 'FLOOR.md'), 'utf8');
  const m = md.match(/^\| \*\*Sleeper z overlap bound\*\* \| \*\*(\d+) %\*\* /m);
  if (m === null) throw new Error('FLOOR.md § 12 carries no *Sleeper z overlap bound* row in the form this tool reads');
  return Number(m[1]) / 100;
}

/** The path card#12187 replaced, planted on the column's `from` line: the z's start over the head on the roll's side. */
const PLANT = {
  anchor: '54 - dir * (54 - Z_LARGE * 0.4 - 2)',
  replacement: '54 + dir * 2',
};

const zsOf = (doc) => [...doc.matchAll(/<g data-part="z" data-t="([\d.-]+)" data-x="([\d.-]+)" data-y="([\d.-]+)" data-h="([\d.-]+)" opacity="([\d.-]+)">/g)]
  .map((m) => ({ t: +m[1], x: +m[2], y: +m[3], h: +m[4], o: +m[5] }));
const withoutZs = (doc) => doc.replace(/<g data-part="zs">[\s\S]*<\/g><\/svg>$/, '</svg>');
const zsOnly = (doc) => doc.replace(/<g data-pose="asleep"[\s\S]*?<\/g><g data-part="zs">/, '<g data-part="zs">');

/** The alpha mask of one SVG document, drawn at `SCALE` px per unit in the page. */
const RASTER = (svg, w, h) => `(async () => {
  const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(${JSON.stringify(svg)});
  await img.decode();
  const c = document.createElement('canvas'); c.width = ${w * SCALE}; c.height = ${h * SCALE};
  const g = c.getContext('2d'); g.drawImage(img, 0, 0, c.width, c.height);
  const d = g.getImageData(0, 0, c.width, c.height).data; const out = [];
  for (let i = 3; i < d.length; i += 4) out.push(d[i] > 40 ? 1 : 0);
  return out;
})()`;

/**
 * Every species × variant × key of a tree, measured: the worst share of z ink on sleeper ink above the eye line over the
 * frames, the largest and smallest z drawn, whether any z's bbox left the frame, and how many z pixels were read.
 */
async function measure(chrome, tree, plant) {
  const C = await import(pathToFileURL(join(tree, 'index.js')).href + (plant ? `?plant=${Date.now()}` : ''));
  const VIEW = C.SEAT_VIEWBOX;
  const variantsOf = (sp) => {
    const seen = new Set();
    for (let i = 0; i < 300; i += 1) seen.add(C.recipe(`v/${sp}/${i}`, sp).variant);
    return [...seen].filter((v) => v !== null);
  };
  const b = await browser(chrome, 300, 500, 'ready');
  const rows = [];
  try {
    await b.load('data:text/html,<title>ready</title>');
    for (const sp of C.SPECIES_KEYS) {
      for (const v of [null, ...variantsOf(sp)]) {
        for (let k = 0; k < KEYS_PER; k += 1) {
          let key = `sleep/${sp}/${v ?? 'any'}/${k}`;
          for (let i = 0; v !== null && C.recipe(key, sp).variant !== v && i < 400; i += 1) key = `sleep/${sp}/${v}/${k}/${i}`;
          if (v !== null && C.recipe(key, sp).variant !== v) continue;
          const row = { sp, v: v ?? '-', key, worst: 0, worstFrame: -1, clipped: 0, zLarge: 0, zSmall: Infinity, zpx: 0 };
          for (let frame = 0; frame < C.SLEEP_FRAMES; frame += 1) {
            const doc = C.sleepDocument(key, { species: sp, frame, deskTop: DESK_TOP });
            const eyes = Number((doc.match(/data-eyes-y="([\d.-]+)"/) ?? [0, NaN])[1]);
            for (const z of zsOf(doc)) {
              row.zLarge = Math.max(row.zLarge, z.h);
              row.zSmall = Math.min(row.zSmall, z.h);
              if (z.y - z.h / 2 < VIEW.y || z.x - z.h * 0.4 < VIEW.x || z.x + z.h * 0.4 > VIEW.x + VIEW.w) row.clipped += 1;
            }
            const body = await b.evaluate(RASTER(withoutZs(doc), VIEW.w, VIEW.h));
            const zz = await b.evaluate(RASTER(zsOnly(doc), VIEW.w, VIEW.h));
            let zpx = 0, onTop = 0;
            for (let i = 0; i < zz.length; i += 1) {
              if (!zz[i]) continue;
              zpx += 1;
              if (body[i] && Math.floor(i / (VIEW.w * SCALE)) / SCALE < eyes - 8) onTop += 1;
            }
            row.zpx += zpx;
            const share = zpx ? onTop / zpx : 0;
            if (share > row.worst) { row.worst = share; row.worstFrame = frame; }
          }
          rows.push(row);
        }
      }
    }
  } finally {
    b.close();
  }
  return { rows, species: C.SPECIES_KEYS };
}

/** A tree copy with the plant applied — under the OS temp dir, so the shipped tree is never written. */
async function plantedTree() {
  const { mkdtempSync, cpSync, writeFileSync } = await import('node:fs');
  const { tmpdir } = await import('node:os');
  const dir = mkdtempSync(join(tmpdir(), 'sleeper-zs-plant-'));
  cpSync(TREE, dir, { recursive: true });
  const file = join(dir, 'creatures.js');
  const src = readFileSync(file, 'utf8');
  if (!src.includes(PLANT.anchor)) throw new Error(`the planted defect's anchor is gone from creatures.js — the selftest would plant nothing: ${PLANT.anchor}`);
  writeFileSync(file, src.replace(PLANT.anchor, PLANT.replacement));
  return dir;
}

function report(label, { rows, species }, bound) {
  const bySpecies = species.map((sp) => {
    const mine = rows.filter((r) => r.sp === sp);
    const w = mine.reduce((a, r) => (r.worst > a.worst ? r : a), mine[0]);
    return `${sp} ${(w.worst * 100).toFixed(1)}% (${w.key} f${w.worstFrame})`;
  });
  const worst = rows.reduce((a, r) => (r.worst > a.worst ? r : a), rows[0]);
  const zpx = rows.reduce((a, r) => a + r.zpx, 0);
  const clipped = rows.reduce((a, r) => a + r.clipped, 0);
  const measured = rows.length >= species.length * KEYS_PER && zpx > 1000 * rows.length;
  const ok = measured && worst.worst <= bound && clipped === 0;
  console.log(`── ${label}: ${rows.length} rows (species × variant × ${KEYS_PER} keys), ${zpx} z px read, z heights ${Math.min(...rows.map((r) => r.zSmall))}–${Math.max(...rows.map((r) => r.zLarge))} units, clipped z's ${clipped}`);
  for (const line of bySpecies) console.log(`   ${line}`);
  console.log(`   worst ${(worst.worst * 100).toFixed(1)}% (${worst.key}) against the bound ${bound * 100}% — ${ok ? 'PASS' : 'FAIL'}${measured ? '' : '  — NOT MEASURED'}`);
  return { ok, worst: worst.worst };
}

const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const bound = boundFromDocument();
const shipped = report(`the tree at ${TREE}`, await measure(chrome, TREE, false), bound);
let failed = !shipped.ok;

if (SELFTEST) {
  const dir = await plantedTree();
  const planted = report('CONTROL (the pre-card#12187 path: the z\'s over the head on the roll\'s side) — must FAIL', await measure(chrome, dir, true), bound);
  const caught = !planted.ok && planted.worst > bound;
  console.log(caught ? 'CONTROL CAUGHT (overlap)' : 'CONTROL NOT CAUGHT (overlap) — the bound cannot tell the column from the path it replaced');
  failed = failed || !caught;
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

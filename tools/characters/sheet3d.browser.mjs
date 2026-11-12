#!/usr/bin/env node
// THE 3-D CHARACTER SHEET, AS A PNG — every species as `server/public/js/floor3d/creature-paint.js` builds it from
// `resources/characters/creatures-3d.js`'s part list, rendered by headless Chromium from the front, the side, above and
// three-quarters, beside its 2-D standing frame and its intern (card#13033 stage 2 slice 3;
// docs/design/plan-13033-stage2-3d-floor.md § 3.2 and § 9 slice 3). It is the operator's reading of the bodies — where
// the code-built toy look is judged, before any page rests on it — and, with `--calls`, the draw-call leg.
//
//   node tools/characters/sheet3d.browser.mjs --out sheet3d.png                   # every species, every view
//   node tools/characters/sheet3d.browser.mjs --out animals.png --kind animal     # one kind
//   node tools/characters/sheet3d.browser.mjs --calls                             # one seat ≤ 1 draw call, one intern ≤ 2
//   node tools/characters/sheet3d.browser.mjs --calls --selftest                  # the same, and each plant seen red
//
// THE DRAW-CALL LEG (`--calls`; plan § 3.3, § 6's `S + 2 I` term, round 3's M3): a page holding ONE character — a seat
// alone, then an intern with its laptop alone — rendered once, `renderer.info.render.calls` read after the render. A
// seat is one call (its body and its face one material), an intern two (its laptop the second). `--selftest` serves
// planted copies of `creature-paint.js` and requires each to red: the face given a material of its own (the seat reads
// two), and the laptop's parts each a group of their own (the intern reads more than two); and a control that the page
// drew something at all (the seat's front picture inked).
//
// NO BUILD STEP, NO DEPENDENCY, NO NETWORK: `sheet.browser.mjs`'s shape — a static server on 127.0.0.1 serves the
// repository root to the browser `tools/design/headless-chromium.mjs` launches (`--chrome <path>`, `$CHROME`, or the
// Playwright cache); a missing browser is a failure, never a skip.

import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from '../design/headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const argv = process.argv.slice(2);
const argOf = (flag, fallback = null) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : fallback; };
const out = argOf('--out'), calls = argv.includes('--calls');
if (!out && !calls) { console.error('usage: sheet3d.browser.mjs --out <png> [--kind animal|vegetable|fruit] [--cell px] | --calls [--selftest] [--chrome path]'); process.exit(2); }

const PAINT = '/server/public/js/floor3d/creature-paint.js';
/**
 * The planted copies `--calls --selftest` serves, each one textual change to `creature-paint.js` and its relative imports
 * made absolute (so the copy shares the page's one Three). Each must make the leg red.
 */
const PLANTS = {
  // the face a material of its own: the seat's body and face are two groups, two calls
  'face-own-material': [
    ['    own.push(setPaint(dress(faceGeometry(body.face)', '    laptop.push(setPaint(dress(faceGeometry(body.face)'],
    ['    if (laptop.length > 0) {\n        materials.push(', '    if (laptop.length > 0) {\n        materials.push('],
  ],
  // the laptop's parts each a group (and a material) of their own: the intern is more than two calls
  'laptop-unmerged': [
    ['        groups.push(mergeGeometries(laptop, false));', '        groups.push(...laptop);'],
    ['        materials.push(new MeshToonMaterial({ map: open, vertexColors: true, transparent: true, opacity: 1 }));', '        for (let i = 1; i < groups.length; i++) materials.push(new MeshToonMaterial({ map: open, vertexColors: true, transparent: true, opacity: 1 }));'],
  ],
};

const IMPORT_MAP = '<script type="importmap">{"imports":{"three":"/server/public/js/vendor/three/three.module.js"}}</script>';
const PAGE = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>loading</title>${IMPORT_MAP}</head><body style="margin:0;background:#f3ece2">
<script type="module">
  const q = new URLSearchParams(location.search);
  const C = await import('/resources/characters/index.js');
  const P = await import(q.get('paint'));
  const { stage } = await import('/tools/characters/stage3d.js');
  const SV = C.SEAT_VIEWBOX, CV = C.CHIBI_VIEWBOX, FOOT = C.FOOT;
  // a seat's frame rect and an intern's, in the part list's units (y up from the foot line)
  const SEAT = { x0: SV.x - 54, x1: SV.x + SV.w - 54, y0: FOOT - (SV.y + SV.h), y1: FOOT - SV.y };
  const CHIBI = { x0: CV.x - 54, x1: CV.x + CV.w - 54, y0: FOOT - (CV.y + CV.h), y1: FOOT - CV.y };
  const ABOVE = { x0: -110, x1: 110, y0: -110, y1: 110 };
  const make = (key, opt) => P.createCreature(document, C, key, { ...opt, height: opt.chibi ? CV.h : C.SEAT_RECT.h });

  window.calls = async () => {
    const st = stage(240, 250);
    const seat = await make('calls/seat', { species: 'fox' });
    const seatCalls = st.shoot(seat.mesh, 'front', SEAT);
    // the control: the seat's front picture is inked — a leg over a page that drew nothing measured nothing
    const g = document.createElement('canvas'); g.width = 240; g.height = 250;
    const x = g.getContext('2d'); x.drawImage(st.canvas, 0, 0);
    const px = x.getImageData(0, 0, 240, 250).data; let inked = 0; for (let i = 3; i < px.length; i += 4) if (px[i] > 128) inked++;
    const intern = await make('calls/seat~intern', { species: 'radish', chibi: true });
    const internCalls = st.shoot(intern.mesh, 'front', CHIBI);
    // the texture memory each holds — every texture's width × height × 4 bytes × 4/3 for its mipmaps (plan § 6's row)
    const bytes = (c) => c.textures().reduce((n, t) => n + t.width * t.height * 4 * 4 / 3, 0);
    const memory = { seat: bytes(seat), intern: bytes(intern), textures: { seat: seat.textures(), intern: intern.textures() } };
    // every species' triangles, seat and intern — printed for § 6's triangle row (slice 4 asserts it at the cap)
    const triangles = {};
    for (const sp of C.SPECIES_KEYS) {
      const s = await make('calls/' + sp, { species: sp }); st.shoot(s.mesh, 'front', SEAT); const t0 = st.renderer.info.render.triangles; s.dispose();
      const i = await make('calls/' + sp + '~intern', { species: sp, chibi: true }); st.shoot(i.mesh, 'front', CHIBI); const t1 = st.renderer.info.render.triangles; i.dispose();
      triangles[sp] = [t0, t1];
    }
    return JSON.stringify({ seat: seatCalls, intern: internCalls, inked, laptopGroups: intern.mesh.geometry.groups.length, triangles, memory });
  };

  window.sheet = async () => {
    const cell = Number(q.get('cell')), kind = q.get('kind');
    const keys = C.SPECIES_KEYS.filter((k) => kind === 'all' || C.SPECIES[k].kind === kind);
    const views = ['front', 'side', 'above', 'three-quarter'];
    const cols = ['2-D standing frame', ...views.map((v) => '3-D ' + v), 'intern, three-quarter'];
    const poseSpecies = keys.slice(0, 2), poses = Object.keys(C.POSES);
    const LABEL = 190, HEAD = 70, ROW = cell + 24;
    const W = LABEL + Math.max(cols.length, poses.length) * cell, H = HEAD + (keys.length + poseSpecies.length + 1) * ROW;
    const sheet = document.createElement('canvas'); sheet.width = W; sheet.height = H;
    const g = sheet.getContext('2d');
    g.fillStyle = '#f3ece2'; g.fillRect(0, 0, W, H);
    g.fillStyle = '#3b2f2a'; g.font = '600 18px sans-serif';
    g.fillText('Mezzanine — the 3-D creatures (card#13033 stage 2 slice 3): ' + keys.length + ' species, built from resources/characters/ by floor3d/creature-paint.js', 12, 26);
    g.font = '13px sans-serif'; g.fillStyle = '#7a6a5e';
    g.fillText('Each row: the 2-D standing frame, then the same key in 3-D from the front, the side, above and three-quarters, then its intern (chibi, laptop on its lap). The last rows: every pose of POSES.', 12, 48);
    const st = stage(cell, cell);
    const headers = (y, list) => { g.font = '600 13px sans-serif'; g.fillStyle = '#3b2f2a'; list.forEach((c, i) => g.fillText(c, LABEL + i * cell + 6, y)); };
    headers(HEAD - 6, cols);
    const uri = (doc) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(doc);
    let y = HEAD;
    for (const sp of keys) {
      const S = C.SPECIES[sp], key = 'sheet3d/' + sp;
      g.fillStyle = '#3b2f2a'; g.font = '600 15px sans-serif'; g.fillText(S.label, 12, y + cell / 2);
      g.font = '12px sans-serif'; g.fillStyle = '#7a6a5e'; g.fillText(sp + ' · ' + S.kind, 12, y + cell / 2 + 18);
      const img = new Image(); img.src = uri(C.frameDocument(key, { species: sp })); await img.decode();
      const s2 = Math.min(cell / SV.w, cell / SV.h);
      g.drawImage(img, LABEL + (cell - SV.w * s2) / 2, y + (cell - SV.h * s2), SV.w * s2, SV.h * s2);
      const seat = await make(key, { species: sp });
      views.forEach((v, i) => { st.shoot(seat.mesh, v, v === 'above' ? ABOVE : SEAT, v === 'above' ? [0, 60, 0] : undefined); g.drawImage(st.canvas, LABEL + (i + 1) * cell, y); });
      seat.dispose();
      const intern = await make(key + '~intern', { species: sp, chibi: true, pose: 'present' });
      st.shoot(intern.mesh, 'three-quarter', CHIBI); g.drawImage(st.canvas, LABEL + (views.length + 1) * cell, y);
      intern.dispose();
      g.strokeStyle = '#e0d3c2'; g.beginPath(); g.moveTo(0, y + ROW - 2); g.lineTo(W, y + ROW - 2); g.stroke();
      y += ROW;
    }
    headers(y + 18, poses); y += 24;
    for (const sp of poseSpecies) {
      g.fillStyle = '#3b2f2a'; g.font = '600 15px sans-serif'; g.fillText(C.SPECIES[sp].label + ' — poses', 12, y + cell / 2);
      const c = await make('sheet3d/' + sp, { species: sp });
      poses.forEach((p, i) => { c.pose(p); st.shoot(c.mesh, 'three-quarter', SEAT); g.drawImage(st.canvas, LABEL + i * cell, y); });
      c.dispose();
      y += ROW;
    }
    return sheet.toDataURL('image/png');
  };
  document.title = 'ready';
</script></body></html>`;

const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.html': 'text/html; charset=utf-8', '.json': 'application/json' };
async function planted(name) {
  let src = await readFile(join(REPO, PAINT), 'utf8');
  for (const [from, to] of PLANTS[name]) {
    if (!src.includes(from)) throw new Error(`plant ${name}: its anchor is not in creature-paint.js — ${from.slice(0, 60)}`);
    src = src.replace(from, to);
  }
  return src.replace("from '../vendor/three/three.module.js'", "from '/server/public/js/vendor/three/three.module.js'")
    .replace("from '../vendor/three/examples/jsm/utils/BufferGeometryUtils.js'", "from '/server/public/js/vendor/three/examples/jsm/utils/BufferGeometryUtils.js'")
    .replace("from './svg-texture.js'", "from '/server/public/js/floor3d/svg-texture.js'");
}
const server = createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path === '/__sheet3d.html') { res.writeHead(200, { 'content-type': TYPES['.html'] }); res.end(PAGE); return; }
  if (path.startsWith('/__plant/')) {
    try { const body = await planted(path.slice('/__plant/'.length).replace(/\.js$/, '')); res.writeHead(200, { 'content-type': TYPES['.js'] }); res.end(body); } catch (e) { res.writeHead(500); res.end(String(e)); }
    return;
  }
  const file = normalize(join(REPO, path));
  if (!file.startsWith(REPO)) { res.writeHead(403); res.end(); return; }
  try { const body = await readFile(file); res.writeHead(200, { 'content-type': TYPES[extname(file)] ?? 'application/octet-stream' }); res.end(body); } catch { res.writeHead(404); res.end(); }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const chrome = findChrome();
if (!chrome) { console.error('no Chromium found: pass --chrome <path> or set $CHROME'); process.exit(1); }
const base = `http://127.0.0.1:${server.address().port}/__sheet3d.html`;
let failures = 0;
const check = (cond, what) => { console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${what}`); if (!cond) failures++; };
const readCalls = async (paint) => {
  const b = await browser(chrome, 800, 600, 'ready');
  try { await b.load(`${base}?${new URLSearchParams({ paint })}`); return JSON.parse(await b.evaluate('window.calls()')); } finally { b.close(); }
};

try {
  if (calls) {
    const r = await readCalls(PAINT);
    console.log(`renderer.info.render.calls over one character alone: seat ${r.seat}, intern with its laptop ${r.intern} (its geometry's groups: ${r.laptopGroups}); the seat's front picture inks ${r.inked} px`);
    if (r.triangles) {
      const t = Object.entries(r.triangles), by = (k) => [...t].sort((x, y) => x[1][k] - y[1][k]);
      const med = (k) => by(k)[Math.floor(t.length / 2)][1][k];
      console.log(`MEASURED triangles a creature (renderer.info.render.triangles, one alone): seats median ${med(0)}, most ${by(0).at(-1)[1][0]} (${by(0).at(-1)[0]}); interns median ${med(1)}, most ${by(1).at(-1)[1][1]} (${by(1).at(-1)[0]}) — plan § 6's estimate is 2,500 a seat and 1,200 an intern; § 6's row is asserted at the cap by slice 4`);
    }
    if (r.memory) {
      const mb = (b) => (b / 1048576).toFixed(2);
      console.log(`MEASURED texture memory a creature at the ${r.memory.textures.seat[0].width}-px face bucket: a seat ${mb(r.memory.seat)} MB, an intern ${mb(r.memory.intern)} MB (${r.memory.textures.seat.map((t) => `${t.width}×${t.height}`).join(', ')}) — at § 6's cap of 6 seats and 48 interns, ${mb(6 * r.memory.seat + 48 * r.memory.intern)} MB; § 6's row is asserted at the cap by slice 4`);
    }
    check(r.inked > 1000, `CONTROL — the seat's front picture is inked (${r.inked} px), so the page drew a creature`);
    check(r.seat >= 1 && r.seat <= 1, `one seat drawn alone is ${r.seat} draw call(s) — at most 1 (its body and its face one material)`);
    check(r.intern >= 1 && r.intern <= 2, `one intern with its laptop drawn alone is ${r.intern} draw call(s) — at most 2 (its laptop the second)`);
    if (argv.includes('--selftest')) {
      console.log('\nplants:');
      // each plant's anchors read first, so a plant that no longer applies fails by name rather than as a page that never drew
      for (const name of Object.keys(PLANTS)) await planted(name);
      const face = await readCalls('/__plant/face-own-material.js');
      check(face.seat > 1, `PLANT face-own-material — the face a material of its own reads the seat as ${face.seat} call(s), over 1: RED`);
      const lap = await readCalls('/__plant/laptop-unmerged.js');
      check(lap.intern > 2, `PLANT laptop-unmerged — the laptop's parts each a group of their own read the intern as ${lap.intern} call(s), over 2: RED`);
    }
  } else {
    const q = new URLSearchParams({ paint: PAINT, cell: argOf('--cell', '230'), kind: argOf('--kind', 'all') });
    const b = await browser(chrome, 1200, 800, 'ready');
    try {
      await b.load(`${base}?${q}`);
      const png = await b.evaluate('window.sheet()');
      await writeFile(out, Buffer.from(png.split(',')[1], 'base64'));
      console.log(`${out}: ${q.get('kind')} species, ${q.get('cell')} px a view`);
    } finally { b.close(); }
  }
} finally { server.close(); }
if (calls) console.log(failures === 0 ? '\nALL PASS' : `\n${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);

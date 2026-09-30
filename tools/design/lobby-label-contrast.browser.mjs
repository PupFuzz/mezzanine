#!/usr/bin/env node
// THE LOBBY's PLATE-LABEL CONTRAST, COVERAGE AND OVERLAP, MEASURED ON RENDERED PIXELS — card#7343 r1's
// ruling (R2, the backing) and r3's fix round (the operator's ruling 2026-09-30, option A: the labels
// move BESIDE the building). Node, no dependencies, no network beyond 127.0.0.1 — but a real browser,
// because what a glyph stands on, whether a label's own box reaches the building's, and whether two
// labels' boxes reach each other are each a composition only a renderer produces.
//
//   node tools/design/lobby-label-contrast.browser.mjs              # judge the shipped lobby modules
//   node tools/design/lobby-label-contrast.browser.mjs --selftest   # …and see each check red on its own planted defect
//   node tools/design/lobby-label-contrast.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ WHAT IS RENDERED — every viewport and framing this file judges, so this sentence is never a claim
// wider than `RUNS` below actually covers: 3, 5, 7 and 10 floors at 1280 × 800, whole-building fit, at
// day and at night; 3 and 10 floors at 1280 × 800 fit at dawn, dusk and `unset` (no live feed yet) too;
// 10 floors at 1920 × 1080, fit; 10 floors at 1280 × 800 zoomed to its top plate (the ride's own aspect,
// where `labelSide()` reads the fallback); and 3 floors at 375 × 812 (a phone's own width), where the
// fallback is what `labelSide()` reads for a DIFFERENT reason — not enough room, not the wrong aspect.
//
// ⛔ WHAT IS MEASURED, PER RUN. The shipped `server/public/js/lobby/building-scene.js`,
// `building-paint.js` and `plate-row.js` draw the building — the surface's backdrop, the windows, the
// storeys and every plate's label, stood by `labelPlan()` exactly as `lobby/main.js` computes and hands
// it to `plateRow()` (the SAME three-line composition, copied here for the reason
// `tests/Feature/Floor/plate-row-probe.mjs`'s header states for its own copy). Three things are read
// back:
//   **Contrast** — each render is captured twice, as drawn and with every label span's text made
//   transparent (its halo and backing left as they are). A pixel that differs between the two is a GLYPH
//   pixel, and the second capture is what that glyph stands on; the contrast of the span's own text
//   colour against that pixel, and against a RING of pixels 2 and 3 px out from it in every direction (in
//   the SAME bare capture — design review r2's MINOR 3: measuring only the exact pixel under a glyph
//   credits the halo's own densest point and never its fall-off a few px out), is WCAG 2.1's ratio
//   (SC 1.4.3, relative luminance); the check is that EVERY glyph pixel, and every ring pixel around it,
//   reads at least 4.5:1. The figures reported are the minimum and the 1st percentile over the WORSE of a
//   glyph pixel and its ring, over every glyph pixel of each render.
//   **Coverage** — every plate's label (its outer box, `getBoundingClientRect()` of the counter-scaled
//   element `plate-row.js` builds) against the building's own drawn box (the `<svg>` `building-paint.js`
//   paints the shell, the windows, the shaft and the cab into): the intersection's area, in real screen
//   px². Card#7343 r3's ruling: this must be `0` wherever `labelPlan()` read `'left'` (there was room);
//   the fallback (`'plate'`) is the ruling's own accepted case and is reported, never asserted at `0`.
//   **Overlap** — every PAIR of plates' label boxes against each other: the intersection's area, in real
//   screen px², which must be `0` regardless of which side the labels stand on.
//
// ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP — `floor-preview.browser.mjs`'s rule, for its reason: a gate
// that reports nothing when its instrument is missing is a check reported as passed that never ran.
// ⛔ AND IT IS NOT WIRED IN CI: a stock runner carries no Chromium (`.github/workflows/design-doc-verifiers.yml`
// names both browser gates and why). It is run by hand, on a host with `~/.cache/ms-playwright`.
//
// ⛔ THE SELFTEST PLANTS ONE DEFECT PER CHECK AND REQUIRES EACH TO RED ON ITS OWN, FOR ITS OWN REASON: no
// backing at all (contrast); the beside-the-building gap inverted, so a label's box runs into the
// building (coverage); and the per-storey line budget ignored, so every optional line always shows,
// which — at floor counts tall enough that this round exists for — makes adjacent labels overlap.

import { spawn } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const JS_ROOT = join(HERE, '..', '..', 'server', 'public', 'js');
const argOf = (name) => { const i = process.argv.indexOf(name); return i === -1 ? null : process.argv[i + 1]; };

/** WCAG 2.1 SC 1.4.3's figure for normal text. */
const MIN_RATIO = 4.5;

/** The planted defects, one per check — see the header's SELFTEST paragraph. */
const PLANTS = {
  contrast: ['    backgroundColor: LABEL_BACKING,\n', ''],
  coverage: ['const LABEL_GAP_PX = 8;', 'const LABEL_GAP_PX = -400;'],
  overlap: ['return Math.max(2, Math.min(4, Math.floor((PLATE_H * camera.zoom) / LABEL_LINE_PX)));', 'return 4;'],
};

function findChrome() {
  const named = argOf('--chrome') || process.env.CHROME;
  if (named) return existsSync(named) ? named : null;
  const cache = join(process.env.HOME || '', '.cache', 'ms-playwright');
  if (!existsSync(cache)) return null;
  for (const d of readdirSync(cache).filter((n) => n.startsWith('chromium'))) {
    for (const sub of ['chrome-headless-shell-linux64/chrome-headless-shell', 'chrome-linux/chrome']) {
      const p = join(cache, d, sub);
      if (existsSync(p)) return p;
    }
  }
  return null;
}

// The page: the lobby's building drawn by the shipped modules as `lobby/main.js` draws it — surface
// style, kept drawing, plates at their rects under `labelPlan()`'s decision, the camera's one transform
// and the labels' counter-scale.
const PAGE = `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0}</style></head><body>
<div id="lobby-building"><ul id="lobby-floors" style="margin:0;padding:0;transform-origin:0 0"></ul></div>
<script type="module">
import { buildingScene, labelLines, labelMax, labelMaxLeft, labelScale, labelSide, surfaceStyle } from './js/lobby/building-scene.js';
import { buildingDrawing, keepDrawing, paintBuilding } from './js/lobby/building-paint.js';
import { plateRow } from './js/lobby/plate-row.js';
import { createCamera, frameOn, focusOn } from './js/wire/camera.js';
const q = new URLSearchParams(location.search);
const n = Number(q.get('floors'));
const phase = q.get('phase') === 'unset' ? null : q.get('phase');
const w = Number(q.get('w')), h = Number(q.get('h'));
const plates = Array.from({ length: n }, (_, i) => ({ floor: 'f' + i, level: i, href: '/floor/f' + i,
  name: 'Floor ' + (i + 1), summary: '4 seats · 3 live',
  rooms: i % 3 === 1 ? [{ install_id: 'alpha', form: 'office', reported: true }, { install_id: 'beta', form: 'office', reported: false }] : [] }));
const scene = buildingScene(plates);
const surface = document.getElementById('lobby-building');
Object.assign(surface.style, surfaceStyle(scene, phase), { width: w + 'px', height: h + 'px' });
const rows = document.getElementById('lobby-floors');
const nodes = buildingDrawing(document);
keepDrawing(rows, nodes.drawing);
Object.assign(rows.style, { position: 'relative', width: scene.extent.w + 'px', height: scene.extent.h + 'px' });
paintBuilding(document, nodes, scene, 0, 0, null, phase);
let cam = frameOn(createCamera({ width: w, height: h }), scene.extent);
if (q.get('zoom') === 'plate') cam = focusOn(cam, scene.plates[0].rect);
// lobby/main.js's own labelPlan() — copied for the reason plate-row-probe.mjs's header states.
function labelPlan(camera) {
  const side = labelSide(camera);
  return { side, maxWidth: side === 'left' ? labelMaxLeft(camera) : labelMax(camera), lines: labelLines(camera), zoom: camera.zoom };
}
const label = labelPlan(cam);
for (const p of plates) rows.append(plateRow(document, p, scene.plates[p.level].rect, p.level === 0, label));
rows.style.transform = 'scale(' + cam.zoom + ') translate(' + (-cam.x) + 'px, ' + (-cam.y) + 'px)';
rows.style.setProperty('--label-scale', String(labelScale(cam)));
window.__labelSide = label.side;
document.title = 'drawn';
</script></body></html>`;

/** Serves the page and the (shipped or planted) module tree on 127.0.0.1. */
function serve(plant) {
  const server = createServer((req, res) => {
    const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (path === '/index.html') { res.writeHead(200, { 'content-type': 'text/html' }); res.end(PAGE); return; }
    if (!path.startsWith('/js/')) { res.writeHead(404); res.end(); return; }
    const file = normalize(join(JS_ROOT, path.slice(4)));
    if (!file.startsWith(JS_ROOT) || !existsSync(file)) { res.writeHead(404); res.end(); return; }
    let body = readFileSync(file, 'utf8');
    if (plant?.file && file.endsWith(join(...plant.file))) {
      if (!body.includes(plant.anchor)) throw new Error(`the planted defect's anchor is gone from ${plant.file.join('/')} — the selftest would plant nothing`);
      body = body.replace(plant.anchor, plant.replacement);
    }
    res.writeHead(200, { 'content-type': 'text/javascript' });
    res.end(body);
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

/** Headless Chromium over the DevTools protocol, one page. */
async function browser(chrome, width, height) {
  const proc = spawn(chrome, ['--headless', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
    '--remote-debugging-port=0', 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
  const wsUrl = await new Promise((resolve, reject) => {
    let err = '';
    const timer = setTimeout(() => reject(new Error('Chromium printed no DevTools endpoint')), 30000);
    proc.stderr.on('data', (d) => {
      err += d;
      const m = err.match(/DevTools listening on (ws:\/\/\S+)/);
      if (m) { clearTimeout(timer); resolve(m[1]); }
    });
    proc.on('exit', () => reject(new Error('Chromium exited before listening')));
  });
  const ws = new WebSocket(wsUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let seq = 0;
  const pending = new Map();
  ws.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.id !== undefined && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(new Error(`${msg.error.message}`)); else resolve(msg.result);
    }
  };
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const page = (method, params) => send(method, params, sessionId);
  await page('Page.enable');
  await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
  const evaluate = async (expression) => {
    const r = await page('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(`in page: ${r.exceptionDetails.exception?.description ?? r.exceptionDetails.text}`);
    return r.result.value;
  };
  return {
    async load(url) {
      await page('Page.navigate', { url });
      for (let i = 0; i < 200; i += 1) {
        if ((await evaluate('document.title')) === 'drawn') return;
        await new Promise((r) => setTimeout(r, 50));
      }
      throw new Error(`the page never drew: ${url}`);
    },
    evaluate,
    shot: async () => `data:image/png;base64,${(await page('Page.captureScreenshot', { format: 'png' })).data}`,
    close() { ws.close(); proc.kill(); },
  };
}

// In the page: every visible text span of every plate label, its text colour and its line boxes.
const SPANS = `(() => [...document.querySelectorAll('li[data-floor] span')]
  .filter((s) => getComputedStyle(s).clipPath !== 'inset(50%)' && s.textContent.trim() !== '')
  .map((s) => ({ text: s.textContent.trim(), color: getComputedStyle(s).color,
    rects: [...s.getClientRects()].map((r) => ({ l: Math.floor(r.left), t: Math.floor(r.top), r: Math.ceil(r.right), b: Math.ceil(r.bottom) }))
      .filter((r) => r.r > r.l && r.b > r.t) })))()`;

// In the page: each plate's own outer label box (the counter-scaled element `plate-row.js` builds),
// and the building's own drawn box (the `<svg>` the shell/windows/shaft/cab are painted into).
const BOXES = `(() => {
  const rect = (r) => ({ l: r.left, t: r.top, r: r.right, b: r.bottom });
  const labels = [...document.querySelectorAll('li[data-floor]')].map((li) => ({
    floor: li.dataset.floor, box: rect(li.querySelector(':scope > div').getBoundingClientRect()),
  }));
  const svg = document.querySelector('#lobby-building svg');
  return { labels, building: svg ? rect(svg.getBoundingClientRect()) : null, side: window.__labelSide };
})()`;

// ⛔ THE LINK's OWN UNDERLINE IS NOT THE GLYPH, AND IS NEUTRALISED HERE, NOT JUST THE SPAN's FILL: `<a>`
// draws `text-decoration: underline` in ITS OWN colour (`text-decoration-color` defaults to that
// element's `currentcolor`, never the descendant SPAN's), so hiding a span's fill alone leaves a solid
// link-blue line sitting right under the text — indistinguishable, to a ring a few px below a glyph's
// baseline, from the text itself, and measured against it every ratio would read close to 1:1 for a
// reason that is the instrument's, never the product's (WCAG 2.1 SC 1.4.3 is text-vs-background; a
// decoration line is neither).
const HIDE_TEXT = `(() => { const s = document.createElement('style'); s.id = '__hide';
  s.textContent = 'li[data-floor] span{color:transparent!important;-webkit-text-fill-color:transparent!important}'
    + 'li[data-floor] a{text-decoration:none!important}';
  document.head.append(s); return true; })()`;

/** The area, in px², two rects share — `0` where they do not overlap at all. */
function overlapArea(a, b) {
  const w = Math.max(0, Math.min(a.r, b.r) - Math.max(a.l, b.l));
  const h = Math.max(0, Math.min(a.b, b.b) - Math.max(a.t, b.t));
  return w * h;
}

// In the page: decode both captures and read the ratio at every glyph pixel AND its ring.
const MEASURE = (a, b, spans) => `(async () => {
  const load = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
  const [ia, ib] = await Promise.all([load(${JSON.stringify(a)}), load(${JSON.stringify(b)})]);
  const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    const g = c.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, img.width, img.height); };
  const A = px(ia), B = px(ib), W = A.width, H = A.height;
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  const L = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  const bgAt = (x, y) => { const i = (y * W + x) * 4; return L(B.data[i], B.data[i + 1], B.data[i + 2]); };
  // design review r2 MINOR 3: a ring 2 and 3 px out in every direction, in the BARE capture, so the
  // check reads the halo's fall-off a few px from the glyph and not only its densest point.
  const RING = [];
  for (const r of [2, 3]) for (let a = 0; a < 8; a += 1) RING.push([Math.round(r * Math.cos(a * Math.PI / 4)), Math.round(r * Math.sin(a * Math.PI / 4))]);
  const ratios = []; const worst = [];
  for (const span of ${JSON.stringify(spans)}) {
    const [r, g, b] = span.color.match(/\\d+(\\.\\d+)?/g).map(Number);
    const fg = L(r, g, b);
    for (const rect of span.rects) {
      for (let y = Math.max(0, rect.t); y < Math.min(H, rect.b); y += 1) {
        for (let x = Math.max(0, rect.l); x < Math.min(W, rect.r); x += 1) {
          const i = (y * W + x) * 4;
          const diff = Math.abs(A.data[i] - B.data[i]) + Math.abs(A.data[i + 1] - B.data[i + 1]) + Math.abs(A.data[i + 2] - B.data[i + 2]);
          if (diff <= 24) continue;
          let worstBg = bgAt(x, y);
          for (const [dx, dy] of RING) {
            const rx = x + dx, ry = y + dy;
            if (rx < 0 || ry < 0 || rx >= W || ry >= H) continue;
            const bg = bgAt(rx, ry);
            if (Math.abs(fg - bg) < Math.abs(fg - worstBg)) worstBg = bg;
          }
          const ratio = (Math.max(fg, worstBg) + 0.05) / (Math.min(fg, worstBg) + 0.05);
          ratios.push(ratio);
          if (ratio < ${MIN_RATIO} && worst.length < 3) worst.push({ text: span.text, x, y, ratio: +ratio.toFixed(2) });
        }
      }
    }
  }
  ratios.sort((p, q) => p - q);
  return { glyphs: ratios.length, min: ratios[0] ?? null, p01: ratios[Math.floor(ratios.length * 0.01)] ?? null,
    under: ratios.filter((v) => v < ${MIN_RATIO}).length, worst };
})()`;

const RUNS = [];
for (const floors of [3, 5, 7, 10]) for (const phase of ['day', 'night']) RUNS.push({ floors, phase, zoom: 'fit', w: 1280, h: 800 });
for (const floors of [3, 10]) for (const phase of ['dawn', 'dusk', 'unset']) RUNS.push({ floors, phase, zoom: 'fit', w: 1280, h: 800 });
RUNS.push({ floors: 10, phase: 'night', zoom: 'fit', w: 1920, h: 1080 });
RUNS.push({ floors: 10, phase: 'day', zoom: 'plate', w: 1280, h: 800 });
RUNS.push({ floors: 3, phase: 'night', zoom: 'fit', w: 375, h: 812 });

/**
 * ONE dedicated scenario for the overlap control alone: 14 floors, tall enough (`labelLines()` floors at
 * its minimum, 2 — both optional lines dropped) that the line budget's own protection is what keeps
 * `plate-row.js`'s realistic content (a rooms line on every third floor, the cue on the first) from
 * overlapping at all; `plant` (or `null`) is `serve()`'s own shape.
 */
async function runOverlapScenario(chrome, plant) {
  const server = await serve(plant);
  const b = await browser(chrome, 1280, 800);
  try {
    const base = `http://127.0.0.1:${server.address().port}/index.html`;
    await b.load(`${base}?floors=14&phase=day&zoom=fit&w=1280&h=800`);
    const boxes = await b.evaluate(BOXES);
    let overlap = 0;
    for (let i = 0; i < boxes.labels.length; i += 1) {
      for (let j = i + 1; j < boxes.labels.length; j += 1) overlap += overlapArea(boxes.labels[i].box, boxes.labels[j].box);
    }
    return { overlap: +overlap.toFixed(2), side: boxes.side };
  } finally {
    b.close();
    server.close();
  }
}

/** Every run, measured; each result carries `ok`. */
async function judge(chrome, plant) {
  const server = await serve(plant);
  const results = [];
  try {
    for (const run of RUNS) {
      const b = await browser(chrome, run.w, run.h);
      try {
        const base = `http://127.0.0.1:${server.address().port}/index.html`;
        await b.load(`${base}?floors=${run.floors}&phase=${run.phase}&zoom=${run.zoom}&w=${run.w}&h=${run.h}`);
        const spans = await b.evaluate(SPANS);
        const boxes = await b.evaluate(BOXES);
        const drawn = await b.shot();
        await b.evaluate(HIDE_TEXT);
        const bare = await b.shot();
        const m = await b.evaluate(MEASURE(drawn, bare, spans));

        let covered = 0;
        if (boxes.building) for (const l of boxes.labels) covered += overlapArea(l.box, boxes.building);
        let overlap = 0;
        for (let i = 0; i < boxes.labels.length; i += 1) {
          for (let j = i + 1; j < boxes.labels.length; j += 1) overlap += overlapArea(boxes.labels[i].box, boxes.labels[j].box);
        }

        // Non-vacuous: every plate's name and summary were found, glyphs were read under them, and the
        // building actually drew a box to measure coverage against. Zoomed to one plate, only THAT
        // plate's own glyphs are on screen at all — the rest are off-screen spans this run never claims
        // to measure — so the floor-count multiplier is `1`, not `run.floors`, for that framing alone.
        const population = run.zoom === 'plate' ? 1 : run.floors;
        const measured = spans.length >= population * 2 && m.glyphs > 200 * population && boxes.building !== null;
        const side = boxes.side;
        results.push({
          ...run, side, spans: spans.length, ...m, covered: +covered.toFixed(2), overlap: +overlap.toFixed(2), measured,
          ok: measured && m.under === 0 && overlap === 0 && (side !== 'left' || covered === 0),
        });
      } finally {
        b.close();
      }
    }
  } finally {
    server.close();
  }
  return results;
}

const report = (r) => `${r.ok ? 'PASS' : 'FAIL'}  ${String(r.floors).padStart(2)} floors ${String(r.w) + 'x' + r.h} ${r.zoom.padEnd(5)} ${r.phase.padEnd(5)} side=${r.side.padEnd(5)}  `
  + `${r.spans} spans, ${r.glyphs} glyph px, min ${r.min?.toFixed(2)}:1, p1 ${r.p01?.toFixed(2)}:1, under ${MIN_RATIO}: ${r.under}, `
  + `covered ${r.covered}px², overlap ${r.overlap}px²`
  + `${r.measured ? '' : '  — NOT MEASURED (too few spans, glyph pixels, or no building box)'}${r.worst.length ? `  e.g. ${JSON.stringify(r.worst)}` : ''}`;

const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const shipped = await judge(chrome, null);
console.log('── the shipped lobby labels — contrast, coverage of the building, overlap with each other');
for (const r of shipped) console.log(report(r));
let failed = shipped.some((r) => !r.ok);

if (argOf('--selftest') !== null || process.argv.includes('--selftest')) {
  for (const [check, [anchor, replacement]] of Object.entries({ contrast: PLANTS.contrast, coverage: PLANTS.coverage })) {
    const planted = await judge(chrome, { file: ['lobby', 'plate-row.js'], anchor, replacement });
    console.log(`── CONTROL (${check}): must FAIL`);
    for (const r of planted) console.log(report(r));

    const bit = check === 'contrast' ? planted.every((r) => r.measured) && planted.some((r) => r.under > 0)
      : planted.some((r) => r.side === 'left' && r.covered > 0);

    console.log(bit ? `CONTROL CAUGHT (${check})` : `CONTROL NOT CAUGHT (${check}) — the check cannot tell a correct render from this planted one`);
    failed = failed || !bit;
  }

  // The overlap control needs its OWN scenario, not the coverage/contrast matrix's — see
  // `runOverlapScenario()`'s own docblock for why 14 floors and not `RUNS`' 3–10.
  console.log('── CONTROL (overlap): must FAIL');
  const shipped14 = await runOverlapScenario(chrome, null);
  const planted14 = await runOverlapScenario(chrome, { file: ['lobby', 'building-scene.js'], anchor: PLANTS.overlap[0], replacement: PLANTS.overlap[1] });
  console.log(`shipped: overlap ${shipped14.overlap}px², side=${shipped14.side}`);
  console.log(`planted: overlap ${planted14.overlap}px², side=${planted14.side}`);
  const overlapBit = shipped14.overlap === 0 && planted14.overlap > 0;
  console.log(overlapBit ? 'CONTROL CAUGHT (overlap)' : 'CONTROL NOT CAUGHT (overlap) — the check cannot tell a correct render from this planted one');
  failed = failed || !overlapBit;
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

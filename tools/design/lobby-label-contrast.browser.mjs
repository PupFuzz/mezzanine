#!/usr/bin/env node
// THE LOBBY's PLATE-LABEL CONTRAST, COVERAGE, OVERLAP AND LINE INTEGRITY, MEASURED ON RENDERED PIXELS —
// card#7343 r4's fix round (the operator's ruling 2026-09-30, option A: the labels move BESIDE the
// building), rebuilt against `lobby/label-paint.js`'s `showLabels()`, the ONE primitive that writes a
// plate's label geometry and paint (design review, replacing the r1–r3 mechanism this tool used to
// measure). Node, no dependencies, no network beyond 127.0.0.1 — but a real browser, because what a
// glyph stands on, whether a label's own box reaches the building's, whether two labels' boxes reach
// each other, and whether a line is shown WHOLE or sliced mid-glyph are each a composition only a
// renderer produces.
//
//   node tools/design/lobby-label-contrast.browser.mjs              # judge the shipped lobby modules
//   node tools/design/lobby-label-contrast.browser.mjs --selftest   # …and see each check red on its own planted defect
//   node tools/design/lobby-label-contrast.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ THE PAGE IS NOT THE SERVED ONE, BUT ITS LAYOUT-RELEVANT SHAPE IS (design review, M6 — the r1–r3
// tool's own runs used the VIEWPORT size as the drawing surface's size, which is not what a browser
// ever gives `#lobby-building`): the served page (`dashboard.blade.php`) ships NO stylesheet, so the
// ONLY thing narrowing `#lobby-building`'s width below the viewport's is the BROWSER'S OWN DEFAULT
// `<body>` margin — verified empirically here (Chromium's UA stylesheet: 8px, all four sides, so 16px
// off the width). This harness's page keeps that default (never `body { margin: 0 }`) and nests the
// drawing exactly as the served page does — `<body><main><section><div id="lobby-building">` — so the
// surface it measures is the ACTUAL rendered `clientWidth`/`clientHeight` a real browser gives that
// element at a given viewport, never a formula applied in this file. `SURFACE_H` (`building-scene.js`,
// `70vh`) accounts for the height half; nothing here re-states either number.
//
// ⛔ WHAT IS RENDERED — every viewport and framing this file judges, so this sentence is never a claim
// wider than `RUNS` below actually covers: at each of five VIEWPORT sizes (800×800, 1280×800, 1440×900,
// 1920×1080, 2560×1080 — chosen so the SURFACE they produce is 784×560, 1264×560, 1424×630, 1904×756 and
// 2544×756, design review P5's own examples), 1, 3, 10, 12 and 16 floors at fit, day; a 4-room plate and
// a long summary, each on the cab's own floor, at 1264×560; a wheel-between-renders case (showLabels()
// called again after a wheel, with NO row rebuilt) at 1264×560; and, from the r3-round's own matrix
// still worth keeping (every sky phase, the ride's own zoomed-to-one-plate aspect, and a phone-width
// fallback): 3 and 10 floors at every phase at 1264×560, 10 floors at 1904×756, 10 floors zoomed to one
// plate at 1264×560, and 3 floors at phone width (359×568, the SAME viewport-minus-16/0.7-height
// formula applied to a 375×812 phone viewport).
//
// ⛔ WHAT IS MEASURED, PER RUN. The shipped `server/public/js/lobby/building-scene.js`,
// `building-paint.js`, `label-paint.js` and `plate-row.js` draw the building and stand every plate's
// label exactly as `lobby/main.js` does — `plateRow()` once, `showLabels()` on the SAME camera every
// `view()` would show it on. Five things are read back:
//   **Contrast** — each render is captured twice, as drawn and with every label span's text made
//   transparent (its halo and backing left as they are). A pixel that differs between the two is a GLYPH
//   pixel, and the second capture is what that glyph stands on; the contrast of the span's own text
//   colour against that pixel, and against a RING of pixels 2 and 3 px out from it in every direction (in
//   the SAME bare capture), is WCAG 2.1's ratio (SC 1.4.3), held to 4.5:1.
//   **Coverage** — every plate's label (its outer box) against the building's own drawn box: the
//   intersection's area, in real screen px². Must be `0` wherever `side === 'left'` (there was room); the
//   fallback (`side === 'plate'`) is the operator's own accepted case and is reported, never asserted at 0.
//   **Overlap** — every PAIR of plates' label boxes against each other: the intersection's area, which
//   must be `0` regardless of which side the labels stand on.
//   **No line sliced** — every VISIBLE line (the name+cue row, the summary, the rooms line) is either
//   WHOLLY inside the label's own clipped box or WHOLLY outside it (invisible) — never cut through its
//   own glyphs' middle, which `overflow: clip` at a `max-height` that is not an exact multiple of the
//   line's own rendered height would produce.
//   **The cue stays reachable** — on the cab's own plate, the cue's own rect lies wholly inside the
//   label's unclipped (fully-shown) region, so *the elevator is here* is never itself the line a short
//   storey slices.
//
// ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP, AND IT IS NOT WIRED IN CI (a stock runner carries no
// Chromium — `.github/workflows/design-doc-verifiers.yml` names both browser gates and why). Run by hand.
//
// ⛔ THE SELFTEST PLANTS ONE DEFECT PER CHECK AND REQUIRES EACH TO RED ON ITS OWN, FOR ITS OWN REASON: no
// backing at all (contrast); the beside-the-building gap inverted, so a label's box runs into the
// building (coverage); and the per-storey line budget ignored, so every optional line always shows,
// which — at floor counts tall enough that this round exists for — makes adjacent labels overlap AND
// slices a line where the forced budget outgrows the storey (overlap and line-integrity, one plant).

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
  contrast: ["backgroundColor: 'var(--label-backing)',\n", ''],
  coverage: ['export const LABEL_GAP_PX = 8;', 'export const LABEL_GAP_PX = -400;'],
  overlap: ['return Math.max(1, Math.floor((PLATE_H * camera.zoom) / LABEL_LINE_PX));', 'return 4;'],
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

// The page: the lobby's building drawn by the shipped modules as `lobby/main.js` draws it, nested
// EXACTLY as the served page nests it (`<body><main><section><div id="lobby-building">`, no `body`
// margin reset) so the surface's own rendered size is the browser's real answer, never a formula.
const PAGE = `<!doctype html><html><head><meta charset="utf-8"></head><body><main><section>
<div id="lobby-building"><ul id="lobby-floors" style="margin:0;padding:0;transform-origin:0 0"></ul></div>
</section></main>
<script type="module">
import { buildingScene, surfaceStyle } from './js/lobby/building-scene.js';
import { buildingDrawing, keepDrawing, paintBuilding } from './js/lobby/building-paint.js';
import { showLabels } from './js/lobby/label-paint.js';
import { plateRow } from './js/lobby/plate-row.js';
import { createCamera, frameOn, focusOn, wheel } from './js/wire/camera.js';
const q = new URLSearchParams(location.search);
const n = Number(q.get('floors'));
const phase = q.get('phase') === 'unset' ? null : q.get('phase');
const cabAt = q.get('cab') === null ? 0 : Number(q.get('cab'));
const rooms4 = q.get('rooms4') === '1';
const longsum = q.get('longsum') === '1';
const plates = Array.from({ length: n }, (_, i) => ({ floor: 'f' + i, level: i, href: '/floor/f' + i,
  name: 'Floor ' + (i + 1),
  summary: (i === 0 && longsum) ? '4 seats · 3 live · a rather long summary line that should ellipsize cleanly rather than wrap' : '4 seats · 3 live',
  rooms: (i === 0 && rooms4) ? [{install_id:'alpha',form:'office',reported:true},{install_id:'beta',form:'office',reported:true},{install_id:'gamma',form:'office',reported:false},{install_id:'delta',form:'office',reported:true}]
    : i % 3 === 1 ? [{ install_id: 'alpha', form: 'office', reported: true }, { install_id: 'beta', form: 'office', reported: false }] : [] }));
const scene = buildingScene(plates);
const surface = document.getElementById('lobby-building');
Object.assign(surface.style, surfaceStyle(scene, phase));
const rows = document.getElementById('lobby-floors');
const nodes = buildingDrawing(document);
keepDrawing(rows, nodes.drawing);
Object.assign(rows.style, { position: 'relative', width: scene.extent.w + 'px', height: scene.extent.h + 'px' });
paintBuilding(document, nodes, scene, cabAt, 0, null, phase);
// The SURFACE's own rendered size — never the viewport's, never a formula (the header's own M6 note).
let cam = frameOn(createCamera({ width: surface.clientWidth, height: surface.clientHeight }), scene.extent);
if (q.get('zoom') === 'plate') cam = focusOn(cam, scene.plates[0].rect);
for (const p of plates) rows.append(plateRow(document, p, scene.plates[p.level].rect, p.level === cabAt));
function paintCamera(camera) {
  rows.style.transform = 'scale(' + camera.zoom + ') translate(' + (-camera.x) + 'px, ' + (-camera.y) + 'px)';
  showLabels(rows, camera);
}
paintCamera(cam);
// The wheel-between-renders case: a camera move with NO row rebuilt at all — showLabels() alone must
// carry the new geometry, which is the whole point of the round's redesign.
if (q.get('zoom') === 'wheel') {
  cam = wheel(cam, { x: surface.clientWidth / 2, y: surface.clientHeight / 2 }, { deltaY: -400 });
  paintCamera(cam);
}
window.__labelSide = rows.dataset.labelSide ?? null;
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

/** Headless Chromium over the DevTools protocol, one page, at a given VIEWPORT (never the surface's own size). */
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
const SPANS = `(() => [...document.querySelectorAll('li[data-floor] a, li[data-floor] span, li[data-floor] div')]
  .filter((s) => s.children.length === 0 && getComputedStyle(s).clipPath !== 'inset(50%)' && s.textContent.trim() !== '')
  .map((s) => ({ text: s.textContent.trim(), color: getComputedStyle(s).color,
    rects: [...s.getClientRects()].map((r) => ({ l: Math.floor(r.left), t: Math.floor(r.top), r: Math.ceil(r.right), b: Math.ceil(r.bottom) }))
      .filter((r) => r.r > r.l && r.b > r.t) })))()`;

// In the page: each plate's own outer label box, the building's own drawn box, every VISIBLE line's own
// rect (for the no-sliced-line and cue-reachable checks), and which of them holds the cab's cue.
const BOXES = `(() => {
  const rect = (r) => ({ l: r.left, t: r.top, r: r.right, b: r.bottom });
  const labels = [...document.querySelectorAll('li[data-floor]')].map((li) => {
    const label = li.querySelector(':scope > div');
    const lines = [...label.querySelectorAll('a, div, span')]
      .filter((el) => el.children.length === 0 && getComputedStyle(el).clipPath !== 'inset(50%)' && el.textContent.trim() !== '')
      .map((el) => ({ text: el.textContent.trim(), isCue: el.tagName === 'SPAN', box: rect(el.getBoundingClientRect()) }));
    return { floor: li.dataset.floor, box: rect(label.getBoundingClientRect()), lines };
  });
  const svg = document.querySelector('#lobby-building svg');
  return { labels, building: svg ? rect(svg.getBoundingClientRect()) : null, side: window.__labelSide };
})()`;

// ⛔ THE LINK's OWN UNDERLINE IS NOT THE GLYPH: `<a>` draws `text-decoration: underline` in ITS OWN
// colour (`text-decoration-color` defaults to that element's `currentcolor`, never a descendant's), so
// hiding a span's fill alone leaves a solid link-coloured line sitting right under the text.
const HIDE_TEXT = `(() => { const s = document.createElement('style'); s.id = '__hide';
  s.textContent = 'li[data-floor] a, li[data-floor] span, li[data-floor] div{color:transparent!important;-webkit-text-fill-color:transparent!important}'
    + 'li[data-floor] a{text-decoration:none!important}';
  document.head.append(s); return true; })()`;

/** The area, in px², two rects share — `0` where they do not overlap at all. */
function overlapArea(a, b) {
  const w = Math.max(0, Math.min(a.r, b.r) - Math.max(a.l, b.l));
  const h = Math.max(0, Math.min(a.b, b.b) - Math.max(a.t, b.t));
  return w * h;
}

/** Whether `inner` lies wholly inside `outer` (a few px of slack for sub-pixel rounding). */
function wholeInside(inner, outer, slack = 0.6) {
  return inner.t >= outer.t - slack && inner.b <= outer.b + slack && inner.l >= outer.l - slack && inner.r <= outer.r + slack;
}

/** Every VISIBLE line either wholly inside its label's own box or wholly outside it (invisible) — never sliced. */
function slicedLines(boxes) {
  const bad = [];

  for (const label of boxes.labels) {
    for (const line of label.lines) {
      const inside = wholeInside(line.box, label.box);
      const outside = line.box.t >= label.box.b - 0.6 || line.box.b <= label.box.t + 0.6 || line.box.r <= label.box.l + 0.6 || line.box.l >= label.box.r + 0.6;

      if (!inside && !outside) {
        bad.push({ floor: label.floor, text: line.text });
      }
    }
  }

  return bad;
}

/** On the cab's own plate, the cue's rect must lie wholly inside its label's own (unclipped) box. */
function cueUnreachable(boxes) {
  const bad = [];

  for (const label of boxes.labels) {
    const cue = label.lines.find((l) => l.isCue);

    if (cue !== undefined && !wholeInside(cue.box, label.box)) {
      bad.push({ floor: label.floor });
    }
  }

  return bad;
}

// In the page: decode both captures and read the ratio at every glyph pixel AND its ring.
const MEASURE = (a, b, spans, surface) => `(async () => {
  const load = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
  const [ia, ib] = await Promise.all([load(${JSON.stringify(a)}), load(${JSON.stringify(b)})]);
  const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    const g = c.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, img.width, img.height); };
  const A = px(ia), B = px(ib), W = A.width, H = A.height;
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  const L = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  const bgAt = (x, y) => { const i = (y * W + x) * 4; return L(B.data[i], B.data[i + 1], B.data[i + 2]); };
  const RING = [];
  for (const r of [2, 3]) for (let a = 0; a < 8; a += 1) RING.push([Math.round(r * Math.cos(a * Math.PI / 4)), Math.round(r * Math.sin(a * Math.PI / 4))]);
  const surf = ${JSON.stringify(surface)};
  const ratios = []; const worst = [];
  for (const span of ${JSON.stringify(spans)}) {
    const [r, g, b] = span.color.match(/\\d+(\\.\\d+)?/g).map(Number);
    const fg = L(r, g, b);
    for (const rect of span.rects) {
      // ⛔ TWO INSETS, NEITHER A REAL GLYPH'S OWN: the diff test alone cannot tell a glyph's anti-aliased
      // edge from a HARD BOUNDARY's — (1) a label's own box edge sitting flush against a drawn scene edge
      // (the roof/floor seam, at 'top: 0' on the first storey) rasterises that seam at a very slightly
      // different sub-pixel position between the drawn and the text-hidden capture, registering as a large
      // diff though no ink is there; (2) the SURFACE's own clip edge (#lobby-building's overflow:
      // hidden) does the same where a label's own top row sits right at it (found empirically: a
      // fallback cue at the roof seam read ~2.5:1 against the roof's OWN colour; a beside summary on the
      // first floor, after a wheel moved the camera, read ~1.1:1 one row into the surface's own top clip).
      // A real glyph's ink at this label's line-height is never confined to either boundary's outermost
      // 1-2px, so both insets cost no real coverage.
      for (let y = Math.max(0, rect.t) + 1; y < Math.min(H, rect.b) - 1; y += 1) {
        for (let x = Math.max(0, rect.l) + 1; x < Math.min(W, rect.r) - 1; x += 1) {
          if (y < surf.t + 2 || y > surf.b - 2 || x < surf.l + 2 || x > surf.r - 2) continue;
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

/** A viewport (W×H) and the SURFACE it is expected to produce — asserted, never assumed, per run. */
const VIEWPORTS = [
  { w: 800, h: 800 }, { w: 1280, h: 800 }, { w: 1440, h: 900 }, { w: 1920, h: 1080 }, { w: 2560, h: 1080 },
];

const RUNS = [];

for (const { w, h } of VIEWPORTS) for (const floors of [1, 3, 10, 12, 16]) RUNS.push({ floors, phase: 'day', zoom: 'fit', w, h });
RUNS.push({ floors: 10, phase: 'day', zoom: 'fit', w: 1440, h: 900, rooms4: 1 });
RUNS.push({ floors: 10, phase: 'day', zoom: 'fit', w: 1440, h: 900, longsum: 1 });
RUNS.push({ floors: 10, phase: 'day', zoom: 'wheel', w: 1440, h: 900 });
for (const floors of [3, 10]) for (const phase of ['night', 'dawn', 'dusk', 'unset']) RUNS.push({ floors, phase, zoom: 'fit', w: 1440, h: 900 });
RUNS.push({ floors: 10, phase: 'night', zoom: 'fit', w: 1920, h: 1080 });
RUNS.push({ floors: 10, phase: 'day', zoom: 'plate', w: 1440, h: 900 });
RUNS.push({ floors: 3, phase: 'night', zoom: 'fit', w: 375, h: 812 });

/**
 * ONE dedicated scenario for the overlap AND slicing controls alone: 14 floors, tall enough
 * (`linesFor()` floors at its minimum, 1) that the line budget's own protection is what keeps
 * `plate-row.js`'s realistic content from overlapping or slicing at all; `plant` (or `null`) is
 * `serve()`'s own shape.
 */
async function runOverlapScenario(chrome, plant) {
  const server = await serve(plant);
  const b = await browser(chrome, 1440, 900);
  try {
    const base = `http://127.0.0.1:${server.address().port}/index.html`;
    await b.load(`${base}?floors=14&phase=day&zoom=fit&w=1440&h=900`);
    const boxes = await b.evaluate(BOXES);
    let overlap = 0;
    for (let i = 0; i < boxes.labels.length; i += 1) {
      for (let j = i + 1; j < boxes.labels.length; j += 1) overlap += overlapArea(boxes.labels[i].box, boxes.labels[j].box);
    }
    return { overlap: +overlap.toFixed(2), sliced: slicedLines(boxes).length, side: boxes.side };
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
        const q = `floors=${run.floors}&phase=${run.phase}&zoom=${run.zoom}&w=${run.w}&h=${run.h}&rooms4=${run.rooms4 || 0}&longsum=${run.longsum || 0}&cab=0`;
        await b.load(`${base}?${q}`);
        const surfaceEl = await b.evaluate("(() => { const r = document.getElementById('lobby-building').getBoundingClientRect(); return { w: r.width, h: r.height, l: r.left, t: r.top, r: r.right, b: r.bottom }; })()");
        const surface = { w: surfaceEl.w, h: surfaceEl.h };
        const spans = await b.evaluate(SPANS);
        const boxes = await b.evaluate(BOXES);
        const drawn = await b.shot();
        await b.evaluate(HIDE_TEXT);
        const bare = await b.shot();
        const m = await b.evaluate(MEASURE(drawn, bare, spans, surfaceEl));

        let covered = 0;
        if (boxes.building) for (const l of boxes.labels) covered += overlapArea(l.box, boxes.building);
        let overlap = 0;
        for (let i = 0; i < boxes.labels.length; i += 1) {
          for (let j = i + 1; j < boxes.labels.length; j += 1) overlap += overlapArea(boxes.labels[i].box, boxes.labels[j].box);
        }
        const sliced = slicedLines(boxes);
        const unreachable = cueUnreachable(boxes);

        const population = run.zoom === 'plate' ? 1 : run.floors;
        const measured = spans.length >= population * 2 && m.glyphs > 200 * population && boxes.building !== null;
        const side = boxes.side;
        results.push({
          ...run, surface, side, spans: spans.length, ...m, covered: +covered.toFixed(2), overlap: +overlap.toFixed(2),
          sliced: sliced.length, unreachable: unreachable.length, measured,
          ok: measured && m.under === 0 && overlap === 0 && sliced.length === 0 && unreachable.length === 0 && (side !== 'left' || covered === 0),
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

const report = (r) => `${r.ok ? 'PASS' : 'FAIL'}  ${String(r.floors).padStart(2)} floors ${String(r.w) + 'x' + r.h} → surface ${r.surface.w}x${r.surface.h} ${r.zoom.padEnd(5)} ${r.phase.padEnd(5)} side=${(r.side ?? 'none').padEnd(5)}  `
  + `${r.spans} spans, ${r.glyphs} glyph px, min ${r.min?.toFixed(2)}:1, p1 ${r.p01?.toFixed(2)}:1, under ${MIN_RATIO}: ${r.under}, `
  + `covered ${r.covered}px², overlap ${r.overlap}px², sliced ${r.sliced}, cue-unreachable ${r.unreachable}`
  + `${r.measured ? '' : '  — NOT MEASURED (too few spans, glyph pixels, or no building box)'}${r.worst.length ? `  e.g. ${JSON.stringify(r.worst)}` : ''}`;

const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const shipped = await judge(chrome, null);
console.log('── the shipped lobby labels — contrast, coverage, overlap, line integrity');
for (const r of shipped) console.log(report(r));

let failed = shipped.some((r) => !r.ok);

if (argOf('--selftest') !== null || process.argv.includes('--selftest')) {
  for (const [check, [anchor, replacement]] of Object.entries({ contrast: PLANTS.contrast, coverage: PLANTS.coverage })) {
    const planted = await judge(chrome, { file: ['lobby', check === 'coverage' ? 'label-paint.js' : 'plate-row.js'], anchor, replacement });
    console.log(`── CONTROL (${check}): must FAIL`);
    for (const r of planted) console.log(report(r));

    const bit = check === 'contrast' ? planted.every((r) => r.measured) && planted.some((r) => r.under > 0)
      : planted.some((r) => r.side === 'left' && r.covered > 0);

    console.log(bit ? `CONTROL CAUGHT (${check})` : `CONTROL NOT CAUGHT (${check}) — the check cannot tell a correct render from this planted one`);
    failed = failed || !bit;
  }

  // The overlap AND slicing controls share one scenario and one plant (the header's own reasoning).
  console.log('── CONTROL (overlap / sliced lines): must FAIL');
  const shipped14 = await runOverlapScenario(chrome, null);
  const planted14 = await runOverlapScenario(chrome, { file: ['lobby', 'label-paint.js'], anchor: PLANTS.overlap[0], replacement: PLANTS.overlap[1] });
  console.log(`shipped: overlap ${shipped14.overlap}px², sliced ${shipped14.sliced}, side=${shipped14.side}`);
  console.log(`planted: overlap ${planted14.overlap}px², sliced ${planted14.sliced}, side=${planted14.side}`);
  const overlapBit = shipped14.overlap === 0 && shipped14.sliced === 0 && (planted14.overlap > 0 || planted14.sliced > 0);
  console.log(overlapBit ? 'CONTROL CAUGHT (overlap / sliced lines)' : 'CONTROL NOT CAUGHT (overlap / sliced lines) — the check cannot tell a correct render from this planted one');
  failed = failed || !overlapBit;
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

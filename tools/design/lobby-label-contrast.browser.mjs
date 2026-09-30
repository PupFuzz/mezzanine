#!/usr/bin/env node
// THE LOBBY's PLATE-LABEL CONTRAST, COVERAGE, OVERLAP AND LINE INTEGRITY, MEASURED ON RENDERED PIXELS.
// The contract this holds is `docs/design/FLOOR.md` § 4.1's; this header states only what this tool
// checks and how. Node, no dependencies, no network beyond 127.0.0.1 — but a real browser, because what
// a glyph stands on, whether a label's own box reaches the building's, whether two labels' boxes reach
// each other, and whether a line is shown WHOLE or sliced mid-glyph are each a composition only a
// renderer produces.
//
//   node tools/design/lobby-label-contrast.browser.mjs              # judge the shipped lobby modules
//   node tools/design/lobby-label-contrast.browser.mjs --selftest   # …and see each check red on its own planted defect
//   node tools/design/lobby-label-contrast.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ THE PAGE IS NOT THE SERVED ONE, BUT ITS LAYOUT-RELEVANT SHAPE IS: the served page ships NO
// stylesheet, so the ONLY thing narrowing `#lobby-building`'s width below the viewport's is the
// BROWSER'S OWN DEFAULT `<body>` margin. This harness's page keeps that default (never `body { margin:
// 0 }`) and nests the drawing exactly as the served page does, so the surface it measures is the ACTUAL
// rendered `clientWidth`/`clientHeight` a real browser gives that element at a given viewport, never a
// formula applied in this file.
//
// ⛔ WHAT IS RENDERED — every viewport and framing this file judges, so this sentence is never a claim
// wider than `RUNS` below actually covers: several viewport sizes, several floor counts at fit, every sky
// phase, a 4-room plate, a long summary, a wheel-between-renders case (`showLabels()` called again with
// NO row rebuilt), zoomed to one plate, and phone width.
//
// ⛔ WHAT IS MEASURED, PER RUN. The shipped `server/public/js/lobby/building-scene.js`,
// `building-paint.js`, `label-paint.js` and `plate-row.js` draw the building and stand every plate's
// label exactly as `lobby/main.js` does — `plateRow()` once, `showLabels()` on the SAME camera every
// `view()` would show it on. Five things are read back:
//   **Contrast** — each render is captured twice, as drawn and with every label span's text made
//   transparent and every link's own decoration killed in BOTH captures (so an underline is never counted
//   as a glyph). A pixel that differs between the two is a GLYPH pixel, and the second capture is what
//   that glyph stands on; the contrast of the span's own text colour against that pixel, and against a
//   RING of pixels out from it (in the SAME bare capture, clipped to the drawing surface), is WCAG 2.1's
//   ratio (SC 1.4.3). Any residual is declared by the line it sits on (the span's own text and a Y-BAND),
//   by name and reason, with its own cap — never a whole run's own cap, which a new defect elsewhere in
//   that run could hide behind.
//   **Coverage** — every plate's label (its outer box) against the building's own drawn box: the
//   intersection's area, in real screen px². Must be `0` wherever a label stands beside the building; the
//   fallback is the accepted case and is reported, never asserted at 0.
//   **Overlap** — every PAIR of plates' label boxes against each other: the intersection's area, which
//   must be `0` regardless of which side the labels stand on.
//   **No line sliced** — judged from RENDERED PIXELS, never a bounding-rect heuristic: every glyph-diff
//   pixel `MEASURE` finds (the SAME diff pass contrast already runs) must sit at or above its own label's
//   `clip-path` bottom edge, excluding one explained by falling inside some OTHER label's own legitimate
//   box; any that aren't are pixels the clip should never have let paint show.
//   **The cue stays reachable** — on the cab's own plate, the cue's own rect lies wholly inside the
//   label's unclipped (fully-shown) region, so *the elevator is here* is never itself the line a short
//   storey slices.
//
// ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP, AND IT IS NOT WIRED IN CI (a stock runner carries no
// Chromium — `.github/workflows/design-doc-verifiers.yml` names both browser gates and why). Run by hand.
//
// ⛔ THE SELFTEST PLANTS ONE DEFECT PER CHECK AND REQUIRES EACH TO RED ON ITS OWN, FOR ITS OWN REASON: no
// backing at all (contrast); the beside-the-building gap inverted, into the building (coverage); the
// per-storey line budget ignored (overlap — the same scenario this plant forces also overlaps every label
// with its neighbours, which masks the slice check's own signal there by the declared blind spot below,
// so this plant is not this check's own proof); the label's own clip-path bled on the bottom too (sliced
// lines, alone — never via overlap); the side threshold shrunk below the cue's own measured width
// (cue-reachable); and the fallback backing at alpha 0.4 on every fallback line (phone residual — a new
// defect still reds on a run that also carries an accepted one). Each browser control's predicate is
// also run against the shipped render, a no-op plant, and must NOT catch there. `residualSplit()` is
// checked on synthetic samples with no browser: its declared line and band hold, another line, another
// band or one past the cap red.
//
// ⚠ DECLARED BLIND SPOT, LEFT — NOT CHEAP TO CLOSE: the slice check's own neighbour-exclusion (`MEASURE`'s
// per-pixel loop, "explainedElsewhere") was added to stop a genuine false positive — a glyph pixel that is
// really a NEIGHBOUR's own correctly-clipped content, wrongly attributed to THIS label's bleed (found
// empirically) — by excluding any candidate pixel that falls inside some OTHER label's own box. That
// exclusion cannot currently tell "the neighbour's own real content" from "THIS label's bleed that happens
// to land inside the neighbour's box", so a run where labels overlap heavily can mask real slicing at the
// same time it overlaps — proper per-line attribution (which of the neighbour's own, budget-limited lines
// could legitimately paint at that exact y) would close it, and is future work.

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
  // r5's fix round (item 4): a plant that bites the SLICE check ALONE — the overlap control above also
  // slices (a forced 4-line budget outgrows a short storey), so it proved nothing about the slice check
  // on its own. This one widens the clip's OWN bottom bleed instead of touching the line budget at all,
  // reproducing r4 review MAJOR 2's exact shape (glyph paint past the label's own clip edge) with no
  // effect on how many lines are budgeted or on any other plate's own box, so it cannot trip overlap.
  slice: ["clipPath: 'inset(-4px -4px 0 -4px)',", "clipPath: 'inset(-4px -4px -12px -4px)',"],
  // r5's fix round (item 4): a plant for the cue-reachable check, which `label-paint.js`'s own docblock
  // had claimed a control for before one existed. Shrinking the side threshold well below the cue's own
  // measured width (≈189px) puts some run's beside label narrower than the cue's unshrinkable content,
  // pushing it past the label's own left edge — reachable ONLY by this specific narrow-width shape, never
  // by `LABEL_GAP_PX` or the line budget, so it cannot trip coverage, overlap or the slice check.
  cueWidth: ['export const LABEL_SIDE_MIN_PX = 240;', 'export const LABEL_SIDE_MIN_PX = 50;'],
  // The fallback backing at alpha 0.4 — every line of every FALLBACK label (the phone run's among them)
  // stands on a see-through backing, so the phone run gains under-threshold samples on lines and y-bands
  // its declared residual does not name, and must red despite also carrying that residual.
  // `residualSplitControls()` below is what shows the span+y-band key itself discriminates.
  phoneResidual: ['export const LABEL_BACKING = rgba(INK.wall, 0.985);', 'export const LABEL_BACKING = rgba(INK.wall, 0.4);'],
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

// In the page: each plate's own outer label box, the building's own drawn box, every VISIBLE line's own
// rect AND colour (for contrast, the no-sliced-line and the cue-reachable checks), and which of them
// holds the cab's cue. ⛔ r5's fix round: ONE query builds both the label geometry AND the per-line
// colour/rect data `MEASURE` needs — the r4-round tool queried these SEPARATELY (`SPANS`, a flat list
// with no owning label; `BOXES`, grouped by label but with no colour), so a glyph pixel had no way to
// know WHICH label's own clip edge it must be judged against — every beside label shares nearly the same
// horizontal span, so the slice check silently matched a floor's own glyph against a DIFFERENT floor's
// label above it, flagging nearly every glyph on every run as "sliced". Each line now carries its own
// owning label's box directly, so the pixel loop below checks a pixel against the ONE label it belongs
// to, never the whole building's worth of labels.
const BOXES = `(() => {
  const rect = (r) => ({ l: r.left, t: r.top, r: r.right, b: r.bottom });
  const labels = [...document.querySelectorAll('li[data-floor]')].map((li) => {
    const label = li.querySelector(':scope > div');
    const box = rect(label.getBoundingClientRect());
    const lines = [...label.querySelectorAll('a, div, span')]
      .filter((el) => el.children.length === 0 && getComputedStyle(el).clipPath !== 'inset(50%)' && el.textContent.trim() !== '')
      .map((el) => ({ text: el.textContent.trim(), isCue: el.tagName === 'SPAN', color: getComputedStyle(el).color,
        box: rect(el.getBoundingClientRect()),
        rects: [...el.getClientRects()].map((r) => ({ l: Math.floor(r.left), t: Math.floor(r.top), r: Math.ceil(r.right), b: Math.ceil(r.bottom) }))
          .filter((r) => r.r > r.l && r.b > r.t) }));
    return { floor: li.dataset.floor, box, lines };
  });
  const svg = document.querySelector('#lobby-building svg');
  return { labels, building: svg ? rect(svg.getBoundingClientRect()) : null, side: window.__labelSide };
})()`;

/**
 * Every visible line, flattened out of `boxes.labels`, each carrying its OWN label's box (`labelBox`) —
 * what `MEASURE` judges a glyph-diff pixel's slicing against. Never the whole label list: a pixel judged
 * against every label rather than the one it belongs to is the bug r5's fix round closed (this
 * function's own docblock, at `BOXES`).
 */
function labeledLines(boxes) {
  const out = [];

  for (const label of boxes.labels) {
    for (const line of label.lines) {
      out.push({ text: line.text, color: line.color, rects: line.rects, labelBox: label.box, floor: label.floor });
    }
  }

  return out;
}

// ⛔ r6's fix round (r5 review MAJOR 2): DECORATION IS NEVER PART OF THE DIFF, IN EITHER CAPTURE. An
// `<a>` draws `text-decoration: underline` in its OWN colour, so if the DRAWN capture shows it and the
// BARE one does not, the diff at every underline pixel is large regardless of any real glyph — the tool
// then measures the LINK's text colour against whatever sits under the UNDERLINE, which is a different
// question than SC 1.4.3 asks. Applied ONCE, before either capture, so both see the SAME (undecorated)
// rendering and an underline can never register as a glyph-diff pixel.
const KILL_DECORATION = `(() => { const s = document.createElement('style'); s.id = '__kill_decoration';
  s.textContent = 'li[data-floor] a{text-decoration:none!important}';
  document.head.append(s); return true; })()`;

// ⛔ THE LINK's OWN FILL, HIDDEN FOR THE BARE CAPTURE — what a glyph stands on, decoration already
// killed by `KILL_DECORATION` above in both captures alike.
const HIDE_TEXT = `(() => { const s = document.createElement('style'); s.id = '__hide';
  s.textContent = 'li[data-floor] a, li[data-floor] span, li[data-floor] div{color:transparent!important;-webkit-text-fill-color:transparent!important}';
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

// In the page: decode both captures and read the ratio at every glyph pixel AND its ring — and, in the
// SAME pass (r5's fix round, item 4: judge slicing from rendered pixels, not a bounding-rect heuristic —
// r4 review m4, "checker footprint ≠ painted"), whether that glyph-diff pixel sits BELOW its OWN line's
// OWN label's clip edge (`span.labelBox.b`, attached per line by `labeledLines()` — never a global list
// of every label, which a nearby FLOOR's box would false-positive against: every beside label shares
// nearly the same horizontal span, so a pixel checked against the wrong label's `box.b` is a defect this
// tool minted on its own first attempt, caught empirically against the SHIPPED, believed-correct code —
// canon #9's own case for seeing a check fail for the RIGHT reason before trusting it).
const MEASURE = (a, b, spans, surface, labels) => `(async () => {
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
  const allLabels = ${JSON.stringify(labels)};
  const ratios = []; const worst = []; const underSamples = []; let slicedPixels = 0; const slicedFloors = new Set(); const sliceSamples = [];
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
          // r5's fix round: a glyph-diff pixel past THIS span's OWN label's bottom clip edge is a
          // CANDIDATE — paint the clip should never have let through, since the label's own clip-path
          // bottom inset is 0, exactly at max-height. But a span's own LAYOUT rect (getBoundingClientRect,
          // never affected by clip-path) routinely overlaps the ADJACENT plate's own label below it once
          // storeys pack tight enough for this round's own line budget to matter — found empirically,
          // against the SHIPPED, believed-correct code (canon #9): a candidate pixel that also falls
          // inside some OTHER label's own box is that label's own legitimate, correctly-clipped content,
          // never THIS span's bleed-through, and is excluded rather than misattributed.
          if (y >= span.labelBox.b + 0.6) {
            let explainedElsewhere = false;
            for (const lb of allLabels) {
              if (lb.floor === span.floor) continue;
              if (x >= lb.box.l - 0.6 && x < lb.box.r + 0.6 && y >= lb.box.t - 0.6 && y < lb.box.b + 0.6) { explainedElsewhere = true; break; }
            }
            if (!explainedElsewhere) {
              slicedPixels += 1; slicedFloors.add(span.floor);
              if (sliceSamples.length < 3) sliceSamples.push({ text: span.text, floor: span.floor, x, y, overshoot: +(y - span.labelBox.b).toFixed(2) });
            }
          }
          let worstBg = bgAt(x, y);
          for (const [dx, dy] of RING) {
            const rx = x + dx, ry = y + dy;
            // r5's fix round (r4 review m7): bounded to the SURFACE, not the full image — a ring sample
            // landing in the page's own white body margin (outside the #lobby-building element) read as
            // an artificially light "background", producing the roughly 1.1:1 wheel artefact at the
            // surface's own top-left corner. A sample the surface does not cover answers nothing about
            // what a glyph here stands on and is skipped, exactly as the primary pixel's own
            // surface-edge inset already is.
            if (rx < surf.l || ry < surf.t || rx >= surf.r || ry >= surf.b) continue;
            const bg = bgAt(rx, ry);
            if (Math.abs(fg - bg) < Math.abs(fg - worstBg)) worstBg = bg;
          }
          const ratio = (Math.max(fg, worstBg) + 0.05) / (Math.min(fg, worstBg) + 0.05);
          ratios.push(ratio);
          if (ratio < ${MIN_RATIO}) {
            // r6's fix round (R3): every under-threshold pixel's OWN span text and y — never just a
            // whole-run count — is what a declared residual is keyed against, so a residual can name
            // "this line, this y-band" and nothing wider silently hides behind its cap.
            underSamples.push({ text: span.text, y });
            if (worst.length < 3) worst.push({ text: span.text, x, y, ratio: +ratio.toFixed(2) });
          }
        }
      }
    }
  }
  ratios.sort((p, q) => p - q);
  return { glyphs: ratios.length, min: ratios[0] ?? null, p01: ratios[Math.floor(ratios.length * 0.01)] ?? null,
    under: ratios.filter((v) => v < ${MIN_RATIO}).length, underSamples, worst, slicedPixels, slicedFloors: [...slicedFloors], sliceSamples };
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
 * `serve()`'s own shape. Slicing is judged from RENDERED PIXELS (r5's fix round, item 4) — the same
 * drawn/bare diff `judge()` uses — so this scenario captures both screenshots too, not just the boxes.
 */
async function runOverlapScenario(chrome, plant) {
  const server = await serve(plant);
  const b = await browser(chrome, 1440, 900);
  try {
    const base = `http://127.0.0.1:${server.address().port}/index.html`;
    await b.load(`${base}?floors=14&phase=day&zoom=fit&w=1440&h=900`);
    await b.evaluate(KILL_DECORATION);
    const surfaceEl = await b.evaluate("(() => { const r = document.getElementById('lobby-building').getBoundingClientRect(); return { w: r.width, h: r.height, l: r.left, t: r.top, r: r.right, b: r.bottom }; })()");
    const boxes = await b.evaluate(BOXES);
    const drawn = await b.shot();
    await b.evaluate(HIDE_TEXT);
    const bare = await b.shot();
    const m = await b.evaluate(MEASURE(drawn, bare, labeledLines(boxes), surfaceEl, boxes.labels.map((l) => ({ floor: l.floor, box: l.box }))));
    let overlap = 0;
    for (let i = 0; i < boxes.labels.length; i += 1) {
      for (let j = i + 1; j < boxes.labels.length; j += 1) overlap += overlapArea(boxes.labels[i].box, boxes.labels[j].box);
    }
    return { overlap: +overlap.toFixed(2), sliced: m.slicedPixels, slicedFloors: m.slicedFloors, side: boxes.side };
  } finally {
    b.close();
    server.close();
  }
}

/**
 * ⭐ r5's fix round (item 5), tightened by r6's (R3, r5 review MAJOR 2): ACCEPTED RESIDUALS, declared by
 * name and reason rather than chased to zero or left to fail the tool's exit code silently. ⚠ r5's own
 * shape capped the WHOLE RUN's `under` count — a run-keyed cap, which a NEW defect anywhere else in that
 * SAME run could hide behind unnoticed (the finding that sank the phone run's own gate: 52 measured px
 * were the summary link's underline, capped at 60, and nothing distinguished "the declared residual grew
 * a little" from "a real defect landed beside it"). Each residual here is now keyed to the LINE it sits
 * on — the span's own text plus a Y-BAND — so `judge()` explains each UNDER-THRESHOLD PIXEL individually:
 * one matching a residual's span/band, within its `cap`, is accepted; anything else — a different span, a
 * pixel outside the band, or a count past the cap — reds, whatever run it is in. `cap` is the MEASURED
 * count (R3: "the cap equal to the measured count"), not a round number guessed to leave headroom.
 */
const RESIDUALS = [
  {
    what: 'a night-sky star pixel directly behind "Floor 9"\'s own glyph',
    reason: 'real (the star IS brighter than the sky around it), negligible — confined to this one glyph',
    match: (run) => run.floors === 10 && run.phase === 'night' && run.w === 1440 && run.h === 900,
    text: 'Floor 9',
    yBand: [496, 499],
    cap: 3,
  },
  {
    what: 'the phone-width fallback\'s rooms line: a ring sample past its backing\'s bottom edge',
    reason: 'measured, not inferred: the glyph pixels (x 172-174, y 264) stand on the backing (bare capture '
      + '≈ rgb(249,240,226)); the ring\'s 3px sample straight below them, y 267, lies past the line box\'s '
      + 'bottom edge (y ≈ 267.09 — `plate-row.js`\'s LINE_PAD is \'0 5px\', no vertical room) on the '
      + 'night scene (≈ rgb(24,34,67)), and the dark ink reads ≈ 1.1:1 against THAT pixel, not against '
      + 'anything the glyph stands on',
    match: (run) => run.w === 375 && run.h === 812,
    text: '— rooms: alpha (office), beta (office — no seats reported for this room)',
    yBand: [263, 265],
    cap: 3,
  },
];

/**
 * Every under-threshold sample this run produced, split into what a declared residual explains and what
 * it does not — the residual's own `match`, `text` and `yBand` must ALL agree, never the run alone —
 * and whether the run's contrast holds: nothing unexplained, and no more explained than the cap.
 *
 * @return {{ explained: number, unexplained: Array<{text: string, y: number}>, declared: ?object, ok: boolean }}
 */
function residualSplit(run, underSamples) {
  const declared = RESIDUALS.find((res) => res.match(run));
  const explained = [];
  const unexplained = [];

  for (const s of underSamples) {
    if (declared && s.text === declared.text && s.y >= declared.yBand[0] && s.y <= declared.yBand[1]) {
      explained.push(s);
    } else {
      unexplained.push(s);
    }
  }

  const ok = unexplained.length === 0 && (declared === undefined || explained.length <= declared.cap);

  return { explained: explained.length, unexplained, declared: declared ?? null, ok };
}

/**
 * `residualSplit()` on synthetic samples, no browser: for each declared residual, N samples on its own
 * line inside its band must hold, and the SAME N samples moved to another line, or out of the band, must
 * red — the span+y-band key discriminating, shown directly. N is the residual's own cap.
 *
 * @return {string[]} one line per control that did not behave
 */
function residualSplitControls() {
  const bad = [];

  for (const res of RESIDUALS) {
    const run = RUNS.find((r) => res.match(r));
    if (run === undefined) { bad.push(`${res.what}: no run in RUNS matches it — the residual declares nothing measured`); continue; }
    const n = res.cap;
    const inBand = Array.from({ length: n }, () => ({ text: res.text, y: res.yBand[0] }));
    const otherLine = Array.from({ length: n }, () => ({ text: res.text + ' (another line)', y: res.yBand[0] }));
    const outOfBand = Array.from({ length: n }, () => ({ text: res.text, y: res.yBand[1] + 1 }));
    const overCap = Array.from({ length: n + 1 }, () => ({ text: res.text, y: res.yBand[0] }));

    if (!residualSplit(run, inBand).ok) bad.push(`${res.what}: ${n} samples on its own line inside its band red`);
    if (residualSplit(run, otherLine).ok) bad.push(`${res.what}: ${n} samples on another line hold`);
    if (residualSplit(run, outOfBand).ok) bad.push(`${res.what}: ${n} samples one row past its band hold`);
    if (residualSplit(run, overCap).ok) bad.push(`${res.what}: ${n + 1} samples, one past its cap, hold`);
  }

  return bad;
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
        await b.evaluate(KILL_DECORATION);
        const surfaceEl = await b.evaluate("(() => { const r = document.getElementById('lobby-building').getBoundingClientRect(); return { w: r.width, h: r.height, l: r.left, t: r.top, r: r.right, b: r.bottom }; })()");
        const surface = { w: surfaceEl.w, h: surfaceEl.h };
        const boxes = await b.evaluate(BOXES);
        const spans = labeledLines(boxes);
        const drawn = await b.shot();
        await b.evaluate(HIDE_TEXT);
        const bare = await b.shot();
        const m = await b.evaluate(MEASURE(drawn, bare, spans, surfaceEl, boxes.labels.map((l) => ({ floor: l.floor, box: l.box }))));

        let covered = 0;
        if (boxes.building) for (const l of boxes.labels) covered += overlapArea(l.box, boxes.building);
        let overlap = 0;
        for (let i = 0; i < boxes.labels.length; i += 1) {
          for (let j = i + 1; j < boxes.labels.length; j += 1) overlap += overlapArea(boxes.labels[i].box, boxes.labels[j].box);
        }
        const unreachable = cueUnreachable(boxes);

        const population = run.zoom === 'plate' ? 1 : run.floors;
        const measured = spans.length >= population * 2 && m.glyphs > 200 * population && boxes.building !== null;
        const side = boxes.side;
        const { explained, unexplained, declared, ok: residualOk } = residualSplit(run, m.underSamples);
        results.push({
          ...run, surface, side, spans: spans.length, ...m, covered: +covered.toFixed(2), overlap: +overlap.toFixed(2),
          unreachable: unreachable.length, measured, residual: declared ? declared.what : null, residualExplained: explained, residualUnexplained: unexplained,
          ok: measured && residualOk && overlap === 0 && m.slicedPixels === 0 && unreachable.length === 0 && (side !== 'left' || covered === 0),
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
  + `covered ${r.covered}px², overlap ${r.overlap}px², sliced-px ${r.slicedPixels}, cue-unreachable ${r.unreachable}`
  + `${r.measured ? '' : '  — NOT MEASURED (too few spans, glyph pixels, or no building box)'}${r.worst.length ? `  e.g. ${JSON.stringify(r.worst)}` : ''}`
  + `${r.sliceSamples.length ? `  sliced e.g. ${JSON.stringify(r.sliceSamples)}` : ''}`
  + `${r.residual ? `  ACCEPTED RESIDUAL (${r.residualExplained}): ${r.residual}` : ''}`
  + `${r.residualUnexplained?.length ? `  UNEXPLAINED under-threshold: ${JSON.stringify(r.residualUnexplained.slice(0, 5))}` : ''}`;

const selftest = argOf('--selftest') !== null || process.argv.includes('--selftest');

if (selftest) {
  const bad = residualSplitControls();
  console.log('── CONTROL (residualSplit, synthetic samples): the declared line and band hold; another line, another band or one past the cap red');
  for (const b of bad) console.log(`CONTROL NOT CAUGHT (residualSplit) — ${b}`);
  console.log(bad.length === 0 ? 'CONTROL CAUGHT (residualSplit)' : 'CONTROL NOT CAUGHT (residualSplit)');
  if (bad.length > 0) process.exitCode = 1;
}

const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const shipped = await judge(chrome, null);
console.log('── the shipped lobby labels — contrast, coverage, overlap, line integrity');
for (const r of shipped) console.log(report(r));

let failed = shipped.some((r) => !r.ok) || process.exitCode === 1;

if (selftest) {
  const judgePlants = {
    contrast: ['plate-row.js', PLANTS.contrast],
    coverage: ['label-paint.js', PLANTS.coverage],
    cueWidth: ['label-paint.js', PLANTS.cueWidth],
    phoneResidual: ['label-paint.js', PLANTS.phoneResidual],
  };

  // What each control counts as caught. Every predicate is also run against the SHIPPED results — a
  // no-op plant — and must NOT catch there, so a predicate that holds whatever is planted reds here.
  const bites = {
    contrast: (rs) => rs.some((r) => !r.ok && r.residualUnexplained.length > 0),
    coverage: (rs) => rs.some((r) => r.side === 'left' && r.covered > 0),
    cueWidth: (rs) => rs.some((r) => r.unreachable > 0),
    // The phone run itself must carry an UNEXPLAINED sample and red — more samples its residual's cap
    // could absorb would not count.
    phoneResidual: (rs) => rs.some((r) => r.w === 375 && r.h === 812 && r.residualUnexplained.length > 0 && !r.ok),
  };

  for (const check of Object.keys(judgePlants)) {
    const vacuous = bites[check](shipped);
    console.log(vacuous ? `CONTROL VACUOUS (${check}) — its predicate already holds on the shipped render, so it would catch any plant, or none`
      : `CONTROL NOT VACUOUS (${check}) — its predicate does not hold on the shipped render`);
    failed = failed || vacuous;
  }

  for (const [check, [file, [anchor, replacement]]] of Object.entries(judgePlants)) {
    const planted = await judge(chrome, { file: ['lobby', file], anchor, replacement });
    console.log(`── CONTROL (${check}): must FAIL`);
    for (const r of planted) console.log(report(r));

    const bit = bites[check](planted);

    console.log(bit ? `CONTROL CAUGHT (${check})` : `CONTROL NOT CAUGHT (${check}) — the check cannot tell a correct render from this planted one`);
    failed = failed || !bit;
  }

  // r5's fix round (item 4): ONE defect per check, each proven to bite ALONE. ⚠ r6 review round: the
  // overlap plant does NOT also slice (measured 0 sliced-px — the header's own declared blind spot is
  // why: heavy overlap masks slicing via the neighbour-exclusion, it does not cause it), so the slice
  // check's OWN control (`PLANTS.slice`, a widened bottom clip-path bleed) is this check's ONLY proof,
  // not a second proof beside an overlap side effect that was never real.
  console.log('── CONTROL (overlap): must FAIL');
  const shipped14 = await runOverlapScenario(chrome, null);
  const overlapPlanted = await runOverlapScenario(chrome, { file: ['lobby', 'label-paint.js'], anchor: PLANTS.overlap[0], replacement: PLANTS.overlap[1] });
  console.log(`shipped: overlap ${shipped14.overlap}px², sliced-px ${shipped14.sliced}, side=${shipped14.side}`);
  console.log(`planted: overlap ${overlapPlanted.overlap}px², sliced-px ${overlapPlanted.sliced}, side=${overlapPlanted.side}`);
  const overlapBit = shipped14.overlap === 0 && shipped14.sliced === 0 && overlapPlanted.overlap > 0;
  console.log(overlapBit ? 'CONTROL CAUGHT (overlap)' : 'CONTROL NOT CAUGHT (overlap) — the check cannot tell a correct render from this planted one');
  failed = failed || !overlapBit;

  console.log('── CONTROL (sliced lines, own plant, never via overlap): must FAIL');
  const slicePlanted = await runOverlapScenario(chrome, { file: ['lobby', 'plate-row.js'], anchor: PLANTS.slice[0], replacement: PLANTS.slice[1] });
  console.log(`planted: overlap ${slicePlanted.overlap}px², sliced-px ${slicePlanted.sliced}, floors ${JSON.stringify(slicePlanted.slicedFloors)}, side=${slicePlanted.side}`);
  const sliceBit = slicePlanted.sliced > 0 && slicePlanted.overlap === 0;
  console.log(sliceBit ? 'CONTROL CAUGHT (sliced lines)' : 'CONTROL NOT CAUGHT (sliced lines) — either it did not bite, or it bit through overlap rather than on its own');
  failed = failed || !sliceBit;
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

#!/usr/bin/env node
// THE LOBBY's PLATE-LABEL CONTRAST, MEASURED ON RENDERED PIXELS — card#7343 r1, the seat's ruling R2.
// Node, no dependencies, no network beyond 127.0.0.1 — but a real browser, because what a glyph stands
// on is a composition of the drawing, the sky behind the building, the halo and the backing, and only a
// renderer composes it.
//
//   node tools/design/lobby-label-contrast.browser.mjs              # judge the shipped lobby modules
//   node tools/design/lobby-label-contrast.browser.mjs --selftest   # …and see the check red on a planted label
//   node tools/design/lobby-label-contrast.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ WHAT IS MEASURED. The shipped `server/public/js/lobby/building-scene.js`, `building-paint.js` and
// `plate-row.js` draw a building of 3 and of 10 plates at whole-building fit, and of 3 zoomed to its top
// plate, at every sky `floor/floor-layout.js`'s `SKY_PAINT` names — the surface's backdrop, the windows,
// the storeys and every plate's label, exactly as `lobby/main.js` stands them. Each render is captured
// twice: as drawn, and with every label span's text made transparent (its halo and backing left as they
// are). A pixel that differs between the two is a GLYPH pixel, and the second capture is what that glyph
// stands on; the contrast of the span's own text colour against that pixel is WCAG 2.1's ratio
// (SC 1.4.3, relative luminance), and the check is that EVERY glyph pixel of every label reads at least
// 4.5:1. The figure reported is the minimum and the 1st percentile over every glyph pixel of each render.
//
// ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP — `floor-preview.browser.mjs`'s rule, for its reason: a gate
// that reports nothing when its instrument is missing is a check reported as passed that never ran.
// ⛔ AND IT IS NOT WIRED IN CI: a stock runner carries no Chromium (`.github/workflows/design-doc-verifiers.yml`
// names both browser gates and why). It is run by hand, on a host with `~/.cache/ms-playwright`.
//
// ⛔ THE SELFTEST PLANTS THE DEFECT THIS EXISTS FOR — a plate label with no backing, the halo alone, which
// the design review of card#7343 r1 measured at ≈1.8:1 over the sky — and requires the check to RED on it
// before it will report a green run as meaning anything.

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

/** The planted defect: the label's text spans stood on no backing — the halo alone. */
const NO_BACKING = ['    backgroundColor: LABEL_BACKING,\n', ''];

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

// The page: the lobby's building drawn by the shipped modules as `lobby/main.js` draws it — surface style,
// kept drawing, plates at their rects, the camera's one transform and the labels' counter-scale.
const PAGE = `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0}</style></head><body>
<div id="lobby-building"><ul id="lobby-floors" style="margin:0;padding:0;transform-origin:0 0"></ul></div>
<script type="module">
import { buildingScene, surfaceStyle, labelScale, labelMax } from './js/lobby/building-scene.js';
import { buildingDrawing, keepDrawing, paintBuilding } from './js/lobby/building-paint.js';
import { plateRow } from './js/lobby/plate-row.js';
import { createCamera, frameOn, focusOn } from './js/wire/camera.js';
const q = new URLSearchParams(location.search);
const n = Number(q.get('floors'));
const phase = q.get('phase') === 'unset' ? null : q.get('phase');
const plates = Array.from({ length: n }, (_, i) => ({ floor: 'f' + i, level: i, href: '/floor/f' + i,
  name: 'Floor ' + (i + 1), summary: '4 seats · 3 live',
  rooms: i % 3 === 1 ? [{ install_id: 'alpha', form: 'office', reported: true }, { install_id: 'beta', form: 'office', reported: false }] : [] }));
const scene = buildingScene(plates);
const surface = document.getElementById('lobby-building');
Object.assign(surface.style, surfaceStyle(scene, phase));
const rows = document.getElementById('lobby-floors');
const nodes = buildingDrawing(document);
keepDrawing(rows, nodes.drawing);
Object.assign(rows.style, { position: 'relative', width: scene.extent.w + 'px', height: scene.extent.h + 'px' });
paintBuilding(document, nodes, scene, 0, 0, null, phase);
for (const p of plates) rows.append(plateRow(document, p, scene.plates[p.level].rect, p.level === 0));
let cam = frameOn(createCamera({ width: surface.clientWidth, height: surface.clientHeight }), scene.extent);
if (q.get('zoom') === 'plate') cam = focusOn(cam, scene.plates[0].rect);
rows.style.transform = 'scale(' + cam.zoom + ') translate(' + (-cam.x) + 'px, ' + (-cam.y) + 'px)';
rows.style.setProperty('--label-scale', String(labelScale(cam)));
rows.style.setProperty('--label-max', labelMax(cam) + 'px');
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
    if (plant !== null && file.endsWith(join('lobby', 'plate-row.js'))) {
      if (!body.includes(plant[0])) throw new Error('the planted defect\'s anchor is gone from plate-row.js — the selftest would plant nothing');
      body = body.replace(plant[0], plant[1]);
    }
    res.writeHead(200, { 'content-type': 'text/javascript' });
    res.end(body);
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

/** Headless Chromium over the DevTools protocol, one page. */
async function browser(chrome) {
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
  const waiters = [];
  ws.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.id !== undefined && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(new Error(`${msg.error.message}`)); else resolve(msg.result);
    } else if (msg.method) {
      for (const w of waiters.splice(0)) w(msg);
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
  await page('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
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

const HIDE_TEXT = `(() => { const s = document.createElement('style'); s.id = '__hide';
  s.textContent = 'li[data-floor] span{color:transparent!important;-webkit-text-fill-color:transparent!important}';
  document.head.append(s); return true; })()`;

// In the page: decode both captures and read the ratio at every glyph pixel.
const MEASURE = (a, b, spans) => `(async () => {
  const load = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
  const [ia, ib] = await Promise.all([load(${JSON.stringify(a)}), load(${JSON.stringify(b)})]);
  const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    const g = c.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, img.width, img.height); };
  const A = px(ia), B = px(ib), W = A.width, H = A.height;
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  const L = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
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
          const bg = L(B.data[i], B.data[i + 1], B.data[i + 2]);
          const ratio = (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);
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
for (const floors of [3, 10]) for (const phase of ['day', 'dawn', 'dusk', 'night', 'unset']) RUNS.push({ floors, phase, zoom: 'fit' });
for (const phase of ['day', 'night']) RUNS.push({ floors: 3, phase, zoom: 'plate' });

/** Every run, measured; each result carries `ok`. */
async function judge(chrome, plant) {
  const server = await serve(plant);
  const b = await browser(chrome);
  const base = `http://127.0.0.1:${server.address().port}/index.html`;
  const results = [];
  try {
    for (const run of RUNS) {
      await b.load(`${base}?floors=${run.floors}&phase=${run.phase}&zoom=${run.zoom}`);
      const spans = await b.evaluate(SPANS);
      const drawn = await b.shot();
      await b.evaluate(HIDE_TEXT);
      const bare = await b.shot();
      const m = await b.evaluate(MEASURE(drawn, bare, spans));
      // Non-vacuous: every plate's name and summary were found, and glyphs were read under them.
      const measured = spans.length >= run.floors * 2 && m.glyphs > 200 * run.floors;
      results.push({ ...run, spans: spans.length, ...m, measured, ok: measured && m.under === 0 });
    }
  } finally {
    b.close();
    server.close();
  }
  return results;
}

const report = (r) => `${r.ok ? 'PASS' : 'FAIL'}  ${String(r.floors).padStart(2)} floors ${r.zoom.padEnd(5)} ${r.phase.padEnd(5)}  `
  + `${r.spans} spans, ${r.glyphs} glyph px, min ${r.min?.toFixed(2)}:1, p1 ${r.p01?.toFixed(2)}:1, under ${MIN_RATIO}: ${r.under}`
  + `${r.measured ? '' : '  — NOT MEASURED (too few spans or glyph pixels)'}${r.worst.length ? `  e.g. ${JSON.stringify(r.worst)}` : ''}`;

const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const shipped = await judge(chrome, null);
console.log('── the shipped lobby labels');
for (const r of shipped) console.log(report(r));
let failed = shipped.some((r) => !r.ok);

if (process.argv.includes('--selftest')) {
  const planted = await judge(chrome, NO_BACKING);
  console.log('── CONTROL: the labels planted with no backing (the halo alone) — must FAIL');
  for (const r of planted) console.log(report(r));
  const bit = planted.every((r) => r.measured) && planted.some((r) => r.under > 0);
  console.log(bit ? 'CONTROL CAUGHT — the check reds on a label with no backing' : 'CONTROL NOT CAUGHT — the check cannot tell a backed label from one with none');
  failed = failed || !bit;
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

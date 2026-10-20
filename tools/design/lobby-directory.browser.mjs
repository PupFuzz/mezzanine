#!/usr/bin/env node
// THE LOBBY DIRECTORY's TEXT — ITS SIZE, ITS BOX, ITS SCROLL, ITS LINES AND ITS CONTRAST — MEASURED ON A RENDER.
// The contract this holds is `docs/design/FLOOR.md` § 4.1's directory claims; this header states only what this tool
// checks and how. Node, no dependencies, no network beyond 127.0.0.1 — but a real browser, because the size a glyph is
// drawn at under two nested transforms, where a box lands under the camera, whether a list scrolls, whether a line
// wraps and what a glyph stands on are each a composition only a renderer produces. (card#12113 re-aimed this tool at
// the plaque's text on the plaque's face; until then it was `lobby-label-contrast.browser.mjs` and measured the
// cross-section's plate labels.)
//
//   node tools/design/lobby-directory.browser.mjs              # judge the shipped lobby modules and sheet
//   node tools/design/lobby-directory.browser.mjs --selftest   # …and see each check red on its own planted defect
//   node tools/design/lobby-directory.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ THE PAGE IS NOT THE SERVED ONE, BUT ITS LAYOUT-RELEVANT SHAPE IS: it links the served sheet
// (`server/public/css/mezzanine.css`, the shipped bytes) and nests the room in the elements that sheet styles it by
// (`<main><section class="lobby"><div id="lobby-building"><div id="lobby-room">`, the directory's and the readings' boxes
// and faces as `resources/views/dashboard.blade.php` declares them), and draws it with the shipped modules exactly as
// `lobby/main.js` does — `roomDrawing()`, `boxStyles()`, `renderDirectory()` over `lobby-model.js`'s `lobbyModel()`, and
// the camera's transform with `showDirectory()` on the same camera. The plaques are the model's over a fixture's
// snapshot (`fx-lobby.json`'s `lobby_attention`, read from disk), and, for the long directory, the same installs
// repeated under new keys.
//
// ⛔ WHAT IS RENDERED — every run `RUNS` names, so this sentence is never wider than they cover: a desktop and a laptop
// window, a short directory (the fixture's three floors, one of two rooms) and a long one (twelve), at fit, zoomed in
// about the centre, and brought to the board; and a phone's width at fit and on the board.
//
// ⛔ WHAT IS MEASURED, PER RUN — five checks:
//   **size** — every text element in the two faces is drawn at the page's own `1rem` on the screen: its computed font
//   size times the face's on-screen scale (its bounding width over its layout width), within half a px.
//   **board** — the directory's box stands on the board's inner rectangle under the camera (the scene rect through the
//   camera's transform, to a px), and its face fills its box, to a px.
//   **scroll** — the plaques' list scrolls (`overflow-y: auto`) and, on the long directory, holds more than it shows and
//   reaches its last plaque when scrolled to its end.
//   **wrap** — every plaque row is one line high (no taller than 1.6 of its line height).
//   **contrast** — each render is captured twice, as drawn and with every face's text transparent; a pixel that differs
//   is a GLYPH pixel, the second capture is what it stands on, and its WCAG 2.1 ratio (SC 1.4.3) against that pixel and a
//   ring of pixels 1 and 2 px out from it — kept inside the text's own line box, clipped to what its scroller shows, so
//   a chip's outline or the board past the list's edge is never what a glyph is judged against — must reach
//   `MIN_RATIO`, but for the `RESIDUALS` declared by name, line and band.
//
// ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP, AND IT IS NOT WIRED IN CI (a stock runner carries no Chromium —
// `.github/workflows/design-doc-verifiers.yml` names the browser tools and why). Run by hand.
//
// ⛔ THE SELFTEST PLANTS ONE DEFECT PER CHECK AND REQUIRES EACH TO RED ON ITS OWN: the plaque's ink paled to its face
// (contrast); the face's counter-scale dropped (size); the box moved off the board (board); the list made unscrollable
// (scroll); a row allowed to wrap (wrap). Each check's predicate is also run against the shipped render, a no-op plant,
// and must NOT catch there.

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const JS_ROOT = join(HERE, '..', '..', 'server', 'public', 'js');
const CSS = join(JS_ROOT, '..', 'css', 'mezzanine.css');
const FIXTURE = join(HERE, '..', '..', 'server', 'tests', 'Feature', 'Floor', 'fixtures', 'fx-lobby.json');

/** WCAG 2.1 SC 1.4.3's figure for normal text. */
const MIN_RATIO = 4.5;

/** The planted defects, one per check — the header's SELFTEST paragraph. `file` is under `server/public/`. */
const PLANTS = {
  contrast: { file: ['css', 'mezzanine.css'], anchor: '    --lobby-plaque-ink: #3b2f2a;', replacement: '    --lobby-plaque-ink: #e9dccd;' },
  size: { file: ['js', 'lobby', 'directory.js'], anchor: "            transform: drawn ? 'scale(var(--lobby-scale, 1))' : '',", replacement: "            transform: '',"},
  board: { file: ['js', 'lobby', 'directory.js'], anchor: '            left: drawn ? `${rect.x}px` : \'\',', replacement: '            left: drawn ? `${rect.x + 60}px` : \'\',' },
  scroll: { file: ['css', 'mezzanine.css'], anchor: "    overflow-y: auto;\n    touch-action: none;\n    display: flex;", replacement: "    overflow-y: hidden;\n    touch-action: none;\n    display: flex;" },
  wrap: { file: ['css', 'mezzanine.css'], anchor: ".lobby .lobby-plaque-row {\n    display: flex;\n    align-items: center;\n    gap: 6px;\n    min-width: 0;\n    white-space: nowrap;", replacement: ".lobby .lobby-plaque-row {\n    display: flex;\n    flex-wrap: wrap;\n    align-items: center;\n    gap: 6px;\n    min-width: 0;\n    white-space: normal;" },
};

/** The snapshot and the layout the plaques are the model's over — `lobby_attention`'s, from disk. */
function data(floors) {
  const run = JSON.parse(readFileSync(FIXTURE, 'utf8')).runs.lobby_attention;
  const snapshot = run.http['/api/fleet/snapshot'][0].body;
  const layout = run.http['/api/building'][0].body.layout.floors;
  const installs = [...snapshot.installs];

  // The long directory: the fixture's installs repeated under new keys until it holds `floors` floors, each its own floor.
  for (let i = 0; installs.length < floors + 1; i += 1) {
    const src = snapshot.installs[i % snapshot.installs.length];
    const id = `${src.install_id}-${String(i).padStart(2, '0')}`;

    installs.push({ ...src, install_id: id, seats: src.seats.map((s) => ({ ...s, install_id: id })) });
  }

  return { snapshot: { ...snapshot, installs: floors <= 3 ? snapshot.installs : installs }, layout, now: Date.parse(snapshot.server_time) + 1000 };
}

const PAGE = `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/css/mezzanine.css"></head><body><main><section id="lobby" class="lobby">
<div id="lobby-building" role="group"><div id="lobby-room">
<section id="lobby-directory"><div class="lobby-face lobby-directory-face"><h3 id="lobby-directory-heading"><span class="lobby-directory-title">FLOOR DIRECTORY</span> <span>— counts the seats this client holds</span></h3><ol id="lobby-floors" class="lobby-plaques" data-camera-scrolls></ol></div></section>
<div id="lobby-readings"><div class="lobby-face lobby-readings-face">
<section class="lobby-reading" data-camera-scrolls><h3>Building readings</h3><p><span>feed: live</span> · <span>resyncs: 0</span> · <span>7 seats · 6 live</span></p><p><span>store: ok</span> · <span>derivation: ok</span> · <span>sweep: ok</span></p><p><span>ingest: last receipt 14:23:14</span> · <span>membership as of 14:23:14</span></p></section>
<section class="lobby-reading" data-camera-scrolls><h3>Event log</h3><ol><li>14:23:14 · snapshot applied — 7 desks held</li></ol></section>
</div></div>
</div></div></section></main>
<script type="module">
import { lobbyScene, surfaceStyle } from './js/lobby/building-scene.js';
import { roomDrawing } from './js/lobby/building-paint.js';
import { boxStyles, renderDirectory, showDirectory } from './js/lobby/directory.js';
import { lobbyModel } from './js/lobby/lobby-model.js';
import { createCamera, frameOn, focusOn, zoomStep } from './js/wire/camera.js';
const q = new URLSearchParams(location.search);
const { snapshot, layout, now } = await (await fetch('/data.json?floors=' + q.get('floors'))).json();
const scene = lobbyScene();
const building = document.getElementById('lobby-building');
const layer = document.getElementById('lobby-room');
Object.assign(building.style, surfaceStyle(scene));
Object.assign(layer.style, { position: 'relative', width: scene.extent.w + 'px', height: scene.extent.h + 'px', transformOrigin: '0 0' });
layer.setAttribute('data-drawn', '');
layer.prepend(roomDrawing(document, () => {}).art);
for (const [id, rect] of [['lobby-directory', scene.directory], ['lobby-readings', scene.readings]]) {
  const box = document.getElementById(id);
  const s = boxStyles(rect, true);
  Object.assign(box.style, s.box);
  Object.assign(box.firstElementChild.style, s.face);
}
renderDirectory(document, document.getElementById('lobby-floors'), lobbyModel(snapshot, layout, null, now));
let cam = frameOn(createCamera({ width: building.clientWidth, height: building.clientHeight }), scene.extent);
if (q.get('camera') === 'zoom') cam = zoomStep(cam, 2);
if (q.get('camera') === 'board') cam = focusOn(cam, scene.board);
layer.style.transform = 'scale(' + cam.zoom + ') translate(' + (-cam.x) + 'px, ' + (-cam.y) + 'px)';
showDirectory(layer, cam);
window.__camera = cam;
document.title = 'drawn';
</script></body></html>`;

/** Serves the page, its data, the (shipped or planted) module tree and sheet on 127.0.0.1. */
function serve(plant) {
  const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://x');
    const path = decodeURIComponent(url.pathname);
    if (path === '/index.html') { res.writeHead(200, { 'content-type': 'text/html' }); res.end(PAGE); return; }
    if (path === '/data.json') { res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify(data(Number(url.searchParams.get('floors'))))); return; }
    const file = path === '/css/mezzanine.css' ? CSS : path.startsWith('/js/') ? normalize(join(JS_ROOT, path.slice(4))) : null;
    if (file === null || !file.startsWith(join(JS_ROOT, '..')) || !existsSync(file)) { res.writeHead(404); res.end(); return; }
    let body = readFileSync(file, 'utf8');
    if (plant !== null && file.endsWith(join(...plant.file))) {
      if (!body.includes(plant.anchor)) throw new Error(`the planted defect's anchor is gone from ${plant.file.join('/')} — the selftest would plant nothing`);
      body = body.replace(plant.anchor, plant.replacement);
    }
    res.writeHead(200, { 'content-type': file.endsWith('.css') ? 'text/css' : 'text/javascript' });
    res.end(body);
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

// In the page: every text-bearing element of the two faces with its on-screen font size and colour; the boxes and faces;
// the list's scroll; every plaque row's height against its line height; and the board's expected screen rect.
const READ = `(() => {
  const rect = (r) => ({ l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height });
  const cam = window.__camera;
  const surface = document.getElementById('lobby-building').getBoundingClientRect();
  const faces = [...document.querySelectorAll('.lobby-face')];
  const texts = [];
  for (const face of faces) {
    const scale = face.getBoundingClientRect().width / face.offsetWidth;
    // Each TEXT NODE on its own — its own rects (a range's), in its parent's colour — so a link's numeral, a span of its
    // own in another ink, is never measured in the link's.
    const walk = document.createTreeWalker(face, NodeFilter.SHOW_TEXT);
    for (let n = walk.nextNode(); n !== null; n = walk.nextNode()) {
      if (n.textContent.trim() === '') continue;
      const cs = getComputedStyle(n.parentElement);
      const range = document.createRange();
      range.selectNodeContents(n);
      // Each line box clipped to what its scroller shows: a line the list has scrolled past shows nothing there, and a
      // glyph at the scroller's edge stands on its line box, never on the board beyond the clip.
      // Every ancestor up to the face that clips its overflow clips the line too — a link squeezed to nothing shows none
      // of its text, though its text node keeps its rects.
      const clip = { left: -Infinity, top: -Infinity, right: Infinity, bottom: Infinity };
      for (let a = n.parentElement; a !== null && face.contains(a); a = a.parentElement) {
        const ac = getComputedStyle(a);
        if (ac.overflowX === 'visible' && ac.overflowY === 'visible') continue;
        const ar = a.getBoundingClientRect();
        clip.left = Math.max(clip.left, ar.left); clip.top = Math.max(clip.top, ar.top);
        clip.right = Math.min(clip.right, ar.right); clip.bottom = Math.min(clip.bottom, ar.bottom);
      }
      texts.push({ text: n.textContent.trim().slice(0, 60), px: parseFloat(cs.fontSize) * scale, color: cs.color,
        rects: [...range.getClientRects()].map((r) => ({ l: Math.ceil(Math.max(r.left, clip.left)), t: Math.ceil(Math.max(r.top, clip.top)), r: Math.floor(Math.min(r.right, clip.right)), b: Math.floor(Math.min(r.bottom, clip.bottom)) })).filter((r) => r.r > r.l && r.b > r.t) });
    }
  }
  const box = document.getElementById('lobby-directory');
  const board = { x: 308, y: 214, w: 488, h: 308 };
  const expected = { l: surface.left + (board.x - cam.x) * cam.zoom, t: surface.top + (board.y - cam.y) * cam.zoom, w: board.w * cam.zoom, h: board.h * cam.zoom };
  const list = document.getElementById('lobby-floors');
  const overflowY = getComputedStyle(list).overflowY;
  const before = list.scrollTop;
  list.scrollTop = list.scrollHeight;
  const last = list.lastElementChild?.getBoundingClientRect();
  const listRect = list.getBoundingClientRect();
  const reached = last !== undefined && last.bottom <= listRect.bottom + 1;
  list.scrollTop = before;
  const rows = [...document.querySelectorAll('.lobby-plaque-row')].map((r) => ({ text: r.textContent.trim().slice(0, 40), h: r.getBoundingClientRect().height,
    line: parseFloat(getComputedStyle(r).lineHeight) * (r.closest('.lobby-face').getBoundingClientRect().width / r.closest('.lobby-face').offsetWidth) }));
  return { texts, box: rect(box.getBoundingClientRect()), face: rect(box.firstElementChild.getBoundingClientRect()), expected,
    list: { overflowY, scrollHeight: list.scrollHeight, clientHeight: list.clientHeight, reached, plaques: list.children.length }, rows,
    surface: rect(surface) };
})()`;

// What a glyph stands on: every face's text made transparent, its decoration killed in both captures alike.
const KILL_DECORATION = `(() => { const s = document.createElement('style'); s.textContent = '.lobby-face a{text-decoration:none!important}'; document.head.append(s); return true; })()`;
const HIDE_TEXT = `(() => { const s = document.createElement('style'); s.textContent = '.lobby-face *{color:transparent!important;-webkit-text-fill-color:transparent!important}'; document.head.append(s); return true; })()`;

// In the page: decode both captures and read the ratio at every glyph pixel and a ring of 1 and 2 px around it.
const MEASURE = (a, b, spans, surface) => `(async () => {
  const load = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
  const [ia, ib] = await Promise.all([load(${JSON.stringify(a)}), load(${JSON.stringify(b)})]);
  const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; const g = c.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, img.width, img.height); };
  const A = px(ia), B = px(ib), W = A.width, H = A.height;
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  const L = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  const bgAt = (x, y) => { const i = (y * W + x) * 4; return L(B.data[i], B.data[i + 1], B.data[i + 2]); };
  const RING = []; for (const r of [1, 2]) for (let k = 0; k < 8; k += 1) RING.push([Math.round(r * Math.cos(k * Math.PI / 4)), Math.round(r * Math.sin(k * Math.PI / 4))]);
  const surf = ${JSON.stringify(surface)};
  const ratios = []; const under = [];
  for (const span of ${JSON.stringify(spans)}) {
    const [r, g, b] = span.color.match(/\\d+(\\.\\d+)?/g).map(Number);
    const fg = L(r, g, b);
    for (const rect of span.rects) {
      for (let y = Math.max(0, rect.t) + 1; y < Math.min(H, rect.b) - 1; y += 1) {
        for (let x = Math.max(0, rect.l) + 1; x < Math.min(W, rect.r) - 1; x += 1) {
          if (y < surf.t + 2 || y > surf.b - 2 || x < surf.l + 2 || x > surf.r - 2) continue;
          const i = (y * W + x) * 4;
          if (Math.abs(A.data[i] - B.data[i]) + Math.abs(A.data[i + 1] - B.data[i + 1]) + Math.abs(A.data[i + 2] - B.data[i + 2]) <= 24) continue;
          let worst = bgAt(x, y);
          // The ring stays inside the glyph's own line box: what the glyph stands on, never a chip's edge or the board.
          for (const [dx, dy] of RING) { const rx = x + dx, ry = y + dy; if (rx < rect.l || ry < rect.t || rx >= rect.r || ry >= rect.b) continue; const bg = bgAt(rx, ry); if (Math.abs(fg - bg) < Math.abs(fg - worst)) worst = bg; }
          const ratio = (Math.max(fg, worst) + 0.05) / (Math.min(fg, worst) + 0.05);
          ratios.push(ratio);
          if (ratio < ${MIN_RATIO} && under.length < 2000) under.push({ text: span.text, y, ratio: +ratio.toFixed(2) });
        }
      }
    }
  }
  ratios.sort((p, q) => p - q);
  return { glyphs: ratios.length, min: ratios[0] ?? null, under };
})()`;

/** The runs: a viewport, a directory's length and a camera. */
const RUNS = [
  { w: 1440, h: 900, floors: 3, camera: 'fit' },
  { w: 1440, h: 900, floors: 12, camera: 'fit' },
  { w: 1440, h: 900, floors: 3, camera: 'zoom' },
  { w: 1280, h: 800, floors: 3, camera: 'fit' },
  { w: 1280, h: 800, floors: 12, camera: 'board' },
  { w: 390, h: 700, floors: 3, camera: 'fit' },
  { w: 390, h: 700, floors: 3, camera: 'board' },
];

/**
 * ACCEPTED RESIDUALS, each declared by the run it is in, the line it sits on and a band of y, with the measured count as
 * its cap — anything else under the ratio reds. None is declared: the shipped render measured clean.
 */
const RESIDUALS = [];

function residualSplit(run, under) {
  const declared = RESIDUALS.filter((res) => res.match(run));
  const unexplained = under.filter((s) => !declared.some((res) => s.text === res.text && s.y >= res.yBand[0] && s.y <= res.yBand[1]));
  const explained = under.length - unexplained.length;

  return { unexplained, ok: unexplained.length === 0 && declared.every((res) => explained <= res.cap) };
}

/** Every run, measured; each result carries each check's verdict and `ok`. */
async function judge(chrome, plant) {
  const server = await serve(plant);
  const results = [];
  try {
    for (const run of RUNS) {
      const b = await browser(chrome, run.w, run.h);
      try {
        await b.load(`http://127.0.0.1:${server.address().port}/index.html?floors=${run.floors}&camera=${run.camera}`);
        await b.evaluate(KILL_DECORATION);
        const r = await b.evaluate(READ);
        const drawn = await b.shot();
        // `--shots <dir>`: each run's drawn capture, saved for a reader to look at — nothing reads it back.
        if (shots !== null) writeFileSync(join(shots, `directory-${run.w}x${run.h}-${run.floors}-${run.camera}.png`), Buffer.from(drawn.split(',')[1], 'base64'));
        await b.evaluate(HIDE_TEXT);
        const bare = await b.shot();
        const m = await b.evaluate(MEASURE(drawn, bare, r.texts, r.surface));
        const near = (x, y) => Math.abs(x - y) <= 1;
        const size = r.texts.filter((t) => Math.abs(t.px - 16) > 0.5).map((t) => `${t.text} ${t.px.toFixed(2)}px`);
        const board = near(r.box.l, r.expected.l) && near(r.box.t, r.expected.t) && near(r.box.w, r.expected.w) && near(r.box.h, r.expected.h)
          && near(r.face.l, r.box.l) && near(r.face.t, r.box.t) && near(r.face.w, r.box.w) && near(r.face.h, r.box.h);
        const scroll = r.list.overflowY === 'auto' && r.list.reached && (run.floors < 12 || r.list.scrollHeight > r.list.clientHeight);
        const wrap = r.rows.filter((row) => row.h > row.line * 1.6).map((row) => `${row.text} ${row.h.toFixed(1)}px`);
        const { unexplained, ok: contrast } = residualSplit(run, m.under);
        const measured = r.texts.length > 20 && m.glyphs > 2000 && r.rows.length >= 4 * Math.min(run.floors, 3);
        results.push({ run, size, board, scroll, wrap, contrast, unexplained, measured, glyphs: m.glyphs, min: m.min, texts: r.texts.length, list: r.list, box: r.box, expected: r.expected,
          ok: measured && size.length === 0 && board && scroll && wrap.length === 0 && contrast });
      } finally {
        b.close();
      }
    }
  } finally {
    server.close();
  }
  return results;
}

const report = (r) => `${r.ok ? 'PASS' : 'FAIL'}  ${r.run.w}x${r.run.h} ${String(r.run.floors).padStart(2)} floors ${r.run.camera.padEnd(5)}  `
  + `${r.texts} texts, ${r.glyphs} glyph px, min ${r.min?.toFixed(2)}:1; size-off ${r.size.length}, board ${r.board ? 'on' : 'OFF'}, `
  + `scroll ${r.scroll ? 'ok' : 'NO'} (${r.list.scrollHeight}/${r.list.clientHeight}, ${r.list.plaques} plaques), wrapped ${r.wrap.length}, `
  + `contrast ${r.contrast ? 'ok' : 'UNDER'}${r.measured ? '' : '  — NOT MEASURED'}`
  + `${r.size.length ? `  size e.g. ${JSON.stringify(r.size.slice(0, 3))}` : ''}${r.wrap.length ? `  wrap e.g. ${JSON.stringify(r.wrap.slice(0, 3))}` : ''}`
  + `${r.unexplained.length ? `  under e.g. ${JSON.stringify(r.unexplained.slice(0, 4))}` : ''}`
  + `${r.board ? '' : `  box ${JSON.stringify(r.box)} expected ${JSON.stringify(r.expected)}`}`;

const selftest = process.argv.includes('--selftest');
const shots = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const chrome = findChrome();

if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const shipped = await judge(chrome, null);
console.log('── the shipped lobby directory — size, board, scroll, wrap, contrast');
for (const r of shipped) console.log(report(r));

let failed = shipped.some((r) => !r.ok);

if (selftest) {
  // What each control counts as caught. Each predicate is also run against the SHIPPED results — a no-op plant — and
  // must NOT catch there, so a predicate that holds whatever is planted reds here.
  const bites = {
    contrast: (rs) => rs.some((r) => !r.contrast),
    size: (rs) => rs.some((r) => r.size.length > 0),
    board: (rs) => rs.some((r) => !r.board),
    scroll: (rs) => rs.some((r) => !r.scroll),
    wrap: (rs) => rs.some((r) => r.wrap.length > 0),
  };

  for (const check of Object.keys(PLANTS)) {
    const vacuous = bites[check](shipped);
    console.log(vacuous ? `CONTROL VACUOUS (${check}) — its predicate already holds on the shipped render` : `CONTROL NOT VACUOUS (${check})`);
    failed = failed || vacuous;
  }

  for (const [check, plant] of Object.entries(PLANTS)) {
    const planted = await judge(chrome, plant);
    console.log(`── CONTROL (${check}): must FAIL`);
    for (const r of planted) console.log(report(r));
    const bit = bites[check](planted);
    console.log(bit ? `CONTROL CAUGHT (${check})` : `CONTROL NOT CAUGHT (${check}) — the check cannot tell a correct render from this planted one`);
    failed = failed || !bit;
  }
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

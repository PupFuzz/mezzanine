#!/usr/bin/env node
// THE LOBBY DIRECTORY's TEXT — ITS SIZE, ITS BOX, ITS HOME, ITS SCROLL, ITS LINES, ITS TOUCH AND ITS CONTRAST — MEASURED ON
// A RENDER. The contract this holds is `docs/design/FLOOR.md` § 4.1's directory claims; this header states only what this
// tool checks and how. Node, no dependencies, no network beyond 127.0.0.1 — but a real browser, because the size a glyph
// is drawn at under two nested transforms, where a box lands under the camera, which home a box takes at a window's size,
// whether a list scrolls, whether a line is cut, what `touch-action` an element computes and what a glyph stands on are
// each a composition only a renderer produces. (card#12113 re-aimed this tool at the plaque's text on the plaque's face;
// until then it was `lobby-label-contrast.browser.mjs` and measured the cross-section's plate labels. card#12187 added the
// dock: below § 12's *Directory dock width* the boxes stand in the page's flow under the room.)
//
//   node tools/design/lobby-directory.browser.mjs              # judge the shipped lobby modules, sheet and markup
//   node tools/design/lobby-directory.browser.mjs --selftest   # …and see each check red on its own planted defect
//   node tools/design/lobby-directory.browser.mjs --shots <dir>   # …and save each run's capture (the docked and
//                                                                 # un-drawn ones as the whole page) for a reader
//   node tools/design/lobby-directory.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ THE PAGE IS NOT THE SERVED ONE, BUT ITS LAYOUT-RELEVANT SHAPE IS THE SHIPPED MARKUP'S: it links the served sheet
// (`server/public/css/mezzanine.css`, the shipped bytes) and carries the lobby's surface and its two boxes as
// `resources/views/dashboard.blade.php` declares them — read out of that file on every run, its Blade comments dropped,
// `data-home="flow"` and all — inside `<main><section class="lobby">`, and draws them with the shipped modules exactly as
// `lobby/main.js` does: `surfaceStyle()`, `roomDrawing()`, `renderDirectory()` over `lobby-model.js`'s `lobbyModel()`,
// `directory.js`'s docking entry placing the boxes, and the camera's transform with `showDirectory()` on the same camera.
// The plaques are the model's over a fixture's snapshot (`fx-lobby.json`'s `lobby_attention`, read from disk), and, for
// the long directory, the same installs repeated under new keys.
//
// ⛔ WHAT IS RENDERED — every run `RUNS` names, so this sentence is never wider than they cover: BOARD runs, which must
// stand the boxes on the board (a desktop window and a tall laptop window, the short directory and the long one, at fit,
// zoomed in and brought to the board); DOCKED runs, which must dock them (three phones upright and one sideways, a
// tablet, a short window and the two real laptops' page viewports, each with the short and the long directory); and
// UN-DRAWN runs, before any room is drawn — one with script execution disabled before the module runs at all, one after
// a fetch that answers no snapshot — at a desktop and a phone width. And one TRANSITION run (`TRANSITION`): loaded at a
// phone, resized to the desktop window, back, to the desktop again, and its root font size then set to 28 px there.
//
// ⛔ WHAT IS MEASURED — per run, the checks that apply to its kind:
//   **size** (every run) — every text element in the two faces is drawn at the page's own `1rem` on the screen: its
//   computed font size times the face's on-screen scale (its bounding width over its layout width), within half a px.
//   **board** (board runs) — both boxes carry `data-home="board"` and stand in the camera's layer, and the directory's box
//   stands on the board's inner rectangle under the camera (the scene rect through the camera's transform, to a px), its
//   face filling its box, to a px.
//   **scroll** (board runs) — the plaques' list scrolls (`overflow-y: auto`) and, on the long directory, holds more than
//   it shows and reaches its last plaque when scrolled to its end.
//   **dock** (docked runs) — both boxes carry `data-home="dock"` and stand in the page's flow, not the layer; the
//   directory is the section's full width; the readings' two panels stand one under the other; and neither face is
//   counter-scaled.
//   **flow** (docked runs) — the plaques' list is no scroller, the page scrolled to its end shows the last plaque, and
//   each readings panel is capped at ten of its lines.
//   **touch** (board, docked and un-drawn runs) — the COMPUTED `touch-action` of the plaques' list, both readings panels
//   and the surface: `none` on the board; off it neither `none` nor `pan-y` (each refused by name) and allowing the
//   browser's pinch-zoom (`auto`). Synthetic pointers bypass `touch-action`, so this reads the property, never a finger.
//   **tap** (docked runs) — every plaque link's box is at least 24 × 24 CSS px (WCAG 2.2 SC 2.5.8).
//   **undrawn** (un-drawn runs) — both boxes carry `data-home="flow"` — with script disabled, from the markup alone — and
//   their text meets the contrast check on the page's own ground.
//   **transition** (the transition run) — after each step, the boxes' `data-home`, the surface's computed `touch-action`
//   and whether each face is counter-scaled are the step's owed home's: `dock` at the phone and at 28 px, `board` at the
//   desktop window.
//   **clip** (every run) — no element of the two faces, the faces included, hides part of what it holds: none is wider
//   than its box (`scrollWidth > clientWidth`) under an overflow that is not `visible` — a line cut at its end, or a list
//   scrolling sideways — and none is taller than its box under one that only hides (`hidden`, `clip`), but for the
//   `CLIP_RESIDUALS` declared by run and element. A list or a panel that scrolls DOWN holds its lines.
//   **contrast** (every run) — each render is captured twice, as drawn and with every face's text transparent; a pixel
//   that differs is a GLYPH pixel, the second capture is what it stands on, and its WCAG 2.1 ratio (SC 1.4.3) against
//   that pixel and a ring of pixels 1 and 2 px out from it — kept inside the text's own line box, clipped to what its
//   scroller shows, and to the surface on the board or the window off it — must reach `MIN_RATIO`, but for the
//   `RESIDUALS` declared by name, line and band.
//
// ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP, AND IT IS NOT WIRED IN CI (a stock runner carries no Chromium —
// `.github/workflows/design-doc-verifiers.yml` names the browser tools and why). Run by hand.
//
// ⛔ THE SELFTEST PLANTS AT LEAST ONE DEFECT PER CHECK AND REQUIRES EACH TO RED ON ITS OWN — `PLANTS`, each an edit to
// what is SERVED (a module, the sheet or the markup): for the card#12113 checks, the plaque's ink paled to its face
// (contrast), the face's counter-scale dropped (size), the box moved off the board (board), the board's list made
// unscrollable (scroll), and the rules card#12113's first review found cutting text re-applied — a plaque row, a reading
// and the title each one line cut at its end (clip; the name row's old shrinking rooms line and agents and desks are
// no longer planted here: since card#12187 no face is narrow enough for them to cut anything, so no render can red on
// them, and `TheLobbyIsADrawnRoomTest`'s sheet check refuses those rules by name); for card#12187's,
// the bound set to nothing (dock) and to 30 em (board), the docked list left a scroller (flow), the readings left side by
// side (dock), `touch-action: none` left on the docked list, `pan-y` on the docked list and the surface, and the board's
// `none` rule removed (touch), a link's minimum height removed (tap), the un-drawn colours keyed back on `#lobby-room` and
// the markup's `data-home="flow"` removed (undrawn), and the `ResizeObserver` on the `1rem` probe removed and the entry's
// surface rewrite skipped (transition). Each check's predicate is also run against the shipped render, a no-op plant,
// and must NOT catch there.

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const JS_ROOT = join(HERE, '..', '..', 'server', 'public', 'js');
const CSS = join(JS_ROOT, '..', 'css', 'mezzanine.css');
const BLADE = join(HERE, '..', '..', 'server', 'resources', 'views', 'dashboard.blade.php');
const FIXTURE = join(HERE, '..', '..', 'server', 'tests', 'Feature', 'Floor', 'fixtures', 'fx-lobby.json');

/** WCAG 2.1 SC 1.4.3's figure for normal text. */
const MIN_RATIO = 4.5;

/** WCAG 2.2 SC 2.5.8's least target, in CSS px — § 4.1 declares it for a docked plaque's link. */
const TAP_PX = 24;

/**
 * The planted defects, at least one per check — the header's SELFTEST paragraph. Each is one edit or several, to a file
 * under `server/public/` (`file`) or to the Blade page (`file: ['blade']`); a plant whose name is not its check's names
 * the check it plants for in `check`.
 */
const PLANTS = {
  contrast: { edits: [{ file: ['css', 'mezzanine.css'], anchor: '    --lobby-plaque-ink: #3b2f2a;', replacement: '    --lobby-plaque-ink: #e9dccd;' }] },
  size: { edits: [{ file: ['js', 'lobby', 'directory.js'], anchor: "            transform: drawn ? 'scale(var(--lobby-scale, 1))' : '',", replacement: "            transform: '',"}] },
  board: { edits: [{ file: ['js', 'lobby', 'directory.js'], anchor: '            left: drawn ? `${rect.x}px` : \'\',', replacement: '            left: drawn ? `${rect.x + 60}px` : \'\',' }] },
  'board (the bound set to 30 em — the 1,440 × 780 board docked)': { check: 'board', edits: [{ file: ['js', 'lobby', 'directory.js'], anchor: 'export const DOCK_BOUND = Object.freeze({ w: 24, h: 15 });', replacement: 'export const DOCK_BOUND = Object.freeze({ w: 30, h: 15 });' }] },
  scroll: { edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby [data-home="board"] .lobby-plaques {\n    overflow-y: auto;', replacement: '.lobby [data-home="board"] .lobby-plaques {\n    overflow-y: hidden;' }] },
  'dock (the bound set to nothing — the box back on the board at a phone)': { check: 'dock', edits: [{ file: ['js', 'lobby', 'directory.js'], anchor: 'export const DOCK_BOUND = Object.freeze({ w: 24, h: 15 });', replacement: 'export const DOCK_BOUND = Object.freeze({ w: 0, h: 0 });' }] },
  'dock (the readings left side by side)': { check: 'dock', edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby :not([data-home="board"]) > .lobby-readings-face {\n    grid-template-columns: minmax(0, 1fr);', replacement: '.lobby :not([data-home="board"]) > .lobby-readings-face {\n    grid-template-columns: repeat(2, minmax(0, 1fr));' }] },
  'flow (the docked list left a scroller)': { check: 'flow', edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby [data-home="dock"] .lobby-plaque-name {', replacement: '.lobby [data-home="dock"] .lobby-plaques {\n    overflow-y: auto;\n    max-height: 160px;\n}\n\n.lobby [data-home="dock"] .lobby-plaque-name {' }] },
  'touch (touch-action: none left on the docked list)': { check: 'touch', edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby .lobby-plaques {\n    flex: 1 1 auto;', replacement: '.lobby .lobby-plaques {\n    touch-action: none;\n    flex: 1 1 auto;' }] },
  'touch (pan-y left on the docked list and the surface)': { check: 'touch', edits: [
    { file: ['css', 'mezzanine.css'], anchor: '.lobby .lobby-plaques {\n    flex: 1 1 auto;', replacement: '.lobby .lobby-plaques {\n    touch-action: pan-y;\n    flex: 1 1 auto;' },
    { file: ['js', 'lobby', 'directory.js'], anchor: "        building.style.touchAction = next === 'board' ? 'none' : 'auto';", replacement: "        building.style.touchAction = next === 'board' ? 'none' : 'pan-y';" },
  ] },
  // Review F5: the board's own `none` rule removed — the board-run touch check must red on it.
  "touch (the board's none rule removed)": { check: 'touch', edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby [data-home="board"] .lobby-plaques {\n    overflow-y: auto;\n    touch-action: none;\n}', replacement: '.lobby [data-home="board"] .lobby-plaques {\n    overflow-y: auto;\n}' }] },
  "tap (a link's minimum height removed)": { check: 'tap', edits: [{ file: ['css', 'mezzanine.css'], anchor: '    min-width: 24px;\n    min-height: 24px;\n', replacement: '    min-width: 24px;\n' }] },
  'undrawn (the un-drawn colours keyed back on #lobby-room)': { check: 'undrawn', edits: [
    { file: ['css', 'mezzanine.css'], anchor: '.lobby [data-home="flow"] .lobby-face {', replacement: '.lobby #lobby-room:not([data-drawn]) .lobby-face {' },
    { file: ['css', 'mezzanine.css'], anchor: '.lobby [data-home="flow"] .lobby-reading,\n.lobby [data-home="flow"] .lobby-plaque {', replacement: '.lobby #lobby-room:not([data-drawn]) .lobby-reading,\n.lobby #lobby-room:not([data-drawn]) .lobby-plaque {' },
  ] },
  'undrawn (the markup\'s data-home="flow" removed)': { check: 'undrawn', edits: [
    { file: ['blade'], anchor: '<section id="lobby-directory" aria-labelledby="lobby-directory-heading" data-home="flow">', replacement: '<section id="lobby-directory" aria-labelledby="lobby-directory-heading">' },
    { file: ['blade'], anchor: '<div id="lobby-readings" data-home="flow">', replacement: '<div id="lobby-readings">' },
  ] },
  'transition (the ResizeObserver on the 1rem probe removed)': { check: 'transition', edits: [{ file: ['js', 'lobby', 'directory.js'], anchor: '    new win.ResizeObserver(() => place()).observe(probe);\n', replacement: '' }] },
  "transition (the entry's surface rewrite skipped)": { check: 'transition', edits: [{ file: ['js', 'lobby', 'directory.js'], anchor: "        building.style.touchAction = next === 'board' ? 'none' : 'auto';\n", replacement: '' }] },
  clip: { edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby .lobby-plaque-row {\n    display: flex;\n    flex-wrap: wrap;', replacement: '.lobby .lobby-plaque-row {\n    white-space: nowrap;\n    text-overflow: ellipsis;\n    display: flex;' }] },
  'clip (the readings one line)': { check: 'clip', edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby .lobby-reading li {\n    margin: 0;', replacement: '.lobby .lobby-reading li {\n    margin: 0;\n    white-space: nowrap;\n    overflow: hidden;\n    text-overflow: ellipsis;' }] },
  'clip (the title one line)': { check: 'clip', edits: [{ file: ['css', 'mezzanine.css'], anchor: '.lobby #lobby-directory-heading {\n    flex: none;', replacement: '.lobby #lobby-directory-heading {\n    white-space: nowrap;\n    overflow: hidden;\n    text-overflow: ellipsis;\n    flex: none;' }] },
};

/** A file's text with every edit of `plant` that names it applied — each anchor required, or the plant would plant nothing. */
function planted(text, plant, file) {
  let out = text;
  for (const edit of plant?.edits ?? []) {
    if (edit.file.join('/') !== file.join('/')) continue;
    if (!out.includes(edit.anchor)) throw new Error(`the planted defect's anchor is gone from ${file.join('/')} — the selftest would plant nothing`);
    out = out.replace(edit.anchor, edit.replacement);
  }
  return out;
}

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

/** The lobby's surface and its two boxes, as the Blade page declares them — its comments dropped — under `plant`. */
function markup(plant) {
  const blade = planted(readFileSync(BLADE, 'utf8'), plant, ['blade']);
  const from = blade.indexOf('<div id="lobby-building"');
  const to = blade.indexOf("@include('partials.camera-hint'");
  if (from < 0 || to < from) throw new Error('dashboard.blade.php no longer holds `#lobby-building` before the camera hint — the page this tool draws would be a guess');
  return blade.slice(from, to).replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

// The module the page runs — `lobby/main.js`'s drawing, step for step, over the shipped modules.
const SCRIPT = `
import { lobbyScene, surfaceStyle } from './js/lobby/building-scene.js';
import { roomDrawing } from './js/lobby/building-paint.js';
import { dockingEntry, renderDirectory, showDirectory } from './js/lobby/directory.js';
import { lobbyModel } from './js/lobby/lobby-model.js';
import { createCamera, frameOn, focusOn, zoomStep } from './js/wire/camera.js';
window.__ran = true;
const q = new URLSearchParams(location.search);
const scene = lobbyScene();
const building = document.getElementById('lobby-building');
const layer = document.getElementById('lobby-room');
const dock = dockingEntry(window, document, { building, layer, boxes: [[document.getElementById('lobby-directory'), scene.directory], [document.getElementById('lobby-readings'), scene.readings]], scene });
const answer = await (await fetch('/data.json?floors=' + q.get('floors') + '&mode=' + q.get('mode'))).json();
if (answer.snapshot === null) {
  // A fetch that answers no snapshot: no room is drawn, and the placeholders stand where the markup put them.
  Object.assign(building.style, surfaceStyle(null));
  dock.place(false);
} else {
  const { snapshot, layout, now } = answer;
  const say = (id, text) => { document.getElementById(id).textContent = text; };
  say('lobby-feed', 'feed: live'); say('lobby-resyncs', 'resyncs: 0'); say('lobby-totals', '7 seats · 6 live');
  say('lobby-store', 'store: ok'); say('lobby-derivation', 'derivation: ok'); say('lobby-sweep', 'sweep: ok');
  say('lobby-ingest', 'ingest: last receipt 14:23:14'); say('lobby-stamp', 'membership as of 14:23:14');
  const log = document.getElementById('lobby-log');
  log.replaceChildren(...['14:23:14 · snapshot applied — 7 desks held'].map((t) => Object.assign(document.createElement('li'), { textContent: t })));
  log.hidden = false;
  Object.assign(building.style, surfaceStyle(scene));
  Object.assign(layer.style, { position: 'relative', width: scene.extent.w + 'px', height: scene.extent.h + 'px', transformOrigin: '0 0' });
  layer.setAttribute('data-drawn', '');
  layer.prepend(roomDrawing(document, () => {}).art);
  renderDirectory(document, document.getElementById('lobby-floors'), lobbyModel(snapshot, layout, null, now));
  dock.place(true);
  let cam = frameOn(createCamera({ width: building.clientWidth, height: building.clientHeight }), scene.extent);
  if (q.get('camera') === 'zoom') cam = zoomStep(cam, 2);
  if (q.get('camera') === 'board') cam = focusOn(cam, scene.board);
  layer.style.transform = 'scale(' + cam.zoom + ') translate(' + (-cam.x) + 'px, ' + (-cam.y) + 'px)';
  showDirectory(layer, cam);
  window.__camera = cam;
}
document.title = 'drawn';
`;

/** The page: the shipped sheet, the shipped markup and the module; with no script, the title the loader waits on is the markup's. */
const page = (plant, noscript) => `<!doctype html><html><head><meta charset="utf-8">${noscript ? '<title>drawn</title>' : ''}<link rel="stylesheet" href="/css/mezzanine.css"></head><body><main><section id="lobby" class="lobby">
${markup(plant)}</section></main>
<script type="module">${SCRIPT}</script></body></html>`;

/** Serves the page, its data, the (shipped or planted) module tree and sheet on 127.0.0.1. */
function serve(plant) {
  const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://x');
    const path = decodeURIComponent(url.pathname);
    if (path === '/index.html') { res.writeHead(200, { 'content-type': 'text/html' }); res.end(page(plant, url.searchParams.get('mode') === 'noscript')); return; }
    if (path === '/data.json') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify(url.searchParams.get('mode') === 'nosnapshot' ? { snapshot: null } : data(Number(url.searchParams.get('floors')))));
      return;
    }
    const file = path === '/css/mezzanine.css' ? CSS : path.startsWith('/js/') ? normalize(join(JS_ROOT, path.slice(4))) : null;
    if (file === null || !file.startsWith(join(JS_ROOT, '..')) || !existsSync(file)) { res.writeHead(404); res.end(); return; }
    const rel = file.slice(join(JS_ROOT, '..').length + 1).split(/[\\/]/);
    const body = planted(readFileSync(file, 'utf8'), plant, rel);
    res.writeHead(200, { 'content-type': file.endsWith('.css') ? 'text/css' : 'text/javascript' });
    res.end(body);
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

// In the page: every text-bearing element of the two faces with its on-screen font size and colour; the boxes, their
// homes and faces; the list's scroll, inside its box and on the page; every element's computed touch-action that a finger
// can reach; every plaque link's box; the readings' panels; and the board's expected screen rect.
const READ = `(() => {
  const rect = (r) => ({ l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height });
  const cam = window.__camera ?? null;
  const building = document.getElementById('lobby-building');
  const layer = document.getElementById('lobby-room');
  const surface = building.getBoundingClientRect();
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
      if (cs.display === 'none' || n.parentElement.closest('[hidden]') !== null) continue;
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
  const readings = document.getElementById('lobby-readings');
  const board = { x: 308, y: 214, w: 488, h: 308 };
  const expected = cam === null ? null : { l: surface.left + (board.x - cam.x) * cam.zoom, t: surface.top + (board.y - cam.y) * cam.zoom, w: board.w * cam.zoom, h: board.h * cam.zoom };
  const list = document.getElementById('lobby-floors');
  const overflowY = getComputedStyle(list).overflowY;
  const before = list.scrollTop;
  list.scrollTop = list.scrollHeight;
  const last = list.lastElementChild?.getBoundingClientRect();
  const listRect = list.getBoundingClientRect();
  const reached = last !== undefined && last.bottom <= listRect.bottom + 1;
  list.scrollTop = before;
  // The PAGE scrolled to its end: the last plaque shown in the window (the docked list's scroll is the page's).
  window.scrollTo(0, document.documentElement.scrollHeight);
  const lastOnPage = list.lastElementChild?.getBoundingClientRect();
  const reachedPage = lastOnPage !== undefined && lastOnPage.bottom <= innerHeight + 1;
  window.scrollTo(0, 0);
  const rows = document.querySelectorAll('.lobby-plaque-row').length;
  // Every element of the two faces, the faces included, that hides part of what it holds: wider than its box under an
  // overflow that is not \`visible\` (a cut line, or a list scrolling sideways), or taller than its box under one that
  // only hides (\`hidden\` or \`clip\` — a list or a panel that SCROLLS down holds its lines, it does not hide them).
  const cut = [];
  for (const face of faces) {
    for (const e of [face, ...face.querySelectorAll('*')]) {
      const cs = getComputedStyle(e);
      const wide = cs.overflowX !== 'visible' && e.scrollWidth > e.clientWidth;
      const tall = (cs.overflowY === 'hidden' || cs.overflowY === 'clip') && e.scrollHeight > e.clientHeight;
      if (wide || tall) cut.push({ el: e.id ? '#' + e.id : (e.className || e.tagName), text: e.textContent.trim().replace(/\\s+/g, ' ').slice(0, 70), sw: e.scrollWidth, cw: e.clientWidth, sh: e.scrollHeight, ch: e.clientHeight });
    }
  }
  const panels = [...document.querySelectorAll('.lobby-reading')];
  const ta = (e) => getComputedStyle(e).touchAction;
  const section = document.getElementById('lobby');
  return { texts, box: rect(box.getBoundingClientRect()), face: rect(box.firstElementChild.getBoundingClientRect()), expected,
    list: { overflowY, scrollHeight: list.scrollHeight, clientHeight: list.clientHeight, reached, reachedPage, plaques: list.children.length }, rows, cut,
    surface: rect(surface), viewport: { l: 0, t: 0, r: innerWidth, b: innerHeight },
    ran: window.__ran === true,
    homes: [box, readings].map((b) => b.getAttribute('data-home')),
    inLayer: [box, readings].map((b) => b.parentElement === layer),
    afterSurface: building.nextElementSibling === box && box.nextElementSibling === readings,
    sectionWidth: section.clientWidth,
    scaled: [box, readings].map((b) => getComputedStyle(b.firstElementChild).transform !== 'none'),
    touch: { list: ta(list), panels: panels.map(ta), surface: ta(building) },
    panels: panels.map((p) => { const cs = getComputedStyle(p); return { ...rect(p.getBoundingClientRect()), maxHeight: cs.maxHeight, line: parseFloat(cs.lineHeight) }; }),
    links: [...list.querySelectorAll('.lobby-plaque-name')].map((a) => rect(a.getBoundingClientRect())) };
})()`;

// What a glyph stands on: every face's text made transparent, its decoration killed in both captures alike.
const KILL_DECORATION = `(() => { const s = document.createElement('style'); s.textContent = '.lobby-face a{text-decoration:none!important}'; document.head.append(s); return true; })()`;
const HIDE_TEXT = `(() => { const s = document.createElement('style'); s.textContent = '.lobby-face *{color:transparent!important;-webkit-text-fill-color:transparent!important}'; document.head.append(s); return true; })()`;

// In the page: decode both captures and read the ratio at every glyph pixel and a ring of 1 and 2 px around it.
const MEASURE = (a, b, spans, area) => `(async () => {
  const load = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
  const [ia, ib] = await Promise.all([load(${JSON.stringify(a)}), load(${JSON.stringify(b)})]);
  const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; const g = c.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, img.width, img.height); };
  const A = px(ia), B = px(ib), W = A.width, H = A.height;
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  const L = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  const bgAt = (x, y) => { const i = (y * W + x) * 4; return L(B.data[i], B.data[i + 1], B.data[i + 2]); };
  const RING = []; for (const r of [1, 2]) for (let k = 0; k < 8; k += 1) RING.push([Math.round(r * Math.cos(k * Math.PI / 4)), Math.round(r * Math.sin(k * Math.PI / 4))]);
  const surf = ${JSON.stringify(area)};
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

/**
 * The runs: a viewport, a directory's length, a camera (or, for the un-drawn runs, a mode), and its KIND — which home the
 * boxes must take there (§ 4.1 claim 14), and so which checks apply. The docked viewports are the phones', a sideways
 * phone's, a tablet's, a short window's and the two real laptops' PAGE viewports (a screen less the browser's bars);
 * the board's are the windows tall enough for a 15 em face.
 */
const RUNS = [
  ...[3, 12].map((floors) => ({ kind: 'board', w: 1440, h: 900, floors, camera: 'fit' })),
  { kind: 'board', w: 1440, h: 900, floors: 3, camera: 'zoom' },
  { kind: 'board', w: 1440, h: 780, floors: 3, camera: 'fit' },
  { kind: 'board', w: 1366, h: 768, floors: 3, camera: 'fit' },
  { kind: 'board', w: 1366, h: 768, floors: 12, camera: 'board' },
  ...[[360, 640], [390, 844], [430, 932], [932, 430], [768, 1024], [1100, 700], [1366, 657], [1280, 690]]
    .flatMap(([w, h]) => [3, 12].map((floors) => ({ kind: 'dock', w, h, floors, camera: 'fit' }))),
  ...[[1440, 900], [390, 844]].flatMap(([w, h]) => ['noscript', 'nosnapshot'].map((mode) => ({ kind: 'undrawn', w, h, floors: 0, mode }))),
];

/**
 * THE TRANSITION RUN: loaded at a phone, each later step a resize of the window or a change of the root font size, each
 * with the home it owes. The font size is changed at the desktop window, where a 28 px rem puts the face under the bound —
 * a change the browser fires no `resize` for, so only the entry's `1rem` probe can see it (review F3).
 */
const TRANSITION = [
  { act: 'load', w: 390, h: 844, owed: 'dock' },
  { act: 'resize', w: 1440, h: 900, owed: 'board' },
  { act: 'resize', w: 390, h: 844, owed: 'dock' },
  { act: 'resize', w: 1440, h: 900, owed: 'board' },
  { act: 'font', px: 28, owed: 'dock' },
];

/**
 * ACCEPTED RESIDUALS, each declared by the run it is in, the line it sits on and a band of y, with the measured count as
 * its cap — anything else under the ratio reds. None is declared: the shipped render measured clean.
 */
const RESIDUALS = [];

/**
 * ACCEPTED CUTS, each declared by the run it is in and the element it is. card#12187 emptied the list: the title's
 * one-line cut on the phone's fitted board went with that board, which now docks. Any cut reds.
 */
const CLIP_RESIDUALS = [];

function residualSplit(run, under) {
  const declared = RESIDUALS.filter((res) => res.match(run));
  const unexplained = under.filter((s) => !declared.some((res) => s.text === res.text && s.y >= res.yBand[0] && s.y <= res.yBand[1]));
  const explained = under.length - unexplained.length;

  return { unexplained, ok: unexplained.length === 0 && declared.every((res) => explained <= res.cap) };
}

/** Off the board: neither `none` nor `pan-y`, and the browser's pinch-zoom allowed. */
const offBoardTouch = (v) => v !== 'none' && !v.includes('pan-y') && (v === 'auto' || v === 'manipulation' || v.includes('pinch-zoom'));

/** Every check that applies to a run of its kind, over what the page read. Each verdict is a list of what failed. */
function verdicts(run, r, contrastOk) {
  const near = (x, y) => Math.abs(x - y) <= 1;
  const out = {};
  out.size = r.texts.filter((t) => Math.abs(t.px - 16) > 0.5).map((t) => `${t.text} ${t.px.toFixed(2)}px`);
  out.clip = r.cut.filter((c) => !CLIP_RESIDUALS.some((res) => res.match(run) && c.el === res.el)).map((c) => `${c.el} "${c.text}" ${c.sw}/${c.cw}w ${c.sh}/${c.ch}h`);
  out.contrast = contrastOk ? [] : ['under the ratio'];
  const touched = [['the list', r.touch.list], ...r.touch.panels.map((v, i) => [`readings panel ${i + 1}`, v]), ['the surface', r.touch.surface]];
  if (run.kind === 'board') {
    out.board = [
      ...(r.homes.every((h) => h === 'board') && r.inLayer.every(Boolean) ? [] : [`the boxes are ${r.homes.join('/')} (in the layer: ${r.inLayer.join('/')}), not on the board`]),
      ...(r.expected !== null && near(r.box.l, r.expected.l) && near(r.box.t, r.expected.t) && near(r.box.w, r.expected.w) && near(r.box.h, r.expected.h)
        && near(r.face.l, r.box.l) && near(r.face.t, r.box.t) && near(r.face.w, r.box.w) && near(r.face.h, r.box.h) ? [] : [`box ${JSON.stringify(r.box)} expected ${JSON.stringify(r.expected)}`]),
    ];
    out.scroll = r.list.overflowY === 'auto' && r.list.reached && (run.floors < 12 || r.list.scrollHeight > r.list.clientHeight) ? [] : [`the list does not scroll inside its box (${r.list.overflowY}, ${r.list.scrollHeight}/${r.list.clientHeight})`];
    out.touch = touched.filter(([, v]) => v !== 'none').map(([what, v]) => `${what} is touch-action ${v} on the board, not none`);
  } else {
    out.touch = touched.filter(([, v]) => !offBoardTouch(v)).map(([what, v]) => `${what} is touch-action ${v} off the board${v.includes('pan-y') ? ' — pan-y refuses the page\'s pinch-zoom' : v === 'none' ? ' — none refuses every native touch' : ''}`);
  }
  if (run.kind === 'dock') {
    const [p1, p2] = r.panels;
    out.dock = [
      ...(r.homes.every((h) => h === 'dock') && !r.inLayer.some(Boolean) && r.afterSurface ? [] : [`the boxes are ${r.homes.join('/')} (in the layer: ${r.inLayer.join('/')}, after the surface: ${r.afterSurface}), not docked`]),
      ...(near(r.box.w, r.sectionWidth) ? [] : [`the directory is ${r.box.w.toFixed(1)} px wide, not the section's ${r.sectionWidth}`]),
      ...(p1 !== undefined && p2 !== undefined && p2.t >= p1.b - 1 ? [] : ['the readings\' panels stand side by side, not stacked']),
      ...(r.scaled.some(Boolean) ? [`a docked face is counter-scaled (${r.scaled.join('/')})`] : []),
    ];
    out.flow = [
      ...(r.list.overflowY !== 'visible' && r.list.scrollHeight > r.list.clientHeight + 1 ? [`the docked list is a scroller (${r.list.overflowY}, ${r.list.scrollHeight}/${r.list.clientHeight})`] : []),
      ...(r.list.reachedPage ? [] : ['the page scrolled to its end does not show the last plaque']),
      ...r.panels.filter((p) => !(parseFloat(p.maxHeight) <= 10 * p.line + 16)).map((p) => `a readings panel is not capped at ten lines (max-height ${p.maxHeight}, line ${p.line})`),
    ];
    out.tap = r.links.length === 0 ? ['no plaque link was read'] : r.links.filter((l) => l.w < TAP_PX - 0.5 || l.h < TAP_PX - 0.5).map((l) => `a link ${l.w.toFixed(1)} × ${l.h.toFixed(1)} px`);
  }
  if (run.kind === 'undrawn') {
    out.undrawn = [
      ...(r.homes.every((h) => h === 'flow') && !r.inLayer.some(Boolean) ? [] : [`the boxes are ${r.homes.join('/')} with no room drawn, not flow`]),
      ...(r.ran === (run.mode !== 'noscript') ? [] : [`the module ${r.ran ? 'ran' : 'did not run'} in the ${run.mode} run`]),
      ...(contrastOk ? [] : ['the placeholders\' text is under the ratio on the page\'s ground']),
    ];
  }
  return out;
}

/** Every run, measured; each result carries each check's verdict and `ok`. */
async function judge(chrome, plant) {
  const server = await serve(plant);
  const results = [];
  try {
    for (const run of RUNS) {
      const b = await browser(chrome, run.w, run.h);
      try {
        if (run.mode === 'noscript') await b.page('Emulation.setScriptExecutionDisabled', { value: true });
        await b.load(`http://127.0.0.1:${server.address().port}/index.html?floors=${run.floors}&camera=${run.camera ?? 'fit'}&mode=${run.mode ?? 'drawn'}`);
        await b.evaluate(KILL_DECORATION);
        const r = await b.evaluate(READ);
        const drawn = await b.shot();
        // `--shots <dir>`: each run's capture, saved for a reader to look at — nothing reads it back. Off the board the
        // whole page, so the docked directory below the window is in the picture.
        if (shots !== null) {
          const name = `directory-${run.w}x${run.h}-${run.floors}-${run.camera ?? run.mode}.png`;
          const full = run.kind === 'board' ? drawn : await fullPage(b, run.w);
          writeFileSync(join(shots, name), Buffer.from(full.split(',')[1], 'base64'));
        }
        // The tool's own measuring runs in the page: with the page's script judged as disabled, it is re-enabled now — the
        // module was skipped at parse and does not run again — so the image decodes the measure waits on can fire.
        if (run.mode === 'noscript') await b.page('Emulation.setScriptExecutionDisabled', { value: false });
        await b.evaluate(HIDE_TEXT);
        const bare = await b.shot();
        // Glyphs are read where the text stands: inside the surface while the boxes are on the board, in the window
        // otherwise — so a board run whose boxes docked is still measured, and reds its board check rather than none.
        const m = await b.evaluate(MEASURE(drawn, bare, r.texts, r.homes.every((h) => h === 'board') ? r.surface : r.viewport));
        const { unexplained, ok: contrastOk } = residualSplit(run, m.under);
        const v = verdicts(run, r, contrastOk);
        const measured = run.kind === 'undrawn'
          ? r.texts.length >= 8 && m.glyphs > 500
          : r.texts.length > 20 && m.glyphs > 2000 && r.rows >= 4 * Math.min(run.floors, 3);
        results.push({ run, v, unexplained, measured, glyphs: m.glyphs, min: m.min, texts: r.texts.length, r,
          ok: measured && Object.values(v).every((f) => f.length === 0) });
      } finally {
        b.close();
      }
    }
    results.push(await transition(chrome, server));
  } finally {
    server.close();
  }
  return results;
}

/** The whole page as one capture, the window's width wide. */
async function fullPage(b, w) {
  const h = await b.evaluate('document.documentElement.scrollHeight');
  return `data:image/png;base64,${(await b.page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } })).data}`;
}

/** The transition run: each step's act, then the boxes' homes, the surface's touch-action and the faces' counter-scale. */
async function transition(chrome, server) {
  const run = { kind: 'transition', w: TRANSITION[0].w, h: TRANSITION[0].h, floors: 3, camera: 'fit' };
  const b = await browser(chrome, run.w, run.h);
  const steps = [];
  try {
    const settle = () => b.evaluate('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 80))))');
    const state = () => b.evaluate(`(() => { const ids = ['lobby-directory', 'lobby-readings'].map((id) => document.getElementById(id));
      return { homes: ids.map((e) => e.getAttribute('data-home')), touch: getComputedStyle(document.getElementById('lobby-building')).touchAction,
        scaled: ids.map((e) => getComputedStyle(e.firstElementChild).transform !== 'none') }; })()`);
    for (const step of TRANSITION) {
      if (step.act === 'load') await b.load(`http://127.0.0.1:${server.address().port}/index.html?floors=3&camera=fit&mode=drawn`);
      if (step.act === 'resize') await b.page('Emulation.setDeviceMetricsOverride', { width: step.w, height: step.h, deviceScaleFactor: 1, mobile: false });
      if (step.act === 'font') await b.evaluate(`document.documentElement.style.fontSize = '${step.px}px'`);
      await settle();
      const s = await state();
      const board = step.owed === 'board';
      const wrong = [
        ...(s.homes.every((h) => h === step.owed) ? [] : [`homes ${s.homes.join('/')}`]),
        ...(s.touch === (board ? 'none' : 'auto') ? [] : [`surface touch-action ${s.touch}`]),
        ...(s.scaled.every((x) => x === board) ? [] : [`counter-scale ${s.scaled.join('/')}`]),
      ];
      steps.push({ step, s, wrong });
    }
  } finally {
    b.close();
  }
  const v = { transition: steps.filter((x) => x.wrong.length > 0).map((x) => `after ${x.step.act} ${x.step.w ? `${x.step.w}x${x.step.h}` : `${x.step.px}px`} (owed ${x.step.owed}): ${x.wrong.join(', ')}`) };
  return { run, v, unexplained: [], measured: steps.length === TRANSITION.length, steps, ok: steps.length === TRANSITION.length && v.transition.length === 0 };
}

const report = (x) => {
  if (x.run.kind === 'transition') {
    return `${x.ok ? 'PASS' : 'FAIL'}  transition ${x.steps.map((s) => `${s.step.act}${s.step.w ? ` ${s.step.w}x${s.step.h}` : ` ${s.step.px}px`}→${s.s.homes[0]}/${s.s.touch}`).join(' · ')}${x.v.transition.length ? `  wrong: ${JSON.stringify(x.v.transition)}` : ''}`;
  }
  const failing = Object.entries(x.v).filter(([, f]) => f.length > 0);
  return `${x.ok ? 'PASS' : 'FAIL'}  ${x.run.kind.padEnd(7)} ${x.run.w}x${x.run.h} ${String(x.run.floors).padStart(2)} floors ${(x.run.camera ?? x.run.mode).padEnd(10)}  `
    + `${x.texts} texts, ${x.glyphs} glyph px, min ${x.min?.toFixed(2)}:1; home ${x.r.homes.join('/')}, touch ${x.r.touch.list}/${x.r.touch.panels.join('/')}/${x.r.touch.surface}; `
    + `checks ${Object.keys(x.v).join(' ')}${x.measured ? '' : '  — NOT MEASURED'}`
    + `${failing.length ? `  failing: ${JSON.stringify(Object.fromEntries(failing.map(([k, f]) => [k, f.slice(0, 4)])))}` : ''}`
    + `${x.unexplained.length ? `  under e.g. ${JSON.stringify(x.unexplained.slice(0, 4))}` : ''}`;
};

const selftest = process.argv.includes('--selftest');
const shots = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const chrome = findChrome();

if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const shipped = await judge(chrome, null);
console.log('── the shipped lobby directory — size, board, scroll, dock, flow, touch, tap, undrawn, transition, clip, contrast');
for (const x of shipped) console.log(report(x));

let failed = shipped.some((x) => !x.ok);

if (selftest) {
  // What each control counts as caught: some run, measured, whose verdict for the check lists a failure. Each predicate is
  // also run against the SHIPPED results — a no-op plant — and must NOT catch there.
  const caught = (rs, check) => rs.some((x) => x.measured && (x.v[check] ?? []).length > 0);
  const bites = {
    contrast: (rs) => caught(rs, 'contrast'),
    size: (rs) => caught(rs, 'size'),
    board: (rs) => caught(rs, 'board'),
    scroll: (rs) => caught(rs, 'scroll'),
    dock: (rs) => caught(rs, 'dock'),
    flow: (rs) => caught(rs, 'flow'),
    touch: (rs) => caught(rs, 'touch'),
    tap: (rs) => caught(rs, 'tap'),
    undrawn: (rs) => caught(rs, 'undrawn'),
    transition: (rs) => caught(rs, 'transition'),
    clip: (rs) => caught(rs, 'clip'),
  };

  for (const check of Object.keys(bites)) {
    const vacuous = bites[check](shipped);
    const reached = shipped.some((x) => check in x.v);
    console.log(vacuous ? `CONTROL VACUOUS (${check}) — its predicate already holds on the shipped render` : !reached ? `CONTROL UNREACHED (${check}) — no run applies it` : `CONTROL NOT VACUOUS (${check})`);
    failed = failed || vacuous || !reached;
  }

  for (const [name, plant] of Object.entries(PLANTS)) {
    const check = plant.check ?? name;
    const xs = await judge(chrome, plant);
    console.log(`── CONTROL (${name}): must FAIL its ${check} check`);
    for (const x of xs.filter((y) => (y.v[check] ?? []).length > 0).slice(0, 3)) console.log(report(x));
    const bit = bites[check](xs);
    console.log(bit ? `CONTROL CAUGHT (${name})` : `CONTROL NOT CAUGHT (${name}) — the check cannot tell a correct render from this planted one`);
    failed = failed || !bit;
  }
}

console.log(failed ? 'FAIL' : 'PASS');
process.exit(failed ? 1 : 0);

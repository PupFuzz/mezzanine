#!/usr/bin/env node
// THE BUILDING VIEW, IN A REAL BROWSER — card#13033 (`docs/design/FLOOR.md` § 4.4's `/building`, Appendix B row 47,
// AT-D3-37's browser half; the design is `docs/design/plan-13033-3d-view.md`, whose § 8 item 5 this implements). Node,
// no dependencies: the served `/building` (`render-floor-view.php building`, through the real layout), the shipped
// modules, stylesheet and art, and the `building_stack` fixture run answering every `/api/…` request — the rig
// `served-pages.mjs` shares with `floor-ride.browser.mjs` — in the Playwright-cached headless Chromium.
//
//   node tools/design/building-view.browser.mjs              every row, each once
//   node tools/design/building-view.browser.mjs --row <id>   one row (repeat it for several: `--row still --row no-webgl
//                                                            --selftest` is what CI runs)
//   node tools/design/building-view.browser.mjs --selftest   every row, then every row again under each of its PLANTs,
//                                                            each of which must red
//   node tools/design/building-view.browser.mjs --out <png>  also save the overview, as drawn, for a person to look at
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// ⛔ EVERY PRECONDITION A ROW NEEDS IS DERIVED, AND ITS ABSENCE IS A CONTROL FAILURE (plan § 8's rule): the composed
// floors from the run's own answers; a point over a floor from the page's own stack, re-derived under node from the
// page's floors, extents and stage by the shipped `building/stack-model.js` and checked against where the page itself
// docked each floor's label; the stage on which the cull culls by narrowing the page until labels contend; and the point
// a stream of wheel events must stand over for the mute to matter, searched over viewports and the stage's grid.
//
// ⛔ A PLANT EDITS WHAT IS SERVED — a module, an art module or the launch — never a copy kept here.
//
// ⚠ WHAT A PASS IS NOT (plan § 8 item 5): that a real GPU, Safari or Firefox rasterise the SVG as SwiftShader does,
// that a pinch on a real touch screen snaps, which `deltaMode` each browser's wheel sends, or how the snap feels under a
// real trackpad. The wheel events here are the protocol's and synthetic ones; the rows' timings are a loopback host's.
// The per-floor paint and rasterise times plan § 8 item 5 asks it to print are NOT measured here.

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';
import { fixtureRun, serve } from './served-pages.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const JS = join(REPO, 'server', 'public', 'js');
const M = await import(pathToFileURL(join(JS, 'building', 'stack-model.js')).href);

const RUN = 'building_stack';
const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** The words the page writes, read from the modules that own them. */
const NO_WEBGL = /^export const NO_WEBGL = '([^']+)';$/m.exec(readFileSync(join(JS, 'building', 'building-screen.js'), 'utf8'))[1];
const ART_FAILED = /^export const ART_FAILED = '([^']+)';$/m.exec(readFileSync(join(JS, 'floor', 'status-strip.js'), 'utf8'))[1];
const DRAG_SLOP_PX = Number(/^export const DRAG_SLOP_PX = (\d+);$/m.exec(readFileSync(join(JS, 'wire', 'camera-gestures.js'), 'utf8'))[1]);
/** The stream: a small pixel-mode wheel step every 16 ms of wall-clock time, run until the view has rested this long. */
const WHEEL_STEP_PX = 8;
const WHEEL_PACE_MS = 16;
const STREAM_REST_MS = 1500;
/** A floor's picture lags its slab by up to this (the seat's ruling of 2026-11-10): a settled page has been still longer. */
const PICTURE_MIN_INTERVAL_MS = Number(/^export const PICTURE_MIN_INTERVAL_MS = (\d+);$/m.exec(readFileSync(join(JS, 'building', 'floor-picture.js'), 'utf8'))[1]);
const GESTURE_GAP_MS = Number(/^export const GESTURE_GAP_MS = (\d+);$/m.exec(readFileSync(join(JS, 'building', 'building-acts.js'), 'utf8'))[1]);

/** The floors the run composes: those whose room's map request answers 200 — a picture each. */
const COMPOSED = (() => {
  const run = fixtureRun(RUN);
  const floors = run.http['/api/building'][0].body.layout.floors;
  return floors.filter((f) => f.rooms.some((r) => (run.http[`/api/building/rooms/${r.install}/map`] ?? []).some((a) => a.status === 200))).map((f) => f.floor);
})();

/** The floor the run draws in the cottage theme — its band made to throw, so the failure is the painter's to report. */
const THROWING_THEME = { file: 'art:floor/themes/cottage/theme.js', from: 'export function band({ w, h, zone, elevator, clock, windows }) {\n', to: "export function band({ w, h, zone, elevator, clock, windows }) {\n    throw new Error('the band, planted to fail');\n" };

const REDUCE = [{ name: 'prefers-reduced-motion', value: 'reduce' }];

/** A browser on the row's server at `/building`, settled: the stack drawn (or the § 7 statement), the run's stream spent. */
async function open(chrome, c, viewport = { w: 1280, h: 800 }) {
  const srv = await serve({ run: RUN, ...c, plant: [THROWING_THEME, ...(c.plant ?? [])] });
  const b = await browser(chrome, viewport.w, viewport.h, 'never', { media: c.media ?? REDUCE, args: c.args ?? [] });
  const thrown = [];
  b.on('Runtime.exceptionThrown', (p) => thrown.push(p.exceptionDetails.exception?.description ?? p.exceptionDetails.text));
  await b.page('Runtime.enable');
  await b.page('Page.navigate', { url: `${srv.base}/building` });
  const end = Date.now() + 20000;
  for (;;) {
    const ready = await b.evaluate("document.readyState === 'complete' && (document.getElementById('building-stage')?.dataset.view !== undefined || document.getElementById('building-webgl')?.hidden === false) && performance.now() > 4000").catch(() => false);
    if (ready) break;
    if (Date.now() > end) break;
    await sleep(50);
  }
  // Settled: the pictures unchanged for longer than a floor's repaint interval — the run's `room.map` re-draws a floor
  // after its stream is spent, and its picture follows its slab by up to that interval.
  const signature = "JSON.stringify([...document.querySelectorAll('#building-pictures canvas[data-floor]')].map((c) => [c.dataset.floor, c.width, c.height, c.hidden]))";
  let last = null;
  for (let since = Date.now(); Date.now() < end + PICTURE_MIN_INTERVAL_MS && Date.now() - since < PICTURE_MIN_INTERVAL_MS + 500; await sleep(100)) {
    const now = await b.evaluate(signature).catch(() => null);
    if (now !== last) { last = now; since = Date.now(); }
  }
  await b.evaluate('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))');
  return { b, srv, thrown, ev: b.evaluate, close: () => { b.close(); srv.close(); } };
}

/** Everything a row reads off the page at rest. */
const READ = `(() => {
  const stage = document.getElementById('building-stage');
  const canvas = document.getElementById('building-canvas');
  const rect = (n) => { const r = n.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; };
  return {
    view: stage.dataset.view ?? null,
    gliding: stage.dataset.gliding ?? null,
    phase: stage.dataset.phase ?? null,
    drawing: !document.getElementById('building-drawing').hidden,
    listDrawn: !document.getElementById('building-floors').classList.contains('visually-hidden'),
    stage: rect(stage),
    surface: { width: stage.clientWidth, height: stage.clientHeight },
    statement: document.getElementById('building-webgl').hidden ? null : document.getElementById('building-webgl').textContent,
    canvasHidden: canvas.hidden,
    rowHidden: document.getElementById('building-camera-row').hidden,
    art: document.getElementById('building-art').hidden ? '' : document.getElementById('building-art').textContent,
    status: document.getElementById('building-view-status').textContent,
    read: { hidden: document.getElementById('building-read-floor').hidden, href: document.getElementById('building-read-floor').getAttribute('href') },
    floors: [...document.querySelectorAll('#building-floors > li.building-floor')].map((li, level) => ({
      floor: li.dataset.floor, level, name: li.querySelector('.building-floor-head > a').textContent,
      home: li.querySelector('.building-floor-head').dataset.home ?? null, transform: li.querySelector('.building-floor-head').style.transform,
      head: rect(li.querySelector('.building-floor-head')),
      notices: (() => { const n = li.querySelector('.building-notices'); return !n.hidden && !n.classList.contains('visually-hidden'); })(),
      culled: li.querySelector('.building-floor-head > a').classList.contains('visually-hidden'),
      desks: [...li.querySelectorAll('a.building-desk')].map((a) => ({ key: a.dataset.desk, home: a.dataset.home ?? null, culled: a.classList.contains('visually-hidden'),
        hidden: a.hidden, display: getComputedStyle(a).display, rect: rect(a) })),
    })),
    scenes: [...document.querySelectorAll('#building-pictures .floor-scene')].map((svg) => ({ floor: svg.parentElement.dataset.floorHost,
      names: svg.querySelectorAll('text.role-name').length, plates: svg.querySelectorAll('.plate').length })),
    pictures: [...document.querySelectorAll('#building-pictures canvas[data-floor]:not([hidden])')].map((c) => ({ floor: c.dataset.floor, w: c.width, h: c.height })),
    contexts: (() => { if (canvas.hidden) return null; return { gl2: canvas.getContext('webgl2') !== null, twoD: canvas.getContext('2d') !== null }; })(),
    active: document.activeElement?.dataset?.desk ?? document.activeElement?.id ?? document.activeElement?.tagName ?? null,
  };
})()`;

/** The page's stack re-derived under node from its floors, the pictures' extents and its stage — checked against the page's own docking. */
function stackOf(r) {
  const rows = r.floors.filter((f) => f.floor !== '').map((f) => ({ floor: f.floor, name: f.name, href: `/floor/${f.floor}`, level: f.level }));
  const extents = new Map(rows.map((row) => { const p = r.pictures.find((q) => q.floor === row.floor); return [row.floor, p === undefined ? null : { x: 0, y: 0, w: p.w, h: p.h }]; }));
  return M.stackModel(rows, extents, '/dashboard', r.surface);
}

/**
 * Where the page's stack is under node is where the page drew it: each floor entry's bottom edge level with its slab's
 * corner within a pixel, where the entry is not held inside the stage's top.
 */
function stackDefects(r, stack) {
  const d = [];
  if (stack === null) return ['CONTROL — the page\'s floors and stage re-derive no stack under node'];
  const camera = M.overviewPose(stack, r.surface, 0);
  for (const f of r.floors.filter((x) => x.floor !== '')) {
    const p = M.projectPoint(camera, r.surface, M.labelCorner(stack.slabs.find((s) => s.floor === f.floor), 0));
    const bottom = f.head.y - r.stage.y + f.head.h;
    if (f.head.y - r.stage.y > 0.5 && Math.abs(bottom - p.y) > 1) d.push(`CONTROL — ${f.floor}'s entry stands with its bottom at ${bottom.toFixed(1)}, not at the node stack's corner ${p.y.toFixed(1)}: the re-derived stack is not the page's`);
  }
  return d;
}

/** No floor's notices drawn over a slab of the node stack (card#13033's polish), and at least one floor's drawn to read. */
function noticeDefects(r, stack) {
  if (stack === null) return [];
  const camera = M.overviewPose(stack, r.surface, 0);
  const drawn = r.floors.filter((f) => f.notices);
  if (drawn.length === 0) return ['CONTROL — no floor\'s notices are drawn at the overview, so the overlap read nothing'];
  const d = [];
  for (const f of drawn) {
    const box = { x: f.head.x - r.stage.x, y: f.head.y - r.stage.y, w: f.head.w, h: f.head.h };
    for (const slab of [...stack.slabs, stack.ground]) {
      const rect = M.slabRect(slab, camera, r.surface);
      if (rect !== null && intersect(box, rect)) d.push(`${f.floor}'s notices are drawn over ${slab.floor ?? 'the ground'}'s slab`);
    }
  }
  return d;
}

/** A stage point over `floor`'s top face at `view` — its face's centre projected. */
function over(stack, surface, view, floor) {
  const slab = stack.slabs.find((s) => s.floor === floor);
  const p = M.projectPoint(M.poseCamera(view, stack, surface), surface, { x: slab.box.x + slab.box.w / 2, y: slab.box.y + slab.box.h, z: slab.box.z + slab.box.d / 2 });
  return { x: p.x, y: p.y };
}

/** One wheel notch in over a stage point, through the protocol (a trusted event). */
async function wheelIn(s, r, p) {
  await s.b.page('Input.dispatchMouseEvent', { type: 'mouseWheel', x: r.stage.x + p.x, y: r.stage.y + p.y, deltaX: 0, deltaY: -120 });
  await s.ev('new Promise((r) => setTimeout(() => requestAnimationFrame(() => r(true)), 200))');
}

const key = async (s, k, code, vk, modifiers = 0) => {
  await s.b.page('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: k, code, windowsVirtualKeyCode: vk, modifiers });
  await s.b.page('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk, modifiers });
  await s.ev('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))');
};

const intersect = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/** The drawn desk labels' boxes: no two intersect, each inside the stage. */
function boxDefects(r, where) {
  const d = [];
  const drawn = r.floors.flatMap((f) => f.desks.filter((k) => !k.culled));
  drawn.forEach((a, i) => drawn.slice(i + 1).forEach((b) => { if (intersect(a.rect, b.rect)) d.push(`${where}: the drawn labels ${a.key} and ${b.key} intersect`); }));
  for (const a of drawn) {
    const s = r.stage;
    if (a.rect.x < s.x - 0.5 || a.rect.y < s.y - 0.5 || a.rect.x + a.rect.w > s.x + s.w + 0.5 || a.rect.y + a.rect.h > s.y + s.h + 0.5) d.push(`${where}: the drawn label ${a.key} is not inside the stage`);
  }
  return d;
}

const row = (what, plants, run, base = {}) => ({ what, plants, run, base });
const PICTURE = 'js/building/floor-picture.js';
const LABELS = 'js/building/labels.js';
const MAIN = 'js/building/main.js';
const MODEL = 'js/building/stack-model.js';

const ROWS = {
  still: row('the page starts and draws: no exception, the stream asked for, a WebGL 2 canvas, one nameplate-free picture per composed floor in the page\'s own pixels, the list docked, the desk labels culled at the overview, no floor\'s notices over a slab, the placeholder gone, and the painter\'s failure on the strip', [
    ['the token inlining dropped', { plant: [{ file: PICTURE, from: ' style="${attribute(style)}"', to: '' }] }],
    ['the nameplate painted twice', { plant: [{ file: PICTURE, from: "                    omit: new Set(['nameplate']),\n", to: '' }] }],
    ['a floor dropped from the pictures', { plant: [{ file: PICTURE, from: '            for (const { row, scene } of drawn) {', to: '            for (const { row, scene } of drawn.slice(1)) {' }] }],
    ['WebGL disabled', { args: ['--disable-3d-apis'] }],
    ['the painter\'s failure routed nowhere', { plant: [{ file: PICTURE, from: '                    failed: art.failed,\n', to: '                    failed: () => {},\n' }] }],
    ['the notices centred over the slab\'s corner', { plant: [{ file: MODEL, from: '        const x = left ? Math.min(anchor.x, ...rows.map((r) => r.x)) - ENTRY_GAP - w : Math.max(anchor.x, ...rows.map((r) => r.x + r.w)) + ENTRY_GAP;', to: '        const x = anchor.x - w / 2;' }] }],
    ['the placeholder left standing', { plant: [{ file: MAIN, from: "    el('building-drawing').hidden = phase !== 'drawing';", to: "    el('building-drawing').hidden = false;" }] }],
  ], async (chrome, c) => {
    const s = await open(chrome, c);
    try {
      const d = [];
      const r = await s.ev(READ);
      const errors = [...s.thrown, ...((await s.ev('window.__ride?.errors ?? []')))];
      if (errors.length > 0) d.push(`an uncaught exception: ${errors[0]}`);
      if ((await s.ev('window.__ride?.streams?.length ?? 0')) === 0) d.push('the page never asked for its stream');
      if (r.contexts === null || !r.contexts.gl2) d.push('#building-canvas holds no WebGL 2 context');
      else if (r.contexts.twoD) d.push('#building-canvas answers a 2-D context — it holds no WebGL one');
      const scenes = r.scenes.map((x) => x.floor).sort();
      if (JSON.stringify(scenes) !== JSON.stringify([...COMPOSED].sort())) d.push(`the pictures' painted floors are ${JSON.stringify(scenes)}, not the composed ${JSON.stringify(COMPOSED)}`);
      for (const x of r.scenes) if (x.names > 0 || x.plates > 0) d.push(`${x.floor}'s picture carries ${x.names} nameplate text(s) and ${x.plates} plate(s) — the name is the label's`);
      const pics = r.pictures.map((x) => x.floor).sort();
      if (JSON.stringify(pics) !== JSON.stringify([...COMPOSED].sort())) d.push(`the canvases kept are ${JSON.stringify(pics)}, not one per composed floor`);
      // The page's own pixels: a chip rect of the top floor, read in the canvas the page keeps as its texture.
      const chip = await s.ev(`(() => {
        const svg = document.querySelector('#building-pictures [data-floor-host="${COMPOSED[0]}"] svg');
        const canvas = document.querySelector('#building-pictures canvas[data-floor="${COMPOSED[0]}"]');
        const rect = svg?.querySelector('rect.chip');
        if (!rect || !canvas) return null;
        const [vx, vy, vw, vh] = svg.getAttribute('viewBox').split(' ').map(Number);
        const k = Math.min(canvas.width / vw, canvas.height / vh);
        const ox = (canvas.width - vw * k) / 2, oy = (canvas.height - vh * k) / 2;
        const x = Math.round(ox + (Number(rect.getAttribute('x')) + Number(rect.getAttribute('width')) / 2 - vx) * k);
        const y = Math.round(oy + (Number(rect.getAttribute('y')) + Number(rect.getAttribute('height')) / 2 - vy) * k);
        return [...canvas.getContext('2d').getImageData(x, y, 1, 1).data];
      })()`);
      if (chip === null) d.push(`CONTROL — ${COMPOSED[0]}'s picture has no chip rect to read`);
      else if (chip[3] < 250 || (chip[0] < 24 && chip[1] < 24 && chip[2] < 24)) d.push(`${COMPOSED[0]}'s chip reads ${JSON.stringify(chip)} in the page's canvas — the palette did not reach the picture`);
      for (const f of r.floors) {
        if (f.home !== 'stage') d.push(`${f.floor || 'the ground'}'s entry is data-home=${f.home}, not stage`);
        if (f.culled) d.push(`${f.floor || 'the ground'}'s entry is culled at the overview`);
        for (const k of f.desks) {
          if (k.home !== 'stage') d.push(`${k.key} is data-home=${k.home}, not stage`);
          if (!k.culled) d.push(`${k.key} is drawn at the overview`);
          if (k.display === 'none' || k.hidden) d.push(`${k.key} is out of the accessibility tree (display ${k.display}, hidden ${k.hidden})`);
        }
      }
      if (r.floors.flatMap((f) => f.desks).length === 0) d.push('CONTROL — the page lists no desk label to read');
      if (r.art !== ART_FAILED) d.push(`the strip's art line reads ${JSON.stringify(r.art)}, not ${JSON.stringify(ART_FAILED)} — the theme's thrown band reached no strip`);
      d.push(...stackDefects(r, stackOf(r)), ...noticeDefects(r, stackOf(r)));
      if (r.phase !== 'stage' || r.drawing || !r.listDrawn) d.push(`the drawn stage is in phase ${r.phase}, the placeholder ${r.drawing ? 'shown' : 'hidden'}, the list ${r.listDrawn ? 'drawn' : 'visually hidden'}`);
      if (argOf('--out') !== null && c.plant.length === 0 && c.args.length === 0) writeFileSync(argOf('--out'), Buffer.from((await s.b.page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
      return { defects: d, facts: { view: r.view, pictures: r.pictures, art: r.art, chip } };
    } finally { s.close(); }
  }),

  first: row('before the first stack the stage shows the placeholder and never the bare list, the list in the accessibility tree throughout, until the stack stands', [
    ['the bare list before the first frame', { plant: [{ file: 'js/building/building-screen.js', from: "    return frame === null || frame.layout === 'pending' || !art ? 'drawing' : 'list';", to: "    return 'list';" }] }],
  ], async (chrome, c) => {
    const srv = await serve({ run: RUN, ...c, plant: [THROWING_THEME, ...(c.plant ?? [])] });
    const b = await browser(chrome, 1280, 800, 'never', { media: c.media ?? REDUCE, args: c.args ?? [] });
    try {
      await b.page('Runtime.enable');
      await b.page('Page.navigate', { url: `${srv.base}/building` });
      // Sampled from the navigation until the stack stands: the page's own state at each sample, never a later reading.
      const SAMPLE = "(() => { const s = document.getElementById('building-stage'); if (s === null) return null; const l = document.getElementById('building-floors'); return { phase: s.dataset.phase ?? null, drawing: !document.getElementById('building-drawing').hidden, listDrawn: !l.classList.contains('visually-hidden'), listed: l.isConnected && getComputedStyle(l).display !== 'none' }; })()";
      const samples = [];
      for (const end = Date.now() + 20000; Date.now() < end; await sleep(20)) {
        const x = await b.evaluate(SAMPLE).catch(() => null);
        if (x === null) continue;
        samples.push(x);
        if (x.phase === 'stage') break;
      }
      const d = [];
      const before = samples.filter((x) => x.phase !== 'stage');
      if (samples.at(-1)?.phase !== 'stage') d.push(`the stack never stood: the last sample reads ${JSON.stringify(samples.at(-1) ?? null)}`);
      if (!before.some((x) => x.drawing)) d.push('CONTROL — no sample before the stack stood saw the placeholder, so the row read nothing');
      const bare = before.find((x) => x.listDrawn || !x.drawing);
      if (bare !== undefined) d.push(`before the stack stood the stage showed ${JSON.stringify(bare)} — the bare list, or no placeholder`);
      if (samples.some((x) => !x.listed)) d.push('the list left the accessibility tree (display none) before the stack stood');
      return { defects: d, facts: { samples: samples.length, before: before.length, phases: [...new Set(samples.map((x) => x.phase))] } };
    } finally { b.close(); srv.close(); }
  }),

  'no-webgl': row('without WebGL 2 the list is the page: § 7\'s statement, no canvas, no camera row, no picture, the list in flow', [
    ['the WebGL check inverted', { plant: [{ file: MAIN, from: "if (document.createElement('canvas').getContext('webgl2') === null) {", to: "if (document.createElement('canvas').getContext('webgl2') !== null) {" }] }],
    ['the list left hidden without WebGL 2', { plant: [{ file: MAIN, from: "    say('building-webgl', NO_WEBGL);\n    showPhase();\n", to: "    say('building-webgl', NO_WEBGL);\n" }] }],
  ], async (chrome, c) => {
    const s = await open(chrome, c);
    try {
      const d = [];
      const r = await s.ev(READ);
      if (r.statement !== NO_WEBGL) d.push(`the § 7 statement reads ${JSON.stringify(r.statement)}`);
      if (!r.canvasHidden) d.push('the canvas is shown');
      if (!r.rowHidden) d.push('the camera row is shown');
      if (r.scenes.length > 0) d.push(`${r.scenes.length} picture(s) were painted`);
      if (r.floors.length === 0) d.push('the list holds no floor');
      if (r.phase !== 'list' || r.drawing || !r.listDrawn) d.push(`without WebGL 2 the stage is in phase ${r.phase}, the placeholder ${r.drawing ? 'shown' : 'hidden'}, the list ${r.listDrawn ? 'drawn' : 'visually hidden'}`);
      for (const f of r.floors) if (f.home === 'stage') d.push(`${f.floor || 'the ground'}'s entry is docked on a stage that is not drawn`);
      return { defects: d, facts: { statement: r.statement } };
    } finally { s.close(); }
  }, { args: ['--disable-3d-apis'] }),

  tab: row('a culled label is in the tree and the tab order, and the keyboard\'s focus on another floor\'s label brings that floor into view, the label drawn', [
    ['a culled label given hidden', { plant: [{ file: LABELS, from: "                node.classList.toggle('visually-hidden', !shown.has(key));", to: "                node.hidden = !shown.has(key);" }] }],
    ['the focus rule dropped', { plant: [{ file: LABELS, from: '                report.focused(at);', to: '' }] }],
  ], async (chrome, c) => {
    const s = await open(chrome, c);
    try {
      const d = [];
      const before = await s.ev(READ);
      const target = before.floors.find((f) => f.desks.length > 0);
      if (target === undefined) return { defects: ['CONTROL — no floor lists a desk label'], facts: {} };
      // From after the list, backwards: the keyboard passes the floors below it, then lands on its last desk label.
      await s.ev("document.querySelector('#building details > summary').focus()");
      let landed = null;
      const path = [];
      for (let i = 0; i < 12 && landed === null; i++) {
        await key(s, 'Tab', 'Tab', 9, 8);
        await s.ev('new Promise((r) => setTimeout(r, 100))');
        const r = await s.ev(READ);
        path.push(r.active);
        if (target.desks.some((k) => k.key === r.active)) landed = r;
      }
      if (landed === null) return { defects: [`the keyboard never reached ${target.floor}'s desk labels: ${JSON.stringify(path)}`], facts: { path } };
      const label = landed.floors.find((f) => f.floor === target.floor).desks.find((k) => k.key === landed.active);
      if (landed.view !== `focus:${target.floor}`) d.push(`focus on ${label.key} left the view at ${landed.view}, not ${target.floor}`);
      if (label.culled || label.display === 'none') d.push(`${label.key}, focused, is not drawn (culled ${label.culled}, display ${label.display})`);
      return { defects: d, facts: { path, view: landed.view } };
    } finally { s.close(); }
  }),

  press: row('a mouse press on a floor\'s label that drags turns the building and snaps to nothing', [
    ['the :focus-visible predicate dropped', { plant: [{ file: LABELS, from: "        if (event.target.matches(':focus-visible')) {", to: '        if (true) {' }] }],
  ], async (chrome, c) => {
    const s = await open(chrome, c);
    try {
      const d = [];
      const r = await s.ev(READ);
      const f = r.floors.find((x) => x.floor !== '');
      const at = await s.ev(`(() => { const r = document.querySelector('[data-floor="${f.floor}"] .building-floor-head > a').getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
      const mouse = (type, x, y) => s.b.page('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons: type === 'mouseReleased' ? 0 : 1, clickCount: 1 });
      await mouse('mousePressed', at.x, at.y);
      for (let dx = 4; dx <= DRAG_SLOP_PX * 6; dx += 4) await mouse('mouseMoved', at.x + dx, at.y);
      await mouse('mouseReleased', at.x + DRAG_SLOP_PX * 6, at.y);
      await s.ev('new Promise((r) => setTimeout(() => requestAnimationFrame(() => r(true)), 200))');
      const after = await s.ev(READ);
      if (after.view !== 'overview') d.push(`a press and drag on ${f.floor}'s label moved the view to ${after.view}`);
      if (after.floors.find((x) => x.floor === f.floor).transform === f.transform) d.push('the drag did not turn the building — the floor labels did not move');
      return { defects: d, facts: { view: after.view } };
    } finally { s.close(); }
  }),

  boxes: row('the label boxes: every name drawn where the pose is legible, and on a narrowed stage the names that fit, the link to the floor page, and a culled name drawn when the keyboard reaches it', [
    ['the cull keeping every label', { plant: [{ file: MODEL, from: '        if (kept.some((key) => overlaps(boxes.get(key), box))) {', to: '        if (false) {' }] }],
    ['the link\'s predicate inverted', { plant: [{ file: MODEL, from: 'return slab === null || culled.size === 0 ?', to: 'return slab === null || culled.size !== 0 ?' }] }],
  ], async (chrome, c) => {
    const s = await open(chrome, c);
    try {
      const d = [];
      let r = await s.ev(READ);
      const stack = stackOf(r);
      d.push(...stackDefects(r, stack));
      const floor = r.floors.find((f) => f.desks.length > 0)?.floor;
      if (floor === undefined || stack === null) return { defects: [...d, 'CONTROL — no floor with desks, or no stack'], facts: {} };
      await wheelIn(s, r, over(stack, r.surface, { pose: 'overview', azimuth: 0 }, floor));
      r = await s.ev(READ);
      const desks = () => r.floors.find((f) => f.floor === floor).desks;
      if (r.view !== `focus:${floor}`) d.push(`a wheel in over ${floor} left the view at ${r.view}`);
      if (desks().some((k) => k.culled)) d.push(`at ${r.surface.width}×${r.surface.height} a name of ${floor} is culled: ${desks().filter((k) => k.culled).map((k) => k.key).join(', ')}`);
      if (!r.read.hidden) d.push(`the read link is shown at ${r.surface.width}×${r.surface.height}, where every name is drawn`);
      d.push(...boxDefects(r, 'the wide stage'));
      // Narrow the window until the names contend — some culled, or (a defect) some drawn over each other.
      let derived = null;
      for (let w = 1240; w >= 280 && derived === null; w -= 40) {
        await s.b.page('Emulation.setDeviceMetricsOverride', { width: w, height: 800, deviceScaleFactor: 1, mobile: false });
        await s.ev('new Promise((r) => setTimeout(() => requestAnimationFrame(() => r(true)), 150))');
        r = await s.ev(READ);
        if (desks().some((k) => k.culled) || boxDefects(r, '').some((x) => x.includes('intersect'))) derived = w;
      }
      if (derived === null) return { defects: [...d, 'CONTROL — no window from 1240 px down to 280 px makes the names contend'], facts: {} };
      if (!desks().some((k) => k.culled)) d.push(`on the ${derived}-px window the names contend and none is culled`);
      if (r.read.hidden || r.read.href !== `/floor/${floor}`) d.push(`on the ${derived}-px window the read link is ${JSON.stringify(r.read)}`);
      d.push(...boxDefects(r, `the ${derived}-px window`));
      const culled = desks().find((k) => k.culled);
      if (culled !== undefined) {
        await s.ev(`document.querySelector('[data-floor="${floor}"] .building-floor-head > a').focus()`);
        for (let i = 0; i < 8 && r.active !== culled.key; i++) { await key(s, 'Tab', 'Tab', 9); r = await s.ev(READ); }
        const now = desks().find((k) => k.key === culled.key);
        if (r.active !== culled.key) d.push(`the keyboard never reached the culled ${culled.key}`);
        else if (now.culled) d.push(`${culled.key}, focused, stays culled — focus rests on an invisible link`);
        d.push(...boxDefects(r, `the ${derived}-px window, ${culled.key} focused`));
      }
      return { defects: d, facts: { derived } };
    } finally { s.close(); }
  }),

  stream: row('a stream of wheel events at a device\'s fixed pace, with motion on, glides once, arrives once, and snaps no more however long it outlives the glide', [
    ['the page\'s show ungated', { plant: [{ file: MAIN, from: '    if (view.gliding() || live.stack === null) {\n        return;\n    }\n\n    const from = resting();', to: '    if (live.stack === null) {\n        return;\n    }\n\n    const from = resting();' }] }],
    ['the acts\' mute dropped', { plant: [{ file: 'js/building/building-acts.js', from: '        muted = true;\n\n        return snapped(', to: '        return snapped(' }] }],
  ], async (chrome, c) => {
    // The point the stream stands over: one that is over a floor at the overview and over ANOTHER floor at that floor's
    // pose, so a second snap from the destination is possible — searched over windows and the stage's grid.
    let found = null;
    for (const viewport of [{ w: 400, h: 900 }, { w: 320, h: 900 }, { w: 1280, h: 800 }]) {
      const s = await open(chrome, { ...c, media: [] }, viewport);
      const r = await s.ev(READ);
      const stack = stackOf(r);
      const control = stackDefects(r, stack);
      if (control.length === 0) {
        const home = M.overviewPose(stack, r.surface, 0);
        for (let x = 2; x < r.surface.width && found === null; x += 4) {
          for (let y = 2; y < r.surface.height && found === null; y += 4) {
            const a = M.aim(M.rayThrough(home, r.surface, { x, y }), [...stack.slabs, stack.ground]);
            if (a === null || a.slab.floor === null) continue;
            const pose = M.focusPose(stack, stack.slabs.indexOf(a.slab), r.surface);
            const b = M.aim(M.rayThrough(pose, r.surface, { x, y }), [...stack.slabs, stack.ground]);
            if (b !== null && b.slab.floor !== null && b.slab.floor !== a.slab.floor) found = { s, r, p: { x, y }, floor: a.slab.floor, other: b.slab.floor, viewport };
          }
        }
      }
      if (found === null) s.close();
      else break;
    }
    if (found === null) return { defects: ['CONTROL — no window and no stage point is over one floor at the overview and over another at that floor\'s pose'], facts: {} };
    const { s, r, p, floor } = found;
    try {
      const d = [];
      // The page samples itself on every animation frame — passively: it dispatches nothing — while the wheel events come
      // from OUTSIDE it, through the protocol, at a fixed wall-clock pace with their own timestamps, as a device delivers
      // them. A long task on the page stalls its frames and its handlers, never this pace (the seat's ruling of 2026-11-10).
      await s.ev(`(() => {
        const stage = document.getElementById('building-stage');
        const status = document.getElementById('building-view-status');
        const head = () => document.querySelector('#building-floors .building-floor-head')?.style.transform ?? '';
        const S = window.__stream = { frames: [], writes: 0, stop: false, wheels: [] };
        // Each wheel the stage receives: its own time, when it was handled, its delta — what a red below is read against.
        stage.addEventListener('wheel', (e) => { S.wheels.push([Math.round(e.timeStamp), Math.round(performance.now()), e.deltaY]); }, { capture: true });
        new MutationObserver(() => { S.writes++; }).observe(status, { childList: true, characterData: true, subtree: true });
        const step = () => {
          S.frames.push({ t: Math.round(performance.now()), gliding: stage.dataset.gliding, view: stage.dataset.view, head: head() });
          if (!S.stop) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
        return true;
      })()`);
      const at = { x: r.stage.x + p.x, y: r.stage.y + p.y };
      let sent = 0;
      const inFlight = [];
      const pace = setInterval(() => {
        sent++;
        inFlight.push(s.b.page('Input.dispatchMouseEvent', { type: 'mouseWheel', x: at.x, y: at.y, deltaX: 0, deltaY: -WHEEL_STEP_PX, timestamp: Date.now() / 1000 }).catch(() => {}));
      }, WHEEL_PACE_MS);
      // Until the glide has been over for a quiet stretch of wall-clock time far past the gesture gap, or a cap.
      let glided = false;
      let restingSince = null;
      for (const end = Date.now() + 20000; Date.now() < end;) {
        await sleep(100);
        const state = await s.ev("document.getElementById('building-stage').dataset.gliding");
        glided ||= state === 'true';
        restingSince = glided && state === 'false' ? restingSince ?? Date.now() : null;
        if (restingSince !== null && Date.now() - restingSince >= STREAM_REST_MS) break;
      }
      clearInterval(pace);
      // Every event sent has been handled — a busy page queues them, and a queued wheel is coalesced with the next, so
      // the wheel out below must not be summed with the stream's tail.
      await Promise.all(inFlight);
      await sleep(GESTURE_GAP_MS + 100);
      const sample = await s.ev('(window.__stream.stop = true, { frames: window.__stream.frames, writes: window.__stream.writes, status: document.getElementById(\'building-view-status\').textContent })');
      sample.sent = sent;
      const views = sample.frames.map((f) => f.view).filter((v, i, a) => i === 0 || v !== a[i - 1]);
      const changes = views.length - 1;
      const gliding = sample.frames.map((f, i) => [f, sample.frames[i + 1]]).filter(([a, b]) => b !== undefined && a.gliding === 'true' && b.gliding === 'true');
      if (gliding.length === 0) d.push('data-gliding never read true on two frames running');
      else if (!gliding.some(([a, b]) => a.head !== b.head)) d.push('the labels did not move between two gliding frames');
      if (changes !== 1 || views[0] !== 'overview' || views[1] !== `focus:${floor}`) d.push(`the stream changed the view ${changes} time(s): ${JSON.stringify(views)}`);
      if (sample.writes !== 1 || !sample.status.includes(r.floors.find((f) => f.floor === floor).name)) d.push(`the status line was written ${sample.writes} time(s), last ${JSON.stringify(sample.status)}`);
      const after = await s.ev(READ);
      for (const f of after.floors) for (const k of f.desks) if ((f.floor === floor) === k.culled) d.push(`${k.key} is ${k.culled ? 'culled' : 'drawn'} at ${floor}'s pose`);
      // A wheel out, once the gesture has gone quiet: back to the overview, every name culled.
      await sleep(GESTURE_GAP_MS + 100);
      // Stamped as the stream's events were, so the two are times on one clock.
      await s.b.page('Input.dispatchMouseEvent', { type: 'mouseWheel', x: r.stage.x + p.x, y: r.stage.y + p.y, deltaX: 0, deltaY: 120, timestamp: Date.now() / 1000 });
      // Until the page has HANDLED the wheel out — a busy page handles its input late, so the view is read only once the
      // event has landed — and its glide has arrived; or a cap, past which a glide that never arrives is read below as the
      // defect it is.
      await s.ev("new Promise((r) => { const cap = performance.now() + 15000; const S = window.__stream; const t = () => (S.wheels.some((w) => w[2] > 0) && document.getElementById('building-stage').dataset.gliding === 'false') || performance.now() > cap ? setTimeout(() => r(true), 50) : requestAnimationFrame(t); setTimeout(t, 50); })");
      const out = await s.ev(READ);
      if (out.view !== 'overview') d.push(`a wheel out left the view at ${out.view} — the last wheels [timeStamp, handled, deltaY]: ${JSON.stringify((await s.ev('window.__stream.wheels')).slice(-4))}`);
      if (out.floors.some((f) => f.desks.some((k) => !k.culled))) d.push('a name is drawn at the overview after a wheel out');
      return { defects: d, facts: { viewport: found.viewport, point: p, floor, other: found.other, sent: sample.sent, frames: sample.frames.length, views, gaps: sample.frames.map((f, i) => i === 0 ? 0 : f.t - sample.frames[i - 1].t).filter((g) => g > 50), changesAt: sample.frames.map((f, i) => i > 0 && f.view !== sample.frames[i - 1].view ? [i, f.t] : null).filter(Boolean) } };
    } finally { s.close(); }
  }),
};

const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no headless Chromium (--chrome, $CHROME or ~/.cache/ms-playwright): this check never ran');
  process.exit(2);
}

const only = argv.flatMap((a, i) => (a === '--row' ? [argv[i + 1]] : []));
const rows = Object.entries(ROWS).filter(([id]) => only.length === 0 || only.includes(id));
if (rows.length !== (only.length === 0 ? Object.keys(ROWS).length : only.length)) { console.error(`FAIL — no row ${only.filter((id) => !(id in ROWS)).join(', ')}`); process.exit(2); }
const merged = (base, planted = {}) => ({ ...base, ...planted, plant: [...(base.plant ?? []), ...(planted.plant ?? [])], args: [...(base.args ?? []), ...(planted.args ?? [])] });
let failed = 0;
/** The rows red with no plant: a plant on one proves nothing, since the row reds either way. */
const red = new Set();

for (const [id, r] of rows) {
  const out = await r.run(chrome, merged(r.base)).catch((e) => ({ defects: [`the row threw: ${e.message}`], facts: {} }));
  console.log(`${out.defects.length === 0 ? 'PASS' : 'FAIL'} ${id} — ${r.what}`);
  for (const x of out.defects) console.log(`  ✗ ${x}`);
  console.log(`  facts: ${JSON.stringify(out.facts)}`);
  if (out.defects.length > 0) { failed++; red.add(id); }
}

if (argv.includes('--selftest')) {
  for (const [id, r] of rows) {
    if (red.has(id)) {
      for (const [plant] of r.plants) console.log(`UNMEASURED ${id} under the plant: ${plant} — the row is red with no plant, so its red under one is no evidence`);
      continue;
    }
    for (const [plant, planted] of r.plants) {
      const out = await r.run(chrome, merged(r.base, planted)).catch((e) => ({ defects: [`the row threw: ${e.message}`] }));
      console.log(`${out.defects.length > 0 ? 'RED  ' : 'GREEN'} ${id} under the plant: ${plant}${out.defects.length > 0 ? ` — ${out.defects[0]}` : ' — the plant was NOT caught'}`);
      if (out.defects.length === 0) failed++;
    }
  }
}

console.log(failed === 0 ? 'PASS' : `FAIL — ${failed}`);
process.exit(failed === 0 ? 0 : 1);

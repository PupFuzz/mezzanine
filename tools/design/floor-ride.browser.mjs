#!/usr/bin/env node
// THE ELEVATOR RIDE, IN A REAL BROWSER — card#11795 (`docs/design/FLOOR.md` § 4.5, § 9.6 of the design; Appendix B
// row 23). Node, no dependencies — but real navigations in the Playwright-cached headless Chromium: the served floor,
// lobby, sign-in and console pages (`render-floor-view.php`, each rendered through the real layout, whose head script
// and inline style ARE what is measured here), the shipped `server/public` modules and stylesheet, the asset route's
// art, and one checked-in fixture run (`floor_rides`, `fx-camera.json`) answering every page's `/api/…` requests.
//
//   node tools/design/floor-ride.browser.mjs              every row, each once
//   node tools/design/floor-ride.browser.mjs --row <id>   one row (`--row starts --selftest` is what CI runs, card#12326)
//   node tools/design/floor-ride.browser.mjs --selftest   every row, then every row again under each of its PLANTs, each of
//                                                         which must red (the bookmark row documents a residual and plants nothing)
//   node tools/design/floor-ride.browser.mjs --shots <dir>  also save the cab rows' windows, and the doors held open, as PNGs under <dir>
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// It also carries the floor page's other real-browser rows that need its served pages and fixture runs: `starts` (the
// CI smoke) and `bird-flight` (card#12345, the coordination bird's flight across repaints).
//
// ⛔ THE PAGES ARE THE SERVED ONES, AND THE TOOL CARRIES NO COPY OF THE READER. Every page is rendered by
// `render-floor-view.php`; every absolute origin the view prints (the app URL, the sandbox host included) is
// rewritten to this tool's server. A plant edits what is SERVED — a module, the sheet, or the rendered page —
// never a copy kept here, so a change to the layout's script changes what every row measures (the `page` row's
// CONTROL).
//
// ⛔ THE RUN IS THE FIXTURE's. Each page's `/api/…` answers are the run's `http` map, each path's last answer
// repeating; its stream is a stand-in `EventSource` firing the run's messages on the page's own clock; its clock is
// continuous. A row may DELAY an answer, a document or the static files, change a status, or redirect a document —
// page conditions, never scenario bytes.
//
// ⛔ THE DEPARTING PAGE IS SETTLED BEFORE IT IS PRESSED (`load`, the first drawn scene, one animation frame): a
// press before the departing page has painted loses the transition, and a viewer presses seconds after load.
// ⛔ AND IT IS NOT READ WHILE ITS NAVIGATION IS PENDING: once `location.assign` is in flight, `Runtime.evaluate`
// on it blocks until the destination commits, so its facts at `pageswap` come from a beacon to this tool's server.
//
// ⚠ WHAT A PASS IS NOT: Safari and Firefox (no `PageRevealEvent` there as of the design's reading, unverified), a
// headed Chrome, a back-forward-cache restore (this headless shell never restores — `BackForwardCacheDisabledForDelegate`),
// a screen reader's reading of the cab, or a real network's round trip. Timings here are a loopback host's.

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const FIXTURES = join(REPO, 'server', 'tests', 'Feature', 'Floor', 'fixtures');
const FLOOR_MD = join(REPO, 'docs', 'design', 'FLOOR.md');
const RUN = 'floor_rides';

const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
/** `--shots <dir>`: the cab rows save a screenshot of each window they measure there. */
const SHOTS = argOf('--shots');

/** § 12's reference viewport, read as `floor-chrome.browser.mjs` reads it. */
function referenceViewport() {
  const row = readFileSync(FLOOR_MD, 'utf8').split('\n').find((l) => l.startsWith('| Floor reference viewport |')) ?? '';
  const m = /^\| Floor reference viewport \| \*\*([\d,]+) × ([\d,]+) CSS px\*\*/.exec(row);
  if (m === null) throw new Error("§ 12's viewport row states no reference viewport in the form this tool reads");
  return { w: Number(m[1].replace(/,/g, '')), h: Number(m[2].replace(/,/g, '')) };
}

/** The shipped hold, read from the module the page imports (the head script's copy is held equal by the suite). */
const CAB_HOLD_MS = Number(/export const CAB_HOLD_MS = (\d+);/.exec(readFileSync(join(REPO, 'server', 'public', 'js', 'wire', 'ride-arrival.js'), 'utf8'))[1]);

/** The ride's door motion (§ 12's *Ride door motion*, card#12038) and one fade (one loop frame), read from the modules. */
const RIDE_DOOR_MS = Number(/^export const RIDE_DOOR_MS = (\d+);$/m.exec(readFileSync(join(REPO, 'server', 'public', 'js', 'wire', 'ride.js'), 'utf8'))[1]);
const FADE_MS = 1000 / Number(/^export const LOOP_FPS = (\d+);$/m.exec(readFileSync(join(REPO, 'server', 'public', 'js', 'wire', 'animation-set.js'), 'utf8'))[1]);
/** A phase that fades and then moves its doors: `entering`, `chosen`, `staying`, `arriving`. */
const DOOR_PHASE_MS = FADE_MS + RIDE_DOOR_MS;

/** The run, from whichever fixture file holds it, with the rig's `@json:` / `@text:` references resolved. */
function fixtureRun(name) {
  const holders = readdirSync(FIXTURES).filter((f) => f.endsWith('.json'))
    .map((f) => JSON.parse(readFileSync(join(FIXTURES, f), 'utf8'))).filter((body) => Object.hasOwn(body.runs ?? {}, name));
  if (holders.length !== 1) throw new Error(`the run ${name} is held by ${holders.length} fixture files, not one`);
  const resolve = (node) => {
    if (typeof node === 'string') {
      if (node.startsWith('@json:')) return JSON.parse(readFileSync(join(REPO, node.slice(6)), 'utf8'));
      if (node.startsWith('@text:')) return readFileSync(join(REPO, node.slice(6)), 'utf8');
      return node;
    }
    if (Array.isArray(node)) return node.map(resolve);
    if (node !== null && typeof node === 'object') return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, resolve(v)]));
    return node;
  };
  return resolve(holders[0].runs[name]);
}

/** Each view the layout serves here, rendered once, its absolute origins made local. */
const VIEWS = Object.fromEntries(['floor', 'dashboard', 'login', 'console'].map((v) => [v,
  execFileSync('php', [join(HERE, 'render-floor-view.php'), v], { encoding: 'utf8' }).replace(/https?:\/\/[a-z0-9.-]+(:\d+)?(\/|(?=["']))/gi, '/')]));

for (const [v, html] of Object.entries(VIEWS)) {
  if (!html.includes('<script>') || !html.includes('<link rel="stylesheet"')) throw new Error(`the ${v} view rendered no head script or no stylesheet link`);
}

/**
 * The page's own scripted world and the tool's instruments, installed right after the layout's head script and
 * style — so the reader stays the first script on the page — and before the stylesheet link and every module.
 */
const STAGE = (run, apiDelay, layoutStatus, streamDelay = 0) => `<script>
(() => {
  const RUN = ${JSON.stringify(run).replace(/</g, '\\u003c')};
  const API_DELAY = ${JSON.stringify(apiDelay)};
  const LAYOUT_STATUS = ${JSON.stringify(layoutStatus)};
  const STREAM_DELAY = ${JSON.stringify(streamDelay)};
  const html = document.documentElement;
  const T = () => Math.round(performance.now());
  // The instruments: every class change on <html>, every pagereveal, the first drawn scene and the chrome's changes,
  // and when each stream message was delivered (\`delivered\`, unrounded on \`performance.now()\`).
  const R = window.__ride = { classes: [], reveals: [], errors: [], scene: null, chrome: [], requests: [], streams: [], delivered: [] };
  new MutationObserver(() => R.classes.push([T(), html.className, location.hash])).observe(html, { attributes: true, attributeFilter: ['class'] });
  addEventListener('pagereveal', (e) => {
    const rec = { at: T(), vt: e.viewTransition !== null, finished: null };
    R.reveals.push(rec);
    e.viewTransition?.finished.then(() => { rec.finished = T(); }, () => { rec.finished = T(); });
  });
  addEventListener('error', (e) => R.errors.push(String(e.message)));
  addEventListener('unhandledrejection', (e) => R.errors.push(String(e.reason?.message ?? e.reason)));
  document.addEventListener('DOMContentLoaded', () => {
    const chrome = () => {
      const s = document.getElementById('floor-layout-statement');
      const r = document.getElementById('floor-rooms');
      const n = document.getElementById('floor-name');
      return { stmt: s === null ? null : (s.hidden ? null : s.textContent), rooms: r?.children.length ?? null, name: n?.textContent ?? null };
    };
    R.chrome.push({ at: T(), cls: html.className, ...chrome(), initial: true });
    new MutationObserver(() => {
      const c = chrome();
      const last = R.chrome[R.chrome.length - 1];
      if (last.stmt !== c.stmt || last.rooms !== c.rooms || last.name !== c.name) R.chrome.push({ at: T(), cls: html.className, ...c });
      // The first drawn scene: the floor's drawing, or the lobby's room (card#12113) — with the leaves as drawn there.
      if (R.scene === null && document.querySelector('#floor-drawing svg, #lobby-room[data-drawn]') !== null) {
        R.scene = { at: T(), cls: html.className, leaves: [...document.querySelectorAll('#floor-drawing .elevator-leaf, #lobby-room .elevator-leaf')].map((l) => l.getAttribute('style')) };
      }
    }).observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true });
  });
  // The departing page's cab, beaconed whenever it opens or closes: once its navigation is pending the page cannot
  // be read over the DevTools protocol, so what it shows at the bound is read from here.
  document.addEventListener('DOMContentLoaded', () => {
    const cab = document.querySelector('dialog.floor-cab');
    if (cab === null) return;
    new MutationObserver(() => navigator.sendBeacon('/beacon', JSON.stringify({ kind: 'cab', at: T(), open: cab.open, path: location.pathname,
      leaves: [...document.querySelectorAll('#floor-drawing .elevator-leaf')].map((l) => l.getAttribute('style')),
      disabled: document.querySelector('#floor-drawing [data-key="elevator"]')?.getAttribute('aria-disabled') ?? null }))).observe(cab, { attributes: true, attributeFilter: ['open'] });
  });
  // The departing page's facts at pageswap, beaconed — registered after the page's own listener (on load).
  addEventListener('load', () => addEventListener('pageswap', (e) => {
    const cab = document.querySelector('dialog.floor-cab');
    navigator.sendBeacon('/beacon', JSON.stringify({ at: T(), vt: e.viewTransition !== null, dialog: cab?.open ?? null, path: location.pathname }));
  }));
  // The run's world: the API from the run's own answers, the stream on the page's clock, a continuous viewer clock.
  const T0 = Date.UTC(2026, 7, 23, RUN.floor?.local_hours ?? 9, RUN.floor?.local_minutes ?? 0);
  const start = performance.now();
  const RealDate = Date;
  class RunDate extends RealDate { constructor(...a) { if (a.length === 0) { super(T0 + performance.now() - start); } else { super(...a); } } static now() { return T0 + performance.now() - start; } }
  window.Date = RunDate;
  const queues = {};
  for (const [path, list] of Object.entries(RUN.http ?? {})) queues[path] = list.map((entry) => ({ entry, left: entry.times ?? 1 }));
  const realFetch = window.fetch.bind(window);
  window.fetch = (input, init) => {
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (!url.pathname.startsWith('/api/')) return realFetch(input, init);
    R.requests.push([T(), url.pathname]);
    const queue = queues[url.pathname] ?? [];
    const head = queue[0];
    if (head === undefined) return Promise.reject(new TypeError('unscripted request: ' + url.pathname));
    if (--head.left <= 0 && queue.length > 1) queue.shift();
    const next = head.entry;
    const status = url.pathname === '/api/building' && LAYOUT_STATUS[location.pathname] !== undefined ? LAYOUT_STATUS[location.pathname] : next.status;
    const text = status !== next.status ? JSON.stringify({ error: 'planted' }) : (next.text !== undefined ? next.text : JSON.stringify(next.body ?? null));
    const delay = (next.delay_ms ?? 0) + (API_DELAY[url.pathname] ?? 0);
    return new Promise((resolve) => setTimeout(() => resolve(new Response(text, { status, headers: { 'content-type': 'application/json' } })), delay));
  };
  class RunEventSource {
    constructor(url) {
      this.url = url; this.readyState = 0; this.listeners = {};
      R.streams.push([T(), String(url)]);
      const opened = performance.now() - start;
      setTimeout(() => { this.readyState = 1; this.fire('open', {}); }, 0);
      for (const m of (RUN.messages ?? []).map((x) => ({ ...x, at_ms: x.at_ms + STREAM_DELAY }))) {
        if (m.at_ms < opened) continue;
        setTimeout(() => {
          if (this.readyState === 2) return;
          if (m.end === true) { this.readyState = 2; this.fire('error', {}); return; }
          R.delivered.push(performance.now());
          this.fire('mezzanine', { data: JSON.stringify(m.envelope) });
        }, m.at_ms - (performance.now() - start));
      }
    }
    addEventListener(type, cb) { (this.listeners[type] ??= []).push(cb); }
    removeEventListener(type, cb) { this.listeners[type] = (this.listeners[type] ?? []).filter((c) => c !== cb); }
    fire(type, event) { for (const cb of this.listeners[type] ?? []) cb({ type, ...event }); }
    close() { this.readyState = 2; }
  }
  window.EventSource = RunEventSource;
})();
</script>`;

const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.png': 'image/png',
  '.json': 'application/json', '.tmj': 'application/json', '.tsx': 'application/xml', '.svg': 'image/svg+xml' };

/**
 * One row's server. `c` — the row's page conditions: `run` (the fixture run, `floor_rides` by default), `delay` (ms
 * on every static `/js` and `/css` answer), `doc` (`path → ms` before a document is answered, or `'gate'` to hold it
 * until `/release`), `redirect` (`path → target`), `api` (`path → ms` added to the run's answer), `layout`
 * (`document path → status` for that page's `/api/building`), `media` (emulated), `missing` (paths answered 404 — an
 * asset that failed to load), `stream` (ms added to every message's `at_ms`, so a run's messages land after the page
 * has settled on a slow host); and
 * `plant` — `[{ file, from, to }]` edits to what is served, `file` a path under `server/public` or `page` (every
 * rendered page).
 */
function serve(c = {}) {
  const run = fixtureRun(c.run ?? RUN);
  const plants = c.plant ?? [];
  const edit = (file, text) => plants.filter((p) => p.file === file).reduce((t, p) => {
    if (!t.includes(p.from)) throw new Error(`the plant's anchor is not in ${file}: ${p.from.slice(0, 60)}`);
    return t.split(p.from).join(p.to);
  }, text);
  const pageOf = (view, key, seat = '') => {
    let html = VIEWS[view];
    if (view === 'floor') html = html.replace(/data-floor="[^"]*"/, `data-floor="${key}"`).replace(/data-seat="[^"]*"/, `data-seat="${seat}"`);
    const at = html.indexOf('<link rel="stylesheet"');
    return edit('page', edit(`page:${view}`, html.slice(0, at) + STAGE(run, c.api ?? {}, c.layout ?? {}, c.stream ?? 0) + html.slice(at)));
  };
  const beacons = [];
  let release = null;
  const roots = [['/art/floor/', join(REPO, 'resources', 'floor')], ['/art/characters/', join(REPO, 'resources', 'characters')], ['/', join(REPO, 'server', 'public')]];
  const server = createServer((req, res) => {
    const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    const doc = (body) => { res.writeHead(200, { 'content-type': 'text/html', 'cache-control': 'no-store' }); res.end(body); };
    if (path === '/beacon' && req.method === 'POST') {
      let b = ''; req.on('data', (d) => { b += d; }); req.on('end', () => { beacons.push(JSON.parse(b)); res.writeHead(204); res.end(); });
      return;
    }
    if (path === '/release') { release?.(); release = null; res.end('ok'); return; }
    const documents = { '/dashboard': () => pageOf('dashboard'), '/login': () => pageOf('login'), '/admin': () => pageOf('console'), '/admin/other': () => pageOf('console') };
    const floor = /^\/floor\/([^/]+)(?:\/([^/]+))?$/.exec(path);
    if (floor !== null || Object.hasOwn(documents, path)) {
      if (c.redirect?.[path] !== undefined) { res.writeHead(302, { location: c.redirect[path] }); res.end(); return; }
      const body = () => doc(floor !== null ? pageOf('floor', floor[1], floor[2] ?? '') : documents[path]());
      const wait = c.doc?.[path];
      if (wait === 'gate') { release = body; return; }
      setTimeout(body, wait ?? 0);
      return;
    }
    if ((c.missing ?? []).includes(path)) { res.writeHead(404); res.end(); return; }
    for (const [prefix, root] of roots) {
      if (!path.startsWith(prefix)) continue;
      const file = normalize(join(root, path.slice(prefix.length)));
      if (file.startsWith(root) && existsSync(file)) {
        const rel = file.slice(join(REPO, 'server', 'public').length + 1);
        const body = edit(rel, readFileSync(file, 'utf8'));
        const late = (path.startsWith('/js/') || path.startsWith('/css/')) ? (c.delay ?? 0) : 0;
        setTimeout(() => { res.writeHead(200, { 'content-type': TYPES[extname(file)] ?? 'application/octet-stream', 'cache-control': 'no-store' }); res.end(body); }, late);
        return;
      }
    }
    res.writeHead(404); res.end();
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({
    base: `http://127.0.0.1:${server.address().port}`, beacons, released: () => release === null, close: () => server.close(),
  })));
}

/** A browser on one row's server: the page, its clicks and keys, its instruments. */
async function session(chrome, srv, c = {}) {
  const { w, h } = c.viewport ?? referenceViewport();
  const b = await browser(chrome, w, h, 'never', { media: c.media ?? [] });
  const page = b.page;
  const ev = b.evaluate;
  const at = async (selector) => ev(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (e === null) return null; const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
  const click = async (selector) => {
    const p = await at(selector);
    if (p === null) throw new Error(`nothing to click at ${selector}`);
    for (const type of ['mousePressed', 'mouseReleased']) await page('Input.dispatchMouseEvent', { type, x: p.x, y: p.y, button: 'left', clickCount: 1 });
  };
  const key = async (k, code, vk, extra = {}) => {
    await page('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: k, code, windowsVirtualKeyCode: vk, ...extra });
    if (!extra.hold) await page('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk });
  };
  const escape = () => key('Escape', 'Escape', 27);
  const pixel = async () => {
    const url = `data:image/png;base64,${(await page('Page.captureScreenshot', { format: 'png' })).data}`;
    return ev(`new Promise((ok) => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.width; c.height = i.height; const x = c.getContext('2d'); x.drawImage(i, 0, 0); const d = x.getImageData(Math.floor(i.width / 2), Math.floor(i.height / 2), 1, 1).data; ok('#' + [d[0], d[1], d[2]].map((v) => v.toString(16).padStart(2, '0')).join('')); }; i.src = ${JSON.stringify(url)}; })`);
  };
  /** Navigate and settle: loaded, a scene drawn, one animation frame. */
  const open = async (path) => {
    await page('Page.navigate', { url: srv.base + path });
    await until(() => ev("document.readyState === 'complete' && window.__ride?.scene !== null && window.__ride?.scene !== undefined"), 15000, `the page ${path} drew no scene`);
    await ev('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))');
  };
  /** The page's instruments, once readable (after a pending navigation commits). */
  const record = () => ev('JSON.parse(JSON.stringify({ path: location.pathname, hash: location.hash, cls: document.documentElement.className, now: Math.round(performance.now()), arrivalAt: document.documentElement.dataset.rideArrivalAt ?? null, arrival: document.documentElement.dataset.rideArrival ?? null, nav: performance.getEntriesByType("navigation")[0]?.type ?? null, ...window.__ride }))');
  const cab = () => ev(`(() => { const d = document.getElementById('floor-cab'); return { open: d.open, modal: d.matches(':modal'), doors: document.getElementById('floor-cab-doors').children.length,
    focus: document.activeElement?.closest('[data-key], [data-stop]')?.getAttribute('data-key') ?? document.activeElement?.closest('[data-stop]')?.dataset.stop ?? null,
    disabled: document.querySelector('#floor-drawing [data-key="elevator"]')?.getAttribute('aria-disabled') ?? null,
    leaves: [...document.querySelectorAll('#floor-drawing .elevator-leaf')].map((l) => l.getAttribute('style')),
    path: location.pathname, panel: !document.getElementById('floor-panel').hidden }; })()`);
  /** With `--shots <dir>`, the window as it stands — or `clip` of it (`{x, y, width, height, scale}`) — saved as `<dir>/<name>.png` (recorded, never judged). */
  const shot = async (name, clip = null) => {
    if (SHOTS === null) return;
    mkdirSync(SHOTS, { recursive: true });
    writeFileSync(join(SHOTS, `${name}.png`), Buffer.from((await page('Page.captureScreenshot', { format: 'png', ...(clip === null ? {} : { clip }) })).data, 'base64'));
  };
  return { b, page, ev, at, click, key, escape, pixel, open, record, cab, shot, close: () => b.close() };
}

/** Poll `fn` until it answers truthy, or fail with `why`. */
async function until(fn, ms, why) {
  const end = Date.now() + ms;
  for (;;) {
    try { const v = await fn(); if (v) return v; } catch { /* the page is between documents */ }
    if (Date.now() > end) throw new Error(why);
    await sleep(25);
  }
}

/** Into the cab on A and up to `inside` (its doors shut, its buttons armed). */
async function intoTheCab(s) {
  await s.click('#floor-drawing [data-key="elevator"]');
  try {
    await until(async () => (await s.cab()).open, RIDE_DOOR_MS + 1000, 'the elevator opened no cab');
  } catch (e) {
    const p = await s.at('#floor-drawing [data-key="elevator"]');
    const hit = p === null ? null : await s.ev(`(() => { const e = document.elementFromPoint(${p.x}, ${p.y}); return e === null ? null : e.tagName + '.' + (e.getAttribute('class') ?? '') + ' in ' + (e.closest('[data-key]')?.getAttribute('data-key') ?? '-'); })()`);
    throw new Error(`${e.message} (the elevator at ${JSON.stringify(p)}, the point hits ${hit}; page errors ${JSON.stringify((await s.record()).errors)})`);
  }
  await until(() => s.ev("!document.getElementById('floor-cab-panel').inert"), RIDE_DOOR_MS + DOOR_PHASE_MS + 1000, 'the cab never reached inside');
}

/**
 * The windows the cab is measured at (card#12039, card#12051): § 12's desktop and phone screenshots' sizes, a short
 * ultra-wide one, a tall tablet one, a landscape window too narrow for the popup beside the cab, and a small phone's.
 * ⚠ In a landscape window wide enough for the side-by-side layout the cab's box takes the cab's own shape, so a
 * stretched fit and `meet` draw it alike there: the `cab-fit` plant reds at the other windows, where the box is not
 * that shape.
 */
const CAB_WINDOWS = Object.freeze({ desktop: { w: 1440, h: 900 }, 'ultra-wide': { w: 1920, h: 600 }, tablet: { w: 768, h: 1024 }, phone: { w: 390, h: 844 }, 'small-landscape': { w: 400, h: 300 }, 'small-phone': { w: 320, h: 568 } });

/**
 * The cab's geometry inside, in window px: the window, the drawing's box, the cab's open front (its rim, the room's
 * whole drawn space), its back wall and side walls, the door's frame, its lamp and leaves, the popup and its buttons.
 */
const cabGeometry = (s) => s.ev(`(() => {
  const r = (e) => { if (e === null) return null; const b = e.getBoundingClientRect(); return { l: b.left, t: b.top, r: b.right, b: b.bottom }; };
  const cab = document.getElementById('floor-cab');
  return { w: innerWidth, h: innerHeight, cell: r(document.getElementById('floor-cab-doors')),
    room: r(document.querySelector('#floor-cab-doors .cab-rim')), back: r(document.querySelector('#floor-cab-doors .cab-back')),
    sides: [...document.querySelectorAll('#floor-cab-doors .cab-side')].map(r),
    frame: r(document.querySelector('#floor-cab-doors .cab-frame')), lamp: r(document.querySelector('#floor-cab-doors .cab-lamp')),
    leaves: [...document.querySelectorAll('#floor-cab-doors .elevator-leaf')].map(r),
    panel: r(document.getElementById('floor-cab-panel')), buttons: [...document.querySelectorAll('#floor-cab-stops button')].map(r),
    scroll: { cab: cab.scrollWidth - cab.clientWidth, page: document.documentElement.scrollWidth - innerWidth } };
})()`);

/** The popup's fade-in run to its end (a software rasteriser can lag a timed wait), then one frame. */
const buttonsIn = (s, prefix = 'floor') => s.ev(`Promise.all(document.getElementById('${prefix}-cab-panel').getAnimations().map((a) => a.finished)).then(() => new Promise((r) => requestAnimationFrame(() => r(true))))`);

/** `a` wholly inside the window (half a px of rounding allowed). */
const inWindow = (a, g) => a !== null && a.l >= -0.5 && a.t >= -0.5 && a.r <= g.w + 0.5 && a.b <= g.h + 0.5;

/** A ride from `aimla` to `stop`: settled, into the cab, the stop pressed; answers when the destination committed. */
async function rideTo(s, stop) {
  await s.open('/floor/aimla');
  await intoTheCab(s);
  await s.click(`#floor-cab-stops li[data-stop="${stop}"] button`);
  const target = stop === '' ? '/dashboard' : `/floor/${stop}`;
  await until(async () => (await s.ev('location.pathname')) === target, 15000, `the ride never reached ${target}`);
}

/** The departing page's reference pixels: its bare floor, and its cab standing open (the `chosen` doors at rest open). */
async function references(s) {
  const floor = await s.pixel();
  await intoTheCab(s);
  return { floor };
}

/** The cab, doors open, `chosen` at its end (no navigation pending yet): what the browser will snapshot in `leaving`. */
async function cabOpenPixel(s) {
  await sleep(DOOR_PHASE_MS - 100);
  return s.pixel();
}

/** The house theme's module as the asset route serves it — the registry's `HOUSE_THEME`, read as PHP reads that line. */
const HOUSE_THEME_PATH = `/art/floor/themes/${/^export const HOUSE_THEME = '([a-z0-9-]+)';$/m.exec(readFileSync(join(REPO, 'resources', 'floor', 'themes', 'index.js'), 'utf8'))[1]}/theme.js`;

/**
 * Every text in the open cab's popup against what it sits on, as the browser computed them (WCAG 2.x, sRGB): the body's
 * texts on its plain colour and under each stop of its light-and-shade gradient (the worst counting), a stop's words on
 * its button, the focus ring's colour on the body. Also whether the dialog is marked themed, and the body's colour.
 */
const cabContrast = (s) => s.ev(`(() => {
  // A computed colour: \`rgb()\` / \`rgba()\`, or \`color(srgb …)\` (what a \`color-mix()\` in sRGB computes to), as 0–255 channels and alpha.
  const rgb = (css) => {
    const m = /rgba?\\(([\\d.]+),? ([\\d.]+),? ([\\d.]+)(?:,? \\/? ?([\\d.]+))?\\)/.exec(css);
    if (m !== null) return [Number(m[1]), Number(m[2]), Number(m[3]), m[4] === undefined ? 1 : Number(m[4])];
    const c = /color\\(srgb ([\\d.e-]+) ([\\d.e-]+) ([\\d.e-]+)(?: \\/ ([\\d.]+))?\\)/.exec(css);
    if (c !== null) return [Number(c[1]) * 255, Number(c[2]) * 255, Number(c[3]) * 255, c[4] === undefined ? 1 : Number(c[4])];
    throw new Error('a colour this row cannot read: ' + css);
  };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)]; return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const over = (base, o) => [0, 1, 2].map((i) => base[i] * (1 - o[3]) + o[i] * o[3]);
  const cs = (e) => getComputedStyle(e);
  const panel = document.getElementById('floor-cab-panel');
  const body = rgb(cs(panel).backgroundColor);
  const image = cs(panel).backgroundImage;
  const linear = image.slice(image.lastIndexOf('linear-gradient('));
  const stops = [...linear.matchAll(/rgba?\\([^)]*\\)/g)].map((m) => rgb(m[0]));
  const grounds = [body, ...stops.map((o) => over(body, o))];
  const worst = (ink) => Math.min(...grounds.map((g) => ratio(ink, g)));
  const out = {};
  for (const id of ['floor-cab-heading', 'floor-cab-status', 'floor-cab-layout', 'floor-cab-feed']) out[id] = worst(rgb(cs(document.getElementById(id)).color));
  for (const li of document.querySelectorAll('#floor-cab-stops li')) {
    const button = li.querySelector('button');
    const plate = rgb(cs(button).backgroundColor);
    const at = li.classList.contains('current') ? 'current' : 'stop';
    for (const part of ['cab-name', 'cab-here']) out[at + ' ' + part] = Math.min(out[at + ' ' + part] ?? Infinity, ratio(rgb(cs(button.querySelector('.' + part)).color), plate));
  }
  const ringRule = [...document.styleSheets].flatMap((sh) => { try { return [...sh.cssRules]; } catch { return []; } }).find((r) => r.selectorText === '.floor-cab-panel li > button:focus-visible');
  const probe = document.createElement('i');
  probe.style.color = ringRule?.style.outlineColor ?? '';
  panel.append(probe);
  out.ring = worst(rgb(cs(probe).color));
  probe.remove();
  return { themed: document.getElementById('floor-cab').hasAttribute('data-themed'), body: cs(panel).backgroundColor, stops: stops.length, ratios: Object.fromEntries(Object.entries(out).map(([k, v]) => [k, Math.round(v * 100) / 100])) };
})()`);

/**
 * Each row: `run(chrome, c)` answers `{ defects, facts }`; `base` its own page conditions; `plant` what must red it — or a
 * list of plants, each run on its own and each required to red, where one row reads two surfaces with a defect each.
 */
const MAIN = 'js/floor/main.js';
const SCREEN = 'js/floor/floor-screen.js';
const PANEL = 'js/floor/cab-panel.js';
const RULE = 'js/wire/ride-arrival.js';
/** The ride's state machine (card#12113, hoisted from the floor screen) and its page half (hoisted from the floor's entry). */
const RIDE = 'js/wire/ride.js';
const PERFORMER = 'js/wire/ride-page.js';
const row = (what, base, plant, run) => ({ what, base, plant, run });

/**
 * A browser without `color-mix()`: the painter's `@supports` condition made one no browser meets (its fallback is derived
 * from the mixed text, so only the condition is edited), and every `color-mix(` in the sheet an unknown function — its
 * `@supports` conditions fail, and a mix written outside them is dropped, as such a browser drops it.
 */
const NO_MIX = [
  { file: 'js/floor/cab-paint.js', from: '@supports (fill:color-mix(in srgb,currentColor 50%,transparent))', to: '@supports (fill:no-such-colour(1))' },
  { file: 'css/mezzanine.css', from: 'color-mix(', to: 'no-such-mix(' },
];

/**
 * card#12194's walker sampler, installed before the page's first script and run on every animation frame for the first
 * `ms`: each `.fx-walker` (the gliding group) — its path, its point and feet `y` from its computed `offset-distance`, whether
 * its shown group draws it, its node (a rebuild is a new one) and its place among the floor stack's children (a move is a
 * new place for the same node), its glide's timing function, duration and start on the timeline, read off the `glide`
 * animation itself (card#12705: a walker with two walk legs runs a second, `walker-shown`, on each glide) — and, per frame, the floor stack:
 * each desk's key, place and foot line (its desk sprite's viewport `y + height`), each walker's place, which element has
 * focus, any walker element outside the stack, a walker shown in the cab outside the band's cab or after its leaves, and any
 * point under a shown walker that is a walker element.
 */
const WALKER_SAMPLER = (ms) => `(window.__walkers = { samples: [], frames: [] }, (() => {
  const ids = new WeakMap(); let next = 0; const paths = new Map(); const t0 = performance.now();
  const curve = (d) => { if (!paths.has(d)) { const p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); paths.set(d, p); } return paths.get(d); };
  const id = (el) => { if (!ids.has(el)) ids.set(el, next++); return ids.get(el); };
  const tick = (now) => {
    const stack = document.querySelector('#floor-drawing .desks');
    const kids = stack === null ? [] : [...stack.children];
    const frame = { t: now - t0, desks: [], walkers: [], loose: [], cabBad: false, hit: [], focus: document.activeElement?.closest('[data-key]')?.getAttribute('data-key') ?? null };
    kids.forEach((k, i) => {
      if (k.classList.contains('desk')) {
        const art = k.querySelector('image.desk-sprite')?.closest('svg');
        frame.desks.push({ key: k.getAttribute('data-key'), index: i, depth: art === null || art === undefined ? null : Number(art.getAttribute('y')) + Number(art.getAttribute('height')) });
      }
    });
    for (const el of document.querySelectorAll('#floor-drawing .fx-walker')) {
      const shownEl = el.parentNode;
      const d = /path\\("?'?([^"')]+)/.exec(el.style.offsetPath)?.[1] ?? null;
      if (d === null) continue;
      const cs = getComputedStyle(el);
      const p = curve(d); const len = p.getTotalLength(); const pct = parseFloat(cs.offsetDistance);
      const at = p.getPointAtLength(len * pct / 100);
      const glide = el.getAnimations().find((a) => a.animationName === 'glide');
      const origin = glide === undefined || glide.startTime === null ? null : glide.startTime + glide.effect.getTiming().delay - t0;
      const shown = Number(getComputedStyle(shownEl).opacity) > 0;
      const index = kids.indexOf(shownEl);
      if (index === -1) frame.loose.push('walker in ' + (shownEl.parentNode?.getAttribute('class') ?? '?'));
      frame.walkers.push({ y: at.y, index, shown });
      window.__walkers.samples.push({ t: now - t0, d, el: id(shownEl), index, pct, len, x: at.x, y: at.y, shown, origin, timing: glide === undefined ? cs.animationTimingFunction : glide.effect.getKeyframes()[0]?.easing ?? null, ms: glide === undefined ? parseFloat(cs.animationDuration) * 1000 : glide.effect.getTiming().duration });
      if (shown) {
        const img = el.querySelector('image, rect');
        if (img !== null) {
          const r = img.getBoundingClientRect();
          const e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          if (e !== null && e.closest('.fx-walker-shown') !== null) frame.hit.push(e.tagName + '.' + (e.getAttribute('class') ?? ''));
        }
      }
    }
    for (const cab of document.querySelectorAll('#floor-drawing .fx-walker-cab')) {
      if (Number(getComputedStyle(cab).opacity) === 0) continue;
      const band = cab.closest('.band-cab');
      const leaf = document.querySelector('#floor-drawing .elevator-leaf');
      if (band === null || leaf === null || !(cab.compareDocumentPosition(leaf) & Node.DOCUMENT_POSITION_FOLLOWING)) frame.cabBad = true;
    }
    window.__walkers.frames.push(frame);
    if (now - t0 < ${ms}) requestAnimationFrame(tick);
  };
  window.__walkers.t0 = t0;
  requestAnimationFrame(tick); })())`;

/** The samples of one walker sampler run, by walk (one `offset-path` per walk). */
const byPath = (all) => {
  const walks = new Map();
  for (const x of all) { if (!walks.has(x.d)) walks.set(x.d, []); walks.get(x.d).push(x); }
  return walks;
};

/**
 * One walk's glide judged over its samples while it moves (shown, strictly between its ends): `linear`, never a step
 * back past `SLACK` px, never a step longer than its own speed covers in the frame gap plus `SLACK`. Pushes its defects
 * onto `d` and answers the walk's facts — its rebuilds (nodes) and its moves (one node at a new place in the stack).
 */
function glideDefects(path, xs, d) {
  const SLACK = 3;
  const tag = path.slice(0, 40);
  const moving = xs.filter((x) => x.shown && x.pct > 0 && x.pct < 100);
  const speed = xs[0].len / xs[0].ms;
  let worstBack = 0;
  let worstStep = 0;
  let moves = 0;
  if (moving.some((x) => x.timing !== 'linear')) d.push(`${tag}: the walker's glide is ${moving.find((x) => x.timing !== 'linear').timing}, not linear`);
  for (let i = 1; i < moving.length; i++) {
    const a = moving[i - 1];
    const b = moving[i];
    const back = (a.pct - b.pct) * a.len / 100;
    const step = Math.hypot(b.x - a.x, b.y - a.y);
    if (a.el === b.el && a.index !== b.index) moves++;
    worstBack = Math.max(worstBack, back);
    worstStep = Math.max(worstStep, step - speed * (b.t - a.t));
    if (back > SLACK) d.push(`${tag}: the walker stepped ${back.toFixed(1)} px BACK at ${Math.round(b.t)} ms (node ${a.el}@${a.index} → ${b.el}@${b.index})`);
    if (step > speed * (b.t - a.t) + SLACK) d.push(`${tag}: the walker jumped ${step.toFixed(1)} px in ${Math.round(b.t - a.t)} ms at ${Math.round(b.t)} ms, over the ${(speed * (b.t - a.t)).toFixed(1)} px its speed covers`);
  }
  return { len: Math.round(xs[0].len), ms: xs[0].ms, samples: moving.length, rebuilds: new Set(moving.map((x) => x.el)).size, moves, worstBack: Number(worstBack.toFixed(2)), worstStepOverSpeed: Number(worstStep.toFixed(2)) };
}

/**
 * MEASUREMENT (card#12194's design r4, item a): a walker's node re-inserted where it stands by `insertBefore` — does
 * Chromium restart its running CSS animations? Read on the shown walker standing in its wait: each animation's object,
 * start time and current time before; the same after one animation frame; and whether the objects are the same ones.
 */
const REINSERT = `(async () => {
  const g = [...document.querySelectorAll('#floor-drawing .fx-walker-shown')].find((n) => getComputedStyle(n).opacity !== '0' && n.parentNode.classList.contains('desks'));
  if (g === undefined) return null;
  const read = (as) => as.map((a) => ({ name: a.animationName, start: a.startTime === null ? null : Math.round(a.startTime), current: a.currentTime === null ? null : Math.round(a.currentTime) }));
  const nodes = [g, g.querySelector('.fx-walker')];
  const before = nodes.flatMap((n) => n.getAnimations());
  const was = read(before);
  g.parentNode.insertBefore(g, g.nextSibling);
  await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  const after = nodes.flatMap((n) => n.getAnimations());
  return { before: was, after: read(after), same_objects: before.length === after.length && before.every((a, i) => a === after[i]), restarted: read(after).some((a, i) => was[i] !== undefined && a.current !== null && was[i].current !== null && a.current < was[i].current) };
})()`;

/**
 * MEASUREMENT (card#12194's design r4, item b): does a re-inserted walker's `<image>` flicker — miss a composited frame
 * while its data URI is decoded again? The standing walker's clip is captured, then the node is re-inserted with its
 * animations put back on their start (as `pin()` does), and the clip captured again at once, eight times; each clip is
 * compared with the first by its pixels, so a frame drawn without the walker reads as a large difference.
 */
async function flicker(s) {
  const box = await s.ev(`(() => { const g = [...document.querySelectorAll('#floor-drawing .fx-walker-shown')].find((n) => getComputedStyle(n).opacity !== '0' && n.parentNode.classList.contains('desks')); if (g === undefined) return null; const r = g.getBoundingClientRect(); return { x: Math.max(0, r.x), y: Math.max(0, r.y), width: Math.max(1, r.width), height: Math.max(1, r.height), scale: 1 }; })()`);
  if (box === null) return null;
  const grab = async () => (await s.page('Page.captureScreenshot', { format: 'png', clip: box })).data;
  const diff = (a, b) => s.ev(`Promise.all([${JSON.stringify(a)}, ${JSON.stringify(b)}].map((d) => new Promise((ok) => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.width; c.height = i.height; const x = c.getContext('2d'); x.drawImage(i, 0, 0); ok(x.getImageData(0, 0, i.width, i.height).data); }; i.src = 'data:image/png;base64,' + d; }))).then(([p, q]) => { let n = 0; for (let k = 0; k < Math.min(p.length, q.length); k += 4) { if (Math.abs(p[k] - q[k]) + Math.abs(p[k + 1] - q[k + 1]) + Math.abs(p[k + 2] - q[k + 2]) > 60) n++; } return n / (p.length / 4); })`);
  const base = await grab();
  const out = [];
  for (let i = 0; i < 8; i++) {
    await s.ev(`(() => { const g = [...document.querySelectorAll('#floor-drawing .fx-walker-shown')].find((n) => getComputedStyle(n).opacity !== '0' && n.parentNode.classList.contains('desks')); const nodes = [g, g.querySelector('.fx-walker')]; const starts = nodes.map((n) => n.getAnimations().map((a) => a.startTime)); g.parentNode.insertBefore(g, g.nextSibling); nodes.forEach((n, j) => n.getAnimations().forEach((a, k) => { if (starts[j][k] !== undefined) a.startTime = starts[j][k]; })); return true; })()`);
    out.push(Number((await diff(base, await grab())).toFixed(4)));
  }
  return { changed_fraction_per_reinsert: out, worst: Math.max(...out) };
}

/** One session on one server, closed whatever the row does. */
async function within(chrome, c, fn) {
  const srv = await serve(c);
  const s = await session(chrome, srv, c);
  try { return await fn(s, srv); } finally { s.close(); srv.close(); }
}

/** The destination's record once it has arrived and drawn (or `ms` has passed). */
async function arrivedRecord(s, ms = 6000) {
  await until(async () => { const r = await s.record(); return r.scene !== null && (r.classes.some(([, k]) => k.includes('ride-arrived')) || !r.classes.some(([, k]) => k.includes('ride-holding'))); }, ms, 'the destination never drew');
  await sleep(100);
  return s.record();
}

/** The defects every destination must be free of: a held class, a transition carried, page errors. */
function cut(r, why) {
  const d = [];
  if (r.reveals[0]?.vt === true && !(r.reveals[0].finished !== null && r.reveals[0].finished - r.reveals[0].at <= 20)) d.push(`${why}: the destination's pagereveal carried a transition that ran (finished ${r.reveals[0].finished} after ${r.reveals[0].at})`);
  if (r.classes.some(([, k]) => k.includes('ride-'))) d.push(`${why}: the destination took a ride-* class: ${JSON.stringify(r.classes)}`);
  return d;
}

// Hold every leaf under `root` at `at` (0 shut … 1 open) of its own motion, and answer the doorway as drawn: the gap
// between the leaves, what of the layer behind them shows in it, and every later-drawn element that crosses it. The
// painter rebuilds the drawing on every render, so a hold whose leaves were replaced before it was read is retried.
async function hold(s, root, behind, layer, at) {
  for (let i = 0; i < 5; i++) {
    const r = await holdOnce(s, root, behind, layer, at);
    if (r.replaced !== true) return r;
  }
  return { error: 'the drawing was replaced under every hold' };
}
const holdOnce = (s, root, behind, layer, at) => s.ev(`(async () => {
  const root = document.querySelector(${JSON.stringify(root)});
  const leaves = [...root.querySelectorAll('.elevator-leaf')];
  const anims = leaves.flatMap((l) => l.getAnimations());
  if (anims.length === 0) return { error: 'no leaf motion to hold', styles: leaves.map((l) => l.getAttribute('style')) };
  for (const a of anims) { a.pause(); const t = a.effect.getTiming(); a.currentTime = t.delay + t.duration * ${at}; }
  await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  if (!root.isConnected) return { replaced: true };
  const box = (e) => { const b = e.getBoundingClientRect(); return { l: b.left, t: b.top, r: b.right, b: b.bottom }; };
  const [L, R] = leaves.map(box);
  const gap = { l: L.r, t: Math.min(L.t, R.t), r: R.l, b: Math.max(L.b, R.b) };
  const crosses = (b) => b.l < gap.r - 0.5 && b.r > gap.l + 0.5 && b.t < gap.b - 0.5 && b.b > gap.t + 0.5;
  const behind = root.querySelector(${JSON.stringify(behind)});
  const seen = behind === null ? null : box(behind);
  // Everything drawn after the layer behind the leaves, in the drawing's order: the leaves, and whatever else.
  const layer = root.querySelector(${JSON.stringify(layer)});
  const all = [...root.querySelectorAll('*')];
  const after = layer === null ? [] : all.filter((e) => (layer.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING) && !layer.contains(e));
  const over = after.filter((e) => !e.classList.contains('elevator-leaf') && !e.classList.contains('elevator-hit') && e.getBBox !== undefined && !['g', 'defs', 'clipPath', 'linearGradient', 'radialGradient', 'stop', 'style'].includes(e.tagName))
    .filter((e) => crosses(box(e))).map((e) => e.tagName + '.' + (e.getAttribute('class') ?? ''));
  return { gap, open: gap.r - gap.l, leaf: L.r - L.l, seen, shows: seen !== null && crosses(seen), over, layer: layer !== null };
})()`);

const ROWS = {
  press: row('click the elevator: the cab opens within opening\'s length and one frame', {}, { plant: [{ file: MAIN, from: '        ride: () => cab.act(screen.ride()),', to: '        ride: () => {},' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      const t = await s.ev('performance.now()');
      await s.click('#floor-drawing [data-key="elevator"]');
      const opened = await until(async () => ((await s.cab()).open ? s.ev('performance.now()') : null), 3000, 'the cab never opened').catch(() => null);
      const limit = RIDE_DOOR_MS + FADE_MS + 120;
      return { defects: opened === null ? ['the cab never opened'] : (opened - t > limit ? [`the cab opened ${Math.round(opened - t)} ms after the press`] : []), facts: { after: opened === null ? null : Math.round(opened - t) } };
    })),

  'door-order': row('the cab\'s doors close only after the cab has faded in (M3)', {}, { plant: [{ file: RIDE, from: '        entering: [fade + door, fade],', to: '        entering: [fade + door, 0],' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await s.click('#floor-drawing [data-key="elevator"]');
      await until(async () => (await s.cab()).open, 3000, 'the cab never opened');
      // Each animation's start is read once it has one: `ready` settles when the animation has started.
      const t = await s.ev(`(async () => { const fade = document.getElementById('floor-cab').getAnimations()[0]; const leaf = document.querySelector('#floor-cab-doors .elevator-leaf')?.getAnimations()[0];
        await Promise.all([fade?.ready, leaf?.ready]);
        return { fade: fade?.startTime ?? null, leaf: leaf === undefined ? null : leaf.startTime + leaf.effect.getTiming().delay, name: leaf?.animationName ?? null }; })()`);
      const d = [];
      if (t.fade === null || t.leaf === null) d.push(`no fade or no door motion: ${JSON.stringify(t)}`);
      else if (t.leaf < t.fade + FADE_MS - 17) d.push(`the doors start ${Math.round(t.leaf - t.fade)} ms after the fade does — under one frame`);
      if (t.name !== 'leaf-close') d.push(`the cab's doors ran ${t.name} on entering`);
      return { defects: d, facts: t };
    })),

  // card#12038: the operator's reading on the sandbox — *the doors open and close too quickly, and it is very choppy*.
  // Each leaf's computed scale is sampled on every animation frame through a whole door motion, on the band
  // (`opening`) and in the cab (`entering`): a continuous motion takes many distinct values, a stepped one a few. The
  // drawing's rebuilds (the painter replaces it on every paint) and the frames over two refresh intervals are recorded,
  // not judged: this headless shell rasterises in software, so its frame times are not a viewer's.
  'smooth-doors': row('the ride\'s doors move continuously over § 12\'s Ride door motion, on the band and in the cab — never in loop-frame steps', {},
    { plant: [{ file: 'js/floor/cab-paint.js', from: "const RIDE_DOOR_EASING = 'ease-in-out';", to: "const RIDE_DOOR_EASING = 'steps(2)';" }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      // One sampler, started just before the press: on every animation frame through `opening` and `entering`, the band's
      // left leaf and the cab's.
      const sample = `(window.__doors = { band: [], cab: [], gaps: [], rebuilds: [], t0: performance.now() },
        new MutationObserver(() => { window.__doors.rebuilds.push(Math.round(performance.now() - window.__doors.t0)); }).observe(document.getElementById('floor-drawing'), { childList: true }),
        (() => { const scale = (sel) => { const e = document.querySelector(sel); if (e === null) return null; const m = /matrix\\(([^,]+)/.exec(getComputedStyle(e).transform); return m === null ? 1 : Number(Number(m[1]).toFixed(3)); };
          let last = performance.now();
          const tick = (now) => { const t = now - window.__doors.t0; window.__doors.gaps.push([Math.round(t), Math.round(now - last)]); last = now;
            window.__doors.band.push([t, scale('#floor-drawing .elevator-leaf.left')]); window.__doors.cab.push([t, scale('#floor-cab-doors .elevator-leaf.left')]);
            if (t < ${RIDE_DOOR_MS + DOOR_PHASE_MS + 200}) requestAnimationFrame(tick); };
          requestAnimationFrame(tick); })(), true)`;
      await s.ev(sample);
      await s.click('#floor-drawing [data-key="elevator"]');
      await sleep(RIDE_DOOR_MS + DOOR_PHASE_MS + 400);
      const r = await s.ev('window.__doors');
      // The band opens over `opening`; the cab closes over `entering`'s door motion, which starts after its fade.
      const band = r.band.filter(([t, v]) => t <= RIDE_DOOR_MS && v !== null).map(([, v]) => v);
      const cabStart = RIDE_DOOR_MS + FADE_MS;
      const cab = r.cab.filter(([t, v]) => t >= cabStart && t <= cabStart + RIDE_DOOR_MS && v !== null).map(([, v]) => v);
      const distinct = (xs) => new Set(xs).size;
      // Ten distinct values over a motion is far above a stepped motion's (its step count plus the ends) and far below a
      // continuous one's at any frame rate a viewer would call smooth.
      const MIN = 10;
      const d = [];
      if (distinct(band) < MIN) d.push(`the band's leaves took ${distinct(band)} distinct scales over the opening: ${JSON.stringify([...new Set(band)])}`);
      if (distinct(cab) < MIN) d.push(`the cab's leaves took ${distinct(cab)} distinct scales over the closing: ${JSON.stringify([...new Set(cab)])}`);
      const bandEnd = r.band.filter(([, v]) => v !== null).map(([, v]) => v).at(-1) ?? null;
      if (bandEnd === null || Math.abs(bandEnd - 0.12) > 0.005) d.push(`the band's leaves end the sample at ${bandEnd}, not open`);
      // card#12051: the cab's leaves move on the room's back wall — both of them inside it once shut.
      const wall = await s.ev(`(() => { const r = (e) => { const b = e.getBoundingClientRect(); return { l: b.left, t: b.top, r: b.right, b: b.bottom }; };
        const back = document.querySelector('#floor-cab-doors .cab-back');
        return back === null ? null : { back: r(back), leaves: [...document.querySelectorAll('#floor-cab-doors .elevator-leaf')].map(r) }; })()`);
      if (wall === null || wall.leaves.length !== 2) d.push(`the cab has no back wall or not two leaves: ${JSON.stringify(wall)}`);
      else wall.leaves.forEach((l, i) => { if (!(l.l >= wall.back.l - 0.5 && l.r <= wall.back.r + 0.5 && l.t >= wall.back.t - 0.5 && l.b <= wall.back.b + 0.5)) d.push(`the cab's leaf ${i} is off its back wall: ${JSON.stringify(l)} on ${JSON.stringify(wall.back)}`); });
      return { defects: d, facts: { band: distinct(band), cab: distinct(cab), frames: r.gaps.length, long_frames: r.gaps.slice(1).filter(([, g]) => g > 34), rebuilds: r.rebuilds, bandEnd, wall } };
    })),

  // ⭐ THE BIRD GLIDES (card#12345). The operator's reading on the sandbox, 2026-10-25: *"It is a little too fast and is
  // not smooth (the bird jumps locations)"*, and *"When a bird goes to the bird house, it disappears before it reaches
  // the birdhouse."* Over `fx-coord`'s `coord_portal` run — A22's departure into this floor's birdhouse, A22's arrival
  // from it, and two A19 travellers, each post a render that lands while the earlier birds fly — every drawn `.fx-bird`
  // is sampled on every animation frame from the page's first script: its point on its own arc, read from its computed
  // `offset-distance`, whether it is drawn, and the flight's own start on the timeline (its `fly` animation's start
  // time plus its delay). Judged per flight (one arc): its start no earlier than the delivery of the post that drew it
  // and no later than its first drawn point; its first drawn point within one frame's travel (plus `SLACK` px) of
  // where a flight from that start, at its own duration, has reached at that sample — a bird first drawn at a paint
  // after its start is legitimately that far along its arc; no step backward, no step longer than the flight's own
  // speed covers in the frame gap (plus `SLACK` px), its last drawn point within one such step of the arc's end (the
  // desk, or the birdhouse's perch for a portal bird — card#12445's ledge dwell and hop are other nodes, `.fx-perched`
  // and `.fx-hop`, which this row does not sample), and at least one repaint (a new `.fx-bird` node) inside the flight, so the
  // continuity is measured ACROSS a rebuild and not only within one node. Which arc is which leg, and where it starts,
  // is the suite's to hold (`ABirdFliesToItsDestinationTest`); here every arc is judged alike.
  // The PLANTs: the stepped flight the card replaced, the whole-frame delay that snapped the bird back on a repaint, and
  // a painter that draws each rebuild from its own delay without pinning it to the flight's one start (`pinEffects()`),
  // and one that dates the flight from `document.timeline.currentTime` — the last frame's time, behind the page's clock
  // by however long the tasks since that frame have run — on a page busy for 40 ms as each post lands (a long task, as
  // the live floor has), so its flight starts that much before its post arrived — and a flight
  // whose keyframes start a tenth of the way along its arc, so the bird is first drawn past where its start has it.
  'bird-flight': row('a coordination bird glides along its arc across repaints, from its start to its destination — the desk or the birdhouse\'s perch', { run: 'coord_portal', stream: 3000 },
    [
      { plant: [{ file: 'js/floor/painter.js', from: 'style: `animation-duration:${seconds}s;animation-delay:${at(fx.flight_start)}s;offset-path', to: 'style: `animation-duration:${seconds}s;animation-timing-function:steps(${fx.frames});animation-delay:${at(fx.flight_start)}s;offset-path' }] },
      { plant: [{ file: 'js/floor/painter.js', from: 'const flown = fx.elapsed_ms / 1000;', to: 'const flown = (fx.elapsed_frames * fx.frame_interval_ms) / 1000;' }] },
      { plant: [{ file: 'js/floor/painter.js', from: '        host.replaceChildren(svg);\n        pinEffects();\n', to: '        host.replaceChildren(svg);\n' }] },
      { plant: [
        { file: 'js/floor/painter.js', from: 'performance.now() - (clock.now() - entry.started_at)', to: 'document.timeline.currentTime - entry.elapsed_ms' },
        { file: 'page', from: 'R.delivered.push(performance.now());', to: 'for (const until = performance.now() + 40; performance.now() < until;); R.delivered.push(performance.now());' },
      ] },
      { plant: [{ file: 'js/floor/painter.js', from: '@keyframes fly{from{offset-distance:0%;', to: '@keyframes fly{from{offset-distance:10%;' }] },
    ],
    (chrome, c) => within(chrome, c, async (s) => {
      // The sampler is installed before the page's first script, so a flight the run fires before the page settles is
      // sampled from its start; it runs on every animation frame for the run's first `SAMPLE_MS`.
      const SAMPLE_MS = 9000;
      await s.page('Page.addScriptToEvaluateOnNewDocument', { source: `(window.__birds = { samples: [] }, (() => {
        const ids = new WeakMap(); let next = 0; const paths = new Map(); const t0 = performance.now();
        const arc = (d) => { if (!paths.has(d)) { const p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); paths.set(d, p); } return paths.get(d); };
        const tick = (now) => {
          for (const el of document.querySelectorAll('#floor-drawing .fx-bird')) {
            if (!ids.has(el)) ids.set(el, next++);
            const cs = getComputedStyle(el);
            const d = /path\\("?'?([^"')]+)/.exec(el.style.offsetPath)?.[1] ?? null;
            if (d === null) continue;
            const p = arc(d); const len = p.getTotalLength(); const pct = parseFloat(cs.offsetDistance);
            const at = p.getPointAtLength(len * pct / 100); const end = p.getPointAtLength(len); const start = p.getPointAtLength(0);
            const fly = el.getAnimations().find((a) => a.animationName === 'fly');
            const origin = fly === undefined || fly.startTime === null ? null : fly.startTime + fly.effect.getTiming().delay - t0;
            window.__birds.samples.push({ t: now - t0, origin, d, el: ids.get(el), pct, len, x: at.x, y: at.y, end: [end.x, end.y], start: [start.x, start.y], shown: Number(cs.opacity) > 0, ms: parseFloat(cs.animationDuration) * 1000 });
          }
          if (now - t0 < ${SAMPLE_MS}) requestAnimationFrame(tick);
        };
        window.__birds.t0 = t0;
        requestAnimationFrame(tick); })())` });
      await s.open('/floor/aimla');
      await until(() => s.ev(`performance.now() > ${SAMPLE_MS + 100}`), SAMPLE_MS + 5000, 'the page never reached the end of the sample');
      const all = await s.ev('window.__birds.samples');
      const t0 = await s.ev('window.__birds.t0');
      // When each post reached the page, on the samples' clock; a flight's post is the last delivered by a frame after its first sample.
      const delivered = (await s.ev('window.__ride.delivered')).map((x) => x - t0);
      const FRAME = 17;
      const SLACK = 3;
      const flights = new Map();
      for (const x of all) { if (!flights.has(x.d)) flights.set(x.d, []); flights.get(x.d).push(x); }
      const d = [];
      const facts = [];
      if (flights.size === 0) d.push('no bird was drawn — the row measured nothing');
      for (const [arcD, xs] of flights) {
        const shown = xs.filter((x) => x.shown);
        const speed = xs[0].len / xs[0].ms;   // scene px per ms, the flight's own
        let worstStep = 0;
        let worstBack = 0;
        for (let i = 1; i < shown.length; i++) {
          const a = shown[i - 1];
          const b = shown[i];
          const step = Math.hypot(b.x - a.x, b.y - a.y);
          const back = (a.pct - b.pct) * a.len / 100;
          worstBack = Math.max(worstBack, back);
          if (back > SLACK) d.push(`${arcD}: the bird stepped ${back.toFixed(1)} px BACK at ${Math.round(b.t)} ms (node ${a.el} → ${b.el})`);
          if (step > speed * (b.t - a.t) + SLACK) d.push(`${arcD}: the bird jumped ${step.toFixed(1)} px in ${Math.round(b.t - a.t)} ms at ${Math.round(b.t)} ms, over the ${(speed * (b.t - a.t)).toFixed(1)} px its speed covers`);
          worstStep = Math.max(worstStep, step - speed * (b.t - a.t));
        }
        const first = shown[0];
        let lag = null;
        if (first !== undefined) {
          const post = delivered.filter((t) => t <= xs[0].t + FRAME).at(-1);
          if (first.origin === null) d.push(`${arcD}: the bird's first drawn node has no started animation`);
          else if (post === undefined) d.push(`${arcD}: no post was delivered before the bird was first drawn`);
          else {
            // 2 ms: `pinEffects()` reads the page's clock and the timeline's one after the other, and a real page clock is whole ms.
            if (first.origin < post - 2) d.push(`${arcD}: the flight starts ${(post - first.origin).toFixed(1)} ms BEFORE its post was delivered`);
            if (first.origin > first.t + 2) d.push(`${arcD}: the flight starts ${(first.origin - first.t).toFixed(1)} ms after its first drawn point`);
            const owed = xs[0].len * Math.min(1, Math.max(0, (first.t - first.origin) / xs[0].ms));
            const along = xs[0].len * first.pct / 100;
            const before = xs.filter((x) => x.t < first.t).at(-1);
            lag = Number((first.t - post).toFixed(1));
            if (Math.abs(along - owed) > speed * (before === undefined ? FRAME : first.t - before.t) + SLACK) d.push(`${arcD}: the bird's first drawn point is ${along.toFixed(1)} px along its arc, where a flight from its start has reached ${owed.toFixed(1)} px`);
          }
        }
        const last = shown.at(-1);
        const after = last === undefined ? undefined : xs.find((x) => x.t > last.t);
        const gap = after === undefined ? FRAME : after.t - last.t;
        const short = last === undefined ? null : Math.hypot(last.end[0] - last.x, last.end[1] - last.y);
        if (last === undefined) d.push(`${arcD}: the bird was never drawn`);
        else if (short > speed * gap + SLACK) d.push(`${arcD}: the bird's last drawn point is ${short.toFixed(1)} px short of its destination`);
        const nodes = new Set(shown.map((x) => x.el)).size;
        if (nodes < 2) d.push(`${arcD}: no repaint landed during the flight (one node) — continuity across a rebuild was not measured`);
        facts.push({ len: Math.round(xs[0].len), ms: xs[0].ms, samples: shown.length, nodes, firstAfterPostMs: lag, short: short === null ? null : Number(short.toFixed(2)), worstBack: Number(worstBack.toFixed(2)), worstStepOverSpeed: Number(worstStep.toFixed(2)) });
      }
      return { defects: d.slice(0, 6), facts };
    })),

  // ⭐ THE WALKER GLIDES (card#12194, FLOOR.md § 6.2's walk note items 8 and 9). The operator's *"For walking characters,
  // make them glide smoothly too"*: over `fx-elevator`'s `repaint` run — an A2 whose walk a `feed.heartbeat`'s render
  // rebuilds mid-walk — every drawn `.fx-walker` (the gliding group) is sampled on every animation frame from the page's
  // first script: its point on its own path (its computed `offset-distance` along its `offset-path`), whether it is shown,
  // its timing function, and its walk's own start on the timeline (its `glide` animation's start time plus its delay — an
  // A2's walk leg starts with the walk). Judged per walk: its timing function `linear`; its start no earlier than the
  // delivery of the delta that drew it (less 2 ms, two clock reads) and no later than its first drawn point; no step back
  // by more than `SLACK` px and no step longer than § 12's speed covers in the frame gap (plus `SLACK`); and at least one
  // rebuild (a new node) inside the walk. The PLANTs: `steps(frames)`, the glide's delay in whole frames under a pin that
  // reads the exact instant, and the start dated from `document.timeline.currentTime` on a page busy 40 ms per delivery
  // (the bird row's PLANT 4).
  'walker-glide': row('a walker glides along its routed path across a repaint, never in steps, from its walk\'s own start', { run: 'repaint', stream: 2000 },
    [
      { plant: [{ file: 'js/floor/painter.js', from: "animation-duration:${sec(walk.frames * I)}s;animation-delay", to: "animation-duration:${sec(walk.frames * I)}s;animation-timing-function:steps(${walk.frames});animation-delay" }] },
      // The glide's delay in whole frames while its pin reads the exact instant, the bird row's second PLANT's form: handed
      // to BOTH, the whole-frame elapsed cancels inside the pin (`began + elapsed` against `walk.start × I − elapsed`), so
      // a pinning browser draws it right — measured, NOT CAUGHT — and the defect a pinning browser can show is the mismatch.
      { plant: [{ file: 'js/floor/painter.js', from: 'animation-delay:${sec(walk.start * I - elapsed)}s`', to: 'animation-delay:${sec(walk.start * I - (fx.elapsed_frames ?? 0) * I)}s`' }] },
      { plant: [
        { file: 'js/floor/painter.js', from: 'performance.now() - (clock.now() - entry.started_at)', to: 'document.timeline.currentTime - entry.elapsed_ms' },
        { file: 'page', from: 'R.delivered.push(performance.now());', to: 'for (const until = performance.now() + 40; performance.now() < until;); R.delivered.push(performance.now());' },
      ] },
    ],
    (chrome, c) => within(chrome, c, async (s) => {
      const SAMPLE_MS = 9000;
      await s.page('Page.addScriptToEvaluateOnNewDocument', { source: WALKER_SAMPLER(SAMPLE_MS) });
      await s.open('/floor/aimla');
      await until(() => s.ev(`performance.now() > ${SAMPLE_MS + 100}`), SAMPLE_MS + 5000, 'the page never reached the end of the sample');
      const all = await s.ev('window.__walkers.samples');
      const t0 = await s.ev('window.__walkers.t0');
      const delivered = (await s.ev('window.__ride.delivered')).map((x) => x - t0);
      const d = [];
      const facts = [];
      const walks = byPath(all);
      if (walks.size === 0) d.push('no walker was drawn — the row measured nothing');
      for (const [path, xs] of walks) {
        const f = glideDefects(path, xs, d);
        const shown = xs.filter((x) => x.shown && x.pct > 0 && x.pct < 100);
        const first = shown[0];
        if (first !== undefined) {
          const post = delivered.filter((t) => t <= xs[0].t + 17).at(-1);
          if (first.origin === null) d.push(`${path.slice(0, 40)}: the walker's first drawn node has no started glide`);
          else if (post === undefined) d.push(`${path.slice(0, 40)}: no delta was delivered before the walker was first drawn`);
          else {
            if (first.origin < post - 2) d.push(`${path.slice(0, 40)}: the walk starts ${(post - first.origin).toFixed(1)} ms BEFORE its delta was delivered`);
            if (first.origin > first.t + 2) d.push(`${path.slice(0, 40)}: the walk starts ${(first.origin - first.t).toFixed(1)} ms after its first drawn point`);
          }
          f.firstAfterPostMs = post === undefined ? null : Number((first.t - post).toFixed(1));
        }
        if (f.rebuilds < 2) d.push(`${path.slice(0, 40)}: no repaint landed during the walk (one node) — continuity across a rebuild was not measured`);
        facts.push(f);
      }
      return { defects: d.slice(0, 6), facts };
    })),

  // ⭐ WALKERS CROSS IN DEPTH (card#12194, FLOOR.md § 6.2's walk note items 9 and 10). Over `fx-elevator`'s `crossing` run —
  // `aimla-review` departing to the elevator while `aimla-impl-1` arrives and walks down past the back row's foot line —
  // with keyboard focus placed on `aimla-impl-1`'s desk, every animation frame reads: each walker's point and node (H20 —
  // no step back, no jump, across the tick that MOVES its node, and the row reds unless a move landed inside a walk); the
  // floor stack's order — every walker after each desk whose foot line (its desk sprite's `y + h`) is at or above its feet
  // and before each deeper one, walkers among themselves by feet `y`, a pair inside one loop frame of travel (24 px) of a
  // crossing excused as § 6.2 item 9's stated lag (H16); the desks' relative order and `document.activeElement` unchanged
  // (H18); every walker element a child of the floor stack, and a walker shown in the cab inside the band's cab clip group
  // before the leaves (H8); and that the point under each shown walker is never a walker element (H19). Then, with
  // `aimla-review` standing in its wait on the threshold: its image draws one frame on two ticks (H21), and a click on the
  // leaves' centre — under the waiting walker — opens the cab (H19). Two MEASUREMENTS are reported as facts: whether
  // `insertBefore` of a walker's node restarts its running animations (`reinsert`), and whether a re-inserted walker's
  // `<image>` is missing from the next composited frame (`flicker`, the walker's clip compared before and after).
  // The PLANTs: no `pin()` after a move (H20), the walker drawn in the effects layer (H16), every stack child re-appended
  // per tick (H18), the pointer rule dropped (H19), a walker stepped unconditionally (H21), and the cab element in the
  // effects layer (H8).
  'walker-crossing': row('two walkers cross in depth: the nearer in front, gliding through every stack move, focus kept, the elevator still pressable under a waiting walker', { run: 'crossing', stream: 2000 },
    [
      { plant: [{ file: 'js/floor/painter.js', from: "                if (repin) {\n                    pin(order[i].item.walker.entry);\n                }\n", to: '' }] },
      // The shipped order: walkers appended to the effects layer, above every desk, and never placed among them.
      { plant: [
        { file: 'js/floor/painter.js', from: '{ stack: desks, cab: cabLayer }', to: '{ stack: effectsLayer, cab: cabLayer }' },
        { file: 'js/floor/painter.js', from: '    function stack(layer, depths, deskNode, walkers, now, repin) {\n', to: '    function stack(layer, depths, deskNode, walkers, now, repin) {\n        return;\n' },
      ] },
      { plant: [{ file: 'js/floor/painter.js', from: '                if (walking && canPin()) {\n', to: '                for (const n of [...desks.children]) {\n                    desks.append(n);\n                }\n\n                if (walking && canPin()) {\n' }] },
      { plant: [{ file: 'js/floor/painter.js', from: 'opacity:0;pointer-events:none;animation-name:walker-shown', to: 'opacity:0;animation-name:walker-shown' }] },
      { plant: [{ file: 'js/floor/painter.js', from: 'if (window !== null && !strides(window, now)) {', to: 'if (false) {' }] },
      { plant: [{ file: 'js/floor/painter.js', from: '{ stack: desks, cab: cabLayer }', to: '{ stack: desks, cab: effectsLayer }' }] },
    ],
    (chrome, c) => within(chrome, c, async (s) => {
      const SAMPLE_MS = 15500;
      await s.page('Page.addScriptToEvaluateOnNewDocument', { source: WALKER_SAMPLER(SAMPLE_MS) });
      await s.open('/floor/aimla');
      await s.ev(`(() => { const d = document.querySelector('#floor-drawing [data-key="aimla/aimla-impl-1"]'); d.focus(); window.__walkers.focusAt = performance.now() - window.__walkers.t0; return true; })()`);
      await until(() => s.ev(`performance.now() > ${SAMPLE_MS + 100}`), SAMPLE_MS + 8000, 'the page never reached the end of the sample');
      const all = await s.ev('window.__walkers.samples');
      const focusAt = await s.ev('window.__walkers.focusAt');
      const frames = await s.ev('window.__walkers.frames');
      const d = [];
      const facts = { walks: [], moves: 0, frames: frames.length };
      const walks = byPath(all);
      if (walks.size < 2) d.push(`${walks.size} walker(s) drawn — the crossing measured nothing`);
      for (const [path, xs] of walks) {
        const f = glideDefects(path, xs, d);
        facts.walks.push(f);
        facts.moves += f.moves;
      }
      if (facts.moves === 0) d.push('no walker\'s node moved in the floor stack inside its walk — continuity across a move was not measured');
      // H16, H18, H8, H19 on every frame.
      const LAG = 24 + 3;
      let deskOrder = null;
      for (const fr of frames) {
        if (fr.loose.length > 0) { d.push(`at ${Math.round(fr.t)} ms a walker element is outside the floor stack: ${fr.loose.join(', ')}`); break; }
        if (fr.cabBad) { d.push(`at ${Math.round(fr.t)} ms a walker shown in the cab is not inside the band's cab before the leaves`); break; }
        if (fr.hit.length > 0) { d.push(`at ${Math.round(fr.t)} ms the point under a shown walker is a walker element: ${fr.hit[0]}`); break; }
        // The desks' order is read once the page has settled (focus placed), and must never change after it.
        if (deskOrder === null && fr.t > focusAt) deskOrder = fr.desks.map((x) => x.key).join(' ');
        if (deskOrder !== null && fr.desks.map((x) => x.key).join(' ') !== deskOrder) { d.push(`at ${Math.round(fr.t)} ms the desks were re-ordered: ${fr.desks.map((x) => x.key).join(' ')}`); break; }
        if (fr.t > focusAt + 20 && fr.focus !== 'aimla/aimla-impl-1') { d.push(`at ${Math.round(fr.t)} ms focus is on ${fr.focus}, not the desk it was placed on`); break; }
        const bad = [];
        for (const w of fr.walkers.filter((x) => x.shown)) {
          for (const desk of fr.desks) {
            if (desk.depth === null || Math.abs(w.y - desk.depth) <= LAG) continue;
            if ((w.y >= desk.depth) !== (w.index > desk.index)) bad.push(`walker at y ${w.y.toFixed(1)} is ${w.index > desk.index ? 'after' : 'before'} ${desk.key} (foot line ${desk.depth})`);
          }
          for (const v of fr.walkers.filter((x) => x.shown && x !== w && x.y - w.y > LAG)) {
            if (v.index < w.index) bad.push(`the nearer walker (y ${v.y.toFixed(1)}) is behind the farther (y ${w.y.toFixed(1)})`);
          }
        }
        if (bad.length > 0) { d.push(`at ${Math.round(fr.t)} ms: ${bad[0]}`); break; }
      }
      // H21: the waiting walker stands at one frame; then measure, then H19: the press under it.
      const review = await s.ev(`(() => { const w = [...document.querySelectorAll('#floor-drawing .fx-walker')].find((g) => getComputedStyle(g.parentNode).opacity !== '0' && getComputedStyle(g).offsetDistance === '100%'); return w === undefined ? null : w.querySelector('image')?.getAttribute('href').length ?? null; })()`);
      const hrefs = [];
      for (let i = 0; i < 3; i++) {
        hrefs.push(await s.ev(`(() => { const w = [...document.querySelectorAll('#floor-drawing .fx-walker')].find((g) => getComputedStyle(g.parentNode).opacity !== '0' && getComputedStyle(g).offsetDistance === '100%'); return w === undefined ? null : w.querySelector('image')?.getAttribute('href') ?? null; })()`));
        await sleep(300);
      }
      if (review === null || hrefs.some((h) => h === null)) d.push('no walker stood on the threshold in its wait to read');
      else if (new Set(hrefs).size !== 1) d.push(`the waiting walker drew ${new Set(hrefs).size} different frames on three ticks — it walks in place`);
      facts.reinsert = await s.ev(REINSERT);
      facts.flicker = await flicker(s);
      await s.click('#floor-drawing .elevator-leaf.left');
      const leaf = await s.ev(`(() => { const l = document.querySelector('#floor-drawing .elevator-leaf.left').getBoundingClientRect(); const e = document.elementFromPoint(l.x + l.width / 2, l.y + l.height / 2); return e === null ? null : e.tagName + '.' + (e.getAttribute('class') ?? ''); })()`);
      const opened = await until(async () => (await s.cab()).open, RIDE_DOOR_MS + 1500, 'never').then(() => true, () => false);
      const cab = await s.cab();
      facts.press = { under: leaf, cab: cab.open, opened };
      if (!cab.open) d.push(`a press on the leaves under the waiting walker did not open the cab (the point is ${leaf})`);
      return { defects: d.slice(0, 6), facts };
    })),

  focus: row('inside, focus is on the current floor\'s button, and stays there across a render', {}, { plant: [{ file: PANEL, from: '            const held = new Map([...list.children].map((li) => [li.dataset.stop, li]));', to: "            [...list.children].forEach((li) => li.remove());\n            const held = new Map();" }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await intoTheCab(s);
      await sleep(50);
      const first = (await s.cab()).focus;
      // The run's next heartbeat renders the page; the panel is reconciled on that paint.
      await until(async () => (await s.ev('performance.now()')) > 5600, 8000, 'the clock stood still');
      const later = (await s.cab()).focus;
      return { defects: [first, later].every((f) => f === 'aimla') ? [] : [`focus: ${first} inside, ${later} after a render`], facts: { first, later } };
    })),

  // card#12039: the operator's *I want to see the top of the elevator door (currently the door is too tall and stretches
  // to the top of the screen)*; card#12051: *an elevator is a limited space. It will not take up the entire screen.
  // Elevators are not very wide.* Inside, at every window in CAB_WINDOWS: the cab's whole open front lies in the window,
  // narrower than it and of an elevator's shape (between 0.8 and 0.95 as wide as it is high: § 12's *Cab interior*, 200 × 230 cm,
  // card#12071); it is fitted, not shrunk
  // (it spans the drawing's box on one axis, as a `meet` fit of the cab's space makes it); its back wall is inside it
  // with a side wall showing either side; the door's frame — its head and the lamp over it included — and both leaves
  // lie wholly in the window; and the button popup overlaps no part of the cab.
  'cab-fit': row('inside the cab the whole cab — an elevator-shaped room taller than it is wide, its door\'s head and lamp included — is in the window at a wide, an ultra-wide, a tall, a phone, a small landscape and a small phone window, and the button popup covers none of it', {},
    { plant: [{ file: 'js/floor/cab-paint.js', from: "preserveAspectRatio: 'xMidYMid meet'", to: "preserveAspectRatio: 'none'" }] },
    async (chrome, c) => {
      const d = [];
      const facts = {};
      for (const [name, viewport] of Object.entries(CAB_WINDOWS)) {
        await within(chrome, { ...c, viewport }, async (s) => {
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await buttonsIn(s);
          const g = await cabGeometry(s);
          if (c.plant.length === 0) await s.shot(`cab-${name}-${viewport.w}x${viewport.h}`);
          const at = `${name} ${viewport.w}×${viewport.h}`;
          const m = g.room;
          facts[name] = { room: m, panel: g.panel, cell: g.cell, aspect: m === null ? null : Math.round(((m.r - m.l) / (m.b - m.t)) * 1000) / 1000 };
          if (m === null || m.b - m.t < 1 || g.frame === null || g.back === null) { d.push(`${at}: the cab drew no room, back wall or door frame`); return; }
          if (!inWindow(m, g)) d.push(`${at}: the cab runs out of the window: ${JSON.stringify(m)}`);
          const [mw, mh] = [m.r - m.l, m.b - m.t];
          if (!(mw < 0.9 * g.w)) d.push(`${at}: the cab is ${Math.round(mw)} px wide in a ${g.w} px window — stretched across it`);
          if (!(mw / mh >= 0.8 && mw / mh <= 0.95)) d.push(`${at}: the cab is ${Math.round(mw)} × ${Math.round(mh)} px — not an elevator's shape, taller than it is wide`);
          const spans = mh >= 0.97 * (g.cell.b - g.cell.t) || mw >= 0.97 * (g.cell.r - g.cell.l);
          if (!spans) d.push(`${at}: the cab is drawn small in its box (${JSON.stringify(m)} in ${JSON.stringify(g.cell)})`);
          if (!(g.back.l > m.l && g.back.r < m.r && g.back.t > m.t && g.back.b < m.b)) d.push(`${at}: the back wall is not inside the cab: ${JSON.stringify(g.back)} in ${JSON.stringify(m)}`);
          if (g.sides.length !== 2 || g.sides.some((w) => w === null || w.r - w.l < 0.1 * mw)) d.push(`${at}: the side walls do not show: ${JSON.stringify(g.sides)}`);
          if (!inWindow(g.frame, g)) d.push(`${at}: the door's frame runs out of the window: ${JSON.stringify(g.frame)}`);
          if (!inWindow(g.lamp, g)) d.push(`${at}: the lamp over the door is out of the window: ${JSON.stringify(g.lamp)}`);
          g.leaves.forEach((leaf, i) => { if (!inWindow(leaf, g)) d.push(`${at}: leaf ${i} runs out of the window: ${JSON.stringify(leaf)}`); });
          const p = g.panel;
          if (p.l < m.r && m.l < p.r && p.t < m.b && m.t < p.b) d.push(`${at}: the button popup covers the cab: ${JSON.stringify(p)} over ${JSON.stringify(m)}`);
        });
      }
      return { defects: d, facts };
    }),

  // card#12039: the operator's *a larger popup window drawn in the same theme as the elevator itself*. The popup's body is
  // the house theme's elevator frame colour, a stop's button the building's door and door-edge colours, the current
  // floor's call button the elevator's lamp — each read from the theme module and the sheet the page loads, and the
  // cab's drawn frame and lamp the same two palette colours.
  'cab-palette': row('the button popup is drawn in the house theme\'s elevator palette — its body the frame\'s colour, a stop in the door\'s colours, the current floor lit by the lamp — as the cab\'s drawing is, its walls, ceiling and floor following the frame\'s colour', {},
    { plant: [{ file: MAIN, from: '    painter.cabTheme(cab.dialog);\n', to: '' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await intoTheCab(s);
      const r = await s.ev(`(async () => {
        const { HOUSE_THEME } = await import('/art/floor/themes/index.js');
        const { PALETTE } = await import('/art/floor/themes/' + HOUSE_THEME + '/theme.js');
        const rgb = (hex) => { const n = parseInt(hex.trim().slice(1), 16); return 'rgb(' + (n >> 16) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ')'; };
        const root = getComputedStyle(document.documentElement);
        const cs = (sel) => { const e = document.querySelector(sel); return e === null ? {} : getComputedStyle(e); };
        const stop = cs('#floor-cab-stops li:not(.current) > button');
        return {
          want: { frame: rgb(PALETTE.elevator.frame), lamp: rgb(PALETTE.elevator.lamp), door: rgb(root.getPropertyValue('--door')), edge: rgb(root.getPropertyValue('--door-edge')) },
          got: { panel: cs('#floor-cab-panel').backgroundColor ?? null, stop: stop.backgroundColor ?? null, stopEdge: stop.borderTopColor ?? null,
            lit: cs('#floor-cab-stops li.current .cab-cap').backgroundColor ?? null, frame: cs('#floor-cab-doors .cab-frame').fill ?? null, lamp: cs('#floor-cab-doors .cab-lamp').fill ?? null },
          // card#12051: the room's surfaces as painted, and again with the dialog's frame colour changed — each must follow it;
          // card#12071: the call plate beside the doors too.
          room: (() => {
            const parts = ['.cab-back', '.cab-side.left', '.cab-side.right', '.cab-ceiling', '.cab-floor', '.cab-plate-body'];
            const read = () => Object.fromEntries(parts.map((p) => [p, cs('#floor-cab-doors ' + p).fill ?? null]));
            const dialog = document.getElementById('floor-cab');
            const before = read();
            const held = dialog.style.getPropertyValue('--cab-frame');
            dialog.style.setProperty('--cab-frame', 'rgb(0, 128, 0)');
            const after = read();
            dialog.style.setProperty('--cab-frame', held);
            return { before, after };
          })(),
        }; })()`);
      const d = [];
      for (const [part, fill] of Object.entries(r.room.before)) {
        if (fill === null || fill === r.room.after[part]) d.push(`the cab's ${part} is ${fill}, and does not follow the palette's frame colour (${r.room.after[part]} with it changed)`);
      }
      for (const [got, want, what] of [['panel', 'frame', 'the popup\'s body'], ['stop', 'door', 'a stop\'s button'], ['stopEdge', 'edge', 'a stop\'s edge'],
        ['lit', 'lamp', 'the current floor\'s call button'], ['frame', 'frame', 'the cab\'s drawn frame'], ['lamp', 'lamp', 'the cab\'s drawn lamp']]) {
        if (r.got[got] !== r.want[want]) d.push(`${what} is ${r.got[got]}, not the palette's ${want} ${r.want[want]}`);
      }
      return { defects: d, facts: r };
    })),

  // card#12047: the popup stays legible when the house theme fails to load (§ 9 F14's failed art, its module answered 404),
  // and with it. Each text in the popup holds 4.5:1 on what it sits on and the focus ring 3:1, as the browser computed
  // them; with the theme failed the dialog is not marked themed and the body is the page's own panel colour.
  'cab-fallback': row('with the house theme failed to load, and with it, every text in the button popup holds WCAG AA on what it sits on', {},
    { plant: [{ file: 'css/mezzanine.css', from: '    --cab-body: var(--panel);\n    --cab-ink: var(--ink);\n', to: '    --cab-body: var(--cab-frame);\n    --cab-ink: var(--scene-ink);\n' }] },
    async (chrome, c) => {
      const d = [];
      const facts = {};
      for (const [state, missing] of [['theme failed', [HOUSE_THEME_PATH]], ['theme loaded', []]]) {
        await within(chrome, { ...c, missing, viewport: CAB_WINDOWS.desktop }, async (s) => {
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await buttonsIn(s);
          const r = await cabContrast(s);
          if (c.plant.length === 0) await s.shot(`cab-fallback-${state.replace(' ', '-')}-${CAB_WINDOWS.desktop.w}x${CAB_WINDOWS.desktop.h}`);
          facts[state] = r;
          if (r.themed !== (missing.length === 0)) d.push(`${state}: the dialog is ${r.themed ? '' : 'not '}marked themed`);
          if (r.stops === 0) d.push(`${state}: the popup's body has no light-and-shade stop to read`);
          if (!['current cab-name', 'stop cab-name', 'floor-cab-heading', 'ring'].every((k) => Object.hasOwn(r.ratios, k))) d.push(`${state}: a text was not measured: ${JSON.stringify(r.ratios)}`);
          for (const [what, ratio] of Object.entries(r.ratios)) {
            // A stop that is not current hides *you are here*; its colour is still the plate's text colour, and is held too.
            const floor = what === 'ring' ? 3 : 4.5;
            if (!(ratio >= floor)) d.push(`${state}: ${what} is ${ratio}:1 on what it sits on, under ${floor}:1`);
          }
        });
      }
      return { defects: d, facts };
    }),

  // card#12039: the popup fits a phone. At a common and a small phone window, inside: the popup and every stop's button
  // lie wholly in the window, and neither the cab nor the page scrolls sideways.
  'cab-phone': row('at a phone\'s width the button popup and every stop\'s button are wholly in the window, and nothing scrolls sideways', {},
    { plant: [{ file: 'css/mezzanine.css', from: '        width: min(var(--cab-panel-max), 100%);\n', to: '        width: 460px;\n' }] },
    async (chrome, c) => {
      const d = [];
      const facts = {};
      for (const viewport of [CAB_WINDOWS.phone, { w: 320, h: 568 }]) {
        await within(chrome, { ...c, viewport }, async (s) => {
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await buttonsIn(s);
          const g = await cabGeometry(s);
          const at = `${viewport.w}×${viewport.h}`;
          facts[at] = { panel: g.panel, scroll: g.scroll };
          if (g.buttons.length === 0) d.push(`${at}: the popup drew no stop`);
          if (!inWindow(g.panel, g)) d.push(`${at}: the popup runs out of the window: ${JSON.stringify(g.panel)}`);
          g.buttons.forEach((b, i) => { if (b.l < g.panel.l - 0.5 || b.r > g.panel.r + 0.5 || !(b.l >= -0.5 && b.r <= g.w + 0.5)) d.push(`${at}: stop ${i}'s button runs out of the popup or the window: ${JSON.stringify(b)}`); });
          if (g.scroll.cab > 0 || g.scroll.page > 0) d.push(`${at}: it scrolls sideways: ${JSON.stringify(g.scroll)}`);
        });
      }
      return { defects: d, facts };
    }),

  // card#12052: the operator's *when the elevator door opens, render a 3D perspective of the office outside. On the floor
  // views, when the elevator door opens or closes, we should see a 3D perspective of the elevator cab*. Each door is held
  // HALF OPEN by pausing its own leaf motion at its midpoint — the band's in `opening`, the cab's in `chosen`, each the
  // ride's real motion — and measured as drawn: between the two leaves' inner edges is the open doorway, and through it
  // the layer behind the leaves shows — the cab's room on the band (`.band-cab .cab-back`), the office in the cab
  // (`.cab-beyond .office-back`) — and NOTHING drawn over the leaves' layer crosses it: no seam line left standing in the
  // doorway (the seam is the leaves' own edges). At a desktop and a phone window, and with the house theme failed.
  // With `--shots`, each held door is saved: the cab half and fully open, the floor's elevator zoomed, half open.
  'through-doors': row('through the open doors the office shows from inside the cab and the cab from the floor, each door held half open, and nothing — no seam line — is drawn across the open doorway', {},
    { plant: [
      { file: 'js/floor/painter.js', from: "            node('rect', { x: e.seam + 1, y: e.y, width: e.x + e.w - e.seam - 1, height: e.h, rx: 3, class: 'elevator-leaf right', style: leaves }, control);\n",
        to: "            node('rect', { x: e.seam + 1, y: e.y, width: e.x + e.w - e.seam - 1, height: e.h, rx: 3, class: 'elevator-leaf right', style: leaves }, control);\n            node('line', { x1: e.seam, y1: e.y, x2: e.seam, y2: e.y + e.h, class: 'elevator-seam' }, control);\n" },
      { file: 'js/floor/cab-paint.js', from: "    node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);\n",
        to: "    node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);\n    node('line', { x1: seam, y1: L.y, x2: seam, y2: L.y + L.h, class: 'elevator-seam' }, cab);\n" },
    ] },
    async (chrome, c) => {
      const d = [];
      const facts = {};
      const judge = (where, r) => {
        if (r.error !== undefined) { d.push(`${where}: ${r.error} ${JSON.stringify(r.styles ?? '')}`); return; }
        if (!r.layer) d.push(`${where}: no layer behind the leaves`);
        if (!(r.open > 2)) d.push(`${where}: the doors held half open leave no gap (${r.open} px)`);
        if (!r.shows) d.push(`${where}: nothing of the view behind the leaves shows in the open doorway: ${JSON.stringify(r.seen)} vs ${JSON.stringify(r.gap)}`);
        if (r.over.length > 0) d.push(`${where}: drawn across the open doorway, over the leaves' layer: ${r.over.join(', ')}`);
      };
      for (const [name, viewport, missing] of [['desktop', CAB_WINDOWS.desktop, []], ['phone', CAB_WINDOWS.phone, []], ['no-theme', CAB_WINDOWS.desktop, [HOUSE_THEME_PATH]]]) {
        await within(chrome, { ...c, viewport, missing }, async (s) => {
          const tag = `${name}-${viewport.w}x${viewport.h}`;
          await s.open('/floor/aimla');
          // The band: press, and hold its leaves half open inside `opening`, before the cab fades in.
          await s.click('#floor-drawing [data-key="elevator"]');
          await until(() => s.ev("document.querySelector('#floor-drawing .elevator-leaf')?.getAnimations().length > 0"), 2000, 'the band\'s leaves never moved');
          const band = await hold(s, '#floor-drawing [data-key="elevator"]', '.cab-back', '.elevator-leaf', 0.5);
          // The band's layer behind the leaves is the band's cab, a sibling drawn before the control: read it from the band.
          const bandCab = await s.ev(`(() => { const c = document.querySelector('#floor-drawing .band-cab'); const k = document.querySelector('#floor-drawing [data-key="elevator"]');
            if (c === null || k === null) return null; const b = c.querySelector('.cab-back')?.getBoundingClientRect(); const h = k.querySelector('.elevator-hit').getBoundingClientRect();
            return { before: (c.compareDocumentPosition(k) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0, back: b === undefined ? null : { l: b.left, t: b.top, r: b.right, b: b.bottom }, hit: { x: h.left, y: h.top, w: h.width, h: h.height } }; })()`);
          if (c.plant.length === 0 && bandCab !== null) {
            const m = 10;
            await s.shot(`floor-elevator-half-open-${tag}`, { x: bandCab.hit.x - m, y: bandCab.hit.y - m, width: bandCab.hit.w + 2 * m, height: bandCab.hit.h + 2 * m, scale: 4 });
          }
          const bandShows = bandCab?.back !== null && bandCab?.back !== undefined && band.gap !== undefined
            && bandCab.back.l < band.gap.r && bandCab.back.r > band.gap.l;
          judge(`${tag} band`, { ...band, layer: bandCab !== null && bandCab.before, shows: bandShows, seen: bandCab?.back ?? null });
          // The cab: into it, a stop pressed, and its leaves held half open in `chosen`, then fully open.
          await until(async () => (await s.cab()).open, RIDE_DOOR_MS + 1500, 'the elevator opened no cab');
          await until(() => s.ev("!document.getElementById('floor-cab-panel').inert"), RIDE_DOOR_MS + DOOR_PHASE_MS + 1000, 'the cab never reached inside');
          await buttonsIn(s);
          await s.click('#floor-cab-stops li[data-stop="zeta"] button');
          await until(() => s.ev("document.querySelector('#floor-cab-doors .elevator-leaf')?.getAnimations().length > 0"), 2000, 'the cab\'s leaves never moved');
          const cab = await hold(s, '#floor-cab-doors', '.cab-beyond .office-back', '.cab-beyond', 0.5);
          if (c.plant.length === 0) await s.shot(`cab-half-open-${tag}`);
          judge(`${tag} cab`, cab);
          if (c.plant.length === 0) { await hold(s, '#floor-cab-doors', '.cab-beyond .office-back', '.cab-beyond', 1); await s.shot(`cab-open-${tag}`); }
          facts[tag] = { band: { open: band.open, over: band.over }, cab: { open: cab.open, over: cab.over } };
        });
      }
      return { defects: d, facts };
    }),

  // card#12052: PR #342's review — a browser without `color-mix()` must still see coherent walls; card#12070: and the button
  // popup must keep its colours. This headless Chromium has `color-mix()`, so the row serves the painter and the sheet as a
  // browser without it reads them (`NO_MIX`): every mixed surface — the cab's, the office's, the band's cab's — must then be painted (not `none`, not the
  // unpainted black) by its plain fallback; and in the popup, with the house theme and with it failed, the current
  // floor's plate and every call button are filled, every stop's button keeps its shadow, the body its rim, and every
  // text holds WCAG AA on what it sits on. Two plants, one per surface: the painter's fallback removed, and the call
  // buttons' plain fill removed from the sheet, leaving only their mix.
  'cab-no-mix': row('with color-mix() unavailable, the cab, the office through its doors and the cab behind the floor\'s leaves are each still painted, every surface in a theme colour, and the button popup keeps its fills, shadows, rim and WCAG AA text with the theme and without it', { plant: NO_MIX },
    [
      { plant: [{ file: 'js/floor/cab-paint.js', from: '${unmixed(MIXED)}@supports', to: '@supports' }] },
      { plant: [{ file: 'css/mezzanine.css', from: '    background: var(--door);\n    box-shadow: inset 0 2px 2px rgb(255 255 255 / 0.6);\n}', to: '    box-shadow: inset 0 2px 2px rgb(255 255 255 / 0.6);\n}' }] },
    ],
    async (chrome, c) => {
      const d = [];
      const facts = {};
      const planted = c.plant.length > NO_MIX.length;
      for (const [state, missing] of [['theme loaded', []], ['theme failed', [HOUSE_THEME_PATH]]]) {
        await within(chrome, { ...c, missing, viewport: CAB_WINDOWS.desktop }, async (s) => {
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await buttonsIn(s);
          if (!planted) await s.shot(`cab-no-mix-${state.replace(' ', '-')}-${CAB_WINDOWS.desktop.w}x${CAB_WINDOWS.desktop.h}`);
          const r = await s.ev(`(() => {
            const fill = (sel) => [...document.querySelectorAll(sel)].map((e) => getComputedStyle(e).fill);
            const cs = (e) => getComputedStyle(e);
            const panel = document.getElementById('floor-cab-panel');
            return {
              fills: Object.fromEntries(['#floor-cab-doors .cab-back', '#floor-cab-doors .cab-side', '#floor-cab-doors .cab-ceiling', '#floor-cab-doors .cab-floor', '#floor-cab-doors .cab-plate-body',
                '#floor-cab-doors .office-floor', '#floor-cab-doors .office-wall', '#floor-cab-doors .office-back', '#floor-cab-doors .office-ceiling', '#floor-cab-doors .office-desk-top',
                '#floor-drawing .band-cab .cab-back', '#floor-drawing .band-cab .cab-side', '#floor-drawing .band-cab .cab-floor'].map((sel) => [sel, fill(sel)])),
              popup: {
                plates: [...document.querySelectorAll('#floor-cab-stops li > button')].map((b) => ({ current: b.parentElement.classList.contains('current'), background: cs(b).backgroundColor, shadow: cs(b).boxShadow })),
                caps: [...document.querySelectorAll('#floor-cab-stops .cab-cap')].map((e) => cs(e).backgroundColor),
                rim: { style: cs(panel).borderTopStyle, colour: cs(panel).borderTopColor },
                lamp: getComputedStyle(panel.querySelector('h2'), '::before').boxShadow,
              },
            };
          })()`);
          const contrast = await cabContrast(s);
          facts[state] = { ...r, ratios: contrast.ratios };
          if (state === 'theme loaded') {
            for (const [sel, fills] of Object.entries(r.fills)) {
              if (fills.length === 0) d.push(`${sel}: not drawn`);
              for (const f of fills) if (f === 'none' || f === 'rgb(0, 0, 0)') d.push(`${sel}: painted ${f} — left unpainted with no color-mix()`);
            }
          }
          const clear = (colour) => colour === 'transparent' || /^rgba\(.*, 0\)$/.test(colour);
          const p = r.popup;
          if (!p.plates.some((b) => b.current)) d.push(`${state}: the popup drew no current floor`);
          for (const b of p.plates) {
            if (clear(b.background)) d.push(`${state}: ${b.current ? 'the current floor\'s' : 'a stop\'s'} plate is unfilled (${b.background}) with no color-mix()`);
            if (b.shadow === 'none') d.push(`${state}: ${b.current ? 'the current floor\'s' : 'a stop\'s'} button lost its shadow with no color-mix()`);
          }
          if (p.caps.length === 0 || p.caps.some(clear)) d.push(`${state}: a call button is unfilled with no color-mix(): ${JSON.stringify(p.caps)}`);
          if (p.rim.style === 'none' || clear(p.rim.colour)) d.push(`${state}: the popup lost its rim with no color-mix(): ${JSON.stringify(p.rim)}`);
          if (p.lamp === 'none') d.push(`${state}: the popup's lamp lost its rim with no color-mix()`);
          for (const [what, ratio] of Object.entries(contrast.ratios)) {
            const floor = what === 'ring' ? 3 : 4.5;
            if (!(ratio >= floor)) d.push(`${state}: ${what} is ${ratio}:1 on what it sits on with no color-mix(), under ${floor}:1`);
          }
        });
      }
      return { defects: d, facts };
    }),

  'one-escape': row('one Escape in entering leaves the cab open', {}, { plant: [{ file: PERFORMER, from: '        event.preventDefault();\n        act(screen().escape(event.cancelable));', to: '        act(screen().escape(event.cancelable));' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await s.click('#floor-drawing [data-key="elevator"]');
      await until(async () => (await s.cab()).open, 3000, 'the cab never opened');
      await sleep(100);
      await s.escape();
      await sleep(100);
      const cab = await s.cab();
      return { defects: cab.open ? [] : ['one Escape closed the cab'], facts: { open: cab.open } };
    })),

  'two-escapes-entering': row('two Escapes in entering end the ride: the cab gone, focus on the elevator, the leaves at rest, the elevator answering again (M5-1)', {},
    { plant: [{ file: PERFORMER, from: '        act(screen().escape(event.cancelable));', to: '        act(screen().escape(true));' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await s.click('#floor-drawing [data-key="elevator"]');
      await until(async () => (await s.cab()).open, 3000, 'the cab never opened');
      await sleep(100);
      await s.escape();
      await sleep(30);
      await s.escape();
      await sleep(100);
      const ended = await s.cab();
      await sleep(900);
      await s.click('#floor-drawing [data-key="elevator"]');
      const again = await until(async () => (await s.cab()).open, RIDE_DOOR_MS + 1000, '').then(() => true, () => false);
      const d = [];
      if (ended.open || ended.doors !== 0) d.push(`the cab is still drawn: ${JSON.stringify(ended)}`);
      if (ended.disabled !== null) d.push('the elevator still says a ride is in flight');
      if (ended.focus !== 'elevator') d.push(`focus is on ${ended.focus}`);
      if (ended.leaves.some((l) => l !== null)) d.push(`the leaves are not at rest: ${JSON.stringify(ended.leaves)}`);
      if (!again) d.push('the elevator refused the next press');
      return { defects: d, facts: { ended, again } };
    })),

  'two-escapes-committed': row('two Escapes in chosen, and in leaving, with the destination 1 200 ms late: the destination is a cut with its own doors, never A\'s floor held over it (M5-1)',
    { doc: { '/floor/zeta': 1200 } }, { plant: [{ file: RULE, from: "    if (ride?.phase === 'leaving' && ride.cab === 'open') {", to: "    if (ride?.phase === 'leaving') {" }] },
    async (chrome, c) => {
      const d = [];
      const facts = {};
      for (const [label, wait] of [['chosen', 100], ['leaving', DOOR_PHASE_MS + 150]]) {
        await within(chrome, c, async (s) => {
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await s.click('#floor-cab-stops li[data-stop="zeta"] button');
          await sleep(wait);
          await s.escape();
          await sleep(30);
          await s.escape();
          await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, 'the ride never committed');
          const r = await arrivedRecord(s);
          facts[label] = { reveals: r.reveals, classes: r.classes, leaves: r.scene?.leaves };
          d.push(...cut(r, label));
          if (!(r.scene?.leaves ?? []).some((l) => String(l).includes('leaf-close'))) d.push(`${label}: the destination's leaves did not close on its first scene: ${JSON.stringify(r.scene?.leaves)}`);
        });
      }
      return { defects: d, facts };
    }),

  race: row('a second Escape within a frame or so of the commit, either side: the destination is still a cut (m6-1); with no second Escape the cab is held',
    { doc: { '/floor/zeta': 'gate' } }, { plant: [{ file: PERFORMER, from: '        outbound = skipUnlessCabLeaving(outbound, lastRide());\n', to: '' }] },
    async (chrome, c) => {
      const d = [];
      const facts = [];
      for (const off of [null, -5, 0, 1, 3, 5, 7, 10, 15]) {
        await within(chrome, c, async (s, srv) => {
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await s.click('#floor-cab-stops li[data-stop="zeta"] button');
          await sleep(DOOR_PHASE_MS + 150);                      // leaving: the navigation pending, held by the server
          await s.escape();
          await sleep(40);
          const release = () => fetch(`${srv.base}/release`);
          if (off === null) await release();
          else if (off >= 0) { await release(); if (off > 0) await sleep(off); await s.escape(); } else { await s.escape(); await sleep(-off); await release(); }
          await until(async () => (await s.ev('location.pathname')) === '/floor/zeta' && (await s.record()).reveals.length > 0, 8000, 'the ride never committed');
          await sleep(100);
          const r = await s.record();
          const held = r.classes.some(([, k]) => k.includes('ride-holding'));
          facts.push({ off, held });
          if (off === null && !held) d.push('CONTROL: with one Escape the destination did not hold the cab — the window measured nothing');
          if (off !== null && held) d.push(`a second Escape ${off} ms from the release: the destination held A's frame`);
        });
      }
      return { defects: d, facts };
    }),

  stay: row('Escape inside, with a desk\'s drill-down open: the cab closes, the URL and the drill-down stay, the leaves close once, focus on the elevator after the close (m3)', {},
    { plant: [{ file: PERFORMER, from: "            fade(cab, 1, 0, ride.fade_ms).then(() => {\n                if (cab.open) {\n                    cab.close();\n                }\n\n                elevator()?.focus({ preventScroll: true });\n            });",
      to: "            elevator()?.focus({ preventScroll: true });\n            fade(cab, 1, 0, ride.fade_ms).then(() => {\n                if (cab.open) {\n                    cab.close();\n                }\n            });" }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla/aimla-pm');
      await until(async () => (await s.cab()).panel, 5000, 'the drill-down never opened');
      await intoTheCab(s);
      await s.ev("document.getElementById('floor-cab').addEventListener('close', () => { window.__ride.closeFocus = document.activeElement?.closest('[data-key]')?.getAttribute('data-key') ?? document.activeElement?.tagName ?? null; }, { once: true })");
      await s.escape();
      await until(async () => !(await s.cab()).open, 3000, 'the cab never closed');
      const closing = await s.cab();
      await sleep(DOOR_PHASE_MS + 150);
      const after = await s.cab();
      const r = await s.record();
      const d = [];
      if (after.path !== '/floor/aimla/aimla-pm') d.push(`the URL moved to ${after.path}`);
      if (!after.panel) d.push('the drill-down closed');
      if (!closing.leaves.every((l) => String(l).startsWith('animation:leaf-close'))) d.push(`the leaves did not close: ${JSON.stringify(closing.leaves)}`);
      if (r.closeFocus !== 'elevator' || after.focus !== 'elevator') d.push(`focus at the close was ${r.closeFocus}, after ${after.focus}`);
      return { defects: d, facts: { closing, after, closeFocus: r.closeFocus } };
    })),

  modal: row('inside, the cab is modal: the page under it does not take a pointer (m5)', {}, { plant: [{ file: PERFORMER, from: '            cab.showModal();', to: '            cab.show();' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await intoTheCab(s);
      const r = await s.ev(`(() => { const a = document.querySelector('a.floor-home').getBoundingClientRect(); const hit = document.elementFromPoint(a.x + a.width / 2, a.y + a.height / 2);
        return { modal: document.getElementById('floor-cab').matches(':modal'), hit: hit?.closest('#floor-cab') !== null }; })()`);
      // The design wrote this row as `a.floor-home.closest(':modal, [inert]')` — which no browser answers true: a
      // modal dialog makes the page under it inert without an attribute, and the link is not inside the dialog.
      return { defects: r.modal && r.hit ? [] : [`the cab is not modal over the page: ${JSON.stringify(r)}`], facts: r };
    })),

  'pre-layout': row('the pre-layout frame under the hold: at the first chrome change the cab is still held, nothing is stated, no room is listed', { api: { '/api/building': 600 } },
    { plant: [{ file: MAIN, from: '    if (frame.scene !== null) {\n        revealed(html);', to: '    if (frame.scene !== null || frame.statement !== null || frame.floor === null) {\n        revealed(html);' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      const r = await arrivedRecord(s);
      const change = r.chrome.find((x) => !x.initial) ?? null;
      const d = [];
      if (change === null) d.push('the destination\'s chrome never changed');
      else {
        if (!change.cls.includes('ride-holding')) d.push(`at the first chrome change (${change.at} ms) the cab was not held: ${change.cls}`);
        if (change.stmt !== null || change.rooms !== 0) d.push(`the first chrome change stated something: ${JSON.stringify(change)}`);
      }
      return { defects: d, facts: { change, scene: r.scene?.at } };
    })),

  'layout-fails': row('a layout that fails (503): its statement drawn under the hold, the cab fading into it at the bound, no elevator, no door motion', { layout: { '/floor/zeta': 503 } },
    { plant: [{ file: MAIN, from: '    if (frame.scene !== null) {\n        revealed(html);', to: '    if (frame.scene !== null || frame.statement !== null) {\n        revealed(html);' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await intoTheCab(s);
      await s.click('#floor-cab-stops li[data-stop="zeta"] button');
      await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, 'the ride never committed');
      await sleep(CAB_HOLD_MS + 600);
      const r = await s.record();
      const t0 = Number(r.arrivalAt);
      const arrived = r.classes.find(([, k]) => k.includes('ride-arrived'))?.[0] ?? null;
      const stated = r.chrome.find((x) => x.stmt !== null) ?? null;
      const bits = await s.ev("({ elevator: document.querySelector('[data-key=\"elevator\"]') !== null, svg: document.querySelector('#floor-drawing svg') !== null })");
      const d = [];
      if (arrived === null || Math.abs(arrived - (t0 + CAB_HOLD_MS)) > 40) d.push(`the hold ended at ${arrived} ms, not at the bound ${t0 + CAB_HOLD_MS}`);
      if (stated === null || !stated.cls.includes('ride-holding')) d.push(`the statement was not drawn under the hold: ${JSON.stringify(stated)}`);
      if (bits.elevator || bits.svg) d.push(`a failed layout drew ${JSON.stringify(bits)}`);
      return { defects: d, facts: { t0, arrived, stated: stated?.at } };
    })),

  pending: row('a layout pending past the bound (2 200 ms): the cab fades into the waiting chrome at the bound, and the room drawn later has its leaves at rest',
    { api: { '/api/building': 2200 } }, { plant: [{ file: RIDE, from: ' && !this.#endsRide(frame) && now <= expiresAt) {', to: ' && !this.#endsRide(frame)) {' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      await until(async () => (await s.record()).scene !== null, 8000, 'the destination never drew');
      await sleep(200);
      const r = await s.record();
      const t0 = Number(r.arrivalAt);
      const arrived = r.classes.find(([, k]) => k.includes('ride-arrived'))?.[0] ?? null;
      const atBound = [...r.chrome].reverse().find((x) => x.at <= (arrived ?? 0)) ?? null;
      const d = [];
      if (arrived === null || Math.abs(arrived - (t0 + CAB_HOLD_MS)) > 40) d.push(`the hold ended at ${arrived}, not the bound ${t0 + CAB_HOLD_MS}`);
      if (atBound === null || atBound.stmt !== null || atBound.rooms !== 0) d.push(`at the bound the chrome said ${JSON.stringify(atBound)}`);
      if (r.scene.at <= (arrived ?? Infinity)) d.push('the room was drawn inside the bound — the row measured nothing');
      if ((r.scene.leaves ?? []).some((l) => l !== null)) d.push(`a room drawn after the bound moved its leaves: ${JSON.stringify(r.scene.leaves)}`);
      return { defects: d, facts: { t0, arrived, scene: r.scene.at, leaves: r.scene.leaves } };
    })),

  'late-modules': row('the module graph 600 ms late, the layout past the bound: the head script still sees the transition and holds before any module has run',
    // PLANT: the listener moved into the module graph — the head script no longer registers it, and `floor/main.js`
    // (twelve imports deep, each 600 ms late) registers it when it runs.
    { delay: 600, api: { '/api/building': 2200 } }, { plant: [
      { file: 'page', from: "addEventListener('pagereveal', function (event) {", to: 'window.__rideReveal = (function (event) {' },
      { file: MAIN, from: "const root = el('floor');\n", to: "addEventListener('pagereveal', window.__rideReveal);\nconst root = el('floor');\n" }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      await until(async () => (await s.record()).scene !== null, 10000, 'the destination never drew');
      const r = await s.record();
      const held = r.classes.find(([, k]) => k.includes('ride-holding'))?.[0] ?? null;
      const moduleRan = r.chrome.find((x) => !x.initial)?.at ?? null;
      const d = [];
      if (held === null) d.push('the destination never held the cab');
      else if (moduleRan !== null && held >= moduleRan) d.push(`the hold began at ${held} ms, after the module's first paint at ${moduleRan}`);
      if ((r.scene.leaves ?? []).some((l) => l !== null)) d.push(`the room drawn after the bound moved its leaves: ${JSON.stringify(r.scene.leaves)}`);
      return { defects: d, facts: { held, moduleRan, scene: r.scene.at } };
    })),

  'late-commit': row('the destination\'s document 2 500 ms late: A tears down at the bound, and the late commit is a cut on B with its own doors (M4-1)',
    { doc: { '/floor/zeta': 2500 } }, { plant: [{ file: PERFORMER, from: "    window.addEventListener('pageswap', (event) => {\n        outbound = skipUnlessCabLeaving(event.viewTransition, lastRide());\n    });", to: '' }] },
    (chrome, c) => within(chrome, c, async (s, srv) => {
      const pressed = await pressStop(s, 'zeta');
      await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, 'the ride never committed');
      const r = await arrivedRecord(s);
      const d = [...cut(r, 'the late commit'), ...tornDown(srv, pressed)];
      if (!(r.scene?.leaves ?? []).some((l) => String(l).includes('leaf-close'))) d.push(`B's leaves did not close on its first scene: ${JSON.stringify(r.scene?.leaves)}`);
      return { defects: d, facts: { beacons: srv.beacons, reveals: r.reveals } };
    })),

  'mid-hold': row('300 ms into the hold the window shows the cab — not A\'s bare floor, not the destination; after the fade it shows the destination', { delay: 600 },
    { plant: [{ file: PERFORMER, from: "    window.addEventListener('pageswap', (event) => {\n", to: "    window.addEventListener('pageswap', () => cab.close());\n    window.addEventListener('pageswap', (event) => {\n" }] },
    (chrome, c) => holdPixels(chrome, c)),

  'new-hidden': row('the destination\'s new image is hidden during the hold', { delay: 600 },
    { plant: [{ file: 'page', from: '        html.ride-holding::view-transition-new(root) { animation: ride-hidden var(--ride-hold-max) linear; }\n', to: '' }] },
    (chrome, c) => holdPixels(chrome, c)),

  'arrival-doors': row('on the destination the leaves are drawn open, close after the fade, and the ride is over one fade and one ride door motion after the arrival', {},
    { plant: [{ file: MAIN, from: '    screen.arriveByRide(rideArrival);\n', to: '' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      const r = await arrivedRecord(s);
      const arrived = r.classes.find(([, k]) => k.includes('ride-arrived'))?.[0] ?? null;
      const leaf = await s.ev("(async () => { const a = document.querySelector('#floor-drawing .elevator-leaf')?.getAnimations()[0]; if (a === undefined) return null; await a.ready; return { name: a.animationName, start: a.startTime + a.effect.getTiming().delay }; })()");
      await sleep(DOOR_PHASE_MS + 150);
      const after = await s.cab();
      const d = [];
      if (!(r.scene?.leaves ?? []).every((l) => String(l).includes('leaf-close'))) d.push(`the first scene's leaves: ${JSON.stringify(r.scene?.leaves)}`);
      if (arrived !== null && leaf !== null && leaf.start < arrived + FADE_MS - 20) d.push(`the doors started closing ${Math.round(leaf.start - arrived)} ms after the fade began — before it ended`);
      if (after.disabled !== null) d.push('the arrival was still in flight one fade and one ride door motion later');
      return { defects: d, facts: { arrived, leaf, after: after.disabled } };
    })),

  // card#12113: the floor's ride to the lobby arrives on the lobby's own drawn elevator — the room drawn, the hold ended
  // on its first drawn room, the room's leaves drawn open and closing.
  lobby: row('press Lobby: the lobby is held and revealed at its first drawn room, the fragment stripped, the room\'s leaves drawn open and closing', {},
    [{ plant: [{ file: 'page:dashboard', from: ' data-ride-reveals>', to: '>' }] },
      { plant: [{ file: 'js/lobby/main.js', from: '    screen.arriveByRide(rideArrival);\n', to: '' }] }],
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, '');
      await until(async () => (await s.record()).scene !== null, 8000, 'the lobby never drew its room');
      await sleep(300);
      const r = await s.record();
      const held = r.classes.find(([, k]) => k.includes('ride-holding'))?.[0] ?? null;
      const arrived = r.classes.find(([, k]) => k.includes('ride-arrived'))?.[0] ?? null;
      const t0 = Number(r.arrivalAt);
      const d = [];
      if (held === null || arrived === null) d.push(`the lobby was not held and revealed: ${JSON.stringify(r.classes)}`);
      if (r.hash !== '') d.push(`the fragment was not stripped: ${r.hash}`);
      if (r.scene.leaves.length !== 2 || !r.scene.leaves.every((l) => typeof l === 'string' && l.includes('leaf-close'))) d.push(`the room's first drawn leaves are not open and closing: ${JSON.stringify(r.scene.leaves)}`);
      // Recorded, not judged: the real lobby's first-scene time, and which exit ended its hold (m5-5).
      return { defects: d, facts: { t0, scene: r.scene.at - t0, arrived: arrived === null ? null : arrived - t0, exit: arrived !== null && Math.abs(arrived - r.scene.at) <= 20 ? 'scene' : 'bound', leaves: r.scene.leaves } };
    })),

  // card#12113 (AT-D3-28): the lobby's elevator rides as the floor's does — pressed on the drawn room, the lobby's own cab
  // opened, a floor chosen, and the floor arriving by a ride, held and revealed at its first scene with its leaves moving.
  'lobby-ride': row('from the lobby, press the room\'s elevator and a floor: the lobby\'s cab opens, and the floor arrives by a ride, held and revealed at its first scene, its leaves drawn open and closing', {},
    { plant: [{ file: 'js/lobby/main.js', from: 'roomDrawing(document, () => cab.act(screen.ride()))', to: 'roomDrawing(document, () => {})' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/dashboard');
      await s.click('#lobby-room [data-key="elevator"]');
      await until(() => s.ev("document.getElementById('lobby-cab').open"), RIDE_DOOR_MS + 1000, 'the lobby\'s elevator opened no cab');
      await until(() => s.ev("!document.getElementById('lobby-cab-panel').inert"), RIDE_DOOR_MS + DOOR_PHASE_MS + 1000, 'the lobby\'s cab never reached inside');
      const stops = await s.ev("[...document.querySelectorAll('#lobby-cab-stops li')].map((li) => [li.dataset.stop, li.classList.contains('current')])");
      await buttonsIn(s, 'lobby');
      await s.click('#lobby-cab-stops li[data-stop="zeta"] button');
      await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 15000, 'the ride never reached /floor/zeta');
      const r = await arrivedRecord(s);
      const d = [];
      if (stops.at(-1)?.[0] !== '' || stops.at(-1)?.[1] !== true || stops.filter(([, cur]) => cur).length !== 1) d.push(`the lobby's cab does not end on *Lobby*, current alone: ${JSON.stringify(stops)}`);
      if (!r.classes.some(([, k]) => k.includes('ride-holding')) || !r.classes.some(([, k]) => k.includes('ride-arrived'))) d.push(`the floor was not held and revealed: ${JSON.stringify(r.classes)}`);
      if (r.hash !== '') d.push(`the fragment was not stripped: ${r.hash}`);
      if (!(r.scene?.leaves ?? []).some((l) => typeof l === 'string' && l.includes('leaf-close'))) d.push(`the floor's leaves were not drawn open and closing: ${JSON.stringify(r.scene?.leaves)}`);
      return { defects: d, facts: { stops, classes: r.classes, leaves: r.scene?.leaves } };
    })),

  // card#12113 (AT-D3-28): the doors held half open on each page show the view the stop owes — on the lobby, its room's
  // leaves open on the cab behind them, the cab's doors closing in `entering` on the lobby (the page's own stop) and
  // opening in `chosen` on the office (a floor's); on a floor, the cab's doors opening on *Lobby* show the lobby.
  'lobby-doors': row('the doors held half open on each page show the view the stop owes: on the lobby, the cab behind the room\'s leaves, the lobby in entering and the office for a floor; on a floor, the lobby for *Lobby*', {},
    { plant: [{ file: RIDE, from: "beyond: stop === null ? 'lobby' : 'office'", to: "beyond: stop === null ? 'office' : 'lobby'" }] },
    async (chrome, c) => {
      const d = [];
      const facts = {};
      const shows = (where, r) => {
        if (r.error !== undefined) { d.push(`${where}: ${r.error}`); return false; }
        if (!(r.open > 2)) d.push(`${where}: the doors held half open leave no gap (${r.open} px)`);
        if (!r.shows) d.push(`${where}: the view the stop owes does not show in the open doorway: ${JSON.stringify(r.seen)} vs ${JSON.stringify(r.gap)}`);
        if (r.over.length > 0) d.push(`${where}: drawn across the open doorway: ${r.over.join(', ')}`);
        return r.shows === true;
      };
      for (const [name, viewport] of [['desktop', CAB_WINDOWS.desktop], ['phone', CAB_WINDOWS.phone]]) {
        const tag = `${name}-${viewport.w}x${viewport.h}`;
        await within(chrome, { ...c, viewport }, async (s) => {
          await s.open('/dashboard');
          // The lobby as it stands, for a person to look at (recorded, never judged).
          if (c.plant.length === 0) await s.shot(`lobby-page-${tag}`);
          // The room's leaves, half open in `opening`: the cab behind them, from the landing.
          await s.click('#lobby-room [data-key="elevator"]');
          await until(() => s.ev("document.querySelector('#lobby-room .elevator-leaf')?.getAnimations().length > 0"), 2000, 'the room\'s leaves never moved');
          const room = await hold(s, '#lobby-room [data-key="elevator"]', '.elevator-hit', '.elevator-leaf', 0.5);
          const cabBehind = await s.ev(`(() => { const b = document.querySelector('#lobby-room .band-cab .cab-back')?.getBoundingClientRect(); const h = document.querySelector('#lobby-room .elevator-hit').getBoundingClientRect();
            return { back: b === undefined ? null : { l: b.left, t: b.top, r: b.right, b: b.bottom }, hit: { x: h.left, y: h.top, w: h.width, h: h.height } }; })()`);
          if (c.plant.length === 0) await s.shot(`lobby-elevator-half-open-${tag}`, { x: cabBehind.hit.x - 10, y: cabBehind.hit.y - 10, width: cabBehind.hit.w + 20, height: cabBehind.hit.h + 20, scale: 4 });
          shows(`${tag} the lobby's room`, { ...room, over: [], shows: cabBehind.back !== null && room.gap !== undefined && cabBehind.back.l < room.gap.r && cabBehind.back.r > room.gap.l, seen: cabBehind.back });
          // The cab's doors closing in `entering`: the page's own stop, the lobby.
          await until(() => s.ev("document.getElementById('lobby-cab').open && document.querySelector('#lobby-cab-doors .elevator-leaf')?.getAnimations().length > 0"), RIDE_DOOR_MS + 1500, 'the lobby\'s cab never closed its doors');
          const entering = await hold(s, '#lobby-cab-doors', '.cab-beyond .lobby-view-back', '.cab-beyond', 0.5);
          if (c.plant.length === 0) await s.shot(`lobby-cab-entering-half-open-${tag}`);
          shows(`${tag} the lobby's cab in entering`, entering);
          await until(() => s.ev("!document.getElementById('lobby-cab-panel').inert"), RIDE_DOOR_MS + DOOR_PHASE_MS + 2000, 'the lobby\'s cab never reached inside');
          await buttonsIn(s, 'lobby');
          // A floor chosen: the cab's doors open on the office.
          await s.click('#lobby-cab-stops li[data-stop="zeta"] button');
          await until(() => s.ev("document.querySelector('#lobby-cab-doors .elevator-leaf')?.getAnimations().length > 0"), 2000, 'the lobby\'s cab never opened its doors');
          const chosen = await hold(s, '#lobby-cab-doors', '.cab-beyond .office-back', '.cab-beyond', 0.5);
          if (c.plant.length === 0) await s.shot(`lobby-cab-chosen-half-open-${tag}`);
          shows(`${tag} the lobby's cab opening on a floor`, chosen);
          facts[`${tag} lobby`] = { room: room.open, entering: entering.open, chosen: chosen.open };
        });
        await within(chrome, { ...c, viewport }, async (s) => {
          // On a floor, *Lobby* chosen: the cab's doors open on the lobby.
          await s.open('/floor/aimla');
          await intoTheCab(s);
          await buttonsIn(s);
          await s.click('#floor-cab-stops li[data-stop=""] button');
          await until(() => s.ev("document.querySelector('#floor-cab-doors .elevator-leaf')?.getAnimations().length > 0"), 2000, 'the floor\'s cab never opened its doors');
          const toLobby = await hold(s, '#floor-cab-doors', '.cab-beyond .lobby-view-back', '.cab-beyond', 0.5);
          if (c.plant.length === 0) await s.shot(`floor-cab-to-lobby-half-open-${tag}`);
          shows(`${tag} the floor's cab opening on the lobby`, toLobby);
          facts[`${tag} floor`] = { toLobby: toLobby.open };
        });
      }
      return { defects: d, facts };
    }),

  links: row('a plaque link into a floor, and a console link: a cut — the transition skipped, no ride-* class, the leaves at rest', {},
    { plant: [{ file: 'page', from: '                event.viewTransition.skipTransition();\n', to: '' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      const d = [];
      await s.open('/dashboard');
      // The plaque may sit past the directory's fold: the list is scrolled to it, as the keyboard's focus would.
      await s.ev(`document.querySelector('#lobby-floors li[data-floor="zeta"] a').scrollIntoView({ block: 'nearest' })`);
      await s.click('#lobby-floors li[data-floor="zeta"] a');
      await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, 'the plaque link never arrived');
      const plate = await arrivedRecord(s);
      // card#12113: the lobby is a ride's departing page now, so its own `pageswap` rule skips a plaque link's outbound
      // transition before the floor sees one (as a floor's own links are skipped); the destination's skip is measured on
      // the console link below, whose page has no such rule.
      d.push(...cut(plate, 'a plaque link'));
      if ((plate.scene?.leaves ?? []).some((l) => l !== null)) d.push(`a plaque link moved the leaves: ${JSON.stringify(plate.scene?.leaves)}`);
      await s.page('Page.navigate', { url: `${await s.ev('location.origin')}/admin` });
      await until(async () => (await s.ev("document.readyState === 'complete'")), 5000, 'the console never loaded');
      await s.ev('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))');
      await s.ev("location.assign('/admin/other')");
      await until(async () => (await s.ev('location.pathname')) === '/admin/other' && (await s.ev("document.readyState === 'complete'")), 5000, 'the console link never arrived');
      await sleep(400);
      const console_ = await s.record();
      d.push(...cut(console_, 'a console link'));
      if (console_.reveals[0]?.vt !== true) d.push('the console link carried no transition to skip — the row measured nothing');
      return { defects: d, facts: { plate: plate.reveals, console: console_.reveals } };
    })),

  // ⚠ The design placed the strip inside the reveal on a measurement (its r4: a synchronous strip in the head left the
  // inbound transition null). This tool does NOT reproduce that on the headless shell it runs: with the strip moved
  // before the reveal the transition still held, three runs of three (card#11795). So the placement is not what this
  // row can check; what it checks is that the fragment is gone by the first moment the cab is held.
  strip: row('the fragment is stripped by the time the cab is held: the hold is reached and the hash is already gone at its first read', {},
    { plant: [{ file: 'page', from: "                history.replaceState(null, '', location.pathname + location.search);\n", to: '' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      const r = await arrivedRecord(s);
      const held = r.classes.find(([, k]) => k.includes('ride-holding')) ?? null;
      return { defects: held === null ? ['the destination never held'] : (held[2] !== '' ? [`the hash at the first hold was ${held[2]}`] : []), facts: { held } };
    })),

  'opt-in': row('the opt-in where the parser has it: three rides with the static files 50 ms late, each held', { delay: 50 },
    { plant: [{ file: 'page', from: '        @view-transition { navigation: auto; }\n', to: '' }, { file: 'css/mezzanine.css', from: ':root {', to: '@view-transition { navigation: auto; }\n\n:root {' }] },
    async (chrome, c) => {
      const held = [];
      for (let i = 0; i < 3; i++) {
        held.push(await within(chrome, c, async (s) => { await rideTo(s, 'zeta'); return (await arrivedRecord(s)).classes.some(([, k]) => k.includes('ride-holding')); }));
      }
      return { defects: held.every(Boolean) ? [] : [`held in ${held.filter(Boolean).length} of 3 rides`], facts: held };
    }),

  'reload-back': row('reload the destination, then Back: no hold, the transition skipped, the leaves at rest', {},
    // The type gate alone cannot be planted here: the strip has removed `#ride` from every history entry before a reload
    // or a traversal could read it, so the gate is reached only once the strip has failed too — which is this plant.
    { plant: [{ file: 'page', from: " && entry !== undefined && entry.type === 'navigate';", to: ';' }, { file: 'page', from: "                history.replaceState(null, '', location.pathname + location.search);\n", to: '' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      await arrivedRecord(s);
      await s.page('Page.reload', {});
      await until(async () => (await s.ev("document.readyState === 'complete' && window.__ride.scene !== null")), 8000, 'the reload never drew');
      await sleep(300);
      const reloaded = await s.record();
      await s.ev('history.back()');
      await until(async () => (await s.ev('location.pathname')) === '/floor/aimla' && (await s.ev("window.__ride?.scene !== null")), 8000, 'Back never drew');
      await sleep(300);
      const back = await s.record();
      const d = [...cut(reloaded, 'the reload'), ...cut(back, 'Back')];
      if ((reloaded.scene?.leaves ?? []).some((l) => l !== null)) d.push('the reload moved the leaves');
      if ((back.scene?.leaves ?? []).some((l) => l !== null)) d.push('Back moved the leaves');
      return { defects: d, facts: { reload: [reloaded.nav, reloaded.reveals], back: [back.nav, back.reveals] } };
    })),

  bookmark: row('a bookmark-style open of /floor/zeta#ride: the named residual — the leaves open then close, the hash stripped, no hold', {}, null,
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/zeta#ride');
      const r = await s.record();
      const d = [];
      if (r.hash !== '') d.push(`the hash was not stripped: ${r.hash}`);
      if (r.classes.some(([, k]) => k.includes('ride-holding'))) d.push('a bookmark held a cab nobody rode in');
      return { defects: d, facts: { reveals: r.reveals, leaves: r.scene?.leaves } };
    })),

  'to-login': row('the destination answering a 302 to /login: the sign-in page cuts, the hash stripped', { redirect: { '/floor/zeta': '/login' } },
    { plant: [{ file: 'page', from: '            if (!(arriving && reveals) || reduce) {', to: '            if (!arriving || reduce) {' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await intoTheCab(s);
      await s.click('#floor-cab-stops li[data-stop="zeta"] button');
      await until(async () => (await s.ev('location.pathname')) === '/login', 8000, 'the redirect never arrived');
      await sleep(500);
      const r = await s.record();
      const d = [...cut(r, 'the sign-in page')];
      if (r.hash !== '') d.push(`the hash was not stripped: ${r.hash}`);
      return { defects: d, facts: { reveals: r.reveals, classes: r.classes } };
    })),

  'never-commits': row('a destination that does not answer within the bound: the cab closes and A\'s floor is back, its leaves at rest; a late answer is a cut', { doc: { '/floor/zeta': 'gate' } },
    { plant: [{ file: PERFORMER, from: '            after(ride.ms, () => screen().abandoned());\n', to: '' }] },
    (chrome, c) => within(chrome, c, async (s, srv) => {
      const pressed = await pressStop(s, 'zeta');
      await sleep(DOOR_PHASE_MS + CAB_HOLD_MS + 500);
      const d = tornDown(srv, pressed);
      await fetch(`${srv.base}/release`);
      await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, 'the late answer never arrived');
      const r = await arrivedRecord(s);
      d.push(...cut(r, 'the late answer'));
      return { defects: d, facts: { beacons: srv.beacons } };
    })),

  page: row('the tool\'s own page: every origin the view prints is this server\'s, and a change to the layout\'s script changes what the rows measure', {},
    { plant: [{ file: 'page', from: "            html.classList.add('ride-holding');\n", to: '' }] },
    async (chrome, c) => {
      const d = [];
      for (const [v, html] of Object.entries(VIEWS)) {
        const left = html.match(/https?:\/\/[a-z0-9.-]+/gi) ?? [];
        if (left.length > 0) d.push(`the ${v} view still prints ${left[0]}`);
      }
      // CONTROL: the ride row's measurement under this row's page edit — it must change.
      const ride = await ROWS.ride.run(chrome, conditions(ROWS.ride, c));
      if (ride.defects.length > 0) d.push(...ride.defects.map((x) => `the ride row: ${x}`));
      return { defects: d, facts: ride.facts };
    }),

  reduce: row('under prefers-reduced-motion: the ride arrives as a cut — the transition skipped, no hold, no leaf motion', { media: [{ name: 'prefers-reduced-motion', value: 'reduce' }] },
    { plant: [{ file: 'page', from: '            if (!(arriving && reveals) || reduce) {', to: '            if (!(arriving && reveals)) {' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      const r = await arrivedRecord(s);
      const d = [...cut(r, 'reduced motion')];
      if ((r.scene?.leaves ?? []).some((l) => String(l).includes('animation'))) d.push(`the leaves moved: ${JSON.stringify(r.scene?.leaves)}`);
      return { defects: d, facts: { reveals: r.reveals, leaves: r.scene?.leaves } };
    })),

  signin: row('a 401 while inside the cab: the cab closes and the prompt answers; the elevator under the prompt opens nothing', { run: 'floor_ride_signin' },
    { plant: [{ file: SCREEN, from: '    return frame.redirect !== null || frame.failure.sign_in !== null || frame.floor === null;', to: '    return frame.redirect !== null || frame.floor === null;' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await intoTheCab(s);
      await until(async () => s.ev("!document.getElementById('floor-signin').hidden"), 8000, 'the prompt never came');
      await sleep(200);
      const after = await s.cab();
      const inert = await s.ev("document.getElementById('floor-signin').closest('[inert]') !== null || document.getElementById('floor-cab').open");
      await s.ev("document.querySelector('#floor-drawing [data-key=\"elevator\"]').dispatchEvent(new MouseEvent('click', { bubbles: true }))");
      // Past where an accepted press would have opened the cab (`opening`'s length).
      await sleep(RIDE_DOOR_MS + FADE_MS + 150);
      const pressed = await s.cab();
      const d = [];
      if (after.open || inert) d.push('the cab stayed over the prompt');
      if (pressed.open) d.push('the elevator opened a cab under the prompt');
      return { defects: d, facts: { after, pressed } };
    })),

  // ⭐ THE PAGES START (card#12326) — the browser smoke CI runs on every pull request (`design-doc-verifiers.yml`'s
  // `browser-smoke` job, `--row starts --selftest`). card#12187 merged green with a lobby that threw before it opened
  // its stream, for every visitor: nothing in CI had loaded the page in a browser. This row loads the lobby and a floor
  // as a visitor does, one navigation each, and asks only whether each STARTED — its stream asked for, its first scene
  // drawn — and threw nothing the DevTools protocol reports as an uncaught exception (`Runtime.exceptionThrown`, the
  // event Playwright's `pageerror` is built on). It drives no ride, no timing and no input, so no viewer-clock race decides it. Each plant is a
  // shape the row exists for: the card#12187 defect itself, its sibling on the floor, a page that never asks for its
  // stream and throws nothing, an exception thrown after a clean start, an unhandled rejection, and an error in the
  // layout's head script.
  starts: row('the lobby and a floor each start: the stream asked for, the first scene drawn, and no uncaught exception on either page', {},
    [
      { plant: [{ file: 'js/lobby/main.js', from: "    const drawn = layer.hasAttribute('data-drawn');", to: '    const drawn = true;' }] },
      { plant: [{ file: MAIN, from: '    surface: surface(),\n    lobby_href: lobbyHref,', to: '    surface: { width: 0, height: 0 },\n    lobby_href: lobbyHref,' }] },
      { plant: [{ file: 'js/wire/fleet-client.js', from: "    start() {\n        this.#phase = 'connecting';\n        this.#open();\n", to: "    start() {\n        this.#phase = 'connecting';\n" }] },
      { plant: [{ file: MAIN, from: 'if (rideArrival !== null) {\n    screen.arriveByRide(rideArrival);\n}\n', to: "if (rideArrival !== null) {\n    screen.arriveByRide(rideArrival);\n}\nsetTimeout(() => { throw new Error('planted after the start'); }, 0);\n" }] },
      { plant: [{ file: MAIN, from: 'if (rideArrival !== null) {\n    screen.arriveByRide(rideArrival);\n}\n', to: "if (rideArrival !== null) {\n    screen.arriveByRide(rideArrival);\n}\nPromise.reject(new Error('planted rejection'));\n" }] },
      { plant: [{ file: 'page', from: 'var CAB_HOLD_MS = ', to: 'null.planted; var CAB_HOLD_MS = ' }] },
    ],
    (chrome, c) => within(chrome, c, async (s, srv) => {
      const thrown = [];
      s.b.on('Runtime.exceptionThrown', ({ exceptionDetails: x }) => thrown.push(x.exception?.description?.split('\n')[0] ?? x.text));
      await s.page('Runtime.enable');
      const d = [];
      const facts = {};
      for (const path of ['/dashboard', '/floor/aimla']) {
        const from = thrown.length;
        await s.page('Page.navigate', { url: srv.base + path });
        // Settled when it has started, or as soon as it has thrown; a page that does neither is waited on to the bound.
        await until(() => thrown.length > from || s.ev("document.readyState === 'complete' && window.__ride?.streams.length > 0 && window.__ride?.scene !== null"), 10000, '').catch(() => {});
        await s.ev('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))').catch(() => {});
        const r = await s.record().catch(() => null);
        const errors = thrown.slice(from);
        facts[path] = { streams: r?.streams ?? null, scene: r?.scene?.at ?? null, errors };
        if (errors.length > 0) d.push(`${path} threw: ${JSON.stringify(errors)}`);
        if (r === null || r.streams.length === 0) d.push(`${path} never asked for its stream`);
        if (r === null || r.scene === null) d.push(`${path} drew no scene`);
      }
      return { defects: d, facts };
    })),

  errors: row('no error and no unhandled rejection on either page across a ride', {},
    { plant: [{ file: PERFORMER, from: '            cab.showModal();\n', to: '            cab.showModal();\n            cab.show();\n' }] },
    async (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await s.click('#floor-drawing [data-key="elevator"]');
      await sleep(RIDE_DOOR_MS + DOOR_PHASE_MS + FADE_MS + 100);   // inside, its buttons faded in
      const a = (await s.record()).errors;
      await s.click('#floor-cab-stops li[data-stop="zeta"] button').catch(() => {});
      await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, '').catch(() => {});
      const b = (await s.record().catch(() => ({ errors: [] }))).errors;
      return { defects: [...a, ...b].length === 0 ? [] : [`errors: ${JSON.stringify([...a, ...b])}`], facts: { a, b } };
    })),

  'held-enter': row('an Enter held on the elevator rides once and chooses no floor (m9)', {},
    { plant: [{ file: PANEL, from: "        if (!event.repeat) {\n            armed = true;\n        }", to: '        armed = true;' }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await s.open('/floor/aimla');
      await s.ev("document.querySelector('#floor-drawing [data-key=\"elevator\"]').focus()");
      const enter = (autoRepeat) => s.page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r', unmodifiedText: '\r', autoRepeat });
      await enter(false);
      // Held past `inside` and its buttons' fade, so a repeat lands while the stops answer.
      for (let i = 0; i < Math.ceil((RIDE_DOOR_MS + DOOR_PHASE_MS + FADE_MS + 500) / 40); i++) { await sleep(40); await enter(true); }
      await s.page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
      await sleep(400);
      const cab = await s.cab();
      // Still `inside`: the cab open on this page and the band's leaves held open behind it — a stay chosen late in the
      // hold is still fading the cab out, but its leaves are already closing.
      const inside = cab.open && cab.path === '/floor/aimla' && cab.leaves.every((l) => l === 'transform:scaleX(.12)');
      return { defects: inside ? [] : [`the held Enter chose: ${JSON.stringify(cab)}`], facts: cab };
    })),

  ride: row('press a floor: the destination is navigated to by a ride, held, and revealed at its first scene (no blank)',
    // The layout answering 600 ms late, so the destination paints its waiting chrome well before its first scene.
    { api: { '/api/building': 600 } },
    { plant: [{ file: MAIN, from: "    if (frame.scene !== null) {\n        revealed(html);\n    }\n", to: "    revealed(html);\n" }] },
    (chrome, c) => within(chrome, c, async (s) => {
      await rideTo(s, 'zeta');
      await until(async () => { const r = await s.record(); return r.scene !== null && r.classes.some(([, k]) => k.includes('ride-arrived')); }, 6000, 'the destination never arrived and drew');
      const r = await s.record();
      const d = [];
      if (r.hash !== '') d.push(`the fragment was not stripped: ${r.hash}`);
      if (r.nav !== 'navigate') d.push(`the navigation's type is ${r.nav}`);
      if (r.arrival !== 'ride') d.push(`data-ride-arrival is ${r.arrival}`);
      const held = r.classes.find(([, k]) => k.includes('ride-holding'));
      const arrived = r.classes.find(([, k]) => k.includes('ride-arrived'));
      if (held === undefined) d.push('the destination never held the cab');
      if (arrived !== undefined && r.scene !== null && arrived[0] < r.scene.at && arrived[0] < Number(r.arrivalAt) + CAB_HOLD_MS - 20) {
        d.push(`the hold ended at ${arrived[0]} ms, before the first scene (${r.scene.at}) and before the bound`);
      }
      const anim = await s.ev("document.getAnimations().filter((a) => a.effect?.pseudoElement).map((a) => [a.effect.pseudoElement, a.animationName])");
      if (r.errors.length > 0) d.push(`page errors: ${JSON.stringify(r.errors)}`);
      return { defects: d, facts: { held: held?.[0], arrived: arrived?.[0], scene: r.scene?.at, t0: Number(r.arrivalAt), anim } };
    })),
};

/** Settled on A, into the cab, `stop` pressed: answers the press's time on A's clock (beacons are on that clock). */
async function pressStop(s, stop) {
  await s.open('/floor/aimla');
  await intoTheCab(s);
  const pressed = await s.ev('performance.now()');
  await s.click(`#floor-cab-stops li[data-stop="${stop}"] button`);
  return pressed;
}

/** A tore its cab down at the bound: a beacon of the cab closing, after `chosen` and *Cab hold*, with the leaves at rest. */
function tornDown(srv, pressed) {
  const closed = srv.beacons.find((b) => b.kind === 'cab' && b.open === false && b.path === '/floor/aimla') ?? null;
  if (closed === null) return ['A never closed its cab before the destination answered'];
  const d = [];
  if (closed.at < pressed + DOOR_PHASE_MS + CAB_HOLD_MS - 50) d.push(`A closed its cab ${Math.round(closed.at - pressed)} ms after the press, before the bound`);
  if (closed.leaves.some((l) => l !== null) || closed.disabled !== null) d.push(`A at the bound: ${JSON.stringify(closed)}`);
  return d;
}

/** The two pixel rows: a ride with the modules 600 ms late, the centre pixel 300 ms into the hold and after the fade. */
async function holdPixels(chrome, c) {
  return within(chrome, c, async (s) => {
    await s.open('/floor/aimla');
    const { floor } = await references(s);
    await s.click('#floor-cab-stops li[data-stop="zeta"] button');
    const cabRef = await cabOpenPixel(s);
    await until(async () => (await s.ev('location.pathname')) === '/floor/zeta', 8000, 'the ride never committed');
    await until(async () => (await s.record()).classes.some(([, k]) => k.includes('ride-holding')), 3000, 'the destination never held');
    const held = (await s.record()).classes.find(([, k]) => k.includes('ride-holding'))[0];
    const now = await s.ev('performance.now()');
    await sleep(Math.max(0, held + 300 - now));
    const mid = await s.pixel();
    const anim = await s.ev("document.getAnimations().filter((a) => a.effect?.pseudoElement).map((a) => [a.effect.pseudoElement, a.animationName])");
    await arrivedRecord(s);
    await sleep(800);
    const after = await s.pixel();
    const d = [];
    if (mid !== cabRef) d.push(`300 ms into the hold the window shows ${mid}, not the cab's ${cabRef} (A's floor ${floor}, the destination ${after})`);
    if (after === cabRef) d.push('after the fade the window still shows the cab');
    const names = Object.fromEntries(anim.map(([p, n]) => [p, n]));
    if (names['::view-transition-old(root)'] !== 'ride-hold' || names['::view-transition-new(root)'] !== 'ride-hidden') d.push(`the hold's animations: ${JSON.stringify(anim)}`);
    return { defects: d, facts: { floor, cabRef, mid, after, anim } };
  });
}

const chrome = findChrome();

if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const only = argOf('--row');
const rows = Object.entries(ROWS).filter(([id]) => only === null || id === only);
let failed = 0;

/** A row's conditions: its own, with a plant's edits and conditions added. */
const conditions = (row, planted) => ({ ...(row.base ?? {}), ...(planted ?? {}), plant: [...(row.base?.plant ?? []), ...(planted?.plant ?? [])] });

for (const [id, row] of rows) {
  let result;
  try { result = await row.run(chrome, conditions(row, null)); } catch (e) { result = { defects: [`the row could not run: ${e.message}`], facts: null }; }
  const ok = result.defects.length === 0;
  failed += ok ? 0 : 1;
  console.log(`${ok ? 'PASS' : 'FAIL'} ${id} — ${row.what}${ok ? '' : `\n  ${result.defects.join('\n  ')}`}\n  facts: ${JSON.stringify(result.facts)}`);
  if (argv.includes('--selftest') && row.plant === null) {
    console.log(`PASS ${id} PLANT — none: the row documents a named residual, and no defect of it is planted`);
  } else if (argv.includes('--selftest')) {
    const plants = Array.isArray(row.plant) ? row.plant : [row.plant];
    for (const [i, plant] of plants.entries()) {
      let planted;
      try { planted = await row.run(chrome, conditions(row, plant)); } catch (e) { planted = { defects: [`(raised) ${e.message}`] }; }
      const bit = planted.defects.length > 0;
      failed += bit ? 0 : 1;
      const tag = plants.length === 1 ? 'PLANT' : `PLANT ${i + 1}/${plants.length}`;
      console.log(`${bit ? "PASS" : "FAIL"} ${id} ${tag} — ${bit ? `red: ${planted.defects[0]}` : `NOT CAUGHT — facts: ${JSON.stringify(planted.facts ?? null)}`}`);
    }
  }
}

console.log(failed === 0 ? 'PASS' : `FAIL (${failed})`);
process.exit(failed === 0 ? 0 : 1);

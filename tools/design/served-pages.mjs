// THE SERVED PAGES AND THEIR SCRIPTED WORLD — the rig the real-browser tools in this directory share: each view the layout
// serves rendered once by `render-floor-view.php` with its absolute origins made local, a fixture run (one of
// `server/tests/Feature/Floor/fixtures/*.json`'s) answering every `/api/…` request and firing its stream on the page's
// own clock, and one server per row over `server/public` and the asset route's trees, with a row's page conditions and
// plants applied to what it serves. Hoisted out of `floor-ride.browser.mjs` at its second caller,
// `building-view.browser.mjs` (card#13033), rather than copied; `floor-ride.browser.mjs`'s header owns what a row may
// change and why.

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const FIXTURES = join(REPO, 'server', 'tests', 'Feature', 'Floor', 'fixtures');

/** The run, from whichever fixture file holds it, with the rig's `@json:` / `@text:` references resolved. */
export function fixtureRun(name) {
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
export const VIEWS = Object.fromEntries(['floor', 'dashboard', 'login', 'console', 'building'].map((v) => [v,
  execFileSync('php', [join(HERE, 'render-floor-view.php'), v], { encoding: 'utf8' }).replace(/https?:\/\/[a-z0-9.-]+(:\d+)?(\/|(?=["']))/gi, '/')]));

for (const [v, html] of Object.entries(VIEWS)) {
  if (!html.includes('<script>') || !html.includes('<link rel="stylesheet"')) throw new Error(`the ${v} view rendered no head script or no stylesheet link`);
}

/**
 * The page's own scripted world and the tool's instruments, installed right after the layout's head script and
 * style — so the reader stays the first script on the page — and before the stylesheet link and every module.
 */
export const STAGE = (run, apiDelay, layoutStatus, streamDelay = 0, busy = 0) => `<script>
(() => {
  const RUN = ${JSON.stringify(run).replace(/</g, '\\u003c')};
  const API_DELAY = ${JSON.stringify(apiDelay)};
  const LAYOUT_STATUS = ${JSON.stringify(layoutStatus)};
  const STREAM_DELAY = ${JSON.stringify(streamDelay)};
  const BUSY = ${JSON.stringify(busy)};
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
          for (const until = performance.now() + BUSY; performance.now() < until;);
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

/** A plant whose `from` is not in the file it edits: the code it was written against has changed. */
export class StaleAnchor extends Error {
  constructor(file, from) { super(`the plant's anchor is not in ${file}: ${from.slice(0, 60)}`); this.name = 'StaleAnchor'; }
}

const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.png': 'image/png',
  '.json': 'application/json', '.tmj': 'application/json', '.tsx': 'application/xml', '.svg': 'image/svg+xml' };

/**
 * One row's server. `c` — the row's page conditions: `run` (the fixture run, `floor_rides` by default), `delay` (ms
 * on every static `/js` and `/css` answer), `doc` (`path → ms` before a document is answered, or `'gate'` to hold it
 * until `/release`), `redirect` (`path → target`), `api` (`path → ms` added to the run's answer), `layout`
 * (`document path → status` for that page's `/api/building`), `media` (emulated), `missing` (paths answered 404 — an
 * asset that failed to load), `stream` (ms added to every message's `at_ms`, so a run's messages land after the page
 * has settled on a slow host), `busy` (ms the page spends in one long task as each message lands, before it is
 * handled — the next frame's time is then taken before the message's paint runs, as on a busy live floor); and
 * `plant` — `[{ file, from, to }]` edits to what is served, `file` a path under `server/public`, `art:<path>` for a
 * file the asset route serves as `/art/<path>` (card#13033), `page:<view>` (that view's rendered page) or `page`
 * (every rendered page).
 *
 * Every plant's anchor is found before the server listens, or `serve()` throws `StaleAnchor`: a plant whose anchor has
 * moved plants nothing, so a tool must report it as a plant that no longer exists — never as a row that went red.
 */
export function serve(c = {}) {
  const run = fixtureRun(c.run ?? 'floor_rides');
  const plants = c.plant ?? [];
  const edit = (file, text) => plants.filter((p) => p.file === file).reduce((t, p) => {
    if (!t.includes(p.from)) throw new StaleAnchor(file, p.from);
    return t.split(p.from).join(p.to);
  }, text);
  const pageOf = (view, key, seat = '') => {
    let html = VIEWS[view];
    if (view === 'floor') html = html.replace(/data-floor="[^"]*"/, `data-floor="${key}"`).replace(/data-seat="[^"]*"/, `data-seat="${seat}"`);
    const at = html.indexOf('<link rel="stylesheet"');
    return edit('page', edit(`page:${view}`, html.slice(0, at) + STAGE(run, c.api ?? {}, c.layout ?? {}, c.stream ?? 0, c.busy ?? 0) + html.slice(at)));
  };
  const roots = [['/art/floor/', join(REPO, 'resources', 'floor')], ['/art/characters/', join(REPO, 'resources', 'characters')], ['/', join(REPO, 'server', 'public')]];
  // Each planted file edited once now, as it would be served: an edit inside a request would throw past the server.
  for (const view of Object.keys(VIEWS)) pageOf(view, 'aimla');
  for (const file of new Set(plants.map((p) => p.file).filter((f) => f !== 'page' && !f.startsWith('page:')))) {
    edit(file, readFileSync(file.startsWith('art:') ? join(REPO, 'resources', file.slice('art:'.length)) : join(REPO, 'server', 'public', file), 'utf8'));
  }
  const beacons = [];
  let release = null;
  const server = createServer((req, res) => {
    const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    const doc = (body) => { res.writeHead(200, { 'content-type': 'text/html', 'cache-control': 'no-store' }); res.end(body); };
    if (path === '/beacon' && req.method === 'POST') {
      let b = ''; req.on('data', (d) => { b += d; }); req.on('end', () => { beacons.push(JSON.parse(b)); res.writeHead(204); res.end(); });
      return;
    }
    if (path === '/release') { release?.(); release = null; res.end('ok'); return; }
    const documents = { '/building': () => pageOf('building'), '/dashboard': () => pageOf('dashboard'), '/login': () => pageOf('login'), '/admin': () => pageOf('console'), '/admin/other': () => pageOf('console') };
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
        const rel = prefix === '/' ? file.slice(join(REPO, 'server', 'public').length + 1) : `art:${path.slice('/art/'.length)}`;
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

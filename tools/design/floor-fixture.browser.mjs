#!/usr/bin/env node
// A FIXTURE RUN, DRAWN BY THE REAL FLOOR PAGE — card#11144 PR-3. Node, no dependencies — but a real
// browser: the shipped floor page (`render-floor-view.php`'s markup, the shipped `server/public` modules
// and stylesheet, the art the asset route serves) in the Playwright-cached headless Chromium, fed one
// checked-in fixture run the way `DrivesTheFloorScreen` feeds the headless probe, and photographed.
//
//   node tools/design/floor-fixture.browser.mjs --run fx-office --out office.png
//   node tools/design/floor-fixture.browser.mjs --run fx-office --out office.png --width 1400 --height 1000
//   node tools/design/floor-fixture.browser.mjs --selftest
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// ⛔ THE RUN IS THE FIXTURE'S, AND NOTHING HERE AUTHORS ONE. `--run` names a run of a file under
// `server/tests/Feature/Floor/fixtures/` (held unique across them, as the rig holds it), with the
// rig's `@json:` / `@text:` references resolved to the repository's own files. The page is fed that
// run and nothing else:
//   · every `/api/…` request is answered from the run's `http` map, each path's responses in order
//     and each one `times` times, the LAST one answering every later request for its path — the page
//     asks for a room's map more than once where the probe asks once (measured on `fx-office`: two
//     requests 4 ms apart), and a picture needs the page's own requests answered. An `/api/…` path
//     the run scripts no response for at all is REFUSED, and the tool fails, because a picture of a
//     request nobody scripted is a picture of nothing;
//   · the stream is a stand-in `EventSource` that fires `open` and then each of the run's `messages`
//     at its `at_ms` on the run's clock — which starts when the client opens its first stream, the
//     probe's `start()` — dropping the ones scheduled before it was constructed, as the probe's does
//     (D2 never replays);
//   · the viewer's clock is the run's: `Date` reads `floor.local_hours:local_minutes` on 2026-08-23,
//     in UTC, at the run's start, advancing in whole seconds of the run's clock and stopping at its
//     `until_ms` — so the wall clock, the sky and every age are the run's and not the host's, and the
//     same on every drawing of one run.
// The shot — the page below its header — is taken once the run's `until_ms` has passed (and a settle
// after it) and two consecutive shots agree, under
// `prefers-reduced-motion: reduce`, so every edge animation is its static form (§ 6.4) and a picture
// is the frame the run ends on rather than a moment inside a walk.
//
// ⛔ IT IS A PICTURE AND NOT A CHECK OF ONE. What this tool proves is that the page drew the run; what
// the picture SHOWS is for whoever looks at it. Its selftest is the property that makes it worth
// running at all — a planted change to the fixture changes the PNG, and the same fixture drawn twice
// does not — and nothing more.
//
// SELFTEST: `fx-office` drawn twice must give identical bytes (CONTROL: the picture is a function of
// the run), and drawn with its map's reservation removed (the PLANT) must give different bytes.

import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createServer } from 'node:http';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const FIXTURES = join(REPO, 'server', 'tests', 'Feature', 'Floor', 'fixtures');
const FLOOR_MD = join(REPO, 'docs', 'design', 'FLOOR.md');

/** How long after the run's `until_ms` the shot waits: the last render, its art, and more than one 1 s age tick on the stopped clock. */
const SETTLE_MS = 2500;

/** How many shots, 500 ms apart, the page is given to draw the same picture twice. */
const STABLE_TRIES = 20;

const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };

/** § 12's reference viewport, read as `floor-chrome.browser.mjs` reads it. */
function referenceViewport() {
  const row = readFileSync(FLOOR_MD, 'utf8').split('\n').find((l) => l.startsWith('| Floor reference viewport |')) ?? '';
  const m = /^\| Floor reference viewport \| \*\*([\d,]+) × ([\d,]+) CSS px\*\*/.exec(row);
  if (m === null) throw new Error("§ 12's viewport row states no reference viewport in the form this tool reads");
  return { w: Number(m[1].replace(/,/g, '')), h: Number(m[2].replace(/,/g, '')) };
}

/** The run, from whichever fixture file holds it — refused when none does, or more than one. */
export function fixtureRun(name) {
  const holders = readdirSync(FIXTURES).filter((f) => f.endsWith('.json'))
    .map((f) => [f, JSON.parse(readFileSync(join(FIXTURES, f), 'utf8'))])
    .filter(([, body]) => Object.hasOwn(body.runs ?? {}, name));
  if (holders.length !== 1) throw new Error(`the run ${name} is held by ${holders.length} fixture files, not one`);
  return resolveRefs(holders[0][1].runs[name]);
}

/** The rig's references (`fleet-client-probe.mjs`): `@json:<path>` parsed, `@text:<path>` as text. */
function resolveRefs(node) {
  if (typeof node === 'string') {
    if (node.startsWith('@json:')) return JSON.parse(readFileSync(join(REPO, node.slice(6)), 'utf8'));
    if (node.startsWith('@text:')) return readFileSync(join(REPO, node.slice(6)), 'utf8');
    if (node.startsWith('@')) throw new Error(`a fixture reference this tool does not resolve: ${node.slice(0, 40)}`);
    return node;
  }
  if (Array.isArray(node)) return node.map(resolveRefs);
  if (node !== null && typeof node === 'object') return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, resolveRefs(v)]));
  return node;
}

/** The page's own scripted world, installed before its module runs (see the header). */
const STAGE = (run) => `<script>
(() => {
  const RUN = ${JSON.stringify(run).replace(/</g, '\\u003c')};
  const T0 = Date.UTC(2026, 7, 23, RUN.floor?.local_hours ?? 9, RUN.floor?.local_minutes ?? 0);
  // The run's clock starts when the client opens its first stream — the probe's start(), since the
  // client opens the stream first (§ 2.2) — so every at_ms is measured from there, as it is in the probe.
  let s0 = null;
  const scenario = () => (s0 === null ? 0 : performance.now() - s0);
  // The viewer's clock, in whole seconds of the run's clock and stopped at its until_ms: every age the
  // page draws is then the same on every drawing of one run, rather than a reading of how fast the
  // host loaded the page — the shot comes more than one age tick after the clock stops.
  const reading = () => T0 + Math.floor(Math.min(scenario(), RUN.until_ms ?? 0) / 1000) * 1000;
  const RealDate = Date;
  class RunDate extends RealDate {
    constructor(...a) { if (a.length === 0) { super(reading()); } else { super(...a); } }
    static now() { return reading(); }
  }
  window.Date = RunDate;
  window.__unscripted = [];
  const queues = {};
  for (const [path, list] of Object.entries(RUN.http ?? {})) queues[path] = list.map((entry) => ({ entry, left: entry.times ?? 1 }));
  const realFetch = window.fetch.bind(window);
  window.fetch = (input, init) => {
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (!url.pathname.startsWith('/api/')) return realFetch(input, init);
    (window.__requests ??= []).push([Math.round(scenario()), url.pathname]);
    const queue = queues[url.pathname] ?? [];
    const head = queue[0];
    if (head === undefined) { window.__unscripted.push(url.pathname); return Promise.reject(new TypeError('unscripted request: ' + url.pathname)); }
    if (--head.left <= 0 && queue.length > 1) queue.shift();
    const next = head.entry;
    if (next.unreachable === true) return Promise.reject(new TypeError('Failed to fetch'));
    const text = next.text !== undefined ? next.text : JSON.stringify(next.body ?? null);
    return new Promise((resolve) => setTimeout(() => resolve(new Response(text, { status: next.status, headers: { 'content-type': 'application/json' } })), next.delay_ms ?? 0));
  };
  class RunEventSource {
    constructor(url) {
      this.url = url; this.readyState = 0; this.listeners = {};
      if (s0 === null) {
        s0 = performance.now();
        setTimeout(() => { document.title = 'drawn'; }, (RUN.until_ms ?? 0) + ${SETTLE_MS});
      }
      const opened = scenario();
      setTimeout(() => { this.readyState = 1; this.fire('open', {}); }, 0);
      for (const m of RUN.messages ?? []) {
        if (m.at_ms < opened) continue;
        setTimeout(() => {
          if (this.readyState === 2) return;
          if (m.end === true) { this.readyState = 2; this.fire('error', {}); return; }
          this.fire('mezzanine', { data: JSON.stringify(m.envelope) });
        }, m.at_ms - scenario());
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

/** The served floor page with the run staged in its head, and every absolute URL made local. */
function page(run) {
  const html = execFileSync('php', [join(HERE, 'render-floor-view.php')], { encoding: 'utf8' })
    .replace(/https?:\/\/localhost(:\d+)?\//g, '/')
    .replace(/data-floor="[^"]*"/, `data-floor="${run.floor?.key ?? 'aimla'}"`);
  if (!html.includes('</head>')) throw new Error('the floor view rendered no </head> to stage the run in');
  return html.replace('</head>', `${STAGE(run)}</head>`);
}

const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.png': 'image/png',
  '.json': 'application/json', '.tmj': 'application/json', '.tsx': 'application/xml', '.svg': 'image/svg+xml' };

/** `/` is the page; `/js`, `/css` are `server/public`; `/art/{floor,characters}/` the asset route's two trees. */
function serve(html) {
  const roots = [['/art/floor/', join(REPO, 'resources', 'floor')], ['/art/characters/', join(REPO, 'resources', 'characters')], ['/', join(REPO, 'server', 'public')]];
  const server = createServer((req, res) => {
    const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (path === '/') { res.writeHead(200, { 'content-type': 'text/html' }); res.end(html); return; }
    for (const [prefix, root] of roots) {
      if (!path.startsWith(prefix)) continue;
      const file = normalize(join(root, path.slice(prefix.length)));
      if (file.startsWith(root) && existsSync(file)) {
        res.writeHead(200, { 'content-type': TYPES[extname(file)] ?? 'application/octet-stream' });
        res.end(readFileSync(file));
        return;
      }
    }
    res.writeHead(404); res.end();
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

/** One run drawn: the PNG's bytes. Refused when the page asked for an `/api/` path the run did not script. */
export async function draw(chrome, run, { w, h }) {
  const server = await serve(page(run));
  const b = await browser(chrome, w, h, 'drawn', { media: [{ name: 'prefers-reduced-motion', value: 'reduce' }], timezone: 'UTC' });
  try {
    await b.load(`http://127.0.0.1:${server.address().port}/`);
    const unscripted = await b.evaluate('window.__unscripted');
    if (unscripted.length > 0) throw new Error(`the page requested what the run scripts no response for: ${unscripted.join(', ')} (${JSON.stringify(await b.evaluate('window.__requests'))})`);
    // The picture is taken once two consecutive shots agree: art that is still arriving on a loaded host
    // is a picture of the host, and the clock is stopped, so a settled page draws the same bytes twice.
    // Everything below the page's header: the strip, the notices and the drawing. The header's link pill
    // drew a handful of pixels differently between two loads of one run (measured: 30 pixels inside it,
    // nowhere else), and it carries nothing a fixture run decides.
    const clip = await b.evaluate(`(() => { const r = document.querySelector('header.app-header').getBoundingClientRect();
      return { x: 0, y: Math.ceil(r.bottom), width: document.documentElement.clientWidth, height: innerHeight - Math.ceil(r.bottom) }; })()`);
    let last = null;
    for (let i = 0; i < STABLE_TRIES; i += 1) {
      const shot = Buffer.from((await b.shot(clip)).split(',')[1], 'base64');
      if (last !== null && shot.equals(last)) return shot;
      last = shot;
      await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error(`the page did not settle: ${STABLE_TRIES} shots 500 ms apart never agreed twice`);
  } finally {
    b.close();
    server.close();
  }
}

const chrome = findChrome();

if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const ref = referenceViewport();
const size = { w: Number(argOf('--width') ?? ref.w), h: Number(argOf('--height') ?? ref.h) };

if (argv.includes('--selftest')) {
  const run = fixtureRun('fx-office');
  const planted = structuredClone(run);
  let removed = 0;
  for (const layer of planted.http['/api/building/rooms/aimla/map'][0].body.map.layers) {
    for (const object of layer.objects ?? []) {
      if ((object.properties ?? []).some((p) => p.name === 'reserved_for')) {
        object.properties = object.properties.filter((p) => p.name !== 'reserved_for');
        removed += 1;
      }
    }
  }
  if (removed !== 1) { console.log(`SELFTEST: the plant removed ${removed} reservations, not one — it plants nothing it names`); process.exit(1); }
  const [a, b, c] = [await draw(chrome, run, size), await draw(chrome, run, size), await draw(chrome, planted, size)];
  const control = a.equals(b);
  const plant = !a.equals(c);
  if (!control) {
    const dir = mkdtempSync(join(tmpdir(), 'floor-fixture-'));
    writeFileSync(join(dir, 'first.png'), a);
    writeFileSync(join(dir, 'second.png'), b);
    console.log(`SELFTEST control: the two drawings are kept in ${dir} to be compared`);
  }
  console.log(`SELFTEST control (fx-office drawn twice): ${control ? 'identical' : 'DIFFERENT — the picture is not a function of the run'}`);
  console.log(`SELFTEST plant (fx-office without its reservation): ${plant ? 'changed the PNG' : 'NOT CAUGHT — the PNG did not change'}`);
  console.log(control && plant ? 'PASS' : 'FAIL');
  process.exit(control && plant ? 0 : 1);
}

const name = argOf('--run');
const out = argOf('--out');

if (name === null || out === null) {
  console.error('usage: floor-fixture.browser.mjs --run <fixture run> --out <png> [--width W --height H] | --selftest');
  process.exit(2);
}

writeFileSync(out, await draw(chrome, fixtureRun(name), size));
console.log(`drew ${name} at ${size.w} × ${size.h} → ${out}`);

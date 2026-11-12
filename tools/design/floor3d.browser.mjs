#!/usr/bin/env node
// THE 3-D FLOOR, IN A REAL BROWSER — card#13033 stage 2 slice 4 (`docs/design/FLOOR.md` § 4.4's `/floor/{floor}/3d`; the
// design is `docs/design/plan-13033-stage2-3d-floor.md`, whose § 8 item 5 and § 6 this implements). Node, no dependencies:
// the served `/floor/aimla/3d` (`render-floor-view.php floor3d`, through the real layout), the shipped modules,
// stylesheet, art and Kenney's `.glb` files, a fixture run answering every `/api/…` request — the rig `served-pages.mjs`
// shares — in the Playwright-cached headless Chromium.
//
//   node tools/design/floor3d.browser.mjs              every row, each once
//   node tools/design/floor3d.browser.mjs --row <id>   one row (repeat it: `--row still --row no-webgl --selftest` is CI's)
//   node tools/design/floor3d.browser.mjs --selftest   every row, then every row again under each of its PLANTs, each of
//                                                      which must red
//   node tools/design/floor3d.browser.mjs --out <dir>  also save the operator's reading pictures: the entry pose, a turned
//                                                      pose, a close view and a view with the band cut away
//   …and --chrome <path> or $CHROME, as every browser tool here.
//
// ⛔ THE PAGE IS READ THROUGH ITS OWN INSTRUMENT: `#floor3d-canvas`'s `inspect()` (`floor3d/main.js`) — the camera, the
// frames drawn, the walls' heights, `renderer.info` and § 6's figures term by term — and the DOM. The gestures are the
// protocol's mouse and key events on the stage, so `OrbitControls` reads them as it reads a person's.
//
// ⛔ A PLANT EDITS WHAT IS SERVED — a module or an art module — never a copy kept here; a plant whose anchor is gone is
// STALE, never a pass.
//
// ⚠ WHAT A PASS IS NOT (plan § 10): a real GPU, Safari's or Firefox's WebGL 2, a real trackpad's inertia, or a frame
// rate on any device — SwiftShader on a loaded host measures none of those. The draw calls, triangles and texture memory
// ARE what this engine reports for the cap floor, and they are what § 6 budgets.

import { readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { inflateSync } from 'node:zlib';
import { browser, findChrome } from './headless-chromium.mjs';
import { StaleAnchor, fixtureRun, serve } from './served-pages.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const JS = join(REPO, 'server', 'public', 'js');
const KIT = join(REPO, 'resources', 'floor', 'models', 'kenney-furniture-kit');
const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const L = await import(pathToFileURL(join(JS, 'floor3d', 'view-limits.js')).href);
const { HOME_POLAR } = await import(pathToFileURL(join(JS, 'building', 'stack-model.js')).href);
const { LOOP_FPS } = await import(pathToFileURL(join(JS, 'wire', 'animation-set.js')).href);
const KITMAP = await import(pathToFileURL(join(KIT, 'map.js')).href);
const REDUCE = [{ name: 'prefers-reduced-motion', value: 'reduce' }];
/** How late the kit's files answer in the loop row's restart leg, ms — after the page has drawn and settled. */
const LATE_KIT_MS = 15000;
/** How long the loop row counts frames at rest with held loops running, s (a paint or two may land in it). */
const HELD_WINDOW_S = 4;
/**
 * How many display ticks a damped turn is given to come to rest before the loop row calls it a loop that never stops.
 * `OrbitControls` damps per FRAME, not per second: each update moves `dampingFactor` of what is left and the turn ends
 * once a step's square falls under its `_EPS`, so the coast is about `ln(step₀ / √_EPS) / −ln(1 − dampingFactor)` frames
 * — a couple of hundred for this row's drag at the default factor — however slowly the host draws them. A cap in
 * seconds measured the host's frame rate instead (and passed only while frames were drawn several to a tick). With one
 * frame a tick, this cap is that coast with room to spare.
 */
const DAMPING_REST_TICKS = 600;
/** The wall-clock bound on the same wait, s — only so a page whose ticks stall cannot hold the row forever. */
const DAMPING_REST_S = 300;
/** How often the loop row samples a damped turn's frames against the display's ticks, ms; a second of samples with no
 *  frame drawn is the rest. */
const DAMPING_SAMPLE_MS = 100;
/**
 * The frames a damped turn may draw beyond the display's ticks: NONE. A page counter re-queues itself each tick, so every
 * rendering opportunity runs it once; the frame loop's callbacks of that opportunity run beside it, and a frame it queues
 * from inside runs at the NEXT one. Each sample reads the frame count and the tick count in one evaluation, between
 * opportunities, so with at most one frame pending every interval draws at most as many frames as it has ticks.
 */
const TICK_SLACK = 0;
/** The page's tick counter, and one atomic read of it beside the renderer's frame count. */
const TICKS = `(() => { if (window.__floor3dTicks === undefined) { window.__floor3dTicks = 0; const t = () => { window.__floor3dTicks++; requestAnimationFrame(t); }; requestAnimationFrame(t); } return window.__floor3dTicks; })()`;
const FRAMES_TICKS = `({ frames: document.getElementById('floor3d-canvas').inspect().frames, ticks: window.__floor3dTicks })`;

/** § 6's budgets — the plan's figures, each a claim this leg checks (plan § 6's table). */
const BUDGET = { calls: 260, triangles: 150000, textureMB: 128, glbMB: 2, walls: 2, fixed: 20, transient: 19 };

/** Primitives a kit file carries, read off its own JSON chunk — `k` of § 6's furniture term is the most of them. */
const primitives = (file) => {
  const b = readFileSync(join(KIT, file));
  const j = JSON.parse(b.subarray(20, 20 + b.readUInt32LE(12)).toString('utf8'));
  return j.meshes.reduce((n, m) => n + m.primitives.length, 0);
};
const K = Math.max(...Object.values(KITMAP.FILES).map(primitives));

// ── the runs ──────────────────────────────────────────────────────────────────────────────────
/** floor_rides with no stream messages after the snapshot: nothing but the viewer wakes the page. */
const quiet = () => ({ ...fixtureRun('floor_rides'), messages: [] });

/** floor_rides with every desk stalled (A8, a still pose) and no interns: with motion on, nothing moves but the viewer. */
const stillDesks = () => {
  const run = structuredClone(quiet());
  for (const i of run.http['/api/fleet/snapshot'][0].body.installs) {
    for (const seat of i.seats) Object.assign(seat, { render_state: 'stalled', activity_state: 'stalled', subagents: [], subagents_open: 0, api_error_type: seat.api_error_type ?? 'overloaded' });
  }
  return run;
};

/**
 * floor_rides, then a seat the snapshot did not hold admitted by its first `seat.delta` and fetched (the membership run
 * `leg_a`'s seat): a desk that arrives after the first render.
 */
/** The late seat's id: one the floor's slot hash seats AHEAD of other desks in reading order, so an append is out of order. */
const LATE = 'aimla-ops';

const lateDesk = (id = LATE) => {
  const base = structuredClone(fixtureRun('floor_rides'));
  const leg = fixtureRun('leg_a');
  const rename = (x) => JSON.parse(JSON.stringify(x).replaceAll('aimla-impl-3', id));
  const seat = rename(leg.http['/api/fleet/seats/aimla-impl-3'][0].body);
  // every later snapshot and heartbeat counts the new seat, so the page keeps it rather than resyncing it away
  const later = structuredClone(base.http['/api/fleet/snapshot'][0]);
  later.body.installs.find((i) => i.install_id === 'aimla').seats.push(Object.fromEntries(Object.entries(seat).filter(([k]) => !['api_version', 'server_time'].includes(k))));
  later.body.fleet.seats_total += 1;
  later.body.fleet.seats_live += 1;
  return {
    ...base,
    http: { ...base.http, '/api/fleet/snapshot': [{ ...base.http['/api/fleet/snapshot'][0], times: 1 }, later], [`/api/fleet/seats/${id}`]: rename(leg.http['/api/fleet/seats/aimla-impl-3']) },
    messages: [
      ...base.messages.map((m) => (m.at_ms < 2000 ? m : { ...m, envelope: { ...m.envelope, fleet: { ...m.envelope.fleet, seats_total: later.body.fleet.seats_total, seats_live: later.body.fleet.seats_live } } })),
      ...rename(leg.messages).map((m) => ({ ...m, at_ms: m.at_ms + 2000 })),
    ].sort((a, b) => a.at_ms - b.at_ms),
  };
};

/** When the churn run's seat leaves and comes back, page time — after the floor has drawn, and far enough apart to settle. */
const CHURN = { seat: 'aimla-impl-1', leave: 9000, back: 17000 };

/** `leave-then-return`'s seat going offline, and back, each far from the other: a body that goes and a body that comes. */
const churn = () => {
  const r = fixtureRun('leave-then-return');
  const [away, home] = r.messages;
  return { ...r, until_ms: CHURN.back + 6000, messages: [{ ...away, at_ms: CHURN.leave }, { ...home, at_ms: CHURN.back }] };
};

/**
 * § 6's CAP FLOOR: the shipped default's desks every one staffed — `interns_cap`'s seats with two more cloned in, each
 * holding § 8.1's cap of interns — and every standing kind of the tileset placed at least once on the default map.
 */
const capFloor = () => {
  const run = structuredClone(fixtureRun('interns_cap'));
  const snap = run.http['/api/fleet/snapshot'][0].body;
  const install = snap.installs.find((i) => i.install_id === 'aimla');
  const full = install.seats.find((s) => (s.subagents ?? []).length === 8);
  const map = run.http['/api/building/rooms/aimla/map'][0].body.map;
  const desks = map.layers.find((l) => l.name === 'desks').objects.length;
  const species = ['fox', 'pumpkin', 'owl', 'radish', 'panda', 'lemon', 'frog', 'bear'];
  while (install.seats.length < desks) {
    const n = install.seats.length;
    install.seats.push({ ...structuredClone(full), seat_id: `aimla-cap-${n}`, character: { species: species[n % species.length] }, protocol_agent_name: `aimla-cap-${n}` });
  }
  for (const s of install.seats) {
    s.subagents = structuredClone(full.subagents);
    s.subagents_open = full.subagents_open;
  }
  snap.fleet.seats_total = snap.fleet.seats_live = snap.installs.reduce((n, i) => n + i.seats.length, 0);
  // every standing kind of the tileset on the map's furniture layer, along its open front rows
  const tsx = readFileSync(join(REPO, 'resources', 'floor', 'tiles', 'floor-plane.tsx'), 'utf8');
  const kinds = new Map([...tsx.matchAll(/<tile id="(\d+)">\s*<properties>\s*<property name="kind" value="([a-z-]+)"/g)].map((m) => [Number(m[1]), m[2]]));
  const layer = map.layers.find((l) => l.name === 'furniture');
  const placed = new Set(layer.data.filter((g) => g > 0).map((g) => kinds.get(g - 1)));
  const owed = [...new Set(kinds.values())].filter((k) => k !== 'wall' && k !== 'accent' && !placed.has(k));
  owed.forEach((kind, i) => {
    const id = [...kinds].find(([, k]) => k === kind)[0];
    layer.data[(map.height - 2) * map.width + 8 + i * 12] = id + 1;
  });
  return run;
};

// ── the page ──────────────────────────────────────────────────────────────────────────────────
/** A browser on the row's server at `path`, drawn: the renderer built, its bodies and labels there, the kit answered. */
async function open(chrome, c, { path = '/floor/aimla/3d', w = 1280, h = 800, kit = true } = {}) {
  const s = await serve(c);
  const b = await browser(chrome, w, h, 'never', { media: c.media ?? [] });
  const errors = [];
  const glb = new Map();
  await b.page('Runtime.enable');
  await b.page('Network.enable');
  b.on('Runtime.exceptionThrown', (e) => errors.push(e.exceptionDetails.exception?.description ?? e.exceptionDetails.text));
  b.on('Network.requestWillBeSent', (e) => { if (e.request.url.includes('.glb')) glb.set(e.request.url, (glb.get(e.request.url) ?? 0) + 1); });
  await b.page('Page.navigate', { url: s.base + path });
  const ev = (x) => b.evaluate(x);
  const inspect = () => ev(`document.getElementById('floor3d-canvas').inspect?.() ?? null`);
  let i = null;
  for (let t = 0; t < 600; t++) {
    i = await inspect().catch(() => null);
    if (i !== null && i.labels > 0 && i.bodies > 0 && i.bodies >= i.desks.length && (!kit || i.terms.furniture.calls > 0)) break;
    await sleep(100);
  }
  const close = () => { b.close(); s.close(); };
  if (i === null || i.bodies === 0) { close(); return { failed: `the page never drew: ${JSON.stringify(errors.slice(0, 2))}` }; }
  const mouse = (type, x, y, extra = {}) => b.page('Input.dispatchMouseEvent', { type, x, y, ...extra });
  const stage = await ev(`(() => { const r = document.getElementById('floor-drawing').getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; })()`);
  const mid = { x: stage.x + stage.w / 2, y: stage.y + stage.h / 2 };
  const drag = async (dx, dy, { button = 'left', steps = 12, at = mid } = {}) => {
    const buttons = button === 'left' ? 1 : 2;
    await mouse('mouseMoved', at.x, at.y);
    await mouse('mousePressed', at.x, at.y, { button, buttons, clickCount: 1 });
    for (let k = 1; k <= steps; k++) await mouse('mouseMoved', at.x + (dx * k) / steps, at.y + (dy * k) / steps, { button, buttons });
    await mouse('mouseReleased', at.x + dx, at.y + dy, { button, buttons: 0, clickCount: 1 });
  };
  const wheel = (deltaY, n = 1) => (async () => { for (let k = 0; k < n; k++) await mouse('mouseWheel', mid.x, mid.y, { deltaX: 0, deltaY }); })();
  const key = async (k, code, shift = false) => {
    const mod = shift ? 8 : 0;
    await b.page('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, modifiers: mod, windowsVirtualKeyCode: { ArrowLeft: 37, ArrowUp: 38, ArrowRight: 39, ArrowDown: 40, Tab: 9 }[k] ?? 0 });
    await b.page('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, modifiers: mod });
  };
  const settle = async (ms = 1200) => { await sleep(ms); return inspect(); };
  const shot = async () => Buffer.from((await b.page('Page.captureScreenshot', { format: 'png' })).data, 'base64');
  return { s, b, ev, inspect, drag, wheel, key, settle, shot, stage, errors, glb, close, first: i };
}

/**
 * The pixels of a PNG as `{ width, height, rgba }` — the protocol's screenshot, decoded: 8-bit, no interlace, RGB (the
 * protocol's colour type 2) or RGBA (6), every pixel answered as four bytes.
 */
function png(buf) {
  let p = 8, width = 0, height = 0, type = 0;
  const idat = [];
  while (p < buf.length) {
    const len = buf.readUInt32BE(p), chunk = buf.toString('ascii', p + 4, p + 8), data = buf.subarray(p + 8, p + 8 + len);
    if (chunk === 'IHDR') { width = data.readUInt32BE(0); height = data.readUInt32BE(4); type = data[9]; if (data[8] !== 8 || data[12] !== 0 || ![2, 6].includes(type)) throw new Error(`a PNG this decoder does not read: depth ${data[8]}, colour type ${type}, interlace ${data[12]}`); }
    if (chunk === 'IDAT') idat.push(data);
    p += 12 + len;
  }
  const raw = inflateSync(Buffer.concat(idat)), bpp = type === 6 ? 4 : 3, stride = width * bpp, px = Buffer.alloc(width * height * bpp);
  for (let y = 0; y < height; y++) {
    const f = raw[y * (stride + 1)], row = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
    for (let x = 0; x < stride; x++) {
      const a = x >= bpp ? px[y * stride + x - bpp] : 0, up = y > 0 ? px[(y - 1) * stride + x] : 0, ul = y > 0 && x >= bpp ? px[(y - 1) * stride + x - bpp] : 0;
      const pa = Math.abs(up - ul), pb = Math.abs(a - ul), pc = Math.abs(a + up - 2 * ul);
      px[y * stride + x] = (row[x] + [0, a, up, (a + up) >> 1, pa <= pb && pa <= pc ? a : pb <= pc ? up : ul][f]) & 255;
    }
  }
  const rgba = Buffer.alloc(width * height * 4, 255);
  for (let i = 0; i < width * height; i++) px.copy(rgba, i * 4, i * bpp, i * bpp + 3);
  return { width, height, rgba };
}

const deg = (r) => (r * 180) / Math.PI;
/** The desks in reading order — the world's own figures, sorted here by back edge, then left edge, then key. */
const readingOrder = (desks) => [...desks].sort((a, b) => a.back - b.back || a.left - b.left || (a.key < b.key ? -1 : 1)).map((d) => d.key);
const KINDS = ['name', 'status', 'chip', 'facts', 'bubble'];
/** What the label layer holds, in document order: `[key, kind, display, text, width]`. */
const LABELS = `[...document.getElementById('floor3d-labels').children].map((e) => [e.dataset.key, e.dataset.kind, e.style.display, e.textContent, e.getBoundingClientRect().width, e.tagName])`;
const orderDefects = (labels, desks) => {
  const d = [];
  const want = readingOrder(desks);
  const seen = [];
  for (const [key] of labels) if (seen.at(-1) !== key) seen.push(key);
  const keyed = want.filter((k) => seen.includes(k));
  if (JSON.stringify(seen) !== JSON.stringify(keyed)) d.push(`the labels' desks are in the order [${seen.join(', ')}], not reading order [${keyed.join(', ')}]`);
  for (const key of seen) {
    const kinds = labels.filter(([k]) => k === key).map(([, kind]) => kind);
    if (JSON.stringify(kinds) !== JSON.stringify(KINDS.filter((k) => kinds.includes(k)))) d.push(`${key}'s labels are [${kinds.join(', ')}] — not name, status, chip, facts, bubble`);
  }
  return d;
};

// ── the plants: each one textual edit of a served file ───────────────────────────────────────
const P = (file, from, to) => ({ file, from, to });
const PAINT = 'js/floor3d/floor-paint.js';

// ── the rows ──────────────────────────────────────────────────────────────────────────────────
const ROWS = {
  still: {
    what: 'the page draws: no uncaught exception, a WebGL 2 canvas, the bodies, the furniture and the labels; the entry pose at the home tilt; every label one element in reading order, each name a link; the kit\'s files requested once each',
    base: { run: lateDesk() },
    plants: [
      ['the entry pose not set from its spherical figures (the camera left at the origin)', { plant: [P(PAINT, '            camera.position.set(position.x, position.y, position.z);\n            controls.update();\n        }\n    };', '            controls.update();\n        }\n    };')] }],
      ['a label appended after the render rather than inserted at its place', { plant: [P('js/floor3d/floor-labels.js', '                if (container.children[i] !== entry.element) {', '                if (false) {')] }],
      ['a kit file requested for every placement', { plant: [P('js/floor3d/furniture.js', '        if (!requests.has(file)) {', '        if (true) {')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        // the late desk: wait for it to be drawn
        let i = p.first;
        for (let t = 0; t < 100 && !i.desks.some((x) => x.key === LATE && x.seat); t++) { await sleep(100); i = await p.inspect(); }
        i = await p.settle(1500);
        const webgl = await p.ev(`document.getElementById('floor').dataset.webgl`);
        if (webgl !== 'ready') d.push(`the page reads data-webgl=${webgl}, not a WebGL 2 canvas`);
        if (!i.desks.some((x) => x.key === LATE)) d.push('CONTROL: the late desk never arrived — the order after a late desk was not measured');
        if (Math.abs(deg(i.polar) - deg(HOME_POLAR)) > 0.5 || Math.abs(i.azimuth) > 1e-3) d.push(`the entry pose is polar ${deg(i.polar).toFixed(2)}°, azimuth ${deg(i.azimuth).toFixed(2)}° — not ${deg(HOME_POLAR)}° from the front`);
        const labels = await p.ev(LABELS);
        d.push(...orderDefects(labels, i.desks));
        const ids = labels.map(([k, kind]) => `${k}/${kind}`);
        if (new Set(ids).size !== ids.length) d.push('a label is drawn by more than one element');
        if (labels.filter(([, kind]) => kind === 'name').some((l) => l[5] !== 'A')) d.push('a desk\'s name is not a link');
        for (const [url, n] of p.glb) if (n !== 1) d.push(`${url.split('/').pop().split('?')[0]} was requested ${n} times`);
        if (p.glb.size === 0) d.push('CONTROL: no kit file was requested');
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        // the operator's reading pictures: the entry pose, a turned pose, and a close view of one desk
        if (argOf('--out') !== null && !(c.plant ?? []).length) {
          writeFileSync(join(argOf('--out'), 'floor3d-entry.png'), await p.shot());
          await p.drag(-0.35 * p.stage.h, -0.08 * p.stage.h);
          await p.wheel(-100, 4);
          await p.settle(2500);
          writeFileSync(join(argOf('--out'), 'floor3d-turned.png'), await p.shot());
          await p.ev(`document.getElementById('floor-fit').click()`);
          await p.ev(`document.querySelector('.floor3d-name[data-key="aimla-impl-1"]').focus()`);
          await p.wheel(-100, 9);
          await p.drag(0.1 * p.stage.h, 0);
          await p.settle(2500);
          writeFileSync(join(argOf('--out'), 'floor3d-close.png'), await p.shot());
        }
        return { defects: d, facts: { bodies: i.bodies, labels: labels.length, glb: p.glb.size, polar: deg(i.polar).toFixed(2), late: labels.findIndex(([k]) => k === LATE) } };
      } finally { p.close(); }
    },
  },

  'no-webgl': {
    what: 'without WebGL 2 the page shows its statement and link, withdraws the stage and the camera, and its list view still lists every desk — never a redirect',
    base: { run: 'floor_rides', args: ['--disable-3d-apis'] },
    plants: [['the statement never shown', { plant: [P('js/floor3d/main.js', "    document.getElementById('floor3d-webgl').hidden = available;", "    document.getElementById('floor3d-webgl').hidden = true;")] }]],
    async run(chrome, c) {
      const s = await serve(c);
      const b = await browser(chrome, 1280, 800, 'never', { args: c.args });
      try {
        await b.page('Page.navigate', { url: `${s.base}/floor/aimla/3d` });
        let r = null;
        for (let t = 0; t < 200; t++) {
          r = await b.evaluate(`document.getElementById('floor3d-webgl') === null ? null : ({ path: location.pathname, statement: !document.getElementById('floor3d-webgl').hidden, link: document.querySelector('#floor3d-webgl a')?.getAttribute('href') ?? null, stage: getComputedStyle(document.getElementById('floor-drawing')).visibility, desks: document.querySelectorAll('#floor-desks > li > a').length })`).catch(() => null);
          if (r !== null && r.desks > 0) break;
          await sleep(100);
        }
        const d = [];
        if (r === null) return { defects: ['the page never loaded'], facts: {} };
        if (!r.statement) d.push('the no-WebGL statement is not shown');
        if (!/\/floor\/aimla$/.test(r.link ?? '')) d.push(`the statement links ${r.link}, not the 2-D floor`);
        if (r.stage !== 'hidden') d.push('the stage is not withdrawn');
        if (r.desks === 0) d.push('the list view lists no desk');
        if (r.path !== '/floor/aimla/3d') d.push(`the page left for ${r.path}`);
        return { defects: d, facts: r };
      } finally { b.close(); s.close(); }
    },
  },

  url: {
    what: 'the 3-D URL survives the first paint, a desk\'s panel and the back button, and a reload of the seat form opens that desk',
    base: { run: 'floor_rides' },
    plants: [
      ['`routeOf()` ignoring the route kind', { plant: [P('js/floor/floor-page.js', "return `/${['floor', floor, ...kind, ...(seat === null ? [] : [seat])]", "return `/${['floor', floor, ...(seat === null ? [] : [seat])]")] }],
      ['`seatSegment()` ignoring the route kind', { plant: [P('js/floor/floor-page.js', '        const at = 2 + kind.length;', '        const at = 2;')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const state = () => p.ev(`({ path: location.pathname, panel: !document.getElementById('floor-panel').hidden })`);
        const at = [];
        at.push(await state());
        await p.ev(`document.querySelector('.floor3d-name[data-key="aimla-pm"]').click()`);
        await sleep(800); at.push(await state());
        await p.ev(`document.getElementById('floor-panel-close').click()`);
        await sleep(500); at.push(await state());
        await p.ev('history.back()');
        await sleep(1200); at.push(await state());
        const want = [['/floor/aimla/3d', false], ['/floor/aimla/3d/aimla-pm', true], ['/floor/aimla/3d', false], ['/floor/aimla/3d/aimla-pm', true]];
        want.forEach(([path, panel], k) => { if (at[k].path !== path || at[k].panel !== panel) d.push(`step ${k}: ${JSON.stringify(at[k])}, not ${path} with the panel ${panel ? 'open' : 'closed'}`); });
        await p.b.page('Page.reload');
        let r = null;
        for (let t = 0; t < 200; t++) { await sleep(100); r = await state().catch(() => null); if (r?.panel) break; }
        await sleep(500); r = await state();
        if (r.path !== '/floor/aimla/3d/aimla-pm' || !r.panel) d.push(`after a reload of the seat form: ${JSON.stringify(r)}`);
        return { defects: d, facts: { steps: at, reload: r } };
      } finally { p.close(); }
    },
  },

  camera: {
    what: 'the camera: a drag down never takes the eye below the floor, a pan past the floor leaves the target in its box, a vertical pan slides along the floor, Fit returns to the home tilt, a wheel at the dolly limit scrolls the page',
    base: { run: quiet(), media: REDUCE },
    plants: [
      ['`maxPolarAngle` widened past 90°', { plant: [P('js/floor3d/view-limits.js', 'export const MAX_POLAR = (88 * Math.PI) / 180;', 'export const MAX_POLAR = (120 * Math.PI) / 180;')] }],
      ['the target clamp dropped', { plant: [P(PAINT, '        const t = controls.target;\n        const was = t.clone();', '        return;\n        const t = controls.target;\n        const was = t.clone();')] }],
      ['screen-space panning', { plant: [P(PAINT, '    controls.screenSpacePanning = false;', '    controls.screenSpacePanning = true;')] }],
      ['the wheel never released at a limit', { plant: [P(PAINT, "        controls.enableZoom = !(view !== null && event.deltaY !== 0 && atLimit);", '        controls.enableZoom = true;')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        // a drag down — tilting to the floor and past it
        await p.drag(0, -2 * p.stage.h);
        let i = await p.settle(600);
        f.lowest = i.eye[1];
        if (!(i.eye[1] > 0)) d.push(`a drag down left the eye at y ${i.eye[1].toFixed(3)} — at or below the floor`);
        // Fit: back to the home tilt
        await p.ev(`document.getElementById('floor-fit').click()`);
        i = await p.settle(600);
        if (Math.abs(deg(i.polar) - deg(HOME_POLAR)) > 0.5) d.push(`after Fit the polar is ${deg(i.polar).toFixed(2)}°, not ${deg(HOME_POLAR)}°`);
        // a vertical right-drag: along the floor, never up
        const before = i.target;
        await p.drag(0, 120, { button: 'right' });
        i = await p.settle(600);
        f.pan = [before, i.target];
        if (Math.abs(i.target[1] - before[1]) > 1e-6) d.push(`a vertical pan moved the target's y ${before[1]} → ${i.target[1]} — up the screen, not along the floor`);
        if (Math.abs(i.target[2] - before[2]) < 0.05) d.push('a vertical pan did not move the target along the floor');
        // a pan far past the floor: the target stays in its box
        for (let k = 0; k < 4; k++) await p.drag(3 * p.stage.w, 0, { button: 'right' });
        i = await p.settle(600);
        const box = i.limits.box;
        f.far = i.target;
        if (i.target[0] < box.min.x - 1e-6 || i.target[0] > box.max.x + 1e-6 || i.target[2] < box.min.z - 1e-6 || i.target[2] > box.max.z + 1e-6) d.push(`a pan past the floor left the target at ${i.target.map((v) => v.toFixed(2))}, outside its box`);
        // a wheel out at the dolly limit: the page scrolls, the camera does not move
        await p.ev(`document.getElementById('floor-fit').click()`);
        await p.wheel(400, 30);
        i = await p.settle(800);
        await p.ev('scrollTo(0, 0)');
        await sleep(200);
        const scroll0 = await p.ev('scrollY');
        const dist0 = i.distance;
        f.atMax = [i.distance, i.limits.distance.max];
        if (Math.abs(i.distance - i.limits.distance.max) > 1e-3) d.push(`CONTROL: thirty wheels out left the distance at ${i.distance.toFixed(3)}, not the limit ${i.limits.distance.max.toFixed(3)}`);
        await p.wheel(400, 3);
        i = await p.settle(800);
        const scroll1 = await p.ev('scrollY');
        f.scroll = [scroll0, scroll1];
        if (!(scroll1 > scroll0)) d.push('a wheel out at the dolly limit did not scroll the page');
        if (Math.abs(i.distance - dist0) > 1e-6) d.push('a wheel out at the dolly limit moved the camera');
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  cutaway: {
    what: 'the cut-away: with the target within a unit of the band, turned to the back, the band is lowered to its foot strip; turned to the front, it stands again; from the floor\'s centre it never lowers',
    base: { run: quiet(), media: REDUCE },
    plants: [['the cut-away\'s segment test dropped', { plant: [P(PAINT, '        const cut = cutaway(camera.position, controls.target, world);', '        const cut = new Set();')] }]],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        const turnBack = async () => {
          let i = await p.inspect();
          for (let k = 0; k < 400 && Math.abs(Math.abs(i.azimuth) - Math.PI) > 0.1; k++) { await p.key('ArrowLeft', 'ArrowLeft'); if (k % 4 === 3) i = await p.inspect(); }
          return p.settle(500);
        };
        const turnFront = async () => {
          let i = await p.inspect();
          for (let k = 0; k < 400 && Math.abs(i.azimuth) > 0.1; k++) { await p.key('ArrowRight', 'ArrowRight'); if (k % 4 === 3) i = await p.inspect(); }
          return p.settle(500);
        };
        // the control: from the centre, turned to the back, the eye sees over the band
        let i = await turnBack();
        f.centre = { band: i.band, az: deg(i.azimuth).toFixed(1) };
        if (i.band < i.limits.box.max.y - 1e-6) d.push(`turned to the back from the floor's centre, the band is lowered to ${i.band} — the derivation says the eye sees over it`);
        i = await turnFront();
        // the target slid toward the band, within a unit of it
        for (let k = 0; k < 300 && i.target[2] > i.limits.box.min.z + 0.8; k++) { await p.key('ArrowUp', 'ArrowUp', true); if (k % 4 === 3) i = await p.inspect(); }
        i = await p.settle(300);
        f.target = i.target;
        if (i.target[2] > i.limits.box.min.z + 1) d.push(`CONTROL: the target could not be brought within a unit of the band (z ${i.target[2].toFixed(2)})`);
        i = await turnBack();
        f.back = { band: i.band, az: deg(i.azimuth).toFixed(1) };
        if (Math.abs(i.band - L.CUTAWAY_H) > 1e-3) d.push(`turned to the back with the target by the band, the band stands ${i.band} tall — not lowered to ${L.CUTAWAY_H}`);
        if (argOf('--out') !== null && !(c.plant ?? []).length) writeFileSync(join(argOf('--out'), 'floor3d-cutaway.png'), await p.shot());
        i = await turnFront();
        f.front = { band: i.band };
        if (Math.abs(i.band - i.limits.box.max.y) > 1e-3) d.push(`turned to the front again, the band stands ${i.band} tall — not restored`);
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  loop: {
    what: 'the frame loop: it stops once nothing moves, restarts when a late kit file answers, wakes on a drag and draws it to rest at no more than a frame a display tick, and with only held loops running draws at most LOOP_FPS frames a second',
    base: { run: quiet(), media: REDUCE },
    plants: [
      ['the stop condition dropped', { plant: [P(PAINT, '        if (raf !== null || settling || tweening || next === 0) {', '        if (true) {')] }],
      ['the restart on a late resource dropped', { plant: [P(PAINT, "                ready: () => {\n                    onTerm(furnitureGroup, 'furniture');\n                    wake();", "                ready: () => {\n                    onTerm(furnitureGroup, 'furniture');")] }],
      ['the controls\' change not wired to the loop', { plant: [P(PAINT, "    controls.addEventListener('change', wake);", '')] }],
      ['the held loop\'s step sleep dropped (a frame each display refresh)', { plant: [P(PAINT, '            timer = window.setTimeout(() => {\n                timer = null;\n                wake();\n            }, next);', '            schedule();')] }],
      ['a settling frame queuing its successor past the one pending frame (two chains, doubling each tick)', { plant: [P(PAINT, '        if (raf !== null || settling || tweening || next === 0) {\n            schedule();', '        if (raf !== null || settling || tweening || next === 0) {\n            raf = window.requestAnimationFrame(frame);')] }],
    ],
    async run(chrome, c) {
      const d = [];
      const f = {};
      // 1 — the stop, and the restart on a late kit file: a page with nothing moving, its kit answering seconds late
      {
        const p = await open(chrome, { ...c, late: { '^/art/floor/models/.*\\.glb$': LATE_KIT_MS } }, { kit: false, w: 640, h: 480 });
        if (p.failed) return { defects: [p.failed], facts: {} };
        try {
          let i = await p.settle(2500);
          const rest = i.frames;
          await sleep(1200);
          i = await p.inspect();
          f.stopped = [rest, i.frames];
          if (i.frames !== rest) d.push(`with nothing moving, ${i.frames - rest} frame(s) were drawn in a second — the loop did not stop`);
          // the first-party pieces stand without a file; the kit's pieces add their calls when their files answer
          const pieces = i.terms.furniture.calls;
          if (p.glb.size === 0) d.push('CONTROL: no kit file had been asked for');
          let before = i.frames;
          for (let t = 0; t < 300 && i.terms.furniture.calls === pieces; t++) { before = i.frames; await sleep(100); i = await p.inspect(); }
          if (i.terms.furniture.calls === pieces) d.push('CONTROL: the kit never answered');
          i = await p.settle(600);
          f.late = { calls: [pieces, i.terms.furniture.calls], frames: [before, i.frames] };
          if (i.terms.furniture.calls > pieces && i.frames <= before) d.push('a kit file answered after the page settled and no frame was drawn — the piece is absent until a gesture');
        } finally { p.close(); }
      }
      // 2 — held loops (the run's working desks) with the camera still: their step rate, not the display's
      {
        const p = await open(chrome, { ...c, media: [] }, { w: 640, h: 480 });
        if (p.failed) return { defects: [p.failed], facts: {} };
        try {
          // held loops (the run's working desks, motion on) with the camera still: at most LOOP_FPS frames a second
          await p.settle(3000);
          const h0 = (await p.inspect()).frames;
          await sleep(HELD_WINDOW_S * 1000);
          const h1 = (await p.inspect()).frames;
          f.held = (h1 - h0) / HELD_WINDOW_S;
          if (f.held > LOOP_FPS + 1) d.push(`with the camera still and held loops running, ${f.held.toFixed(1)} frames a second were drawn — more than LOOP_FPS (${LOOP_FPS}) and a paint`);
          if (h1 === h0) d.push('CONTROL: no held loop drew a frame — the rest rate was not measured');
        } finally { p.close(); }
      }
      // 3 — a drag under damping wakes the loop and draws to rest: motion on, so damping is on, and no held loop runs
      {
        const p = await open(chrome, { ...c, run: stillDesks(), media: [] }, { w: 640, h: 480 });
        if (p.failed) return { defects: [p.failed], facts: {} };
        try {
          let i = await p.settle(2500);
          const a0 = i.azimuth;
          const fr0 = i.frames;
          await p.ev(TICKS);
          await sleep(200);
          let last = await p.ev(FRAMES_TICKS);
          await p.drag(200, 0, { steps: 6 });
          const released = await p.inspect();
          // damping eases the turn out over hundreds of frames: sample until a second passes with none drawn, summing
          // the frames and the display's ticks over every interval that drew one (an interval at rest adds ticks and
          // no frame, so counting it would only loosen the bound)
          const moving = { frames: 0, ticks: 0 };
          let still = 0;
          const t0 = Date.now();
          const tick0 = last.ticks;
          while (last.ticks - tick0 < DAMPING_REST_TICKS && Date.now() - t0 < DAMPING_REST_S * 1000 && still * DAMPING_SAMPLE_MS < 1000) {
            await sleep(DAMPING_SAMPLE_MS);
            const now = await p.ev(FRAMES_TICKS);
            if (now.frames > last.frames) { moving.frames += now.frames - last.frames; moving.ticks += now.ticks - last.ticks; still = 0; } else still++;
            last = now;
          }
          const waited = (Date.now() - t0) / 1000;
          i = await p.inspect();
          f.drag = { az: [deg(a0).toFixed(1), deg(released.azimuth).toFixed(1), deg(i.azimuth).toFixed(1)], frames: [fr0, released.frames, i.frames], moving, restedAfterS: waited.toFixed(1) };
          if (i.frames <= fr0) d.push('a drag drew no frame — the loop did not wake on the controls\' change');
          if (Math.abs(i.azimuth - a0) < 0.05) d.push('a drag turned nothing');
          if (still * DAMPING_SAMPLE_MS < 1000) d.push(`the loop was still drawing ${last.ticks - tick0} display ticks (${waited.toFixed(0)} s) after the drag — it never came to rest`);
          if (moving.frames > moving.ticks + TICK_SLACK) d.push(`a drag and its damped settle drew ${moving.frames} frames in ${moving.ticks} display ticks — more than one frame pending at once`);
          if (moving.ticks === 0) d.push('CONTROL: the tick counter never advanced while the turn settled — frames per tick was not measured');
        } finally { p.close(); }
      }
      return { defects: d, facts: f };
    },
  },

  labels: {
    what: 'the labels: the status reads the same from in front of its desk and from behind it; a Tab onto a desk\'s name moves the target to that desk; every desk is a row of the list view while a label behind the eye is not drawn',
    base: { run: quiet(), media: REDUCE },
    plants: [
      ['the status drawn on the monitor only, with no label', { plant: [P('js/floor3d/floor-labels.js', '            const wanted = [...world.labels]', "            const wanted = [...world.labels].filter((l) => l.kind !== 'status')")] }],
      ['a focused name leaving the target where it was', { plant: [P('js/floor3d/floor-labels.js', "            element.addEventListener('focus', () => focused(held.get(idOf(label)).desk));", '')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        // Tab from the stage onto the first desk's name
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        await p.key('Tab', 'Tab');
        await sleep(400);
        const focused = await p.ev(`document.activeElement?.dataset?.key ?? null`);
        let i = await p.settle(400);
        const desk = i.desks.find((x) => x.key === focused);
        f.focused = focused;
        if (desk === undefined) d.push(`a Tab from the stage focused ${focused}, not a desk's name`);
        else if (Math.abs(i.target[0] - (desk.left + desk.right) / 2) > 1e-3 || Math.abs(i.target[2] - (desk.back + desk.front) / 2) > 1e-3) d.push(`focusing ${focused}'s name left the target at ${i.target.map((v) => v.toFixed(2))}, not on its desk`);
        // the status from the front and from behind
        const status = async () => p.ev(`(() => { const e = document.querySelector('.floor3d-status[data-key="${focused}"]'); return e === null ? null : { text: e.textContent, w: Math.round(e.getBoundingClientRect().width), shown: e.style.display !== 'none' }; })()`);
        const front = await status();
        for (let k = 0; k < 400 && Math.abs(Math.abs(i.azimuth) - Math.PI) > 0.05; k++) { await p.key('ArrowLeft', 'ArrowLeft'); if (k % 4 === 3) i = await p.inspect(); }
        await sleep(400);
        const behind = await status();
        f.status = [front, behind];
        if (front === null || behind === null) d.push(`${focused} has no status label`);
        else if (front.text !== behind.text || front.w !== behind.w || !front.shown || !behind.shown) d.push(`the status read ${JSON.stringify(front)} in front and ${JSON.stringify(behind)} behind`);
        // a label behind the eye: the target on the front desk, the eye turned to the band's side of it, brought in close
        // and down to the floor's level — looking at the front edge, the back row is behind it
        const frontDesk = readingOrder(i.desks).at(-1);
        await p.ev(`document.querySelector('.floor3d-name[data-key="${frontDesk}"]').focus()`);
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        i = await p.inspect();
        for (let k = 0; k < 400 && Math.abs(Math.abs(i.azimuth) - Math.PI) > 0.05; k++) { await p.key('ArrowRight', 'ArrowRight'); if (k % 4 === 3) i = await p.inspect(); }
        await p.wheel(-400, 40);
        for (let k = 0; k < 60; k++) await p.key('ArrowDown', 'ArrowDown');
        await sleep(600);
        const labels = await p.ev(LABELS);
        const rows = await p.ev(`[...document.querySelectorAll('#floor-desks > li > a')].map((a) => decodeURIComponent(a.getAttribute('href').split('/').pop()))`);
        i = await p.inspect();
        f.hidden = labels.filter((l) => l[2] === 'none').length;
        if (f.hidden === 0) d.push('CONTROL: no label was behind the eye — the list leg measured nothing');
        for (const x of i.desks) if (!rows.includes(x.seat)) d.push(`${x.key} is not a row of the list view`);
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  face: {
    what: 'a desk\'s face is drawn: the face patch of the first desk in reading order, seen close from the front and above, holds the face\'s ink',
    base: { run: quiet(), media: REDUCE },
    plants: [['the face atlas dropped from the body\'s material', { plant: [P('js/floor3d/creature-paint.js', "    const material = new MeshToonMaterial({ map: open, vertexColors: true, alphaTest: 0.35 });", "    const material = new MeshToonMaterial({ vertexColors: true, alphaTest: 0.35 });")] }]],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const i0 = await p.inspect();
        const first = readingOrder(i0.desks)[0];
        await p.ev(`document.querySelector('.floor3d-name[data-key="${first}"]').focus()`);
        // in toward the desk while its sitter's face stays on the stage, and no closer than half the way to the limit
        let i = await p.settle(600);
        let face = i.faces.find((x) => x.key === first);
        // a face's point is the stage's own (CSS px from its top-left); the screenshot's is the page's
        const onStage = (x) => x !== undefined && x.x > 20 && x.x < p.stage.w - 20 && x.y > 20 && x.y < p.stage.h - 20;
        for (let k = 0; k < 30 && onStage(face) && i.distance > 3; k++) {
          await p.wheel(-100, 1);
          i = await p.settle(200);
          const next = i.faces.find((x) => x.key === first);
          if (!onStage(next)) { await p.wheel(100, 1); i = await p.settle(300); break; }
          face = next;
        }
        i = await p.settle(800);
        face = i.faces.find((x) => x.key === first);
        if (!onStage(face)) return { defects: [`CONTROL: ${first}'s face is not on the stage (${JSON.stringify(face)})`], facts: {} };
        // the canvas alone: a label over the face is DOM, and its ink is not the face's
        await p.ev(`document.getElementById('floor3d-labels').style.visibility = 'hidden'`);
        await sleep(200);
        const img = png(await p.shot());
        const r = 6;
        const at = { x: Math.round(p.stage.x + face.x), y: Math.round(p.stage.y + face.y) };
        let ink = 0;
        for (let y = at.y - r; y <= at.y + r; y++) {
          for (let x = at.x - r; x <= at.x + r; x++) {
            const o = (y * img.width + x) * 4;
            if (0.299 * img.rgba[o] + 0.587 * img.rgba[o + 1] + 0.114 * img.rgba[o + 2] < 90) ink++;
          }
        }
        if (ink === 0) d.push(`${first}'s face patch at ${at.x},${at.y} holds no ink — the face is blank`);
        if (argOf('--out') !== null) writeFileSync(join(argOf('--out'), `floor3d-face${(c.plant ?? []).length ? '-planted' : ''}.png`), await p.shot());
        return { defects: d, facts: { desk: first, at, distance: i.distance.toFixed(2), ink } };
      } finally { p.close(); }
    },
  },

  budget: {
    what: '§ 6\'s budget on the cap floor, each term against its own bound and then the total: draw calls, triangles, texture memory and the .glb transfer',
    base: { run: capFloor(), media: REDUCE },
    plants: [
      ['the character merge dropped (an intern\'s laptop parts each a call)', { plant: [P('js/floor3d/creature-paint.js', '        groups.push(mergeGeometries(laptop, false));', '        groups.push(...laptop);'), P('js/floor3d/creature-paint.js', '        materials.push(new MeshToonMaterial({ map: open, vertexColors: true, transparent: true, opacity: 1 }));', '        for (let i = 1; i < groups.length; i++) materials.push(new MeshToonMaterial({ map: open, vertexColors: true, transparent: true, opacity: 1 }));')] }],
      ['the furniture instancing dropped (a mesh a placement)', { plant: [P('js/floor3d/furniture.js', '        const mesh = new InstancedMesh(geometry, material, placements.length);\n\n        placements.forEach((m, i) => mesh.setMatrixAt(i, m));', '        for (const m of placements.slice(1)) { const one = new InstancedMesh(geometry, material, 1); one.setMatrixAt(0, m); group.add(one); meshes.push(one); }\n        const mesh = new InstancedMesh(geometry, material, 1);\n\n        placements.slice(0, 1).forEach((m, i) => mesh.setMatrixAt(i, m));')] }],
      ['the wall instancing dropped (a mesh a wall)', { plant: [P(PAINT, "        for (const kind of ['edge', 'partition']) {\n            const of = world.walls.filter((w) => w.kind === kind);", "        for (const kind of world.walls.map((w) => w.id)) {\n            const of = world.walls.filter((w) => w.id === kind);")] }],
      ['every part\'s facets ×4 (a lathe of 96 segments)', { plant: [P('js/floor3d/part-geometry.js', 'seat: Object.freeze({ lathe: 24, sphere: [16, 10], bead: [8, 6], tube: [2, 6], cone: 8, cylinder: 20, torus: [8, 24], curve: 4, bevel: 2 }),', 'seat: Object.freeze({ lathe: 96, sphere: [64, 40], bead: [32, 24], tube: [8, 24], cone: 32, cylinder: 80, torus: [32, 96], curve: 16, bevel: 8 }),'), P('js/floor3d/part-geometry.js', 'chibi: Object.freeze({ lathe: 12, sphere: [10, 7], bead: [6, 4], tube: [1, 4], cone: 6, cylinder: 10, torus: [5, 12], curve: 2, bevel: 1 }),', 'chibi: Object.freeze({ lathe: 48, sphere: [40, 28], bead: [24, 16], tube: [4, 16], cone: 24, cylinder: 40, torus: [20, 48], curve: 8, bevel: 4 }),')] }],
      ['every face at the 512 px bucket', { plant: [P('js/floor3d/creature-paint.js', 'export const FACE_TEXTURE = 256;', 'export const FACE_TEXTURE = 512;')] }],
      ['a 3 MB file added to the kind map', { plant: [P('art:floor/models/kenney-furniture-kit/map.js', "    desk: 'desk.glb',", "    desk: 'bulky.glb',")], files: { '/art/floor/models/kenney-furniture-kit/bulky.glb': Buffer.alloc(3 * 1048576) } }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c, { kit: false });
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        await sleep(4000);
        const i = await p.settle(1500);
        const d = [];
        const t = i.terms;
        const bytes = i.textures.reduce((n, x) => n + x.width * x.height * (x.float ? 16 : 4) * (4 / 3), 0);
        const mb = bytes / 1048576;
        const glb = [...p.glb.keys()].map((u) => u.split('/').pop().split('?')[0]);
        const sizeOf = (f) => (c.files?.[`/art/floor/models/kenney-furniture-kit/${f}`]?.length ?? statSync(join(KIT, f)).size);
        const glbMB = glb.reduce((n, f) => n + sizeOf(f), 0) / 1048576;
        const S = i.seats;
        const I = i.interns;
        const desks = i.desks.length;
        const total = Object.values(t).reduce((n, x) => n + x.calls, 0);
        if (S < desks || I < 8 * desks) d.push(`CONTROL: the cap floor drew ${S} seats and ${I} interns — not ${desks} desks each at the cap`);
        if (t.characters.calls > S + 2 * I) d.push(`characters ${t.characters.calls} calls > S + 2I = ${S + 2 * I}`);
        if (t.furniture.calls > 22 * K) d.push(`furniture ${t.furniture.calls} calls > 22 k = ${22 * K} (k = ${K}, the most primitives a kit file carries)`);
        if (t.walls.calls > BUDGET.walls) d.push(`walls ${t.walls.calls} calls > ${BUDGET.walls}`);
        if (t.fixed.calls > BUDGET.fixed) d.push(`fixed ${t.fixed.calls} calls > ${BUDGET.fixed}`);
        if (t.transient.calls > BUDGET.transient) d.push(`transient ${t.transient.calls} calls > ${BUDGET.transient}`);
        if (total > BUDGET.calls) d.push(`${total} draw calls > ${BUDGET.calls}`);
        if (i.triangles > BUDGET.triangles) d.push(`${i.triangles} triangles > ${BUDGET.triangles}`);
        if (mb > BUDGET.textureMB) d.push(`${mb.toFixed(1)} MB of textures > ${BUDGET.textureMB} MB`);
        if (glbMB > BUDGET.glbMB) d.push(`${glbMB.toFixed(2)} MB of .glb > ${BUDGET.glbMB} MB`);
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        if (argOf('--out') !== null && !(c.plant ?? []).length) writeFileSync(join(argOf('--out'), 'floor3d-cap.png'), await p.shot());
        const perDesk = (x) => (x / desks).toFixed(1);
        return { defects: d, facts: { desks, S, I, k: K, terms: t, calls: total, triangles: i.triangles, perDesk: { calls: perDesk(total), triangles: perDesk(i.triangles) }, textureMB: mb.toFixed(1), textures: i.textures.length, glbMB: glbMB.toFixed(3), glb: glb.length, memory: i.memory } };
      } finally { p.close(); }
    },
  },

  first: {
    what: 'printed, not asserted: each floor page\'s first drawn scene, ms from the page\'s start, with the API answering at once — the figure FLOOR.md § 12\'s *Cab hold* row divides by the serial import depth',
    base: { run: 'floor_rides' },
    plants: [],
    async run(chrome, c) {
      const s = await serve(c);
      const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)];
      const read = { '2d': [], '3d': [] };
      try {
        for (let k = 0; k < 3; k++) {
          for (const [kind, path, probe] of [['2d', '/floor/aimla', 'window.__ride?.scene?.at ?? null'], ['3d', '/floor/aimla/3d', 'window.__first3d ?? null']]) {
            const b = await browser(chrome, 1280, 800, 'never');
            try {
              // the 3-D page's first drawn scene: its first paint, which inserts the labels
              await b.page('Page.addScriptToEvaluateOnNewDocument', { source: "document.addEventListener('DOMContentLoaded', () => { const t = document.getElementById('floor3d-labels'); if (t) new MutationObserver(() => { if (window.__first3d === undefined && t.children.length > 0) window.__first3d = Math.round(performance.now()); }).observe(t, { childList: true }); });" });
              await b.page('Page.navigate', { url: s.base + path });
              let v = null;
              for (let t = 0; t < 400 && v === null; t++) { await sleep(50); v = await b.evaluate(probe).catch(() => null); }
              read[kind].push(v);
            } finally { b.close(); }
          }
        }
      } finally { s.close(); }
      const d = Object.entries(read).filter(([, xs]) => xs.includes(null)).map(([kind]) => `CONTROL: the ${kind} page drew no scene to time`);
      return { defects: d, facts: { firstSceneMs: { '2d': median(read['2d'].filter(Boolean)), '3d': median(read['3d'].filter(Boolean)) }, runs: read } };
    },
  },

  churn: {
    what: 'a body that leaves gives back what it held: once a seat has left by the elevator and returned, the renderer\'s textures and geometries are back at their count',
    base: { run: churn(), media: REDUCE },
    plants: [['a leaving body\'s dispose skipped', { plant: [P(PAINT, '                b.creature?.dispose();\n', '')] }]],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const at = async (ms) => { while ((await p.ev('performance.now()')) < ms) await sleep(200); return (await p.inspect()).memory; };
        const base = await at(CHURN.leave - 500);
        const gone = await at(CHURN.back - 500);
        const back = await at(CHURN.back + 4000);
        if (!(gone.textures < base.textures)) d.push(`with ${CHURN.seat} gone the renderer holds ${gone.textures} textures, not fewer than the ${base.textures} it held with it — the leaving body kept them`);
        if (back.textures !== base.textures || back.geometries !== base.geometries) d.push(`with ${CHURN.seat} back the renderer holds ${back.textures} textures and ${back.geometries} geometries, not the ${base.textures} and ${base.geometries} it held before it left`);
        return { defects: d, facts: { base, gone, back } };
      } finally { p.close(); }
    },
  },
};

// ── the runner ────────────────────────────────────────────────────────────────────────────────
const chrome = findChrome();
if (chrome === null) {
  console.error('FAIL — no headless Chromium (--chrome, $CHROME or ~/.cache/ms-playwright): this check never ran');
  process.exit(2);
}

if (argOf('--out') !== null) mkdirSync(argOf('--out'), { recursive: true });
const only = argv.flatMap((a, i) => (a === '--row' ? [argv[i + 1]] : []));
const rows = Object.entries(ROWS).filter(([id]) => only.length === 0 || only.includes(id));
if (rows.length !== (only.length === 0 ? Object.keys(ROWS).length : only.length)) { console.error(`FAIL — no row ${only.filter((id) => !(id in ROWS)).join(', ')}`); process.exit(2); }
const merged = (base, planted = {}) => ({ ...base, ...planted, plant: [...(base.plant ?? []), ...(planted.plant ?? [])], files: { ...(base.files ?? {}), ...(planted.files ?? {}) } });
let failed = 0;
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
      const out = await r.run(chrome, merged(r.base, planted)).catch((e) => (e instanceof StaleAnchor ? { stale: e } : { defects: [`the row threw: ${e.message}`] }));
      if (out.stale !== undefined) { console.log(`STALE ${id} under the plant: ${plant} — NOT PLANTED: ${out.stale.message}`); failed++; continue; }
      console.log(`${out.defects.length > 0 ? 'RED  ' : 'GREEN'} ${id} under the plant: ${plant}${out.defects.length > 0 ? ` — ${out.defects[0]}` : ` — the plant was NOT caught; facts: ${JSON.stringify(out.facts)}`}`);
      if (out.defects.length === 0) failed++;
    }
  }
}

console.log(failed === 0 ? 'PASS' : `FAIL — ${failed}`);
process.exit(failed === 0 ? 0 : 1);

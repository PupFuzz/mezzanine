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
//                                                      pose, a close view, a view with a wall cut away, a walk in
//                                                      from the entry to the back wall and one from a close view, the
//                                                      cap floor, the leaves open, the cab through them, a walker, the
//                                                      marks, a bird, the restroom door in its side wall and reduced
//                                                      motion
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

import { existsSync, readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
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
const { PAN_STEP_PX, turnOf } = await import(pathToFileURL(join(JS, 'wire', 'camera.js')).href);
const KITMAP = await import(pathToFileURL(join(KIT, 'map.js')).href);
const CLOCK = await import(pathToFileURL(join(JS, 'floor3d', 'clip-clock.js')).href);
const { CAB_SHADE } = await import(pathToFileURL(join(JS, 'floor', 'cab-paint.js')).href);
const CHARACTERS = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);
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
const BUDGET = { calls: 260, triangles: 150000, textureMB: 128, glbMB: 2, walls: 2, fixed: 20, transient: 20 };

/**
 * § 6's fixed term derived from the room's own members (plan § 6: *each counted from the world's own members*): a plane
 * per grid, the band, the contact discs, a screen per monitor, the doorway's cab and two leaves with a band, and each
 * restroom door's leaf and field.
 */
const fixedOf = (m) => m.planes + m.band + 1 + m.screens + 3 * m.band + 2 * m.restroom;
/** § 6's transient term derived from what is drawn: a bird and its envelope each a call, a ring and its singer each one,
 *  a screen's cross-fade one. */
const transientOf = (t, fading) => t.birds + t.envelopes + t.rings + t.singers + fading;

/** A run's stream messages moved this many ms later, so they arrive after the page has drawn and settled. */
const LATER_MS = 7000;
const later = (name) => {
  const r = structuredClone(fixtureRun(name));
  return { ...r, until_ms: (r.until_ms ?? 0) + LATER_MS, messages: r.messages.map((m) => ({ ...m, at_ms: m.at_ms + LATER_MS })) };
};

/**
 * The frame the clock names for a held body's read (plan § 3.5): `⌊T ÷ ms⌋` plus its key's offset, mod the loop's length —
 * derived here from the character tree and the clip clock's form table, never read back from the page.
 */
const expectedFrame = (r, ms) => {
  const form = CLOCK.HELD_FORMS[r.animation_id];
  const length = CLOCK.loopLength(CHARACTERS, form);
  return (Math.floor(r.t / ms) + CHARACTERS.loopOffset(r.key, length)) % length;
};
const LONG_TASK_MS = 1500;

/** For a picture only: the camera's target moved to a desk (its name focused) and the eye zoomed in `notches` steps. */
const closeUp = async (p, key, notches) => {
  await p.ev(`document.querySelector('.floor3d-name[data-key="${key}"]')?.focus()`);
  await p.ev(`(() => { const b = document.getElementById('floor-zoom-in'); for (let k = 0; k < ${notches}; k++) b.click(); })()`);
  // a damped dolly eases in over many frames
  await sleep(2500);
};

/**
 * The page's inspection once its frame loop has drawn what was asked and rested: no frame drawn in `ms`. A read straight
 * after a gesture can land between the controls' own update on a pointer move and the frame that clamps it — a pose no
 * frame draws — and on a loaded host that frame can be hundreds of ms late. Only for a row whose run moves nothing
 * held under motion, where the loop rests.
 */
const rested = async (p, ms = 400) => {
  let i = await p.inspect();
  for (let k = 0; k < 60; k++) {
    await sleep(ms);
    const next = await p.inspect();
    if (next.frames === i.frames) return next;
    i = next;
  }
  return i;
};

/** Primitives a kit file carries, read off its own JSON chunk — `k` of § 6's furniture term is the most of them. */
const primitives = (file) => {
  const b = readFileSync(join(KIT, file));
  const j = JSON.parse(b.subarray(20, 20 + b.readUInt32LE(12)).toString('utf8'));
  return j.meshes.reduce((n, m) => n + m.primitives.length, 0);
};
const K = Math.max(...Object.values(KITMAP.FILES).map(primitives));
/** § 6's furniture term: each of the 22 kinds and parts at most `k` calls, and a dressed kind's dressing one more. */
const DRESSINGS = KITMAP.DRESSED.length;
const FURNITURE = 22 * K + DRESSINGS;

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
  // `modifiers` is the protocol's bit field: 1 Alt, 2 Ctrl, 4 Meta, 8 Shift.
  const drag = async (dx, dy, { button = 'left', steps = 12, at = mid, modifiers = 0 } = {}) => {
    const buttons = button === 'left' ? 1 : 2;
    await mouse('mouseMoved', at.x, at.y, { modifiers });
    await mouse('mousePressed', at.x, at.y, { button, buttons, clickCount: 1, modifiers });
    for (let k = 1; k <= steps; k++) await mouse('mouseMoved', at.x + (dx * k) / steps, at.y + (dy * k) / steps, { button, buttons, modifiers });
    await mouse('mouseReleased', at.x + dx, at.y + dy, { button, buttons: 0, clickCount: 1, modifiers });
  };
  const wheel = (deltaY, n = 1) => (async () => { for (let k = 0; k < n; k++) await mouse('mouseWheel', mid.x, mid.y, { deltaX: 0, deltaY }); })();
  const key = async (k, code, shift = false) => {
    const mod = shift ? 8 : 0;
    await b.page('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, modifiers: mod, windowsVirtualKeyCode: { ArrowLeft: 37, ArrowUp: 38, ArrowRight: 39, ArrowDown: 40, Tab: 9, PageUp: 33, PageDown: 34 }[k] ?? 0 });
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
/** What the label layer holds, in document order, but the elevator's control (the `ride` row's): `[key, kind, display, text, width]`. */
const LABELS = `[...document.getElementById('floor3d-labels').children].filter((e) => e.dataset.key !== 'elevator').map((e) => [e.dataset.key, e.dataset.kind, e.style.display, e.textContent, e.getBoundingClientRect().width, e.tagName])`;
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
const CLIP = 'js/floor3d/clip-clock.js';
const TRANSIENT = 'js/floor3d/transient-paint.js';
const ROOM = 'js/floor3d/room-paint.js';
const BODIES = 'js/floor3d/body-paint.js';
const LOOP = 'js/floor3d/frame-loop.js';

// ── the rows ──────────────────────────────────────────────────────────────────────────────────
const ROWS = {
  still: {
    what: 'the page draws: no uncaught exception, a WebGL 2 canvas, the bodies, the furniture and the labels; the entry pose at the home tilt; every label one element in reading order, each name a link; the kit\'s files requested once each',
    base: { run: lateDesk() },
    plants: [
      ['the entry pose not set from its spherical figures (the camera left at the origin)', { plant: [P(PAINT, '            camera.position.set(position.x, position.y, position.z);\n            controls.update();\n            pose = { eye: position, target };', '            controls.update();\n            pose = { eye: position, target };')] }],
      ['a label appended after the render rather than inserted at its place', { plant: [P('js/floor3d/floor-labels.js', '                if (container.children[first + i] !== entry.element) {', '                if (false) {')] }],
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
      ['`routeOf()` ignoring the route kind', { plant: [P('js/floor/floor-page.js', '        return floorRoute(route, floor, seat);', "        return floorRoute('2d', floor, seat);")] }],
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
    what: 'the camera: a drag down never takes the eye below the floor, a right-drag and Shift + ArrowUp carry the eye into the office and stop it at the back wall, a pan across stops it at each side wall, a vertical pan slides along the floor, Fit returns to the home tilt, the zoom-in button brings the eye closer and zoom-out takes it back, Page Up / Page Down and Alt + a vertical drag raise and lower the eye and the target together within the room\'s height and turn nothing, and with the target on the floor Page Down lowers the eye alone, tilting toward level, and Page Up retraces it, Shift + a left-drag still pans along the floor, a wheel at the dolly limit scrolls the page',
    base: { run: quiet(), media: REDUCE },
    plants: [
      ['`maxPolarAngle` widened past 90°', { plant: [P('js/floor3d/view-limits.js', 'export const MAX_POLAR = (88 * Math.PI) / 180;', 'export const MAX_POLAR = (120 * Math.PI) / 180;')] }],
      ['the confinement dropped (the eye free)', { plant: [P(PAINT, '            pose = confineControls(controls, view.bound, pose);', '')] }],
      ['screen-space panning', { plant: [P('js/floor3d/view-limits.js', '    controls.screenSpacePanning = false;', '    controls.screenSpacePanning = true;')] }],
      ['a notch in handed to the controls as the distance times the notch', { plant: [P('js/floor3d/camera-acts.js', '            const scale = ZOOM_STEP ** -Math.abs(notches);', '            const scale = ZOOM_STEP ** Math.abs(notches);')] }],
      ['the wheel never released at a limit', { plant: [P(PAINT, "        controls.enableZoom = !(view !== null && event.deltaY !== 0 && atLimit);", '        controls.enableZoom = true;')] }],
      ['a rise that moves the target alone (the view turns)', { plant: [P('js/floor3d/camera-acts.js', '        position.set(position.x, position.y + both + alone, position.z);', '')] }],
      ['Page Down that stops at the floor (the eye never lowered alone)', { plant: [P('js/floor3d/camera-acts.js', '            both = Math.max(h, -target.y);', '            both = h;')] }],
      ['the Alt press left to the controls (Alt + drag turns)', { plant: [P(PAINT, '                        event.stopImmediatePropagation();\n', '')] }],
      ['the Alt drag never reaching the rise', { plant: [P(PAINT, '                        acts.rise(event.clientY - rising.y);', '')] }],
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
        // the zoom buttons: in brings the eye closer, out takes it back
        const fitted = i.distance;
        await p.ev(`document.getElementById('floor-zoom-in').click()`);
        i = await p.settle(400);
        f.zoom = [fitted, i.distance];
        if (!(i.distance < fitted - 1e-6)) d.push(`the zoom-in button took the eye from ${fitted.toFixed(3)} to ${i.distance.toFixed(3)} — not closer`);
        await p.ev(`document.getElementById('floor-zoom-out').click()`);
        i = await p.settle(400);
        f.zoom.push(i.distance);
        if (!(i.distance > f.zoom[1] + 1e-6)) d.push(`the zoom-out button took the eye from ${f.zoom[1].toFixed(3)} to ${i.distance.toFixed(3)} — not farther`);
        // Page Up / Page Down: the target and the eye lifted by one vector — angles and distance kept — within the box
        const sub = (a, b) => a.map((v, k) => v - b[k]);
        const kept = (what, a, b) => {
          const off = sub(sub(b.eye, b.target), sub(a.eye, a.target)).map(Math.abs);
          if (Math.max(...off) > 1e-6 || Math.abs(a.polar - b.polar) > 1e-6 || Math.abs(a.azimuth - b.azimuth) > 1e-6) d.push(`${what} turned or moved the eye apart from the target: offset change ${off.map((v) => v.toExponential(1))}, polar ${deg(a.polar).toFixed(3)}° → ${deg(b.polar).toFixed(3)}°, azimuth ${deg(a.azimuth).toFixed(3)}° → ${deg(b.azimuth).toFixed(3)}°`);
        };
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        let at = await p.inspect();
        for (let k = 0; k < 3; k++) await p.key('PageUp', 'PageUp');
        i = await p.settle(400);
        f.rise = [at.target[1], i.target[1]];
        if (!(i.target[1] > at.target[1] + 1e-3)) d.push(`three Page Ups took the target's y from ${at.target[1].toFixed(3)} to ${i.target[1].toFixed(3)} — not up`);
        kept('Page Up', at, i);
        at = i;
        const top = i.limits.bound.target.max.y;
        for (let k = 0; k < 400 && i.target[1] < top - 1e-6; k++) { await p.key('PageUp', 'PageUp'); if (k % 8 === 7) i = await p.inspect(); }
        i = await p.settle(300);
        f.top = i.target[1];
        if (Math.abs(i.target[1] - top) > 1e-6) d.push(`Page Up held left the target at y ${i.target[1].toFixed(3)}, not the room's height ${top}`);
        kept('Page Up to the top', at, i);
        at = i;
        // down to the floor, a press at a time: while the target is above it, the two move together
        for (let k = 0; k < 400 && i.target[1] > 1e-6; k++) { await p.key('PageDown', 'PageDown'); i = await rested(p, 250); }
        f.bottom = i.target[1];
        if (Math.abs(i.target[1]) > 1e-6) d.push(`Page Down held left the target at y ${i.target[1].toFixed(4)}, not the floor`);
        kept('Page Down to the floor', at, i);
        // THE CARVE-OUT (the operator's answer to card#13033's Q4): with the target on the floor, Page Down lowers the
        // eye alone — the target stays, the eye comes straight down, the view tilts toward level and faces the same way —
        // and Page Up retraces it, the eye back where it stood before the two lift together again
        at = i;
        for (let k = 0; k < 3; k++) await p.key('PageDown', 'PageDown');
        i = await rested(p);
        f.alone = { eye: [at.eye[1], i.eye[1]], polar: [deg(at.polar), deg(i.polar)], target: i.target[1] };
        if (Math.abs(i.target[1]) > 1e-6 || !(i.eye[1] < at.eye[1] - 1e-3) || !(i.polar > at.polar + 1e-6) || Math.abs(i.azimuth - at.azimuth) > 1e-6 || Math.abs(i.eye[0] - at.eye[0]) > 1e-6 || Math.abs(i.eye[2] - at.eye[2]) > 1e-6) {
          d.push(`three Page Downs with the target on the floor took the eye ${at.eye.map((v) => v.toFixed(3))} → ${i.eye.map((v) => v.toFixed(3))}, the target's y to ${i.target[1].toFixed(4)}, the polar ${deg(at.polar).toFixed(3)}° → ${deg(i.polar).toFixed(3)}° — not the eye alone straight down, tilting toward level`);
        }
        for (let k = 0; k < 3; k++) await p.key('PageUp', 'PageUp');
        i = await rested(p);
        f.retraced = { eye: i.eye[1], target: i.target[1] };
        if (Math.abs(i.target[1]) > 1e-6 || Math.max(...sub(i.eye, at.eye).map(Math.abs)) > 1e-6) d.push(`three Page Ups after them left the eye at ${i.eye.map((v) => v.toFixed(3))} and the target's y at ${i.target[1].toFixed(4)} — not back at ${at.eye.map((v) => v.toFixed(3))} with the target on the floor`);
        // Alt + a drag down the stage raises, up it lowers — and turns nothing
        at = i;
        await p.drag(0, 160, { modifiers: 1 });
        i = await p.settle(400);
        f.altRise = [at.target[1], i.target[1]];
        if (!(i.target[1] > at.target[1] + 1e-3)) d.push(`Alt + a drag down took the target's y from ${at.target[1].toFixed(3)} to ${i.target[1].toFixed(3)} — not up`);
        kept('Alt + a drag down', at, i);
        at = i;
        // a drag up lowering the target to within a pixel's rise of the floor, so the two move together (the controls'
        // pan rate, as `rise()` takes it) — past the floor the eye would go on alone, the carve-out above
        await p.drag(0, -Math.floor(at.target[1] * p.stage.h / (2 * at.distance * Math.tan(Math.PI / 8))), { modifiers: 1 });
        i = await p.settle(400);
        f.altRise.push(i.target[1]);
        if (!(i.target[1] < at.target[1] - 1e-3)) d.push(`Alt + a drag up took the target's y from ${at.target[1].toFixed(3)} to ${i.target[1].toFixed(3)} — not down`);
        kept('Alt + a drag up', at, i);
        // Shift + a left-drag: still the controls' pan, along the floor at the raised height
        at = i;
        await p.drag(0, 120, { modifiers: 8 });
        i = await p.settle(600);
        f.shiftPan = [at.target, i.target];
        if (Math.abs(i.target[1] - at.target[1]) > 1e-6 || Math.abs(i.target[2] - at.target[2]) < 0.05) d.push(`Shift + a left-drag moved the target ${at.target.map((v) => v.toFixed(2))} → ${i.target.map((v) => v.toFixed(2))} — not along the floor`);
        await p.ev(`document.getElementById('floor-fit').click()`);
        i = await p.settle(600);
        // a vertical right-drag: along the floor, never up
        const before = i.target;
        await p.drag(0, 120, { button: 'right' });
        i = await p.settle(600);
        f.pan = [before, i.target];
        if (Math.abs(i.target[1] - before[1]) > 1e-6) d.push(`a vertical pan moved the target's y ${before[1]} → ${i.target[1]} — up the screen, not along the floor`);
        if (Math.abs(i.target[2] - before[2]) < 0.05) d.push('a vertical pan did not move the target along the floor');
        // into the office: right-drags forward carry the eye in and stop it at the back wall's face; then across, to each
        // side wall — the eye past no bound after any drag, and its distance and angles kept
        const eyeOut = (what, at) => {
          const { min, max } = at.limits.bound.eye;
          if (at.eye[0] < min.x - 1e-6 || at.eye[0] > max.x + 1e-6 || at.eye[2] < min.z - 1e-6 || at.eye[2] > max.z + 1e-6) d.push(`${what} left the eye at ${at.eye.map((v) => v.toFixed(3))}, past the bound ${JSON.stringify(at.limits.bound.eye)}`);
        };
        const walk = async (what, dx, dy, until) => {
          let at = await p.inspect();
          const from = at;
          for (let k = 0; k < 12 && !until(at); k++) { await p.drag(dx, dy, { button: 'right' }); at = await rested(p); eyeOut(what, at); }
          // rule A: a walk keeps the eye's height, its pitch, the way it faces and its distance from the target
          if (Math.abs(at.eye[1] - from.eye[1]) > 1e-6 || Math.abs(at.distance - from.distance) > 1e-6 || Math.abs(at.polar - from.polar) > 1e-6 || Math.abs(at.azimuth - from.azimuth) > 1e-6) d.push(`${what} changed the eye's height ${from.eye[1].toFixed(3)} → ${at.eye[1].toFixed(3)}, the distance ${from.distance.toFixed(3)} → ${at.distance.toFixed(3)}, the pitch or the facing — not a walk`);
          return at;
        };
        await p.ev(`document.getElementById('floor-fit').click()`);
        i = await p.settle(600);
        const back = i.limits.bound.eye.min.z;
        i = await walk('a right-drag forward', 0, 300, (at) => at.eye[2] <= back + 1e-6);
        f.inside = i.eye;
        if (Math.abs(i.eye[2] - back) > 1e-6) d.push(`right-drags forward left the eye at z ${i.eye[2].toFixed(3)} — not in the office at the back wall's face (${back.toFixed(3)})`);
        if (argOf('--out') !== null && !(c.plant ?? []).length) writeFileSync(join(argOf('--out'), 'floor3d-walk-in.png'), await p.shot());
        for (const [dx, side] of [[p.stage.w / 2, 'min'], [-p.stage.w / 2, 'max']]) {
          const wall = i.limits.bound.eye[side].x;
          i = await walk(`a right-drag across to the ${side} side`, dx, 0, (at) => Math.abs(at.eye[0] - wall) < 1e-6);
          if (Math.abs(i.eye[0] - wall) > 1e-6 || Math.abs(i.eye[2] - back) > 1e-6) d.push(`right-drags across left the eye at ${i.eye.map((v) => v.toFixed(3))} — not in the ${side} corner (${wall.toFixed(3)}, ${back.toFixed(3)})`);
        }
        f.corner = i.eye;
        // Shift + ArrowUp from the entry: the keys' pan, the same way in
        await p.ev(`document.getElementById('floor-fit').click()`);
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        i = await p.settle(600);
        for (let k = 0; k < 400 && i.eye[2] > back + 1e-6; k++) { await p.key('ArrowUp', 'ArrowUp', true); if (k % 8 === 7) { i = await rested(p); eyeOut('Shift + ArrowUp', i); } }
        i = await rested(p);
        eyeOut('Shift + ArrowUp held', i);
        f.keysIn = i.eye;
        if (Math.abs(i.eye[2] - back) > 1e-6) d.push(`Shift + ArrowUp held left the eye at z ${i.eye[2].toFixed(3)} — not at the back wall's face (${back.toFixed(3)})`);
        // For pictures only: zoomed in from the entry and walked into the office with the keys, then on to the back wall.
        if (argOf('--out') !== null && !(c.plant ?? []).length) {
          await p.ev(`document.getElementById('floor-fit').click()`);
          await p.ev(`(() => { const b = document.getElementById('floor-zoom-in'); for (let k = 0; k < 9; k++) b.click(); })()`);
          await p.ev(`document.getElementById('floor-drawing').focus()`);
          for (let k = 0; k < 6; k++) await p.key('ArrowUp', 'ArrowUp', true);
          i = await rested(p);
          writeFileSync(join(argOf('--out'), 'floor3d-inside.png'), await p.shot());
          // and on to the back wall
          for (let k = 0; k < 200 && i.eye[2] > back + 1e-6; k++) { await p.key('ArrowUp', 'ArrowUp', true); if (k % 6 === 5) i = await rested(p); }
          await rested(p);
          writeFileSync(join(argOf('--out'), 'floor3d-walk-in-close.png'), await p.shot());
        }
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
    what: 'the cut-away: looking level across the floor from its front, a wall between the eye and the target is lowered to its foot strip, and stands again once the eye looks down from the entry; and the eye turned at the back wall\'s face stays in front of it',
    base: { run: quiet(), media: REDUCE },
    plants: [['the cut-away\'s segment test dropped', { plant: [P(ROOM, '        const cut = cutaway(eye, target, world);', '        const cut = new Set();')] }]],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        const lowered = (i) => i.walls.filter(([, h]) => Math.abs(h - L.CUTAWAY_H) < 1e-3).map(([id]) => id);
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        let i = await rested(p);
        f.entry = { lowered: lowered(i) };
        // level: the eye tilted down to the polar limit, then swept across the floor (Shift + an arrow) until its line to
        // the target runs through a wall in front of it
        for (let k = 0; k < 120 && i.polar < i.limits.polar.max - 1e-3; k++) { await p.key('ArrowDown', 'ArrowDown'); if (k % 8 === 7) i = await p.inspect(); }
        i = await rested(p);
        for (const [key, presses] of [['ArrowLeft', 40], ['ArrowRight', 80]]) {
          for (let k = 0; k < presses && lowered(i).length === 0; k++) { await p.key(key, key, true); i = await rested(p, 250); }
        }
        f.level = { polar: deg(i.polar).toFixed(1), target: i.target, lowered: lowered(i), walls: i.walls.length };
        if (lowered(i).length === 0) d.push(`looking level across the floor from its front and swept across it, no wall of ${i.walls.length} was lowered — the cut-away met nothing between the eye and the target`);
        if (argOf('--out') !== null && !(c.plant ?? []).length) writeFileSync(join(argOf('--out'), 'floor3d-cutaway.png'), await p.shot());
        // back to the entry: everything that was lowered stands again
        await p.ev(`document.getElementById('floor-fit').click()`);
        i = await rested(p);
        const still = i.walls.filter(([id, h]) => f.level.lowered.includes(id) && h < L.CUTAWAY_H + 1e-3);
        if (still.length > 0) d.push(`back at the entry, ${still.map(([id]) => id).join(', ')} still stand lowered`);
        // the eye brought to the back wall's face (Shift + ArrowUp), then turned to look along the wall each way
        const back = i.limits.bound.eye.min.z;
        for (let k = 0; k < 400 && i.eye[2] > back + 1e-6; k++) { await p.key('ArrowUp', 'ArrowUp', true); if (k % 8 === 7) i = await rested(p); }
        i = await rested(p);
        f.atWall = { eye: i.eye };
        if (Math.abs(i.eye[2] - back) > 1e-6) d.push(`CONTROL: Shift + ArrowUp did not bring the eye to the back wall's face (z ${i.eye[2].toFixed(3)}, the face's bound ${back.toFixed(3)})`);
        for (const key of ['ArrowLeft', 'ArrowRight', 'ArrowRight']) {
          for (let k = 0; k < 30; k++) await p.key(key, key);
          i = await rested(p);
          if (i.eye[2] < back - 1e-6) d.push(`turned with ${key} at the back wall, the eye stands at z ${i.eye[2].toFixed(3)} — behind the face's bound ${back.toFixed(3)}`);
        }
        f.turned = { eye: i.eye, az: deg(i.azimuth).toFixed(1) };
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  loop: {
    what: 'the frame loop: it stops once nothing moves, restarts when a late kit file answers, wakes on a drag and draws it to rest at no more than a frame a display tick, and with only held loops running draws at most LOOP_FPS frames a second',
    base: { run: quiet(), media: REDUCE },
    plants: [
      ['the stop condition dropped', { plant: [P(LOOP, '        if (raf !== null || next === 0) {', '        if (true) {')] }],
      ['the restart on a late resource dropped', { plant: [P(PAINT, "                ready: () => {\n                    onTerm(furnitureGroup, 'furniture');\n                    wake();", "                ready: () => {\n                    onTerm(furnitureGroup, 'furniture');")] }],
      ['the controls\' change not wired to the loop', { plant: [P(PAINT, "    controls.addEventListener('change', wake);", '')] }],
      ['the held loop\'s step sleep dropped (a frame each display refresh)', { plant: [P(LOOP, '            timer = window.setTimeout(() => {\n                timer = null;\n                wake();\n            }, next);', '            schedule();')] }],
      ['a settling frame queuing its successor past the one pending frame (two chains, doubling each tick)', { plant: [P(LOOP, '        if (raf !== null || next === 0) {\n            schedule();', '        if (raf !== null || next === 0) {\n            raf = window.requestAnimationFrame(frame);')] }],
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
      ['a focused name leaving the target where it was', { plant: [P('js/floor3d/floor-labels.js', '                focused({ x: (slab.left + slab.right) / 2, z: (slab.back + slab.front) / 2 }, seat);', '')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        // Tab from the stage onto the elevator's control, the layer's first, and on onto the first desk's name
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        await p.key('Tab', 'Tab');
        await sleep(200);
        f.first = await p.ev(`document.activeElement?.dataset?.key ?? null`);
        if (f.first !== 'elevator') d.push(`a Tab from the stage focused ${f.first}, not the elevator's control`);
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
    plants: [['the face left out of the face atlas (its open and shut sheets alike)', { plant: [P('js/floor3d/creature-paint.js', '        g.drawImage(face, layout.face.x, layout.face.y);', '')] }]],
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
        // the canvas alone: a label or a mark over the face is DOM, and its ink is not the face's
        await p.ev(`for (const id of ['floor3d-labels', 'floor3d-marks']) document.getElementById(id).style.visibility = 'hidden'`);
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
      ['the wall instancing dropped (a mesh a wall)', { plant: [P(ROOM, "        for (const kind of ['edge', 'partition']) {\n            const of = world.walls.filter((w) => w.kind === kind);", "        for (const kind of world.walls.map((w) => w.id)) {\n            const of = world.walls.filter((w) => w.id === kind);")] }],
      ['every part\'s facets ×4 (a lathe of 96 segments)', { plant: [P('js/floor3d/part-geometry.js', 'seat: Object.freeze({ lathe: 24, sphere: [16, 10], bead: [8, 6], tube: [2, 6], cone: 8, cylinder: 20, torus: [8, 24], curve: 4, bevel: 2 }),', 'seat: Object.freeze({ lathe: 96, sphere: [64, 40], bead: [32, 24], tube: [8, 24], cone: 32, cylinder: 80, torus: [32, 96], curve: 16, bevel: 8 }),'), P('js/floor3d/part-geometry.js', 'chibi: Object.freeze({ lathe: 12, sphere: [10, 7], bead: [6, 4], tube: [1, 4], cone: 6, cylinder: 10, torus: [5, 12], curve: 2, bevel: 1 }),', 'chibi: Object.freeze({ lathe: 48, sphere: [40, 28], bead: [24, 16], tube: [4, 16], cone: 24, cylinder: 40, torus: [20, 48], curve: 8, bevel: 4 }),')] }],
      ['a second cab room in the doorway', { plant: [P(ROOM, '        cab.geometry = cabGeometry(world.band.cab, world.band, cabRead);', "        cab.geometry = cabGeometry(world.band.cab, world.band, cabRead);\n        doorway.add(onTerm(new Mesh(cab.geometry, cab.material), 'fixed'));")] }],
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
        if (t.furniture.calls > FURNITURE) d.push(`furniture ${t.furniture.calls} calls > 22 k + ${DRESSINGS} = ${FURNITURE} (k = ${K}, the most primitives a kit file carries; a dressing each dressed kind)`);
        if (t.walls.calls > BUDGET.walls) d.push(`walls ${t.walls.calls} calls > ${BUDGET.walls}`);
        if (t.fixed.calls > BUDGET.fixed) d.push(`fixed ${t.fixed.calls} calls > ${BUDGET.fixed}`);
        if (t.fixed.calls > fixedOf(i.room.members)) d.push(`fixed ${t.fixed.calls} calls > the ${fixedOf(i.room.members)} its members count (${JSON.stringify(i.room.members)})`);
        if (t.transient.calls > BUDGET.transient) d.push(`transient ${t.transient.calls} calls > ${BUDGET.transient}`);
        if (t.transient.calls > transientOf(i.transient, i.room.fading)) d.push(`transient ${t.transient.calls} calls > the ${transientOf(i.transient, i.room.fading)} drawn`);
        if (total > BUDGET.calls) d.push(`${total} draw calls > ${BUDGET.calls}`);
        if (i.triangles > BUDGET.triangles) d.push(`${i.triangles} triangles > ${BUDGET.triangles}`);
        if (mb > BUDGET.textureMB) d.push(`${mb.toFixed(1)} MB of textures > ${BUDGET.textureMB} MB`);
        if (glbMB > BUDGET.glbMB) d.push(`${glbMB.toFixed(2)} MB of .glb > ${BUDGET.glbMB} MB`);
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        if (argOf('--out') !== null && !(c.plant ?? []).length) writeFileSync(join(argOf('--out'), 'floor3d-cap.png'), await p.shot());
        const perDesk = (x) => (x / desks).toFixed(1);
        return { defects: d, facts: { desks, S, I, k: K, furnitureBound: FURNITURE, members: i.room.members, fixedOf: fixedOf(i.room.members), terms: t, calls: total, triangles: i.triangles, perDesk: { calls: perDesk(total), triangles: perDesk(i.triangles) }, textureMB: mb.toFixed(1), textures: i.textures.length, glbMB: glbMB.toFixed(3), glb: glb.length, memory: i.memory } };
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

  clips: {
    what: 'the clock, on the page: every moving held body shows the loop frame its time since the paint and its key\'s offset name, its time SET from the latest paint even across a long task; two seats in one state at one instant differ in phase; a walker steps its walk at the frame its time names',
    base: { run: capFloor(), media: [] },
    plants: [
      ['the clock advanced by a capped delta a frame, not set', { plant: [P(BODIES, '        const since = now - paintedAt;\n', '        const since = (b.kept = Math.min(now - paintedAt, (b.kept ?? 0) + 50));\n')] }],
      ['the paint\'s instant not taken on a later paint', { plant: [P(BODIES, '            paintedAt = at;\n', '            paintedAt ||= at;\n')] }],
      ['the offset dropped from the loop\'s time', { plant: [P(CLIP, '    const frame = (Math.floor(since / ms) + characters.loopOffset(key, length)) % length;', '    const frame = Math.floor(since / ms) % length;')] }],
    ],
    async run(chrome, c) {
      const d = [];
      const f = {};
      const ms = 1000 / LOOP_FPS;
      const check = (i, when) => {
        const held = i.clock.filter((r) => r.kind === 'held' && r.frame !== null);
        if (held.length === 0) d.push(`CONTROL (${when}): no held body was moving — the clock was not read`);
        for (const r of held) {
          const since = r.now - i.paintedAt;
          if (Math.abs(r.t - since) > 1e-6) { d.push(`${when}: ${r.key}'s time is ${r.t.toFixed(1)} ms, not the ${since.toFixed(1)} ms since the latest paint — it was not set`); break; }
          const want = expectedFrame(r, ms);
          if (r.frame !== want) { d.push(`${when}: ${r.key} (${r.animation_id}) is on loop frame ${r.frame} at ${r.t.toFixed(0)} ms, not ${want}`); break; }
          const clip = CLOCK.HELD_FORMS[r.animation_id].clip;
          if (clip !== undefined && r.pose !== CHARACTERS.CLIPS[clip][want % CHARACTERS.CLIPS[clip].length]) { d.push(`${when}: ${r.key} shows ${r.pose}, not its clip's pose at frame ${want}`); break; }
        }
        return held;
      };
      {
        const p = await open(chrome, c, { kit: false, w: 960, h: 600 });
        if (p.failed) return { defects: [p.failed], facts: {} };
        try {
          let i = await p.settle(2500);
          const first = check(i, 'at rest');
          f.paints = i.paints;
          if (i.paints < 2) d.push(`CONTROL: the page painted ${i.paints} time(s) — a later paint's restart was not measured`);
          // two seats in one held state, their offsets apart over the loop: at one frame they show different loop frames
          const seats = first.filter((r) => !r.key.includes('~'));
          const pair = seats.flatMap((a) => seats.map((b) => [a, b])).find(([a, b]) => a.animation_id === b.animation_id && a.now === b.now && expectedFrame({ ...a, t: 0 }, ms) !== expectedFrame({ ...b, key: b.key, t: 0 }, ms));
          if (pair === undefined) d.push(`CONTROL: no two seats in one held state with offsets apart — the phase was not measured (${seats.map((r) => r.key).join(', ')})`);
          else if (pair[0].frame === pair[1].frame) d.push(`${pair[0].key} and ${pair[1].key} hold ${pair[0].animation_id} with offsets apart and show loop frame ${pair[0].frame} at one instant`);
          else f.pair = pair.map((r) => [r.key, r.animation_id, r.frame]);
          // a long task between two frames: the frame after it shows the frame its time names
          await p.ev(`(() => { const t = performance.now(); while (performance.now() - t < ${LONG_TASK_MS}) {} })()`);
          await sleep(400);
          i = await p.inspect();
          check(i, `after a ${LONG_TASK_MS} ms task`);
          f.read = i.clock.filter((r) => r.frame !== null).slice(0, 3).map((r) => [r.key, r.animation_id, r.frame, Math.round(r.t)]);
        } finally { p.close(); }
      }
      if (c.plant?.length) return { defects: d, facts: f };
      // a walker: its walk's pose at the frame its time names, and it moves between two frames
      {
        const p = await open(chrome, { ...c, run: later('leave-stale') }, { kit: false, w: 960, h: 600 });
        if (p.failed) return { defects: [...d, p.failed], facts: f };
        try {
          let w = null;
          for (let t = 0; t < 200 && w === null; t++) { await sleep(100); w = (await p.inspect()).clock.find((r) => r.kind === 'walker' && r.frame !== null) ?? null; }
          if (w === null) d.push('CONTROL: no walker was in flight — the walk was not read');
          else {
            await sleep(400);
            const i = await p.inspect();
            const r = i.clock.find((x) => x.key === w.key);
            const at = i.faces.find((x) => x.key === w.key);
            const want = CHARACTERS.CLIPS.walk[Math.floor(r.t / ms) % CHARACTERS.CLIPS.walk.length];
            if (r.frame !== null && (r.frame !== Math.floor(r.t / ms) || r.pose !== want)) d.push(`the walker ${r.key} at ${r.t.toFixed(0)} ms is on frame ${r.frame} showing ${r.pose}, not ${Math.floor(r.t / ms)} showing ${want}`);
            await sleep(300);
            const at2 = (await p.inspect()).faces.find((x) => x.key === w.key);
            if (at !== undefined && at2 !== undefined && at.x === at2.x && at.y === at2.y) d.push(`the walker ${r.key} stood still between two frames with motion on`);
            f.walker = [r.key, r.frame, r.pose];
          }
        } finally { p.close(); }
      }
      return { defects: d, facts: f };
    },
  },

  motion: {
    what: 'what moves off the bodies: a walker leaving by the elevator walks with the leaves opening over the cab and shut again, and is seen in the cab until they have; a sleeper\'s z\'s and a marker light in turn; the frame loop draws them at the loop\'s rate and stops once they have ended',
    base: { run: later('leave-stale'), media: [] },
    plants: [
      ['the elevator\'s leaves never moved', { plant: [P(ROOM, '        const shut = 1 - (1 - LEAF_OPEN) * (open ?? leafLevel(world.doors, f));', '        const shut = 1;')] }],
      ['a rider hidden the moment it leaves the floor', { plant: [P(BODIES, '            c.mesh.visible = p !== null && p.shown;', "            c.mesh.visible = p !== null && p.where === 'floor';")] }],
      ['the marks lit once and never stepped', { plant: [P(CLIP, 'characters.zLevels(f % characters.SLEEP_LOOP))', 'characters.zLevels(0))'), P(CLIP, 'characters.markerLevel(f % characters.SLEEP_LOOP)]', 'characters.markerLevel(0)]')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c, { kit: false });
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = { leaves: [] };
        const out = argOf('--out') !== null && !(c.plant ?? []).length ? argOf('--out') : null;
        // the marks before the walk, read across a whole pulse (`SLEEP_LOOP` loop frames): their levels step
        const reads = [];
        for (let k = 0; k < CHARACTERS.SLEEP_LOOP; k++) {
          reads.push(JSON.stringify((await p.inspect()).marks.filter((m) => m.kind !== 'thought').map((m) => m.levels)));
          await sleep(1000 / LOOP_FPS);
        }
        f.marks = [...new Set(reads)].length;
        if (out !== null) {
          const sleeper = (await p.inspect()).marks.find((m) => m.kind === 'zs');
          if (sleeper !== undefined) {
            await closeUp(p, sleeper.key, 9);
            writeFileSync(join(out, 'floor3d-marks.png'), await p.shot());
            await p.ev(`document.getElementById('floor-fit').click()`);
            await sleep(600);
          }
        }
        if (reads[0] === '[]') d.push('CONTROL: no sleeper or marker on the floor — the marks were not read');
        else if (f.marks === 1) d.push(`the marks read ${reads[0]} at every read across a whole pulse — they do not step`);
        let open = false, walked = false, shot = false, rode = 0;
        // the pictures: near the back desk the elevator stands by, the walker walking out from it
        if (out !== null) await closeUp(p, readingOrder((await p.inspect()).desks)[0], 6);
        for (let t = 0; t < 400; t++) {
          await sleep(100);
          const i = await p.inspect();
          const l = i.room.leaves;
          const walker = i.clock.find((r) => r.kind === 'walker' && r.frame !== null);
          walked ||= walker !== undefined;
          // a boarder in the cab with its leaves not yet shut is in sight, as the 2-D floor draws it behind them
          const rider = i.clock.find((r) => r.kind === 'walker' && r.where === 'cab' && r.frame !== null);
          if (rider !== undefined && l !== null && l[0][0] < l[0][1] - 1e-6) {
            rode++;
            if (!rider.shown) { d.push(`the rider ${rider.key} at ${rider.t.toFixed(0)} ms is hidden in the cab with its leaves at ${(l[0][0] / l[0][1]).toFixed(2)} of shut`); break; }
          }
          if (l !== null && l[0][0] < l[0][1] - 1e-6) {
            if (!open) f.leaves.push(l.map(([w, full]) => +(w / full).toFixed(2)));
            open = true;
            // the picture at the leaves' widest open (the 2-D leaves' `scaleX(.12)`), before they close again
            if (out !== null && !shot && l[0][0] / l[0][1] < 0.2) { writeFileSync(join(out, 'floor3d-leaf-open.png'), await p.shot()); shot = true; }
          }
          if (walker !== undefined && out !== null && !existsSync(join(out, 'floor3d-walker.png')) && walker.t > 2000) writeFileSync(join(out, 'floor3d-walker.png'), await p.shot());
          if (open && walked && walker === undefined && l !== null && Math.abs(l[0][0] - l[0][1]) < 1e-6) break;
        }
        const i = await p.inspect();
        f.end = i.room.leaves;
        if (!walked) d.push('CONTROL: no walker was in flight — the leaves were not read against a walk');
        else if (rode === 0 && !d.length) d.push('CONTROL: no rider was read in the cab with its leaves open — the cab was not read');
        f.rode = rode;
        if (!open) d.push('the walker left by the elevator and its leaves never opened');
        if (i.room.leaves !== null && Math.abs(i.room.leaves[0][0] - i.room.leaves[0][1]) > 1e-6) d.push('the leaves are not shut again once the walk has ended');
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  cab: {
    what: 'through the elevator\'s leaves, held open, the cab\'s room is seen, each of its surfaces in a colour of its own as the page resolves the 2-D cab\'s, and nothing of it outside the doorway — from the close view and from an eye raised over the band',
    // The leaves are SERVED held open, so the cab is looked at as long as the view takes on a loaded host — the `motion`
    // row holds that they open over a walk; this one is about what they open onto.
    base: { run: fixtureRun('leave-stale'), media: [], plant: [P(ROOM, '        const shut = 1 - (1 - LEAF_OPEN) * (open ?? leafLevel(world.doors, f));', '        const shut = LEAF_OPEN;')] },
    plants: [
      ['the cab never clipped to its doorway', { plant: [P(ROOM, 'new MeshBasicMaterial({ vertexColors: true, clippingPlanes: cabClip })', 'new MeshBasicMaterial({ vertexColors: true })')] }],
      ['every cab surface given the back wall\'s colour', { plant: [P(ROOM, "surfaces: Object.fromEntries(Object.entries(CAB_SURFACES).map(([name, [cls]]) => [name, bytes(style(cls).fill)])),", "surfaces: Object.fromEntries(Object.entries(CAB_SURFACES).map(([name]) => [name, bytes(style('cab-back').fill)])),")] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c, { kit: false });
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const out = argOf('--out') !== null && c.plant.length === 1 ? argOf('--out') : null;
        // the view the operator reads: close to the back desk the elevator stands by, slid along to the elevator before it
        await closeUp(p, readingOrder((await p.inspect()).desks)[0], 8);
        for (let k = 0; k < 3; k++) await p.key('ArrowLeft', 'ArrowLeft', true);
        const i = await p.settle(2500);
        const l = i.room.leaves;
        if (l === null || l[0][0] / l[0][1] > 0.2) return { defects: [`CONTROL: the leaves are not held open (${JSON.stringify(l)}) — the cab was not seen`], facts: {} };
        if (out !== null) writeFileSync(join(out, 'floor3d-cab-open.png'), await p.shot());
        const colours = i.room.cab;
        if (colours === null) return { defects: ['the cab\'s colours were never resolved — no cab was built behind the doorway'], facts: {} };
        const names = Object.keys(colours.surfaces);
        for (const [k, a] of names.entries()) {
          for (const b of names.slice(k + 1)) {
            if (colours.surfaces[a].join() === colours.surfaces[b].join()) d.push(`the cab's ${a} and ${b} resolve to one colour, rgb(${colours.surfaces[a].join(', ')}) — the surfaces do not separate`);
          }
        }
        // The far wall is one even tone (`CAB_SHADE.ceiling` of the ink over the back's colour), so a pixel of it inside the
        // box's projected rect but outside the opening's is the cab seen past its doorway. Read from this view and from one
        // raised over the band.
        const far = colours.surfaces.back.map((v, k) => v + (colours.ink[k] - v) * CAB_SHADE.ceiling);
        const stray = {};
        const seenFrom = async (pose) => {
          const at = await p.inspect();
          const img = png(await p.shot());
          // the rect of the opening, and of the whole box — the only place any of the cab can be drawn, so the band's own
          // picture elsewhere, whatever its colours, is never read
          const rect = (qs, m) => [Math.min(...qs.map((q) => q.x)) - m, Math.max(...qs.map((q) => q.x)) + m, Math.min(...qs.map((q) => q.y)) - m, Math.max(...qs.map((q) => q.y)) + m].map((v, k) => v + (k < 2 ? p.stage.x : p.stage.y));
          const [x0, x1, y0, y1] = rect(at.cab.opening, 2);
          const [bx0, bx1, by0, by1] = rect(at.cab.box, 0);
          let inside = 0, outside = 0;
          for (let y = Math.max(0, Math.floor(by0)); y < Math.min(img.height, by1); y++) {
            for (let x = Math.max(0, Math.floor(bx0)); x < Math.min(img.width, bx1); x++) {
              const o = (y * img.width + x) * 4;
              if (far.every((v, k) => Math.abs(img.rgba[o + k] - v) <= 2)) (x >= x0 && x <= x1 && y >= y0 && y <= y1 ? inside++ : outside++);
            }
          }
          stray[pose] = { inside, outside };
          if (inside === 0) d.push(`CONTROL: from the ${pose}, no pixel of the cab's far wall (rgb ${far.map(Math.round).join(', ')}) shows in its doorway — the wall was not found`);
          else if (outside > 0) d.push(`from the ${pose}, ${outside} pixels of the cab's far wall show outside its doorway's projected rect — the cab is seen past the band`);
        };
        await seenFrom('close view');
        for (let k = 0; k < 8; k++) await p.key('PageUp', 'PageUp');
        await p.settle(2500);
        await seenFrom('raised eye');
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        return { defects: d, facts: { cab: colours, stray } };
      } finally { p.close(); }
    },
  },

  birds: {
    what: 'a coordination round\'s birds fly between their desks — each a body and an envelope, two calls a bird, the transient term within its bound — and are gone after',
    base: { run: later('coord'), media: [] },
    plants: [
      ['a bird drawn as four meshes', { plant: [P(TRANSIENT, "                    drawn.push({ entry, bird: mesh(wings.down, birdMaterial), envelope: mesh(envelopeGeometry, envelopeMaterial), ring: null, ringMaterial: null });", "                    drawn.push({ entry, bird: mesh(wings.down, birdMaterial), envelope: mesh(envelopeGeometry, envelopeMaterial), ring: null, ringMaterial: null });\n                    for (let k = 0; k < 3; k++) { const x = mesh(wings.up, birdMaterial); x.visible = true; x.position.set(entry.from.x, entry.from.y, entry.from.z); }")] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c, { kit: false });
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = { most: 0 };
        let seen = false, shot = false;
        if (argOf('--out') !== null && !(c.plant ?? []).length) await closeUp(p, readingOrder((await p.inspect()).desks)[0], 7);
        const light = () => p.ev(`document.getElementById('floor3d-canvas').inspect({ terms: false })`);
        for (let t = 0; t < 600; t++) {
          await sleep(50);
          // a cheap read to catch a flight, and the full one — every term rendered alone — once a bird is drawn
          if ((await light()).transient.birds === 0 && !seen) continue;
          const i = await p.inspect();
          const want = transientOf(i.transient, i.room.fading);
          if (i.transient.birds > 0) {
            seen = true;
            f.most = Math.max(f.most, i.terms.transient.calls);
            if (i.terms.transient.calls > want) { d.push(`with ${i.transient.birds} birds, ${i.transient.envelopes} envelopes and ${i.transient.rings} rings drawn the transient term is ${i.terms.transient.calls} calls, more than their ${want}`); break; }
            if (i.terms.transient.calls > BUDGET.transient) { d.push(`the transient term is ${i.terms.transient.calls} calls > ${BUDGET.transient}`); break; }
            if (!shot && argOf('--out') !== null && !(c.plant ?? []).length) {
              const b = i.transient;
              writeFileSync(join(argOf('--out'), 'floor3d-bird.png'), await p.shot());
              shot = true; f.shot = b;
            }
          } else if (seen && i.transient.envelopes === 0 && i.transient.rings === 0) break;
        }
        if (!seen) d.push(`CONTROL: no bird was drawn — the round flew nothing here${p.errors.length > 0 ? ` (uncaught: ${p.errors[0]})` : ''}`);
        const i = await p.inspect();
        f.after = i.terms.transient.calls;
        if (seen && i.transient.birds + i.transient.envelopes + i.transient.rings > 0) d.push(`after the round the transient drawings are still drawn: ${JSON.stringify(i.transient)}`);
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  restroom: {
    what: 'a restroom door set into its side wall (the shipped default\'s, `right`): the wall over its doorway drawn as lintels from their box\'s foot; its leaf drawn at the world\'s box in the wall\'s thickness, as long as it is shut from the back jamb, and its field centred on the flag\'s plane; with the eye low inside the room facing that side wall and the target past it, every lintel of a lowered column lowered to nothing with it',
    base: { run: fixtureRun('idle-visit'), media: [] },
    plants: [
      ['the lintel stood on the floor, not at its box\'s foot', { plant: [P(ROOM, 'makeTranslation(w.box.x, w.box.y, w.box.z)', 'makeTranslation(w.box.x, 0, w.box.z)')] }],
      ['the side leaf laid across the room as a face-on door\'s, not in the wall', { plant: [P(ROOM, '                leaf.scale.set(b.w, b.h, b.d * shut);', '                leaf.scale.set(b.d * shut, b.h, b.w);')] }],
      ['a lintel lowered only when the eye meets it, not with its column', { plant: [P(ROOM, ': toward(w.h, w.box.h, cut.has(id) || w.column.some((c) => cut.has(c)), 0);', ': toward(w.h, w.box.h, cut.has(id), 0);')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c);
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        const E = 1e-6;
        const out = argOf('--out') !== null && !(c.plant ?? []).length ? argOf('--out') : null;
        const quick = () => p.ev(`document.getElementById('floor3d-canvas').inspect({ terms: false })`);
        let i = await quick();
        const side = () => i.room.restroom.filter((r) => r.facing !== 'back');
        if (side().length === 0 || i.room.lintels.length === 0) return { defects: [`CONTROL: no restroom door set into a side wall, or no lintel, on the page — ${JSON.stringify(i.room.restroom.map((r) => r.facing))}, ${i.room.lintels.length} lintel(s)`], facts: {} };
        f.doors = side().map((r) => r.id);
        f.lintels = i.room.lintels.length;
        for (const l of i.room.lintels) {
          if (Math.abs(l.foot - l.y) > E || Math.abs(l.h - l.full) > E) d.push(`the lintel ${l.id} is drawn from ${l.foot} up ${l.h} units — not from its box's foot ${l.y} up its full ${l.full}`);
        }
        // the leaf in the wall's thickness at the world's box, as long as it is shut from the back jamb, and the field
        // centred on the flag's plane — read across the page's paints, each of which stands the doors' meshes afresh and
        // the next frame places them, so a box counts once a frame has placed it
        let read = 0;
        for (let t = 0; t < 100 && read < 10; t++) {
          await sleep(200);
          i = await quick();
          if (!side().every((r) => r.drawn.leaf.slice(3).some((v) => v !== 1))) continue;
          read++;
          for (const r of side()) {
            const [x, y, z, sx, sy, sz] = r.drawn.leaf;
            const b = r.drawn.bar;
            if ([x - b.x, y - b.y, z - b.z, sx - b.w, sy - b.h].some((v) => Math.abs(v) > E) || !(sz > 0 && sz <= b.d + E)) {
              d.push(`${r.id}'s leaf is drawn at ${JSON.stringify(r.drawn.leaf)} — not its box ${JSON.stringify(b)} in the wall, as long as it is shut from the back jamb`);
            }
            const [fx, fy, fz, fw, fh, fd] = r.drawn.field;
            const s = r.drawn.sign;
            if ([fx - s.x, fy - s.y, fw - s.w, fh - s.h, fz + fd / 2 - s.z].some((v) => Math.abs(v) > E)) {
              d.push(`${r.id}'s field is drawn at ${JSON.stringify(r.drawn.field)} — not centred on the flag's plane at ${JSON.stringify(s)}`);
            }
          }
          if (d.length > 0) break;
        }
        f.reads = read;
        if (read === 0) d.push('CONTROL: no frame placed the side door\'s leaf and field — they were not read');
        // seen through the side wall: the eye turned to the wall's outside (`+x`, azimuth +90°), the segment to the target
        // meets that wall's cells, and every lintel of a column with a lowered wall is lowered to nothing
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        // the azimuth turned to `az` by the arrow keys, each press read once its damped turn has settled
        const turnTo = async (az) => {
          const off = async () => { await sleep(400); i = await quick(); return Math.abs(Math.atan2(Math.sin(i.azimuth - az), Math.cos(i.azimuth - az))); };
          let gap = await off();
          await p.key('ArrowLeft', 'ArrowLeft');
          const key = (await off()) < gap ? 'ArrowLeft' : 'ArrowRight';
          gap = await off();
          for (let k = 0; k < 120 && gap > 0.15; k++) {
            await p.key(key, key);
            const next = await off();
            if (next > gap) break;
            gap = next;
          }
          await sleep(1200);
          i = await quick();
        };
        // the target slid to `goal` (the door, by default) by Shift + the arrows: a press kept where it brings the target
        // nearer once its damped pan has settled, and undone by the opposite key where it does not
        const toDoor = async (goal = side()[0].at) => {
          const opposite = { ArrowLeft: 'ArrowRight', ArrowRight: 'ArrowLeft', ArrowUp: 'ArrowDown', ArrowDown: 'ArrowUp' };
          const gapNow = async () => { await sleep(900); i = await quick(); return Math.hypot(goal.x - i.target[0], i.target[1], goal.z - i.target[2]); };
          let gap = await gapNow();
          for (let k = 0; k < 40 && gap > 0.3; k++) {
            let moved = false;
            for (const key of Object.keys(opposite)) {
              await p.key(key, key, true);
              const next = await gapNow();
              if (next < gap - 0.05) { gap = next; moved = true; break; }
              await p.key(opposite[key], opposite[key], true);
              gap = await gapNow();
            }
            if (!moved) break;
          }
          f.pan = [...(f.pan ?? []), { goal: [goal.x, goal.z].map((v) => +v.toFixed(2)), at: i.target.map((v) => +v.toFixed(2)) }];
        };
        // seen through the side wall from inside the room: the eye cannot stand outside a side wall (FLOOR.md § 4.5),
        // so the view faces the door's wall from the room — the target at the door, the eye turned to the room's side of
        // it, brought in close and low, then walked at the wall (Shift + ArrowUp) until the eye stands at its bound. The
        // target, ahead of the eye along the floor, is then past the wall, and the eye's line to it crosses the door's
        // column below its top.
        const door = side()[0].at;
        // turned from the entry to face the wall: a quarter turn in arrow presses, each the keys' own step
        await p.ev(`document.getElementById('floor-fit').click()`);
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        const quarter = Math.round(Math.PI / 2 / turnOf(PAN_STEP_PX, p.stage.w));
        for (let k = 0; k < quarter; k++) { const key = door.x > 0 ? 'ArrowLeft' : 'ArrowRight'; await p.key(key, key); }
        await sleep(1500);
        // the target along the door's wall a unit and a half from its centre, clear of the doorway, so the line crosses a
        // full wall cell of the door's column, not its opening
        await toDoor({ x: door.x, z: door.z - 1.5 });
        for (let k = 0; k < 30 && i.distance > 1.8; k++) { await p.ev(`document.getElementById('floor-zoom-in').click()`); await sleep(300); i = await quick(); }
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        for (let k = 0, was = null; k < 60 && (was === null || Math.abs(i.eye[0] - was) > 1e-6); k++) { was = i.eye[0]; await p.key('ArrowUp', 'ArrowUp', true); await sleep(500); i = await quick(); }
        await sleep(1500);
        i = await quick();
        const lowered = i.room.lintels.filter((l) => l.column);
        f.through = { az: deg(i.azimuth).toFixed(1), eye: i.eye.map((v) => +v.toFixed(2)), target: i.target.map((v) => +v.toFixed(2)), columnLowered: lowered.length, standing: lowered.filter((l) => l.h > E).map((l) => l.id) };
        if (lowered.length === 0) d.push(`CONTROL: turned to ${deg(i.azimuth).toFixed(1)}°, the eye lowered no wall of a side door's column — the column rule was not read`);
        else if (f.through.standing.length > 0) d.push(`seen through the side wall with its column lowered, the lintels ${f.through.standing.join(', ')} still stand — floating over the lowered wall`);
        // the picture: from inside the room, beside the door, close
        if (out !== null) {
          for (let k = 0; k < 8; k++) await p.key('PageUp', 'PageUp');
          await turnTo(-0.7);
          await toDoor();
          await p.ev(`(() => { const b = document.getElementById('floor-zoom-in'); for (let k = 0; k < 12; k++) b.click(); })()`);
          await sleep(2500);
          writeFileSync(join(out, 'floor3d-restroom-door.png'), await p.shot());
          for (let k = 0; k < 4; k++) await p.key('PageUp', 'PageUp');
          await sleep(1500);
          writeFileSync(join(out, 'floor3d-restroom-door-raised.png'), await p.shot());
        }
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  ride: {
    what: 'the ride from the 3-D floor (FLOOR.md § 4.5): a drag across the doorway turns the camera and rides nothing; a click on the drawn doorway opens the leaves and then the cab dialog, its doors drawn by the one cab path; a floor\'s stop navigates to that floor\'s 3-D route, which arrives; and the elevator\'s keyboard control opens the cab on Enter',
    base: { run: quiet() },
    plants: [
      ['the doorway not a pointer target', { plant: [P(PAINT, '        return first !== undefined && room.isDoorway(first.object);', '        return false;')] }],
      ['a 3-D start arriving on the 2-D route (the page handing the screen the 2-D route kind)', { plant: [P('js/floor/floor-page.js', '        route,\n        reduce:', "        route: '2d',\n        reduce:")] }],
      ['the cab drawn by nothing (the renderer\'s own empty `paintCab`)', { plant: [P(PAINT, '                paintCab,', '                paintCab() {},')] }],
      ['the elevator\'s control with no keyboard route', { plant: [P('js/floor3d/floor-labels.js', "        if (event.key === 'Enter' || event.key === ' ') {", '        if (false) {')] }],
    ],
    async run(chrome, c) {
      const p = await open(chrome, c, { kit: false });
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        const f = {};
        const cab = () => p.ev(`(() => { const c = document.getElementById('floor-cab'); return { open: c.open, drawn: document.querySelector('#floor-cab-doors svg.cab-scene') !== null, path: location.pathname }; })()`);
        let i = await p.settle(800);
        if (i.doorway === null) return { defects: ['the page draws no doorway — the ride was not read'], facts: {} };
        const at = { x: p.stage.x + i.doorway.x, y: p.stage.y + i.doorway.y };
        f.doorway = at;
        // a drag that starts on the doorway is the camera's, not a press
        const before = i.azimuth;
        await p.drag(80, 0, { at });
        i = await p.settle(800);
        f.dragged = [before, i.azimuth];
        if (Math.abs(i.azimuth - before) < 1e-3) d.push('CONTROL: a drag across the doorway did not turn the camera — the drag was not read');
        if ((await cab()).open) d.push('a drag across the doorway opened the cab');
        // the camera back to the entry pose, so the doorway is where it was drawn
        await p.ev(`document.getElementById('floor-fit').click()`);
        i = await p.settle(1500);
        const door = { x: p.stage.x + i.doorway.x, y: p.stage.y + i.doorway.y };
        await p.b.page('Input.dispatchMouseEvent', { type: 'mouseMoved', x: door.x, y: door.y });
        await p.b.page('Input.dispatchMouseEvent', { type: 'mousePressed', x: door.x, y: door.y, button: 'left', buttons: 1, clickCount: 1 });
        await p.b.page('Input.dispatchMouseEvent', { type: 'mouseReleased', x: door.x, y: door.y, button: 'left', buttons: 0, clickCount: 1 });
        // the leaves open before the cab: read them while the ride is `opening`
        let opened = false;
        let c1 = null;
        for (let t = 0; t < 40; t++) {
          await sleep(100);
          const r = (await p.inspect()).room.leaves;
          opened ||= r !== null && r[0][0] < r[0][1] * 0.9;
          c1 = await cab();
          if (c1.open) break;
        }
        f.clicked = { opened, cab: c1 };
        if (!opened) d.push('a click on the doorway did not open the leaves');
        if (!c1.open) d.push('a click on the doorway did not open the cab');
        else if (!c1.drawn) d.push('the cab opened with nothing drawn behind its doors — not the one cab path');
        // inside: the stops answer; a real click on another floor's stop
        let stop = null;
        for (let t = 0; t < 40 && stop === null; t++) {
          await sleep(100);
          stop = await p.ev(`(() => { if (document.getElementById('floor-cab-panel').inert) return null; const b = document.querySelector('#floor-cab-stops li[data-stop="kappa"] button'); if (b === null) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`).catch(() => null);
        }
        if (stop === null) d.push('the cab\'s buttons never answered, or it offers no stop for kappa');
        else {
          await p.b.page('Input.dispatchMouseEvent', { type: 'mousePressed', x: stop.x, y: stop.y, button: 'left', buttons: 1, clickCount: 1 });
          await p.b.page('Input.dispatchMouseEvent', { type: 'mouseReleased', x: stop.x, y: stop.y, button: 'left', buttons: 0, clickCount: 1 });
          let path = null;
          for (let t = 0; t < 80; t++) {
            await sleep(100);
            path = await p.ev('location.pathname').catch(() => null);
            if (path !== null && path !== '/floor/aimla/3d') break;
          }
          f.arrived = path;
          if (path !== '/floor/kappa/3d') d.push(`the ride to kappa arrived at ${path}, not /floor/kappa/3d`);
        }
        // the keyboard route, on the floor the ride arrived at: Tab from the stage to the elevator, Enter
        for (let t = 0; t < 100; t++) { await sleep(100); if ((await p.ev(`document.querySelector('#floor3d-labels [data-key="elevator"]') !== null && document.getElementById('floor-cab').open === false`).catch(() => false))) break; }
        await sleep(1500);
        await p.ev(`document.getElementById('floor-drawing').focus()`);
        await p.key('Tab', 'Tab');
        await sleep(200);
        f.focused = await p.ev(`document.activeElement?.dataset?.key ?? null`);
        if (f.focused !== 'elevator') d.push(`a Tab from the stage focused ${f.focused}, not the elevator's control`);
        await p.b.page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await p.b.page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        let c2 = null;
        for (let t = 0; t < 40; t++) { await sleep(100); c2 = await cab(); if (c2.open) break; }
        f.keyed = c2;
        if (!c2.open) d.push('Enter on the elevator\'s control did not open the cab');
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        return { defects: d, facts: f };
      } finally { p.close(); }
    },
  },

  reduced: {
    what: 'under reduced motion every held body is its still — no loop frame, no step asked for — the marks at full brightness, a walk drawn at its end, and the frame loop at rest',
    base: { run: later('leave-stale'), media: REDUCE },
    plants: [['the clock stepping a held loop under reduced motion (keyed on the row, not its rendering\'s motion, at the loop\'s rate)', { plant: [P(CLIP, "    const moving = form !== undefined && held.motion === true && typeof held.frame_interval_ms === 'number';", "    const moving = form !== undefined;"), P(CLIP, '    const ms = held.frame_interval_ms;', '    const ms = held.frame_interval_ms ?? 1000 / 4;')] }]],
    async run(chrome, c) {
      const p = await open(chrome, c, { kit: false });
      if (p.failed) return { defects: [p.failed], facts: {} };
      try {
        const d = [];
        let i = await p.settle(2000);
        if (argOf('--out') !== null && !(c.plant ?? []).length) {
          await closeUp(p, readingOrder(i.desks)[0], 5);
          writeFileSync(join(argOf('--out'), 'floor3d-reduced.png'), await p.shot());
          i = await p.settle(1500);
        }
        const held = i.clock.filter((r) => r.kind === 'held');
        if (held.length === 0) d.push('CONTROL: no held body was read');
        const stepping = held.filter((r) => r.frame !== null);
        if (stepping.length > 0) d.push(`${stepping[0].key} (${stepping[0].animation_id}) is on loop frame ${stepping[0].frame} under reduced motion — not its still`);
        const dim = i.marks.filter((m) => m.kind !== 'thought' && m.levels.some((l) => l !== 3));
        if (dim.length > 0) d.push(`a mark reads levels ${dim[0].levels.join('')} under reduced motion — not every glyph at full brightness`);
        if (i.marks.filter((m) => m.kind !== 'thought').length === 0) d.push('CONTROL: no mark on the floor — the still marks were not read');
        // a paint (a delivered message) draws its one frame; nothing else may
        const rest = i.frames;
        const painted = i.paints;
        await sleep(1500);
        i = await p.inspect();
        if (i.frames - rest > i.paints - painted) d.push(`with motion reduced and the camera still, ${i.frames - rest} frame(s) were drawn in 1.5 s over ${i.paints - painted} paint(s) — the loop did not rest`);
        // the walk's row arrives: drawn at its end, nothing in flight
        await sleep(LATER_MS);
        i = await p.inspect();
        if (i.clock.some((r) => r.kind === 'walker' && r.frame !== null)) d.push('a walker steps under reduced motion');
        if (p.errors.length > 0) d.push(`uncaught: ${p.errors[0]}`);
        return { defects: d, facts: { held: held.length, marks: i.marks.map((m) => [m.kind, m.levels.join('')]) } };
      } finally { p.close(); }
    },
  },

  churn: {
    what: 'a body that leaves gives back what it held: once a seat has left by the elevator and returned, the renderer\'s textures and geometries are back at their count',
    base: { run: churn(), media: REDUCE },
    plants: [['a leaving body\'s dispose skipped', { plant: [P(BODIES, '                b.creature?.dispose();\n', '')] }]],
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

/**
 * card#12450 — AN INTERN'S PAINTED WALK, OVER A FAKE DOM: what `createPainter().paint()` writes for one intern A10
 * walker, painted at each of the given instants as the page repaints it, so `AnInternRidesTheElevatorTest` can read
 * the start the painter pinned on the walker's glide, on the laptop drawn over it through its `seat` leg, and on the
 * elevator's leaves open over the walks' door spans. A repaint re-creates every one of those nodes, so an animation
 * the painter does not pin restarts from its full delay on every paint, or starts whenever the browser gets round to
 * laying the paint out; the fake's animations have no start (`null`) unless the painter pins one. `node`, no
 * dependencies.
 *
 * ⚠ WHAT IT CANNOT SEE: the CSS evaluated, or a real engine's start for a rebuilt animation — it reports what was
 * written. The character tree is a stand-in that returns one empty drawing for every frame the painter asks for.
 *
 * argv[2]: the module tree's root (`server/public/js` or a mutated copy). stdin: `{ effect, paints, doors? }` — the walker
 * as the scene emitted it, `[{ timeline_ms, frame_ms, clock_ms, elapsed_ms, doors_at? }, …]`, and optionally the walks'
 * door spans, which draw the band and its elevator with the leaves open over them from each paint's `doors_at`. stdout:
 * `[{ glide, laptop, leaves }, …]`, one per paint, each node `{ style, start }` or `null` (`leaves` a list).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const root = process.argv[2];
const input = JSON.parse(readFileSync(0, 'utf8'));
const host = new Node('div');
const timeline = { currentTime: 0 };
const clocks = { timeline: 0, page: 0 };

Object.defineProperty(globalThis, 'performance', { value: { now: () => clocks.timeline }, configurable: true });

globalThis.document = {
    activeElement: null,
    timeline,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
    createElement: () => ({ getContext: () => ({ measureText: (t) => ({ width: String(t).length }) }) }),
};
globalThis.CSS = { escape: (s) => s };
globalThis.setInterval = () => 0;
globalThis.clearInterval = () => {};

const { createPainter } = await import(pathToFileURL(join(root, 'floor', 'painter.js')).href);

const SVG = '<svg xmlns="http://www.w3.org/2000/svg"/>';
// The band with its elevator, drawn when the input carries walk door spans (`doors`) — `elevator-control-probe.mjs`'s.
const BAND = {
    x: 0, y: 0, w: 1000, h: 160,
    zone: { x: 0, y: 0, w: 272, h: 160 },
    elevator: { x: 32, y: 16, w: 84, h: 144, seam: 74, frame: { x: 26, y: 2, w: 96, h: 158 }, surround: { x: 26, y: 2, w: 114, h: 158 } },
    clock: { x: 164, y: 48, w: 64, h: 64, set: true, hour_angle_deg: 10, minute_angle_deg: 120, text: '14:23' },
    windows: [],
};
const characters = {
    chibiFrame: () => SVG,
    internFrames: () => ({ frames: [SVG, SVG], loopFrom: 1 }),
    walkFrames: () => [SVG],
    poseFrames: () => ({ frames: [SVG], loopFrom: 0 }),
    loopOffset: () => 0,
};

/** Every node under `n` whose class list carries `cls`. */
const find = (n, cls) => [
    ...(String(n.attrs?.class ?? '').split(' ').includes(cls) ? [n] : []),
    ...n.children.flatMap((c) => find(c, cls)),
];
const read = (n) => (n === undefined ? null : { style: n.attrs.style ?? null, start: n.animations?.[0]?.startTime ?? null });

const fx = input.effect;
const painter = createPainter({ characters, themes: {}, failed: () => {}, select: () => {}, clock: { now: () => clocks.page } });
const out = [];

for (const p of input.paints) {
    timeline.currentTime = p.frame_ms;
    clocks.timeline = p.timeline_ms;
    clocks.page = p.clock_ms;
    const scene = {
        band: input.doors === undefined ? null : BAND, band_docs: null, decorative: [], desks: [], doors: input.doors ?? [], doors_at: p.doors_at,
        planes: [], scenery: [], slab: null, strip: null, ride: null,
        loop_fps: 1000 / fx.frame_interval_ms,
        effects: [{ ...fx, elapsed_frames: Math.floor(p.elapsed_ms / fx.frame_interval_ms), started_at: 0, elapsed_ms: p.elapsed_ms }],
    };

    painter.paint(scene, { bounds: null, view: null });
    out.push({ glide: read(find(host, 'fx-walker')[0]), laptop: read(find(host, 'fx-laptop')[0]), leaves: find(host, 'elevator-leaf').map(read) });
}

process.stdout.write(JSON.stringify(out));

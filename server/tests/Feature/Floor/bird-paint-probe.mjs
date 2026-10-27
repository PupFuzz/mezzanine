/**
 * card#12345 — THE PAINTED BIRD FLIGHT, OVER A FAKE DOM: what `createPainter().paint()` writes for each A19 / A22
 * flight it is handed, painted TWICE — at two instants, the second a repaint of the same flight further on — so
 * `ABirdFliesToItsDestinationTest` can read, from what the painter wrote, where the flight is drawn at every
 * instant: its arc (`offset-path`), its duration and delay, the start the painter pinned on the timeline, and the
 * sheet's `.fx-bird` rule and `fly` keyframes. `node`, no dependencies.
 *
 * ⛔ THE CSS IS NOT EVALUATED HERE. This probe reports what was written; the test models the animation from it, so a
 * painter that writes a timing function the model does not know reds there rather than being read as linear.
 *
 * Each paint sets the three clocks a browser's paint reads: `timeline_ms`, the document timeline's clock read live
 * (`performance.now()`); `frame_ms`, `document.timeline.currentTime`, which holds the last frame's time and so trails
 * it; and `clock_ms`, the page's clock (the painter's `clock`). Each flight started at 0 on the page's clock
 * (`started_at`), and `elapsed_ms` is how long it had flown at the render the paint draws.
 *
 * ⚠ WHAT IT CANNOT SEE: when a real browser starts a rebuilt CSS animation. The fake's animations have no start
 * unless the painter pins them (`start: null`), which the test reads as a defect;
 * `tools/design/floor-ride.browser.mjs`'s `bird-flight` row is where a real engine's rebuild is measured.
 *
 * argv[2]: the module tree's root (`server/public/js` or a mutated copy). stdin: `{ effects, paints, degraded }` —
 * the flights as the scene emitted them, `[{ timeline_ms, frame_ms, clock_ms, elapsed_ms }, …]`, and optionally
 * `degraded`: `'no-get-animations'` (a browser with no `Element.getAnimations()`) or `'no-timeline'` (a timeline
 * with no time). stdout: `{ style, flights: [{ key, paints: [{ bird, rest, perched, hop }] }] }`, each node
 * `{ style, start }` or `null`; `perched` and `hop` (card#12445, the portal bird on the birdhouse's ledge) also carry
 * their `class`, the ledge's `translate` (`at`), and their bird's `transform` and leg path.
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

if (input.degraded === 'no-get-animations') {
    delete Node.prototype.getAnimations;
}

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

const { createPainter, STYLE } = await import(pathToFileURL(join(root, 'floor', 'painter.js')).href);

/** Every node under `n` whose class list carries `cls`. */
const find = (n, cls) => [
    ...(String(n.attrs?.class ?? '').split(' ').includes(cls) ? [n] : []),
    ...n.children.flatMap((c) => find(c, cls)),
];
const read = (n) => (n === undefined ? null : { style: n.attrs.style ?? null, start: n.animations?.[0]?.startTime ?? null });
// card#12445: a node on the birdhouse's ledge — its class, its timing, the ledge's `translate` (its parent's), and its
// bird's feet: the bird group's `transform` and the leg path the feet end on.
const ledge = (n) => {
    if (n === undefined) {
        return null;
    }

    const bird = n.children[0];
    const legs = bird.children.find((c) => c.name === 'path' && String(c.attrs.d ?? '').startsWith('M -2 6.5'));

    return { ...read(n), class: n.attrs.class, at: n.parentNode?.attrs.transform ?? null, bird: bird.attrs.transform ?? null, legs: legs?.attrs.d ?? null };
};

const flights = [];

for (const fx of input.effects) {
    const painter = createPainter({ characters: null, themes: {}, failed: () => {}, select: () => {}, clock: { now: () => clocks.page } });
    const paints = [];

    for (const p of input.paints) {
        timeline.currentTime = input.degraded === 'no-timeline' ? null : p.frame_ms;
        clocks.timeline = p.timeline_ms;
        clocks.page = p.clock_ms;
        const elapsedFrames = Math.floor(p.elapsed_ms / fx.frame_interval_ms);
        const scene = {
            band: null, band_docs: null, decorative: [], desks: [], doors: [], planes: [], scenery: [], slab: null, strip: null, ride: null,
            loop_fps: 1000 / fx.frame_interval_ms,
            effects: [{ ...fx, elapsed_frames: elapsedFrames, started_at: 0, elapsed_ms: p.elapsed_ms }],
        };

        painter.paint(scene, { bounds: null, view: null });
        paints.push({ bird: read(find(host, 'fx-bird')[0]), rest: read(find(host, 'fx-rest')[0]), perched: ledge(find(host, 'fx-perched')[0]), hop: ledge(find(host, 'fx-hop')[0]) });
    }

    flights.push({ cause: fx.cause, animation_id: fx.animation_id, leg: fx.leg ?? null, from: fx.from, to: fx.to, frames: fx.frames, frame_interval_ms: fx.frame_interval_ms,
        flight_start: fx.flight_start, wait_frames: fx.wait_frames, rest_frames: fx.rest_frames, perch: fx.perch, paints });
}

process.stdout.write(JSON.stringify({ style: STYLE, flights }));

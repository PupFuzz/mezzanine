/**
 * card#12345 — THE PAINTED BIRD FLIGHT, OVER A FAKE DOM: what `createPainter().paint()` writes for each A19 / A22
 * flight it is handed, painted TWICE — at two document-timeline instants, the second a repaint of the same flight
 * further on — so `ABirdFliesToItsDestinationTest` can read, from what the painter wrote, where the flight is drawn
 * at every instant: its arc (`offset-path`), its duration and delay, the start the painter pinned on the timeline,
 * and the sheet's `.fx-bird` rule and `fly` keyframes. `node`, no dependencies.
 *
 * ⛔ THE CSS IS NOT EVALUATED HERE. This probe reports what was written; the test models the animation from it, so a
 * painter that writes a timing function the model does not know reds there rather than being read as linear.
 *
 * ⚠ WHAT IT CANNOT SEE: when a real browser starts a rebuilt CSS animation. The fake's animations start at their
 * paint's timeline instant unless the painter pins them, so a painter that does not pin its flights reads continuous
 * here; `tools/design/floor-ride.browser.mjs`'s `bird-flight` row is where that is measured.
 *
 * argv[2]: the module tree's root (`server/public/js` or a mutated copy). stdin: `{ effects, paints }` — the flights
 * as the scene emitted them, and `[{ timeline_ms, elapsed_ms }, …]`, each paint's timeline instant and how long the
 * flight had been in flight then. stdout: `{ style, flights: [{ key, paints: [{ bird, rest }] }] }`, each node
 * `{ style, start }` or `null`.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const root = process.argv[2];
const input = JSON.parse(readFileSync(0, 'utf8'));
const host = new Node('div');
const timeline = { currentTime: 0 };

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

const flights = [];

for (const fx of input.effects) {
    const painter = createPainter({ characters: null, themes: {}, failed: () => {}, select: () => {} });
    const paints = [];

    for (const p of input.paints) {
        timeline.currentTime = p.timeline_ms;
        const elapsedFrames = Math.floor(p.elapsed_ms / fx.frame_interval_ms);
        const scene = {
            band: null, band_docs: null, decorative: [], desks: [], doors: [], planes: [], scenery: [], slab: null, strip: null, ride: null,
            loop_fps: 1000 / fx.frame_interval_ms,
            effects: [{ ...fx, elapsed_frames: elapsedFrames, elapsed_ms: p.elapsed_ms }],
        };

        painter.paint(scene, { bounds: null, view: null });
        paints.push({ bird: read(find(host, 'fx-bird')[0]), rest: read(find(host, 'fx-rest')[0]) });
    }

    flights.push({ cause: fx.cause, animation_id: fx.animation_id, leg: fx.leg ?? null, from: fx.from, to: fx.to, frames: fx.frames, frame_interval_ms: fx.frame_interval_ms, paints });
}

process.stdout.write(JSON.stringify({ style: STYLE, flights }));

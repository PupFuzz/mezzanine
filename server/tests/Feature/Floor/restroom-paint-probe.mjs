/**
 * card#13033 — A RESTROOM DOOR's VISITOR, PAINTED, OVER A FAKE DOM: what `createPainter().paint()` writes for a frame's
 * restroom doors and the walkers standing at their thresholds through an `inside` leg, read back from the written styles
 * so `ACharacterTakesARestroomBreakTest` can hold the drawing of a visit to the door's own timeline. `node`, no
 * dependencies.
 *
 * For each paint: per door, its `facing` and the instant its leaf's animation is pinned to; per walker with an `inside`
 * leg (one walker a paint), whether it is drawn in a recess clip (a `back` door's) or on the floor stack, the frames its
 * floor element is shown, and at every one of its own frames two levels from 0 to 4 — its door's LEAF (shut … open), from
 * the leaf's written animation and base transform, and the walker's OPACITY, from the side door's fade wrapper (4 where it
 * writes none) — and the instant the fade is pinned to. Each is sampled at the middle of the frame, so a run's edge never
 * decides a sample.
 *
 * ⚠ WHAT IT CANNOT SEE: the CSS evaluated by an engine. It reads the `animation` shorthand the painter wrote, its keyframe
 * names' trailing level (`restroom-leaf-<n>`, `restroom-bar-<n>`, `restroom-fade-<n>`) and its duration and delay, and a
 * base `scaleX` / `scaleY` mapped back through the painter's `1 − n ÷ 4 × 0.88`.
 *
 * argv[2]: the module tree's root (`server/public/js` or a mutated copy). stdin: `{ paints: [{ doors, effects, doors_at,
 * loop_fps }] }` — a frame's `scene.restroom_doors`, its effects with an `inside` leg, its `doors_at`. stdout: one answer
 * per paint, `{ doors: [{ id, facing, pinned, levels }], walkers: [{ key, clipped, shown, opacity, pinned }] }`.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { Node, animationOf } from './fake-svg-node.mjs';

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
const characters = {
    chibiFrame: () => SVG,
    internFrames: () => ({ frames: [SVG, SVG], loopFrom: 1 }),
    walkFrames: () => [SVG],
    poseFrames: () => ({ frames: [SVG], loopFrom: 0 }),
    loopOffset: () => 0,
};

const classes = (n) => String(n.attrs?.class ?? '').split(' ');
const find = (n, cls) => [...(classes(n).includes(cls) ? [n] : []), ...n.children.flatMap((c) => find(c, cls))];
const within = (n, cls) => (n === null ? false : classes(n).includes(cls) || within(n.parentNode, cls));

/** The `animation` shorthand's runs a node's style wrote, `{ name, level, from, to }` in ms from its own zero. */
const runsOf = (n) => {
    const shorthand = /(?:^|;)animation:([^;]*)/.exec(n.attrs.style ?? '')?.[1] ?? '';

    return shorthand.split(',').filter((r) => r.trim() !== '').map((r) => {
        const [name, dur, , delay] = r.trim().split(/\s+/);
        const from = parseFloat(delay) * 1000;

        return { name, level: Number(name.split('-').pop()), from, to: from + parseFloat(dur) * 1000 };
    });
};

/** The level a run list draws at `t` ms: the run covering it, else `base`. */
const levelAt = (runs, t, base) => runs.find((r) => r.from <= t && t < r.to)?.level ?? base;

const painter = createPainter({ characters, themes: {}, failed: () => {}, select: () => {}, clock: { now: () => clocks.page } });

const out = input.paints.map((p) => {
    const frameMs = 1000 / p.loop_fps;

    clocks.page = p.doors_at;
    clocks.timeline = 50000;
    timeline.currentTime = 50000;

    painter.paint({
        band: null, band_docs: null, decorative: [], desks: [], doors: [], doors_at: p.doors_at, planes: [], scenery: [], slab: null,
        strip: null, ride: null, loop_fps: p.loop_fps, restroom_doors: p.doors, effects: p.effects,
    }, { bounds: null, view: null });

    const leaves = find(host, 'restroom-leaf');
    const doors = p.doors.map((d, i) => {
        const leaf = leaves[i];
        const scale = /scale[XY]\(([\d.]+)\)/.exec(leaf.attrs.style)[1];

        return {
            id: d.id,
            at: d.threshold,
            facing: d.facing,
            base: Math.round(((1 - Number(scale)) / 0.88) * 4),
            runs: runsOf(leaf),
            pinned: leaf.animations === undefined ? null : animationOf(leaf).pinned,
        };
    });
    const walkers = p.effects.map((fx, i) => {
        const shownEl = find(host, 'fx-walker-shown').filter((n) => !classes(n).includes('fx-walker-inside') && !classes(n).includes('fx-walker-cab'))[i];
        const fade = find(shownEl, 'restroom-fade')[0] ?? null;
        const at = fx.legs.find((l) => l.kind === 'inside').at;
        const door = doors.find((d) => d.at !== null && d.at.x === at.x && d.at.y === at.y);
        const fadeRuns = fade === null ? [] : runsOf(fade);
        // the walker's frame `f` in its door's frames, as the painter moves it (`started_at − doors_at`)
        const shift = fx.started_at - p.doors_at;
        const shownRuns = /animation-delay:([^;]*)/.exec(shownEl.attrs.style ?? '');
        const durations = /animation-duration:([^;]*)/.exec(shownEl.attrs.style ?? '');
        const shown = shownRuns === null ? [] : shownRuns[1].split(',').map((d, k) => {
            const from = parseFloat(d) * 1000 + fx.elapsed_ms;

            return [from / frameMs, (from + parseFloat(durations[1].split(',')[k]) * 1000) / frameMs];
        });

        return {
            key: fx.walker_key ?? fx.seat_id,
            clipped: find(host, 'fx-walker-inside').length > 0,
            shown,
            leaf: Array.from({ length: fx.frames }, (_, f) => levelAt(door.runs, (f + 0.5) * frameMs + shift, door.base)),
            opacity: Array.from({ length: fx.frames }, (_, f) => levelAt(fadeRuns, (f + 0.5) * frameMs + shift, 4)),
            pinned: fade === null || fade.animations === undefined ? null : animationOf(fade).pinned,
            within_shown: fade !== null && within(fade, 'fx-walker-shown'),
        };
    });

    return { doors: doors.map(({ id, facing, pinned }) => ({ id, facing, pinned })), walkers };
});

process.stdout.write(JSON.stringify(out));

/**
 * card#13521 — A TRIP WALKER's THOUGHT, AS BOTH FLOORS DRAW IT: the probe `ACharacterVisitsTheSnackCornerTest` and
 * `ACharacterTakesARestroomBreakTest` read which bubble text a snack or restroom walker carries on each leg through. The
 * 2-D side is `floor/painter.js`'s `paint()` over a fake DOM — per glide (one a walk leg), the frame its walk begins at and
 * the thought text drawn inside it; the 3-D side is `floor/floor-world.js`'s `floorWorld()` handed to
 * `floor3d/body-paint.js`'s `floorBodies()` and placed at the middle frame of each of the walker's legs from the paint
 * on — the thought label's text over the walker there, or `null`.
 *
 * argv[2]: the module tree's root (`server/public/js` or a mutated copy). stdin: `{ paints: [{ scene, effect }] }` — a
 * frame's scene and ONE of its effects, painted alone so each floor draws that one walker; or `effect` null, the scene
 * painted as it is and placed once, at its paint. stdout: one answer per paint, `{ glides: [{ from_frame, texts }],
 * placed: [{ frame, text }] }`, a text being the bubble's lines joined by a space.
 *
 * ⚠ WHAT IT DOES NOT SEE: pixels, or the 3-D creature itself — `floor3d/creature-paint.js` is replaced by a stub whose
 * mesh is always visible, since the build stops at a canvas this probe does not have; the label is read off the marks
 * group's objects, never off a renderer.
 */
import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const root = process.argv[2];
const mod = (path) => import(pathToFileURL(join(root, path)).href);
const THREE = pathToFileURL(join(root, 'vendor', 'three', 'three.module.js')).href;
const STUB = `data:text/javascript,${encodeURIComponent(`export async function createCreature() {
    const mesh = { visible: true, position: { set() {} }, rotation: { y: 0 }, layers: { enable() {} }, traverse(f) { f(this); } };
    return { mesh, pose() {}, blink() {}, fade() {}, dispose() {}, textures: () => [] };
}`)}`;

registerHooks({
    resolve: (specifier, context, next) => (specifier === 'three'
        ? { url: THREE, shortCircuit: true }
        : specifier === './creature-paint.js' && context.parentURL?.endsWith('/floor3d/body-paint.js')
            ? { url: STUB, shortCircuit: true }
            : next(specifier, context)),
});

/** A fake element with what the 3-D marks touch beyond the painter's node: its removal. */
class Element extends Node {
    remove() {}
}

const host = new Node('div');

Object.defineProperty(globalThis, 'performance', { value: { now: () => 0 }, configurable: true });
globalThis.document = {
    activeElement: null,
    timeline: { currentTime: 0 },
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
    createElement: (name) => (name === 'canvas' ? { getContext: () => ({ measureText: (t) => ({ width: String(t).length }) }) } : new Element(name)),
};
globalThis.CSS = { escape: (s) => s };
globalThis.setInterval = () => 0;
globalThis.clearInterval = () => {};

const { paints } = JSON.parse(readFileSync(0, 'utf8'));
const C = await import(pathToFileURL(join(HERE, '..', '..', '..', '..', 'resources', 'characters', 'index.js')).href);
const { createPainter } = await mod('floor/painter.js');
const { floorWorld } = await mod('floor/floor-world.js');
const { floorBodies } = await mod('floor3d/body-paint.js');
const { Group } = await import(THREE);

const classes = (n) => String(n.attrs?.class ?? '').split(' ');
const find = (n, cls) => [...(classes(n).includes(cls) ? [n] : []), ...n.children.flatMap((c) => find(c, cls))];

const out = [];

for (const { scene, effect } of paints) {
    const alone = effect === null ? scene : { ...scene, effects: [effect] };
    const I = effect?.frame_interval_ms ?? 1;
    const elapsed = effect?.elapsed_ms ?? 0;
    const legs = effect?.legs ?? [{ start: 0, frames: 0 }];

    // ---- 2-D: each glide, the frame its walk begins at (its glide's delay, `walk.start × I − elapsed`), and its texts ----
    host.children = [];
    createPainter({ characters: C, themes: {}, failed: () => {}, select: () => {}, clock: { now: () => 0 } }).paint(alone, { bounds: null, view: null });

    const glides = find(host, 'fx-walker').map((g) => {
        const delay = parseFloat(/animation-delay:([^;,]*)/.exec(g.attrs.style ?? '')?.[1] ?? 'NaN');

        return {
            from_frame: Math.round((delay * 1000 + elapsed) / I),
            texts: find(g, 'snack-thought').map((t) => find(t, 'snack-thought-text').map((l) => l.textContent).join(' ')),
        };
    });

    // ---- 3-D: the bodies over the world, placed at the middle frame of each leg the walker has still to walk ----
    const marks = new Group();
    const bodies = floorBodies({
        document: globalThis.document,
        creatures: { add() {}, remove() {} },
        room: new Group(),
        marks,
        wake: () => {},
        art: () => ({ characters: C }),
        failed: () => {},
        now: () => 0,
    });

    bodies.paint(floorWorld(alone), 0);
    await new Promise((resolve) => setImmediate(resolve));

    const placed = [];

    for (const leg of legs) {
        const frame = leg.start + leg.frames / 2;

        if (frame * I < elapsed) {
            continue;
        }

        bodies.place(frame * I - elapsed);

        const labels = marks.children.filter((o) => classes({ attrs: { class: o.element.className } }).includes('floor3d-thought'));

        placed.push({ frame, text: labels.length === 0 ? null : labels.map((o) => o.element.children.map((s) => s.textContent).join(' ')).join(' | ') });
    }

    out.push({ glides, placed });
}

process.stdout.write(JSON.stringify(out));

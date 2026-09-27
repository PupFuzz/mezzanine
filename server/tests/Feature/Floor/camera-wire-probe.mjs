/**
 * The probe the PHP suite drives the camera's PAGE WIRE through — `wire/camera-view.js` (how a page
 * shows the camera: at once, or as a glide) and `wire/camera-gestures.js` (the wheel and the drag) —
 * under `node`, with a stubbed frame clock and a stand-in element. No DOM, no network.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/wire` or a MUTATED COPY of it — so every planted control re-mints its defect in
 * the real code.
 *
 * stdin — JSON, one of:
 *  · `{ "view": [op, …] }` — a `cameraView()` over a recorder, on a frame clock the ops move. The
 *    cameras are `camera.js`'s own: FROM is a surface of 1280 × 800 framed at fit on a building of
 *    two 1600 × 1000 plates, TO is FROM focused on the first plate, OTHER on the second. Ops:
 *    `{ "op": "glide", "ms", "commit" }` — `glideTo(FROM, TO, ms, done)`, committed or not;
 *    `{ "op": "frame", "ms" }` — the clock moves `ms` and every frame requested so far runs;
 *    `{ "op": "show" }` — `show(OTHER)`; `{ "op": "glide_other", "ms" }` — `glideTo(FROM, OTHER, …)`.
 *  · `{ "gestures": [event, …] }` — `cameraGestures()` on a stand-in element whose box is at
 *    (10, 20), with acts that record their arguments. Each event is `{ "type", …the event's own
 *    members }`, dispatched cancelable.
 * stdout — JSON: `{ "log": [ … ] }` — for `view`, each camera applied, named `from` / `to` / `other`
 * or `between`, and each `done` run, in order; for `gestures`, each act, show and pointer capture,
 * and for each event whether it was `default_prevented` and had its propagation stopped.
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node camera-wire-probe.mjs <wire-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

// The frame clock: `requestAnimationFrame` holds its callback until a `frame` op runs it.
let clock = 0;
let nextFrame = 0;
const frames = new Map();

globalThis.requestAnimationFrame = (callback) => {
    nextFrame += 1;
    frames.set(nextFrame, callback);

    return nextFrame;
};
globalThis.cancelAnimationFrame = (id) => {
    frames.delete(id);
};
Object.defineProperty(globalThis, 'performance', { value: { now: () => clock }, configurable: true });

const { cameraView } = await import(pathToFileURL(join(dir, 'camera-view.js')).href);
const { cameraGestures } = await import(pathToFileURL(join(dir, 'camera-gestures.js')).href);
const { createCamera, focusOn, frameOn } = await import(pathToFileURL(join(dir, 'camera.js')).href);

const payload = JSON.parse(readFileSync(0, 'utf8'));
const log = [];

if (payload.view !== undefined) {
    const from = frameOn(createCamera({ width: 1280, height: 800 }), { x: 0, y: 0, w: 1600, h: 2000 });
    const cameras = {
        from,
        to: focusOn(from, { x: 0, y: 0, w: 1600, h: 1000 }),
        other: focusOn(from, { x: 0, y: 1000, w: 1600, h: 1000 }),
    };
    const same = (a, b) => a.zoom === b.zoom && a.x === b.x && a.y === b.y;
    const name = (camera) => Object.keys(cameras).find((k) => same(cameras[k], camera)) ?? 'between';
    const view = cameraView((camera) => log.push({ applied: name(camera) }));

    for (const op of payload.view) {
        switch (op.op) {
            case 'glide':
                view.glideTo(cameras.from, cameras.to, op.ms, () => log.push({ done: 'to' }), { commit: op.commit === true });
                break;
            case 'glide_other':
                view.glideTo(cameras.from, cameras.other, op.ms, () => log.push({ done: 'other' }));
                break;
            case 'show':
                view.show(cameras.other);
                break;
            case 'frame': {
                clock += op.ms;
                const due = [...frames.entries()];

                frames.clear();

                for (const [, callback] of due) {
                    callback(clock);
                }
                break;
            }
            default:
                throw new Error(`unknown view op ${op.op}`);
        }
    }
} else if (payload.gestures !== undefined) {
    const element = new EventTarget();

    element.getBoundingClientRect = () => ({ left: 10, top: 20 });
    element.setPointerCapture = (id) => log.push({ capture: id });

    cameraGestures(element, {
        wheel: (point, delta) => ({ act: 'wheel', point, delta }),
        drag: (dx, dy) => ({ act: 'drag', dx, dy }),
    }, (camera) => log.push({ show: camera }));

    for (const spec of payload.gestures) {
        const { type, ...members } = spec;
        const event = Object.assign(new Event(type, { cancelable: true }), members);

        element.dispatchEvent(event);
        log.push({ event: type, default_prevented: event.defaultPrevented, propagation_stopped: event.cancelBubble });
    }
} else {
    throw new Error('the payload names neither `view` nor `gestures`');
}

process.stdout.write(JSON.stringify({ log }));

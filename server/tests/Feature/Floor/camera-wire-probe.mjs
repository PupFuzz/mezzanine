/**
 * The probe the PHP suite drives the camera's PAGE WIRE through — `wire/camera-view.js` (how a page
 * shows the camera: at once, or as a glide), `wire/camera-gestures.js` (the wheel, the drag, the pinch and Safari's `gesture*` pinch) and
 * `wire/camera-keys.js` (the keyboard and the zoom buttons) — under `node`, with a stubbed frame clock
 * and stand-in elements. No DOM, no network.
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
 *  · `{ "gestures": [event, …], "framed"? }` — `cameraGestures()` on a stand-in element whose box is
 *    at (10, 20), with acts that record their arguments — the wheel's two, `pan` and `zoom`, answering
 *    that they consumed the event, as a framed camera's do off the edge; with `"pan_consumed": false`
 *    the pan answers that it did not, as at an edge (card#11045 Q3); with `"riding": true` the wheel's acts and
 *    the pinch answer the camera a lobby mid-ride holds, `{ "act": "held" }`, the wheel's consuming the event
 *    (card#11045 PR-C) — and a camera that frames a scene — or, with
 *    `"framed": false`, frames nothing (`bounds: null`, the uncomposed lobby's). Each event is
 *    `{ "type", …the event's own members }`, dispatched cancelable — except `{ "type": "reframe",
 *    "framed": bool }`, which dispatches nothing: the camera frames a scene, or nothing, from then on
 *    (a building arriving or going away between two events), logged as `{ "reframed": bool }`.
 *  · `{ "keys": [event, …], "framed"? }` — `cameraKeys()` on a stand-in drawing and two stand-in
 *    buttons, with acts that record their arguments and the same camera. Each event is `{ "type",
 *    …members }`, dispatched cancelable on the drawing — or, with `"on": "zoom_in"` / `"zoom_out"`, on
 *    that button; `reframe` as above.
 *  · `{ "offer": [bool, …] }` — `offerKeys()` on a stand-in drawing and four stand-ins (the zoom buttons,
 *    the page's framing control and its gesture hint), each starting withdrawn as both pages' markup does,
 *    once per entry with a camera that frames a scene (`true`) or nothing (`false`), logging after each
 *    whether each is hidden, the drawing's `aria-keyshortcuts` and `tabindex` (`null` when it has none), and
 *    `writes` — how many writes `offerKeys()` has made to the drawing and the buttons so far.
 * stdout — JSON: `{ "log": [ … ] }` — for `view`, each camera applied, named `from` / `to` / `other`
 * or `between`, and each `done` run, in order; for `gestures` and `keys`, each act, show, pointer
 * capture and capture released (`release`), and for each event whether it was `default_prevented`, had its propagation stopped, and the
 * drawing's `user-select` after it (`user_select`, `""` when none is set) and its prefixed
 * `-webkit-user-select` (`webkit_user_select`, likewise).
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
const { cameraKeys, offerKeys } = await import(pathToFileURL(join(dir, 'camera-keys.js')).href);
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
} else if (payload.offer !== undefined) {
    let writes = 0;
    const attributes = new Map();
    const element = {
        hasAttribute: (name) => attributes.has(name),
        getAttribute: (name) => attributes.get(name) ?? null,
        setAttribute: (name, value) => {
            writes += 1;
            attributes.set(name, String(value));
        },
        removeAttribute: (name) => {
            writes += 1;
            attributes.delete(name);
        },
    };
    // A button the page's markup starts hidden, as both pages' do; every write to it is counted.
    const button = () => {
        let hidden = true;

        return {
            get hidden() {
                return hidden;
            },
            set hidden(value) {
                writes += 1;
                hidden = value;
            },
        };
    };
    const buttons = { zoomIn: button(), zoomOut: button(), fit: button(), hint: button() };

    for (const framed of payload.offer) {
        offerKeys(element, buttons, { bounds: framed ? { x: 0, y: 0, w: 1600, h: 2000 } : null });
        log.push({
            framed,
            zoom_in_hidden: buttons.zoomIn.hidden,
            zoom_out_hidden: buttons.zoomOut.hidden,
            fit_hidden: buttons.fit.hidden,
            hint_hidden: buttons.hint.hidden,
            keyshortcuts: attributes.get('aria-keyshortcuts') ?? null,
            tabindex: attributes.get('tabindex') ?? null,
            writes,
        });
    }
} else if (payload.gestures !== undefined || payload.keys !== undefined) {
    const element = new EventTarget();
    const targets = { drawing: element, zoom_in: new EventTarget(), zoom_out: new EventTarget() };

    element.style = {};
    element.getBoundingClientRect = () => ({ left: 10, top: 20 });
    element.setPointerCapture = (id) => log.push({ capture: id });
    element.releasePointerCapture = (id) => log.push({ release: id });

    // A lobby mid-ride (`"riding": true`) holds its camera: the wheel's acts and the pinch answer the camera
    // they hold, `{ act: 'held' }`, the wheel's consuming the event (`lobby/lobby-screen.js`'s ride-hold).
    const held = { act: 'held' };
    const riding = payload.riding === true;
    const acts = {
        pan: (delta) => ({ camera: riding ? held : { act: 'pan', delta }, consumed: riding || payload.pan_consumed !== false }),
        zoom: (point, delta) => ({ camera: riding ? held : { act: 'zoom', point, delta }, consumed: true }),
        pinch: (from, to, factor) => (riding ? held : { act: 'pinch', from, to, factor }),
        zoomStep: (notches) => ({ act: 'zoomStep', notches }),
        drag: (dx, dy) => ({ act: 'drag', dx, dy }),
    };
    const show = (camera) => log.push({ show: camera });
    const scene = { x: 0, y: 0, w: 1600, h: 2000 };
    let bounds = payload.framed === false ? null : scene;
    const camera = () => ({ bounds });

    if (payload.gestures !== undefined) {
        cameraGestures(element, { pan: acts.pan, zoom: acts.zoom, pinch: acts.pinch, drag: acts.drag, camera }, show);
    } else {
        cameraKeys(element, { zoomIn: targets.zoom_in, zoomOut: targets.zoom_out }, { zoomStep: acts.zoomStep, drag: acts.drag, camera }, show);
    }

    for (const spec of payload.gestures ?? payload.keys) {
        const { type, on = 'drawing', ...members } = spec;

        if (type === 'reframe') {
            bounds = members.framed ? scene : null;
            log.push({ reframed: members.framed });

            continue;
        }
        const event = Object.assign(new Event(type, { cancelable: true }), members);

        targets[on].dispatchEvent(event);
        log.push({
            event: type,
            default_prevented: event.defaultPrevented,
            propagation_stopped: event.cancelBubble,
            user_select: element.style.userSelect ?? '',
            // The prefixed property, which WebKit engines read and which the module sets and resets beside it.
            webkit_user_select: element.style.webkitUserSelect ?? '',
        });
    }
} else {
    throw new Error('the payload names none of `view`, `gestures`, `keys` and `offer`');
}

process.stdout.write(JSON.stringify({ log }));

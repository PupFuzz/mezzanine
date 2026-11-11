/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsCameraStaysAboveTheFloorTest` reads the 3-D floor's keys through —
 * `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 3's `camera-acts-probe.mjs` (card#13033 stage 2 slice 2): the
 * house keys wire (`wire/camera-keys.js`) driven with stand-in key events over `floor3d/camera-acts.js`'s acts, whose
 * controls are a stub recording every call. argv[2] is the client's `wire/` directory (the shipped one or a MUTATED COPY
 * of the tree); stdout is `{ "legs": { "<leg>": [defect, …] }, "log": [ … ] }`.
 *
 * ⛔ THE KEYS ARE PRESSED THROUGH THE WIRE, NOT HANDED TO THE ACTS: a key reaches `drag()` with its modifiers only if
 * the wire hands them, so a wire that dropped the Shift member reds here as an act that read it wrongly would.
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const wire = process.argv[2];
const { cameraKeys } = await import(pathToFileURL(join(wire, 'camera-keys.js')).href);
const { PAN_STEP_PX, ZOOM_STEP, turnOf } = await import(pathToFileURL(join(wire, 'camera.js')).href);
const { cameraActs } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'camera-acts.js')).href);

const log = [];
const vector = (name) => ({ set: (x, y, z) => log.push([`${name}.set`, x, y, z]) });
const controls = {
    rotateLeft: (angle) => log.push(['rotateLeft', angle]),
    rotateUp: (angle) => log.push(['rotateUp', angle]),
    pan: (dx, dy) => log.push(['pan', dx, dy]),
    dollyIn: (s) => log.push(['dollyIn', s]),
    dollyOut: (s) => log.push(['dollyOut', s]),
    update: () => log.push(['update']),
    target: vector('target'),
    object: { position: vector('position') },
};
const surface = { width: 1280, height: 800 };
const entry = { position: { x: 0, y: 3, z: 4 }, target: { x: 0, y: 0, z: 0 } };
const bounds = { min: { x: -1, y: 0, z: -1 }, max: { x: 1, y: 1.6, z: 1 } };
const acts = cameraActs(controls, { surface: () => surface, entry: () => entry, clamp: () => log.push(['clamp']), bounds: () => bounds });

/** A stand-in element: its listeners by type, and a dispatch that answers whether the default was prevented. */
function element() {
    const on = {};

    return {
        addEventListener: (type, fn) => {
            (on[type] ??= []).push(fn);
        },
        fire: (event) => {
            let prevented = false;

            for (const fn of on[event.type] ?? []) {
                fn({ ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, ...event, preventDefault: () => { prevented = true; } });
            }

            return prevented;
        },
    };
}

const stage = element();
const buttons = { zoomIn: element(), zoomOut: element() };
const shown = [];

cameraKeys(stage, buttons, acts, (camera) => shown.push(camera));

const turn = turnOf(PAN_STEP_PX, surface.width);
const legs = { turn: [], tilt: [], pan: [], dolly: [], fit: [], wire: [] };
const press = (event, target = stage) => {
    log.length = 0;
    shown.length = 0;

    const prevented = target.fire(event);

    return { calls: JSON.stringify(log), prevented, shown: shown.length };
};
const expect = (leg, what, got, calls) => {
    const want = JSON.stringify(calls);

    if (got.calls !== want || got.shown !== 1) {
        legs[leg].push(`${what}: the controls were called ${got.calls} and ${got.shown} camera(s) shown — not ${want} and one`);
    }
};

// ←/→ turn the azimuth as a drag of the scene: the view moves the way the arrow points, the tilt untouched.
expect('turn', '←', press({ type: 'keydown', key: 'ArrowLeft' }), [['rotateLeft', turn], ['rotateUp', 0], ['clamp']]);
expect('turn', '→', press({ type: 'keydown', key: 'ArrowRight' }), [['rotateLeft', -turn], ['rotateUp', 0], ['clamp']]);
// ↑/↓ tilt.
expect('tilt', '↑', press({ type: 'keydown', key: 'ArrowUp' }), [['rotateLeft', 0], ['rotateUp', turn], ['clamp']]);
expect('tilt', '↓', press({ type: 'keydown', key: 'ArrowDown' }), [['rotateLeft', 0], ['rotateUp', -turn], ['clamp']]);
// Shift + an arrow pans the target by the step, and turns nothing.
expect('pan', 'Shift+←', press({ type: 'keydown', key: 'ArrowLeft', shiftKey: true }), [['pan', PAN_STEP_PX, 0], ['clamp']]);
expect('pan', 'Shift+↑', press({ type: 'keydown', key: 'ArrowUp', shiftKey: true }), [['pan', 0, PAN_STEP_PX], ['clamp']]);
// + and − dolly by the house notch, from the keys and from the buttons.
expect('dolly', '+', press({ type: 'keydown', key: '+' }), [['dollyIn', ZOOM_STEP], ['clamp']]);
expect('dolly', '−', press({ type: 'keydown', key: '-' }), [['dollyOut', ZOOM_STEP], ['clamp']]);
expect('dolly', 'the zoom-in button', press({ type: 'click' }, buttons.zoomIn), [['dollyIn', ZOOM_STEP], ['clamp']]);
expect('dolly', 'the zoom-out button', press({ type: 'click' }, buttons.zoomOut), [['dollyOut', ZOOM_STEP], ['clamp']]);

// *Fit the floor*: the entry pose set, the controls updated, the target clamped.
log.length = 0;
acts.fit();

if (JSON.stringify(log) !== JSON.stringify([['target.set', 0, 0, 0], ['position.set', 0, 3, 4], ['update'], ['clamp']])) {
    legs.fit.push(`Fit called ${JSON.stringify(log)} — not the entry pose set, an update and the clamp`);
}

// The wire's own rules hold over these acts: a key with Ctrl, Meta or Alt is the browser's, and the camera gates on bounds.
const ctrl = press({ type: 'keydown', key: 'ArrowLeft', ctrlKey: true });

if (ctrl.calls !== '[]' || ctrl.prevented) {
    legs.wire.push(`Ctrl+← reached the camera: ${ctrl.calls}`);
}

if (JSON.stringify(acts.camera()) !== JSON.stringify({ bounds })) {
    legs.wire.push(`the acts' camera is ${JSON.stringify(acts.camera())} — not the box the wire gates on`);
}

process.stdout.write(JSON.stringify({ legs }));

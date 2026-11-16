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

import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
const { cameraKeys } = await import(pathToFileURL(join(wire, 'camera-keys.js')).href);
const { PAN_STEP_PX, ZOOM_STEP, turnOf } = await import(pathToFileURL(join(wire, 'camera.js')).href);
const { RISE_STEP, cameraActs } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'camera-acts.js')).href);
const L = await import(pathToFileURL(join(wire, '..', 'floor3d', 'view-limits.js')).href);
const { ROOM_H_U } = await import(pathToFileURL(join(wire, '..', 'floor', 'floor-world.js')).href);
const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const { SCENE_W, SCENE_H } = await import(pathToFileURL(join(repo, 'resources', 'characters', 'index.js')).href);

const log = [];
/** The eye's distance from the target the stub answers — moved by the rise leg between the closest and the fit. */
let distance = 5;
/** A stand-in vector at (x, y, z): its `set()` recorded and applied, so an act that reads it reads what it wrote. */
const vector = (name, x, y, z) => {
    const v = { x, y, z };

    v.set = (...xyz) => {
        log.push([`${name}.set`, ...xyz]);
        [v.x, v.y, v.z] = xyz;
    };

    return v;
};
const controls = {
    rotateLeft: (angle) => log.push(['rotateLeft', angle]),
    rotateUp: (angle) => log.push(['rotateUp', angle]),
    pan: (dx, dy) => log.push(['pan', dx, dy]),
    dollyIn: (s) => log.push(['dollyIn', s]),
    dollyOut: (s) => log.push(['dollyOut', s]),
    update: () => log.push(['update']),
    getDistance: () => distance,
    target: vector('target', 0.5, 0.25, -1),
    object: { position: vector('position', 0.5, 3.25, 3), fov: 45 },
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
const legs = { turn: [], tilt: [], pan: [], dolly: [], toward: [], rise: [], fit: [], wire: [] };
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
expect('dolly', '+', press({ type: 'keydown', key: '+' }), [['dollyIn', 1 / ZOOM_STEP], ['clamp']]);
expect('dolly', '−', press({ type: 'keydown', key: '-' }), [['dollyOut', 1 / ZOOM_STEP], ['clamp']]);
expect('dolly', 'the zoom-in button', press({ type: 'click' }, buttons.zoomIn), [['dollyIn', 1 / ZOOM_STEP], ['clamp']]);
expect('dolly', 'the zoom-out button', press({ type: 'click' }, buttons.zoomOut), [['dollyOut', 1 / ZOOM_STEP], ['clamp']]);

// + and the zoom-in button bring the eye closer, − and the zoom-out button take it away — read on the controls' own
// arithmetic, not their names: `dollyIn(s)` multiplies the eye's distance by `s` and `dollyOut(s)` divides it
// (`OrbitControls`' `_dollyIn()` / `_dollyOut()` on its `_scale`, applied to the radius on the next update; its wheel
// passes one factor under 1 to both), so a mapping that calls the right method with the wrong factor moves the wrong way.
for (const [what, event, target, closer] of [['+', { type: 'keydown', key: '+' }, undefined, true], ['−', { type: 'keydown', key: '-' }, undefined, false],
    ['the zoom-in button', { type: 'click' }, buttons.zoomIn, true], ['the zoom-out button', { type: 'click' }, buttons.zoomOut, false]]) {
    const { calls } = press(event, target);
    const distance = JSON.parse(calls).reduce((d, [name, s]) => (name === 'dollyIn' ? d * s : name === 'dollyOut' ? d / s : d), 10);

    if (closer ? !(distance < 10) : !(distance > 10)) {
        legs.toward.push(`${what} takes the eye from 10 to ${distance} — ${closer ? 'away from' : 'toward'} the target`);
    }
}

// Page Up / Page Down raise and lower the target and the eye by one vector, straight up, one `RISE_STEP` a press — an
// eighth of the room's height — and turn nothing; then the clamp. The same step at the closest distance the limits allow
// and at the fit (the shipped default floor's on this stage): a press is a share of the room, not of the stage. Read
// from the stub's own pose, so each key starts where the last left it.
if (Math.abs(RISE_STEP - ROOM_H_U / 8) > 1e-12) {
    legs.rise.push(`a key's step is ${RISE_STEP} units — not an eighth of the room's height (${ROOM_H_U / 8})`);
}

const view = L.limits({ w: 17.84, d: 6.96 }, surface, { w: SCENE_W, h: SCENE_H });

for (const [where, at] of [['closest', view.distance.min], ['fit', view.distance.max / L.MAX_FIT_SHARE]]) {
    distance = at;

    for (const [what, key, h] of [['Page Up', 'PageUp', ROOM_H_U / 8], ['Page Down', 'PageDown', -ROOM_H_U / 8]]) {
        const { target: t, object: { position: p } } = controls;
        const want = [['target.set', t.x, t.y + h, t.z], ['position.set', p.x, p.y + h, p.z], ['update'], ['clamp']];

        expect('rise', `${what} at the ${where} distance (${at.toFixed(2)})`, press({ type: 'keydown', key }), want);
    }
}

// Alt + a vertical drag (`rise(dy)`, which the renderer hands the pointer's movement): `dy` px of the scene at the
// target's distance — the controls' pan rate at their 45° field — so it does scale with the distance.
distance = 5;

for (const dy of [PAN_STEP_PX, -PAN_STEP_PX]) {
    const h = (2 * dy * distance * Math.tan(Math.PI / 8)) / surface.height;
    const { target: t, object: { position: p } } = controls;
    const want = JSON.stringify([['target.set', t.x, t.y + h, t.z], ['position.set', p.x, p.y + h, p.z], ['update'], ['clamp']]);

    log.length = 0;
    acts.rise(dy);

    if (JSON.stringify(log) !== want) {
        legs.rise.push(`a drag of ${dy} px called ${JSON.stringify(log)} — not ${want}`);
    }
}

// *Fit the floor*: the entry pose set, the controls updated, the renderer's clamp called.
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

const altPage = press({ type: 'keydown', key: 'PageUp', altKey: true });

if (altPage.calls !== '[]' || altPage.prevented) {
    legs.wire.push(`Alt+Page Up reached the camera: ${altPage.calls}`);
}

if (JSON.stringify(acts.camera()) !== JSON.stringify({ bounds })) {
    legs.wire.push(`the acts' camera is ${JSON.stringify(acts.camera())} — not the box the wire gates on`);
}

process.stdout.write(JSON.stringify({ legs }));

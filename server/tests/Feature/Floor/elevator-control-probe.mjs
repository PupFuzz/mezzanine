/**
 * AT-D3-26's PAINTER LEG — the floor's elevator as a control and the ride's leaves (`docs/design/FLOOR.md` § 4.5,
 * card#11795), over a fake DOM. `node`, no dependencies. Driven by `Tests\Feature\Floor\TheElevatorIsAControlOnTheDrawingTest`.
 *
 * The painter is handed one band and no desks, and painted under each ride phase at a stated elapsed time; what it
 * wrote is read back: the elevator control's attributes and hit rect, how many presses a click, Enter and Space
 * each make, each band leaf's `style`, and `paintCab()`'s cab.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: JSON, one member per leg (see the bottom).
 */

import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const JS = args.includes('--js') ? args[args.indexOf('--js') + 1] : join(HERE, '..', '..', '..', 'public', 'js');
const host = new Node('div');

globalThis.document = {
    activeElement: null,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
};
globalThis.CSS = { escape: (s) => s };
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};

const { createPainter } = await import(pathToFileURL(join(JS, 'floor', 'painter.js')).href);

const BAND = {
    x: 0, y: 0, w: 1000, h: 160,
    zone: { x: 0, y: 0, w: 272, h: 160 },
    elevator: { x: 32, y: 16, w: 84, h: 144, seam: 74, frame: { x: 26, y: 2, w: 96, h: 158 }, surround: { x: 26, y: 2, w: 114, h: 158 } },
    clock: { x: 164, y: 48, w: 64, h: 64, set: true, hour_angle_deg: 10, minute_angle_deg: 120, text: '14:23' },
    windows: [],
};
const WALK_DOORS = [{ from: 3, to: 8 }];
const sceneOf = (over) => ({ band: BAND, band_docs: null, slab: null, planes: [], scenery: [], decorative: [], lines: [], desks: [], strip: null, effects: [], doors: [], loop_fps: 4, ride: null, ...over });

/** Every node under `n`, depth first. */
const nodes = (n, out = []) => { out.push(n); n.children.forEach((c) => nodes(c, out)); return out; };
const withClass = (root, cls) => nodes(root).filter((n) => (n.getAttribute?.('class') ?? '').split(' ').includes(cls));

let presses = 0;
const painter = createPainter({ characters: null, themes: {}, failed() {}, select() {}, ride: () => { presses += 1; } });

/** One paint of the band under `ride` (and walks' `doors`): the control and the two leaves' styles. */
function paint(ride, doors = []) {
    painter.paint(sceneOf({ ride, doors }), { bounds: null });

    const control = nodes(host).find((n) => n.getAttribute?.('data-key') === 'elevator') ?? null;
    const leaves = withClass(host, 'elevator-leaf').map((n) => n.getAttribute('style'));

    return { control, leaves };
}

const ride = (phase, elapsed, over = {}) => ({ phase, elapsed_ms: elapsed, ms: 750, door_delay_ms: 250, ...over });

// ── the control ──────────────────────────────────────────────────────────────────────────────────
const { control } = paint(null);
const hit = control === null ? null : withClass(control, 'elevator-hit')[0] ?? null;
const fire = (type, event) => { for (const fn of control?.listeners?.[type] ?? []) fn({ preventDefault() {}, ...event }); };
const pressesBy = (type, event) => { presses = 0; fire(type, event); return presses; };

const controlLeg = {
    found: control !== null,
    attrs: control === null ? null : { role: control.getAttribute('role'), tabindex: control.getAttribute('tabindex'), aria_label: control.getAttribute('aria-label'), aria_disabled: control.getAttribute('aria-disabled') },
    hit: hit === null ? null : { x: Number(hit.getAttribute('x')), y: Number(hit.getAttribute('y')), w: Number(hit.getAttribute('width')), h: Number(hit.getAttribute('height')) },
    surround: BAND.elevator.surround,
    presses: { click: pressesBy('click', {}), enter: pressesBy('keydown', { key: 'Enter' }), space: pressesBy('keydown', { key: ' ' }), tab: pressesBy('keydown', { key: 'Tab' }) },
    disabled_riding: paint(ride('inside', 0)).control?.getAttribute('aria-disabled') ?? null,
};

// ── the band's leaves, per phase, at two elapsed times 300 ms apart ─────────────────────────────
const E = 40;
const phases = {
    opening: { ms: 500, door_delay_ms: 0 },
    entering: {}, inside: { ms: 250, door_delay_ms: null }, chosen: {}, leaving: { ms: 2000, door_delay_ms: null }, staying: {}, arriving: {},
};
const leavesLeg = Object.fromEntries(Object.entries(phases).map(([phase, over]) => [phase, {
    at: paint(ride(phase, E, over)).leaves,
    later: paint(ride(phase, E + 300, over)).leaves,
}]));

// ── the walks' leaves: unchanged with no ride, and nothing added while a ride holds them ─────────
const walksLeg = {
    no_ride: paint(null, WALK_DOORS).leaves,
    no_doors: paint(null, []).leaves,
    riding: paint(ride('inside', E, { ms: 250, door_delay_ms: null }), WALK_DOORS).leaves,
    riding_no_walk: paint(ride('inside', E, { ms: 250, door_delay_ms: null }), []).leaves,
};

// ── the cab ──────────────────────────────────────────────────────────────────────────────────────
const cabHost = new Node('div');
const cab = (r) => {
    painter.paintCab(cabHost, r);

    return {
        children: cabHost.children.length,
        leaves: withClass(cabHost, 'elevator-leaf').map((n) => n.getAttribute('style')),
        texts: nodes(cabHost).filter((n) => n.name === 'text').length,
    };
};
const cabLeg = {
    entering: cab(ride('entering', E)),
    inside: cab(ride('inside', E, { ms: 250, door_delay_ms: null })),
    chosen: cab(ride('chosen', E)),
    leaving: cab(ride('leaving', E, { ms: 2000, door_delay_ms: null })),
    emptied: cab(null),
};

console.log(JSON.stringify({ control: controlLeg, leaves: leavesLeg, walks: walksLeg, cab: cabLeg, elapsed: E }));

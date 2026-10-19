/**
 * AT-D3-26's PAINTER LEG — the floor's elevator as a control and the ride's leaves (`docs/design/FLOOR.md` § 4.5,
 * card#11795), over a fake DOM. `node`, no dependencies. Driven by `Tests\Feature\Floor\TheElevatorIsAControlOnTheDrawingTest`.
 *
 * The painter is handed one band and no desks, and painted under each ride phase at a stated elapsed time; what it
 * wrote is read back: the elevator control's attributes and hit rect, how many presses a click, Enter and Space
 * each make, each band leaf's `style`, and `paintCab()`'s cab — and (card#12039) what `cabTheme()` carries onto the
 * dialog from the shipped house theme's elevator palette, and the cab drawing's fit and paint — and (card#12051) the
 * cab's room in depth: its back wall, side walls, ceiling and floor as drawn, the layer behind its leaves, and the room
 * `drawCabInterior()` draws into a box of its own — and (card#12052) what is seen through the open doors: the office
 * in the cab's layer behind its leaves (`drawOfficeView()`), the cab's room behind the band's leaves, what is drawn after
 * the leaves in each doorway, and `STYLE`'s `color-mix()` fallback — and (card#12071) the call plate beside the cab's
 * doors, read as scenery and as a control would be, and the share of the cab's view its doorway takes.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: JSON, one member per leg (see the bottom).
 */

import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const JS = args.includes('--js') ? args[args.indexOf('--js') + 1] : join(HERE, '..', '..', '..', 'public', 'js');
/** The theme registry and modules, from disk — the tree the asset route serves as `/art/floor/themes/`. */
const THEMES = join(HERE, '..', '..', '..', '..', 'resources', 'floor', 'themes');
const host = new Node('div');

globalThis.document = {
    activeElement: null,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
};
globalThis.CSS = { escape: (s) => s };
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};

const { createPainter, CAB, LANDING, OFFICE, STYLE, drawCabInterior, drawOfficeView } = await import(pathToFileURL(join(JS, 'floor', 'painter.js')).href);

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

// ── the cab's palette and fit (card#12039) ───────────────────────────────────────────────────────
// A painter holding the shipped house theme (read from the registry, as the page does) carries its elevator palette
// onto the dialog and marks it `data-themed` (card#12047); one holding no theme removes what an earlier one set. The
// cab's drawing: its fit, its overflow, and what its frame and lamp are painted with.
const recording = (props = {}) => ({ props, setProperty(k, v) { props[k] = v; }, removeProperty(k) { delete props[k]; } });
/** A fake dialog: its inline style and its attributes, `attrs` set at the start. */
const dialogOf = (props, attrs = {}) => ({ style: recording(props), attrs, toggleAttribute(k, on) { if (on) { attrs[k] = ''; } else { delete attrs[k]; } return on; } });
const { HOUSE_THEME } = await import(pathToFileURL(join(THEMES, 'index.js')).href);
const house = await import(pathToFileURL(join(THEMES, HOUSE_THEME, 'theme.js')).href);
const themed = createPainter({ characters: null, themes: { [HOUSE_THEME]: house }, failed() {}, select() {}, house: HOUSE_THEME });
const dialog = dialogOf({});
const stale = dialogOf({ '--cab-frame': 'stale', '--cab-lamp': 'stale' }, { 'data-themed': '' });
const themedHost = new Node('div');

themedHost.style = recording();
themed.cabTheme(dialog);
painter.cabTheme(stale);
themed.paintCab(themedHost, ride('inside', E, { ms: 250, door_delay_ms: null }));

const svg = themedHost.children[0] ?? null;
const one = (cls) => { const n = withClass(themedHost, cls)[0] ?? null; return n === null ? null : { fill: n.getAttribute('fill') }; };
const paletteLeg = {
    palette: house.PALETTE.elevator,
    carried: dialog.style.props,
    unthemed: stale.style.props,
    marked: dialog.attrs,
    unmarked: stale.attrs,
    fit: svg?.getAttribute('preserveAspectRatio') ?? null,
    overflow: svg?.getAttribute('overflow') ?? null,
    viewBox: svg?.getAttribute('viewBox') ?? null,
    aspect: themedHost.style.props['--cab-aspect'] ?? null,
    frame: one('cab-frame'),
    lamp: one('cab-lamp'),
    style: nodes(themedHost).find((n) => n.name === 'style')?.textContent ?? '',
};

// ── the cab in depth (card#12051) ────────────────────────────────────────────────────────────────
// The themed cab's surfaces as drawn — each polygon's corners, the back wall's rect, the leaves' rects, and whether any
// carries an inline colour — the order of the doorway's layers, and the room drawn on its own into a stated box.
const num = (n, k) => Number(n.getAttribute(k));
const rectOf = (n) => (n === undefined || n === null ? null : { x: num(n, 'x'), y: num(n, 'y'), w: num(n, 'width'), h: num(n, 'height') });
const cornersOf = (n) => (n === undefined || n === null ? null : (n.getAttribute('points') ?? '').trim().split(/\s+/).map((p) => p.split(',').map(Number)));
const firstOf = (root, cls, also = null) => withClass(root, cls).find((n) => also === null || (n.getAttribute('class') ?? '').split(' ').includes(also)) ?? null;
const drawn = svg === null ? [] : nodes(svg);
const at = (cls) => drawn.findIndex((n) => (n.getAttribute?.('class') ?? '').split(' ').includes(cls));
const beyond = firstOf(themedHost, 'cab-beyond');
const room = new Node('g');
const box = { x: 10, y: 20, w: 80, h: 115 };
const answered = drawCabInterior(room, box, 'probe');
const depthLeg = {
    cab: CAB,
    landing: LANDING,
    viewBox: svg?.getAttribute('viewBox') ?? null,
    back: rectOf(firstOf(themedHost, 'cab-back')),
    left: cornersOf(firstOf(themedHost, 'cab-side', 'left')),
    right: cornersOf(firstOf(themedHost, 'cab-side', 'right')),
    ceiling: cornersOf(firstOf(themedHost, 'cab-ceiling')),
    floor: cornersOf(firstOf(themedHost, 'cab-floor')),
    leaves: withClass(themedHost, 'elevator-leaf').map(rectOf),
    // card#12052: each surface with its depth overlay — a `cab-shade` drawn over exactly its shape, the back wall's an even one.
    shaded: Object.fromEntries([['ceiling', 'cab-ceiling'], ['floor', 'cab-floor'], ['left', 'cab-side', 'left'], ['right', 'cab-side', 'right'], ['back', 'cab-back']].map(([part, cls, also]) => {
        const surface = firstOf(themedHost, cls, also ?? null);
        const shape = (n) => n.getAttribute('points') ?? ['x', 'y', 'width', 'height'].map((k) => n.getAttribute(k)).join(' ');
        return [part, surface !== null && withClass(themedHost, 'cab-shade').some((o) => o.name === surface.name && shape(o) === shape(surface))];
    })),
    inline: ['cab-back', 'cab-side', 'cab-ceiling', 'cab-floor'].flatMap((cls) => withClass(themedHost, cls).filter((n) => n.getAttribute('fill') !== null || n.getAttribute('style') !== null).map(() => cls)),
    beyond: beyond === null ? null : { children: beyond.children.length, at: at('cab-beyond'), recess: at('cab-recess'), leaf: at('elevator-leaf') },
    alone: {
        box,
        answered: answered.back,
        back: rectOf(firstOf(room, 'cab-back')),
        sides: withClass(room, 'cab-side').length,
        ceiling: withClass(room, 'cab-ceiling').length,
        floor: withClass(room, 'cab-floor').length,
        leaves: withClass(room, 'elevator-leaf').length + withClass(room, 'cab-frame').length,
        ids: nodes(room).map((n) => n.getAttribute?.('id')).filter((v) => v !== null && v !== undefined),
    },
};

// ── through the doors (card#12052) ───────────────────────────────────────────────────────────────
// Every element under `root`, with what a check of scenery reads off it: its tag, its classes, its id, any attribute that
// could carry a datum (`data-*`, `href`, `aria-label`), any colour written on it rather than by its class (a `fill`,
// `stroke` or `stop-color` that is not a `url(#…)` reference, or a `style`), and any motion (an `<animate…>`/`<set>`, a
// style naming an animation or a transition).
const census = (root) => nodes(root).slice(1).map((n) => ({
    tag: n.name,
    cls: (n.getAttribute?.('class') ?? '').split(' ').filter(Boolean),
    id: n.getAttribute?.('id') ?? null,
    data: Object.keys(n.attrs ?? {}).filter((k) => k.startsWith('data-') || k === 'href' || k === 'aria-label' || k === 'role'),
    inline: ['fill', 'stroke', 'stop-color', 'color', 'style'].filter((k) => { const v = n.getAttribute?.(k); return v !== null && v !== undefined && !(k !== 'style' && /^url\(#[a-z0-9-]+\)$/.test(v)); }),
    motion: /^(animate|animateTransform|animateMotion|set)$/.test(n.name) || /animation|transition/.test(n.getAttribute?.('style') ?? ''),
    text: n.name === 'text' || (n.textContent ?? '') !== '',
}));
/** The clip rect a group under `root` is clipped to: the `<clipPath>` its `clip-path` names, read back as a rect. */
const clipOf = (root) => {
    const clipped = nodes(root).find((n) => /^url\(#(.+)\)$/.test(n.getAttribute?.('clip-path') ?? '')) ?? null;
    if (clipped === null) return null;
    const id = /^url\(#(.+)\)$/.exec(clipped.getAttribute('clip-path'))[1];
    const path = nodes(root).find((n) => n.name === 'clipPath' && n.getAttribute('id') === id) ?? null;
    return { id, rect: rectOf(path?.children[0]), drawn: nodes(clipped).filter((n) => n.name === 'polygon' || n.name === 'rect').length };
};

// The office, in the themed cab's layer behind its leaves: its doorway (the two leaves' span) and what is drawn in it.
const [leafL, leafR] = withClass(themedHost, 'elevator-leaf').map(rectOf);
const officeIn = beyond === null ? null : firstOf(beyond, 'office-view');
const officeAlone = new Node('g');
const officeBox = { x: 5, y: 7, w: 55, h: 100 };
const officeAnswer = drawOfficeView(officeAlone, officeBox, 'probe-office');
const officeLeg = {
    office: OFFICE,
    door: leafL === undefined ? null : { x: leafL.x, y: leafL.y, w: leafR.x + leafR.w - leafL.x, h: leafL.h },
    found: officeIn !== null,
    clip: officeIn === null ? null : clipOf(officeIn),
    census: officeIn === null ? [] : census(officeIn),
    beyond: beyond === null ? [] : census(beyond).filter((c) => c.cls.includes('office-view')).length,
    alone: { box: officeBox, answered: officeAnswer.clip, clip: clipOf(officeAlone), ids: census(officeAlone).map((c) => c.id).filter((v) => v !== null) },
};

// The band's cab: a painter with the house theme and one without, each painting the band at rest and over a walk.
const bandOf = (p, doors) => {
    const scene = sceneOf({ doors: structuredClone(doors) });
    const before = JSON.stringify(scene);
    p.paint(scene, { bounds: null });
    const band = nodes(host).find((n) => n.getAttribute?.('class') === 'band') ?? null;
    const order = band === null ? [] : nodes(band);
    const cab = band === null ? null : firstOf(band, 'band-cab');
    const control = order.find((n) => n.getAttribute?.('data-key') === 'elevator') ?? null;
    const leafAt = order.findIndex((n) => (n.getAttribute?.('class') ?? '').split(' ').includes('elevator-leaf'));
    const rooms = order.map((n, i) => [n, i]).filter(([n]) => (n.getAttribute?.('class') ?? '') === 'cab-room');
    return {
        found: cab !== null,
        // Every cab room in the band, by draw order, against the first leaf.
        rooms: rooms.map(([, i]) => i),
        leaf: leafAt,
        sides: cab === null ? 0 : withClass(cab, 'cab-side').length,
        back: cab === null ? null : rectOf(firstOf(cab, 'cab-back')),
        surfaces: cab === null ? [] : ['cab-ceiling', 'cab-floor', 'cab-back'].filter((k) => withClass(cab, k).length === 1),
        plates: band === null ? 0 : withClass(band, 'cab-plate').length,
        clip: cab === null ? null : clipOf(cab),
        census: cab === null ? [] : census(cab),
        carriers: cab?.getAttribute('style') ?? null,
        // The control's children as drawn: the leaves, then the hit area — nothing after the leaves but it.
        control: control === null ? [] : control.children.map((n) => `${n.name}.${n.getAttribute('class') ?? ''}`),
        scene: JSON.stringify(scene) === before,
        doors: scene.doors,
    };
};
const bandLeg = {
    elevator: BAND.elevator,
    themed: bandOf(themed, []),
    walking: bandOf(themed, WALK_DOORS),
    unthemed: bandOf(painter, WALK_DOORS),
    palette: house.PALETTE.elevator,
};

// ── the call plate beside the doors, and how close the eye stands (card#12071) ─────────────────────
// The decorative plate in the themed cab: the group's own attributes (what a focus, a pointer or a screen reader would
// reach), every element under it read as scenery is, its rects against the doors' wall and the door's frame, and the
// sheet's rule for it. And the share of the cab's view the doorway the leaves span takes.
const plate = firstOf(themedHost, 'cab-plate');
const frameRect = rectOf(firstOf(themedHost, 'cab-frame'));
const plateLeg = {
    count: withClass(themedHost, 'cab-plate').length,
    attrs: plate === null ? null : Object.fromEntries(Object.entries(plate.attrs)),
    listeners: plate === null ? [] : nodes(plate).flatMap((n) => Object.keys(n.listeners ?? {})),
    census: plate === null ? [] : census(plate),
    body: rectOf(plate === null ? null : firstOf(plate, 'cab-plate-body')),
    buttons: plate === null ? 0 : withClass(plate, 'cab-plate-button').length,
    windows: plate === null ? 0 : withClass(plate, 'cab-plate-window').length,
    frame: frameRect,
    back: depthLeg.back,
    before: { plate: at('cab-plate'), beyond: at('cab-beyond') },
};
const viewOf = (door) => door === null ? null : (door.w * door.h) / (CAB.w * CAB.h);
const closerLeg = { share: viewOf(officeLeg.door), door: officeLeg.door };

// What the cab draws after its layer behind the leaves: the leaves, and nothing else.
const cabOrder = svg === null ? [] : svg.children.map((n) => `${n.name}.${n.getAttribute('class') ?? ''}`);
const seamLeg = { cab: cabOrder.slice(cabOrder.findIndex((c) => c.endsWith('.cab-beyond'))), band: bandLeg.themed.control };

console.log(JSON.stringify({ control: controlLeg, leaves: leavesLeg, walks: walksLeg, cab: cabLeg, palette: paletteLeg, depth: depthLeg, office: officeLeg, band: bandLeg, plate: plateLeg, closer: closerLeg, seam: seamLeg, style: STYLE, elapsed: E }));

/**
 * The lobby room's and the directory's PAINT, over a fake DOM — `lobby/building-paint.js` (the room, its elevator
 * control and the cab behind its leaves), `lobby/building-scene.js` (the room's shapes and its window) and
 * `lobby/directory.js` (the plaques, their counter-scale and their boxes). `node`, no dependencies. Driven by
 * `Tests\Feature\Lobby\TheLobbyIsADrawnRoomTest` and `Tests\Feature\Lobby\TheLobbysElevatorIsTheFloorsRideTest`.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdin: JSON `{ "plaques": [<lobby-model.js plaque>, …], "unclaimed":
 * [<room>, …], "rides": [<scene.ride>|null, …] }` — the plaques painted, the F17 rows painted, and each ride the room's
 * leaves are painted under. stdout: JSON, one member per leg (see the bottom).
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from '../Floor/fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const JS = args.includes('--js') ? args[args.indexOf('--js') + 1] : join(HERE, '..', '..', '..', 'public', 'js');
const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');

/** The fake node, with what the lobby's modules touch beside what the painter's do. */
class LobbyNode extends Node {
    constructor(name) {
        super(name);
        this.style = new Proxy({ props: {} }, {
            get: (target, key) => (key === 'props' ? target.props : key === 'setProperty' ? (k, v) => { target.props[k] = v; } : key === 'removeProperty' ? (k) => { delete target.props[k]; } : target.props[key] ?? ''),
            set: (target, key, value) => { target.props[key] = value; return true; },
        });
    }

    removeAttribute(k) { delete this.attrs[k]; }

    toggleAttribute(k, on) { if (on) { this.attrs[k] = ''; } else { delete this.attrs[k]; } }

    set className(v) { this.attrs.class = v; }

    get className() { return this.attrs.class ?? ''; }

    set href(v) { this.attrs.href = v; }

    get href() { return this.attrs.href ?? null; }

    prepend(...nodes) { for (const n of nodes.reverse()) { n.parentNode = this; this.children.unshift(n); } }

    // An append moves a node: it leaves the parent it had.
    append(...nodes) { for (const n of nodes) { n.parentNode?.children && n.parentNode !== this && n.parentNode.children.includes(n) && n.parentNode.children.splice(n.parentNode.children.indexOf(n), 1); n.parentNode = this; this.children.push(n); } }

    remove() { const p = this.parentNode; if (p !== null) { p.children.splice(p.children.indexOf(this), 1); this.parentNode = null; } }

    get firstElementChild() { return this.children[0] ?? null; }

    get lastChild() { return this.children.at(-1) ?? null; }

    get childNodes() { return [...this.children]; }
}

globalThis.document = {
    createElementNS: (ns, name) => new LobbyNode(name),
    createElement: (name) => new LobbyNode(name),
    createTextNode: (text) => Object.assign(new LobbyNode('#text'), { textContent: text }),
};

const url = (file) => pathToFileURL(join(JS, file)).href;
const { ELEVATOR_NAME, paintRoom, roomDrawing, themeRoomCab } = await import(url('lobby/building-paint.js'));
const { CEILING_GRID, INK, ROOM, lobbyScene, roomArt, windowArt } = await import(url('lobby/building-scene.js'));
const { boxStyles, plaqueRow, renderDirectory, showDirectory, unclaimedRow } = await import(url('lobby/directory.js'));
const { createCamera, frameOn, unframe } = await import(url('wire/camera.js'));

/** Every node under `n`, depth first. */
const nodes = (n, out = []) => { out.push(n); n.children.forEach((c) => nodes(c, out)); return out; };
const classOf = (n) => (n.attrs?.class ?? '').split(' ').filter(Boolean);
const withClass = (root, cls) => nodes(root).filter((n) => classOf(n).includes(cls));

/** A node as data: its name, attributes, style properties, text and children. */
const tree = (n) => ({
    name: n.name,
    attrs: { ...n.attrs },
    style: { ...n.style.props },
    dataset: { ...n.dataset },
    text: n.children.length === 0 ? n.textContent : null,
    children: n.children.map(tree),
});

// ── The room ────────────────────────────────────────────────────────────────────────────────

let presses = 0;
const room = roomDrawing(document, () => { presses += 1; });
const all = nodes(room.art);
const control = room.control;
const key = (k) => ({ key: k, preventDefault() { this.prevented = true; } });

// A click, Enter and Space each press once; another key presses nothing.
for (const type of ['click']) (control.listeners?.[type] ?? []).forEach((fn) => fn({ type }));
const enter = key('Enter');
const space = key(' ');
const other = key('a');
for (const e of [enter, space, other]) (control.listeners?.keydown ?? []).forEach((fn) => fn(e));

const painted = (payload.rides ?? [null]).map((ride) => {
    paintRoom(document, room, ride, 'day', null);

    return {
        ride,
        leaves: room.leaves.map((leaf) => leaf.attrs.style ?? null),
        disabled: control.attrs['aria-disabled'] ?? null,
    };
});

// The window painted at each phase, then the same phase again (nothing repainted).
const windowPaints = [];
let last = null;
for (const phase of ['night', 'night', 'day', null]) {
    const before = room.windows.children;
    last = paintRoom(document, room, null, phase, last);
    windowPaints.push({ phase, painted: last, repainted: room.windows.children !== before, shapes: room.windows.children.length });
}

themeRoomCab(room.cab, { frame: '#c9a873', lamp: '#f2b84b' });
const themed = room.cab.attrs.style ?? null;
themeRoomCab(room.cab, null);
const unthemed = room.cab.attrs.style ?? null;

const clip = nodes(room.cab).find((n) => n.name === 'clipPath');
const clipped = nodes(room.cab).find((n) => n.attrs['clip-path'] !== undefined);

const roomOut = {
    scene: lobbyScene(),
    room: ROOM,
    ceiling_grid: CEILING_GRID,
    ink: INK,
    art: { name: room.art.name, attrs: { ...room.art.attrs } },
    // Every text node the room's drawing carries, and every attribute name any of its nodes carries.
    texts: all.filter((n) => n.name === 'text').map((n) => n.textContent),
    attribute_names: [...new Set(all.flatMap((n) => Object.keys(n.attrs)))].sort(),
    data_attributes: all.flatMap((n) => Object.keys(n.attrs).filter((k) => k.startsWith('data-')).map((k) => `${n.name}[${k}=${n.attrs[k]}]`)),
    panels: withClass(room.art, 'lobby-ceiling-panel').map((n) => n.attrs.points.split(' ').map((p) => p.split(',').map(Number))),
    window_glass: windowArt('day').shapes.filter((s) => String(s.attrs.fill ?? '').startsWith('url(#')).length,
    control: { name: control.name, attrs: { ...control.attrs }, children: control.children.map((c) => ({ name: c.name, attrs: { ...c.attrs } })) },
    control_index: room.art.children.indexOf(control),
    children: room.art.children.map((c) => ({ name: c.name, attrs: { ...c.attrs } })),
    presses,
    keys: { enter: enter.prevented === true, space: space.prevented === true, other: other.prevented === true },
    elevator_name: ELEVATOR_NAME,
    cab: {
        attrs: { ...room.cab.attrs },
        rooms: withClass(room.cab, 'cab-room').length,
        backs: withClass(room.cab, 'cab-back').length,
        clip: clip === undefined ? null : clip.children.map((c) => ({ ...c.attrs })),
        clipped: clipped === undefined ? null : clipped.attrs['clip-path'],
        themed,
        unthemed,
    },
    painted,
    window_paints: windowPaints,
    // `roomArt()` read twice: the same shapes — it takes nothing, so nothing can move it.
    art_stable: JSON.stringify(roomArt()) === JSON.stringify(roomArt()),
    art_shape_count: roomArt().shapes.length,
};

// ── The directory ───────────────────────────────────────────────────────────────────────────

const plaques = (payload.plaques ?? []).map((plaque, i) => tree(plaqueRow(document, plaque, i)));
const unclaimed = (payload.unclaimed ?? []).map((r) => tree(unclaimedRow(document, r)));
const emptyList = new LobbyNode('ol');
renderDirectory(document, emptyList, { floors: [], unclaimed: [] });

const framed = frameOn(createCamera({ width: 1280, height: 800 }), lobbyScene().extent);
const scaled = (camera) => {
    const layer = new LobbyNode('div');

    showDirectory(layer, camera);

    return { ...layer.style.props };
};

// A re-render over the same rows (the 1 s tick's, or a render's): the list rendered from the summary `rerender[0]`, its
// first row's link marked, then rendered from `rerender[1]` — whether that link is the same element, and the list as it
// then stands.
let rerendered = null;

if (payload.rerender !== undefined) {
    const list = new LobbyNode('ol');
    const linkOf = (li) => (li?.children[0]?.name === 'a' ? li.children[0] : li?.children[0]?.children[0]) ?? null;

    renderDirectory(document, list, payload.rerender[0]);
    const first = linkOf(list.children[0]);

    if (first !== null) first.marked = true;
    renderDirectory(document, list, payload.rerender[1]);
    rerendered = { kept: linkOf(list.children[0])?.marked === true, list: tree(list) };
}

const directoryOut = {
    rerendered,
    plaques,
    unclaimed,
    empty: tree(emptyList),
    shown: { framed: scaled(framed), zoomed: scaled({ ...framed, zoom: framed.zoom * 2 }), unframed: scaled(unframe(framed)), zoom: framed.zoom },
    boxes: { drawn: boxStyles(lobbyScene().directory, true), undrawn: boxStyles(lobbyScene().directory, false) },
};

process.stdout.write(JSON.stringify({ room: roomOut, directory: directoryOut }, null, 2));

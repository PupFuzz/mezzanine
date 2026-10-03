/**
 * card#11058 B1 — THE PAINTER, OVER A FAKE DOM: every text and rect it paints for a desk lies inside that
 * desk's box. `node`, no dependencies. Driven by `TheNewDeskKeepsEveryLeafTest`.
 *
 * ⛔ WHY: the layout's rects are checked by AT-D3-20 and the guard, but the PAINTER turns them into SVG
 * and can still put a desk's text somewhere else — the class this exists for is a desk-local offset the
 * painter used as an absolute one (a badge's text drawn at the room's left edge, review r1 of PR #268,
 * found only on a screenshot). So the check reads what `createPainter().paint()` actually wrote: each
 * desk's `<g>`, each child `rect` / `image` by its attributes, each `text` by its `x`, its baseline and its
 * text measured with the harness measurer the layout was given.
 *
 * The desks are every planted seat (`desk-leaves/fx-desk-leaves-planted.json` — the bounds, the caps,
 * the badges, the nulls) confirmed, unconfirmed and as the placeholder, each placed at its own box on a
 * row, through the real `placeDesk()` / `placeBubbles()`.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: `{ desks, painted, defects: [ … ] }`.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const JS = args.includes('--js') ? args[args.indexOf('--js') + 1] : join(HERE, '..', '..', '..', 'public', 'js');
const mod = async (p) => import(pathToFileURL(join(JS, p)).href);

// ── the fake DOM: exactly what the painter touches ────────────────────────────────────────────────
class Node {
    constructor(name) {
        this.name = name;
        this.attrs = {};
        this.children = [];
        this.parentNode = null;
        this.dataset = {};
        this.style = { setProperty() {} };
        this.textContent = '';
    }

    setAttribute(k, v) { this.attrs[k] = String(v); }

    getAttribute(k) { return this.attrs[k] ?? null; }

    append(...nodes) { for (const n of nodes) { n.parentNode = this; this.children.push(n); } }

    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }

    addEventListener() {}

    contains() { return false; }

    focus() {}

    querySelector() { return null; }

    querySelectorAll() { return []; }
}

const host = new Node('div');

globalThis.document = {
    activeElement: null,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
    createElement: () => ({ getContext: () => ({}), toDataURL: () => 'data:,' }),
};
globalThis.CSS = { escape: (s) => s };

const { deskModel } = await mod('desk/desk-render.js');
const { placeDesk, placeBubbles } = await mod('floor/scene.js');
const { createPainter } = await mod('floor/painter.js');
const { LINE } = await mod('floor/desk-layout.js');
const { deskAgeReadout } = await mod('wire/age-readout.js');

const NOW_MS = Date.parse('2026-08-23T14:23:22.400Z');
const GLYPH_W = 6;
const measure = (text) => ({ w: [...String(text)].length * GLYPH_W, h: LINE });
const BOX = { width: 440, height: 228 };

// ── the desks, each at its own box on one row ─────────────────────────────────────────────────────
const desks = [];
const seats = JSON.parse(readFileSync(join(HERE, 'desk-leaves', 'fx-desk-leaves-planted.json'), 'utf8')).seats.map((s) => s.seat);

for (const seat of seats) {
    for (const v of [{ missing: false, placeholder: false }, { missing: true, placeholder: false }, { missing: false, placeholder: true }]) {
        const model = deskModel(seat, deskAgeReadout(seat, NOW_MS), { missing: v.missing, derivation_stamp: '2026-08-23T14:23:14.400Z' }, {});

        if (model === null) {
            continue;
        }

        // One room id per placed desk: the bubble pass keys desks by `(install_id, seat_id)`, and the
        // variants of one seat would otherwise share a bubble.
        const room = `room-${desks.length}`;
        const key = `${room}/${seat.seat_id}`;
        const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, sprite: { url: 'desk.png', w: 116, h: 57 }, placeholder: v.placeholder };

        desks.push(placeDesk(model, key, room, null, { x: desks.length * BOX.width, y: 0 }, ctx, { overflow: false, slot: null, object_id: null }));
    }
}

placeBubbles(desks, measure, BOX.width, desks.map((d) => d.key));

const scene = { band: null, slab: null, tiles: [], planes: [], decorative: [], lines: [], desks, strip: null, effects: [] };
const painter = createPainter({ characters: { paintSceneFrame() {} }, failed() {}, select() {} });

painter.paint(scene, { bounds: null });

// ── read back what was painted ────────────────────────────────────────────────────────────────────
const svg = host.children[0];
const layer = svg?.children.find((n) => n.getAttribute('class') === 'desks');
const boxes = new Map(desks.map((d) => [d.key, d.box]));
const defects = [];
let painted = 0;
const num = (n, k) => Number(n.getAttribute(k));

for (const g of layer?.children ?? []) {
    const key = g.getAttribute('data-key');
    const b = boxes.get(key);
    const inside = (x0, y0, x1, y1) => x0 >= b.x - 1e-6 && y0 >= b.y - 1e-6 && x1 <= b.x + b.w + 1e-6 && y1 <= b.y + b.h + 1e-6;

    for (const n of g.children) {
        let rect = null;

        if (n.name === 'rect' || n.name === 'image') {
            rect = [num(n, 'x'), num(n, 'y'), num(n, 'x') + num(n, 'width'), num(n, 'y') + num(n, 'height')];
        } else if (n.name === 'text') {
            // `y` is the baseline the painter wrote (the element's top + LINE − 2).
            const top = num(n, 'y') - (LINE - 2);

            rect = [num(n, 'x'), top, num(n, 'x') + measure(n.textContent).w, top + LINE];
        } else if (n.name === 'line') {
            rect = [num(n, 'x1'), Math.min(num(n, 'y1'), num(n, 'y2')), num(n, 'x2'), Math.max(num(n, 'y1'), num(n, 'y2'))];
        } else {
            continue;
        }

        painted++;

        if (rect.some((v) => !Number.isFinite(v)) || !inside(...rect)) {
            defects.push(`${key}: a painted ${n.name} (${n.getAttribute('class') ?? ''}${n.textContent ? ` «${n.textContent.slice(0, 30)}»` : ''}) `
                + `at [${rect.map((v) => Math.round(v)).join(', ')}] leaves the desk's box [${b.x}, ${b.y}, ${b.x + b.w}, ${b.y + b.h}]`);
        }
    }
}

if (layer === undefined) {
    defects.push('the painter drew no desks layer');
}

console.log(JSON.stringify({ desks: desks.length, painted, defects }));

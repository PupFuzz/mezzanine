/**
 * card#11058 B1/B2 — THE PAINTER, OVER A FAKE DOM: every node it paints for a desk is exactly where that
 * desk's layout element puts it, and inside the desk's box. `node`, no dependencies. Driven by
 * `TheNewDeskKeepsEveryLeafTest`.
 *
 * ⛔ WHY: the layout's rects are checked by AT-D3-20 and the guard, but the PAINTER turns them into SVG
 * and can still put a desk's text somewhere else — the class this exists for is a desk-local offset the
 * painter used as an absolute one (a badge's text drawn at the room's left edge, review r1 of PR #268,
 * found only on a screenshot). So the check reads what `createPainter().paint()` actually wrote: each
 * desk's `<g>`, each child `rect` / `<svg>` viewport / `image` by its attributes, each `text` by its `x`,
 * its baseline and its text measured with the harness measurer in the type role it was drawn in.
 *
 * ⛔ TWO CHECKS, AND CONTAINMENT IS THE WEAKER (PR #268 review r2): a node displaced INSIDE its box — a
 * chip's rect drawn 40 px right of its element — stays inside the box and reads clean to containment. So
 * each element of `desk.elements` is walked in the painter's order and the nodes it paints are consumed
 * one by one, each held EQUAL to the element's x / y / w / h — plus the offset the painter applies where
 * it applies one (a badge's and the flag's `text_dx`, the chip's centring, the interim intern glyph's
 * placement in its rect, an art image at its viewport's origin) — and a node no element accounts for, or
 * an element whose node is missing, is a defect too. Containment is kept beside it.
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
const { TYPE_ROLES, STOOL_GLYPH } = await mod('floor/desk-layout.js');
const { deskAgeReadout } = await mod('wire/age-readout.js');
const { harnessMeasurer } = await import('../Support/harness-measurer.mjs');

const NOW_MS = Date.parse('2026-08-23T14:23:22.400Z');
const PLANTED = JSON.parse(readFileSync(join(HERE, 'desk-leaves', 'fx-desk-leaves-planted.json'), 'utf8'));
const measure = harnessMeasurer(PLANTED.measurer);
const BOX = { width: 440, height: 228 };

// ── the desks, each at its own box on one row ─────────────────────────────────────────────────────
const desks = [];
const seats = PLANTED.seats.map((s) => s.seat);

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
const byKey = new Map(desks.map((d) => [d.key, d]));
const defects = [];
let painted = 0;
const EPS = 1e-6;
const num = (n, k) => Number(n.getAttribute(k));
const same = (a, b) => Number.isFinite(a) && Math.abs(a - b) <= EPS;
const classes = (n) => (n.getAttribute('class') ?? '').split(' ');
const roleOf = (n) => classes(n).find((c) => c.startsWith('role-'))?.slice('role-'.length) ?? 'fact';
const label = (n) => `${n.name} (${n.getAttribute('class') ?? ''}${n.textContent ? ` «${n.textContent.slice(0, 30)}»` : ''})`;

/** A painted node's own rect, in the drawing's coordinates — a text's from its baseline and its role. */
function rectOf(n) {
    if (n.name === 'rect' || n.name === 'image' || n.name === 'svg') {
        return { x: num(n, 'x'), y: num(n, 'y'), w: num(n, 'width'), h: num(n, 'height') };
    }

    if (n.name === 'text') {
        const role = TYPE_ROLES[roleOf(n)];
        const size = measure(n.textContent, roleOf(n));

        return { x: num(n, 'x'), y: num(n, 'y') - (role?.baseline ?? NaN), w: size.w, h: size.h };
    }

    if (n.name === 'line') {
        return { x: num(n, 'x1'), y: Math.min(num(n, 'y1'), num(n, 'y2')), w: num(n, 'x2') - num(n, 'x1'), h: Math.abs(num(n, 'y2') - num(n, 'y1')) };
    }

    return null;
}

/**
 * What the painter owes one layout element, in order: `[node name, {x, y, w?, h?, text?}, extra check?]`.
 * The offsets are the painter's stated ones — and nothing else may move a node off its element.
 */
function owed(e) {
    const at = { x: e.x, y: e.y, w: e.w, h: e.h };

    switch (e.kind) {
        case 'character':
        case 'desk-sprite':
            // The art's clipping viewport at the element's rect, the image at the viewport's origin.
            return [['svg', at, (n) => {
                const img = n.children[0];

                return n.children.length === 1 && img.name === 'image' && n.getAttribute('overflow') === 'hidden'
                    && n.getAttribute('viewBox') === `0 0 ${e.w} ${e.h}`
                    && same(num(img, 'x'), 0) && same(num(img, 'y'), 0) && same(num(img, 'width'), e.w) && same(num(img, 'height'), e.h)
                    ? null : 'its clipping viewport does not hold the one image at the viewport\'s own rect';
            }]];
        case 'gauge-bar':
            return [['rect', at], ['rect', { ...at, w: e.w * (e.pct / 100) }]];
        case 'badge':
            // The id cut to the chip less its padding on both sides: inside, at the padding's offset.
            return [['rect', at], ['text', { x: e.x + e.text_dx, y: e.y, h: e.h, text: e.text },
                (n) => (rectOf(n).w <= e.w - 2 * e.text_dx + EPS ? null : 'its text is wider than the chip less its padding')]];
        case 'flag':
            return [['rect', at], ['text', { x: e.x + e.text_dx, y: e.y, w: e.w - 2 * e.text_dx, h: e.h, text: e.text }]];
        case 'chip':
            return [['rect', at], ['text', { x: e.x + (e.w - e.text_w) / 2, y: e.y, w: e.text_w, text: e.text }]];
        case 'stool':
            return [['rect', { x: e.x + (e.w - STOOL_GLYPH) / 2, y: e.y + e.h - STOOL_GLYPH, w: STOOL_GLYPH, h: STOOL_GLYPH }]];
        case 'chair':
        case 'monitor':
        case 'placeholder':
        case 'side-table':
        case 'plate':
        case 'lag-overlay':
            return [['rect', at]];
        default:
            return typeof e.text === 'string' ? [['text', { ...at, text: e.text }]] : [];
    }
}

/** The defects of one painted node against what was owed it. */
function compare(key, i, e, n, [name, want, extra]) {
    const where = `${key}: element ${i} (${e.kind})`;

    if (n === undefined) {
        return [`${where} is not painted — the painter drew no ${name} for it`];
    }

    if (n.name !== name) {
        return [`${where} is painted as a ${label(n)}, not a ${name}`];
    }

    const got = rectOf(n);
    const out = [];

    for (const k of ['x', 'y', 'w', 'h']) {
        if (want[k] !== undefined && !same(got[k], want[k])) {
            out.push(`${where}: the painted ${label(n)} has ${k} ${Math.round(got[k] * 100) / 100} and its element owes ${Math.round(want[k] * 100) / 100}`);
        }
    }

    if (want.text !== undefined && n.textContent !== want.text) {
        out.push(`${where}: the painted text reads «${n.textContent}» and its element «${want.text}»`);
    }

    const more = extra?.(n) ?? null;

    if (more !== null) {
        out.push(`${where}: ${more}`);
    }

    return out;
}

for (const g of layer?.children ?? []) {
    const key = g.getAttribute('data-key');
    const desk = byKey.get(key);
    const b = desk.box;
    const nodes = [...g.children];
    let next = 0;

    // ── equality: each element's nodes, in the painter's order ──
    desk.elements.forEach((e, i) => {
        for (const want of owed(e)) {
            defects.push(...compare(key, i, e, nodes[next], want));
            next++;
        }
    });

    if (desk.bubble !== null) {
        const bb = desk.bubble;
        const lines = [['line', { x: bb.tail.x, w: 0 }], ['rect', { x: bb.x, y: bb.y, w: bb.w, h: bb.h }],
            ['text', { x: bb.x + 3, y: bb.y + 3, text: bb.text }]];

        if (bb.second !== null) {
            lines.push(['text', { x: bb.x + 3, y: bb.y + 3 + TYPE_ROLES.fact.line, text: bb.second }]);
        }

        for (const want of lines) {
            defects.push(...compare(key, 'bubble', { kind: 'bubble' }, nodes[next], want));
            next++;
        }
    }

    for (const n of nodes.slice(next)) {
        defects.push(`${key}: a painted ${label(n)} that no layout element accounts for`);
    }

    // ── containment: every node inside the desk's box ──
    for (const n of nodes) {
        const r = rectOf(n);

        if (r === null) {
            continue;
        }

        painted++;

        const inside = r.x >= b.x - EPS && r.y >= b.y - EPS && r.x + r.w <= b.x + b.w + EPS && r.y + r.h <= b.y + b.h + EPS;

        if (![r.x, r.y, r.w, r.h].every(Number.isFinite) || !inside) {
            defects.push(`${key}: a painted ${label(n)} at [${[r.x, r.y, r.x + r.w, r.y + r.h].map((v) => Math.round(v)).join(', ')}] `
                + `leaves the desk's box [${b.x}, ${b.y}, ${b.x + b.w}, ${b.y + b.h}]`);
        }
    }
}

if (layer === undefined) {
    defects.push('the painter drew no desks layer');
}

console.log(JSON.stringify({ desks: desks.length, painted, defects }));

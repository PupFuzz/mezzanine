/**
 * AT-D3-25's PAINTER LEGS — the floor's theme as the painter draws it (`docs/design/FLOOR.md § 10.6` items 2 and
 * 8, card#11046 Appendix B row 22), over a fake DOM. `node`, no dependencies. Driven by
 * `Tests\Feature\Floor\AFloorIsDrawnInItsThemeTest`.
 *
 * The theme is STUBBED: every document names the function and the inputs it was drawn for, and the stub can
 * be told to throw for one function or one input — so what the painter does with a document is read without
 * the house theme's drawing in the way. Four legs, each a list of defects:
 *   - FALLBACK: a scene whose theme cannot draw (every `doc` null, no `band_docs`) — the band's flat `--house-wall`
 *     fill and every plane's flat `--scene-floor` fill are drawn, and no theme image is;
 *   - SET: one desk's furniture set throwing in its fourth document — that desk draws none of its five, the
 *     painter reports its asset alone, and every other desk draws all five;
 *   - BAND: the band's documents throwing in one window's surround — none of the band's documents is drawn, its
 *     flat fill is, and the band's asset alone is reported;
 *   - CACHE: a plane re-drawn after a map save that changes its runs under the SAME asset id is new markup, and
 *     the theme was asked again — the painter's cache is keyed on the document's inputs, never on its asset id.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: `{ legs: { <leg>: { painted, defects } } }`.
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
    createElement: () => ({ getContext: () => ({ font: '', measureText: (t) => ({ width: String(t).length }) }) }),
};
globalThis.CSS = { escape: (s) => s };
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};

const { createPainter } = await import(pathToFileURL(join(JS, 'floor', 'painter.js')).href);

const doc = (what) => `<svg xmlns="http://www.w3.org/2000/svg"><title>${what}</title></svg>`;

const calls = [];
let throwWhen = () => false;
const FNS = ['band', 'windowSurround', 'elevatorSurround', 'clockCase', 'plane', 'scenery', 'chair', 'desk', 'monitorFrame', 'deskProps', 'sideTable'];
const theme = Object.fromEntries(FNS.map((fn) => [fn, (input) => {
    calls.push(fn);

    if (throwWhen(fn, input)) {
        throw new Error(`a planted defect in ${fn}`);
    }

    return doc(`${fn} ${JSON.stringify(input)}`);
}]));

/** Every node under the drawing, depth first. */
const nodes = (n = host, out = []) => { out.push(n); n.children.forEach((c) => nodes(c, out)); return out; };
const images = () => nodes().filter((n) => n.name === 'image').map((n) => String(n.getAttribute('href')));
const classed = (cls) => nodes().filter((n) => (n.getAttribute?.('class') ?? '').split(' ').includes(cls));

const BAND = {
    x: 0, y: 0, w: 1000, h: 160,
    zone: { x: 0, y: 0, w: 272, h: 160 },
    elevator: { x: 32, y: 16, w: 84, h: 144, seam: 74, frame: { x: 26, y: 2, w: 96, h: 158 }, surround: { x: 26, y: 2, w: 114, h: 158 } },
    clock: { x: 164, y: 48, w: 64, h: 64, set: true, hour_angle_deg: 10, minute_angle_deg: 120, text: '14:23' },
    windows: [{ x: 400, y: 22, w: 208, h: 80, sky: 'day', surround: { x: 368, y: 6, w: 272, h: 114 } }, { x: 760, y: 22, w: 208, h: 80, sky: 'day', surround: { x: 728, y: 6, w: 272, h: 114 } }],
};
const bandDocs = (asset) => ({
    asset,
    theme: 'studio',
    docs: [
        { fn: 'band', rect: { x: 0, y: 0, w: 1000, h: 160 }, input: { w: 1000 } },
        { fn: 'windowSurround', rect: BAND.windows[0].surround, input: { index: 0 } },
        { fn: 'windowSurround', rect: BAND.windows[1].surround, input: { index: 1 } },
        { fn: 'elevatorSurround', rect: BAND.elevator.surround, input: {} },
        { fn: 'clockCase', rect: { x: 164, y: 48, w: 64, h: 64 }, input: {} },
    ],
});
const planeOf = (id, walls, withDoc = true) => ({
    install_id: id, x: 0, y: 160, w: 1000, h: 400,
    doc: withDoc ? { asset: `theme:studio/plane:${id}`, theme: 'studio', fn: 'plane', input: { w: 1000, h: 400, seed: id, walls } } : null,
});
const FURNITURE = [['chair', 'chair'], ['desk-sprite', 'desk'], ['monitor-frame', 'monitorFrame'], ['desk-props', 'deskProps'], ['side-table', 'sideTable']];
const deskOf = (seat, i) => {
    const set = `theme:studio/desk:aimla/${seat}`;

    return {
        key: `aimla/${seat}`, install_id: 'aimla', seat_id: seat, placeholder: false, lighting: 'full', bubble: null, held: null,
        box: { x: i * 440, y: 600, w: 440, h: 228 },
        elements: FURNITURE.map(([kind, fn], k) => ({
            kind, member: null, x: i * 440 + k * 10, y: 600, w: 50, h: 40,
            doc: { set, theme: 'studio', fn, input: fn === 'sideTable' ? { install_id: 'aimla', seat_id: seat, seats: 4 } : { install_id: 'aimla', seat_id: seat } },
        })),
    };
};
const sceneOf = (over) => ({ band: BAND, band_docs: bandDocs('theme:studio/band'), slab: null, planes: [planeOf('aimla', [])], scenery: [], decorative: [], lines: [], desks: [], strip: null, effects: [], doors: [], loop_fps: 4, ...over });

const legs = {};

/** One paint of `scene` through a fresh painter, its reports collected. */
function paint(scene, painter = null) {
    const reported = [];
    const p = painter ?? createPainter({ characters: null, themes: { studio: theme }, failed: (ids) => reported.push(...ids), select() {} });

    p.paint(scene, { bounds: null });

    return { reported, painter: p };
}

// ── FALLBACK ────────────────────────────────────────────────────────────────────────────────────
{
    const defects = [];
    const planes = [planeOf('hallway-floor', [], false), planeOf('aimla', [], false)];

    paint(sceneOf({ band_docs: null, planes }));

    const fills = classed('plane');

    if (fills.length !== planes.length) {
        defects.push(`a theme that cannot draw left ${planes.length - fills.length} of ${planes.length} plane(s) with no flat fallback fill — the facts' ink would stand on the drawing's dark ground`);
    }

    if (classed('wall').length !== 1) {
        defects.push('a theme that cannot draw left the band with no flat fallback fill');
    }

    if (images().length !== 0) {
        defects.push(`a theme that cannot draw still drew ${images().length} theme image(s)`);
    }

    legs.fallback = { painted: fills.length, defects };
}

// ── SET ─────────────────────────────────────────────────────────────────────────────────────────
{
    const defects = [];
    const desks = ['aimla-pm', 'aimla-impl-1', 'aimla-review'].map(deskOf);

    throwWhen = (fn, input) => fn === 'deskProps' && input.seat_id === 'aimla-impl-1';

    const { reported } = paint(sceneOf({ desks }));

    throwWhen = () => false;

    const drawn = images().filter((h) => h.includes(encodeURIComponent('"install_id":"aimla"')));

    for (const seat of ['aimla-pm', 'aimla-review']) {
        const own = drawn.filter((h) => h.includes(encodeURIComponent(`"seat_id":"${seat}"`)));

        if (own.length !== FURNITURE.length) {
            defects.push(`${seat}'s furniture set drew ${own.length} of its ${FURNITURE.length} documents, with nothing failed for it`);
        }
    }

    const lost = drawn.filter((h) => h.includes(encodeURIComponent('"seat_id":"aimla-impl-1"')));

    if (lost.length !== 0) {
        defects.push(`aimla-impl-1's set threw in one document and still drew ${lost.length} of the others — a half-drawn set`);
    }

    if (JSON.stringify(reported) !== JSON.stringify(['theme:studio/desk:aimla/aimla-impl-1'])) {
        defects.push(`the painter reported ${JSON.stringify(reported)}, not aimla-impl-1's set alone`);
    }

    legs.set = { painted: drawn.length, defects };
}

// ── BAND ────────────────────────────────────────────────────────────────────────────────────────
{
    const defects = [];

    throwWhen = (fn, input) => fn === 'windowSurround' && input.index === 1;

    const { reported } = paint(sceneOf({ planes: [] }));

    throwWhen = () => false;

    const drawn = images();

    if (drawn.length !== 0) {
        defects.push(`the band's documents threw in one window's surround and ${drawn.length} of the others were still drawn — a half-drawn band`);
    }

    if (classed('wall').length !== 1) {
        defects.push('the band drew no flat fallback fill under its failed documents');
    }

    if (JSON.stringify(reported) !== JSON.stringify(['theme:studio/band'])) {
        defects.push(`the painter reported ${JSON.stringify(reported)}, not the band's asset alone`);
    }

    // And the intact band draws all five, in their order: the wall under the glazing, each surround over its window.
    paint(sceneOf({ planes: [] }));

    const order = nodes().filter((n) => n.name === 'image' || (n.getAttribute?.('class') ?? '').startsWith('window')).map((n) => (n.name === 'image' ? decodeURIComponent(String(n.getAttribute('href'))).match(/<title>(\w+)/)?.[1] : 'glazing'));
    const want = ['band', 'glazing', 'windowSurround', 'glazing', 'windowSurround', 'elevatorSurround', 'clockCase'];

    if (JSON.stringify(order) !== JSON.stringify(want)) {
        defects.push(`the band painted ${order.join(', ')}, not ${want.join(', ')}`);
    }

    legs.band = { painted: drawn.length, defects };
}

// ── CACHE ───────────────────────────────────────────────────────────────────────────────────────
{
    const defects = [];
    const { painter } = paint(sceneOf({ band_docs: null, planes: [planeOf('aimla', [{ x: 0, y: 0, w: 8, h: 400 }])] }));
    const before = images()[0];
    const asked = calls.length;

    paint(sceneOf({ band_docs: null, planes: [planeOf('aimla', [{ x: 0, y: 0, w: 8, h: 400 }, { x: 0, y: 200, w: 400, h: 8 }])] }), painter);

    const after = images()[0];

    if (before === undefined || after === undefined) {
        defects.push('the plane was not drawn — the cache leg read nothing');
    } else if (before === after || calls.length === asked) {
        defects.push('a plane re-drawn after a map save that changed its runs under the same asset id drew the old markup — the cache is keyed on the asset id, not the inputs');
    }

    // The same inputs again draw from the cache: the theme is not asked a third time.
    const asked2 = calls.length;

    paint(sceneOf({ band_docs: null, planes: [planeOf('aimla', [{ x: 0, y: 0, w: 8, h: 400 }, { x: 0, y: 200, w: 400, h: 8 }])] }), painter);

    if (calls.length !== asked2 || images()[0] !== after) {
        defects.push('the same plane, the same inputs, was drawn anew rather than from the cache');
    }

    legs.cache = { painted: 3, defects };
}

console.log(JSON.stringify({ legs }));

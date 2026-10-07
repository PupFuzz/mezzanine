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
 * it applies one (a badge's and the flag's `text_dx`, the chip's centring, a fallen-back intern's glyph
 * in its rect, an art image at its viewport's origin) — and a node no element accounts for, or an element
 * whose node is missing, is a defect too. Each node's CLASS is held too where it carries the look: the
 * chip and its text, the badge and the flag and each one's text, the plate, and every intern node.
 * Containment is kept beside it.
 *
 * ⛔ THE INTERNS (card#11058 PR-C): each is its sprite in a clipping viewport — static, the character
 * tree's chibi frame drawn under the intern key `seat~<call_id>`, which this probe writes from the
 * published rule rather than importing it — inside a dashed edge when untitled; or, where its art
 * is reported failed, § 9 F14's glyph in its rect, that stool alone. The reorder check paints each seat
 * of two or more interns in the wire's order and reversed and reads each intern's sprite by its place;
 * the cache check paints the floor again with no intern, then again with them, and holds the painter to
 * having dropped every intern the first paint drew (the tree is asked again) and nothing else.
 *
 * ⛔ AT-D3-24's PAINTER HALF (card#11046): every character is an `<image>` whose `href` is the tree's
 * SVG document as a `data:image/svg+xml;charset=utf-8,` URI over encodeURIComponent — the rule written
 * here, not imported — `xMidYMax meet`; no canvas is made for a character; a desk of every
 * `(pose, glyph)` pair the desk model asks a character for draws that seat's standing frame (parity); a
 * held loop steps the walk's frames and leaves an intern still; no frame list is copied onto an element;
 * and AT-D3-19's two causes — a tree that failed to load, a generator that throws for one intern's key —
 * reach the painter's report, the second for that intern alone, and never throw out of the paint.
 *
 * The desks are every planted seat (`desk-leaves/fx-desk-leaves-planted.json` — the bounds, the caps,
 * the badges, the nulls) confirmed, unconfirmed and as the placeholder, each placed at its own box on a
 * row, through the real `placeDesk()` / `placeBubbles()`.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: `{ desks, painted, interns, defects: [ … ] }`
 * — `interns` counts each form painted, so a leg that painted nothing reads as such.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const JS = args.includes('--js') ? args[args.indexOf('--js') + 1] : join(HERE, '..', '..', '..', 'public', 'js');
const mod = async (p) => import(pathToFileURL(join(JS, p)).href);

// ── the fake DOM: exactly what the painter touches (`fake-svg-node.mjs`) ─────────────────────────────
const host = new Node('div');

globalThis.document = {
    activeElement: null,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
    // A canvas whose 2D context records the font each measurement was taken in (`measurer()`'s check).
    // Every canvas made is counted: the painter makes none for a character (AT-D3-24).
    createElement: () => {
        canvases++;
        const context = { font: '', measureText(text) { measured.push({ font: this.font, text }); return { width: String(text).length }; } };

        return { getContext: () => context, toDataURL: () => 'data:image/png;base64,' };
    },
};
const measured = [];
let canvases = 0;
globalThis.CSS = { escape: (s) => s };
// The held loop's timer, captured so the probe can step it.
const loops = [];
globalThis.setInterval = (fn) => { loops.push(fn); return loops.length; };
globalThis.clearInterval = () => {};

const { deskModel } = await mod('desk/desk-render.js');
const { placeDesk, placeBubbles } = await mod('floor/scene.js');
const { createPainter, measurer, STYLE } = await mod('floor/painter.js');
const { TYPE_ROLES, STOOL_GLYPH, FONT, FONT_NAME, BUBBLE_PAD } = await mod('floor/desk-layout.js');

/**
 * The character tree, stubbed: a frame is an SVG document naming the `(install, seat key, frame)` it was
 * drawn for. The EXPECTED intern key is written here from the published rule — `seat~<call_id>` (§ 10.4,
 * Q3) — and the URI from § 10.2's — never imported from the module under test, so a layout that keys
 * interns otherwise, or a painter that makes another URI, reds.
 */
const asked = { walk: [], chibi: [] };
const doc = (what) => `<svg xmlns="http://www.w3.org/2000/svg"><title>${what}</title></svg>`;
const uri = (d) => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(d)}`;
let throwFor = null;
const characters = {
    walkFrames(installId, seatId) { asked.walk.push(`${installId}/${seatId}`); return [0, 1, 2].map((p) => doc(`${installId}/${seatId}#${p}`)); },
    chibiFrame(installId, key) {
        asked.chibi.push(`${installId}/${key}`);
        if (key === throwFor) throw new Error('a planted generator defect for one key');
        return doc(`${installId}/${key}#chibi`);
    },
};
const internFrame = (installId, seatId, callId) => uri(doc(`${installId}/${seatId}~${callId}#chibi`));

/**
 * The floor's theme, stubbed (FLOOR.md § 10.6, card#11046 row 22): every document names the function and the
 * inputs it was drawn for. What each art element owes is written here from the published rule — the
 * element's kind to the API function, the desk's key `(install_id, seat_id)` and the side table's seat
 * count — never imported from the module under test.
 */
const theme = Object.fromEntries(['chair', 'desk', 'monitorFrame', 'deskProps', 'sideTable', 'band', 'windowSurround', 'elevatorSurround', 'clockCase', 'plane', 'scenery']
    .map((fn) => [fn, (input) => doc(`theme ${fn} ${JSON.stringify(input)}`)]));
const FURNITURE = { chair: 'chair', 'desk-sprite': 'desk', 'monitor-frame': 'monitorFrame', 'desk-props': 'deskProps', 'side-table': 'sideTable' };
const furnitureFrame = (seat, e) => uri(doc(`theme ${FURNITURE[e.kind]} ${JSON.stringify(e.kind === 'side-table'
    ? { install_id: seat.install_id, seat_id: seat.seat_id, seats: e.seats } : { install_id: seat.install_id, seat_id: seat.seat_id })}`));
const { deskAgeReadout } = await mod('wire/age-readout.js');
const { harnessMeasurer } = await import('../Support/harness-measurer.mjs');

const NOW_MS = Date.parse('2026-08-23T14:23:22.400Z');
const PLANTED = JSON.parse(readFileSync(join(HERE, 'desk-leaves', 'fx-desk-leaves-planted.json'), 'utf8'));
const measure = harnessMeasurer(PLANTED.measurer);
const BOX = { width: 440, height: 228 };

// ── the desks, each at its own box on one row ─────────────────────────────────────────────────────
const desks = [];
const seatOf = new Map();
const seats = PLANTED.seats.map((s) => s.seat);

/**
 * § 9 F14's per-stool fallback, painted beside the sprite: each variant reports a different set of interns'
 * art failed — none on the confirmed desk, the odd-placed ones on the unconfirmed, the even-placed ones on
 * the placeholder — so a desk of two or more interns paints both forms side by side, and every intern is
 * painted in both forms across the variants.
 */
const failing = (seat, parity) => new Set(parity === null ? [] : seat.subagents
    .filter((_, i) => i % 2 === parity)
    .map((s) => `intern:${seat.install_id}/${seat.seat_id}~${s.call_id}`));
const failedOf = new Map();

for (const seat of seats) {
    for (const v of [{ missing: false, placeholder: false, fails: null }, { missing: true, placeholder: false, fails: 1 }, { missing: false, placeholder: true, fails: 0 }]) {
        const model = deskModel(seat, deskAgeReadout(seat, NOW_MS), { missing: v.missing, derivation_stamp: '2026-08-23T14:23:14.400Z' }, {});

        if (model === null) {
            continue;
        }

        // One room id per placed desk: the bubble pass keys desks by `(install_id, seat_id)`, and the
        // variants of one seat would otherwise share a bubble.
        const room = `room-${desks.length}`;
        const key = `${room}/${seat.seat_id}`;
        const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, theme: 'studio', placeholder: v.placeholder, failed: failing(seat, v.fails) };

        seatOf.set(key, seat);
        failedOf.set(key, ctx.failed);
        desks.push(placeDesk(model, key, room, null, { x: desks.length * BOX.width, y: 0 }, ctx, { overflow: false, slot: null, object_id: null }));
    }
}

placeBubbles(desks, measure, BOX.width, desks.map((d) => d.key));

const scene = { band: null, band_docs: null, slab: null, scenery: [], planes: [], decorative: [], lines: [], desks, strip: null, effects: [] };
const painter = createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} });

painter.paint(scene, { bounds: null });

// ── read back what was painted ────────────────────────────────────────────────────────────────────
const svg = host.children[0];
const layer = svg?.children.find((n) => n.getAttribute('class') === 'desks');
const byKey = new Map(desks.map((d) => [d.key, d]));
const defects = [];
let painted = 0;
let bubbles = 0;
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

    if (n.name === 'circle') {
        const r = num(n, 'r');

        return { x: num(n, 'cx') - r, y: num(n, 'cy') - r, w: 2 * r, h: 2 * r };
    }

    if (n.name === 'path') {
        return pathExtent(String(n.getAttribute('d') ?? ''));
    }

    return null;
}

/**
 * A painted path's extent, read from its own `d` (§ 5.1's cloud, card#11468): the path is walked command by
 * command and each arc is SAMPLED along the curve SVG's own endpoint parameterisation draws — so the extent
 * is the ink's, whatever the scene meant it to be. Only what the cloud is written in is read — `M`, `A` with
 * equal radii and no rotation, `Z`; anything else is NOT MEASURED and returns a non-finite rect, which the
 * containment check reds rather than passing an ink it could not see.
 */
function pathExtent(d) {
    const tokens = d.trim().split(/[\s,]+/);
    const xs = [];
    const ys = [];
    let at = null;
    let i = 0;
    const bad = { x: NaN, y: NaN, w: NaN, h: NaN };

    while (i < tokens.length) {
        const cmd = tokens[i++];

        if (cmd === 'M') {
            at = [Number(tokens[i++]), Number(tokens[i++])];
            xs.push(at[0]);
            ys.push(at[1]);
        } else if (cmd === 'A' && at !== null) {
            const [rx, ry, rot, large, sweep, x, y] = tokens.slice(i, i + 7).map(Number);

            i += 7;

            if (rx !== ry || rot !== 0) {
                return bad;
            }

            for (const p of arcPoints(at, [x, y], rx, large === 1, sweep === 1)) {
                xs.push(p[0]);
                ys.push(p[1]);
            }

            at = [x, y];
        } else if (cmd === 'Z') {
            continue;
        } else {
            return bad;
        }
    }

    if (xs.length === 0 || ![...xs, ...ys].every(Number.isFinite)) {
        return bad;
    }

    return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) };
}

/** Points along an SVG circular arc from `p` to `q` (SVG 1.1 F.6.5's centre conversion, radius scaled up if short). */
function arcPoints(p, q, radius, large, sweep) {
    const [mx, my] = [(p[0] + q[0]) / 2, (p[1] + q[1]) / 2];
    const half = Math.hypot(q[0] - p[0], q[1] - p[1]) / 2;
    const r = Math.max(radius, half);
    const h = Math.sqrt(Math.max(0, r * r - half * half));
    const [ux, uy] = half === 0 ? [0, 0] : [(q[0] - p[0]) / (2 * half), (q[1] - p[1]) / (2 * half)];
    // The two candidate centres sit either side of the chord; F.6.5 takes the one with sign(large ≠ sweep).
    const s = large !== sweep ? 1 : -1;
    const [cx, cy] = [mx - s * h * uy, my + s * h * ux];
    const a0 = Math.atan2(p[1] - cy, p[0] - cx);
    let delta = Math.atan2(q[1] - cy, q[0] - cx) - a0;

    if (sweep && delta < 0) {
        delta += 2 * Math.PI;
    }

    if (!sweep && delta > 0) {
        delta -= 2 * Math.PI;
    }

    return Array.from({ length: 65 }, (_, k) => [cx + r * Math.cos(a0 + (delta * k) / 64), cy + r * Math.sin(a0 + (delta * k) / 64)]);
}

/**
 * What the painter owes one layout element, in order: `[node name, {x, y, w?, h?, text?}, extra check?]`.
 * The offsets are the painter's stated ones — and nothing else may move a node off its element.
 */
function owed(e, seat, failed, key) {
    const at = { x: e.x, y: e.y, w: e.w, h: e.h };

    switch (e.kind) {
        case 'character':
            // The art's clipping viewport at the element's rect, the image at the viewport's origin — and
            // the image is the seat's STANDING FRAME as an SVG-document URI, whatever the pose (AT-D3-24).
            return [['svg', at, (n) => viewport(e)(n) ?? classIs(['character'])(n.children[0])
                ?? (n.children[0].getAttribute('href') === uri(doc(`${key}#0`)) ? null
                    : `its image is «${decodeURIComponent(String(n.children[0].getAttribute("href"))).slice(60, 140)}», not the seat's standing frame as an SVG-document URI`)
                ?? (n.children[0].getAttribute('preserveAspectRatio') === 'xMidYMax meet' ? null : 'its image is not drawn xMidYMax meet')]];
        case 'chair':
        case 'desk-sprite':
        case 'monitor-frame':
        case 'desk-props':
        case 'side-table':
            // FLOOR.md § 10.6 item 2: the floor's theme's document for the element — its kind's function over
            // the desk's key (and the side table's seat count) — in a clipping viewport at the element's rect,
            // the image at the viewport's origin, drawn `xMidYMax meet`.
            return [['svg', at, (n) => viewport(e)(n) ?? classIs([e.kind])(n.children[0])
                ?? (n.children[0].getAttribute('href') === furnitureFrame(seat, e) ? null
                    : `its image is «${decodeURIComponent(String(n.children[0].getAttribute('href'))).slice(60, 160)}», not the theme's ${FURNITURE[e.kind]} for the desk`)
                ?? (n.children[0].getAttribute('preserveAspectRatio') === 'xMidYMax meet' ? null : 'its image is not drawn xMidYMax meet')]];
        case 'gauge-bar':
            return [['rect', at], ['rect', { ...at, w: e.w * (e.pct / 100) }]];
        case 'badge':
            // The id cut to the chip less its padding on both sides: inside, at the padding's offset.
            return [['rect', at, classIs(['badge'])], ['text', { x: e.x + e.text_dx, y: e.y, h: e.h, text: e.text },
                (n) => classIs(['badge-text'])(n) ?? (rectOf(n).w <= e.w - 2 * e.text_dx + EPS ? null : 'its text is wider than the chip less its padding')]];
        case 'flag':
            return [['rect', at, classIs(['flag'])], ['text', { x: e.x + e.text_dx, y: e.y, w: e.w - 2 * e.text_dx, h: e.h, text: e.text }, classIs(['t-flag'])]];
        case 'chip': {
            // The chip's look by its state (§ 5.4, AT-D3-11): an unrecognised state is the hollow red chip
            // and never a recognised member's colour; a held seat's chip is marked unconfirmed.
            const look = [e.unrecognised ? 'unrecognised' : `state-${e.render_state}`, ...(e.unconfirmed ? ['unconfirmed'] : [])];

            return [['rect', at, classIs(['chip', ...look])], ['text', { x: e.x + (e.w - e.text_w) / 2, y: e.y, w: e.text_w, text: e.text }, classIs(['t-chip', ...look])]];
        }
        case 'stool': {
            // § 9 F14: an intern whose art failed — by this probe's own report, never the layout's word
            // for it — is the glyph inside its rect, that stool alone.
            if (failed.has(`intern:${seat.install_id}/${seat.seat_id}~${e.call_id}`)) {
                const glyph = { x: e.x + (e.w - STOOL_GLYPH) / 2, y: e.y + e.h - STOOL_GLYPH, w: STOOL_GLYPH, h: STOOL_GLYPH };

                return [['rect', glyph, classIs(e.untitled ? ['stool', 'untitled'] : ['stool'])]];
            }

            // § 8 / Q3: the sprite in its clipping viewport, the tree's standing frame under `seat~<call_id>`;
            // an untitled intern inside a dashed edge.
            const look = e.untitled ? ['intern', 'untitled'] : ['intern'];
            const want = internFrame(seat.install_id, seat.seat_id, e.call_id);
            const sprite = ['svg', at, (n) => {
                const img = n.children[0];
                const shape = viewport(e)(n);

                return shape ?? classIs(['art'])(n) ?? classIs(look)(img)
                    ?? (img.getAttribute('href') === want ? null : `its sprite is the tree's «${img.getAttribute('href')}», not the intern key's «${want}»`)
                    ?? (img.getAttribute('preserveAspectRatio') === 'xMidYMax meet' ? null : 'its sprite is not drawn xMidYMax meet');
            }];

            // The untitled edge at the intern's own rect, its corners at rx 3 (the designer's ruling).
            const edge = ['rect', at, (n) => classIs(['intern-edge'])(n) ?? (n.getAttribute('rx') === '3' ? null : `its dashed edge has rx «${n.getAttribute('rx')}», not 3`)];

            return e.untitled ? [sprite, edge] : [sprite];
        }
        case 'plate':
            return [['rect', at, classIs(['plate'])]];
        case 'facts-plate':
            // § 10.6 (card#11468): the facts' one backdrop, at its rect, its corners at the layout's radius.
            return [['rect', at, (n) => classIs(['facts-plate'])(n) ?? (n.getAttribute('rx') === String(e.rx) ? null : `its corners are rx «${n.getAttribute('rx')}», not ${e.rx}`)]];
        case 'monitor':
        case 'placeholder':
        case 'lag-overlay':
            return [['rect', at]];
        default:
            return typeof e.text === 'string' ? [['text', { ...at, text: e.text }]] : [];
    }
}

/** § 10.6's art elements and the character — every other element of a desk is a fact, painted after them. */
const PAINT_ART = new Set(['chair', 'character', 'desk-sprite', 'monitor-frame', 'desk-props', 'side-table']);

/** The facts § 10.6 puts on the facts plate (card#11468), written here from the document, not imported. */
const PLATED = new Set(['label', 'currency', 'lag', 'gauge-bar', 'gauge-pct', 'badge', 'flag', 'quiet-age']);

/** A clipping viewport at the element's rect holding the one image at the viewport's own origin (§ 10.4). */
function viewport(e) {
    return (n) => {
        const img = n.children[0];

        return n.children.length === 1 && img.name === 'image' && n.getAttribute('overflow') === 'hidden'
            && n.getAttribute('viewBox') === `0 0 ${e.w} ${e.h}`
            && same(num(img, 'x'), 0) && same(num(img, 'y'), 0) && same(num(img, 'width'), e.w) && same(num(img, 'height'), e.h)
            ? null : 'its clipping viewport does not hold the one image at the viewport\'s own rect';
    };
}

/** A check that a painted node's class is exactly these tokens, in any order. */
function classIs(tokens) {
    const want = [...tokens].sort().join(' ');

    return (n) => {
        const got = classes(n).filter(Boolean).sort().join(' ');

        return got === want ? null : `its class is «${got}» and its element owes «${want}»`;
    };
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
    const paintedKind = [];

    // ── equality: each element's nodes, in the painter's order ──
    desk.elements.forEach((e, i) => {
        for (const want of owed(e, seatOf.get(key), failedOf.get(key), key)) {
            defects.push(...compare(key, i, e, nodes[next], want));
            paintedKind[next] = e.kind;
            next++;
        }
    });

    // ── FLOOR.md § 10.6's rule 2, the paint-order leg (AT-D3-25's re-laid half): every node painted for a
    // fact element comes after every node painted for an art element or the character. The art kinds are
    // written here from § 10.6, never imported from the layout they check.
    const lastArt = paintedKind.reduce((at, k, i) => (PAINT_ART.has(k) ? i : at), -1);
    const firstFact = paintedKind.findIndex((k) => k !== undefined && !PAINT_ART.has(k));

    if (lastArt >= 0 && firstFact >= 0 && firstFact < lastArt) {
        defects.push(`${key}: paint order — the fact ${paintedKind[firstFact]} is painted before the art ${paintedKind[lastArt]} (§ 10.6 rule 2)`);
    }

    // ── the plate-order leg (card#11468, § 10.6's rule 2 for the plate): the facts plate is painted before every
    // node of every fact it is drawn behind, and holds each of them — a plate painted after a fact hides it ──
    const plateAt = paintedKind.indexOf('facts-plate');

    if (plateAt >= 0) {
        const plate = rectOf(nodes[plateAt]);

        paintedKind.forEach((k, i) => {
            if (!PLATED.has(k)) {
                return;
            }

            if (i < plateAt) {
                defects.push(`${key}: plate order — the fact ${k} is painted before the facts plate, which hides it`);
            }

            const r = rectOf(nodes[i]);

            if (r !== null && !(r.x >= plate.x - EPS && r.y >= plate.y - EPS && r.x + r.w <= plate.x + plate.w + EPS && r.y + r.h <= plate.y + plate.h + EPS)) {
                defects.push(`${key}: plate order — the fact ${k} is not on the facts plate`);
            }
        });
    } else if (paintedKind.some((k) => PLATED.has(k))) {
        defects.push(`${key}: plate order — facts are painted and no facts plate is`);
    }

    if (desk.bubble !== null) {
        const bb = desk.bubble;
        const trailAt = next;
        const lines = [
            ...bb.trail.map((c) => ['circle', { x: c.x - c.r, y: c.y - c.r, w: 2 * c.r, h: 2 * c.r }, classIs(['bubble-trail'])]),
            ['path', {}, (n) => classIs(['bubble'])(n) ?? (n.getAttribute('d') === bb.cloud.d ? null : 'its outline is not the cloud the scene traced')],
            ['text', { x: bb.x + BUBBLE_PAD, y: bb.y + BUBBLE_PAD, text: bb.text }],
        ];

        if (bb.second !== null) {
            lines.push(['text', { x: bb.x + BUBBLE_PAD, y: bb.y + BUBBLE_PAD + TYPE_ROLES.fact.line, text: bb.second }]);
        }

        for (const want of lines) {
            defects.push(...compare(key, 'bubble', { kind: 'bubble' }, nodes[next], want));
            next++;
        }

        // ── the thought-bubble leg (§ 5.1, card#11468), read on what was PAINTED: at least three circles,
        // smallest first and each above the last, on the anchor's line, none meeting the character; and the
        // cloud's ink inside the bubble's own rect, which is what AT-D3-20 (b) and rule 5 read as the bubble ──
        const circles = nodes.slice(trailAt).filter((n) => n.name === 'circle').map((n) => ({ x: num(n, 'cx'), y: num(n, 'cy'), r: num(n, 'r') }));
        const head = desk.elements.find((e) => e.kind === 'character') ?? null;

        if (circles.length < 3) {
            defects.push(`${key}: the bubble's trail paints ${circles.length} circles, not at least 3`);
        }

        circles.forEach((c, i) => {
            if (i > 0 && !(c.r > circles[i - 1].r && c.y < circles[i - 1].y)) {
                defects.push(`${key}: the bubble's trail does not grow toward the cloud at circle ${i}`);
            }

            if (!same(c.x, desk.anchor_x)) {
                defects.push(`${key}: the bubble's trail circle ${i} is at x ${c.x}, off the anchor ${desk.anchor_x}`);
            }

            if (head !== null && c.x + c.r > head.x && head.x + head.w > c.x - c.r && c.y + c.r > head.y && head.y + head.h > c.y - c.r) {
                defects.push(`${key}: the bubble's trail circle ${i} meets the character's rect`);
            }
        });

        const cloud = nodes.slice(trailAt).find((n) => n.name === 'path');
        const ink = cloud === undefined ? null : rectOf(cloud);

        if (ink === null || ![ink.x, ink.y, ink.w, ink.h].every(Number.isFinite)
            || !(ink.x >= bb.x - EPS && ink.y >= bb.y - EPS && ink.x + ink.w <= bb.x + bb.w + EPS && ink.y + ink.h <= bb.y + bb.h + EPS)) {
            defects.push(`${key}: the bubble's cloud leaves the bubble's rect [${[bb.x, bb.y, bb.x + bb.w, bb.y + bb.h].map((v) => Math.round(v)).join(', ')}]`);
        }

        bubbles++;
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

// ── the interns' frames are held to what is on screen: the floor painted again with no intern on it, and
// then once more as at first, asks the tree again for every intern the first paint drew as a sprite — the
// painter dropped them — and for no seat, whose frames it keeps (an intern's key is minted per dispatch) ──
{
    const drawn = new Set(desks.flatMap((d) => d.elements
        .filter((e) => e.kind === 'stool' && !failedOf.get(d.key).has(e.asset))
        .map((e) => `${e.install_id}/${e.key}`)));

    painter.paint({ ...scene, desks: [] }, { bounds: null });
    asked.walk.length = 0;
    asked.chibi.length = 0;
    painter.paint(scene, { bounds: null });

    const got = new Set(asked.chibi);
    const missed = [...drawn].filter((k) => !got.has(k));

    if (drawn.size === 0 || missed.length > 0) {
        defects.push(`the painter kept ${missed.length} of the ${drawn.size} interns no longer drawn (e.g. ${missed.slice(0, 2).join(', ')}) — `
            + "an intern's key is minted per dispatch, so frames it keeps grow for the page's life");
    }

    if (asked.walk.length > 0) {
        defects.push(`the painter asked the tree again for ${asked.walk.length} seat(s) whose frames it held — a repaint re-draws nothing`);
    }
}

// ── the untitled intern's look, as the designer ruled it (card#11058 PR-C): the edge no fill, the stool's
// token at 1.5 wide, dashed `3 2`; the fallback glyph no fill, the stool's token, dashed `3 2` ──
{
    const rule = (selector) => {
        const m = new RegExp(`(?:^|[}\\n])${selector.replace(/\./g, '\\.')}\\{([^}]*)\\}`).exec(STYLE);

        return m === null ? null : Object.fromEntries(m[1].split(';').filter(Boolean).map((d) => d.split(':').map((x) => x.trim())));
    };
    const ruled = {
        '.intern-edge': { fill: 'none', stroke: 'var(--scene-stool)', 'stroke-width': '1.5', 'stroke-dasharray': '3 2' },
        '.stool.untitled': { fill: 'none', stroke: 'var(--scene-stool)', 'stroke-dasharray': '3 2' },
    };

    for (const [selector, want] of Object.entries(ruled)) {
        const got = rule(selector);

        for (const [prop, value] of Object.entries(want)) {
            if (got?.[prop] !== value) {
                defects.push(`STYLE: ${selector} declares ${prop} «${got?.[prop] ?? 'nothing'}», and the designer ruled «${value}»`);
            }
        }
    }
}

// What was painted for the interns, by form — so a run that never painted one of them reads as such.
const interns = { sprite: 0, glyph: 0, untitled_sprite: 0, untitled_glyph: 0, swapped: 0 };

for (const desk of desks) {
    for (const e of desk.elements.filter((x) => x.kind === 'stool')) {
        const glyph = failedOf.get(desk.key).has(e.asset);

        interns[glyph ? 'glyph' : 'sprite']++;
        interns[glyph ? 'untitled_glyph' : 'untitled_sprite'] += e.untitled ? 1 : 0;
    }
}

// ── Q3: the intern key is `call_id` — an intern keeps its sprite when `subagents[]` reorders ──────
// Each planted seat with two or more interns is painted twice, its `subagents[]` in the wire's order and
// reversed, with no art failed. The sprite at row place `i` belongs to the intern the input put there,
// so each intern's sprite is read by its PLACE — never by its href — and must be the same in both. Each
// paint is a FRESH painter — another page load, or another browser — because one painter's frame cache
// (keyed by the asset) would hand back the first paint's sprite and hide a key that is not the call's.
{
    const spritesByCall = (seat) => {
        const model = deskModel(seat, deskAgeReadout(seat, NOW_MS), { missing: false, derivation_stamp: '2026-08-23T14:23:14.400Z' }, {});
        const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, sprite: { url: 'desk.png', w: 116, h: 57 }, placeholder: false, failed: new Set() };
        const desk = placeDesk(model, `swap/${seat.seat_id}`, seat.install_id, null, { x: 0, y: 0 }, ctx, { overflow: false, slot: null, object_id: null });

        createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} }).paint({ ...scene, desks: [desk] }, { bounds: null });

        const g = host.children[0].children.find((n) => n.getAttribute('class') === 'desks').children[0];
        const sprites = g.children.filter((n) => n.name === 'svg' && classes(n.children[0]).includes('intern'))
            .sort((a, b) => num(a, 'x') - num(b, 'x'));

        return new Map(seat.subagents.slice(0, sprites.length).map((s, i) => [s.call_id, sprites[i].children[0].getAttribute('href')]));
    };

    for (const seat of seats.filter((x) => x.subagents.length >= 2)) {
        const before = spritesByCall(seat);
        const after = spritesByCall({ ...seat, subagents: [...seat.subagents].reverse() });

        for (const [call, href] of before) {
            interns.swapped++;

            if (after.get(call) !== href) {
                defects.push(`${seat.seat_id}: the intern ${call} drew «${href}» in the wire's order and «${after.get(call)}» reversed — `
                    + 'its sprite is keyed by its place in the row, not by its call (Q3)');
            }
        }
    }
}

// ── the page's own measurer: each role measured in its own font (§ 12's Nameplate type size row) ──
{
    const measure = measurer();

    for (const [role, font] of [['fact', FONT], ['name', FONT_NAME]]) {
        measured.length = 0;
        measure('aimla-pm', role);

        if (measured.length !== 1 || measured[0].font !== font) {
            defects.push(`measurer(): the ${role} role measured in ${JSON.stringify(measured.map((m) => m.font))}, not «${font}»`);
        }
    }

    let refused = false;

    try {
        measure('aimla-pm', 'headline');
    } catch {
        refused = true;
    }

    if (!refused) {
        defects.push('measurer(): a role TYPE_ROLES does not declare was measured rather than refused');
    }
}

// ── AT-D3-24, parity: a desk of every `(pose, glyph)` pair the desk model asks a character for drew that
// seat's standing frame (the equality above holds each), and every such pair was painted — the population
// read from `desk/desk-poses.js`'s DESK and, for A4's THINKING, reached through `deskModel` itself (N5) ──
const { DESK } = await mod('desk/desk-poses.js');
const poses = new Set(desks.flatMap((d) => d.elements.filter((e) => e.kind === 'character').map((e) => e.pose)));
const owedPoses = new Set([...Object.values(DESK).map((d) => d.pose).filter((p) => p !== 'empty-chair'), 'leaning-back']);

for (const p of owedPoses) {
    if (!poses.has(p)) {
        defects.push(`no desk painted a character in the pose «${p}» — the parity leg read nothing for it`);
    }
}

// ── AT-D3-24: no canvas for a character, and no frame list copied onto an element (N8) ──
{
    canvases = 0;
    createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} }).paint(scene, { bounds: null });

    if (canvases > 0) {
        defects.push(`the painter made ${canvases} canvas(es) painting characters — a character is an SVG document, never a raster a canvas made`);
    }

    const walk = (n) => [n, ...n.children.flatMap(walk)];

    for (const n of walk(host.children[0])) {
        if (n.dataset?.frames !== undefined) {
            defects.push(`a painted ${label(n)} carries its frame list on the element — frames are kilobytes, held in the painter's Map (N8)`);
            break;
        }

        const href = n.name === 'image' ? n.getAttribute('href') : null;

        if (href !== null && href.startsWith('data:') && !href.startsWith('data:image/svg+xml;charset=utf-8,')) {
            defects.push(`a painted image's href is «${href.slice(0, 40)}» — a character's is an SVG-document URI`);
            break;
        }
    }
}

// ── § 6.2's held loop steps the walk's frames on a moving character, and leaves an intern still ──
{
    const desk = desks.find((d) => d.elements.some((e) => e.kind === 'character') && d.elements.some((e) => e.kind === 'stool' && !failedOf.get(d.key).has(e.asset)));
    const moving = {
        ...desk,
        held: { ...(desk.held ?? {}), motion: true, frame_interval_ms: 250 },
        elements: desk.elements.map((e) => (e.kind === 'character' ? { ...e, animation: { motion: true, frame_interval_ms: 250 } } : e)),
    };

    loops.length = 0;
    createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} }).paint({ ...scene, desks: [moving] }, { bounds: null });

    const g = host.children[0].children.find((n) => n.getAttribute('class') === 'desks').children[0];
    const character = g.children.find((n) => n.name === 'svg' && n.children[0] && classes(n.children[0]).includes('character'))?.children[0];
    const intern = g.children.find((n) => n.name === 'svg' && n.children[0] && classes(n.children[0]).includes('intern'))?.children[0];
    const internBefore = intern?.getAttribute('href');
    const asset = moving.key;

    if (character === undefined || intern === undefined) {
        defects.push('the held-loop desk painted no character sprite or no intern sprite to step');
    } else if (loops.length !== 1) {
        defects.push(`a moving held render started ${loops.length} loop(s), not one`);
    } else {
        loops[0]();

        if (character.getAttribute('href') !== uri(doc(`${asset}#1`))) {
            defects.push('the held loop did not step the moving character to the walk\'s next frame');
        }

        if (intern.getAttribute('href') !== internBefore) {
            defects.push('the held loop stepped an intern — an intern stands still (§ 8)');
        }
    }
}

// ── AT-D3-19's two causes for a code-drawn character (§ 10.2): a tree that failed to load reports every
// character's and every intern's asset; a generator that throws for one intern's key reports that
// intern alone; neither throws out of the paint ──
{
    const reported = [];
    const owedAssets = new Set(desks.flatMap((d) => d.elements
        .filter((e) => e.kind === 'character' || (e.kind === 'stool' && e.art))
        .map((e) => e.asset)));
    let threw = null;

    try {
        createPainter({ characters: null, themes: { studio: theme }, failed: (ids) => reported.push(...ids), select() {} }).paint(scene, { bounds: null });
    } catch (e) {
        threw = e;
    }

    const missing = [...owedAssets].filter((a) => !reported.includes(a));

    if (threw !== null || owedAssets.size === 0 || missing.length > 0) {
        defects.push(`F14 (a): with the tree failed to load, the painter ${threw ? `threw (${threw.message})` : `left ${missing.length} of ${owedAssets.size} character assets unreported`}`);
    }

    const victim = desks.flatMap((d) => d.elements.filter((e) => e.kind === 'stool' && e.art)).at(0);
    const one = [];

    throwFor = victim.key;
    threw = null;

    try {
        createPainter({ characters, themes: { studio: theme }, failed: (ids) => one.push(...ids), select() {} }).paint(scene, { bounds: null });
    } catch (e) {
        threw = e;
    }

    throwFor = null;

    if (threw !== null || one.length !== 1 || one[0] !== victim.asset) {
        defects.push(`F14 (b): with the generator throwing for one intern's key, the painter ${threw ? `threw (${threw.message})` : `reported ${JSON.stringify(one.slice(0, 3))}, not that intern's asset alone`}`);
    }
}

// ── § 9 F14's third cause (card#11046, § 10.2): a frame the browser cannot decode fires the character
// <image>'s `error` event, and the painter reports that asset — the character's, and nothing else ──
{
    const reported = [];
    const desk = desks.find((d) => d.elements.some((e) => e.kind === 'character'));
    const asset = desk.elements.find((e) => e.kind === 'character').asset;

    createPainter({ characters, themes: { studio: theme }, failed: (ids) => reported.push(...ids), select() {} }).paint({ ...scene, desks: [desk] }, { bounds: null });

    const g = host.children[0].children.find((n) => n.getAttribute('class') === 'desks').children[0];
    const img = g.children.find((n) => n.name === 'svg' && n.children[0] && classes(n.children[0]).includes('character'))?.children[0];

    if (img === undefined) {
        defects.push('F14 (c): the desk painted no character image to fail');
    } else {
        img.dispatch('error');

        if (reported.length !== 1 || reported[0] !== asset) {
            defects.push(`F14 (c): an undecodable character frame's error event reported ${JSON.stringify(reported)}, not ${asset} alone`);
        }
    }
}

// ── the held loop steps only what the LAST paint drew: one painter painting twice keeps no node of the
// first paint in its loop (a list that only grows would step detached nodes for the page's life) ──
{
    const desk = desks.find((d) => d.elements.some((e) => e.kind === 'character'));
    const moving = {
        ...desk,
        held: { ...(desk.held ?? {}), motion: true, frame_interval_ms: 250 },
        elements: desk.elements.map((e) => (e.kind === 'character' ? { ...e, animation: { motion: true, frame_interval_ms: 250 } } : e)),
    };
    const twice = createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} });
    const imagesOf = () => {
        const walk = (n) => [n, ...n.children.flatMap(walk)];

        return walk(host.children[0]).filter((n) => n.name === 'image');
    };

    loops.length = 0;
    twice.paint({ ...scene, desks: [moving] }, { bounds: null });
    const stale = imagesOf();
    const before = stale.map((n) => n.getAttribute('href'));

    twice.paint({ ...scene, desks: [moving] }, { bounds: null });
    const live = imagesOf();
    const liveBefore = live.map((n) => n.getAttribute('href'));

    loops.at(-1)?.();

    if (stale.some((n, i) => n.getAttribute('href') !== before[i])) {
        defects.push('the held loop stepped a node of an earlier paint — the painter keeps every paint\'s characters in its loop');
    }

    if (!live.some((n, i) => n.getAttribute('href') !== liveBefore[i])) {
        defects.push('the held loop stepped nothing the last paint drew');
    }
}

console.log(JSON.stringify({ desks: desks.length, painted, bubbles, interns, poses: [...poses].sort(), defects }));

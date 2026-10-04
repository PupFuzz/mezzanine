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
 * tree's standing frame painted under the intern key `seat~<call_id>`, which this probe writes from the
 * published rule rather than importing it — inside a dashed edge when untitled; or, where its art
 * is reported failed, § 9 F14's glyph in its rect, that stool alone. The reorder check paints each seat
 * of two or more interns in the wire's order and reversed and reads each intern's sprite by its place;
 * the cache check paints the floor again with no intern and holds the painter to forgetting, in the tree,
 * every intern the first paint drew.
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
    // A canvas whose 2D context records the font each measurement was taken in (`measurer()`'s check),
    // and whose image names what the character tree painted on it — so a painted `href` says which key
    // the tree was handed (the intern key's check).
    createElement: () => {
        const context = { font: '', painted: '', measureText(text) { measured.push({ font: this.font, text }); return { width: String(text).length }; } };

        return { getContext: () => context, toDataURL: () => `data:,${context.painted}` };
    },
};
const measured = [];
globalThis.CSS = { escape: (s) => s };

const { deskModel } = await mod('desk/desk-render.js');
const { placeDesk, placeBubbles } = await mod('floor/scene.js');
const { createPainter, measurer, STYLE } = await mod('floor/painter.js');
const { TYPE_ROLES, STOOL_GLYPH, FONT, FONT_NAME } = await mod('floor/desk-layout.js');

/**
 * The character tree, stubbed: a frame names the `(install, seat key, phase)` it was painted for. The
 * EXPECTED intern key is written here from the published rule — `seat~<call_id>` (§ 10.4, Q3) — and
 * never imported from the module under test, so a layout that keys interns otherwise reds.
 */
const forgotten = [];
const characters = {
    paintSceneFrame(context, installId, seatId, { phase }) { context.painted = `${installId}/${seatId}#${phase}`; },
    forget(installId, seatId) { forgotten.push(`${installId}/${seatId}`); },
};
const internFrame = (installId, seatId, callId) => `data:,${installId}/${seatId}~${callId}#0`;
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
        const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, sprite: { url: 'desk.png', w: 116, h: 57 }, placeholder: v.placeholder, failed: failing(seat, v.fails) };

        seatOf.set(key, seat);
        failedOf.set(key, ctx.failed);
        desks.push(placeDesk(model, key, room, null, { x: desks.length * BOX.width, y: 0 }, ctx, { overflow: false, slot: null, object_id: null }));
    }
}

placeBubbles(desks, measure, BOX.width, desks.map((d) => d.key));

const scene = { band: null, slab: null, tiles: [], planes: [], decorative: [], lines: [], desks, strip: null, effects: [] };
const painter = createPainter({ characters, failed() {}, select() {} });

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
function owed(e, seat, failed) {
    const at = { x: e.x, y: e.y, w: e.w, h: e.h };

    switch (e.kind) {
        case 'character':
        case 'desk-sprite':
            // The art's clipping viewport at the element's rect, the image at the viewport's origin.
            return [['svg', at, viewport(e)]];
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
            const look = e.untitled ? ['intern', 'pixel', 'untitled'] : ['intern', 'pixel'];
            const want = internFrame(seat.install_id, seat.seat_id, e.call_id);
            const sprite = ['svg', at, (n) => {
                const img = n.children[0];
                const shape = viewport(e)(n);

                return shape ?? classIs(['art'])(n) ?? classIs(look)(img)
                    ?? (img.getAttribute('href') === want ? null : `its sprite is the tree's «${img.getAttribute('href')}», not the intern key's «${want}»`)
                    ?? (img.dataset.frames === undefined ? null : 'its sprite carries frames to step — an intern stands still (§ 8)');
            }];

            // The untitled edge at the intern's own rect, its corners at rx 3 (the designer's ruling).
            const edge = ['rect', at, (n) => classIs(['intern-edge'])(n) ?? (n.getAttribute('rx') === '3' ? null : `its dashed edge has rx «${n.getAttribute('rx')}», not 3`)];

            return e.untitled ? [sprite, edge] : [sprite];
        }
        case 'plate':
            return [['rect', at, classIs(['plate'])]];
        case 'chair':
        case 'monitor':
        case 'placeholder':
        case 'side-table':
        case 'lag-overlay':
            return [['rect', at]];
        default:
            return typeof e.text === 'string' ? [['text', { ...at, text: e.text }]] : [];
    }
}

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

    // ── equality: each element's nodes, in the painter's order ──
    desk.elements.forEach((e, i) => {
        for (const want of owed(e, seatOf.get(key), failedOf.get(key))) {
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

// ── the interns' caches are held to what is on screen: the floor painted again with no intern on it
// forgets every intern the first paint drew as a sprite, in the tree too (`forget()`), and nothing else ──
{
    const drawn = new Set(desks.flatMap((d) => d.elements
        .filter((e) => e.kind === 'stool' && !failedOf.get(d.key).has(e.asset))
        .map((e) => `${e.install_id}/${e.key}`)));

    forgotten.length = 0;
    painter.paint({ ...scene, desks: [] }, { bounds: null });

    const got = new Set(forgotten);
    const missed = [...drawn].filter((k) => !got.has(k));
    const extra = [...got].filter((k) => !drawn.has(k));

    if (drawn.size === 0 || missed.length > 0 || extra.length > 0 || forgotten.length !== got.size) {
        defects.push(`the painter forgot ${forgotten.length} of the ${drawn.size} interns no longer drawn `
            + `(missed ${missed.slice(0, 2).join(', ')}; extra ${extra.slice(0, 2).join(', ')}) — an intern's key is minted per dispatch, `
            + 'so a cache that keeps it grows for the page\'s life');
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

        createPainter({ characters, failed() {}, select() {} }).paint({ ...scene, desks: [desk] }, { bounds: null });

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

console.log(JSON.stringify({ desks: desks.length, painted, interns, defects }));

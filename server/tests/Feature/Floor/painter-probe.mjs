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
 * ⛔ § 7.4's FADE (card#12346, the operator's ruling of 2026-10-25): on a desk whose seat carries `fold_lag` every
 * node painted for an art element — § 10.6's art kinds and § 9 F14's placeholder, written here from the document —
 * is drawn inside ONE `g.fold-lag` group at the layout's `FOLD_LAG_OPACITY`, which is a fade (above 0, below 1;
 * `TheNewDeskKeepsEveryLeafTest` holds the figure to § 12's row); every other node of that desk, and every node of
 * a desk without the badge, is at full opacity — no `fold-lag` group on it at all. `fade.faded` / `fade.full` count
 * the desks each half read, and `fade.opacity` is the layout's figure.
 *
 * ⛔ THE INTERNS (card#11058 PR-C, card#12448): each is its sprite in a clipping viewport — the character
 * tree's `internFrames()` drawn under the intern key `seat~<call_id>`, which this probe writes from the
 * published rule rather than importing it: its laptop still where § 6.2 A23 draws no motion, and its
 * typing loop from its own offset where it does, which since card#12451 is exactly while its desk's side-table
 * rendering moves — held by the interns' own `activity_state` and moving while the desk's treatment permits it (written
 * here from that rule, never read off the model's `interns_held` or the stool's element) — inside a dashed edge when
 * untitled; or, where its art
 * is reported failed, § 9 F14's glyph in its rect, that stool alone. The reorder check paints each seat
 * of two or more interns twice, with distinct `started_at`s ascending then descending — which reverses the places a fresh
 * replace seats them at (card#12450's slots) — and reads each intern's sprite by its place;
 * the cache check paints the floor again with no intern, then again with them, and holds the painter to
 * having dropped every intern the first paint drew (the tree is asked again) and nothing else.
 *
 * ⛔ AT-D3-24's PAINTER HALF (card#11046): every character is an `<image>` whose `href` is the tree's
 * SVG document as a `data:image/svg+xml;charset=utf-8,` URI over encodeURIComponent — the rule written
 * here, not imported — `xMidYMax meet`; no canvas is made for a character; a desk of every
 * `(pose, glyph)` pair the desk model asks a character for draws frame 0 of the tree's frames for that pose over
 * its desk's top edge (§ 10.4's frame contract — the tree here a stub, so the sleeper's own drawing is
 * `sleeper-probe.mjs`'s); a held loop steps its pose's own frames, every one in order, and steps an intern
 * through its own typing loop exactly while the stool's A23 rendering moves; no frame list is copied onto an element;
 * and AT-D3-19's two causes — a tree that failed to load, a generator that throws for one intern's key —
 * reach the painter's report, the second for that intern alone, and never throw out of the paint.
 *
 * ⛔ AT-D3-24's BODY LEGS (card#12443): every desk, walker and intern is drawn from the seat object's DELIVERED
 * `character.species` — the stub names the species it was handed in every frame, and what each element is owed is
 * written from the fixture seat's `character`, never from the element under test. The planted seats deliver a species
 * that differs from their key's own draw, on purpose, so a painter that draws from the key is seen. A second paint
 * after the species moved draws the new body (the frame cache is keyed on it), and a species the tree does not hold
 * — the stub throws for it, as the tree does — is § 9 F14's placeholder for that asset alone.
 *
 * The desks are every planted seat (`desk-leaves/fx-desk-leaves-planted.json` — the bounds, the caps,
 * the badges, the nulls) confirmed, unconfirmed and as the placeholder, each placed at its own box on a
 * row, through the real `placeDesk()` / `placeBubbles()`.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: `{ desks, painted, interns, fade, defects: [ … ] }`
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
// The generator's species list and draw, read from the tree itself: what the stub admits and what a key draws.
const { SPECIES_KEYS, speciesOf } = await import(pathToFileURL(join(HERE, '..', '..', '..', '..', 'resources', 'characters', 'creatures.js')).href);
// the tree's declared crest of a seat's drawn head (card#12444), which the page hands the scene as `crest`, and what its
// bubble must leave uncovered (card#12446), handed as `headClear`
const { crestTop, headClear } = await import(pathToFileURL(join(HERE, '..', '..', '..', '..', 'resources', 'characters', 'index.js')).href);
const { placeDesk, placeBubbles } = await mod('floor/scene.js');
const { createPainter, measurer, STYLE } = await mod('floor/painter.js');
const { TYPE_ROLES, STOOL_GLYPH, FONT, FONT_NAME, BUBBLE_PAD, BUBBLE_GAP, BUBBLE_MAX_W, TRAIL_RADII, TRAIL_GAP, TRAIL_REACH, STOOL_FRAME, FRAME_UNITS_PER_PX, FOLD_LAG_OPACITY } = await mod('floor/desk-layout.js');

/**
 * The character tree, stubbed: a frame is an SVG document naming the `(install, seat key, frame)` it was
 * drawn for. The EXPECTED intern key is written here from the published rule — `seat~<call_id>` (§ 10.4,
 * Q3) — and the URI from § 10.2's — never imported from the module under test, so a layout that keys
 * interns otherwise, or a painter that makes another URI, reds.
 */
const asked = { walk: [], pose: [], chibi: [], offset: [] };
/**
 * The offset the stub's `loopOffset()` answers for every seat (§ 6.2's per-seat loop phase, card#12187): not 0, so a
 * painter that starts a moving loop at frame 0, or a still desk anywhere but frame 0, draws a frame no element owes.
 */
const STUB_OFFSET = 2;
const doc = (what) => `<svg xmlns="http://www.w3.org/2000/svg"><title>${what}</title></svg>`;
const uri = (d) => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(d)}`;
let throwFor = null;
/**
 * The tree's contract for a forced species (`recipe()`, card#12443): one it does not hold is a throw; omitted, the key's
 * own draw — which the stub names `@undefined`, so a painter that omits it draws a frame no element owes.
 */
const body = (species) => {
    if (species !== undefined && !SPECIES_KEYS.includes(species)) throw new RangeError(`no species "${species}"`);
    return String(species);
};
const characters = {
    walkFrames(installId, seatId, species) { asked.walk.push(`${installId}/${seatId}`); const sp = body(species); return [0, 1, 2].map((p) => doc(`${installId}/${seatId}@${sp}#${p}`)); },
    // A desk's frames for its pose — the tree → painter interface, `{ frames, loopFrom }` (§ 10.4's frame contract
    // item 4): the stub names the pose, the desk's top edge and the clear rects it was asked for in every frame, so a
    // painter that asks for another pose, or drops the edge or the clear rects, draws a frame no element owes. The
    // sleeper's is FIVE documents with `loopFrom` 1 — a still, then a loop of four — and every other pose's FOUR with
    // `loopFrom` 0: so a loop stepping three, a loop stepped from document 0, or an offset drawn over the list's length
    // rather than the loop's, reds the held-loop leg.
    poseFrames(installId, seatId, at) {
        asked.pose.push(`${installId}/${seatId}`);
        const sp = body(at.species);
        const loopFrom = at.pose === 'asleep' ? 1 : 0;
        return { frames: Array.from({ length: loopFrom + 4 }, (_, i) => doc(`${installId}/${seatId}@${sp}#${at.pose}@${at.deskTop}~${JSON.stringify(at.clear ?? null)}#${i}`)), loopFrom };
    },
    loopOffset(installId, seatId, length) {
        asked.offset.push([`${installId}/${seatId}`, length]);
        return STUB_OFFSET % length;
    },
    // An intern's frames (card#12448, § 10.4's frame contract item 3): FIVE documents with `loopFrom` 1 — its laptop
    // still, then its typing loop of four — so an intern drawn at another document, or stepped from the still, reds.
    // Each names the body the intern is kept off, its seat's delivered species (card#12443). The plain `chibiFrame()` —
    // an IDLE intern's drawing, no laptop (card#12451) — is stubbed beside it under its own name, so a working intern
    // drawn at it reds below.
    internFrames(installId, key, seatSpecies) {
        asked.chibi.push(`${installId}/${key}`);
        if (key === throwFor) throw new Error('a planted generator defect for one key');
        return { frames: Array.from({ length: 5 }, (_, i) => doc(`${installId}/${key}!${body(seatSpecies)}#intern#${i}`)), loopFrom: 1 };
    },
    chibiFrame(installId, key, seatSpecies) {
        asked.chibi.push(`${installId}/${key}`);
        return doc(`${installId}/${key}!${body(seatSpecies)}#chibi`);
    },
};
const internFrame = (installId, seatId, callId, seatSpecies, i = 0) => uri(doc(`${installId}/${seatId}~${callId}!${seatSpecies}#intern#${i}`));
/**
 * The document an intern is first painted at, from the published rule (§ 6.2 A23, card#12451) written out here and never
 * read off the model's own `interns_held` — reading the model's answer would hold the painter to whatever the model says,
 * so a model that typed on a stilled desk would read clean: the side table moves while an intern it draws is `working`,
 * the desk's treatment permits motion (no lag badge, no `config_invalid` note, no unrecognised value — this probe stills
 * no floor and reduces no motion) and the desk is not drawn dark. Moving, the loop's start plus the stub's offset into
 * its loop of four; else the still.
 */
const internStart = (desk) => (desk.side_table.stools.some((s) => s.activity === 'working') && desk.lag === null
    && desk.config_note === null && desk.unrecognised.length === 0 && desk.dark === null ? 1 + (STUB_OFFSET % 4) : 0);
const internForms = { typing: 0, still: 0 };

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
// The furniture box at the cap, read from its one declaration (`resources/floor/furniture-box.js`) — never a copy of it.
const { FURNITURE_BOX: BOX } = await import(pathToFileURL(join(HERE, '..', '..', '..', '..', 'resources', 'floor', 'furniture-box.js')).href);

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
// Each placed desk's model, which `internStart()` reads the treatment facts off.
const modelOf = new Map();

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
        const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, crest: crestTop, headClear, theme: 'studio', placeholder: v.placeholder, failed: failing(seat, v.fails) };

        seatOf.set(key, seat);
        modelOf.set(key, model);
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
// The A2 walker leg's readings (card#12194): whether it painted, and which frames its two images drew on each pair of ticks.
const walker_ = { painted: false, frames: null };
let painted = 0;
let bubbles = 0;
const fade = { faded: 0, full: 0, opacity: FOLD_LAG_OPACITY };
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
 * The desk's top edge across its character's rect, as a share of the rect's height — written here from the
 * published rule (§ 10.4's frame contract item 4: the edge `deskRects()` derives) over the desk's own painted
 * elements, never read off the character element under test.
 */
function deskTop(key, e) {
    const desk = byKey.get(key);
    const sprite = desk.elements.find((x) => x.kind === 'desk-sprite');

    return (sprite.y - e.y) / e.h;
}

/** Where a pose's held loop starts in the stub's frames, written from the stub's own rule above: 1 for the sleeper, else 0. */
const loopFromOf = (pose) => (pose === 'asleep' ? 1 : 0);

/**
 * The document a desk's character is first painted at: 0 — the still — when its held render is still; when it moves,
 * the loop's start plus the stub's offset into the loop of four (§ 10.4 item 4's `uris[loopFrom + offset]`).
 */
const startOf = (e) => (e.animation?.motion === true && e.animation.frame_interval_ms !== null ? loopFromOf(e.pose) + (STUB_OFFSET % 4) : 0);

/** A stub frame of a desk's character, as the painter is owed to draw it (the stub's naming, above). */
const poseFrame = (key, e, i, species = seatOf.get(key).character.species) => uri(doc(`${key}@${species}#${e.pose}@${deskTop(key, e)}~${JSON.stringify(e.clear ?? null)}#${i}`));

/**
 * The art viewport a desk's character is owed — written here from the published rule (§ 10.4 item 4, § 12's *Sleeper
 * frame*), never read off the element under test: every character's is its OVERDRAW, the character's rect widened by
 * half its width each side and raised by the thought trail's height at its normal reach (a pixel under where the bubble
 * sits on its character, card#12444).
 */
function characterPort(key, e) {
    // § 10.4's frame contract (card#12187, card#12259): every character's viewport is its overdraw — the rect widened
    // by half its width each side and raised by the trail's height, `TRAIL_RADII` and `TRAIL_GAP` — whatever its pose.
    const top = e.y - TRAIL_RADII.reduce((h, r) => h + 2 * r + TRAIL_GAP, 0);

    return { x: e.x - e.w / 2, y: top, w: 2 * e.w, h: e.y + e.h - top };
}

/** § 10.4's frame contract item 3 (card#12259): an intern's viewport is its stool's rect widened to `STOOL_FRAME`, centred. */
function internPort(e) {
    // `STOOL_FRAME` across, centred on the rect — what holds every chibi's ink; a neighbour's may overlap it (card#12444)
    return { x: e.x - (STOOL_FRAME - e.w) / 2, y: e.y, w: STOOL_FRAME, h: e.h };
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
            // the image is the tree's frame for the desk's POSE, over the desk's top edge as the element's own
            // rects place it, as an SVG-document URI (AT-D3-24; § 10.4's frame contract): frame 0 when the held
            // render is still, the seat's loop offset when it moves (§ 6.2's per-seat loop phase, card#12187).
            // A sleeper's viewport is its overdraw (§ 10.4 item 4, card#12187), and its image the still or its loop's
            // document; its pose's frames are handed its clear rects.
            return [['svg', characterPort(key, e), (n) => viewport(characterPort(key, e))(n) ?? classIs(['character'])(n.children[0])
                ?? (n.children[0].getAttribute('href') === poseFrame(key, e, startOf(e)) ? null
                    : `its image is «${decodeURIComponent(String(n.children[0].getAttribute("href"))).slice(60, 200)}», not document ${startOf(e)} of the seat's «${e.pose}» frames over its desk's edge and clear rects as an SVG-document URI`)
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

            // § 8 / Q3: the sprite in its clipping viewport — the stool's overdraw, the rect widened to the row's pitch
            // (§ 10.4's frame contract item 3, card#12259) — the tree's chibi frame under `seat~<call_id>`; an untitled
            // intern with a dashed edge at its own rect.
            const look = e.untitled ? ['intern', 'untitled'] : ['intern'];
            const start = internStart(modelOf.get(key));
            const want = internFrame(seat.install_id, seat.seat_id, e.call_id, seat.character.species, start);

            internForms[start === 0 ? 'still' : 'typing']++;
            const port = internPort(e);
            const sprite = ['svg', port, (n) => {
                const img = n.children[0];
                const shape = viewport(port)(n);

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
            return [['rect', at]];
        default:
            if (typeof e.text !== 'string') {
                return [];
            }

            // A wrapped text (the screen's, card#12446): one painted text a line, each on the role's line below the
            // last and as wide as its own measured line — never one text holding every line.
            return e.lines === undefined
                ? [['text', { ...at, text: e.text }]]
                : e.lines.map((line, i) => ['text', { x: e.x, y: e.y + i * TYPE_ROLES[e.role ?? 'fact'].line, w: line.w, h: TYPE_ROLES[e.role ?? 'fact'].line, text: line.text }]);
    }
}

/** § 10.6's art elements and the character — every other element of a desk is a fact, painted after them. */
const PAINT_ART = new Set(['chair', 'character', 'desk-sprite', 'monitor-frame', 'desk-props', 'side-table']);

/** What § 7.4's fade draws at `FOLD_LAG_OPACITY` on a `fold_lag` desk: the art kinds, or F14's placeholder for them. */
const FADED_ART = new Set([...PAINT_ART, 'placeholder']);

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
    // § 7.4's fade group is read through: its nodes are the desk's, in place, each carrying the group's opacity.
    const fadeGroups = g.children.filter((n) => n.name === 'g' && classes(n).includes('fold-lag'));
    const opacityOf = new Map(fadeGroups.flatMap((f) => f.children.map((n) => [n, Number(f.getAttribute('opacity'))])));
    const nodes = g.children.flatMap((n) => (fadeGroups.includes(n) ? n.children : [n]));
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

    // ── § 7.4's fade leg (card#12346): driven by the BADGE, as § 7.4 drives the whole treatment ──
    const lagged = (seatOf.get(key).badges ?? []).includes('fold_lag');

    if (lagged && !(FOLD_LAG_OPACITY > 0 && FOLD_LAG_OPACITY < 1)) {
        defects.push(`${key}: the fold-lag opacity is ${FOLD_LAG_OPACITY}, which is not a fade`);
    }

    nodes.forEach((n, i) => {
        const want = lagged && FADED_ART.has(paintedKind[i]) ? FOLD_LAG_OPACITY : 1;
        const got = opacityOf.get(n) ?? 1;

        if (got !== want) {
            defects.push(`${key}: § 7.4's fade — the painted ${label(n)} for ${paintedKind[i] ?? 'no element'} is at opacity ${got}, not ${want}`
                + (lagged ? ' on a fold_lag desk' : ' on a desk without fold_lag'));
        }
    });

    if (fadeGroups.length !== (lagged ? 1 : 0)) {
        defects.push(`${key}: § 7.4's fade — ${fadeGroups.length} fold-lag group(s) on a desk ${lagged ? 'with' : 'without'} fold_lag, not ${lagged ? 1 : 0}`);
    }

    fade[lagged ? 'faded' : 'full']++;

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

    // ── the interns' PAINT ORDER (card#12444): neighbouring frames may overlap, so the back row is painted before the
    // front and each row left to right, which keeps every drawing whole wherever two frames meet ──
    const stoolsPainted = desk.elements.filter((e) => e.kind === 'stool');

    stoolsPainted.forEach((s, i) => {
        const p = stoolsPainted[i - 1];

        if (p !== undefined && !(s.y > p.y + EPS || (same(s.y, p.y) && s.x > p.x + EPS))) {
            defects.push(`${key}: the interns are not painted back row first, each row left to right — intern ${s.index} after ${p.index}`);
        }
    });

    if (desk.bubble !== null) {
        const bb = desk.bubble;
        const trailAt = next;
        const lines = [
            ...bb.trail.map((c) => ['circle', { x: c.x - c.r, y: c.y - c.r, w: 2 * c.r, h: 2 * c.r }, classIs(['bubble-trail'])]),
            ['path', {}, (n) => classIs(['bubble'])(n) ?? (n.getAttribute('d') === bb.cloud.d ? null : 'its outline is not the cloud the scene traced')],
            // the title's lines, one text a line on the fact role's line (card#12446), then the source line under the last
            ...bb.lines.map((line, i) => ['text', { x: bb.x + BUBBLE_PAD, y: bb.y + BUBBLE_PAD + i * TYPE_ROLES.fact.line, text: line.text }]),
        ];

        if (bb.second !== null) {
            lines.push(['text', { x: bb.x + BUBBLE_PAD, y: bb.y + BUBBLE_PAD + bb.lines.length * TYPE_ROLES.fact.line, text: bb.second }]);
        }

        for (const want of lines) {
            defects.push(...compare(key, 'bubble', { kind: 'bubble' }, nodes[next], want));
            next++;
        }

        // ── the thought-bubble leg (§ 5.1, card#11468, card#12446), read on what was PAINTED: exactly `TRAIL_RADII`'s
        // circles (the operator's 2026-10-28 *"fewer small bubbles … each one should grow progressively in size"*), each
        // larger than the one under it and above it, on the anchor's line, none meeting the character's face (or, asleep,
        // its drawn head); and the cloud's ink inside the bubble's own rect, which is what AT-D3-20 (b) and rule 5 read
        // as the bubble ──
        const circles = nodes.slice(trailAt).filter((n) => n.name === 'circle').map((n) => ({ x: num(n, 'cx'), y: num(n, 'cy'), r: num(n, 'r') }));
        const head = desk.elements.find((e) => e.kind === 'character') ?? null;
        // the character's DRAWN head, from the tree's own declaration (card#12444's seat ruling); a species the tree does
        // not hold is drawn as the placeholder, and its trail starts at the rect's top
        const at = head === null ? null : { pose: head.pose, glyph: head.glyph, species: head.species, clear: head.clear };
        const crestY = head === null ? null : head.y + (() => {
            try {
                return crestTop(desk.install_id, desk.seat_id, at) / FRAME_UNITS_PER_PX;
            } catch {
                return 0;
            }
        })();
        // what the bubble and its trail leave uncovered (card#12446): the face, from the tree's own declaration — or,
        // with no face (asleep) or no tree answer, the drawn head
        const clear = head === null ? null : (() => {
            try {
                return headClear(desk.install_id, desk.seat_id, at);
            } catch {
                return null;
            }
        })();
        const faceY = head === null ? null : (clear?.face ?? null) === null ? crestY : head.y + clear.face / FRAME_UNITS_PER_PX;

        // three, FLOOR.md § 12's *Thought trail* row (*"always three circles"*) — written here, never read off the module
        // under test, so a layout that draws another number reds
        if (circles.length !== 3) {
            defects.push(`${key}: the bubble's trail paints ${circles.length} circles, not 3`);
        }

        circles.forEach((c, i) => {
            if (i > 0 && !(c.r > circles[i - 1].r && c.y < circles[i - 1].y)) {
                defects.push(`${key}: the bubble's trail does not grow toward the cloud at circle ${i}`);
            }

            if (!same(c.x, desk.anchor_x)) {
                defects.push(`${key}: the bubble's trail circle ${i} is at x ${c.x}, off the anchor ${desk.anchor_x}`);
            }

            if (faceY !== null && c.y + c.r > faceY - EPS) {
                defects.push(`${key}: the bubble's trail circle ${i} meets the character's ${clear?.face == null ? 'drawn head' : 'face'}`);
            }
        });

        // ── the trail REACHES the cloud and the bubble is SET ON ITS CHARACTER (card#12444, the operator's ruling of
        // 2026-10-27, *"anchor the bubble to the character. If necessary, add another bubble to extend the height"*):
        // the last circle stands no further under the cloud's rect than the widest gap the trail leaves between two of
        // its circles or over the creature; and a bubble no pass parted is set as low as the seat's face and its pose's
        // mark allow (card#12446, the operator's 2026-10-28 *"The bubble can also be lowered"*): `TRAIL_REACH` above the
        // face, `BUBBLE_GAP` above the mark — or, with no tree answer, `BUBBLE_GAP` above the character's frame — and no
        // wider than the art column, so it never reaches the facts plate ──
        if (circles.length > 0 && head !== null) {
            const frameTop = head.y - (TRAIL_REACH - TRAIL_GAP);
            const want = clear === null
                ? frameTop - BUBBLE_GAP
                : Math.min(clear.face === null ? Infinity : faceY - TRAIL_REACH, clear.mark === null ? Infinity : head.y + clear.mark / FRAME_UNITS_PER_PX - BUBBLE_GAP);
            // the trail starts at the drawn head, or, under a bubble set over the head's top, `TRAIL_REACH` under the bubble
            const startY = Math.max(crestY, want + TRAIL_REACH);
            const gaps = circles.map((c, i) => (i === 0 ? startY : circles[i - 1].y - circles[i - 1].r) - (c.y + c.r));
            const top = circles[circles.length - 1];
            const under = top.y - top.r - (bb.y + bb.h);

            // the trail STARTS at the drawn head: the first circle stands over the crest no further than the trail's
            // circles stand apart (card#12444's seat ruling — a trail from the frame's top stops short of a low head)
            if (gaps.length > 1 && gaps[0] > Math.max(...gaps.slice(1)) + EPS) {
                defects.push(`${key}: the bubble's trail starts ${gaps[0].toFixed(1)} px over the character's drawn head, its circles ${Math.max(...gaps.slice(1)).toFixed(1)} px apart — it does not reach the head`);
            }

            if (!(under >= -EPS && under <= Math.max(...gaps) + EPS)) {
                defects.push(`${key}: the bubble's trail stops ${under.toFixed(1)} px under the cloud, its circles ${Math.max(...gaps).toFixed(1)} px apart at most — it does not reach the bubble`);
            }

            const plate = desk.elements.find((e) => e.kind === 'facts-plate') ?? null;

            if (bb.w > BUBBLE_MAX_W + EPS || (plate !== null && bb.x < plate.x + plate.w && plate.x < bb.x + bb.w && bb.y < plate.y + plate.h && plate.y < bb.y + bb.h)) {
                defects.push(`${key}: the bubble (${bb.w.toFixed(1)} px wide) covers the facts plate or is wider than the art column`);
            }

            if (bb.lifted === 0) {
                if (!same(bb.y + bb.h, want)) {
                    defects.push(`${key}: the bubble's bottom is at ${(bb.y + bb.h).toFixed(1)}, not set on its character at ${want.toFixed(1)}`);
                }
            }
        }

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
    asked.pose.length = 0;
    asked.chibi.length = 0;
    painter.paint(scene, { bounds: null });

    const got = new Set(asked.chibi);
    const missed = [...drawn].filter((k) => !got.has(k));

    if (drawn.size === 0 || missed.length > 0) {
        defects.push(`the painter kept ${missed.length} of the ${drawn.size} interns no longer drawn (e.g. ${missed.slice(0, 2).join(', ')}) — `
            + "an intern's key is minted per dispatch, so frames it keeps grow for the page's life");
    }

    if (asked.walk.length + asked.pose.length > 0) {
        defects.push(`the painter asked the tree again for ${asked.walk.length + asked.pose.length} seat(s) whose frames it held — a repaint re-draws nothing`);
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
// Each planted seat with two or more interns is painted twice, with distinct `started_at`s ascending and then descending
// by array place — which reverses the places a fresh replace seats them at (card#12450: oldest at slot 0) — with no art failed. The
// sprite at slot `s` belongs to the intern the published rule seats there, so each intern's sprite is read by its
// PLACE — never by its href — and must be the same in both. Each
// paint is a FRESH painter — another page load, or another browser — because one painter's frame cache
// (keyed by the asset) would hand back the first paint's sprite and hide a key that is not the call's.
{
    const spritesByCall = (seat) => {
        const model = deskModel(seat, deskAgeReadout(seat, NOW_MS), { missing: false, derivation_stamp: '2026-08-23T14:23:14.400Z' }, {});
        const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, sprite: { url: 'desk.png', w: 116, h: 57 }, placeholder: false, failed: new Set() };
        const desk = placeDesk(model, `swap/${seat.seat_id}`, seat.install_id, null, { x: 0, y: 0 }, ctx, { overflow: false, slot: null, object_id: null });

        createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} }).paint({ ...scene, desks: [desk] }, { bounds: null });

        const g = host.children[0].children.find((n) => n.getAttribute('class') === 'desks').children[0];
        // Slot order: the front row (the larger `y`) left to right, then the row behind it — `stoolSlots()`' row-major order.
        const sprites = g.children.filter((n) => n.name === 'svg' && classes(n.children[0]).includes('intern'))
            .sort((a, b) => num(b, 'y') - num(a, 'y') || num(a, 'x') - num(b, 'x'));
        // The published rule for a fresh replace: `started_at` ascending, then `call_id` by code unit, oldest at slot 0.
        const bySlot = [...seat.subagents].sort((a, b) => (a.started_at < b.started_at ? -1 : a.started_at > b.started_at ? 1
            : a.call_id < b.call_id ? -1 : a.call_id > b.call_id ? 1 : 0));

        return new Map(bySlot.slice(0, sprites.length).map((s, i) => [s.call_id, sprites[i].children[0].getAttribute('href')]));
    };

    // Distinct starts, ascending then descending by array place: a planted seat's interns may share one `started_at`
    // (the cap plants do), and a reversal of equal starts reverses nothing — the places would not move at all.
    const at = (i) => new Date(Date.parse('2026-08-23T14:23:00.000Z') + i).toISOString();

    for (const seat of seats.filter((x) => x.subagents.length >= 2)) {
        const n = seat.subagents.length;
        const before = spritesByCall({ ...seat, subagents: seat.subagents.map((s, i) => ({ ...s, started_at: at(i) })) });
        const after = spritesByCall({ ...seat, subagents: seat.subagents.map((s, i) => ({ ...s, started_at: at(n - 1 - i) })) });

        for (const [call, href] of before) {
            interns.swapped++;

            if (after.get(call) !== href) {
                defects.push(`${seat.seat_id}: the intern ${call} drew «${href}» at its place and «${after.get(call)}» at its reversed place — `
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

// ── AT-D3-24: a desk of every `(pose, glyph)` pair the desk model asks a character for drew frame 0 of that
// pose's frames (the equality above holds each), and every such pair was painted — the population
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

// ── § 6.2's held loop steps a moving character through its own pose's held loop — every frame of it, as many as the
// tree drew, from the loop's start (`loopFrom`, § 10.4 item 4) and never another drawing's — and an intern through its
// own typing loop exactly while its stool's A23 rendering moves (card#12448). Read three times: a desk whose pose's
// loop is the whole list (`loopFrom` 0) with a typing intern beside it; the same desk with its stool's rendering still
// — the intern's motion is the stool's own (since card#12451 its interns' own `activity_state`) and never the desk's — and a sleeper, whose
// still stands ahead of its loop (`loopFrom` 1) and never steps ──
function heldLoop(desk, withIntern, internTypes = false) {
    const go = { motion: true, frame_interval_ms: 250 };
    const moving = {
        ...desk,
        held: { ...(desk.held ?? {}), ...go },
        elements: desk.elements.map((e) => (e.kind === 'character' ? { ...e, animation: go }
            : e.kind === 'stool' ? { ...e, animation: internTypes ? go : { motion: false, frame_interval_ms: null } } : e)),
    };

    loops.length = 0;
    asked.offset.length = 0;
    createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} }).paint({ ...scene, desks: [moving] }, { bounds: null });

    const g = host.children[0].children.find((n) => n.getAttribute('class') === 'desks').children[0];
    const character = g.children.find((n) => n.name === 'svg' && n.children[0] && classes(n.children[0]).includes('character'))?.children[0];
    const intern = g.children.find((n) => n.name === 'svg' && n.children[0] && classes(n.children[0]).includes('intern'))?.children[0];
    const internBefore = intern?.getAttribute('href');
    const asset = moving.key;
    const pose = moving.elements.find((e) => e.kind === 'character');
    const from = loopFromOf(pose.pose);

    if (character === undefined || (withIntern && intern === undefined)) {
        defects.push(`the held-loop desk (${pose.pose}) painted no character sprite${withIntern ? ' or no intern sprite' : ''} to step`);
    } else if (loops.length !== 1) {
        defects.push(`a moving held render (${pose.pose}) started ${loops.length} loop(s), not one`);
    } else {
        const frame = (i) => poseFrame(desk.key, pose, i);
        const seen = [];
        const internSeen = [];
        const start = character.getAttribute('href');
        // § 6.2's per-seat loop phase (card#12187): the moving loop starts at the seat's offset into the LOOP of four
        // and steps on from it — documents `from + ((tick + offset) % 4)`, never the still ahead of the loop.
        const owedSteps = [1, 2, 3, 4, 5, 6, 7, 8].map((i) => from + ((i + STUB_OFFSET) % 4));

        for (let i = 0; i < 8; i++) {
            loops[0]();
            seen.push(character.getAttribute('href'));
            internSeen.push(intern?.getAttribute('href'));
        }

        if (start !== frame(from + (STUB_OFFSET % 4))) {
            defects.push(`the moving ${pose.pose} character was first painted at «${decodeURIComponent(start).match(/#\d+<\/title>/)?.[0] ?? '?'}», not at its loop's start plus its seat's offset, document ${from + (STUB_OFFSET % 4)}`);
        }

        if (!asked.offset.some(([k, n]) => k === asset && n === 4)) {
            defects.push(`the painter never asked the tree for the moving ${pose.pose} seat's loop offset over its loop's four frames — it asked ${JSON.stringify(asked.offset)}`);
        }

        if (seen.join('|') !== owedSteps.map(frame).join('|')) {
            defects.push(`the held loop did not step the moving ${pose.pose} character through its loop's four frames in order from its offset — it drew ${seen.map((h) => decodeURIComponent(h).match(/#(\d+)<\/title>/)?.[1] ?? '?').join(', ')}, not documents ${owedSteps.join(', ')}`);
        }

        if (withIntern) {
            // the stool whose intern the leg read — the first painted, whose key its document names
            const stool = moving.elements.find((e) => e.kind === 'stool' && decodeURIComponent(internBefore).includes(`${e.install_id}/${e.key}!`));
            const iframe = (i) => internFrame(stool.install_id, ...stool.key.split('~'), stool.seat_species, i);
            const owedIntern = internTypes ? [1, 2, 3, 4, 5, 6, 7, 8].map((i) => 1 + ((i + STUB_OFFSET) % 4)) : [0, 0, 0, 0, 0, 0, 0, 0];
            const firstIntern = internTypes ? 1 + (STUB_OFFSET % 4) : 0;

            if (internBefore !== iframe(firstIntern) || internSeen.join('|') !== owedIntern.map(iframe).join('|')) {
                defects.push(`with its stool's A23 rendering ${internTypes ? 'moving' : 'still'}, the intern was painted at «${decodeURIComponent(internBefore).match(/#intern#\d+/)?.[0] ?? '?'}» and stepped through ${internSeen.map((h) => decodeURIComponent(h).match(/#intern#(\d+)/)?.[1] ?? '?').join(', ')}, not document ${firstIntern} then ${owedIntern.join(', ')}`);
            }
        }
    }
}

{
    const typist = desks.find((d) => d.elements.some((e) => e.kind === 'character' && e.pose !== 'asleep') && d.elements.some((e) => e.kind === 'stool' && !failedOf.get(d.key).has(e.asset)));
    const sleeper = desks.find((d) => d.elements.some((e) => e.kind === 'character' && e.pose === 'asleep'));

    if (typist === undefined || sleeper === undefined) {
        defects.push(`the planted desks hold no ${typist === undefined ? 'moving desk with an intern' : 'sleeper'} — the held-loop leg reads nothing`);
    } else {
        heldLoop(typist, true, true);
        heldLoop(typist, true, false);
        heldLoop(sleeper, false);
    }
}

/** Every node under `n`, `n` first. */
function walkOf(n) {
    return [n, ...n.children.flatMap(walkOf)];
}

// ── card#12194 — an A2 walker (§ 6.2's walk note items 5 and 9): its floor element is a child of the floor stack (the
// desks layer, never `effects`), its cab element a descendant of the band's cab clip group after the room and before the
// leaves; neither takes a pointer (H19); and the floor image strides only inside its walk leg — the same phase-0 frame on
// two ticks of its wait, differing frames on two ticks of its walk — while the cab image never strides (H21). The loop's
// time is `performance.now() − began`, so the probe stubs `performance.now()` and places each tick inside a leg ──
{
    const { backWall, threshold } = await mod('floor/scene.js');
    const { backWallBand } = await mod('floor/floor-layout.js');
    const band = backWall(backWallBand({ x: 0, y: 0, width: 1200 }), null);
    const T = threshold(band);
    const I = 250;
    const walker = {
        animation_id: 'A2', cause: 77, install_id: 'walk-room', seat_id: 'walker', class: 'edge', motion: true, frame_interval_ms: I,
        route: 'routed', side: 'empty', lattice_key: 'k', frames: 25, door: { start: 20, frames: 5 },
        legs: [
            { kind: 'walk', start: 0, frames: 8, points: [{ x: 300, y: 200 }, { x: 200, y: 100 }, T], length: 0 },
            { kind: 'stand', start: 8, frames: 12, at: T, why: 'wait' },
            { kind: 'stand', start: 20, frames: 2, at: T, why: 'door' },
            { kind: 'cab', start: 22, frames: 3, at: T },
        ],
        size: { w: 54, h: 96 }, frame: { w: 108, h: 117 },
        elapsed_frames: 0, elapsed_ms: 0, started_at: 5000,
        // card#12443: the walker is the desk's character, in the seat's delivered body.
        species: 'otter',
    };
    let perf = 100000;
    const realPerformance = globalThis.performance;

    Object.defineProperty(globalThis, 'performance', { value: { now: () => perf }, configurable: true });
    loops.length = 0;
    asked.walk.length = 0;
    createPainter({ characters, themes: { studio: theme }, failed() {}, select() {}, clock: { now: () => 5000 } })
        .paint({ ...scene, band, desks: desks.slice(0, 2), effects: [walker], doors: [], loop_fps: 4 }, { bounds: null });

    const drawing = host.children[0];
    const stackLayer = drawing.children.find((n) => n.getAttribute('class') === 'desks');
    const effectsLayer = drawing.children.find((n) => n.getAttribute('class') === 'effects');
    const shownOf = (n) => classes(n).includes('fx-walker-shown');
    const floorEl = stackLayer.children.find(shownOf);
    const cabEl = walkOf(drawing).find((n) => classes(n).includes('fx-walker-cab'));
    const cabGroup = walkOf(drawing).find((n) => n.getAttribute('class') === 'band-cab');
    const imageIn = (n) => (n === undefined ? undefined : walkOf(n).find((m) => m.name === 'image' && classes(m).includes('character')));
    const floorImage = imageIn(floorEl);
    const cabImage = imageIn(cabEl);
    const phase = (i) => uri(doc(`walk-room/walker@otter#${i}`));
    const frameOf = (h) => decodeURIComponent(String(h)).match(/#(\d+)<\/title>/)?.[1] ?? '?';

    walker_.painted = floorEl !== undefined;

    if (floorEl === undefined || walkOf(effectsLayer).some(shownOf)) {
        defects.push('the A2 walker is not a child of the floor stack (the desks layer) — or a walker element is in the effects layer');
    }

    if (stackLayer.children.filter((n) => classes(n).includes('desk')).length !== 2) {
        defects.push('the floor stack does not hold the two desks beside the walker');
    }

    if (cabEl === undefined || cabGroup === undefined || !walkOf(cabGroup).includes(cabEl)) {
        defects.push("the A2 walker's cab element is not inside the band's cab clip group");
    } else {
        const order = walkOf(drawing);
        const firstLeaf = order.findIndex((n) => classes(n).includes('elevator-leaf'));

        if (!(order.indexOf(cabEl) < firstLeaf)) {
            defects.push("the A2 walker's cab element is drawn after the elevator's leaves — over them");
        }
    }

    // § 1.2's pointer rule: every walker element, its placeholder and its images are under a `pointer-events:none` group.
    if (!/\.fx-walker-shown\{[^}]*pointer-events:none/.test(STYLE)) {
        defects.push('the sheet does not draw `.fx-walker-shown` with `pointer-events:none` — a walker takes the presses meant for the elevator and the desks');
    }

    if (floorImage === undefined || cabImage === undefined || loops.length !== 1) {
        defects.push(`the A2 walker painted ${floorImage === undefined ? 'no floor image' : cabImage === undefined ? 'no cab image' : `${loops.length} loop(s), not one`}`);
    } else {
        const tick = (ms) => { perf = 100000 + ms; loops[0](); return [floorImage.getAttribute('href'), cabImage.getAttribute('href')]; };
        // Inside the walk leg (0 … 8 frames), inside the wait (8 … 20), inside the cab (22 … 25).
        const walk1 = tick(2 * I + 10);
        const walk2 = tick(3 * I + 10);
        const wait1 = tick(10 * I + 10);
        const wait2 = tick(11 * I + 10);
        const cab1 = tick(22 * I + 10);
        const cab2 = tick(23 * I + 10);

        walker_.frames = { walk: [walk1[0], walk2[0]].map(frameOf), wait: [wait1[0], wait2[0]].map(frameOf), cab: [cab1[1], cab2[1]].map(frameOf) };

        if (walk1[0] === walk2[0]) {
            defects.push(`the A2 walker did not stride inside its walk leg — two ticks drew frame ${frameOf(walk1[0])} both times`);
        }

        if (wait1[0] !== phase(0) || wait2[0] !== phase(0)) {
            defects.push(`the A2 walker walked in place while it waited — it drew frames ${frameOf(wait1[0])}, ${frameOf(wait2[0])}, not its phase-0 stand frame on both ticks`);
        }

        if ([walk1, walk2, wait1, wait2, cab1, cab2].some(([, c]) => c !== phase(0))) {
            defects.push('the A2 walker\'s cab image stepped — it is never stepped, standing in the cab at its phase-0 frame');
        }
    }

    Object.defineProperty(globalThis, 'performance', { value: realPerformance, configurable: true });
}

// ── A desk's frames are cached BY POSE (§ 10.4's frame contract): one painter drawing a seat asleep, then the same seat
// in another pose, draws the second pose's frames — never the first's from a cache keyed without the pose ──
{
    const sleeping = desks.find((d) => d.elements.some((e) => e.kind === 'character' && e.pose === 'asleep'));
    const awake = {
        ...sleeping,
        elements: sleeping.elements.map((e) => {
            if (e.kind !== 'character') {
                return e;
            }

            // Every pose carries the overdraw and the clear rects since card#12259; only the pose changes.
            return { ...e, pose: 'present' };
        }),
    };
    const one = createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} });
    const characterHref = () => walkOf(host.children[0]).find((n) => n.name === 'image' && classes(n).includes('character'))?.getAttribute('href');

    one.paint({ ...scene, desks: [sleeping] }, { bounds: null });
    one.paint({ ...scene, desks: [awake] }, { bounds: null });

    const pose = awake.elements.find((e) => e.kind === 'character');

    if (characterHref() !== poseFrame(sleeping.key, pose, startOf(pose))) {
        defects.push(`a seat drawn asleep and then present by one painter drew «${decodeURIComponent(String(characterHref())).match(/#[a-z-]+@/)?.[0] ?? '?'}» frames the second time — the painter's frames are not keyed by pose`);
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

// ── AT-D3-24's body legs (card#12443): a desk is drawn in the species its seat object DELIVERS. The frame legs above
// already hold every desk's image to its fixture seat's `character.species` and every intern's to that seat's; this
// block holds the fixture to delivering species apart from its keys' own draws (or those legs could not tell the two
// apart), the painter's cache to the species, and an unheld species to § 9 F14 ──
const body_ = { apart: 0, recached: false, unheld: false };
{
    const same = seats.filter((s) => s.character.species === speciesOf(`${s.install_id}/${s.seat_id}`));

    body_.apart = seats.length - same.length;

    if (seats.length === 0 || same.length > 0) {
        defects.push(`body: ${same.length} planted seat(s) deliver their key's own draw (e.g. ${same.slice(0, 2).map((s) => s.seat_id).join(', ')}) — a painter drawing from the key would pass the frame legs over them`);
    }

    const desk = desks.find((d) => d.elements.some((e) => e.kind === 'character'));
    const element = desk.elements.find((e) => e.kind === 'character');
    const moved = SPECIES_KEYS.find((sp) => sp !== element.species);
    const reborn = { ...desk, elements: desk.elements.map((e) => (e.kind === 'character' ? { ...e, species: moved } : e)) };
    const one = createPainter({ characters, themes: { studio: theme }, failed() {}, select() {} });
    const characterHref = () => walkOf(host.children[0]).find((n) => n.name === 'image' && classes(n).includes('character'))?.getAttribute('href');

    one.paint({ ...scene, desks: [desk] }, { bounds: null });
    one.paint({ ...scene, desks: [reborn] }, { bounds: null });
    body_.recached = characterHref() === poseFrame(desk.key, element, startOf(element), moved);

    if (!body_.recached) {
        defects.push(`body: after ${desk.key}'s delivered species moved to ${moved}, one painter still drew «${decodeURIComponent(String(characterHref())).match(/@[a-z-]+#/)?.[0] ?? '?'}» — its frames are not cached by species`);
    }

    const reported = [];
    const unheld = { ...desk, elements: desk.elements.map((e) => (e.kind === 'character' ? { ...e, species: 'no-such-species' } : e)) };

    let threw = null;

    try {
        createPainter({ characters, themes: { studio: theme }, failed: (ids) => reported.push(...ids), select() {} }).paint({ ...scene, desks: [unheld] }, { bounds: null });
    } catch (e) {
        threw = e;
    }

    body_.unheld = threw === null && characterHref() === undefined && reported.length === 1 && reported[0] === element.asset;

    if (!body_.unheld) {
        defects.push(`body: a delivered species the tree does not hold ${threw ? `threw out of the paint (${threw.message})` : `drew ${characterHref() === undefined ? 'no image' : 'an image'} and reported ${JSON.stringify(reported)}`} — § 9 F14 is that asset's placeholder alone, never another body`);
    }
}

console.log(JSON.stringify({ desks: desks.length, painted, bubbles, interns, intern_forms: internForms, fade, poses: [...poses].sort(), walker: walker_, body: body_, defects }));

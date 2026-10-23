/**
 * card#12159, card#12187 — AN IDLE DESK DRAWS ITS SLEEPER (FLOOR.md § 6.2 A6, § 7.1's `idle` row, § 10.4's frame contract
 * item 4): the probe `AnIdleDeskDrawsItsSleeperTest` reads, under `node` over the painter's fake DOM, with the REAL
 * character tree — never a stub — so what it reads is the drawing the page shows.
 *
 * ⛔ IT READS WHAT WAS DRAWN, NOT WHAT THE MODEL SAYS. The model named `idle` → pose `asleep` since the desk render
 * landed, and the floor drew an upright, open-eyed character stepping the walk's frames side to side the whole time
 * (card#12159). So every leg below reads the painted `<image>`'s `href`, decodes the SVG document behind it, and reads the
 * sleeper's parts off that document by their `data-*` marks. Every figure it holds a drawing to is read from FLOOR.md,
 * never from the tree or the layout under test.
 *
 * The legs, each named in its defect line:
 *   model     — the idle desk's model: pose `asleep`, monitor `off`, held A6 with motion (and without, reduced).
 *   sleeper   — the painted character is the tree's sleeper over its desk's own top edge: `data-pose="asleep"`,
 *               closed eyes, the head resting at the edge the desk's rects place; its document drawn in § 12's
 *               *Sleeper frame*.
 *   monitor   — the painted monitor is off (card#12187): the unlit screen, carrying no text.
 *   zs        — in every document, THREE z's (card#12187's final rulings), z1 to z3, each its share of § 12's `L` tall
 *               (*Sleeper's z's*: z1 0.915 `L`, z2 0.75, z3 0.585), every body inside the frame, every z on the side the
 *               head rolled AWAY from.
 *   order     — "up" held by POSITION: z1 the lowest and each next z higher (y strictly decreasing with k), so a tree
 *               that numbers its z's from the top, running the pulse down, reds.
 *   pulse     — document 0, the still, every z at level 3; documents 1.. the loop's levels, § 12's *Sleeper z pulse*
 *               table, `SLEEP_LOOP` of them.
 *   halo      — every lit z (level 1 or more) has its halo, in the stylesheet's `--lamp`, and none at level 0; all halos
 *               drawn first, behind every z's body.
 *   still     — the sleeper and its z's stand still: the sleeper is one drawing in every document, the z's are in the
 *               same places in every document, and only their brightness changes.
 *   crest     — `CREST_TOP` declared for every species and variant the tree draws, and equal to the table § 10.4
 *               records from `tools/design/sleeper-zs.browser.mjs`'s scan (the browser tool holds the raster; this leg
 *               holds the tree to what it measured, so a crest top moved off the drawing's reds without a browser).
 *   overdraw  — the painter's art viewport for the sleeper is the overdraw § 10.4 states (108 × 117 px at box
 *               (24, 37)), the element's clear rects are the four rects § 10.4 states, and the painted document is the
 *               tree's for those clear rects.
 *   loop      — the held loop steps ONLY the sleeper's own loop, from its start (`loopFrom` 1): the still is never
 *               a loop frame, and after `SLEEP_LOOP` steps the loop is back where it began.
 *   reduced   — § 6.4: with reduced motion no loop runs and the painted document is the still, document 0.
 *   stagger   — § 6.2's per-seat loop phase (card#12187): for two idle seats whose loop offsets differ, each moving loop
 *               starts at document 1 + its offset over the LOOP's length, and each still is document 0. The offset is
 *               read by the published rule, `draw(seatKey, 'loop-phase') % length` (`seed.js`), never by the tree's own
 *               function.
 *   chair     — AT-D3-5's comparison, on the drawing: the `stale` desk painted beside it draws no character at
 *               all, where the `idle` desk draws its sleeper (§ 7.5).
 *   species   — every species and variant of the tree draws a sleeper meeting the sleeper, zs, order, pulse, halo,
 *               still and crest legs over its documents, each a well-formed, self-contained SVG document.
 *
 * argv: `--js <tree>` (default: the shipped client), `--tree <dir>` (default: the shipped `resources/characters`).
 * stdout: `{ counts: {...}, defects: [ … ] }` — `counts` says how much each leg read, so a leg that read nothing
 * reads as such.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..', '..', '..');
const args = process.argv.slice(2);
const arg = (name, fallback) => (args.includes(name) ? args[args.indexOf(name) + 1] : fallback);
const JS = arg('--js', join(REPO, 'server', 'public', 'js'));
const TREE = arg('--tree', join(REPO, 'resources', 'characters'));
const mod = async (p) => import(pathToFileURL(join(JS, p)).href);

// ── the fake DOM ──────────────────────────────────────────────────────────────────────────────────
const host = new Node('div');

globalThis.document = {
    activeElement: null,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
    createElement: () => ({ getContext: () => ({ font: '', measureText: (t) => ({ width: String(t).length }) }) }),
};
globalThis.CSS = { escape: (s) => s };
const loops = [];
globalThis.setInterval = (fn) => { loops.push(fn); return loops.length; };
globalThis.clearInterval = () => {};

const C = await import(pathToFileURL(join(TREE, 'index.js')).href);
// A tree with no sleeper is this probe's first finding, not a crash: every leg still reads what the page draws.
const SLEEPS = typeof C.sleepDocument === 'function';
const { draw, seatKey } = await import(pathToFileURL(join(TREE, 'seed.js')).href);
/** § 6.2's per-seat loop phase, by the published rule (FLOOR.md § 10.4 item 4). */
const offsetOf = (installId, seatId, length) => draw(seatKey(installId, seatId), 'loop-phase') % length;

// ── the figures, read from the document ────────────────────────────────────────────────────────────
const MD = readFileSync(join(REPO, 'docs', 'design', 'FLOOR.md'), 'utf8');
const num = (s) => Number(String(s).replace('−', '-'));
const read = (re, what) => {
    const m = MD.match(re);

    if (m === null) {
        throw new Error(`FLOOR.md carries no ${what} in the form this probe reads`);
    }

    return m;
};
/** § 12's *Sleeper's z's*: the seat's `L`, and each z's share of it, z1 first. */
const L = num(read(/sized \*\*`L` ([\d.]+)\*\* at every seat/, "§ 12 *Sleeper's z's* `L`")[1]);
const SHARES = read(/z1 \*\*([\d.]+)\*\* `L`, z2 \*\*([\d.]+)\*\* `L` and z3 \*\*([\d.]+)\*\* `L`/, "§ 12 *Sleeper's z's* shares").slice(1).map(num);
/** § 12's *Sleeper z pulse*: the levels of z1 z2 z3 by loop frame. */
const PULSE = read(/^\| \*\*Sleeper z pulse\*\* \|.*?the levels by loop frame are `([0-3 ]+)`/m, '§ 12 *Sleeper z pulse* table')[1]
    .split(' ').map((t) => [...t].map(Number));
const LOOP = PULSE.length;
/** § 12's *Sleeper frame*. */
const FRAME = (() => {
    const m = read(/^\| \*\*Sleeper frame\*\* \| \*\*(\d+) × (\d+)\*\* frame units from \(\*\*(-?\d+)\*\*, \*\*(-?\d+)\*\*\)/m, '§ 12 *Sleeper frame* row');

    return { x: num(m[3]), y: num(m[4]), w: num(m[1]), h: num(m[2]) };
})();
/** § 10.4 item 4's overdraw: its size and its place on the furniture box, in px. */
const OVERDRAW = (() => {
    const m = read(/(\d+) × (\d+) px, at box\s+\((\d+), (\d+)\)/, '§ 10.4 item 4 overdraw rect');

    return { x: num(m[3]), y: num(m[4]), w: num(m[1]), h: num(m[2]) };
})();
/** § 10.4 item 4's clear rects, in frame units: x and y ranges (the desk's by its y range alone, as the document states it). */
const CLEAR = (() => {
    const m = read(/the monitor's frame \(x ([−\d-]+)–([−\d-]+), y ([−\d-]+)–([−\d-]+) units\), the desk's props \(x ([−\d-]+)–([−\d-]+), y ([−\d-]+)–([−\d-]+)\),\s+the desk\s+\(y ([−\d-]+)–([−\d-]+)\) and the thought trail's column \(x ([−\d-]+)–([−\d-]+), y ([−\d-]+)–([−\d-]+)\)/, '§ 10.4 item 4 clear rects');
    const v = m.slice(1).map(num);

    return { monitor: { x: [v[0], v[1]], y: [v[2], v[3]] }, props: { x: [v[4], v[5]], y: [v[6], v[7]] }, desk: { y: [v[8], v[9]] }, trail: { x: [v[10], v[11]], y: [v[12], v[13]] } };
})();
/** § 10.4 item 4's crest table, as the measuring tool scanned it. */
const CRESTS = Object.fromEntries([...read(/as the tool scans it over 100 keys each \(1 unit a raster pixel\):([\s\S]*?)— and the tool holds/, '§ 10.4 item 4 crest table')[1]
    .matchAll(/`([a-z/]+)` (\d+)/g)].map((m) => [m[1], Number(m[2])]));
/** The palette's lamp colour, which every halo is drawn in. */
const LAMP = readFileSync(join(REPO, 'server', 'public', 'css', 'mezzanine.css'), 'utf8').match(/--lamp:\s*(#[0-9a-fA-F]{6})/)?.[1] ?? null;

const { wellFormed, vectorDefect } = await import(pathToFileURL(join(REPO, 'tools', 'design', 'svg-document.mjs')).href);
const { deskModel } = await mod('desk/desk-render.js');
const { placeDesk } = await mod('floor/scene.js');
const { createPainter } = await mod('floor/painter.js');
const { deskAgeReadout } = await mod('wire/age-readout.js');
const { harnessMeasurer } = await import('../Support/harness-measurer.mjs');

const PLANTED = JSON.parse(readFileSync(join(HERE, 'desk-leaves', 'fx-desk-leaves-planted.json'), 'utf8'));
const measure = harnessMeasurer(PLANTED.measurer);
const NOW_MS = Date.parse('2026-08-23T14:23:22.400Z');
const BOX = { width: 440, height: 228 };
const seatNamed = (id) => PLANTED.seats.map((s) => s.seat).find((s) => s.seat_id === id);
const defects = [];
const counts = { frames: 0, zs: 0, species: 0, steps: 0 };

// ── the drawing, read ─────────────────────────────────────────────────────────────────────────────
const decode = (href) => decodeURIComponent(String(href).replace(/^data:image\/svg\+xml;charset=utf-8,/, ''));
const attr = (doc, name) => {
    const m = doc.match(new RegExp(`${name}="([^"]*)"`));

    return m === null ? null : m[1];
};
// Each z with its outline's stroke width, read off its first path — so its box is the drawing's, never a constant here.
const zsOf = (doc) => [...doc.matchAll(/<g data-part="z" data-k="(\d+)" data-level="(\d)" data-x="([\d.-]+)" data-y="([\d.-]+)" data-h="([\d.-]+)"><path [^>]*stroke-width="([\d.]+)"/g)]
    .map((m) => ({ k: +m[1], level: +m[2], x: +m[3], y: +m[4], h: +m[5], hw: +m[5] * 0.4 + +m[6] / 2, hh: +m[5] / 2 + +m[6] / 2 }));
const halosOf = (doc) => [...doc.matchAll(/<path data-part="halo" data-k="(\d+)" data-level="(\d)" d="[^"]*" stroke="([^"]+)"/g)]
    .map((m) => ({ k: +m[1], level: +m[2], colour: m[3], at: m.index }));
/** The side the head rolled to: the sign of its rotation (1 its right, -1 its left). */
const rollOf = (doc) => Math.sign(Number((doc.match(/<g data-part="head" transform="[^"]*rotate\((-?[\d.]+)\)/) ?? [])[1])) || 1;
const withoutZs = (doc) => doc.replace(/<g data-part="zs">[\s\S]*<\/g><\/svg>$/, '</svg>');
const VIEW = C.SEAT_VIEWBOX;

/**
 * The sleeper's own legs over ONE drawing's documents — `sleeper`, `zs`, `order`, `pulse`, `halo`, `still`, `crest` —
 * each defect prefixed with `where`. `edge` is the desk's top edge in the frame's units, as the caller derived it.
 */
function sleeperLegs(where, docs, edge, crestKey) {
    const out = [];

    if (docs.length !== 1 + LOOP) {
        out.push(`pulse: ${where} draws ${docs.length} documents, not the still and § 12's ${LOOP} loop frames`);
    }

    docs.forEach((doc, i) => {
        counts.frames++;
        const at = `${where} document ${i}`;
        const bad = wellFormed(doc) ?? vectorDefect(doc);

        if (bad !== null) {
            out.push(`${at}: not a well-formed, self-contained SVG document — ${bad}`);
        }

        if (attr(doc, 'data-pose') !== 'asleep') {
            out.push(`sleeper: ${at} is not the sleeper — it carries no data-pose="asleep" (an upright creature is drawn)`);

            return;
        }

        if (!doc.includes('data-part="eyes-closed"')) {
            out.push(`sleeper: ${at} draws no closed eyes`);
        }

        if (attr(doc, 'viewBox') !== `${FRAME.x} ${FRAME.y} ${FRAME.w} ${FRAME.h}`) {
            out.push(`sleeper: ${at} is drawn in viewBox «${attr(doc, 'viewBox')}», not § 12's *Sleeper frame* ${FRAME.w} × ${FRAME.h} from (${FRAME.x}, ${FRAME.y})`);
        }

        if (Math.abs(Number(attr(doc, 'data-edge')) - edge) > 0.01) {
            out.push(`sleeper: ${at} rests on an edge at ${attr(doc, 'data-edge')}, not its desk's top edge at ${edge.toFixed(2)}`);
        }

        const head = doc.match(/<g data-part="head" transform="translate\(([\d.-]+) ([\d.-]+)\)/);

        if (head === null || !(Number(head[2]) <= edge && Number(head[2]) >= edge - 12)) {
            out.push(`sleeper: ${at}'s head does not rest at its desk's edge (chin at ${head?.[2]}, edge ${edge.toFixed(2)})`);
        }

        const zs = zsOf(doc);

        counts.zs += zs.length;

        if (zs.length !== 3 || zs.some((z, k) => z.k !== k)) {
            out.push(`zs: ${at} draws ${zs.length} z's (${zs.map((z) => `z${z.k + 1}`).join(', ')}), not § 10.4's three, z1 to z3`);

            return;
        }

        const away = -rollOf(doc);

        for (const z of zs) {
            if (Math.abs(z.h - SHARES[z.k] * L) > 0.02) {
                out.push(`zs: ${at}'s z${z.k + 1} is ${z.h} units tall, not ${SHARES[z.k]} of § 12's L ${L} (${(SHARES[z.k] * L).toFixed(2)})`);
            }

            if (z.y - z.hh < FRAME.y - 0.01 || z.y + z.hh > FRAME.y + FRAME.h + 0.01 || z.x - z.hw < FRAME.x - 0.01 || z.x + z.hw > FRAME.x + FRAME.w + 0.01) {
                out.push(`zs: ${at} draws z${z.k + 1}'s body (centre ${z.x}, ${z.y}) out of the sleeper's frame`);
            }

            if (Math.sign(z.x - 54) !== away) {
                out.push(`zs: ${at} draws z${z.k + 1} at x ${z.x}, on the side its head rolled to — § 10.4: the z's stand AWAY from the roll`);
            }
        }

        for (let k = 1; k < zs.length; k++) {
            if (!(zs[k].y < zs[k - 1].y)) {
                out.push(`order: ${at}'s z${k + 1} (y ${zs[k].y}) is not above z${k} (y ${zs[k - 1].y}) — the pulse would not travel up`);
            }
        }

        const want = i === 0 ? [3, 3, 3] : PULSE[i - 1] ?? null;

        if (want === null || zs.map((z) => z.level).join('') !== want.join('')) {
            out.push(`pulse: ${at}'s levels are ${zs.map((z) => z.level).join('')}, not ${i === 0 ? 'the still\'s 333' : want === null ? 'any loop frame of the table' : `loop frame ${i - 1}'s ${want.join('')}`}`);
        }

        const halos = halosOf(doc);
        const firstZ = doc.indexOf('<g data-part="z"');

        for (const z of zs) {
            const h = halos.filter((x) => x.k === z.k);

            if (z.level === 0 ? h.length !== 0 : (h.length !== 1 || h[0].level !== z.level || h[0].colour !== LAMP)) {
                out.push(`halo: ${at}'s z${z.k + 1} at level ${z.level} has ${h.length === 0 ? 'no halo' : `halos ${h.map((x) => `${x.level} ${x.colour}`).join(', ')}`} — a lit z has one at its level in --lamp ${LAMP}, a dim one none`);
            }
        }

        if (halos.some((h) => h.at > firstZ)) {
            out.push(`halo: ${at} draws a halo after a z's body — every halo stands behind every z`);
        }
    });

    // still: the sleeper is one drawing in every document; its z's stand in the same places in every one.
    const bodies = new Set(docs.map(withoutZs));

    if (bodies.size !== 1) {
        out.push(`still: ${where}'s sleeper is ${bodies.size} different drawings across its ${docs.length} documents — it moves (sways or walks) where only its z's brightness may`);
    }

    const places = new Set(docs.map((d) => zsOf(d).map((z) => `${z.x},${z.y},${z.h}`).join('|')));

    if (places.size !== 1) {
        out.push(`still: ${where}'s z's move between documents (${places.size} different stands) — they stand fixed, lit in turn`);
    }

    // crest: the tree's table declares this seat's crest, as the measuring tool recorded it.
    const declared = (C.CREST_TOP ?? {})[crestKey];

    if (declared === undefined || declared !== CRESTS[crestKey]) {
        out.push(`crest: ${where}'s CREST_TOP «${crestKey}» is ${declared ?? 'not declared'}, not § 10.4's measured ${CRESTS[crestKey] ?? 'nothing'}`);
    }

    return out;
}

// ── the desks: an idle seat, moving and reduced, and the stale seat beside it ──────────────────────
const idle = { ...seatNamed('plant-idle'), badges: [] };
const stale = seatNamed('plant-stale');

if (idle.render_state !== 'idle' || stale.render_state !== 'stale') {
    defects.push(`the planted fixture no longer holds an idle and a stale seat (${idle.render_state}, ${stale.render_state}) — the probe reads nothing`);
}

const ctx = { box: BOX, measure, character: { w: 18, h: 32 }, theme: 'studio', placeholder: false, failed: new Set() };
const desk = (seat, reduce, i) => placeDesk(deskModel(seat, deskAgeReadout(seat, NOW_MS), {}, { reduce }), `room-${i}/${seat.seat_id}`, `room-${i}`, null,
    { x: i * BOX.width, y: i * 30 }, ctx, { overflow: false, slot: null, object_id: null });

// A theme whose every furniture document is an empty drawing: this probe reads the character and the monitor.
const blank = () => '<svg xmlns="http://www.w3.org/2000/svg"/>';
const theme = new Proxy({}, { get: () => blank });
const failed = [];

function paint(desks) {
    loops.length = 0;
    host.replaceChildren();
    createPainter({ characters: C, themes: { studio: theme }, failed: (ids) => failed.push(...ids), select() {} })
        .paint({ band: null, band_docs: null, slab: null, scenery: [], planes: [], decorative: [], lines: [], desks, strip: null, effects: [] }, { bounds: null });

    return host.children[0].children.find((n) => n.getAttribute('class') === 'desks').children;
}

const portOf = (g) => g.children.find((n) => n.name === 'svg' && (n.children[0]?.getAttribute('class') ?? '').split(' ').includes('character'));
const characterOf = (g) => portOf(g)?.children[0];
const characterElement = (d) => d.elements.find((e) => e.kind === 'character');
const edgeOf = (d) => {
    const ch = characterElement(d);
    const top = d.elements.find((e) => e.kind === 'desk-sprite');

    // The published rule (§ 10.4's frame contract item 4): the desk's top edge across the character's rect, in the
    // character frame's units — the frame is `VIEW.h` units to the rect's height.
    return ch === undefined || top === undefined ? NaN : ((top.y - ch.y) / ch.h) * VIEW.h;
};
/**
 * The tree's own documents for a placed desk, over the clear rects its character element carries — none, and a
 * `sleeper` defect, where the tree refuses them (a desk not laid out as a sleeper hands it no clear rects).
 */
const ownFrames = (placed) => {
    if (!SLEEPS) {
        return { frames: [], loopFrom: 1 };
    }

    try {
        return C.sleepFrames(placed.install_id, placed.seat_id, edgeOf(placed) / VIEW.h, characterElement(placed)?.clear);
    } catch (e) {
        defects.push(`sleeper: the tree draws no sleeper for ${placed.key}'s character element — ${e.message}`);

        return { frames: [], loopFrom: 1 };
    }
};

// model
{
    const m = deskModel(idle, deskAgeReadout(idle, NOW_MS), {}, {});
    const r = deskModel(idle, deskAgeReadout(idle, NOW_MS), {}, { reduce: true });

    if (m.pose !== 'asleep' || m.monitor.lit !== 'off' || m.held?.animation_id !== 'A6' || m.held?.motion !== true) {
        defects.push(`model: the idle desk's model is pose ${m.pose}, monitor ${m.monitor.lit}, held ${m.held?.animation_id} motion ${m.held?.motion} — not § 7.1's asleep, off, A6 moving`);
    }

    if (r.held?.motion !== false) {
        defects.push('model: the idle desk under reduced motion still holds a moving A6');
    }
}

// sleeper, monitor, overdraw, zs …, loop — the moving idle desk, painted at a box off the floor's origin, so an
// overdraw that does not move with its desk reds
{
    const placed = desk(idle, false, 1);
    const [g] = paint([placed]);
    const image = g === undefined ? undefined : characterOf(g);
    const edge = edgeOf(placed);

    if (image === undefined) {
        defects.push(`sleeper: the idle desk painted no character at all (reported: ${failed.join(', ') || 'nothing'}) — § 7.5: a sleeper is a character`);
    } else {
        const monitor = g.children.find((n) => n.name === 'rect' && (n.getAttribute('class') ?? '').split(' ').includes('monitor'));

        if (!(monitor?.getAttribute('class') ?? '').split(' ').includes('lit-off')) {
            defects.push(`monitor: the idle desk's monitor is painted «${monitor?.getAttribute('class')}», not the screen off`);
        }

        const screenText = g.children.filter((n) => n.name === 'text' && (n.getAttribute('class') ?? '').split(' ').includes('t-monitor-text'));

        if (screenText.length > 0) {
            defects.push(`monitor: the idle desk's screen, off, carries text («${screenText.map((n) => n.textContent).join(' ')}»)`);
        }

        // overdraw: the art viewport is § 10.4's overdraw on the desk's box, and the element's clear rects are § 10.4's.
        const port = portOf(g);
        const got = ['x', 'y', 'width', 'height'].map((k) => Number(port.getAttribute(k)));
        const want = [placed.box.x + OVERDRAW.x, placed.box.y + OVERDRAW.y, OVERDRAW.w, OVERDRAW.h];

        if (got.join() !== want.join()) {
            defects.push(`overdraw: the sleeper's art viewport is at [${got.join(', ')}], not § 10.4's overdraw [${want.join(', ')}] on its desk's box`);
        }

        const clear = characterElement(placed)?.clear ?? [];
        const byName = Object.fromEntries(clear.map((c) => [c.name, c]));
        const off = Object.entries(CLEAR).filter(([name, r]) => {
            const c = byName[name];

            return c === undefined || (r.x !== undefined && (c.x !== r.x[0] || c.x + c.w !== r.x[1])) || c.y !== r.y[0] || c.y + c.h !== r.y[1];
        }).map(([name]) => name);

        if (clear.length !== 4 || off.length > 0) {
            defects.push(`overdraw: the sleeper's clear rects are ${JSON.stringify(clear)} — not § 10.4's four (${off.join(', ') || 'a fifth'} differ)`);
        }

        const own = ownFrames(placed);
        const loop = own.frames.length - 1;
        const offset = offsetOf(placed.install_id, placed.seat_id, LOOP);
        const first = image.getAttribute('href');
        const stepped = [];

        if (SLEEPS && decode(first) !== own.frames[1 + offset]) {
            const at = own.frames.indexOf(decode(first));

            defects.push(`stagger: the moving idle desk was first painted at ${at < 0 ? 'a document the tree does not draw for its clear rects' : `document ${at}`}, not its loop's start plus its seat's offset, document ${1 + offset}`);
        }

        if (loops.length !== 1) {
            defects.push(`loop: the moving idle desk started ${loops.length} loop(s), not its one held loop`);
        } else {
            for (let i = 0; i < 2 * LOOP; i++) {
                loops[0]();
                stepped.push(decode(image.getAttribute('href')));
                counts.steps++;
            }
        }

        if (SLEEPS && stepped.includes(own.frames[0])) {
            defects.push(`loop: the moving idle desk's loop steps the still (document 0) as one of its frames — the loop is documents 1 to ${loop}`);
        }

        if (stepped.length > 0 && stepped[LOOP - 1] !== decode(first)) {
            defects.push(`loop: the idle desk's loop does not come back to its first document after its ${LOOP} frames — it steps a loop of another length`);
        }

        if (stepped.some((d) => attr(d, 'data-pose') !== 'asleep')) {
            defects.push('loop: the held loop stepped the idle desk into a document that is not the sleeper (the walk\'s side-to-side steps)');
        }

        // The painted loop in loop-frame order — tick j shows loop frame (j + 1 + offset) mod LOOP — after the still the
        // reduced leg reads painted, judged as the sleeper's own documents.
        if (stepped.length === 2 * LOOP) {
            const loopDocs = Array.from({ length: LOOP }, (_, f) => stepped[(((f - 1 - offset) % LOOP) + LOOP) % LOOP]);

            defects.push(...sleeperLegs('the idle desk', [own.frames[0] ?? '', ...loopDocs], edge, crestOf(placed)));
        }
    }
}

function crestOf(placed) {
    const r = C.recipe(seatKey(placed.install_id, placed.seat_id));

    return r.variant === null ? r.species : `${r.species}/${r.variant}`;
}

// reduced — the idle desk under § 6.4's reduced motion
{
    const placed = desk(idle, true, 0);
    const [g] = paint([placed]);
    const image = g === undefined ? undefined : characterOf(g);

    if (loops.length !== 0) {
        defects.push(`reduced: the idle desk under reduced motion started ${loops.length} loop(s) — its z's are drawn once and still`);
    }

    if (image === undefined) {
        defects.push('reduced: the idle desk under reduced motion painted no character');
    } else {
        const doc = decode(image.getAttribute('href'));

        if (attr(doc, 'data-pose') !== 'asleep' || zsOf(doc).length !== 3 || zsOf(doc).some((z) => z.level !== 3)) {
            defects.push(`reduced: the idle desk's static document is not the slumped sleeper with its three z's at full brightness (pose ${attr(doc, 'data-pose')}, levels ${zsOf(doc).map((z) => z.level).join('')})`);
        }

        if (SLEEPS && doc !== ownFrames(placed).frames[0]) {
            defects.push('reduced: the idle desk\'s static document is not the sleeper\'s still, document 0');
        }
    }
}

// stagger — § 6.2's per-seat loop phase over two idle seats whose offsets differ
if (SLEEPS) {
    const ids = [];

    for (let i = 0; ids.length < 2 && i < 64; i++) {
        const id = i === 0 ? idle.seat_id : `${idle.seat_id}-${i}`;

        if (ids.every((o) => offsetOf(idle.install_id, o, LOOP) !== offsetOf(idle.install_id, id, LOOP))) {
            ids.push(id);
        }
    }

    const readSeat = (seatId, reduce) => {
        const placed = desk({ ...idle, seat_id: seatId }, reduce, 0);
        const [g] = paint([placed]);
        const image = g === undefined ? undefined : characterOf(g);

        return { placed, href: image === undefined ? null : decode(image.getAttribute('href')), frames: ownFrames(placed).frames };
    };
    const seats = ids.map((id) => ({ id, still: readSeat(id, true), moving: readSeat(id, false) }));

    counts.stagger = seats.length;

    if (seats.length < 2) {
        defects.push('stagger: no two seat ids with different loop offsets were found — the leg read nothing');
    } else {
        const firsts = seats.map((s) => s.moving.frames.indexOf(s.moving.href));

        for (const [i, s] of seats.entries()) {
            if (s.still.href !== s.still.frames[0]) {
                defects.push(`stagger: ${s.id} under reduced motion is not painted at the still, document 0`);
            }

            const owed = 1 + offsetOf(s.moving.placed.install_id, s.moving.placed.seat_id, LOOP);

            if (firsts[i] !== owed) {
                defects.push(`stagger: ${s.id}'s moving loop starts at document ${firsts[i]}, not its loop's start plus its offset over the loop's ${LOOP} frames, document ${owed}`);
            }
        }

        if (firsts[0] === firsts[1]) {
            defects.push(`stagger: two seats whose offsets differ both start their moving loop at document ${firsts[0]} — they step in lockstep`);
        }
    }
}

// chair — AT-D3-5's comparison on the drawing: the idle desk's sleeper beside the stale desk's empty chair
{
    const gs = paint([desk(idle, false, 0), desk(stale, false, 1)]);
    const sleeper = characterOf(gs[0]);
    const chair = characterOf(gs[1]);

    if (sleeper === undefined) {
        defects.push('chair: the idle desk beside the stale one painted no sleeper');
    }

    if (chair !== undefined) {
        defects.push(`chair: the stale desk painted a character («${attr(decode(chair.getAttribute('href')), 'data-pose') ?? 'upright'}») — § 7.5: a dark desk is the empty chair, never a sleeper`);
    }
}

// species — every species and variant the tree draws, over the floor's own desk edge and clear rects
if (!SLEEPS) {
    defects.push('species: the character tree draws no sleeper (no sleepDocument) — no species can be drawn asleep');
} else {
    const placed = desk(idle, false, 0);
    const edgeShare = edgeOf(placed) / VIEW.h;
    const clear = characterElement(placed)?.clear;
    const forms = [];

    for (const sp of C.SPECIES_KEYS) {
        const seen = new Map();

        for (let i = 0; i < 400 && seen.size < 3; i++) {
            const key = `sleeper/${sp}/${i}`;
            const v = C.recipe(key, sp).variant ?? '-';

            if (!seen.has(v)) {
                seen.set(v, key);
            }
        }

        forms.push(...[...seen.entries()].map(([v, key]) => [sp, v, key]));
    }

    for (const [sp, v, key] of forms) {
        counts.species++;
        let docs = [];

        try {
            docs = Array.from({ length: 1 + LOOP }, (_, frame) => C.sleepDocument(key, { species: sp, frame, deskTop: edgeShare, clear }));
        } catch (e) {
            defects.push(`species: ${sp}${v === '-' ? '' : ` (${v})`} draws no sleeper — ${e.message}`);
            continue;
        }

        defects.push(...sleeperLegs(`${sp}${v === '-' ? '' : ` (${v})`}`, docs, edgeShare * VIEW.h, v === '-' ? sp : `${sp}/${v}`));

        if (docs[0] === C.frameDocument(key, { species: sp })) {
            defects.push(`sleeper: ${sp}'s sleeper is its standing frame`);
        }
    }
}

console.log(JSON.stringify({ counts, defects }));

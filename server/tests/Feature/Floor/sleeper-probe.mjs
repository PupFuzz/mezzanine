/**
 * card#12159 — AN IDLE DESK DRAWS ITS SLEEPER (FLOOR.md § 6.2 A6, § 7.1's `idle` row, § 10.4's frame contract
 * item 4): the probe `AnIdleDeskDrawsItsSleeperTest` reads, under `node` over the painter's fake DOM, with the
 * REAL character tree — never a stub — so what it reads is the drawing the page shows.
 *
 * ⛔ IT READS WHAT WAS DRAWN, NOT WHAT THE MODEL SAYS. The model has named `idle` → pose `asleep`, monitor
 * `dimmed` since the desk render landed, and the floor drew an upright, open-eyed character stepping the walk's
 * frames side to side the whole time (card#12159). So every leg below reads the painted `<image>`'s `href`,
 * decodes the SVG document behind it, and reads the sleeper's parts off that document by their `data-*` marks.
 *
 * The legs, each named in its defect line:
 *   model     — the idle desk's model: pose `asleep`, monitor `dimmed`, held A6 with motion (and without, reduced).
 *   sleeper   — the painted character is the tree's sleeper over its desk's own top edge: `data-pose="asleep"`,
 *               closed eyes, the head resting at the edge the desk's rects place.
 *   monitor   — the painted monitor is the dimmed one.
 *   zs        — in every frame, at least three z's, every one above the closed eyes and inside the frame, the
 *               largest lowest and each higher one smaller ("ZZzzz"), the highest fading.
 *   rise      — frame to frame every z rises: the loop advances each z up its path, and the frame after the last
 *               is frame 0 again.
 *   still     — the held loop steps ONLY the sleeper's own frames, and the sleeper itself is the same drawing in
 *               every one (no sway, no walk); only the z's change.
 *   reduced   — § 6.4: with reduced motion no loop runs and the static frame is frame 0 — the slumped sleeper,
 *               its z's drawn once.
 *   chair     — AT-D3-5's comparison, on the drawing: the `stale` desk painted beside it draws no character at
 *               all, where the `idle` desk draws its sleeper (§ 7.5).
 *   species   — every species and variant of the tree draws a sleeper meeting the sleeper, zs, rise and still
 *               legs over its frames, each frame a well-formed, self-contained SVG document.
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
const FRAMES = SLEEPS ? C.SLEEP_FRAMES : 4;
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
const zsOf = (doc) => [...doc.matchAll(/<g data-part="z" data-t="([\d.-]+)" data-x="([\d.-]+)" data-y="([\d.-]+)" data-h="([\d.-]+)" opacity="([\d.-]+)">/g)]
    .map((m) => ({ t: +m[1], x: +m[2], y: +m[3], h: +m[4], opacity: +m[5] }));
const withoutZs = (doc) => doc.replace(/<g data-part="zs">[\s\S]*<\/g><\/svg>$/, '</svg>');
const VIEW = C.SEAT_VIEWBOX;

/**
 * The sleeper's own legs over ONE drawing's frames — `sleeper`, `zs`, `rise`, `still` — each defect prefixed
 * with `where`. `edge` is the desk's top edge in the frame's units, as the caller derived it.
 */
function sleeperLegs(where, docs, edge) {
    const out = [];

    docs.forEach((doc, i) => {
        counts.frames++;
        const at = `${where} frame ${i}`;
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

        if (Math.abs(Number(attr(doc, 'data-edge')) - edge) > 0.01) {
            out.push(`sleeper: ${at} rests on an edge at ${attr(doc, 'data-edge')}, not its desk's top edge at ${edge.toFixed(2)}`);
        }

        const head = doc.match(/<g data-part="head" transform="translate\(([\d.-]+) ([\d.-]+)\)/);

        if (head === null || !(Number(head[2]) <= edge && Number(head[2]) >= edge - 12)) {
            out.push(`sleeper: ${at}'s head does not rest at its desk's edge (chin at ${head?.[2]}, edge ${edge.toFixed(2)})`);
        }

        const eyes = Number(attr(doc, 'data-eyes-y'));
        const zs = zsOf(doc).sort((a, b) => b.y - a.y);

        counts.zs += zs.length;

        if (zs.length < 3) {
            out.push(`zs: ${at} draws ${zs.length} z's, not the rising ZZzzz of three or more`);

            return;
        }

        for (const z of zs) {
            if (!(z.y + z.h / 2 < eyes)) {
                out.push(`zs: ${at} draws a z (bottom ${(z.y + z.h / 2).toFixed(1)}) that is not above the sleeper's closed eyes (${eyes})`);
            }

            if (z.y - z.h / 2 < VIEW.y || z.x - z.h * 0.4 < VIEW.x || z.x + z.h * 0.4 > VIEW.x + VIEW.w) {
                out.push(`zs: ${at} draws a z at (${z.x}, ${z.y}) that leaves the character's frame`);
            }
        }

        for (let k = 1; k < zs.length; k++) {
            if (!(zs[k].h < zs[k - 1].h)) {
                out.push(`zs: ${at}'s z's do not shrink as they rise — the z at ${zs[k].y} is ${zs[k].h} tall, the one below it ${zs[k - 1].h}`);
            }
        }

        const highest = zs[zs.length - 1];
        const fullest = Math.max(...zs.map((z) => z.opacity));

        if (!(highest.opacity < fullest)) {
            out.push(`zs: ${at}'s highest z does not fade (opacity ${highest.opacity}, the frame's fullest ${fullest})`);
        }
    });

    // rise: every z moves up its path frame to frame, along one path on which a later z is higher and smaller.
    const path = docs.flatMap((d) => zsOf(d)).sort((a, b) => a.t - b.t);

    for (let k = 1; k < path.length; k++) {
        if (path[k].t > path[k - 1].t && !(path[k].y < path[k - 1].y && path[k].h < path[k - 1].h)) {
            out.push(`rise: ${where}'s z's do not rise and shrink along their path — at t ${path[k].t} a z is at ${path[k].y}, ${path[k].h} tall, after ${path[k - 1].y}, ${path[k - 1].h}`);
            break;
        }
    }

    const ts = docs.map((d) => zsOf(d).map((z) => z.t).sort((a, b) => a - b));

    for (let i = 0; i < docs.length; i++) {
        const next = ts[(i + 1) % docs.length];
        const step = ((next[0] - ts[i][0]) % (1 / ts[i].length) + 1 / ts[i].length) % (1 / ts[i].length);

        if (!(step > 0.001)) {
            out.push(`rise: ${where}'s z's do not move from frame ${i} to frame ${(i + 1) % docs.length} — the loop draws them still`);
            break;
        }
    }

    // still: the sleeper is one drawing in every frame; only the z's change, and they do change.
    const bodies = new Set(docs.map(withoutZs));

    if (bodies.size !== 1) {
        out.push(`still: ${where}'s sleeper is ${bodies.size} different drawings across its ${docs.length} frames — it moves (sways or walks) where only its z's may`);
    }

    if (new Set(docs).size !== docs.length) {
        out.push(`still: ${where}'s ${docs.length} frames are not ${docs.length} different drawings — its z's do not drift`);
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
    { x: i * BOX.width, y: 0 }, ctx, { overflow: false, slot: null, object_id: null });

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

const characterOf = (g) => g.children.find((n) => n.name === 'svg' && (n.children[0]?.getAttribute('class') ?? '').split(' ').includes('character'))?.children[0];
const edgeOf = (d) => {
    const ch = d.elements.find((e) => e.kind === 'character');
    const top = d.elements.find((e) => e.kind === 'desk-sprite');

    // The published rule (§ 10.4's frame contract item 4): the desk's top edge across the character's rect, in the
    // frame's units — the frame is drawn `xMidYMax meet` into the rect, `VIEW.h` units to its height.
    return ch === undefined || top === undefined ? NaN : ((top.y - ch.y) / ch.h) * VIEW.h;
};

// model
{
    const m = deskModel(idle, deskAgeReadout(idle, NOW_MS), {}, {});
    const r = deskModel(idle, deskAgeReadout(idle, NOW_MS), {}, { reduce: true });

    if (m.pose !== 'asleep' || m.monitor.lit !== 'dimmed' || m.held?.animation_id !== 'A6' || m.held?.motion !== true) {
        defects.push(`model: the idle desk's model is pose ${m.pose}, monitor ${m.monitor.lit}, held ${m.held?.animation_id} motion ${m.held?.motion} — not § 7.1's asleep, dimmed, A6 moving`);
    }

    if (r.held?.motion !== false) {
        defects.push('model: the idle desk under reduced motion still holds a moving A6');
    }
}

// sleeper, monitor, zs, rise, still — the moving idle desk, painted
{
    const placed = desk(idle, false, 0);
    const [g] = paint([placed]);
    const image = g === undefined ? undefined : characterOf(g);
    const edge = edgeOf(placed);

    if (image === undefined) {
        defects.push(`sleeper: the idle desk painted no character at all (reported: ${failed.join(', ') || 'nothing'}) — § 7.5: a sleeper is a character`);
    } else {
        const monitor = g.children.find((n) => n.name === 'rect' && (n.getAttribute('class') ?? '').split(' ').includes('monitor'));

        if (!(monitor?.getAttribute('class') ?? '').split(' ').includes('lit-dimmed')) {
            defects.push(`monitor: the idle desk's monitor is painted «${monitor?.getAttribute('class')}», not the dimmed screen`);
        }

        const first = image.getAttribute('href');
        const stepped = [];

        if (loops.length !== 1) {
            defects.push(`still: the moving idle desk started ${loops.length} loop(s), not its one held loop`);
        } else {
            for (let i = 0; i < 2 * FRAMES; i++) {
                loops[0]();
                stepped.push(image.getAttribute('href'));
                counts.steps++;
            }
        }

        const docs = [first, ...stepped.slice(0, FRAMES - 1)].map(decode);

        defects.push(...sleeperLegs('the idle desk', docs, edge));

        if (stepped.length > 0 && stepped[FRAMES - 1] !== first) {
            defects.push(`rise: the idle desk's loop does not come back to its first frame after its ${FRAMES} frames — it steps a loop of another length`);
        }

        if (stepped.some((h) => attr(decode(h), 'data-pose') !== 'asleep')) {
            defects.push('still: the held loop stepped the idle desk into a frame that is not the sleeper (the walk\'s side-to-side steps)');
        }
    }
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

        if (attr(doc, 'data-pose') !== 'asleep' || zsOf(doc).length < 3) {
            defects.push(`reduced: the idle desk's static frame is not the slumped sleeper with its z's drawn (pose ${attr(doc, 'data-pose')}, ${zsOf(doc).length} z's)`);
        }

        // The desk's key as the floor placed it — the room is the install (`placeDesk()`).
        const zero = SLEEPS ? C.sleepFrames(placed.install_id, placed.seat_id, edgeOf(placed) / VIEW.h)[0] : null;

        if (doc !== zero) {
            defects.push('reduced: the idle desk\'s static frame is not the sleeper\'s frame 0');
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

// species — every species and variant the tree draws, over the floor's own desk edge
if (!SLEEPS) {
    defects.push('species: the character tree draws no sleeper (no sleepDocument) — no species can be drawn asleep');
} else {
    const edgeShare = edgeOf(desk(idle, false, 0)) / VIEW.h;
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
        const docs = Array.from({ length: FRAMES }, (_, frame) => C.sleepDocument(key, { species: sp, frame, deskTop: edgeShare }));

        defects.push(...sleeperLegs(`${sp}${v === '-' ? '' : ` (${v})`}`, docs, edgeShare * VIEW.h));

        if (docs[0] === C.frameDocument(key, { species: sp })) {
            defects.push(`sleeper: ${sp}'s sleeper is its standing frame`);
        }
    }
}

console.log(JSON.stringify({ counts, defects }));

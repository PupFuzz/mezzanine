/**
 * The probe `Tests\Feature\Building\TheBuildingViewStacksTheFloorsTest` reads `building/stack-model.js` through — plan
 * § 8 item 3's legs for the storey, the focus pose, the paths, the floor entries' corner, the snap table, the cull and
 * the rest. argv[2] is the module directory (the shipped `public/js/building` or a MUTATED COPY of it); stdin carries
 * `{ "cull": { "extent", "labels", "glyph_w" } }` — one floor's scene extent and desk labels from the harness's
 * `building_stack` run, and the label font's glyph width — and stdout is `{ "legs": { "<leg>": [defect, …] }, "printed":
 * { … } }`: each leg a list of defects, empty when it holds, and the figures the plan says are printed, not asserted.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NONE OF ITS DECISIONS. What it computes is the OBSERVATION — a sight
 * line, a projected box, a sampled path — over the module's own functions, so a planted defect in the module moves the
 * observation and the leg reds.
 *
 * ⛔ A PRECONDITION A LEG NEEDS IS DERIVED HERE AND FAILS AS A CONTROL WHEN IT IS ABSENT (plan § 12 item 21): the
 * centre ray that meets no slab, the point that names another floor from a focus pose, the stage on which the cull culls.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node stack-model-probe.mjs <building-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const M = await import(pathToFileURL(join(dir, 'stack-model.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');

/** The shipped default's floor extent — `resources/floor/default.tmj` drawn with its band and slab (plan § 1). */
const DEFAULT = { x: 0, y: -160, w: 1784, h: 864 };
const UNEQUAL = [{ x: 0, y: -160, w: 1784, h: 864 }, { x: 0, y: -160, w: 2400, h: 1100 }, { x: 0, y: -160, w: 1200, h: 600 }, { x: 0, y: -160, w: 1952, h: 976 }];

const stage = (width, height) => ({ width, height });
/** The five stages § 3.3's paths are measured on. */
const PATH_STAGES = [stage(1280, 600), stage(1280, 800), stage(1920, 1000), stage(1366, 500), stage(1600, 420)];
/**
 * The stages § 3.3's legibility predicate holds on for the shipped default's footprint (asserted there), and those it
 * fails on (printed). The bound is the footprint's as well as the stage's — a wider floor is framed from farther — so a
 * fixture of other footprints prints its scale on every stage, and the cull's leg derives its own.
 */
const LEGIBLE = [stage(1280, 600), stage(1280, 800), stage(1920, 1000), stage(1366, 500), stage(1100, 700)];
const ILLEGIBLE = [stage(1024, 700), stage(1024, 600), stage(960, 700), stage(1200, 450), stage(1600, 420), stage(768, 950), stage(390, 700)];
const BOX_W = 440;
const AZIMUTHS = Array.from({ length: 24 }, (_, i) => (i * 15 * Math.PI) / 180);
const SAMPLES = 64;

/** A stack of `extents`, the first the top storey, as `stackModel()` builds it from directory rows. */
function stackOf(extents, surface) {
    const rows = extents.map((_, level) => ({ floor: `f${level}`, name: `floor ${level}`, href: `/floor/f${level}`, level, held: 99, staffing: 'x' }));

    return M.stackModel(rows, new Map(rows.map((row, i) => [row.floor, extents[i]])), '/', surface);
}

const FIXTURES = {
    'one floor': [DEFAULT],
    'two floors': [DEFAULT, DEFAULT],
    'five floors': Array(5).fill(DEFAULT),
    'ten floors': Array(10).fill(DEFAULT),
    'unequal footprints': UNEQUAL,
};

const name = (s) => `${s.width}×${s.height}`;
const boxesOf = (stack) => [stack.ground, ...stack.slabs].map((s) => s.box);
const topOf = (slab) => slab.box.y + slab.box.h;
const legs = {};
const printed = {};
const leg = (key) => (legs[key] ??= []);

// ── the storey converges and clears ───────────────────────────────────────────────────────────

for (const [fixture, extents] of Object.entries(FIXTURES)) {
    for (const surface of PATH_STAGES) {
        const stack = stackOf(extents, surface);
        const footprints = [...stack.slabs].sort((a, b) => a.storey - b.storey).map((s) => ({ w: s.box.w, d: s.box.d }));
        const need = M.storeyNeed(footprints, stack.storey, surface);

        if (!stack.settled) {
            leg('storey').push(`${fixture} on ${name(surface)}: the storey did not settle within the bound`);
        }

        if (!(stack.storey > need)) {
            leg('storey').push(`${fixture} on ${name(surface)}: s = ${stack.storey} is not above g(s) = ${need}`);
        }

        const camera = M.overviewPose(stack, surface, 0);
        const byStorey = [stack.ground, ...[...stack.slabs].sort((a, b) => a.storey - b.storey)];

        for (let k = 0; k < byStorey.length - 1; k++) {
            const below = byStorey[k];
            const { x, z, w } = below.box;

            for (const point of [{ x, y: topOf(below), z }, { x: x + w / 2, y: topOf(below), z }, { x: x + w, y: topOf(below), z }]) {
                if (!M.segmentClears(camera.position, point, [byStorey[k + 1].box])) {
                    leg('storey').push(`${fixture} on ${name(surface)}: the home camera's ray to ${k === 0 ? 'the ground' : `storey ${k - 1}`}'s back edge crosses the slab above`);
                }
            }
        }
    }
}

// ── the focus pose's four properties, on every floor of every fixture ─────────────────────────

const poseCache = new Map();
const focus = (stack, i, surface, key) => {
    const id = `${key}|${i}|${name(surface)}`;

    if (!poseCache.has(id)) {
        poseCache.set(id, M.focusPose(stack, i, surface));
    }

    return poseCache.get(id);
};

const insideBox = (p, b) => p.x > b.x && p.x < b.x + b.w && p.y > b.y && p.y < b.y + b.h && p.z > b.z && p.z < b.z + b.d;
printed.illegible = {};

for (const [fixture, extents] of Object.entries(FIXTURES)) {
    for (const surface of [...LEGIBLE, ...ILLEGIBLE]) {
        const stack = stackOf(extents, surface);
        const front = stack.bounds.z + stack.bounds.d;

        stack.slabs.forEach((slab, i) => {
            const camera = focus(stack, i, surface, fixture);
            const at = `${fixture}, ${slab.floor}, on ${name(surface)}`;
            const { x, z, w, d } = slab.box;
            const y = topOf(slab);
            const corners = [{ x, y, z }, { x: x + w, y, z }, { x, y, z: z + d }, { x: x + w, y, z: z + d }];

            if (boxesOf(stack).some((b) => insideBox(camera.position, b)) || !(camera.position.z > front)) {
                leg('focus').push(`${at}: (1) the camera is inside a slab or not in front of the stack`);
            }

            const others = boxesOf(stack).filter((b) => b !== slab.box);

            for (const point of [...corners, { x: x + w / 2, y, z }]) {
                if (!M.segmentClears(camera.position, point, others)) {
                    leg('focus').push(`${at}: (2) a sight line to the face crosses another slab`);
                }
            }

            for (const c of corners) {
                const p = M.projectPoint(camera, surface, c);

                if (p.x < M.LABEL_MARGIN.x - 1e-6 || p.x > surface.width - M.LABEL_MARGIN.x + 1e-6
                    || p.y < M.LABEL_MARGIN.y - 1e-6 || p.y > surface.height - M.LABEL_MARGIN.y + 1e-6) {
                    leg('focus').push(`${at}: (3) a corner projects outside the stage less the label margin`);
                }
            }

            // An edge desk's label, its anchor at each corner of the drawing, at the widest a label can be.
            const edgeLabels = [{ x: slab.extent.x, y: slab.extent.y }, { x: slab.extent.x + slab.extent.w, y: slab.extent.y + slab.extent.h }]
                .map((at2, n) => ({ key: `edge${n}`, at: at2 }));

            for (const [key, box] of M.labelBoxes(edgeLabels, slab, camera, surface, () => M.LABEL_MAX)) {
                if (box.x < -1e-6 || box.x + box.w > surface.width + 1e-6 || box.y < -1e-6 || box.y + box.h > surface.height + 1e-6) {
                    leg('focus').push(`${at}: an edge desk's label box (${key}) crosses the stage's edge`);
                }
            }

            const legible = M.legibility(camera, slab, surface, BOX_W);

            if (fixture === 'unequal footprints') {
                (printed.unequal ??= {})[`${slab.floor} on ${name(surface)}`] = Number(legible.scale.toFixed(3));
            } else if (LEGIBLE.includes(surface)) {
                if (!legible.holds) {
                    leg('focus').push(`${at}: (4) the farthest corner's scale ${legible.scale.toFixed(3)} is under the label bound ${legible.bound.toFixed(3)}`);
                }
            } else if (fixture === 'five floors' && i === 0) {
                printed.illegible[name(surface)] = Number(legible.scale.toFixed(3));
            }
        });
    }
}

// A stage too small for two label margins still frames every floor — the inset falls to a quarter of the stage — and a
// stage with no size (collapsed or hidden: the page reads `clientWidth` 0) builds no stack, which frames nothing.
for (const surface of [stage(120, 600), stage(1280, 30), stage(100, 80), stage(0, 500), stage(0, 0), stage(800, 0)]) {
    const stack = stackOf(Array(5).fill(DEFAULT), surface);

    if (surface.width <= 0 || surface.height <= 0) {
        if (stack !== null) {
            leg('focus').push(`the unsized ${name(surface)} stage built a stack (storey ${stack.storey}) — it frames nothing`);
        }

        continue;
    }

    // The control reads the stage against the margins, not `focusInset()` — the function this case is about.
    if (surface.width > 2 * M.LABEL_MARGIN.x && surface.height > 2 * M.LABEL_MARGIN.y) {
        leg('focus').push(`CONTROL — ${name(surface)} holds two label margins each way, so it tests nothing`);
    }

    const inset = M.focusInset(surface);

    stack.slabs.forEach((slab, i) => {
        let camera;

        try {
            camera = M.focusPose(stack, i, surface);
        } catch (error) {
            leg('focus').push(`the small ${name(surface)}: ${error.message}`);

            return;
        }

        const { x, z, w, d } = slab.box;
        const y = topOf(slab);

        for (const c of [{ x, y, z }, { x: x + w, y, z: z + d }]) {
            const p = M.projectPoint(camera, surface, c);

            if (!(p.depth > 0) || p.x < inset.x - 1e-6 || p.x > surface.width - inset.x + 1e-6 || p.y < inset.y - 1e-6 || p.y > surface.height - inset.y + 1e-6) {
                leg('focus').push(`${slab.floor} on the small ${name(surface)}: a corner projects outside the stage less its inset`);
            }
        }
    });
}

// ── the paths clear ───────────────────────────────────────────────────────────────────────────

let clearance = Infinity;
const distanceToBounds = (p, b) => Math.hypot(
    Math.max(b.x - p.x, 0, p.x - (b.x + b.w)), Math.max(b.y - p.y, 0, p.y - (b.y + b.h)), Math.max(b.z - p.z, 0, p.z - (b.z + b.d)));

for (const [fixture, extents] of Object.entries(FIXTURES)) {
    for (const surface of PATH_STAGES) {
        const stack = stackOf(extents, surface);
        const boxes = boxesOf(stack);
        const overview = (azimuth) => ({ view: { pose: 'overview', azimuth }, camera: M.overviewPose(stack, surface, azimuth) });
        const posed = stack.slabs.map((slab, i) => ({ view: { pose: { floor: slab.floor }, azimuth: 0 }, camera: focus(stack, i, surface, fixture) }));
        const paths = [
            ...AZIMUTHS.flatMap((a) => posed.map((p) => [overview(a), p, `overview at ${Math.round((a * 180) / Math.PI)}° to ${p.view.pose.floor}`])),
            ...posed.flatMap((p) => posed.filter((q) => q !== p).map((q) => [p, q, `${p.view.pose.floor} to ${q.view.pose.floor}`])),
            ...posed.flatMap((p) => AZIMUTHS.map((a) => [p, overview(a), `${p.view.pose.floor} to the overview at ${Math.round((a * 180) / Math.PI)}°`])),
        ];

        for (const [from, to, what] of paths) {
            let last = from.camera.position;

            for (let n = 1; n <= SAMPLES; n++) {
                const here = M.betweenPoses(from, to, n / SAMPLES, stack.axis).camera.position;

                if (!M.segmentClears(last, here, boxes)) {
                    leg('paths').push(`${fixture} on ${name(surface)}: the step at t = ${(n / SAMPLES).toFixed(3)} from ${what} meets a slab`);

                    break;
                }

                clearance = Math.min(clearance, distanceToBounds(here, stack.bounds));
                last = here;
            }
        }
    }
}

printed.least_clearance = Number(clearance.toFixed(2));

// ── the floor entries' corner ─────────────────────────────────────────────────────────────────

for (const [fixture, extents] of Object.entries(FIXTURES)) {
    const surface = stage(1280, 600);
    const stack = stackOf(extents, surface);

    for (const azimuth of AZIMUTHS) {
        const camera = M.overviewPose(stack, surface, azimuth);

        for (const slab of [stack.ground, ...stack.slabs]) {
            const corner = M.labelCorner(slab, azimuth);

            if (!M.segmentClears(camera.position, { ...corner, y: corner.y + 1e-3 }, boxesOf(stack))) {
                leg('corner').push(`${fixture} at ${Math.round((azimuth * 180) / Math.PI)}°: ${slab.floor ?? 'the ground'}'s entry corner is hidden`);
            }
        }
    }
}

// ── the snap table, cell by cell ──────────────────────────────────────────────────────────────

printed.plus = {};
let centreMisses = 0;
let otherFloorPoints = 0;

for (const [fixture, extents] of Object.entries(FIXTURES)) {
    const surface = stage(1280, 600);
    const stack = stackOf(extents, surface);
    const home = { pose: 'overview', azimuth: 0 };
    const turned = { pose: 'overview', azimuth: 0.7 };
    const centreOf = (camera, slab) => M.projectPoint(camera, surface, { x: slab.box.x + slab.box.w / 2, y: topOf(slab), z: slab.box.z + slab.box.d / 2 });
    const cell = (what, got, want) => {
        if (!M.samePose(got, want) || got.azimuth !== want.azimuth) {
            leg('snap').push(`${fixture}: ${what} answered ${JSON.stringify(got)}, not ${JSON.stringify(want)}`);
        }
    };
    const homeCamera = M.overviewPose(stack, surface, 0);
    const sky = { x: 2, y: 2 };

    if (M.aim(M.rayThrough(homeCamera, surface, sky), [...stack.slabs, stack.ground]) !== null) {
        leg('snap').push(`${fixture}: CONTROL — the stage's corner is over a slab, so the sky cell reads nothing`);
    }

    for (const act of ['in', 'click']) {
        for (const slab of stack.slabs) {
            cell(`${act} over ${slab.floor} at the overview`, M.snap(home, { act, point: centreOf(homeCamera, slab) }, stack, surface), { pose: { floor: slab.floor }, azimuth: 0 });
        }

        cell(`${act} over the ground at the overview`, M.snap(home, { act, point: centreOf(homeCamera, stack.ground) }, stack, surface), home);
        cell(`${act} over the sky at the overview`, M.snap(home, { act, point: sky }, stack, surface), home);
    }

    const plus = M.snap(home, { act: 'plus' }, stack, surface);
    const nearest = M.nearestToCentre(stack, homeCamera, surface);

    cell('+ at the overview', plus, { pose: { floor: nearest.floor }, azimuth: 0 });
    printed.plus[fixture] = plus.pose === 'overview' ? null : `storey ${stack.slabs.find((s) => s.floor === plus.pose.floor).storey}`;

    // The `+` plant's precondition: a stack whose centre ray meets no slab, so a ray-aimed + would choose nothing.
    if (M.aim(M.rayThrough(homeCamera, surface, { x: surface.width / 2, y: surface.height / 2 }), [...stack.slabs, stack.ground]) === null) {
        centreMisses++;
    }

    cell('− at the overview', M.snap(turned, { act: 'out' }, stack, surface), turned);
    cell('Fit at the overview', M.snap(turned, { act: 'fit' }, stack, surface), home);

    // The keyboard's focus on a floor's label (§ 3.4): the click on its slab, named by its key — the ground's (`null`)
    // and a key the stack does not hold stay.
    for (const slab of stack.slabs) {
        cell(`focus on ${slab.floor}'s label at the overview`, M.snap(turned, { act: 'floor', floor: slab.floor }, stack, surface), { pose: { floor: slab.floor }, azimuth: 0.7 });
    }

    cell('focus on the ground\'s label at the overview', M.snap(turned, { act: 'floor', floor: null }, stack, surface), turned);
    cell('focus on a dropped floor\'s label at the overview', M.snap(turned, { act: 'floor', floor: 'no-such-floor' }, stack, surface), turned);

    stack.slabs.forEach((slab, i) => {
        const at = { pose: { floor: slab.floor }, azimuth: 0.7 };
        const camera = focus(stack, i, surface, fixture);

        cell(`+ at ${slab.floor}`, M.snap(at, { act: 'plus' }, stack, surface), at);
        cell(`− at ${slab.floor}`, M.snap(at, { act: 'out' }, stack, surface), turned);
        cell(`Fit at ${slab.floor}`, M.snap(at, { act: 'fit' }, stack, surface), home);
        cell(`in over ${slab.floor} itself`, M.snap(at, { act: 'in', point: { x: surface.width / 2, y: surface.height / 2 } }, stack, surface), at);
        cell(`focus on ${slab.floor}'s own label at ${slab.floor}`, M.snap(at, { act: 'floor', floor: slab.floor }, stack, surface), at);
        cell(`focus on the ground's label at ${slab.floor}`, M.snap(at, { act: 'floor', floor: null }, stack, surface), at);

        for (const other of stack.slabs.filter((s) => s !== slab)) {
            cell(`focus on ${other.floor}'s label at ${slab.floor}`, M.snap(at, { act: 'floor', floor: other.floor }, stack, surface), { pose: { floor: other.floor }, azimuth: 0.7 });
        }

        // A point the focus pose aims at another floor — searched over the stage's grid.
        for (let gx = 10; gx < surface.width; gx += 40) {
            for (let gy = 5; gy < surface.height; gy += 10) {
                const hit = M.aim(M.rayThrough(camera, surface, { x: gx, y: gy }), [...stack.slabs, stack.ground]);

                if (hit !== null && hit.slab.floor !== null && hit.slab.floor !== slab.floor) {
                    otherFloorPoints++;
                    cell(`in over ${hit.slab.floor} from ${slab.floor}`, M.snap(at, { act: 'in', point: { x: gx, y: gy } }, stack, surface), { pose: { floor: hit.slab.floor }, azimuth: 0.7 });
                    gx = surface.width;

                    break;
                }
            }
        }
    });

    // A ray through a slab's underside is over no slab.
    const slab = stack.slabs[0];
    const up = { origin: { x: slab.box.x + slab.box.w / 2, y: slab.box.y - 1, z: slab.box.z + slab.box.d / 2 }, dir: { x: 0, y: 1, z: 0 } };

    if (M.aim(up, [slab]) !== null) {
        leg('snap').push(`${fixture}: aim() through ${slab.floor}'s underside names it`);
    }
}

if (centreMisses === 0) {
    leg('snap').push('CONTROL — no fixture\'s centre ray misses every slab, so the + cell cannot tell choosing a floor from aiming at the centre');
}

if (otherFloorPoints === 0) {
    leg('snap').push('CONTROL — no focus pose aims any point at another floor, so the focus row\'s in cell read nothing');
}

printed.centre_misses = centreMisses;

// ── labelsShown() and cullOverlaps() ──────────────────────────────────────────────────────────

{
    const box = (x, y, w = 100, h = 20) => ({ x, y, w, h });
    // Reading order is the scene's (`at`), not the screen's: `b` reads first but stands to the right on the screen.
    const labels = [{ key: 'a', at: { x: 10, y: 50 } }, { key: 'b', at: { x: 400, y: 10 } }, { key: 'c', at: { x: 10, y: 90 } }];
    const boxes = new Map([['a', box(0, 0)], ['b', box(60, 5)], ['c', box(300, 0)]]);
    const plain = M.cullOverlaps(labels, boxes, null);
    const kept = [...plain.kept];
    const meets = (p, q) => p.x < q.x + q.w && q.x < p.x + p.w && p.y < q.y + q.h && q.y < p.y + p.h;

    if (kept.some((k, i) => kept.slice(i + 1).some((j) => meets(boxes.get(k), boxes.get(j))))) {
        leg('cull').push('(a) two kept boxes intersect');
    }

    if (!plain.kept.has('b') || plain.kept.has('a')) {
        leg('cull').push(`(b) of the overlapping pair the earlier in reading order is not the one kept: kept ${JSON.stringify(kept)}`);
    }

    const led = M.cullOverlaps(labels, boxes, 'a');

    if (!led.kept.has('a') || !led.culled.has('b')) {
        leg('cull').push('(c) the leader is not kept with the label it overlaps culled');
    }

    if (JSON.stringify([...M.cullOverlaps([...labels].reverse(), boxes, null).kept].sort()) !== JSON.stringify([...plain.kept].sort())) {
        leg('cull').push('(d) the same labels in another order give another set');
    }

    const view = { pose: { floor: 'f0' }, azimuth: 0 };
    const byFloor = new Map([['f0', labels]]);

    if (M.labelsShown(view, byFloor, boxes, 'a').shown.has('b') || M.labelsShown(null, byFloor, boxes, null).shown.size !== 0
        || M.labelsShown({ pose: 'overview', azimuth: 0 }, byFloor, boxes, null).shown.size !== 0) {
        leg('cull').push('labelsShown() draws a label at the overview or in flight, or ignores the leader');
    }
}

// (e) the predicate and the cull agree, over the harness's floor: its extent, its labels, the measured widths.
{
    const fx = payload.cull ?? null;

    if (fx === null || fx.labels.length < 2) {
        leg('cull').push('CONTROL — the probe was handed no floor of two labels or more');
    } else {
        const widthOf = (label) => [...label.text].length * fx.glyph_w + 2 * (M.LABEL_PAD + M.LABEL_BORDER);
        const at = (surface) => {
            const stack = M.stackModel([{ floor: 'aimla', name: 'aimla', href: '/floor/aimla', level: 0 }], new Map([['aimla', fx.extent]]), '/', surface);
            const camera = M.focusPose(stack, 0, surface);
            const view = { pose: { floor: 'aimla' }, azimuth: 0 };
            const boxes = M.labelBoxes(fx.labels, stack.slabs[0], camera, surface, widthOf);
            const { shown, culled } = M.labelsShown(view, new Map([['aimla', fx.labels]]), boxes, null);

            return { stack, camera, boxes, shown, culled, link: M.readFloorLink(view, culled, stack), legible: M.legibility(camera, stack.slabs[0], surface, BOX_W) };
        };

        // The stages the predicate holds on for THIS floor, derived — its footprint is the harness's, not the default's.
        const holding = [...LEGIBLE, ...ILLEGIBLE].filter((surface) => at(surface).legible.holds);

        printed.cull_legible = holding.map(name);

        if (holding.length === 0) {
            leg('cull').push('CONTROL — the predicate holds on no stage for the harness\'s floor, so (e)\'s first half read nothing');
        }

        for (const surface of holding) {
            const r = at(surface);

            if (r.culled.size !== 0 || r.link.hidden !== true) {
                leg('cull').push(`(e) on ${name(surface)}, where the predicate holds, the cull culled ${r.culled.size} or the link is shown`);
            }
        }

        let derived = null;

        for (let width = 1280; width >= 120 && derived === null; width -= 10) {
            const r = at(stage(width, 800));

            if (r.culled.size > 0) {
                derived = { width, r };
            }
        }

        if (derived === null) {
            leg('cull').push('CONTROL — no stage narrowed from 1280 × 800 makes the cull cull a label');
        } else {
            const { r } = derived;
            const keptBoxes = [...r.shown].map((k) => r.boxes.get(k));

            printed.cull_stage = `${derived.width}×800`;

            if (keptBoxes.some((p, i) => keptBoxes.slice(i + 1).some((q) => p.x < q.x + q.w && q.x < p.x + p.w && p.y < q.y + q.h && q.y < p.y + p.h))) {
                leg('cull').push(`(a) on the derived ${derived.width}×800 stage two drawn labels intersect`);
            }

            if (r.link.hidden !== false || r.link.href !== '/floor/aimla') {
                leg('cull').push(`(e) on the derived ${derived.width}×800 stage the cull culled and the link is ${JSON.stringify(r.link)}`);
            }
        }
    }
}

// ── the rest ──────────────────────────────────────────────────────────────────────────────────

{
    const stack = stackOf(Array(5).fill(DEFAULT), stage(1280, 600));
    const slab = stack.slabs[2];
    const check = (what, ok) => {
        if (!ok) {
            leg('rest').push(what);
        }
    };

    for (const [surface, dpr] of [[stage(1280, 600), 1], [stage(1280, 600), 2], [stage(1920, 1000), 2], [stage(2560, 1300), 2]]) {
        const s5 = stackOf(Array(5).fill(DEFAULT), surface);
        const camera = M.focusPose(s5, 2, surface);
        const f = M.focusScale(camera, s5.slabs[2], surface, dpr, 8192);
        // The nearest edge is the front one: at azimuth 0 its every point has one depth.
        const nearest = M.scaleAt(camera, surface, { x: 0, y: topOf(s5.slabs[2]), z: s5.slabs[2].box.z + s5.slabs[2].box.d });
        const want = Math.min(4096 / 1784, Math.max(1, nearest * dpr));

        check(`focusScale() on ${name(surface)} at DPR ${dpr} is ${f}, not the nearest edge's ${want}`, Math.abs(f - want) < 1e-9 && f >= 1 && f <= 4096 / 1784);
        (printed.focus_scale ??= {})[`${name(surface)}@${dpr}`] = Number(f.toFixed(3));
    }

    check('focusScale() at DPR 2 on 1280×600 is not above 1', printed.focus_scale['1280×600@2'] > 1);

    const front = M.worldOf(slab, { x: slab.extent.x, y: slab.extent.y + slab.extent.h });
    const back = M.worldOf(slab, { x: slab.extent.x + slab.extent.w, y: slab.extent.y });
    const near = (p, q) => Math.abs(p.x - q.x) < 1e-9 && Math.abs(p.y - q.y) < 1e-9 && Math.abs(p.z - q.z) < 1e-9;

    check('worldOf() does not map the drawing\'s bottom-left to the slab\'s front-left', near(front, { x: slab.box.x, y: topOf(slab), z: slab.box.z + slab.box.d }));
    check('worldOf() does not map the drawing\'s top-right to the slab\'s back-right', near(back, { x: slab.box.x + slab.box.w, y: topOf(slab), z: slab.box.z }));

    for (const surface of [stage(1600, 400), stage(400, 1600)]) {
        const bounds = stack.bounds;
        const d = M.fitDistance(bounds, M.FOV, surface.width / surface.height);
        const angular = Math.asin(Math.hypot(bounds.w, bounds.h, bounds.d) / 2 / d);
        const half = Math.min(M.FOV / 2, Math.atan(Math.tan(M.FOV / 2) * surface.width / surface.height));

        check(`fitDistance() on ${name(surface)} does not fit the bounding sphere to the narrower half-angle`, Math.abs(angular - half) < 1e-9);
    }

    // The status line names what the view rests on, by the floor's name (a name and no figure).
    check(`viewStatus() at the overview is ${JSON.stringify(M.viewStatus({ pose: 'overview', azimuth: 0.7 }, stack))}`, M.viewStatus({ pose: 'overview', azimuth: 0.7 }, stack) === 'the whole building');
    check(`viewStatus() at ${slab.floor} is ${JSON.stringify(M.viewStatus({ pose: { floor: slab.floor }, azimuth: 0 }, stack))}`, M.viewStatus({ pose: { floor: slab.floor }, azimuth: 0 }, stack) === `floor ${slab.name}`);
    check('viewStatus() at a dropped floor is not the whole building\'s', M.viewStatus({ pose: { floor: 'no-such-floor' }, azimuth: 0 }, stack) === 'the whole building');

    const t1 = M.textureSize(DEFAULT, 1, 8192);
    const t2 = M.textureSize({ x: 0, y: 0, w: 8000, h: 2000 }, 1, 8192);
    const t3 = M.textureSize(DEFAULT, 1, 1024);

    check(`textureSize() under the cap is not 1:1: ${JSON.stringify(t1)}`, t1.width === 1784 && t1.height === 864);
    check(`textureSize() over the cap is not proportional at the cap: ${JSON.stringify(t2)}`, t2.width === 4096 && t2.height === 1024);
    check(`textureSize() on a 1024 device is not proportional at 1024: ${JSON.stringify(t3)}`, t3.width === 1024 && t3.height === Math.round((864 * 1024) / 1784));
}

process.stdout.write(JSON.stringify({ legs: Object.fromEntries(['storey', 'focus', 'paths', 'corner', 'snap', 'cull', 'rest'].map((k) => [k, legs[k] ?? []])), printed }));

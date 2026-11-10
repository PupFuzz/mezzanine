/**
 * The probe `Tests\Feature\Building\TheBuildingViewStacksTheFloorsTest` reads `building/building-acts.js` through — plan
 * § 8 item 3's *acts* leg: stand-in gestures under a stub clock. argv[2] is the module directory (the shipped
 * `public/js/building` or a MUTATED COPY of it); stdout is `{ "legs": { "<leg>": [defect, …] }, "printed": { … } }`.
 *
 * ⛔ THE PAGE IS STOOD IN FOR, AS § 3.3 ITEM 2 SPECIFIES IT, AND NOTHING MORE. `page.show(view)` drops every answer while
 * a glide is in flight; a view at the resting pose is a turn and sets the resting view; any other pose starts a glide of
 * `GLIDE_MS` on the stub clock, which ARRIVES — `acts.arrived(to)` — once the clock passes its end. What a leg counts is
 * the glides started: a pose change. The building page (slice 4) wires the real `wire/camera-view.js`; this stand-in is
 * how the acts' own rules are observable without one.
 *
 * ⛔ A STREAM's LENGTH AND SPACING ARE CHOSEN BY RUNNING IT, AND EACH IS CHECKED HERE RATHER THAN TAKEN ON TRUST: a stream
 * that is to outlive the glide is asserted to have events after the arrival, and the point a stream is aimed at is
 * DERIVED — one `aim()` names as one floor at the overview and as another at that floor's pose — or the leg fails as a
 * control (plan § 12 item 21).
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node building-acts-probe.mjs <building-module-dir>');
    process.exit(2);
}

const A = await import(pathToFileURL(join(dir, 'building-acts.js')).href);
const M = await import(pathToFileURL(join(dir, 'stack-model.js')).href);
const { GLIDE_MS, NOTCH_PX, PINCH_GAIN } = await import(pathToFileURL(join(dir, '..', 'wire', 'camera.js')).href);

const DEFAULT = { x: 0, y: -160, w: 1784, h: 864 };
const legs = {};
const printed = {};
const leg = (key) => (legs[key] ??= []);

function stackOf(n, surface) {
    const rows = Array.from({ length: n }, (_, level) => ({ floor: `f${level}`, name: `f${level}`, href: `/floor/f${level}`, level }));

    return M.stackModel(rows, new Map(rows.map((r) => [r.floor, DEFAULT])), '/', surface);
}

/** The acts over a stack, behind the stand-in page, on a stub clock. */
function rig(stack, surface, { glideMs = GLIDE_MS } = {}) {
    let t = 0;
    let glide = null;
    let resting = A.HOME_VIEW;
    const changes = [];
    const acts = A.buildingActs({ stack: () => stack, surface: () => surface, now: () => t, gliding: () => glide !== null });
    const settle = () => {
        if (glide !== null && t >= glide.end) {
            resting = glide.to;
            glide = null;
            acts.arrived(resting);
        }
    };
    const page = {
        show(view) {
            settle();

            if (glide !== null) {
                return;
            }

            if (M.samePose(view, resting)) {
                resting = view;

                return;
            }

            changes.push({ at: t, view });
            glide = { to: view, end: t + glideMs };
            settle();
        },
    };

    return {
        acts,
        changes,
        at(ms) {
            t = ms;
            settle();
        },
        get now() {
            return t;
        },
        get resting() {
            return resting;
        },
        get arrivedBy() {
            return changes.length === 0 ? null : changes[changes.length - 1].at + glideMs;
        },
        page,
    };
}

const surface = { width: 1280, height: 600 };
const stack = stackOf(5, surface);
const centreOf = (camera, slab) => M.projectPoint(camera, surface, { x: slab.box.x + slab.box.w / 2, y: slab.box.y + slab.box.h, z: slab.box.z + slab.box.d / 2 });
const home = M.overviewPose(stack, surface, 0);
const top = stack.slabs[0];
const overTop = centreOf(home, top);

/** The stages and stack heights the mute's point is searched over — the search's population, not a figure it must find. */
const SEARCH = [390, 768, 1024, 1280, 1600].flatMap((width) => [420, 600, 700, 800, 950].map((height) => ({ width, height })))
    .flatMap((stage) => [2, 5, 10].map((n) => ({ stage, n })));

/**
 * A point `aim()` names as one floor from the overview and as ANOTHER from that floor's pose — the mute's subject —
 * searched over each stack and stage's grid, the first found; `null` when none exists on any.
 */
function twoFloorPoint() {
    for (const { stage, n } of SEARCH) {
        const st = stackOf(n, stage);
        const camera = M.overviewPose(st, stage, 0);
        const all = [...st.slabs, st.ground];

        for (let gx = 5; gx < stage.width; gx += 10) {
            for (let gy = 2; gy < stage.height; gy += 3) {
                const p = { x: gx, y: gy };
                const first = M.aim(M.rayThrough(camera, stage, p), all);

                if (first === null || first.slab.floor === null) {
                    continue;
                }

                const pose = M.focusPose(st, st.slabs.indexOf(first.slab), stage);
                const second = M.aim(M.rayThrough(pose, stage, p), all);

                if (second !== null && second.slab.floor !== null && second.slab.floor !== first.slab.floor) {
                    return { stack: st, surface: stage, point: p, floors: n, first: first.slab.floor, second: second.slab.floor };
                }
            }
        }
    }

    return null;
}

// ── the wheel's sign ──────────────────────────────────────────────────────────────────────────

{
    const r = rig(stack, surface);

    r.page.show(r.acts.pan({ deltaY: -NOTCH_PX, deltaMode: 0 }, overTop).camera);

    if (r.changes.length !== 1 || r.changes[0].view.pose?.floor !== top.floor) {
        leg('acts').push(`a negative deltaY over ${top.floor} is not IN to it: ${JSON.stringify(r.changes)}`);
    }

    r.at(r.arrivedBy + 400);
    r.page.show(r.acts.pan({ deltaY: NOTCH_PX, deltaMode: 0 }, overTop).camera);

    if (r.changes.length !== 2 || r.changes[1].view.pose !== 'overview') {
        leg('acts').push(`a positive deltaY at a focus pose is not OUT to the overview: ${JSON.stringify(r.changes)}`);
    }

    const consumed = r.acts.pan({ deltaY: 1, deltaMode: 0 }, overTop).consumed;

    if (consumed !== true) {
        leg('acts').push('a plain wheel over the stage is not consumed');
    }
}

// ── a stream fills the bucket once and outlives the glide ─────────────────────────────────────

const STREAM_STEP = 4;
const STREAM_GAP = 16;

/** A pixel-mode stream over `point`: `count` events of `−STREAM_STEP` px every `STREAM_GAP` ms, each shown. */
function stream(r, point, count, { gap = STREAM_GAP, step = STREAM_STEP, start = 0 } = {}) {
    const answers = [];

    for (let i = 0; i < count; i++) {
        r.at(start + i * gap);
        const before = r.resting;
        const answer = r.acts.pan({ deltaY: -step, deltaMode: 0 }, point).camera;

        answers.push({ at: r.now, before, answer });
        r.page.show(answer);
    }

    return answers;
}

{
    const fills = Math.ceil(NOTCH_PX / STREAM_STEP);
    const count = fills + Math.ceil((GLIDE_MS + 400) / STREAM_GAP);
    const r = rig(stack, surface);
    const answers = stream(r, overTop, count);
    const last = answers[answers.length - 1].at;

    printed.stream = { events: count, fills_at: fills, arrived_at: r.arrivedBy, last_event_at: last };

    if (r.changes.length !== 1) {
        leg('acts').push(`a stream of ${count} small wheel events made ${r.changes.length} pose changes, not one`);
    } else if (Math.round(r.changes[0].at / STREAM_GAP) + 1 !== fills) {
        leg('acts').push(`the stream fired at event ${Math.round(r.changes[0].at / STREAM_GAP) + 1}, not at event ${fills}, where its scroll first reaches a notch`);
    }

    if (!(last > (r.arrivedBy ?? Infinity))) {
        leg('acts').push('CONTROL — the stream did not outlive the glide');
    }

    for (const a of answers.slice(fills)) {
        if (!M.samePose(a.answer, a.before)) {
            leg('acts').push(`the stream's event at ${a.at} ms answered a move after the firing`);

            break;
        }
    }
}

// The mute's subject: a stream that outlives the glide over a point on another floor from the destination.
{
    const derived = twoFloorPoint();

    if (derived === null) {
        leg('mute').push('CONTROL — on no stack and stage does a point aim at one floor from the overview and another from that floor\'s pose');
    } else {
        const { stack: st, surface: stage, point } = derived;

        printed.mute_point = { stage: `${stage.width}×${stage.height}`, floors: derived.floors, point, first: derived.first, second: derived.second };

        const fills = Math.ceil(NOTCH_PX / STREAM_STEP);
        const count = 2 * fills + Math.ceil((GLIDE_MS + 400) / STREAM_GAP);
        const r = rig(st, stage);

        stream(r, point, count);

        if (r.changes.length !== 1) {
            leg('mute').push(`a stream over ${JSON.stringify(point)} that outlives the glide made ${r.changes.length} pose changes: ${r.changes.map((c) => c.view.pose.floor ?? 'overview').join(', ')}`);
        }

        // The same under reduced motion, where the glide is a cut and only the mute holds the one-snap rule.
        const cut = rig(st, stage, { glideMs: 0 });

        stream(cut, point, count);

        if (cut.changes.length !== 1) {
            leg('mute').push(`under a cut, the stream made ${cut.changes.length} pose changes`);
        }

        // A slow stream — every event inside the gap of the last — stays one gesture for its whole length.
        const slow = rig(st, stage);

        stream(slow, point, Math.ceil((GLIDE_MS + 1200) / 100), { gap: 100, step: 40 });

        if (slow.changes.length !== 1) {
            leg('mute').push(`a slow stream (an event every 100 ms) made ${slow.changes.length} pose changes — the gap is not read from the last event`);
        }
    }
}

// ── a line- or page-mode event is a notch of its own ──────────────────────────────────────────

for (const deltaMode of [1, 2]) {
    const r = rig(stack, surface);

    r.page.show(r.acts.pan({ deltaY: -3, deltaMode }, overTop).camera);

    if (r.changes.length !== 1) {
        leg('acts').push(`one deltaMode ${deltaMode} wheel event of −3 made ${r.changes.length} pose changes, not one`);
    }
}

// ── a quiet gap starts a new gesture, with an empty bucket ────────────────────────────────────

{
    const r = rig(stack, surface);
    const part = -(NOTCH_PX * 0.6);

    r.page.show(r.acts.pan({ deltaY: part, deltaMode: 0 }, overTop).camera);
    r.at(A.GESTURE_GAP_MS);
    r.page.show(r.acts.pan({ deltaY: part, deltaMode: 0 }, overTop).camera);

    if (r.changes.length !== 0) {
        leg('acts').push('two part-notches a quiet gap apart added up to a snap — the bucket did not empty at the new gesture');
    }

    const s = rig(stack, surface);

    s.page.show(s.acts.pan({ deltaY: part, deltaMode: 0 }, overTop).camera);
    s.at(A.GESTURE_GAP_MS - 1);
    s.page.show(s.acts.pan({ deltaY: part, deltaMode: 0 }, overTop).camera);

    if (s.changes.length !== 1) {
        leg('acts').push('CONTROL — two part-notches inside the gap did not add up to a snap');
    }
}

// ── the buckets, pure ─────────────────────────────────────────────────────────────────────────

{
    const fired = A.wheelBucket(0, -NOTCH_PX);
    const after = A.wheelBucket(fired.bucket, -1);

    if (fired.fire !== 'in' || after.fire !== null) {
        leg('acts').push(`the wheel bucket at a notch answered ${JSON.stringify(fired)}, then ${JSON.stringify(after)} — it does not empty on firing`);
    }

    if (A.wheelBucket(0, -(NOTCH_PX - 1)).fire !== null || A.wheelBucket(0, NOTCH_PX).fire !== 'out') {
        leg('acts').push('the wheel bucket fires short of a notch, or not at +NOTCH_PX as out');
    }

    const p = A.pinchBucket(0, A.PINCH_SNAP);

    if (p.fire !== 'in' || A.pinchBucket(p.bucket, 1.01).fire !== null || A.pinchBucket(0, 1 / (A.PINCH_SNAP * 1.001)).fire !== 'out') {
        leg('acts').push('the pinch bucket does not fire in at PINCH_SNAP, empty on firing, or fire out at its inverse');
    }
}

// ── a Ctrl+wheel reaches a notch by PINCH_GAIN ────────────────────────────────────────────────

{
    const r = rig(stack, surface);

    r.page.show(r.acts.zoom(overTop, { deltaY: -NOTCH_PX / PINCH_GAIN, deltaMode: 0 }).camera);

    if (r.changes.length !== 1) {
        leg('acts').push(`a Ctrl+wheel of −NOTCH_PX/PINCH_GAIN made ${r.changes.length} pose changes, not one`);
    }

    const s = rig(stack, surface);

    s.page.show(s.acts.pan({ deltaY: -NOTCH_PX / PINCH_GAIN, deltaMode: 0 }, overTop).camera);

    if (s.changes.length !== 0) {
        leg('acts').push('CONTROL — a plain wheel of the same scroll snapped, so the gain was not what fired');
    }
}

// ── a pinch fires at the step whose cumulative ratio first crosses PINCH_SNAP ─────────────────

{
    const factor = 1.05;
    const first = Math.ceil(Math.log(A.PINCH_SNAP) / Math.log(factor));
    const r = rig(stack, surface);

    for (let i = 1; i <= first + 1; i++) {
        r.at(i * 16);
        r.page.show(r.acts.pinch(overTop, overTop, factor));

        if (i === first - 1 && r.changes.length !== 0) {
            leg('acts').push(`the pinch fired before step ${first}, where its ratio first crosses PINCH_SNAP`);
        }
    }

    if (r.changes.length !== 1 || r.changes[0].at !== first * 16) {
        leg('acts').push(`the pinch made ${r.changes.length} changes, the first at ${r.changes[0]?.at} ms, not one at step ${first}`);
    }
}

// ── the drag turns the overview and nothing else ──────────────────────────────────────────────

{
    const r = rig(stack, surface);
    const turned = r.acts.drag(120, 0);

    r.page.show(turned);

    if (turned.pose !== 'overview' || !(Math.abs(turned.azimuth) > 0) || r.acts.camera().azimuth !== turned.azimuth || r.changes.length !== 0) {
        leg('acts').push(`a drag at the overview did not turn it and record the turn: ${JSON.stringify(turned)}, resting ${JSON.stringify(r.acts.camera())}`);
    }

    const tilted = r.acts.drag(0, -60);

    if (tilted.azimuth !== turned.azimuth) {
        leg('acts').push('an upward drag changed the view');
    }

    // The turn is what the next snap starts from: a wheel in keeps the turned azimuth for the way back.
    r.page.show(r.acts.pan({ deltaY: -NOTCH_PX, deltaMode: 0 }, centreOf(M.overviewPose(stack, surface, turned.azimuth), top)).camera);

    if (r.changes.length !== 1 || r.changes[0].view.azimuth !== turned.azimuth) {
        leg('acts').push(`the snap after a turn did not start from the turned azimuth: ${JSON.stringify(r.changes)}`);
    }

    r.at(r.arrivedBy + 1);

    const still = r.acts.drag(200, 0);

    if (!M.samePose(still, r.resting) || still.azimuth !== r.resting.azimuth) {
        leg('acts').push('a drag at a focus pose moved the view');
    }
}

// ── no stack, no bounds ───────────────────────────────────────────────────────────────────────

{
    const acts = A.buildingActs({ stack: () => null, surface: () => surface, now: () => 0, gliding: () => false });

    if (acts.camera().bounds !== null) {
        leg('acts').push('camera() before any extent has bounds');
    }

    if (acts.camera().pose !== 'overview' || stackOf(1, surface) === null || A.buildingActs({ stack: () => stack, surface: () => surface, now: () => 0, gliding: () => false }).camera().bounds === null) {
        leg('acts').push('CONTROL — camera() over a drawn stack has no bounds');
    }
}

process.stdout.write(JSON.stringify({ legs: { acts: legs.acts ?? [], mute: legs.mute ?? [] }, printed }));

/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsCameraStaysAboveTheFloorTest` reads `floor3d/view-limits.js` through —
 * `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 3. argv[2] is the client's `wire/` directory (the shipped one or a
 * MUTATED COPY of the tree); stdout is `{ "legs": { "<leg>": [defect, …] }, "printed": { … } }`, each leg a list of
 * defects, empty when it holds.
 *
 * ⛔ THE GEOMETRY IS THE OBSERVATION, COMPUTED HERE FROM THE MODULE's FIGURES: the eye on its sphere about the target and
 * the near plane's four corners (a perspective camera's, `y` up, its vertical field of view `fov`), at every target of a
 * grid over the floor, every polar from straight down to the limit and every azimuth of a turn, at the minimum distance.
 * The module states the limits; this measures whether they keep the camera, and its near plane, above the floor.
 *
 * ⛔ THE CAB's CLIP IS JUDGED BY LINE OF SIGHT (the `doorway` leg): `doorwayView()`'s planes keep a point behind the band's
 * face exactly where the segment from the eye to it crosses the doorway's opening, each normal of unit length, and give no
 * planes to an eye at or behind the face — over a grid of eyes and of points wider and taller than the opening.
 *
 * ⛔ THE CONFINEMENT IS DRIVEN THROUGH THE VENDORED `OrbitControls` (`FLOOR.md` § 4.5's *the eye stays in the office*):
 * the library's own update, damping on and off, with `confineControls()` after every update as the renderer's frame
 * calls it, and the keys through `floor3d/camera-acts.js` over the same controls. A pointer's drag, the wheel and a turn
 * are the library's public `pan()`, `dollyIn()` / `dollyOut()` and `rotateLeft()` — each its private step and an update,
 * which is what its pointer handlers run. The library imports the bare `three`, which the page's import map resolves;
 * here a resolve hook does the same. Legs:
 * - `move` — rule A: a right-drag walks — from the high opening view and from an eye lowered to desk level, a pan
 *   forward carries the eye into the office with its height, pitch, facing and distance kept and stops it at the back
 *   wall; a pan across slides it along the wall to each side; the eye passes no bound at any frame.
 * - `keys` — the same with Shift + an arrow, through the acts; and *Fit* from a pose the bound pulled in is the entry.
 * - `turn` — a turn through a full circle and the wheel in and out, from the entry and from inside the office: the eye
 *   passes no bound at any frame, the target does not move, and a side view at the fit is brought in.
 * - `damping` — a pan into the back wall is held there while its damped remainder runs out (the library's update after
 *   the clamp re-applied it: the back wall's drop), and a pan back out right after moves the eye by the pan's own
 *   length — the remainder owed into the wall is not left to cancel it.
 * - `near` — a turn at a wall (each side, the back, a corner) from the dolly minimum looking level: every drawn frame no
 *   nearer the target than the minimum, the near plane's corners above the floor.
 * - `wake` — a pointer's pan pushing the eye past the back wall again from where the clamp held it fires the controls'
 *   change, the renderer's wake, so a frame clamps it: the clamp leaves the controls' own last pose at the held one.
 * - `confine` — the pure rule over seeded random poses: every result legal, its target's height in range, a legal pose
 *   kept, and the entry legal on every extent from a narrow floor up.
 */

import { registerHooks } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
const JS = join(wire, '..');

registerHooks({
    resolve: (specifier, context, next) => (specifier === 'three'
        ? { url: pathToFileURL(join(JS, 'vendor', 'three', 'three.module.js')).href, shortCircuit: true }
        : next(specifier, context)),
});

const { PerspectiveCamera, Vector3 } = await import(pathToFileURL(join(JS, 'vendor', 'three', 'three.module.js')).href);
const { OrbitControls } = await import(pathToFileURL(join(JS, 'vendor', 'three', 'examples', 'jsm', 'controls', 'OrbitControls.js')).href);
const { cameraActs } = await import(pathToFileURL(join(JS, 'floor3d', 'camera-acts.js')).href);
const { PAN_STEP_PX } = await import(pathToFileURL(join(wire, 'camera.js')).href);
const L = await import(pathToFileURL(join(wire, '..', 'floor3d', 'view-limits.js')).href);
const { ROOM_H_U, UNIT } = await import(pathToFileURL(join(wire, '..', 'floor', 'floor-world.js')).href);
const { CHARACTER_SCALE } = await import(pathToFileURL(join(wire, '..', 'floor', 'desk-layout.js')).href);
const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const { SCENE_W, SCENE_H } = await import(pathToFileURL(join(repo, 'resources', 'characters', 'index.js')).href);

const character = { w: SCENE_W, h: SCENE_H };
/** The shipped default's floor below its band, in units (1,784 × 696 scene px). */
const extent = { w: 17.84, d: 6.96 };
const wide = { width: 1280, height: 800 };
const tall = { width: 800, height: 1280 };
const legs = { limits: [], above: [], cutaway: [], doorway: [], move: [], keys: [], turn: [], damping: [], wake: [], near: [], confine: [] };
const printed = {};
const deg = (r) => (r * 180) / Math.PI;

const sub = (a, b) => ({ x: a.x - b.x, y: a.y - b.y, z: a.z - b.z });
const add = (a, b) => ({ x: a.x + b.x, y: a.y + b.y, z: a.z + b.z });
const scale = (a, k) => ({ x: a.x * k, y: a.y * k, z: a.z * k });
const cross = (a, b) => ({ x: a.y * b.z - a.z * b.y, y: a.z * b.x - a.x * b.z, z: a.x * b.y - a.y * b.x });
const norm = (a) => scale(a, 1 / Math.hypot(a.x, a.y, a.z));
const eyeAt = (target, polar, azimuth, d) => add(target, scale({ x: Math.sin(polar) * Math.sin(azimuth), y: Math.cos(polar), z: Math.sin(polar) * Math.cos(azimuth) }, d));

/** The near plane's four corners of a camera at `eye` looking at `target`, `y` up (straight down: `-z` up on screen). */
function corners(eye, target, near, fov, aspect) {
    const f = norm(sub(target, eye));
    const right = Math.abs(f.y) > 1 - 1e-9 ? { x: 1, y: 0, z: 0 } : norm(cross(f, { x: 0, y: 1, z: 0 }));
    const up = cross(right, f);
    const h = near * Math.tan(fov / 2);
    const w = h * aspect;
    const centre = add(eye, scale(f, near));

    return [[-1, -1], [1, -1], [-1, 1], [1, 1]].map(([sx, sy]) => add(centre, add(scale(right, sx * w), scale(up, sy * h))));
}

// ── the limits ──────────────────────────────────────────────────────────────────────────────
const a = L.limits(extent, wide, character);
const b = L.limits(extent, tall, character);
const height = (SCENE_H * CHARACTER_SCALE) / UNIT;

printed.limits = { polar_max_deg: deg(a.polar.max), distance: a.distance, near: a.near, far: a.far, fit_wide: a.distance.max / L.MAX_FIT_SHARE, fit_tall: b.distance.max / L.MAX_FIT_SHARE };

if (!(a.polar.min === 0 && a.polar.max < Math.PI / 2)) {
    legs.limits.push(`the polar range is ${deg(a.polar.min)}°–${deg(a.polar.max)}° — from straight down to under 90°`);
}

if (!(a.distance.min >= height - 1e-9)) {
    legs.limits.push(`the minimum distance ${a.distance.min} is under one character's height, ${height}`);
}

if (a.near !== L.NEAR || !(a.far > a.distance.max)) {
    legs.limits.push(`the near plane is ${a.near} (the module's NEAR ${L.NEAR}) and the far ${a.far} — not past the farthest the eye stands`);
}

// The eye's bound: the floor's sides and back drawn in by the near plane's reach, the front out to the dolly limit; the
// target's height from the floor to the room's.
const bound = a.bound;
const reach = Math.max(...[0, Math.PI / 3, Math.PI / 2].flatMap((tilt) => [0, 1, 2, 3].map((q) => {
    const eye = { x: 0, y: 0, z: 0 };
    const look = { x: Math.sin(tilt) * Math.sin(q), y: -Math.cos(tilt), z: -Math.sin(tilt) * Math.cos(q) };

    return Math.max(...corners(eye, look, a.near, a.fov, wide.width / wide.height).map((c) => Math.hypot(c.x, c.y, c.z)));
})));

printed.bound = { gap: bound.gap, reach };

if (!(bound.gap >= reach - 1e-12) || !(bound.gap >= L.NEAR)) {
    legs.limits.push(`the gap ${bound.gap} is short of the near plane's reach ${reach} — a wall the eye stands at would be cut`);
}

const want = { min: { x: -extent.w / 2 + bound.gap, z: -extent.d / 2 + bound.gap }, max: { x: extent.w / 2 - bound.gap, z: extent.d / 2 + a.distance.max } };

if (JSON.stringify(bound.eye) !== JSON.stringify(want) || bound.target.min.y !== 0 || bound.target.max.y !== ROOM_H_U || bound.distance !== a.distance) {
    legs.limits.push(`the bound is ${JSON.stringify(bound)} — not the floor's sides and back by the gap, the front to the dolly limit, the target's height 0 to the room's, the distance to the dolly limit`);
}

// The far plane is past every point of the room from every corner of the eye's box, at the farthest the eye stands above a
// target at the room's height.
const farthest = Math.max(...[want.min.x, want.max.x].flatMap((x) => [want.min.z, want.max.z].flatMap((z) => [-extent.w / 2, extent.w / 2].flatMap((rx) => [-extent.d / 2, extent.d / 2].map((rz) => Math.hypot(x - rx, ROOM_H_U + a.distance.max, z - rz))))));

if (!(a.far >= farthest - 1e-9)) {
    legs.limits.push(`the far plane ${a.far} is short of the room's farthest point from the eye's box, ${farthest}`);
}

// The fit follows the stage: a tall stage's fit is farther than a wide one's.
if (!(b.distance.max > a.distance.max)) {
    legs.limits.push(`a tall stage's farthest distance ${b.distance.max} is not past a wide one's ${a.distance.max} — the fit ignores the stage's aspect`);
}

// The entry: the extent's centre from the front at the home tilt, at the fit.
const e = a.entry;
const rel = sub(e.position, e.target);
const polar = deg(Math.acos(rel.y / Math.hypot(rel.x, rel.y, rel.z)));

printed.entry_polar_deg = polar;

if (Math.abs(polar - 50) > 0.5 || Math.abs(rel.x) > 1e-9 || !(rel.z > 0) || e.target.x !== 0 || e.target.y !== 0 || e.target.z !== 0) {
    legs.limits.push(`the entry stands at ${polar}° from vertical, ${JSON.stringify(rel)} from ${JSON.stringify(e.target)} — not the centre from the front at 50°`);
}

// ── never below the floor: the eye and the near plane's corners, over a grid ────────────────
let lowest = Infinity;
let samples = 0;

for (let i = 0; i <= 8; i++) {
    for (let k = 0; k <= 4; k++) {
        const target = { x: -extent.w / 2 + (i / 8) * extent.w, y: 0, z: -extent.d / 2 + (k / 4) * extent.d };

        for (let p = 0; p <= 22; p++) {
            const polarAt = (p / 22) * a.polar.max;

            for (let q = 0; q < 8; q++) {
                const eye = eyeAt(target, polarAt, (q / 8) * 2 * Math.PI, a.distance.min);
                const ys = [eye.y, ...corners(eye, target, a.near, a.fov, wide.width / wide.height).map((c) => c.y)];
                const min = Math.min(...ys);

                samples++;
                lowest = Math.min(lowest, min);

                if (!(min > 0) && legs.above.length < 5) {
                    legs.above.push(`at target ${JSON.stringify(target)}, polar ${deg(polarAt).toFixed(1)}°, distance ${a.distance.min}: the eye or a near-plane corner is at y ${min}`);
                }
            }
        }
    }
}

printed.above = { samples, lowest };

// ── the cut-away: exactly the walls the segment meets ────────────────────────────────────
const world = {
    walls: [{ id: 'on', box: { x: -0.5, y: 0, z: 1, w: 1, h: ROOM_H_U, d: 0.08 } }, { id: 'off', box: { x: 5, y: 0, z: 1, w: 1, h: ROOM_H_U, d: 0.08 } }],
    band: { x: -extent.w / 2, w: extent.w, z: -extent.d / 2, h: ROOM_H_U },
};
const cut = (eye, target) => [...L.cutaway(eye, target, world)].sort();
const front = cut({ x: 0, y: 1, z: 4 }, { x: 0, y: 0, z: -1 });

if (JSON.stringify(front) !== JSON.stringify(['on'])) {
    legs.cutaway.push(`a segment through the wall at z 1 cuts [${front}] — not that wall alone`);
}

// A target on a wall's face, seen from that side: the wall is at the target, not between, and stands.
if (cut({ x: 0, y: 1, z: 4 }, { x: 0, y: 0, z: 1.08 }).length !== 0) {
    legs.cutaway.push('a target on the front face of the wall at z 1, seen from in front, cuts it — the wall is at the target, not between');
}

// An eye standing inside a wall lowers it, wherever it looks.
const within = cut({ x: 0, y: 0.5, z: 1.04 }, { x: 0, y: 0, z: 3 });

if (JSON.stringify(within) !== JSON.stringify(['on'])) {
    legs.cutaway.push(`an eye inside the wall at z 1 cuts [${within}] — not that wall`);
}

// A target past the band's face, the eye in front of it: the band is never named.
if (cut({ x: 0, y: 1, z: -extent.d / 2 + 0.5 }, { x: 0, y: 0, z: -extent.d / 2 - 3 }).includes('band')) {
    legs.cutaway.push('a target past the band\'s face, seen from in front of it, cuts the band');
}

// A RAISED target (Page Up, Alt + a drag: the target and the eye lifted by one vector) is a target like any other: the
// segment from the eye still lowers what stands between. Looking nearly level at half the room's height, the full-height
// wall between cuts; a low wall the same segment passes over stands, and cuts again once the pair is lowered to the
// floor.
const level = (target) => eyeAt(target, (85 * Math.PI) / 180, 0, 5);
const raised = { x: 0, y: ROOM_H_U / 2, z: -1 };
const floored = { ...raised, y: 0 };
const low = { walls: [{ id: 'low', box: { x: -0.5, y: 0, z: 1, w: 1, h: 0.5, d: 0.08 } }], band: null };

if (JSON.stringify(cut(level(raised), raised)) !== JSON.stringify(['on'])) {
    legs.cutaway.push(`a raised target seen level through the wall at z 1 cuts [${cut(level(raised), raised)}] — not that wall alone`);
}

if (L.cutaway(level(raised), raised, low).size !== 0) {
    legs.cutaway.push('a raised eye looking over a low wall cuts it — the segment passes above it');
}

if (!L.cutaway(level(floored), floored, low).has('low')) {
    legs.cutaway.push('CONTROL: the same eye lowered to the floor does not cut the low wall it now looks through');
}

// ── the confinement, through the vendored controls ───────────────────────────────────────────
/** The-burrow's floor below its band, in units (the sandbox's), on the 1,280 × 579 stage the defect was seen on. */
const burrow = { w: 20.24, d: 8.16 };
const stage = { width: 1280, height: 579 };
const EPS = 1e-9;

/**
 * A camera and its controls made as `floor3d/floor-paint.js` makes them — `configureControls()`, the damping the page's
 * reduced motion — at the entry pose, with `frame()` the renderer's frame (an update, then the clamp) and `acts` the
 * keys' acts over the same controls and clamp.
 */
function rig(over, { damping = true } = {}) {
    const view = L.limits(over, stage, character);
    const camera = new PerspectiveCamera(45, stage.width / stage.height, 0.05, 1000);
    const controls = new OrbitControls(camera);

    controls.domElement = { clientWidth: stage.width, clientHeight: stage.height };
    controls.enableDamping = damping;
    L.configureControls(controls, view);

    const { position, target } = view.entry;

    controls.target.set(target.x, target.y, target.z);
    camera.position.set(position.x, position.y, position.z);
    controls.update();

    let pose = { eye: position, target };
    const clamp = () => {
        pose = L.confineControls(controls, view.bound, pose);
    };
    const acts = cameraActs(controls, { surface: () => stage, entry: () => view.entry, clamp, bounds: () => null });
    const eye = () => camera.position;
    /** Where the eye is out of the bound, or does not look at the target; `null` where neither. */
    const out = () => {
        const e = eye();
        const { min, max } = view.bound.eye;
        const looks = new Vector3(0, 0, -1).applyQuaternion(camera.quaternion);
        const wants = controls.target.clone().sub(e).normalize();

        if (e.x < min.x - EPS || e.x > max.x + EPS || e.z < min.z - EPS || e.z > max.z + EPS) {
            return `the eye at ${[e.x, e.y, e.z].map((v) => v.toFixed(3))} past the bound`;
        }

        return looks.distanceTo(wants) > 1e-6 ? `the eye at ${[e.x, e.y, e.z].map((v) => v.toFixed(3))} looking ${looks.distanceTo(wants).toFixed(4)} off the target` : null;
    };

    return {
        view, camera, controls, acts, clamp, eye, out,
        frame: () => {
            controls.update();
            clamp();
        },
    };
}

/**
 * Run `step` `n` times, a frame after each and `rest` frames at the end, `after` called after each frame; the first
 * bound broken, or `null` — the run goes on to its end either way, so the pose after it is the walk's.
 */
function drive(r, n, step, rest = 400, after = () => {}) {
    let first = null;

    for (let k = 0; k < n + rest; k++) {
        if (k < n) {
            step(k);
        }

        r.frame();
        after();

        const o = r.out();

        first ??= o === null ? null : `${o} at frame ${k}`;
    }

    return first;
}

for (const damping of [true, false]) {
    const tag = damping ? 'damped' : 'with no damping';

    // RULE A — a right-drag walks: the eye and the target move together, the eye's height, its pitch and the way it
    // faces kept, until the eye meets the back wall, then along it to each side. From the high opening view, and from
    // an eye at desk level (brought in, tilted to level, then raised three Page Up steps off the floor).
    const lowered = (r) => {
        r.acts.zoomStep(12);
        r.acts.drag(0, -2 * stage.width);
        r.acts.riseStep(3);
        drive(r, 0, () => {}, 400);
    };

    for (const [from, prepare] of [['from the opening view', () => {}], ['from an eye at desk level', lowered]]) {
        const r = rig(burrow, { damping });

        prepare(r);

        const start = { y: r.eye().y, polar: r.controls.getPolarAngle(), azimuth: r.controls.getAzimuthalAngle(), distance: r.controls.getDistance() };
        const kept = (what) => {
            const now = { y: r.eye().y, polar: r.controls.getPolarAngle(), azimuth: r.controls.getAzimuthalAngle(), distance: r.controls.getDistance() };

            if (Object.keys(start).some((k) => Math.abs(now[k] - start[k]) > 1e-6)) {
                legs.move.push(`${tag}, ${what} ${from} changed the walk: ${JSON.stringify(start)} → ${JSON.stringify(now)} — the eye's height, pitch, facing and distance are kept`);
            }
        };
        const broken = drive(r, 160, () => r.controls.pan(0, 40));
        const e = r.eye();

        printed[`move_${damping}_${from.split(' ')[1]}`] = { start, end: [e.x, e.y, e.z] };

        if (from !== 'from the opening view' && !(start.y < ROOM_H_U)) {
            legs.move.push(`CONTROL: the desk-level eye stands at y ${start.y.toFixed(3)}, above the room's height`);
        }

        if (broken !== null) {
            legs.move.push(`${tag}, a pan forward ${from} left ${broken}`);
        }

        if (!(e.z < burrow.d / 2) || Math.abs(e.z - r.view.bound.eye.min.z) > 1e-6) {
            legs.move.push(`${tag}, a long pan forward ${from} left the eye at z ${e.z.toFixed(3)} — not in the office at the back wall (${r.view.bound.eye.min.z.toFixed(3)})`);
        }

        kept('a pan forward');

        // Then across, each way: along the wall to each side.
        for (const [dx, side] of [[40, 'min'], [-40, 'max']]) {
            const across = drive(r, 160, () => r.controls.pan(dx, 0));

            if (across !== null) {
                legs.move.push(`${tag}, a pan across ${from} left ${across}`);
            }

            if (Math.abs(r.eye().x - r.view.bound.eye[side].x) > 1e-6 || Math.abs(r.eye().z - r.view.bound.eye.min.z) > 1e-6) {
                legs.move.push(`${tag}, a pan across the back wall ${from} left the eye at ${[r.eye().x, r.eye().z].map((v) => v.toFixed(3))} — not in the corner`);
            }

            kept(`a pan across to the ${side} side`);
        }
    }

    // Shift + an arrow, through the acts: the same, and Fit from where the bound held it is the entry.
    {
        const r = rig(burrow, { damping });
        const broken = drive(r, 300, () => r.acts.drag(0, PAN_STEP_PX, { shift: true }));

        if (broken !== null) {
            legs.keys.push(`${tag}, Shift + ArrowUp left ${broken}`);
        }

        if (Math.abs(r.eye().z - r.view.bound.eye.min.z) > 1e-6) {
            legs.keys.push(`${tag}, Shift + ArrowUp held left the eye at z ${r.eye().z.toFixed(3)} — not at the back wall`);
        }

        const side = drive(r, 300, () => r.acts.drag(-PAN_STEP_PX, 0, { shift: true }));

        if (side !== null || Math.abs(r.eye().x - r.view.bound.eye.max.x) > 1e-6) {
            legs.keys.push(`${tag}, Shift + ArrowRight held: ${side ?? `the eye at x ${r.eye().x.toFixed(3)}, not at the side`}`);
        }

        // A turn there pulls the eye in; Fit returns to the entry exactly.
        drive(r, 60, () => r.acts.drag(40, 0));
        r.acts.fit();
        drive(r, 0, () => {});

        const { position, target } = r.view.entry;
        const off = Math.max(...['x', 'y', 'z'].flatMap((k) => [Math.abs(r.eye()[k] - position[k]), Math.abs(r.controls.target[k] - target[k])]));

        if (off > 1e-6) {
            legs.keys.push(`${tag}, Fit from a pose the bound held left the camera ${off.toFixed(4)} from the entry`);
        }
    }

    // A full turn and the wheel, from the entry and from inside the office: the target stays, the eye in the bound.
    for (const inside of [false, true]) {
        const r = rig(burrow, { damping });
        const where = inside ? 'from inside the office' : 'from the entry';

        if (inside) {
            drive(r, 60, () => r.controls.pan(0, 40));
        }

        const t0 = r.controls.target.clone();
        let nearest = Infinity;
        let farthest = 0;
        const turn = drive(r, 360, () => {
            r.controls.rotateLeft(Math.PI / 180);
            nearest = Math.min(nearest, r.controls.getDistance());
            farthest = Math.max(farthest, r.controls.getDistance());
        });

        if (turn !== null) {
            legs.turn.push(`${tag}, a turn ${where} left ${turn}`);
        }

        const wheel = drive(r, 40, (k) => (k < 20 ? r.controls.dollyIn(0.8) : r.controls.dollyOut(0.8)));

        if (wheel !== null) {
            legs.turn.push(`${tag}, the wheel ${where} left ${wheel}`);
        }

        if (r.controls.target.distanceTo(t0) > 1e-9) {
            legs.turn.push(`${tag}, a turn and the wheel ${where} moved the target ${r.controls.target.distanceTo(t0)} — the bound slid the pair`);
        }

        if (!inside && !(nearest < r.view.distance.max / L.MAX_FIT_SHARE - 1)) {
            legs.turn.push(`${tag}, a turn at the fit was never brought in (nearest ${nearest.toFixed(2)}) — CONTROL: the bound was not met`);
        }

        printed[`turn_${damping}_${inside}`] = { nearest, farthest };
    }
}

// A turn at a wall with the eye close in and level: every drawn frame no nearer the target than the dolly minimum, and
// the near plane's corners above the floor — the header's proof holds only from the minimum distance out. The eye is
// brought to the dolly minimum, tilted to level, walked to within `gap` of a side wall (and of the back wall, and into
// the corner), then turned a full circle each way.
for (const damping of [true, false]) {
    for (const [where, dx, dy] of [['a side wall', -40, 0], ['the other side wall', 40, 0], ['the back wall', 0, 40], ['a corner', 40, 40]]) {
        // the eye at the wall, then stood off it by each of these, units — a step the camera's own walk makes
        for (const off of [0, 0.15, 0.3, 0.6]) {
            const r = rig(burrow, { damping });
            let worst = { d: Infinity, corner: Infinity };

            r.acts.zoomStep(40);
            r.acts.drag(0, -2 * stage.width);
            drive(r, 0, () => {}, 200);
            drive(r, 400, () => r.controls.pan(dx, dy), 200);

            // the pair stood back off the wall by `off`, into the room (a pan of `dx`, `dy` walks toward `-dx`, `-dy`)
            const back = { x: Math.sign(dx) * off, z: Math.sign(dy) * off };

            r.controls.target.set(r.controls.target.x + back.x, r.controls.target.y, r.controls.target.z + back.z);
            r.camera.position.set(r.camera.position.x + back.x, r.camera.position.y, r.camera.position.z + back.z);
            drive(r, 0, () => {}, 50);

            const sample = () => {
                const e = r.eye();
                const t = r.controls.target;
                const low = Math.min(...corners(e, t, r.view.near, r.view.fov, stage.width / stage.height).map((c) => c.y));

                worst = { d: Math.min(worst.d, r.controls.getDistance()), corner: Math.min(worst.corner, low) };
            };

            for (const turn of [Math.PI / 180, -Math.PI / 180]) {
                drive(r, 400, () => r.controls.rotateLeft(turn), 100, sample);
            }

            printed[`near_${damping}_${where}_${off}`] = worst;

            if (worst.d < r.view.distance.min - 1e-9 || !(worst.corner > 0)) {
                legs.near.push(`${damping ? 'damped' : 'with no damping'}, a turn ${off} from ${where} with the eye close in and level drew the eye ${worst.d.toFixed(3)} from the target (the minimum ${r.view.distance.min}) and the near plane's lowest corner at y ${worst.corner.toFixed(4)}`);
            }
        }
    }
}

// The damped remainder: one pan into the back wall, its remainder run out, then a pan back out.
{
    const r = rig(burrow);
    const free = rig(burrow);

    drive(free, 1, () => free.controls.pan(0, -100));

    const length = free.eye().z - free.view.entry.position.z;
    // Each frame's pose is where the clamp left it: a second clamp moves nothing (an update after the clamp pays the
    // remainder out again, and the frame draws that).
    let drift = 0;
    const held = drive(r, 1, () => r.controls.pan(0, 1200), 4000, () => {
        const was = [...r.eye().toArray(), ...r.controls.target.toArray()];

        r.clamp();
        drift = Math.max(drift, ...[...r.eye().toArray(), ...r.controls.target.toArray()].map((v, k) => Math.abs(v - was[k])));
    });

    printed.drift = drift;

    if (held !== null) {
        legs.damping.push(`a pan into the back wall left ${held} while its remainder ran out`);
    }

    if (drift > 1e-12) {
        legs.damping.push(`a clamp after a frame's clamp moved the camera ${drift.toFixed(4)} — the frame drew a pose the clamp had not held`);
    }

    drive(r, 3, () => r.controls.pan(0, 1200), 0);

    const before = r.eye().z;

    drive(r, 1, () => r.controls.pan(0, -100));

    const moved = r.eye().z - before;

    printed.damping = { length, moved };

    if (!(Math.abs(length) > 0.5) || !(moved > 0.9 * length)) {
        legs.damping.push(`a pan back out from the back wall moved the eye ${moved.toFixed(3)}, the same pan in the open ${length.toFixed(3)} — the remainder owed into the wall cancelled it`);
    }
}

// A pointer's pan at the wall wakes a frame: each move is the controls' own update, and their change event is the
// renderer's wake. A move that pushes the eye past the wall again from where the clamp held it is a change.
for (const damping of [true, false]) {
    const r = rig(burrow, { damping });
    let changes = 0;

    r.controls.addEventListener('change', () => changes++);
    drive(r, 120, () => r.controls.pan(0, 40), 400);

    for (let k = 0; k < 3; k++) {
        const was = changes;

        r.controls.pan(0, 40);

        if (changes === was) {
            legs.wake.push(`${damping ? 'damped' : 'with no damping'}, a pan into the back wall from where the clamp held the eye (move ${k + 1}) fired no change — no frame clamps it`);
        }

        r.frame();
    }
}

// The pure rule over seeded random poses.
{
    let seed = 13033;
    const random = () => {
        seed = (seed * 1103515245 + 12345) % 2147483648;

        return seed / 2147483648;
    };
    const v = burrow;
    const view = L.limits(v, stage, character);
    const { min, max } = view.bound.eye;
    const legal = (p, slack = EPS) => p.x >= min.x - slack && p.x <= max.x + slack && p.z >= min.z - slack && p.z <= max.z + slack;
    const somewhere = (spread) => ({ x: (random() - 0.5) * spread * v.w, y: (random() - 0.3) * 3, z: (random() - 0.5) * spread * v.d });
    /** An offset as the controls leave one: above the target, from the minimum distance to the dolly limit. */
    const offset = () => {
        const polarAt = random() * view.polar.max;
        const azimuth = random() * 2 * Math.PI;

        return eyeAt({ x: 0, y: 0, z: 0 }, polarAt, azimuth, view.distance.min + random() * (view.distance.max - view.distance.min));
    };
    let kept = 0;

    for (let k = 0; k < 4000 && legs.confine.length < 5; k++) {
        // The pose last held is one the rule answered.
        const t0 = somewhere(3);
        const prev = L.confine(add(t0, offset()), t0, { eye: add(t0, offset()), target: t0 }, view.bound);
        const target = random() < 0.5 ? prev.target : somewhere(3);
        const eye = random() < 0.5 ? add(target, offset()) : add(target, sub(prev.eye, prev.target));
        const got = L.confine(eye, target, prev, view.bound);

        const reach = Math.hypot(got.eye.x - got.target.x, got.eye.y - got.target.y, got.eye.z - got.target.z);
        const asked = Math.hypot(eye.x - target.x, eye.y - target.y, eye.z - target.z);

        if (!legal(got.eye) || got.target.y < 0 || got.target.y > ROOM_H_U || ![got.eye.x, got.eye.y, got.eye.z].every(Number.isFinite)
            || (reach > asked + EPS && reach > view.bound.distance.max + EPS)) {
            legs.confine.push(`from ${JSON.stringify({ eye, target, prev })} the rule answered ${JSON.stringify(got)}`);
        }

        if (legal(eye, 0) && target.y >= 0 && target.y <= ROOM_H_U) {
            kept++;

            if (['x', 'y', 'z'].some((a) => got.eye[a] !== eye[a] || got.target[a] !== target[a])) {
                legs.confine.push(`a legal pose ${JSON.stringify({ eye, target })} was moved to ${JSON.stringify(got)}`);
            }
        }
    }

    printed.confine = { kept };

    if (kept === 0) {
        legs.confine.push('CONTROL: no legal pose was drawn — the kept-as-is check measured nothing');
    }

    // The entry is legal on every extent, a narrow floor's included, on a wide and a tall stage.
    for (const over of [extent, burrow, { w: 2, d: 1 }, { w: 60, d: 20 }]) {
        for (const surface of [wide, tall, stage]) {
            const at = L.limits(over, surface, character);
            const { position, target } = at.entry;
            const got = L.confine(position, target, at.entry && { eye: position, target }, at.bound);

            if (['x', 'y', 'z'].some((a) => got.eye[a] !== position[a])) {
                legs.confine.push(`the entry on a ${over.w} × ${over.d} floor at ${surface.width} × ${surface.height} is out of the bound`);
            }
        }
    }
}

// ── the cab seen only through its doorway ───────────────────────────────────────────────────
// The truth, computed here: a point behind the face is seen where the segment from the eye to it crosses the face inside
// the opening. Every eye of a grid in front of the face and behind it, every point of a grid over a box behind the face
// wider and taller than the opening; a point within a hair of the pyramid's boundary is not judged.
{
    const door = { x: -0.42, y: 0, z: -3, w: 0.84, h: 1.44 };
    const kept = (planes, p) => planes.map(({ normal: n, constant }) => n.x * p.x + n.y * p.y + n.z * p.z + constant);
    const through = (eye, p) => {
        const t = (door.z - eye.z) / (p.z - eye.z);
        const x = eye.x + (p.x - eye.x) * t;
        const y = eye.y + (p.y - eye.y) * t;

        return { x, y, edge: Math.min(Math.abs(x - door.x), Math.abs(x - door.x - door.w), Math.abs(y - door.y), Math.abs(y - door.y - door.h)) };
    };
    const counts = { seen: 0, hidden: 0, behind: 0 };

    for (const ex of [-4, -0.5, 0, 0.3, 3]) {
        for (const ey of [0.2, 1, 2, 6]) {
            for (const ez of [-3.5, -2.9, -1, 2, 5]) {
                const eye = { x: ex, y: ey, z: ez };
                const planes = L.doorwayView(eye, door);

                if (ez <= door.z) {
                    counts.behind++;

                    if (planes !== null) {
                        legs.doorway.push(`an eye at ${JSON.stringify(eye)}, behind the face, is given planes — no line of sight from it crosses the opening`);
                    }

                    continue;
                }

                if (planes === null || planes.length !== 4) {
                    legs.doorway.push(`an eye at ${JSON.stringify(eye)}, in front of the face, is given ${JSON.stringify(planes)} — not four planes`);
                    continue;
                }

                // Three moves a clipping plane into the view by its point `−constant · normal`, true of a unit normal only.
                const long = planes.find(({ normal: n }) => Math.abs(Math.hypot(n.x, n.y, n.z) - 1) > 1e-9);

                if (long !== undefined) {
                    legs.doorway.push(`from ${JSON.stringify(eye)} a plane's normal ${JSON.stringify(long.normal)} is not of unit length`);
                }

                for (let px = -1.2; px <= 1.2; px += 0.15) {
                    for (let py = 0; py <= 2.2; py += 0.15) {
                        for (const pz of [-3.05, -3.5, -4]) {
                            const p = { x: px, y: py, z: pz };
                            const hit = through(eye, p);

                            if (hit.edge < 1e-3) {
                                continue;
                            }

                            const want = hit.x > door.x && hit.x < door.x + door.w && hit.y > door.y && hit.y < door.y + door.h;
                            const got = kept(planes, p).every((d) => d >= 0);

                            counts[want ? 'seen' : 'hidden']++;

                            if (got !== want) {
                                legs.doorway.push(`from ${JSON.stringify(eye)} the point ${JSON.stringify(p)} is ${got ? 'kept' : 'clipped'} — its line of sight crosses the face at (${hit.x.toFixed(3)}, ${hit.y.toFixed(3)}), ${want ? 'inside' : 'outside'} the opening`);
                            }
                        }
                    }
                }
            }
        }
    }

    printed.doorway = counts;

    if (counts.seen === 0 || counts.hidden === 0 || counts.behind === 0) {
        legs.doorway.push(`CONTROL: the grid judged no point seen, none hidden or no eye behind the face — ${JSON.stringify(counts)}`);
    }
}

process.stdout.write(JSON.stringify({ legs, printed }));

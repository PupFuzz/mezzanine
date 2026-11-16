/**
 * THE 3-D FLOOR's VIEW LIMITS, ITS CONFINEMENT AND ITS CUT-AWAY — what the camera's controls are configured with, where
 * the eye may stand, which walls stand between the eye and what it looks at, and what of the cab its doorway lets the
 * eye see. No DOM and no Three: the renderer (`floor3d/floor-paint.js`) configures `OrbitControls` from `limits()`
 * through `configureControls()`, holds the camera to `confine()` through `confineControls()` after every update,
 * lowers what `cutaway()` names and clips the cab to `doorwayView()`. `docs/design/plan-13033-stage2-3d-floor.md`
 * §§ 3.6–3.7; the figures are `docs/design/FLOOR.md` § 12's *Floor 3-D camera* and *Floor 3-D cut-away* rows. Driven
 * under `node` by `tests/Feature/Floor/view-limits-probe.mjs`, the controls' composition over the vendored library.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NEVER BELOW THE FLOOR — one guard, and the arithmetic that makes a second dead: the polar limit keeps the eye above
 * its TARGET's height, and the target's height is held at or above the plane, so the eye's `y` is
 * `target.y + d · cos(polar) ≥ d · cos(MAX_POLAR) > 0` for every `d > 0`. The near plane's bottom corners stand
 * `near · (cos θ + tan(fov/2) · sin θ)` under the eye at the polar limit θ, which at `NEAR` and `MIN_DISTANCE` leaves
 * them above the plane; at twice `NEAR` they would be under it (plan § 3.6, where the figures are worked).
 *
 * ⛔ THE EYE STAYS IN THE OFFICE (`FLOOR.md` § 4.5): never behind the back wall's face, never past either side of the
 * floor, each by `gap` — the farthest the near plane reaches from the eye, so no wall the eye stands at is cut by it.
 * The front is open to the dolly limit: the eye stands in front of the room, as at the entry, no farther out than the
 * farthest it may stand from its target, so a pan away from the room stops where the floor is still seen. A move that
 * would carry the eye out is resolved by what moved: the target's move slides the eye and the target back together,
 * so a pan stops at the wall and slides along it; a turn or a dolly about the target brings the eye along its line to
 * the target, never nearer it than the dolly minimum — the header's proof above needs that distance, so at a wall a
 * turn that would bring the eye nearer stops instead. The target is free across the floor and past it — at the
 * fit it lies far beyond an eye at the back wall — and only its height is held.
 *
 * ⛔ NOTHING IS A COLLIDER (plan § 3.7): a wall between the eye and the target is lowered, never collided with; a desk
 * or a character is never cut away. The band is never lowered: the eye never stands behind its face.
 */

import { FOV, HOME_POLAR, fitDistance, orbitCamera } from '../building/stack-model.js';
import { ROOM_H_U, UNIT } from '../floor/floor-world.js';
import { walkerSize } from '../floor/scene.js';

/** The eye's angle from vertical at its lowest — just above the floor plane (§ 12's *Floor 3-D camera*). */
export const MAX_POLAR = (88 * Math.PI) / 180;

/** The camera's near plane, world units — the largest that keeps its corners above the plane (the header's figures). */
export const NEAR = 0.05;

/** How far past the fit the eye may dolly: 1.5 × the fit distance (§ 12's *Floor 3-D camera*). */
export const MAX_FIT_SHARE = 1.5;

/** A wall the cut-away lowers stands this tall, world units, reached over `CUTAWAY_MS` (§ 12's *Floor 3-D cut-away*). */
export const CUTAWAY_H = 0.15;

export const CUTAWAY_MS = 150;

/**
 * The limits over a floor of `extent` world units, seen on a stage `surface` CSS px, by a viewer whose characters are
 * `character`'s frame (the scene's `input.character`).
 *
 * @param {{w: number, d: number}} extent the world's `extent`
 * @param {{width: number, height: number}} surface the stage
 * @param {{w: number, h: number}} character the character tree's frame, scene px before the desk's scale
 * @returns {object} `polar` (`min`, `max`), `distance` (`min`, `max`), `near`, `far`, `fov`, `bound` — `confine()`'s:
 *          the eye's (`eye.min` and `eye.max`, each `x` and `z`), the target's height (`target.min.y`, `target.max.y`),
 *          `gap` and `distance` (the farthest the eye stands from the target) — and `entry`, the camera
 *          `{ position, target, fov }` the floor opens at and *Fit* returns to
 */
export function limits(extent, surface, character) {
    const bounds = { w: extent.w, h: ROOM_H_U, d: extent.d };
    const aspect = surface.width / surface.height;
    const fit = fitDistance(bounds, FOV, aspect);
    const max = MAX_FIT_SHARE * fit;
    // The near plane's corner, the farthest point of it from the eye.
    const half = Math.tan(FOV / 2);
    const gap = NEAR * Math.hypot(1, half, half * aspect);
    // One character's height: closer than that the near plane cuts a head.
    const distance = Object.freeze({ min: walkerSize(character).h / UNIT, max });

    return Object.freeze({
        polar: Object.freeze({ min: 0, max: MAX_POLAR }),
        distance,
        near: NEAR,
        // The room's farthest point from the farthest corner of the eye's box: as far above the target as it stands, and
        // out in front to the dolly limit.
        far: Math.hypot(bounds.w, bounds.h + max, bounds.d + max),
        fov: FOV,
        bound: Object.freeze({
            eye: Object.freeze({
                min: Object.freeze({ x: -extent.w / 2 + gap, z: -extent.d / 2 + gap }),
                max: Object.freeze({ x: extent.w / 2 - gap, z: extent.d / 2 + max }),
            }),
            target: Object.freeze({ min: Object.freeze({ y: 0 }), max: Object.freeze({ y: ROOM_H_U }) }),
            gap,
            distance,
        }),
        // The extent's centre on the floor, from the front at stage 1's home tilt, at the fit — set from the spherical
        // figures, never by a library's fit (plan § 3.6, B2).
        entry: orbitCamera({ x: 0, y: 0, z: 0 }, HOME_POLAR, 0, fit),
    });
}

/**
 * The camera and its controls set from `view` (`limits()`): the camera's planes and field, the controls' polar and
 * distance limits, and the pan along the floor rather than the screen. One home for the renderer and the probe; the
 * damping is the page's (reduced motion), set where the controls are made.
 *
 * @param {object} controls `OrbitControls` (its `object` the perspective camera)
 * @param {object} view `limits()`'s answer
 */
export function configureControls(controls, view) {
    const camera = controls.object;

    camera.near = view.near;
    camera.far = view.far;
    camera.fov = (view.fov * 180) / Math.PI;
    camera.updateProjectionMatrix();
    controls.screenSpacePanning = false;
    controls.minPolarAngle = view.polar.min;
    controls.maxPolarAngle = view.polar.max;
    controls.minDistance = view.distance.min;
    controls.maxDistance = view.distance.max;
}

const add = (a, b) => ({ x: a.x + b.x, y: a.y + b.y, z: a.z + b.z });
const sub = (a, b) => ({ x: a.x - b.x, y: a.y - b.y, z: a.z - b.z });
const scale = (a, k) => ({ x: a.x * k, y: a.y * k, z: a.z * k });
const clampTo = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

/** The eye's bounded axes, each `[axis, min, max]`. */
const eyeAxes = (bound) => ['x', 'z'].map((axis) => [axis, bound.eye.min[axis], bound.eye.max[axis]]);

/**
 * THE CONFINEMENT (the header's rule): the camera `eye` and `target` after an update, held to `bound`, given the pose
 * `prev` it was last held at. A legal pose is returned as it is. Otherwise the move is taken in two parts. The target's
 * move first, carrying `prev`'s offset: where that eye is out, the target and the eye slide back by its overshoot on
 * each axis it is out on (`slid`). Then the offset's change, about that target: the eye on its line from the target at
 * the legal distance nearest the update's, from the dolly minimum to its limit (`distance`) — nearer in where the
 * update stood it out past a side, out to the back wall where it stood it behind (the target past the wall) — or,
 * where that line has no legal point in that range, at `prev`'s offset, which the slide left legal: so a turn whose
 * legal stretch of line lies nearer than the minimum stops, as one with none does.
 *
 * @param {{x: number, y: number, z: number}} eye
 * @param {{x: number, y: number, z: number}} target
 * @param {{eye: object, target: object}} prev the pose last returned (or the entry)
 * @param {object} bound `limits().bound`
 * @returns {{eye: object, target: object, slid: {x: boolean, z: boolean}}}
 */
export function confine(eye, target, prev, bound) {
    const axes = eyeAxes(bound);
    const inside = (p) => axes.every(([axis, lo, hi]) => p[axis] >= lo && p[axis] <= hi);
    const t = { x: target.x, y: clampTo(target.y, bound.target.min.y, bound.target.max.y), z: target.z };
    // The height's clamp lifts the eye with the target; the eye's `x` and `z` are untouched by it.
    const lifted = { x: eye.x, y: eye.y + (t.y - target.y), z: eye.z };

    if (inside(lifted)) {
        return { eye: lifted, target: t, slid: { x: false, z: false } };
    }

    const offset = sub(eye, target);
    const before = sub(prev.eye, prev.target);
    const carried = add(t, before);
    const slide = { x: 0, y: 0, z: 0 };

    for (const [axis, lo, hi] of axes) {
        slide[axis] = clampTo(carried[axis], lo, hi) - carried[axis];
    }

    const held = add(t, slide);
    const d = Math.hypot(offset.x, offset.y, offset.z);
    const u = scale(offset, 1 / d);
    let near = Math.max(bound.gap, bound.distance.min);
    let far = bound.distance.max;

    for (const [axis, lo, hi] of axes) {
        // A line square to an axis (the entry's and Fit's, at azimuth 0 exactly) never crosses its bounds: it is
        // legal all along or nowhere.
        if (u[axis] === 0) {
            if (held[axis] < lo || held[axis] > hi) {
                far = -Infinity;
            }

            continue;
        }

        const [a, b] = [(lo - held[axis]) / u[axis], (hi - held[axis]) / u[axis]];

        near = Math.max(near, Math.min(a, b));
        far = Math.min(far, Math.max(a, b));
    }

    return {
        eye: near <= far ? add(held, scale(u, clampTo(d, near, far))) : add(held, before),
        target: held,
        slid: { x: slide.x !== 0, z: slide.z !== 0 },
    };
}

/**
 * `confine()` written into `controls` (`OrbitControls`, or a stub with its members): the target and the camera set to
 * the held pose and the camera turned to the target. The damped pan still owed on a slid axis is dropped — the next
 * update would otherwise pay it out against the wall again, and a pan back out would first have to cancel it. No
 * update follows: an update pays out the damped pan and turn still owed, and the frame would draw a pose this has not
 * held — the back wall's drop, when the target was what was held.
 *
 * @param {object} controls `target`, `object` (its `position`, `quaternion` and `lookAt()`) and the library's
 *        `_panOffset` and its last pose (`_lastPosition`, `_lastQuaternion`, `_lastTargetPosition`) — private members
 *        of the vendored release, which `view-limits-probe.mjs` drives
 * @param {object} bound `limits().bound`
 * @param {{eye: object, target: object}} prev the pose last returned (or the entry)
 * @returns {{eye: object, target: object}} the pose held — the next call's `prev`
 */
export function confineControls(controls, bound, prev) {
    const { target, object: camera } = controls;
    const next = confine(camera.position, target, prev, bound);

    for (const axis of ['x', 'z']) {
        if (next.slid[axis]) {
            controls._panOffset[axis] = 0;
        }
    }

    target.set(next.target.x, next.target.y, next.target.z);
    camera.position.set(next.eye.x, next.eye.y, next.eye.z);
    camera.lookAt(target);
    // The controls tell a change from their own last pose: it is the held one now. Left as the pose their update
    // computed, the next pointer move that computes it again reads as no change, wakes no frame, and the camera rests
    // where no frame drew it — past the wall.
    controls._lastPosition.copy(camera.position);
    controls._lastQuaternion.copy(camera.quaternion);
    controls._lastTargetPosition.copy(target);

    return { eye: next.eye, target: next.target };
}

/**
 * Where the segment from `a` to `b` first enters `box` (`{x, y, z, w, h, d}`, min corner and size), as a share of the
 * segment, or `null` where it does not meet it before `b` — the slab test. A box the segment only touches AT `b`
 * stands at the target, not between the eye and it.
 */
function meets(a, b, box) {
    let t0 = 0;
    let t1 = 1;

    for (const [axis, size] of [['x', 'w'], ['y', 'h'], ['z', 'd']]) {
        const from = a[axis];
        const step = b[axis] - from;
        const lo = box[axis];
        const hi = box[axis] + box[size];

        if (step === 0) {
            if (from < lo || from > hi) {
                return null;
            }

            continue;
        }

        const ta = (lo - from) / step;
        const tb = (hi - from) / step;

        t0 = Math.max(t0, Math.min(ta, tb));
        t1 = Math.min(t1, Math.max(ta, tb));

        if (t0 > t1) {
            return null;
        }
    }

    return t0 < 1 ? t0 : null;
}

/**
 * THE CUT-AWAY (plan § 3.7): the ids of every wall the segment from the eye to the target meets — a wall standing on its
 * own floor box; an eye inside one meets it. The band is not among them (the header's rule): a target past its face
 * is the orbit's centre, not a thing seen through it.
 *
 * @param {{x: number, y: number, z: number}} eye
 * @param {{x: number, y: number, z: number}} target
 * @param {{walls: list<{id: string, box: object}>}} world `floorWorld()`'s
 * @returns {Set<string>}
 */
export function cutaway(eye, target, world) {
    return new Set(world.walls.filter((wall) => meets(eye, target, wall.box) !== null).map((wall) => wall.id));
}

/**
 * THE CAB SEEN ONLY THROUGH ITS DOORWAY — the 3-D cab clipped as the 2-D band cab is clipped to its doorway
 * (`cab-paint.js`'s `clippedTo()`): a point behind the band's face is seen from `eye` only where the line of sight to it
 * crosses the doorway's opening (`door`: its min corner `x`, `y`, its size `w`, `h`, on the face at `z`, the room toward
 * `+z`). Answers the four planes of that pyramid — each `{normal, constant}`, `normal` of unit length, a point `p`
 * inside every one where `normal · p + constant ≥ 0`, the sense the renderer's clipping planes keep — or `null` for an
 * eye at or behind the face, from where no line of sight to a point behind it crosses the opening, so nothing of the cab
 * is seen. That branch is the function's domain guard: on the page `confine()` holds the eye in front of the face, so
 * the renderer never reaches it.
 *
 * @param {{x: number, y: number, z: number}} eye
 * @param {{x: number, y: number, z: number, w: number, h: number}} door
 * @returns {list<{normal: {x: number, y: number, z: number}, constant: number}>|null}
 */
export function doorwayView(eye, door) {
    if (eye.z <= door.z) {
        return null;
    }

    const corner = (x, y) => ({ x: x - eye.x, y: y - eye.y, z: door.z - eye.z });
    const rim = [corner(door.x, door.y), corner(door.x + door.w, door.y), corner(door.x + door.w, door.y + door.h), corner(door.x, door.y + door.h)];
    const centre = corner(door.x + door.w / 2, door.y + door.h / 2);

    return rim.map((a, k) => {
        const b = rim[(k + 1) % 4];
        const n = { x: a.y * b.z - a.z * b.y, y: a.z * b.x - a.x * b.z, z: a.x * b.y - a.y * b.x };
        // Turned so the opening's centre, inside the pyramid, is on its kept side, and of unit length — a plane's normal as
        // Three moves it into the view (`Plane.applyMatrix4()` takes its point on the plane as `−constant · normal`).
        const sense = n.x * centre.x + n.y * centre.y + n.z * centre.z < 0 ? -1 : 1;
        const unit = sense / Math.hypot(n.x, n.y, n.z);
        const normal = { x: n.x * unit, y: n.y * unit, z: n.z * unit };

        return { normal, constant: -(normal.x * eye.x + normal.y * eye.y + normal.z * eye.z) };
    });
}

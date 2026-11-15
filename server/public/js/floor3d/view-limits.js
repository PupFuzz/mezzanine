/**
 * THE 3-D FLOOR's VIEW LIMITS AND ITS CUT-AWAY — what the camera's controls are configured with, which walls stand
 * between the eye and what it looks at, and what of the cab its doorway lets the eye see. Pure, no DOM and no Three: the
 * renderer (`floor3d/floor-paint.js`, slice 4) configures `OrbitControls` from `limits()`, lowers what `cutaway()` names
 * and clips the cab to `doorwayView()`. `docs/design/plan-13033-stage2-3d-floor.md`
 * §§ 3.6–3.7 (card#13033 stage 2 slice 2); the figures are `docs/design/FLOOR.md` § 12's *Floor 3-D camera* and *Floor
 * 3-D cut-away* rows. Driven under `node` by `tests/Feature/Floor/view-limits-probe.mjs`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NEVER BELOW THE FLOOR — one guard, and the arithmetic that makes a second dead: the polar limit keeps the eye above
 * its TARGET's height, and the target's box keeps the target at or above the plane, so the eye's `y` is
 * `target.y + d · cos(polar) ≥ d · cos(MAX_POLAR) > 0` for every `d > 0`. The near plane's bottom corners stand
 * `near · (cos θ + tan(fov/2) · sin θ)` under the eye at the polar limit θ, which at `NEAR` and `MIN_DISTANCE` leaves
 * them above the plane; at twice `NEAR` they would be under it (plan § 3.6, where the figures are worked).
 *
 * ⛔ NOTHING IS A COLLIDER (plan § 3.7): walls and the band between the eye and the target are lowered, never collided
 * with; a desk or a character is never cut away.
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

/** How thick the band is stood for the cut-away's test, world units, behind its face — a hundredth of a unit. */
const BAND_D = 0.01;

/**
 * The limits over a floor of `extent` world units, seen on a stage `surface` CSS px, by a viewer whose characters are
 * `character`'s frame (the scene's `input.character`).
 *
 * @param {{w: number, d: number}} extent the world's `extent`
 * @param {{width: number, height: number}} surface the stage
 * @param {{w: number, h: number}} character the character tree's frame, scene px before the desk's scale
 * @returns {object} `polar` (`min`, `max`), `distance` (`min`, `max`), `near`, `far`, `fov`, `box` (`min`, `max` — the
 *          target's), and `entry` — the camera `{ position, target, fov }` the floor opens at and *Fit* returns to
 */
export function limits(extent, surface, character) {
    const bounds = { w: extent.w, h: ROOM_H_U, d: extent.d };
    const fit = fitDistance(bounds, FOV, surface.width / surface.height);
    const max = MAX_FIT_SHARE * fit;

    return Object.freeze({
        polar: Object.freeze({ min: 0, max: MAX_POLAR }),
        // One character's height: closer than that the near plane cuts a head.
        distance: Object.freeze({ min: walkerSize(character).h / UNIT, max }),
        near: NEAR,
        // Past the farthest the eye may stand by the farthest point of the box from a target inside it.
        far: max + Math.hypot(bounds.w, bounds.h, bounds.d),
        fov: FOV,
        box: Object.freeze({
            min: Object.freeze({ x: -extent.w / 2, y: 0, z: -extent.d / 2 }),
            max: Object.freeze({ x: extent.w / 2, y: ROOM_H_U, z: extent.d / 2 }),
        }),
        // The extent's centre on the floor, from the front at stage 1's home tilt, at the fit — set from the spherical
        // figures, never by a library's fit (plan § 3.6, B2).
        entry: orbitCamera({ x: 0, y: 0, z: 0 }, HOME_POLAR, 0, fit),
    });
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
 * THE CUT-AWAY (plan § 3.7): the ids of every wall the segment from the eye to the target meets, the band (`'band'`)
 * among them — a wall standing on its own floor box, the band a quad on the floor's back edge `ROOM_H_U` tall.
 *
 * @param {{x: number, y: number, z: number}} eye
 * @param {{x: number, y: number, z: number}} target
 * @param {{walls: list<{id: string, box: object}>, band: object|null}} world `floorWorld()`'s
 * @returns {Set<string>}
 */
export function cutaway(eye, target, world) {
    const bodies = [
        ...world.walls,
        // The band is a face on the floor's back edge with the room in front of it: stood here as a thin wall BEHIND that
        // face, so an eye behind the room passes through it to a target on its line, and one in front only touches it.
        ...(world.band === null ? [] : [{ id: 'band', box: { x: world.band.x, y: 0, z: world.band.z - BAND_D, w: world.band.w, h: world.band.h, d: BAND_D } }]),
    ];

    return new Set(bodies.filter((body) => meets(eye, target, body.box) !== null).map((body) => body.id));
}

/**
 * THE CAB SEEN ONLY THROUGH ITS DOORWAY — the 3-D cab clipped as the 2-D band cab is clipped to its doorway
 * (`cab-paint.js`'s `clippedTo()`): a point behind the band's face is seen from `eye` only where the line of sight to it
 * crosses the doorway's opening (`door`: its min corner `x`, `y`, its size `w`, `h`, on the face at `z`, the room toward
 * `+z`). Answers the four planes of that pyramid — each `{normal, constant}`, `normal` of unit length, a point `p`
 * inside every one where `normal · p + constant ≥ 0`, the sense the renderer's clipping planes keep — or `null` for an
 * eye at or behind the face, from where no line of sight to a point behind it crosses the opening, so nothing of the cab
 * is seen.
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

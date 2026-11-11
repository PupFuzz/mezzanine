/**
 * THE 3-D FLOOR's VIEW LIMITS AND ITS CUT-AWAY — what the camera's controls are configured with, and which walls stand
 * between the eye and what it looks at. Pure, no DOM and no Three: the renderer (`floor3d/floor-paint.js`, slice 4)
 * configures `OrbitControls` from `limits()` and lowers what `cutaway()` names. `docs/design/plan-13033-stage2-3d-floor.md`
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
 * segment, or `null` where it does not meet it — the slab test, a zero-thickness box (the band) included.
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

    return t0;
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
        ...(world.band === null ? [] : [{ id: 'band', box: { x: world.band.x, y: 0, z: world.band.z, w: world.band.w, h: world.band.h, d: 0 } }]),
    ];

    return new Set(bodies.filter((body) => meets(eye, target, body.box) !== null).map((body) => body.id));
}

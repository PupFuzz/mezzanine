/**
 * THE 3-D FLOOR's CAMERA FRAME — a perspective camera on an orbit about its target, the model's own projection of a world
 * point onto the stage, and the two distances a framing is found at: the bounds' bounding-sphere fit and the least
 * distance at which a set of corners projects inside the stage less an inset. No DOM, no Three, no clock, so `node`
 * drives it (`tests/Feature/Floor/view-limits-probe.mjs`); `floor3d/view-limits.js` builds the floor's limits from it.
 *
 * ⛔ ONE WORLD: `y` is up and a world unit is `floor/floor-world.js`'s `UNIT` scene px. A CAMERA HERE IS
 * `{ position, target, fov }` — aimed at `target` with `y` up and a vertical field of view `fov` (radians), over a stage
 * `{ width, height }` in CSS px.
 */

/** The camera's vertical field of view. */
export const FOV = (45 * Math.PI) / 180;

/** The entry camera's angle from vertical. */
export const HOME_POLAR = (50 * Math.PI) / 180;

/** How many times the framing search may double its distance before it is a defect, never a loop (`framingDistance()`). */
const FOCUS_SEARCH_BOUND = 64;

const sub = (a, b) => ({ x: a.x - b.x, y: a.y - b.y, z: a.z - b.z });
const dot = (a, b) => a.x * b.x + a.y * b.y + a.z * b.z;
const cross = (a, b) => ({ x: a.y * b.z - a.z * b.y, y: a.z * b.x - a.x * b.z, z: a.x * b.y - a.y * b.x });
const scale = (a, k) => ({ x: a.x * k, y: a.y * k, z: a.z * k });
const add = (a, b) => ({ x: a.x + b.x, y: a.y + b.y, z: a.z + b.z });
const norm = (a) => scale(a, 1 / Math.hypot(a.x, a.y, a.z));

/**
 * The distance from the bounds' centre at which their bounding sphere fits the narrower of the stage's two half-angles.
 *
 * @param {{w: number, h: number, d: number}} bounds
 * @param {number} fov the vertical field of view, radians
 * @param {number} aspect the stage's width over its height
 */
export function fitDistance(bounds, fov, aspect) {
    const radius = Math.hypot(bounds.w, bounds.h, bounds.d) / 2;
    const half = Math.min(fov / 2, Math.atan(Math.tan(fov / 2) * aspect));

    return radius / Math.sin(half);
}

/** A camera on a sphere about `target`: `polar` from vertical, `azimuth` from the front (+z), at `distance`. */
export function orbitCamera(target, polar, azimuth, distance) {
    const offset = { x: Math.sin(polar) * Math.sin(azimuth), y: Math.cos(polar), z: Math.sin(polar) * Math.cos(azimuth) };

    return Object.freeze({ position: Object.freeze(add(target, scale(offset, distance))), target: Object.freeze({ ...target }), fov: FOV });
}

/**
 * How far inside the stage a framing's corners must project: `margin` (CSS px a side, `{x, y}`), or a quarter of the
 * stage on an axis too short to hold two margins — any browser size is reachable (FLOOR § 13 row 14), and a stage under
 * two margins across could frame nothing inside them at any distance (`framingDistance()`).
 */
export function stageInset(surface, margin) {
    return { x: Math.min(margin.x, surface.width / 4), y: Math.min(margin.y, surface.height / 4) };
}

/**
 * The least distance from `target`, in front at `polar` (azimuth 0), at which every one of `corners` projects inside the
 * stage less `inset` (CSS px a side, `{x, y}`) — found by bisection, the predicate being monotone in the distance: a
 * point's offset across the line of sight is fixed while its depth grows with the distance. The 3-D floor's entry
 * (`floor3d/view-limits.js`) is its caller.
 *
 * @param {{x: number, y: number, z: number}} target
 * @param {number} polar the camera's angle from vertical
 * @param {list<{x: number, y: number, z: number}>} corners world points
 * @param {{width: number, height: number}} surface the stage
 * @param {{x: number, y: number}} inset each under half the stage's axis, or no distance frames a point off the line of sight
 */
export function framingDistance(target, polar, corners, surface, inset) {
    const framed = (distance) => {
        const camera = orbitCamera(target, polar, 0, distance);

        return corners.every((c) => {
            const p = projectPoint(camera, surface, c);

            return p.depth > 0 && p.x >= inset.x && p.x <= surface.width - inset.x && p.y >= inset.y && p.y <= surface.height - inset.y;
        });
    };
    const span = (axis) => Math.max(...corners.map((c) => c[axis])) - Math.min(...corners.map((c) => c[axis]));
    let near = 0;
    let far = Math.max(span('x'), span('y'), span('z'));

    // A sized stage frames the corners well inside this many doublings; past them the search has no answer to find.
    for (let doubling = 0; !framed(far); doubling++) {
        if (doubling === FOCUS_SEARCH_BOUND) {
            throw new Error(`no distance frames the ${corners.length} corners on a ${surface.width} × ${surface.height} stage`);
        }

        near = far;
        far *= 2;
    }

    for (let step = 0; step < 60; step++) {
        const mid = (near + far) / 2;

        if (framed(mid)) {
            far = mid;
        } else {
            near = mid;
        }
    }

    return far;
}

/**
 * The camera's basis: forward, right and up, unit vectors — as the renderer's `Matrix4.lookAt()` builds it
 * (`vendor/three/three.core.js`), including its one degenerate case: a camera looking straight down the up axis has its
 * line of sight nudged a ten-thousandth toward `z`, so the model projects that camera as the page would draw it.
 */
function basis(camera) {
    const up = { x: 0, y: 1, z: 0 };
    let forward = norm(sub(camera.target, camera.position));

    if (dot(cross(forward, up), cross(forward, up)) === 0) {
        forward = norm({ ...forward, z: forward.z - 0.0001 });
    }

    const right = norm(cross(forward, up));

    return { forward, right, up: cross(right, forward) };
}

/**
 * A world point on the stage: CSS px from the stage's top-left, and its depth along the camera's line of sight (world
 * units; not positive behind the camera).
 */
export function projectPoint(camera, surface, point) {
    const { forward, right, up } = basis(camera);
    const rel = sub(point, camera.position);
    const depth = dot(rel, forward);
    const t = Math.tan(camera.fov / 2);
    const aspect = surface.width / surface.height;

    return {
        x: (1 + dot(rel, right) / (depth * t * aspect)) * surface.width / 2,
        y: (1 - dot(rel, up) / (depth * t)) * surface.height / 2,
        depth,
    };
}

/**
 * THE STACK — `docs/design/FLOOR.md § 4`'s building view: every floor's slab stood in the order the directory reads, the
 * lobby the ground slab under them, the two camera poses (the whole building, and one floor close up) and the snap
 * between them, which slab a pointer is over, and which desk labels are drawn. Appendix B row 47 (card#13033 slice 3;
 * the plan is `docs/design/plan-13033-3d-view.md` §§ 3.1, 3.3 and 3.4, whose derivations this module implements).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A MODEL AND NOT A RENDERER: no document, no WebGL, no clock. Every decision the building page makes about
 * where a slab stands, where the camera is and what the viewer's gesture means is made here, so `node` can drive it
 * (`tests/Feature/Building/stack-model-probe.mjs`); the renderer only applies the camera a pose names.
 *
 * ⛔ ONE WORLD. A world unit is `UNIT` scene px. `y` is up; the stack's axis is the vertical line `x = 0, z = 0`; the
 * front of the stack — the drawings' bottom edge, where the overflow strip is — faces `+z`, so the bands recede to the
 * back. A slab's `box` is its min corner and its size: `x ∈ [box.x, box.x + w]`, `y ∈ [box.y, box.y + h]`, `z ∈
 * [box.z, box.z + d]`; its top face is `y = box.y + box.h`. Slabs are centred on `x` and their front edges aligned at
 * `z = +D/2`, `D` the deepest footprint's depth (plan § 3.1).
 *
 * ⛔ A CAMERA HERE IS `{ position, target, fov }` — a perspective camera aimed at `target` with `y` up and a vertical
 * field of view `fov` (radians), over a stage `{ width, height }` in CSS px. `projectPoint()` and `rayThrough()` are
 * the model's own projection and its inverse, so a property about what the viewer sees is checkable without a browser.
 *
 * ⛔ A VIEW IS `{ pose, azimuth }`: `pose` is `'overview'` or `{ floor }` (a floor's KEY), and `azimuth` the overview's
 * turn in radians (0 in front). A focus pose keeps the azimuth the viewer left the overview at, which is where a wheel
 * out returns (§ 3.3's snap table); the focus camera itself always stands in front.
 */

import { PLATE_TEXT_W } from '../floor/desk-layout.js';
import { UNIT, topOf, worldOf } from '../floor/floor-world.js';

// The unit, a slab's top and the one scene-px-to-world map are the floor's world's (`floor/floor-world.js`, hoisted
// there at the 3-D floor, card#13033 stage 2), read here unchanged.
export { UNIT, topOf, worldOf };

/** A slab's thickness in world units — 12 scene px (§ 12's *Building view slab*). */
export const SLAB_T = 0.12;

/** The camera's vertical field of view. */
export const FOV = (45 * Math.PI) / 180;

/** The home camera's angle from vertical — the overview's tilt, from which the storey is derived (§ 3.1). */
export const HOME_POLAR = (50 * Math.PI) / 180;

/** The focus pose's angle from vertical — the home tilt, so a snap is a pure approach (§ 3.3). */
export const FOCUS_POLAR = HOME_POLAR;

/** The storey's fixed point stops when an iterate moves less than this (one scene px), or after `STOREY_BOUND` steps. */
export const STOREY_TOLERANCE = 0.01;

export const STOREY_BOUND = 8;

/** The label rule's padding and border, a side, in CSS px — the sheet's (plan § 3.3 (4)). */
export const LABEL_PAD = 6;

export const LABEL_BORDER = 1;

/** The widest a desk label can be: the nameplate's text bound plus the label's padding and border. */
export const LABEL_MAX = PLATE_TEXT_W + 2 * (LABEL_PAD + LABEL_BORDER);

/** A desk label's height in CSS px. */
export const LABEL_H = 20;

/** How far inside the stage the focused face's corners must project: half the widest label across, a label's height down. */
export const LABEL_MARGIN = Object.freeze({ x: LABEL_MAX / 2, y: LABEL_H });

/** How many times the focus search may double its distance before it is a defect, never a loop (`focusPose()`). */
const FOCUS_SEARCH_BOUND = 64;

/** The longest texture side drawn, whatever the GPU allows above it (§ 12's *Building view texture cap*). */
export const TEXTURE_CAP = 4096;

const EPS = 1e-9;

const sub = (a, b) => ({ x: a.x - b.x, y: a.y - b.y, z: a.z - b.z });
const dot = (a, b) => a.x * b.x + a.y * b.y + a.z * b.z;
const cross = (a, b) => ({ x: a.y * b.z - a.z * b.y, y: a.z * b.x - a.x * b.z, z: a.x * b.y - a.y * b.x });
const scale = (a, k) => ({ x: a.x * k, y: a.y * k, z: a.z * k });
const add = (a, b) => ({ x: a.x + b.x, y: a.y + b.y, z: a.z + b.z });
const norm = (a) => scale(a, 1 / Math.hypot(a.x, a.y, a.z));
const lerp = (a, b, t) => a + (b - a) * t;

/**
 * The boxes of a stack of footprints at storey spacing `s`: the ground (the widest footprint, its top at `y = 0`), then
 * each floor by storey, storey 0 lowest, its top at `(storey + 1) × s`. `footprints` is in storey order, each `{w, d}`.
 */
function boxesFor(footprints, s) {
    const W = Math.max(...footprints.map((f) => f.w));
    const D = Math.max(...footprints.map((f) => f.d));
    const box = (f, top) => Object.freeze({ x: -f.w / 2, y: top - SLAB_T, z: D / 2 - f.d, w: f.w, h: SLAB_T, d: f.d });
    const ground = box({ w: W, d: D }, 0);
    const floors = footprints.map((f, storey) => box(f, (storey + 1) * s));
    const top = floors.length * s;

    return {
        ground,
        floors,
        bounds: Object.freeze({ x: -W / 2, y: -SLAB_T, z: -D / 2, w: W, h: top + SLAB_T, d: D }),
    };
}

/** The bounds' centre. */
function centreOf(bounds) {
    return { x: bounds.x + bounds.w / 2, y: bounds.y + bounds.h / 2, z: bounds.z + bounds.d / 2 };
}

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

/** The home camera over `bounds` at `azimuth` — the overview's camera, and the one the storey is derived from. */
function homeCamera(bounds, surface, azimuth) {
    return orbitCamera(centreOf(bounds), HOME_POLAR, azimuth, fitDistance(bounds, FOV, surface.width / surface.height));
}

/**
 * § 3.1's occlusion bound `g(s)` for footprints at spacing `s`: the least storey at which, from the home camera at the
 * front, the ray to every lower slab's back edge — the ground's included — crosses the slab above's front plane under its
 * underside. `SLAB_T + max_k h_k · d_k / (a + d_k)`, `h_k` the camera's height above slab `k`'s top and `a` its distance
 * in front of the aligned front edges.
 *
 * @param {list<{w: number, d: number}>} footprints in storey order
 */
export function storeyNeed(footprints, s, surface) {
    const { ground, floors, bounds } = boxesFor(footprints, s);
    const camera = homeCamera(bounds, surface, 0);
    const a = camera.position.z - (bounds.z + bounds.d);
    // Every slab with a slab above it: the ground and every floor but the top.
    const below = [ground, ...floors.slice(0, -1)];

    return SLAB_T + Math.max(...below.map((box) => {
        const h = camera.position.y - (box.y + box.h);

        return (h * box.d) / (a + box.d);
    }));
}

/**
 * The storey spacing (§ 3.1): the bounded fixed point of `storeyNeed()` from the parallel-ray value, stopped when an
 * iterate moves less than `STOREY_TOLERANCE` or after `STOREY_BOUND` steps, and the result the last iterate PLUS the
 * tolerance — the iterates rise toward the fixed point, so the last sits up to the tolerance below it.
 *
 * @param {list<{w: number, d: number}>} footprints in storey order; at least one
 * @returns {{spacing: number, iterations: number, settled: boolean}}
 */
export function storeySpacing(footprints, surface) {
    let s = Math.max(...footprints.map((f) => f.d)) / Math.tan(HOME_POLAR) + SLAB_T;

    for (let i = 1; i <= STOREY_BOUND; i++) {
        const next = storeyNeed(footprints, s, surface);
        const moved = Math.abs(next - s);

        s = next;

        if (moved < STOREY_TOLERANCE) {
            return { spacing: s + STOREY_TOLERANCE, iterations: i, settled: true };
        }
    }

    return { spacing: s + STOREY_TOLERANCE, iterations: STOREY_BOUND, settled: false };
}

/**
 * The stack (§ 3.1): one slab per directory row, the first row at the top storey (`n − 1 − level`, the operator's
 * ruling 3), each its scene's extent in world units — a floor with no extent the widest footprint and no picture — and
 * the lobby the ground slab. It reads a row's key, level, name and link and nothing else of it. `null` while no row's
 * scene has an extent, and while the stage has no width or no height: there is no stack to draw, and a `null` stack is
 * what every wire gate reads as framing nothing.
 *
 * ⛔ THE STAGE's SIZE HAS ONE OWNER, AND IT IS HERE. A collapsed or hidden stage reads `clientWidth` 0, and the page
 * hands that to the screen's `resize()`; every pose, snap and act is derived from a stack and reads the stage it was built
 * for (`stack.surface`), so an unsized stage stopped here reaches none of them — no pose is searched for, no aspect
 * divides by zero, no drag turns by NaN.
 *
 * @param {list<{floor: string, name: string, href: string, level: number}>} rows `lobby/building-model.js`'s `plates()`
 * @param {Map<string, {x: number, y: number, w: number, h: number}|null>} extents each row's scene extent, by key
 * @param {string|null} lobbyHref the lobby's link — the ground slab's
 * @param {{width: number, height: number}} surface the stage, CSS px
 */
export function stackModel(rows, extents, lobbyHref, surface) {
    const held = rows.map((row) => extents.get(row.floor) ?? null);

    if (held.every((extent) => extent === null) || !(surface.width > 0 && surface.height > 0)) {
        return null;
    }

    const known = held.filter((extent) => extent !== null);
    const widest = { w: Math.max(...known.map((e) => e.w)) / UNIT, d: Math.max(...known.map((e) => e.h)) / UNIT };
    const n = rows.length;
    const storeyOf = (level) => n - 1 - level;
    // The footprints in storey order (storey 0 lowest): the row at level `n − 1` first.
    const byStorey = rows.map((row, i) => ({ row, level: row.level, extent: held[i] })).sort((p, q) => storeyOf(p.level) - storeyOf(q.level));
    const footprints = byStorey.map(({ extent }) => (extent === null ? widest : { w: extent.w / UNIT, d: extent.h / UNIT }));
    const storey = storeySpacing(footprints, surface);
    const { ground, floors, bounds } = boxesFor(footprints, storey.spacing);
    const slabs = byStorey.map(({ row, level, extent }, k) => Object.freeze({
        floor: row.floor,
        name: row.name,
        href: row.href,
        level,
        storey: storeyOf(level),
        box: floors[k],
        picture: extent !== null,
        extent: extent === null ? null : Object.freeze({ ...extent }),
    })).sort((p, q) => p.level - q.level);

    return Object.freeze({
        storey: storey.spacing,
        settled: storey.settled,
        slabs: Object.freeze(slabs),
        ground: Object.freeze({ floor: null, name: null, href: lobbyHref, level: null, storey: -1, box: ground, picture: false, extent: null }),
        bounds,
        // The stage the stack was derived for — the one every pose and act reads (see the header above).
        surface: Object.freeze({ width: surface.width, height: surface.height }),
        // The stack's axis — what a transition rounds (§ 3.3).
        axis: Object.freeze({ x: 0, z: 0 }),
    });
}

/** The overview pose (§ 3.3): the home camera at the viewer's azimuth. */
export function overviewPose(stack, surface, azimuth) {
    return homeCamera(stack.bounds, surface, azimuth);
}

/** The slab's top-face corners, in world units. */
function faceCorners(slab) {
    const { x, z, w, d } = slab.box;
    const y = topOf(slab);

    return [{ x, y, z }, { x: x + w, y, z }, { x, y, z: z + d }, { x: x + w, y, z: z + d }];
}

/**
 * How far inside the stage a focused face's corners must project: `LABEL_MARGIN`, or a quarter of the stage on an axis
 * too short to hold two margins — any browser size is reachable (FLOOR § 13 row 14), and a stage under two label widths
 * across could frame no face inside the full margins at any distance.
 */
export function focusInset(surface) {
    return { x: Math.min(LABEL_MARGIN.x, surface.width / 4), y: Math.min(LABEL_MARGIN.y, surface.height / 4) };
}

/**
 * Floor `i`'s focus pose (§ 3.3): in front, at `FOCUS_POLAR`, aimed at its top face's centre, at the least distance at
 * which the face's four corners project inside the stage less `focusInset()` — found by bisection, the predicate being
 * monotone in the distance.
 *
 * @param {object} stack `stackModel()`'s
 * @param {number} i the slab's index in `stack.slabs`
 */
export function focusPose(stack, i, surface) {
    const slab = stack.slabs[i];
    const { x, z, w, d } = slab.box;
    const target = { x: x + w / 2, y: topOf(slab), z: z + d / 2 };
    const corners = faceCorners(slab);
    const inset = focusInset(surface);
    const framed = (distance) => {
        const camera = orbitCamera(target, FOCUS_POLAR, 0, distance);

        return corners.every((c) => {
            const p = projectPoint(camera, surface, c);

            return p.depth > 0 && p.x >= inset.x && p.x <= surface.width - inset.x && p.y >= inset.y && p.y <= surface.height - inset.y;
        });
    };
    let near = 0;
    let far = Math.max(w, d);

    // A sized stage frames the face well inside this many doublings; past them the search has no answer to find.
    for (let doubling = 0; !framed(far); doubling++) {
        if (doubling === FOCUS_SEARCH_BOUND) {
            throw new Error(`no distance frames ${slab.floor}'s face on a ${surface.width} × ${surface.height} stage`);
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

    return orbitCamera(target, FOCUS_POLAR, 0, far);
}

/**
 * A view whose floor the stack still lists, or the overview at its azimuth (§ 12 item 10: a focused floor the layout
 * dropped is the overview, at once).
 */
export function liveView(view, stack) {
    if (view.pose === 'overview' || stack.slabs.some((slab) => slab.floor === view.pose.floor)) {
        return view;
    }

    return Object.freeze({ pose: 'overview', azimuth: view.azimuth });
}

/** The camera a view names: the overview at its azimuth, or its floor's focus pose. */
export function poseCamera(view, stack, surface) {
    const live = liveView(view, stack);

    return live.pose === 'overview'
        ? overviewPose(stack, surface, live.azimuth)
        : focusPose(stack, stack.slabs.findIndex((slab) => slab.floor === live.pose.floor), surface);
}

/** Whether two views rest at the same pose — the same floor, or both the overview. */
export function samePose(a, b) {
    return a.pose === 'overview' ? b.pose === 'overview' : b.pose !== 'overview' && a.pose.floor === b.pose.floor;
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

/** The ray from the camera through a stage point, `{ origin, dir }` with `dir` a unit vector. */
export function rayThrough(camera, surface, point) {
    const { forward, right, up } = basis(camera);
    const t = Math.tan(camera.fov / 2);
    const aspect = surface.width / surface.height;
    const nx = (2 * point.x) / surface.width - 1;
    const ny = 1 - (2 * point.y) / surface.height;

    return { origin: camera.position, dir: norm(add(forward, add(scale(right, nx * t * aspect), scale(up, ny * t)))) };
}

/** Screen px per SCENE px at a world point: `H / (2 · depth · tan(fov/2) · UNIT)`. */
export function scaleAt(camera, surface, point) {
    return surface.height / (2 * projectPoint(camera, surface, point).depth * Math.tan(camera.fov / 2) * UNIT);
}

/**
 * § 3.3 (4)'s legibility predicate at a pose: the scale at the slab face's farthest corner against the widest label
 * over the furniture box's width — from that bound inward no two desks' labels can overlap.
 *
 * @param {number} boxWidth the furniture box's width, scene px (`resources/floor/furniture-box.js`)
 * @returns {{scale: number, bound: number, holds: boolean}}
 */
export function legibility(camera, slab, surface, boxWidth) {
    const farthest = faceCorners(slab).reduce((a, b) => (projectPoint(camera, surface, a).depth >= projectPoint(camera, surface, b).depth ? a : b));
    const s = scaleAt(camera, surface, farthest);
    const bound = LABEL_MAX / boxWidth;

    return { scale: s, bound, holds: s >= bound };
}

/**
 * The texture's size for an extent at `scale` texture px per scene px, scaled down proportionally when its longer side
 * would pass the cap — `min(maxTexture, TEXTURE_CAP)` (§ 3.2 step 6).
 *
 * @returns {{width: number, height: number}}
 */
export function textureSize(extent, s, maxTexture) {
    const cap = Math.min(maxTexture, TEXTURE_CAP);
    const k = Math.min(s, cap / Math.max(extent.w, extent.h));

    return { width: Math.round(extent.w * k), height: Math.round(extent.h * k) };
}

/**
 * The focused slab's raster scale (§ 3.2 step 8): the scale its pose shows its NEAREST edge at, in DEVICE px per scene
 * px, at least 1 and at most the cap over the floor's longer side.
 */
export function focusScale(camera, slab, surface, dpr, maxTexture) {
    const nearest = faceCorners(slab).reduce((a, b) => (projectPoint(camera, surface, a).depth <= projectPoint(camera, surface, b).depth ? a : b));
    const cap = Math.min(maxTexture, TEXTURE_CAP) / Math.max(slab.extent.w, slab.extent.h);

    return Math.min(cap, Math.max(1, scaleAt(camera, surface, nearest) * dpr));
}

/** A slab's raster scale under a view: the focused slab at its `focusScale()`, every other at 1 (§ 3.2 step 8). */
export function textureScale(slab, view, stack, surface, dpr, maxTexture) {
    const live = liveView(view, stack);

    return live.pose !== 'overview' && live.pose.floor === slab.floor
        ? focusScale(poseCamera(live, stack, surface), slab, surface, dpr, maxTexture)
        : 1;
}

/**
 * A glide's step (§ 3.3): over two SHOWN values `{ view, camera }`, `{ view: to.view, camera }` with the camera's target
 * moved in a straight line and its position in cylindrical coordinates about the stack's axis — radius and height
 * linearly, azimuth the shortest way round; `to` itself at `t ≥ 1`, so the last step applies the exact destination.
 *
 * @param {{x: number, z: number}} axis the stack's axis (`stack.axis`)
 */
export function betweenPoses(from, to, t, axis) {
    if (t >= 1) {
        return to;
    }

    const cyl = (p) => ({ r: Math.hypot(p.x - axis.x, p.z - axis.z), a: Math.atan2(p.x - axis.x, p.z - axis.z), y: p.y });
    const a = cyl(from.camera.position);
    const b = cyl(to.camera.position);
    let turn = b.a - a.a;

    turn -= 2 * Math.PI * Math.round(turn / (2 * Math.PI));

    const r = lerp(a.r, b.r, t);
    const angle = a.a + turn * t;
    const ft = from.camera.target;
    const tt = to.camera.target;

    return {
        view: to.view,
        camera: Object.freeze({
            position: Object.freeze({ x: axis.x + r * Math.sin(angle), y: lerp(a.y, b.y, t), z: axis.z + r * Math.cos(angle) }),
            target: Object.freeze({ x: lerp(ft.x, tt.x, t), y: lerp(ft.y, tt.y, t), z: lerp(ft.z, tt.z, t) }),
            fov: to.camera.fov,
        }),
    };
}

/**
 * Where a ray first enters a box — `{ t, axis }`, the face's axis and side as `'x-'`, `'y+'` …, or `null` when it
 * misses or only grazes it.
 */
function entry(origin, dir, box) {
    let near = -Infinity;
    let far = Infinity;
    let face = null;

    for (const [k, lo, size] of [['x', box.x, box.w], ['y', box.y, box.h], ['z', box.z, box.d]]) {
        if (Math.abs(dir[k]) < EPS) {
            if (origin[k] <= lo || origin[k] >= lo + size) {
                return null;
            }

            continue;
        }

        const t1 = (lo - origin[k]) / dir[k];
        const t2 = (lo + size - origin[k]) / dir[k];
        const enter = Math.min(t1, t2);

        if (enter > near) {
            near = enter;
            face = `${k}${t1 < t2 ? '-' : '+'}`;
        }

        far = Math.min(far, Math.max(t1, t2));
    }

    return far - near > EPS && far > EPS ? { t: near, face } : null;
}

/**
 * Which slab a pointer's ray is over (§ 3.3): the nearest box the ray enters among `slabs`, when it enters through that
 * slab's TOP face — a ray that first meets an underside or a side is over no slab (round 4's minor 3). `{ slab, point }`
 * or `null`.
 *
 * @param {{origin: object, dir: object}} ray
 * @param {list<object>} slabs every slab the ray may meet, the ground included
 */
export function aim(ray, slabs) {
    let best = null;

    for (const slab of slabs) {
        const hit = entry(ray.origin, ray.dir, slab.box);

        if (hit !== null && hit.t > 0 && (best === null || hit.t < best.hit.t)) {
            best = { slab, hit };
        }
    }

    if (best === null || best.hit.face !== 'y+') {
        return null;
    }

    return { slab: best.slab, point: add(ray.origin, scale(ray.dir, best.hit.t)) };
}

/**
 * Whether the segment from `a` to `b` meets the interior of no box — a sight line, or one step of a camera's path.
 *
 * @param {list<{x: number, y: number, z: number, w: number, h: number, d: number}>} boxes
 */
export function segmentClears(a, b, boxes) {
    const dir = sub(b, a);

    return boxes.every((box) => {
        const hit = entry(a, dir, box);

        return hit === null || hit.t >= 1 - EPS;
    });
}

/** The top-face centre of a slab. */
function faceCentre(slab) {
    return { x: slab.box.x + slab.box.w / 2, y: topOf(slab), z: slab.box.z + slab.box.d / 2 };
}

/**
 * The floor **+** focuses from the overview (§ 3.3): the one whose top-face centre projects nearest the stage's centre,
 * the higher on a tie. Never the ground.
 */
export function nearestToCentre(stack, camera, surface) {
    const distance = (slab) => {
        const p = projectPoint(camera, surface, faceCentre(slab));

        return Math.hypot(p.x - surface.width / 2, p.y - surface.height / 2);
    };

    return [...stack.slabs].sort((a, b) => distance(a) - distance(b) || b.storey - a.storey)[0];
}

/**
 * THE SNAP (§ 3.3's table): the view to show for an intent at a view — the SAME view when nothing moves.
 *
 * @param {{pose: string|{floor: string}, azimuth: number}} view the resting view
 * @param {{act: 'in'|'click', point: {x: number, y: number}}|{act: 'floor', floor: string|null}|{act: 'plus'|'out'|'fit'}} intent
 *        a wheel in or a pinch in over a stage point, a click at one, the keyboard's focus on a floor's label (§ 3.4 — the
 *        table's *click on slab k*, the slab named by its key; `null` is the ground's), **+**, a wheel out / a pinch out /
 *        **−**, or *Fit the building*
 */
export function snap(view, intent, stack, surface) {
    const live = liveView(view, stack);
    const focus = (floor) => Object.freeze({ pose: Object.freeze({ floor }), azimuth: live.azimuth });
    const at = live.pose === 'overview' ? null : live.pose.floor;

    switch (intent.act) {
        case 'in':
        case 'click': {
            const hit = aim(rayThrough(poseCamera(live, stack, surface), surface, intent.point), [...stack.slabs, stack.ground]);
            const floor = hit?.slab.floor ?? null;

            return floor === null || floor === at ? live : focus(floor);
        }

        case 'floor':
            return intent.floor === at || !stack.slabs.some((slab) => slab.floor === intent.floor) ? live : focus(intent.floor);

        case 'plus':
            return at === null ? focus(nearestToCentre(stack, poseCamera(live, stack, surface), surface).floor) : live;

        case 'out':
            return at === null ? live : Object.freeze({ pose: 'overview', azimuth: live.azimuth });

        case 'fit':
            return Object.freeze({ pose: 'overview', azimuth: 0 });

        default:
            throw new Error(`unknown snap intent ${intent.act}`);
    }
}

/**
 * A floor entry's anchor (§ 3.4): the corner of its slab's top face nearest a camera at `azimuth` — on a tie, the
 * front and the left.
 */
export function labelCorner(slab, azimuth) {
    const sx = Math.sin(azimuth);
    const cz = Math.cos(azimuth);
    const { x, z, w, d } = slab.box;

    return { x: sx > EPS ? x + w : x, y: topOf(slab), z: cz < -EPS ? z : z + d };
}

/** The space between a floor entry and the slabs it stands beside, CSS px. */
export const ENTRY_GAP = 8;

/**
 * A slab's rectangle on the stage: the bounding rectangle of its eight corners projected, `{ x, y, w, h }` in CSS px — or
 * `null` when a corner is not in front of the camera. That is reachable: at a focus pose the camera stands just under the
 * storey above, so the slabs two or more storeys up have corners behind it — above the camera's eye and out of the frame.
 */
export function slabRect(slab, camera, surface) {
    const { x, y, z, w, h, d } = slab.box;
    const points = [x, x + w].flatMap((px) => [y, y + h].flatMap((py) => [z, z + d].map((pz) => projectPoint(camera, surface, { x: px, y: py, z: pz }))));

    if (points.some((p) => !(p.depth > 0))) {
        return null;
    }

    const xs = points.map((p) => p.x);
    const ys = points.map((p) => p.y);
    const left = Math.min(...xs);
    const top = Math.min(...ys);

    return { x: left, y: top, w: Math.max(...xs) - left, h: Math.max(...ys) - top };
}

/**
 * Where each floor entry — the floor's link and its notices — stands on the stage under a camera (§ 3.4): BESIDE the
 * stack, never over a slab. Its bottom edge is level with its anchor (`labelCorner()` projected), held inside the stage's
 * height; it stands on the side of its own slab the anchor is on, clear by `ENTRY_GAP` of every slab whose rectangle
 * shares any of its rows — its own included, so a notice is never written over a picture.
 *
 * ⛔ THE NOTICES ARE DRAWN ONLY WHERE THEY FIT. Every entry is first placed as its link alone; then, in the directory's
 * order, an entry whose notices would be pushed past the stage's side, or meet another entry as it stands, stays its link
 * alone and its notices are culled — kept in the list and the accessibility tree, as a culled desk label is. That is
 * reachable on any stage the stack fills across (a narrow window: the room beside the stack falls toward nothing) and at
 * a focus pose, where the floor fills the stage less a label's margin by design. A link pushed past the stage's side is
 * held at that side, so the keyboard's focus on it stays in view; there it may overlap a slab's edge, and it carries no
 * notice. The ground's entry is placed by the same rule and has no notices to cull.
 *
 * @param {object} stack `stackModel()`'s
 * @param {{position: object, target: object, fov: number}} camera a pose or a glide's step
 * @param {function(object): {entry: {w: number, h: number}, link: {w: number, h: number}}} sizeOf an entry's box with its
 *        notices and its link's alone, CSS px, by its slab (the ground's included)
 * @returns {Map<string|null, {x: number, y: number, w: number, h: number, notices: boolean}>} by floor key, the ground's
 *          under `null`; `notices` whether the entry's notices are drawn
 */
export function floorEntryBoxes(stack, camera, surface, sizeOf) {
    const azimuth = Math.atan2(camera.position.x - stack.axis.x, camera.position.z - stack.axis.z);
    const slabs = [...stack.slabs, stack.ground];
    const rects = new Map(slabs.map((slab) => [slab, slabRect(slab, camera, surface)]));
    const occupied = [...rects.values()].filter((r) => r !== null);
    const beside = (slab, { w, h }) => {
        const anchor = projectPoint(camera, surface, labelCorner(slab, azimuth));
        const own = rects.get(slab);
        const left = own === null || anchor.x <= own.x + own.w / 2;
        const y = Math.min(Math.max(0, anchor.y - h), surface.height - h);
        const rows = occupied.filter((r) => r.y < y + h && y < r.y + r.h);
        const x = left ? Math.min(anchor.x, ...rows.map((r) => r.x)) - ENTRY_GAP - w : Math.max(anchor.x, ...rows.map((r) => r.x + r.w)) + ENTRY_GAP;

        return { x, y, w, h };
    };
    // Every entry as its link alone, held inside the stage's sides; then, in the directory's order, each entry's notices
    // where the whole entry stands inside the stage and meets no other entry as it stands.
    const boxes = new Map(slabs.map((slab) => {
        const link = beside(slab, sizeOf(slab).link);

        return [slab.floor, Object.freeze({ ...link, x: Math.min(Math.max(0, link.x), surface.width - link.w), notices: false })];
    }));

    for (const slab of slabs) {
        const full = beside(slab, sizeOf(slab).entry);

        if (full.x >= 0 && full.x + full.w <= surface.width && ![...boxes].some(([key, box]) => key !== slab.floor && overlaps(box, full))) {
            boxes.set(slab.floor, Object.freeze({ ...full, notices: true }));
        }
    }

    return boxes;
}

/**
 * READING ORDER (§ 3.4) — the one order of a floor's desks on this page, for the list and the cull alike: by the
 * nameplate anchor's scene `y`, then its `x`.
 */
export function readingOrder(labels) {
    return [...labels].sort((a, b) => a.at.y - b.at.y || a.at.x - b.at.x || (a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
}

/**
 * Each label's box on the stage at a camera: its anchor (`worldOf()`) projected, centred under its measured width, and
 * `LABEL_H` tall.
 *
 * @param {list<{key: string, at: {x: number, y: number}}>} labels one slab's
 * @param {function(object): number} widthOf a label's measured width, CSS px
 * @returns {Map<string, {x: number, y: number, w: number, h: number}>}
 */
export function labelBoxes(labels, slab, camera, surface, widthOf) {
    return new Map(labels.map((label) => {
        const p = projectPoint(camera, surface, worldOf(slab, label.at));
        const w = widthOf(label);

        return [label.key, Object.freeze({ x: p.x - w / 2, y: p.y - LABEL_H / 2, w, h: LABEL_H })];
    }));
}

const overlaps = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/**
 * THE CULL (§ 3.4): the labels walked once in reading order — the leader first when one is named — each kept when its
 * box meets no box already kept.
 *
 * @param {list<object>} labels one floor's
 * @param {Map<string, object>} boxes `labelBoxes()`'s
 * @param {string|null} leader the key of the label that leads, or `null`
 * @returns {{kept: Set<string>, culled: Set<string>}}
 */
export function cullOverlaps(labels, boxes, leader) {
    const order = readingOrder(labels);
    const led = leader === null ? order : [...order.filter((l) => l.key === leader), ...order.filter((l) => l.key !== leader)];
    const kept = [];
    const culled = new Set();

    for (const label of led) {
        const box = boxes.get(label.key);

        if (kept.some((key) => overlaps(boxes.get(key), box))) {
            culled.add(label.key);
        } else {
            kept.push(label.key);
        }
    }

    return { kept: new Set(kept), culled };
}

/**
 * Which desk labels are drawn (§ 3.4): the focused floor's, at rest, that the cull keeps; none at the overview and none
 * while a glide is in flight (`view` `null`). The floor entries are drawn always and are not this function's.
 *
 * @param {object|null} view the resting view, or `null` while a glide is in flight
 * @param {Map<string, list<object>>} labelsByFloor each floor's desk labels, by key
 * @returns {{shown: Set<string>, culled: Set<string>}}
 */
export function labelsShown(view, labelsByFloor, boxes, leader) {
    if (view === null || view.pose === 'overview') {
        return { shown: new Set(), culled: new Set() };
    }

    const { kept, culled } = cullOverlaps(labelsByFloor.get(view.pose.floor) ?? [], boxes, leader);

    return { shown: kept, culled };
}

/**
 * The camera row's status line on arrival (§ 3.3): what the view rests on — the whole building, or the floor's name
 * alone, as the directory and the floor page's heading read it. A name and no figure.
 */
export function viewStatus(view, stack) {
    const live = liveView(view, stack);

    return live.pose === 'overview' ? 'the whole building' : stack.slabs.find((slab) => slab.floor === live.pose.floor).name;
}

/**
 * The *Read this floor* link (§ 3.4): shown exactly at a focus pose whose cull culled a label, linking to that floor's
 * own page.
 *
 * @returns {{hidden: boolean, href: string|null}}
 */
export function readFloorLink(view, culled, stack) {
    const slab = view.pose === 'overview' ? null : stack.slabs.find((s) => s.floor === view.pose.floor) ?? null;

    return slab === null || culled.size === 0 ? { hidden: true, href: null } : { hidden: false, href: slab.href };
}

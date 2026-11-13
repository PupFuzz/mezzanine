/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsCameraStaysAboveTheFloorTest` reads `floor3d/view-limits.js` through —
 * `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 3 (card#13033 stage 2 slice 2). argv[2] is the client's `wire/`
 * directory (the shipped one or a MUTATED COPY of the tree); stdout is `{ "legs": { "<leg>": [defect, …] }, "printed":
 * { … } }`, each leg a list of defects, empty when it holds.
 *
 * ⛔ THE GEOMETRY IS THE OBSERVATION, COMPUTED HERE FROM THE MODULE's FIGURES: the eye on its sphere about the target and
 * the near plane's four corners (a perspective camera's, `y` up, its vertical field of view `fov`), at every target of a
 * grid over the box, every polar from straight down to the limit and every azimuth of a turn, at the minimum distance.
 * The module states the limits; this measures whether they keep the camera, and its near plane, above the floor.
 */

import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
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
const legs = { limits: [], above: [], cutaway: [] };
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

const box = a.box;

if (box.min.x !== -extent.w / 2 || box.max.x !== extent.w / 2 || box.min.z !== -extent.d / 2 || box.max.z !== extent.d / 2 || box.min.y !== 0 || box.max.y !== ROOM_H_U) {
    legs.limits.push(`the target's box is ${JSON.stringify(box)} — not the extent from the floor to the room's height`);
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
        const target = { x: box.min.x + (i / 8) * extent.w, y: 0, z: box.min.z + (k / 4) * extent.d };

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

// ── the cut-away: exactly the walls the segment meets, the band among them ──────────────────
const world = {
    walls: [{ id: 'on', box: { x: -0.5, y: 0, z: 1, w: 1, h: ROOM_H_U, d: 0.08 } }, { id: 'off', box: { x: 5, y: 0, z: 1, w: 1, h: ROOM_H_U, d: 0.08 } }],
    band: { x: -extent.w / 2, w: extent.w, z: -extent.d / 2, h: ROOM_H_U },
};
const cut = (eye, target) => [...L.cutaway(eye, target, world)].sort();
const front = cut({ x: 0, y: 1, z: 4 }, { x: 0, y: 0, z: -1 });

if (JSON.stringify(front) !== JSON.stringify(['on'])) {
    legs.cutaway.push(`a segment through the wall at z 1 cuts [${front}] — not that wall alone`);
}

// From behind at the home tilt: a target 1 unit in front of the band puts the band on the ray; the centre does not.
const behind = (target) => eyeAt(target, (50 * Math.PI) / 180, Math.PI, a.distance.max / L.MAX_FIT_SHARE);
const close = { x: 0, y: 0, z: -extent.d / 2 + 1 };
const centre = { x: 0, y: 0, z: 0 };

if (!cut(behind(close), close).includes('band')) {
    legs.cutaway.push('a target 1 unit in front of the band, seen from behind the room, does not cut the band');
}

if (cut(behind(centre), centre).includes('band')) {
    legs.cutaway.push('the floor\'s centre, seen from behind the room, cuts the band — the ray passes over it (plan § 3.7\'s derivation)');
}

// A target ON the band's line (the target's box lets it reach the back edge) seen from the room's front: the band is at
// the target, behind it, and stands; from behind the room it is between, and lowers.
const onBand = { x: 0, y: 0, z: -extent.d / 2 };
const ahead = (target) => eyeAt(target, (50 * Math.PI) / 180, 0, a.distance.max / L.MAX_FIT_SHARE);

if (cut(ahead(onBand), onBand).includes('band')) {
    legs.cutaway.push('a target on the band\'s line, seen from the room\'s front, cuts the band — the band is behind the target, not between');
}

if (!cut(behind(onBand), onBand).includes('band')) {
    legs.cutaway.push('a target on the band\'s line, seen from behind the room, does not cut the band');
}

process.stdout.write(JSON.stringify({ legs, printed }));

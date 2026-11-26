/**
 * WHERE THE 3-D FLOOR's GROUND SHADE LIES — the soft plum decal's rects (`FLOOR.md` § 12's *Floor 3-D ground shade*
 * row; `docs/design/plan-13033-stage2-3d-floor.md` § 14.3 (d)), which `floor3d/ground-shade.js` draws as one instanced
 * decal. Pure — no Three, no DOM — so `tests/Feature/Floor/look-probe.mjs` reads it under node.
 *
 * A rect is `{ kind, x, y, z, hw, hd, turn, round, soft, strength, glow }`: centred at `(x, z)` on the surface at `y`,
 * `hw` across and `hd` deep (half extents, units) before it is turned `turn` radians about `y`, its corners rounded
 * `round` of the smaller half extent, fading over `soft` units outside its edge (and half that inside), at `strength`;
 * `glow` a warm pool added to the floor rather than a shade over it.
 *
 * ⛔ THE POPULATION: each still body (`floor3d/body-paint.js`'s `still()`); each piece of furniture standing on the floor
 * — a footprint `floor3d/furniture.js` stood at `y` 0 that is not set into a wall (`facing`); each wall RUN at the floor
 * (`wallRuns()`); the band's foot, a line; and, in a phase `look.js`'s `poolShows()` lights, a pool round each floor
 * lamp's footprint.
 * `world.walls` holds no cell along the band (`floor/floor-walls.js`: the band stands in for the footprint's top row),
 * so the band's own line is the only shade at its foot.
 */

import { LOOK, poolShows } from './look.js';

const EPS = 1e-6;
const near = (a, b) => Math.abs(a - b) < EPS;

/** Whether a placement stands on the floor: its foot at `y` 0, and not set into a wall (`facing`). */
export function standsOnFloor(p) {
    return near(p.at.y, 0) && p.facing === undefined;
}

/**
 * The walls standing on the floor (`box.y` 0) joined into runs: consecutive cells of one row into a run along `x`, then
 * the cells no row run took joined along `z`; each cell in one run. Each run its box `{ x, z, w, d }` (units).
 */
export function wallRuns(walls) {
    const boxes = walls.filter((w) => near(w.box.y, 0)).map((w) => w.box);
    const join = (cells, along, across, size) => {
        const lines = new Map();

        for (const b of cells) {
            const key = [...lines.keys()].find((k) => near(k, b[across])) ?? b[across];

            lines.set(key, [...(lines.get(key) ?? []), b]);
        }

        const runs = [];

        for (const line of lines.values()) {
            line.sort((a, b) => a[along] - b[along]);

            for (const b of line) {
                const last = runs.at(-1);

                if (last !== undefined && last.line === line && near(last.end, b[along])) {
                    last.cells.push(b);
                    last.end = b[along] + b[size];
                } else {
                    runs.push({ line, cells: [b], end: b[along] + b[size] });
                }
            }
        }

        return runs.map((r) => r.cells);
    };
    const rows = join(boxes, 'x', 'z', 'w');
    const long = rows.filter((cells) => cells.length > 1);
    const left = rows.filter((cells) => cells.length === 1).flat();
    const box = (cells) => {
        const x = Math.min(...cells.map((b) => b.x));
        const z = Math.min(...cells.map((b) => b.z));

        return { x, z, w: Math.max(...cells.map((b) => b.x + b.w)) - x, d: Math.max(...cells.map((b) => b.z + b.d)) - z };
    };

    return [...long, ...join(left, 'z', 'x', 'd')].map(box);
}

/**
 * The shade's rects for a world.
 *
 * @param {object} world the floor's world (`floor/floor-world.js`)
 * @param {list<object>} footprints `{ kind, at, hw, hd, turn, facing? }` — what the furniture stood
 * @param {list<{at: object, h: number}>} still the still bodies, each its foot and height
 * @param {string} phase the sky phase (`look.js`'s `phaseOf()`)
 * @returns {list<object>}
 */
export function shadeRects(world, footprints, still, phase) {
    const S = LOOK.shade;
    const out = [];
    const wall = { round: S.wall.round, soft: S.wall.soft, strength: S.wall.strength, glow: false };

    for (const { at, h } of still) {
        const r = S.body.radius * h;

        out.push({ kind: 'body', x: at.x, y: at.y, z: at.z, hw: r, hd: r, turn: 0, round: S.body.round, soft: S.body.soft * h, strength: S.body.strength, glow: false });
    }

    for (const f of footprints) {
        if (standsOnFloor(f)) {
            out.push({ kind: 'furniture', x: f.at.x, y: 0, z: f.at.z, hw: f.hw, hd: f.hd, turn: f.turn ?? 0, round: S.furniture.round, soft: S.furniture.soft, strength: S.furniture.strength, glow: false });
        }
    }

    for (const r of wallRuns(world.walls)) {
        out.push({ kind: 'wall', x: r.x + r.w / 2, y: 0, z: r.z + r.d / 2, hw: r.w / 2, hd: r.d / 2, turn: 0, ...wall });
    }

    if (world.band !== null) {
        out.push({ kind: 'band', x: world.band.x + world.band.w / 2, y: 0, z: world.band.z, hw: world.band.w / 2, hd: 0, turn: 0, ...wall });
    }

    // A lamp's pool is centred on the lamp's own footprint — where its body stands — not on its placement's `at`, which
    // rule W puts on the lamp's back face.
    if (poolShows(phase)) {
        for (const f of footprints.filter((p) => p.kind === S.pool.kind)) {
            out.push({ kind: 'pool', x: f.at.x, y: f.at.y, z: f.at.z, hw: 0, hd: 0, turn: 0, round: S.pool.round, soft: S.pool.soft, strength: S.pool.strength, glow: true });
        }
    }

    return out;
}

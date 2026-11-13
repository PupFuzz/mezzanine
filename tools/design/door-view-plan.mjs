#!/usr/bin/env node
/**
 * THE DOOR VIEW's DERIVATION, PRINTED FROM A MAP (card#12987; `docs/design/plan-12987-cab-door-view.md` § 3.5): what a
 * rider in the cab would have in front of them on one floor, read off the floor's Tiled map alone — a coarse plan of
 * the map with the elevator's threshold marked, and every body the view draws in the rider's frame in cm.
 *
 * A DESIGN-TIME READER, NOT THE BUILD: it restates the arithmetic the plan states so a reviewer can re-print the plan's
 * tables from a map, and it is deliberately independent of `floor/door-view.js` (the build's model), so the two can be
 * compared. It reads no store: dump a room's map first, e.g.
 *
 *   cd server && php artisan tinker --execute='foreach (App\Floor\Floors::all() as $r) file_put_contents("/tmp/{$r->install_id}.tmj", $r->map);'
 *   node tools/design/door-view-plan.mjs /tmp/aimla.tmj
 *   node tools/design/door-view-plan.mjs resources/floor/default.tmj      # the shipped default — a room with no authored map
 *
 * Frame of reference (FLOOR.md § 4.5's *Through the open doors at a FLOOR is that floor*): the threshold is the seam of the
 * band's elevator, `ELEVATOR.dx + ELEVATOR.w / 2 = 74` px in from the floor's extent's left edge on its top edge; the rider
 * faces into the room, toward the page's own viewer, so the rider's right is the floor's −x. `SCALE` is § 12's *Door-view
 * scale*, `CAB.door.h / ELEVATOR.h`. A single-room, unplanned floor is assumed (the room's origin is the extent's origin),
 * which is every floor on the sandbox on 2026-11-08; a planned floor's rooms would be offset by their origins.
 *
 * THE BODIES ARE THE MAP's CELLS (the plan's § 3.2 and § 3.3): every `wall` cell is one box — a WALL of the band's height
 * where the cell lies on the footprint's edge, a PARTITION of § 12's *Partition height* elsewhere, not drawn in the
 * footprint's top row (the near wall) or under a desk's slab; the footprint's far wall is a plane on its far edge and its
 * side walls are planes cut into row strips; each desk is its top and its monitor's back, cut into row strips; each
 * standing piece is a billboard of zero depth on its foot line, a row boundary. THE DRAW ORDER is one total order over
 * those bodies — rows far to near, a boundary's bodies between the row beyond it and the row before it, within one row the
 * body farther from the eye's centre line first, a desk's higher part after its lower — and this tool CHECKS it against an
 * exact ray cast through the doorway (every pair of bodies one ray meets must be painted far first), prints the counts
 * the plan's cost figures are taken from (bodies, bodies inside the doorway's frustum, faces per cell and per row run),
 * and runs the rejected nearest-point sort through the same check for comparison.
 *
 * ⚠ ITS ORDER CHECK IS THE DESIGN ROUND's, NOT THE BUILD's GATE: it skips a pair a ray meets at one depth (a tie) and its
 * `row` order predates design review round 3's folds (the far wall first on its boundary, pieces in tile order, cells
 * placed on the grid by their tile index) — the build's order and its check, ties included, are `floor/door-view.js` and
 * `tools/design/door-view.selftest.mjs`.
 *
 * argv: `<map.tmj> [tiles-per-char] [rays]` (default 4 → one character is 32 px on an 8-px grid; 4000 rays). Reads the
 * tileset's `kind` per tile from `resources/floor/tiles/floor-plane.tsx` relative to the repository root.
 */

import fs from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SEAM_PX = 32 + 84 / 2;
const SCALE = 200 / 144;
const DESK_SPRITE = { x: 18, w: 180, fromBottom: 100, h: 60 };
const MONITOR = { w: 96, top: 147 };
const DESK_TOP = 76;
const WALL_H = 160 * SCALE;
const PARTITION_H = 120;
const EYE = { x: 0, y: 155, z: -120 };
const DOOR = { w: 110, h: 200 };

const [mapPath, perChar = '4', rayCount = '4000'] = process.argv.slice(2);

if (!mapPath) {
    console.error('usage: door-view-plan.mjs <map.tmj> [tiles-per-char] [rays]');
    process.exit(2);
}

const tsx = fs.readFileSync(join(ROOT, 'resources', 'floor', 'tiles', 'floor-plane.tsx'), 'utf8');
const kinds = new Map();
const sizes = new Map();

for (const m of tsx.matchAll(/<tile id="(\d+)">([\s\S]*?)<\/tile>/g)) {
    const kind = /name="kind" value="([^"]+)"/.exec(m[2]);
    const image = /<image[^>]*width="(\d+)"[^>]*height="(\d+)"/.exec(m[2]);

    if (kind) kinds.set(Number(m[1]) + 1, kind[1]);
    if (image) sizes.set(Number(m[1]) + 1, [Number(image[1]), Number(image[2])]);
}

const map = JSON.parse(fs.readFileSync(mapPath, 'utf8'));
const { width: W, height: H, tilewidth: T } = map;
const C = Number(perChar);
const letter = {
    wall: '#', accent: '.', bookcase: 'B', plant: 'p', 'floor-lamp': 'L', armchair: 'A', cushion: 'c', 'book-pile': 'b', 'snack-table': 'S', 'snack-table-low': 's', 'restroom-door': 'R', 'water-cooler': 'W', 'filing-cabinet': 'F', copier: 'C', 'coat-rack': 'K', whiteboard: 'w', aquarium: 'Q', 'bean-bag': 'g', globe: 'G',
};
const grid = Array.from({ length: Math.ceil(H / C) }, () => Array(Math.ceil(W / C)).fill(' '));
const put = (tx, ty, ch, force = false) => {
    const r = Math.floor(ty / C);
    const c = Math.floor(tx / C);

    if (grid[r] && c < grid[r].length && (force || grid[r][c] === ' ' || grid[r][c] === '.')) grid[r][c] = ch;
};
const walls = [];
const standing = [];
const desks = [];
const accentCells = [];

for (const L of map.layers) {
    if (L.type === 'tilelayer') {
        L.data.forEach((g, i) => {
            if (!g) return;
            const kind = kinds.get(g) ?? '?';
            const tx = i % W;
            const ty = Math.floor(i / W);

            if (kind === 'accent') {
                accentCells.push([tx * T, ty * T]);
                put(tx, ty, '.');
            } else if (kind === 'wall') {
                walls.push([tx * T, ty * T]);
                put(tx, ty, '#', true);
            } else {
                const [w, h] = sizes.get(g) ?? [T, T];

                standing.push({ kind, x: tx * T, y: (ty + 1) * T - h, w, h });
            }
        });
    }
    if (L.type === 'objectgroup') {
        for (const o of L.objects) {
            desks.push({ id: o.id, x: o.x, y: o.y, w: o.width, h: o.height, reserved: (o.properties ?? []).some((p) => p.name === 'reserved_for') });
            for (let x = o.x; x < o.x + o.width; x += T) {
                put(x / T, o.y / T, 'D', true);
                put(x / T, (o.y + o.height - 1) / T, 'D', true);
            }
            for (let y = o.y; y < o.y + o.height; y += T) {
                put(o.x / T, y / T, 'D', true);
                put((o.x + o.width - 1) / T, y / T, 'D', true);
            }
        }
    }
}

for (const s of standing) put(s.x / T, s.y / T, letter[s.kind] ?? '?', true);
for (const d of desks.filter((d) => d.reserved)) put((d.x + d.w / 2) / T, (d.y + d.h / 2) / T, 'P', true);
put(SEAM_PX / T, 0, 'E', true);

console.log(`== ${mapPath}: ${W * T} × ${H * T} px, one character ${C * T} px; E = the threshold (the seam, x = ${SEAM_PX}) on the top edge`);
console.log(`   ${Array.from({ length: grid[0].length }, (_, i) => (i % 5 === 0 ? String(Math.floor((i * C * T) / 100)).slice(-1) : ' ')).join('')}`);
grid.forEach((row, r) => console.log(`${String(r * C * T).padStart(4)}${row.join('')}`));

const cm = (px) => Math.round(px * SCALE);
const X = (px) => cm(SEAM_PX - px);
const slabOf = (d) => ({ x: d.x + DESK_SPRITE.x, y: d.y + d.h - DESK_SPRITE.fromBottom, w: DESK_SPRITE.w, h: DESK_SPRITE.h });
// The VIEW's slab covers the cell rows the exact rect touches — snapped outward, at most 7 px (10 cm) each way — so every
// body in one row spans the row's whole depth and the row order's within-row rule (outer first) is exact (plan § 3.3).
const snapped = (s) => { const y0 = Math.floor(s.y / T) * T; const y1 = Math.ceil((s.y + s.h) / T) * T; return { x: s.x, y: y0, w: s.w, h: y1 - y0 }; };
const slabs = desks.map((d) => snapped(slabOf(d)));
const underSlab = (x, y) => slabs.some((s) => x < s.x + s.w && s.x < x + T && y < s.y + s.h && s.y < y + T);
const onEdge = (x, y) => x === 0 || x + T === W * T || y + T === H * T;

// ── the cells, classified (plan § 3.2) ───────────────────────────────────────────────────────────────
const cells = walls.map(([x, y]) => ({ x, y, cls: y === 0 ? 'near' : underSlab(x, y) ? 'under-slab' : onEdge(x, y) ? 'WALL' : 'PARTITION' }));
const drawnCells = cells.filter((c) => c.cls === 'WALL' || c.cls === 'PARTITION');
const count = (cls) => cells.filter((c) => c.cls === cls).length;

console.log(`\nwall cells ${walls.length}: WALL ${count('WALL')}, PARTITION ${count('PARTITION')}, near wall (top row, not drawn) ${count('near')}, under a desk's slab (not drawn) ${count('under-slab')}`);
console.log(`accent cells ${accentCells.length} (${((accentCells.length / (W * H)) * 100).toFixed(1)} % of cells), merged along rows into ${rowRuns(accentCells.map(([x, y]) => ({ x, y, cls: 'accent' }))).length} runs; scale ${SCALE.toFixed(3)} cm/px; rider's X positive to the RIGHT`);

/** Same-class cells side by side in one row, as one run — a PRINT grouping here, and the build's SVG-count merge (§ 3.3). */
function rowRuns(list) {
    const sorted = [...list].sort((a, b) => a.y - b.y || a.x - b.x);
    const runs = [];

    for (const c of sorted) {
        const last = runs[runs.length - 1];

        if (last && last.y === c.y && last.cls === c.cls && last.x + last.w === c.x) last.w += T;
        else runs.push({ x: c.x, y: c.y, w: T, h: T, cls: c.cls });
    }

    return runs;
}

/** Runs of one column stacked down rows, for the plan's legibility only — the model never stacks. */
function columnGroups(runs) {
    const groups = [];

    for (const r of [...runs].sort((a, b) => a.x - b.x || a.y - b.y)) {
        const g = groups.find((g) => g.x === r.x && g.w === r.w && g.cls === r.cls && g.y + g.h === r.y);

        if (g) g.h += r.h;
        else groups.push({ ...r });
    }

    return groups.sort((a, b) => a.y - b.y || a.x - b.x);
}

console.log(`\ncells as the plan's tables list them (grouped for printing only — each line is ${'`'}n${'`'} cells of one class):`);
for (const g of columnGroups(rowRuns(drawnCells))) {
    console.log(`  ${g.cls.padEnd(9)} ${String((g.w / T) * (g.h / T)).padStart(3)} cells  px x${g.x}..${g.x + g.w} y${g.y}..${g.y + g.h}  →  X ${X(g.x + g.w)}..${X(g.x)} cm, Z ${cm(g.y)}..${cm(g.y + g.h)} cm`);
}
for (const d of desks) {
    const s = slabOf(d);

    const v = snapped(s);

    console.log(`  desk #${d.id}${d.reserved ? ' (reserved)' : ''} slab px x${s.x}..${s.x + s.w} y${s.y}..${s.y + s.h}  →  X ${X(s.x + s.w)}..${X(s.x)} cm, Z ${cm(s.y)}..${cm(s.y + s.h)} cm (drawn on rows y${v.y}..${v.y + v.h}: Z ${cm(v.y)}..${cm(v.y + v.h)} cm); its monitor X ${X(s.x + s.w)}..${X(s.x + s.w - MONITOR.w)} cm`);
}
for (const s of standing) {
    console.log(`  ${s.kind} px x${s.x}..${s.x + s.w} foot y${s.y + s.h}  →  X ${X(s.x + s.w)}..${X(s.x)} cm, Z ${cm(s.y + s.h)} cm, ${cm(s.h)} cm tall`);
}

// ── the bodies and the order (plan § 3.3) ───────────────────────────────────────────────────────────────

/** Every body in the rider's frame, cm, unrounded: `x1 < x2` across (right positive), `z1 <= z2` into the room, `y1 < y2` up. */
const bodies = [];
const Xf = (px) => (SEAM_PX - px) * SCALE;
const Zf = (py) => py * SCALE;
const rowKey = (py) => 2 * Math.floor(py / T) + 1;
const boundaryKey = (py) => 2 * Math.round(py / T);
// A body's distance from the eye's centre line: 0 where it straddles X = 0. A desk's parts take the DESK's (`mx` handed in):
// its monitor stands on its top, X-inside it, and the two are ordered by height (rank), never by their own X.
const mxOf = (x1, x2) => (x1 <= 0 && 0 <= x2 ? 0 : Math.min(Math.abs(x1), Math.abs(x2)));
const push = (b, key, tag, rank = 0, mx = mxOf(b.x1, b.x2)) => {
    bodies.push({ ...b, key, mx, rank, tag, i: bodies.length });
};
const strips = (px, py, pw, ph, y1, y2, tag, rank = 0, mx = undefined) => {
    for (let k = Math.floor(py / T); k * T < py + ph; k++) {
        const a = Math.max(py, k * T);
        const b = Math.min(py + ph, (k + 1) * T);

        if (b > a) push({ x1: Xf(px + pw), x2: Xf(px), z1: Zf(a), z2: Zf(b), y1, y2 }, rowKey(a), tag, rank, mx);
    }
};

push({ x1: Xf(W * T), x2: Xf(0), z1: Zf(H * T), z2: Zf(H * T), y1: 0, y2: WALL_H }, boundaryKey(H * T), 'far-wall');
strips(0, 0, 0, H * T, 0, WALL_H, 'side-wall');
strips(W * T, 0, 0, H * T, 0, WALL_H, 'side-wall');
for (const c of drawnCells) push({ x1: Xf(c.x + T), x2: Xf(c.x), z1: Zf(c.y), z2: Zf(c.y + T), y1: 0, y2: c.cls === 'WALL' ? WALL_H : PARTITION_H }, rowKey(c.y), c.cls.toLowerCase());
for (const s of slabs) {
    const mx = mxOf(Xf(s.x + s.w), Xf(s.x));

    strips(s.x, s.y, s.w, s.h, 0, DESK_TOP, 'desk-top', 0, mx);
    strips(s.x + s.w - MONITOR.w, s.y, MONITOR.w, T, DESK_TOP, MONITOR.top, 'monitor', 1, mx);
}
for (const s of standing) push({ x1: Xf(s.x + s.w), x2: Xf(s.x), z1: Zf(s.y + s.h), z2: Zf(s.y + s.h), y1: 0, y2: s.h * SCALE }, boundaryKey(s.y + s.h), `${s.kind}@${s.x},${s.y}`);

const ORDERS = {
    // § 3.3's order: far row first; a boundary's bodies between the row beyond and the row before; outer |X| first; a desk's higher part later
    row: (B) => [...B].sort((a, b) => b.key - a.key || b.mx - a.mx || a.rank - b.rank || a.i - b.i),
    // the rejected rule over the same bodies: by nearest point, with no place for a boundary between rows
    nearest: (B) => [...B].sort((a, b) => b.z1 - a.z1 || b.mx - a.mx || a.rank - b.rank || a.i - b.i),
    // the floor page's habit, planes then scenery: every billboard after every other body
    piecesLast: (B) => { const o = ORDERS.row(B); return [...o.filter((b) => b.z1 !== b.z2 || b.tag === 'far-wall'), ...o.filter((b) => b.z1 === b.z2 && b.tag !== 'far-wall')]; },
};

// The projection: the office view's `at()` without the window's scale — screen x = X / (Z − EYE.z), screen y = (EYE.y − Y) / (Z − EYE.z).
const screenRect = (b) => {
    const xs = [];
    const ys = [];

    for (const x of [b.x1, b.x2]) for (const z of [b.z1, b.z2]) for (const y of [b.y1, b.y2]) {
        xs.push(x / (z - EYE.z));
        ys.push((EYE.y - y) / (z - EYE.z));
    }

    return { x1: Math.min(...xs), x2: Math.max(...xs), y1: Math.min(...ys), y2: Math.max(...ys) };
};
// The doorway at Z = 0 seen from the eye: the frustum every drawn body must reach into.
const doorway = { x1: -DOOR.w / 2 / -EYE.z, x2: DOOR.w / 2 / -EYE.z, y1: (EYE.y - DOOR.h) / -EYE.z, y2: EYE.y / -EYE.z };
const inFrustum = (b) => { const r = screenRect(b); return r.x1 < doorway.x2 && doorway.x1 < r.x2 && r.y1 < doorway.y2 && doorway.y1 < r.y2; };
const visible = bodies.filter(inFrustum);

// Faces: a cell's near face always; its top where the eye is above it; one end where it lies wholly to one side of X = 0.
const facesOf = (b) => 1 + (b.y2 < EYE.y ? 1 : 0) + (b.x1 > 0 || b.x2 < 0 ? 1 : 0);
const cellBodies = visible.filter((b) => b.tag === 'wall' || b.tag === 'partition');
const runBodies = rowRuns(drawnCells).map((r) => ({ x1: Xf(r.x + r.w), x2: Xf(r.x), z1: Zf(r.y), z2: Zf(r.y + T), y1: 0, y2: r.cls === 'WALL' ? WALL_H : PARTITION_H })).filter(inFrustum);
const sum = (list, f) => list.reduce((n, b) => n + f(b), 0);

const accentRuns = rowRuns(accentCells.map(([x, y]) => ({ x, y, cls: 'accent' })));
const accentVisible = accentRuns.filter((r) => inFrustum({ x1: Xf(r.x + r.w), x2: Xf(r.x), z1: Zf(r.y), z2: Zf(r.y + T), y1: 0, y2: 0 }));

console.log(`\naccent runs inside the frustum ${accentVisible.length} of ${accentRuns.length} (flat on the plane, drawn before every body, no order among them)`);
console.log(`bodies ${bodies.length} (far wall 1, side-wall strips ${bodies.filter((b) => b.tag === 'side-wall').length}, cells ${drawnCells.length}, desk strips ${bodies.filter((b) => b.tag === 'desk-top' || b.tag === 'monitor').length}, billboards ${standing.length}); inside the doorway's frustum ${visible.length}`);
console.log(`faces inside the frustum: per cell ${sum(cellBodies, facesOf)} over ${cellBodies.length} cells; merged along row runs ${sum(runBodies, facesOf)} over ${runBodies.length} runs; desk-strip faces ${sum(visible.filter((b) => b.tag === 'desk-top' || b.tag === 'monitor'), facesOf)}; billboards ${visible.filter((b) => b.z1 === b.z2 && b.tag !== 'far-wall').length}`);

// ── the exact ray cast: every pair one ray meets must be painted far first ─────────────────────────────
let seed = 12345;
const rnd = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
const rayHit = (b, o, d) => {
    let t0 = 0;
    let t1 = 1e9;

    for (const [lo, hi, i] of [[b.x1, b.x2, 0], [b.y1, b.y2, 1], [b.z1, b.z2, 2]]) {
        if (Math.abs(d[i]) < 1e-12) { if (o[i] < lo || o[i] > hi) return null; continue; }
        let a = (lo - o[i]) / d[i];
        let c = (hi - o[i]) / d[i];

        if (a > c) [a, c] = [c, a];
        t0 = Math.max(t0, a);
        t1 = Math.min(t1, c);
        if (t0 > t1 + 1e-12) return null;
    }

    return t0;
};
const positions = Object.fromEntries(Object.entries(ORDERS).map(([k, f]) => [k, new Map(f(visible).map((b, p) => [b.i, p]))]));
const violations = Object.fromEntries(Object.keys(ORDERS).map((k) => [k, new Map()]));
let pairs = 0;

for (let r = 0; r < Number(rayCount); r++) {
    const d = [-DOOR.w / 2 + DOOR.w * rnd(), DOOR.h * rnd() - EYE.y, -EYE.z];
    const o = [EYE.x, EYE.y, EYE.z];
    const hits = visible.map((b) => [rayHit(b, o, d), b]).filter(([t]) => t !== null).sort((a, b) => a[0] - b[0]);

    for (let p = 0; p < hits.length; p++) for (let q = p + 1; q < hits.length; q++) {
        if (hits[q][0] - hits[p][0] < 1e-9) continue; // coplanar: no order owed
        pairs += 1;
        for (const k of Object.keys(ORDERS)) {
            if (positions[k].get(hits[p][1].i) < positions[k].get(hits[q][1].i)) {
                const key = `${hits[q][1].tag} painted over ${hits[p][1].tag}`;

                violations[k].set(key, (violations[k].get(key) ?? 0) + 1);
            }
        }
    }
}

console.log(`\nray cast — ${rayCount} rays through the doorway, ${pairs} (near, far) pairs owed an order:`);
for (const [k, v] of Object.entries(violations)) {
    const total = [...v.values()].reduce((a, b) => a + b, 0);

    console.log(`  ${k.padEnd(11)} violations ${String(total).padStart(6)}${total ? `  — ${[...v.entries()].sort((a, b) => b[1] - a[1]).slice(0, 4).map(([key, n]) => `${key} ×${n}`).join('; ')}` : ''}`);
}

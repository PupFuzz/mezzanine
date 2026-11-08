#!/usr/bin/env node
/**
 * THE DOOR VIEW's DERIVATION, PRINTED FROM A MAP (card#12987; `docs/design/plan-12987-cab-door-view.md` § 3.5): what a
 * rider in the cab would have in front of them on one floor, read off the floor's Tiled map alone — a coarse plan of
 * the map with the elevator's threshold marked, and every wall run and desk sprite in the rider's frame in cm.
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
 * argv: `<map.tmj> [tiles-per-char]` (default 4 → one character is 32 px on an 8-px grid). Reads the tileset's `kind`
 * per tile from `resources/floor/tiles/floor-plane.tsx` relative to the repository root.
 */

import fs from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SEAM_PX = 32 + 84 / 2;
const SCALE = 200 / 144;
const DESK_SPRITE = { x: 18, w: 180, fromBottom: 100, h: 60 };

const [mapPath, perChar = '4'] = process.argv.slice(2);

if (!mapPath) {
    console.error('usage: door-view-plan.mjs <map.tmj> [tiles-per-char]');
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
let accent = 0;

for (const L of map.layers) {
    if (L.type === 'tilelayer') {
        L.data.forEach((g, i) => {
            if (!g) return;
            const kind = kinds.get(g) ?? '?';
            const tx = i % W;
            const ty = Math.floor(i / W);

            if (kind === 'accent') {
                accent += 1;
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

// Wall cells merged into rects the way `tileRegions()` merges them: runs along a row, identical runs stacked down columns.
const set = new Set(walls.map(([x, y]) => `${x},${y}`));
const seen = new Set();
const runs = [];

for (const [x, y] of walls.sort((a, b) => a[1] - b[1] || a[0] - b[0])) {
    if (seen.has(`${x},${y}`)) continue;
    let x2 = x;

    while (set.has(`${x2 + T},${y}`)) {
        x2 += T;
        seen.add(`${x2},${y}`);
    }
    seen.add(`${x},${y}`);
    runs.push({ x, y, w: x2 + T - x, h: T });
}

const used = new Set();
const rects = [];

runs.forEach((r, i) => {
    if (used.has(i)) return;
    let h = r.h;

    for (;;) {
        const j = runs.findIndex((q, qi) => !used.has(qi) && qi !== i && q.x === r.x && q.w === r.w && q.y === r.y + h);

        if (j < 0) break;
        used.add(j);
        h += T;
    }
    used.add(i);
    rects.push({ ...r, h });
});

const cm = (px) => Math.round(px * SCALE);
const X = (px) => cm(SEAM_PX - px);
const onEdge = (r) => (r.w > r.h ? r.y === 0 || r.y + r.h === H * T : r.x === 0 || r.x + r.w === W * T);

console.log(`\nwall tiles ${walls.length}, merged rects ${rects.length}; accent on ${((accent / (W * H)) * 100).toFixed(1)} % of cells; scale ${SCALE.toFixed(3)} cm/px; rider's X positive to the RIGHT`);
for (const r of rects.sort((a, b) => a.y - b.y || a.x - b.x)) {
    console.log(`  ${onEdge(r) ? 'WALL     ' : 'PARTITION'} px x${r.x}..${r.x + r.w} y${r.y}..${r.y + r.h}  →  X ${X(r.x + r.w)}..${X(r.x)} cm, Z ${cm(r.y)}..${cm(r.y + r.h)} cm`);
}
for (const d of desks) {
    const sx = d.x + DESK_SPRITE.x;
    const sy = d.y + d.h - DESK_SPRITE.fromBottom;

    console.log(`  desk #${d.id}${d.reserved ? ' (reserved)' : ''} sprite px x${sx}..${sx + DESK_SPRITE.w} y${sy}..${sy + DESK_SPRITE.h}  →  X ${X(sx + DESK_SPRITE.w)}..${X(sx)} cm, Z ${cm(sy)}..${cm(sy + DESK_SPRITE.h)} cm`);
}
for (const s of standing) {
    console.log(`  ${s.kind} px x${s.x}..${s.x + s.w} foot y${s.y + s.h}  →  X ${X(s.x + s.w)}..${X(s.x)} cm, Z ${cm(s.y + s.h)} cm, ${cm(s.h)} cm tall`);
}

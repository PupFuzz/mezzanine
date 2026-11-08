#!/usr/bin/env node
// THE CAB's DOOR VIEW, CHECKED — `server/public/js/floor/door-view.js`'s model (card#12987; the plan is
// `docs/design/plan-12987-cab-door-view.md`, §§ 3–6 and 10). Node, no dependencies, no network.
//
//   node tools/design/door-view.selftest.mjs                       every leg, then every planted RED
//   node tools/design/door-view.selftest.mjs --order [scenes] [rays] [seed...]   the order leg alone, printed per seed
//
// Two legs, each answering its defects (`[]` for a clean tree):
//
// - FIXTURES — the plan's § 6 floors, built inline as 8-px maps over the shipped tileset and driven through the real
//   `Building` (a scripted fetch), the real `TilesetLoader` and the real theme modules: `alpha` (the house theme; a partition
//   column right of the seam, a desk left of it, a wall cell under its slab, a plant inside the column), `bravo` (cottage; a
//   partition row crossing the centre line with its perimeter post, the near-wall row, a bookcase behind the partition, a
//   bookcase on the last row off the centre line, two pieces on one foot line, every floor cell accent — and again with its
//   tile layers dragged by each offset `DRAGS` names, on both sides of half a cell and below zero), `charlie` (two rooms of
//   different heights, and again with a tileset the loader lacks), `delta` (a planned floor: a hallway and a room placed
//   below it), `echo` (a planned room off the 8-px lattice, so a partition cell and a desk straddle the centre line), and
//   the bare routes R2–R4. Each reading is the plan's § 3.5 shape: centimetres in the rider's frame, and document order.
// - ORDER — the plan's § 10 random-scene check, committed (design review round 3's m6): random floors of one or two rooms,
//   runs of wall cells, desks at unaligned y, standing pieces (some on the last row, some layers dragged off the grid), each
//   built by `doorView()` itself; rays from the eye through the doorway; for every pair of bodies one ray meets at different
//   depths the nearer must be painted later, and for every pair it meets AT THE SAME DEPTH — a tie, which the first version
//   of this check skipped and so could not see round 3's M1 — the order a shared plane owes: the one on the eye's side of a
//   plane they touch at, a piece over the far wall it stands against, and two pieces on one foot line in the floor page's
//   tile order. A tie no rule names is counted and printed, never silently passed.
//
// ⛔ EVERY LEG CAN FAIL, AND IS WATCHED FAILING ON EVERY RUN: each RED below is planted in a MIRROR of the module tree in a
// temporary directory and the leg it names must red on it, naming what the RED says it names (the theme gates' pattern,
// `tools/floor-themes/selftest.mjs`). A RED that stays green fails this run.

import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const T = 8;
const EYE = Object.freeze([0, 155, -120]);
const EPS = 1e-7;

let failures = 0;
const check = (cond, what) => { console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${what}`); if (!cond) failures++; };
let fresh = 0;
const load = async (path) => import(`${pathToFileURL(path).href}?v=${fresh++}`);

// ── the tree a leg reads: the shipped one, or a planted mirror of it ────────────────────────────────
const shipped = { root: REPO };

/** A writable mirror of the module tree and the theme tree, `plant(mirror)` applied to it. */
function mirror(plant) {
    const dir = mkdtempSync(join(tmpdir(), 'door-view-'));

    for (const part of [['server', 'public', 'js'], ['resources', 'floor'], ['resources', 'characters']]) {
        mkdirSync(dirname(join(dir, ...part)), { recursive: true });
        cpSync(join(REPO, ...part), join(dir, ...part), { recursive: true });
    }

    const t = { root: dir };

    plant(t);

    return t;
}

/** One planted line in a mirror's file, required to be there exactly once. */
function edit(path, from, to) {
    const src = readFileSync(path, 'utf8');

    if (src.split(from).length !== 2) {
        throw new Error(`the plant's anchor is not in ${path} exactly once: ${from.slice(0, 80)}`);
    }

    writeFileSync(path, src.replace(from, to));
}

const VIEW = (t) => join(t.root, 'server', 'public', 'js', 'floor', 'door-view.js');

/** The modules a leg drives, loaded from the tree `t`. */
async function open(t) {
    const js = (...p) => join(t.root, 'server', 'public', 'js', ...p);
    const themesDir = join(t.root, 'resources', 'floor', 'themes');
    const registry = await load(join(themesDir, 'index.js'));
    const modules = Object.fromEntries(await Promise.all(registry.THEMES.map(async (name) => [name, await load(join(themesDir, name, 'theme.js'))])));
    const cab = await load(js('floor', 'cab-paint.js'));
    const tileset = await load(js('floor', 'tileset.js'));
    const url = tileset.tilesetUrl('tiles/floor-plane.tsx');
    const tsx = readFileSync(join(t.root, 'resources', 'floor', 'tiles', 'floor-plane.tsx'), 'utf8');
    const read = tileset.readTileset(tsx, url);
    const gids = {};

    // Every tile of the shipped set by kind and size: `gid = id + 1` under the maps' one `firstgid`.
    for (let id = 0; id < 256; id++) {
        const tile = read.tile(id);
        const kind = tile?.properties?.kind;

        if (typeof kind === 'string') {
            (gids[kind] ??= []).push({ gid: id + 1, w: tile.sw ?? T, h: tile.sh ?? T });
        }
    }

    return {
        t,
        registry,
        modules,
        themes: cab.themeInputs(registry, modules),
        svgUri: cab.svgUri,
        view: await load(js('floor', 'door-view.js')),
        layout: await load(js('floor', 'floor-layout.js')),
        mapTiles: await load(js('floor', 'map-tiles.js')),
        Building: (await load(js('wire', 'building.js'))).Building,
        TilesetLoader: tileset.TilesetLoader,
        tilesetUrl: tileset.tilesetUrl,
        tsx,
        url,
        read,
        gids,
    };
}

// ── maps, built inline ──────────────────────────────────────────────────────────────────────────────
const TILESET_ENTRY = Object.freeze({ firstgid: 1, source: 'tiles/floor-plane.tsx' });

/**
 * An 8-px map: `walls` and `accents` as `[col, row]` cells, `pieces` as `{gid, col, row}` (the tile bottom-aligned to
 * its cell, so its foot is `(row + 1) · 8`), `desks` as Tiled objects, `offset` on both tile layers, `tilesets` extra.
 */
function makeMap({ w, h, walls = [], accents = [], pieces = [], desks = [], offset = null, tilesets = [] }) {
    const layer = (name, cells) => {
        const data = new Array(w * h).fill(0);

        for (const [col, row, gid] of cells) {
            data[row * w + col] = gid;
        }

        return { name, type: 'tilelayer', width: w, height: h, data, visible: true, opacity: 1, ...(offset === null ? {} : { offsetx: offset, offsety: offset }) };
    };

    return {
        width: w,
        height: h,
        tilewidth: T,
        tileheight: T,
        tilesets: [TILESET_ENTRY, ...tilesets],
        layers: [
            layer('accent', accents.map(([c, r]) => [c, r, 'ACCENT'])),
            layer('walls', walls.map(([c, r]) => [c, r, 'WALL'])),
            layer('furniture', pieces.map((p) => [p.col, p.row, p.gid])),
            { name: 'desks', type: 'objectgroup', objects: desks.map((d, i) => ({ id: i + 1, x: d.x, y: d.y, width: d.width ?? 216, height: d.height ?? 240 })) },
        ],
    };
}

/** `makeMap()`'s `WALL` and `ACCENT` placeholders resolved to the shipped set's gids. */
function resolve(o, map) {
    for (const layer of map.layers) {
        if (layer.type === 'tilelayer') {
            layer.data = layer.data.map((g) => (g === 'WALL' ? o.gids.wall[0].gid : g === 'ACCENT' ? o.gids.accent[0].gid : g));
        }
    }

    return map;
}

/** A scripted same-origin fetch: each path's answer, or a 503 per path named in `fail`. */
function scripted(o, rooms, { fail = [], layout = null } = {}) {
    return async (path) => {
        const answer = (status, body) => ({ status, ok: status === 200, json: async () => body, text: async () => (typeof body === 'string' ? body : JSON.stringify(body)) });

        if (fail.some((f) => path.includes(f))) {
            return answer(503, null);
        }

        if (path === '/api/building' && layout !== null) {
            return answer(200, layout);
        }

        if (path === o.url) {
            return answer(200, o.tsx);
        }

        const id = decodeURIComponent(path.match(/^\/api\/building\/rooms\/(.+)\/map$/)?.[1] ?? '');

        if (rooms[id] !== undefined) {
            return answer(200, { install_id: id, source: 'authored', map_version: rooms[id].version ?? 1, map: rooms[id].map });
        }

        return answer(404, null);
    };
}

/** A stop's plaque row (`plates()`'s shape) for an unplanned floor of `ids`. */
const stopRow = (key, ids, theme = null) => ({ floor: key, name: key, label: null, theme, hallway: null, rooms: ids.map((id) => ({ install_id: id, form: 'open', reported: true })) });

/** The page's `Building` and `TilesetLoader`, every room entered and every tileset loaded through the scripted fetch. */
async function held(o, rooms, options = {}) {
    const fetch = scripted(o, rooms, options);
    const building = new o.Building(fetch);
    const tilesets = new o.TilesetLoader(fetch);

    await building.enterRooms(Object.keys(rooms));
    await tilesets.load(Object.values(rooms).flatMap(({ map }) => map.tilesets.map((entry) => o.tilesetUrl(entry.source))));

    return { building, tilesets, fetch };
}

// ── the fixture floors (plan § 6) ───────────────────────────────────────────────────────────────────
const plantOf = (o) => o.gids.plant.find((g) => g.w === 80 && g.h === 128);
const bookcaseOf = (o) => o.gids.bookcase.find((g) => g.w === 72);

function alphaMap(o) {
    const H = 90;

    return resolve(o, makeMap({
        w: 200,
        h: H,
        // one column of wall cells right of the seam (x 40..48), down the whole map; one wall cell under the first slab
        // ...and one partition cell in the first desk's monitor row, between the desk and the centre line
        walls: [...Array.from({ length: H }, (_, r) => [5, r]), [30, 45], [15, 42]],
        pieces: [{ gid: plantOf(o).gid, col: 6, row: 62 }],
        desks: [{ x: 200, y: 200 }, { x: 1200, y: 200 }],
    }));
}

function bravoMap(o, offset = null) {
    const W = 160;
    const H = 90;
    const accents = [];

    for (let r = 0; r < H; r++) {
        for (let c = 0; c < W; c++) {
            accents.push([c, r]);
        }
    }

    return resolve(o, makeMap({
        w: W,
        h: H,
        accents,
        // the partition row y 400..408 from x 0..600; the near-wall row x 0..400 on the top row
        walls: [...Array.from({ length: 75 }, (_, c) => [c, 50]), ...Array.from({ length: 50 }, (_, c) => [c, 0])],
        pieces: [
            // behind the partition (foot y 488), inside the doorway's frustum — the order's RED (aimla's shape)
            { gid: bookcaseOf(o).gid, col: 25, row: 60 },
            // on the last row, off the centre line — round 3's M1
            { gid: bookcaseOf(o).gid, col: 50, row: H - 1 },
            // two pieces on one foot line, the earlier in tile order nearer the centre line — round 3's m4
            { gid: bookcaseOf(o).gid, col: 70, row: 30 },
            { gid: bookcaseOf(o).gid, col: 75, row: 30 },
        ],
        desks: [{ x: 900, y: 100 }],
        offset,
    }));
}

const charlieMap = (o, h, extra = false) => resolve(o, makeMap({ w: 60, h: h / T, tilesets: extra ? [{ firstgid: 1000, source: 'tiles/extra.tsx' }] : [] }));

/** The offsets `bravo`'s tile layers are dragged by: under half a cell, half, over half, nearly whole, and negative. */
const DRAGS = Object.freeze([3, 4, 5, 7, -3]);

const close = (a, b, tol = 0.6) => Math.abs(a - b) <= tol;
const cm = (o, px) => px * o.view.SCALE;

/** The FIXTURES leg. */
async function fixtures(o) {
    const defects = [];
    const say = (cond, what) => { if (!cond) defects.push(what); };
    const { doorView, SCALE, ROOM_H, PARTITION_H } = o.view;
    const seam = 32 + 84 / 2;
    const X = (px) => (seam - px) * SCALE;
    const Z = (py) => py * SCALE;
    const studio = o.modules.studio;
    const cottage = o.modules.cottage;

    say(close(SCALE, 200 / 144, 1e-12) && close(ROOM_H, 160 * 200 / 144, 1e-9) && PARTITION_H === 120, `the figures: SCALE ${SCALE}, ROOM_H ${ROOM_H}, PARTITION_H ${PARTITION_H}`);

    // ── S1: alpha, everything held ────────────────────────────────────────────────────────────────
    {
        const map = alphaMap(o);
        const { building, tilesets } = await held(o, { alpha: { map } });
        const v = doorView(stopRow('alpha', ['alpha']), building, tilesets, o.themes);
        const index = (b) => v.bodies.indexOf(b);
        const column = v.bodies.filter((b) => (b.kind === 'partition' || b.kind === 'wall') && close(b.x1, X(48)) && close(b.x2, X(40)));
        const slab = { x: 200 + 108 - 90, w: 180 };

        say(v.stop === 'alpha' && v.bare === false && v.theme === 'studio', `alpha: the model is ${JSON.stringify({ stop: v.stop, bare: v.bare, theme: v.theme })}`);
        say(v.palette?.['--dv-floor'] === studio.PALETTE.floor.boards && v.palette?.['--dv-wall-top'] === studio.PALETTE.wall.top,
            `alpha: the palette's --dv-floor / --dv-wall-top are ${v.palette?.['--dv-floor']} / ${v.palette?.['--dv-wall-top']}, studio's are ${studio.PALETTE.floor.boards} / ${studio.PALETTE.wall.top}`);
        say(column.length === 89 && column.every((b) => b.x1 > 0 && b.x2 > 0), `alpha: the partition column right of the seam — ${column.length} cells (89 owed, the top-row cell not drawn), X ${column.slice(0, 1).map((b) => `${b.x1.toFixed(1)}..${b.x2.toFixed(1)}`)} cm (owed +36..+47, to the rider's RIGHT)`);
        say(column.filter((b) => b.kind === 'partition').every((b) => b.y2 === PARTITION_H) && column.filter((b) => b.kind === 'wall').length === 1,
            `alpha: the column's interior cells are partitions and its last cell (on the far edge) a wall — ${column.filter((b) => b.kind === 'wall').length} wall(s)`);
        say(!v.bodies.some((b) => (b.kind === 'partition' || b.kind === 'wall') && b.x2 <= X(slab.x) + 0.01 && b.x1 >= X(slab.x + slab.w) - 0.01),
            'alpha: a wall cell inside the first desk\'s slab is drawn — the desk stands on it (the under-slab reading)');

        const tops = v.bodies.filter((b) => b.kind === 'desk-top' && close(b.x2, X(slab.x)));
        const monitors = v.bodies.filter((b) => b.kind === 'monitor' && close(b.x1, X(slab.x + slab.w)));

        say(tops.length === 8 && close(Math.min(...tops.map((b) => b.z1)), Z(336)) && close(Math.max(...tops.map((b) => b.z2)), Z(400)) && tops.every((b) => b.x2 < 0),
            `alpha: the first desk's top — ${tops.length} row strips (8 owed), LEFT of centre, Z ${Math.min(...tops.map((b) => b.z1)).toFixed(1)}..${Math.max(...tops.map((b) => b.z2)).toFixed(1)} cm (owed the slab y 340..400 snapped to rows 336..400: ${Z(336).toFixed(1)}..${Z(400).toFixed(1)})`);
        say(v.bodies.filter((b) => b.kind === 'desk-top').length === 16, `alpha: both desks are in the model — ${v.bodies.filter((b) => b.kind === 'desk-top').length} top strips (16 owed; culling is the painter's)`);
        say(monitors.length === 1 && index(monitors[0]) > index(tops.find((b) => close(b.z1, monitors[0].z1))),
            'alpha: the monitor\'s back is not painted after the desk top it stands on');

        const inner = v.bodies.find((b) => b.kind === 'partition' && close(b.x2, X(120)) && close(b.z1, Z(336)));

        say(inner !== undefined && monitors.length === 1 && index(inner) > index(monitors[0]),
            'alpha: a partition nearer the centre line than the desk, in its monitor\'s row, is painted before the monitor — a desk\'s rank ahead of its distance');

        const plant = v.bodies.find((b) => b.kind === 'billboard' && b.piece === 'plant');
        const cell = o.mapTiles.mapTiles(map, { x: 0, y: 0 }, () => o.read, 'alpha').find((c) => c.kind === 'plant');

        say(plant !== undefined && plant.href === o.svgUri(studio.scenery(o.mapTiles.sceneryInput(cell, 'alpha'))), 'alpha: the plant\'s billboard is not the studio theme\'s own scenery document');
        say(plant !== undefined && close(plant.z1, Z(504)) && column.filter((b) => b.z1 >= Z(504) - 0.01).every((b) => index(b) < index(plant))
            && column.filter((b) => b.z2 <= Z(504) + 0.01).every((b) => index(b) > index(plant)),
            'alpha: the plant (foot y 504) is not painted after every column cell in the rows behind it and before every one in front of it');
        say(v.accents.length === 0, `alpha: ${v.accents.length} accent runs on a floor with no accent`);
    }

    // ── S2: bravo, everything held — and again with its tile layers dragged 3 px (round 3's M3) ─────────────────
    for (const offset of [null, ...DRAGS]) {
        const who = offset === null ? 'bravo' : `bravo dragged ${offset} px`;
        const map = bravoMap(o, offset);
        const H = map.height;
        const { building, tilesets } = await held(o, { bravo: { map } });
        const v = doorView(stopRow('bravo', ['bravo'], 'cottage'), building, tilesets, o.themes);
        const index = (b) => v.bodies.indexOf(b);
        const row = v.bodies.filter((b) => (b.kind === 'partition' || b.kind === 'wall') && close(b.z1, Z(400)));
        const post = row.find((b) => close(b.x2, X(0)));

        say(v.bare === false && v.theme === 'cottage' && v.palette?.['--dv-floor'] === cottage.PALETTE.floor.boards && v.palette?.['--dv-wall-top'] === cottage.PALETTE.wall.top,
            `${who}: the palette's --dv-floor / --dv-wall-top are ${v.palette?.['--dv-floor']} / ${v.palette?.['--dv-wall-top']}, cottage's are ${cottage.PALETTE.floor.boards} / ${cottage.PALETTE.wall.top}`);
        say(row.length === 75 && row.filter((b) => b.kind === 'partition').length === 74 && row.some((b) => b.x1 < 0) && row.some((b) => b.x2 > 0),
            `${who}: the partition row at Z ${Z(400).toFixed(1)} cm — ${row.length} cells (75 owed: 74 partitions crossing the centre line and the post)`);
        say(post?.kind === 'wall' && post.y2 === ROOM_H, `${who}: the row's x 0 cell, on the footprint's left edge, is ${post?.kind ?? 'absent'} — a ${ROOM_H.toFixed(0)}-cm post is owed`);
        say(!v.bodies.some((b) => (b.kind === 'partition' || b.kind === 'wall') && b.z1 < Z(T) - 0.01), `${who}: a near-wall cell on the footprint's top row is drawn`);
        say(v.bodies.filter((b) => b.kind === 'desk-top').every((b) => b.x1 < -1000), `${who}: the far-left desk is not where its slot is`);
        say(v.accents.length >= 1, `${who}: no accent run on a floor of accent`);

        const cells = o.mapTiles.mapTiles(map, { x: 0, y: 0 }, () => o.read, 'bravo').filter((c) => c.kind === 'bookcase');
        const behind = v.bodies.find((b) => b.kind === 'billboard' && close(b.z1, Z(488)));

        say(behind !== undefined && behind.href === o.svgUri(cottage.scenery(o.mapTiles.sceneryInput(cells.find((c) => c.col === 25 && c.row === 60), 'bravo'))), `${who}: the bookcase's billboard is not the cottage theme's own scenery document`);
        say(behind !== undefined && row.every((b) => index(behind) < index(b)), `${who}: the bookcase behind the partition is painted over it (pieces last — the order's RED)`);

        const farWall = v.bodies.find((b) => b.kind === 'far-wall');
        const last = v.bodies.find((b) => b.kind === 'billboard' && close(b.z1, Z(H * T)));

        say(last !== undefined && last.x2 < 0 && index(last) > index(farWall), `${who}: the bookcase on the last row, off the centre line, is painted under the far wall it stands against (round 3's M1)`);

        const pair = v.bodies.filter((b) => b.kind === 'billboard' && close(b.z1, Z(31 * T)));

        say(pair.length === 2 && pair[0].tile < pair[1].tile, `${who}: two pieces on one foot line are not painted in the floor page's tile order (round 3's m4)`);
    }

    // ── S6: charlie, two rooms of different heights; and its second room naming a tileset the loader lacks ────────
    {
        const { building, tilesets } = await held(o, { charlie: { map: charlieMap(o, 296) }, 'charlie-2': { map: charlieMap(o, 496) } });
        const v = doorView(stopRow('charlie', ['charlie', 'charlie-2']), building, tilesets, o.themes);
        const far = v.bodies.filter((b) => b.kind === 'far-wall').map((b) => b.z1).sort((a, b) => a - b);
        const sides = new Set(v.bodies.filter((b) => b.kind === 'side-wall').map((b) => b.x1.toFixed(2)));

        say(far.length === 2 && close(far[0], Z(296)) && close(far[1], Z(496)), `charlie: the far walls stand at Z ${far.map((z) => z.toFixed(1)).join(', ')} cm — owed one per footprint, at ${Z(296).toFixed(1)} and ${Z(496).toFixed(1)}`);
        say(sides.size === 4, `charlie: side-wall strips at ${sides.size} distinct X — owed four, each footprint's two edges`);
        say(close(v.floor.x1, X(480 + 64 + 480)) && close(v.floor.x2, X(0)) && close(v.floor.z2, Z(496)), 'charlie: the floor plane is not the floor\'s extent, the gap between the rooms included');

        const missing = await held(o, { charlie: { map: charlieMap(o, 296) }, 'charlie-2': { map: charlieMap(o, 496, true) } });
        const bare = doorView(stopRow('charlie', ['charlie', 'charlie-2']), missing.building, missing.tilesets, o.themes);

        say(bare.bare === true && bare.reason === 'tileset' && bare.bodies.length === 0, `charlie: a tileset the loader lacks reads ${JSON.stringify({ bare: bare.bare, reason: bare.reason, bodies: bare.bodies.length })} — the whole floor bare, reason tileset, is owed`);
    }

    // ── a planned floor: a hallway at the floor's origin and a room placed below it (the plan's § 7 asks for one) ─────
    {
        const hallway = resolve(o, makeMap({ w: 60, h: 12, accents: Array.from({ length: 60 }, (_, c) => [c, 5]) }));
        const room = charlieMap(o, 296);
        const { building, tilesets } = await held(o, { delta: { map: room } });
        const row = { ...stopRow('delta', ['delta']), hallway, rooms: [{ install_id: 'delta', form: 'open', reported: true, origin: { x: 0, y: 96 } }] };
        const v = doorView(row, building, tilesets, o.themes);
        const far = v.bodies.filter((b) => b.kind === 'far-wall');

        say(v.bare === false && far.length === 1 && close(far[0].z1, Z(96 + 296)), `delta (planned): ${JSON.stringify({ bare: v.bare, reason: v.reason, far: far.map((b) => b.z1.toFixed(1)) })} — one far wall, the room's, at ${Z(392).toFixed(1)} cm is owed (the hallway contributes none)`);
        say(v.accents.length === 1 && close(v.accents[0].z1, Z(40)), `delta (planned): the hallway's accent row is ${v.accents.length} run(s) — one, at ${Z(40).toFixed(1)} cm, is owed`);
        say(close(v.floor.z2, Z(392)), 'delta (planned): the floor plane is not the extent the hallway and the room make');
    }

    // ── echo: a planned room at origin x 3, off the 8-px lattice the threshold's seam (x 74) sits on, so a partition cell
    // (x 67..75) straddles the centre line with neighbours on both sides, and a desk straddles it with a partition cell
    // touching its right end ─────
    {
        const hallway = resolve(o, makeMap({ w: 60, h: 12 }));
        const room = resolve(o, makeMap({
            w: 60,
            h: 37,
            walls: [...Array.from({ length: 21 }, (_, c) => [c, 6]), [6, 20]],
            desks: [{ x: 38, y: 0 }],
        }));
        const { building, tilesets } = await held(o, { echo: { map: room } });
        const row = { ...stopRow('echo', ['echo']), hallway, rooms: [{ install_id: 'echo', form: 'open', reported: true, origin: { x: 3, y: 96 } }] };
        const v = doorView(row, building, tilesets, o.themes);
        const index = (b) => v.bodies.indexOf(b);
        const partitionRow = v.bodies.filter((b) => b.kind === 'partition' && close(b.z1, Z(96 + 48)));
        const straddler = partitionRow.find((b) => b.x1 < 0 && b.x2 > 0);
        const beside = partitionRow.filter((b) => close(b.x1, straddler?.x2 ?? NaN) || close(b.x2, straddler?.x1 ?? NaN));

        say(straddler !== undefined && beside.length === 2 && beside.every((b) => index(b) < index(straddler)),
            `echo: the partition cell straddling the centre line is not painted after both its neighbours (${beside.length} found) — the straddle clause`);

        const cell = v.bodies.find((b) => b.kind === 'partition' && close(b.z1, Z(96 + 160)));
        const top = v.bodies.find((b) => b.kind === 'desk-top' && close(b.z1, Z(96 + 160)));

        say(cell !== undefined && top !== undefined && top.x1 < 0 && top.x2 > 0 && close(cell.x1, top.x2) && index(top) > index(cell),
            'echo: the desk straddling the centre line is not painted after the partition touching its right end — a distance read off the body\'s centre');
    }

    // ── the bare routes (plan § 5) ──────────────────────────────────────────────────────────────────
    {
        const map = bravoMap(o);
        const never = await held(o, { bravo: { map } }, { fail: ['/rooms/bravo/'] });
        const s3 = doorView(stopRow('bravo', ['bravo'], 'cottage'), never.building, never.tilesets, o.themes);

        say(s3.bare && s3.reason === 'mapless' && s3.palette?.['--dv-floor'] === cottage.PALETTE.floor.boards && s3.bodies.length === 0,
            `S3 (bravo's map 503, never held): ${JSON.stringify({ bare: s3.bare, reason: s3.reason, palette: s3.palette !== null })} — bare, mapless, in cottage's palette is owed`);

        // S3b (m-g; m2's wording): bravo held at map_version 1, then the layout reporting 2, and the held map's refetch
        // answering 503 — so `room()` answers `{ held: <the v1 map>, failure: { status: 503 } }`.
        const fetchOk = scripted(o, { bravo: { map, version: 1 } });
        const building = new o.Building(fetchOk);
        const tilesets = new o.TilesetLoader(fetchOk);

        await building.enterRooms(['bravo']);
        await tilesets.load([o.url]);

        const after = scripted(o, {}, { fail: ['/rooms/bravo/'], layout: { layout: { layout_version: 2, floors: [] }, rooms: [{ install_id: 'bravo', map_version: 2 }] } });
        const swap = { failing: false };
        const page = new o.Building(async (path, init) => (swap.failing ? after : fetchOk)(path, init));

        await page.enterRooms(['bravo']);
        swap.failing = true;
        await page.fetchLayout();
        await page.enterRooms(['bravo']);

        const s3b = doorView(stopRow('bravo', ['bravo'], 'cottage'), page, tilesets, o.themes);

        say(page.room('bravo')?.held?.map_version === 1 && page.room('bravo')?.failure !== null, `S3b: the scripted page does not hold v1 under a failure (${JSON.stringify(page.room('bravo')?.failure)})`);
        say(s3b.bare && s3b.reason === 'mapless', `S3b (a held v1 map under a failed refetch): ${JSON.stringify({ bare: s3b.bare, reason: s3b.reason })} — bare, mapless is owed (F16's extent is not a map to draw)`);
        say(doorView(stopRow('bravo', ['bravo'], 'cottage'), building, tilesets, o.themes).bare === false, 'S3b\'s control: the same map held with no failure is not drawn');

        const s5 = doorView(stopRow('bravo', ['bravo'], 'cottage'), building, tilesets, { ...o.themes, modules: { ...o.themes.modules, cottage: null } });

        say(s5.bare && s5.reason === 'theme' && s5.palette === null, `S5 (the cottage module not held): ${JSON.stringify({ bare: s5.bare, reason: s5.reason, palette: s5.palette })} — bare, theme, no palette is owed`);

        const none = doorView(stopRow('bravo', ['bravo'], 'cottage'), building, tilesets, { ...o.themes, registry: null });

        say(none.bare && none.reason === 'theme' && none.palette === null, `the registry not held: ${JSON.stringify({ bare: none.bare, reason: none.reason })} — bare, theme is owed`);

        const s7 = doorView(null, building, tilesets, o.themes);

        say(s7.bare && s7.reason === 'stop' && s7.palette === null, `S7 (a stop the stops no longer offer): ${JSON.stringify({ bare: s7.bare, reason: s7.reason })}`);
    }

    return defects;
}

// ── the ORDER leg (plan § 10) ───────────────────────────────────────────────────────────────────────
const rng = (seed) => { let s = seed >>> 0; return () => { s = (s * 1664525 + 1013904223) % 4294967296; return s / 4294967296; }; };

/** One random floor: one or two rooms, wall runs, desks at unaligned y (slabs disjoint), pieces, layers sometimes off the grid. */
function scene(o, rnd) {
    const rooms = {};
    const count = rnd() < 0.5 ? 1 : 2;
    const standing = Object.entries(o.gids).filter(([kind]) => kind !== 'wall' && kind !== 'accent').flatMap(([, list]) => list);

    for (let r = 0; r < count; r++) {
        const W = 60 + Math.floor(rnd() * 164);
        const H = 30 + Math.floor(rnd() * 58);
        const walls = [];
        const pieces = [];
        const desks = [];
        const slabs = [];

        for (let n = Math.floor(rnd() * 11); n > 0; n--) {
            const vertical = rnd() < 0.5;
            const len = 3 + Math.floor(rnd() * 40);
            const c0 = Math.floor(rnd() * W);
            const r0 = Math.floor(rnd() * H);

            for (let k = 0; k < len; k++) {
                const c = vertical ? c0 : c0 + k;
                const rr = vertical ? r0 + k : r0;

                if (c < W && rr < H) {
                    walls.push([c, rr]);
                }
            }
        }

        for (let n = Math.floor(rnd() * 7), tries = 0; n > 0 && tries < 60; tries++) {
            const x = Math.floor(rnd() * (W * T - 216));
            const y = Math.floor(rnd() * (H * T - 240));
            const sx = x + 18;
            const sy0 = Math.floor((y + 140) / T) * T;
            const sy1 = Math.ceil((y + 200) / T) * T;

            if (slabs.every((s) => sx + 180 <= s.x || s.x + 180 <= sx || sy1 <= s.y0 || s.y1 <= sy0)) {
                slabs.push({ x: sx, y0: sy0, y1: sy1 });
                desks.push({ x, y });
                n--;
            }
        }

        for (let n = Math.floor(rnd() * 9); n > 0; n--) {
            const p = standing[Math.floor(rnd() * standing.length)];
            const cols = W - Math.ceil(p.w / T);
            const least = Math.ceil(p.h / T) - 1;
            const row = rnd() < 0.25 ? H - 1 : least + Math.floor(rnd() * (H - least));

            if (cols > 0 && row < H) {
                pieces.push({ gid: p.gid, col: Math.floor(rnd() * cols), row });
            }
        }

        rooms[`room-${r}`] = resolve(o, makeMap({ w: W, h: H, walls, pieces, desks, offset: rnd() < 0.3 ? Math.floor(rnd() * 15) - 7 : null }));
    }

    const building = { room: (id) => ({ held: { map: rooms[id] }, failure: null }), mapless: () => false };
    const tilesets = { held: new Map([[o.url, { tileset: o.read }]]) };

    return o.view.doorView(stopRow('random', Object.keys(rooms), rnd() < 0.5 ? 'cottage' : null), building, tilesets, o.themes);
}

/** Where a ray from the eye along `d` first meets an axis-aligned body, or `null`. */
function hit(b, d) {
    let t0 = 0;
    let t1 = Infinity;

    for (const [lo, hi, i] of [[b.x1, b.x2, 0], [b.y1, b.y2, 1], [b.z1, b.z2, 2]]) {
        if (Math.abs(d[i]) < 1e-12) {
            if (EYE[i] < lo || EYE[i] > hi) {
                return null;
            }

            continue;
        }

        let a = (lo - EYE[i]) / d[i];
        let c = (hi - EYE[i]) / d[i];

        if (a > c) {
            [a, c] = [c, a];
        }

        t0 = Math.max(t0, a);
        t1 = Math.min(t1, c);

        if (t0 > t1 + 1e-12) {
            return null;
        }
    }

    return t0;
}

const AXES = Object.freeze([['z1', 'z2', 2], ['x1', 'x2', 0], ['y1', 'y2', 1]]);

/**
 * Which of two bodies a ray meets at ONE depth is in front — the one on the eye's side of a plane they touch at; for two
 * bodies on one plane, a piece over the far wall it stands against and, between two pieces, the later in the floor page's
 * tile order (`'tile'`). `null` where no rule names the pair: an unowed tie, counted and printed.
 */
function inFront(a, b) {
    for (const [lo, hi, i] of AXES) {
        const coplanar = a[hi] <= b[lo] + EPS && b[hi] <= a[lo] + EPS;

        if (!coplanar && a[hi] <= b[lo] + EPS) {
            return EYE[i] < a[hi] ? a : b;
        }

        if (!coplanar && b[hi] <= a[lo] + EPS) {
            return EYE[i] < b[hi] ? b : a;
        }
    }

    const kinds = [a.kind, b.kind].sort().join('/');

    if (kinds === 'billboard/far-wall') {
        return a.kind === 'billboard' ? a : b;
    }

    return kinds === 'billboard/billboard' ? 'tile' : null;
}

/** The doorway at `Z = 0` seen from the eye — a body whose projection misses it meets no ray through the doorway. */
function inFrustum(b) {
    const xs = [];
    const ys = [];

    for (const x of [b.x1, b.x2]) for (const z of [b.z1, b.z2]) for (const y of [b.y1, b.y2]) {
        xs.push(x / (z - EYE[2]));
        ys.push((EYE[1] - y) / (z - EYE[2]));
    }

    return Math.min(...xs) < 55 / 120 && -55 / 120 < Math.max(...xs) && Math.min(...ys) < 155 / 120 && (155 - 200) / 120 < Math.max(...ys);
}

/** The ORDER leg: `scenes` random floors per seed, `rays` rays each. Defects name each violated class. */
function order(o, { scenes = 260, rays = 220, seeds = [12345, 777], print = false } = {}) {
    const defects = [];

    for (const seed of seeds) {
        const rnd = rng(seed);
        const violations = new Map();
        const unowed = new Map();
        let pairs = 0;
        let ties = 0;
        let bodies = 0;
        let pieces = 0;
        let floors = 0;

        for (let s = 0; s < scenes; s++) {
            const v = scene(o, rnd);

            if (v.bare) {
                continue;
            }

            floors++;
            bodies += v.bodies.length;
            pieces += v.bodies.filter((b) => b.kind === 'billboard').length;

            const seen = v.bodies.map((b, i) => ({ b, i })).filter(({ b }) => inFrustum(b));

            for (let r = 0; r < rays; r++) {
                const d = [-55 + 110 * rnd(), 200 * rnd() - EYE[1], -EYE[2]];
                const hits = seen.map(({ b, i }) => ({ t: hit(b, d), b, i })).filter((h) => h.t !== null).sort((p, q) => p.t - q.t);

                for (let p = 0; p < hits.length; p++) {
                    for (let q = p + 1; q < hits.length; q++) {
                        const [near, far] = [hits[p], hits[q]];
                        let front;
                        let back;

                        if (far.t - near.t > EPS) {
                            pairs++;
                            [front, back] = [near, far];
                        } else {
                            ties++;
                            const f = inFront(near.b, far.b);

                            if (f === null) {
                                const key = [near.b.kind, far.b.kind].sort().join(' / ');

                                unowed.set(key, (unowed.get(key) ?? 0) + 1);
                                continue;
                            }

                            const later = f === 'tile' ? (near.b.tile > far.b.tile ? near : far) : f === near.b ? near : far;

                            [front, back] = later === near ? [near, far] : [far, near];
                        }

                        if (front.i < back.i) {
                            const key = `${back.b.kind} over ${front.b.kind}${far.t - near.t > EPS ? '' : ' (a tie)'}`;

                            violations.set(key, (violations.get(key) ?? 0) + 1);
                        }
                    }
                }
            }
        }

        const total = [...violations.values()].reduce((a, b) => a + b, 0);
        const list = [...violations.entries()].sort((a, b) => b[1] - a[1]).map(([k, n]) => `${k} ×${n}`).join('; ');

        if (print || total > 0) {
            console.log(`  seed ${seed}: ${floors} floors drawn of ${scenes}, ${bodies} bodies (${pieces} pieces), ${rays} rays each; ${pairs} (near, far) pairs owed an order, ${ties} ties`
                + ` — violations ${total}${total ? `: ${list}` : ''}; ties no rule names: ${unowed.size === 0 ? 'none' : [...unowed.entries()].map(([k, n]) => `${k} ×${n}`).join('; ')}`);
        }

        if (floors < scenes / 2 || pairs === 0 || ties === 0) {
            defects.push(`seed ${seed}: the order leg measured nothing — ${floors} floors drawn, ${pairs} pairs, ${ties} ties`);
        }

        if (total > 0) {
            defects.push(`seed ${seed}: ${total} order violations — ${list}`);
        }
    }

    return defects;
}

const LEGS = { fixtures, order: (o) => order(o) };

// ── run ─────────────────────────────────────────────────────────────────────────────────────────────
const argv = process.argv.slice(2);

if (argv[0] === '--order') {
    const [scenes = '800', rays = '300', ...seeds] = argv.slice(1);
    const defects = order(await open(shipped), { scenes: Number(scenes), rays: Number(rays), seeds: seeds.length ? seeds.map(Number) : [12345, 777], print: true });

    console.log(defects.length === 0 ? 'ORDER CLEAN' : `ORDER: ${defects.length} defect(s)`);
    process.exit(defects.length === 0 ? 0 : 1);
}

const real = await open(shipped);

for (const [leg, run] of Object.entries(LEGS)) {
    console.log(`\n${leg} — the shipped tree`);

    const defects = await run(real);

    check(defects.length === 0, `${leg}: ${defects.length === 0 ? 'clean' : defects.slice(0, 6).join('; ')}`);
}

console.log('\nthe planted REDs — each must red its leg, naming what the RED says it names');

const PLANTS = [
    ['the mirror dropped (X = (px − threshold.x) · SCALE)', 'fixtures', "to the rider's RIGHT",
        (t) => edit(VIEW(t), 'const X = (px) => (threshold.x - px) * SCALE;', 'const X = (px) => (px - threshold.x) * SCALE;')],
    ['the threshold at the extent\'s edge (the seam\'s 74 px dropped)', 'fixtures', 'the partition column right of the seam',
        (t) => edit(VIEW(t), 'const threshold = thresholdOf(placement.extent);', 'const threshold = { x: placement.extent.x, y: placement.extent.y };')],
    ['the house theme\'s palette for every stop', 'fixtures', "cottage's are",
        (t) => edit(VIEW(t), 'const module = resolved === null ? null : themes.modules[resolved] ?? null;', 'const module = resolved === null ? null : themes.modules[themes.registry.house] ?? null;')],
    ['a cell under a desk\'s slab drawn', 'fixtures', 'the under-slab reading',
        (t) => edit(VIEW(t), 'if (y === f.y || underSlab(x, y)) {', 'if (y === f.y) {')],
    ['the footprint\'s top row drawn', 'fixtures', 'a near-wall cell on the footprint\'s top row is drawn',
        (t) => edit(VIEW(t), 'if (y === f.y || underSlab(x, y)) {', 'if (underSlab(x, y)) {')],
    ['bare read off the held map alone (F16\'s failure ignored)', 'fixtures', 'S3b (a held v1 map under a failed refetch)',
        (t) => edit(VIEW(t), 'if (ids.some((id) => building.mapless(id))) {', 'if (ids.some((id) => (building.room(id)?.held?.map ?? null) === null)) {')],
    ['the tilesets ignored', 'fixtures', 'the whole floor bare, reason tileset',
        (t) => edit(VIEW(t), 'if (urls.some((url) => held.get(url)?.tileset === undefined)) {', 'if (false) {')],
    ['the walls from the union extent, not each footprint', 'fixtures', 'owed one per footprint',
        (t) => edit(VIEW(t), 'for (const { footprint: f } of grids.filter((g) => g.room)) {', 'for (const f of [placement.extent]) {')],
    ['pieces last (every billboard after every other body)', 'fixtures', 'pieces last — the order\'s RED',
        (t) => edit(VIEW(t), '    const extent = placement.extent;\n', '    bodies.sort((a, b) => (a.body.kind === \'billboard\') - (b.body.kind === \'billboard\'));\n    const extent = placement.extent;\n')],
    ['pieces last, through the random floors', 'order', 'billboard',
        (t) => edit(VIEW(t), '    const extent = placement.extent;\n', '    bodies.sort((a, b) => (a.body.kind === \'billboard\') - (b.body.kind === \'billboard\'));\n    const extent = placement.extent;\n')],
    ['round 3\'s M1: the far wall not first on its boundary (the order before the fold)', 'order', 'far-wall over billboard (a tie)',
        (t) => edit(VIEW(t), 'b.key - a.key || a.tier - b.tier || (a.tier === 1 ? a.seq - b.seq : b.mx - a.mx || a.rank - b.rank || a.seq - b.seq)', 'b.key - a.key || b.mx - a.mx || a.rank - b.rank || a.seq - b.seq')],
    ['round 3\'s M1, on the fixture', 'fixtures', 'round 3\'s M1',
        (t) => edit(VIEW(t), 'b.key - a.key || a.tier - b.tier || (a.tier === 1 ? a.seq - b.seq : b.mx - a.mx || a.rank - b.rank || a.seq - b.seq)', 'b.key - a.key || b.mx - a.mx || a.rank - b.rank || a.seq - b.seq')],
    ['round 3\'s m4: two pieces on one foot line by their distance, not the tile order', 'fixtures', 'round 3\'s m4',
        (t) => edit(VIEW(t), '(a.tier === 1 ? a.seq - b.seq : ', '(a.tier === 1 ? b.mx - a.mx || a.seq - b.seq : ')],
    ['round 3\'s M3: no snap, the cells left where a dragged layer puts them', 'fixtures', 'bravo dragged 3 px',
        (t) => edit(VIEW(t), 'const whole = (v) => Math.trunc(v / T) * T;', 'const whole = (v) => v;')],
    ['round 3\'s M3, through the random floors', 'order', 'violations',
        (t) => edit(VIEW(t), 'const whole = (v) => Math.trunc(v / T) * T;', 'const whole = (v) => v;')],
    ['the offset ROUNDED to the grid (half a cell or more moves the layer a cell)', 'fixtures', 'bravo dragged 4 px',
        (t) => edit(VIEW(t), 'const whole = (v) => Math.trunc(v / T) * T;', 'const whole = (v) => Math.round(v / T) * T;')],
    ['the offset FLOORED to the grid (a negative offset moves the layer a cell)', 'fixtures', 'bravo dragged -3 px',
        (t) => edit(VIEW(t), 'const whole = (v) => Math.trunc(v / T) * T;', 'const whole = (v) => Math.floor(v / T) * T;')],
    ['no straddle clause (a body across the centre line at its nearer edge\'s distance)', 'fixtures', 'the straddle clause',
        (t) => edit(VIEW(t), 'const mxOf = (x1, x2) => (x1 <= 0 && x2 >= 0 ? 0 : Math.min(Math.abs(x1), Math.abs(x2)));', 'const mxOf = (x1, x2) => Math.min(Math.abs(x1), Math.abs(x2));')],
    ['a body\'s distance from the centre line read off its centre', 'fixtures', 'a distance read off the body\'s centre',
        (t) => edit(VIEW(t), 'const mxOf = (x1, x2) => (x1 <= 0 && x2 >= 0 ? 0 : Math.min(Math.abs(x1), Math.abs(x2)));', 'const mxOf = (x1, x2) => Math.abs(x1 + x2) / 2;')],
    ['a desk\'s rank ahead of its distance', 'fixtures', 'a desk\'s rank ahead of its distance',
        (t) => edit(VIEW(t), 'b.mx - a.mx || a.rank - b.rank || a.seq - b.seq)', 'a.rank - b.rank || b.mx - a.mx || a.seq - b.seq)')],
    ['a monitor at its own distance, not its desk\'s', 'order', 'over monitor',
        (t) => edit(VIEW(t), "y1: DESK_H, y2: MONITOR_TOP }), { mx, rank: 1 });", "y1: DESK_H, y2: MONITOR_TOP }), { rank: 1 });")],
];

for (const [red, leg, says, plant] of PLANTS) {
    let t = null;

    try {
        t = mirror(plant);

        const defects = await LEGS[leg](await open(t));
        const named = defects.find((d) => d.includes(says));

        check(named !== undefined, `RED — ${red}: the ${leg} leg ${named !== undefined ? `reds: ${named.slice(0, 170)}` : `stayed ${defects.length === 0 ? 'green' : `red for another reason: ${defects[0].slice(0, 200)}`}`}`);
    } catch (e) {
        check(false, `RED — ${red}: the plant could not be run — ${e.message}`);
    } finally {
        if (t !== null) {
            rmSync(t.root, { recursive: true, force: true });
        }
    }
}

console.log(failures === 0 ? '\nALL DOOR-VIEW CHECKS PASS' : `\n${failures} DOOR-VIEW CHECK FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);

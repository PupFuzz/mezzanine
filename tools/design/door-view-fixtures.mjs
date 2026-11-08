// THE CAB's DOOR-VIEW FIXTURES — the plan's § 6 floors (`docs/design/plan-12987-cab-door-view.md`), built inline as 8-px maps
// over the shipped tileset: one source for the model's selftest (`tools/design/door-view.selftest.mjs`) and the cab's paint
// and wiring probe (`server/tests/Feature/Floor/door-view-leg.mjs`), so the two drive the same floors. Node, no dependencies.
//
// Most builders take `o`, the kit a caller loaded from the module tree it drives (`kit()`, plus that tree's `Building`,
// `TilesetLoader` and `tilesetUrl` for `held()`), so a planted mirror of the tree builds its fixtures through its own reader.

const T = 8;

/**
 * The shipped tileset read through a tree's `floor/tileset.js` module: its URL, its text, the read set, and every tile of it by
 * kind and size — `gid = id + 1` under the maps' one `firstgid`.
 *
 * @param {object} tileset the tree's `floor/tileset.js` module
 * @param {string} tsx `resources/floor/tiles/floor-plane.tsx`'s text
 */
export function kit(tileset, tsx) {
    const url = tileset.tilesetUrl('tiles/floor-plane.tsx');
    const read = tileset.readTileset(tsx, url);
    const gids = {};

    for (let id = 0; id < 256; id++) {
        const tile = read.tile(id);
        const kind = tile?.properties?.kind;

        if (typeof kind === 'string') {
            (gids[kind] ??= []).push({ gid: id + 1, w: tile.sw ?? T, h: tile.sh ?? T });
        }
    }

    return { url, tsx, read, gids };
}

// ── maps, built inline ──────────────────────────────────────────────────────────────────────────────
export const TILESET_ENTRY = Object.freeze({ firstgid: 1, source: 'tiles/floor-plane.tsx' });

/**
 * An 8-px map: `walls` and `accents` as `[col, row]` cells, `pieces` as `{gid, col, row}` (the tile bottom-aligned to
 * its cell, so its foot is `(row + 1) · 8`), `desks` as Tiled objects, `offset` on both tile layers, `tilesets` extra.
 */
export function makeMap({ w, h, walls = [], accents = [], pieces = [], desks = [], offset = null, tilesets = [] }) {
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
export function resolve(o, map) {
    for (const layer of map.layers) {
        if (layer.type === 'tilelayer') {
            layer.data = layer.data.map((g) => (g === 'WALL' ? o.gids.wall[0].gid : g === 'ACCENT' ? o.gids.accent[0].gid : g));
        }
    }

    return map;
}

/** A scripted same-origin fetch: each path's answer, or a 503 per path named in `fail`. */
export function scripted(o, rooms, { fail = [], layout = null } = {}) {
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
export const stopRow = (key, ids, theme = null) => ({ floor: key, name: key, label: null, theme, hallway: null, rooms: ids.map((id) => ({ install_id: id, form: 'open', reported: true })) });

/** The page's `Building` and `TilesetLoader`, every room entered and every tileset loaded through the scripted fetch. */
export async function held(o, rooms, options = {}) {
    const fetch = scripted(o, rooms, options);
    const building = new o.Building(fetch);
    const tilesets = new o.TilesetLoader(fetch);

    await building.enterRooms(Object.keys(rooms));
    await tilesets.load(Object.values(rooms).flatMap(({ map }) => map.tilesets.map((entry) => o.tilesetUrl(entry.source))));

    return { building, tilesets, fetch };
}

// ── the fixture floors (plan § 6) ───────────────────────────────────────────────────────────────────
export const plantOf = (o) => o.gids.plant.find((g) => g.w === 80 && g.h === 128);
export const bookcaseOf = (o) => o.gids.bookcase.find((g) => g.w === 72);

export function alphaMap(o) {
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

export function bravoMap(o, offset = null) {
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

export const charlieMap = (o, h, extra = false) => resolve(o, makeMap({ w: 60, h: h / T, tilesets: extra ? [{ firstgid: 1000, source: 'tiles/extra.tsx' }] : [] }));

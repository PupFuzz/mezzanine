/**
 * THE FLOOR's TILES, OVER A FAKE DOM: the painter draws every tile the scene emits as exactly that tile, in
 * an order that keeps the picture, and draws identical neighbours as ONE primitive — so no edge falls
 * between them. `node`, no dependencies. Driven by `TheFloorsTilesDrawWithoutSeamsTest`.
 *
 * ⛔ WHY: a tile drawn as a primitive of its own has its own antialiased edge, and where two neighbours'
 * shared edge lands on a fractional device pixel — any zoom but a whole one — each covers that pixel
 * partly and the plane under them shows through as a hairline: the room's theme in thin lines between the
 * planks, a dotted ladder down an 8 px wall strip. No browser runs here, so this reads the property that
 * causes the hairline rather than the pixels: two identical tiles sharing an edge, drawn by two nodes.
 *
 * ⛔ THE CHECKS PER CASE, read from what `createPainter().paint()` wrote under `g.tiles`:
 *   - THE SAME TILES: every painted primitive is expanded back into the tiles it draws — a pattern-filled
 *     path into the lattice cells of its rects, a per-tile viewport (the form the painter used to write,
 *     so a return to it reds as the seam it is and not as a tile gone missing) into its one — each with
 *     its image, its window onto the image, the image's size, its cell, its flips and its opacity, and
 *     the result must be exactly the scene's tiles, none missing and none extra;
 *   - THE SAME ORDER WHERE IT SHOWS: any two tiles that overlap are painted in the scene's order, each
 *     room's plane under its own tiles and over the hallway's;
 *   - ONE PASS PER PRIMITIVE: no primitive paints tiles of two rooms, or of a room and the hallway;
 *   - NO SEAM (the cases marked `seamless`): two tiles of one pass that read back as the same picture and
 *     share an edge are painted by ONE node.
 *
 * ⛔ THE READER IS CLOSED. Every attribute and child the painter writes under `g.tiles`, and on the
 * pattern and image a path is filled with, is either read into the picture above or is a defect naming
 * it — so nothing the painter writes on a tile's nodes goes uncompared.
 *
 * ⛔ THE INPUTS ARE STRATA, EACH SIZED AT RUN TIME AND HELD TO ITS PRODUCT. The SHIPPED default room
 * (`resources/floor/default.tmj` through the shipped tileset reader and `mapTiles()`) and one planted
 * lattice case come first; then:
 *   - MERGE: every field a real `mapTiles()` cell carries but its place and its room, crossed with the
 *     four directions, a pair or a chain of two before the differing tile, and the differing tile first
 *     or last in draw order — two neighbours differing in that field alone;
 *   - ORDER: a tile between two identical tiles that overlaps the second, in each direction; one place
 *     drawn by three layers; a hallway tile under a room's plane;
 *   - OFFSETS: lattices built through `mapTiles()` under nested group offsets — whole, dyadic and
 *     non-dyadic — and tileset offsets, whose identical neighbours must still be one primitive;
 *   - ROOMS: two rooms' identical tiles touching across their boundary, in each direction;
 *   - RANDOM: a fixed-seed fill, run through the same checks and counted toward no stratum.
 *
 * argv: `<floor module dir>` (the shipped `server/public/js/floor`, or a mutated copy's). stdout:
 * `{ cases: { <name>: { tiles, primitives, nodes } }, strata: { <name>: { cells, product, factors } },
 * fields, look, defects: [ … ] }` — `fields` is the merge stratum's field set as derived, `look` the
 * fields of `tileLook()` (the controls' source for dropping each in turn).
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const FLOOR = process.argv[2] ?? join(HERE, '..', '..', '..', 'public', 'js', 'floor');
const RESOURCES = join(HERE, '..', '..', '..', '..', 'resources', 'floor');
const mod = async (p) => import(pathToFileURL(join(FLOOR, p)).href);

const host = new Node('div');

globalThis.document = {
    activeElement: null,
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
};
globalThis.CSS = { escape: (s) => s };

const { createPainter } = await mod('painter.js');
const { mapTiles, tileLook } = await mod('scene.js');
const { FLOOR_ART, readTileset } = await mod('tileset.js');

const defects = [];
const strata = {};

/** A stratum's planted cells against the product of its factors — a cell short or extra is a defect. */
const stratum = (name, cells, factors) => {
    const product = Object.values(factors).reduce((p, n) => p * n, 1);

    strata[name] = { cells, product, factors };

    if (cells !== product) {
        defects.push(`the ${name} stratum planted ${cells} cells, not the product of its factors `
            + `${Object.entries(factors).map(([k, n]) => `${k} ${n}`).join(' × ')} = ${product}`);
    }
};

// ── the cases ─────────────────────────────────────────────────────────────────────────────────────
const tile = (over) => ({
    room: null, layer: 'planted', w: 8, h: 8, image: '/art/floor/tiles/planted/strip.svg', iw: 8, ih: 8,
    tileset: '/art/floor/tiles/planted.tsj', sx: 0, sy: 0, sw: 8, sh: 8, flip_h: false, flip_v: false, flip_d: false,
    opacity: 1, properties: { kind: 'planted' }, ...over,
});
const lattice = (cols, rows, at, over) => Array.from({ length: rows }, (_, r) => Array.from({ length: cols },
    (__, c) => tile({ ...over, x: at.x + c * (over.w ?? 8), y: at.y + r * (over.h ?? 8) }))).flat();

const shipped = (() => {
    const map = JSON.parse(readFileSync(join(RESOURCES, 'default.tmj'), 'utf8'));
    const held = new Map();
    const tilesetFor = (url) => {
        if (!held.has(url)) {
            held.set(url, readTileset(readFileSync(join(RESOURCES, url.slice(FLOOR_ART.length)), 'utf8'), url));
        }

        return held.get(url);
    };
    const tiles = mapTiles(map, { x: 0, y: 0 }, tilesetFor, 'shipped');

    return {
        seamless: true,
        tiles,
        planes: [{ install_id: 'shipped', x: 0, y: 0, w: map.width * map.tilewidth, h: map.height * map.tileheight, theme: 'oak' }],
    };
})();

const sheet = { image: '/art/floor/tiles/planted/sheet.png', iw: 64, ih: 48, sx: 16, sy: 8, sw: 16, sh: 16, w: 16, h: 16 };
const DIRS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

/**
 * A tile that differs from `base` in `field` alone: a flag flipped, a number halved, a name changed, an
 * object given a member. A field of any other kind cannot be varied — `null`, and its cells go unplanted,
 * which the stratum's count reds.
 */
const differIn = (base, field) => {
    const v = base[field];

    if (typeof v === 'boolean') {
        return { ...base, [field]: !v };
    }

    if (typeof v === 'number' && v !== 0) {
        return { ...base, [field]: v / 2 };
    }

    if (typeof v === 'string') {
        return { ...base, [field]: v.replace(/(\.\w+)?$/, '-other$1') };
    }

    if (v !== null && typeof v === 'object') {
        return { ...base, [field]: { ...v, other: true } };
    }

    return null;
};

/** `next` placed against `last` on the side `[dx, dy]` points to, edge to edge whatever their sizes. */
const against = (last, next, [dx, dy]) => ({
    ...next,
    x: dx === 1 ? last.x + last.w : dx === -1 ? last.x - next.w : last.x,
    y: dy === 1 ? last.y + last.h : dy === -1 ? last.y - next.h : last.y,
});

/**
 * THE MERGE STRATUM: F is every field a real `mapTiles()` cell carries — read off the shipped room's first
 * cell, never listed here — but its place (`x`, `y`) and its pass (`room`, the rooms stratum's). Per field,
 * per direction, per depth (the differing tile against one tile, or against the end of a chain of two
 * identical ones) and per order (the differing tile drawn first, or last), one slot. A field the planted
 * tile does not carry is a defect: its cells could not be planted.
 */
const merge = (() => {
    const F = Object.keys(shipped.tiles[0] ?? {}).filter((k) => !['x', 'y', 'room'].includes(k));
    const DEPTHS = [1, 2];
    const ORDERS = ['differing first', 'differing last'];
    const base = tile({ ...sheet, opacity: 0.5 });
    // Room for the longest chain either way of the slot's origin, and as much again between slots.
    const SLOT = 2 * (2 * Math.max(...DEPTHS) + 1) * base.w;
    const tiles = [];
    let cells = 0;

    if (F.length === 0) {
        defects.push('the shipped room produced no tile, so the merge stratum has no field to vary');
    }

    for (const field of F.filter((f) => !(f in base))) {
        defects.push(`the planted tile lacks «${field}», which mapTiles() produces — its merge cells are not planted`);
    }

    for (const field of F) {
        for (const dir of DIRS) {
            for (const depth of DEPTHS) {
                for (const order of ORDERS) {
                    const other = field in base ? differIn(base, field) : null;

                    if (other === null) {
                        continue;
                    }

                    const at = { x: 1000 + SLOT * (cells % 40), y: 1000 + SLOT * Math.floor(cells / 40) };
                    const chain = [{ ...base, ...at }];

                    while (chain.length < depth) {
                        chain.push(against(chain[chain.length - 1], base, dir));
                    }

                    const odd = against(chain[chain.length - 1], other, dir);

                    tiles.push(...(order === 'differing first' ? [odd, ...chain] : [...chain, odd]));
                    cells += 1;
                }
            }
        }
    }

    stratum('merge', cells, { fields: F.length, directions: DIRS.length, depths: DEPTHS.length, orders: ORDERS.length });

    return { seamless: true, tiles, planes: [], fields: F };
})();

/**
 * THE ORDER STRATUM: per direction, a tile between two identical neighbours that overlaps the second — so
 * drawing the pair as one would put it under that second tile; one place drawn by three layers, the middle
 * one different; and a hallway tile under the plane of a room whose tile is at the same place.
 */
const order = (() => {
    const tiles = [];
    const planes = [];
    let between = 0;

    DIRS.forEach(([dx, dy], i) => {
        const at = { x: 3000 + 64 * i, y: 3000 };
        const a = tile({ ...at });

        tiles.push(a, tile({ image: '/art/floor/tiles/planted/over.svg', x: at.x + dx * 12, y: at.y + dy * 12 }),
            tile({ x: at.x + dx * a.w, y: at.y + dy * a.h }));
        between += 1;
    });

    const place = { x: 3000, y: 3100 };

    tiles.push(tile({ ...place, layer: 'floor' }), tile({ x: place.x + 8, y: place.y, layer: 'floor' }),
        tile({ ...place, layer: 'mid', image: '/art/floor/tiles/planted/over.svg' }), tile({ ...place, layer: 'top' }));

    const room = { install_id: 'order-room', x: 3000, y: 3200, w: 16, h: 8, theme: 'sage' };

    tiles.push(tile({ x: 3000, y: 3200 }), tile({ x: 3000, y: 3200, room: 'order-room', image: '/art/floor/tiles/planted/room.svg' }));
    planes.push(room);

    stratum('order', between + 1 + 1, { 'directions + layers + plane': DIRS.length + 1 + 1 });

    return { seamless: false, tiles, planes };
})();

/**
 * THE OFFSETS STRATUM: a 40 × 2 lattice of one sheet tile, built through `mapTiles()` with its layer inside
 * two nested groups that each carry the offset (so the sum drifts as a float), per group offset — whole,
 * dyadic, non-dyadic — and per offset of its embedded tileset.
 */
const offsets = (() => {
    const GROUP = [[0, 0], [3, -2], [0.5, 0.25], [0.1, 1 / 3], [1 / 3, 0.1]];
    const TILESET = [[0, 0], [2, -1], [0.1, 1 / 3]];
    const tiles = [];
    let cells = 0;

    for (const [gx, gy] of GROUP) {
        for (const [tx, ty] of TILESET) {
            const map = {
                width: 40,
                height: 2,
                tilewidth: 8,
                tileheight: 8,
                tilesets: [{
                    firstgid: 1, tilewidth: 8, tileheight: 8, columns: 2, tilecount: 4,
                    image: 'tiles/planted/sheet.png', imagewidth: 16, imageheight: 16, tileoffset: { x: tx, y: ty },
                }],
                layers: [{
                    type: 'group', offsetx: gx, offsety: gy, layers: [{
                        type: 'group', offsetx: gx, offsety: gy, layers: [{
                            type: 'tilelayer', name: 'offset', width: 40, height: 2, data: new Array(80).fill(2),
                        }],
                    }],
                }],
            };
            const built = mapTiles(map, { x: 0, y: 4000 + 32 * cells }, () => null, null);

            if (built.length > 0) {
                tiles.push(...built);
                cells += 1;
            }
        }
    }

    stratum('offsets', cells, { 'group offsets': GROUP.length, 'tileset offsets': TILESET.length });

    return { seamless: true, tiles, planes: [] };
})();

/** THE ROOMS STRATUM: per direction, two rooms' identical tiles touching across the rooms' boundary. */
const rooms = (() => {
    const tiles = [];
    const planes = [];

    DIRS.forEach((dir, i) => {
        const a = tile({ x: 5000 + 64 * i, y: 5000, room: `room-${i}-a` });
        const b = against(a, { ...a, room: `room-${i}-b` }, dir);

        tiles.push(a, b);
        planes.push({ install_id: a.room, x: a.x, y: a.y, w: a.w, h: a.h, theme: 'oak' },
            { install_id: b.room, x: b.x, y: b.y, w: b.w, h: b.h, theme: 'sage' });
    });

    stratum('rooms', planes.length / 2, { directions: DIRS.length });

    return { seamless: true, tiles, planes };
})();

/**
 * THE RANDOM FILL: a fixed seed and size, tiles of three looks on an 8 px lattice and off it by half a
 * tile, over the hallway and a room. Its numbers are mulberry32's — the low bits of a power-of-two LCG
 * repeat with a short period and laid every tile on a few lines, where nothing was a neighbour.
 */
const random = (() => {
    let seed = 281;
    const next = (n) => {
        seed = (seed + 0x6d2b79f5) | 0;

        let z = Math.imul(seed ^ (seed >>> 15), 1 | seed);

        z ^= z + Math.imul(z ^ (z >>> 7), 61 | z);

        return ((z ^ (z >>> 14)) >>> 0) % n;
    };
    const looks = [{}, { image: '/art/floor/tiles/planted/over.svg' }, { flip_h: true, opacity: 0.5 }];
    const tiles = Array.from({ length: 160 }, () => tile({
        ...looks[next(looks.length)],
        x: 6000 + 8 * next(16) + (next(6) === 0 ? 4 : 0),
        y: 6000 + 8 * next(16) + (next(6) === 0 ? 4 : 0),
        room: next(3) === 0 ? 'random-room' : null,
    }));

    return { seamless: false, tiles, planes: [{ install_id: 'random-room', x: 6000, y: 6000, w: 132, h: 132, theme: 'oak' }] };
})();

const CASES = {
    'the shipped default room': shipped,
    'a sheet tile flipped at half opacity, an L of wall strip and a lattice off the grid': {
        seamless: true,
        tiles: [
            ...lattice(4, 3, { x: 0, y: 0 }, { ...sheet, flip_h: true, flip_v: true, opacity: 0.5 }),
            ...lattice(1, 6, { x: 80, y: 0 }, {}),
            ...lattice(6, 1, { x: 88, y: 40 }, {}),
            ...lattice(3, 2, { x: 3.5, y: 101.25 }, { image: '/art/floor/tiles/planted/odd.svg' }),
        ],
        planes: [],
    },
    'the merge stratum': merge,
    'the order stratum': order,
    'the offsets stratum': offsets,
    'the rooms stratum': rooms,
    'the random fill': random,
};

// ── read back what was painted ────────────────────────────────────────────────────────────────────
/** A place to a millionth of a pixel — the reader's tolerance for a float that drifted in its last bits. */
const near = (v) => Math.round(v * 1e6);
const whole = (v) => Math.abs(v - Math.round(v)) < 1e-9;
const num = (n, k) => Number(n.getAttribute(k) ?? 0);

/** The picture a tile draws — the members of a scene tile the reader below reads back off the DOM. */
const picture = (t) => JSON.stringify([t.image, t.iw, t.ih, t.sx, t.sy, t.sw, t.sh, t.w, t.h, t.flip_h, t.flip_v, Number(t.opacity)]);
const placed = (t) => `${picture(t)}@${near(t.x)},${near(t.y)}`;
const overlap = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/** Every attribute of `n` outside `read`, and every child past `children`, as the defect that names it. */
function closed(n, read, children, where, defects) {
    for (const a of Object.keys(n.attrs).filter((k) => !read.includes(k))) {
        defects.push(`${where}: the painter writes «${a}» the probe cannot attribute to a look field`);
    }

    for (const extra of n.children.slice(children)) {
        defects.push(`${where}: the painter writes a child «${extra.name}» the probe cannot attribute to a look field`);
    }

    if (n.textContent !== '') {
        defects.push(`${where}: the painter writes text the probe cannot attribute to a look field`);
    }
}

/**
 * One painted image, inside a window (`viewBox`) of `w × h`, as the tile it draws: the window is the tile's
 * source rect, the image's size the image's, and a flip is the transform that mirrors the window about its
 * own centre — anything else is a defect.
 */
function drawn(img, viewBox, w, h, where, defects) {
    const [sx, sy, sw, sh] = (viewBox ?? '').split(/[ ,]+/).map(Number);
    const transform = img?.getAttribute('transform') ?? null;
    let flip = [false, false];

    if (img?.name !== 'image') {
        return { defect: `${where} holds no image` };
    }

    closed(img, ['href', 'x', 'y', 'width', 'height', 'preserveAspectRatio', 'transform'], 0, `${where}'s image`, defects);

    if (img.getAttribute('preserveAspectRatio') !== 'none') {
        return { defect: `${where}: its image is not stretched to its own size` };
    }

    if (transform !== null) {
        const m = /^translate\((\S+) (\S+)\) scale\((\S+) (\S+)\)$/.exec(transform);

        if (m === null) {
            return { defect: `${where}: its image's transform «${transform}» is not a flip` };
        }

        flip = [Number(m[3]) === -1, Number(m[4]) === -1];

        if (Number(m[1]) !== (flip[0] ? 2 * sx + sw : 0) || Number(m[2]) !== (flip[1] ? 2 * sy + sh : 0)) {
            return { defect: `${where}: its image's flip «${transform}» does not mirror the window ${viewBox} about its centre` };
        }
    }

    if (num(img, 'x') !== 0 || num(img, 'y') !== 0) {
        return { defect: `${where}: its image is not at the window's origin` };
    }

    return { image: img.getAttribute('href'), iw: num(img, 'width'), ih: num(img, 'height'), sx, sy, sw, sh, w, h, flip_h: flip[0], flip_v: flip[1] };
}

function paintedOf(svg, defects, name) {
    const layer = svg.children.find((n) => n.getAttribute('class') === 'tiles');
    const defs = new Map(svg.children.filter((n) => n.name === 'defs').flatMap((d) => d.children).map((n) => [n.getAttribute('id'), n]));
    const items = [];

    if (layer === undefined) {
        defects.push(`${name}: the painter drew no tiles layer`);

        return { items, primitives: 0 };
    }

    layer.children.forEach((n, prim) => {
        const where = `${name}: painted node ${prim} (${n.name})`;
        const cls = n.getAttribute('class') ?? '';

        if (n.name === 'rect' && cls.split(' ').includes('plane')) {
            closed(n, ['x', 'y', 'width', 'height', 'class'], 0, where, defects);
            items.push({ plane: cls, x: num(n, 'x'), y: num(n, 'y'), w: num(n, 'width'), h: num(n, 'height'), prim });

            return;
        }

        if (n.name === 'svg') {
            // A tile in a viewport of its own — one tile, one node.
            closed(n, ['x', 'y', 'width', 'height', 'viewBox', 'preserveAspectRatio', 'opacity'], 1, where, defects);

            const t = drawn(n.children[0], n.getAttribute('viewBox'), num(n, 'width'), num(n, 'height'), where, defects);

            if (t.defect) {
                defects.push(t.defect);
            } else {
                items.push({ ...t, x: num(n, 'x'), y: num(n, 'y'), opacity: n.getAttribute('opacity') ?? 1, prim });
            }

            return;
        }

        const fill = /^url\(#(.+)\)$/.exec(n.getAttribute('fill') ?? '');

        if (n.name === 'path' && fill !== null) {
            // A path filled with a tile as a pattern: the tile at every cell of the pattern's lattice in each
            // of the path's rects.
            closed(n, ['d', 'fill', 'opacity'], 0, where, defects);

            const pattern = defs.get(fill[1]);

            if (pattern === undefined || pattern.name !== 'pattern') {
                defects.push(`${where} is filled with «${fill[1]}», which is no pattern in the drawing's defs`);

                return;
            }

            closed(pattern, ['id', 'patternUnits', 'x', 'y', 'width', 'height', 'viewBox', 'preserveAspectRatio'], 1, `${where}'s pattern`, defects);

            if (pattern.getAttribute('patternUnits') !== 'userSpaceOnUse' || pattern.getAttribute('preserveAspectRatio') !== 'none') {
                defects.push(`${where}: its pattern is not in the drawing's own units, stretched to its cell`);

                return;
            }

            const t = drawn(pattern.children[0], pattern.getAttribute('viewBox'), num(pattern, 'width'), num(pattern, 'height'), `${where}'s pattern`, defects);

            if (t.defect) {
                defects.push(t.defect);

                return;
            }

            const d = n.getAttribute('d') ?? '';
            const RECT = /M(-?[\d.e+-]+) (-?[\d.e+-]+)h(-?[\d.e+-]+)v(-?[\d.e+-]+)h(-?[\d.e+-]+)Z/g;
            const rects = [...d.matchAll(RECT)].map((m) => m.slice(1).map(Number));

            if (rects.length === 0 || rects.map((m) => `M${m[0]} ${m[1]}h${m[2]}v${m[3]}h${m[4]}Z`).join('') !== d) {
                defects.push(`${where}: its outline «${d.slice(0, 60)}» is not a list of rects`);

                return;
            }

            for (const [x, y, w, h, back] of rects) {
                const [cols, rows, col0, row0] = [w / t.w, h / t.h, (x - num(pattern, 'x')) / t.w, (y - num(pattern, 'y')) / t.h];

                if (back !== -w || ![cols, rows, col0, row0].every(whole) || cols < 1 || rows < 1) {
                    defects.push(`${where}: its rect at (${x}, ${y}) is not a whole number of its pattern's cells, on the pattern's own lattice`);

                    continue;
                }

                for (let r = 0; r < Math.round(rows); r++) {
                    for (let c = 0; c < Math.round(cols); c++) {
                        items.push({ ...t, x: x + c * t.w, y: y + r * t.h, opacity: n.getAttribute('opacity') ?? 1, prim });
                    }
                }
            }

            return;
        }

        defects.push(`${where} is no tile, plane or pattern-filled path — nothing in the scene accounts for it`);
    });

    return { items, primitives: layer.children.length };
}

const count = (n) => 1 + n.children.reduce((s, c) => s + count(c), 0);
const cases = {};

for (const [name, c] of Object.entries(CASES)) {
    const scene = { band: null, slab: null, tiles: c.tiles, planes: c.planes, decorative: [], lines: [], desks: [], strip: null, effects: [] };

    createPainter({ characters: null, failed() {}, select() {} }).paint(scene, { bounds: null });

    const svg = host.children[0];
    const { items, primitives } = paintedOf(svg, defects, name);

    cases[name] = { tiles: c.tiles.length, primitives, nodes: count(svg) };

    // The scene's own order: the hallway's tiles, then each plane and its room's tiles.
    const expected = [
        ...c.tiles.filter((t) => t.room === null),
        ...c.planes.flatMap((p) => [{ plane: `plane plane-${p.theme}`, ...p }, ...c.tiles.filter((t) => t.room === p.install_id)]),
    ];
    const id = (t) => (t.plane !== undefined ? `${t.plane}@${t.x},${t.y},${t.w},${t.h}` : placed(t));

    // ── the same tiles: each expected item matched to one painted item, in turn ──
    const pool = new Map();

    items.forEach((it, at) => {
        const k = id(it);

        pool.set(k, [...(pool.get(k) ?? []), at]);
    });

    const position = expected.map((t) => {
        const at = pool.get(id(t))?.shift();

        if (at === undefined) {
            defects.push(`${name}: the scene's ${t.plane ?? `tile ${t.image} at (${t.x}, ${t.y})`} is not painted as itself`);
        }

        return at;
    });

    for (const [k, left] of pool) {
        if (left.length > 0) {
            defects.push(`${name}: ${left.length} painted ${k} that no item of the scene accounts for`);
        }
    }

    // ── the same order where it shows: every overlapping pair, in the scene's order ──
    for (let i = 0; i < expected.length; i++) {
        for (let j = i + 1; j < expected.length; j++) {
            if (position[i] !== undefined && position[j] !== undefined && overlap(expected[i], expected[j]) && position[i] > position[j]) {
                defects.push(`${name}: ${id(expected[j])} is painted under ${id(expected[i])}, which the scene draws first`);
            }
        }
    }

    // ── one pass per primitive: a primitive's tiles are all of one room, or all of the hallway ──
    const passOf = new Map();

    expected.forEach((t, i) => {
        if (t.plane !== undefined || position[i] === undefined) {
            return;
        }

        const prim = items[position[i]].prim;

        if (passOf.has(prim) && passOf.get(prim) !== t.room) {
            defects.push(`${name}: one primitive paints tiles of two passes (${passOf.get(prim) ?? 'the hallway'} and ${t.room ?? 'the hallway'}) `
                + `— the tile at (${t.x}, ${t.y}) is drawn outside its own pass`);
        }

        passOf.set(prim, t.room);
    });

    // ── no seam: two tiles of one pass that read back as the same picture and share an edge are one primitive ──
    if (c.seamless) {
        const at = new Map();

        expected.forEach((t, i) => {
            const k = `${t.room}|${placed(t)}`;

            if (t.plane === undefined && position[i] !== undefined && !at.has(k)) {
                at.set(k, { t, prim: items[position[i]].prim });
            }
        });

        for (const { t, prim } of at.values()) {
            for (const next of [{ ...t, x: t.x + t.w }, { ...t, y: t.y + t.h }]) {
                const other = at.get(`${t.room}|${placed(next)}`);

                if (other !== undefined && other.prim !== prim) {
                    defects.push(`${name}: ${t.image} at (${t.x}, ${t.y}) and its identical neighbour at (${other.t.x}, ${other.t.y}) `
                        + 'are painted as two primitives — the edge between them is a seam at any fractional zoom');
                }
            }
        }
    }
}

console.log(JSON.stringify({ cases, strata, fields: merge.fields, look: Object.keys(tileLook(shipped.tiles[0] ?? tile({}))), defects }));

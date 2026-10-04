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
 * ⛔ THREE CHECKS PER CASE, read from what `createPainter().paint()` wrote under `g.tiles`:
 *   - THE SAME TILES: every painted primitive is expanded back into the tiles it draws — a pattern-filled
 *     path into the lattice cells of its rects, a per-tile viewport (the form the painter used to write, so
 *     a return to it reds as the seam it is and not as a tile gone missing) into its one — each with its image, its window onto the image, the image's size, its flips and its opacity, and
 *     the result must be exactly the scene's tiles, none missing and none extra;
 *   - THE SAME ORDER WHERE IT SHOWS: any two tiles that overlap are painted in the scene's order, each
 *     room's plane under its own tiles and over the hallway's;
 *   - NO SEAM (the cases marked `seamless`): two identical tiles sharing an edge are painted by ONE node.
 *
 * The cases: the SHIPPED default room (`resources/floor/default.tmj` through the shipped tileset reader and
 * `mapTiles()`), and planted ones — a sheet tile's window flipped both ways at half opacity on a lattice, an
 * L of 8 px wall strip (no one rectangle covers it), a lattice off the grid, and an ORDER case: a tile between two identical
 * neighbours that overlaps the second, so drawing the pair as one would put that tile under it.
 *
 * argv: `<floor module dir>` (the shipped `server/public/js/floor`, or a mutated copy's). stdout:
 * `{ cases: { <name>: { tiles, primitives, nodes } }, defects: [ … ] }` — `tiles` counts each case's tiles,
 * so a case that painted nothing reads as such.
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
const { mapTiles } = await mod('scene.js');
const { FLOOR_ART, readTileset } = await mod('tileset.js');

// ── the cases ─────────────────────────────────────────────────────────────────────────────────────
const tile = (over) => ({
    room: null, layer: 'planted', w: 8, h: 8, image: '/art/floor/tiles/planted/strip.svg', iw: 8, ih: 8, tileset: null,
    sx: 0, sy: 0, sw: 8, sh: 8, flip_h: false, flip_v: false, flip_d: false, opacity: 1, properties: null, ...over,
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
    'a tile between two identical neighbours that overlaps the second': {
        seamless: false,
        tiles: [
            tile({ x: 0, y: 200, room: 'r' }),
            tile({ x: 12, y: 200, room: 'r', image: '/art/floor/tiles/planted/over.svg' }),
            tile({ x: 8, y: 200, room: 'r' }),
        ],
        planes: [{ install_id: 'r', x: 0, y: 200, w: 24, h: 8, theme: 'sage' }],
    },
};

// ── read back what was painted ────────────────────────────────────────────────────────────────────
const num = (n, k) => Number(n.getAttribute(k) ?? 0);
const keyOf = (t) => JSON.stringify([t.image, t.iw, t.ih, t.sx, t.sy, t.sw, t.sh, t.w, t.h, t.flip_h, t.flip_v, Number(t.opacity)]);
const placed = (t) => `${keyOf(t)}@${t.x},${t.y}`;
const overlap = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/**
 * One painted image, inside a window (`viewBox`) of `w × h`, as the tile it draws: the window is the tile's
 * source rect, the image's size the image's, and a flip is the transform that mirrors the window about its
 * own centre — anything else is a defect.
 */
function drawn(img, viewBox, w, h, where) {
    const [sx, sy, sw, sh] = (viewBox ?? '').split(/[ ,]+/).map(Number);
    const transform = img?.getAttribute('transform') ?? null;
    let flip = [false, false];

    if (img?.name !== 'image') {
        return { defect: `${where} holds no image` };
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
            items.push({ plane: cls, x: num(n, 'x'), y: num(n, 'y'), w: num(n, 'width'), h: num(n, 'height'), prim });

            return;
        }

        if (n.name === 'svg') {
            // A tile in a viewport of its own — one tile, one node.
            const t = drawn(n.children[0], n.getAttribute('viewBox'), num(n, 'width'), num(n, 'height'), where);

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
            const pattern = defs.get(fill[1]);

            if (pattern === undefined || pattern.name !== 'pattern') {
                defects.push(`${where} is filled with «${fill[1]}», which is no pattern in the drawing's defs`);

                return;
            }

            if (pattern.getAttribute('patternUnits') !== 'userSpaceOnUse' || pattern.getAttribute('preserveAspectRatio') !== 'none') {
                defects.push(`${where}: its pattern is not in the drawing's own units, stretched to its cell`);

                return;
            }

            const t = drawn(pattern.children[0], pattern.getAttribute('viewBox'), num(pattern, 'width'), num(pattern, 'height'), `${where}'s pattern`);

            if (t.defect) {
                defects.push(t.defect);

                return;
            }

            const d = n.getAttribute('d') ?? '';
            const RECT = /M(-?[\d.]+) (-?[\d.]+)h(-?[\d.]+)v(-?[\d.]+)h(-?[\d.]+)Z/g;
            const rects = [...d.matchAll(RECT)].map((m) => m.slice(1).map(Number));

            if (rects.length === 0 || rects.map((m) => `M${m[0]} ${m[1]}h${m[2]}v${m[3]}h${m[4]}Z`).join('') !== d) {
                defects.push(`${where}: its outline «${d.slice(0, 60)}» is not a list of rects`);

                return;
            }

            for (const [x, y, w, h, back] of rects) {
                const [cols, rows] = [w / t.w, h / t.h];

                if (back !== -w || !Number.isInteger(cols) || !Number.isInteger(rows) || cols < 1 || rows < 1
                    || !Number.isInteger((x - num(pattern, 'x')) / t.w) || !Number.isInteger((y - num(pattern, 'y')) / t.h)) {
                    defects.push(`${where}: its rect at (${x}, ${y}) is not a whole number of its pattern's cells, on the pattern's own lattice`);

                    continue;
                }

                for (let r = 0; r < rows; r++) {
                    for (let c = 0; c < cols; c++) {
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
const defects = [];
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

    // ── no seam: identical tiles sharing an edge are one primitive ──
    if (c.seamless) {
        const byPlace = new Map(items.filter((it) => it.plane === undefined).map((it) => [placed(it), it]));

        for (const it of byPlace.values()) {
            for (const next of [{ ...it, x: it.x + it.w }, { ...it, y: it.y + it.h }]) {
                const other = byPlace.get(placed(next));

                if (other !== undefined && other.prim !== it.prim) {
                    defects.push(`${name}: ${it.image} at (${it.x}, ${it.y}) and its identical neighbour at (${other.x}, ${other.y}) `
                        + 'are painted as two primitives — the edge between them is a seam at any fractional zoom');
                }
            }
        }
    }
}

console.log(JSON.stringify({ cases, defects }));

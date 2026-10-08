/**
 * THE FLOOR's PLANE KINDS, MERGED INTO RUNS: the scene's one region pass (`scene.js`'s `tileRegions()`) joins
 * every `wall` and every `accent` cell to its identical neighbours, so the plane document a floor's theme draws
 * them into receives each run as ONE area and draws no edge between its cells; and the painter draws no tile's
 * image at all (FLOOR.md § 10.6 item 6, card#11046 Appendix B row 22). `node`, no dependencies. Driven by
 * `TheFloorsTilesDrawWithoutSeamsTest`.
 *
 * ⛔ WHY: a run drawn cell by cell has an edge of its own at every cell, and where that edge lands on a
 * fractional device pixel — any zoom but a whole one — the pair leaves a hairline of whatever is under them:
 * a dotted ladder down an 8 px wall. Until row 22 the painter drew tiles and was held to one primitive per
 * run; since then the plane document draws the runs, and what is held here is that the runs it is handed are
 * the joined areas. No browser runs here, so this reads the property that causes the hairline, not pixels.
 *
 * ⛔ THE CHECKS PER CASE, read from `tileRegions()`'s regions:
 *   - THE SAME CELLS: the regions' rects, expanded back into cells, are exactly the case's cells — none
 *     missing, none extra, no two rects overlapping — and each cell is in a region of its own kind and size;
 *   - THE SAME ORDER WHERE IT SHOWS: a region is drawn at its first cell, so no copy moves earlier past a cell
 *     of another region it overlaps;
 *   - NO SEAM (the cases marked `seamless`): two cells of one kind and size sharing an edge are one region.
 * And over a painted scene: the painter writes no `<image>` that is not a `data:` URI a theme or the character
 * tree made, and no `<pattern>` but the hatch — no tile's image reaches the drawing.
 *
 * ⛔ THE INPUTS: the SHIPPED default room (`resources/floor/default.tmj` through the shipped tileset reader and
 * `mapTiles()`), its `wall` and its `accent` cells; planted lattices of one kind; two neighbours differing in
 * their kind alone and in their size alone, in each direction; a cell of another kind between two identical
 * cells that it overlaps, in each direction; and lattices built through `mapTiles()` under nested group offsets
 * — whole, dyadic and non-dyadic.
 *
 * argv: `<floor module dir>` (the shipped `server/public/js/floor`, or a mutated copy's). stdout:
 * `{ cases: { <name>: { cells, regions } }, look, images, defects: [ … ] }` — `look` the fields of `tileLook()`
 * (the controls' source for dropping each in turn).
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
const { mapTiles, tileLook, tileRegions } = await mod('scene.js');
const { FLOOR_ART, readTileset } = await mod('tileset.js');

const defects = [];
const cases = {};
const spot = (x, y) => `${Math.round(x * 1024)},${Math.round(y * 1024)}`;

// ── the cases ─────────────────────────────────────────────────────────────────────────────────────
const cell = (over) => ({ room: null, layer: 'planted', w: 8, h: 8, kind: 'wall', properties: { kind: 'wall' }, ...over });
const lattice = (cols, rows, at, over = {}) => Array.from({ length: rows }, (_, r) => Array.from({ length: cols },
    (__, c) => cell({ ...over, x: at.x + c * (over.w ?? 8), y: at.y + r * (over.h ?? 8) }))).flat();
const DIRS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

const shippedCells = (() => {
    const map = JSON.parse(readFileSync(join(RESOURCES, 'default.tmj'), 'utf8'));
    const held = new Map();
    const tilesetFor = (url) => {
        if (!held.has(url)) {
            held.set(url, readTileset(readFileSync(join(RESOURCES, url.slice(FLOOR_ART.length)), 'utf8'), url));
        }

        return held.get(url);
    };

    return mapTiles(map, { x: 0, y: 0 }, tilesetFor, 'shipped');
})();

const planted = {
    'the shipped default room\'s walls': { seamless: true, cells: shippedCells.filter((c) => c.kind === 'wall') },
    'the shipped default room\'s accent': { seamless: true, cells: shippedCells.filter((c) => c.kind === 'accent') },
    'a lattice of one kind': { seamless: true, cells: lattice(12, 3, { x: 0, y: 0 }) },
    'an L of wall': { seamless: true, cells: [...lattice(10, 1, { x: 0, y: 0 }), ...lattice(1, 9, { x: 0, y: 8 })] },
};

// Two neighbours differing in their kind alone, and in their size alone — never one region.
for (const [dx, dy] of DIRS) {
    planted[`two kinds side by side, ${dx},${dy}`] = { seamless: true, cells: [cell({ x: 40, y: 40 }), cell({ x: 40 + dx * 8, y: 40 + dy * 8, kind: 'accent', properties: { kind: 'accent' } })] };
    planted[`two sizes side by side, ${dx},${dy}`] = { seamless: true, cells: [cell({ x: 40, y: 40, w: 16, h: 16 }), cell({ x: 40 + dx * 16, y: 40 + dy * 16 })] };
}

// A cell of another kind drawn between two identical cells, overlapping the second: the second may not move
// ahead of it into the first's region.
for (const [dx, dy] of DIRS) {
    const a = cell({ x: 80, y: 80 });
    const b = cell({ x: 80 + dx * 8, y: 80 + dy * 8 });
    const between = cell({ x: b.x, y: b.y, kind: 'accent', properties: { kind: 'accent' } });

    planted[`a cell of another kind between, ${dx},${dy}`] = { seamless: false, cells: [a, between, b] };
}

// Lattices built through `mapTiles()` under nested group offsets — their identical neighbours one region.
const OFFSETS = [[0, 0], [8, 16], [0.5, 0.25], [0.1, 0.3], [1 / 3, 2 / 3]];
const embedded = { firstgid: 1, tilewidth: 8, tileheight: 8, tilecount: 1, columns: 0, tiles: [{ id: 0, image: 'strip.svg', imagewidth: 8, imageheight: 8, properties: [{ name: 'kind', type: 'string', value: 'wall' }] }] };

for (const [ox, oy] of OFFSETS) {
    for (const [gx, gy] of OFFSETS) {
        const data = Array.from({ length: 40 * 2 }, () => 1);
        const map = {
            type: 'map', orientation: 'orthogonal', width: 40, height: 2, tilewidth: 8, tileheight: 8, tilesets: [embedded],
            layers: [{ type: 'group', offsetx: gx, offsety: gy, layers: [{ type: 'tilelayer', name: 'walls', width: 40, height: 2, data, offsetx: ox, offsety: oy }] }],
        };

        planted[`a lattice under offsets ${ox},${oy} in a group at ${gx},${gy}`] = { seamless: true, cells: mapTiles(map, { x: 0, y: 4000 }, () => null, null) };
    }
}

// ── the checks ────────────────────────────────────────────────────────────────────────────────────
// What a cell is to the floor, written here from the published rule (§ 10.6 item 6: its kind and its cell's
// size) and never imported, so a look that drops a field reads as a cell not drawn as itself.
const lookKey = (t) => JSON.stringify({ kind: t.kind, w: t.w, h: t.h });

for (const [name, { seamless, cells }] of Object.entries(planted)) {
    const regions = tileRegions(cells);

    cases[name] = { cells: cells.length, regions: regions.length };

    if (cells.length === 0) {
        defects.push(`${name}: the case has no cell — every check would read nothing`);
        continue;
    }

    // Expand every region back into cells: each of its rects, cut on the region's lattice.
    const drawn = [];

    regions.forEach((region, r) => {
        for (const rect of region.rects) {
            for (let y = rect.y; y < rect.y + rect.h - 1e-9; y += region.look.h) {
                for (let x = rect.x; x < rect.x + rect.w - 1e-9; x += region.look.w) {
                    drawn.push({ key: `${JSON.stringify(region.look)}@${spot(x, y)}`, region: r });
                }
            }
        }
    });

    const want = cells.map((c) => `${lookKey(c)}@${spot(c.x, c.y)}`);
    const got = drawn.map((d) => d.key);
    const count = (list) => list.reduce((m, k) => m.set(k, (m.get(k) ?? 0) + 1), new Map());
    const [w, g] = [count(want), count(got)];

    for (const [k, n] of w) {
        if ((g.get(k) ?? 0) < n) {
            defects.push(`${name}: the cell ${k} is not in the regions as itself — a run drawn as another kind or size, or not at all`);
        }
    }

    for (const [k, n] of g) {
        if ((w.get(k) ?? 0) < n) {
            defects.push(`${name}: the regions draw ${k}, which is no cell of the case`);
        }
    }

    // No two rects of one region overlap (each cell is drawn once).
    for (const region of regions) {
        region.rects.forEach((a, i) => region.rects.slice(i + 1).forEach((b) => {
            if (a.x < b.x + b.w - 1e-9 && b.x < a.x + a.w - 1e-9 && a.y < b.y + b.h - 1e-9 && b.y < a.y + a.h - 1e-9) {
                defects.push(`${name}: two rects of one region overlap`);
            }
        }));
    }

    const regionOf = new Map(drawn.map((d) => [d.key, d.region]));

    // The draw order where it shows: a cell is drawn at its region's first cell, so it must not land ahead
    // of an earlier-listed cell of another region it overlaps.
    cells.forEach((a, i) => cells.slice(i + 1).forEach((b) => {
        const ra = regionOf.get(`${lookKey(a)}@${spot(a.x, a.y)}`);
        const rb = regionOf.get(`${lookKey(b)}@${spot(b.x, b.y)}`);
        const overlap = a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

        if (overlap && ra !== rb && ra !== undefined && rb !== undefined && rb < ra) {
            defects.push(`${name}: a cell listed after one it overlaps is drawn first — the region pass moved it ahead`);
        }
    }));

    if (seamless) {
        // Two cells of one look sharing an edge are one region — no edge falls between them.
        const at = new Map(cells.map((c) => [`${lookKey(c)}@${spot(c.x, c.y)}`, c]));

        for (const c of cells) {
            for (const [dx, dy] of DIRS) {
                const k = `${lookKey(c)}@${spot(c.x + dx * c.w, c.y + dy * c.h)}`;

                if (at.has(k) && regionOf.get(k) !== regionOf.get(`${lookKey(c)}@${spot(c.x, c.y)}`)) {
                    defects.push(`${name}: two ${c.kind} cells sharing an edge are two regions — the edge between them is a seam`);
                }
            }
        }
    }
}

// ── the painter draws no tile's image ─────────────────────────────────────────────────────────────
const doc = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
const theme = { plane: () => doc, scenery: () => doc };
const shippedPlane = { install_id: 'shipped', x: 0, y: 0, w: 1576, h: 544, doc: { asset: 'theme:t/plane:shipped', theme: 't', fn: 'plane', input: { w: 1576 } } };
const scenery = shippedCells.filter((c) => c.kind !== null && !['wall', 'accent'].includes(c.kind))
    .map((c, i) => ({ room: 'shipped', kind: c.kind, x: c.x, y: c.y, w: c.w, h: c.h, decoration: null, doc: { asset: `theme:t/scenery:${c.kind}`, theme: 't', fn: 'scenery', input: { i } } }));
const painter = createPainter({ characters: null, themes: { t: theme }, failed() {}, select() {} });

painter.paint({ band: null, band_docs: null, slab: null, planes: [shippedPlane], scenery, decorative: [], lines: [], desks: [], strip: null, effects: [], doors: [], loop_fps: 4 }, { bounds: null });

const images = [];
const walk = (n) => {
    if (n.name === 'image') {
        images.push(String(n.getAttribute('href')));
    }

    if (n.name === 'pattern' && n.getAttribute('id') !== 'hatch') {
        defects.push(`the painter writes a <pattern> «${n.getAttribute('id')}» — a tile's image reaches the drawing`);
    }

    n.children.forEach(walk);
};

walk(host);

if (scenery.length === 0 || images.length !== 1 + scenery.length) {
    defects.push(`the painter drew ${images.length} image(s) for one plane and ${scenery.length} standing piece(s)`);
}

for (const href of images) {
    if (!href.startsWith('data:image/svg+xml')) {
        defects.push(`the painter draws the image «${href}» — the floor never draws a tile's image`);
    }
}

console.log(JSON.stringify({ cases, look: Object.keys(tileLook(cell({ x: 0, y: 0 }))), images: images.length, defects }));

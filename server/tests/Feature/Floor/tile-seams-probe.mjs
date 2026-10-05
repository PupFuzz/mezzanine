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
 *     path into the lattice cells of its rects, a per-tile viewport (the form the painter used to write, so
 *     a return to it reds as the seam it is and not as a tile gone missing) into its one — each with its image, its window onto the image, the image's size, its flips and its opacity, and
 *     the result must be exactly the scene's tiles, none missing and none extra;
 *   - THE SAME ORDER WHERE IT SHOWS: any two tiles that overlap are painted in the scene's order, each
 *     room's plane under its own tiles and over the hallway's;
 *   - NO SEAM (the cases marked `seamless`): two identical tiles sharing an edge are painted by ONE node;
 *   - APART (the pairs a case lists in `apart`): two neighbours that differ in one member of the merge key
 *     are painted by TWO nodes — the one check that reads a member the painter does not draw (`flip_d`).
 *
 * The cases: the SHIPPED default room (`resources/floor/default.tmj` through the shipped tileset reader and
 * `mapTiles()`), and planted ones — a sheet tile's window flipped both ways at half opacity on a lattice, an
 * L of 8 px wall strip (no one rectangle covers it), a lattice off the grid, an ORDER case: a tile between two identical
 * neighbours that overlaps the second, so drawing the pair as one would put that tile under it, and a KEY case: per member
 * of the merge key as `KEY_MEMBERS` pins it, two neighbours that differ in that member alone — and the key `tileRegions()`
 * computes, read out of the `scene.js` under test, must be exactly that pinned set.
 *
 * argv: `<floor module dir>` (the shipped `server/public/js/floor`, or a mutated copy's). stdout:
 * `{ cases: { <name>: { tiles, primitives, nodes } }, key: { expression, members, pinned }, defects: [ … ] }` — `tiles`
 * counts each case's tiles, so a case that painted nothing reads as such; `key` is the merge key as read
 * (`expression`, `members`) beside the pin (`pinned`), the controls' source for dropping each member in turn.
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

/**
 * THE MERGE KEY, PINNED HERE AND NOWHERE ELSE — the members `tileRegions()` must join tiles on, every one of
 * which the planted pairs below vary. The key `scene.js` actually computes is read out of the module dir
 * under test and must be exactly this set: a member removed from it, or one added, is a defect naming the
 * member. So the key cannot shrink and keep the probe green by planting one pair fewer, and a member added
 * to it is a conscious edit of this list, which plants its pair.
 */
const KEY_MEMBERS = ['image', 'iw', 'ih', 'sx', 'sy', 'sw', 'sh', 'w', 'h', 'flip_h', 'flip_v', 'flip_d', 'opacity'];

/**
 * THE KEY AS `scene.js` COMPUTES IT: the `const kind = JSON.stringify([...]);` statement in the body of
 * `tileRegions()` — the binding the merge looks each tile's kind up by — matched as a whole line, so a
 * comment that merely mentions a `JSON.stringify([` is not read for it. Anything but exactly one such line
 * is a defect: none (the key is computed some other way, which this reader cannot vouch for) or several
 * (which one the merge uses is not the reader's to guess).
 */
const keyDefects = [];

const KEY = (() => {
    const source = readFileSync(join(FLOOR, 'scene.js'), 'utf8');
    const body = /^export function tileRegions\([\s\S]*?^\}$/m.exec(source)?.[0] ?? null;
    const lines = body === null ? [] : [...body.matchAll(/^\s*const kind = (JSON\.stringify\(\[([^\]\n]*)\]\));$/gm)];

    if (lines.length !== 1) {
        keyDefects.push(body === null
            ? 'scene.js has no `export function tileRegions(` — the merge key could not be read'
            : `tileRegions() in scene.js has ${lines.length} lines «const kind = JSON.stringify([…]);», not exactly one — the merge key could not be read`);

        return { expression: null, members: [], pinned: KEY_MEMBERS };
    }

    const members = lines[0][2].split(',').map((s) => /^\s*t\.(\w+)\s*$/.exec(s)?.[1] ?? null);

    if (members.includes(null)) {
        keyDefects.push(`the merge key «${lines[0][1]}» in tileRegions() is not a list of a tile's members \`t.<member>\``);
    }

    for (const m of KEY_MEMBERS.filter((p) => !members.includes(p))) {
        keyDefects.push(`the merge key in tileRegions() lacks the pinned member «${m}» — tiles that differ in it would be joined`);
    }

    for (const m of members.filter((r) => r !== null && !KEY_MEMBERS.includes(r))) {
        keyDefects.push(`the merge key in tileRegions() has «${m}», which the probe's KEY_MEMBERS does not pin — pin it, so its pair is planted`);
    }

    return { expression: lines[0][1], members: members.filter((m) => m !== null), pinned: KEY_MEMBERS };
})();

/**
 * A neighbour that differs from `base` in `member` alone: a flag flipped, a number halved, a name changed.
 * A member of any other kind, or one the planted tile does not carry, cannot be varied — a defect, so the
 * member is never silently left unplanted.
 */
const differIn = (base, member) => {
    const v = base[member];

    if (typeof v === 'boolean') {
        return { ...base, [member]: !v };
    }

    if (typeof v === 'number' && v !== 0) {
        return { ...base, [member]: v / 2 };
    }

    if (typeof v === 'string') {
        return { ...base, [member]: v.replace(/(\.\w+)?$/, '-other$1') };
    }

    return null;
};

/**
 * EVERY KEY MEMBER, ONE PAIR EACH: a tile and its neighbour at its right edge that differ in that member
 * alone, each pair on a row of its own. Drop the member from the key and the pair merges into one region,
 * drawn as its first tile.
 */
const pairs = (() => {
    const tiles = [];
    const apart = [];

    KEY_MEMBERS.forEach((member, row) => {
        const base = tile({ ...sheet, opacity: 0.5, x: 0, y: 400 + 32 * row });
        const other = differIn(base, member);

        if (other === null) {
            keyDefects.push(`the key member «${member}» cannot be varied on the planted tile (${JSON.stringify(base[member])}) — its pair is not planted`);

            return;
        }

        apart.push([tiles.length, tiles.length + 1, member]);
        tiles.push(base, { ...other, x: base.x + base.w });
    });

    return { tiles, apart };
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
    'a tile between two identical neighbours that overlaps the second': {
        seamless: false,
        tiles: [
            tile({ x: 0, y: 200, room: 'r' }),
            tile({ x: 12, y: 200, room: 'r', image: '/art/floor/tiles/planted/over.svg' }),
            tile({ x: 8, y: 200, room: 'r' }),
        ],
        planes: [{ install_id: 'r', x: 0, y: 200, w: 24, h: 8, theme: 'sage' }],
    },
    // Not `seamless`: a pair that differs only in what the painter does not draw (`flip_d`) reads back as
    // two identical tiles, and two primitives there is the point.
    'neighbours that differ in exactly one member of the merge key': {
        seamless: false,
        tiles: pairs.tiles,
        apart: pairs.apart,
        planes: [],
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
const defects = [...keyDefects];
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

    // ── apart: neighbours that differ in one key member are two primitives ──
    for (const [a, b, member] of c.apart ?? []) {
        const primAt = (t) => items.find((it) => it.plane === undefined && it.x === t.x && it.y === t.y)?.prim;
        const [pa, pb] = [primAt(c.tiles[a]), primAt(c.tiles[b])];

        if (pa !== undefined && pa === pb) {
            defects.push(`${name}: the tiles at (${c.tiles[a].x}, ${c.tiles[a].y}) and (${c.tiles[b].x}, ${c.tiles[b].y}) differ only in `
                + `«${member}» and are painted as ONE primitive — the second is drawn as the first`);
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

console.log(JSON.stringify({ cases, key: KEY, defects }));

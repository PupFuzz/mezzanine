/**
 * A FLOOR's WALLS AS BODIES — which of a floor's `wall` cells stand, where on the floor's cell grid each stands, and how
 * tall: hoisted out of `floor/door-view.js` (card#12987's door view, `docs/design/plan-12987-cab-door-view.md` § 3.2) at
 * its second caller, the scene (`floor/scene.js`'s `walls`, which the 3-D floor's world lifts — `floor/floor-world.js`,
 * `docs/design/plan-13033-stage2-3d-floor.md` § 3.1; card#13033 stage 2 slice 2). Pure and DOM-free; it imports only
 * modules the lobby page may load, as `door-view.js` does. `tools/design/door-view.selftest.mjs` is the hoist's control.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A GRID is `{ map, origin, footprint, room, seed }`: a map document at its origin on the floor, the footprint it is
 * a room of (a planned floor's hallway stands its own extent in for one), whether it is a room, and the seed its tiles
 * are drawn under (`mapTiles()`'s room argument where it is a room).
 *
 * ⛔ A CELL IS PLACED BY ITS TILE INDEX, NOT BY ITS DRAWN PIXELS (`cellOf()`): its column and row in its layer, moved only
 * by WHOLE cells of the offset `mapTiles()` added (the layer's and its groups' `offsetx`/`offsety` and the tileset's),
 * the part under a cell truncated toward zero. The footprint is the map's own grid at the room's origin, so a cell on the
 * footprint's top row or edge is there by construction whatever an author dragged a layer by. Rounding the drawn pixels
 * would move a whole layer one cell across the footprint's edges at an offset of half a cell or more, and flooring them
 * would do the same for any negative offset; the door view's selftest holds both on its dragged fixtures.
 *
 * ⛔ WHICH WALL CELLS STAND, AND HOW TALL (§ 12's *Door-view scale* and *Partition height*): a `wall` cell of a ROOM's
 * grid on its footprint's left, right or bottom edge is an `edge` — the room's full height; one inside it a `partition`.
 * A planned floor's hallway is classified against its own extent the same way. The footprint's top row is the wall
 * along the band, which the band itself stands in for, so it is no body; and a cell under a desk's slab is under the
 * desk standing on it, so it is none either.
 */

import { mapDesks, mapGrid } from './floor-layout.js';
import { mapTiles } from './map-tiles.js';
import { deskSlab } from './desk-geometry.js';

/**
 * A grid's cells in the floor page's tile order — `mapTiles()` under the grid's seed where it is a room.
 *
 * @param {object} g a grid
 * @param {function(string): object|null} tilesetFor the decoded tileset a map's tileset URL names
 * @returns {list<object>}
 */
export function gridCells(g, tilesetFor) {
    return mapTiles(g.map, g.origin, tilesetFor, g.room ? g.seed : null);
}

/**
 * The floor's one cell: the first ROOM's tile height (`T`). ⚠ Every shipped and live map is on one 8-px grid; rooms on
 * grids of different sizes on one floor are not defended (`plan-12987` § 7).
 *
 * @param {list<object>} grids the floor's grids in draw order
 * @returns {number}
 */
export function floorCell(grids) {
    return mapGrid(grids.find((g) => g.room).map).tileheight;
}

/**
 * Where a cell of `g` stands on the floor's grid of cell `T` (the header's rule): its top-left in floor px.
 *
 * @param {object} cell a `mapTiles()` cell
 * @param {object} g its grid
 * @param {number} T the floor's cell
 * @returns {{x: number, y: number}}
 */
export function cellOf(cell, g, T) {
    const grid = mapGrid(g.map);
    // Whole cells of a layer's or a tileset's offset, truncated toward zero.
    const whole = (v) => Math.trunc(v / T) * T;
    const atX = g.origin.x + cell.col * grid.tilewidth;
    const atY = g.origin.y + cell.row * grid.tileheight;

    return { x: atX + whole(cell.x - atX), y: atY + whole(cell.y + cell.h - atY - grid.tileheight) };
}

/**
 * Every room's desks' slabs, each snapped OUTWARD to the rows of ITS ROOM's grid it touches (at most a cell deeper), so
 * each spans rows the room's cells, walls and footprint lie in — a room's origin may be off the floor's 8-px lattice (an
 * authored planned floor), and a slab on the floor's lattice would then sort against the room's rows by key rather than
 * by depth in the door view.
 *
 * @returns {list<{x: number, y: number, w: number, h: number}>}
 */
export function deskSlabs(grids, T) {
    return grids.filter((g) => g.room).flatMap((g) => mapDesks(g.map).map((desk) => {
        const exact = deskSlab({ x: g.origin.x + desk.x, y: g.origin.y + desk.y + desk.height });
        const y0 = g.origin.y + Math.floor((exact.y - g.origin.y) / T) * T;

        return { ...exact, y: y0, h: g.origin.y + Math.ceil((exact.y + exact.h - g.origin.y) / T) * T - y0 };
    }));
}

/**
 * The floor's standing walls (the header's rule), one per `wall` cell in the floor page's tile order: its top-left on
 * the floor's grid, its cell, and its kind.
 *
 * @param {list<object>} grids the floor's grids in draw order
 * @param {function(object): list<object>} cellsOf a grid's `mapTiles()` cells, in tile order (`gridCells()`'s, which a
 *        caller that reads the cells itself hands once rather than tiling a map twice)
 * @param {number} T the floor's cell (`floorCell()`)
 * @param {list<object>} slabs the desks' slabs (`deskSlabs()`)
 * @returns {list<{x: number, y: number, size: number, kind: 'edge'|'partition'}>}
 */
export function wallBodies(grids, cellsOf, T, slabs) {
    const underSlab = (x, y) => slabs.some((s) => x < s.x + s.w && s.x < x + T && y < s.y + s.h && s.y < y + T);
    const walls = [];

    for (const g of grids) {
        const f = g.footprint;

        for (const cell of cellsOf(g)) {
            if (cell.kind !== 'wall') {
                continue;
            }

            const { x, y } = cellOf(cell, g, T);

            if (y === f.y || underSlab(x, y)) {
                continue;
            }

            const edge = x === f.x || x + T === f.x + f.width || y + T === f.y + f.height;

            walls.push(Object.freeze({ x, y, size: T, kind: edge ? 'edge' : 'partition' }));
        }
    }

    return walls;
}

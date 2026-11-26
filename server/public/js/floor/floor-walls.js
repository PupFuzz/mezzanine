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
import { RESTROOM_PLATES, SIDE_DOOR_BOX, mapTiles } from './map-tiles.js';
import { deskSlab } from './desk-geometry.js';
import { CENTRING } from './route.js';

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

/**
 * card#13033 — A RESTROOM DOOR's GEOMETRY, from the wall it is set into (FLOOR.md § 6.7 item 2; § 12's *Restroom door*):
 * computed once per door from the floor's wall BODIES — exactly what the 3-D floor and the cab's door view stand — and
 * read by every consumer. The tile spans the rows from its top, `cellOf().y + T − cell.h` (`cellOf()` answers the painted
 * FOOT cell), to its foot; where the distinct rows of wall bodies in the column against its right edge number every cell
 * row of its height the door faces `right`, its left edge likewise `left`, both `right`, neither `back`. A door touching
 * the footprint's top row cannot count that row, since `wallBodies()` leaves it out (the band stands there), and so
 * reads `back`.
 *
 * `back` answers today's face-on door: `{ facing, face: null, doorway: null, draw: null, plates }`, `plates` the tile's
 * (`RESTROOM_PLATES.back`). A side door, in scene px for `right` (`left` mirrors in x), every figure from the PLACED cell
 * (`cellOf()`), as the wall bodies are, so the doorway is cut from exactly the wall cells the facing counted: the wall
 * FACE `x_w`, the placed tile's right edge; the DOORWAY, the wall strip over the span from the placed foot line back by
 * the tile's width (`{x, y, w: T, h}`);
 * the 2-D drawing box (`SIDE_DOOR_BOX`) and the `side` plates in its px, mirrored as `box.w − x − w` for `left`.
 *
 * @param {object} cell a `restroom-door` cell of `g` (`mapTiles()`)
 * @param {object} g its grid
 * @param {list<{x: number, y: number}>} walls the floor's `wallBodies()`
 * @param {number} T the floor's cell (`floorCell()`)
 */
export function restroomDoorGeometry(cell, g, walls, T) {
    const at = cellOf(cell, g, T);
    const top = at.y + T - cell.h;
    const rows = (x) => new Set(walls.filter((w) => w.x === x && w.y >= top && w.y < top + cell.h).map((w) => w.y)).size;
    const full = cell.h / T;
    const facing = rows(at.x + cell.w) === full ? 'right' : rows(at.x - T) === full ? 'left' : 'back';

    if (facing === 'back') {
        return Object.freeze({ facing, face: null, doorway: null, draw: null, plates: RESTROOM_PLATES.back });
    }

    // Placed as the walls are (`at`), never by the drawn pixels: a layer dragged off the cell grid moves the tile's
    // picture, and the doorway still lines up with the wall column and rows the facing was read from.
    const right = facing === 'right';
    const face = right ? at.x + cell.w : at.x;
    const foot = at.y + T;
    const B = SIDE_DOOR_BOX;
    const mirror = (r) => Object.freeze(right ? r : { ...r, x: B.w - r.x - r.w });

    return Object.freeze({
        facing,
        face,
        doorway: Object.freeze({ x: right ? face : face - T, y: foot - cell.w, w: T, h: cell.w }),
        draw: Object.freeze({ x: right ? face - B.face : face - (B.w - B.face), y: foot - B.rise, w: B.w, h: B.h }),
        plates: Object.freeze(Object.fromEntries(Object.entries(RESTROOM_PLATES.side).map(([k, r]) => [k, mirror(r)]))),
    });
}

/**
 * The wall bodies' cells as `x:y` keys (scene px) — what `standingAgainst()` reads a wall off, built once per scene.
 *
 * @param {list<{x: number, y: number}>} walls the floor's `wallBodies()`
 * @returns {Set<string>}
 */
export function wallKeys(walls) {
    return new Set(walls.map((w) => `${w.x}:${w.y}`));
}

/**
 * WHICH WALL A STANDING PIECE STANDS AGAINST — the 3-D floor's rule W (`docs/design/plan-13033-stage2-3d-floor.md`
 * § 15.3): read once per piece, here beside `restroomDoorGeometry()`, from the PLACED cell as that reads a door (`at`,
 * `top` the tile's top row, `foot` its foot line), over the cell grid's columns the tile covers. A cell is wall where
 * `bodies` holds it or it lies on the footprint's top row, which the band stands in for. The first that holds:
 * - `back`: the NEAREST row that is wall across the tile's width, searched from the row above the tile's foot cell up to
 *   `T + CENTRING.reach` above the tile's top or to the footprint's top row, whichever is lower down (a picture taller
 *   than its room reaches above that row) — a strip shallower than a walk keeps from a walkway's edge is no walkway, and
 *   a wall the picture crosses is the one the body stands in front of; its face the floor's back edge for the band's
 *   row, else the row's foot. The foot cell's own row is never read: a face there would leave the body no depth, so a
 *   piece painted with its foot on a wall run stands free;
 * - `left` / `right`: the column against that edge of the tile, or one cell past it, is wall over the tile's foot strip
 *   (`min(w, h)` deep, where a turned piece stands) — a cell clear of the wall is how the shipped default stands its
 *   left-wall pieces; its face the wall's own;
 * - `front`: the row under the foot line is wall across the tile's width; its face the foot line;
 * - none: `{ against: null, face: null }`.
 * `back` is read before the sides, so a piece in a back corner faces the room's front as its picture does. A door's read
 * stays its own: a doorway needs wall over the tile's whole height, a piece over its plan depth.
 *
 * @param {object} cell a standing cell of `g` (`mapTiles()`)
 * @param {object} g its grid
 * @param {Set<string>} bodies the floor's wall cells (`wallKeys()`)
 * @param {number} T the floor's cell (`floorCell()`)
 * @returns {{against: ?('back'|'left'|'right'|'front'), face: ?number}} the face in scene px: a `y` for `back` and
 *          `front`, an `x` for the sides
 */
export function standingAgainst(cell, g, bodies, T) {
    const at = cellOf(cell, g, T);
    const top = at.y + T - cell.h;
    const foot = at.y + T;
    const f = g.footprint;
    // The cell grid's columns and rows the tile covers: a tile narrower than a whole number of cells covers the last one.
    const span = Math.ceil(cell.w / T) * T;
    const wallAt = (x, y) => y === f.y || bodies.has(`${x}:${y}`);
    const across = (y) => Array.from({ length: span / T }, (_, k) => at.x + k * T).every((x) => wallAt(x, y));
    const strip = Math.ceil(Math.min(cell.w, cell.h) / T) * T;
    const down = (x) => Array.from({ length: strip / T }, (_, k) => foot - strip + k * T).every((y) => wallAt(x, y));
    const answer = (against, face) => Object.freeze({ against, face });

    for (let y = foot - 2 * T; y >= Math.max(top - T - CENTRING.reach, f.y); y -= T) {
        if (across(y)) {
            return answer('back', y === f.y ? f.y : y + T);
        }
    }

    const left = [at.x - T, at.x - 2 * T].find(down);

    if (left !== undefined) {
        return answer('left', left + T);
    }

    const right = [at.x + span, at.x + span + T].find(down);

    if (right !== undefined) {
        return answer('right', right);
    }

    return across(foot) ? answer('front', foot) : answer(null, null);
}

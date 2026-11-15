/**
 * THE FLOOR BEHIND THE CAB's OPEN DOORS, AS A MODEL — `docs/design/FLOOR.md` § 4.5's *Through the open doors at a FLOOR is
 * that floor* (card#12987; the derivation and the build plan are `docs/design/plan-12987-cab-door-view.md` §§ 3–5). One
 * function, `doorView()`, projects a stop's own floor — its rooms composed as the floor page composes them, its maps'
 * `wall` and `accent` cells, its desks and its standing pieces, its theme — into the rider's frame in centimetres, and
 * answers every body the cab's painter draws in the one order it draws them. Pure and DOM-free, driven under `node`
 * (`tools/design/door-view.selftest.mjs`), and importing only modules the lobby page may load
 * (`Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s `DOOR_VIEW_ROOTS`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RIDER's FRAME (§ 4.5): the rider stands in the band at the elevator's threshold (`thresholdOf()`) and faces INTO the
 * room — down the page, toward the page's own viewer — so the rider's RIGHT is the floor's −x:
 * `X = (threshold.x − px.x) · SCALE` (positive to the right), `Z = (px.y − threshold.y) · SCALE` (into the room), `Y` up.
 *
 * ⛔ EVERY BODY LIES IN ONE ROW OF THE FLOOR's CELL GRID, OR ON ONE ROW BOUNDARY, SO THE DRAW ORDER IS ONE SORT (plan § 3.3):
 * a `wall` cell is its own row; a room's side walls, a desk's top and its monitor's back and a side restroom door's recess
 * are cut into row strips; the far wall and every standing piece (a billboard on its foot line) lie on a boundary. A cell
 * is placed on the grid once, here, by its tile index (a layer's or a tileset's offset draws it off the grid), so the
 * invariant holds for every map the floor page draws. The order: far rows first; a boundary's bodies between the row
 * beyond it and the row before it — on a boundary the far wall first (a piece on the last row stands on its plane, in
 * front of it), then the pieces in the floor page's own tile order;
 * within a row, the body farther from the eye's centre line first, a desk's parts at the desk's distance and its higher
 * part later. `tools/design/door-view.selftest.mjs` checks that order against an exact ray cast over random floors, ties
 * on a shared plane included.
 *
 * ⛔ A FLOOR THE CLIENT DOES NOT WHOLLY HOLD IS THE BARE ROOM, NEVER HALF A FLOOR (plan § 5): a room mapless by
 * `Building#mapless()`, a tileset a held map names that the loader does not hold, the stop's theme module not held, or a
 * stop the stops no longer offer — the model says `bare` and why, and carries no body.
 */

import { BAND_H, ELEVATOR, mapGrid, placeRooms, thresholdOf } from './floor-layout.js';
import { floorTheme } from './floor-theme.js';
import { PLANE_KINDS, RESTROOM_DOOR, sceneryInput } from './map-tiles.js';
import { namedTilesets } from './tileset.js';
import { monitorFrame } from './desk-geometry.js';
import { cellOf, deskSlabs, floorCell, gridCells, restroomDoorGeometry, wallBodies } from './floor-walls.js';
import { CAB, svgUri } from './cab-paint.js';

/** § 12's *Door-view scale*: cm per floor px — the cab's door (200 cm) over the band's leaves (144 px), the one object drawn on both sides. */
export const SCALE = CAB.door.h / ELEVATOR.h;

/** The room's height, cm: the band's 160 px at `SCALE` (§ 12's *Door-view scale* row) — a wall cell on its room's edge, and the walls. */
export const ROOM_H = BAND_H * SCALE;

/** § 12's *Partition height*, cm: a `wall` cell that does not lie on its room's footprint edge. */
export const PARTITION_H = 120;

/** An empty desk's top, cm above the floor, and its monitor's back from there to `MONITOR_TOP` (plan § 3.2's desk row). */
export const DESK_H = 76;
export const MONITOR_TOP = 147;

/**
 * The theme's colours the cab's painter reads, as the custom properties the view's root group carries (plan § 3.4): each
 * `[property, PALETTE row, key]` — the rows every theme has, and the `view` row the drift leg holds to the house theme's keys.
 */
export const VIEW_CARRIERS = Object.freeze([
    ['--dv-wall-top', 'wall', 'top'],
    ['--dv-wall-bottom', 'wall', 'bottom'],
    ['--dv-skirting', 'wall', 'skirting_top'],
    ['--dv-floor', 'floor', 'boards'],
    ['--dv-accent', 'accent', 'top'],
    ['--dv-lamplight', 'light', 'lamplight'],
    ['--dv-desk', 'view', 'desk'],
    ['--dv-desk-edge', 'view', 'desk_edge'],
    ['--dv-monitor', 'view', 'monitor'],
    ['--dv-partition', 'view', 'partition'],
    ['--dv-partition-cap', 'view', 'partition_cap'],
    ['--dv-recess', 'restroom-door', 'recess'],
].map((carrier) => Object.freeze(carrier)));

/**
 * The view behind the cab's doors at a floor's stop.
 *
 * @param {object|null} stopRow `lobby/building-model.js`'s `plates()` row for the stop (`floor`, `name`, `rooms`, `hallway`,
 *        `theme`), or `null` for a stop the stops no longer offer
 * @param {{room: function(string): object|null, mapless: function(string): boolean}} building the page's `wire/building.js` `Building`
 * @param {{held: Map<string, object>}} tilesets the page's `floor/tileset.js` `TilesetLoader`
 * @param {{registry: {names: list<string>, house: string, kinds: list<string>}|null, modules: Object<string, object|null>}} themes
 *        the scene's `themes` input (`floor/cab-paint.js`'s `themeInputs()`)
 * @returns {object} the frozen model: `stop`, `bare`, `reason` (`stop` / `mapless` / `tileset` / `theme`, or `null`), `theme`,
 *          `palette` (the custom properties, or `null` with no module held), and — for a floor drawn — `floor` (the floor
 *          plane's rect), `height` (the ceiling's, `ROOM_H`), `accents` (flat runs) and `bodies` in the order they are painted
 */
export function doorView(stopRow, building, tilesets, themes) {
    if (stopRow === null) {
        return bare(null, 'stop', null, null);
    }

    const resolved = themes.registry === null ? null : floorTheme(stopRow, themes.registry).theme;
    const module = resolved === null ? null : themes.modules[resolved] ?? null;
    const palette = module === null ? null : paletteOf(module.PALETTE);
    const ids = stopRow.rooms.map((room) => room.install_id);

    if (ids.some((id) => building.mapless(id))) {
        return bare(stopRow.floor, 'mapless', resolved, palette);
    }

    const maps = new Map(ids.map((id) => [id, building.room(id).held.map]));
    const placement = placeRooms(stopRow.rooms, new Map(ids.map((id) => [id, mapGrid(maps.get(id))])), stopRow.hallway ?? null);
    const held = tilesets.held;
    const urls = namedTilesets([placement.hallway === null ? null : stopRow.hallway, ...maps.values()]);

    // An absent set reads every tile of it as no kind — a floor with no walls, accents or pieces drawn with confidence.
    if (urls.some((url) => held.get(url)?.tileset === undefined)) {
        return bare(stopRow.floor, 'tileset', resolved, palette);
    }

    if (module === null) {
        return bare(stopRow.floor, 'theme', resolved, null);
    }

    return Object.freeze({
        stop: stopRow.floor,
        bare: false,
        reason: null,
        theme: resolved,
        palette,
        ...project(stopRow, placement, maps, (url) => held.get(url).tileset, themes.registry.kinds, module),
    });
}

/** A bare room's model (plan § 5): the reason, and the stop's palette where its module is held. */
function bare(stop, reason, theme, palette) {
    return Object.freeze({ stop, bare: true, reason, theme, palette, floor: null, accents: Object.freeze([]), bodies: Object.freeze([]) });
}

/** The theme's carriers, `VIEW_CARRIERS` read off its `PALETTE`. */
function paletteOf(P) {
    return Object.freeze(Object.fromEntries(VIEW_CARRIERS.map(([property, row, key]) => [property, P[row][key]])));
}

/**
 * The floor drawn: its plane, its accent runs, and every body in paint order — on ONE cell grid per floor
 * (`floor-walls.js`'s `floorCell()`).
 */
function project(stopRow, placement, maps, tilesetFor, kinds, module) {
    const rooms = new Map(placement.rooms.map((room) => [room.install_id, room]));
    const threshold = thresholdOf(placement.extent);
    const X = (px) => (threshold.x - px) * SCALE;
    const Z = (py) => (py - threshold.y) * SCALE;
    const standing = kinds.filter((kind) => !PLANE_KINDS.includes(kind));
    const bodies = [];
    const accents = [];
    let seq = 0;

    // A body's sort key (plan § 3.3): `2·row + 1` in a row, `2·m` on the boundary `y = m·T`; `tier` 0 for a plane on a
    // boundary (the far wall), 1 for a piece; `mx` its distance from the eye's centre line, 0 where it straddles it.
    const mxOf = (x1, x2) => (x1 <= 0 && x2 >= 0 ? 0 : Math.min(Math.abs(x1), Math.abs(x2)));
    const push = (body, key, sort = {}) => bodies.push({ body, key, tier: 0, mx: mxOf(body.x1, body.x2), rank: 0, seq: seq++, ...sort });
    const rowStrips = (py0, py1, make, sort) => {
        for (let y = py0; y < py1; y += T) {
            push(make(Z(y), Z(y + T)), (2 * y) / T + 1, sort);
        }
    };

    // The grids in the floor page's draw order: the hallway's (a planned floor only, its own extent standing in for a
    // footprint and contributing no walls), then each room's.
    const grids = [
        ...(placement.hallway === null ? [] : [{
            seed: stopRow.floor,
            map: stopRow.hallway,
            origin: { x: 0, y: 0 },
            footprint: { x: 0, y: 0, width: placement.hallway.pixel_width, height: placement.hallway.pixel_height },
            room: false,
        }]),
        ...placement.draw_order.map((id) => ({ seed: id, map: maps.get(id), origin: rooms.get(id).origin, footprint: rooms.get(id).footprint, room: true })),
    ];
    const T = floorCell(grids);
    // The desks' slabs, each strip of one spanning one of its room's rows (`deskSlabs()`).
    const slabs = deskSlabs(grids, T);
    const cells = new Map(grids.map((g) => [g, gridCells(g, tilesetFor)]));
    const walls = wallBodies(grids, (g) => cells.get(g), T, slabs);
    // Each restroom door's geometry from the wall bodies it stands against (`restroomDoorGeometry()`, as the scene reads it).
    // A door set into a SIDE wall is drawn in that wall (FLOOR.md § 6.7 item 2): its column's wall bodies over the doorway,
    // and the footprint's side strips on the column's outer face over those rows, cut to a lintel at the tile's height —
    // a body the lintel would leave nothing of left out, as the 3-D floor cuts them — and the opening closed at that
    // outer face by the dark recess, so the far end's wall cell shows its near face as the jamb and each lintel its
    // underside as the head. `outer` is that face's x: past the column for `right`, the column's own x for `left`.
    const doors = new Map(grids.flatMap((g) => cells.get(g)
        .filter((c) => c.kind === RESTROOM_DOOR.kind && standing.includes(c.kind))
        .map((c) => [c, restroomDoorGeometry(c, g, walls, T)])));
    const sides = [...doors].filter(([, d]) => d.facing !== 'back').map(([c, d]) => ({
        ...d,
        lintel: c.h * SCALE,
        outer: d.facing === 'right' ? d.doorway.x + d.doorway.w : d.doorway.x,
    }));
    // The lintel's height over the row `y` at `x`, where `x` is a side door's `lineOf()` and `y` a row of its doorway; else 0.
    const lintelAt = (x, y, lineOf) => sides.find((d) => lineOf(d) === x && y >= d.doorway.y && y < d.doorway.y + d.doorway.h)?.lintel ?? 0;

    // The rooms' walls, per FOOTPRINT (never the union extent): the far wall on its bottom edge, the side walls on its left
    // and right edges cut into row strips — a strip two rooms share drawn once. The top edge is the band, behind the eye.
    const sideStrips = new Set();

    for (const { footprint: f } of grids.filter((g) => g.room)) {
        push({ kind: 'far-wall', x1: X(f.x + f.width), x2: X(f.x), z1: Z(f.y + f.height), z2: Z(f.y + f.height), y1: 0, y2: ROOM_H }, (2 * (f.y + f.height)) / T);

        for (const edge of [f.x, f.x + f.width]) {
            for (let y = f.y; y < f.y + f.height; y += T) {
                if (!sideStrips.has(`${edge}:${y}`)) {
                    sideStrips.add(`${edge}:${y}`);
                    push({ kind: 'side-wall', x1: X(edge), x2: X(edge), z1: Z(y), z2: Z(y + T), y1: lintelAt(edge, y, (d) => d.outer), y2: ROOM_H }, (2 * y) / T + 1);
                }
            }
        }
    }

    // Every standing `wall` cell a box of its own, in the floor page's tile order (`wallBodies()`: an `edge` the room's
    // height, a `partition` the partition's — the footprint's top row, the near wall along the band, is none: drawn, it
    // would fill the doorway).
    for (const w of walls) {
        const edge = w.kind === 'edge';
        const full = edge ? ROOM_H : PARTITION_H;
        const y1 = lintelAt(w.x, w.y, (d) => d.doorway.x);

        if (y1 < full) {
            push({ kind: edge ? 'wall' : 'partition', x1: X(w.x + T), x2: X(w.x), z1: Z(w.y), z2: Z(w.y + T), y1, y2: full }, (2 * w.y) / T + 1);
        }
    }

    for (const d of sides) {
        rowStrips(d.doorway.y, d.doorway.y + d.doorway.h, (z1, z2) => ({ kind: 'doorway', x1: X(d.outer), x2: X(d.outer), z1, z2, y1: 0, y2: d.lintel }));
    }

    // Every other cell of every grid, in the floor page's tile order, placed on the grid by its tile index
    // (`cellOf()`): an `accent` cell a piece of a flat run, a standing kind a billboard on its foot line — but a restroom
    // door set into a side wall, whose billboard is its FLAG alone: the plate at right angles to the wall faces the eye,
    // standing on the doorway's foot line at the heights over it the plate's drawing-box rect gives.
    for (const g of grids) {
        for (const cell of cells.get(g)) {
            const { x, y } = cellOf(cell, g, T);
            const door = doors.get(cell) ?? null;

            if (cell.kind === 'accent') {
                accents.push({ x, y });
            } else if (door !== null && door.facing !== 'back') {
                const foot = door.doorway.y + door.doorway.h;
                const plate = { x: door.draw.x + door.plates.plate.x, y: door.draw.y + door.plates.plate.y, w: door.plates.plate.w, h: door.plates.plate.h };

                push({
                    kind: 'billboard',
                    piece: cell.kind,
                    tile: seq,
                    href: svgUri(module.scenery(sceneryInput(cell, g.seed, door, 'flag'))),
                    x1: X(plate.x + plate.w),
                    x2: X(plate.x),
                    z1: Z(foot),
                    z2: Z(foot),
                    y1: (foot - (plate.y + plate.h)) * SCALE,
                    y2: (foot - plate.y) * SCALE,
                }, (2 * foot) / T, { tier: 1 });
            } else if (standing.includes(cell.kind)) {
                const foot = y + T;

                push({
                    kind: 'billboard',
                    piece: cell.kind,
                    tile: seq,
                    href: svgUri(module.scenery(sceneryInput(cell, g.seed, door))),
                    x1: X(cell.x + cell.w),
                    x2: X(cell.x),
                    z1: Z(foot),
                    z2: Z(foot),
                    y1: 0,
                    y2: cell.h * SCALE,
                }, (2 * foot) / T, { tier: 1 });
            }
        }
    }

    // Each desk empty: its top cut into row strips, its monitor's back one row deep on the slab's near edge, both at the
    // desk's own distance from the eye's line so the monitor follows the top it stands on.
    for (const s of slabs) {
        const monitor = monitorFrame(s);
        const mx = mxOf(X(s.x + s.w), X(s.x));

        rowStrips(s.y, s.y + s.h, (z1, z2) => ({ kind: 'desk-top', x1: X(s.x + s.w), x2: X(s.x), z1, z2, y1: 0, y2: DESK_H }), { mx, rank: 0 });
        rowStrips(s.y, s.y + T, (z1, z2) => ({ kind: 'monitor', x1: X(monitor.x + monitor.w), x2: X(monitor.x), z1, z2, y1: DESK_H, y2: MONITOR_TOP }), { mx, rank: 1 });
    }

    bodies.sort((a, b) => b.key - a.key || a.tier - b.tier || (a.tier === 1 ? a.seq - b.seq : b.mx - a.mx || a.rank - b.rank || a.seq - b.seq));

    const extent = placement.extent;

    return {
        floor: Object.freeze({ x1: X(extent.x + extent.width), x2: X(extent.x), z1: Z(extent.y), z2: Z(extent.y + extent.height) }),
        height: ROOM_H,
        accents: Object.freeze(rowRuns(accents, T).map((r) => Object.freeze({ x1: X(r.x + r.w), x2: X(r.x), z1: Z(r.y), z2: Z(r.y + T) }))),
        bodies: Object.freeze(bodies.map(({ body }) => Object.freeze(body))),
    };
}

/** Adjacent cells of one row as one rect (plan § 3.2's accent row) — the accent's merge, flat on the plane and unordered. */
function rowRuns(cells, T) {
    const runs = [];

    for (const c of [...cells].sort((a, b) => a.y - b.y || a.x - b.x)) {
        const last = runs[runs.length - 1];

        if (last !== undefined && last.y === c.y && last.x + last.w >= c.x) {
            last.w = Math.max(last.w, c.x + T - last.x);
        } else {
            runs.push({ x: c.x, y: c.y, w: T });
        }
    }

    return runs;
}

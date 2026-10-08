/**
 * THE SCENE — `docs/design/FLOOR.md` Appendix B row 14's model: what is drawn where on the floor,
 * as data. It reads step 7's frame (`floor/floor-screen.js` — each room's placement, the floor's
 * extent, the back-wall band and every desk's slot) and the documents the client holds (each room's
 * map, the floor's hallway, the decoded tilesets), and emits every tile, every desk's elements, the
 * floor's frame (§ 4.2: the band with its elevator, clock and windows, the slab, each room's plane),
 * the overflow strip, the coordination line and the § 6.2 forms to draw, and § 9 F21's notices.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A MODEL AND NOT A PAGE (row 14: "a model no browser is needed for, and a DOM half no check
 * exercises"). There is no browser on the build host, so every decision about where a thing lands
 * is made here and read headlessly — AT-D3-19 and AT-D3-20 read this module's output — and
 * `floor/painter.js` draws what this says and decides nothing.
 *
 * ⛔ WHAT IT READS IS § 10.3's READ MEMBERS AND NOTHING BEYOND THEM: the grid, `tilesets[]`, the
 * tile layers in document order bottom first, and the one `desks` object group. It reads no field
 * steps 5 and 7 do not already hold and adds no § 6.2 row.
 *
 * ⛔ THE INPUTS A MODULE CANNOT IMPORT ARRIVE AS INPUTS: the furniture box and the desk sprite
 * (`resources/floor/furniture-box.js`), the page's text measurer, and the character's size
 * (`resources/characters/index.js`'s `SCENE_W`/`SCENE_H`). Both trees are served at the asset
 * route's absolute URLs, which resolve under no `node` harness, so a scene that imported them would
 * be a model no gate can load (row 14). The painter supplies them on the page and the fixture in the
 * harness.
 *
 * ⛔ THE SCENE STARTS NO CLAIM-BEARING MOTION. Every § 6.2 form below is the drawing of a row the
 * animation set already logged or a held render the desk model already selected; decorative motion
 * (§ 6.3) is emitted as data too — which element, where, that it is decoration — so the painter
 * draws it without deciding it and § 11's review question has a list to read.
 */

import { ANIMATION_SET, LOOP_FPS, loops } from '../wire/animation-set.js';
import { footprintsIntersect, mapDesks, mapGrid, mapLayers } from './floor-layout.js';
import { readEmbedded, splitGid, tilesetUrl } from './tileset.js';
import { BUBBLE_BAND, BUBBLE_PAD, CHARACTER_SCALE, LINE, characterCentre, deskLayout, fit, furnitureAsset, union } from './desk-layout.js';
import { bubbleLayout } from '../desk/task-bubble.js';

/**
 * The back-wall band's height (§ 4.2) — "its height is a drawing layer's" (`floor-layout.js`): a wall
 * about 2.2 m tall at the floor's scale (card#11045, the operator's 2026-10-01 ruling: tall office
 * windows, not a jail's). FLOOR.md § 12's *Back-wall band height* row is its other home, and
 * `Tests\Feature\Floor\TheFloorDrawsItsFrameTest` holds the two equal — as it does `ZONE_W` and
 * `WINDOW`'s pitch and glazing below.
 */
export const BAND_H = 160;

/**
 * THE FLOOR's FRAME (§ 4.2's frame list, card#11045 PR-D) — the band's parts, in scene px from the
 * band's left edge and top. Layout, carrying no fact, and this module's own.
 *
 * ⛔ THE RESERVED ZONE IS THE BAND's LEFT `ZONE_W` px, AND NO WINDOW EVER ENTERS IT: the two-door
 * elevator and the wall clock hang there, so the clock is never covered whatever the floor's width.
 * A band narrower than the zone — a stored map can be that narrow (F21) — is WIDENED to it, so the
 * clock (A17's fact) is always on the wall and never hanging past its edge over the exterior.
 *
 * ⛔ THE ELEVATOR IS WHERE A SEAT LEAVES AND RETURNS (§ 4.2, card#9566). It is the lobby cab's form — two
 * leaves meeting at a seam, a lamp over them — in the lobby's own door colours (`--door` / `--door-edge`),
 * so the floor and the lobby read as one building. Its leaves open and close inside an A1 or A2 walk and
 * for nothing else, and it takes no § 6.2 row of its own. It never navigates: the elevator that does is
 * the lobby's (§ 4.1), reached by the floor's *whole building* control.
 *
 * ⛔ THE WINDOWS FILL WHAT IS LEFT, PAST THE ZONE: `n = ⌊(w − ZONE_W − margin) ÷ pitch⌋`, at least
 * one, spread evenly, each centred in its cell and `WINDOW.w` wide — narrowed to its cell less the gap
 * on a band too short for a full one, never under `WINDOW.min_w`. BELOW THE WIDTH THAT HOLDS THE ZONE,
 * THE MARGIN AND ONE MINIMUM WINDOW WITH ITS GAP (`ZONE_W + margin + min_w + gap`) THERE ARE NONE
 * (design review r3 MINOR-1): the formula's *at least one* holds from that width up, and under it a
 * window — or its sill — could only be drawn inside the zone. A stored map can be that narrow (F21).
 */
const ZONE_W = 272;
const ELEVATOR = { dx: 32, w: 84, h: 144, frame: 6, header: 14 };
const CLOCK = { dx: 164, size: 64 };
const WINDOW = { margin: 24, pitch: 360, w: 208, min_w: 96, gap: 24, h: 80, dy: 22 };

/**
 * THE RECTS A FLOOR's THEME DRAWS THE BAND's SURROUNDS IN (FLOOR.md § 10.6 item 2, § 12): a window's surround
 * is its glazing grown by the surround margin — 32 px each side, 16 above, 18 below — and the elevator's is
 * its frame grown 18 px to the right, where the call buttons hang. Both stay clear of the clock's face at
 * every width (AT-D3-25's band leg).
 */
export const SURROUND = Object.freeze({ side: 32, above: 16, below: 18, elevator_reach: 18 });

/** The slab under the floor's rooms — the front wall's top edge, scenery, outside every grid (§ 4.2). */
export const SLAB_H = 8;

/**
 * FLOOR.md § 10.6 item 6's two classes of tile kind: the PLANE kinds, whose cells are merged into runs by the
 * one region pass (`tileRegions()`) and handed to the grid's plane document, and every other kind of the
 * registry's set, which STANDS — drawn at its tile's cell rect by the floor's theme. A tile with no kind, or
 * a kind the registry does not name, is drawn by nothing: the floor never draws a tile's image.
 */
export const PLANE_KINDS = Object.freeze(['wall', 'accent']);

/** The overflow strip's gap below the floor's extent, and its header — § 3.2's labelled row. */
export const STRIP_GAP = 24;

/**
 * The strip's header — the heading the floor page already carries for the same row
 * (`resources/views/floor.blade.php`), now drawn on the floor where the row is.
 *
 * § 3.2's label for the row, in the wording the operator ratified (card#7342, 2026-09-25).
 */
export const STRIP_HEADER = "Overflow — seats past the map's desks";

/**
 * § 6.3's bound on decorative motion: a cycle of at least § 12's *Decorative motion's minimum
 * cycle* (2 s). The glow runs at the 2.4 s the operator ratified in `docs/design/floor-preview/`.
 */
export const DECORATIVE_CYCLE_MS = 2400;

/**
 * A decoration's glow (§ 6.3): the ellipse the painter fills, INSIDE ITS TILE — at the tile's top, where
 * the kit's floor and table lamps carry their shade, the tile's full width across and as tall as it is
 * wide (no taller than the tile). The scene decides it, so a glow reaches no further than its own tile:
 * a lamp the author keeps inside the room's grid glows inside it (card#11045: the glow was drawn about
 * twice its tile, centred on its foot, and a lamp at the room's front edge glowed below the floor).
 */
function glowOf(cell) {
    const ry = Math.min(cell.w, cell.h) / 2;

    return Object.freeze({ cx: cell.x + cell.w / 2, cy: cell.y + ry, rx: cell.w / 2, ry });
}

/**
 * § 9 F21's two notices, in § 5.5's words: the objects by Tiled `id` in `id` order, the room, and
 * after the colon the `seat_id` at each object — omitted for an empty one.
 */
export function intersectNotice(a, b, installId, seats) {
    return `desk objects \`${a}\` and \`${b}\` intersect — ${roomSuffix(installId, seats)}`;
}

/**
 * § 9 F23's notice, in § 5.5's words (card#11046, FLOOR.md § 10.6 item 5): the theme as the layout names
 * it, and the floor by its name — `label ?? key`, the one rendering rule § 4.6 states.
 */
export function unshippedThemeNotice(name, floorName) {
    return `floor theme \`${name}\` is not installed — drawn in the house theme — \`${floorName}\``;
}

/**
 * The theme a floor is drawn in (FLOOR.md § 10.6 item 5): the one its layout entry names when the registry
 * holds it; the house theme when it names none; and the house theme under § 9 F23's notice when it names
 * one the build does not ship. With no registry held (`themes` absent — it failed to load, which is § 9
 * F14's), nothing is resolved and nothing is claimed.
 *
 * @param {{theme: string|null, name: string}} floor the frame's floor
 * @param {{names: list<string>, house: string}|null|undefined} themes the registry's `THEMES` and `HOUSE_THEME`
 * @returns {{theme: string|null, notice: string|null}}
 */
export function floorTheme(floor, themes) {
    if (themes === null || themes === undefined) {
        return { theme: null, notice: null };
    }

    if (floor.theme === null || floor.theme === undefined) {
        return { theme: themes.house, notice: null };
    }

    return themes.names.includes(floor.theme)
        ? { theme: floor.theme, notice: null }
        : { theme: themes.house, notice: unshippedThemeNotice(floor.theme, floor.name) };
}

/**
 * THE FLOOR's THEME, AND WHETHER IT CAN DRAW (FLOOR.md § 10.6 items 5 and 8): the registry the page loaded
 * (`themes.registry`, or `null` where its import was rejected), the theme module of each name it holds
 * (`themes.modules`, a name's `null` where that import was rejected) and what has failed. A floor whose theme
 * cannot draw — no registry, its module rejected or reported failed — draws every desk's placeholder and the
 * band's and every plane's flat fallback fills, and § 9 F14's strip names what failed: the registry by its
 * asset id, the module as `theme:<name>`. Each is added to `used` so the strip can name it.
 *
 * @returns {{name: string|null, resolved: string|null, drawn: boolean, kinds: list<string>, notice: string|null}}
 */
function themeOf(floor, themes, failed, used) {
    if (themes === null || themes === undefined) {
        return { name: null, resolved: null, drawn: false, kinds: [], notice: null };
    }

    if (themes.registry === null) {
        used.add(themes.asset);

        return { name: null, resolved: null, drawn: false, kinds: [], notice: null };
    }

    const resolved = floorTheme(floor, themes.registry);
    const asset = `theme:${resolved.theme}`;
    const held = (themes.modules?.[resolved.theme] ?? null) !== null && !failed.has(asset);

    if (!held) {
        used.add(asset);
    }

    return { name: resolved.theme, resolved: resolved.theme, drawn: held, kinds: [...themes.registry.kinds], notice: resolved.notice };
}

/**
 * The band's theme documents, each at the rect the scene decides (§ 10.6 item 2): the wall at the band, a
 * surround per window, the elevator's surround and the clock's case — band-relative inputs, scene rects.
 * Exported for the theme gates (`tools/floor-themes/selftest.mjs`), which draw them over every band width.
 */
export function bandDocuments(band) {
    const rel = (r) => Object.freeze({ x: r.x - band.x, y: r.y - band.y, w: r.w, h: r.h });
    const docs = [
        {
            fn: 'band',
            rect: { x: band.x, y: band.y, w: band.w, h: band.h },
            input: {
                w: band.w,
                h: band.h,
                zone: Object.freeze({ x: band.zone.x - band.x, w: band.zone.w }),
                elevator: rel(band.elevator.frame),
                clock: rel(band.clock),
                windows: Object.freeze(band.windows.map(rel)),
            },
        },
        ...band.windows.map((w, index) => ({ fn: 'windowSurround', rect: w.surround, input: { index, glazing: Object.freeze({ w: w.w, h: w.h }) } })),
        { fn: 'elevatorSurround', rect: band.elevator.surround, input: {} },
        { fn: 'clockCase', rect: { x: band.clock.x, y: band.clock.y, w: band.clock.w, h: band.clock.h }, input: {} },
    ];

    return Object.freeze(docs.map((d) => Object.freeze({ fn: d.fn, rect: Object.freeze(d.rect), input: Object.freeze(d.input) })));
}

export function undersizedNotice(id, installId, seats) {
    return `desk object \`${id}\` is smaller than the furniture box — ${roomSuffix(installId, seats)}`;
}

function roomSuffix(installId, seats) {
    const named = seats.filter((seat) => seat !== null);

    return named.length === 0 ? installId : `${installId}: ${named.join(', ')}`;
}

/**
 * The walk and the travel speeds the edge rows' frames are counted from, in scene pixels per frame
 * at § 12's loop rate. They decide how many frames a walk takes and carry no fact: every walk of
 * one length takes the same number of frames, whatever the seat.
 */
export const WALK_PX_PER_FRAME = 48;
const ENVELOPE_PX_PER_FRAME = 96;
const RING_PX_PER_FRAME = 240;
const RING_FADE_FRAMES = 2;

/**
 * A moving A18 line's flow: the frames one dash period takes to travel along it, at § 12's loop rate.
 * It decides how fast the dashes flow and carries no fact — every moving line flows alike.
 */
const THREAD_FLOW_FRAMES = 4;

/**
 * The elevator's leaves, in loop frames (FLOOR § 12's *Elevator leaves* row, card#9566): opening, the
 * step through the doorway, closing.
 */
export const ELEVATOR_DOOR = Object.freeze({ open: 2, step: 1, close: 2 });

/** Where every A1 and A2 walk meets the elevator: the leaves' seam, at their foot (§ 6.2's walk note item 3). */
export function threshold(band) {
    return band === null || band === undefined ? null : Object.freeze({ x: band.elevator.seam, y: band.elevator.y + band.elevator.h });
}

/**
 * One A1 or A2 walk (FLOOR § 6.2's walk note items 3 and 5): A16's straight-segment rule between the
 * desk's anchor and the elevator's threshold, `⌈length ÷ WALK_PX_PER_FRAME⌉` frames, and the door's
 * frames around it. A2 walks to the threshold, the leaves open, the walker steps in and is gone, the
 * leaves close; A1 is the reverse, the leaves closing behind it while it walks, and it sits on the
 * walk's last frame. `shown` is the frames the walker is drawn in, `walk` the frames it moves in, `door`
 * the frames the leaves are not shut in. With no motion, or no desk on this floor, it draws nothing.
 */
function walkEffect(base, id, desk, door, motion, character) {
    if (!motion || desk === null || door === null) {
        return Object.freeze({ ...base, from: null, to: null, frames: 0, walk: null, shown: null, door: null, size: null });
    }

    const [from, to] = id === 'A2' ? [desk, door] : [door, desk];
    const steps = Math.max(1, Math.ceil(Math.hypot(to.x - from.x, to.y - from.y) / WALK_PX_PER_FRAME));
    const D = ELEVATOR_DOOR;
    const doorFrames = D.open + D.step + D.close;
    const timing = id === 'A2'
        ? { walk: { start: 0, frames: steps }, shown: { start: 0, frames: steps + D.open + D.step }, door: { start: steps, frames: doorFrames } }
        : { walk: { start: D.open + D.step, frames: steps }, shown: { start: D.open, frames: D.step + steps }, door: { start: 0, frames: doorFrames } };

    return Object.freeze({
        ...base,
        from,
        to,
        ...timing,
        // The walker is the desk's character at its drawn size, its feet on the segment.
        size: Object.freeze({ w: character.w * CHARACTER_SCALE, h: character.h * CHARACTER_SCALE }),
        frames: Math.max(timing.walk.start + timing.walk.frames, timing.door.start + timing.door.frames),
    });
}

/** A § 6.2 edge row's single-frame forms — the 250 ms fades and eases, one loop frame each. */
const ONE_FRAME = new Set(['A5', 'A11', 'A12', 'A14', 'A17']);

/**
 * The scene for one floor frame, or `null` when the frame composed no floor.
 *
 * @param {object} frame `FloorScreen#draw()`'s frame
 * @param {object} input `{ box, measure, character, themes, maps, hallway, tilesets, failed,
 *        reduce, effects, previous }` — `themes` is `painter.js`'s `themeInputs()`; `maps` is
 *        install_id → the held map document or `null`; `tilesets` is `TilesetLoader#held`; `failed` the
 *        asset ids the painter reported; `effects` the animation-log rows this render wrote; `previous`
 *        key → the box the last scene drew
 */
export function buildScene(frame, input) {
    if (frame.floor === null || frame.floor === undefined || frame.rooms.length === 0) {
        return null;
    }

    const failed = new Set(input.failed ?? []);
    const used = new Set();
    const tilesetFailures = new Set();
    const decorative = [];
    const box = input.box;
    const W = box.width;
    const H = box.height;

    const tilesetFor = (url) => {
        const held = input.tilesets.get(url);

        if (held === undefined || held.pending === true) {
            return null;
        }

        if (held.failure !== undefined) {
            tilesetFailures.add(url);

            return null;
        }

        return held.tileset;
    };

    // ── The floor's theme (FLOOR.md § 10.6 item 5, § 9 F23), and whether it can draw (§ 10.6 item 8) ──
    const theme = themeOf(frame.floor, input.themes, failed, used);

    /**
     * One theme document the painter is to draw — the asset it is reported under, the API function, and
     * the inputs the scene hands it (§ 10.6 item 2) — or `null` where the theme cannot draw it: none held,
     * or that asset already reported failed. The painter's flat fallback is drawn under it either way.
     */
    const docOf = (asset, fn, docInput) => {
        if (!theme.drawn) {
            return null;
        }

        used.add(asset);

        return failed.has(asset) ? null : Object.freeze({ asset, theme: theme.name, fn, input: Object.freeze(docInput) });
    };

    // ── Each grid in draw order — the hallway's, then each room's: its plane, its scenery ─────────
    const grids = [];

    if (frame.planned && input.hallway !== null && input.hallway !== undefined) {
        grids.push({ install_id: null, map: input.hallway, origin: { x: 0, y: 0 }, seed: frame.floor.key });
    }

    const byRoom = new Map(frame.rooms.map((room) => [room.install_id, room]));

    for (const installId of frame.draw_order) {
        const room = byRoom.get(installId);
        const map = input.maps.get(installId) ?? null;

        // F16: a room whose map is not drawn has no plane — "every fact, no room".
        if (!room.mapless && map !== null && room.footprint !== null) {
            grids.push({ install_id: installId, map, origin: room.origin, seed: installId, footprint: room.footprint });
        }
    }

    // ⛔ THE FRAME's ONE PER-GRID PRIMITIVE: a plane under the grid's whole extent, opaque over the hallway as
    // a room is (§ 4.2), drawn before that grid's scenery — so it is under everything the author placed. Its
    // flat `--scene-floor` fill is drawn on every paint, and the theme's plane document over it.
    const band = frame.band === null ? null : backWall(frame.band, frame.room);
    const door = threshold(band);
    const planes = [];
    const scenery = [];
    const standing = theme.kinds.filter((kind) => !PLANE_KINDS.includes(kind));
    // The landing is drawn on the plane of the TOPMOST grid in draw order that holds the threshold (§ 10.6 item 7).
    const holds = (g, extent) => door !== null && door.x >= extent.x && door.x < extent.x + extent.w && door.y >= extent.y && door.y < extent.y + extent.h;
    // A room's extent is its footprint (`floor-layout.js`'s `placeRooms()`); the hallway's, its grid at the origin.
    const extents = grids.map((g) => {
        const grid = mapGrid(g.map);
        const f = g.footprint ?? (grid === null ? null : { x: g.origin.x, y: g.origin.y, width: grid.width * grid.tilewidth, height: grid.height * grid.tileheight });

        return grid === null || f === null ? null : { x: f.x, y: f.y, w: f.width, h: f.height, cell: { w: grid.tilewidth, h: grid.tileheight } };
    });
    const landingAt = extents.findLastIndex((e, i) => e !== null && holds(grids[i], e));

    grids.forEach((g, i) => {
        const extent = extents[i];

        if (extent === null) {
            return;
        }

        const cells = mapTiles(g.map, g.origin, tilesetFor, g.install_id);
        const of = (kind) => tileRegions(cells.filter((c) => c.kind === kind))
            .flatMap((region) => region.rects)
            .map((r) => Object.freeze({ x: r.x - extent.x, y: r.y - extent.y, w: r.w, h: r.h }));
        const desksOn = mapDesks(g.map).map((o) => Object.freeze({ x: o.x, y: o.y, w: o.width, h: o.height }));
        const asset = `theme:${theme.name}/plane:${g.install_id ?? 'hallway'}`;

        planes.push(Object.freeze({
            install_id: g.install_id,
            x: extent.x,
            y: extent.y,
            w: extent.w,
            h: extent.h,
            doc: docOf(asset, 'plane', {
                w: extent.w,
                h: extent.h,
                seed: g.seed,
                desks: Object.freeze(desksOn),
                walls: Object.freeze(of('wall')),
                accents: Object.freeze(of('accent')),
                cell: Object.freeze(extent.cell),
                band_foot: band !== null && band.y + band.h === extent.y,
                threshold: i === landingAt ? Object.freeze({ x: door.x - extent.x, y: door.y - extent.y }) : null,
            }),
        }));

        // § 10.6 item 6: every standing kind at its tile's cell rect, in tile order (the row-major y-sort).
        for (const cell of cells) {
            if (!standing.includes(cell.kind)) {
                continue;
            }

            const doc = docOf(`theme:${theme.name}/scenery:${cell.kind}`, 'scenery', { kind: cell.kind, w: cell.w, h: cell.h, seed: g.seed, index: cell.index });

            if (doc === null) {
                continue;
            }

            const decoration = typeof cell.properties?.decoration === 'string' ? cell.properties.decoration : null;

            scenery.push(Object.freeze({ room: g.install_id, kind: cell.kind, x: cell.x, y: cell.y, w: cell.w, h: cell.h, decoration, doc }));

            // § 6.3's decorative glow — a floor lamp's — the painter's, inside the tile of the piece it lights.
            if (decoration !== null) {
                decorative.push(Object.freeze({
                    decoration: cell.properties.decoration,
                    x: cell.x,
                    y: cell.y,
                    w: cell.w,
                    h: cell.h,
                    glow: glowOf(cell),
                    // § 6.3: claims nothing — no test reads it, it keeps glowing on a dead feed and
                    // asserts nothing by it, and it is on no element a § 6.2 row draws.
                    bound: '§ 6.3',
                    cycle_ms: DECORATIVE_CYCLE_MS,
                    motion: input.reduce !== true,
                }));
            }
        }
    });

    // ── Desks: inside their slots, the later `id` on top; F21's notices per room ──────────────
    const desks = [];
    const notices = [];
    const models = frame.desks.desks;

    // § 9 F14: a desk draws its placeholder when its art cannot be drawn — the floor's theme held by
    // nothing, its furniture set reported failed (all or nothing, § 10.6 item 8), or its character's.
    const artFor = (desk) => {
        const character = `character:${desk.install_id}/${desk.seat_id}`;
        const set = theme.drawn ? furnitureAsset(theme.name, desk) : null;

        if (desk.character) {
            used.add(character);
        }

        if (set !== null) {
            used.add(set);
        }

        return set === null || failed.has(set) || (desk.character && failed.has(character));
    };

    for (const installId of frame.draw_order) {
        const room = byRoom.get(installId);
        const map = input.maps.get(installId) ?? null;
        const objects = room.mapless || map === null ? [] : mapDesks(map);
        const seatAt = new Map(room.desks.filter((d) => d.slot !== null).map((d) => [d.slot, d.key]));

        if (!room.mapless && map !== null) {
            notices.push(...f21(installId, objects, seatAt, box, models));
        }

        const placed = room.desks
            .map((d) => ({ d, object: d.slot === null ? null : objects[d.slot] }))
            .sort((a, b) => (a.object?.id ?? 0) - (b.object?.id ?? 0) || a.d.key.localeCompare(b.d.key));

        for (const { d, object } of placed) {
            const model = models[d.key];

            if (model === undefined) {
                continue;
            }

            const slot = object === null ? null : {
                x: room.origin.x + object.x,
                y: room.origin.y + object.y,
                w: object.width,
                h: object.height,
            };
            // The box's anchor is the slot's bottom-left — the floor line its author drew — so a
            // slot at least the box's size holds it, and an undersized one's desk is drawn at
            // native size from that anchor, past the slot's edge (F21), neither scaled nor clipped.
            const at = slot === null
                ? { x: room.origin.x + d.grid_index * W, y: room.origin.y }
                : { x: slot.x, y: slot.y + slot.h - H };

            desks.push(placeDesk(model, d.key, installId, slot, at, {
                box,
                measure: input.measure,
                character: input.character,
                theme: theme.drawn ? theme.name : null,
                // F16: a mapless room's every desk is the placeholder, in a plain grid.
                placeholder: room.mapless || artFor(model),
                failed,
            }, { overflow: false, slot: d.slot, object_id: object?.id ?? null }));
        }
    }

    // § 9 F23: an unshipped name draws the house theme, and says so.
    if (theme.notice !== null) {
        notices.push(theme.notice);
    }

    // ── § 3.2's overflow row: the strip below the floor, each desk at the box, no slot ────────
    const extent = frame.extent;
    let strip = null;

    if (frame.overflow.length > 0 && extent !== null) {
        const top = extent.y + extent.height + STRIP_GAP;
        const header = fit(STRIP_HEADER, Math.max(W, extent.width), input.measure);
        const headerRect = { x: extent.x, y: top, w: header.w, h: header.h, text: header.text, truncated: header.truncated };
        // § 9 F13 (card#11045): the bench WRAPS — as many boxes to a row as the floor is wide, at least
        // one, and further rows below — so it never runs past the floor's width however many seats it holds.
        const perRow = Math.max(1, Math.floor(extent.width / W));
        const rows = Math.ceil(frame.overflow.length / perRow);

        frame.overflow.forEach((key, i) => {
            const model = models[key];

            if (model === undefined) {
                return;
            }

            const at = { x: extent.x + (i % perRow) * W, y: top + LINE + 4 + Math.floor(i / perRow) * H };

            desks.push(placeDesk(model, key, model.install_id, null, at, {
                box,
                measure: input.measure,
                character: input.character,
                theme: theme.drawn ? theme.name : null,
                placeholder: artFor(model),
                failed,
            }, { overflow: true, slot: null, object_id: null }));
        });

        strip = Object.freeze({
            x: extent.x,
            y: top,
            w: Math.max(extent.width, Math.min(frame.overflow.length, perRow) * W),
            h: LINE + 4 + rows * H,
            header: Object.freeze(headerRect),
        });
    }

    // § 9 F14: each intern's sprite is an asset of its own — a failure falls back for that stool alone
    // (`deskLayout()`), and is named on the strip like any other.
    for (const desk of desks) {
        for (const e of desk.elements) {
            if (e.kind === 'stool') {
                used.add(e.asset);
            }
        }
    }

    // ── The bubbles: § 5.1 rule 5's pass over the base rects, in the box's top band ───────────
    placeBubbles(desks, input.measure, W, Object.keys(models));

    // ── The frame: the band over the floor's whole extent (§ 4.2) and the slab under it ────────
    // The band's documents are ONE failure unit (§ 10.6 item 8): the wall and every surround, drawn all or
    // none over the band's flat fallback fill.
    const bandAsset = `theme:${theme.name}/band`;

    if (band !== null && theme.drawn) {
        used.add(bandAsset);
    }

    const bandDocs = band === null || !theme.drawn || failed.has(bandAsset) ? null : Object.freeze({ asset: bandAsset, theme: theme.name, docs: bandDocuments(band) });
    const slab = band === null || extent === null ? null : Object.freeze({
        x: band.x,
        y: extent.y + extent.height,
        w: band.w,
        h: SLAB_H,
    });

    // ── The coordination line and the § 6.2 forms this render draws ───────────────────────────
    const anchors = new Map(desks.map((desk) => [desk.key, anchorOf(desk)]));
    const lines = buildLines(frame, anchors, input);
    const effects = buildEffects(input.effects ?? [], frame, anchors, input.previous ?? new Map(), extent, band, input.reduce === true, input.character);

    // § 9 F14: every asset the scene asked to be drawn that the painter reported failed, and every
    // tileset that could not be fetched or read.
    const failedAssets = [...new Set([...[...used].filter((id) => failed.has(id)), ...tilesetFailures])].sort();

    const all = [
        ...(band === null ? [] : [{ x: band.x, y: band.y, w: band.w, h: band.h }]),
        ...(extent === null ? [] : [{ x: extent.x, y: extent.y, w: extent.width, h: extent.height }]),
        ...(slab === null ? [] : [slab]),
        ...desks.map((desk) => desk.box),
        ...(strip === null ? [] : [strip]),
    ];

    return Object.freeze({
        extent: union(all),
        band,
        slab,
        band_docs: bandDocs,
        planes: Object.freeze(planes),
        scenery: Object.freeze(scenery),
        desks: Object.freeze(desks),
        strip,
        lines,
        effects,
        decorative: Object.freeze(decorative),
        notices: Object.freeze(notices),
        theme: theme.resolved,
        failed: Object.freeze(failedAssets),
        art_failed: failedAssets.length > 0,
        // Where each desk's lines and walks meet it — what the NEXT render's walks start from.
        anchors: Object.freeze(Object.fromEntries(anchors)),
        loop_fps: LOOP_FPS,
    });
}

/**
 * One desk placed at `at`: its box, its elements in scene coordinates, and their union. Exported for
 * the desk leaf guard (`TheNewDeskKeepsEveryLeafTest`), which reads a desk as the painter is handed it.
 */
export function placeDesk(model, key, installId, slot, at, ctx, extra) {
    const { elements, bubble } = deskLayout(model, ctx);
    const moved = elements.map((e) => Object.freeze({ ...e, x: at.x + e.x, y: at.y + e.y }));

    return {
        key,
        install_id: installId,
        seat_id: model.seat_id,
        ...extra,
        slot_rect: slot === null ? null : Object.freeze(slot),
        box: Object.freeze({ x: at.x, y: at.y, w: ctx.box.width, h: ctx.box.height }),
        // FLOOR.md § 10.6: where the bubble, the thread line and the walks meet this desk — its sitter's
        // centre line, through the one primitive (`characterCentre()`).
        anchor_x: at.x + characterCentre(ctx.box, ctx.character),
        placeholder: ctx.placeholder,
        elements: Object.freeze(moved),
        furniture: Object.freeze(union(moved)),
        held: model.held,
        lighting: model.lighting,
        bubble_model: bubble,
        bubble: null,
    };
}

/**
 * § 5.1 rules 4 and 5: each bubble's text cut to the box through the one primitive, its box sized
 * from the MEASURED text, and overlapping bubbles parted by `desk/task-bubble.js`'s own pass over
 * the base rects — here, the box's top band, above the character's column. Exported, with
 * `placeDesk()`, for the desk leaf guard.
 */
export function placeBubbles(desks, measure, W, delivered) {
    const inner = W - 2 * BUBBLE_PAD;
    const wanted = [];
    // ⛔ HANDED OVER IN THE ORDER THE CLIENT HOLDS THE SEATS, which is the order they were DELIVERED
    // — deliberately not an order this module fixes, because rule 5's determinism is
    // `bubbleLayout()`'s own sort and must not rest on a caller that happened to sort first
    // (AT-D3-20's sixth RED plants exactly that sort away).
    const order = new Map(delivered.map((key, i) => [key, i]));
    const byDelivery = [...desks].sort((a, b) => (order.get(a.key) ?? 0) - (order.get(b.key) ?? 0));

    for (const desk of byDelivery) {
        const b = desk.bubble_model;

        delete desk.bubble_model;

        if (b === null || b === undefined) {
            continue;
        }

        const line1 = fit(b.text, inner, measure);
        const second = [b.source, b.degraded_note].filter((s) => s !== null && s !== undefined);
        const line2 = second.length === 0 ? null : fit(second.join(' · '), inner, measure);
        const w = Math.max(line1.w, line2?.w ?? 0) + 2 * BUBBLE_PAD;
        const h = (line2 === null ? LINE : 2 * LINE) + 2 * BUBBLE_PAD;
        // Above the character's column, kept inside the box: the anchor is the character's centre
        // line, moved only as far as the bubble's own width needs to stay within the box.
        const centre = Math.min(Math.max(desk.anchor_x, desk.box.x + w / 2), desk.box.x + W - w / 2);

        wanted.push({
            install_id: desk.install_id,
            seat_id: desk.seat_id,
            text: line1.text,
            anchor: { x: centre, y: desk.box.y + BUBBLE_BAND },
            lines: [line1, line2],
            size: { w, h },
            source: b.source,
            degraded_note: b.degraded_note,
            truncated: b.truncated || line1.truncated,
        });
    }

    const placed = bubbleLayout(wanted, (text, bubble) => bubble.size);
    const bySeat = new Map(placed.map((rect) => [`${rect.install_id}/${rect.seat_id}`, rect]));

    for (const desk of desks) {
        const want = wanted.find((b) => b.install_id === desk.install_id && b.seat_id === desk.seat_id);

        if (want === undefined) {
            continue;
        }

        const rect = bySeat.get(`${desk.install_id}/${desk.seat_id}`);

        desk.bubble = Object.freeze({
            x: rect.x,
            y: rect.y,
            w: rect.w,
            h: rect.h,
            lifted: rect.lifted,
            text: want.lines[0].text,
            text_w: want.lines[0].w,
            truncated: want.truncated,
            second: want.lines[1]?.text ?? null,
            source: want.source,
            degraded_note: want.degraded_note,
            tail: Object.freeze({ x: desk.anchor_x, y: desk.box.y + BUBBLE_BAND }),
        });
    }

    for (const desk of desks) {
        Object.freeze(desk);
    }
}

/**
 * Every tile a map draws, in document order bottom first (§ 10.3's `layers[]` row), each cell
 * resolved to its tileset image and source rectangle — an image-collection tile is drawn at its own
 * size, bottom-aligned to its cell, as Tiled draws one.
 */
export function mapTiles(map, origin, tilesetFor, owner) {
    const grid = mapGrid(map);

    if (grid === null) {
        return [];
    }

    // Each `tilesets[]` entry by its `firstgid`, highest first, so a GID finds its own.
    const sets = (Array.isArray(map.tilesets) ? map.tilesets : [])
        .map((entry) => ({
            firstgid: entry?.firstgid ?? 1,
            tileset: typeof entry?.source === 'string' ? tilesetFor(tilesetUrl(entry.source)) : safeEmbedded(entry),
            url: typeof entry?.source === 'string' ? tilesetUrl(entry.source) : null,
        }))
        .sort((a, b) => b.firstgid - a.firstgid);

    const out = [];

    // § 10.3's layer tree, groups walked, through the one walk `mapDesks()` reads it by too.
    for (const { layer, offset: here, opacity: alpha, visible } of mapLayers(map)) {
        if (!visible || layer.type !== 'tilelayer' || !Array.isArray(layer.data)) {
            continue;
        }

        const columns = layer.width ?? grid.width;

        layer.data.forEach((cell, i) => {
            const { gid, flip_h, flip_v, flip_d } = splitGid(cell);

            if (gid === 0) {
                return;
            }

            const set = sets.find((s) => gid >= s.firstgid);

            if (set === undefined) {
                return;
            }

            const tile = set.tileset?.tile(gid - set.firstgid) ?? null;
            const col = i % columns;
            const row = Math.floor(i / columns);
            const offsetX = set.tileset?.offset.x ?? 0;
            const offsetY = set.tileset?.offset.y ?? 0;
            const w = tile?.sw ?? grid.tilewidth;
            const h = tile?.sh ?? grid.tileheight;

            out.push({
                room: owner,
                layer: layer.name ?? null,
                x: origin.x + here.x + col * grid.tilewidth + offsetX,
                y: origin.y + here.y + (row + 1) * grid.tileheight - h + offsetY,
                w,
                h,
                image: tile?.image ?? null,
                // The image's own size, so a sheet tile is drawn as a window onto its sheet.
                iw: tile?.iw ?? w,
                ih: tile?.ih ?? h,
                tileset: set.url,
                sx: tile?.sx ?? 0,
                sy: tile?.sy ?? 0,
                sw: w,
                sh: h,
                flip_h,
                flip_v,
                flip_d,
                opacity: alpha,
                properties: tile?.properties ?? null,
                // § 10.6 item 6: what the floor's theme draws the tile as — never its image.
                kind: typeof tile?.properties?.kind === 'string' ? tile.properties.kind : null,
                // The cell's place in its layer — a standing piece's seeded choices are the cell's.
                index: i,
            });
        });
    }

    return out.map((cell) => Object.freeze(cell));
}

/**
 * WHAT A TILE IS, TO THE FLOOR — its KIND and its cell's size, and nothing about its image (FLOOR.md § 10.6
 * item 6: the floor draws a placed tile by its kind in the floor's theme and never draws the tile's image).
 * Tiles of one look are one kind at one size, so `tileRegions()` joins them on it into the runs a plane
 * document draws.
 */
export function tileLook(t) {
    return Object.freeze({ kind: t.kind, w: t.w, h: t.h });
}

/**
 * The tiles of one grid as REGIONS — THE SCENE's ONE REGION PASS (§ 10.6 item 6): a region is one tile and
 * every tile of the same look (`tileLook()`) joined to it edge to edge on its lattice — its copies at
 * `x + c·w`, `y + r·h`. Every tile is in exactly one region; a tile with no neighbour of its look is a region
 * of one. `rects` is the region's area, row by row: each run of copies side by side in a row is one rect,
 * and the rects never overlap. A grid's `wall` and `accent` cells reach its plane document as these rects.
 *
 * ⛔ WHY ONE AREA AND NOT ITS CELLS: a run drawn cell by cell has an edge of its own at every cell, and where
 * that edge lands on a fractional device pixel the pair leaves a hairline of whatever is under them (a dotted
 * ladder down a wall). The plane document draws a run as one shape, so its cells meet at no edge of their own.
 *
 * ⛔ A NEIGHBOUR IS FOUND BY ITS PLACE TO 1/1024 px, NEVER BY ITS EXACT COORDINATES. `mapTiles()` places a
 * cell at `origin + offsets + col·w`, and a group or tileset offset such as 0.1 or 1/3 makes that differ in
 * its last bits from the `x + w` the lookup computes, so an exact match misses identical neighbours. A dyadic
 * coordinate of ten fractional bits or fewer is exact at that quantum, and one of up to six decimal places
 * (or a third) lies at least 10⁻⁶ of a quantum from a rounding edge, where a sum's drift on a floor-sized
 * coordinate is orders of magnitude smaller. Inside a region a copy's place is its whole-number column and
 * row on the region's lattice, so `rects` are on that lattice exactly.
 *
 * ⛔ THE DRAW ORDER IS KEPT WHERE IT IS VISIBLE. A region is drawn where its first tile was, which moves
 * its later copies earlier; that is only allowed past tiles they do not overlap. A copy that would move
 * past a tile it overlaps — so that tile would now be drawn over a copy it used to be under — is held out
 * of the region and starts one of its own at its own place, and the regions are found again until the
 * order holds for every copy.
 *
 * @param {list<object>} tiles one pass's tiles, in draw order (`mapTiles()`'s cells)
 * @returns {list<{look: object, x: number, y: number, rects: list<{x: number, y: number, w: number, h: number}>}>}
 *          in draw order; `look` is the region's (`tileLook()`), and `x`, `y` its first tile's place — the
 *          origin of its lattice
 */
export function tileRegions(tiles) {
    const spot = (x, y) => `${Math.round(x * 1024)},${Math.round(y * 1024)}`;

    // Each look by a small number, so a place is looked up by a short key.
    const kinds = new Map();
    const looks = [];
    const keys = tiles.map((t) => {
        const look = tileLook(t);
        const kind = JSON.stringify(look);

        if (!kinds.has(kind)) {
            kinds.set(kind, kinds.size);
            looks.push(look);
        }

        return kinds.get(kind);
    });
    const at = new Map();

    tiles.forEach((t, i) => {
        const k = `${keys[i]}@${spot(t.x, t.y)}`;

        // Two identical tiles at one place (two layers) stay apart: the later one is a region of its own.
        if (!at.has(k)) {
            at.set(k, i);
        }
    });

    const heldOut = new Set();
    const regionOf = new Array(tiles.length);
    let regions;

    for (;;) {
        regionOf.fill(-1);
        regions = [];

        tiles.forEach((t, first) => {
            if (regionOf[first] !== -1) {
                return;
            }

            const { w, h } = looks[keys[first]];
            const id = regions.length;
            const members = [first];
            const cells = [[0, 0]];

            regionOf[first] = id;

            // Every tile of its look reachable edge to edge — a held-out tile joins no region but its own.
            for (let m = 0; m < members.length && !heldOut.has(first); m++) {
                const here = tiles[members[m]];

                for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
                    const i = at.get(`${keys[first]}@${spot(here.x + dx * w, here.y + dy * h)}`);

                    // Later than `first` (an earlier tile is already in a region), and never `first` itself —
                    // which a tile of no width or height would otherwise find at every step.
                    if (i !== undefined && i > first && regionOf[i] === -1 && !heldOut.has(i)) {
                        regionOf[i] = id;
                        members.push(i);
                        cells.push([cells[m][0] + dx, cells[m][1] + dy]);
                    }
                }
            }

            regions.push({ first, members, cells });
        });

        const late = misordered(tiles, regions, regionOf);

        if (late.length === 0) {
            break;
        }

        for (const m of late) {
            heldOut.add(m);
        }
    }

    return regions.map(({ first, cells }) => {
        const look = looks[keys[first]];
        const { x, y } = tiles[first];
        const runs = [];

        // Row by row, each run of whole-number columns side by side one rect.
        for (const [c, r] of [...cells].sort((a, b) => a[1] - b[1] || a[0] - b[0])) {
            const last = runs[runs.length - 1];

            if (last !== undefined && last.r === r && last.c + last.n === c) {
                last.n += 1;
            } else {
                runs.push({ c, r, n: 1 });
            }
        }

        const rects = runs.map(({ c, r, n }) => Object.freeze({ x: x + c * look.w, y: y + r * look.h, w: n * look.w, h: look.h }));

        return Object.freeze({ look, x, y, rects: Object.freeze(rects) });
    });
}

/**
 * The copies a region draws too early: a member `m` of region `R` is now drawn at `R`'s first tile, so
 * every tile between the two in the old order that `m` overlaps must still be drawn before it — in a
 * region that starts before `R` does. Each such member is returned, to be held out of `R`.
 */
function misordered(tiles, regions, regionOf) {
    const CELL = 64;
    const span = (t) => [Math.floor(t.x / CELL), Math.floor((t.x + t.w) / CELL), Math.floor(t.y / CELL), Math.floor((t.y + t.h) / CELL)];
    const grid = new Map();

    tiles.forEach((t, i) => {
        const [x0, x1, y0, y1] = span(t);

        for (let gx = x0; gx <= x1; gx++) {
            for (let gy = y0; gy <= y1; gy++) {
                const k = `${gx},${gy}`;

                if (!grid.has(k)) {
                    grid.set(k, []);
                }

                grid.get(k).push(i);
            }
        }
    });

    const overlap = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
    const late = [];

    regions.forEach(({ first, members }, r) => {
        for (const m of members) {
            const [x0, x1, y0, y1] = span(tiles[m]);
            let crossed = false;

            for (let gx = x0; gx <= x1 && !crossed; gx++) {
                for (let gy = y0; gy <= y1 && !crossed; gy++) {
                    crossed = (grid.get(`${gx},${gy}`) ?? []).some((a) => a > first && a < m && regionOf[a] !== r
                        && regions[regionOf[a]].first > first && overlap(tiles[a], tiles[m]));
                }
            }

            if (crossed) {
                late.push(m);
            }
        }
    });

    return late;
}

function safeEmbedded(entry) {
    try {
        return readEmbedded(entry);
    } catch {
        return null;
    }
}

/**
 * § 9 F21 for one room, from the map alone: its `desks` objects pairwise on step 7's half-open
 * footprint test (`floor/floor-layout.js`'s own, F18's), and each against the furniture box.
 */
function f21(installId, objects, seatAt, box, models) {
    const seat = (index) => {
        const key = seatAt.get(index);

        return key === undefined ? null : (models[key]?.seat_id ?? null);
    };
    const rect = (o) => ({ x: o.x, y: o.y, width: o.width, height: o.height });
    const notices = [];

    for (let i = 0; i < objects.length; i++) {
        for (let j = i + 1; j < objects.length; j++) {
            if (footprintsIntersect(rect(objects[i]), rect(objects[j]))) {
                notices.push(intersectNotice(objects[i].id, objects[j].id, installId, [seat(i), seat(j)]));
            }
        }
    }

    objects.forEach((o, i) => {
        if (o.width < box.width || o.height < box.height) {
            notices.push(undersizedNotice(o.id, installId, [seat(i)]));
        }
    });

    return notices;
}

/**
 * § 4.2's back wall over one span (`floor-layout.js`'s `backWallBand()`): the wall, its skirting, the
 * two-door elevator and the wall clock in the reserved zone, and the windows past it. Its height is
 * this layer's; its span is the frame's, widened to the reserved zone where the floor is narrower.
 *
 * ⛔ AT REST NOTHING ON THE BAND MEETS THE CLOCK's FACE (AT-D3-20's clock clause): the elevator ends left
 * of it and every window, sill included, starts right of the zone. `Tests\Feature\Floor\TheFloorDrawsItsFrameTest`
 * sweeps every width from 1 px.
 *
 * @param {{x: number, y: number, width: number}} span where the band stands: its left, the slab's top, its width
 * @param {object|null} room `roomTick()`'s value — the clock's hands and the windows' sky — or `null`
 */
export function backWall(span, room) {
    const w = Math.max(span.width, ZONE_W);
    const y = span.y - BAND_H;
    const floor = span.y;
    const windows = [];
    const remaining = w - ZONE_W - WINDOW.margin;
    const n = remaining < WINDOW.min_w + WINDOW.gap ? 0 : Math.max(1, Math.floor(remaining / WINDOW.pitch));

    for (let i = 0; i < n; i++) {
        const cell = remaining / n;
        // At least `min_w`: the threshold above holds one minimum window and its gap in a cell.
        const ww = Math.min(WINDOW.w, cell - WINDOW.gap);
        const x = span.x + ZONE_W + cell * (i + 0.5) - ww / 2;
        const top = y + WINDOW.dy;

        windows.push(Object.freeze({
            x,
            y: top,
            w: ww,
            h: WINDOW.h,
            sky: room?.sky ?? null,
            // The rect the floor's theme draws the window's frame, mullions, curtains, rod and sill in (§ 10.6
            // item 2): the glazing grown by the surround margin. The glazing itself is the painter's (A17).
            surround: Object.freeze({
                x: x - SURROUND.side,
                y: top - SURROUND.above,
                w: ww + 2 * SURROUND.side,
                h: WINDOW.h + SURROUND.above + SURROUND.below,
            }),
        }));
    }

    const lift = { x: span.x + ELEVATOR.dx, y: floor - ELEVATOR.h, w: ELEVATOR.w, h: ELEVATOR.h };
    const frameRect = { x: lift.x - ELEVATOR.frame, y: lift.y - ELEVATOR.header, w: lift.w + 2 * ELEVATOR.frame, h: lift.h + ELEVATOR.header };

    return Object.freeze({
        x: span.x,
        y,
        w,
        h: BAND_H,
        // The reserved zone the elevator and the clock hang in — no window enters it.
        zone: Object.freeze({ x: span.x, y, w: ZONE_W, h: BAND_H }),
        // The two-door elevator: its two leaves (the painter's, in the building's door colours), its frame,
        // and the surround the floor's theme draws the frame, the header plate, the lamp, the recess and the
        // call buttons in — the frame grown 18 px to the right (§ 10.6 item 2).
        elevator: Object.freeze({
            ...lift,
            frame: Object.freeze(frameRect),
            surround: Object.freeze({ ...frameRect, w: frameRect.w + SURROUND.elevator_reach }),
            seam: lift.x + lift.w / 2,
        }),
        // § 6.5: a room with no value is drawn as having none — `set: false`, never a plausible time.
        // Hung at mid-wall, in the zone, right of the elevator.
        clock: Object.freeze({
            x: span.x + CLOCK.dx,
            y: y + (BAND_H - CLOCK.size) / 2,
            w: CLOCK.size,
            h: CLOCK.size,
            set: room !== null,
            hour_angle_deg: room?.hour_angle_deg ?? null,
            minute_angle_deg: room?.minute_angle_deg ?? null,
            text: room?.text ?? null,
            label: room?.label ?? null,
        }),
        windows: Object.freeze(windows),
    });
}

/** Where a line or an envelope meets a desk: the character's column, at the desk's mid-height. */
function anchorOf(desk) {
    return Object.freeze({ x: desk.anchor_x, y: desk.box.y + desk.box.h / 2 });
}

/**
 * § 5.7's thread line, drawn between the desks its resolved participants stand at — A18's held
 * render, in the form the set decided.
 */
function buildLines(frame, anchors, input) {
    const out = [];

    for (const [installId, model] of Object.entries(frame.coord ?? {})) {
        for (const thread of model.threads) {
            if (!thread.animations.includes('A18')) {
                continue;
            }

            const ends = thread.endpoints
                .map((seatId) => anchors.get(`${installId}/${seatId}`) ?? null)
                .filter((a) => a !== null);

            if (ends.length < 2) {
                continue;
            }

            const mid = { x: (ends[0].x + ends[1].x) / 2, y: (ends[0].y + ends[1].y) / 2 };
            const label = thread.label === null ? null : fit(thread.label, 240, input.measure);

            out.push(Object.freeze({
                animation_id: 'A18',
                install_id: installId,
                thread_ref: thread.thread_ref,
                ends: Object.freeze(ends),
                ended: thread.ended,
                // The label is the wire's, with its mark where `subject_truncated` said so (§ 5.7);
                // cut to fit through the one primitive like every other string on the floor.
                label: label === null ? null : Object.freeze({ x: mid.x, y: mid.y - LINE, text: label.text, truncated: label.truncated || thread.truncated }),
                carrier: thread.carrier,
                lifecycle: thread.lifecycle,
                beads: thread.beads_label,
                // ⛔ THE LINE's `held` IS THE RENDERING THE SET LOGGED (`floor/floor-screen.js`), so a
                // line § 9 F6 stilled is drawn static here as it is logged static there; reduced motion
                // is already folded into it. A moving line flows at § 12's loop rate, one dash period
                // per THREAD_FLOW_FRAMES frames — a fixed rate carrying no fact (§ 6.1 rule 2).
                ...form('A18', !thread.held.motion),
                frame_interval_ms: 1000 / LOOP_FPS,
                frames: thread.held.motion ? THREAD_FLOW_FRAMES : 0,
            }));
        }
    }

    return Object.freeze(out);
}

/** § 6.2's form for one row — its class, whether it loops, and § 6.4's rendering. */
function form(animationId, reduce) {
    const row = ANIMATION_SET[animationId];

    return {
        class: row.class,
        loops: loops(animationId),
        form: reduce ? row.reduced : row.animation,
        motion: !reduce,
    };
}

/**
 * Every § 6.2 edge row this render WROTE, as the frames to draw — at § 12's loop rate, from where
 * the fact happened to where it ends. Rows are the animation set's; nothing here fires one.
 */
function buildEffects(rows, frame, anchors, previous, extent, band, reduce, character) {
    const interval = 1000 / LOOP_FPS;
    const effects = [];
    const seen = new Set();

    for (const row of rows) {
        if (row.class !== 'edge') {
            continue;
        }

        const id = row.animation_id;
        const motion = row.motion === true;
        const key = row.seat_id === null ? null : `${row.install_id}/${row.seat_id}`;
        const base = {
            animation_id: id,
            cause: row.cause,
            install_id: row.install_id,
            seat_id: row.seat_id,
            ...form(id, !motion || reduce),
            frame_interval_ms: interval,
        };

        if (id === 'A19' || id === 'A20') {
            // One drawing per post: A19 is logged once per destination and drawn as that many
            // envelopes; A20 is one ring.
            const tag = `${id} ${row.install_id} ${row.cause}`;

            if (seen.has(tag)) {
                continue;
            }

            seen.add(tag);

            const round = frame.coord?.[row.install_id]?.rounds.find((r) => r.post_ref === row.cause) ?? null;
            const origin = round?.origin.agent?.resolved ? anchors.get(`${row.install_id}/${round.origin.agent.seat_id}`) ?? null : null;

            if (id === 'A19' && round !== null && origin !== null) {
                for (const target of round.targets.desks) {
                    const to = anchors.get(`${row.install_id}/${target.seat_id}`) ?? null;

                    if (to === null) {
                        continue;
                    }

                    const length = Math.hypot(to.x - origin.x, to.y - origin.y);

                    effects.push(Object.freeze({
                        ...base,
                        from: origin,
                        to,
                        // The operator's QA ruling (card#7341 scope addition 3): the envelope's nose
                        // points along its direction of travel.
                        heading_deg: Math.atan2(to.y - origin.y, to.x - origin.x) * 180 / Math.PI,
                        frames: motion && !reduce ? Math.max(1, Math.ceil(length / ENVELOPE_PX_PER_FRAME)) : 0,
                    }));
                }
            }

            if (id === 'A20' && origin !== null && extent !== null) {
                // The operator's QA ruling: the ring spans the whole floor BEFORE it fades — reach
                // first, fade after, never dissipating mid-office. Its reach is the floor's farthest
                // corner from the origin desk.
                const reach = Math.max(...[
                    [extent.x, extent.y],
                    [extent.x + extent.width, extent.y],
                    [extent.x, extent.y + extent.height],
                    [extent.x + extent.width, extent.y + extent.height],
                ].map(([x, y]) => Math.hypot(x - origin.x, y - origin.y)));
                const reachFrames = Math.max(1, Math.ceil(reach / RING_PX_PER_FRAME));

                effects.push(Object.freeze({
                    ...base,
                    at: origin,
                    radius: reach,
                    reach_frames: motion && !reduce ? reachFrames : 0,
                    fade_frames: motion && !reduce ? RING_FADE_FRAMES : 0,
                    frames: motion && !reduce ? reachFrames + RING_FADE_FRAMES : 0,
                }));
            }

            continue;
        }

        if (id === 'A14') {
            effects.push(Object.freeze({ ...base, target: 'strip', frames: motion ? 1 : 0 }));

            continue;
        }

        if (id === 'A17') {
            effects.push(Object.freeze({ ...base, target: 'band', at: band?.clock ?? null, frames: motion ? 1 : 0 }));

            continue;
        }

        // A desk row: at the desk the row names — where it is now, and for a walk where it was.
        const now = key === null ? null : anchors.get(key) ?? null;
        const before = key === null ? null : previous.get(key) ?? null;

        if (id === 'A1' || id === 'A2') {
            effects.push(walkEffect(base, id, now, threshold(band), motion && !reduce, character));

            continue;
        }

        if (id === 'A13' || id === 'A16') {
            const from = before ?? now;
            const to = id === 'A16' ? now : (from === null ? null : { x: from.x, y: (extent?.y ?? from.y) + (extent?.height ?? 0) });
            const length = from === null || to === null ? 0 : Math.hypot(to.x - from.x, to.y - from.y);

            effects.push(Object.freeze({
                ...base,
                from,
                to,
                frames: motion && !reduce ? Math.max(1, Math.ceil(length / WALK_PX_PER_FRAME)) : 0,
            }));

            continue;
        }

        effects.push(Object.freeze({
            ...base,
            at: now,
            frames: motion && !reduce && (ONE_FRAME.has(id) || id === 'A10') ? 1 : 0,
        }));
    }

    return Object.freeze(effects);
}

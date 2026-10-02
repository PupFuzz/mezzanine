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
import { fnv1a32, footprintsIntersect, mapDesks, mapGrid } from './floor-layout.js';
import { FLOOR_ART, readEmbedded, resolvePath, splitGid, tilesetUrl } from './tileset.js';
import { BUBBLE_BAND, BUBBLE_PAD, ART_W, LINE, deskLayout, fit, union } from './desk-layout.js';
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
 * ⛔ THE ELEVATOR IS SCENERY. It is the lobby cab's form — two leaves meeting at a seam, a lamp over
 * them — in the lobby's own door colours (`--door` / `--door-edge`), so the floor and the lobby read as
 * one building; it never opens and takes no § 6.2 row. The elevator that navigates is the lobby's
 * (§ 4.1), reached by the floor's *whole building* control.
 *
 * ⛔ THE WINDOWS FILL WHAT IS LEFT, PAST THE ZONE: `n = ⌊(w − ZONE_W − margin) ÷ pitch⌋`, at least
 * one, spread evenly, each centred in its cell and `WINDOW.w` wide — narrowed to its cell less the gap
 * on a band too short for a full one, never under `WINDOW.min_w`. BELOW THE WIDTH THAT HOLDS THE ZONE,
 * THE MARGIN AND ONE MINIMUM WINDOW WITH ITS GAP (`ZONE_W + margin + min_w + gap`) THERE ARE NONE
 * (design review r3 MINOR-1): the formula's *at least one* holds from that width up, and under it a
 * window — or its sill — could only be drawn inside the zone. A stored map can be that narrow (F21).
 */
const ZONE_W = 272;
const ELEVATOR = { dx: 32, w: 84, h: 144, frame: 6, header: 14, lamp_r: 3 };
const CLOCK = { dx: 164, size: 64 };
const WINDOW = { margin: 24, pitch: 360, w: 200, min_w: 96, gap: 24, h: 124, dy: 16, sill_h: 6, sill_out: 6, transom: 0.62 };
const SKIRTING_H = 8;

/** The slab under the floor's rooms — a baseline strip, scenery, outside every grid (§ 4.2). */
export const SLAB_H = 8;

/**
 * § 10.4's seeded room theme (Q2, the operator's 2026-10-01 ruling on card#11045): each room's plane
 * takes one of these by `fnv1a32(install_id) mod 4` — keyed on the ROOM, whose key never moves, never on
 * the floor's (§ 4.6: a floor's key moves when a lower-sorting room joins it). Appearance, no fact; the
 * band stays in the house palette. The paint is `public/css/mezzanine.css`'s `--room-<theme>` pair.
 */
export const ROOM_THEMES = Object.freeze(['oak', 'walnut', 'sage', 'slate']);

export function roomTheme(installId) {
    return ROOM_THEMES[fnv1a32(installId) % ROOM_THEMES.length];
}

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
const WALK_PX_PER_FRAME = 48;
const ENVELOPE_PX_PER_FRAME = 96;
const RING_PX_PER_FRAME = 240;
const RING_FADE_FRAMES = 2;

/**
 * A moving A18 line's flow: the frames one dash period takes to travel along it, at § 12's loop rate.
 * It decides how fast the dashes flow and carries no fact — every moving line flows alike.
 */
const THREAD_FLOW_FRAMES = 4;

/** A § 6.2 edge row's single-frame forms — the 250 ms fades and eases, one loop frame each. */
const ONE_FRAME = new Set(['A5', 'A11', 'A12', 'A14', 'A17']);

/**
 * The scene for one floor frame, or `null` when the frame composed no floor.
 *
 * @param {object} frame `FloorScreen#draw()`'s frame
 * @param {object} input `{ box, desk_sprite, measure, character, maps, hallway, tilesets, failed,
 *        reduce, effects, previous }` — `maps` is install_id → the held map document or `null`;
 *        `tilesets` is `TilesetLoader#held`; `failed` the asset ids the painter reported; `effects`
 *        the animation-log rows this render wrote; `previous` key → the box the last scene drew
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

    // ── The desk sprite (resources/floor/furniture-box.js), through the tileset reader ─────────
    const spriteUrl = resolvePath(FLOOR_ART, input.desk_sprite.image);
    const spriteTileset = tilesetFor(tilesetUrl(input.desk_sprite.tileset));
    const spriteTile = spriteTileset === null ? null : spriteTileset.imageTile(spriteUrl);
    const sprite = spriteTile === null || failed.has(spriteUrl)
        ? null
        : { url: spriteUrl, w: spriteTile.sw, h: spriteTile.sh };

    if (spriteTile !== null) {
        used.add(spriteUrl);
    }

    // ── Tiles: the hallway, then each room in draw order, each map's layers bottom first ──────
    const tiles = [];
    const drawMap = (map, origin, owner) => {
        for (const cell of mapTiles(map, origin, tilesetFor, owner)) {
            if (cell.image !== null) {
                used.add(cell.image);
            }

            if (cell.image === null || failed.has(cell.image)) {
                continue;
            }

            tiles.push(cell);

            if (typeof cell.properties?.decoration === 'string') {
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
    };

    if (frame.planned && input.hallway !== null && input.hallway !== undefined) {
        drawMap(input.hallway, { x: 0, y: 0 }, null);
    }

    const byRoom = new Map(frame.rooms.map((room) => [room.install_id, room]));
    // ⛔ THE FRAME's ONE PER-ROOM PRIMITIVE: a plane under the room's whole grid, opaque over the hallway as
    // a room is (§ 4.2), drawn before that room's tiles — so it is UNDER the author's content and over no
    // author's grid but its own. A room whose map is not drawn (F16) has no plane: F16 is "every fact, no
    // room". The painter draws each plane and then the tiles whose `room` is its `install_id`.
    const planes = [];

    for (const installId of frame.draw_order) {
        const room = byRoom.get(installId);
        const map = input.maps.get(installId) ?? null;

        if (!room.mapless && map !== null) {
            if (room.footprint !== null) {
                const f = room.footprint;

                planes.push(Object.freeze({ install_id: installId, x: f.x, y: f.y, w: f.width, h: f.height, theme: roomTheme(installId) }));
            }

            drawMap(map, room.origin, installId);
        }
    }

    // ── Desks: inside their slots, the later `id` on top; F21's notices per room ──────────────
    const desks = [];
    const notices = [];
    const models = frame.desks.desks;

    const artFor = (desk) => {
        const character = `character:${desk.install_id}/${desk.seat_id}`;

        if (desk.character) {
            used.add(character);
        }

        return sprite === null || (desk.character && failed.has(character));
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
                sprite,
                // F16: a mapless room's every desk is the placeholder, in a plain grid.
                placeholder: room.mapless || artFor(model),
            }, { overflow: false, slot: d.slot, object_id: object?.id ?? null }));
        }
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
                sprite,
                placeholder: artFor(model),
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

    // ── The bubbles: § 5.1 rule 5's pass over the base rects, in the box's top band ───────────
    placeBubbles(desks, input.measure, W, Object.keys(models));

    // ── The frame: the band over the floor's whole extent (§ 4.2) and the slab under it ────────
    const band = frame.band === null ? null : backWall(frame.band, frame.room);
    const slab = band === null || extent === null ? null : Object.freeze({
        x: band.x,
        y: extent.y + extent.height,
        w: band.w,
        h: SLAB_H,
    });

    // ── The coordination line and the § 6.2 forms this render draws ───────────────────────────
    const anchors = new Map(desks.map((desk) => [desk.key, anchorOf(desk)]));
    const lines = buildLines(frame, anchors, input);
    const effects = buildEffects(input.effects ?? [], frame, anchors, input.previous ?? new Map(), extent, band, input.reduce === true);

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
        planes: Object.freeze(planes),
        tiles: Object.freeze(tiles),
        desks: Object.freeze(desks),
        strip,
        lines,
        effects,
        decorative: Object.freeze(decorative),
        notices: Object.freeze(notices),
        failed: Object.freeze(failedAssets),
        art_failed: failedAssets.length > 0,
        // Where each desk's lines and walks meet it — what the NEXT render's walks start from.
        anchors: Object.freeze(Object.fromEntries(anchors)),
        loop_fps: LOOP_FPS,
    });
}

/** One desk placed at `at`: its box, its elements in scene coordinates, and their union. */
function placeDesk(model, key, installId, slot, at, ctx, extra) {
    const { elements, bubble } = deskLayout(model, ctx);
    const moved = elements.map((e) => Object.freeze({ ...e, x: at.x + e.x, y: at.y + e.y }));

    return {
        key,
        install_id: installId,
        seat_id: model.seat_id,
        ...extra,
        slot_rect: slot === null ? null : Object.freeze(slot),
        box: Object.freeze({ x: at.x, y: at.y, w: ctx.box.width, h: ctx.box.height }),
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
 * the base rects — here, the box's top band, above the character's column.
 */
function placeBubbles(desks, measure, W, delivered) {
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
        const centre = Math.min(Math.max(desk.box.x + ART_W / 2, desk.box.x + w / 2), desk.box.x + W - w / 2);

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
            tail: Object.freeze({ x: desk.box.x + ART_W / 2, y: desk.box.y + BUBBLE_BAND }),
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
    const walk = (layers, offset, opacity) => {
        for (const layer of Array.isArray(layers) ? layers : []) {
            if (layer === null || typeof layer !== 'object' || layer.visible === false) {
                continue;
            }

            const here = { x: offset.x + (layer.offsetx ?? 0), y: offset.y + (layer.offsety ?? 0) };
            const alpha = opacity * (layer.opacity ?? 1);

            if (layer.type === 'group') {
                walk(layer.layers, here, alpha);

                continue;
            }

            if (layer.type !== 'tilelayer' || !Array.isArray(layer.data)) {
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
                });
            });
        }
    };

    walk(map.layers, { x: 0, y: 0 }, 1);

    return out.map((cell) => Object.freeze(cell));
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
            // Two mullions and a transom, drawn inside the glazing; the sill under it.
            mullions: Object.freeze([x + ww / 3, x + (2 * ww) / 3]),
            transom: top + WINDOW.h * WINDOW.transom,
            sill: Object.freeze({ x: x - WINDOW.sill_out, y: top + WINDOW.h, w: ww + 2 * WINDOW.sill_out, h: WINDOW.sill_h }),
        }));
    }

    const lift = { x: span.x + ELEVATOR.dx, y: floor - ELEVATOR.h, w: ELEVATOR.w, h: ELEVATOR.h };

    return Object.freeze({
        x: span.x,
        y,
        w,
        h: BAND_H,
        // The reserved zone the elevator and the clock hang in — no window enters it.
        zone: Object.freeze({ x: span.x, y, w: ZONE_W, h: BAND_H }),
        skirting: Object.freeze({ x: span.x, y: floor - SKIRTING_H, w, h: SKIRTING_H }),
        // The two-door elevator: its frame and header plate, the lamp over it, and the two leaves.
        elevator: Object.freeze({
            ...lift,
            frame: Object.freeze({
                x: lift.x - ELEVATOR.frame,
                y: lift.y - ELEVATOR.header,
                w: lift.w + 2 * ELEVATOR.frame,
                h: lift.h + ELEVATOR.header,
            }),
            header: Object.freeze({ x: lift.x - ELEVATOR.frame, y: lift.y - ELEVATOR.header, w: lift.w + 2 * ELEVATOR.frame, h: ELEVATOR.header - 2 }),
            lamp: Object.freeze({ cx: lift.x + lift.w / 2, cy: lift.y - ELEVATOR.header / 2, r: ELEVATOR.lamp_r }),
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
    return Object.freeze({ x: desk.box.x + ART_W / 2, y: desk.box.y + desk.box.h / 2 });
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
function buildEffects(rows, frame, anchors, previous, extent, band, reduce) {
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

        if (id === 'A1' || id === 'A2' || id === 'A13' || id === 'A16') {
            const from = id === 'A1' ? (now === null ? null : { x: now.x, y: (extent?.y ?? now.y) + (extent?.height ?? 0) }) : (before ?? now);
            const to = id === 'A16' || id === 'A1' ? now : (from === null ? null : { x: from.x, y: (extent?.y ?? from.y) + (extent?.height ?? 0) });
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

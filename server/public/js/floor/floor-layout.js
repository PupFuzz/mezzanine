/**
 * The FLOOR LAYOUT — `docs/design/FLOOR.md` Appendix B row 7, card#7341 step 7: where every desk
 * stands, which seats have no slot, and how N rooms compose onto one floor. Pure geometry over
 * documents the client already holds; it reads no store, opens no request and touches no DOM.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SLOT FUNCTION RUNS PER ROOM AND IS A PURE FUNCTION OF THE RENDERED SEAT SET (§ 3.2). Two
 * browsers, two reloads and two server restarts agree without a stored position and without a
 * server field. A seat's slot is a function of `(install_id, seat_id)` and that ROOM's own `S`, so
 * rearranging the building moves no desk inside any room — this module reads the layout's `origin`
 * to place a room's GRID and never to place a seat.
 *
 * ⛔ NEITHER ARRIVAL ORDER NOR SORTED `seat_id` ORDER MAY REACH THIS ASSIGNMENT, and both are
 * named because both are what an implementer reaches for. Arrival order is not a function of the
 * rendered set at all, so two browsers disagree and a reload moves the furniture; sorted order
 * shifts EVERY later desk when one seat is provisioned. § 3.2 rejects each by name and
 * AT-D3-3 plants both.
 *
 * ⛔ A SEAT IS NEVER DROPPED (§ 3.2's overflow rule). Seats past `S` are the floor's overflow row,
 * with the same desk and the same drill-down, under § 5.5's *floor map is short N desks* — suffixed
 * with the room's `install_id` on a floor of several rooms, because *which map is short* is the
 * question the notice exists to answer (card#9292). A silent drop is a seat that exists and is
 * invisible.
 *
 * ⛔ EXTENT LIVES IN THE ROOM AND POSITION ON THE FLOOR (§ 4.6 rule 1). A room's footprint is its
 * map's grid in pixels and nothing here invents one: a room whose map the client does not hold has
 * NO footprint (§ 9 F16), is left out of F18's determination, and its desks are drawn as F14
 * placeholders under F16's notice.
 *
 * ⛔ AND IT IS STILL ON THE FLOOR, SO IT IS STILL IN THE FLOOR'S EXTENT (§ 4.6 rule 5). A room with
 * no footprint enters the extent union as the POINT its origin is — the corner its placeholder grid
 * is drawn at — because the band above the slab is the BUILDING's backdrop and a room the union
 * leaves out draws its desks outside it, which makes a room-map outage look like a rendering defect
 * instead. The point carries no size: nothing the client holds gives that grid one, and a size
 * invented here is the number with no derivation § 4.6 refuses.
 *
 * ⛔ F18 IS A READ-TIME RENDER AND NOT A REFUSAL, and that is why the arithmetic is here as well
 * as in `App\Building\FloorPlan`. The two answer different questions over the same predicate: the
 * server REFUSES a write that would make two footprints share a pixel; this client DRAWS a floor
 * that meets an overlap no write produced — a deploy that changed the shipped default's grid under
 * a planned floor — with both rooms, the later `install_id` on top, under § 5.5's notice
 * (§ 4.6, § 9 F18). The one thing that must not diverge is the half-open rectangle, so both
 * runtimes are held to one checked-in case file
 * (`server/tests/fixtures/building/footprint-cases.json`).
 *
 * ⛔ THE WALL CLOCK AND THE WINDOWS ARE THE FLOOR'S, DRAWN ONCE (§ 4.2, card#9267), on a back-wall
 * band spanning the floor's whole extent above the composed slab — inside no room's map and inside
 * no hallway's. A floor of five offices has one clock over all five. What they READ is the
 * viewer's own clock at minute resolution (§ 5.5's wall-clock row); WHEN they are re-evaluated is
 * § 6.2 A17's `feed.heartbeat`, which is `floor/floor-screen.js`'s to drive — this module states
 * the value and the band, never the schedule.
 */

/** § 12's *Gap between rooms on a floor with no plan*, in pixels. A planned floor has no gap rule. */
export const ROOM_GAP_PX = 64;

/** § 3.2's FNV-1a-32 constants, as that section publishes them. */
const FNV_OFFSET_BASIS = 2166136261;

const FNV_PRIME = 16777619;

/**
 * § 3.2's `h(seat)` — FNV-1a-32 over the UTF-8 bytes of `install_id + "/" + seat_id`. Both ids are
 * ASCII by D1 § 3.1's slug patterns, and the encoder is the platform's so a non-ASCII id hashes
 * its real bytes rather than its code units.
 */
export function hashSeat(installId, seatId) {
    const bytes = new TextEncoder().encode(`${installId}/${seatId}`);

    let h = FNV_OFFSET_BASIS;

    for (const b of bytes) {
        h ^= b;
        // `Math.imul` is 32-bit multiplication; `*` would lose the low bits past 2^53.
        h = Math.imul(h, FNV_PRIME) >>> 0;
    }

    return h >>> 0;
}

/**
 * § 3.2's assignment for ONE room: the room's seats in ascending `(h, seat_id)`, each taking the
 * first free slot from `(h + i) mod S`, and the ones with no slot in that same order.
 *
 * `S` of `0` — a map that declares no `desks` object at all — puts every seat in the overflow row
 * rather than probing an empty ring, which is the same answer as a map one desk short taken to its
 * limit.
 *
 * @param {Iterable<{install_id: string, seat_id: string}>} seats this room's rendered seat set
 * @param {number} slotCount the room's `S` — the count of its map's `desks` objects (§ 10.3)
 * @returns {{slots: Map<string, number>, probes: Map<string, number>, order: list<string>, overflow: list<string>}}
 */
export function assignSlots(seats, slotCount) {
    // `S` is taken as given, because its one producer is `mapDesks()`'s own length and § 3.2 states
    // the answer for a room that declares none: the probe loop runs zero times and every seat is the
    // overflow row. A guard here would be a check on a state the caller cannot express.
    const S = slotCount;

    const order = [...seats]
        .map((seat) => ({
            key: `${seat.install_id}/${seat.seat_id}`,
            seat_id: seat.seat_id,
            h: hashSeat(seat.install_id, seat.seat_id),
        }))
        // § 3.2: ascending by `(h, seat_id)` — total, because `seat_id` is unique within an install.
        .sort((a, b) => a.h - b.h || (a.seat_id < b.seat_id ? -1 : a.seat_id > b.seat_id ? 1 : 0));

    const taken = new Map();
    const slots = new Map();
    const probes = new Map();
    const overflow = [];

    for (const seat of order) {
        let placed = false;

        for (let i = 0; i < S; i++) {
            const slot = (seat.h + i) % S;

            if (taken.has(slot)) {
                continue;
            }

            taken.set(slot, seat.key);
            slots.set(seat.key, slot);
            probes.set(seat.key, i);
            placed = true;
            break;
        }

        if (!placed) {
            overflow.push(seat.key);
        }
    }

    return {
        slots,
        probes,
        order: order.map((seat) => seat.key),
        overflow,
    };
}

/**
 * A Tiled document's grid, in tiles and in pixels — § 10.3's `width × tilewidth` by
 * `height × tileheight`, which § 4.6 rule 1 makes a room's whole footprint. `null` for a document
 * that does not declare all four as positive integers, because a footprint computed from a
 * half-read document is a number with nothing behind it.
 */
export function mapGrid(map) {
    const cells = ['width', 'height', 'tilewidth', 'tileheight'].map((k) => map?.[k]);

    if (!cells.every((v) => Number.isInteger(v) && v > 0)) {
        return null;
    }

    const [width, height, tilewidth, tileheight] = cells;

    return Object.freeze({
        width,
        height,
        tilewidth,
        tileheight,
        pixel_width: width * tilewidth,
        pixel_height: height * tileheight,
    });
}

/**
 * § 10.3: the map's object layer named `desks` carries the slots, and `S` is their count in `id`
 * order. The objects' own `x`/`y` are where a desk is drawn inside the room, in the map's pixel
 * space, so slot *i*'s position on the FLOOR is this plus the room's `origin`.
 *
 * ⛔ THE LAYER NAME IS § 10.3's AND THE ORDER IS ITS `id` ORDER, not the document's array order:
 * Tiled writes objects in insertion order and an author who deletes and re-adds one can hand this
 * an array whose order is not the ids'. Sorting is what makes `S` and slot *i* properties of the
 * document rather than of the editing session that produced it.
 */
export function mapDesks(map) {
    const layers = Array.isArray(map?.layers) ? map.layers : [];
    const desks = layers.find((layer) => layer?.type === 'objectgroup' && layer?.name === 'desks');

    if (desks === undefined || !Array.isArray(desks.objects)) {
        return [];
    }

    return [...desks.objects]
        .sort((a, b) => (a?.id ?? 0) - (b?.id ?? 0))
        .map((object) => Object.freeze({
            id: object?.id ?? null,
            x: object?.x ?? 0,
            y: object?.y ?? 0,
            width: object?.width ?? 0,
            height: object?.height ?? 0,
        }));
}

/** § 4.6's half-open footprints: two rooms may share an EDGE and may never share a pixel. */
export function footprintsIntersect(a, b) {
    return a.x < b.x + b.width
        && b.x < a.x + a.width
        && a.y < b.y + b.height
        && b.y < a.y + a.height;
}

/**
 * The floor's rooms PLACED — § 4.2's *how N rooms sit on the one screen* and § 4.6's plan.
 *
 * A floor is planned when every room carries an `origin`; the reader refuses a half-placed floor
 * at the write (§ 4.6), so a floor delivered with some rooms placed is treated as UNPLANNED rather
 * than half-drawn — the default arrangement is the rule that needs no author, and laying it beside
 * an authored one is the two-rules-on-one-screen this section refuses.
 *
 * On an unplanned floor: side by side, left to right in `install_id` ascending (§ 2.1 row 6), top
 * edges aligned, each at its own map's size, `ROOM_GAP_PX` apart, and no hallway.
 *
 * A room with NO FOOTPRINT (F16 — its map request failed and the client holds none) is placed in its
 * turn and contributes no width: it has no footprint, so F18's determination leaves it out, and its
 * desks are drawn as placeholders at its origin. It is in the floor's EXTENT all the same, as that
 * origin (§ 4.6 rule 5).
 *
 * @param {list<{install_id: string, form?: string, origin?: {x: number, y: number}}>} rooms one floor's rooms
 * @param {Map<string, {pixel_width: number, pixel_height: number}|null>} extents install_id → its footprint size, or `null`
 * @param {object|null} [hallway] the floor's `hallway` document (§ 4.6), on a planned floor only
 */
export function placeRooms(rooms, extents, hallway = null) {
    const ordered = [...rooms].sort((a, b) => (a.install_id < b.install_id ? -1 : a.install_id > b.install_id ? 1 : 0));
    const planned = ordered.length > 0 && ordered.every((room) => Number.isInteger(room.origin?.x) && Number.isInteger(room.origin?.y));

    let x = 0;
    const placed = ordered.map((room) => {
        const extent = extents.get(room.install_id) ?? null;
        const origin = planned ? { x: room.origin.x, y: room.origin.y } : { x, y: 0 };

        if (!planned) {
            x += (extent?.pixel_width ?? 0) + ROOM_GAP_PX;
        }

        return Object.freeze({
            install_id: room.install_id,
            form: room.form ?? null,
            origin: Object.freeze(origin),
            footprint: extent === null ? null : Object.freeze({
                x: origin.x,
                y: origin.y,
                width: extent.pixel_width,
                height: extent.pixel_height,
            }),
        });
    });

    // ⛔ The hallway is drawn at the FLOOR's origin, under the rooms, and only on a planned floor
    // (§ 4.6 rule 2 and the `hallway` row: a corridor with no rooms placed along it is refused at
    // the write, so an unplanned floor delivered with one is drawn without it rather than half).
    const hall = planned ? mapGrid(hallway) : null;

    // Everything on the floor with a SIZE: each room's footprint, and the hallway's own grid.
    const sized = placed.map((room) => room.footprint).filter((box) => box !== null);

    if (hall !== null) {
        sized.push({ x: 0, y: 0, width: hall.pixel_width, height: hall.pixel_height });
    }

    // ⛔ EVERY ROOM PLACED ON THE FLOOR IS IN THE FLOOR'S EXTENT, F16's MAPLESS ROOM INCLUDED
    // (§ 4.6 rule 5) — as the POINT its origin is, which is the corner its placeholder grid is
    // drawn at and the whole of what the client holds about where that room is.
    const points = placed
        .filter((room) => room.footprint === null)
        .map((room) => ({ x: room.origin.x, y: room.origin.y, width: 0, height: 0 }));

    // § 4.6: the floor's extent is the union of all of it — computed from documents the client
    // holds, stored nowhere. A floor with nothing measurable on it has no extent at all rather
    // than a zero-sized one at the origin, which would be a box nothing is inside — so a room's
    // origin WIDENS an extent and never mints one. And where a room has a footprint, that
    // footprint's corner IS its origin, so this arithmetic is unchanged on every floor whose every
    // map arrived.
    const boxes = sized.length === 0 ? [] : [...sized, ...points];
    const extent = boxes.length === 0 ? null : Object.freeze({
        x: Math.min(...boxes.map((b) => b.x)),
        y: Math.min(...boxes.map((b) => b.y)),
        width: Math.max(...boxes.map((b) => b.x + b.width)) - Math.min(...boxes.map((b) => b.x)),
        height: Math.max(...boxes.map((b) => b.y + b.height)) - Math.min(...boxes.map((b) => b.y)),
    });

    // F18: the pairs that share a pixel, each named in key order, which is the order § 5.5's
    // notice renders them in. Only a planned floor can reach it — the default arrangement lays
    // rooms `ROOM_GAP_PX` apart by construction — and a room with no footprint is left out.
    const overlaps = [];

    for (let i = 0; i < placed.length; i++) {
        for (let j = i + 1; j < placed.length; j++) {
            if (placed[i].footprint !== null
                && placed[j].footprint !== null
                && footprintsIntersect(placed[i].footprint, placed[j].footprint)) {
                overlaps.push(Object.freeze([placed[i].install_id, placed[j].install_id]));
            }
        }
    }

    return Object.freeze({
        planned,
        rooms: Object.freeze(placed),
        hallway: hall,
        extent,
        overlaps: Object.freeze(overlaps),
        // § 4.6 F18: both rooms are drawn, the LATER `install_id` on top. The draw order is the
        // key order the rooms are already in, so *later on top* is what a painter's-order consumer
        // gets for free — it is stated as a returned order rather than left to a drawing layer.
        draw_order: Object.freeze(placed.map((room) => room.install_id)),
    });
}

/**
 * § 4.2's back-wall band: the wall clock and the windows, spanning the floor's whole extent ABOVE
 * the composed slab. `null` where the floor has no extent, because a band over nothing is a band
 * nothing is behind.
 *
 * ⛔ IT IS THE FLOOR SCREEN'S OWN BAND, inside no room's map and inside no hallway's (§ 4.6
 * rule 4). Its height is a drawing layer's — this states WHERE it spans and that there is exactly
 * one of it.
 */
export function backWallBand(extent) {
    return extent === null ? null : Object.freeze({
        x: extent.x,
        y: extent.y,
        width: extent.width,
    });
}

/**
 * § 4.2 and § 5.5: what the wall clock's hands and the windows' sky READ — the **viewer's own**
 * clock, never the server's and never § 2.4's corrected one.
 *
 * ⛔ MINUTE RESOLUTION, AND NO SECOND HAND (card#7341's option-C ruling, § 12's heartbeat row).
 * A17 steps this on each `feed.heartbeat`, 15 s apart, so a second hand would advance in 15 s
 * jumps — which *looks broken*, and *looks broken* is what a later maintainer repairs with a
 * `setInterval`, reintroducing a second unconditional mover and costing AT-D3-6 its instrument.
 *
 * ⛔ IT CARRIES NO CURRENCY LABEL AND NO *as of* STAMP (§ 5.5). When the feed is down this value
 * is stale by construction and the floor is still: that IS the design (§ 9 F1), and the
 * feed-status narration is where the page says how current it is.
 *
 * @param {number} localMs the viewer's own clock, as `Date.now()` reads it
 * @param {function(number): {hours: number, minutes: number}} [readLocal] the viewer's civil time
 */
export function roomTick(localMs, readLocal = civilTime) {
    const { hours, minutes } = readLocal(localMs);

    return Object.freeze({
        // The hands, as an angle each — the fact is the time, and the angles are that fact in the
        // form a clock face draws. The minute hand's own sweep carries the hour's fraction, so a
        // face drawn from these reads 09:45 rather than 09:00 with a stray minute hand.
        hours,
        minutes,
        hour_angle_deg: ((hours % 12) + minutes / 60) * 30,
        minute_angle_deg: minutes * 6,
        // The windows' sky, as § 4.2's *the time of day*. Four phases, on the civil hour alone —
        // no sunrise arithmetic, because that needs a latitude the viewer's browser is not asked
        // for and this document publishes no figure for.
        sky: skyPhase(hours),
        // § 6.2 A17 constraint 5: the clock's ACCESSIBLE TEXT, the one machine-readable rendering of
        // where the hands are — set in the same render that sets them, from the same two numbers,
        // so text and hands cannot disagree. Minute resolution: constraint 1, no second hand.
        text: `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`,
        label: 'your local time',
    });
}

/** The viewer's civil hours and minutes, from the platform's own zone handling. */
function civilTime(localMs) {
    const d = new Date(localMs);

    return { hours: d.getHours(), minutes: d.getMinutes() };
}

/** § 4.2's *windows whose sky carries the time of day*, as four named phases. */
export function skyPhase(hours) {
    if (hours < 6) {
        return 'night';
    }

    if (hours < 9) {
        return 'dawn';
    }

    if (hours < 18) {
        return 'day';
    }

    return hours < 21 ? 'dusk' : 'night';
}

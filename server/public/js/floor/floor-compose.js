/**
 * THE FLOOR, COMPOSED — `docs/design/FLOOR.md § 4.2`'s rooms placed over the floor's hallway, § 3.2's desks slotted
 * inside each, the floor's one back-wall band, its extent, its overflow row and its notices; and the input every
 * caller of `floor/scene.js`'s `buildScene()` hands it beside the frame. Appendix B row 47 (card#13033 slice 1; the
 * plan is `docs/design/plan-13033-3d-view.md` § 2 item 1).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER. The floor screen (`floor/floor-screen.js`'s `#drawFloor()`) composed its one
 * floor inline; the building view composes every floor, and a second copy of *where does this desk stand* would be
 * two answers free to disagree. What moved is the PURE half — a function of the composed floor row, the client's
 * `Building` and the held seats. What did not move is everything that reads the journal: § 3.3's displacement and
 * § 5.7's coordination lines stay in the floor screen, over the `assignments` this answers, so one assignment per
 * room per render is still what both the desks and the displacement read.
 *
 * ⛔ IT IS A MODEL AND NOT A PAGE: no document, no network, no clock. `building` is read through `room()` and
 * `mapless()` only, and nothing is written to it.
 *
 * ⛔ ONE INPUT ASSEMBLY FOR THE SCENE. `buildScene(frame, input)` reads the members `SCENE_INPUT_KEYS` lists: the
 * page's art (`artInputs()`, from `loadArt()`'s answer and the page's measurer), and per frame the documents the
 * client holds and the render's own state, joined by `sceneInput()`. `Tests\Feature\Floor\TheFloorCompositionIsOneForBothScreensTest`
 * holds the list equal to the `input.<member>` reads in `scene.js`, and holds that `assignSlots()` and
 * `mapReservation()` are called here and nowhere else.
 */

import { themeInputs } from './cab-paint.js';
import { assignSlots, backWallBand, mapDesks, mapGrid, mapReservation, placeRooms } from './floor-layout.js';

/**
 * § 5.5's *floor map is short N desks* — bare on a one-room floor, whose single map needs no
 * naming, and suffixed with the room on a floor of several, because *which map is short* is the
 * question the notice exists to answer (card#9292).
 */
export function shortNotice(count, installId, roomsOnFloor) {
    const bare = `floor map is short ${count} desks`;

    return roomsOnFloor > 1 ? `${bare} — ${installId}` : bare;
}

/**
 * § 5.5's reserved-desk line and § 9 F22 (card#11144): the room's reserved desk seats nobody, either
 * because no seat relays its role or because two or more do — named by its Tiled `id`, never by
 * § 3.2's slot index, and the seats by their `seat_id` in § 3.2's `order`. `null` when the desk seats
 * its one holder, which is the case with nothing to say. Bare on a one-room floor and suffixed with
 * the room on a floor of several, as `shortNotice()` is and for its reason: *which room's desk* is
 * the question.
 *
 * @param {{reserved: {id: number|null, role: string}|null, eligible: list<string>}} assignment `assignSlots()`'s answer for the room
 */
export function reservationNotice(assignment, installId, roomsOnFloor) {
    const { reserved, eligible } = assignment;

    if (reserved === null || eligible.length === 1) {
        return null;
    }

    const head = `reserved for \`${reserved.role}\` (id ${reserved.id})`;
    const bare = eligible.length === 0
        ? `${head} — no seat holds that role`
        : `${head} — ${eligible.length} seats hold that role: ${eligible.map((key) => `\`${key}\``).join(', ')}`;

    return roomsOnFloor > 1 ? `${bare} — ${installId}` : bare;
}

/** § 5.5 and § 9 F18: the two `install_id`s in key order, which is the order they are placed in. */
export function overlapNotice(a, b) {
    return `rooms \`${a}\` and \`${b}\` overlap on this floor`;
}

/**
 * § 9 F16's notice, naming the room. A `null` status is a request that never reached one — the
 * browser could not reach the server — and it is kept apart from every code rather than rendered as
 * one, for `wire/building.js`'s own reason: a failure with no code to name must not read as a code.
 */
export function mapFailureNotice(installId, status) {
    return status === null
        ? `room map could not be loaded — no response — ${installId}`
        : `room map could not be loaded — HTTP ${status} — ${installId}`;
}

/** § 9 F17's full-width statement, on every screen that composes floors — the floor route, and the building view. */
export function layoutFailureStatement(status) {
    return status === null
        ? 'the building layout could not be loaded — no response'
        : `the building layout could not be loaded — HTTP ${status}`;
}

/** § 4.6: a room the layout declares that the client holds no seat for. About SEATS, never installs. */
export const NO_SEATS_REPORTED = 'no seats reported for this room';

/**
 * One composed floor: its rooms placed, its desks slotted, its band, its extent, its overflow row and its notices.
 *
 * @param {object} floor one row of `lobby/lobby-model.js`'s `floors()` — `{ floor, name, aliases, theme, hallway, rooms }`
 * @param {object} building a `wire/building.js` `Building`, read through `room()` and `mapless()`
 * @param {Map<string, list<object>>} seatsByRoom the held seats by install (`lobby-model.js`'s `roomsOf()`)
 * @returns {{planned: boolean, hallway: object|null, extent: object|null, band: object|null, rooms: list<object>,
 *            draw_order: list<string>, overlaps: list<list<string>>, overflow: list<string>,
 *            desk_positions: Object<string, object>, notices: list<string>, assignments: Map<string, object>}}
 *          frozen; `desk_positions` keyed by `keyOf()`; `assignments` § 3.2's answer per room, the one the
 *          desks were placed by
 */
export function composeFloor(floor, building, seatsByRoom) {
    const held = new Map();
    const extents = new Map();

    for (const room of floor.rooms) {
        const state = building.room(room.install_id);
        const map = state?.held?.map ?? null;
        const grid = mapGrid(map);

        held.set(room.install_id, { state, map, grid });
        // § 9 F16: a mapless room "keeps the extent of the last map the client held for it, and
        // with none held it has NO footprint" — so the footprint is the held document's whether
        // or not the last request failed, and `null` where nothing is held. `null` is not the
        // room leaving the floor's extent: § 4.6 rule 5 puts its origin in that union, which
        // `placeRooms()` reads from the placement it just made rather than from this map.
        extents.set(room.install_id, grid);
    }

    const placement = placeRooms(floor.rooms, extents, floor.hallway);
    const notices = [];

    for (const [a, b] of placement.overlaps) {
        notices.push(overlapNotice(a, b));
    }

    // `floors()` already answered § 4.6's *a room the fleet reports no seat for* for each room;
    // the placement carries geometry, so the composed row is read back by key rather than having
    // that member copied into a geometry function that has no use for it.
    const composed = new Map(floor.rooms.map((room) => [room.install_id, room]));
    const rooms = [];
    const positions = new Map();
    const overflow = [];

    // ⛔ ONE ASSIGNMENT PER ROOM PER RENDER, read by the desks AND by § 3.3's displacement
    // check. Two runs of § 3.2 over one seat set would be two answers free to disagree, which
    // is the defect a pure function makes avoidable rather than impossible.
    const assignments = new Map();

    for (const placed of placement.rooms) {
        const state = held.get(placed.install_id);
        const seats = seatsByRoom.get(placed.install_id) ?? [];
        const mapless = building.mapless(placed.install_id);
        const slots = mapless ? [] : mapDesks(state.map);
        const assignment = assignSlots(seats, slots.length, mapReservation(slots));

        assignments.set(placed.install_id, assignment);

        // § 9 F16: with no map to draw, every desk is F14's placeholder "in a plain grid —
        // every fact as F14 draws it, no room". The ORDER is still
        // § 3.2's own `(h, seat_id)` order, so a mapless room's desks are as stable across a
        // reload as a mapped room's — F16 costs the room, never the identity.
        const drawn = mapless
            ? assignment.order.map((key, index) => ({ key, slot: null, grid_index: index, x: null, y: null }))
            : assignment.order
                .filter((key) => assignment.slots.has(key))
                .map((key) => {
                    const slot = assignment.slots.get(key);
                    const object = slots[slot];

                    return {
                        key,
                        slot,
                        grid_index: null,
                        // § 3.2's slot on the ROOM's map, plus § 4.6's origin for the room:
                        // the plan places the grid and never a seat.
                        x: placed.origin.x + object.x,
                        y: placed.origin.y + object.y,
                    };
                });

        for (const desk of drawn) {
            positions.set(desk.key, Object.freeze({ ...desk, install_id: placed.install_id }));
        }

        const roomNotices = [];

        if (mapless) {
            roomNotices.push(mapFailureNotice(placed.install_id, state.state?.failure?.status ?? null));
        } else if (assignment.overflow.length > 0) {
            roomNotices.push(shortNotice(assignment.overflow.length, placed.install_id, floor.rooms.length));
        }

        const reservation = reservationNotice(assignment, placed.install_id, floor.rooms.length);

        if (reservation !== null) {
            roomNotices.push(reservation);
        }

        // § 4.6: a room the fleet reports no seat for is drawn and LABELLED, never omitted — and
        // the wording is about SEATS, because "a client cannot tell an install with no seats
        // from an install that does not exist".
        const reported = composed.get(placed.install_id).reported;

        if (!reported) {
            roomNotices.push(NO_SEATS_REPORTED);
        }

        // ⛔ A MAPLESS ROOM'S DESKS ARE NOT THE OVERFLOW ROW. § 3.2's overflow is *seats past the
        // map's `S`*, and F16's room has no map and therefore no `S` at all: its desks are
        // placeholders in a plain grid, drawn in the room, and the floor's band below the
        // composed floor is for seats a MAP was short of. Reading `assignSlots(…, 0)`'s answer
        // as overflow here would put a whole room in that band under a notice about a map count.
        if (!mapless) {
            overflow.push(...assignment.overflow);
        }

        notices.push(...roomNotices);

        rooms.push(Object.freeze({
            install_id: placed.install_id,
            form: placed.form,
            origin: placed.origin,
            footprint: placed.footprint,
            map: state.state?.held == null ? null : Object.freeze({
                source: state.state.held.source,
                map_version: state.state.held.map_version,
            }),
            mapless,
            slots: mapless ? null : slots.length,
            desks: Object.freeze(drawn.map((desk) => Object.freeze(desk))),
            overflow: Object.freeze(assignment.overflow),
            reported,
            notices: Object.freeze(roomNotices),
        }));
    }

    return Object.freeze({
        planned: placement.planned,
        hallway: placement.hallway,
        extent: placement.extent,
        // § 4.2: ONE back-wall band for the floor, spanning its whole extent, inside no room's
        // map and inside no hallway's. A floor of five offices has one clock over all five.
        band: backWallBand(placement.extent),
        rooms: Object.freeze(rooms),
        draw_order: placement.draw_order,
        overlaps: placement.overlaps,
        // § 3.2: the overflow row is the FLOOR's — one band below the composed floor — however
        // many rooms are short.
        overflow: Object.freeze(overflow),
        desk_positions: Object.freeze(Object.fromEntries(positions)),
        notices: Object.freeze(notices),
        assignments,
    });
}

/**
 * Every member `buildScene()` reads off its input — the art's, the documents', the render's, and the members the floor
 * screen's in-flight effects add (the list's last line). `TheFloorCompositionIsOneForBothScreensTest` holds it equal to
 * `scene.js`'s reads.
 */
export const SCENE_INPUT_KEYS = Object.freeze([
    'box', 'measure', 'character', 'draw', 'crest', 'headClear', 'themes',
    'maps', 'hallway', 'tilesets', 'layout_version', 'failed', 'reduce', 'effects', 'previous',
    'interns_in_flight', 'laptop_at', 'trips_in_flight', 'walks_in_flight',
]);

/**
 * The scene's art inputs from `painter.js`'s `loadArt()` answer (Appendix B row 14) — the art no module may import.
 * The caller asks only once the furniture box has loaded: with none there is no scene to build.
 *
 * @param {{furniture: object, characters: object|null, registry: object|null, themes: Object<string, object|null>}} art
 * @param {function(string, string): {w: number, h: number}} measure the page's measurer (`painter.js`'s `measurer()`)
 */
export function artInputs({ furniture, characters, registry, themes }, measure) {
    return Object.freeze({
        box: furniture.FURNITURE_BOX,
        measure,
        character: { w: characters?.SCENE_W ?? 0, h: characters?.SCENE_H ?? 0 },
        // The character tree's seeded draw: a departing walker's wait at the elevator is one (§ 6.2's walk note item 5).
        draw: characters?.draw ?? null,
        // The character tree's crest of a seat's drawn head: where § 5.1's thought trail ends (card#12444).
        crest: characters?.crestTop ?? null,
        // The tree's face and pose mark over the head: what the bubble is set above (card#12446).
        headClear: characters?.headClear ?? null,
        // FLOOR.md § 10.6: the registry each floor's `theme` is resolved against (item 5, § 9 F23) and
        // which themes the page holds (item 8). A registry or a theme whose import was rejected is
        // § 9 F14's: the floor draws its fallback, and the strip names the asset.
        themes: themeInputs(registry, themes),
    });
}

/**
 * Each room's held map, by install — `null` for a room the client holds none for.
 *
 * @param {{rooms: list<{install_id: string}>}} frame a composed frame (or `composeFloor()`'s answer)
 * @param {object} building a `wire/building.js` `Building`
 * @returns {Map<string, object|null>}
 */
export function roomMaps(frame, building) {
    return new Map(frame.rooms.map((room) => [room.install_id, building.room(room.install_id)?.held?.map ?? null]));
}

/**
 * What `buildScene()` reads beside a frame: the art inputs and the per-frame documents — `{ maps, hallway, tilesets,
 * layout_version, failed, reduce, effects, previous }` and, from the floor screen's render, the in-flight members
 * `interns_in_flight`, `laptop_at`, `trips_in_flight` and `walks_in_flight` — joined. A member a caller has no value
 * for is left out, and the scene reads its absence as nothing in flight.
 *
 * @param {object} art `artInputs()`'s answer (the harness hands its own of the same members)
 * @param {object} docs the per-frame documents
 */
export function sceneInput(art, docs) {
    return Object.freeze({ ...art, ...docs });
}

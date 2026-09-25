/**
 * THE FLOOR SCREEN — `docs/design/FLOOR.md § 4.2`'s floor, composed: § 4.6's rooms at their
 * origins over the floor's hallway, § 3.2's desks inside each of them, § 4.2's one wall clock and
 * windows across the whole extent, and § 5.7's coordination line drawn between two desks.
 * Appendix B row 7, card#7341 step 7.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A MODEL AND NOT A PAGE. Every fact is decided here and asserted headlessly
 * (`server/tests/Feature/Floor/`, through `fleet-client-probe.mjs`); nothing here touches a
 * document, and where on a canvas a thing lands is a drawing layer's. That is the same split
 * `desk/desk-render.js`, `lobby/lobby-model.js` and `coord/coord-model.js` each keep, and the
 * reason is theirs: there is no browser on the build host, so a decision in a DOM file is a
 * decision no check can exercise.
 *
 * ⚠ NO PAGE CONSTRUCTS THE CLIENT PROTOCOL BEFORE Appendix B ROW 8, so nothing calls this on a
 * page yet either. What row 8 adds is the stream recovery and the page that owns it; what this
 * module owns is what a floor looks like once something has applied a message to it.
 *
 * ⛔ THE DRAIN IS HERE, AND THE DESK FLOOR IS HANDED WHAT THIS SCREEN DRAINED. § 2.5's apply path
 * belongs to whoever owns the apply, which is the screen; `desk/desk-floor.js` keeps its own
 * `render()` for the desk-only rig step 5 built and this module calls `renderWith()`. One drain per
 * apply on either path, and the two paths are mutually exclusive.
 *
 * ⛔ ONE ANIMATION SET, WHICH IS THE DESK FLOOR'S. A18/A19/A20 and A16 are written through the same
 * `wire/animation-set.js` instance the desks' held renders go through, because "a second way into
 * the log is a second implementation of the one record AT-D3-1 reads" (step 6's own argument, one
 * screen up).
 *
 * ⛔ THE COMPOSITION IS `lobby/lobby-model.js`'s `floors()` AND NOT A SECOND ONE. § 4.6 states its
 * one client-side rule — an install the snapshot carries that no delivered floor places is a floor
 * of its own, alone, `open` — and gives it two homes held to one fixture
 * (`App\Building\Building::compose()` and `floors()`). A third here would be a third answer to
 * *which rooms are on this floor*; what this module adds is the GEOMETRY, which is
 * `floor/floor-layout.js`'s and which neither of those two computes.
 *
 * ⛔ F17 COMPOSES NOTHING ON A COLD START (§ 9). `floors()` answers `null` for a layout that was
 * never read, and this screen carries that `null` through as the uncomposed render: the statement,
 * and the snapshot's installs as rooms with NO FLOOR CLAIMED. It does not compose the route's
 * segment into a one-room floor, "because deciding whether a segment is a floor's key or a room on
 * someone else's floor needs the very document that failed".
 */

import { Building } from '../wire/building.js';
import { DeskFloor } from '../desk/desk-floor.js';
import { coordModel } from '../coord/coord-model.js';
import { correctedNowMs } from '../wire/duration.js';
import { floors } from '../lobby/lobby-model.js';
import { buildJoin } from './coord-join.js';
import {
    assignSlots,
    backWallBand,
    mapDesks,
    mapGrid,
    placeRooms,
    roomTick,
} from './floor-layout.js';

/**
 * § 5.5's *floor map is short N desks* — bare on a one-room floor, whose single map needs no
 * naming, and suffixed with the room on a floor of several, because *which map is short* is the
 * question the notice exists to answer (card#9292).
 */
export function shortNotice(count, installId, roomsOnFloor) {
    const bare = `floor map is short ${count} desks`;

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

/** § 9 F17's full-width statement, on the floor route exactly as on the lobby. */
export function layoutFailureStatement(status) {
    return status === null
        ? 'the building layout could not be loaded — no response'
        : `the building layout could not be loaded — HTTP ${status}`;
}

/** § 9 F17: what the floors a screen still holds are labelled while the last request failed. */
export const LAST_KNOWN_LAYOUT = 'last known layout';

/** § 4.6: a room the layout declares that the client holds no seat for. About SEATS, never installs. */
export const NO_SEATS_REPORTED = 'no seats reported for this room';

/**
 * § 4.4's floor segment, resolved against the composed floors: the floor it names, the floor to
 * REDIRECT to when it names a room that is not its floor's key, or neither.
 *
 * ⛔ THE SEGMENT IS A KEY AND NEVER A LABEL (card#9273). Nothing here matches on `label`: a label is
 * edited freely and no published link, bookmark or cab position may move when one is.
 */
export function resolveRoute(segment, composed) {
    const floor = composed.find((row) => row.floor === segment);

    if (floor !== undefined) {
        return { floor, redirect: null };
    }

    const holder = composed.find((row) => row.rooms.some((room) => room.install_id === segment));

    // § 4.4 row 3: a segment naming a room that is not its floor's id redirects to the floor that
    // holds the room — "no link dies when the operator composes a floor, or re-keys one".
    return holder === undefined
        ? { floor: null, redirect: null }
        : { floor: null, redirect: holder.floor };
}

export class FloorScreen {
    #client;

    #building;

    #clock;

    #desks;

    #set;

    #options;

    /** § 4.4's `{floor}` segment this screen was entered on — a key, never a label. */
    #segment;

    /** install_id → the slot assignment the last render drew, for § 3.3's displacement. */
    #placed = new Map();

    /** § 4.2's wall clock and sky, `null` until a render that established a LIVE feed (§ 6.5). */
    #tick = null;

    /** Whether the last render saw the protocol holding a live feed — § 6.5's ESTABLISHMENT edge. */
    #wasLive = false;

    /** The `post_ref`s whose A19/A20 rows have been written, so a redelivery draws nothing new. */
    #drawn = new Set();

    /**
     * @param {object} client a `FleetClient` — the seats, the journal, the record and the offset
     * @param {object} building a `wire/building.js` `Building` — the layout and each room's map
     * @param {{now: function(): number}} clock the browser's own clock
     * @param {object} log `wire/animation-log.js`'s `createAnimationLog()`
     * @param {object} [options] `{ reduce, ref_bases, local_time }` — `local_time` reads the
     *        VIEWER's civil time and exists because a build host has one time zone and § 4.2's sky
     *        has four phases; the viewer's own `Date` is the default.
     */
    constructor(client, building, clock, log, options = {}) {
        this.#client = client;
        this.#building = building;
        this.#clock = clock;
        this.#desks = new DeskFloor(client, clock, log, options);
        this.#set = this.#desks.set;
        this.#options = options;
        this.#segment = options.floor ?? null;
    }

    /** The desk floor this screen runs — the age ticker's population, and the frame's source. */
    get desks() {
        return this.#desks;
    }

    /**
     * § 4.4's `/floor/{floor}` entry: the layout, then each room's map whose version the client does
     * not already hold.
     *
     * ⛔ THE LAYOUT IS FETCHED ONLY WHEN THE CLIENT HOLDS NONE. `enter()` may be called on a screen
     * whose `Building` already read one — the lobby's entry did, and § 2.2 step 3b is once per
     * connect — and a second unconditional fetch here would spend a request re-reading a document
     * this client already has at a version no message said had moved.
     */
    async enter() {
        if (this.#building.floors === null) {
            await this.#building.fetchLayout();
        }

        await this.#enterRooms();
    }

    /** § 4.4: "each room on the floor whose map the client does not already hold at the version". */
    async #enterRooms() {
        const target = this.#target();

        if (target === null) {
            return;
        }

        await this.#building.enterRooms(target.rooms.map((room) => room.install_id));
    }

    /**
     * The rooms this screen has NEVER asked for — a room the layout placed, or an install the
     * discovery fetch added after `enter()` ran (§ 4.6's one client-side rule composes it onto a
     * floor of its own the moment the snapshot carries it).
     *
     * ⛔ IT DOES NOT RE-ASK FOR A ROOM WHOSE REQUEST FAILED, and that is the whole difference from
     * `enter()`. F16's recovery is "retry on the user's action, and on the next `room.map` for that
     * room" — so a screen that re-entered every failed room on every apply would put a request
     * behind every delta the fleet sends, which is a poll wearing an apply's clothes.
     */
    async #enterNewRooms() {
        const target = this.#target();

        if (target === null) {
            return;
        }

        const unasked = target.rooms
            .map((room) => room.install_id)
            .filter((installId) => this.#building.room(installId) === null);

        if (unasked.length > 0) {
            await this.#building.enterRooms(unasked);
        }
    }

    /**
     * § 2.5's apply path for the whole floor: drain the journal once, run the layout acts it
     * carries, then draw.
     *
     * ⛔ THE LAYOUT ACTS RUN BEFORE THE DRAW AND FIRE NO § 6.2 ROW. § 2.5 gives `room.map` and
     * `building.layout` one rule and § 6.5 makes a layout act no fleet event: the acts REPLACE a
     * document, the draw reads whatever is then held, and nothing about either reaches the
     * animation set — which reads the journal for `seat.delta`, `feed.heartbeat` and the
     * coordination messages alone.
     */
    async render() {
        const journal = this.#client.takeWire();

        await this.#layoutActs(journal);
        await this.#enterNewRooms();

        return this.draw(journal);
    }

    /**
     * Appendix B row 13's two owed halves, discharged (T39): the message is delivered to
     * `wire/building.js`'s apply — which re-fetches the room at its new version, so the room
     * RE-RENDERS on the next draw — and the line that apply returns is written into § 5.5's record.
     */
    async #layoutActs(journal) {
        const target = this.#target();
        const rendered = target === null ? [] : target.rooms.map((room) => room.install_id);

        for (const entry of journal) {
            if (entry.t === 'building.layout') {
                await this.#building.applyLayout(entry);
                // A layout that moved may have moved this floor's rooms, so the rooms the screen
                // now draws are fetched for before it draws them.
                await this.#enterRooms();

                continue;
            }

            if (entry.t !== 'room.map') {
                continue;
            }

            // D2 § 8.7: "a client applies a `room.map` only for a room it renders and ignores the
            // rest". Which rooms those are is this screen's answer and nobody else's.
            const { line } = await this.#building.applyRoomMap(entry, rendered);

            if (line !== null) {
                this.#client.record(line);
            }
        }
    }

    /** The composed floors the client holds, or `null` when no layout request has ever succeeded. */
    #composed() {
        return floors(this.#snapshotShape(), this.#building.floors);
    }

    /**
     * `floors()` reads a snapshot BODY and this screen holds the protocol's seat map, so the map is
     * projected into the shape that function already reads. It is a projection and not a second
     * membership rule: the population is the seats the client holds, which is § 2.1 row 5's.
     */
    #snapshotShape() {
        return { installs: [...this.#seatsByRoom()].map(([install_id, seats]) => ({ install_id, seats })) };
    }

    /** The composed floor this screen draws, or `null` when there is none to draw. */
    #target() {
        const composed = this.#composed();

        if (composed === null) {
            return null;
        }

        return resolveRoute(this.#segment, composed).floor;
    }

    /**
     * The floor, drawn over one drained journal. Pure but for the episode state the animation set
     * and this screen's own displacement bookkeeping keep.
     */
    draw(journal) {
        const offset = this.#client.clockOffsetMs;
        const at = correctedNowMs(offset, this.#clock.now());
        const composed = this.#composed();
        const failure = this.#building.layoutFailure;

        // ⛔ THE DESKS ARE DRAWN WHATEVER THE LAYOUT SAYS, and they are drawn first: F17's "every
        // seat stays reachable; no composition is asserted on either screen" is a statement about
        // the COMPOSITION, not about the desks, and a screen that withheld them would have made a
        // failed layout request into a floor with no fleet on it.
        const deskFrame = this.#desks.renderWith(journal);

        this.#setRoom(journal);

        if (composed === null) {
            // F17's cold start: no layout was ever read, so nothing is composed. The installs the
            // client holds are listed as rooms with NO FLOOR CLAIMED.
            return Object.freeze({
                composed: false,
                statement: layoutFailureStatement(failure?.status ?? null),
                last_known_layout: false,
                floor: null,
                redirect: null,
                rooms: Object.freeze(this.#snapshotShape().installs.map((install) => Object.freeze({
                    install_id: install.install_id,
                    floor_claimed: false,
                }))),
                room: this.#tick,
                desks: deskFrame,
                notices: Object.freeze([]),
            });
        }

        const route = resolveRoute(this.#segment, composed);

        if (route.floor === null) {
            return Object.freeze({
                composed: true,
                statement: null,
                last_known_layout: failure !== null,
                floor: null,
                // § 4.4 row 3's redirect, decided here and performed by whatever owns the URL.
                redirect: route.redirect,
                rooms: Object.freeze([]),
                room: this.#tick,
                desks: deskFrame,
                notices: route.redirect === null
                    ? Object.freeze([`the floor ${this.#segment} is not in the building`])
                    : Object.freeze([]),
            });
        }

        return this.#drawFloor(route.floor, deskFrame, failure, journal, at);
    }

    /**
     * § 6.5's property, and not a list of renders: A17's wall clock and sky are set by a render that
     * establishes or re-establishes a LIVE feed, and by nothing else.
     *
     * A `feed.heartbeat` is what A17 FIRES on (§ 6.2), and the connect sequence's snapshot — and a
     * successful reconnect's — SETS the value with no log row at all, because nothing happened to any
     * seat. Before either, the room has no value and is rendered as having none: "a plausible time
     * on a page that has never been live is exactly the zero that rule refuses".
     *
     * ⚠ WHAT THIS CANNOT YET TELL APART, named rather than guarded against: F1's 10 s POLL response
     * is also a snapshot apply, and § 6.5 says it sets nothing — the poll being the timer that
     * amendment removed. No polling mode exists before Appendix B step 8, so no poll response can
     * reach this journal; the step that builds one owes its own marking of those responses, and this
     * comment is where that obligation is left rather than a branch no input can select today.
     */
    #setRoom(journal) {
        const live = this.#client.phase === 'live';
        // ⛔ THE ESTABLISHMENT IS THE EDGE INTO `live`, NOT A SNAPSHOT ROW IN THE JOURNAL. The two
        // agree on a fleet with seats and part company on one with none: the journal carries a row per
        // SEAT a snapshot delivered, so a live feed over an empty population journals nothing and a
        // reader looking for a row would leave the clock unset on a healthy fleet — which is § 9 F1's
        // feed-down claim made falsely, the exact inverse of the defect A17 exists to prevent.
        const established = live && !this.#wasLive;
        const heartbeat = journal.some((entry) => entry.t === 'feed.heartbeat');

        this.#wasLive = live;

        if (heartbeat || established) {
            this.#tick = roomTick(this.#clock.now(), this.#options.local_time);
        }
    }

    /** One composed floor: its rooms placed, its desks slotted, its band, and its lines. */
    #drawFloor(floor, deskFrame, failure, journal, at) {
        const held = new Map();
        const extents = new Map();

        for (const room of floor.rooms) {
            const state = this.#building.room(room.install_id);
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

        const seatsByRoom = this.#seatsByRoom();
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
            const mapless = state.state === null || state.state.failure !== null;
            const slots = mapless ? [] : mapDesks(state.map);
            const assignment = assignSlots(seats, slots.length);

            assignments.set(placed.install_id, assignment);

            // § 9 F16: with no map to draw, every desk is F14's placeholder "in a plain grid —
            // nameplate, state label and badge cluster; every fact, no room". The ORDER is still
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

        this.#displacements(assignments, journal, at);

        const coord = this.#coordinate(floor, seatsByRoom, positions, journal, at);

        return Object.freeze({
            composed: true,
            // § 9 F17 on the floor route: the same statement, over the rooms it already holds.
            statement: failure === null ? null : layoutFailureStatement(failure.status),
            last_known_layout: failure !== null ? LAST_KNOWN_LAYOUT : null,
            floor: Object.freeze({
                key: floor.floor,
                label: floor.label,
                // § 4.6's one rendering rule: `label ?? key`, decided once by `floors()` and read
                // here rather than restated.
                name: floor.name,
            }),
            redirect: null,
            planned: placement.planned,
            hallway: placement.hallway,
            extent: placement.extent,
            // § 4.2: ONE back-wall band for the floor, spanning its whole extent, inside no room's
            // map and inside no hallway's. A floor of five offices has one clock over all five.
            band: backWallBand(placement.extent),
            room: this.#tick,
            rooms: Object.freeze(rooms),
            draw_order: placement.draw_order,
            overlaps: placement.overlaps,
            // § 3.2: the overflow row is the FLOOR's — one band below the composed floor — however
            // many rooms are short.
            overflow: Object.freeze(overflow),
            desks: deskFrame,
            desk_positions: Object.fromEntries(positions),
            coord,
            notices: Object.freeze(notices),
        });
    }

    /** The held seats grouped by their install — each room's own rendered seat set (§ 3.2). */
    #seatsByRoom() {
        const byRoom = new Map();

        for (const seat of this.#client.seats.values()) {
            const id = String(seat.install_id);

            if (!byRoom.has(id)) {
                byRoom.set(id, []);
            }

            byRoom.get(id).push(seat);
        }

        return byRoom;
    }

    /**
     * § 3.3's displacement, as § 6.2 A16: an arriving seat took an incumbent's slot, so the
     * incumbent walks to its new one.
     *
     * ⛔ IT IS THE SLOT FUNCTION'S OWN ANSWER AND NOT A DIFF OF TWO RENDERS. The assignment is
     * re-run over the new seat set and each incumbent's slot compared with the one it held — which
     * is what makes the row true of the function rather than of two frames that might disagree for
     * some other reason.
     *
     * ⛔ AND IT NEEDS AN APPLIED `seat.delta` IN THE JOURNAL (§ 6.5). A snapshot, a resync and a
     * per-seat insert fire NO `edge` row: a discovery snapshot that adds a seat re-slots the room
     * and animates nothing, because "their arrival is not a claim that anything happened to any
     * seat — it is a claim about what the client knows". The delta that NAMED a seat the client did
     * not hold is the causing message; the insert fetch is only how the object was obtained
     * (§ 2.3), which is why the fetch's own journal row is not what is read here.
     *
     * ⚠ THE DEPARTURE SIDE IS NOT BUILT AND IS NOT GUARDED AGAINST. § 3.5 states its cost — "a
     * retirement can move one other desk" — and a removal is Appendix B step 10's announcement, so
     * no input at this step can shrink a room's seat set. The step that builds the removal owes
     * A16's other cause: the seat-set change is then a DEPARTURE, and § 11's *the arriving seat's
     * key* has no referent for it.
     */
    #displacements(assignments, journal, at) {
        const arrived = new Set(journal
            .filter((entry) => entry.t === 'seat.delta' && entry.outcome === 'applied')
            .map((entry) => `${entry.install_id}/${entry.seat_id}`));

        for (const [installId, assignment] of assignments) {
            const before = this.#placed.get(installId) ?? null;

            this.#placed.set(installId, assignment.slots);

            if (before === null) {
                continue;
            }

            // § 3.2's order over the seats that arrived with a delta this journal carries. § 11
            // names A16's cause as *the arriving seat's key*; with more than one arrival settling
            // in one turn it is the one that sorts lowest in that order, so that two browsers
            // replaying one journal agree — § 14 item 27's ruling, which adopted the rule this
            // line was first written with, and AT-D3-3's two-arrival GREEN pins it.
            const arrivals = assignment.order.filter((key) => !before.has(key) && arrived.has(key));

            if (arrivals.length === 0) {
                continue;
            }

            for (const [key, slot] of assignment.slots) {
                if (before.has(key) && before.get(key) !== slot) {
                    const [install_id, seat_id] = splitKey(key);

                    this.#set.displaced(install_id, seat_id, arrivals[0], at);
                }
            }
        }
    }

    /**
     * § 5.7's coordination layer, PER ROOM — "a round is drawn in the ROOM its own `install_id`
     * names and in no other" (clause 3), and a floor is N rooms, so the model is run once per room
     * against that room's own join.
     */
    #coordinate(floor, seatsByRoom, positions, journal, at) {
        const messages = this.#client.coordMessages;
        const rooms = {};
        const threads = [];

        for (const room of floor.rooms) {
            const { join, reasons, checks } = buildJoin(seatsByRoom.get(room.install_id) ?? []);
            const model = coordModel(messages, {
                install_id: room.install_id,
                join,
                reasons,
                checks,
            });

            // The line's endpoints are DESK POSITIONS, which is why this render is step 7's at all:
            // "drawing a line between two desks needs their floor positions" (§ 14 item 24).
            const lines = model.threads.map((thread) => Object.freeze({
                ...thread,
                ends: Object.freeze(thread.endpoints.map((seatId) => {
                    const at_desk = positions.get(`${room.install_id}/${seatId}`) ?? null;

                    return Object.freeze({
                        seat_id: seatId,
                        x: at_desk?.x ?? null,
                        y: at_desk?.y ?? null,
                    });
                })),
            }));

            rooms[room.install_id] = Object.freeze({ ...model, threads: Object.freeze(lines) });
            threads.push(...lines);

            this.#roundRows(room.install_id, model, journal, at);
        }

        // § 6.2 A18 over every thread on the floor: entered while the thread is open with two
        // endpoints that resolve, left when either stops being true.
        this.#set.lines(threads, at);

        return Object.freeze(rooms);
    }

    /**
     * A19 and A20's rows for the `coord.round` messages this journal carried.
     *
     * ⛔ ONE ENVELOPE PER RESOLVED DESTINATION (§ 6.2 A19: "from the origin desk to EACH destination
     * desk"), and "a destination that does not resolve gets no envelope and no line, and the ones
     * that do still get theirs". ONE ring per broadcast post (A20).
     *
     * ⛔ A REDELIVERED POST DRAWS NOTHING NEW. D2 publishes no `seq` for these messages, so
     * `post_ref` is the whole of a post's identity and a repeat is the one path that reaches this
     * feed twice for one real post. The set below is *which posts have been ANIMATED*, which is a
     * different question from `coordModel()`'s own dedupe of a message LIST and is why it is not a
     * second copy of it.
     */
    #roundRows(installId, model, journal, at) {
        for (const entry of journal) {
            if (entry.t !== 'coord.round' || entry.outcome !== 'applied' || entry.install_id !== installId) {
                continue;
            }

            const round = model.rounds.find((r) => r.post_ref === entry.coord_ref);

            if (round === undefined || this.#drawn.has(entry.coord_ref)) {
                continue;
            }

            this.#drawn.add(entry.coord_ref);

            if (round.animations.includes('A19')) {
                for (let i = 0; i < round.targets.desks.length; i++) {
                    this.#set.envelope(installId, round.post_ref, at);
                }
            }

            if (round.animations.includes('A20')) {
                this.#set.broadcast(installId, round.post_ref, at);
            }
        }
    }
}

/** A held seat's key, split back into the pair § 3.1 makes a desk's identity. */
function splitKey(key) {
    const cut = key.indexOf('/');

    return [key.slice(0, cut), key.slice(cut + 1)];
}

/**
 * The floor, started — the shape a page (Appendix B step 8) and the probe both drive.
 *
 * @param {object} client a `FleetClient`
 * @param {Function} fetchImpl the browser's own `fetch`, unbound — `wire/building.js`'s injection
 * @param {{now: function(): number}} clock
 * @param {object} log the animation log every § 6.2 row is recorded in
 * @param {function(object): void} draw receives each floor frame
 * @param {object} [options] `{ floor, reduce, ref_bases, local_time }`
 */
export function startFloorScreen(client, fetchImpl, clock, log, draw, options = {}) {
    const screen = new FloorScreen(client, new Building(fetchImpl), clock, log, options);

    return {
        enter: async () => {
            await screen.enter();
        },
        render: async () => {
            draw(await screen.render());
        },
    };
}

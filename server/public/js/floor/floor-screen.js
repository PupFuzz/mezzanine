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
 * ⛔ ITS PAGE IS `floor/main.js` (Appendix B row 8), which constructs the client protocol with its
 * stream recovery and draws each frame this module returns. What this module owns is what a floor
 * looks like once something has applied a message to it — the status strip and the failure renders
 * included, on every frame shape.
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
 *
 * ⛔ THE ROOM DRAWING IS THIS FRAME's `scene` (Appendix B row 14, card#7341 step 11). Once the page
 * has handed this screen the scene's inputs (`sceneInputs()` — the furniture box, the desk sprite,
 * its measurer and the character's size) every frame carries `floor/scene.js`'s model of what is
 * drawn where, built from this frame, the maps and tilesets the client holds, the assets the painter
 * reported failed (`assetsFailed()`) and the § 6.2 rows this very render wrote. The scene's F21
 * notices join the frame's, and its F14 verdict is the status strip's `art` line — one strip, the
 * step-8 module's, told one more thing.
 *
 * ⛔ THE CAPABILITY FLOOR AND THE CAMERA ARE THIS SCREEN's TOO (Appendix B row 15, § 4.5). Below § 12's
 * viewport floor — the size read from the viewport the page supplies (`resize()`), and in the harness
 * from the fixture — a frame is the LIST VIEW (`capability: 'list'`): no scene, no camera, every desk
 * as text. At or above it the frame carries the scene and the camera (`wire/camera.js`) framed on the
 * scene's whole extent, which is the floor AND the overflow strip (card#7965). The camera is held
 * HERE, beside the episode state, because the render that must leave it alone is this module's: every
 * `draw()` hands the camera the scene's extent and the camera keeps the viewer's zoom and pan (the
 * first framing alone fits), and the viewer's acts — `wheel()`, `zoomStep()`, `drag()`, `fitFloor()`
 * — move the camera and nothing else: they drain nothing, draw nothing and write no animation-log
 * row. A navigation act is never state (§ 4.5), and AT-D3-21 reds the day one of them reaches the log.
 */

import { Building } from '../wire/building.js';
import { DeskFloor } from '../desk/desk-floor.js';
import { DrillDownPanel } from '../drilldown/drilldown-panel.js';
import { coordModel } from '../coord/coord-model.js';
import { correctedNowMs } from '../wire/duration.js';
import { floors, heldBody, roomsOf } from '../lobby/lobby-model.js';
import { buildJoin } from './coord-join.js';
import { statusStrip } from './status-strip.js';
import { failureRender } from '../wire/failure-render.js';
import { buildScene } from './scene.js';
import { createCamera, fit, frameOn, glideMs, panBy, resize, sizeOf, unframe, wheel, zoomStep } from '../wire/camera.js';
import { TilesetLoader, tilesetUrl } from './tileset.js';
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

/**
 * § 12's *Floor viewport floor*: below it in either dimension the route renders the list view, at or
 * above it the drawn floor under the camera (§ 4.5's first rule). `TheCameraMovesTheViewerAndNeverTheFleetTest`
 * enters the route at the figure § 12 states and one pixel under it in each dimension, so this copy
 * reds there the day it and the document part.
 */
export const VIEWPORT_FLOOR = Object.freeze({ width: 1280, height: 800 });

/** § 4.5's capability floor over a viewport in CSS px: `'floor'` at or above it, else `'list'`. */
export function capabilityOf(viewport) {
    return viewport.width >= VIEWPORT_FLOOR.width && viewport.height >= VIEWPORT_FLOOR.height ? 'floor' : 'list';
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

/**
 * § 11's `cause` for one A16 row (§ 14 item 27).
 *
 * ⛔ THE CAUSE IS THE ARRIVAL THAT NOW HOLDS THE DISPLACED SEAT'S FORMER SLOT. A16 is a
 * displacement, so the seat it names must be the one that did the displacing. Naming some other
 * arrival would be a wrong attribution, not an approximate one.
 *
 * ⚠ A CASCADE IS THE ONE CASE THAT RULE CANNOT ANSWER, AND THE FALLBACK IS A STATED APPROXIMATION.
 * When the new holder of the former slot is not an arrival (an arrival moved B, and B took C's
 * slot), C was displaced by a seat-set change with no single arriving key behind it. The cause is
 * then the arrival that sorts lowest in § 3.2's `order` among this render's arrivals. That is
 * deterministic, because `order` is a total order over keys that every client computes identically.
 *
 * @param {number} formerSlot the slot the displaced incumbent held before this render
 * @param {Map<number, string>} holder slot → the key that holds it now
 * @param {list<string>} arrivals this render's arrivals, in § 3.2's `order`; never empty
 * @returns {string} the arriving key the A16 row names
 */
function displacementCause(formerSlot, holder, arrivals) {
    const taker = holder.get(formerSlot);

    return arrivals.includes(taker) ? taker : arrivals[0];
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

    /**
     * § 4.4's `{seat_id}` segment — the drill-down the route asks for — or `null` on the floor alone.
     * Kept by the screen because the URL is the screen's to decide and the page's to perform, as the
     * floor segment's redirect is.
     */
    #seatSegment;

    /** The drill-down (Appendix B row 10), over this screen's own client protocol. */
    #panel;

    /** Whether `#seatSegment`'s desk has been opened — so a panel a removal closed clears the segment. */
    #seatOpened = false;

    /** install_id → the slot assignment the last render drew, for § 3.3's displacement. */
    #placed = new Map();

    /** § 4.2's wall clock and sky, `null` until a render that established a LIVE feed (§ 6.5). */
    #tick = null;

    /**
     * The `post_ref`s whose A19/A20 rows have been written, so a redelivery draws nothing new —
     * trimmed on every draw to the posts the protocol still holds (`#coordinate`, § 14 item 25).
     */
    #drawn = new Set();

    /** The tapped log — the one AT-D3-1 reads, with this render's rows kept for the scene. */
    #tap;

    /** The tilesets the held maps name, and the desk sprite's (`floor/tileset.js`). */
    #tilesets;

    /**
     * The scene's inputs no module may import (Appendix B row 14) — `{ box, desk_sprite, measure,
     * character }` — or `null` until the page (or the harness) supplies them. With none, a frame
     * carries no scene.
     */
    #sceneInput = null;

    /** The asset ids the painter reported failed to load (§ 9 F14). Never cleared: *retry on reload*. */
    #failed = new Set();

    /** key → where the last scene drew each desk — where the next render's walks start. */
    #anchors = new Map();

    /** The last frame a scene was built over — what the 1 s tick re-reads (`sceneView`). */
    #lastFrame = null;

    /** The viewer's viewport in CSS px — what § 4.5's capability floor reads (Appendix B row 15). */
    #viewport;

    /** The camera (`wire/camera.js`) — the viewer's head, never the fleet's; framed while the floor is drawn. */
    #camera;

    /**
     * @param {object} client a `FleetClient` — the seats, the journal, the record and the offset
     * @param {object} building a `wire/building.js` `Building` — the layout and each room's map
     * @param {{now: function(): number}} clock the browser's own clock
     * @param {object} log `wire/animation-log.js`'s `createAnimationLog()`
     * @param {object} [options] `{ floor, seat, reduce, local_time, viewport, surface }` —
     *        `local_time` reads the VIEWER's civil time and exists because a build host has one time
     *        zone and § 4.2's sky has four phases; the viewer's own `Date` is the default. `viewport`
     *        (required) is the viewer's viewport in CSS px, `surface` the drawing's (the viewport's
     *        own size when not stated).
     * @param {TilesetLoader} [tilesets] the tilesets' loader, over the page's own `fetch`
     */
    constructor(client, building, clock, log, options = {}, tilesets = null) {
        this.#client = client;
        this.#building = building;
        this.#clock = clock;
        this.#tap = tapped(log);
        this.#tilesets = tilesets;
        this.#desks = new DeskFloor(client, clock, this.#tap.log, options);
        this.#set = this.#desks.set;
        this.#options = options;
        this.#segment = options.floor ?? null;
        this.#seatSegment = options.seat ?? null;
        this.#panel = new DrillDownPanel(client);
        this.#viewport = sizeOf(options.viewport);
        this.#camera = createCamera(options.surface ?? this.#viewport);
    }

    /**
     * The viewer's viewport, and the drawing surface inside it, changed size (Appendix B row 15). The
     * next render decides the capability floor again; a camera still at fit stays at fit.
     */
    resize(viewport, surface = viewport) {
        this.#viewport = sizeOf(viewport);
        this.#camera = resize(this.#camera, surface);
    }

    /** The camera as it stands — the viewer's head, as data. */
    get camera() {
        return this.#camera;
    }

    /**
     * A wheel event — a notch, a trackpad's scroll or a pinch — at a point on the drawing surface: a
     * zoom about that point in proportion to the scroll (§ 4.5; `wire/camera.js`'s `wheel()`).
     *
     * @param {{x: number, y: number}} point
     * @param {{deltaY: number, deltaMode?: number, ctrlKey?: boolean}} delta `ctrlKey` marks a pinch
     */
    wheel(point, delta) {
        this.#camera = wheel(this.#camera, point, delta);

        return this.#camera;
    }

    /**
     * `notches` zoom steps about the drawing's centre — the keyboard's `+`/`-` and the zoom buttons,
     * which have no cursor (§ 4.5): positive in, negative out.
     */
    zoomStep(notches) {
        this.#camera = zoomStep(this.#camera, notches);

        return this.#camera;
    }

    /** A drag by `dx`, `dy` CSS px: a pan, clamped to keep the floor in view (§ 4.5). */
    drag(dx, dy) {
        this.#camera = panBy(this.#camera, dx, dy);

        return this.#camera;
    }

    /**
     * The fit-floor control: the camera framed on the floor's whole extent, overflow strip included.
     * `glide_ms` is how long the page may take to get there — none under `prefers-reduced-motion`,
     * where the camera cuts; the camera's state is the destination from this call on.
     *
     * @returns {{from: object, to: object, glide_ms: number}}
     */
    fitFloor() {
        const from = this.#camera;

        this.#camera = fit(from);

        return { from, to: this.#camera, glide_ms: glideMs(this.#options.reduce === true) };
    }

    /**
     * Open the drill-down on one desk — § 4.3's "opened by selecting a desk". The segment the route
     * carries is the desk's `seat_id` (§ 4.4); the pair is the desk's identity (§ 3.1).
     *
     * @returns {Promise<void>} settled once the panel's two requests have answered
     */
    openPanel(installId, seatId) {
        this.#seatSegment = seatId;
        this.#seatOpened = true;

        return this.#panel.open(installId, seatId);
    }

    /**
     * The route's seat segment changed under the page — the browser's back and forward (§ 4.4's
     * `/floor/{floor}/{seat_id}` is a URL, so history moves it). The desk it names is resolved on the
     * next render exactly as a deep link's is; `null` is the floor alone.
     */
    routeSeat(seatId) {
        this.#panel.close();
        this.#seatSegment = seatId;
        this.#seatOpened = false;
    }

    /** "Closes to the floor" (§ 4.3) — no stream touched, nothing re-fetched. */
    closePanel() {
        this.#seatSegment = null;
        this.#seatOpened = false;
        this.#panel.close();
    }

    /** § 9 F10/F11's "retry on the user's action". */
    retryPanel() {
        return this.#panel.retry();
    }

    /** § 4.3's timeline page: "paginated with `before` on scroll". */
    morePanel() {
        return this.#panel.more();
    }

    /**
     * The open drill-down at the browser's instant — the 1 s tick's panel half (§ 2.5: "every age
     * readout, and nothing else"), read from what the last render left and draining nothing.
     */
    panelView(floorName = null) {
        return this.#panel.view(this.#clock.now(), { floor: floorName });
    }

    /** The desk floor this screen runs — the age ticker's population, and the frame's source. */
    get desks() {
        return this.#desks;
    }

    /**
     * The scene's inputs (Appendix B row 14): `{ box, desk_sprite, measure, character }`. The page
     * supplies them once its imports of the asset route's modules have answered; the harness from
     * its fixture. The next render draws the scene.
     */
    sceneInputs(inputs) {
        this.#sceneInput = inputs;
    }

    /**
     * § 9 F14: the painter reports each asset it could not draw — a tile's image, the desk sprite,
     * a seat's character (`character:<install>/<seat>`), a tileset. Held for the page's life, because
     * F14's recovery is *retry on reload*. An asset failure is not a state change: it moves no desk's
     * `render_state` and writes no animation row (AT-D3-19).
     */
    assetsFailed(ids) {
        for (const id of ids) {
            this.#failed.add(id);
        }
    }

    /** The tilesets held — `url → {tileset}|{failure}|{pending}`. */
    get tilesets() {
        return this.#tilesets?.held ?? new Map();
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
        await this.#loadTilesets();

        return this.draw(journal);
    }

    /**
     * Appendix B row 14's tileset reader, fed: every tileset a held map or the floor's hallway names
     * by `source`, and the desk sprite's — each fetched once (`floor/tileset.js`'s loader).
     */
    async #loadTilesets() {
        const target = this.#target();

        if (this.#sceneInput === null || this.#tilesets === null || target === null) {
            return;
        }

        const docs = [
            target.hallway ?? null,
            ...target.rooms.map((room) => this.#building.room(room.install_id)?.held?.map ?? null),
        ].filter((doc) => doc !== null);
        const urls = docs.flatMap((doc) => (Array.isArray(doc.tilesets) ? doc.tilesets : [])
            .filter((entry) => typeof entry?.source === 'string')
            .map((entry) => tilesetUrl(entry.source)));

        await this.#tilesets.load([tilesetUrl(this.#sceneInput.desk_sprite.tileset), ...urls]);
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
        return heldBody(this.#client.seats.values());
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
        // § 4.3's live patching and the close a removal forces, over the same journal the desks read.
        this.#panel.observe(journal);

        this.#tap.take();

        const frame = this.#drawFrame(journal);
        const capability = capabilityOf(this.#viewport);
        const rows = this.#tap.take();
        // § 4.5: below the viewport floor the route renders the list view — no map, so no scene and
        // nothing framed, and the floor that comes back comes back at fit.
        const scene = capability === 'floor' ? this.#scene(frame, rows) : null;

        // A frame with nothing to draw — no floor composed yet, the art not yet answered, or a floor
        // with nothing measurable on it (no map held and no desk, where the scene's extent is `null`,
        // as `floor-layout.js`'s own extent is: § 4.6 mints no zero-sized box) — leaves the camera as
        // it stands: only the list view unframes it.
        if (capability === 'list') {
            this.#camera = unframe(this.#camera);
        } else if (scene !== null && scene.extent !== null) {
            this.#camera = frameOn(this.#camera, scene.extent);
        }

        const drawn = {
            ...frame,
            // The status strip, on every frame shape — with § 9 F14's line when the scene says art
            // failed (Appendix B row 14: "an edit to step 8's strip module rather than a second strip").
            strip: statusStrip(this.#client.feed, this.#client.fleet, {
                reduce: this.#options.reduce === true,
                // With no scene inputs at all — the page could not import the furniture box — every
                // failure the painter reported is art that could not be drawn.
                art_failed: scene?.art_failed === true || (this.#sceneInput === null && this.#failed.size > 0),
            }),
            scene,
            capability,
            camera: this.#camera,
            // § 9 F21's notices — the scene's, from the maps it drew — beside the floor's own.
            notices: Object.freeze([...frame.notices, ...(scene?.notices ?? [])]),
        };

        return Object.freeze({ ...drawn, ...this.#drillDown(drawn) });
    }

    /** Appendix B row 14's scene over one frame, or `null` with no inputs or no floor to draw. */
    #scene(frame, rows) {
        if (this.#sceneInput === null || frame.floor === null) {
            return null;
        }

        const scene = buildScene(frame, this.#sceneDocs(frame, rows));

        if (scene !== null) {
            this.#anchors = new Map(Object.entries(scene.anchors));
        }

        this.#lastFrame = frame;

        return scene;
    }

    /**
     * The scene at the browser's instant — the 1 s tick's half (§ 2.5: "every age readout, and
     * nothing else"): the last frame's floor with the desks re-read over fresh ages, draining
     * nothing and drawing no § 6.2 row, since a tick is not an apply.
     */
    sceneView(desks) {
        if (this.#sceneInput === null || this.#lastFrame === null || this.#lastFrame.floor === null) {
            return null;
        }

        return buildScene({ ...this.#lastFrame, desks }, this.#sceneDocs(this.#lastFrame, []));
    }

    /** What the scene reads beside a frame: the inputs, the documents held, and what failed. */
    #sceneDocs(frame, effects) {
        return {
            ...this.#sceneInput,
            maps: new Map(frame.rooms.map((room) => [room.install_id, this.#building.room(room.install_id)?.held?.map ?? null])),
            hallway: this.#target()?.hallway ?? null,
            tilesets: this.#tilesets?.held ?? new Map(),
            failed: this.#failed,
            reduce: this.#options.reduce === true,
            effects,
            previous: this.#anchors,
        };
    }

    /**
     * The drill-down half of a frame: the route's `{seat_id}` resolved to a desk on this floor and
     * opened, a panel a removal closed taken off the route, and the panel's model.
     *
     * ⚠ A `seat_id` IS RESOLVED ACROSS EVERY ROOM ON THE FLOOR, AND ONE THAT NAMES DESKS IN TWO ROOMS
     * OPENS NEITHER. § 4.4 reads the seat segment "against the room's `install_id`", and the floor key
     * is the least room's; but the redirect from `/floor/{room}/{seat_id}` keeps the seat and drops the
     * room, so on a floor of several rooms the segment alone can name a desk in a room that is not the
     * key's. Opening the key's room's desk of that name — or the first match — would open a desk the
     * link did not mean; the notice says the segment is ambiguous instead. The resolution is stated at
     * FLOOR § 4.4 (Appendix B step 10).
     */
    #drillDown(frame) {
        const notices = [...frame.notices];

        if (this.#seatSegment !== null && this.#panel.target === null) {
            if (this.#seatOpened) {
                // It was open and it closed without the user: the desk went (§ 3.5, AT-D3-16). The
                // route follows the panel back to the floor.
                this.#seatSegment = null;
                this.#seatOpened = false;
            } else if (frame.floor !== null && this.#client.feed.applied) {
                const rooms = new Set(frame.rooms.map((room) => room.install_id));
                const matches = [...this.#client.seats.values()]
                    .filter((seat) => rooms.has(seat.install_id) && seat.seat_id === this.#seatSegment);

                if (matches.length === 1) {
                    this.#seatOpened = true;
                    this.#panel.open(matches[0].install_id, matches[0].seat_id);
                } else if (matches.length === 0) {
                    // § 4.4's two seat-segment notices — this one and the one below — in the
                    // wording the operator ratified (card#7342, 2026-09-25): an ambiguous segment
                    // opens neither desk "and says so, naming the rooms".
                    notices.push(`no desk ${this.#seatSegment} on this floor`);
                } else {
                    notices.push(`the seat ${this.#seatSegment} names a desk in more than one room on this floor — `
                        + `${matches.map((seat) => seat.install_id).sort().join(', ')}`);
                }
            }
        }

        return {
            seat: this.#seatSegment,
            panel: this.#panel.view(this.#clock.now(), { floor: frame.floor?.name ?? null }),
            notices: Object.freeze(notices),
        };
    }

    /** The floor, drawn over one drained journal — everything but the drill-down (`draw`). */
    #drawFrame(journal) {
        const offset = this.#client.clockOffsetMs;
        const at = correctedNowMs(offset, this.#clock.now());
        const composed = this.#composed();
        const failure = this.#building.layoutFailure;

        // § 9 F6: "ANY read returns 401" — the building surface's two requests included, which go
        // through `wire/building.js` and not through the protocol's own reads.
        if (failure?.status === 401 || this.#roomRefused()) {
            this.#client.readRefused(401);
        }

        // Appendix B row 8: the status strip and the failure renders, on every frame this screen
        // draws — a failure is never allowed to be the one thing a frame shape leaves out.
        // The status strip is `draw()`'s, once the scene has said whether art failed (§ 9 F14).
        const narration = {
            failure: failureRender(this.#client.feed),
        };

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
                ...narration,
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
                ...narration,
            });
        }

        return this.#drawFloor(route.floor, deskFrame, failure, journal, at, narration);
    }

    /**
     * § 6.5's property, and not a list of renders: A17's wall clock and sky are set by a render that
     * establishes or re-establishes a LIVE feed, and by nothing else.
     *
     * A `feed.heartbeat` is what A17 FIRES on (§ 6.2). What SETS the value with no log row at all is
     * the ESTABLISHMENT the protocol journals (`feed.established`): the connect sequence's snapshot,
     * and the first message on a re-opened stream — a successful reconnect. Before either the room has
     * no value and is rendered as having none: "a plausible time on a page that has never been live is
     * exactly the zero that rule refuses".
     *
     * ⛔ A POLL SETS NOTHING (§ 6.5, § 9 F1; AT-D3-6's Fourth RED). A poll's rows are a full snapshot
     * in the journal like any other, and they are deliberately not what is read here: "the poll IS the
     * timer" the amendment removed, so a client that set the room on one would advance the clock every
     * 10 s of a dead feed. Only the protocol can tell a live feed coming back from a read it made
     * because the feed is down, which is why the establishment is its journal line and not a guess
     * from the rows.
     */
    #setRoom(journal) {
        const set = journal.some((entry) => entry.t === 'feed.heartbeat' || entry.t === 'feed.established');

        if (set) {
            this.#tick = roomTick(this.#clock.now(), this.#options.local_time);
        }
    }

    /** One composed floor: its rooms placed, its desks slotted, its band, and its lines. */
    #drawFloor(floor, deskFrame, failure, journal, at, narration) {
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
            ...narration,
        });
    }

    /** Whether any room's last map request was refused `401` (§ 9 F6's *any read*). */
    #roomRefused() {
        const target = this.#target();

        return target !== null && target.rooms.some((room) => this.#building.room(room.install_id)?.failure?.status === 401);
    }

    /** The held seats grouped by their install — each room's own rendered seat set (§ 3.2). */
    #seatsByRoom() {
        return roomsOf(this.#client.seats.values());
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
     * ⛔ AND A RETIREMENT IS THE DEPARTURE SIDE OF THE SAME ROW (§ 3.5, AT-D3-16's collision-chain
     * GREEN; Appendix B step 10). "The desks that shared its collision chain re-probe, because § 3.2's
     * assignment is a pure function of the rendered seat set and that set just changed. The move is
     * A16." The causing message is the ANNOUNCEMENT — the `seat.retired` message or the retiring delta,
     * each journalled as `seat.removed` with a `state_version` behind it — and the backstop's removal
     * (§ 2.3 row 4, journalled with the cause `snapshot`) moves desks WITHOUT a row, because a snapshot
     * animates nothing (§ 6.5). The row's `cause` is the seat-set change recorded as the DEPARTED
     * seat's key — whose freed slot the mover now holds, and in a cascade (the mover took a slot
     * some OTHER mover left) the departure that held the lowest slot: the stated approximation § 11
     * makes for an arrival's cascade, read from the other side. ⚠ The departure half of `cause` is
     * this step's reading — § 11's cell names the ARRIVING seat's key, and a departure has none.
     */
    #displacements(assignments, journal, at) {
        const arrived = new Set(journal
            .filter((entry) => entry.t === 'seat.delta' && entry.outcome === 'applied')
            .map((entry) => `${entry.install_id}/${entry.seat_id}`));
        const announced = new Set(journal
            .filter((entry) => entry.t === 'seat.removed' && entry.cause !== 'snapshot')
            .map((entry) => `${entry.install_id}/${entry.seat_id}`));

        for (const [installId, assignment] of assignments) {
            const before = this.#placed.get(installId) ?? null;

            this.#placed.set(installId, assignment.slots);

            if (before === null) {
                continue;
            }

            // The seats that arrived with a delta this journal carries, in § 3.2's order, and the
            // seats an announcement took off the floor, in the order they were placed.
            const arrivals = assignment.order.filter((key) => !before.has(key) && arrived.has(key));
            const departures = [...before.keys()]
                .filter((key) => !assignment.slots.has(key) && announced.has(key))
                .sort((a, b) => before.get(a) - before.get(b));

            if (arrivals.length === 0 && departures.length === 0) {
                continue;
            }

            // Who holds each slot NOW, so a displaced incumbent's cause can be the seat that took
            // its former slot; and who held each slot BEFORE, so a mover's cause can be the departed
            // seat whose slot it took.
            const holder = new Map([...assignment.slots].map(([key, slot]) => [slot, key]));
            const formerHolder = new Map([...before].map(([key, slot]) => [slot, key]));

            for (const [key, slot] of assignment.slots) {
                if (before.has(key) && before.get(key) !== slot) {
                    const [install_id, seat_id] = splitKey(key);
                    const cause = arrivals.length > 0
                        ? displacementCause(before.get(key), holder, arrivals)
                        : departureCause(slot, formerHolder, departures);

                    this.#set.displaced(install_id, seat_id, cause, at);
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
                truncated: this.#client.coordTruncated,
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

        // § 5.7 / § 14 item 25: the record of animated posts takes no figure of its own — it is
        // trimmed to the `post_ref`s the protocol still holds, so it is bounded by the protocol's
        // cap. The stated cost: a post redelivered after its thread was evicted animates again,
        // because nothing held says it was drawn before.
        const held = new Set(messages
            .filter((m) => m.t === 'coord.round')
            .map((m) => m.coord_round?.post_ref ?? null));

        for (const ref of this.#drawn) {
            if (!held.has(ref)) {
                this.#drawn.delete(ref);
            }
        }

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

/**
 * A16's `cause` for a desk that moved because a seat LEFT (§ 3.5): the departed seat that held the
 * slot the mover now holds, or — when the mover took a slot another mover vacated — the departure
 * that held the lowest slot, which is deterministic because the slots are.
 *
 * @param {number} slot the slot the mover holds now
 * @param {Map<number, string>} formerHolder slot → the key that held it before this render
 * @param {list<string>} departures this render's announced departures, by former slot; never empty
 */
function departureCause(slot, formerHolder, departures) {
    const vacated = formerHolder.get(slot);

    return departures.includes(vacated) ? vacated : departures[0];
}

/**
 * The animation log, TAPPED: every call goes to the one log AT-D3-1 reads, unchanged, and the row it
 * wrote is also kept for the scene, which draws what this render logged (Appendix B row 14: "what
 * this row adds is the drawing of what the set already logged"). It is not a second way into the
 * log — it forwards each call and holds a copy of the row the log itself wrote.
 */
function tapped(log) {
    const taken = [];
    const last = () => {
        const rows = log.rows;

        return rows[rows.length - 1];
    };

    return {
        log: {
            edge(args) {
                log.edge(args);
                taken.push(last());
            },
            enterHeld(args) {
                const id = log.enterHeld(args);

                taken.push(last());

                return id;
            },
            leaveHeld(episodeId, options) {
                log.leaveHeld(episodeId, options);
                taken.push(last());
            },
            get rows() {
                return log.rows;
            },
        },
        take: () => taken.splice(0),
    };
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
 * @param {object} [options] `{ floor, seat, reduce, local_time, viewport, surface }`
 */
export function startFloorScreen(client, fetchImpl, clock, log, draw, options = {}) {
    const screen = new FloorScreen(client, new Building(fetchImpl), clock, log, options, new TilesetLoader(fetchImpl));

    return {
        // The desk floor the screen runs — the 1 s age tick's population (§ 2.5), for a page.
        desks: screen.desks,
        enter: async () => {
            await screen.enter();
        },
        render: async () => {
            draw(await screen.render());
        },
        // The drill-down's user actions (§ 4.3, § 9 F10/F11). Each returns once its requests have
        // answered; the page asks for a render after each, as after any other response.
        openPanel: (installId, seatId) => screen.openPanel(installId, seatId),
        closePanel: () => screen.closePanel(),
        routeSeat: (seatId) => screen.routeSeat(seatId),
        retryPanel: () => screen.retryPanel(),
        morePanel: () => screen.morePanel(),
        panelView: (floorName) => screen.panelView(floorName),
        // Appendix B row 14: the scene's inputs, and the painter's report of what failed to load.
        sceneInputs: (inputs) => screen.sceneInputs(inputs),
        assetsFailed: (ids) => screen.assetsFailed(ids),
        tilesets: () => screen.tilesets,
        sceneView: (desks) => screen.sceneView(desks),
        // Appendix B row 15: the viewport, and the viewer's camera acts. None renders; the page
        // repaints the drawing's view from the camera each returns.
        resize: (viewport, surface) => screen.resize(viewport, surface),
        camera: () => screen.camera,
        wheel: (point, delta) => screen.wheel(point, delta),
        zoomStep: (notches) => screen.zoomStep(notches),
        drag: (dx, dy) => screen.drag(dx, dy),
        fitFloor: () => screen.fitFloor(),
    };
}

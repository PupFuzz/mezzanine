/**
 * THE LOBBY SCREEN — `docs/design/FLOOR.md § 4.1`'s building summary over the client protocol: the drawn room and the
 * floor directory on its wall, each plaque's figures, the fleet totals, the discrepancy check's words, the membership
 * stamp, the three indicators, the feed status with its resync count, the client's event record — and § 4.5's ride
 * from the room's elevator. Appendix B rows 9 and 24 (card#7341 step 9; card#12113).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A MODEL AND NOT A PAGE. Every fact is decided here or in the pure modules it composes, and asserted
 * headlessly through the harness (`tests/Feature/Floor/fleet-client-probe.mjs`); its page is `lobby/main.js`, which
 * draws each frame and decides nothing. The same split `floor/floor-screen.js` keeps, for the same reason: there is no
 * browser on the build host.
 *
 * ⛔ THE LOBBY RENDERS THE PROTOCOL'S POPULATION AND FETCHES NO SNAPSHOT OF ITS OWN. The protocol is the one § 4.1
 * trigger (`FleetClient#check`, one fetch per distinct `(N, M)`, a failed fetch spending nothing, one in flight), and
 * this screen renders the words over the very pair the protocol compares (`discrepancyState()`), so the words and the
 * fetch can never name two different disagreements.
 *
 * ⛔ SILENCE ONCE NO CHECK CAN EVER RUN (§ 4.1). A client that has applied no full snapshot (`feed.applied`) has no
 * population to compare, and such a page already carries § 9's failure render; a disagreement against a client
 * holding nothing would be a count nothing measured.
 *
 * ⛔ THE LAYOUT IS FETCHED AFTER THE SNAPSHOT (§ 4.4's `/` row). The first render that finds a full snapshot applied
 * asks for it, once; a `building.layout` the stream delivers re-fetches it (§ 2.5); a `room.map` is not the lobby's,
 * because a plaque draws no room interior (§ 4.1, D2 § 8.7).
 *
 * ⛔ THE JOURNAL IS DRAINED HERE, on every render. The lobby reads its `building.layout` entries, and its
 * `feed.heartbeat` and `feed.established` entries for the sky — and nothing else on this screen animates.
 *
 * ⭐ THE ROOM's ONE WINDOW CARRIES § 6.2 A17's SKY, ON A17's DRIVER (§ 4.1). Its value is the floor's own —
 * `floor/floor-layout.js`'s `RoomClock`, which sets the phase on a delivered `feed.heartbeat` or the feed's
 * establishment and on nothing else — and its firing is the floor's own, through the one animation set, which this
 * screen constructs drawing A17 alone (`edgeRows`): a heartbeat writes one A17 row, and no delta, retirement, camera
 * act or ride writes any. So the sky follows the VIEWER's clock, steps only on the heartbeat and freezes when the feed
 * dies (§ 9 F1). Under `prefers-reduced-motion` A17's row is logged without motion and the sky steps the same.
 *
 * ⭐ THE CAMERA AT ROOM SCALE (§ 4.5, Appendix B row 24). This screen holds row 15's camera (`../wire/camera.js`) as
 * the floor screen holds it: framing the drawn room (`building-scene.js`'s `lobbyScene()`) from the first frame that
 * draws it — fit, § 6.5's setting and not a move — and keeping the viewer's zoom and pan on every later render. The
 * room is drawn once a full snapshot has applied, with or without a layout (the room carries no fact, § 9 F17).
 * *Fit the lobby* is `fit()`; the keyboard's focus on the elevator or on a plaque's link outside the view brings the
 * camera to the elevator's or the board's rect (`focusOn()`), and moves nothing when it is already in view.
 *
 * ⭐ THE ELEVATOR's RIDE IS THE FLOOR's (§ 4.5, card#12113): `wire/ride.js`'s `Ride`, the one state machine both screens
 * hold, constructed with what is the lobby's own — `endsRide()`, the sign-in prompt alone (the lobby has no redirect
 * and no floor to lose); `currentStop`, the lobby's `null` with *Lobby* as its name; and the stops, § 4.1's plaques of
 * the composed floors (`building-model.js`'s `plates()`), re-derived on every frame — and asked on every frame for
 * `frame.ride` (`rideFrame()`) and `scene.ride` (`sceneRide()`, the leaves and the view behind the cab's doors). A
 * press is accepted only on a frame that drew the room, and the arrival flag `arriveByRide()` records is consumed by
 * the first render that draws it. The page paints `rideView()` after each ride method rather than waiting on a
 * render. ⛔ NO CAB POSITION ANYWHERE: the ride is navigation, which is never state (§ 4.5).
 *
 * ⛔ NAVIGATION IS NEVER STATE (§ 4.5). A ride, a zoom and a pan draw nothing, drain nothing and fetch nothing, and
 * write no animation-log row: the one path to the log on this screen is `render()`'s A17 over a drained journal
 * (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` holds the camera acts to an unchanged log, and the lobby page's
 * whole import graph to its one path; `TheLobbysElevatorIsTheFloorsRideTest` holds the ride's acts to it).
 */

import { Building } from '../wire/building.js';
import { AnimationSet } from '../wire/animation-set.js';
import { correctedNowMs } from '../wire/duration.js';
import { RoomClock } from '../floor/floor-layout.js';
import { createCamera, fit, focusOn, frameOn, glideMs, pan, panBy, pinch, resize, unframe, zoom, zoomStep } from '../wire/camera.js';
import { failureRender } from '../wire/failure-render.js';
import { Ride, LOBBY_STOP } from '../wire/ride.js';
import { statusStrip } from '../floor/status-strip.js';
import { plates } from './building-model.js';
import { lobbyScene } from './building-scene.js';
import { discrepancyNotice, heldBody, lobbyModel } from './lobby-model.js';

/**
 * THE ONE PREDICATE THAT ENDS A LOBBY RIDE (§ 4.5): the sign-in prompt — any read's `401` (§ 9 F6). The lobby has no
 * redirect and no floor to lose, so this is all of it. Handed to the screen's `wire/ride.js` `Ride`, which asks it at
 * the press (the prompt dims the page without making it inert) and on every frame drawn while a ride is in flight.
 */
export function endsRide(frame) {
    return (frame.failure?.sign_in ?? null) !== null;
}

export class LobbyScreen {
    #client;

    #building;

    #clock;

    /** § 6.2 A17's driver — the floor's (`floor-layout.js`'s `RoomClock`) — whose `sky` is drawn in the room's window. */
    #room;

    /** The animation set, drawing A17 alone (`edgeRows`): the lobby's one § 6.2 row. */
    #set;

    /** Whether the entry's layout request (§ 4.4) has been made — after the first applied snapshot. */
    #layoutAsked = false;

    /** The camera (`wire/camera.js`) — the viewer's head, never the fleet's; framed while the room is drawn. */
    #camera;

    /** `prefers-reduced-motion: reduce`, as the page reads it: *Fit the lobby* and a focus cut. */
    #reduce;

    /** The elevator's ride (§ 4.5) — `wire/ride.js`'s state machine, the floor screen's. */
    #ride;

    /** The last frame DRAWN, or `null` — what a press is asked of, what the tick and `rideView()` re-read. */
    #drawn = null;

    /**
     * @param {object} client a `FleetClient`
     * @param {object} building a `wire/building.js` `Building`
     * @param {{now: function(): number}} clock the browser's own clock
     * @param {object} log `wire/animation-log.js`'s `createAnimationLog()`
     * @param {{surface: {width: number, height: number}, reduce?: boolean, local_time?: Function, lobby_href?: string|null}} options
     *        the room's drawing surface in CSS px, whether the viewer prefers reduced motion, the viewer's civil time
     *        for the sky (`floor-screen.js`'s `local_time`, for its reason), and the lobby's own route — the cab's
     *        *Lobby* stop's `href`
     */
    constructor(client, building, clock, log, options) {
        this.#client = client;
        this.#building = building;
        this.#clock = clock;
        this.#camera = createCamera(options.surface);
        this.#reduce = options.reduce === true;
        this.#room = new RoomClock(clock, options.local_time);
        this.#set = new AnimationSet(log, { reduce: this.#reduce, edgeRows: ['A17'] });
        this.#ride = new Ride({
            clock,
            reduce: this.#reduce,
            lobby_href: options.lobby_href ?? null,
            endsRide,
            currentStop: () => ({ current: null, current_name: LOBBY_STOP }),
            stops: () => plates(this.#body(), this.#building.floors),
        });
    }

    /** The camera as it stands — the viewer's head, as data. */
    get camera() {
        return this.#camera;
    }

    /** The seats the protocol holds, in a snapshot body's shape — the one population every figure is read over. */
    #body() {
        return heldBody(this.#client.seats.values(), this.#client.fleet, this.#client.membershipAsOf);
    }

    /**
     * § 2.5's apply path for the lobby: drain the journal, apply any `building.layout` it carried, make the entry's
     * layout request once a snapshot has applied, then draw.
     */
    async render() {
        const journal = this.#client.takeWire();

        for (const entry of journal) {
            if (entry.t === 'building.layout' && await this.#building.applyLayout(entry)) {
                this.#layoutAsked = true;
            }
        }

        if (!this.#layoutAsked && this.#client.feed.applied) {
            this.#layoutAsked = true;
            await this.#building.fetchLayout();
        }

        // § 6.2 A17 over the drained journal — the floor's driver and the floor's firing, read at draw time as the
        // floor reads them (`floor-screen.js`'s `#drawFrame`).
        this.#room.take(journal);
        this.#set.edges(journal, correctedNowMs(this.#client.clockOffsetMs, this.#clock.now()));

        return this.draw();
    }

    /**
     * § 2.3's "lobby's refresh control": one full snapshot through the protocol (`FleetClient#refresh`), then the
     * layout — the request F17 says a failed layout is retried on.
     */
    async refresh() {
        await this.#client.refresh();

        if (this.#client.feed.applied) {
            this.#layoutAsked = true;
            await this.#building.fetchLayout();
        }
    }

    /**
     * The lobby, drawn over what the protocol and the building client hold now — pure but for the `401` it reports
     * (§ 9 F6: "ANY read returns 401", the layout request included), the camera it frames on the room — at fit the
     * first time the room is drawn, kept where the viewer put it on every draw after — and the ride's end rule and
     * arrival, which it asks of the frame it draws.
     *
     * `summary` is `null` until a full snapshot has applied: before it the client holds no population, and drawing the
     * directory — *no installs are provisioned* over a fleet not yet read — would be a calm empty office on a page that
     * is still connecting. The page keeps its *waiting* placeholders, and § 9's failure render says why when a read
     * failed. The room is drawn exactly when there is a summary.
     */
    draw() {
        const client = this.#client;
        const feed = client.feed;
        const layoutFailure = this.#building.layoutFailure;

        if (layoutFailure?.status === 401) {
            client.readRefused(401);
        }

        const state = client.discrepancyState();
        const now = this.#clock.now();
        const room = feed.applied ? lobbyScene() : null;

        // Nothing drawn — no snapshot yet — leaves the camera unframed, and the next framing is a first framing again:
        // at fit.
        this.#camera = room === null ? unframe(this.#camera) : frameOn(this.#camera, room.extent);

        const frame = {
            summary: this.#summary(),
            room,
            camera: this.#camera,
            // § 6.2 A17's sky phase as last set, drawn in the room's window — `null` until a live feed.
            sky: this.#room.tick?.sky ?? null,
            // § 4.1's words over the protocol's own pair — and silence while no check can run.
            discrepancy: feed.applied && state !== null ? discrepancyNotice(state.held, state.total) : null,
            strip: statusStrip(feed, client.fleet),
            failure: failureRender(client.feed),
            event_log: client.eventLog,
        };

        // The ride's end rule (§ 4.5): a frame that ends a ride carries none.
        this.#ride.observe(frame);

        // § 4.5: the arrival flag's one consumer is the first render that draws the room — this one.
        if (room !== null) {
            this.#ride.arrive(frame, now);
        }

        this.#drawn = this.#withRide(frame, now);

        return this.#drawn;
    }

    /** The directory and the building's figures at the corrected clock now — `null` before a snapshot has applied. */
    #summary() {
        const client = this.#client;

        return client.feed.applied
            ? lobbyModel(this.#body(), this.#building.floors, this.#building.layoutFailure, correctedNowMs(client.clockOffsetMs, this.#clock.now()))
            : null;
    }

    /** A frame with the ride as it stands at `now`: `frame.ride` and the room's `scene.ride` (§ 4.5). */
    #withRide(frame, now) {
        return Object.freeze({
            ...frame,
            ride: this.#ride.rideFrame(now),
            scene: frame.room === null ? null : Object.freeze({ ...frame.room, ride: this.#ride.sceneRide(now) }),
        });
    }

    /**
     * § 2.5's 1 s tick: the last drawn frame with the directory's ages and its long-call threshold re-read at the
     * corrected clock now — draining nothing, drawing no § 6.2 row and moving no camera, since a tick is not an apply.
     * `null` with nothing drawn yet.
     */
    tickView() {
        if (this.#drawn === null) {
            return null;
        }

        this.#drawn = this.#withRide({ ...this.#drawn, summary: this.#summary() }, this.#clock.now());

        return this.#drawn;
    }

    /**
     * The last drawn frame with the ride as it stands now — what the page paints after a ride method, with no render
     * and no await between the act and its paint. It needs no end rule of its own: `ride()` refuses on a last drawn
     * frame that ends a ride and `draw()` ends the ride on one, so no ride stands over such a frame.
     */
    rideView() {
        if (this.#drawn === null) {
            return null;
        }

        this.#drawn = this.#withRide(this.#drawn, this.#clock.now());

        return this.#drawn;
    }

    /**
     * THE ELEVATOR's RIDE (§ 4.5): the press on the drawn elevator, accepted only on a frame that drew the room; the
     * rest of the press's guard — no ride in flight, and no `endsRide()` holding of the frame — is `wire/ride.js`'s
     * `press()`. Every ride method is synchronous, answers `null` out of phase and `{ phase }` otherwise; the page
     * paints `rideView()` after each one that answered. Each below is `wire/ride.js`'s method of the same name.
     *
     * @returns {{phase: string|null}|null}
     */
    ride() {
        const last = this.#drawn;

        if (last === null || last.room === null) {
            return null;
        }

        return this.#ride.press(last);
    }

    entered() {
        return this.#ride.entered();
    }

    shut() {
        return this.#ride.shut();
    }

    choose(key) {
        return this.#ride.choose(key);
    }

    opened() {
        return this.#ride.opened();
    }

    abandoned() {
        return this.#ride.abandoned();
    }

    arrived() {
        return this.#ride.arrived();
    }

    returned() {
        return this.#ride.returned();
    }

    cabClosed() {
        return this.#ride.cabClosed();
    }

    escape(cancelable) {
        return this.#ride.escape(cancelable);
    }

    /**
     * This document was entered by a ride (the layout's head script read it off the navigation; § 4.5): the first
     * room drawn before `expires_in_ms` has passed enters `arriving`. Accepted once, before any render.
     */
    arriveByRide(arrival) {
        if (this.#drawn !== null) {
            return null;
        }

        return this.#ride.arriveByRide(arrival);
    }

    /**
     * *Fit the lobby*: the camera framed on the whole room. `glide_ms` is how long the page may take to get there —
     * none under `prefers-reduced-motion`, where the camera cuts.
     *
     * @returns {{from: object, to: object, glide_ms: number}}
     */
    fitLobby() {
        const from = this.#camera;

        this.#camera = fit(from);

        return { from, to: this.#camera, glide_ms: glideMs(this.#reduce) };
    }

    /**
     * The keyboard's focus on the elevator: the camera brought to the elevator's rect when it is not wholly in view;
     * `null` — and nothing moves — when it is, or with no room drawn.
     */
    focusElevator() {
        return this.#focus('elevator');
    }

    /**
     * The keyboard's focus on a plaque's link: the camera brought to the board's rect when it is not wholly in view
     * (the browser's own scroll-into-view scrolls the directory to the plaque); `null` when it is, or with no room.
     */
    focusBoard() {
        return this.#focus('board');
    }

    /** The camera to `part`'s rect of the drawn room, or `null` when it is already in view or nothing is drawn. */
    #focus(part) {
        const rect = this.#drawn?.room?.[part] ?? null;
        const from = this.#camera;

        if (rect === null || within(from.view, rect)) {
            return null;
        }

        this.#camera = focusOn(from, rect);

        return { from, to: this.#camera, glide_ms: glideMs(this.#reduce) };
    }

    /**
     * A plain wheel event — a pan, `wire/camera.js`'s `pan()`, which leaves a wheel at the room's edge to the page's
     * scroll. Renders nothing.
     *
     * @returns {{camera: object, consumed: boolean}}
     */
    pan(delta) {
        const { camera, consumed } = pan(this.#camera, delta);

        this.#camera = camera;

        return { camera, consumed };
    }

    /**
     * A Ctrl+wheel event at a point on the surface — a zoom, `wire/camera.js`'s `zoom()`. Renders nothing.
     *
     * @returns {{camera: object, consumed: boolean}}
     */
    zoom(point, delta) {
        const { camera, consumed } = zoom(this.#camera, point, delta);

        this.#camera = camera;

        return { camera, consumed };
    }

    /** One step of a touch screen's two-finger pinch — `wire/camera.js`'s `pinch()`. Renders nothing. */
    pinch(from, to, factor) {
        this.#camera = pinch(this.#camera, from, to, factor);

        return this.#camera;
    }

    /** `notches` wheel notches about the surface's centre — the keyboard's zoom and the zoom buttons'. Renders nothing. */
    zoomStep(notches) {
        this.#camera = zoomStep(this.#camera, notches);

        return this.#camera;
    }

    /** A drag by `dx`, `dy` CSS px — the pointer's, or an arrow key's. Renders nothing. */
    drag(dx, dy) {
        this.#camera = panBy(this.#camera, dx, dy);

        return this.#camera;
    }

    /** The room's drawing surface changed size; a camera still at fit stays at fit. */
    resize(surface) {
        this.#camera = resize(this.#camera, surface);

        return this.#camera;
    }
}

/**
 * Whether `inner` lies wholly inside `outer`, both scene rects — to within a millionth of a scene px, so the arithmetic
 * of a camera already on the rect never reads as a rect out of view.
 */
function within(outer, inner) {
    const e = 1e-6;

    return inner.x >= outer.x - e && inner.y >= outer.y - e
        && inner.x + inner.w <= outer.x + outer.w + e && inner.y + inner.h <= outer.y + outer.h + e;
}

/**
 * The lobby, started — the shape its page and the harness both drive.
 *
 * @param {object} client a `FleetClient`
 * @param {Function} fetchImpl the page's `fetch` (`wire/live-page.js`) — `wire/building.js`'s injection
 * @param {{now: function(): number}} clock the browser's own clock
 * @param {object} log `wire/animation-log.js`'s `createAnimationLog()` — the page's, bounded (`wire/live-page.js`)
 * @param {function(object): void} draw receives each lobby frame
 * @param {object} options `LobbyScreen`'s
 */
export function startLobbyScreen(client, fetchImpl, clock, log, draw, options) {
    const screen = new LobbyScreen(client, new Building(fetchImpl), clock, log, options);

    return {
        render: async () => {
            draw(await screen.render());
        },
        refresh: async () => {
            await screen.refresh();
        },
        // § 2.5's 1 s tick and the ride's paint: the last frame re-read, nothing drained (each `null` before a draw).
        tickView: () => screen.tickView(),
        rideView: () => screen.rideView(),
        // § 4.5's elevator ride: each synchronous, each answering `null` out of phase; the page paints `rideView()`
        // after each that answered.
        ride: () => screen.ride(),
        entered: () => screen.entered(),
        shut: () => screen.shut(),
        choose: (key) => screen.choose(key),
        opened: () => screen.opened(),
        abandoned: () => screen.abandoned(),
        arrived: () => screen.arrived(),
        returned: () => screen.returned(),
        cabClosed: () => screen.cabClosed(),
        escape: (cancelable) => screen.escape(cancelable),
        arriveByRide: (arrival) => screen.arriveByRide(arrival),
        // Appendix B row 24: the viewer's camera at room scale. None renders; the page shows the camera each returns.
        fitLobby: () => screen.fitLobby(),
        focusElevator: () => screen.focusElevator(),
        focusBoard: () => screen.focusBoard(),
        pan: (delta) => screen.pan(delta),
        zoom: (point, delta) => screen.zoom(point, delta),
        pinch: (from, to, factor) => screen.pinch(from, to, factor),
        zoomStep: (notches) => screen.zoomStep(notches),
        drag: (dx, dy) => screen.drag(dx, dy),
        resize: (surface) => screen.resize(surface),
        camera: () => screen.camera,
    };
}

/**
 * THE LOBBY SCREEN — `docs/design/FLOOR.md § 4.1`'s building summary over the client protocol:
 * the stacked floors and their per-floor summaries, the fleet totals, the discrepancy check's words,
 * the membership stamp, the three indicators, the feed status with its resync count, and the
 * client's event record. Appendix B row 9, card#7341 step 9.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A MODEL AND NOT A PAGE. Every fact is decided here or in the pure modules it composes,
 * and asserted headlessly through the harness (`tests/Feature/Floor/fleet-client-probe.mjs`) and
 * the lobby's own probe; its page is `lobby/main.js`, which draws each frame and decides nothing.
 * The same split `floor/floor-screen.js` keeps, for the same reason: there is no browser on the
 * build host.
 *
 * ⛔ THE LOBBY RENDERS THE PROTOCOL'S POPULATION AND FETCHES NO SNAPSHOT OF ITS OWN. Until step 9
 * the lobby read one snapshot body per entry and ran its own § 4.1 trigger over it; a lobby that
 * constructed the protocol beside that trigger would have had two triggers and two budgets, and one
 * disagreement would have cost two requests. Now the protocol is the one trigger (`FleetClient#check`, one fetch per distinct `(N, M)`, a failed fetch
 * spending nothing, one in flight), and this screen renders the words over the very pair the
 * protocol compares (`discrepancyState()`), so the words and the fetch can never name two
 * different disagreements.
 *
 * ⛔ SILENCE ONCE NO CHECK CAN EVER RUN (§ 4.1). A client that has applied no full snapshot
 * (`feed.applied`, which is `phase === 'live'` once started) has no population to compare — before its first snapshot, or after that snapshot failed and before the recovery's next
 * cold read succeeds — and such a page already carries § 9's failure render; a disagreement against
 * a client holding nothing would be a count nothing measured. Neither ratified sentence claims a
 * refresh, so the one-check-already-ran case needs no string of its own: the same words stand.
 *
 * ⛔ THE LAYOUT IS FETCHED AFTER THE SNAPSHOT (§ 4.4's `/` row: "`GET /api/fleet/snapshot`, then
 * `GET /api/building`"). The first render that finds a full snapshot applied asks for it, once; a
 * refused cold read asks for none, and a cold retry that later succeeds asks then. A `building.layout`
 * the stream delivers re-fetches it (§ 2.5: "`building.layout` applied — the lobby's stack"); a
 * `room.map` is not the lobby's, because a plate draws no room interior (§ 4.1, D2 § 8.7: "a client
 * applies a `room.map` only for a room it renders").
 *
 * ⛔ THE JOURNAL IS DRAINED HERE, on every render. The protocol keeps a wire journal a renderer drains
 * (`FleetClient#takeWire`); a page that never drained it would grow it without bound. The lobby reads
 * only its `building.layout` entries — nothing on this screen animates (§ 6.5, and § 4.1's plates
 * carry no § 6.2 row).
 *
 * ⭐ THE CAMERA AT BUILDING SCALE AND THE RIDE'S ARRIVAL — Appendix B row 16, slice A (card#7343). This
 * screen holds row 15's camera (`../wire/camera.js`) as `floor/floor-screen.js` holds it on the floor:
 * its second caller, framing every plate of the building (`building-scene.js`) where the floor screen
 * frames the floor. The first framing fits — whole-building, § 6.5's setting and not a move — and every
 * later render keeps the viewer's zoom and pan and only re-clamps them. The whole-building control is
 * the fit; zoom-to-a-plate is the ride: `ride()` names the elevator's next stop — the cab the page then
 * moves there, since the cab is the viewer's and not the model's — zooms the camera to that plate and
 * hands back the route the page arrives at, `/floor/{key}` — the plate's own `href`, the KEY and never
 * the label (§ 4.4, card#9273) — which row 8 serves and which deep-links on a cold start like any other
 * visit to it.
 *
 * ⛔ THE CLICK COMMITS THE RIDE, AND THE HOLD PROTECTS THE GLIDE (card#7343 r1 ruling; r2-4, the seat's
 * ruling; Appendix B row 16). From `ride()` until the page's glide has arrived and the page has asked
 * for the ride's route — `returned()`, which the page also calls when the back-forward cache restores
 * it — a ride is IN FLIGHT: the frame says so (`riding`, which disables the ride control), a second
 * `ride()` is refused, and the wheel, the keys, the zoom buttons, the drag, the whole-building control
 * and a focused plate leave the camera on the plate. The page's glide is committed
 * (`wire/camera-view.js`), so any of them cuts it to the plate and it arrives. Once it has arrived the
 * hold is over: a navigation the browser then cancels or never completes leaves a lobby whose controls
 * work, never one held for good.
 *
 * ⛔ A FOCUSED PLATE IS BROUGHT INTO VIEW (card#7343 r2-2). The plates are links the keyboard reaches;
 * a plate the viewer tabs to outside the view is one they cannot see they are on, and the drawing's
 * clip holds its scroll at the origin (`lobby/main.js`'s `unscroll()`), so the browser's own
 * scroll-into-view cannot bring it. `focusPlate()` brings the camera to it — row 15's `focusOn()`, the
 * ride's own zoom — and leaves a plate already wholly in view where it is, so tabbing through a building
 * at fit moves nothing.
 *
 * ⛔ NAVIGATION IS NEVER STATE (§ 4.5, § 4.6's elevator row). A ride, a zoom and a pan draw nothing,
 * drain nothing and fetch nothing; this screen holds no animation log and loads nothing that could write
 * one or start anything through the animation set (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`
 * holds the lobby page's whole import graph to that). A glide is the viewer's, and under
 * `prefers-reduced-motion` it is a cut: `glide_ms` is `0`, for the cab and the camera alike.
 */

import { Building } from '../wire/building.js';
import { createCamera, fit, focusOn, frameOn, glideMs, panBy, resize, unframe, wheel, zoomStep } from '../wire/camera.js';
import { failureRender } from '../wire/failure-render.js';
import { statusStrip } from '../floor/status-strip.js';
import { buildingModel } from './building-model.js';
import { buildingScene } from './building-scene.js';
import { discrepancyNotice, heldBody, lobbyModel } from './lobby-model.js';

export class LobbyScreen {
    #client;

    #building;

    /** Whether the entry's layout request (§ 4.4) has been made — after the first applied snapshot. */
    #layoutAsked = false;

    /** The camera (`wire/camera.js`) — the viewer's head, never the fleet's; framed while a plate is drawn. */
    #camera;

    /** `prefers-reduced-motion: reduce`, as the page reads it: a ride and the whole-building control cut. */
    #reduce;

    /** The ride in flight, from its click until its glide has arrived (`returned()`); `null` when none. */
    #riding = null;

    /**
     * The last frame DRAWN — the building the plates and the ride control were drawn from, and its scene.
     * A ride reads it rather than composing a second building, so the ride goes where the control said.
     */
    #drawn = null;

    /**
     * @param {object} client a `FleetClient`
     * @param {object} building a `wire/building.js` `Building`
     * @param {{surface: {width: number, height: number}, reduce?: boolean}} options the building's drawing
     *        surface in CSS px, and whether the viewer prefers reduced motion
     */
    constructor(client, building, options) {
        this.#client = client;
        this.#building = building;
        this.#camera = createCamera(options.surface);
        this.#reduce = options.reduce === true;
    }

    /** The camera as it stands — the viewer's head, as data. */
    get camera() {
        return this.#camera;
    }

    /**
     * § 2.5's apply path for the lobby: drain the journal, apply any `building.layout` it carried,
     * make the entry's layout request once a snapshot has applied, then draw.
     *
     * @param {string|null} cab the viewer's elevator stop (§ 4.5: navigation is never state)
     */
    async render(cab) {
        for (const entry of this.#client.takeWire()) {
            if (entry.t === 'building.layout' && await this.#building.applyLayout(entry)) {
                this.#layoutAsked = true;
            }
        }

        if (!this.#layoutAsked && this.#client.feed.applied) {
            this.#layoutAsked = true;
            await this.#building.fetchLayout();
        }

        return this.draw(cab);
    }

    /**
     * § 2.3's "lobby's refresh control": one full snapshot through the protocol (`FleetClient#refresh`),
     * then the layout — the request F17 says a failed layout is retried on.
     */
    async refresh() {
        await this.#client.refresh();

        if (this.#client.feed.applied) {
            this.#layoutAsked = true;
            await this.#building.fetchLayout();
        }
    }

    /**
     * The lobby, drawn over what the protocol and the building client hold now — pure but for the
     * `401` it reports (§ 9 F6: "ANY read returns 401", the layout request included) and the camera it
     * frames on the building's extent: at fit the first time there is one, and kept where the viewer
     * put it on every draw after (row 15: a camera move "survives every re-render").
     *
     * `summary` is `null` until a full snapshot has applied: before it the client holds no population,
     * and drawing the building — *no installs are provisioned* over a fleet not yet read — would be a
     * calm empty office on a page that is still connecting. The page keeps its *waiting* placeholders,
     * and § 9's failure render says why when a read failed.
     *
     * @param {string|null} cab the viewer's elevator stop
     */
    draw(cab) {
        const client = this.#client;
        const feed = client.feed;
        const layoutFailure = this.#building.layoutFailure;

        if (layoutFailure?.status === 401) {
            client.readRefused(401);
        }

        const state = client.discrepancyState();
        const body = heldBody(client.seats.values(), client.fleet, client.membershipAsOf);

        const building = feed.applied ? buildingModel(body, cab, this.#building.floors) : null;
        const scene = building?.composed === true ? buildingScene(building.plates) : null;

        // Nothing to frame — no snapshot yet, no layout held (§ 9 F17), or no plate — leaves the camera
        // unframed, and the next framing is a first framing again: at fit.
        this.#camera = scene === null || scene.extent === null
            ? unframe(this.#camera)
            : frameOn(this.#camera, scene.extent);

        this.#drawn = Object.freeze({
            summary: feed.applied ? lobbyModel(body, this.#building.floors, layoutFailure) : null,
            building,
            scene,
            camera: this.#camera,
            // A ride in flight: the page disables the ride control until it has arrived.
            riding: this.#riding !== null,
            // § 4.1's words over the protocol's own pair — and silence while no check can run.
            discrepancy: feed.applied && state !== null ? discrepancyNotice(state.held, state.total) : null,
            strip: statusStrip(feed, client.fleet),
            failure: failureRender(client.feed),
            event_log: client.eventLog,
        });

        return this.#drawn;
    }

    /**
     * The elevator's ride to its next stop — the one the last drawn ride control offered — and its
     * arrival: the stop the page moves the cab to, the camera zoomed to that plate, and the route the
     * page arrives at. `null` when there is nowhere to ride (`building-model.js`'s `NO_STOPS` /
     * `ONE_STOP`, or no building drawn yet) or while a ride is already in flight, and then nothing moves.
     *
     * ⛔ THE REFUSAL IS RE-ASKED OF THE DRAWN MODEL RATHER THAN READ OFF THE BUTTON. Before the first
     * snapshot lands there is no building to ride, and a ride to nowhere would put the cab on `null`,
     * which resolves to the first plate and reads as a ride the viewer never took.
     *
     * ⛔ THE ROUTE IS THE PLATE's `href` — `lobby-model.js`'s `/floor/{key}`, D3's own route — and never
     * one minted here from the plate's `name`: a label is edited freely, and a link built from it would
     * not resolve (§ 4.4's ⛔, card#9273). The plate is the one `elevator().next` names, found by that key
     * — the key the cab is moved to — and its `level` is its index in both the stack and the scene.
     *
     * @returns {{cab: string, route: string, from: object, to: object, glide_ms: number}|null}
     */
    ride() {
        const building = this.#drawn?.building ?? null;
        const next = building === null ? null : building.elevator.next;

        if (next === null || this.#riding !== null) {
            return null;
        }

        const plate = building.plates.find((p) => p.floor === next);
        const from = this.#camera;

        this.#camera = focusOn(from, this.#drawn.scene.plates[plate.level].rect);
        this.#riding = { cab: plate.floor, route: plate.href, from, to: this.#camera, glide_ms: glideMs(this.#reduce) };

        return this.#riding;
    }

    /**
     * The ride is over: its glide has arrived and the page has asked for its route, or the page is back
     * from the back-forward cache. The viewer may ride — and move the camera — again; the camera stays
     * where the ride left it.
     */
    returned() {
        this.#riding = null;
    }

    /**
     * The keyboard's focus on a plate: the camera brought to that plate (`focusOn()`, the ride's zoom)
     * when the plate is not wholly in view. `null` — and nothing moves — when it already is, when no
     * drawn plate has that key, or while a ride is in flight. `glide_ms` as the whole-building control's.
     *
     * @param {string} floor the focused plate's key
     * @returns {{from: object, to: object, glide_ms: number}|null}
     */
    focusPlate(floor) {
        const plate = this.#drawn?.scene?.plates.find((p) => p.floor === floor) ?? null;
        const from = this.#camera;

        if (plate === null || this.#riding !== null || within(from.view, plate.rect)) {
            return null;
        }

        this.#camera = focusOn(from, plate.rect);

        return { from, to: this.#camera, glide_ms: glideMs(this.#reduce) };
    }

    /**
     * The whole-building control: the camera framed on every plate. `glide_ms` is how long the page may
     * take to get there — none under `prefers-reduced-motion`, where the camera cuts.
     *
     * @returns {{from: object, to: object, glide_ms: number}}
     */
    wholeBuilding() {
        const from = this.#camera;

        // A ride in flight keeps the camera on its plate; the page's committed glide arrives instead.
        this.#camera = this.#riding === null ? fit(from) : from;

        return { from, to: this.#camera, glide_ms: glideMs(this.#reduce) };
    }

    /**
     * One wheel event at a point on the surface — `wire/camera.js`'s `wheel()`. Renders nothing, and
     * moves nothing while a ride is in flight.
     */
    wheel(point, delta) {
        this.#camera = this.#riding === null ? wheel(this.#camera, point, delta) : this.#camera;

        return this.#camera;
    }

    /**
     * `notches` wheel notches about the surface's centre — the keyboard's zoom and the zoom buttons'
     * (`wire/camera.js`'s `zoomStep()`). Renders nothing, and moves nothing while a ride is in flight.
     */
    zoomStep(notches) {
        this.#camera = this.#riding === null ? zoomStep(this.#camera, notches) : this.#camera;

        return this.#camera;
    }

    /** A drag by `dx`, `dy` CSS px — the pointer's, or an arrow key's. Renders nothing, and moves nothing while a ride is in flight. */
    drag(dx, dy) {
        this.#camera = this.#riding === null ? panBy(this.#camera, dx, dy) : this.#camera;

        return this.#camera;
    }

    /** The building's drawing surface changed size; a camera still at fit stays at fit. */
    resize(surface) {
        this.#camera = resize(this.#camera, surface);

        return this.#camera;
    }
}

/**
 * Whether `inner` lies wholly inside `outer`, both scene rects — to within a millionth of a scene px, so
 * the arithmetic of a camera already on the plate never reads as a plate out of view.
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
 * @param {Function} fetchImpl the browser's own `fetch`, unbound — `wire/building.js`'s injection
 * @param {function(object): void} draw receives each lobby frame
 * @param {{surface: {width: number, height: number}, reduce?: boolean}} options `LobbyScreen`'s
 * @returns {{render: function(string|null): Promise<void>, refresh: function(): Promise<void>, draw: function(string|null): void,
 *            ride: function(): object|null, returned: function(): void, wholeBuilding: function(): object,
 *            focusPlate: function(string): object|null, wheel: Function, zoomStep: Function, drag: Function,
 *            resize: Function, camera: function(): object}}
 */
export function startLobbyScreen(client, fetchImpl, draw, options) {
    const screen = new LobbyScreen(client, new Building(fetchImpl), options);

    return {
        render: async (cab = null) => {
            draw(await screen.render(cab));
        },
        refresh: async () => {
            await screen.refresh();
        },
        // A ride's cab re-draws from what is held and drains nothing.
        draw: (cab = null) => {
            draw(screen.draw(cab));
        },
        // Appendix B row 16: the viewer's camera at building scale, and the ride. None renders; the page
        // shows the camera each returns, and a ride's arrival is the page's route change.
        ride: () => screen.ride(),
        returned: () => screen.returned(),
        wholeBuilding: () => screen.wholeBuilding(),
        focusPlate: (floor) => screen.focusPlate(floor),
        wheel: (point, delta) => screen.wheel(point, delta),
        zoomStep: (notches) => screen.zoomStep(notches),
        drag: (dx, dy) => screen.drag(dx, dy),
        resize: (surface) => screen.resize(surface),
        camera: () => screen.camera,
    };
}

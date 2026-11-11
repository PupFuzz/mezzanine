/**
 * THE BUILDING SCREEN — `docs/design/FLOOR.md § 4`'s building view over the client protocol: every floor the directory
 * lists composed and drawn as the floor page composes and draws it, each floor's desk labels, and the stack those floors
 * stand in. Appendix B row 47 (card#13033 slice 3; the plan is `docs/design/plan-13033-3d-view.md` § 4's
 * `building-screen.js` row).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A MODEL AND NOT A PAGE, for the floor screen's reason: every fact is decided here and asserted headlessly
 * through the harness (`tests/Feature/Floor/fleet-client-probe.mjs`'s `building` entry). Its page draws each frame and
 * decides nothing.
 *
 * ⛔ THE JOURNAL IS DRAINED HERE, ONCE PER RENDER, and the one desk floor is handed what was drained (`renderWith()`):
 * a building of N floors is one client, one journal and one consumer — never a floor screen per floor, which would drain
 * it N times. The drain also carries the layout acts: a `building.layout` re-reads the layout, and a `room.map` is applied
 * for every room this screen renders — every room of every floor (D2 § 8.7) — with its line written into the client's
 * record and that ONE room's tilesets loaded (`FloorData#roomTilesets()`), never the whole prefetch again, which would
 * re-request every room whose last request failed.
 *
 * ⛔ THE FLOOR IS THE FLOOR PAGE's, COMPOSED BY THE SAME CODE: `floor/floor-compose.js`'s `composeFloor()` for the rooms
 * and desks, its `sceneInput()` for what `floor/scene.js`'s `buildScene()` reads, and `buildScene()` itself — so a slab
 * shows the floor the floor page shows. Each scene is a STILL: drawn under `reduce`, with no effect in flight and no
 * previous anchors, and the desk floor runs under `reduce` too (plan § 3.2: a texture's walk would be motion with no
 * visible driver).
 *
 * ⛔ A PICTURE AND NO FACT (§ 4.1's rule, on a third screen). The frame carries no camera — the view is the page's — no
 * ride, no panel, and no figure of its own: a floor's notices are the floor page's notices for that floor, a label is a
 * desk's nameplate text and its links, and the stack reads a row's key, level, name and link and the scene's extent.
 *
 * ⛔ NAVIGATION IS NEVER STATE (§ 4.5): the one path from this screen to the animation log is the desk floor's set, over
 * the journal this render drained.
 */

import { Building } from '../wire/building.js';
import { DeskFloor } from '../desk/desk-floor.js';
import { failureRender } from '../wire/failure-render.js';
import { heldBody, roomsOf } from '../lobby/lobby-model.js';
import { plates } from '../lobby/building-model.js';
import { RoomClock } from '../floor/floor-layout.js';
import { composeFloor, layoutFailureStatement, roomMaps, sceneInput } from '../floor/floor-compose.js';
import { buildScene } from '../floor/scene.js';
import { FloorData } from '../floor/floor-data.js';
import { TilesetLoader } from '../floor/tileset.js';
import { statusStrip } from '../floor/status-strip.js';
import { stackModel } from './stack-model.js';

/**
 * § 7's statement when the browser offers no WebGL 2 context — the page's list is then the whole page. A constant here
 * because text is a decision; whether the context exists is the page's own read of the browser.
 */
export const NO_WEBGL = 'this browser offers no WebGL 2, so the building is not drawn; the floors are listed below';

/**
 * What the stage shows (§ 7) — a decision, so it is made here and driven headlessly:
 *  · `list` without WebGL 2: the floors' list in flow is the page;
 *  · `stage` once a stack stands: the canvas, the list docked over it as its labels;
 *  · `drawing` while the first stack is still coming — the snapshot, the layout or the art not yet answered: the page's
 *    placeholder in the stage, the list visually hidden and still in the accessibility tree, so a viewer never reads the
 *    bare list for the seconds the first frame takes. The page shows the phase as it stands a stack, after that stack's
 *    pictures are rasterised, so the placeholder usually covers the first rasterisation (a resize during that first
 *    paint can stand the stack before its pictures, showing bare slabs until they land);
 *  · `list` again when everything has answered and there is still no stack to stand — a failed layout, no floor with an
 *    extent, a stage collapsed to no size. A snapshot that never arrives leaves the phase `drawing`; the feed's
 *    failure banner is what says so.
 *
 * @param {{webgl: boolean, art: boolean, frame: object|null, stack: object|null}} state whether the page holds a WebGL 2
 *        paint; whether the art modules have answered (`loadArt()`); the last building frame, `null` before the first; and
 *        the stack as stood
 * @returns {'drawing'|'stage'|'list'}
 */
export function stagePhase({ webgl, art, frame, stack }) {
    if (!webgl) {
        return 'list';
    }

    if (stack !== null) {
        return 'stage';
    }

    return frame === null || frame.layout === 'pending' || !art ? 'drawing' : 'list';
}

/** The ground slab's entry in the floors' list: the lobby, by the lobby page's own heading. */
export const GROUND_NAME = 'Lobby';

/**
 * Each desk's label (§ 3.4): the desk's nameplate element's text — the floor page's own fitted string — its rect's
 * centre in scene px, and the link to its drill-down, `/floor/{key}/{seat_id}` by the floor's KEY. A desk with no
 * nameplate element has no label.
 *
 * @param {object|null} scene `buildScene()`'s answer for the floor
 * @param {{href: string}} row the floor's directory row — its `href` is the floor's route by its key
 * @returns {list<{key: string, install_id: string, seat_id: string, text: string, at: {x: number, y: number}, href: string}>}
 */
export function deskLabels(scene, row) {
    return (scene?.desks ?? []).flatMap((desk) => {
        const plate = desk.elements.find((e) => e.kind === 'nameplate' && e.member === 'nameplate');

        return plate === undefined ? [] : [Object.freeze({
            key: desk.key,
            install_id: desk.install_id,
            seat_id: desk.seat_id,
            text: plate.text,
            at: Object.freeze({ x: plate.x + plate.w / 2, y: plate.y + plate.h / 2 }),
            href: `${row.href}/${encodeURIComponent(desk.seat_id)}`,
        })];
    });
}

export class BuildingScreen {
    #client;

    #building;

    /** The one desk floor, over every held seat, under `reduce`. */
    #desks;

    /** The rows' rooms, their maps' tilesets — fetched on entry and on each layout change. */
    #data;

    #tilesets;

    /** § 4.2's wall clock and sky, read into every floor's band — a still's, as the floor page's would be at this render. */
    #room;

    /** `prefers-reduced-motion`, as the page reads it — the status strip's. The scenes are stills whatever it says. */
    #reduce;

    #lobbyHref;

    #surface;

    /** Whether the layout request has been made — after the first applied snapshot, as the lobby asks it. */
    #layoutAsked = false;

    /** The layout version the rows were last prefetched for, or `undefined` before the first. */
    #prefetched;

    /** `floor-compose.js`'s `artInputs()` — the art no module may import — or `null` until the page supplies it. */
    #art = null;

    /** The asset ids every floor's painter reported failed (§ 9 F14). Never cleared: *retry on reload*. */
    #failed = new Set();

    /** The last draw's directory rows and each floor's extent — what a resize re-stacks without a render. */
    #drawn = { rows: null, extents: new Map() };

    /**
     * @param {object} client a `FleetClient`
     * @param {object} building a `wire/building.js` `Building`
     * @param {{now: function(): number}} clock the browser's own clock
     * @param {object} log `wire/animation-log.js`'s `createAnimationLog()`
     * @param {{surface: {width: number, height: number}, reduce?: boolean, local_time?: Function, lobby_href?: string|null,
     *          wake?: function(): void}} options the stage in CSS px; the viewer's reduced motion; the viewer's civil time for
     *        the bands' clocks (`floor-screen.js`'s `local_time`, for its reason); the lobby's route — the ground slab's link;
     *        and the page's render request, asked as each prefetch step lands
     * @param {object} tilesets a `floor/tileset.js` `TilesetLoader`
     */
    constructor(client, building, clock, log, options, tilesets) {
        this.#client = client;
        this.#building = building;
        this.#tilesets = tilesets;
        this.#reduce = options.reduce === true;
        this.#lobbyHref = options.lobby_href ?? null;
        this.#surface = options.surface;
        this.#desks = new DeskFloor(client, clock, log, { reduce: true });
        this.#room = new RoomClock(clock, options.local_time);
        this.#data = new FloorData({ building, tilesets, rows: () => this.#rows(), wake: options.wake });
    }

    /** The art inputs (`floor-compose.js`'s `artInputs()`); the next render draws every floor's scene. */
    sceneInputs(inputs) {
        this.#art = inputs;
    }

    /** § 9 F14: assets a floor's painter, or the page's art import, could not draw. */
    assetsFailed(ids) {
        for (const id of ids) {
            this.#failed.add(id);
        }
    }

    /**
     * The stage changed size: the stack re-derived at once from the last draw's floors over the new stage, and answered —
     * the acts read the stage from the stack (`stack.surface`), so a resize must reach them before the next gesture, not
     * at the next render. `null` as `stackModel()` answers it: nothing drawn yet, or an unsized stage.
     */
    resize(surface) {
        this.#surface = surface;

        return this.#stack();
    }

    /** The stack over the last draw's rows and extents, at the stage as it stands. */
    #stack() {
        const { rows, extents } = this.#drawn;

        return rows === null ? null : stackModel(rows, extents, this.#lobbyHref, this.#surface);
    }

    /** The directory's rows over what the client holds, or `null` with no layout held. */
    #rows() {
        return plates(heldBody(this.#client.seats.values()), this.#building.floors);
    }

    /** Every room of every row — the rooms this screen renders. */
    #rendered(rows) {
        return (rows ?? []).flatMap((row) => row.rooms.map((room) => room.install_id));
    }

    /**
     * § 2.5's apply path for the building: drain the journal once, run the layout acts it carries, read the layout once a
     * snapshot has applied, fetch every row's data when the layout is new, then draw.
     */
    async render() {
        const journal = this.#client.takeWire();

        for (const entry of journal) {
            if (entry.t === 'building.layout' && await this.#building.applyLayout(entry)) {
                this.#layoutAsked = true;
            }

            if (entry.t === 'room.map') {
                const { applied, line } = await this.#building.applyRoomMap(entry, this.#rendered(this.#rows()));

                if (line !== null) {
                    this.#client.record(line);
                }

                if (applied) {
                    await this.#data.roomTilesets(String(entry.install_id));
                }
            }
        }

        if (!this.#layoutAsked && this.#client.feed.applied) {
            this.#layoutAsked = true;
            await this.#building.fetchLayout();
        }

        if (this.#building.floors !== null && this.#prefetched !== this.#building.layoutVersion) {
            this.#prefetched = this.#building.layoutVersion;
            await this.#data.prefetch();
        }

        await this.#enterNewRooms();

        return this.draw(journal);
    }

    /**
     * The rooms this screen has never asked for — an install the discovery fetch added after the prefetch, which § 4.6's
     * client-side rule composes onto a floor of its own — entered, and their tilesets loaded. A room whose request
     * FAILED is not re-asked: F16's recovery is the next `room.map` for it, not every render.
     */
    async #enterNewRooms() {
        const unasked = this.#rendered(this.#rows()).filter((id) => this.#building.room(id) === null);

        if (unasked.length > 0) {
            await this.#building.enterRooms(unasked);

            for (const id of unasked) {
                await this.#data.roomTilesets(id);
            }
        }
    }

    /** The building, drawn over one drained journal. */
    draw(journal) {
        const client = this.#client;
        const failure = this.#building.layoutFailure;
        const rows = this.#rows();

        // § 9 F6: ANY read returns 401 — the layout and every room's map among them.
        if (failure?.status === 401 || this.#rendered(rows).some((id) => this.#building.room(id)?.failure?.status === 401)) {
            client.readRefused(401);
        }

        const desks = this.#desks.renderWith(journal);

        this.#room.take(journal);

        const floors = (rows ?? []).map((row) => this.#floor(row, desks));

        this.#drawn = { rows, extents: new Map(floors.map(({ row, scene }) => [row.floor, scene?.extent ?? null])) };

        return Object.freeze({
            floors: Object.freeze(floors),
            stack: this.#stack(),
            strip: statusStrip(client.feed, client.fleet, {
                reduce: this.#reduce,
                art_failed: floors.some(({ scene }) => scene?.art_failed === true) || (this.#art === null && this.#failed.size > 0),
            }),
            failure: failureRender(client.feed),
            statement: failure === null ? null : layoutFailureStatement(failure.status),
            layout: this.#building.layoutState,
        });
    }

    /**
     * One floor: `composeFloor()`'s answer framed with exactly the members `buildScene()` reads — the thread edges empty, a
     * still's nothing — its scene, built as the floor page builds it, its desk labels, and the floor page's notices for it.
     */
    #floor(row, desks) {
        const composition = composeFloor(row, this.#building, roomsOf(this.#client.seats.values()));
        const frame = Object.freeze({
            floor: Object.freeze({ key: row.floor, label: row.label, name: row.name, theme: row.theme ?? null }),
            planned: composition.planned,
            extent: composition.extent,
            band: composition.band,
            room: this.#room.tick,
            rooms: composition.rooms,
            draw_order: composition.draw_order,
            overflow: composition.overflow,
            desks,
            coord: Object.freeze({}),
            coord_fleet: null,
        });
        const scene = this.#art === null ? null : buildScene(frame, sceneInput(this.#art, {
            maps: roomMaps(frame, this.#building),
            hallway: row.hallway ?? null,
            tilesets: this.#tilesets.held,
            layout_version: this.#building.layoutVersion,
            failed: this.#failed,
            reduce: true,
            effects: [],
            previous: new Map(),
            interns_in_flight: new Map(),
            laptop_at: () => null,
            trips_in_flight: new Map(),
            walks_in_flight: new Map(),
        }));

        return Object.freeze({
            row,
            frame,
            scene,
            labels: Object.freeze(deskLabels(scene, row)),
            notices: Object.freeze([...composition.notices, ...(scene?.notices ?? [])]),
        });
    }
}

/**
 * The building screen, started — the shape its page and the harness both drive.
 *
 * @param {object} client a `FleetClient`
 * @param {Function} fetchImpl the page's `fetch` (`wire/live-page.js`) — `wire/building.js`'s injection, and the tilesets'
 * @param {{now: function(): number}} clock
 * @param {object} log the animation log
 * @param {function(object): (Promise<void>|void)} draw receives each building frame — awaited, so a render's pictures are
 *        that render's and the page's coalesced render request bounds them (plan § 3.2)
 * @param {object} options `BuildingScreen`'s
 */
export function startBuildingScreen(client, fetchImpl, clock, log, draw, options) {
    const screen = new BuildingScreen(client, new Building(fetchImpl), clock, log, options, new TilesetLoader(fetchImpl));

    return {
        render: async () => {
            await draw(await screen.render());
        },
        sceneInputs: (inputs) => screen.sceneInputs(inputs),
        assetsFailed: (ids) => screen.assetsFailed(ids),
        resize: (surface) => screen.resize(surface),
    };
}

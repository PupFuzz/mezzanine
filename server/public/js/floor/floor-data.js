/**
 * THE FLOORS' DATA, FETCHED AHEAD — every row's rooms, each held map's tilesets and, on a page that holds none, the theme
 * modules: what a page needs in hand to draw a floor it is not standing on. `docs/design/FLOOR.md` Appendix B row 47
 * (card#13033 slice 1; the plan is `docs/design/plan-13033-3d-view.md` § 2 item 2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER. The press-time prefetch was `floor/door-views.js`'s own (card#12987, the views
 * behind the cab's doors); the building view needs the same sequence on entry for every floor. `DoorViews` holds one of
 * these and delegates to it.
 *
 * ⛔ ITS FETCHING WRITES NOTHING A SCREEN READS AS A FACT, AND ITS ONLY FAILURE SIGNAL IS THE DATA's ABSENCE (plan-12987
 * § 4, cut 2): `Building#enterRooms()` for every row's rooms (held by version, so a second run fetches nothing), the
 * maps' tilesets through the page's loader, and — where the page holds no theme module — every theme the registry names
 * through `cab-paint.js`'s one loader. NO `Building#fetchLayout()`: that sets the failure both screens state as F17's and
 * refuse on at 401, and a prefetch is a request for data, not a reading of the layout.
 *
 * ⛔ `roomTilesets()` ENTERS NO ROOM. It loads the tilesets one room's HELD map names, and nothing else: `enterRooms()`
 * re-requests every room whose last request failed (`wire/building.js`), so a caller that already has a room's new map in
 * hand — a `room.map` applied — and ran `prefetch()` again would re-fetch every other failed room too.
 *
 * Driven by `Tests\Feature\Floor\TheElevatorIsAControlOnTheDrawingTest`'s door-view leg (`door-view-leg.mjs`).
 */

import { namedTilesets } from './tileset.js';

/** No theme registry and no module held: every floor's view is the bare room in the sheet's tokens (plan-12987 § 5's R3). */
export const NO_THEMES = Object.freeze({ registry: null, modules: Object.freeze({}) });

export class FloorData {
    #building;

    #tilesets;

    #rows;

    #loadThemes;

    #wake;

    /** The themes `prefetch()` loaded (`#loadThemes`), or `null` until it answered — a page whose themes are its own has none. */
    #themes = null;

    /**
     * @param {{building: object, tilesets: object, rows: function(): (list<object>|null),
     *          loadThemes?: (function(): Promise<object|null>)|null, wake?: function(): void}} page
     *        `building` the page's `wire/building.js` `Building`; `tilesets` its `floor/tileset.js` `TilesetLoader`; `rows`
     *        the floors' plaque rows (`lobby/building-model.js`'s `plates()`), `null` with no layout; `loadThemes` the loader
     *        run where the page holds no theme module (the lobby: `cab-paint.js`'s `loadThemes()`); `wake` the page's render
     *        request, asked after each step answers
     */
    constructor({ building, tilesets, rows, loadThemes = null, wake = () => {} }) {
        this.#building = building;
        this.#tilesets = tilesets;
        this.#rows = rows;
        this.#loadThemes = loadThemes;
        this.#wake = wake;
    }

    /** The themes `prefetch()` loaded — `NO_THEMES` where the loader answered none — or `null` until then. */
    get themes() {
        return this.#themes;
    }

    /**
     * Every row's rooms, the rows in parallel; then each row's maps' tilesets; and the theme modules where the page loads
     * them here — the page woken after each step answers.
     *
     * @returns {Promise<void>} settled when every step has
     */
    prefetch() {
        const steps = (this.#rows() ?? []).map(async (row) => {
            const ids = row.rooms.map((room) => room.install_id);

            await this.#building.enterRooms(ids);
            this.#wake();
            await this.#tilesets.load(namedTilesets([row.hallway ?? null, ...ids.map((id) => this.#building.room(id)?.held?.map ?? null)]));
            this.#wake();
        });

        if (this.#loadThemes !== null && this.#themes === null) {
            steps.push(this.#loadThemes().then((themes) => {
                this.#themes = themes ?? NO_THEMES;
                this.#wake();
            }));
        }

        return Promise.all(steps).then(() => undefined);
    }

    /**
     * The tilesets one room's held map names — the tileset step of `prefetch()` for that room alone, entering no room —
     * the page woken once they answer.
     *
     * @param {string} installId the room whose held map's tilesets are loaded
     * @returns {Promise<void>}
     */
    async roomTilesets(installId) {
        await this.#tilesets.load(namedTilesets([this.#building.room(installId)?.held?.map ?? null]));
        this.#wake();
    }
}

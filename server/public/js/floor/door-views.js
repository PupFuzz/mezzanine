/**
 * THE VIEWS BEHIND THE CAB's DOORS, AS A PAGE HOLDS THEM — `docs/design/FLOOR.md` § 4.5's *Through the open doors at a FLOOR is
 * that floor* (card#12987; the plan is `docs/design/plan-12987-cab-door-view.md` § 4). Both screens hold one: it fetches every
 * stop's data at the press, attaches the stop's model (`floor/door-view.js`'s `doorView()`) to `scene.ride` as `view`, memoised
 * on every input the model reads so an unchanged model is the same object, and wakes the page each time a press's fetch has
 * answered so the cab repaints on the model's arrival (`wire/ride-page.js`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ITS FETCHING WRITES NOTHING A SCREEN READS AS A FACT, AND ITS ONLY FAILURE SIGNAL IS THE BARE ROOM (plan § 4, cut 2): the
 * press is `floor/floor-data.js`'s `prefetch()` (hoisted there by card#13033, whose building view runs the same sequence)
 * — `Building#enterRooms()` for every stop's rooms (held by version, so a second ride fetches nothing), the maps'
 * tilesets through the page's loader, and — on a page that holds no theme module, the lobby — every theme the registry names
 * through `cab-paint.js`'s one loader. NO `Building#fetchLayout()`: that sets the failure both screens state as F17's and
 * refuse on at 401, and a press is a request for a ride, not a reading of the layout. What the steps write — a room's
 * entry, the loader's `held`, the loaded themes — no screen reads for a room off its own floor.
 *
 * ⛔ THE MEMO IS IDENTITY: a key over every input `doorView()` reads that can change while a page lives answers the same
 * object, so the ride page's repaint rule compares the model it last painted by identity and never re-derives it. It is
 * cleared when the ride ends, so a page holds at most one ride's stops.
 */

import { doorView } from './door-view.js';
import { floorTheme } from './floor-theme.js';
import { FloorData, NO_THEMES } from './floor-data.js';
import { namedTilesets } from './tileset.js';

export class DoorViews {
    #building;

    #tilesets;

    #rows;

    #themes;

    /** The press's fetches (`floor/floor-data.js`), over the same building, loader and rows. */
    #data;

    /** The memo: key → model. */
    #memo = new Map();

    /**
     * @param {{building: object, tilesets: object, rows: function(): (list<object>|null), themes?: function(): (object|null),
     *          loadThemes?: (function(): Promise<object|null>)|null, wake?: function(): void}} page
     *        `building` the page's `wire/building.js` `Building`; `tilesets` its `floor/tileset.js` `TilesetLoader`; `rows` the
     *        stops' plaque rows the cab offers (`lobby/building-model.js`'s `plates()`, the ride's own stops), `null` with no
     *        layout; `themes` the themes the page already holds (the floor page's scene input), when it holds them;
     *        `loadThemes` the loader the press runs when it does not (the lobby: `cab-paint.js`'s `loadThemes()`); `wake` the
     *        page's render request, asked after each press's fetch answers
     */
    constructor({ building, tilesets, rows, themes = () => null, loadThemes = null, wake = () => {} }) {
        this.#building = building;
        this.#tilesets = tilesets;
        this.#rows = rows;
        this.#themes = themes;
        this.#data = new FloorData({ building, tilesets, rows, loadThemes, wake });
    }

    /**
     * THE PRESS's SEQUENCE (plan § 4) — `FloorData#prefetch()`: every stop's rooms, the stops in parallel; then each
     * stop's maps' tilesets; and the theme modules where the page loads them here — the page woken after each step answers.
     *
     * @returns {Promise<void>} settled when every step has
     */
    prefetch() {
        return this.#data.prefetch();
    }

    /**
     * `scene.ride` with the view behind its doors: `view` the stop's model at a floor's stop, `null` at the lobby's; `null` for
     * no ride, which ends the ride's memo.
     *
     * @param {object|null} ride `wire/ride.js`'s `sceneRide()`
     */
    attach(ride) {
        if (ride === null) {
            this.#memo.clear();

            return null;
        }

        return Object.freeze({ ...ride, view: ride.beyond === 'lobby' ? null : this.#view(ride.stop) });
    }

    /** The model for `stop`, the memo's when every input it reads is unchanged. */
    #view(stop) {
        const row = (this.#rows() ?? []).find((r) => r.floor === stop) ?? null;
        const themes = this.#data.themes ?? this.#themes() ?? NO_THEMES;
        const key = this.#key(stop, row, themes);
        const held = this.#memo.get(key);

        if (held !== undefined) {
            return held;
        }

        const model = doorView(row, this.#building, this.#tilesets, themes);

        this.#memo.set(key, model);

        return model;
    }

    /**
     * Every input `doorView()` reads that can change while a page lives (plan § 4's memo key): the stop; the layout's version
     * (its rooms, their form and origin, the hallway); each room's map version or `mapless`; each tileset the held maps name
     * and its state in the loader; the stop's theme and whether its module is held.
     */
    #key(stop, row, themes) {
        if (row === null) {
            return `${stop}|no stop`;
        }

        const b = this.#building;
        const ids = row.rooms.map((room) => room.install_id);
        const maps = ids.map((id) => `${id}:${b.mapless(id) ? 'mapless' : b.room(id).held.map_version}`);
        const urls = namedTilesets([row.hallway ?? null, ...ids.map((id) => (b.mapless(id) ? null : b.room(id).held.map))]).map((url) => {
            const entry = this.#tilesets.held.get(url);

            return `${url}:${entry?.tileset !== undefined ? 'held' : entry?.failure !== undefined ? 'failed' : 'pending'}`;
        });
        const theme = themes.registry === null ? null : floorTheme(row, themes.registry).theme;

        return [stop, b.layoutVersion, ...maps, ...urls, theme, themes.modules[theme] ? 'module' : 'none'].join('|');
    }
}

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
 */

import { Building } from '../wire/building.js';
import { failureRender } from '../wire/failure-render.js';
import { statusStrip } from '../floor/status-strip.js';
import { buildingModel } from './building-model.js';
import { discrepancyNotice, heldBody, lobbyModel } from './lobby-model.js';

export class LobbyScreen {
    #client;

    #building;

    /** Whether the entry's layout request (§ 4.4) has been made — after the first applied snapshot. */
    #layoutAsked = false;

    /**
     * @param {object} client a `FleetClient`
     * @param {object} building a `wire/building.js` `Building`
     */
    constructor(client, building) {
        this.#client = client;
        this.#building = building;
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
     * `401` it reports (§ 9 F6: "ANY read returns 401", the layout request included).
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

        return Object.freeze({
            summary: feed.applied ? lobbyModel(body, this.#building.floors, layoutFailure) : null,
            building: feed.applied ? buildingModel(body, cab, this.#building.floors) : null,
            // § 4.1's words over the protocol's own pair — and silence while no check can run.
            discrepancy: feed.applied && state !== null ? discrepancyNotice(state.held, state.total) : null,
            strip: statusStrip(feed, client.fleet),
            failure: failureRender(client.feed),
            event_log: client.eventLog,
        });
    }
}

/**
 * The lobby, started — the shape its page and the harness both drive.
 *
 * @param {object} client a `FleetClient`
 * @param {Function} fetchImpl the browser's own `fetch`, unbound — `wire/building.js`'s injection
 * @param {function(object): void} draw receives each lobby frame
 * @returns {{render: function(string|null): Promise<void>, refresh: function(): Promise<void>, draw: function(string|null): void}}
 */
export function startLobbyScreen(client, fetchImpl, draw) {
    const screen = new LobbyScreen(client, new Building(fetchImpl));

    return {
        render: async (cab = null) => {
            draw(await screen.render(cab));
        },
        refresh: async () => {
            await screen.refresh();
        },
        // An elevator ride (§ 4.5) re-draws from what is held and drains nothing.
        draw: (cab = null) => {
            draw(screen.draw(cab));
        },
    };
}

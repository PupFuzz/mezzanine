/**
 * The building surface's client — `docs/design/FLEET-STATE.md § 8.7`'s two endpoints, as every screen
 * reads them: the LAYOUT (`GET /api/building`) and each room's MAP
 * (`GET /api/building/rooms/{install_id}/map`), held by version. `docs/design/FLOOR.md` Appendix B
 * row 13, card#9208 build slice 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ AN AUTHORED DOCUMENT IS FETCHED WHOLE AND BY VERSION, AND A MESSAGE ONLY SAYS ONE CHANGED
 * (D2 § 8.7). `building.layout` carries a `layout_version` and `room.map` a `map_version`; neither
 * carries the document, so each apply below compares a version with the one held and FETCHES — there
 * is no path that patches a held document from a message.
 *
 * ⛔ A FAILED REQUEST IS HELD AS A FAILURE, NEVER AS A DOCUMENT (§ 9 F16, F17; § 13 decision 30). The
 * two substitutions that would make every screen draw something — the shipped default for a room, the
 * EMPTY layout for the building — are both a building the operator did not author drawn with
 * confidence, and for a `503` D2's forbidden clean zero. So a failure keeps whatever was held before it
 * (F16's extent, F17's *last known layout*), or nothing, and says so beside it.
 *
 * ⛔ THE `fetch` IS INJECTED. There is no browser on the build host; every decision here — what to
 * fetch, what to reuse, what a failure leaves — is driven under `node` against a scripted `fetch`
 * (`tests/Feature/Lobby/lobby-probe.mjs`) over bodies the real routes served.
 *
 * ⚠ WHO CALLS WHAT, TODAY. The lobby (`lobby/lobby-entry.js`) fetches the layout. NOTHING YET CALLS
 * `enterRooms()` OR THE TWO MESSAGE APPLIES: rooms are entered by § 4.4's floor route, which is
 * Appendix B step 7 (card#7341) and unbuilt, and messages arrive on § 2.2's stream, which the
 * client protocol opens — `wire/fleet-client.js`, built at step 3, which NO PAGE CONSTRUCTS before
 * step 8, so the lobby still opens no stream. They are here because row 13 is the cache, and are
 * exercised by `Tests\Feature\Building\TheClientHoldsRoomMapsByVersionTest`.
 */

/** § 8.7's layout endpoint. */
export const LAYOUT_PATH = '/api/building';

/** § 8.7's map endpoint for one room. The segment is D1 § 3.1's slug; it is encoded, never trusted. */
export function roomMapPath(installId) {
    return `/api/building/rooms/${encodeURIComponent(installId)}/map`;
}

/**
 * One same-origin GET, as `{ status, ok, body }`.
 *
 * `status` is `null` when the request never reached a status — the browser could not reach the
 * server — which is a failure with no code to name, and is kept apart from every code so no caller
 * renders it as one. `body` is the parsed JSON object, or `null` when the body is not one.
 *
 * `fetchImpl` is called as a plain function, never as a method of anything: the lobby passes the
 * browser's own `fetch`, unbound.
 */
export async function request(fetchImpl, path) {
    let response;

    try {
        response = await fetchImpl(path, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
    } catch {
        return { status: null, ok: false, body: null };
    }

    const body = await response.json().catch(() => null);

    return {
        status: response.status,
        ok: response.ok,
        body: body !== null && typeof body === 'object' ? body : null,
    };
}

/**
 * A `200` is § 8.7's layout only when it carries the members a screen composes from. A `200` that does
 * not — a proxy's page, a body cut short — is a failed request, and reading it as `floors: []` would
 * compose the EMPTY layout's building out of it, which is F17's "Never" arriving through a status code.
 */
function isLayoutAnswer(body) {
    return Number.isInteger(body?.layout?.layout_version)
        && Array.isArray(body.layout.floors)
        && Array.isArray(body.rooms);
}

/** Likewise a map: § 8.7's envelope, for the room that was asked for. */
function isMapAnswer(body, installId) {
    return body?.install_id === installId
        && (body.source === 'authored' || body.source === 'default')
        && (Number.isInteger(body.map_version) || body.map_version === null)
        && body.map !== null
        && typeof body.map === 'object';
}

/**
 * § 2.5's event-log line for an applied `room.map`: "naming the room and the revision". A removal is
 * `map_version: null` — "the room is back on the shipped default" (D2 § 8.3's row) — and is named as
 * that rather than as a revision numbered `null`.
 *
 * ⚠ THE WORDING IS NOT RATIFIED. § 2.5 states what the line names and publishes no string for it; this
 * is written from those two members and nothing else. The line is RETURNED, not written: the client's
 * event record is § 5.5's, built at Appendix B step 3 in `wire/fleet-client.js`; step 7 is what
 * delivers a `room.map` to this apply and writes the line it returns into that record.
 */
export function roomMapLine(installId, mapVersion) {
    return mapVersion === null
        ? `room ${installId}: map removed — back on the shipped default`
        : `room ${installId}: map revision ${mapVersion}`;
}

export class Building {
    #fetch;

    /** `{ layout_version, floors }` from the last layout request that succeeded, or `null`. */
    #layout = null;

    /** `{ status }` of the last layout request when it failed; `null` once one succeeds. */
    #layoutFailure = null;

    /**
     * install_id → the `map_version` the client was last TOLD is current: `/api/building`'s `rooms[]`,
     * then each successful map fetch's own answer, which is newer than any `rooms[]` read before it. A
     * room absent here is unauthored — the default, `null` (§ 8.7: "a room absent here renders the
     * shipped default").
     */
    #reported = new Map();

    /** install_id → `{ held: { source, map_version, map } | null, failure: { status } | null }`. */
    #rooms = new Map();

    constructor(fetchImpl) {
        this.#fetch = fetchImpl;
    }

    /**
     * `GET /api/building` — on connect (§ 2.2 step 3b), and again on a `building.layout` (§ 2.5).
     *
     * On success the layout and every authored room's version replace what was held. On failure
     * NOTHING held is touched: the floors a screen already holds stay, labelled *last known layout* by
     * the screen (F17), and a client that held none holds none.
     *
     * @returns {Promise<boolean>} whether the request succeeded
     */
    async fetchLayout() {
        const { status, body } = await request(this.#fetch, LAYOUT_PATH);

        if (status !== 200 || !isLayoutAnswer(body)) {
            this.#layoutFailure = { status };

            return false;
        }

        this.#layout = { layout_version: body.layout.layout_version, floors: body.layout.floors };
        this.#reported = new Map(body.rooms.map((room) => [String(room.install_id), room.map_version]));
        this.#layoutFailure = null;

        return true;
    }

    /** The floors the last successful layout request answered, or `null` when none has. */
    get floors() {
        return this.#layout === null ? null : this.#layout.floors;
    }

    /** `{ status }` when the last layout request failed, else `null`. */
    get layoutFailure() {
        return this.#layoutFailure;
    }

    /**
     * A `building.layout` message (D2 § 8.3). § 2.5: the client fetches `GET /api/building` again "when
     * the message's `layout_version` differs from the one it holds" — and F17's recovery is "on the next
     * `building.layout`", so a client whose last request failed fetches on any.
     *
     * @returns {Promise<boolean>} whether it fetched
     */
    async applyLayout(message) {
        if (this.#layoutFailure === null
            && this.#layout !== null
            && message?.layout_version === this.#layout.layout_version) {
            return false;
        }

        await this.fetchLayout();

        return true;
    }

    /** The version the client was last told is current for a room — `null` for the default. */
    reportedVersion(installId) {
        return this.#reported.get(installId) ?? null;
    }

    /**
     * What the client holds for a room: the last map a request answered (`held`), and the last
     * request's failure (`failure`). `null` for a room never entered.
     *
     * ⛔ WHILE `failure` IS SET, `held` IS F16's EXTENT AND NOT A MAP TO DRAW: "the room renders its
     * desks with no map … the mapless room keeps the extent of the last map the client held for it".
     * That render is step 7's.
     */
    room(installId) {
        return this.#rooms.get(installId) ?? null;
    }

    /**
     * § 4.4's floor entry: fetch each room "whose map the client does not already hold at the
     * `map_version` `/api/building` reported". A room whose last request failed is fetched again
     * whatever it holds — entering a room is F16's "retry on the user's action".
     */
    async enterRooms(installIds) {
        for (const installId of installIds) {
            if (this.#holds(installId, this.reportedVersion(installId))) {
                continue;
            }

            await this.#fetchRoom(installId);
        }
    }

    /**
     * A `room.map` message (D2 § 8.3). D2 § 8.7: "A client applies a `room.map` only for a room it
     * renders and ignores the rest"; § 2.5: it "fetches the room's map when the message's `map_version`
     * differs from the one it holds". F16's recovery — "on the next `room.map` for that room" — is the
     * same test, because a room whose request failed holds nothing current.
     *
     * ⚠ AN IGNORED MESSAGE CHANGES NOTHING HERE, AS D2 WRITES IT — including the version the client
     * was told for that room. A map held for a room the client stopped rendering therefore stays at its
     * old version until `/api/building` is read again; whether that can be reached depends on how step
     * 7's floor route keeps this cache across routes, which is not designed yet.
     *
     * @param {Iterable<string>} rendered the rooms the client renders now
     * @returns {Promise<{applied: boolean, line: string|null}>} `line` is § 2.5's one event-log line
     */
    async applyRoomMap(message, rendered) {
        const id = String(message?.install_id);
        const version = message?.map_version ?? null;

        if (!new Set(rendered).has(id)) {
            return { applied: false, line: null };
        }

        if (this.#holds(id, version)) {
            return { applied: false, line: null };
        }

        const line = roomMapLine(id, version);

        await this.#fetchRoom(id);

        return { applied: true, line };
    }

    /** Whether a room's held map is current at `version`, with no failure standing against it. */
    #holds(installId, version) {
        const room = this.#rooms.get(installId);

        return room !== undefined
            && room.failure === null
            && room.held !== null
            && room.held.map_version === version;
    }

    async #fetchRoom(id) {
        const held = this.#rooms.get(id)?.held ?? null;
        const { status, body } = await request(this.#fetch, roomMapPath(id));

        if (status !== 200 || !isMapAnswer(body, id)) {
            // F16's "Never": no default in its place. What was held stays — as the extent, under the
            // failure — and a room that held nothing holds nothing.
            this.#rooms.set(id, { held, failure: { status } });

            return;
        }

        this.#rooms.set(id, {
            held: { source: body.source, map_version: body.map_version, map: body.map },
            failure: null,
        });
        this.#reported.set(id, body.map_version);
    }
}

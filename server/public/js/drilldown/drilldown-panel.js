/**
 * THE DRILL-DOWN, OPEN — `docs/design/FLOOR.md § 4.3`: the panel over the floor, its two requests,
 * the live patching while it is open, § 2.4's stamp rule per `fetch-fresh` block, § 9 F10/F11, and
 * the close a retirement forces. Appendix B row 10, card#7342.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT OPENS NO STREAM AND FETCHES NOTHING ON ITS OWN SCHEDULE. § 4: "the drill-down is a panel over
 * the floor rather than a route of its own, because closing it must not cost a reconnect" — so it
 * reads the floor page's ONE client protocol, and its two requests are issued when the USER opens a
 * desk (or retries, or pages the timeline), through the protocol's one read path
 * (`FleetClient#readSeatDetail` / `#readTimeline`), which is where § 9 F6's *any read returns 401*
 * and § 2.4's offset refresh already live. "This document states no polling cadence for the panel —
 * inventing one would be inventing a cadence D2 does not state" (§ 4.3).
 *
 * ⛔ THE VERSION-BEARING MEMBERS ARE THE PROTOCOL'S HELD SEAT, PATCHED LIVE. "While the panel is open,
 * deltas for that seat patch it live — the version-bearing members, and only those." The protocol
 * already applies every delta to the one held object under § 2.2's version rule; a second copy here,
 * patched separately, would be a second seat map free to disagree with the desk it was opened from.
 *
 * ⛔ THE THREE `fetch-fresh` BLOCKS ARE HELD HERE, EACH WITH THE STAMP OF WHAT DELIVERED IT. § 2.4:
 * "A block's *as of HH:MM:SS* stamp is the `server_time` of whatever delivered the values the block is
 * currently showing — a fetch, a snapshot apply, a resync, or a delta whose shallow merge re-sent that
 * nested object whole." The protocol's map follows the version rule and drops a snapshot row that is
 * not HIGHER than what it holds — but a row at the SAME version is the same state read later, so its
 * copies of the ten members are fresher readings of an unchanged state. The panel takes them, with
 * that row's stamp: that is how the transport block is RE-STAMPED BY EACH POLL rather than ticked
 * (AT-D3-6's panel half) while the protocol's own map keeps its rule whole. A row at a LOWER version is
 * an older state and is never taken.
 *
 * ⛔ A RETIRED SEAT HAS NO PANEL. § 3.5: "the drill-down goes with the desk"; AT-D3-16: "the drill-down
 * for it, if open, closes". The protocol's one removal is journalled (`seat.removed`) and the panel
 * closes on it — on the announcement and on § 2.3 row 4's backstop alike, because in both the desk is
 * gone and D2's read surfaces no longer serve the seat.
 *
 * ⛔ NOTHING HERE READS A CLOCK. The browser's reading is an argument to `view()`, corrected there by
 * the protocol's own offset — which is what lets the harness put the viewer three hours fast
 * (AT-D3-10's panel half) and read what the panel says.
 */

import { correctedNowMs, wireMs } from '../wire/duration.js';
import { failed } from '../wire/fleet-client.js';
import { drillDownModel } from './drilldown-model.js';

/** § 4.3's three `fetch-fresh` blocks — the nested objects whose members are among D2 § 6.5's ten. */
const FRESH_BLOCKS = Object.freeze(['delivery', 'reporter', 'derivation']);

export class DrillDownPanel {
    #client;

    /** `{ install_id, seat_id, key }` while open, else `null`. */
    #target = null;

    /** Bumped on every open and close, so an answer to an earlier open is never applied to a later one. */
    #generation = 0;

    /** The last detail response that answered, whole — its `server_time` dates `detail`. */
    #detail = null;

    /** § 9 F11: `{ status }` of the detail request that failed, until one succeeds. */
    #detailFailure = null;

    /** Every timeline page that answered, merged: `{ events, next_before }`, or `null` before one. */
    #timeline = null;

    /** § 9 F10: `{ status }` of the timeline request that failed, until one succeeds. */
    #timelineFailure = null;

    /** block → `{ value, stamp, version }` — the `fetch-fresh` block as last delivered. */
    #fresh = new Map();

    /** The requests in flight, so a retry or a page never doubles one. */
    #inFlight = new Set();

    /**
     * @param {object} client the floor page's `FleetClient` — its held seats, its stamps, its offset,
     *        its read status and its two drill-down reads
     */
    constructor(client) {
        this.#client = client;
    }

    /** `{ install_id, seat_id }` of the open panel, or `null`. */
    get target() {
        return this.#target === null
            ? null
            : { install_id: this.#target.install_id, seat_id: this.#target.seat_id };
    }

    /**
     * Open on one desk: seed the `fetch-fresh` blocks from what the protocol holds, then issue § 4.3's
     * two requests. Opening the desk already open is a no-op — it must not re-issue both requests.
     *
     * @returns {Promise<void>} settled once both requests have answered, whichever way
     */
    async open(installId, seatId) {
        const k = `${installId}/${seatId}`;

        if (this.#target?.key === k) {
            return;
        }

        this.close();
        this.#target = { install_id: installId, seat_id: seatId, key: k };

        const held = this.#client.seats.get(k);

        if (held !== undefined) {
            for (const block of FRESH_BLOCKS) {
                this.#offer(block, held[block], this.#client.stampOf(k, block), held.state_version);
            }
        }

        await Promise.all([this.#loadDetail(), this.#loadTimeline(null)]);
    }

    /** Close — to the floor, with nothing re-fetched (§ 4.3: "closes to the floor"). */
    close() {
        this.#target = null;
        this.#generation++;
        this.#detail = null;
        this.#detailFailure = null;
        this.#timeline = null;
        this.#timelineFailure = null;
        this.#fresh = new Map();
        this.#inFlight = new Set();
    }

    /**
     * § 9 F10/F11's recovery — "retry on the user's action": re-issue whichever request failed, and
     * nothing that did not.
     */
    async retry() {
        const work = [];

        if (this.#target !== null && this.#detailFailure !== null) {
            work.push(this.#loadDetail());
        }

        if (this.#target !== null && this.#timelineFailure !== null) {
            work.push(this.#loadTimeline(this.#timeline === null ? null : this.#timeline.next_before));
        }

        await Promise.all(work);
    }

    /** § 4.3: the window is "paginated with `before` on scroll" — the server's own `next_before`. */
    async more() {
        const before = this.#timeline?.next_before ?? null;

        if (this.#target !== null && before !== null) {
            await this.#loadTimeline(before);
        }
    }

    /**
     * What the protocol applied since the last render, for the open desk: the removal that closes the
     * panel, and every delivery of a `fetch-fresh` block — a delta that re-sent one whole, and every
     * row a full snapshot or a seat fetch carried for this seat, whether or not it replaced the held
     * object.
     *
     * @param {list<object>} journal `FleetClient#takeWire()`'s entries, as the floor screen drained them
     */
    observe(journal) {
        if (this.#target === null) {
            return;
        }

        const { install_id: installId, seat_id: seatId } = this.#target;

        for (const entry of journal) {
            if (entry.install_id !== installId || entry.seat_id !== seatId) {
                continue;
            }

            if (entry.t === 'seat.removed') {
                this.close();

                return;
            }

            if (entry.t === 'seat.delta' && entry.outcome === 'applied') {
                for (const block of FRESH_BLOCKS) {
                    if (entry.changed.includes(block)) {
                        this.#offer(block, entry.after[block], entry.server_time, entry.state_version);
                    }
                }

                continue;
            }

            if ((entry.t === 'snapshot' || entry.t === 'seat.fetch') && entry.row !== undefined) {
                for (const block of FRESH_BLOCKS) {
                    this.#offer(block, entry.row[block], entry.server_time, entry.state_version);
                }
            }
        }
    }

    /**
     * The panel's model at the browser's instant `browserNowMs`, or `null` while closed.
     *
     * @param {number} browserNowMs the BROWSER's clock, corrected here by the protocol's own offset
     * @param {object} [facts] `{ floor }` — the room the header names
     */
    view(browserNowMs, facts = {}) {
        if (this.#target === null) {
            return null;
        }

        const k = this.#target.key;
        const held = this.#client.seats.get(k);

        if (held === undefined) {
            return null;
        }

        const seat = { ...held };

        for (const block of FRESH_BLOCKS) {
            if (this.#fresh.has(block)) {
                seat[block] = this.#fresh.get(block).value;
            }
        }

        if (this.#detail !== null) {
            seat.detail = this.#detail.detail ?? null;
            seat.server_time = this.#detail.server_time ?? null;
        }

        return {
            ...drillDownModel(seat, this.#timeline, {
                now_ms: correctedNowMs(this.#client.clockOffsetMs, browserNowMs),
                stamps: {
                    delivery: this.#fresh.get('delivery')?.stamp ?? null,
                    reporter: this.#fresh.get('reporter')?.stamp ?? null,
                    derivation: this.#fresh.get('derivation')?.stamp ?? null,
                    detail: this.#detail?.server_time ?? null,
                },
                floor: facts.floor ?? null,
                missing: this.#client.readStatus(k).missing,
                detail_failure: this.#detailFailure,
                detail_pending: this.#detail === null && this.#inFlight.has('detail'),
                timeline_failure: this.#timelineFailure,
            }),
            // Whether the detail response is still out — the sections that need it are not yet
            // *unavailable*, they are waiting (§ 9 F11 names a FAILED request, not a pending one).
            loading: this.#inFlight.has('detail') || this.#inFlight.has('timeline'),
            can_retry: this.#detailFailure !== null || this.#timelineFailure !== null,
        };
    }

    /**
     * § 2.4's stamp rule for one block: take a delivered copy when it is of a NEWER state, or of the
     * same state delivered later. Never an older state, whatever its stamp.
     */
    #offer(block, value, stamp, version) {
        if (value === undefined || !Number.isInteger(version)) {
            return;
        }

        const held = this.#fresh.get(block);
        const later = held === undefined
            || version > held.version
            || (version === held.version && (wireMs(stamp) ?? -Infinity) >= (wireMs(held.stamp) ?? -Infinity));

        if (later) {
            this.#fresh.set(block, { value, stamp: stamp ?? null, version });
        }
    }

    async #loadDetail() {
        if (this.#inFlight.has('detail')) {
            return;
        }

        const generation = this.#generation;
        const { install_id: installId, seat_id: seatId } = this.#target;

        this.#inFlight.add('detail');

        const res = await this.#client.readSeatDetail(installId, seatId);

        if (generation !== this.#generation) {
            return;
        }

        this.#inFlight.delete('detail');

        if (res === null) {
            return;
        }

        // § 2.2's one failure rule — a non-2xx, no response, or a body that is not an object — which
        // is the protocol's (`failed`), not a second copy of it.
        if (failed(res)) {
            // § 9 F11: "the drill-down opens with the seat object it already holds and the sections
            // that need `detail` read *unavailable*".
            this.#detailFailure = { status: res.status };

            return;
        }

        this.#detailFailure = null;
        this.#detail = res.body;

        for (const block of FRESH_BLOCKS) {
            this.#offer(block, res.body[block], res.body.server_time, res.body.state_version);
        }
    }

    async #loadTimeline(before) {
        if (this.#inFlight.has('timeline')) {
            return;
        }

        const generation = this.#generation;
        const { install_id: installId, seat_id: seatId } = this.#target;

        this.#inFlight.add('timeline');

        const res = await this.#client.readTimeline(installId, seatId, before);

        if (generation !== this.#generation) {
            return;
        }

        this.#inFlight.delete('timeline');

        if (res === null) {
            return;
        }

        if (failed(res) || !Array.isArray(res.body.events)) {
            // § 9 F10: the timeline area says so, and every page already delivered stays.
            this.#timelineFailure = { status: res.status };

            return;
        }

        this.#timelineFailure = null;
        this.#timeline = {
            events: [...(before === null ? [] : (this.#timeline?.events ?? [])), ...res.body.events],
            next_before: res.body.next_before ?? null,
        };
    }
}

/**
 * The client protocol — `docs/design/FLOOR.md § 2.2`'s connect, snapshot and deltas, § 2.3's
 * membership, § 2.4's clock offset and § 5.5's event record, as one per-seat primitive over
 * `docs/design/FLEET-STATE.md`'s stream and REST surfaces. FLOOR Appendix B row 3, card#7341
 * step 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE PER-SEAT PRIMITIVE, AND EVERY ROW GOES THROUGH IT. A delta is a delta whether it arrived
 * live, was buffered across the connect window, or was drained after a fetch — `#applyDelta` is
 * the only path, so the version comparison is written ONCE and cannot diverge between the live
 * case and the drained one. Likewise every ROW — the first snapshot's, a discovery snapshot's, a
 * seat fetch's — enters through `#replaceIfHigher`, which is where § 2.2's version rule lives.
 *
 * ⛔ A FULL SNAPSHOT NEVER LOWERS A HELD `state_version` (§ 2.2). A snapshot was READ before the
 * deltas the stream may since have delivered, so a row that is not higher than what is held
 * replaces nothing — and it is dropped WHOLE, never merged into the held object. That single rule
 * is what makes ONE discovery fetch safe: a snapshot row, a seat fetch's object and a live delta
 * for one seat are ordered by one monotonic integer (D2 § 8.2.1's `state_version`), whichever
 * arrives first.
 *
 * ⛔ THE DISCOVERY FETCH IS `ADMIT` (§ 2.3 row 3): applying its rows IS the admission of every
 * install it carries, and no second, scoped read follows it. It BUFFERS NOTHING — deltas keep
 * applying while it is in flight, and a delta for a seat the client does not hold takes § 2.3
 * row 1's insert fetch as it would at any other time. What an in-flight discovery DOES decide is
 * what happens to a BUFFER whose own seat fetch has just failed: it is left standing for the
 * discovery's own release, because the discovery may still insert the key that buffer drains
 * against.
 *
 * ⛔ NOTHING HERE DRAWS ANYTHING, AND NOTHING HERE SETS A TIMER. The protocol holds data: the seat
 * map, each held member's delivery stamp (§ 2.4's stamp rule, `stampOf`), the record's lines, the
 * clock offset, the read status. Every render is another module's (Appendix B rows 4, 5, 6, 9, 10
 * — row 4's ages are `wire/age-readout.js`, which reads this client's seats and offset and ticks
 * on a timer it is handed; row 5's desks are `desk/desk-floor.js`, which reads the same), and the
 * one renderer owns every hook. No `setTimeout`, no `Date.now`, no `Math.random`: the clock is injected, which is what lets the harness replay a
 * scenario and get the same records every time — `Tests\Feature\Floor`'s determinism check scans
 * this file's own source for those identifiers.
 *
 * ⛔ THE `fetch` AND THE `EventSource` ARE INJECTED, for the reason `wire/building.js` gives: there
 * is no browser on the build host, so every decision here is driven under `node` against a
 * scripted pair (`tests/Feature/Floor/fleet-client-probe.mjs`).
 *
 * ⚠ WHO CONSTRUCTS THIS, TODAY: nothing but the harness. No page builds a `FleetClient` before
 * Appendix B step 8, which is where stream recovery — dead-feed detection, the re-opens, the
 * reload grace and the re-run from step 1 — is built. Until then the lobby keeps its own
 * one-shot fetch and its own § 4.1 trigger; step 9 replaces that trigger with this one.
 */

import { request } from './building.js';
import { clockOffsetMs } from './duration.js';
import { DiscrepancyBudget } from './discrepancy-budget.js';

/** § 2.2 step 3 / D2 § 8.2's full snapshot — the connect read, and the discovery read. */
const SNAPSHOT_PATH = '/api/fleet/snapshot';

/** D2 § 8.2.3's per-seat read. Each segment is encoded, never trusted — `building.js` does the same. */
function seatPath(installId, seatId) {
    return `/api/fleet/seats/${encodeURIComponent(installId)}/${encodeURIComponent(seatId)}`;
}

/** The one key shape for the held map, the buffers, the streaks and the record's lines. */
function key(installId, seatId) {
    return `${installId}/${seatId}`;
}

/** § 5.5: the record is "newest first, capped at 200 lines, text only". The cap is the record's own. */
const LOG_CAP = 200;

/**
 * § 2.3 row 5: how many CONSECUTIVE failed reads of one held seat (with no intervening success)
 * before the client stops drawing a character for it and the desk renders § 7.1's empty chair.
 *
 * ⭐ **2**, ratified by the operator (card#7341, 2026-09-15). It is also the smallest value the
 * rule's own no-flicker constraint allows: the ruling is that a single failure the very next retry
 * repairs must never flip the render, which puts the threshold above 1. No existing bound derives
 * it — § 2.4's `stale`/`offline` figures and D2's heartbeat interval all classify the SERVER's
 * receipt age on a fixed period, and a per-seat read has no period of its own (it is re-issued by
 * the next gap-delta and by a discovery's release, so its rate rides the discovery cadence).
 *
 * ⛔ THIS IS THE ONLY DEFINITION OF THE COUNT. FLOOR § 2.3 row 5 and its Why cell state the figure
 * because they are quoting the operator's ratified ANSWER — a different act from defining the
 * constant the code reads. Nothing derives a second constant from this one.
 */
const MISSING_AFTER = 2;

/**
 * § 2.2's failure rule, in one place: a response failed when `request()` reports `ok: false` — a
 * non-2xx, or a request that never reached a status — OR when its body is not a JSON object,
 * for which `request()` answers `body: null`.
 *
 * ⛔ THE SECOND HALF IS NOT BELT-AND-BRACES. A `200` carrying a proxy's HTML — a maintenance page,
 * a captive portal — reports `ok: true`. Treated as a success it reaches the row walk, throws
 * inside the fetch's own continuation, and a discovery that throws never clears its in-flight
 * pair: no discovery would ever run again on that connection, and the failure would be an
 * unhandled rejection nobody rendered.
 */
function failed(response) {
    return !response.ok || response.body === null;
}

export class FleetClient {
    #fetch;

    #EventSourceImpl;

    #clock;

    #es = null;

    /** `"install_id/seat_id"` → the held seat object (D2 § 8.2.1's shape, no REST envelope). */
    #seats = new Map();

    /** key → deltas held back while this seat cannot be applied to yet. */
    #buffers = new Map();

    /** keys with a seat fetch (resync or insert) in flight. */
    #pendingSeats = new Set();

    /** `connecting` until the first snapshot answers; then `live`, or `snapshot-failed` for good. */
    #phase = 'idle';

    /** The held `fleet{}`'s envelope `server_time` — Appendix A T40's filter reads it. */
    #fleetTime = null;

    /** The held `fleet{}`'s `seats_total` — § 4.1's M. */
    #fleetTotal = null;

    #budget = new DiscrepancyBudget();

    /** The `(held, total)` pair the discovery fetch in flight was issued for, or `null`. */
    #discovery = null;

    /** Whether a `fleet{}` was admitted while that discovery was in flight. */
    #recheck = false;

    /** § 2.4's `clock_offset_ms`, or `null` before any `server_time` arrived. */
    #offset = null;

    /** § 5.5's record: newest first, text only. */
    #log = [];

    /** § 2.3 row 5: key → consecutive failed reads, cleared by any confirmed apply. */
    #failStreak = new Map();

    /** key → the browser clock's reading of when this client last trusted an apply for the key. */
    #confirmedAt = new Map();

    /**
     * key → `{ member: server_time }`: for each top-level member of the held object, the
     * `server_time` of whatever DELIVERED the value now held — § 2.4's stamp rule.
     */
    #stamps = new Map();

    /**
     * @param {Function} fetchImpl the browser's own `fetch`, unbound — called as a plain function
     *   through `wire/building.js`'s `request()`
     * @param {Function} EventSourceImpl constructed as `new EventSourceImpl('/api/fleet/stream')`;
     *   a browser's `EventSource` satisfies it
     * @param {{now: function(): number}} clockImpl read when a record line is written and when an
     *   offset is refreshed. No timer is set anywhere in this file.
     */
    constructor(fetchImpl, EventSourceImpl, clockImpl) {
        this.#fetch = fetchImpl;
        this.#EventSourceImpl = EventSourceImpl;
        this.#clock = clockImpl;
    }

    /** The held seat map — a COPY, so a caller cannot mutate what the protocol holds. */
    get seats() {
        return new Map(this.#seats);
    }

    /** § 5.5's record, newest first, at most `LOG_CAP` lines — a copy, for the same reason. */
    get eventLog() {
        return [...this.#log];
    }

    /** `'connecting' | 'live' | 'snapshot-failed'` (`'idle'` before `start()`). */
    get phase() {
        return this.#phase;
    }

    /** § 2.4's offset, or `null` before any `server_time` arrived. Every AGE is `age-readout.js`'s. */
    get clockOffsetMs() {
        return this.#offset;
    }

    /**
     * § 2.4's STAMP RULE for one member of one held seat: the `server_time` of whatever delivered
     * the value the client now holds for it, or `null` for a member it holds nothing for.
     *
     * > "A block's *as of HH:MM:SS* stamp is the `server_time` of whatever delivered the values the
     * > block is currently showing — a fetch, a snapshot apply, a resync, or a delta whose shallow
     * > merge re-sent that nested object whole. The stamp advances **with** the values and never
     * > independently of them."
     *
     * ⛔ IT IS HELD HERE BECAUSE ONLY THE PROTOCOL SEES THE ENVELOPE. The held object is D2
     * § 8.2.1's seat object with the REST envelope stripped (see `#fetchSeat`), so by the time a
     * renderer reads a `derivation` block the `server_time` that dates it is gone — and a renderer
     * that stamped it with the client's newest `server_time` instead would date a two-minute-old
     * `fold_lag_ms` to a heartbeat that never carried it (§ 7.4's lag line, AT-D3-5).
     *
     * ⛔ A REPLACED ROW STAMPS EVERY MEMBER; A `+1` PATCH STAMPS ONLY THE MEMBERS IT CARRIED. That
     * is the shallow merge's own boundary (D2 § 8.3.1): a patch that touched `delivery.no_data_since`
     * re-sent the whole `delivery` object, so `delivery` moves to that delta's `server_time`, and a
     * `derivation` block it did not carry keeps the stamp of the fetch that last delivered it.
     */
    stampOf(k, member) {
        return this.#stamps.get(k)?.[member] ?? null;
    }

    /**
     * § 2.3 row 5's read status for one HELD key: `{missing, failStreak, confirmedAt}`.
     *
     * ⛔ `missing` IS NEVER TRUE FOR A KEY WITH NO HELD OBJECT, and that is asserted here at the
     * READ as well as kept true at the write (the streak only ever advances for a held key). The
     * client cannot draw a specific desk for a seat it has never held — a disagreement it cannot
     * attribute to a seat is the LOBBY's shape, `discrepancyState()` below. Step 3 removes no
     * seat, so today the read-side guard is redundant with the write-side one; the removal
     * backstop (§ 2.3 row 4, Appendix B step 10) is what will make it load-bearing.
     *
     * ⚠ FOR STEP 10, WHEN THAT BACKSTOP LANDS: removing a held key must also delete it from the
     * streak and confirmation maps, which nothing prunes today, so a seat re-inserted under the
     * same key starts from a clean read status rather than a streak left over from before.
     *
     * `confirmedAt` is the BROWSER clock's reading of when this client last trusted an apply for
     * the key — a client-local quantity, and deliberately NOT a substitute for § 2.4's *no data
     * since …* line, which is built from the server's own `delivery.*` pair. A seat whose
     * client-side read is failing can have a `delivery.*` pair saying delivery is perfectly
     * current: the server received the row, this client's fetch of it did not land. The two
     * answer different questions.
     */
    readStatus(k) {
        const streak = this.#failStreak.get(k) ?? 0;

        return {
            missing: this.#seats.has(k) && streak >= MISSING_AFTER,
            failStreak: streak,
            confirmedAt: this.#confirmedAt.get(k) ?? null,
        };
    }

    /**
     * § 4.1's disagreement, for the lobby's notice: `{held, total, refreshing}`, or `null` while
     * the counts agree.
     *
     * ⛔ `refreshing` ANSWERS "CAN A CHECK FOR THIS PAIR STILL RUN", NOT "HAS ONE RUN YET". Once
     * the budget has spent the pair and no discovery is in flight for it, the one check this pair
     * gets has already run and has not resolved the disagreement — saying *refreshing* past that
     * point claims a refresh that is not happening. And once `phase` has left `'live'` for good no
     * check can EVER run again, because the discrepancy check is only reached from a live
     * dispatch: `snapshot-failed` is permanent until step 8's re-run exists, so a client in it
     * claiming a pending refresh is claiming one nothing can start.
     *
     * ⚠ ONE REACHABLE STATE IS NOT CLOSED BY THAT, AND IS NAMED RATHER THAN DESIGNED AROUND: a
     * `live` page whose feed has quietly died has an unspent pair and no heartbeat coming, and
     * this still reports `refreshing: true`. Telling "a heartbeat is due" from "the feed is dead"
     * IS dead-feed detection, which is step 8's and is not decidable from data step 3 holds.
     */
    discrepancyState() {
        const held = this.#seats.size;
        const total = this.#fleetTotal;

        if (!Number.isInteger(total) || held === total) {
            return null;
        }

        const checking = this.#discovery !== null
            && this.#discovery[0] === held
            && this.#discovery[1] === total;
        const spent = this.#budget.hasSpent(held, total);

        return { held, total, refreshing: checking || (!spent && this.#phase === 'live') };
    }

    /**
     * § 2.2 step 1 then step 3: open the stream, THEN fetch the snapshot.
     *
     * ⛔ THE ORDER IS THE WHOLE POINT (§ 2.2, D2 § 8.4). D2 replays nothing — no `id:`, the cursor
     * starts at head — so a delta emitted between the snapshot read and the stream opening is
     * gone for good. Opening first makes the window a BUFFERING problem instead of a data-loss
     * one: every delta that lands while the snapshot is in flight is held and drained against it.
     */
    start() {
        this.#phase = 'connecting';
        this.#open();
        this.#initialSnapshot();
    }

    /**
     * ⛔ EXACTLY ONE `addEventListener("mezzanine", …)`, AND `onmessage` IS NEVER USED (§ 2.2;
     * D2 § 8.4). Every message on this feed is a named `mezzanine` event whose `t` is the sole
     * discriminator; a client listening on `"message"` receives NOTHING and looks perfectly
     * healthy doing it.
     */
    #open() {
        this.#es = new this.#EventSourceImpl('/api/fleet/stream');
        this.#es.addEventListener('mezzanine', (ev) => this.#dispatch(JSON.parse(ev.data)));
    }

    /**
     * § 2.4 row 1: the offset is "refreshed on every message and response that carries
     * `server_time`" — through `wire/duration.js`'s shared computation, never a second one here.
     *
     * ⛔ A MALFORMED OR ABSENT `server_time` KEEPS THE OFFSET ALREADY HELD. `clockOffsetMs`
     * validates the wire shape and answers `null` on anything else; overwriting with the `NaN` a
     * local subtraction would produce turns every age on the screen into nonsense on one bad body.
     */
    #observeTime(serverTime) {
        const offset = clockOffsetMs(serverTime, this.#clock.now());

        if (offset !== null) {
            this.#offset = offset;
        }
    }

    /** One GET, with § 2.4's offset refreshed from any body that carries `server_time`. */
    async #get(path) {
        const res = await request(this.#fetch, path);

        if (res.body !== null) {
            this.#observeTime(res.body.server_time);
        }

        return res;
    }

    /**
     * § 2.2 step 3, and step 5's drain.
     *
     * ⛔ THE APPLY AND THE DRAIN ARE ONE SYNCHRONOUS CONTINUATION. If the client awaited anything
     * between going `live` and releasing the buffers, a delta delivered in that gap would take the
     * live path against a half-populated map and start a fetch for a seat the snapshot is about to
     * insert.
     *
     * ⚠ A FAILED FIRST SNAPSHOT STOPS THE CLIENT: the buffers are discarded, every later delta is
     * ignored, and `phase` stays `snapshot-failed`. Patching held-nothing from deltas would be
     * § 2.2's forbidden "partial object" built out of patches. The re-run from step 1 is step 8's.
     */
    async #initialSnapshot() {
        const res = await this.#get(SNAPSHOT_PATH);

        if (failed(res)) {
            this.#phase = 'snapshot-failed';
            this.#buffers.clear();

            return;
        }

        this.#applySnapshot(res.body);
        this.#phase = 'live';

        for (const k of [...this.#buffers.keys()]) {
            this.#release(k);
        }
    }

    /** A full snapshot — the connect one and a discovery's — through the one version rule. */
    #applySnapshot(body) {
        this.#acceptFleet(body.server_time, body.fleet);

        for (const install of body.installs) {
            for (const row of install.seats) {
                this.#replaceIfHigher(row);
                this.#stampIfHeld(row, body.server_time);
            }
        }
    }

    /**
     * `t` is the sole discriminator (D2 § 8.4).
     *
     * ⚠ EVERY OTHER `t` IS IGNORED AT THIS STEP, BY DESIGN AND NOT BY OVERSIGHT: `room.map` and
     * `building.layout` are step 7's, `seat.retired` and `fleet.reload`/`feed.close` are steps 10
     * and 8's, `coord.*` has its own surface, and an unrecognised `t` is D2's own forward
     * compatibility rule — ignore it. Counting the unknown ones is step 8's strip.
     */
    #dispatch(envelope) {
        this.#observeTime(envelope.server_time);

        switch (envelope.t) {
            case 'seat.delta':
                this.#applyDelta(envelope);
                break;
            case 'feed.heartbeat':
            case 'fleet.health':
                if (this.#acceptFleet(envelope.server_time, envelope.fleet)) {
                    this.#check();
                }
                break;
            default:
                break;
        }
    }

    /**
     * A seat's deltas are held back while the first snapshot is in flight (for EVERY key, held or
     * not) or while a seat fetch for that key is in flight.
     *
     * ⛔ A DISCOVERY FETCH IS NOT ON THIS LIST. It buffers nothing — see this file's header.
     */
    #buffering(k) {
        return this.#phase === 'connecting' || this.#pendingSeats.has(k);
    }

    /**
     * The key's state is now trusted: stamp it and clear the fail streak.
     *
     * ⛔ ONLY PATHS THAT ADVANCE `state_version` THROUGH A TRUSTED CHAIN CALL THIS — a `+1` merge
     * and a replacement by a higher row. A `+1` merge is exactly as much a confirmation as a
     * fetch: the version guard is the same guarantee either way. What must NOT clear the streak is
     * "a delta arrived": a delta that names a gap is the thing that STARTS the read whose failure
     * the streak counts.
     */
    #confirm(k) {
        this.#confirmedAt.set(k, this.#clock.now());
        this.#failStreak.delete(k);
    }

    /**
     * § 2.2 step 5 — THE one path for every delta, live or drained.
     *
     * Held nothing → buffer it and start an INSERT fetch (§ 2.3 row 1). At or below the held
     * version → discard (§ 2.2's watermark: the snapshot already contains it). Exactly one above →
     * shallow-merge the `patch` (D2 § 8.3: a delta's patch is a shallow member set, never deep).
     * More than one above → a gap: buffer it and start a RESYNC fetch.
     */
    #applyDelta(d) {
        if (this.#phase === 'snapshot-failed' || this.#phase === 'idle') {
            return;
        }

        const k = key(d.install_id, d.seat_id);

        if (this.#buffering(k)) {
            this.#push(k, d);

            return;
        }

        const held = this.#seats.get(k);

        if (held === undefined) {
            this.#push(k, d);
            this.#fetchSeat(d.install_id, d.seat_id, null);

            return;
        }

        if (d.state_version <= held.state_version) {
            return;
        }

        if (d.state_version === held.state_version + 1) {
            this.#seats.set(k, { ...held, ...d.patch, state_version: d.state_version });
            this.#confirm(k);
            this.#stamp(k, Object.keys(d.patch), d.server_time);

            return;
        }

        this.#push(k, d);
        this.#fetchSeat(d.install_id, d.seat_id, held.state_version);
    }

    #push(k, d) {
        if (!this.#buffers.has(k)) {
            this.#buffers.set(k, []);
        }

        this.#buffers.get(k).push(d);
    }

    /**
     * § 2.3 row 1's insert fetch and § 2.2's resync, which are one request with one difference:
     * a resync names the last version the client actually applied.
     *
     * ⛔ THE RESYNC LINE IS WRITTEN WHEN THE REQUEST IS ISSUED, NOT WHEN IT ANSWERS. § 5.5's record
     * narrates what the client DID; a resync that never answers is still a resync the client ran.
     *
     * ⛔ THE REST ENVELOPE NEVER ENTERS THE HELD MAP. `FleetController::seat()` answers
     * `api_version`, `server_time`, the seat object's members, then `detail` — three of those are
     * the envelope, not the seat, and a held object carrying them would put `server_time` one
     * shallow merge away from being treated as a seat member by every later reader.
     */
    async #fetchSeat(installId, seatId, resyncFrom) {
        const k = key(installId, seatId);

        this.#pendingSeats.add(k);

        const path = resyncFrom === null
            ? seatPath(installId, seatId)
            : `${seatPath(installId, seatId)}?resync_from=${resyncFrom}`;

        if (resyncFrom !== null) {
            this.#line(`resync ${k} from ${resyncFrom}`);
        }

        const res = await this.#get(path);

        this.#pendingSeats.delete(k);

        if (failed(res)) {
            // § 2.3 row 5: ONE rule for every failed read, and an in-flight discovery is not an
            // exception to it. The count above already carries the whole anti-flicker property —
            // one failure leaves the streak at 1, below the threshold, and any good read clears it
            // — so excusing a failure that lands under a discovery only ever changed behaviour at
            // the SECOND consecutive failure, which is the one that matters: a seat re-read once
            // per discovery generation never HAS a second failure inside one, so the excuse
            // applied forever and the desk drew a stale character indefinitely. The operator ruled
            // that the floor may say a seat is missing while a discovery that might still repair
            // it is running; a discovery that lands ends it the ordinary way, by repairing the
            // seat.
            //
            // Only a key the client currently HOLDS counts: a failed INSERT fetch for a key never
            // held is the lobby-level disagreement, not a desk this client can name.
            if (this.#seats.has(k)) {
                this.#failStreak.set(k, (this.#failStreak.get(k) ?? 0) + 1);
            }

            // What an in-flight discovery DOES decide, and the only thing: leave this buffer
            // standing for the discovery's own release. The discovery may insert this very key,
            // and draining against nothing would throw the deltas away first.
            if (this.#discovery !== null) {
                return;
            }

            // No discovery in flight: run the buffer against whatever is held now — which may have
            // been set by a discovery that landed while this fetch was outstanding. Discard every
            // entry at or below it, apply the `+1` chain, drop the rest (a gap this fetch would
            // have closed). This branch issues no request; the read is re-issued by the next delta
            // that names a fresh gap, and by a discovery release that re-presents a surviving
            // buffer.
            const buffered = (this.#buffers.get(k) ?? [])
                .sort((a, b) => a.state_version - b.state_version);

            this.#buffers.delete(k);

            for (const d of buffered) {
                const h = this.#seats.get(k);

                if (h === undefined || d.state_version > h.state_version + 1) {
                    break;
                }

                if (d.state_version === h.state_version + 1) {
                    this.#seats.set(k, { ...h, ...d.patch, state_version: d.state_version });
                    this.#confirm(k);
                    this.#stamp(k, Object.keys(d.patch), d.server_time);
                }
            }

            return;
        }

        const { api_version, server_time, detail, ...row } = res.body;
        const inserted = !this.#seats.has(k);

        this.#replaceIfHigher(row);
        this.#stampIfHeld(row, res.body.server_time);
        this.#confirm(k);

        if (inserted) {
            this.#line(`seat added to the floor: ${k}`);
        }

        this.#release(k);
    }

    /**
     * § 2.2's version rule, in the one place every row passes through.
     *
     * ⛔ THE REPLACEMENT IS THE WHOLE ROW, NEVER A MERGE WITH THE OLDER OBJECT (§ 2.2). Merging a
     * stale row into a newer one produces an object that existed at no instant on the server —
     * newer members from the delta chain beside older ones from the snapshot — and nothing
     * downstream could tell it from a real seat.
     */
    #replaceIfHigher(row) {
        const k = key(row.install_id, row.seat_id);
        const held = this.#seats.get(k);

        if (held === undefined || row.state_version > held.state_version) {
            this.#seats.set(k, row);
            this.#confirm(k);
        }
    }

    /**
     * § 2.4's stamp rule, for a whole row: every member it carries was delivered by the response
     * whose `server_time` is given — but ONLY if the row is now what the client holds. A row the
     * version rule dropped delivered nothing the client shows, and stamping from it would date the
     * held values to a response they did not come from.
     */
    #stampIfHeld(row, serverTime) {
        const k = key(row.install_id, row.seat_id);

        if (this.#seats.get(k) === row) {
            this.#stamps.set(k, {});
            this.#stamp(k, Object.keys(row), serverTime);
        }
    }

    /** § 2.4's stamp rule, per member: these members' values arrived with `serverTime`. */
    #stamp(k, members, serverTime) {
        const stamps = this.#stamps.get(k) ?? {};

        for (const member of members) {
            stamps[member] = serverTime ?? null;
        }

        this.#stamps.set(k, stamps);
    }

    /**
     * Hand a key's held-back deltas back to the one primitive, oldest first.
     *
     * ⛔ THEY GO THROUGH `#applyDelta`, NOT STRAIGHT INTO THE MAP. That is what makes a GAP inside
     * a buffer start a resync, and a buffered delta for a seat still unheld start the insert
     * fetch, instead of being merged blind into whatever is there.
     */
    #release(k) {
        if (this.#buffering(k)) {
            return;
        }

        const buffered = this.#buffers.get(k) ?? [];

        this.#buffers.delete(k);
        buffered.sort((a, b) => a.state_version - b.state_version);

        for (const d of buffered) {
            this.#applyDelta(d);
        }
    }

    /**
     * Appendix A T40: a `fleet{}` is taken only from an envelope NEWER than the one whose
     * `fleet{}` is held — the stream's own ordering guarantee is per seat, not fleet-wide, so an
     * older heartbeat overtaking a newer one would otherwise walk `seats_total` backwards and
     * mint a disagreement that does not exist.
     *
     * @returns {boolean} whether this envelope's `fleet{}` was admitted
     */
    #acceptFleet(serverTime, fleet) {
        if (this.#fleetTime !== null && !(serverTime > this.#fleetTime)) {
            return false;
        }

        this.#fleetTime = serverTime;
        this.#fleetTotal = fleet?.seats_total;

        return true;
    }

    /**
     * § 4.1's discrepancy trigger, which the protocol owns: the seats the client HOLDS against the
     * `fleet{}`'s own `seats_total`, spending one fetch per distinct pair.
     *
     * ⛔ ONE DISCOVERY AT A TIME. A `fleet{}` admitted while one is in flight spends nothing and
     * issues nothing; the check is re-run once, against what the client then holds, when that
     * discovery ends. Without it a burst of heartbeats during one slow round trip issues a
     * snapshot fetch each, every one of them answering the question the in-flight one is already
     * answering.
     *
     * ⚠ BEFORE `live` THERE IS NO POPULATION TO COMPARE, so the check does not run: the client
     * holds no seats yet and every heartbeat would read as a total disagreement.
     */
    #check() {
        if (this.#phase !== 'live') {
            return;
        }

        if (this.#discovery !== null) {
            this.#recheck = true;

            return;
        }

        const held = this.#seats.size;
        const total = this.#fleetTotal;

        if (this.#budget.admits(held, total)) {
            this.#discover(held, total);
        }
    }

    /**
     * § 2.3 row 3's discovery fetch — one full snapshot, applied under the version rule, which IS
     * the admission of every install it carries.
     *
     * ⛔ A FAILED DISCOVERY REFUNDS ITS PAIR. It answered nothing, so the disagreement it was sent
     * to resolve has not had its one check yet; the next `fleet{}` carrying the same pair retries.
     *
     * ⛔ EVERY BUFFER IS RELEASED WHEN IT ENDS, EITHER WAY. A seat fetch that failed while this
     * discovery was in flight deliberately left its buffer standing for exactly this moment — and
     * a discovery FAILING is no reason to keep those deltas waiting for a discovery that is over.
     */
    async #discover(held, total) {
        this.#discovery = [held, total];

        const res = await this.#get(SNAPSHOT_PATH);

        if (failed(res)) {
            this.#budget.refund(held, total);
        } else {
            this.#applySnapshot(res.body);
        }

        this.#discovery = null;

        for (const k of [...this.#buffers.keys()]) {
            this.#release(k);
        }

        if (this.#recheck) {
            this.#recheck = false;
            this.#check();
        }
    }

    /**
     * § 5.5's record: newest first, capped, text only.
     *
     * ⚠ NO WORDING IS RATIFIED. § 5.5 states what a line NAMES and publishes no string, so these
     * are written from the facts the section names and nothing else; the tests assert content and
     * identity, never a pinned sentence.
     */
    #line(text) {
        this.#log.unshift(`${this.#clock.now()} ${text}`);

        if (this.#log.length > LOG_CAP) {
            this.#log.length = LOG_CAP;
        }
    }
}

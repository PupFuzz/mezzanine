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
 * ⛔ NOTHING HERE DRAWS ANYTHING, AND NOTHING HERE READS AN AMBIENT TIMER OR CLOCK. The protocol
 * holds data: the seat map, each held member's delivery stamp (§ 2.4's stamp rule, `stampOf`), the
 * record's lines, the clock offset, the read status, and the stream recovery's own state (`feed`).
 * Every render is another module's (Appendix B rows 4–10 — the status strip is
 * `wire/status-strip.js` and the failure renders `wire/failure-render.js`, both pure over what this
 * client exposes). No `setTimeout`, no `Date.now`, no `Math.random`: the clock AND the scheduler are
 * injected, which is what lets the harness replay a scenario and get the same records every time —
 * `Tests\Feature\Floor`'s determinism check scans this file's own source for those identifiers.
 *
 * ⛔ THE STREAM RECOVERY IS HERE (§ 2.2 steps 7–9, § 9 F1/F3/F4–F8/F19/F20, Appendix B row 8),
 * BECAUSE ONLY THE PROTOCOL SEES THE STREAM. The scheduler (`{after(ms, fn), cancel(handle)}`) is the
 * fourth constructor argument; a client constructed WITHOUT one has no recovery at all — it never
 * notices a dead feed and never re-opens — and that is the harness's replay of the pre-recovery
 * protocol, never a page's configuration: `floor/main.js` always passes one, and
 * `Tests\Feature\Floor\FloorPageWiringTest` reds if it stops. The client OWNS THE RECONNECT: an
 * errored `EventSource` is closed at once, so the browser's own reconnect — which re-runs none of
 * steps 1–5 — is never inherited.
 *
 * ⛔ THE `fetch` AND THE `EventSource` ARE INJECTED, for the reason `wire/building.js` gives: there
 * is no browser on the build host, so every decision here is driven under `node` against a
 * scripted pair (`tests/Feature/Floor/fleet-client-probe.mjs`).
 *
 * ⚠ WHO CONSTRUCTS THIS: the floor page (`floor/main.js`, Appendix B row 8) and the harness. The
 * lobby keeps its own one-shot fetch and its own § 4.1 trigger until step 9 replaces that trigger
 * with this one.
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
 * D2 § 8.1's `feed_version` this client was built for. "The client JavaScript is served by the same
 * deploy that serves the feed", so it is the release's own constant — `App\Feed\FeedEnvelope::
 * FEED_VERSION` — and `Tests\Feature\Floor\TheStreamRecoveryIsTheDocumentsTest` reds if the two
 * disagree. An envelope carrying any other value is § 9 F8.
 */
export const FEED_VERSION = 1;

/** § 9 F1: "no message of any kind for 45 s" — D2 § 8.3's three heartbeat intervals. */
export const SILENCE_MS = 45000;

/** § 2.2 / § 9 F1: the one cadence the client polls and re-opens at. */
export const CADENCE_MS = 10000;

/** § 2.2: `unavailable`'s backed-off cadence doubles from `CADENCE_MS` to this ceiling. */
export const BACKOFF_CEILING_MS = 80000;

/** § 2.2's reload grace, derived at § 12's row of that name. */
export const RELOAD_GRACE_MS = 60000;

/** § 5.7 / § 12's *Held coordination envelopes*: the most this client holds (§ 14 item 25). */
export const COORD_CAP = 1000;

/** Every `t` D2 § 8.3 publishes. Anything else is D2's forward-compatibility case: ignore and count. */
const KNOWN_TYPES = new Set([
    'seat.delta', 'seat.retired', 'fleet.health', 'feed.heartbeat', 'fleet.reload', 'feed.close',
    'room.map', 'building.layout', 'coord.thread', 'coord.round',
]);

/** The thread an envelope belongs to — § 5.7's grouping key, and the eviction unit. */
function threadOf(envelope) {
    const body = envelope.t === 'coord.round' ? envelope.coord_round : envelope.coord_thread;

    return body !== null && typeof body === 'object' ? (body.thread_ref ?? null) : null;
}

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

    /**
     * D2 § 8.3.3's two COORDINATION OBJECTS, in arrival order — the envelopes this client applied,
     * which `coord/coord-model.js` renders and `floor/floor-screen.js` draws the line from
     * (§ 5.7). card#7341 step 7 is the first consumer that applies them.
     *
     * ⛔ THEY RIDE THE FEED AND NO REST SURFACE CARRIES THEM (D2's no-snapshot ruling), so this
     * list starts EMPTY on every connect and "a client that has just connected draws no thread line
     * until the next post on that thread" (§ 5.7). An empty coordination layer is therefore never
     * evidence that the fleet is not talking, and nothing may render it as *quiet*.
     *
     * ⛔ NO DEDUPLICATION HERE, deliberately. Identity is `post_ref` / `thread_ref` and what a
     * consumer may do with a repeat is D2's own rule, which `coordModel()` already implements over
     * a list — a second implementation here would be two homes for one rule (the defect card#7341
     * step 6 removed one class up).
     *
     * ⛔ HELD TO `COORD_CAP`, EVICTED BY WHOLE THREADS (§ 5.7, § 14 item 25) — `#holdCoord()`.
     */
    #coord = [];

    /**
     * THE WIRE JOURNAL: every message this client handled and every seat row it took from a REST
     * surface, in handling order, with what it DID with each. It is the renderer's one honest
     * source for *did the client apply this, and what did it carry* — and every input
     * `docs/design/FLOOR.md § 6.2`'s `edge` conditions are written over, none of which is
     * recoverable from a diff of two renders (§ 2.5: re-sending a held value still counts as a
     * change, which is what `changed[]` is for).
     *
     * ⛔ IT SAYS WHAT HAPPENED AND DECIDES NOTHING. § 6.5's rule — a snapshot, a resync, a fetch
     * and a reconnect animate nothing — is `wire/animation-set.js`'s to keep, which is why the
     * snapshot's rows and the fetch's are journalled here beside the deltas rather than withheld:
     * a renderer that never sees them cannot be shown to have refused them, and an empty journal
     * would satisfy *no `edge` row* for free.
     *
     * ⛔ IT IS DRAINED, NOT READ (`takeWire()` below), so it only ever holds what happened between
     * two renders. A client whose renderer never drains it grows it without bound, exactly as an
     * unreleased `#buffers` entry does, and that is a wiring defect in the page rather than a case
     * to handle here.
     */
    #wire = [];

    /** § 2.3 row 5: key → consecutive failed reads, cleared by any confirmed apply. */
    #failStreak = new Map();

    /** key → the browser clock's reading of when this client last trusted an apply for the key. */
    #confirmedAt = new Map();

    /** The `thread_ref`s whose oldest rounds `COORD_CAP` dropped — § 5.7's *N+* bead count. */
    #coordTruncated = new Set();

    /** The injected scheduler, or `null` for the harness's pre-recovery replay (see the header). */
    #timers = null;

    /**
     * The CURRENT stream attempt: `{ id, at, opened, spoke, refused, ended, reconnect, snapshotOnOpen }`.
     * `at` is the browser clock when it was constructed; `reconnect` is false for the connect's own.
     */
    #stream = null;

    #streamSeq = 0;

    /**
     * The recovery's mode — `normal`, `down` (§ 9 F1: polling and re-opening on the 10 s cadence),
     * `reconnecting` (F3 `stalled`), `backoff` (F3 `unavailable` / F4 / F5: the backed-off cadence),
     * `grace` (F3 `reload`: § 2.2's reload grace), and the two TERMINAL ones, `signed-out` (F6/F7)
     * and `reload-required` (F8), from which nothing is re-opened.
     */
    #mode = 'normal';

    #silenceTimer = null;

    #retryTimer = null;

    #graceTimer = null;

    /** The next backed-off interval. */
    #backoff = CADENCE_MS;

    /** The last message of any kind: `{ at: browser ms, server_time }`, or `null`. */
    #lastMessage = null;

    /** § 9 F4/F5: the store could not be read — `{ server_time }` of the refusal that said so. */
    #store = null;

    /** § 9 F4's other refusals: the last failed snapshot read, `{ status, error }`. */
    #refusal = null;

    /** § 9 F6: `{ since }` — the wire instant this client was last live — once any read returned 401. */
    #signedOut = null;

    /** Whether a full snapshot has ever been applied — § 9 F4's *on a cold start there is no floor to keep*. */
    #applied = false;

    /** A poll's snapshot read in flight — one at a time, so a stalled REST plane is asked no faster (F20). */
    #polling = false;

    /** § 5.5's *resyncs: N* — the F2 resyncs this client has ISSUED since it loaded. */
    #resyncs = 0;

    /** D2 § 8.3: an unrecognised `t` is ignored AND COUNTED. */
    #unknownTypes = 0;

    /** The held `fleet{}` itself, for the status strip's indicators (§ 4.2: the lobby's, on the floor). */
    #fleet = null;

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
     *   offset is refreshed
     * @param {{after: function(number, Function): *, cancel: function(*): void}|null} [timersImpl]
     *   the stream recovery's scheduler. Absent, this client recovers nothing (see the header).
     */
    constructor(fetchImpl, EventSourceImpl, clockImpl, timersImpl = null) {
        this.#fetch = fetchImpl;
        this.#EventSourceImpl = EventSourceImpl;
        this.#clock = clockImpl;
        this.#timers = timersImpl;
    }

    /**
     * The stream recovery's state, for the status strip and the failure renders — every field a fact
     * about THIS CLIENT (§ 2.1 row 7), never about a seat. A frozen copy.
     *
     * `silent` is § 9 F1's test, read now: no message of any kind for `SILENCE_MS`, measured from the
     * last message — or from the first stream's construction when none has arrived yet.
     */
    get feed() {
        const now = this.#clock.now();
        const since = this.#lastMessage?.at ?? this.#stream?.at ?? now;
        const stream = this.#stream;

        return Object.freeze({
            mode: this.#mode,
            down_cause: this.#mode === 'down' ? this.#downCause : null,
            silent: now - since >= SILENCE_MS,
            connected: stream !== null && stream.opened && !stream.ended,
            stream_opened: stream?.opened ?? false,
            stream_spoke: stream?.spoke ?? false,
            stream_refused: stream?.refused ?? false,
            last_message: this.#lastMessage === null ? null : Object.freeze({ ...this.#lastMessage }),
            store: this.#store === null ? null : Object.freeze({ ...this.#store }),
            refusal: this.#refusal === null ? null : Object.freeze({ ...this.#refusal }),
            signed_out: this.#signedOut === null ? null : Object.freeze({ ...this.#signedOut }),
            reload_required: this.#mode === 'reload-required',
            applied: this.#applied,
            resyncs: this.#resyncs,
            unknown_messages: this.#unknownTypes,
        });
    }

    /** The `fleet{}` this client holds (T40's filter decides which), or `null` — a copy. */
    get fleet() {
        return this.#fleet === null ? null : JSON.parse(JSON.stringify(this.#fleet));
    }

    /** § 5.7's *N+*: the threads `COORD_CAP` cut, whose bead count is a lower bound. */
    get coordTruncated() {
        return [...this.#coordTruncated];
    }

    /**
     * § 9 F6's trigger from a read this module does not issue itself — the building surface's layout
     * and map requests go through `wire/building.js`, and *any read returns 401* covers them too.
     */
    readRefused(status) {
        if (status === 401) {
            this.#endSession();
        }
    }

    /** The held seat map — a COPY, so a caller cannot mutate what the protocol holds. */
    get seats() {
        return new Map(this.#seats);
    }

    /** § 5.5's record, newest first, at most `LOG_CAP` lines — a copy, for the same reason. */
    get eventLog() {
        return [...this.#log];
    }

    /** D2 § 8.3.3's coordination envelopes this client has applied, oldest first — a copy. */
    get coordMessages() {
        return [...this.#coord];
    }

    /**
     * Write one line into § 5.5's record from a surface ABOVE the protocol.
     *
     * ⚠ THE RECORD IS THIS MODULE's ARTIFACT AND THAT IS WHY THE DOOR IS HERE RATHER THAN THE
     * STORE BEING SHARED. Appendix B row 13 owes step 7 "one event-log line written" for an applied
     * `room.map`: `wire/building.js` RETURNS the line and does not hold a record, and the floor
     * route is what delivers the message to that apply — so the line is composed there and written
     * here, through the one capped, stamped, newest-first path every other line takes.
     */
    record(text) {
        this.#line(text);
    }

    /**
     * Every message handled and every REST row taken since the last call, oldest first, and the
     * journal is CLEARED by the call.
     *
     * ⛔ WHY A DRAIN AND NOT A CALLBACK. The renderer's own instrument (§ 11's animation log)
     * REFUSES by throwing, and `desk/desk-floor.js` deliberately does not catch. A callback fired
     * from inside `#applyDelta` would carry that throw out through a drain loop and abandon a
     * seat's remaining buffered deltas half-applied — a state no rule in § 2.2 describes. Draining
     * at the render keeps a renderer defect inside the render.
     *
     * Each entry carries `t` — the wire message's own, or `snapshot` / `seat.fetch` for a row a
     * REST surface delivered — and `outcome`:
     *   · `applied`   — a delta merged at `+1`, or a row that replaced what was held
     *   · `buffered`  — held back for the connect window, a gap, or a seat fetch in flight
     *   · `discarded` — at or below the held version (§ 2.2's watermark), a row not higher, or a
     *                   `fleet{}` an older envelope carried (T40)
     *   · `ignored`   — a message this step applies nothing for, and one arriving after a failed
     *                   first snapshot
     * An `applied` `seat.delta` also carries `changed` (D2 § 8.3.1: the patch's own keys) and the
     * held object `before` and `after` the merge.
     *
     * ⚠ ONE OUTCOME IS NOT WRITTEN, named rather than left to be found: a buffered delta the
     * failed-fetch drain abandons at a gap gets its `buffered` line and no resolution line. The
     * drain stops at the gap (`#fetchSeat`) and nothing downstream reads a resolution, so this
     * journal records the buffering and the merges and says nothing about that remainder.
     *
     * @returns {list<object>}
     */
    takeWire() {
        const taken = this.#wire;

        this.#wire = [];

        return taken;
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
     * dispatch: a client in `snapshot-failed` has no population to compare until the recovery's
     * next cold read succeeds, so claiming a pending refresh there is claiming one nothing can start.
     *
     * ⚠ ONE REACHABLE STATE IS NOT CLOSED BY THAT, AND IS NAMED RATHER THAN DESIGNED AROUND: a
     * `live` page whose feed has quietly died has an unspent pair and no heartbeat coming, and
     * this still reports `refreshing: true` until § 9 F1's 45 s pass. From then the status strip
     * says *feed down — polling* (`feed.silent`, `wire/status-strip.js`), and every poll is itself
     * a full snapshot, so the disagreement is re-read on the 10 s cadence either way.
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
        if (this.#es !== null) {
            this.#closeStream();
        }

        const id = ++this.#streamSeq;

        this.#stream = {
            id,
            at: this.#clock.now(),
            opened: false,
            spoke: false,
            refused: false,
            ended: false,
            reconnect: id > 1,
            snapshotOnOpen: false,
        };
        this.#es = new this.#EventSourceImpl('/api/fleet/stream');
        this.#es.addEventListener('mezzanine', (ev) => this.#receive(id, JSON.parse(ev.data)));

        // ⛔ `open` AND `error` ARE LISTENED FOR ONLY WHERE THERE IS A RECOVERY TO RUN. Neither is a
        // message listener — § 2.2's ONE named listener above is still the only one — and without a
        // scheduler there is nothing either could start.
        if (this.#timers !== null) {
            this.#es.addEventListener('open', () => this.#opened(id));
            this.#es.addEventListener('error', () => this.#errored(id));

            if (id === 1) {
                this.#armSilence();
            }
        }
    }

    /** Close the current `EventSource` — ours to close, so the browser's own reconnect never runs. */
    #closeStream() {
        this.#es?.close();
        this.#es = null;

        if (this.#stream !== null) {
            this.#stream.ended = true;
        }
    }

    /** One message from stream `id`. A message from any stream but the current one is dropped. */
    #receive(id, envelope) {
        const stream = this.#stream;

        if (stream === null || stream.id !== id || stream.ended) {
            return;
        }

        // A message is proof of an open stream, whether or not an `open` event was seen.
        stream.opened = true;

        if (this.#mode === 'reload-required' || this.#mode === 'signed-out') {
            this.#note(envelope.t, 'ignored', { server_time: envelope.server_time });

            return;
        }

        // § 9 F8: "an envelope carrying a `feed_version` the client does not know" — on
        // `fleet.reload` first, and on any later envelope. Never the message alone.
        if (envelope.feed_version !== FEED_VERSION) {
            this.#reloadRequired(envelope);

            return;
        }

        const first = !stream.spoke;

        stream.spoke = true;
        this.#lastMessage = { at: this.#clock.now(), server_time: envelope.server_time ?? null };
        this.#armSilence();

        // ⛔ A STREAM THAT SAYS THE STORE CANNOT BE READ IS NOT A RECOVERY. F5's stream opens to say
        // why and ends in the same breath — that is an attempt that "fails the same way" (§ 2.2), so
        // it neither resets the backoff nor establishes a live feed.
        const storeDown = envelope.t === 'fleet.health' && envelope.fleet?.db === 'down';

        if (first && stream.reconnect && !storeDown) {
            this.#recovered(envelope);
        }

        this.#dispatch(envelope);
    }

    /** `open` on stream `id` — the snapshot a grace or backed-off attempt waited for follows it. */
    #opened(id) {
        if (this.#stream === null || this.#stream.id !== id || this.#stream.ended) {
            return;
        }

        this.#stream.opened = true;

        if (this.#stream.snapshotOnOpen) {
            this.#poll();
        }
    }

    /**
     * `error` on stream `id` — § 2.2 step 8. The stream is closed at once: an errored `EventSource`
     * left open is one the browser re-opens on its own schedule, re-running none of steps 1–5.
     */
    #errored(id) {
        const stream = this.#stream;

        if (stream === null || stream.id !== id || stream.ended) {
            return;
        }

        if (!stream.opened) {
            stream.refused = true;
        }

        this.#closeStream();

        // Inside a retry loop an attempt that failed is already followed by the next one.
        if (this.#mode !== 'normal') {
            if (this.#mode === 'down') {
                this.#downCause = stream.refused ? 'refused' : (stream.spoke ? 'silent' : 'never-spoke');
            }

            return;
        }

        // The stream ended with NO `feed.close` (a `feed.close` closes the stream itself, below, so
        // it never reaches here): "a reason the server did not choose, and is F1's silence arriving
        // early" — the deploy's drain included, with no grace (§ 9 F3).
        this.#line(stream.opened ? 'the stream ended without a reason — feed presumed dead' : 'the stream could not be opened');
        this.#presumeDead();
    }

    /**
     * Why the feed was presumed dead, for the strip's words: `never-spoke` — the stream opened and
     * delivered nothing (§ 9 F19: something is buffering it); `refused` — it errored before `open`
     * (F20); `silent` otherwise. Re-read on every attempt that fails, so F20's *on each attempt* is
     * the latest attempt's answer.
     */
    #downCause = null;

    /** § 9 F1: arm the dead-feed timer `SILENCE_MS` after the last message. */
    #armSilence() {
        this.#cancel(this.#silenceTimer);

        const since = this.#lastMessage?.at ?? this.#stream?.at ?? this.#clock.now();

        this.#silenceTimer = this.#after(since + SILENCE_MS - this.#clock.now(), () => this.#silenceElapsed());
    }

    /**
     * 45 s of silence. Inside the reload grace, clause (2) applies instead and the mode is left alone
     * — the *reconnecting* the strip then reads is `wire/status-strip.js`'s, from `feed.silent`.
     */
    #silenceElapsed() {
        this.#silenceTimer = null;

        if (this.#mode === 'normal' || this.#mode === 'reconnecting') {
            this.#line('no message for 45 s — feed presumed dead, polling');
            this.#presumeDead();
        }
    }

    /** § 2.2 step 7: poll and re-open on the 10 s cadence, starting now. */
    #presumeDead() {
        const stream = this.#stream;

        if (stream !== null) {
            this.#downCause = stream.refused ? 'refused' : (stream.opened && !stream.spoke ? 'never-spoke' : 'silent');
        }

        this.#mode = 'down';
        this.#rerun();
    }

    /**
     * § 2.2 step 9, "re-run from step 1": a new stream, and the snapshot — at once, or once the stream
     * has opened where the grace or the backed-off cadence says so ("the first stream that opens
     * carries `fleet.health` as its first message and the snapshot follows", F5; AT-D3-8's *no
     * snapshot poll before the stream re-opened*). The next attempt is scheduled here too, and a
     * recovery cancels it.
     */
    #rerun() {
        this.#retryTimer = null;

        if (this.#terminal()) {
            return;
        }

        const waitForOpen = this.#mode === 'grace' || this.#mode === 'backoff';

        this.#open();
        this.#stream.snapshotOnOpen = waitForOpen;

        if (!waitForOpen) {
            this.#poll();
        }

        this.#schedule(this.#mode === 'backoff' ? this.#nextBackoff() : CADENCE_MS);
    }

    /**
     * One snapshot read for the recovery — the cold connect's own read while nothing has been applied,
     * a warm re-read after. One at a time: a poll still in flight is not joined by a second (F20: "one
     * more request against a pool that has none to give").
     *
     * ⛔ A WARM RE-RUN BUFFERS NOTHING, FOR ADMIT's OWN REASON (§ 2.2). Every row and every delta
     * carries its seat's `state_version`, so the re-read's rows and the new stream's deltas order
     * themselves under the version rule whichever lands first — exactly the argument that retired
     * the second discovery read — and a held seat is never blanked while the read is out ("blanking
     * the floor while reconnecting" is F3's Never).
     */
    #poll() {
        if (!this.#applied) {
            // The cold read's own phase says whether it is in flight.
            if (this.#phase !== 'connecting') {
                this.#phase = 'connecting';
                this.#initialSnapshot();
            }

            return;
        }

        if (this.#polling) {
            return;
        }

        this.#polling = true;
        this.#resnapshot().finally(() => {
            this.#polling = false;
        });
    }

    /** A warm re-run's snapshot, applied under the version rule like any full snapshot. */
    async #resnapshot() {
        const res = await this.#get(SNAPSHOT_PATH);

        if (failed(res) || this.#terminal()) {
            return;
        }

        this.#applySnapshot(res.body);

        for (const k of [...this.#buffers.keys()]) {
            this.#release(k);
        }
    }

    /**
     * The first message on a re-opened stream: the feed is back. The backoff resets (§ 2.2), the
     * pending attempt and the grace are cancelled, and the journal carries the ESTABLISHMENT that
     * § 6.5 lets set the room — a render that re-establishes a live feed, and nothing else.
     */
    #recovered(envelope) {
        this.#cancel(this.#retryTimer);
        this.#cancel(this.#graceTimer);
        this.#retryTimer = null;
        this.#graceTimer = null;
        this.#backoff = CADENCE_MS;
        this.#mode = 'normal';
        this.#line('stream re-opened — feed live');
        this.#note('feed.established', 'applied', { server_time: envelope.server_time ?? null });

        // A cold client whose snapshot read never succeeded has nothing to drain against yet.
        if (!this.#applied) {
            this.#poll();
        }
    }

    /**
     * § 9 F3: the server ended the stream on its own decision, and said why. The stream is closed
     * here rather than on the `error` that follows, because the reason is the last envelope and the
     * end adds nothing to it.
     */
    #serverClosed(reason, serverTime) {
        this.#closeStream();
        this.#line(`the server ended the stream: ${reason}`);

        switch (reason) {
            case 'session':
                // F6's render, immediately — and NO reconnect: "a request the server has just said it
                // will refuse".
                this.#endSession();

                return;
            case 'unavailable':
                // F5: the store cannot be read. F4's statement, immediately, and the BACKED-OFF cadence.
                this.#store = { server_time: this.#store?.server_time ?? serverTime ?? null };
                this.#mode = 'backoff';
                this.#schedule(this.#nextBackoff());

                return;
            case 'reload':
                // A `feed_version` this client knows (an unknown one was F8 already, on the message):
                // § 2.2's reload grace. Re-open 10 s after the `feed.close`, render nothing.
                this.#mode = 'grace';
                this.#cancel(this.#graceTimer);
                this.#graceTimer = this.#after(RELOAD_GRACE_MS, () => this.#graceEnded());
                this.#schedule(CADENCE_MS);

                return;
            case 'stalled':
                this.#mode = 'reconnecting';
                this.#schedule(CADENCE_MS);

                return;
            default:
                // D2 § 8.3 publishes exactly four; a fifth is a reason this client cannot act on, and
                // a stream ended for a reason it cannot act on is step 7's.
                this.#presumeDead();
        }
    }

    /** § 2.2 clause (1): the grace does not outlast itself. Past it, step 7's path. */
    #graceEnded() {
        this.#graceTimer = null;

        if (this.#mode === 'grace') {
            this.#line('the reload grace ended with no stream open — feed presumed dead, polling');
            this.#mode = 'down';

            // The attempt on the cadence is what polls; start one now if none is pending.
            if (this.#retryTimer === null) {
                this.#rerun();
            }
        }
    }

    /** § 9 F6: any read returned 401, or the server said the session is gone. Nothing re-opens. */
    #endSession() {
        if (this.#signedOut !== null) {
            return;
        }

        this.#signedOut = { since: this.#lastMessage?.server_time ?? this.#fleetTime ?? null };
        this.#mode = 'signed-out';
        this.#stopRecovery();
        this.#closeStream();
        this.#line('the session is no longer valid — sign in to continue');
    }

    /** § 9 F8: a `feed_version` this client does not know. Delta application stops; nothing re-opens. */
    #reloadRequired(envelope) {
        this.#mode = 'reload-required';
        this.#stopRecovery();
        this.#note(envelope.t, 'ignored', { server_time: envelope.server_time, feed_version: envelope.feed_version ?? null });
        this.#line(`a new version was deployed (feed_version ${envelope.feed_version}) — reload required`);
    }

    #terminal() {
        return this.#mode === 'signed-out' || this.#mode === 'reload-required';
    }

    #stopRecovery() {
        this.#cancel(this.#silenceTimer);
        this.#cancel(this.#retryTimer);
        this.#cancel(this.#graceTimer);
        this.#silenceTimer = null;
        this.#retryTimer = null;
        this.#graceTimer = null;
    }

    /** The next attempt, replacing any pending one. */
    #schedule(ms) {
        this.#cancel(this.#retryTimer);
        this.#retryTimer = this.#after(ms, () => this.#rerun());
    }

    /** § 2.2's backed-off cadence: 10, 20, 40, 80, and 80 thereafter. */
    #nextBackoff() {
        const delay = this.#backoff;

        this.#backoff = Math.min(this.#backoff * 2, BACKOFF_CEILING_MS);

        return delay;
    }

    /** The injected scheduler, or nothing at all without one. */
    #after(ms, fn) {
        return this.#timers === null ? null : this.#timers.after(Math.max(0, ms), fn);
    }

    #cancel(handle) {
        if (handle !== null && handle !== undefined && this.#timers !== null) {
            this.#timers.cancel(handle);
        }
    }

    /**
     * What every snapshot read's answer says about the recovery (§ 9 F4, F6). A discovery's read is a
     * snapshot read too — its refusal is as true — but it schedules nothing: the discovery budget is
     * its retry.
     */
    #snapshotAnswered(res, discovery) {
        if (!failed(res)) {
            this.#refusal = null;

            if (res.body.fleet?.db !== 'down') {
                this.#store = null;
            }

            return;
        }

        if (res.status === 401) {
            return;
        }

        // D2 § 2.2: a snapshot `503` is `fleet_unavailable` — the store could not be read.
        if (res.status === 503) {
            this.#store = { server_time: res.body.server_time ?? null };
        } else {
            this.#refusal = { status: res.status, error: typeof res.body?.error === 'string' ? res.body.error : null };
        }

        // F4: "retry the snapshot with backoff". A read failing inside a retry loop is that loop's
        // attempt failing, and the loop's next attempt is already scheduled.
        if (!discovery && this.#mode === 'normal') {
            this.#mode = 'backoff';
            this.#schedule(this.#nextBackoff());
        }
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

    /**
     * One GET, with § 2.4's offset refreshed from any body that carries `server_time` — and the ONE
     * place every read this module issues is seen, so § 9 F6's *any read returns 401* is one check
     * rather than one per caller.
     */
    async #get(path, { discovery = false } = {}) {
        const res = await request(this.#fetch, path);

        if (res.body !== null) {
            this.#observeTime(res.body.server_time);
        }

        if (res.status === 401) {
            this.#endSession();
        }

        if (path === SNAPSHOT_PATH) {
            this.#snapshotAnswered(res, discovery);
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
     * ⚠ A FAILED COLD READ STOPS THE APPLY: the buffers are discarded, every later delta is
     * ignored, and `phase` stays `snapshot-failed` until the recovery's next cold read (`#poll`,
     * on the backed-off cadence F4 names) re-enters this method. Patching held-nothing from deltas
     * would be § 2.2's forbidden "partial object" built out of patches.
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

        // § 6.5: the connect sequence's snapshot is the render that ESTABLISHES a live feed — the
        // first one only. A cold RETRY's read is the same method and is not a live feed coming back;
        // that is the re-opened stream's first message (`#recovered`).
        if (!this.#applied && this.#streamSeq === 1) {
            this.#note('feed.established', 'applied', { server_time: res.body.server_time ?? null });
        }

        this.#applied = true;

        for (const k of [...this.#buffers.keys()]) {
            this.#release(k);
        }
    }

    /** A full snapshot — the connect one and a discovery's — through the one version rule. */
    #applySnapshot(body) {
        this.#acceptFleet(body.server_time, body.fleet);

        for (const install of body.installs) {
            for (const row of install.seats) {
                this.#replaceIfHigher(row, 'snapshot', body.server_time);
                this.#stampIfHeld(row, body.server_time);
            }
        }
    }

    /**
     * `t` is the sole discriminator (D2 § 8.4).
     *
     * ⚠ EVERY OTHER `t` IS IGNORED, BY DESIGN AND NOT BY OVERSIGHT: `room.map` and
     * `building.layout` are journalled and applied by the floor screen (step 7) through
     * `wire/building.js`, because an authored document is FETCHED by version and no message carries
     * one; `seat.retired` is step 10's; and an unrecognised `t` is D2's own forward compatibility
     * rule — ignore it and count it (`feed.unknown_messages`). § 5.5 publishes no narration line for
     * that count, so the strip draws none.
     */
    #dispatch(envelope) {
        this.#observeTime(envelope.server_time);

        switch (envelope.t) {
            case 'seat.delta':
                this.#applyDelta(envelope);
                break;
            case 'feed.heartbeat':
            case 'fleet.health':
                // ⛔ THE JOURNAL LINE IS WRITTEN FOR THE MESSAGE RECEIVED, NOT FOR THE `fleet{}`
                // ADMITTED. § 6.2's A14 and A17 fire on "each `feed.heartbeat` message received",
                // and T40's filter below decides only whose `fleet{}` this client holds — a
                // heartbeat overtaken by a newer one still arrived, and a room render stopped on
                // it would claim § 9 F1's feed-down condition on a live feed.
                // § 9 F5: `db: "down"` is the store-unavailable render, whatever else follows it.
                if (envelope.fleet?.db === 'down') {
                    this.#store = { server_time: envelope.server_time ?? null };
                } else if (typeof envelope.fleet?.db === 'string') {
                    this.#store = null;
                }

                if (this.#acceptFleet(envelope.server_time, envelope.fleet)) {
                    this.#note(envelope.t, 'applied', { server_time: envelope.server_time });
                    this.#check();
                } else {
                    this.#note(envelope.t, 'discarded', { server_time: envelope.server_time });
                }
                break;
            case 'room.map':
            case 'building.layout':
                // ⛔ THE OUTCOME IS THIS MODULE'S AND IT IS HONEST: the PROTOCOL applies nothing for
                // a layout act — it holds no layout and no map — so `ignored` is what it did. What
                // applies them is `wire/building.js`, driven by the floor screen off this very
                // journal (§ 2.5's two rules, § 4.4's floor route), and that is also why the line
                // carries the members those applies read: a journal line that named the message
                // type and dropped its version would leave the screen to compare a version it was
                // never told, which is how a `building.layout` whose version EQUALS the held one
                // gets re-fetched for nothing.
                this.#note(envelope.t, 'ignored', {
                    server_time: envelope.server_time,
                    install_id: envelope.install_id ?? null,
                    map_version: envelope.map_version ?? null,
                    layout_version: envelope.layout_version ?? null,
                });
                break;
            case 'feed.close':
                this.#note(envelope.t, 'applied', { server_time: envelope.server_time, reason: envelope.reason ?? null });

                if (this.#timers !== null) {
                    this.#serverClosed(envelope.reason ?? null, envelope.server_time ?? null);
                }
                break;
            case 'fleet.reload':
                // A `feed_version` this client knows — F8 was decided in `#receive` — so the deploy
                // moved nothing this client reads, and the `feed.close{reload}` that follows is what
                // is acted on (operator ruling A4).
                this.#note(envelope.t, 'applied', { server_time: envelope.server_time, feed_version: envelope.feed_version });
                break;
            case 'coord.thread':
            case 'coord.round':
                // ⛔ THESE ARE HELD, NOT MERELY SEEN (D2 § 8.3.3). No REST surface carries them, so
                // the stream is the only place they exist and a renderer that did not hold them
                // could draw a thread only for as long as one message was in flight. § 5.7 clause 3
                // scopes each to the room its own `install_id` names, which is the RENDERER's
                // filter and not a reason to drop one here: a floor draws several rooms.
                this.#holdCoord(envelope);
                this.#note(envelope.t, 'applied', {
                    server_time: envelope.server_time,
                    install_id: this.#coordBody(envelope)?.install_id ?? null,
                    // § 11's `cause` for A18/A19/A20 — the identity of the message that caused the
                    // row, which is the whole of what D2 publishes as *the identities a consumer
                    // needs*.
                    coord_ref: envelope.t === 'coord.round'
                        ? (this.#coordBody(envelope)?.post_ref ?? null)
                        : (this.#coordBody(envelope)?.thread_ref ?? null),
                });
                break;
            default:
                // D2 § 8.3: an unrecognised `t` is ignored AND COUNTED. A known one this protocol
                // applies nothing for (`seat.retired` until Appendix B step 10) is only ignored.
                if (!KNOWN_TYPES.has(envelope.t)) {
                    this.#unknownTypes++;
                }

                this.#note(envelope.t, 'ignored', { server_time: envelope.server_time });
                break;
        }
    }

    /**
     * § 5.7's bound on the coordination envelopes this client holds (§ 14 item 25): at most
     * `COORD_CAP`, evicting WHOLE THREADS, least recently received first.
     *
     * ⛔ A THREAD LEAVES WHOLE — its `coord.thread` and every round together — so an evicted thread
     * renders exactly as one a just-connected client has not seen, and every surviving thread keeps
     * a complete bead count. The one case that unit cannot satisfy is a single thread that alone
     * exceeds the cap: it keeps its newest `coord.thread` and drops its OLDEST rounds, and its bead
     * count becomes a lower bound (`coordTruncated`, rendered *N+*).
     */
    #holdCoord(envelope) {
        this.#coord.push(envelope);

        while (this.#coord.length > COORD_CAP) {
            // Recency is the index of a thread's newest envelope: the list is in arrival order.
            const newest = new Map();

            this.#coord.forEach((e, i) => newest.set(threadOf(e), i));

            if (newest.size > 1) {
                const victim = [...newest.entries()].sort((a, b) => a[1] - b[1])[0][0];

                this.#coord = this.#coord.filter((e) => threadOf(e) !== victim);
                this.#coordTruncated.delete(victim);

                continue;
            }

            // One thread alone over the cap: drop its oldest envelope that is not its newest
            // `coord.thread` — the object the line's lifecycle is rendered from.
            const lastThread = this.#coord.findLastIndex((e) => e.t === 'coord.thread');
            const drop = this.#coord.findIndex((e, i) => i !== lastThread);

            if (this.#coord[drop].t === 'coord.round') {
                this.#coordTruncated.add(threadOf(this.#coord[drop]));
            }

            this.#coord.splice(drop, 1);
        }
    }

    /** The object under a coordination envelope — D2 § 8.3.3's two member names, and no third. */
    #coordBody(envelope) {
        const body = envelope.t === 'coord.round' ? envelope.coord_round : envelope.coord_thread;

        return body !== null && typeof body === 'object' ? body : null;
    }

    /** One journal line. Every write to `#wire` is here, so no path can invent a shape. */
    #note(t, outcome, fields = {}) {
        this.#wire.push({ t, outcome, ...fields });
    }

    /** One journal line for a delta, carrying the members every § 6.2 `edge` row is written over. */
    #noteDelta(d, outcome, fields = {}) {
        this.#note('seat.delta', outcome, {
            install_id: d.install_id,
            seat_id: d.seat_id,
            state_version: d.state_version,
            server_time: d.server_time,
            ...fields,
        });
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
        if (this.#phase === 'snapshot-failed' || this.#phase === 'idle' || this.#terminal()) {
            this.#noteDelta(d, 'ignored');

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
            this.#noteDelta(d, 'discarded');

            return;
        }

        if (d.state_version === held.state_version + 1) {
            this.#merge(k, held, d);

            return;
        }

        this.#push(k, d);
        this.#fetchSeat(d.install_id, d.seat_id, held.state_version);
    }

    /**
     * § 2.2's `+1` shallow merge, in the ONE place both paths that perform it reach it.
     *
     * ⚠ HOISTED HERE AT CARD#7341 STEP 6, AT ITS SECOND REAL CALLER. `#applyDelta` and
     * `#fetchSeat`'s failure drain each carried their own copy of these three lines; a journal line
     * written into one of them would have left the other applying deltas the renderer never hears
     * about — the animation a viewer sees and the log the honesty tests read parting company on
     * exactly the path a failed seat fetch takes.
     */
    #merge(k, held, d) {
        const after = { ...held, ...d.patch, state_version: d.state_version };

        this.#seats.set(k, after);
        this.#confirm(k);
        this.#stamp(k, Object.keys(d.patch), d.server_time);
        // D2 § 8.3.1: `changed` is the patch's own keys, which is what § 6.2's `edge` conditions
        // are gated on — never a diff, which cannot tell a re-sent value from one never sent.
        this.#noteDelta(d, 'applied', { changed: Object.keys(d.patch), before: held, after });
    }

    #push(k, d) {
        if (!this.#buffers.has(k)) {
            this.#buffers.set(k, []);
        }

        this.#buffers.get(k).push(d);
        this.#noteDelta(d, 'buffered');
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
            this.#resyncs++;
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
                    this.#merge(k, h, d);
                }
            }

            return;
        }

        const { api_version, server_time, detail, ...row } = res.body;
        const inserted = !this.#seats.has(k);

        this.#replaceIfHigher(row, 'seat.fetch', res.body.server_time);
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
    #replaceIfHigher(row, source, serverTime) {
        const k = key(row.install_id, row.seat_id);
        const held = this.#seats.get(k);

        if (held === undefined || row.state_version > held.state_version) {
            this.#seats.set(k, row);
            this.#confirm(k);
            this.#noteRow(source, row, serverTime, 'applied');

            return;
        }

        this.#noteRow(source, row, serverTime, 'discarded');
    }

    /**
     * One journal line for a row a REST surface delivered. § 6.5 is why it is written at all: the
     * renderer must be able to SEE the snapshot, the resync and the insert and animate none of
     * them, and a GREEN over a journal that never carried them would be satisfied by a client that
     * renders nothing.
     */
    #noteRow(source, row, serverTime, outcome) {
        this.#note(source, outcome, {
            install_id: row.install_id,
            seat_id: row.seat_id,
            state_version: row.state_version,
            server_time: serverTime,
        });
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
        this.#fleet = fleet ?? null;

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

        const res = await this.#get(SNAPSHOT_PATH, { discovery: true });

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

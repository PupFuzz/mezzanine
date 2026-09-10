/**
 * The lobby's render model — `docs/design/FLOOR.md § 4.1`, as pure functions over a
 * `GET /api/fleet/snapshot` body (D2 § 8.2.2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO DOM AND NO `fetch` IN THIS FILE, DELIBERATELY. There is no browser on the build host, so
 * anything that touches a document is a thing no check here can exercise. Every FACT the lobby
 * renders is decided in this file and asserted directly (`tests/Feature/Lobby`, driven through
 * `node`); `main.js` is the thin layer that puts these strings into elements, and it is the part
 * that is NOT covered by an automated check. Keeping the split sharp is what keeps the uncovered
 * part free of decisions.
 *
 * ⛔ EVERY RENDERED FACT NAMES ITS D2 MEMBER (§ 1.3 corollary 1: "No rendered fact without a
 * named field"). The map, once, so no function below has to argue it a second time:
 *
 *   floor row .................. `installs[].install_id`               (§ 4.1 row 1)
 *   per-floor summary counts ... `render_state` over `installs[].seats[]`, client-computed
 *                                (§ 2.1 row 5 — the wire has no per-install count)
 *   unrecognised remainder ..... the same, § 5.4, carrying the raw string
 *   fleet totals ............... `fleet.seats_total`, `fleet.seats_live` — NEVER RECOUNTED
 *   discrepancy ................ the two above (§ 4.1, AT-D3-15)
 *   membership stamp ........... the response's `server_time` (§ 2.3)
 *   store / derivation / sweep . `fleet.db`, `fleet.fold`, `fleet.sweep`,
 *                                `fleet.sweep_last_run_at`, `fleet.ingest_last_receipt_at`
 *
 * ⛔ `fleet.max_fold_lag_ms` IS NOT RENDERED HERE. It has a published form elsewhere — § 2.4's
 * fleet-banner figure, whose home is § 7.4's status strip on the FLOOR — and a second rendering
 * of one fact on a second surface is exactly what § 2.4's one-rendered-form-per-fact rule
 * forbids. The lobby's derivation indicator renders `fleet.fold` and stops there.
 *
 * ⛔ NO DURATION IS RENDERED ANYWHERE IN THIS CLIENT, and card#9209 CHANGED THE REASON WITHOUT
 * CHANGING THE ANSWER. It published the duration FORMAT — D3 § 2.4's one function, § 12's row,
 * § 13 decision 23 — so *there is no format* is no longer true and is no longer why. What is
 * still open is the WORDING: § 5.3 asks for `sweep_last_run_at` and `ingest_last_receipt_at`
 * "as an age" and no section publishes the string either age is spoken in, which is § 14 item 17.
 * A format with no ratified wording still leaves a picked string as the one nobody ratified, so
 * until that item closes this renders each as the LABELLED TIMESTAMP the wire actually carries,
 * in § 4.1's own `HH:MM:SS` form, and subtracts nothing from anything.
 */

import { RENDER_STATES, isRenderState } from './render-state.js';

/**
 * § 5.6's default, in decision 13's own word: "a null is rendered as **not reported**, never as a
 * zero … where the element's own space is drawn unconditionally, it reads *not reported*". The
 * three indicators and the two fleet readouts are drawn unconditionally, so they take this.
 */
export const NOT_REPORTED = 'not reported';

/**
 * `HH:MM:SS` out of an RFC3339-with-milliseconds server-clock timestamp (D2 § 8.2), which is the
 * form § 4.1 publishes for the membership stamp ("membership as of 14:23:14").
 *
 * ⛔ IT READS THE WIRE'S OWN DIGITS AND CONVERTS NOTHING. Parsing to a `Date` and formatting
 * would render the VIEWER's timezone for a SERVER-clock fact, and § 2.4 is emphatic that the
 * viewer's own machine clock is admitted at exactly one place on this product (§ 6.2 A17's wall
 * clock, which is the floor's and is labelled as the client's own). D3 publishes the FORM and
 * states no timezone rule for it, so the zero-assumption render is the server's own digits.
 * ⚠ REPORTED, NOT INVENTED: that leaves an operator in another zone reading UTC. It is a
 * question for the review loop, not something to answer by picking a conversion here.
 *
 * `null` for a null or unreadable value — the caller applies § 5.6's `not reported`, and NEVER a
 * zero, an epoch, or the string "null".
 */
export function clockTime(wireTime) {
    if (typeof wireTime !== 'string') {
        return null;
    }

    const m = wireTime.match(/T(\d{2}):(\d{2}):(\d{2})/);

    return m === null ? null : `${m[1]}:${m[2]}:${m[3]}`;
}

/**
 * § 4.1's per-floor state summary: "a count per `render_state` member present, e.g. *2 working ·
 * 1 idle · 1 stale*, in § 7.1's fixed member order".
 *
 * ⛔ ITERATING § 7.1's ORDER ALONE IS A FILTER, AND A FILTER DROPS A SEAT OUT OF ITS OWN FLOOR'S
 * COUNT. That is card#7943's class arriving through a COUNT instead of through a lookup, and it
 * breaks AT-D3-15 — the lobby never invents a count — in the direction nobody watches: four seats
 * at the desks, three on the line, no error anywhere. So the ordered pass is followed by the
 * remainder: every value the floor holds that is in NO member set, named as unrecognised and
 * CARRYING THE RAW STRING (§ 5.4), in the order the floor holds them.
 *
 * `Object.create(null)` because the keys are wire strings: a seat whose `render_state` is
 * `constructor` must be counted, not silently added to a function.
 */
export function floorSummary(seats) {
    const rows = Array.isArray(seats) ? seats : [];
    const seen = Object.create(null);

    for (const seat of rows) {
        const state = seat === null || typeof seat !== 'object' ? undefined : seat.render_state;
        const key = String(state);
        seen[key] = (seen[key] || 0) + 1;
    }

    const unheard = rows
        .map((seat) => (seat === null || typeof seat !== 'object' ? undefined : seat.render_state))
        .filter((v, i, all) => !isRenderState(v) && all.indexOf(v) === i);

    return RENDER_STATES.filter((k) => seen[k])
        .map((k) => `${seen[k]} ${k}`)
        .concat(unheard.map((v) => `${seen[String(v)]} unrecognised (${String(v)})`))
        .join(' · ');
}

/**
 * § 4.1 row 1: "one row per floor, the row being the link to the floor", from
 * `installs[].install_id`, ascending.
 *
 * The sort is § 2.1 row 6's ("floors by `install_id` ascending") and is applied here rather than
 * trusted from the wire: D2 § 8.2.2 does order them, and a client that renders in received order
 * is a client whose order is a property of somebody else's `ORDER BY`.
 *
 * `href` is § 4.4's `/floor/{install_id}` — the route D3 declares. ⚠ THAT ROUTE IS NOT BUILT: the
 * floor is card#9208-blocked (no D2 read surface for an authored floor map). The row is still the
 * link, because § 4.1 says the row IS the link and a lobby whose rows are inert is a different
 * design; what it must not be is a link to an invented endpoint, and `/floor/{install_id}` is
 * D3's own published route, not one minted here.
 */
export function floors(snapshot) {
    const installs = Array.isArray(snapshot?.installs) ? snapshot.installs : [];

    return installs
        .map((install) => ({
            install_id: String(install?.install_id),
            href: `/floor/${encodeURIComponent(String(install?.install_id))}`,
            summary: floorSummary(install?.seats),
            held: Array.isArray(install?.seats) ? install.seats.length : 0,
        }))
        .sort((a, b) => (a.install_id < b.install_id ? -1 : a.install_id > b.install_id ? 1 : 0));
}

/**
 * § 4.1 row 3: "*4 seats · 4 live*, read from the wire and **never recounted**" —
 * `fleet.seats_total` and `fleet.seats_live`, verbatim.
 *
 * ⛔ THE ONE THING THIS FUNCTION MAY NOT DO IS COUNT. AT-D3-15's RED is "compute the fleet counts
 * by counting desks → the lobby confidently reports 3 seats on a 4-seat fleet, and the missing
 * desk is invisible precisely because the count agrees with the floor". It therefore takes the
 * `fleet` object and is not given the installs at all: the defect is unwriteable from here.
 *
 * A non-integer member — which is what D2 § 8.2.4's `db: "down"` posture serves, the five
 * store-derived members being ABSENT rather than defaulted — reads `not reported`, never 0.
 */
export function fleetTotals(fleet) {
    const total = Number.isInteger(fleet?.seats_total) ? String(fleet.seats_total) : NOT_REPORTED;
    const live = Number.isInteger(fleet?.seats_live) ? String(fleet.seats_live) : NOT_REPORTED;

    return `${total} seats · ${live} live`;
}

/**
 * § 4.1's discrepancy check: "when `Σ floors ≠ fleet.seats_total` the lobby renders the
 * disagreement … It never silently picks a winner (AT-D3-15)."
 *
 * Both directions, in § 4.1's own words. N > M is reachable and is not a paranoia case: a client
 * that "missed the `seat.retired` announcement" still holds a desk that `seats_total` has stopped
 * counting — and a test built only on N < M leaves the wording *N of M*, which reads as a subset,
 * unexercised on the direction where it is false (AT-D3-15).
 *
 * `null` when they agree, which is the intact fixture's discriminating control: no notice.
 */
export function discrepancyNotice(held, total) {
    if (!Number.isInteger(total) || held === total) {
        return null;
    }

    return held < total
        ? `the client holds ${held} of ${total} seats — refreshing`
        : `the client holds ${held} seats; the fleet reports ${total} — refreshing`;
}

/**
 * § 4.1 row 5 / § 2.3: "*membership as of 14:23:14*" — "the time of the last full snapshot", read
 * from that response's own `server_time`, so the age of the MEMBERSHIP picture is visible
 * separately from the age of the STATE picture.
 */
export function membershipStamp(serverTime) {
    return `membership as of ${clockTime(serverTime) ?? NOT_REPORTED}`;
}

/**
 * § 4.1 row 6 / § 5.3: "three separate indicators, NEVER ONE AGGREGATE" —
 * D2 § 8.2.4: "no aggregate rolls three health facts into one… D3 may compose a banner from these
 * three; the wire keeps them apart."
 *
 * So this returns a LIST of three, plus § 5.3's own fourth rendered element (ingest recency),
 * each with its own key and its own value. There is no combined verdict anywhere in this module,
 * and the caller has none to render: the shape is what keeps the rule.
 *
 * ⛔ EVERY VALUE IS THE WIRE'S RAW STRING. `db`, `fold` and `sweep` are D2 enums; D3's § 5.4
 * membership rule names six sets and none of them is one of these, so this client publishes no
 * vocabulary for them and maps nothing. Rendering the raw value verbatim is what makes a member
 * D2 adds tomorrow legible today, and — the property that matters — makes it IMPOSSIBLE for an
 * unknown value to be shown as `ok`.
 *
 * `store_unavailable` is the § 5.3 rule that `db: "down"` gets § 9's store-unavailable render,
 * "which is a full statement, not a red dot". The caller reads the flag; the statement is
 * `storeUnavailableStatement()` below, so the two surfaces that can reach it (a `db: "down"` body
 * and an F4 `503`) render ONE string.
 */
export function indicators(fleet) {
    const health = fleet === null || typeof fleet !== 'object' ? {} : fleet;

    return [
        {
            key: 'store',
            label: 'store',
            member: 'fleet.db',
            value: typeof health.db === 'string' ? health.db : NOT_REPORTED,
            detail: null,
        },
        {
            key: 'derivation',
            label: 'derivation',
            member: 'fleet.fold',
            value: typeof health.fold === 'string' ? health.fold : NOT_REPORTED,
            // ⛔ NOT `fleet.max_fold_lag_ms`. See this file's header: its published form is the
            // fleet banner's, and that banner is § 7.4's on the floor.
            detail: null,
        },
        {
            key: 'sweep',
            label: 'sweep',
            member: 'fleet.sweep',
            value: typeof health.sweep === 'string' ? health.sweep : NOT_REPORTED,
            // § 5.3: "`stalled` ⇒ indicator plus the age". The age's WORDING is § 14 item 17 —
            // card#9209 published the format, not the string it is spoken in — so what
            // is drawn is the instant itself, labelled, on every value — a dead sweep's last run
            // is the fact, and hiding it unless `stalled` would make the indicator's own evidence
            // conditional on the indicator's verdict.
            detail: `last run ${clockTime(health.sweep_last_run_at) ?? NOT_REPORTED}`,
        },
        {
            key: 'ingest',
            label: 'ingest',
            member: 'fleet.ingest_last_receipt_at',
            // § 5.3: "rendered as an age; it is the fleet-wide reading that separates *every seat
            // died* from *our pipe is broken*". Same substitution, same reason: § 14 item 17.
            value: `last receipt ${clockTime(health.ingest_last_receipt_at) ?? NOT_REPORTED}`,
            detail: null,
        },
    ];
}

/**
 * § 9 F4's observable, verbatim: "a full-width statement: **fleet state is unavailable — the
 * store could not be read at 14:23:14**".
 *
 * The instant is the refusal's OWN `server_time` — `App\Read\ReadRefusal::response()` puts one on
 * every refusal body, so this is a server-clock fact and not the client guessing when the store
 * broke. `not reported` if it is missing, never the client's own clock silently substituted.
 */
export function storeUnavailableStatement(serverTime) {
    return `fleet state is unavailable — the store could not be read at ${clockTime(serverTime) ?? NOT_REPORTED}`;
}

/**
 * § 4.1's one-fetch-per-distinct-`(N, M)` budget, as a thing with a memory rather than a rule
 * written at the call site.
 *
 * § 4.1: "It triggers **one snapshot fetch per distinct (N, M) observation**: a disagreement still
 * standing after that fetch is rendered and **not** re-fetched, so a discrepancy the snapshot
 * cannot resolve costs one request rather than one every 15 s." AT-D3-15's second GREEN is the
 * property directly: "the **second** identical heartbeat issues **no** fetch, because the trigger
 * is one fetch per *distinct* (N, M) observation and not a poll".
 *
 * ⚠ ADMIT's own fetch is NOT counted against this budget (§ 2.2 step 6, § 2.3, decision 9) —
 * "that budget exists to bound a *disagreement* and ADMIT is bounded by the install set instead".
 * ADMIT belongs to the delta feed, which is out of this slice; nothing here calls this for it.
 */
export class DiscrepancyBudget {
    #spent = new Set();

    /** True at most once per distinct `(held, total)` pair, and never while they agree. */
    admits(held, total) {
        if (discrepancyNotice(held, total) === null) {
            return false;
        }

        const key = `${held}/${total}`;

        if (this.#spent.has(key)) {
            return false;
        }

        this.#spent.add(key);

        return true;
    }

    /** How many fetches this budget has admitted — the client's own count of its own acts. */
    get spent() {
        return this.#spent.size;
    }
}

/**
 * The whole lobby, from one snapshot body: what § 4.1's table says the screen carries, and
 * nothing else. `main.js` renders these strings and decides none of them.
 */
export function lobbyModel(snapshot) {
    // ONE pass over the installs, and the held count summed from the same rows the floors
    // render. Calling `heldSeats()` here as well would build the summaries twice and open the
    // one gap that matters: two counts of one population that can disagree.
    const rows = floors(snapshot);
    const held = rows.reduce((n, floor) => n + floor.held, 0);

    return {
        floors: rows,
        held,
        totals: fleetTotals(snapshot?.fleet),
        // A non-integer `seats_total` is `discrepancyNotice`'s own `null` case: there is no
        // disagreement to render when there is no number to disagree with, and inventing one
        // from the held count is § 2.1's forbidden recount wearing a different hat.
        discrepancy: discrepancyNotice(held, snapshot?.fleet?.seats_total),
        stamp: membershipStamp(snapshot?.server_time),
        indicators: indicators(snapshot?.fleet),
        // § 5.3 / § 9 F5: `db: "down"` gets the store-unavailable statement, not a red dot.
        store_unavailable: snapshot?.fleet?.db === 'down'
            ? storeUnavailableStatement(snapshot?.server_time)
            : null,
    };
}

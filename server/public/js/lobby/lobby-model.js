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
 *   floor row .................. the BUILDING LAYOUT `GET /api/building` answered (§ 4.6), composed
 *                                against `installs[].install_id`        (§ 4.1 row 1)
 *   a room on the row .......... `installs[].install_id`, and whether the client holds it
 *   per-floor summary counts ... `render_state` over `installs[].seats[]` for every install the
 *                                floor's rooms name, client-computed (§ 2.1 row 5 — the wire
 *                                has no per-install count)
 *   unrecognised remainder ..... the same, § 5.4, carrying the raw string
 *   the plaque (card#12113) .... § 4.1's rows over the same seats: the tally's chips (`render_state`),
 *                                agents and desks (`render_state`, through `deskDrawsCharacter()`),
 *                                the markers (`render_state`, `install_id`, `seat_id`), the attention
 *                                line (`render_state`, `link_state`, `blocked_since`, `api_error_type`,
 *                                `activity.last_received_at`, `context.used_pct`, `longest_open_call`)
 *                                and the floor totals (`task.ref`, `open_calls`, `subagents_open`,
 *                                `activity.last_received_at`)
 *   fleet totals ............... `fleet.seats_total`, `fleet.seats_live` — NEVER RECOUNTED
 *   discrepancy ................ the two above, as the client protocol compares them (§ 4.1,
 *                                AT-D3-15) — worded here, decided and rendered by
 *                                `lobby-screen.js` over `FleetClient#discrepancyState()`
 *   membership stamp ........... the last full snapshot's `server_time` (§ 2.3, § 5.5)
 *   store / derivation / sweep . `fleet.db`, `fleet.fold`, `fleet.sweep`,
 *                                `fleet.sweep_last_run_at`, `fleet.ingest_last_receipt_at`
 *
 * ⛔ `fleet.max_fold_lag_ms` IS NOT RENDERED HERE. It has a published form elsewhere — § 2.4's
 * fleet-banner figure, whose home is § 7.4's status strip on the FLOOR — and a second rendering
 * of one fact on a second surface is exactly what § 2.4's one-rendered-form-per-fact rule
 * forbids. The lobby's derivation indicator renders `fleet.fold` and stops there.
 *
 * ⛔ THE FLEET READOUTS RENDER NO DURATION, and card#9209 CHANGED THE REASON WITHOUT CHANGING THE
 * ANSWER. It published the duration FORMAT — D3 § 2.4's one function, § 12's row, § 13 decision 23 —
 * so *there is no format* is no longer true and is no longer why. What is still open is the WORDING:
 * § 5.3 asks for `sweep_last_run_at` and `ingest_last_receipt_at` "as an age" and no section
 * publishes the string either age is spoken in, which is § 14 item 17. A format with no ratified
 * wording still leaves a picked string as the one nobody ratified, so until that item closes this
 * renders each as the LABELLED TIMESTAMP the wire actually carries, in § 4.1's own `HH:MM:SS` form,
 * and subtracts nothing from anything.
 *
 * ⚠ THE PLAQUE'S AGES ARE THE EXCEPTION, AND § 4.1 IS WHERE THEY ARE WORDED (card#12113, seat rulings
 * listed at § 14 item 39(7)): the attention line's quiet age and long call in § 2.4's own wordings, and
 * the totals' *last activity N ago*. Each is the corrected clock less a server-clock instant
 * (`../wire/age-readout.js`, `../wire/duration.js`), and with no corrected clock — `nowMs` null — none
 * is drawn and the long-call threshold is not asked.
 */

import { RENDER_STATES, isRenderState } from './render-state.js';
import { deskDrawsCharacter } from '../desk/task-bubble.js';
import { DASH, UNRECOGNISED, apiErrorLine, blockedLine } from '../desk/state-words.js';
import { actionElapsedLine, quietAgeLine } from '../wire/age-readout.js';
import { clockTime } from '../wire/clock.js';
import { contextGauge } from '../wire/context-gauge.js';
import { disagrees } from '../wire/discrepancy-budget.js';
import { ageFrom, wireMs } from '../wire/duration.js';
import { NOT_REPORTED } from '../wire/null-render.js';

/**
 * ⚠ `clockTime` MOVED to `../wire/clock.js` at its second caller (card#8300's coordination
 * client needs the identical function), and is re-exported here so this module stays the
 * lobby's one import. Its reasoning — why it shows every instant in the viewer's browser zone
 * (card#9446) — moved with it rather than being copied.
 */
export { clockTime };

/**
 * § 5.6's default, in decision 13's own word: "a null is rendered as **not reported**, never as a
 * zero … where the element's own space is drawn unconditionally, it reads *not reported*". The
 * three indicators and the two fleet readouts are drawn unconditionally, so they take this.
 * ⚠ The word is `../wire/null-render.js`'s, hoisted there at the desk, its third caller (card#7341
 * step 5), and re-exported so this module stays the lobby's one import.
 */
export { NOT_REPORTED };

/**
 * § 12's three directory rows (card#12113, each a seat ruling revisable there): the *Directory marker
 * cap*, the *Context attention threshold* and the *Long-call threshold*.
 *
 * ⛔ RESTATEMENTS OF § 12, GUARDED: a browser cannot read FLOOR.md, so the figures live here, and
 * `Tests\Feature\Floor\TheDirectoryPlaqueReadsWhatTheClientHoldsTest` parses § 12's rows on every run
 * and reds when either side moves without the other.
 */
export const MARKER_CAP = 12;

export const CONTEXT_ATTENTION_PCT = 80;

export const LONG_CALL_MS = 10 * 60 * 1000;

/** § 4.1's header accessible name on a plaque holding no seat. */
export const NO_SEATS_HELD = 'no seats held';

/** § 4.1's totals row on a floor whose every `activity.last_received_at` is null — never *0s*. */
export const NO_ACTIVITY_YET = 'no activity yet';

/**
 * `n word`/`n words`, agreeing with its count — every count on the plaque and the discrepancy
 * sentence's desks (§ 4.1). `spelled` is the count as written, where § 4.1 spells it in words.
 */
function counted(n, word, spelled = String(n)) {
    return `${spelled} ${n === 1 ? word : `${word}s`}`;
}

/** The plaque's order and its tie-break: the lower `(install_id, seat_id)` (§ 4.1, § 14 item 39(5)). */
function byKey(a, b) {
    const ka = [String(a.install_id), String(a.seat_id)];
    const kb = [String(b.install_id), String(b.seat_id)];

    return ka[0] < kb[0] ? -1 : ka[0] > kb[0] ? 1 : ka[1] < kb[1] ? -1 : ka[1] > kb[1] ? 1 : 0;
}

/**
 * The one candidate a part names: the least by `order`, ties on the lower `(install_id, seat_id)`.
 * `null` over an empty population.
 */
function pick(seats, order) {
    return [...seats].sort((a, b) => order(a, b) || byKey(a, b))[0] ?? null;
}

/**
 * § 4.1's per-floor state summary: "a count per `render_state` member present, e.g. *2 working ·
 * 1 idle · 1 stale*, in § 7.1's fixed member order" — since card#12113 drawn as one CHIP per member,
 * and this text form the header link's accessible name. `stateChips()` is the derivation and
 * `floorSummary()` its members joined, so the chips and the line cannot disagree.
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
 *
 * @returns {Array<{state: *, count: number, recognised: boolean, text: string}>}
 */
export function stateChips(seats) {
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

    const chip = (state, recognised) => ({
        state: state ?? null,
        count: seen[String(state)],
        recognised,
        text: recognised ? `${seen[state]} ${state}` : `${seen[String(state)]} ${unrecognisedWord(state)}`,
    });

    return RENDER_STATES.filter((k) => seen[k])
        .map((k) => chip(k, true))
        .concat(unheard.map((v) => chip(v, false)));
}

/** `stateChips()`'s members as § 4.1's one text line — the header link's accessible name's half. */
export function floorSummary(seats) {
    return stateChips(seats).map((chip) => chip.text).join(' · ');
}

/**
 * § 4.1 row 1: "one row per floor, the row being the link to the floor" — the floors the building
 * layout composes, plus one per install it does not place, floor keys ascending.
 *
 * ⭐ A ROOM IS AN INSTALL; A FLOOR IS AN OPERATOR-COMPOSED SET OF ROOMS (card#9267, § 3.1, § 4.6).
 * `layout` is the validated, normalised floors `GET /api/building` answered (D2 § 8.7): a list of
 * `{ floor, label, rooms: [{ install, form }] }`, keys already derived server-side and `label`
 * null on a floor the operator did not name (card#9273, § 4.6). What THIS function
 * adds is § 4.6's one default rule — an install the snapshot carries that no delivered floor
 * places is a floor of its own, alone, `open` — and it adds it HERE and not only on the server
 * because § 4.1's discrepancy check discovers an install AFTER the layout was fetched, and that
 * install owes a floor the layout could not have known about. The rule has a second home in
 * `App\Building\Building::compose()` for the console; `tests/fixtures/building/compose-cases.json`
 * is the one statement both are held to.
 *
 * ⛔ THE LAYOUT SUBSCRIBES TO NOTHING AND NAMES NO SEAT (§ 4.6). A room the layout places that
 * the snapshot does not carry is drawn on its floor with `reported: false` — § 4.6's *no seats
 * reported for this room*, the client's own narration — and is never fetched for, never
 * subscribed to and never counted. ADMIT's population is the snapshot's, not the layout's.
 *
 * The sort is § 2.1 row 6's ("floors by floor id ascending") and is applied here rather than
 * trusted from the wire: a client that renders in received order is a client whose
 * order is a property of somebody else's serialiser.
 *
 * ⛔ NO LAYOUT HELD COMPOSES NO BUILDING, AND THIS FUNCTION IS WHERE THAT IS DECIDED (§ 9 F17). A
 * `layout` that is not a list, absent or `null`, answers `null`, never the rows of the EMPTY layout:
 * `[]` is § 4.6's legal document, which composes one floor per install, and reading a missing layout
 * as that document would draw a building out of a request that failed, which is F17's "Never". Both
 * callers here read `null` as *no building*, and a caller added later (Appendix B step 7's floor
 * route) that forgets a check of its own gets `null` from this function, never a building.
 *
 * `href` is § 4.4's `/floor/{floor}` route — D3's own published route, served since Appendix B
 * row 8 (card#7341 step 8), and never one minted here. § 4.1 says the row IS the link.
 */
export function floors(snapshot, layout, nowMs = null) {
    if (!Array.isArray(layout)) {
        return null;
    }

    const installs = Array.isArray(snapshot?.installs) ? snapshot.installs : [];
    // Install id -> the seats the client holds for it. `Object.create(null)` for the same reason
    // `floorSummary()` uses it: the keys are wire strings.
    const held = Object.create(null);

    for (const install of installs) {
        held[String(install?.install_id)] = Array.isArray(install?.seats) ? install.seats : [];
    }

    const placed = new Set();
    const rows = [];

    for (const floor of layout) {
        const rooms = (Array.isArray(floor?.rooms) ? floor.rooms : []).map((room) => {
            const install_id = String(room?.install);
            placed.add(install_id);

            const composed = { install_id, form: String(room?.form), reported: install_id in held };

            // ⭐ § 4.6's PLAN (card#9292): a room on a PLANNED floor carries where it is drawn.
            // Carried through exactly as delivered and never re-derived — `origin` is a member
            // the server has already refused every bad form of (integers ≥ 0, `x` and `y` and
            // nothing else), and a second reading of that rule here is the drift this file's
            // label comment names. A floor with no plan carries no `origin` at all, which is how
            // the renderer tells *placed* from *arrange it by the default rule*: an invented
            // `null` here would be a third state neither runtime has.
            if (room?.origin !== undefined) {
                composed.origin = room.origin;
            }

            return composed;
        });

        // § 4.6 (card#9273): the floor's LABEL, as the delivered document carries it — a string
        // or nothing. The server has already refused every label it would not deliver, so this
        // reads the member rather than re-deriving the rule; what it must not do is coerce, which
        // would turn a delivered number into a name the operator never wrote.
        const label = typeof floor?.label === 'string' ? floor.label : null;

        // § 4.6's `theme` (card#11046, FLOOR.md § 10.6 item 5): the name the delivered entry carries, or
        // null for the house theme. Read as the server delivered it — the server refused a non-string at
        // load — and never judged against the registry here: whether the build ships it is the floor
        // screen's to draw (§ 9 F23), and the lobby draws no interior.
        const theme = typeof floor?.theme === 'string' ? floor.theme : null;

        // ⭐ § 4.6's PLAN, the floor's half (card#9292): its `hallway` — the corridor drawn at the
        // floor's origin UNDER its rooms — carried exactly as delivered, for `origin`'s reason
        // above. The lobby draws no interior and ignores it (§ 4.1, card#9267); the floor route
        // reads "one floor, the rooms on it, and its plan — each room's `origin` and the floor's
        // `hallway`, or neither" (§ 4.6's reader table), and it reads it HERE rather than going
        // back to the raw document, because a second composition of one floor is two answers to
        // *which rooms are on it*.
        rows.push(plate(String(floor?.floor), label, rooms, held, floor?.hallway ?? null, theme, nowMs));
    }

    for (const install_id of Object.keys(held)) {
        // An implicit floor has no layout entry to carry a label (§ 4.6, card#9273), so it reads
        // as its key — the `install_id` the wire already carries, which is a name and not a
        // placeholder.
        if (!placed.has(install_id)) {
            // An implicit floor is one room and no plan at all (§ 4.6): no `origin` on its room and
            // no `hallway` on it, because "a corridor with no rooms placed along it is a picture of
            // nothing" and nobody placed this one.
            rows.push(plate(install_id, null, [{ install_id, form: 'open', reported: true }], held, null, null, nowMs));
        }
    }

    return rows.sort((a, b) => (a.floor < b.floor ? -1 : a.floor > b.floor ? 1 : 0));
}

/**
 * One floor row — since card#12113 the directory's PLAQUE: its key, its label, the NAME it reads as,
 * its rooms, the link, and every row § 4.1's table gives the plaque, each over the seats the client
 * holds for EVERY install the floor's rooms name (§ 2.1 row 5), in room order, and the corrected clock
 * `nowMs` (`null` before the client has one, which draws no age and asks no age threshold).
 *
 * ⛔ `name` IS RENDERED AND `floor` IS ROUTED, AND THE TWO ARE SEPARATE MEMBERS FOR THAT REASON
 * (§ 4.6, card#9273). `href` is the KEY whatever the label says, and so is the sort in `floors()`
 * and the cab's stop in `building-model.js` — a label is display text, and the moment anything
 * looked one up by it, editing a label would move a viewer's floor.
 *
 * ⛔ THE ROW CARRIES NO SEAT OBJECT, only what is drawn from one (§ 4.1: "the lobby still draws no
 * desk"). A marker names its seat in its accessible name and carries no `seat_id` member of its own,
 * so a plate that grew a list of desks is still the defect
 * `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` looks for.
 */
function plate(floor, label, rooms, held, hallway, theme, nowMs) {
    const seats = rooms.flatMap((room) => held[room.install_id] ?? []);
    // § 4.1: "on a floor of more than one room the `install_id`, a slash and the `seat_id`" — a
    // `seat_id` is unique within one install only (D2 § 8.2.1).
    const seatName = rooms.length > 1
        ? (seat) => `${seat.install_id}/${seat.seat_id}`
        : (seat) => String(seat.seat_id);

    const row = {
        floor,
        hallway,
        label,
        // § 4.6 (card#11046): the theme the entry names, or null — an implicit floor's is always null.
        theme,
        // § 4.6 (card#9273): "its label when the layout gives it one, else its key — the key is
        // honest and no placeholder is invented".
        name: label ?? floor,
        rooms,
        href: `/floor/${encodeURIComponent(floor)}`,
        summary: floorSummary(seats),
        held: seats.length,
    };

    return {
        ...row,
        // § 4.1: "The text form survives as the header link's accessible name".
        accessible_name: `${row.name}, ${row.summary === '' ? NO_SEATS_HELD : row.summary}`,
        chips: stateChips(seats),
        staffing: staffing(seats),
        markers: markers(seats, seatName),
        attention: attention(seats, seatName, nowMs),
        floor_totals: floorTotals(seats, nowMs),
    };
}

/** § 4.1's word for a `render_state` this client does not know, carrying the raw string (§ 5.4). */
function unrecognisedWord(state) {
    return `${UNRECOGNISED} (${String(state)})`;
}

/**
 * § 4.1's **agents and desks** — *3 agents · 4 desks*. **Desks** is the held count; **agents** the
 * seats whose `render_state` draws a character, which is `desk/task-bubble.js`'s `deskDrawsCharacter()`
 * CALLED — never a second reading of § 7.1's Desk column — so an empty chair (`stale`, `offline`) and
 * an unrecognised state are desks with nobody counted at them (§ 14 item 39(1)).
 */
export function staffing(seats) {
    const agents = seats.filter((seat) => deskDrawsCharacter(seat?.render_state)).length;

    return { agents, desks: seats.length, text: `${counted(agents, 'agent')} · ${counted(seats.length, 'desk')}` };
}

/**
 * § 4.1's **marker row**: one marker per held seat in the floor's rooms' key order and inside a room
 * by `seat_id` (§ 14 item 39(5)), the first `MARKER_CAP` of them, then *+N more* where N is the held
 * count less the cap — derived from the two counts, never a third.
 *
 * ⛔ THE CAP IS APPLIED AFTER THE SORT: capping the received order would draw twelve seats of the
 * server's serialisation and call them the floor's first twelve (AT-D3-27).
 *
 * A marker is a **disc** where `deskDrawsCharacter()` is true and a **ring** where it is not — the same
 * predicate as the agents count, so a ring is exactly a desk the count does not staff. Its name is the
 * seat's name, a comma and the state's word, so the colour is never the only carrier.
 */
export function markers(seats, seatName) {
    const sorted = [...seats].sort(byKey);
    const shown = sorted.slice(0, MARKER_CAP).map((seat) => {
        const recognised = isRenderState(seat.render_state);
        const word = recognised ? seat.render_state : unrecognisedWord(seat.render_state);

        return {
            state: seat.render_state ?? null,
            recognised,
            shape: deskDrawsCharacter(seat.render_state) ? 'disc' : 'ring',
            name: `${seatName(seat)}, ${word}`,
        };
    });
    const more = seats.length - MARKER_CAP;

    return { shown, more: more > 0 ? `+${more} more` : null };
}

/**
 * § 4.1's (b) population: the floor's `live` seats whose `context` is NON-NULL — the seats the
 * threshold is asked of. A null `context` is excluded here, before any comparison: never read as 0 and
 * never dereferenced (§ 5.6). Exported because AT-D3-27 asks of the population itself: a null read as
 * a reported 0 never clears the threshold, so the line alone could not show it entered.
 */
export function contextCandidates(seats) {
    return seats.filter((seat) => seat.link_state === 'live' && seat.context !== null && typeof seat.context === 'object');
}

/**
 * § 4.1's **attention line** — the parts that need a look, each a row of its own and each opening with
 * the seat's name, in the order (a) blocked-else-stalled, (b) context, (c) a long call; `null` when no
 * part applies, because the element is ABSENT then — never *all quiet*, a claim over seats the client
 * may not confirm.
 *
 * ⛔ KEYED ON `render_state`, NEVER `activity_state` (D2 § 4.2): a `stale` seat whose activity is
 * `blocked` renders `stale` and is named by no part, where reading the activity would name a person
 * waiting at a dark desk. (b) and (c) read `live` seats only: they claim something about NOW, which a
 * dark seat's sample and open call are history for (§ 7.3, § 14 item 39(11)).
 *
 * ⛔ `blocked_since` IS A SEAT-CLOCK CLAIM: the earliest is found by comparing the seats' own claims and
 * is DRAWN as one, *(seat clock)*, never subtracted from the server clock (§ 2.4).
 *
 * ⛔ THE LONG CALL IS `longest_open_call`, NEVER `action` (D2 § 8.2.1, card#12113): `action` is the
 * NEWEST open call, and an intern's call replaces a dispatch there while the dispatch stays open.
 */
export function attention(seats, seatName, nowMs) {
    const parts = [];
    const line = (part, seat, ...words) => ({ part, text: [seatName(seat), ...words].join(DASH) });

    const blocked = seats.filter((seat) => seat.render_state === 'blocked');
    const waiting = pick(blocked.filter((seat) => wireMs(seat.blocked_since) !== null),
        (a, b) => wireMs(a.blocked_since) - wireMs(b.blocked_since));

    if (waiting !== null) {
        parts.push(line('blocked', waiting, blockedLine(waiting.blocked_since)));
    } else if (blocked.length === 0) {
        const stalled = pick(
            seats.filter((seat) => seat.render_state === 'stalled' && wireMs(seat.activity?.last_received_at) !== null),
            (a, b) => wireMs(a.activity.last_received_at) - wireMs(b.activity.last_received_at),
        );

        if (stalled !== null) {
            // § 7.6's composed line by reference, omitted on the null `api_error_type` a `stalled` seat
            // never carries (D2 § 8.2.1); the quiet age is § 2.4's, absent with no corrected clock.
            const words = [
                ...((stalled.api_error_type ?? null) === null ? [] : [apiErrorLine(stalled.api_error_type)]),
                ...(nowMs === null ? [] : [quietAgeLine(stalled.activity, nowMs)]),
            ];

            parts.push(line('stalled', stalled, ...words));
        }
    }

    const full = pick(
        contextCandidates(seats).filter((seat) => contextGauge(seat.context, null).reported
            && seat.context.used_pct >= CONTEXT_ATTENTION_PCT),
        (a, b) => b.context.used_pct - a.context.used_pct,
    );

    if (full !== null) {
        parts.push(line('context', full, `context ${contextGauge(full.context, null).pct}`));
    }

    // The seat's OLDEST open call — its one reading here, so no clause below can read `action` instead.
    const oldest = (seat) => seat.longest_open_call ?? null;
    const opened = (seat) => wireMs(oldest(seat)?.started_received_at);
    const long = nowMs === null ? null : pick(
        seats.filter((seat) => seat.link_state === 'live' && opened(seat) !== null && nowMs - opened(seat) >= LONG_CALL_MS),
        (a, b) => opened(a) - opened(b),
    );

    if (long !== null) {
        parts.push(line('long_call', long, `${oldest(long).tool_name} ${actionElapsedLine(oldest(long), nowMs)}`));
    }

    return parts.length === 0 ? null : parts;
}

/**
 * § 4.1's **floor totals** — *2 cards · 3 open calls · 2 interns · last activity 12s ago* — over EVERY
 * held seat, dark or live: a count of the picture the client holds (§ 14 item 39(11)).
 *
 * ⛔ CARDS ARE DISTINCT NON-NULL `task.ref`s, never seats with a task: two seats on one card are one
 * card, and a telemetry title with no `ref` is not a card (§ 5.6). ⛔ INTERNS ARE `subagents_open`,
 * never `subagents[].length`, which is capped (§ 8). OPEN CALLS ARE THE WIRE'S `open_calls`, never a
 * count of `action` objects.
 *
 * *Last activity* is the latest `activity.last_received_at` (server clock) as an age at `nowMs` —
 * absent with no corrected clock — and *no activity yet* where every seat's is null, never *0s*.
 */
export function floorTotals(seats, nowMs) {
    const cards = new Set(seats.map((seat) => seat.task?.ref ?? null).filter((ref) => ref !== null)).size;
    const sum = (member) => seats.reduce((n, seat) => n + (Number.isInteger(seat[member]) ? seat[member] : 0), 0);
    const openCalls = sum('open_calls');
    const interns = sum('subagents_open');
    const latest = pick(seats.filter((seat) => wireMs(seat.activity?.last_received_at) !== null),
        (a, b) => wireMs(b.activity.last_received_at) - wireMs(a.activity.last_received_at));
    const age = latest === null || nowMs === null ? null : ageFrom(latest.activity.last_received_at, nowMs);
    const lastActivity = latest === null ? NO_ACTIVITY_YET : (age === null ? null : `last activity ${age} ago`);

    return {
        cards,
        open_calls: openCalls,
        interns,
        last_activity: lastActivity,
        text: [counted(cards, 'card'), counted(openCalls, 'open call'), counted(interns, 'intern'), lastActivity]
            .filter((part) => part !== null)
            .join(' · '),
    };
}

/**
 * The seats the client protocol HOLDS, grouped by the install that is their room (§ 4.6) — in the
 * order the protocol holds them.
 *
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7341 step 9). The floor screen grouped the held map by
 * room for its geometry and projected it into a snapshot's shape for `floors()`; the lobby, which
 * since step 9 renders the protocol's population rather than a one-shot snapshot body of its own,
 * needs exactly the same two things. One projection, so the two screens cannot disagree about which
 * seats are in which room.
 *
 * @param {Iterable<object>} seats the held seat objects (`FleetClient#seats`' values)
 * @returns {Map<string, list<object>>}
 */
export function roomsOf(seats) {
    const byRoom = new Map();

    for (const seat of seats) {
        const id = String(seat.install_id);

        if (!byRoom.has(id)) {
            byRoom.set(id, []);
        }

        byRoom.get(id).push(seat);
    }

    return byRoom;
}

/**
 * The held population in the shape every function here reads — a snapshot BODY's `installs`,
 * `fleet` and `server_time` — so the lobby's model runs over what the protocol holds and never over
 * a body it fetched for itself. It is a projection and not a second membership rule: the population
 * is the seats the client holds, which is § 2.1 row 5's.
 *
 * @param {Iterable<object>} seats the held seat objects
 * @param {object|null} [fleet] the `fleet{}` the protocol holds (`FleetClient#fleet`)
 * @param {string|null} [serverTime] the last full snapshot's `server_time` (`FleetClient#membershipAsOf`)
 */
export function heldBody(seats, fleet = null, serverTime = null) {
    return {
        installs: [...roomsOf(seats)].map(([install_id, held]) => ({ install_id, seats: held })),
        fleet,
        server_time: serverTime,
    };
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

/** § 4.1's count agreement for the ending, in words to nine and in digits past it. */
const COUNT_WORDS = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

/** `n desk`/`n desks`, agreeing with its count. */
function desks(n, spelled = String(n)) {
    return counted(n, 'desk', spelled);
}

/**
 * § 4.1's discrepancy check, in words: "when `Σ floors ≠ fleet.seats_total` the lobby renders the
 * disagreement in the floor's own nouns … It never silently picks a winner (AT-D3-15)."
 *
 * ⭐ THE OPERATOR'S RATIFIED SENTENCES (card#7341, 2026-09-15), quoted by § 4.1 at the counts they
 * were ratified at: *"showing 4 of 5 desks — one desk could not be read"* when the client holds
 * fewer desks than the building lists, and *"showing 5 desks — the building lists 4"* when it holds
 * more. They are TWO sentences, not one template with its numbers swapped: *showing 4 of 3 desks*
 * is not a count this floor can render, so the N > M direction names the building's own count as
 * the claim (AT-D3-15). Neither says *client*, *fleet* or *seats* — the protocol's nouns, which the
 * operator could not parse — and neither claims a refresh.
 *
 * The count agreement is ratified too (§ 4.1; card#7341, 2026-09-25): the ending spells its count
 * in words to NINE and in digits past it (*11 desks could not be read*), and every *desks* agrees
 * with the number it follows (*showing 0 of 1 desk*, *showing 1 desk — the building lists 0*).
 *
 * `null` when they agree — the intact fixture's discriminating control: no notice. The condition is
 * `../wire/discrepancy-budget.js`'s `disagrees`, the one predicate the protocol's trigger asks too.
 */
export function discrepancyNotice(held, total) {
    if (!disagrees(held, total)) {
        return null;
    }

    if (held > total) {
        return `showing ${desks(held)} — the building lists ${total}`;
    }

    const short = total - held;

    return `showing ${held} of ${desks(total)} — ${desks(short, COUNT_WORDS[short - 1] ?? String(short))} could not be read`;
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
 * § 5.3's rule that `db: "down"` gets § 9's store-unavailable render, "which is a full statement,
 * not a red dot", is NOT this function's: the client protocol holds the fact wherever it arrives —
 * a `fleet.health`, or a snapshot's own `fleet{}` — and `../wire/failure-render.js` words it, for
 * the lobby exactly as for the floor, so the two surfaces that can reach it (a `db: "down"` body and
 * an F4 `503`) render ONE string.
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

/** § 5.3's fleet-counters row, verbatim: "a `null` `counters` renders as *unreadable*, never as zeros". */
export const UNREADABLE = 'unreadable';

/**
 * § 5.3's **fleet counters** row — `GET /api/fleet/health`'s `counters`, "rendered on an operator view
 * of the health endpoint only" — as `{ readable, statement, rows }`, each row `{ name, value }` in name
 * order.
 *
 * ⛔ A `null` OBJECT IS *unreadable*, NEVER A COLUMN OF ZEROS (§ 5.3, AT-D3-14's panel half). D2
 * § 8.2.4: "`null` says *we could not read these*; `0` would say *nothing has happened*" — and it is
 * null exactly when `db` is `down`, so a zero column there would be the clean zero on the one surface
 * D2's read posture is built to keep honest.
 *
 * ⚠ NO PAGE DRAWS THIS YET, AND THAT IS STATED RATHER THAN HIDDEN. § 5.3 places the counters on "an
 * operator view of the health endpoint", and no Appendix B row builds that view; the render is decided
 * here, where the lobby's other § 5.3 rows are, so the view that lands inherits the null rule rather than
 * minting its own. AT-D3-14's panel half asserts it (`Tests\Feature\Floor\TheDrillDownNeverDrawsANullAsAZeroTest`).
 */
export function healthCounters(counters) {
    if (counters === null || typeof counters !== 'object') {
        return { readable: false, statement: UNREADABLE, rows: [] };
    }

    return {
        readable: true,
        statement: null,
        rows: Object.entries(counters)
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([name, value]) => ({ name, value: String(value) })),
    };
}

/**
 * § 9 F17's statement, from the failed layout request's `{ status }` (`../wire/building.js`):
 * "**the building layout could not be loaded — HTTP N**".
 *
 * ⚠ TWO FAILURES F17 DOES NOT WORD, because it words a status code and neither has one to name: a
 * request that never reached a status — worded as this file's snapshot twin is in `main.js` — and a
 * `200` whose body is not a layout, which is F17's own words with what the code does not say added.
 * Neither string is ratified.
 */
export function layoutStatement(failure) {
    if (failure === null) {
        return null;
    }

    if (failure.status === null) {
        return 'the building layout could not be requested — the browser could not reach the server';
    }

    return failure.status === 200
        ? 'the building layout could not be loaded — HTTP 200, and the body is not a layout'
        : `the building layout could not be loaded — HTTP ${failure.status}`;
}

/**
 * The lobby's statement when its page script could not start (card#12319): said in the building readings and the
 * directory in place of their placeholders, which would otherwise say WAITING for a snapshot that no stream will ever
 * bring. The cause is the thrown error's own message. Not a § 9 failure render — those are the protocol's, and a
 * lobby that did not start has no protocol running — and its wording is not ratified.
 */
export function startFailure(error) {
    return `the lobby could not start — ${error instanceof Error ? error.message : String(error)}; reload the page to try again`;
}

/**
 * § 9 F17's cold start: "there is no layout to keep, so under that statement it lists the snapshot's
 * installs as rooms with **no floor claimed**, each a link to `/floor/{install_id}`". No floor, no key,
 * no label and no summary is derived — composing is exactly what the failed document was needed for.
 * `held` is carried so the lobby's held count still counts every seat the client holds.
 */
function unclaimedRooms(snapshot) {
    const installs = Array.isArray(snapshot?.installs) ? snapshot.installs : [];

    return installs.map((install) => {
        const install_id = String(install?.install_id);

        return {
            install_id,
            href: `/floor/${encodeURIComponent(install_id)}`,
            held: Array.isArray(install?.seats) ? install.seats.length : 0,
        };
    });
}

/**
 * The lobby's building summary, from the held population in a snapshot body's shape (`heldBody()`)
 * and the building layout `GET /api/building` answered: the floors, the totals, the stamp and the
 * indicators § 4.1's table names. `lobby-screen.js` adds what the client protocol decides — the
 * discrepancy, the feed status, the failure renders and the event log — and `main.js` renders the
 * strings and decides none of them.
 *
 * ⛔ NO DISCREPANCY AND NO STORE STATEMENT HERE, since card#7341 step 9. Both were computed from the
 * lobby's own one-shot snapshot body while the lobby fetched one; the disagreement is now the
 * protocol's pair (`FleetClient#discrepancyState()`) and the store statement the protocol's `feed`
 * (`failureRender()`), so a second derivation here would be a second answer free to disagree.
 *
 * `layout` is the floors the client holds — `null` when no layout request has ever succeeded — and
 * `layoutFailure` is the last layout request's failure, or `null`.
 *
 * ⛔ NO LAYOUT HELD COMPOSES NO BUILDING (§ 9 F17). `floors()` decides it and answers `null`, and
 * that `null` is what takes the uncomposed render here: F17's cold-start list, and no floors.
 */
export function lobbyModel(snapshot, layout, layoutFailure = null, nowMs = null) {
    // ONE pass over the installs, and the held count summed from the same rows the lobby
    // renders. Calling `heldSeats()` here as well would build the summaries twice and open the
    // one gap that matters: two counts of one population that can disagree.
    const composed = floors(snapshot, layout, nowMs);
    const rows = composed ?? [];
    const unclaimed = composed === null ? unclaimedRooms(snapshot) : [];
    const held = [...rows, ...unclaimed].reduce((n, row) => n + row.held, 0);

    return {
        floors: rows,
        unclaimed,
        layout_statement: layoutStatement(layoutFailure),
        // F17: "over the floors it already holds, labelled *last known layout*" — and on a cold start
        // there are none, so nothing is labelled as kept.
        layout_kept: layoutFailure !== null && composed !== null ? 'last known layout' : null,
        held,
        totals: fleetTotals(snapshot?.fleet),
        stamp: membershipStamp(snapshot?.server_time),
        indicators: indicators(snapshot?.fleet),
    };
}

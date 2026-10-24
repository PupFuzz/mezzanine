/**
 * The coordination layer's render model — `docs/design/FLOOR.md § 5.7`, as pure functions over the
 * `coord.round` messages of D2 § 8.3.3. Since the operator's ruling of 2026-10-23 (card#12239) every
 * coordination render is an EDGE fired by one post on arrival — A19's traveller, A20's ring and A22's
 * portal bird — and nothing is held: no thread, no line, no bead, no label (`coord.thread` is not
 * published, and A18 and A21 are retired rows of § 6.2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO DOM AND NO SOCKET IN THIS FILE: every FACT the floor draws from a post is decided here and
 * asserted directly (`tests/Feature/Coordination`, `tests/Feature/Coord`, driven through `node`).
 *
 * ⛔ THE THREE REFUSALS THE RENDER MAP TURNS ON:
 *
 *   1. NO JOIN IS EVER GUESSED. `resolve()` reads the caller's join and nothing else — never
 *      `name === seat_id`, never a desk by position, order or prefix. The join is
 *      `floor/coord-join.js`'s, one arm per `scope` (D2 § 8.3.3 rule 1): the install arm over one
 *      room's `protocol_agent_name`s, the fleet arm over every held seat's `roundtable_agent_name`.
 *      A name that does not resolve anchors nothing, is REPORTED (`unresolvedEnds()`, the client's
 *      event-log line) and does not suppress the rest of the post.
 *   2. NULL AND EMPTY ARE DIFFERENT ANSWERS on `targets`: `[]` is *this post reached nobody*, `null`
 *      is *the fan-out is not resolved here* — on every broadcast, by design (card#12239 r5).
 *   3. A BROADCAST IS THE RING ALONE: a post whose `to` carries `all` fires A20 and no traveller and
 *      no portal departure (the operator, card#12239 round 4).
 */

import { COORD_ROUND_MEMBERS, COORD_ROUND_MESSAGE } from './coord-members.js';
import { NO_DECLARING_SEAT, UNCHECKED_WORDS, reasonWords } from '../floor/coord-join.js';
import { clockTime } from '../wire/clock.js';

/**
 * What a caller supplies BESIDE the join — D2 § 8.3.3 rule 1's two unresolved reasons, and the
 * resolving seat's check state per bound name (§ 5.7's *rests on an UNCHECKED declaration* row).
 * A caller with no builder passes none and every unresolved name takes rule 1's own default token.
 */
const EMPTY_META = Object.freeze({ reasons: Object.freeze({}), checks: Object.freeze({}) });

/**
 * ⚠ `clockTime` IS SHARED, NOT COPIED. It was the lobby's; card#8300 is its second caller, so it
 * was hoisted to `../wire/clock.js` rather than duplicated here — two copies of one function
 * agree with each other right up until one of them is edited.
 */
export { clockTime };

/** D2 § 8.3.3's own words for the two `targets` answers this client may never collapse. */
export const REACHED_NOBODY = 'this post reached nobody';

export const FANOUT_UNRESOLVABLE = 'the fan-out is not resolvable here';

/**
 * § 5.7 clause 1's rendering for a name that binds to no desk. It is a FIRST-CLASS render and
 * not an error string: it is what the wire actually supports today.
 */
export const UNRESOLVED = 'unresolved';

/**
 * ⛔ THE ONE FUNCTION THAT MAY BIND A NAME TO A DESK, and the only place in this client that
 * touches the join at all. Everything else calls it.
 *
 * `join` is a caller-supplied map from a PROTOCOL AGENT NAME to a `seat_id`. Absent, empty, or
 * missing this name ⇒ `{ resolved: false, seat_id: null }`, which § 5.7 clause 1 reports as
 * `unresolved` and anchors nothing at.
 *
 * ⛔ THERE IS NO ELSE BRANCH. Not `seat_id = name`, not the first seat on the floor, not a
 * prefix match. D2 § 8.3.3: "equality between an agent name and a `seat_id` is a coincidence
 * this plane cannot check", and card#7957 exists to stop an unvalidated join becoming a
 * ratified one by being written here.
 */
export function resolve(name, join, meta = EMPTY_META) {
    const agent = typeof name === 'string' ? name : String(name);
    const seat = join === null || typeof join !== 'object' ? undefined : join[agent];
    const bound = typeof seat === 'string' && seat !== '';
    const check = bound ? (meta.checks?.[agent] ?? null) : null;

    return Object.freeze({
        name: agent,
        seat_id: bound ? seat : null,
        resolved: bound,
        // ⛔ WHY THE REASON IS THE BUILDER'S AND NOT THIS FUNCTION'S. `resolve()` cannot tell an
        // EXCLUDED name from an undeclared one — a duplicated name is absent from the map exactly
        // as a never-declared one is — so the builder hands the two reasons BESIDE the map
        // (`floor/coord-join.js`) and this function reads them. Asking it to decide would put D2
        // § 8.3.3 rule 1's counting inside a function that never sees the seat population.
        // Absent ⇒ `no_declaring_seat`, which is that arm's own token for a name no seat mentions.
        reason: bound ? null : (meta.reasons?.[agent] ?? NO_DECLARING_SEAT),
        // § 5.7's *rests on an UNCHECKED declaration* row: the resolving seat's own check state, and
        // the marker ONLY on `unchecked` — `checked` draws nothing extra, "or every line would
        // carry one".
        check,
        marker: check === 'unchecked' ? UNCHECKED_WORDS : null,
        // The duplicate arm's words, because § 5.7 renders that reason as a SENTENCE and the other
        // as plain `unresolved`: "an install misconfiguration must not read as *no seat may
        // resolve it*".
        words: bound ? null : reasonWords(meta.reasons?.[agent] ?? NO_DECLARING_SEAT),
    });
}

/** Every name in `names`, bound. Order is the wire's, which D2 already sorted and deduplicated. */
function bindAll(names, join, meta) {
    return (Array.isArray(names) ? names : []).map((n) => resolve(n, join, meta));
}

/**
 * § 5.7's opener/origin rule, which is the same rule twice: `opened_by` and `from` are each read
 * WITH the object's `attribution`, because D2 states that "a consumer reading `opened_by`
 * without reading this renders *nobody* where the honest render is *not recoverable*".
 *
 * So the returned `state` is always the wire's raw `attribution` string, and `agent` is `null`
 * whenever the name is. A caller that renders `agent` and drops `state` re-mints exactly the
 * defect D2 named.
 */
function attributedName(name, attribution, join, meta) {
    const named = typeof name === 'string' && name !== '';

    return Object.freeze({
        state: typeof attribution === 'string' ? attribution : null,
        agent: named ? resolve(name, join, meta) : null,
    });
}

/** `coord_round.targets`: `null`, `[]` and a populated array are THREE answers, kept apart. */
function fanout(targets, join, meta) {
    if (targets === null || targets === undefined) {
        return Object.freeze({ known: false, statement: FANOUT_UNRESOLVABLE, desks: [], members: [] });
    }

    const members = bindAll(targets, join, meta);

    return Object.freeze({
        known: true,
        statement: members.length === 0 ? REACHED_NOBODY : null,
        desks: members.filter((m) => m.resolved),
        members,
    });
}

/** One `coord.round` object, rendered — § 5.7's render map. */
export function roundRender(round, join, meta = EMPTY_META) {
    const r = round === null || typeof round !== 'object' ? {} : round;
    const to = Array.isArray(r.to) ? r.to.map((v) => (typeof v === 'string' ? v : String(v))) : [];

    return Object.freeze({
        post_ref: typeof r.post_ref === 'string' ? r.post_ref : null,
        thread_ref: typeof r.thread_ref === 'string' ? r.thread_ref : null,
        // D2 § 8.3.3: which declaration joins this post's names, and whether it has a room at all
        // (card#12239). Raw: a value this client has not met selects no arm (§ 5.7's `scope` row).
        scope: typeof r.scope === 'string' ? r.scope : null,
        install_id: typeof r.install_id === 'string' ? r.install_id : null,
        origin: attributedName(r.from, r.attribution, join, meta),
        // The address AS WRITTEN. `all` is a literal member and is never expanded here.
        to,
        broadcast: to.includes('all'),
        targets: fanout(r.targets, join, meta),
        carrier: typeof r.carrier === 'string' ? r.carrier : null,
        // Somebody performed the close act. NOT a convergence, and not worded as one.
        declares_close: r.declares_close === true,
        received: clockTime(r.received_at),
        posted: clockTime(r.posted_at),
    });
}

/**
 * § 6.2's edges for one INSTALL-scope post in its own room, decided from the rendered post alone.
 *
 * A19 `envelope` — the traveller: an origin that resolves and at least one destination that does, and
 *     a `to` that does NOT carry `all`. An unresolved destination gets none and cancels no other.
 * A20 `broadcast-pulse` — `to` carries the literal `all` AND the origin resolves: the ring, and only
 *     the ring.
 */
export function roundAnimations(round) {
    const out = [];
    // ⛔ BOTH ROWS LEAVE THE ORIGIN DESK, so both need one: A19 has nowhere to depart from without it
    // and A20 nothing to expand from (§ 6.2 A20's trigger cell states both halves).
    const origin = round.origin.agent !== null && round.origin.agent.resolved;

    if (origin && !round.broadcast && round.targets.desks.length > 0) {
        out.push('A19');
    }

    if (origin && round.broadcast) {
        out.push('A20');
    }

    return out;
}

/**
 * Every end of a post that resolves to no desk, with D2's reason in § 5.7's words — the client's
 * event-log line for that post (§ 5.7 property 1): `duplicate_declaration` as *declared by more than
 * one seat*, any other as *unresolved*. An end that resolved through an `unchecked` install-arm
 * declaration rides the same line as *unchecked*.
 */
export function unresolvedEnds(round) {
    const ends = [...(round.origin.agent === null ? [] : [round.origin.agent]), ...round.targets.members]
        .filter((e) => !e.resolved || e.marker !== null)
        .map((e) => Object.freeze({ name: e.name, words: e.resolved ? e.marker : (e.words ?? UNRESOLVED) }));

    // A post that evidences no author (`from` null) has no origin to resolve, and § 5.7's origin row has
    // the line name the attribution state instead — never *nobody*.
    return round.origin.agent === null
        ? [Object.freeze({ name: 'the author', words: `${UNRESOLVED} (attribution ${round.origin.state ?? 'absent'})` }), ...ends]
        : ends;
}

/**
 * One ROOM's install-scope posts, from the `coord.round` messages a render applied.
 *
 * `options.install_id` is the room. ⛔ A post whose `install_id` is not this room's, or whose `scope`
 * is not `install`, is not this room's and is counted `off_floor`, never drawn: an install-scope post
 * animates in the room its binding names and in no other (§ 5.7 property 3), and a fleet-scope post is
 * `fleetModel()`'s. A post repeated in the list is drawn once (`post_ref` is a post's identity).
 */
export function coordModel(messages, options) {
    const opts = options === null || typeof options !== 'object' ? {} : options;
    const room = typeof opts.install_id === 'string' ? opts.install_id : null;
    const join = opts.join === null || typeof opts.join !== 'object' ? null : opts.join;
    const meta = Object.freeze({
        reasons: opts.reasons === null || typeof opts.reasons !== 'object' ? EMPTY_META.reasons : opts.reasons,
        checks: opts.checks === null || typeof opts.checks !== 'object' ? EMPTY_META.checks : opts.checks,
    });
    const rounds = [];
    const seen = new Set();
    let offFloor = 0;

    for (const message of Array.isArray(messages) ? messages : []) {
        const body = message?.t === COORD_ROUND_MESSAGE ? message.coord_round : null;

        if (body === null || typeof body !== 'object') {
            continue;
        }

        if ((room !== null && body.install_id !== room) || body.scope !== INSTALL_SCOPE) {
            offFloor += 1;

            continue;
        }

        const rendered = roundRender(body, join, meta);

        if (rendered.post_ref !== null && seen.has(rendered.post_ref)) {
            continue;
        }

        if (rendered.post_ref !== null) {
            seen.add(rendered.post_ref);
        }

        rounds.push(Object.freeze({ ...rendered, animations: roundAnimations(rendered) }));
    }

    return Object.freeze({
        install_id: room,
        join_available: join !== null,
        rounds: Object.freeze(rounds),
        off_floor: offFloor,
    });
}

/** D2 § 8.3.3's two `scope` values (card#12239). */
export const INSTALL_SCOPE = 'install';

export const FLEET_SCOPE = 'fleet';

/**
 * The FLEET-scope posts of D2 § 8.3.3, rendered for ONE floor (card#12239; COORD-STORE.md § 6.1, D3 § 5.7
 * property 3). Every name joins through the fleet arm — `floor/coord-join.js`'s `buildFleetJoin()`, whose
 * map is name → `install_id/seat_id` — and each resolved end is then a desk ON THIS FLOOR (its key is in
 * `options.here`) or a desk on another floor of the building. An end with no desk anchors nothing.
 *
 * Per post, the rules the operator ruled and nothing else:
 *   - A20 when `to` carries `all` and the origin is a desk here — the ring, and nothing else: no traveller
 *     and no portal departure (r3 MINOR-2, settled by the operator);
 *   - otherwise A19 when the origin is a desk here, once per destination desk here;
 *   - D3 § 6.2's A22, the portal bird, when BOTH ends resolve and one is on another floor:
 *     `portal.departure` for an origin here with a destination elsewhere (once per post),
 *     `portal.arrivals` for each destination here whose origin is elsewhere (D3 AT-D3-29).
 *
 * @param {list<object>} messages the feed's envelopes, in arrival order
 * @param {{join: object, reasons: object, checks: object, here: Set<string>}} options
 */
export function fleetModel(messages, options) {
    const opts = options === null || typeof options !== 'object' ? {} : options;
    const join = opts.join === null || typeof opts.join !== 'object' ? null : opts.join;
    const meta = Object.freeze({
        reasons: opts.reasons === null || typeof opts.reasons !== 'object' ? EMPTY_META.reasons : opts.reasons,
        checks: opts.checks === null || typeof opts.checks !== 'object' ? EMPTY_META.checks : opts.checks,
    });
    const here = opts.here instanceof Set ? opts.here : new Set();
    const rounds = [];
    const seen = new Set();

    for (const message of Array.isArray(messages) ? messages : []) {
        const body = message?.t === COORD_ROUND_MESSAGE ? message.coord_round : null;

        if (body === null || typeof body !== 'object' || body.scope !== FLEET_SCOPE) {
            continue;
        }

        const rendered = roundRender(body, join, meta);

        if (rendered.post_ref !== null && seen.has(rendered.post_ref)) {
            continue;
        }

        if (rendered.post_ref !== null) {
            seen.add(rendered.post_ref);
        }

        rounds.push(Object.freeze({ ...rendered, ...fleetAnimations(rendered, here) }));
    }

    return Object.freeze({ rounds: Object.freeze(rounds) });
}

/** One fleet-scope round's edges on this floor — `fleetModel()`'s rules, from the rendered round alone. */
export function fleetAnimations(round, here) {
    const origin = round.origin.agent !== null && round.origin.agent.resolved ? round.origin.agent.seat_id : null;
    const originHere = origin !== null && here.has(origin);
    const desks = round.targets.desks.map((d) => d.seat_id);
    const local = desks.filter((key) => here.has(key));
    const far = desks.filter((key) => !here.has(key));
    const animations = [];

    if (round.broadcast) {
        if (originHere) {
            animations.push('A20');
        }

        return { animations, travellers: [], portal: Object.freeze({ departure: false, arrivals: [] }) };
    }

    if (originHere && local.length > 0) {
        animations.push('A19');
    }

    const departure = originHere && far.length > 0;
    const arrivals = origin !== null && !originHere ? local : [];

    return {
        animations,
        travellers: originHere ? local : [],
        portal: Object.freeze({ departure, arrivals: Object.freeze(arrivals) }),
    };
}

/**
 * The § 6.2 rows ONE drawn post writes on this floor, in order — `{animation_id, install_id}` per row, the
 * room each is logged against. The floor screen fires exactly these and decides none itself, so the
 * per-post rules are asserted here, through `node`, rather than through a page:
 *
 *   - an install-scope post (`room` its room): A19 once per destination desk, A20 once;
 *   - a fleet-scope post (`room` null): A19 once per traveller, A20 once, in the ORIGIN's room — and
 *     A22 once for a departure (the origin's room) and once per arrival (each destination's room).
 *
 * A fleet-scope end is an `install_id/seat_id` key, so its room is the key's first half.
 */
export function edgesOf(round, room = null) {
    const roomOf = (key) => (room !== null ? room : key.split('/')[0]);
    const origin = round.origin.agent?.resolved ? roomOf(round.origin.agent.seat_id) : null;
    const travellers = room !== null ? round.targets.desks : round.travellers;
    const out = [];

    if (round.animations.includes('A19')) {
        for (let i = 0; i < travellers.length; i++) {
            out.push(Object.freeze({ animation_id: 'A19', install_id: origin }));
        }
    }

    if (round.animations.includes('A20')) {
        out.push(Object.freeze({ animation_id: 'A20', install_id: origin }));
    }

    if (room === null && round.portal.departure) {
        out.push(Object.freeze({ animation_id: 'A22', install_id: origin }));
    }

    for (const key of room === null ? round.portal.arrivals : []) {
        out.push(Object.freeze({ animation_id: 'A22', install_id: roomOf(key) }));
    }

    return Object.freeze(out);
}

/** The member set and message type, re-exported so a probe reads one module. */
export { COORD_ROUND_MEMBERS, COORD_ROUND_MESSAGE };

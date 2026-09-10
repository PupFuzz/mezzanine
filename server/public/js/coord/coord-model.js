/**
 * The coordination thread line's render model — `docs/design/FLOOR.md § 5.7`, as pure functions
 * over the `coord.thread` and `coord.round` messages of D2 § 8.3.3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO DOM AND NO SOCKET IN THIS FILE, for the reason `../lobby/lobby-model.js` states of
 * itself: there is no browser on the build host, so anything touching a document is a thing no
 * check here can exercise. Every FACT this surface renders is decided here and asserted directly
 * (`tests/Feature/Coordination`, driven through `node`); `main.js` puts these values into
 * elements and decides nothing.
 *
 * ⛔ EVERY RENDERED FACT NAMES ITS D2 MEMBER (§ 1.3 corollary 1). The map is § 5.7's table and
 * is not restated here; what IS here, because it cannot be read off a table, is the shape of the
 * three refusals that table turns on:
 *
 *   1. NO JOIN IS EVER GUESSED. `resolve()` reads the caller's join and nothing else. It never
 *      falls back to `name === seat_id`, which D2 § 8.3.3 calls "a coincidence this plane cannot
 *      check", and it never picks a desk by position, by order or by prefix. A name that does
 *      not resolve is REPORTED as unresolved and DOES NOT SUPPRESS the rest of the object
 *      (card#7957 ruling (2)). ⚠ NOTHING PRODUCES A JOIN TODAY — no wire event and no config in
 *      this repository carries one — so `main.js` passes none and every name resolves to
 *      `null`. That is why the parameter exists rather than being deleted: the renderer is
 *      correct on day one and does not change shape when the join lands.
 *
 *   2. NULL AND EMPTY ARE DIFFERENT ANSWERS, on `coord_round.targets` above all: `[]` is "this
 *      post reached nobody", `null` is "the fan-out is not resolvable here". Collapsing them is
 *      the one defect on this surface that turns an unknown into a measurement.
 *
 *   3. NOTHING HERE IS A CONVERGENCE. `lifecycle: "closed"` is that the thread ENDED and
 *      `declares_close` is that somebody PERFORMED the close act. D2 publishes no `converged`
 *      flag and no ledger to compute one from, and § 5.7 forbids rendering either as one.
 *
 * ⛔ NO DURATION IS RENDERED ANYWHERE IN THIS CLIENT, and the reason is § 5.7 property 4: D3
 * § 2.4 publishes the duration FORMAT but fixes one rendered WORDING per fact and publishes none
 * for a coordination age, and § 14 item 17 owns that gap "rather than [having it] filled by
 * whichever surface reaches them first". So both receipt clocks render as LABELLED TIMESTAMPS
 * and nothing on this surface is subtracted from anything. This is the second surface to make
 * that call; card#7341's lobby was the first.
 *
 * ⛔ NO ENUM VOCABULARY. `lifecycle`, `attribution` and `carrier` are rendered as the wire's raw
 * strings — see `coord-members.js` for why D2's declining to enum them makes a set here a second
 * home rather than a safety net. The one value this module compares against a literal is
 * `lifecycle === "closed"`, which § 5.7 fixes as the ONLY value the ended treatment applies to,
 * and `to` containing `"all"`, which D2 keeps verbatim on the wire precisely so a reader can
 * test for it without a boolean.
 */

import {
    COORD_ROUND_MEMBERS,
    COORD_ROUND_MESSAGE,
    COORD_THREAD_MEMBERS,
    COORD_THREAD_MESSAGE,
} from './coord-members.js';
import { clockTime } from '../wire/clock.js';

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

/** The mark a wire-truncated subject carries, so a clipped string is not read as a whole one. */
export const TRUNCATION_MARK = '…';

/**
 * ⛔ THE ONE FUNCTION THAT MAY BIND A NAME TO A DESK, and the only place in this client that
 * touches the join at all. Everything else calls it.
 *
 * `join` is a caller-supplied map from a PROTOCOL AGENT NAME to a `seat_id`. Absent, empty, or
 * missing this name ⇒ `{ resolved: false, seat_id: null }`, which § 5.7 clause 1 renders as
 * `unresolved` and draws no line for.
 *
 * ⛔ THERE IS NO ELSE BRANCH. Not `seat_id = name`, not the first seat on the floor, not a
 * prefix match. D2 § 8.3.3: "equality between an agent name and a `seat_id` is a coincidence
 * this plane cannot check", and card#7957 exists to stop an unvalidated join becoming a
 * ratified one by being written here.
 */
export function resolve(name, join) {
    const agent = typeof name === 'string' ? name : String(name);
    const seat = join === null || typeof join !== 'object' ? undefined : join[agent];
    const bound = typeof seat === 'string' && seat !== '';

    return Object.freeze({ name: agent, seat_id: bound ? seat : null, resolved: bound });
}

/** Every name in `names`, bound. Order is the wire's, which D2 already sorted and deduplicated. */
function bindAll(names, join) {
    return (Array.isArray(names) ? names : []).map((n) => resolve(n, join));
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
function attributedName(name, attribution, join) {
    const named = typeof name === 'string' && name !== '';

    return Object.freeze({
        state: typeof attribution === 'string' ? attribution : null,
        agent: named ? resolve(name, join) : null,
    });
}

/** `coord_round.targets`: `null`, `[]` and a populated array are THREE answers, kept apart. */
function fanout(targets, join) {
    if (targets === null || targets === undefined) {
        return Object.freeze({ known: false, statement: FANOUT_UNRESOLVABLE, desks: [], members: [] });
    }

    const members = bindAll(targets, join);

    return Object.freeze({
        known: true,
        statement: members.length === 0 ? REACHED_NOBODY : null,
        desks: members.filter((m) => m.resolved),
        members,
    });
}

/**
 * One `coord.thread` object, rendered. § 5.7's first seven rows.
 *
 * `ended` is `lifecycle === 'closed'` and nothing else — § 5.7 fixes that as the only value the
 * ended treatment applies to, and `lifecycle` itself is carried through raw so a value this
 * client has not met is SHOWN rather than mapped to the nearest one it knows.
 */
export function threadRender(thread, join) {
    const t = thread === null || typeof thread !== 'object' ? {} : thread;
    const subject = typeof t.subject === 'string' ? t.subject : null;

    return Object.freeze({
        thread_ref: typeof t.thread_ref === 'string' ? t.thread_ref : null,
        install_id: typeof t.install_id === 'string' ? t.install_id : null,
        // Raw, always. The treatment is the boolean beside it.
        lifecycle: typeof t.lifecycle === 'string' ? t.lifecycle : null,
        ended: t.lifecycle === 'closed',
        // A null subject is NO label — never a placeholder and never the last one held.
        label: subject === null ? null : subject + (t.subject_truncated === true ? TRUNCATION_MARK : ''),
        truncated: t.subject_truncated === true,
        carrier: typeof t.carrier === 'string' ? t.carrier : null,
        opener: attributedName(t.opened_by, t.attribution, join),
        participants: bindAll(t.participants, join),
        // The receipt clock, as a LABELLED TIMESTAMP. No age is computed from it here.
        received: clockTime(t.received_at),
        // GitHub's clock — a third clock. Labelled as the poster's own, subtracted from nothing.
        posted: clockTime(t.posted_at),
    });
}

/** One `coord.round` object, rendered. § 5.7's remaining rows. */
export function roundRender(round, join) {
    const r = round === null || typeof round !== 'object' ? {} : round;
    const to = Array.isArray(r.to) ? r.to.map((v) => (typeof v === 'string' ? v : String(v))) : [];

    return Object.freeze({
        post_ref: typeof r.post_ref === 'string' ? r.post_ref : null,
        thread_ref: typeof r.thread_ref === 'string' ? r.thread_ref : null,
        install_id: typeof r.install_id === 'string' ? r.install_id : null,
        origin: attributedName(r.from, r.attribution, join),
        // The address AS WRITTEN. `all` is a literal member and is never expanded here.
        to,
        broadcast: to.includes('all'),
        targets: fanout(r.targets, join),
        carrier: typeof r.carrier === 'string' ? r.carrier : null,
        // Somebody performed the close act. NOT a convergence, and not worded as one.
        declares_close: r.declares_close === true,
        received: clockTime(r.received_at),
        posted: clockTime(r.posted_at),
    });
}

/**
 * § 6.2's three coordination animations, decided from the rendered objects and from nothing
 * else. Returning them as data — rather than firing them — is what lets a test assert the
 * honesty principle without a browser: an animation this list does not contain is one
 * `main.js` has no way to play.
 *
 * A18 `thread-line` — HELD, and it needs TWO resolved endpoints. One endpoint is not a line and
 *     a guessed second one is § 5.7 clause 1's forbidden render.
 * A19 `envelope`    — EDGE, one applied `coord.round` with a resolved origin and at least one
 *     resolved destination. An unresolved destination gets none and does not cancel the others.
 * A20 `broadcast-pulse` — EDGE, `to` carries the literal `all`.
 */
export function threadAnimations(thread) {
    return thread.ended || thread.participants.filter((p) => p.resolved).length < 2 ? [] : ['A18'];
}

export function roundAnimations(round) {
    const out = [];

    if (round.origin.agent !== null && round.origin.agent.resolved && round.targets.desks.length > 0) {
        out.push('A19');
    }

    if (round.broadcast) {
        out.push('A20');
    }

    return out;
}

/**
 * The whole coordination layer of one floor, from the messages that floor's feed has delivered.
 *
 * `options.install_id` is the floor being drawn. ⛔ A message whose `install_id` is not that
 * floor's is EXCLUDED and counted, never drawn: `install_id` comes from the hook binding, so
 * "a broadcast's reach equals the install's, and a renderer that draws wider is drawing past its
 * event" (D2 § 8.3.3). The count is kept so the exclusion is visible rather than silent.
 *
 * `options.join` is the name→`seat_id` map of `resolve()` above. Omitted — which is what
 * `main.js` does and what every deployment does today — nothing resolves and no line is drawn.
 *
 * ⛔ THE BEAD COUNT IS A COUNT OF DISTINCT `post_ref`, per D2: these messages carry no `seq`,
 * "a lost coordination message is not detectable", and identity is all a consumer has. A
 * redelivered post therefore draws nothing new, which is the one thing that makes the count mean
 * anything at all.
 */
export function coordModel(messages, options) {
    const opts = options === null || typeof options !== 'object' ? {} : options;
    const floor = typeof opts.install_id === 'string' ? opts.install_id : null;
    const join = opts.join === null || typeof opts.join !== 'object' ? null : opts.join;
    const inbox = Array.isArray(messages) ? messages : [];

    const threads = new Map();
    const rounds = [];
    const seenPosts = new Set();
    let off_floor = 0;

    for (const message of inbox) {
        const m = message === null || typeof message !== 'object' ? {} : message;

        if (m.t !== COORD_THREAD_MESSAGE && m.t !== COORD_ROUND_MESSAGE) {
            continue;
        }

        const body = m.t === COORD_THREAD_MESSAGE ? m.coord_thread : m.coord_round;
        const install = body === null || typeof body !== 'object' ? null : body.install_id;

        if (floor !== null && install !== floor) {
            off_floor += 1;

            continue;
        }

        if (m.t === COORD_THREAD_MESSAGE) {
            const rendered = threadRender(body, join);

            // The LAST `coord.thread` for a `thread_ref` is the thread's state: `thread_ref` is
            // the identity, so a reopen replaces the close rather than appending to it.
            threads.set(rendered.thread_ref, rendered);

            continue;
        }

        const rendered = roundRender(body, join);

        // Distinct `post_ref`, and the repeat is DROPPED rather than counted — an operator
        // Redeliver older than the digest store's retention is the one path that reaches this
        // feed twice for one real post, "and it arrives carrying the same `post_ref`".
        if (rendered.post_ref !== null && seenPosts.has(rendered.post_ref)) {
            continue;
        }

        if (rendered.post_ref !== null) {
            seenPosts.add(rendered.post_ref);
        }

        rounds.push(Object.freeze({ ...rendered, animations: roundAnimations(rendered) }));
    }

    const lines = [...threads.values()].map((thread) => {
        const beads = rounds.filter((r) => r.thread_ref === thread.thread_ref).length;
        const animations = threadAnimations(thread);

        return Object.freeze({
            ...thread,
            beads,
            animations,
            // The endpoints, and there is no line without two of them.
            endpoints: animations.includes('A18')
                ? thread.participants.filter((p) => p.resolved).map((p) => p.seat_id)
                : [],
            unresolved: thread.participants.filter((p) => !p.resolved).map((p) => p.name),
        });
    });

    const unresolved = new Set();

    for (const line of lines) {
        line.unresolved.forEach((n) => unresolved.add(n));
    }

    for (const round of rounds) {
        if (round.origin.agent !== null && !round.origin.agent.resolved) {
            unresolved.add(round.origin.agent.name);
        }

        round.targets.members.filter((m) => !m.resolved).forEach((m) => unresolved.add(m.name));
    }

    return Object.freeze({
        install_id: floor,
        // Present so a caller cannot mistake "no join configured" for "nothing resolved today".
        join_available: join !== null,
        threads: lines,
        rounds,
        unresolved: [...unresolved].sort(),
        drawn_lines: lines.filter((l) => l.animations.includes('A18')).length,
        off_floor,
        // ⛔ NEVER a *no coordination* or *quiet* rendering. § 5.7: no REST surface carries these
        // objects, so an empty layer is the ordinary state of a page that has just loaded and is
        // never evidence that the fleet is not talking. The caller is handed the FACT and has no
        // verdict to render. ⚠ It is named `empty` and not *empty because nothing was sent*:
        // `off_floor` above is a second way to be empty, and a flag that asserted a CAUSE would
        // be wrong on exactly that case.
        empty: lines.length === 0 && rounds.length === 0,
    });
}

/** The member sets, re-exported so the probe reads one module. */
export { COORD_THREAD_MEMBERS, COORD_ROUND_MEMBERS, COORD_THREAD_MESSAGE, COORD_ROUND_MESSAGE };

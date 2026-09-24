/**
 * THE JOIN — one room's `protocol_agent_name` → `seat_id` map, built from the seat population this
 * client already holds. `docs/design/FLEET-STATE.md § 8.3.3` rules 1 and 2, and
 * `docs/design/FLOOR.md § 5.7` clause 1's render. Appendix B row 7, card#7341 step 7.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS IS THE MAP `coord/coord-model.js` HAS TAKEN AS A PARAMETER SINCE card#8300 AND NOTHING
 * BUILT. That module's header names the builder's obligations and this file is the builder; the
 * resolution itself is still `resolve()`'s, which stays one name to one `seat_id` or to none. No
 * rule moved here — what moved is that somebody now hands it a map.
 *
 * ⛔ THE TWO ARMS ARE DECIDED IN D2's ORDER, AND THE ORDER IS THE WHOLE DEFECT. The duplicate arm
 * runs FIRST, over every seat of the install that DECLARES the name — `checked`, `unchecked` and
 * `disagreed` alike, only `undeclared` left out — and rule 2's `checked`/`unchecked` filter is
 * applied ONLY to a name exactly one seat declares. A builder that filters first sees one resolving
 * seat beside a `disagreed` one and draws to it: "the pick arriving through the order of two
 * correct rules", which D2 § 8.3.3 rule 1 forbids by name and D2 § 13 row 50 is the ruling on.
 *
 * ⛔ AND IT IS NEVER AN ASSIGNMENT OVER THE POPULATION. `map[name] = seat_id` in a loop is
 * last-writer-wins over an unordered seat set — "a pick wearing a map's clothes, whose answer
 * changes with the order the seats happened to arrive in". Every name is COUNTED before any of them
 * is bound, so no order of the input can change an answer.
 *
 * ⛔ THE TWO UNRESOLVED REASONS ARE DISTINCT AND ARE REPORTED, not folded into one (§ 5.7
 * clause 1): `duplicate_declaration` is an install MISCONFIGURATION and `no_declaring_seat` is
 * *no seat may resolve it*. Rendering the first as the second shows an operator the opposite
 * diagnosis, and this client is the only party that can see the violation at all — a seat's own
 * check asks whether its name is in the roster, and two seats declaring one name both pass.
 *
 * ⛔ NO FALLBACK, IN ANY FORM (D2 § 8.3.3 rule 3). Not `name === seat_id`, not a prefix, not *the
 * only seat in the room*. A name that merely EQUALS a `seat_id` it was never declared on is absent
 * from the returned map, so `resolve()` answers unresolved for it without having to know why.
 */

/** D2 § 8.2.1's check states that RESOLVE — rule 2, and the whole of it. */
const RESOLVING_CHECKS = Object.freeze(['checked', 'unchecked']);

/** The one check state that is not a declaration at all (rule 1's *only an `undeclared` seat*). */
const NOT_A_DECLARATION = 'undeclared';

/** D2 § 8.3.3's own tokens for the two unresolved arms. Every consumer uses these two. */
export const NO_DECLARING_SEAT = 'no_declaring_seat';

export const DUPLICATE_DECLARATION = 'duplicate_declaration';

/**
 * § 5.7's rendering for the duplicate arm — *words beside the name*, because rendering it as a
 * plain `unresolved` shows an install misconfiguration as *no seat may resolve it*.
 */
export const DUPLICATE_WORDS = 'declared by more than one seat';

/**
 * § 5.7 clause 1's marker for a resolved endpoint whose declaration nobody checked. `checked` draws
 * nothing extra — "the ordinary case carries no marker, or every line would carry one".
 */
export const UNCHECKED_WORDS = 'unchecked';

/**
 * Build one room's join.
 *
 * @param {Iterable<object>} seats the seat objects of ONE install — D2 § 8.2.1 objects, as the
 *        client protocol holds them. Scoping is the caller's: a name declared in another install is
 *        another install's fact (rule 1), and § 5.7 clause 3 draws a round in one room only.
 * @returns {{join: Record<string, string>, reasons: Record<string, string>, checks: Record<string, string>}}
 *          `join` is `resolve()`'s map; `reasons` carries D2's token for every name that declared
 *          and did not resolve; `checks` carries the resolving seat's own check state per bound
 *          name, which is what § 5.7's *rests on an UNCHECKED declaration* row renders.
 */
export function buildJoin(seats) {
    // Phase 1 — COUNT. Every declaring seat of every name, before any name is bound.
    const declarers = new Map();

    for (const seat of seats) {
        const name = seat?.protocol_agent_name;
        const check = seat?.protocol_agent_name_check ?? null;

        if (typeof name !== 'string' || name === '' || check === NOT_A_DECLARATION) {
            continue;
        }

        if (!declarers.has(name)) {
            declarers.set(name, []);
        }

        declarers.get(name).push({ seat_id: seat.seat_id, check });
    }

    // Phase 2 — BIND. The duplicate arm first, then rule 2's filter on the one seat left.
    const join = {};
    const reasons = {};
    const checks = {};

    for (const [name, claims] of declarers) {
        if (claims.length > 1) {
            reasons[name] = DUPLICATE_DECLARATION;

            continue;
        }

        const [claim] = claims;

        if (!RESOLVING_CHECKS.includes(claim.check)) {
            // A lone `disagreed` declarer, and a seat whose last heartbeat carried no check state:
            // each DECLARES and neither resolves, so the arm is named for its outcome (rule 1).
            reasons[name] = NO_DECLARING_SEAT;

            continue;
        }

        join[name] = claim.seat_id;
        checks[name] = claim.check;
    }

    return Object.freeze({ join: Object.freeze(join), reasons: Object.freeze(reasons), checks: Object.freeze(checks) });
}

/**
 * § 5.7 clause 1's reason for ONE name, as a consumer renders it: D2's token for a name that
 * declared and did not resolve, and `no_declaring_seat` for a name no seat of this room mentions at
 * all — which is the same arm and the same outcome, so it takes the same token rather than a third.
 */
export function unresolvedReason(name, reasons) {
    return reasons[name] ?? NO_DECLARING_SEAT;
}

/**
 * The words § 5.7 renders beside an unresolved participant. A `duplicate_declaration` carries its
 * own sentence; the other arm is `coord-model.js`'s `UNRESOLVED`, which the caller already has.
 */
export function reasonWords(reason) {
    return reason === DUPLICATE_DECLARATION ? DUPLICATE_WORDS : null;
}

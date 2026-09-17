/**
 * `docs/design/FLOOR.md § 4.1`'s one-fetch-per-distinct-`(N, M)` budget, and the predicate that
 * decides whether two counts disagree at all. card#7341 Appendix B step 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT LIVES IN `wire/` BECAUSE IT HAS TWO CONSUMERS, AND ONE OF THEM IS NOT THE LOBBY. The
 * budget was `lobby/lobby-model.js`'s while the lobby's own § 4.1 trigger was the only thing that
 * spent it; the client protocol (`wire/fleet-client.js`) now spends the same budget for the same
 * disagreement, and a second copy of a rule with a memory is two memories — the first thing they
 * do is disagree about what has already been spent. `lobby-model.js` imports and re-exports this
 * class, exactly as it re-exports `clockTime`, so every lobby caller's import is unchanged.
 *
 * ⛔ A `wire/` MODULE MUST NOT IMPORT A `lobby/` ONE, WHICH IS WHY `disagrees` IS HERE. The
 * budget's own admission test used to ask `discrepancyNotice()` — the lobby's WORDING function —
 * whether the counts disagree, which made "do these two numbers differ" a fact only the lobby
 * could answer. It is one predicate with two readers (this budget, and the notice that words the
 * disagreement), so it is stated once, here, and the notice asks it too.
 *
 * ⚠ `refund` EXISTS BECAUSE A FETCH CAN FAIL, AND A PAIR THE FAILED FETCH NEVER ANSWERED IS NOT
 * SPENT (§ 2.3 as card#7341 step 3 leaves it; the protocol's S11). The budget bounds a
 * disagreement to ONE ANSWER, not to one attempt: a discovery that returned `503` answered
 * nothing, so the next `fleet{}` carrying the same `(held, total)` must be able to try again.
 * Without it a single refusal costs that pair its only check for the life of the connection.
 */

/**
 * Whether a held count and a reported total disagree — § 4.1's condition, and nothing else.
 *
 * A `total` that is not an integer is not a disagreement: the wire did not report one, and a
 * client that treated *not reported* as a mismatch would fetch on every heartbeat of a degraded
 * fleet object.
 */
export function disagrees(held, total) {
    return Number.isInteger(total) && held !== total;
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
 */
export class DiscrepancyBudget {
    #spent = new Set();

    /** True at most once per distinct `(held, total)` pair, and never while they agree. */
    admits(held, total) {
        if (!disagrees(held, total)) {
            return false;
        }

        const key = `${held}/${total}`;

        if (this.#spent.has(key)) {
            return false;
        }

        this.#spent.add(key);

        return true;
    }

    /**
     * The fetch this pair admitted FAILED, so the pair is not spent — the next observation of the
     * same `(held, total)` is admitted again. See this file's header.
     */
    refund(held, total) {
        this.#spent.delete(`${held}/${total}`);
    }

    /**
     * Whether `(held, total)` has been admitted and not since refunded — READ-ONLY, and that is
     * the whole point of its existing beside `admits`.
     *
     * ⛔ ASKING MUST NOT SPEND. The lobby's notice needs to tell "the one check this pair gets is
     * still to come" from "it has run and the disagreement survived it" in order to stop claiming
     * a refresh that is not running (§ 4.1 as card#7341 step 3 leaves it). Answering that with
     * `admits()` would consume the very check it is asking about.
     */
    hasSpent(held, total) {
        return this.#spent.has(`${held}/${total}`);
    }

    /** How many fetches this budget has admitted — the client's own count of its own acts. */
    get spent() {
        return this.#spent.size;
    }
}

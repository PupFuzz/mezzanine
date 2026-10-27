/**
 * THE `post_ref` MEMORY — `docs/design/FLOOR.md § 5.7`'s *the post, drawn once* row and § 12's
 * *Remembered coordination post identities* (card#12239). The one coordination state the floor keeps:
 * the identities of the posts it has already drawn, so a repeat of one fires no edge.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ BOUNDED, OLDEST FORGOTTEN FIRST. A redelivery older than the memory animates again, which § 5.7
 * accepts and states rather than guarding against with a store this page does not have. Nothing about
 * a post is held here but its identity. The bound is held to § 12's figure by
 * `tests/Feature/Floor/TheCoordinationPostMemoryIsHeldToItsBoundTest.php`.
 */

/** § 12's *Remembered coordination post identities*. */
export const POST_MEMORY = 1000;

export class PostMemory {
    #bound;

    #refs = new Set();

    constructor(bound = POST_MEMORY) {
        this.#bound = bound;
    }

    /**
     * Whether this is the FIRST sight of `ref` within the memory — and, when it is, remember it. A
     * `null` identity is never remembered and is always a first sight: a post the wire did not name
     * cannot be told from another one.
     */
    first(ref) {
        if (ref === null || ref === undefined) {
            return true;
        }

        if (this.#refs.has(ref)) {
            return false;
        }

        this.#refs.add(ref);

        while (this.#refs.size > this.#bound) {
            this.#refs.delete(this.#refs.values().next().value);
        }

        return true;
    }

    /** How many identities are remembered now — never more than the bound. */
    get size() {
        return this.#refs.size;
    }
}

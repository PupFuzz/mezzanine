/**
 * `docs/design/FLOOR.md § 7.1`'s ten `render_state` members, in § 7.1's own fixed order —
 * WRITTEN ONCE, HERE, and imported by everything that needs them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ORDER IS THE CONTRACT, NOT JUST THE SET. § 4.1: the lobby's per-floor summary renders
 * "a count per `render_state` member present … in § 7.1's fixed member order". A set that agrees
 * while the order has drifted renders a summary in an order nobody ratified, and a set-equality
 * check cannot see it — so `LobbyMemberSetMatchesTheDocumentTest` compares this ARRAY, position
 * by position, against § 7.1's table RE-DERIVED from `FLOOR.md` on every run, in BOTH directions.
 *
 * ⛔ ONE COPY. `docs/design/floor-preview/README.md`: "Copy the table, never one of the
 * derivations: **six** copies of one member set are what this replaced — four of them covering
 * only four members (card#7943) — and a second member set, in any spelling, is how the
 * unrecognised case gets lost again." Every surface in this client that needs the members
 * imports this array; none of them writes a second one.
 *
 * ⛔ `thinking` IS NOT A MEMBER, and this is where the next reader finds that out before adding
 * it. D2 sends the ten below; the *think pose* is `FLOOR.md § 6.2` A4's condition over a
 * `working` seat (`open_calls == 0` ∧ `open_turn == true`) — a DERIVATION, never a wire member.
 * The lobby draws no pose, so it derives nothing here: it counts what the wire said.
 *
 * ⚠ `retired` is a member of this ORDER and is not a state a snapshot's seat is ever in: the
 * read surfaces stop selecting a retired seat at `retired_at` (D2 § 4.10, card#9078). It is here
 * because § 7.1 publishes ten and this array is § 7.1's, not a filtered copy of it — filtering to
 * "the ones we expect to see" is how a published member set silently becomes nine.
 */
export const RENDER_STATES = Object.freeze([
    'working',
    'idle',
    'blocked',
    'stalled',
    'unknown',
    'catching_up',
    'stale',
    'offline',
    'disabled',
    'retired',
]);

/**
 * § 5.4's membership test — "'The client does not know' is a membership test against a set this
 * document publishes". A value that fails it is never mapped to the nearest known member and
 * never defaulted to a healthy-looking one (AT-D3-11's two REDs); the caller renders it as
 * explicitly unrecognised, carrying the raw string.
 */
export function isRenderState(value) {
    return RENDER_STATES.includes(value);
}

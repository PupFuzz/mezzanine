/**
 * The member set of `docs/design/FLEET-STATE.md § 8.3.3`'s `coord_round` — the one coordination object
 * this client reads — in D2's own table order.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE IS A RESTATEMENT AND IT IS GUARDED, NOT TRUSTED. D2 § 8.3.3 is a markdown table; a
 * browser cannot read one, so the copy cannot be deleted. The guard is
 * `tests/Feature/Coordination/CoordMemberSetMatchesTheDocumentTest.php`, which RE-DERIVES the set — its
 * members, its order and its size — from `docs/design/FLEET-STATE.md` on every run and compares it
 * position by position, in both directions.
 *
 * ⛔ THERE IS NO `coord_thread` SET. Since card#12239 `coord.thread` is specified and not published
 * (D2 § 8.3), so a member set for it here would be a client's copy of an object no wire carries.
 *
 * ⛔ THERE IS NO MEMBER SET FOR `attribution`, `carrier` OR `scope`'s values HERE. D2 declines to type
 * them as enums — "minted and consumed inside one deployment" — so a vocabulary here would be a second
 * home for words D2 owns, and its first effect would be to map an unknown value to the nearest known one.
 */

/** `coord_round`'s members, in D2 § 8.3.3's table order. */
export const COORD_ROUND_MEMBERS = Object.freeze([
    'post_ref',
    'thread_ref',
    'scope',
    'install_id',
    'from',
    'attribution',
    'to',
    'targets',
    'carrier',
    'declares_close',
    'posted_at',
    'received_at',
]);

/** The one `t` D2 § 8.3's message table carries for this surface that this client reads. */
export const COORD_ROUND_MESSAGE = 'coord.round';

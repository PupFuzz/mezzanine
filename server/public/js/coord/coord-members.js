/**
 * The two member sets of `docs/design/FLEET-STATE.md § 8.3.3` — `coord_thread` and `coord_round`,
 * in D2's own table order.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE IS A RESTATEMENT AND IT IS GUARDED, NOT TRUSTED. D2 § 8.3.3 is two markdown
 * tables; a browser cannot read one, so the copy cannot be deleted. The rule for a restatement a
 * consumer cannot follow a pointer to is therefore GUARD it, and the guard is
 * `tests/Feature/Coordination/CoordMemberSetMatchesTheDocumentTest.php`, which RE-DERIVES both
 * sets from `docs/design/FLEET-STATE.md` on every run and compares them position by position, in
 * both directions. A hand-written expected list in that test would be a THIRD copy, which is how
 * two copies end up agreeing while the document says something else.
 *
 * ⛔ THE ORDER IS LOAD-BEARING, not decoration. It is the order the render map of
 * `docs/design/FLOOR.md § 5.7` walks, so a set that agrees while two members have swapped places
 * is a set no comparison of SETS can see is wrong.
 *
 * ⛔ THERE IS NO MEMBER SET FOR `lifecycle`, `attribution` OR `carrier` HERE, AND THAT IS A
 * DECISION RATHER THAN AN OMISSION. D2 declines to type them as enums — "minted and consumed
 * inside one deployment, so there is nothing for that machinery to route" — and § 5.7 renders
 * each verbatim for that reason. A vocabulary here would be a second home for three words D2
 * already owns, free to disagree with it, and its first effect would be to let an unknown value
 * be mapped to the nearest one this client happens to know.
 */

/** `coord_thread`'s eleven, in D2 § 8.3.3's first table's order. */
export const COORD_THREAD_MEMBERS = Object.freeze([
    'thread_ref',
    'install_id',
    'lifecycle',
    'carrier',
    'subject',
    'subject_truncated',
    'opened_by',
    'attribution',
    'participants',
    'posted_at',
    'received_at',
]);

/** `coord_round`'s eleven, in D2 § 8.3.3's second table's order. */
export const COORD_ROUND_MEMBERS = Object.freeze([
    'post_ref',
    'thread_ref',
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

/**
 * The two message types D2 § 8.3's message table carries for this surface. They are the `t`
 * values the feed sends and the only two this client reads on the coordination layer.
 */
export const COORD_THREAD_MESSAGE = 'coord.thread';

export const COORD_ROUND_MESSAGE = 'coord.round';

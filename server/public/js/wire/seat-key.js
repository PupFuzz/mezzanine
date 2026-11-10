/**
 * The desk key — `docs/design/FLOOR.md § 3.1`'s desk row: a seat is keyed by its `seat_id` alone, which is
 * unique among live seats site-wide (D2 § 8.2.1). The seat object's `install_id` is the ROOM the seat is
 * drawn in, a mutable member, so it is never part of a key: a seat the console moves keeps its desk key.
 *
 * ⛔ THE ONLY MODULE THAT BUILDS A KEY OR ASKS A KEY FOR ITS ROOM. Every held map, buffer, slot and asset
 * name a page keeps per seat is keyed by `keyOf()`, and a room is read off the held seat by `roomOf()` —
 * never out of the key. `Tests\Feature\Floor\TheKeyPrimitiveIsTheOnlyKeyBuilderTest` walks every module the
 * pages load and reds on a key joined from an `install_id` and a `seat_id`, or split on `/`, anywhere else.
 */

/**
 * A seat's desk key.
 *
 * @param {{seat_id: string}} seat a D2 § 8.2.1 seat object, or anything carrying its `seat_id`
 * @returns {string}
 */
export function keyOf(seat) {
    return seat.seat_id;
}

/**
 * The room the seat under `key` is drawn in: the held seat object's `install_id` — `unassigned` for a seat in no room,
 * as the snapshot groups it (D2 § 8.1).
 *
 * @param {string} key a `keyOf()` key of a seat the page holds
 * @param {Map<string, {install_id: string}>} held the seats the page holds, by key (`FleetClient#seats`)
 * @returns {string}
 */
export function roomOf(key, held) {
    return held.get(key).install_id;
}

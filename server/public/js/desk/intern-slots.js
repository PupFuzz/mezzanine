/**
 * THE INTERNS' SLOTS — `docs/design/FLOOR.md § 6.2`'s *Interns ride the elevator too*, item 13 (card#12450): the place
 * at the side table each intern keeps for as long as it is in `subagents[]`, and what one delta did to them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A SLOT IS KEPT, NEVER RE-DERIVED FROM THE ARRAY'S ORDER. The array is newest first, so laying the interns by
 * index moves every seated one a place along on each gain — harmless for a still sprite, and wrong for a walk: the
 * anchor a walk was routed to would move under it, and the seated would jump. So a seat holds `call_id → slot`, over
 * `0 … STOOL_CAP − 1` (`floor/desk-layout.js`'s `stoolSlots()`, row-major), the way a seat holds its desk slot
 * (§ 3.2's discipline, one level down):
 *   · a `call_id` a delta's array GAINS takes the LOWEST FREE slot, gains of one delta in `started_at` ascending order
 *     (then `call_id` by code unit), so two viewers holding one array in two orders agree;
 *   · a `call_id` it LOSES frees its slot, and a hole stays a hole until a later gain fills it — the row is never
 *     compacted, since compaction is the shift this exists to remove;
 *   · a NON-DELTA replace of the seat's object (a snapshot, a resync, an insert, a reconnect) REBUILDS the map from the
 *     delivered array in `started_at` ascending order, oldest at slot 0 — the map a delta-driven history with no
 *     departures also reaches, so a reload moves nobody on the common seat (§ 6.5: a snapshot is drawn as delivered).
 *
 * Pure functions over plain data; `desk/desk-floor.js` holds the maps across renders.
 */

/** § 8.1's cap: the array D2 caps at 8, which is the slot count (`floor/desk-layout.js`'s `STOOL_CAP`). */
export const SLOT_CAP = 8;

const elements = (seat) => (Array.isArray(seat?.subagents) ? seat.subagents : []).filter((s) => typeof s?.call_id === 'string');

/** `started_at` ascending, then `call_id` by code unit — never `localeCompare`, so every viewer orders alike. */
const oldestFirst = (a, b) => {
    const [x, y] = [a.started_at ?? '', b.started_at ?? ''];

    return x < y ? -1 : x > y ? 1 : a.call_id < b.call_id ? -1 : a.call_id > b.call_id ? 1 : 0;
};

/** The map a non-delta replace delivers: the array in `started_at` ascending order, oldest at slot 0. */
export function rebuildSlots(seat) {
    return new Map([...elements(seat)].sort(oldestFirst).slice(0, SLOT_CAP).map((s, i) => [s.call_id, i]));
}

/**
 * One applied delta's effect on a seat's map, and what it did to the interns: `gained` and `lost` (each element with
 * the slot it takes or frees), and `exact` — item 15's rule, `subagents_open ≤ 8` on both sides, which is when the
 * array is the whole set and a lost `call_id` is a stop and a gained one a spawn.
 *
 * @param {Map<string, number>} prev the seat's map before the delta
 * @param {object} before the seat object before the merge
 * @param {object} after the seat object after it
 * @returns {{slots: Map<string, number>, gained: list<object>, lost: list<object>, exact: boolean}}
 */
export function advanceSlots(prev, before, after) {
    const kept = new Set(elements(after).map((s) => s.call_id));
    const had = new Map(elements(before).map((s) => [s.call_id, s]));
    const slots = new Map([...prev].filter(([id]) => kept.has(id)));
    const lost = [...prev].filter(([id]) => !kept.has(id))
        .map(([id, slot]) => Object.freeze({ ...(had.get(id) ?? { call_id: id }), slot }));
    const taken = new Set(slots.values());
    const gained = [];

    for (const s of elements(after).filter((e) => !prev.has(e.call_id)).sort(oldestFirst)) {
        let slot = 0;

        while (taken.has(slot)) {
            slot++;
        }

        if (slot >= SLOT_CAP) {
            break;
        }

        taken.add(slot);
        slots.set(s.call_id, slot);
        gained.push(Object.freeze({ ...s, slot }));
    }

    // A count the wire did not deliver as an integer says nothing about the reduction, so it is not exact: the stool
    // fills or empties with no walk, which claims no start and no stop.
    const within = (seat) => Number.isInteger(seat?.subagents_open) && seat.subagents_open <= SLOT_CAP;

    return { slots, gained, lost, exact: within(before) && within(after) };
}

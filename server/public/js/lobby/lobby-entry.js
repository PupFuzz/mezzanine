/**
 * The lobby's entry fetches, in order — `docs/design/FLOOR.md § 4.4`'s `/` row: "`GET /api/fleet/snapshot`,
 * then `GET /api/building`" (§ 2.2 steps 3 and 3b). card#9208 build slice 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE LAYOUT COMES FROM THE FETCH AND FROM NOWHERE ELSE. Until Appendix B row 13 the page inlined
 * it; D2 § 13 row 41 refuses one building on two delivery paths, so the page no longer carries it and
 * this is the only way the lobby learns one.
 *
 * ⛔ THE `fetch` IS INJECTED, so the order is driven under `node` (`tests/Feature/Lobby/lobby-probe.mjs`).
 * `main.js` passes the browser's.
 *
 * ⚠ § 2.2 STEP 1 IS NOT HERE: the stream is opened before the snapshot in § 2.2, and the lobby opens
 * no stream — that is Appendix B step 3's protocol, `wire/fleet-client.js`, which is built and which
 * no page constructs before step 8. So a `building.layout` saved between this fetch and the next
 * reaches this page only when it is refreshed or reloaded.
 */

import { request } from '../wire/building.js';

/** D2 § 8.2's one snapshot endpoint. */
export const SNAPSHOT_PATH = '/api/fleet/snapshot';

/**
 * The snapshot alone — also § 4.1's discrepancy fetch, which is a snapshot fetch and not a layout one.
 */
export function fetchSnapshot(fetchImpl) {
    return request(fetchImpl, SNAPSHOT_PATH);
}

/**
 * The snapshot, then — when it answered — the layout into `building`. A refused snapshot is F4's render
 * with nothing to compose, so the layout is not asked for.
 *
 * @returns the snapshot's `{ status, ok, body }`; the layout's outcome is on `building`
 */
export async function enter(fetchImpl, building) {
    const snapshot = await fetchSnapshot(fetchImpl);

    if (snapshot.ok) {
        await building.fetchLayout();
    }

    return snapshot;
}

/**
 * `task` — the ONE place this client turns `docs/design/FLEET-STATE.md § 8.2.1`'s `task` member
 * into a render decision, shared by every surface that draws it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED AT ITS SECOND CALLER (card#7897), not at its Nth — the same move `wire/clock.js`
 * records for itself. `drilldown/drilldown-model.js` wrote this for card#7342's panel; the
 * DESK's thought bubble (`desk/task-bubble.js`, `docs/design/FLOOR.md § 5.1`) renders the same
 * members, and D3 is explicit that the two surfaces are ONE fact at two fidelities rather than
 * two facts: "The drill-down still carries `task` and that is not a second form: it is the same
 * fact at full fidelity on a surface the viewer opened." A second implementation of the merge's
 * null case, its reference rule or its degraded wording would be free to disagree with the
 * first, and the desk is exactly where a disagreement would be least visible.
 *
 * ⛔ WHAT IT DECIDES IS WHAT IS TRUE OF THE MEMBER, NOT WHAT A SURFACE DRAWS. The null case, the
 * reference and the degraded wording are the same on both surfaces and live here. Which of these
 * facts a given element renders, and in what form, is that surface's section of D3 and stays in
 * that surface's module — § 5.2's row for the panel, § 5.1's bubble rules for the desk.
 *
 * ⛔ THE REFERENCE IS PLAIN TEXT AND NEVER A LINK (§ 5.2; operator ruling 2026-09-13, § 14 item 3):
 * "no link base URL is configured, because the board is private. A guessed URL is a link that goes
 * somewhere wrong, which is worse than no link." This module resolved a `card#N` or `<repo>#N` into
 * a URL under a configured base until card#7342 step 10 removed it — nothing configured one, the
 * ruling means nothing will, and `<repo>#N` was tier 2's shape, retired with it on card#9234.
 */

import { clockTime } from './clock.js';

/**
 * `docs/design/FLOOR.md § 4.3`, verbatim, when `task.degraded` — a better tier's value was
 * dropped past its freshness bound and the merge fell through (D2 § 4.9). ⛔ ONE WORDING: D2's
 * obligation T19 ("`task.degraded` says a better one was dropped") is addressed to § 5.1 AND
 * § 5.2, so the desk and the panel say it in the same words or one of them is a second wording
 * of one fact — § 2.4's one-rendered-form-per-fact rule, reaching across two surfaces.
 */
export const STALE_TITLE_DROPPED = 'stale title dropped';

/**
 * The `task` member, decided once: `null` when the seat reports no task at all, and otherwise
 * the members D3 § 5.1's `task` row names, each already put through its own rule.
 *
 * ⛔ `null` IN, `null` OUT, AND THE ABSENCE IS THE CALLER'S TO RENDER. § 5.6: a null `task` is
 * "**no thought bubble at all**" on the desk and *not reported* in the panel's own labelled row —
 * two renders of one absence, which is why this returns the absence rather than a string for it.
 * Never a placeholder title, never the last title the client held.
 *
 * ⚠ `task.ref` IS NULL ON EVERY SEAT A TIER-3 ANSWER SERVES: D2 § 4.9's tier 3 (telemetry) carries
 * `ref = null`, and the one tier that mints a non-null `ref` is tier 1 — the board-poll producer
 * `docs/design/BOARD-TASK.md` designs (card#7582); tier 2 was retired outright (card#9234). That is
 * why `task.source` is carried beside the title: "a floor showing tier 3 everywhere is visibly a
 * floor whose board integration is dark" (D2 § 4.9, D3 § 14 item 4).
 */
export function taskFacts(task) {
    if ((task ?? null) === null) {
        return null;
    }

    const ref = task.ref ?? null;

    return {
        title: task.title ?? null,
        source: task.source ?? null,
        // § 5.6, `task.ref`: "the title renders with NO REFERENCE TEXT — not an empty reference,
        // not *(no reference)*"; a non-null one is plain text (§ 5.2).
        ref,
        as_of: clockTime(task.as_of ?? null),
        degraded_note: task.degraded === true ? STALE_TITLE_DROPPED : null,
    };
}

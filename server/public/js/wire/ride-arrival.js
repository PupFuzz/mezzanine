/**
 * THE RIDE's TWO DOCUMENT-LEVEL HALVES — the module half of the elevator ride's arrival, and the departing
 * page's one `pageswap` rule. `docs/design/FLOOR.md` § 4.5 (the ride), § 12 (*Cab hold*), Appendix B row 23
 * (card#11795).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ARRIVAL IS DECIDED BY THE LAYOUT's HEAD SCRIPT, NOT HERE. A ride ends in a real navigation to
 * `…#ride`; the destination knows it was entered by a ride from that navigation alone — the fragment, under
 * the navigation's `type === 'navigate'` — and the classic inline `<script>` in `layouts/app.blade.php`'s
 * `<head>` reads it, strips it inside the `pagereveal` handler, holds the cab's snapshot over the loading page
 * and arms the one bound. A module is too late for `pagereveal` (it runs after the first rendering
 * opportunity), so the two halves share no function: THE DECLARED SURFACE IS `<html>`'s ATTRIBUTES AND
 * CLASSES — `data-ride-arrival` and `data-ride-arrival-at` (the head writes, this module reads),
 * `data-ride-reveals` (the view declares it through the layout's `@yield('document-attributes')`, the head
 * reads it), and `ride-holding` / `ride-arrived` (the head adds the first, either side replaces it with the
 * second; the replacement is a no-op the second time).
 *
 * ⛔ THE HOLD ENDS ON A POSITIVE FACT OR ON THE CLOCK, NEVER ON A FAILURE. `revealed()` is called by a page on
 * its FIRST DRAWN SCENE and on nothing else — no statement, no failure, no `layout` value, no missing status
 * — and the head script's own timer ends it at `t0 + CAB_HOLD_MS` otherwise. A page with no data yet looks
 * like a page in some failure state, which is why no failure predicate may end the hold.
 *
 * ⛔ `CAB_HOLD_MS` IS WRITTEN TWICE: here, and as the head script's literal, which cannot import. The layout
 * test (`TheRideArrivalIsTheLayoutsTest`) holds the two equal.
 *
 * ⛔ `skipUnlessCabLeaving()` TOUCHES NO DOCUMENT. It is the departing page's `pageswap` rule: the browser
 * snapshots the departing page as it is, so a transition is let through only while the ride is `leaving`
 * with its cab dialog still open — anything else in the snapshot (A's bare floor after a cab the browser
 * closed, or a page a late commit finds torn down) would be held over the destination, frozen and
 * pointer-dead. The cab's state is a fact of the frame (`ride.cab`), so the rule reads the frame and never
 * the dialog, and nothing on the unload path changes the DOM (which would change the snapshot).
 */

/**
 * § 12's *Cab hold*: the longest a viewer is left looking at a static cab, on either side of the navigation —
 * the departure's `leaving` bound, and the arrival's hold and arrival-flag bound, both from the head script's
 * own `t0`. A bound, not a motion figure: the same under `prefers-reduced-motion`.
 */
export const CAB_HOLD_MS = 2000;

/**
 * Whether this document was entered by a ride, as the head script recorded it, and how long the arrival flag
 * has left: `{ expires_in_ms }` measured from the head script's `t0` on the page's own clock, or `null`.
 *
 * @param {{dataset: object}} html the document element
 * @param {number} now `performance.now()` — the clock the head script read `t0` on
 * @returns {{expires_in_ms: number}|null}
 */
export function arrival(html, now) {
    if (html.dataset.rideArrival !== 'ride') {
        return null;
    }

    return { expires_in_ms: Number(html.dataset.rideArrivalAt) + CAB_HOLD_MS - now };
}

/**
 * The hold is over: the cab's snapshot fades into the page. The head script's own bound performs the same
 * replacement; the second is a no-op, and so is one on a page that never held.
 *
 * @param {{classList: DOMTokenList}} html the document element
 */
export function revealed(html) {
    html.classList.replace('ride-holding', 'ride-arrived');
}

/**
 * The departing page's `pageswap` rule: the outbound view transition is kept only while the ride is
 * `leaving` with its cab still open, and skipped otherwise. Answers the transition it kept, or `null`.
 *
 * ⛔ THE SAME RULE IS ASKED AGAIN WHEN THE CAB CLOSES AFTER `pageswap`. The browser can close the dialog
 * between `pageswap` and the snapshot — a second Escape within a frame of the commit — so the page keeps the
 * transition this answered and hands it back here once the screen has recorded the close: the predicate,
 * now false, skips it before the snapshot is taken.
 *
 * @param {{skipTransition: function(): void}|null} transition the event's `viewTransition`, or a kept one
 * @param {{phase: string, cab: string}|null} ride the last painted frame's `ride`
 * @returns {object|null} the transition still let through
 */
export function skipUnlessCabLeaving(transition, ride) {
    if (transition === null || transition === undefined) {
        return null;
    }

    if (ride?.phase === 'leaving' && ride.cab === 'open') {
        return transition;
    }

    transition.skipTransition();

    return null;
}

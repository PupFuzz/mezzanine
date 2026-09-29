/**
 * THE COMMITTED RIDE WINS OVER A PLATE LINK — `docs/design/FLOOR.md § 4.5`, Appendix B row 16 (card#7343:
 * the seat's r3 ruling, made true of the code at r3b).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CLICK COMMITS THE RIDE, AND A PLATE LINK IS NO WAY OUT OF IT. Every plate is a link to its floor,
 * and a ride's arrival is the page's `location.assign` when the glide has arrived — so a plate link clicked
 * during the glide would navigate at once, and on a fast server reach its floor before the ride did. So
 * while a ride is in flight a click on a plate link inside the building has its default action — the
 * navigation — prevented, and the ride arrives where the viewer committed to. Keyboard Enter on a focused
 * link is the same `click`, so the keyboard is held the same way. Only the navigation is held: the click
 * still reaches every other listener.
 *
 * ⛔ CAPTURE PHASE. The listener runs on the building on the way DOWN to the link, before any listener on
 * the link or between the two could stop the event's propagation and so take it out of the hold.
 *
 * ⛔ THE HOLD IS THE SCREEN's. `riding` is `lobby-screen.js`'s: a ride is in flight from its click until the
 * glide has arrived and the page has asked for its route (`returned()`, card#7343 r2-4), so once the ride
 * has arrived a plate link navigates as it always did — and with no ride running it is never touched.
 *
 * Driven under `node` with a stand-in element by `Tests\Feature\Lobby\TheCommittedRideWinsOverAPlateLinkTest`.
 */

/**
 * @param {EventTarget} building the building's drawing, `#lobby-building`, which holds every plate
 * @param {function(): boolean} riding whether a ride is in flight — the lobby screen's
 */
export function holdPlateLinks(building, riding) {
    building.addEventListener('click', (event) => {
        const link = event.target.closest('a');

        if (riding() && link !== null && link.closest('li[data-floor]') !== null) {
            event.preventDefault();
        }
    }, { capture: true });
}

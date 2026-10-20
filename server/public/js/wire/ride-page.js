/**
 * THE RIDE's PAGE HALF — the phase performer both pages wire with their own cab dialog (`docs/design/FLOOR.md` § 4.5's
 * *One ride, one cab, one module*; card#11795 built it in `floor/main.js`, card#12113 hoisted it here at its second
 * caller, the lobby). The dialog is `resources/views/partials/ride-cab.blade.php`, included once per page under that
 * page's id prefix; `cabIds()` is the one statement here of the ids it declares.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every ride decision is the page's screen's (`wire/ride.js`, which both screens hold):
 * its press, each phase's timer, a stop's press and the cab's Escape are each one synchronous screen method, after
 * which this file has the page paint `screen.rideView()` (`act()`) — never a render, so no step of the ride waits on
 * the render's awaits. ONE PAGE RULE performs the phases: `perform()` compares the frame's ride phase with the last one
 * performed and, on a change, performs that phase's entry once — the cab dialog, its fades, the cab's drawing
 * (`floor/cab-paint.js`'s `paintCab()`, handed in), the ride's one timer (armed at one site, the previous cancelled), the
 * navigation to the screen's route — or, on a change to no ride, the teardown. A phase of no length
 * (`prefers-reduced-motion`) is performed at once.
 *
 * ⛔ THE DEPARTING PAGE's ONE `pageswap` LISTENER IS `skipUnlessCabLeaving()` (`wire/ride-arrival.js`), fed the page's
 * last painted frame's ride (`lastRide()`, the page's own): it reads the frame and touches no DOM, so the browser
 * snapshots the cab as it stands; nothing listens on `pagehide`. The cab's `cancel` asks the screen (`escape()`) and
 * asks the rule again of a transition it already let through; a page the back-forward cache restores ends its ride
 * (`returned()`).
 *
 * ⛔ THE ARRIVAL IS NOT HERE: the layout head script records it, the page hands it to its screen before its first render,
 * and the page ends the hold on its first drawn room (`wire/ride-arrival.js` says how the halves meet).
 */

import { cabPanel } from '../floor/cab-panel.js';
import { skipUnlessCabLeaving } from './ride-arrival.js';

/**
 * The cab dialog's element ids under a page's prefix — what `partials/ride-cab.blade.php` declares with the same prefix
 * (its heading, `<prefix>-cab-heading`, is the dialog's and the stop list's label and is written by nothing).
 *
 * @param {string} prefix the page's id prefix: `floor`, `lobby`
 */
export function cabIds(prefix) {
    return Object.freeze({
        cab: `${prefix}-cab`,
        doors: `${prefix}-cab-doors`,
        panel: `${prefix}-cab-panel`,
        stops: `${prefix}-cab-stops`,
        status: `${prefix}-cab-status`,
        feed: `${prefix}-cab-feed`,
        layout: `${prefix}-cab-layout`,
    });
}

/**
 * Wire one page's cab dialog to its screen's ride.
 *
 * @param {{prefix: string, screen: () => object, paint: function(object): void, paintCab: function(Element, object|null): void,
 *          elevator: function(): (HTMLElement|null), lastRide: function(): (object|null),
 *          timers: {after: function(number, function(): void): number, cancel: function(number): void}}} page
 *        `screen` the page's screen, read when a ride act runs (the page constructs it after this); `paint` the page's
 *        paint of a frame; `paintCab` the cab's drawing into the doors' element; `elevator` the drawn elevator, for focus;
 *        `lastRide` the ride of the frame the page last painted, which the `pageswap` rule reads
 * @returns {{act: function(object|null): (object|null), dialog: HTMLDialogElement, perform: function({ride: object|null, cab: object|null,
 *          feed: string, layout: string|null}): void}}
 */
export function ridePage({ prefix, screen, paint, paintCab, elevator, lastRide, timers }) {
    const ids = cabIds(prefix);
    const el = (id) => {
        const node = document.getElementById(id);

        if (node === null) {
            throw new Error(`the page has no #${id}`);
        }

        return node;
    };
    const say = (node, text) => {
        node.textContent = text ?? '';
        node.hidden = text === null || text === undefined || text === '';
    };
    const cab = el(ids.cab);
    const doors = el(ids.doors);
    const panel = el(ids.panel);
    const lines = { status: el(ids.status), feed: el(ids.feed), layout: el(ids.layout) };

    /** The ride phase this page last performed (`null`: no ride), and the ride's one timer. */
    let performed = null;
    let rideTimer = null;

    /** The outbound view transition the `pageswap` rule let through, kept so a later cab close can skip it. */
    let outbound = null;

    /** A ride method's answer: when it answered, the ride as it now stands is painted — no render, no await. */
    function act(answer) {
        if (answer !== null) {
            paint(screen().rideView());
        }

        return answer;
    }

    const stops = cabPanel(el(ids.stops), (key) => act(screen().choose(key)));

    /**
     * A fade of one element to `to` over the phase's fade length — a cut under reduced motion. The element is left
     * at `to` either way; the promise settles when the fade has run.
     */
    function fade(node, from, to, ms) {
        node.style.opacity = String(to);

        return ms > 0 ? node.animate([{ opacity: from }, { opacity: to }], { duration: ms }).finished : Promise.resolve();
    }

    /** The ride's one timer: the phase's step after `ms`, or at once for a phase of no length. */
    function after(ms, step) {
        if (ms === 0) {
            act(step());

            return;
        }

        rideTimer = timers.after(ms, () => {
            rideTimer = null;
            act(step());
        });
    }

    /** Each phase's ENTRY, performed once when the frame's ride enters it (FLOOR.md § 4.5's ride). */
    const ENTRY = {
        // The page's leaves open (its drawing draws them from the scene's ride).
        opening: (ride) => after(ride.ms, () => screen().entered()),
        // Into the cab: the dialog fades in over one frame, then its doors close; the buttons are hidden and inert
        // until they have.
        entering: (ride, view) => {
            panel.inert = true;
            panel.style.opacity = '0';
            cab.showModal();
            fade(cab, 0, 1, ride.fade_ms);
            paintCab(doors, view.cab);
            after(ride.ms, () => screen().shut());
        },
        // The buttons fade in and answer; focus on the current stop's.
        inside: (ride) => {
            panel.inert = false;
            stops.arm();
            fade(panel, 0, 1, ride.fade_ms);
            stops.focusCurrent();
        },
        // The buttons fade out, then the doors open.
        chosen: (ride, view) => {
            panel.inert = true;
            fade(panel, 1, 0, ride.fade_ms);
            paintCab(doors, view.cab);
            after(ride.ms, () => screen().opened());
        },
        // The ride's navigation. The cab stays open — it is what the browser snapshots — bounded by *Cab hold*.
        leaving: (ride, view) => {
            paintCab(doors, view.cab);
            window.location.assign(ride.route);
            after(ride.ms, () => screen().abandoned());
        },
        // A stay (Q1): the cab fades out and closes, focus back on the elevator after the close, the leaves close.
        staying: (ride) => {
            panel.inert = true;
            fade(cab, 1, 0, ride.fade_ms).then(() => {
                if (cab.open) {
                    cab.close();
                }

                elevator()?.focus({ preventScroll: true });
            });
            after(ride.ms, () => screen().arrived());
        },
        // The destination's doors close after the cab's snapshot has faded into this page (FLOOR.md § 4.5).
        arriving: (ride) => {
            elevator()?.focus({ preventScroll: true });
            after(ride.ms, () => screen().arrived());
        },
    };

    /**
     * THE ONE PAGE RULE: a change of the frame's ride phase performs that phase's entry once, the previous timer
     * cancelled; a change to no ride is the teardown — the timer cancelled, the dialog closed, the cab emptied and
     * focus on the elevator. A cut: teardown animates nothing. `view` is the frame as the cab reads it: its `ride`,
     * the cab's drawing's (`cab`, the scene's ride), and the cab's own two lines — the feed's (§ 9 F1, *the feed dies
     * while idling inside*) and the layout's label.
     */
    function perform(view) {
        const ride = view.ride;

        if (ride !== null) {
            stops.reconcile(ride.stops);
            say(lines.status, ride.status);
        }

        say(lines.feed, view.feed);
        say(lines.layout, view.layout);

        const phase = ride?.phase ?? null;

        if (phase === performed) {
            return;
        }

        performed = phase;

        if (rideTimer !== null) {
            timers.cancel(rideTimer);
            rideTimer = null;
        }

        if (phase !== null) {
            ENTRY[phase](ride, view);

            return;
        }

        panel.inert = true;

        if (cab.open) {
            cab.close();
        }

        paintCab(doors, view.cab);
        elevator()?.focus({ preventScroll: true });
    }

    // Escape in the cab: never the browser's own close while it can be held — the screen answers it (the stay
    // `inside`; nothing in the other phases). A second Escape with no activation between cannot be held: the
    // browser closes the cab whatever this does, so the screen records it (`cabClosed()`), and an outbound
    // transition the `pageswap` rule already let through is asked again — the cab it would carry is gone.
    cab.addEventListener('cancel', (event) => {
        event.preventDefault();
        act(screen().escape(event.cancelable));
        outbound = skipUnlessCabLeaving(outbound, lastRide());
    });

    // The departing page's one rule on its unload path (§ 4.5): the outbound transition only while the ride is
    // leaving with its cab open — it touches no DOM, so the snapshot is the cab as it stands.
    window.addEventListener('pageswap', (event) => {
        outbound = skipUnlessCabLeaving(event.viewTransition, lastRide());
    });

    // Back to a page the browser kept whole (the back-forward cache): whatever ride it left in is over.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            act(screen().returned());
        }
    });

    return { act, perform, dialog: cab };
}

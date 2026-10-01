/**
 * A PAGE's LIVE CLIENT — the browser half every screen that constructs the client protocol shares:
 * the protocol constructed WITH its stream recovery, over the browser's own `fetch`, `EventSource`
 * and timers, and one coalesced render after everything that can change what it holds.
 * `docs/design/FLOOR.md` Appendix B rows 8 and 9.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7341 step 9). The floor page (`floor/main.js`, row 8)
 * wrote this inline; the lobby (`lobby/main.js`, row 9) constructs the same protocol the same way,
 * and two copies of *how a page owns the reconnect* is two definitions of it — the first thing they
 * would do is disagree about which events ask for a render.
 *
 * ⛔ THE CLIENT PROTOCOL IS CONSTRUCTED WITH ITS SCHEDULER, AND ONLY WITH IT (Appendix B row 8's ⛔).
 * A real `EventSource` held without the stream recovery inherits the browser's own reconnect, which
 * re-runs none of § 2.2's steps 1–5; `wire/fleet-client.js` recovers nothing without the fourth
 * argument, so this module passes it and both pages' wiring tests red if it stops.
 *
 * ⛔ A RENDER FOLLOWS EVERY THING THAT CAN CHANGE WHAT THE PROTOCOL HOLDS — a message, a stream's
 * `open`/`error`, a response, a recovery timer — coalesced into one render at a time. The protocol
 * exposes a drained journal rather than a callback (`FleetClient#takeWire`), so the page is what
 * knows an apply happened; each hook below only asks for a render, and the render drains.
 *
 * ⛔ THE PAGE's ANIMATION LOG IS CONSTRUCTED HERE, WITH § 12's RETENTION (§ 14 item 26) — the one
 * instrument every § 6.2 row a page draws is started through, bounded on a page because an unbounded
 * log is a leak whose rate the heartbeat sets. The harness and every acceptance test construct their
 * own, unbounded.
 *
 * ⛔ THIS FILE DECIDES NOTHING ABOUT WHAT IS DRAWN: it is the browser's globals and nothing else.
 * Every decision is the screen's the page hands `render` to. Its fetch is the one part a probe drives
 * (`tests/Feature/Floor/page-fetch-probe.mjs`, over shimmed globals and a real `Response`), because
 * what that response carries is what every consumer of it reads.
 */

import { FleetClient } from './fleet-client.js';
import { createAnimationLog } from './animation-log.js';

/**
 * § 12's *A page's animation-log retention* — every page's bound, and the harness's never (§ 14 item 26).
 * Hoisted here from the floor page when the lobby became the log's second page (card#7343: its sky is
 * § 6.2 A17's, so the lobby writes an A17 row on every `feed.heartbeat`), so the two pages hold one
 * figure and neither can be constructed with the log unbounded.
 */
export const ANIMATION_LOG_RETENTION = 2000;

/**
 * @param {function(): Promise<void>} render the page's one render — called at most once at a time,
 *        and never dropped: a request made while one runs is honoured when it ends
 * @returns {{client: FleetClient, clock: {now: function(): number}, fetch: Function, requestRender: function(): void,
 *            log: object}} `log` the page's animation log (`wire/animation-log.js`), bounded by § 12
 */
export function livePage(render) {
    const clock = { now: () => Date.now() };

    let rendering = false;
    let dirty = false;

    /** One coalesced render — never two at once, never one dropped. */
    function requestRender() {
        if (rendering) {
            dirty = true;

            return;
        }

        rendering = true;
        window.setTimeout(async () => {
            try {
                await render();
            } finally {
                rendering = false;

                if (dirty) {
                    dirty = false;
                    requestRender();
                }
            }
        }, 0);
    }

    /** The browser's `EventSource`, with a render asked for after each of the protocol's own events. */
    class PageEventSource extends EventSource {
        constructor(url) {
            super(url);

            // Registered BEFORE the protocol's own listeners, and the render they ask for runs on a
            // later task — so it always sees what the protocol did with the event.
            for (const type of ['mezzanine', 'open', 'error']) {
                this.addEventListener(type, requestRender);
            }
        }
    }

    /**
     * The browser's `fetch`, with a render asked for once each response body has been read.
     *
     * ⛔ THE RESPONSE IT HANDS BACK HAS EXACTLY THE MEMBERS THE PROBES' FAKE HAS — `status`, `ok`,
     * and a body read as `json()` (every API read, through `wire/building.js`'s `request()`) or as
     * `text()` (a tileset, `floor/tileset.js`'s loader), each asking for a render once it settles.
     * The client probes drive its consumers through `tests/Feature/Support/scripted-fetch.mjs`, so a
     * member that fake has and this one lacks is green there and a TypeError here — which is how both
     * tilesets failed on the page while every probe passed (card#7341).
     * `Tests\Feature\Floor\TheHarnessFetchIsNoWiderThanThePagesTest` reds when the two member sets
     * differ, reading both off the objects themselves.
     */
    function pageFetch(path, init) {
        return fetch(path, init).then(
            (response) => ({
                status: response.status,
                ok: response.ok,
                json: () => response.json().finally(requestRender),
                text: () => response.text().finally(requestRender),
            }),
            (error) => {
                requestRender();

                throw error;
            },
        );
    }

    /** § 2.2's scheduler: every recovery timer is followed by a render of what it changed. */
    const timers = {
        after: (ms, fire) => window.setTimeout(() => {
            fire();
            requestRender();
        }, ms),
        cancel: (handle) => window.clearTimeout(handle),
    };

    const client = new FleetClient(pageFetch, PageEventSource, clock, timers);

    return { client, clock, fetch: pageFetch, requestRender, log: createAnimationLog(ANIMATION_LOG_RETENTION) };
}

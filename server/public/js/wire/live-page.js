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
 * ⛔ THIS FILE DECIDES NOTHING ABOUT WHAT IS DRAWN, and nothing in it is exercised headlessly: it is
 * the browser's globals and nothing else. Every decision is the screen's the page hands `render` to.
 */

import { FleetClient } from './fleet-client.js';

/**
 * @param {function(): Promise<void>} render the page's one render — called at most once at a time,
 *        and never dropped: a request made while one runs is honoured when it ends
 * @returns {{client: FleetClient, clock: {now: function(): number}, fetch: Function, requestRender: function(): void}}
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

    /** The browser's `fetch`, with a render asked for once each response body has been read. */
    function pageFetch(path, init) {
        return fetch(path, init).then(
            (response) => ({
                status: response.status,
                ok: response.ok,
                json: () => response.json().finally(requestRender),
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

    return { client, clock, fetch: pageFetch, requestRender };
}

/**
 * THE COMPOSE BLOCK, HELD — `docs/design/BOARD-TASK.md § 15.10`, card#9417: the one post a press
 * issues, the in-flight state, and the receipt the answer left. Every sentence and every decision is
 * `compose-model.js`'s; this file holds state and sends one request.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE PRESS, ONE REQUEST, AND NO RETRY. A retried comment is a duplicate comment (§ 15.7), so a
 * press while a post is in flight does nothing, and a failed post is resent only by the operator
 * pressing again — the text stays in the box on every row but `posted` (§ 15.10).
 *
 * ⛔ THE POST CARRIES THE LABEL THE OPERATOR WAS SHOWN (`expected_ref`), not a target. The server
 * re-resolves and refuses `target_moved` when the two differ (§ 15.6), so a delta that moved the desk
 * between render and press cannot land a message on a card nobody saw.
 *
 * ⛔ REDIRECTS ARE NOT FOLLOWED. A signed-out post is answered with the login redirect; followed, that
 * is a `200` HTML page the client would have to guess about. Unfollowed it is status `0`, which
 * § 15.11's `client_401` row names.
 *
 * The request is the page's `fetch` (`wire/live-page.js`'s, `{status, ok, json()}`), handed in, so the
 * probe drives this file over scripted answers exactly as the page drives it over real ones.
 */

import { composeTarget, fill, receipt } from './compose-model.js';

export class ComposeBox {
    #fetch;

    #csrf;

    #unconfigured;

    /** `"install/seat"` the receipt below belongs to — a receipt never follows the operator to another desk. */
    #for = null;

    /** The last answer's receipt row, or `null`. */
    #receipt = null;

    #inFlight = false;

    /** Set on `posted`, taken once by the render that clears the text box. */
    #clear = false;

    /**
     * @param {(path: string, init: object) => Promise<{status: number, ok: boolean, json: () => Promise<any>}>} fetch
     * @param {{csrf: string, unconfigured: {class: string, key: string|null}|null}} page what the server rendered
     */
    constructor(fetch, page) {
        this.#fetch = fetch;
        this.#csrf = page.csrf;
        this.#unconfigured = page.unconfigured ?? null;
    }

    /**
     * The block's view for the open seat: the target line, the button, the receipt line, and whether
     * this render clears the text box.
     */
    view(seat) {
        const target = composeTarget(seat, this.#unconfigured);
        const here = seat === null ? null : `${seat.install_id}/${seat.seat_id}`;
        const mine = this.#for !== null && this.#for === here;
        const clear = mine && this.#clear;

        if (clear) {
            this.#clear = false;
        }

        return {
            target: target.line,
            button: target.button,
            enabled: target.enabled && !this.#inFlight,
            receipt: mine && this.#inFlight ? fill('in_flight') : (mine ? this.#receipt?.sentence ?? null : null),
            receipt_key: mine && this.#inFlight ? 'in_flight' : (mine ? this.#receipt?.key ?? null : null),
            clear_text: clear,
        };
    }

    /**
     * Post `text` to the open seat's target. Resolves once the answer is held; a press the block does
     * not allow (in flight, or a target the client already knows is a refusal) sends nothing.
     */
    async post(seat, text) {
        const target = composeTarget(seat, this.#unconfigured);

        // `enabled` is a card target only (`composeTarget()`), so `label` is the `card#N` shown.
        if (this.#inFlight || !target.enabled) {
            return;
        }

        const shown = target.label;

        this.#for = `${seat.install_id}/${seat.seat_id}`;
        this.#receipt = null;
        this.#clear = false;
        this.#inFlight = true;

        let answer = null;

        try {
            const res = await this.#fetch(
                `/desk/${encodeURIComponent(seat.install_id)}/${encodeURIComponent(seat.seat_id)}/compose`,
                {
                    method: 'POST',
                    redirect: 'manual',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.#csrf,
                    },
                    body: JSON.stringify({ text, expected_ref: shown }),
                },
            );

            let body = null;

            try {
                body = await res.json();
            } catch {
                body = null;
            }

            answer = { status: res.status, body };
        } catch {
            answer = null;
        }

        this.#inFlight = false;
        this.#receipt = receipt(answer, shown);
        this.#clear = this.#receipt.posted;
    }
}

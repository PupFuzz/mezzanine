/**
 * THE FAILURE RENDERS — `docs/design/FLOOR.md § 9`'s F4, F5, F6/F7 and F8, as words and flags a
 * drawing layer puts on screen. Appendix B row 8, card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE HOME FOR THE WORDS, AND THE LOBBY READS THEM FROM HERE. Until step 8 the lobby's DOM file
 * (`lobby/main.js`) carried F4's *last known good*, its cold-start sentence and the read surface's
 * other refusals inline — decisions in a file whose header says it decides nothing, and a second
 * screen needing them would have been a second copy free to disagree. `storeUnavailableStatement`
 * moved here from `lobby/lobby-model.js`, which re-exports it so every lobby import is unchanged.
 *
 * ⛔ PURE. Every function reads a value it is handed — the client protocol's `feed` for
 * `failureRender()` — and nothing here reads a clock, a document or a request.
 *
 * ⛔ A FAILURE IS NEVER AN EMPTY OFFICE (§ 9 F4's Never, AT-D3-8). On a warm client the statement
 * stands over the floor it kept, labelled *last known good*; on a cold one there is no floor to keep
 * and the words say so. No branch here answers "nothing to show" with nothing.
 */

import { clockTime } from './clock.js';
import { NOT_REPORTED } from './null-render.js';

/** § 9 F4/F5: what the floor a client already drew is labelled while the store cannot be read. */
export const LAST_KNOWN_GOOD = 'last known good';

/** § 9 F4: "On a cold start there is no floor to keep, and the screen says so in words". */
export const NOTHING_TO_KEEP = 'nothing has been rendered yet — there is no earlier floor to keep';

/** A snapshot read that never reached a status — the browser could not reach the server. */
export const COULD_NOT_REQUEST = 'fleet state could not be requested — the browser could not reach the server';

/** § 9 F8's full-width banner, verbatim. */
export const RELOAD_BANNER = 'a new version was deployed — reload to continue';

/**
 * § 9 F6's blocking prompt. ⚠ The document publishes the prompt's existence and not its sentence;
 * this is written from what F6 states (the session is gone, sign in again) and nothing else.
 */
export const SIGN_IN_PROMPT = 'your session has ended — sign in to continue';

/**
 * § 9 F4's observable, verbatim: "a full-width statement: **fleet state is unavailable — the
 * store could not be read at 14:23:14**".
 *
 * The instant is the refusal's OWN `server_time` — `App\Read\ReadRefusal::response()` puts one on
 * every refusal body, so this is a server-clock fact and not the client guessing when the store
 * broke. `not reported` if it is missing, never the client's own clock silently substituted.
 */
export function storeUnavailableStatement(serverTime) {
    return `fleet state is unavailable — the store could not be read at ${clockTime(serverTime) ?? NOT_REPORTED}`;
}

/**
 * The statement for one failed snapshot read. A `503` is F4's store statement; every other non-2xx is
 * reported with the code D2 § 8.6 puts in the body rather than folded into F4's sentence, which would
 * name a cause that is not true; a read that reached no status says that.
 *
 * @param {number|null} status
 * @param {object|null} body  the refusal's body, for its `server_time` and `error`
 */
export function snapshotRefusalStatement(status, body) {
    if (status === null) {
        return COULD_NOT_REQUEST;
    }

    if (status === 503) {
        return storeUnavailableStatement(body?.server_time);
    }

    return `fleet state is unavailable — the read surface answered HTTP ${status}`
        + (typeof body?.error === 'string' ? ` (${body.error})` : '');
}

/** The label under a refusal statement: the floor kept, or the words for having none. */
export function keptLabel(holding) {
    return holding ? LAST_KNOWN_GOOD : NOTHING_TO_KEEP;
}

/** § 9 F6: "the floor beneath is dimmed and labelled *not live since HH:MM:SS*". */
export function notLiveSince(wireTime) {
    return `not live since ${clockTime(wireTime) ?? NOT_REPORTED}`;
}

/**
 * Every § 9 failure render the client protocol's state calls for, at once — they can stand together
 * (a store outage can end in a sign-out), and each is its own element.
 *
 * @param {object} feed  `FleetClient#feed`
 */
export function failureRender(feed) {
    let statement = null;

    if (feed.store !== null) {
        statement = storeUnavailableStatement(feed.store.server_time);
    } else if (feed.refusal !== null) {
        statement = snapshotRefusalStatement(feed.refusal.status, { error: feed.refusal.error });
    }

    return Object.freeze({
        statement,
        kept: statement === null ? null : keptLabel(feed.applied),
        sign_in: feed.signed_out === null ? null : Object.freeze({
            prompt: SIGN_IN_PROMPT,
            dimmed: true,
            label: notLiveSince(feed.signed_out.since),
        }),
        banner: feed.reload_required ? RELOAD_BANNER : null,
    });
}

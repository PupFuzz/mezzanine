/**
 * THE STATUS STRIP — `docs/design/FLOOR.md § 5.5`'s narration rows the floor carries, and § 4.2's
 * "persistent status strip carrying the same fleet indicators the lobby shows". Appendix B row 8,
 * card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY LINE HERE IS THE CLIENT TALKING ABOUT ITSELF (§ 2.1 row 7, § 5.5), and none of them may
 * become a fact about a seat: nothing here reaches a desk's pose, currency label, badge or
 * animation. The one effect the connection has on a desk is F1's — none — and the ages keep ticking.
 *
 * ⛔ PURE, OVER THE PROTOCOL'S OWN STATE. `FleetClient#feed` is the whole input: the mode its
 * recovery is in, whether the stream has gone silent for § 9 F1's 45 s, and the counters it keeps.
 * The strip decides the WORDS and nothing about when to retry — that is the protocol's.
 *
 * ⛔ THE FLEET INDICATORS ARE THE LOBBY'S, NOT A SECOND SET. § 4.2 says "the same fleet indicators
 * the lobby shows", so this reads `lobby/lobby-model.js`'s `indicators()` over the `fleet{}` the
 * protocol holds, and the sweep and ingest instants come out exactly as the lobby draws them —
 * labelled timestamps, which is what § 14 item 17 leaves both screens with until a wording is chosen.
 */

import { clockTime } from '../wire/clock.js';
import { notLiveSince } from '../wire/failure-render.js';
import { NOT_REPORTED } from '../wire/null-render.js';
import { indicators } from '../lobby/lobby-model.js';

/** § 5.5's feed status, and § 9's own words for each failure row it names. */
export const LIVE = 'live';
export const CONNECTING = 'connecting';
export const RECONNECTING = 'reconnecting';
export const RELOAD_REQUIRED = 'reload required';

/** § 9 F1, verbatim. */
export const FEED_DOWN = 'feed down — polling';

/** § 9 F19, verbatim: the strip's words are specific, and addressed to the operator. */
export const NEVER_SPOKE = 'feed down — polling; the stream opened and never spoke (check the proxy: D2 § 8.3 R1)';

/** § 9 F20, verbatim: "This is the console failing, not the feed". */
export const FEED_UNAVAILABLE = 'feed unavailable — polling';

/**
 * The feed status, in § 9's words.
 *
 * ⛔ *LIVE* IS THE CONSERVATIVE CLAIM (decision 18): a message newer than 45 s, and no `401` since —
 * a `401` ends the session, so `signed_out` is the second half. Nothing below reads *live* out of
 * the absence of a failure.
 *
 * ⚠ BEFORE THE FIRST MESSAGE OF ANY KIND the strip reads *connecting*, which § 5.5's list does not
 * name: no message is not a live feed, and none of the four words it lists is true of a stream that
 * has not yet spoken. D2 § 8.3's on-connect `fleet.health` is the handler's first byte, so on a
 * healthy feed this lasts one round trip.
 */
export function feedStatus(feed) {
    if (feed.reload_required) {
        return RELOAD_REQUIRED;
    }

    if (feed.signed_out !== null) {
        return notLiveSince(feed.signed_out.since);
    }

    switch (feed.mode) {
        case 'down':
            if (feed.down_cause === 'never-spoke') {
                return NEVER_SPOKE;
            }

            // F20 needs BOTH halves: the stream errors before `open` AND the polls fail. A refused
            // stream over polls that answer is F1's dead feed with a working REST plane.
            return feed.down_cause === 'refused' && feed.refusal !== null ? FEED_UNAVAILABLE : FEED_DOWN;
        case 'grace':
            // § 2.2 clause (2): the grace does not hold the *live* claim — past 45 s it reads
            // *reconnecting*, which is true, and not *feed down — polling*, which is not: no poll is
            // made, and the server said why the stream ended.
            return feed.silent ? RECONNECTING : LIVE;
        case 'reconnecting':
        case 'backoff':
            return RECONNECTING;
        default:
            if (feed.silent) {
                return FEED_DOWN;
            }

            return feed.last_message === null ? CONNECTING : LIVE;
    }
}

/**
 * The strip, as one frozen object.
 *
 * @param {object} feed   `FleetClient#feed`
 * @param {object|null} fleet  `FleetClient#fleet`
 * @param {{reduce?: boolean}} [options]  § 6.4's condition: under `reduce`, § 6.2 A14's pulse is
 *        replaced by its reduced form, *last message HH:MM:SS*, which is carried here — the pulse
 *        itself is the animation set's
 */
export function statusStrip(feed, fleet, options = {}) {
    const status = feedStatus(feed);

    return Object.freeze({
        feed: status,
        live: status === LIVE,
        // § 9 F5: "the stream's own indicator no longer reads **connected**, because the stream has
        // ended". The indicator is the stream's; the feed status above is the verdict.
        connection: feed.connected ? 'connected' : 'not connected',
        // § 5.5: a count of THIS CLIENT's own requests, labelled as one — never D2's
        // `feed_gap_detected`, and never a count of applied deltas.
        resyncs: `resyncs: ${feed.resyncs}`,
        last_message: options.reduce === true
            ? `last message ${clockTime(feed.last_message?.server_time ?? null) ?? NOT_REPORTED}`
            : null,
        indicators: Object.freeze(indicators(fleet).map((row) => Object.freeze(row))),
    });
}

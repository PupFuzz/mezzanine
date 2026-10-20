/**
 * The words § 7.1's Label cells and § 7.6's `api_error_type` table fix — `docs/design/FLOOR.md` — and the
 * two lines composed from them that more than one surface speaks: the `blocked` sentence and § 7.6's
 * composed API-error line.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED OUT OF `desk/desk-render.js` AT ITS SECOND CALLER (card#12113). The lobby's directory
 * plaque names a seat that needs a look in the desk's own words (§ 4.1's attention line: "*waiting on a
 * human since 14:31 (seat clock)*", and § 7.6's composed line "by reference"), and the lobby may not
 * import the desk render: that module reaches `wire/animation-set.js`, and the lobby page's import graph
 * admits the set by one path alone (`Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`).
 * So the words moved here, a module that imports no animation, and `desk-render.js` imports and
 * re-exports them — one copy, read by both surfaces.
 *
 * ⛔ THE STRINGS RESTATE PUBLISHED TABLES AND ARE GUARDED, NOT TRUSTED — by
 * `Tests\Feature\Desk\TheDeskSpeaksTheDocumentsWordsTest`, which re-derives each from the document on
 * every run, reading them through `desk-render.js`'s re-exports.
 */

import { seatClock } from '../wire/age-readout.js';

/** § 5.4 / AT-D3-11: an unrecognised member renders as unrecognised, carrying the raw string. */
export const UNRECOGNISED = 'unrecognised';

/** The separator § 7.1's cells put between a state's sentence and the value that completes it. */
export const DASH = ' — ';

/**
 * § 7.1's Label line, per member — the FIXED words of each cell. A cell that completes its
 * sentence with a value (an age, a timestamp, an error) has only its stem here; `desk-render.js`'s
 * `labelLine()` splices the value in by the rule the cell is a worked instance of. `retired` has no
 * entry: its desk is removed, and it renders no label line on the floor (§ 7.1, § 3.5).
 */
export const LABEL = Object.freeze({
    working: 'working',
    idle: 'finished',
    blocked: 'waiting on a human since',
    stalled: 'API error',
    catching_up: 'replaying history',
    stale: 'no data since',
    offline: 'no data since',
    disabled: 'reporting disabled',
});

/** § 7.6's twelve `api_error_type` phrases — the plain words beside the raw value, never for it. */
export const API_ERROR_PHRASE = Object.freeze({
    rate_limit: 'rate limit',
    overloaded: 'the API was overloaded',
    server_error: 'the API returned a server error',
    authentication_failed: 'authentication failed',
    billing_error: 'a billing error',
    invalid_request: 'the request was rejected as invalid',
    model_not_found: 'the model was not found',
    max_output_tokens: 'the turn hit its output-token ceiling',
    oauth_org_not_allowed: 'this organisation is not permitted',
    account_on_hold: 'the account is on hold',
    unknown: 'the harness reported the error as unknown',
    unrecognised: "this reporter did not recognise the harness's error member",
});

/**
 * § 7.6's composed line, "published here ONCE": **API error — *the raw value* (*the phrase*)**.
 * An unrecognised value keeps its raw string with *(unrecognised)* in place of the phrase; a null
 * one reads **API error** alone.
 */
export function apiErrorLine(raw) {
    if (raw === null) {
        return LABEL.stalled;
    }

    const phrase = Object.hasOwn(API_ERROR_PHRASE, raw) ? API_ERROR_PHRASE[raw] : UNRECOGNISED;

    return `${LABEL.stalled}${DASH}${raw} (${phrase})`;
}

/**
 * § 7.1's `blocked` cell completed — *waiting on a human since 14:31:00 (seat clock)* — from
 * `blocked_since`, a SEAT-CLOCK claim drawn as one and subtracted from nothing (§ 2.4). `null` for a
 * null instant; the caller draws that absence.
 */
export function blockedLine(blockedSince) {
    const since = seatClock(blockedSince);

    return since === null ? null : `${LABEL.blocked} ${since}`;
}

/**
 * The DESK RENDER — `docs/design/FLOOR.md` Appendix B row 5: § 5.1's render map, § 7.1's ten state
 * renders with § 7.3's currency treatment and § 7.4's frozen-fold render, § 5.6's null render for
 * every member the desk draws, and § 8's side table. Gated by AT-D3-5 and AT-D3-14's desk half.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE DESK SWITCHES ON `render_state` AND ON NOTHING ELSE (§ 5.1: "The one field the desk's
 * appearance is switched on"). `activity_state` is drawn only UNDER a currency label on a desk that
 * is not `live` (§ 7.3, § 7.6). Switching on it instead is AT-D3-5's first RED: a `stale` seat
 * whose activity underneath is `idle` would draw a character peacefully asleep at a desk nobody
 * has heard from in eleven minutes — `D2-MUST` #2 broken at the last layer after two documents
 * held it.
 *
 * ⛔ EVERY DECISION IS HERE, AND THIS FILE IS PURE. It reads no clock, sets no timer and draws no
 * pixel: it turns one held seat object, the ages the 1 s tick already rendered for it
 * (`wire/age-readout.js`), and two facts only the client protocol holds — whether it can still
 * confirm the seat (§ 2.3 row 5) and when the seat's `derivation` block was delivered (§ 2.4's
 * stamp rule) — into a model of one desk. `desk/desk-floor.js` is what runs it over the floor and
 * records its held renders; a drawing layer draws what the model says and decides nothing.
 * There is no browser on the build host, so a decision made in a drawing layer is a decision no
 * test reaches.
 *
 * ⛔ THE STRINGS BELOW RESTATE PUBLISHED TABLES AND ARE GUARDED, NOT TRUSTED. A browser cannot read
 * FLOOR.md, so § 7.1's state sentences, its seven `unknown_reason` sentences and § 7.6's twelve
 * `api_error_type` phrases live here as copies — and
 * `Tests\Feature\Desk\TheDeskSpeaksTheDocumentsWordsTest` re-derives each of them from the
 * document on every run and set-differences both directions. § 7.1's cells are WORKED INSTANCES
 * (its own convention: "never a rule"), so what is copied is each cell's fixed words — the values
 * spliced into them come from the fields § 5.1 names, through § 2.4's formats.
 *
 * ⚠ WHAT IS NOT HERE, named rather than left as a silence:
 *   · WHICH § 6.2 ROW A HELD RENDER IS, AND WHAT IT LOOKS LIKE. `wire/animation-set.js` holds
 *     the closed set — each row's class, its § 6.4 form and whether it loops at all — and
 *     `heldRendering()` below is what turns this file's one input into that row's rendering. What
 *     is THIS file's is the § 7.3 TREATMENT: whether a lag, a `config_invalid` reporter, an
 *     unrecognised state or a desk with nobody at it permits the render's loop to run. The edge
 *     animations and the frames a loop is actually drawn at are the set's and a drawing layer's.
 *   · MEMBERSHIP TESTING of `activity_state`, `link_state` and the badges (§ 5.4). `render_state`
 *     is membership-tested here because the desk switches on it; the other sets' unrecognised
 *     render is AT-D3-11's, gated at step 8. The *was:* form and the badge cluster carry the raw
 *     wire value in the meantime, which § 5.4 permits and never guesses past.
 *   · The DRILL-DOWN's fidelity — the uncapped intern list, the transport, derivation and
 *     reporter blocks, the session — which is step 10's.
 */

import { isRenderState } from '../lobby/render-state.js';
import { clockTime } from '../wire/clock.js';
import { contextGauge } from '../wire/context-gauge.js';
import { formatDuration } from '../wire/duration.js';
import { heldRendering } from '../wire/animation-set.js';
import { NO_DATA_YET, UNTITLED } from '../wire/null-render.js';
import { SEAT_CLOCK, seatClock } from '../wire/age-readout.js';
import { deskDrawsCharacter, taskBubble } from './task-bubble.js';

/** § 5.4 / AT-D3-11: an unrecognised member renders as unrecognised, carrying the raw string. */
export const UNRECOGNISED = 'unrecognised';

/** The separator § 7.1's cells put between a state's sentence and the value that completes it. */
export const DASH = ' — ';

/**
 * § 7.1's Label line, per member — the FIXED words of each cell. A cell that completes its
 * sentence with a value (an age, a timestamp, an error) has only its stem here; `labelLine()`
 * splices the value in by the rule the cell is a worked instance of. `retired` has no entry: its
 * desk is removed, and it renders no label line on the floor (§ 7.1, § 3.5).
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

/** § 7.1's seven `unknown_reason` sentences — "one glyph, seven explanations". */
export const UNKNOWN_REASON = Object.freeze({
    no_data_yet: 'no data yet — this seat has never reported',
    turn_aborted_calls: 'the last turn ended with calls aborted',
    turn_killed_by_clear: 'the last turn was killed by a /clear',
    turn_ended_with_session: 'the last turn ended with its session',
    stalled_left_live: 'rate-limited, then the seat went quiet',
    stalled_session_ended: 'rate-limited, then the session ended',
    session_closed_turn_open: 'the session closed with a turn still open',
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

/** § 7.3's `config_invalid` row: "its activity render, with the badge and *sending nothing*". */
export const SENDING_NOTHING = 'sending nothing';

/** § 7.2's cluster line for `badges_since` — one line for the whole cluster, never per badge. */
export const OLDEST_BADGE_SINCE = 'oldest badge since';

/**
 * § 7.1's **Desk** column, as a picture per member: who is at the desk, in what pose, under what
 * light, and what the monitor shows. `glyph` is the state's own mark; `lighting` is § 7.3's
 * treatment column for the states it dims.
 *
 * ⛔ `stale` AND `offline` ARE THE EMPTY CHAIR, WITH NOBODY IN IT — never `idle`'s sleeper
 * (§ 7.5's *Asleep* bullet, AT-D3-5's third RED). "A sleeper is a *character*, an empty chair is
 * an *absence*, and neither needs the z's to be told from the other."
 */
export const DESK = Object.freeze({
    working: { pose: 'at-keyboard', glyph: 'working', lighting: 'full', monitor: 'on' },
    idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'dimmed' },
    blocked: { pose: 'raised-hand', glyph: 'attention', lighting: 'full', monitor: 'on' },
    stalled: { pose: 'head-in-hands', glyph: 'stalled', lighting: 'full', monitor: 'on' },
    unknown: { pose: 'present', glyph: 'question', lighting: 'full', monitor: 'on' },
    catching_up: { pose: 'present', glyph: 'replay', lighting: 'desaturated', monitor: 'on' },
    stale: { pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dimmed', monitor: 'on' },
    offline: { pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dark', monitor: 'on' },
    disabled: { pose: 'present', glyph: 'monitor-off', lighting: 'dimmed', monitor: 'off' },
});

/** § 7.1's A4 condition over a `working` seat: a turn open with no call is the THINK pose. */
const THINKING = { pose: 'leaning-back', glyph: 'thinking', lighting: 'full', monitor: 'on' };

/**
 * § 6.2's `held` rows, by the `render_state` each row's own condition names (and A4's, which adds
 * two more members). `null` is a state whose desk holds no render at all — § 7.1's Animation
 * column reads *none* for it.
 *
 * ⛔ WHICH OF THESE HOLDS MOTION IS NOT HERE. § 11's *two states with no motion by design* are
 * `stalled` and `unknown`, and `wire/animation-set.js` answers that from A8's and A9's own § 6.2
 * Animation cells — which name no loop where every other held row names a 4 fps one — rather than
 * from a pair of ids kept beside this map, which is what this file held until card#7341 step 6
 * and what nothing re-derived from the document.
 * `Tests\Feature\Floor\TheAnimationSetIsTheDocumentsClosedSetTest` re-derives this map itself
 * from § 6.2's held conditions.
 */
const HELD = Object.freeze({
    // A4 when the THINKING pose is drawn instead — the two are exclusive (§ 6.2's A3 row).
    working: 'A3',
    idle: 'A6',
    blocked: 'A7',
    stalled: 'A8',
    unknown: 'A9',
    catching_up: 'A15',
});

/**
 * § 7.1's Label line for one member, completed from the fields and ages its cell names.
 *
 * ⛔ A VALUE THE WIRE LEFT NULL IS NEVER SPLICED IN AS A WORD. `stale`/`offline` with a null
 * `delivery.no_data_since` read ***no data yet*** alone (§ 5.6, § 7.1's `offline` row) — never
 * *no data since null*, and never an age beside it. A `blocked` seat's `blocked_since` cannot be
 * null by D2 § 8.2.1's own tie, so no case is written for it beyond the bare state word § 7.1's
 * `working` row argues is "the only string that renders `render_state` and no second fact".
 */
function labelLine(state, seat, ages, dark) {
    switch (state) {
        case 'working':
        case 'catching_up':
        case 'disabled':
            return LABEL[state];
        case 'idle':
            // "the same readout under the state's own sentence, never a second wording" (§ 2.4).
            return ages.quiet_age === null ? LABEL.idle : LABEL.idle + DASH + ages.quiet_age;
        case 'blocked': {
            const since = seatClock(seat.blocked_since ?? null);

            return since === null ? state : `${LABEL.blocked} ${since}`;
        }
        case 'stalled':
            return apiErrorLine(seat.api_error_type ?? null);
        case 'unknown':
            return unknownLine(seat.unknown_reason ?? null);
        case 'stale':
        case 'offline':
            return dark.age === null ? dark.since : dark.since + DASH + dark.age;
        default:
            return null;
    }
}

/**
 * § 7.6's composed line, "published here ONCE": **API error — *the raw value* (*the phrase*)**.
 * An unrecognised value keeps its raw string with *(unrecognised)* in place of the phrase; a null
 * one reads **API error** alone.
 */
function apiErrorLine(raw) {
    if (raw === null) {
        return LABEL.stalled;
    }

    const phrase = Object.hasOwn(API_ERROR_PHRASE, raw) ? API_ERROR_PHRASE[raw] : UNRECOGNISED;

    return `${LABEL.stalled}${DASH}${raw} (${phrase})`;
}

/** § 7.1's `unknown` row: "one sentence per `unknown_reason`" — raw and marked when unknown to us. */
function unknownLine(raw) {
    if (raw === null) {
        return 'unknown';
    }

    return Object.hasOwn(UNKNOWN_REASON, raw) ? UNKNOWN_REASON[raw] : `${raw} (${UNRECOGNISED})`;
}

/**
 * The dark desk's pair (§ 2.4's `dark-only` row): *no data since HH:MM:SS* from the version-bearing
 * `delivery.no_data_since`, a SERVER-clock timestamp that never ticks, and beside it the receipt
 * age the 1 s tick already rendered from `delivery.last_receipt_at`. On D2's word the two are one
 * instant on these two states, delivered twice — "once version-bearing and once not".
 */
function darkPair(seat, ages) {
    const since = clockTime(seat.delivery?.no_data_since ?? null);

    return {
        since: since === null ? NO_DATA_YET : `${LABEL.stale} ${since}`,
        // § 5.6: a never-reported seat's age "has nothing to draw and is not drawn".
        age: since === null ? null : ages.receipt_age,
    };
}

/**
 * § 7.3's *was:* currency label — § 7.6 owns the form: *was: working (last event 12:47, seat
 * clock)*. The parenthetical is `activity.last_event_time` as a labelled seat-clock TIMESTAMP and
 * never an elapsed time; with no last event it is not drawn (§ 5.6).
 */
function wasLabel(seat) {
    const at = clockTime(seat.activity?.last_event_time ?? null);
    const activity = seat.activity_state ?? null;

    return at === null ? `was: ${activity}` : `was: ${activity} (last event ${at}, ${SEAT_CLOCK})`;
}

/**
 * § 7.4's lag line: *this state is N behind — as of HH:MM:SS*. The words before the dash are
 * § 2.4's wording table's (*this state is 1m 57s behind*), N is § 2.4's duration format over
 * `derivation.fold_lag_ms`, and the stamp is the `server_time` that DELIVERED the `derivation`
 * block (§ 2.4's stamp rule), because the number is `fetch-fresh` and the desk has no block whose
 * stamp it could borrow.
 *
 * ⛔ THE NUMBER IS NEVER TICKED. A client's copy of `fold_lag_ms` froze with the fold that wrote
 * it; nothing here adds the time since it arrived, because nothing has delivered a new one.
 */
function lagLine(seat, stamp) {
    const ms = seat.derivation?.fold_lag_ms;
    const asOf = clockTime(stamp);

    if (typeof ms !== 'number' || asOf === null) {
        return null;
    }

    return `this state is ${formatDuration(ms / 1000)} behind${DASH}as of ${asOf}`;
}

/**
 * § 8's side table, the DESK's half: one stool per element of the capped `subagents[]`, newest
 * first as the wire orders it, and *+N more* from the wire's own count.
 *
 * ⛔ THE COUNT IS `subagents_open`, NEVER THE ARRAY'S LENGTH, and an empty array is NO stools —
 * not a stool count of zero (AT-D3-14: "the side table shows no stools rather than zero stools").
 */
function sideTable(seat) {
    const subagents = Array.isArray(seat.subagents) ? seat.subagents : [];
    const open = Number.isInteger(seat.subagents_open) ? seat.subagents_open : null;
    const more = open === null ? 0 : open - subagents.length;

    return {
        stools: subagents.map((s) => ({
            call_id: s.call_id ?? null,
            // § 5.6: **untitled**, never an invented title and never the type standing in for one.
            label: s.title ?? UNTITLED,
            untitled: (s.title ?? null) === null,
            // § 5.6: a null type draws no tag; the stool and its label are unaffected.
            type: s.subagent_type ?? null,
            // § 8: "a seat-clock claim, rendered as a labelled timestamp and never as *how long it
            // has been running*".
            started_at: seatClock(s.started_at ?? null),
        })),
        more: more > 0 ? more : null,
    };
}

/**
 * § 5.1's monitor: what the seat is doing right now, or the desk's state line when no call is
 * open — "never a stale last action". `disabled`'s monitor is off (§ 7.1).
 */
function monitor(desk, seat, label) {
    if (desk.monitor === 'off') {
        return { lit: 'off', text: null, subagent_call: false };
    }

    const action = seat.action ?? null;

    if (action === null) {
        return { lit: desk.monitor, text: label, subagent_call: false };
    }

    return {
        lit: desk.monitor,
        // § 5.6: a null descriptor shows `tool_name` alone — "never a descriptor synthesized from
        // the tool name".
        text: action.descriptor ?? action.tool_name ?? null,
        // § 5.1's *this is a subagent's call* marker. § 5.6: a null scope is never defaulted to
        // `main`, and a missing parent names none — the marker is drawn on a field the wire SENT.
        subagent_call: action.agent_scope === 'subagent' || (action.parent_call_id ?? null) !== null,
    };
}

/**
 * One desk, or `null` for a seat with no desk at all (`retired`, § 7.1: "the instruction to stop
 * rendering one").
 *
 * @param {object} seat         the held seat object (D2 § 8.2.1)
 * @param {object} ages         this seat's readouts from `wire/age-readout.js`'s `deskAgeReadout`
 * @param {object} [facts]      what only the client protocol knows:
 *   `missing` — § 2.3 row 5: the client can no longer confirm the seat;
 *   `derivation_stamp` — the `server_time` that delivered the held `derivation` block.
 * @param {object} [options]    `{ ref_bases }` for the thought bubble (§ 5.2's link rule), and
 *   `reduce` — § 6.4's `prefers-reduced-motion`, which selects each § 6.2 row's reduced-motion
 *   FORM. It is not a degradation: the same fact, carried without motion.
 */
export function deskModel(seat, ages, facts = {}, options = {}) {
    const state = seat.render_state;

    if (state === 'retired') {
        return null;
    }

    const recognised = isRenderState(state);
    const badges = Array.isArray(seat.badges) ? seat.badges : [];

    // § 7.4: the treatment is driven by the BADGE — version-bearing, delivered by the sweeper —
    // and never by a held `fold_lag_ms`, which froze with the fold and "can never cross 60 s".
    const lagged = badges.includes('fold_lag');
    const configInvalid = badges.includes('config_invalid');

    // § 2.3 row 5 / § 7.5's *Confirmed* bullet: a seat the client cannot confirm is the empty
    // chair, "never a character, however recently the held object was accurate".
    const unconfirmed = facts.missing === true;

    let desk = recognised ? DESK[state] : null;

    if (state === 'working' && seat.open_calls === 0 && seat.open_turn === true) {
        desk = THINKING;
    }

    if (desk === null) {
        // § 5.4: an unrecognised glyph carrying the raw string, and the desk treated as not-current.
        desk = { pose: 'empty-chair', glyph: UNRECOGNISED, lighting: 'dimmed', monitor: 'on' };
    }

    if (unconfirmed) {
        desk = { ...desk, pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dimmed' };
    }

    const character = recognised && !unconfirmed && deskDrawsCharacter(state);
    const dark = state === 'stale' || state === 'offline' ? darkPair(seat, ages) : null;
    const label = recognised ? labelLine(state, seat, ages, dark) : `${state} (${UNRECOGNISED})`;

    // § 6.2's held render, and § 7.3's TREATMENT of whether its loop may run: a lag, a
    // `config_invalid` reporter, an unrecognised state and a desk with nobody at it all stop it.
    // Whether the row loops at all, and § 6.4's form, are the animation set's answer.
    const heldId = character ? (desk === THINKING ? 'A4' : (HELD[state] ?? null)) : null;
    const permitted = !lagged && !configInvalid;

    return {
        install_id: seat.install_id,
        seat_id: seat.seat_id,
        nameplate: seat.seat_id,
        render_state: { value: state, recognised },
        character,
        unconfirmed,
        pose: desk.pose,
        glyph: recognised ? desk.glyph : `${UNRECOGNISED}: ${state}`,
        lighting: desk.lighting,
        label_line: label,
        // § 7.3: the activity state UNDER the label, on `catching_up` and `disabled`; on a dark
        // desk it is "in the drill-down only, under *when it went dark*", so the desk draws none.
        currency_label: state === 'catching_up' || state === 'disabled' ? wasLabel(seat) : null,
        dark,
        lag: lagged
            ? { overlay: 'hatched', line: lagLine(seat, facts.derivation_stamp ?? null) }
            : null,
        config_note: configInvalid ? SENDING_NOTHING : null,
        monitor: monitor(desk, seat, label),
        // § 5.1: "`0` renders nothing rather than a zero" — the count appears past one.
        open_calls: Number.isInteger(seat.open_calls) && seat.open_calls > 1 ? seat.open_calls : null,
        action: seat.action === null || seat.action === undefined ? null : {
            started_at: seatClock(seat.action.started_at ?? null),
            elapsed: ages.action_elapsed,
        },
        // § 2.4: the quiet age, ticking. On `idle` it is inside the Label line — "the same
        // readout under the state's own sentence, never a second wording".
        quiet_age: state === 'idle' ? null : ages.quiet_age,
        last_kind: seat.activity?.last_kind ?? null,
        last_event_time: seatClock(seat.activity?.last_event_time ?? null),
        gauge: contextGauge(seat.context ?? null, ages.context_age),
        // § 5.6: a null model label is omitted — no label, no *(unknown model)*.
        model_label: seat.model_label ?? null,
        badges: [...badges],
        oldest_badge_since: (seat.badges_since ?? null) === null
            ? null
            : `${OLDEST_BADGE_SINCE} ${clockTime(seat.badges_since)}`,
        side_table: sideTable(seat),
        // § 5.1 rule 3: "A desk that draws no character draws no bubble", which covers the
        // unconfirmed seat too — the bubble module reads `render_state` alone and cannot know.
        bubble: character ? taskBubble(seat, { ref_bases: options.ref_bases ?? null }) : null,
        held: heldId === null ? null : heldRendering(heldId, permitted, options.reduce === true),
    };
}

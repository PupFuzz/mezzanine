/**
 * The desk drill-down panel's model — `docs/design/FLOOR.md § 4.3`'s table, § 5.2's rules, § 8's
 * intern join, § 7.2's badge lines, § 9 F10/F11, and § 5.6's null render for every nullable member
 * it touches. Appendix B row 10, card#7342.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY DECISION THIS PANEL MAKES IS IN THIS FILE, AND `main.js` MAKES NONE. There is no
 * browser on the build host, so the DOM half is exercised by nothing but a stub; keeping every
 * string, every absence render and every selection here is what keeps the unverifiable half free of
 * judgement. If a rule appears in `main.js` that is not here, it is in the wrong file.
 *
 * ⛔ IT RENDERS WHAT THE WIRE CARRIES AND NOTHING ELSE. Every value below names a member of
 * `docs/design/FLEET-STATE.md § 8.2.1` (the seat object) or of § 8.2.3's `detail` / § 8.2's
 * timeline response. § 5.4: "a rendered fact with no field is a fact the client invented."
 *
 * ⛔ IT IS PURE OVER WHAT IT IS HANDED, AND WHAT IT IS HANDED IS `drilldown-panel.js`'s. That module
 * owns the two requests § 4.3 puts on the panel's open, the live patching from the protocol's held
 * seat, and § 2.4's stamp rule per `fetch-fresh` block; this one turns the composed seat, its stamps
 * and the corrected clock into strings. Two clocks reach it and they are never mixed: `now_ms`, the
 * CORRECTED clock the version-bearing ages tick on (§ 2.4), and each block's *as of* stamp, which
 * the `fetch-fresh` values are read AT and never ticked from.
 *
 * ⚠ TWO AGES ARE RENDERED IN A WORDING THIS DOCUMENT HAS NOT CHOSEN, and § 14 item 17 is what
 * permits it: the context sample's age and the timeline row's age — and, since step 10, the
 * transport block's heartbeat freshness and the spool's oldest-unsent age — are sites whose FORMAT is
 * closed and whose WORDING is "the renderer's" in the meantime. So each is drawn as the bare output of
 * § 2.4's function beside its own labelled element. The ages that DO have a published wording take it
 * verbatim — *running for 2m 05s*, *nothing done for 4m 12s*, *no data for 11m*, *this state is 1m 57s
 * behind* — and none is spelled here: all four are `wire/age-readout.js`'s, which the desk draws from
 * too.
 */

import { clockTime } from '../wire/clock.js';
import { contextGauge } from '../wire/context-gauge.js';
import { ageFrom, formatDuration, wireMs } from '../wire/duration.js';
import { NOT_REPORTED, NO_DATA_YET, UNTITLED } from '../wire/null-render.js';
import {
    actionElapsedLine,
    deskAgeReadout,
    derivationLagLine,
    quietAgeLine,
    receiptAgeAt,
    seatClock,
} from '../wire/age-readout.js';
import { BADGES } from '../wire/member-sets.js';
import { isRenderState } from '../lobby/render-state.js';
import { taskFacts } from '../wire/task.js';
import { LABEL, deskModel, wasLabel } from '../desk/desk-render.js';

/**
 * § 4.3's `task` members are decided by the ONE implementation of that member's rules —
 * `wire/task.js`'s `taskFacts`, which the desk's thought bubble shares. The degraded wording is
 * RE-EXPORTED from there rather than re-spelled.
 */
export { STALE_TITLE_DROPPED } from '../wire/task.js';

/**
 * § 5.6's shared words — *not reported*, *untitled* — are `wire/null-render.js`'s. RE-EXPORTED, NOT
 * RE-SPELLED: this panel's published surface keeps both names.
 */
export { NOT_REPORTED, UNTITLED } from '../wire/null-render.js';

/** § 5.2, verbatim: an empty timeline window is a fact, not an empty panel. */
export const NO_ACTIVITY = 'no activity in this window';

/** § 5.4 / AT-D3-11: an unrecognised member renders as unrecognised, carrying the raw string. */
export const UNRECOGNISED = 'unrecognised';

/** § 5.5: the client narrating its own request outcome, never a seat's fact. */
export const WINDOW_NOT_FETCHED = 'the recent-activity window has not been fetched';

/**
 * § 9 F11's render for the intern list, verbatim in its two halves: the list "falls back to
 * `subagents[]` **and says it is capped**". ⚠ The sentence around those facts is not ratified — F11
 * publishes the facts and no string — so it names them and nothing else.
 */
export const LIST_NOT_SOURCED = 'unavailable — the seat detail could not be read, so this is the seat object’s subagents[], capped at 8';

/** § 9 F11: "the sections that need `detail` read **unavailable**" — the cell's own word. */
export const UNAVAILABLE = 'unavailable';

/**
 * § 5.5: the detail request is still out. F11 names a FAILED request, and a panel that said
 * *unavailable* before its request had answered would be reporting a failure nobody observed.
 * ⚠ Not ratified — the client narrating its own request, in the facts and nothing else.
 */
export const WAITING = 'waiting for the seat detail';

/** F11's intern-list sentence while the detail request is still out: nothing is claimed yet. */
export const LIST_WAITING = `${WAITING} — the uncapped list comes with it`;

/** § 2.4: with no corrected clock there is no honest age, and the panel says so rather than tick. */
export const NO_CORRECTED_CLOCK = 'ages are not shown — this response carried no server clock';

/** § 5.2's session block: "`session` null ⇒ *no session open*, which is a fact, not a blank". */
export const NO_SESSION_OPEN = 'no session open';

/**
 * § 7.2 / § 5.6: D1's twelve badges are rendered *since reporter start*, never as *now*, with
 * `reporter.uptime_s` beside them; a null uptime reads *since reporter start — uptime not reported*
 * (§ 5.6's `reporter.uptime_s` cell, verbatim).
 */
export const SINCE_REPORTER_START = 'since reporter start';

/**
 * § 7.2's **Drill-down line** column, one per badge — the first italic span of each cell, verbatim,
 * with its `N` where the cell has one. Its **Origin** column beside it.
 *
 * ⛔ A GUARDED COPY, NOT A TRUSTED ONE. A browser cannot read FLOOR.md, so the eighteen lines live
 * here — each cell's words, with the code quotes the document sets them in dropped — — and `Tests\Feature\Floor\TheDrillDownSpeaksTheDocumentsBadgeLinesTest` re-derives both
 * columns from § 7.2's table on every run and set-differences both directions, so a line edited in
 * either place reds.
 */
export const BADGE_LINE = Object.freeze({
    lossy: Object.freeze({ origin: 'D1', line: 'events discarded: N' }),
    batches_rejected: Object.freeze({ origin: 'D1', line: 'N batches refused — last status and error code' }),
    harness_contract_moved: Object.freeze({ origin: 'D1', line: 'the harness payload moved under this reporter' }),
    reporter_behind: Object.freeze({ origin: 'D1', line: 'the harness has an enum member this reporter coerces' }),
    value_clamped: Object.freeze({ origin: 'D1', line: 'a reported value left its declared range and was clamped' }),
    counters_omitted: Object.freeze({ origin: 'D1', line: 'N counters did not fit the heartbeat' }),
    index_overflow: Object.freeze({ origin: 'D1', line: 'the seat passed its open-call or open-session index cap, or skipped history when its index journal tail was truncated at 8 MiB' }),
    invalid_tool_name: Object.freeze({ origin: 'D1', line: 'a tool name failed its pattern and was sent as INVALID_TOOL_NAME' }),
    bad_session_id: Object.freeze({ origin: 'D1', line: 'a session id failed its pattern and was sent as null' }),
    config_invalid: Object.freeze({ origin: 'D1', line: "the reporter's config failed validation; it is spooling and sending nothing" }),
    statusline_degraded: Object.freeze({ origin: 'D1', line: 'the wrapped status-line command is failing' }),
    epoch_reset: Object.freeze({ origin: 'both', line: 'a new sequence epoch was minted; nothing was discarded' }),
    seq_gap: Object.freeze({ origin: 'D2', line: 'N events the reporter sent did not arrive' }),
    seq_collision: Object.freeze({ origin: 'D2', line: 'two events claimed one sequence number' }),
    clock_skew: Object.freeze({ origin: 'D2', line: "seat clock is N s from the server's" }),
    reporter_ahead: Object.freeze({ origin: 'D2', line: 'this seat is sending values this server does not know' }),
    fold_lag: Object.freeze({ origin: 'D2', line: 'this state is N behind the events that produced it' }),
    derivation_error: Object.freeze({ origin: 'D2', line: 'an event could not be projected; this seat\'s state is missing it' }),
});

/**
 * `docs/design/EVENT-SCHEMA.md § 9.3`'s **Raised by** column for `reporter.heartbeat.degraded` — "the
 * mapping, and it is the only one" — so that § 7.2's *the counter's value* beside each of D1's twelve
 * badges is the counters that RAISED it, read from `detail.heartbeat_counters`. A name written
 * `x.<y>` is a family, matched on its `x.` prefix.
 *
 * ⛔ GUARDED LIKE `BADGE_LINE`: the same test re-derives this column from D1's table.
 */
export const RAISED_BY = Object.freeze({
    lossy: Object.freeze(['spool_dropped_events', 'spool_corrupt_lines', 'events_rejected_dropped', 'oversize_event_dropped', 'spool_append_failed.<tree>']),
    batches_rejected: Object.freeze(['batches_rejected']),
    harness_contract_moved: Object.freeze(['hook_name_mismatch', 'payload_key_missing.<key>', 'payload_key_missing.is_interrupt']),
    reporter_behind: Object.freeze(['enum_value_unknown.<wire field>', 'enum_value_unknown.notification_type']),
    value_clamped: Object.freeze(['value_clamped.<wire field>']),
    counters_omitted: Object.freeze(['data_truncated.reporter.heartbeat.counters']),
    index_overflow: Object.freeze(['open_call_index_overflow', 'open_session_index_overflow', 'index_fold_truncated']),
    invalid_tool_name: Object.freeze(['invalid_tool_name']),
    bad_session_id: Object.freeze(['bad_session_id']),
    config_invalid: Object.freeze(['config_invalid']),
    statusline_degraded: Object.freeze(['wrapped_statusline_failures']),
    epoch_reset: Object.freeze(['state_reset']),
});

/**
 * The panel's UNCAPPED INTERN LIST, out of § 8.2.3's open-call list: the calls that DISPATCHED
 * an intern — `is_dispatch`, D2 § 6.4's own column on `calls`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ D3 STATES TWO SELECTIONS FOR THIS ONE LIST AND THEY SELECT DISJOINT SETS. This function
 * implements the second, and the argument is written here rather than left in a PR body because
 * the next reader will otherwise "fix" it back to the first.
 *
 *   (a) § 5.2's rule cell and § 8's *the full list* row say: "selected on `agent_scope ==
 *       "subagent"` / a non-null `parent_call_id`".
 *   (b) § 8's own rendered rows — the intern's LABEL is `title`, its TYPE is `subagent_type`, a
 *       null title renders **untitled** — and AT-D3-4's GREEN — "the drill-down, opened against
 *       a stubbed detail response carrying nine open DISPATCH calls, lists 9" — say: the open
 *       dispatch calls.
 *
 * They are disjoint on this deployment's real data, not merely differently worded. A dispatch is
 * a call the MAIN agent makes (D2 § 10's worked trace, E1: the `Agent` call is `is_dispatch`,
 * and "`subagents` gains a title-less entry"), so it carries `agent_scope: "main"` and a null
 * `parent_call_id` — (a) excludes every one of them. What (a) DOES select is the calls the
 * intern itself runs, which carry no `title` and no `subagent_type` at all, so under (a) every
 * row of this list is **untitled** forever, AT-D3-4's GREEN cannot pass, and § 8's own two label
 * rows have nothing to draw from.
 *
 * ⛔ § 8.1 IS WHAT DECIDES IT, because the cap argument rests on this list's population: "the
 * panel that would benefit from a longer array ALREADY HAS EVERY INTERN". Under (a) the panel
 * has none, and the reason § 8.1 gives for keeping the cap at 8 is not merely weakened but
 * false. Under (b) it is exactly true — the same population as the seat object's `subagents[]`
 * (`SeatFacts::openSubagents()` is `is_dispatch` too), without the cap, which is precisely what
 * § 8 says the two artifacts are: "two artifacts, two sources".
 *
 * ⚠ THE DOCUMENT IS NOT AMENDED BY THIS FILE. A design-doc change is a ratified act; card#7342's
 * PR body carries the proposed text for § 5.2's cell and § 8's row under its own heading, and
 * `DrillDownRendersTheInternsTest` pins BOTH readings — this one as the rendered list, and (a)
 * as the set that is empty of titles — so the day the amendment is ruled on, the evidence is a
 * test rather than a memory.
 *
 * ⛔ IT IS NOT EVERY OPEN CALL either, which is the reading § 5.2 refuses in terms: "the panel
 * that listed every one would call a seat's own `Bash` call an intern".
 *
 * ⚠ `is_dispatch` ARRIVES AS `TINYINT(1)`. D2 § 6.4 declares the column and the read plane hands
 * the row out as it stores it, so the true value on the wire is the number `1` and not `true`.
 * Both are admitted and nothing else is: `Boolean(x)` would admit the string `"false"`, which is
 * the kind of truthiness that turns a wire-shape question into a rendered lie.
 */
export function internCalls(openCalls) {
    return (openCalls ?? []).filter((call) => call?.is_dispatch === true || call?.is_dispatch === 1);
}

/**
 * § 5.2's OTHER selection — the calls carrying a subagent scope or a parent — exported for the
 * one test that holds the contradiction above visible, and rendered by nothing.
 *
 * ⛔ A NULL `agent_scope` IS NOT DEFAULTED TO `main` OR TO `subagent` (§ 5.6): a call is admitted
 * on a non-null `parent_call_id` and on nothing else, so a field the wire left empty never moves
 * a call between two lists.
 */
export function subagentScopedCalls(openCalls) {
    return (openCalls ?? []).filter(
        (call) => call?.agent_scope === 'subagent' || (call?.parent_call_id ?? null) !== null,
    );
}

/**
 * § 5.4's membership test, applied to the one enum member the header carries raw.
 *
 * ⛔ AN UNRECOGNISED MEMBER CARRIES THE RAW STRING AND SAYS IT IS UNRECOGNISED — it is never
 * mapped to the nearest known member and never defaulted to a healthy-looking one (AT-D3-11's
 * two REDs). The composed label is decided here rather than in the DOM half, because which of
 * the two forms is drawn is exactly the decision that layer does not make.
 */
function member(value) {
    const raw = typeof value === 'string' ? value : null;
    const recognised = isRenderState(value);

    return {
        value: raw,
        recognised,
        label: recognised ? raw : `${raw === null ? NOT_REPORTED : raw} (${UNRECOGNISED})`,
    };
}

/** § 2.4's *as of HH:MM:SS* — the stamp a `fetch-fresh` block is read at, or `null` with none. */
function asOf(stamp) {
    const at = clockTime(stamp);

    return at === null ? null : `as of ${at}`;
}

/**
 * The panel, from what `drilldown-panel.js` composed.
 *
 * @param seat      the seat to draw: the protocol's held object — version-bearing members patched
 *                  live — with the three `fetch-fresh` blocks (`delivery`, `reporter`, `derivation`)
 *                  as last DELIVERED, plus `server_time` and `detail` from the panel's own detail
 *                  response when one has answered. A bare `GET /api/fleet/seats/…` body is such a
 *                  seat, which is what the model's own tests hand it.
 * @param timeline  `GET …/timeline`'s body — every page fetched, merged — or `null` when none has
 *                  answered
 * @param options   `{ now_ms }` — the CORRECTED clock (§ 2.4); `{ stamps }` — each `fetch-fresh`
 *                  block's `server_time` (§ 2.4's stamp rule), defaulting to the response's own;
 *                  `{ floor }` — the room the header names; `{ detail_failure, timeline_failure }` —
 *                  § 9 F11 / F10, `{ status }` of the request that failed; `{ detail_pending }` — the
 *                  detail request is still out; `{ missing }` — § 2.3 row 5
 */
export function drillDownModel(seat, timeline, options = {}) {
    const now = options.now_ms;
    const ages = typeof now === 'number' && Number.isFinite(now);
    const nowMs = ages ? now : null;
    const age = (wireTime) => (ages ? ageFrom(wireTime, now) : null);
    const stamps = {
        delivery: options.stamps?.delivery ?? seat?.server_time ?? null,
        reporter: options.stamps?.reporter ?? seat?.server_time ?? null,
        derivation: options.stamps?.derivation ?? seat?.server_time ?? null,
        detail: options.stamps?.detail ?? seat?.server_time ?? null,
    };
    const detailFailed = (options.detail_failure ?? null) !== null;
    // What a section that needs `detail` reads while it has none: § 9 F11's *unavailable* once the
    // request has failed (or a body simply carried no `detail`), and *waiting* while it is still out.
    const missingDetail = options.detail_pending === true && !detailFailed ? WAITING : UNAVAILABLE;
    const skew = skewNote(seat?.delivery?.clock_skew_ms ?? null);
    const sc = (wireTime) => withSkew(seatClock(wireTime ?? null), skew);

    return {
        // § 3.1: `(install_id, seat_id)` is the identity and the whole of it. Never null.
        seat: { seat_id: seat?.seat_id ?? null, install_id: seat?.install_id ?? null },
        render_state: member(seat?.render_state),
        header: header(seat, nowMs, stamps, options, skew),
        ages_available: ages,
        // § 9's rule for every failure path: the viewer SEES it. A panel that quietly dropped
        // every age because one response arrived without a server clock would look like a seat
        // with nothing to report, which is the confusion this whole product exists to prevent.
        no_clock_statement: ages ? null : NO_CORRECTED_CLOCK,
        // § 5.2's clock skew, "beside EVERY seat-clock timestamp in the panel" while non-null — and
        // with it null, "no seat-clock timestamp gains a skew note" (§ 5.6).
        skew,
        task: taskBlock(seat?.task ?? null),
        action: actionBlock(seat?.action ?? null, nowMs, sc),
        quiet_age: quietAge(seat?.activity ?? null, nowMs, sc),
        context: panelGauge(seat?.context ?? null, age(seat?.context?.sampled_received_at ?? null), sc),
        interns: internBlock(seat, detailFailed, missingDetail, sc),
        activity: activityBlock(timeline, age, options.timeline_failure ?? null, sc),
        transport: transportBlock(seat?.delivery ?? null, seat?.activity ?? null, nowMs, stamps.delivery),
        derivation: derivationBlock(seat?.derivation ?? null, stamps.derivation),
        reporter: reporterBlock(seat?.reporter ?? null, seat?.enabled ?? null, stamps.reporter),
        badges: badgeBlock(seat, detailFailed, missingDetail),
        session: sessionBlock(seat?.session ?? null, seat?.model_label ?? null, sc),
        counters: countersBlock(seat, detailFailed, missingDetail, stamps.detail),
        raw: rawBlock(seat),
    };
}

/**
 * § 4.3's **header**: "seat name, floor, `render_state` with its plain-language line, the currency
 * label if any" — the DESK's own line and label (§ 5.1, § 7.1, § 7.3), from the ONE desk model rather
 * than a second copy of § 7.1's sentences. On a dark desk the activity state is "in the drill-down
 * only, under *when it went dark*" (§ 7.3's `stale` / `offline` row), so there the header carries the
 * *was:* form the desk does not draw — through the desk's own `wasLabel`, § 7.6's one form.
 */
function header(seat, nowMs, stamps, options, skew) {
    if (seat === null || seat === undefined || typeof seat.render_state !== 'string') {
        return { seat_id: seat?.seat_id ?? null, floor: options.floor ?? null, line: null, currency: null };
    }

    const desk = deskModel(seat, deskAgeReadout(seat, nowMs), {
        missing: options.missing === true,
        derivation_stamp: stamps.derivation,
    });

    if (desk === null) {
        return { seat_id: seat.seat_id, floor: options.floor ?? null, line: null, currency: null };
    }

    const dark = seat.render_state === 'stale' || seat.render_state === 'offline';
    const currency = desk.currency_label ?? (dark && typeof seat.activity_state === 'string' ? wasLabel(seat) : null);

    return {
        seat_id: seat.seat_id,
        floor: options.floor ?? seat.install_id ?? null,
        // Two of § 7.1's lines and § 7.6's *was:* form carry a seat-clock claim — *waiting on a human
        // since … (seat clock)*, *(last event …, seat clock)* — and § 5.2 puts the skew "beside EVERY
        // seat-clock timestamp in the panel".
        line: seat.render_state === 'blocked' ? withSkew(desk.label_line, skew) : desk.label_line,
        // `wasLabel` draws the parenthetical exactly when `activity.last_event_time` is non-null.
        currency: currency !== null && (seat.activity?.last_event_time ?? null) !== null ? withSkew(currency, skew) : currency,
        unconfirmed: desk.unconfirmed,
    };
}

/**
 * § 5.2's skew note: `delivery.clock_skew_ms` "rendered whenever non-null, beside every seat-clock
 * timestamp in the panel, so a narrative time is never read as an absolute one". Signed, in the unit
 * the wire carries. ⚠ The words are not ratified; they are § 7.2's `clock_skew` line in the wire's
 * own unit. A null skew is no note at all — never *0 ms*, which would claim the two clocks were
 * measured to agree (§ 5.6).
 */
function skewNote(ms) {
    if (typeof ms !== 'number' || !Number.isFinite(ms)) {
        return null;
    }

    return `seat clock is ${ms > 0 ? '+' : ''}${ms} ms from the server's`;
}

function withSkew(text, skew) {
    return text === null || skew === null ? text : `${text} — ${skew}`;
}

/**
 * § 4.3's **current task** row: `task.title`, the tier that answered (`task.source`), the reference
 * as plain text, and *stale title dropped* when `task.degraded`.
 *
 * ⛔ THE REFERENCE IS PLAIN TEXT, NEVER A LINK — operator ruling 2026-09-13 (§ 5.2, § 14 item 3): no
 * link base URL is configured, because the board is private, and "a guessed URL is a link that goes
 * somewhere wrong, which is worse than no link". A non-null `task.ref` comes from D2 § 4.9's tier 1,
 * the board-poll producer `docs/design/BOARD-TASK.md` designs (card#7582); tier 3 (telemetry) carries
 * none, which is why `task.source` is rendered beside the title: "a floor showing tier 3 everywhere
 * is visibly a floor whose board integration is dark".
 */
function taskBlock(task) {
    const facts = taskFacts(task);

    if (facts === null) {
        return { present: false, statement: NOT_REPORTED };
    }

    return { present: true, ...facts };
}

/**
 * § 4.3's **current action** row. The elapsed time is § 2.4's action elapsed, verbatim, and it
 * is computed from `started_received_at` — the SERVER clock — because "both ends are the server
 * clock, which is what makes it the one honest duration over an action". `started_at` is the
 * seat's own claim and is rendered beside it as a labelled timestamp, subtracted from nothing.
 */
function actionBlock(action, nowMs, sc) {
    if (action === null) {
        // § 5.6, `action`: no monitor content, "never a stale last action".
        return { present: false, statement: NOT_REPORTED };
    }

    return {
        present: true,
        call_id: action.call_id ?? null,
        tool_name: action.tool_name ?? null,
        // § 5.6: a null descriptor shows `tool_name` alone — "never a descriptor synthesized
        // from the tool name".
        descriptor: action.descriptor ?? null,
        started_at: sc(action.started_at),
        elapsed: actionElapsedLine(action, nowMs),
        // § 5.1: labels, stored for the intern join, and nothing on this page gates on them.
        agent_scope: action.agent_scope ?? null,
        parent_call_id: action.parent_call_id ?? null,
    };
}

/**
 * § 2.4's **quiet age**, with § 5.6's *nothing done yet* for a null basis — `quietAgeLine`'s, and
 * the reachable never-reported seat is the case it is for.
 */
function quietAge(activity, nowMs, sc) {
    return {
        line: quietAgeLine(activity, nowMs),
        last_kind: activity?.last_kind ?? null,
        last_event_time: sc(activity?.last_event_time),
    };
}

/**
 * § 4.3's **context gauge** is `wire/context-gauge.js`'s `contextGauge`, the desk's too; what is the
 * PANEL's is that its seat-clock `sampled_at` carries the skew note like every other one here.
 */
function panelGauge(context, age, sc) {
    const gauge = contextGauge(context, age);

    return gauge.reported ? { ...gauge, sampled_at: sc(context?.sampled_at) } : gauge;
}

/**
 * § 4.3's **interns** row — the uncapped list from `detail`, with `subagents_open` beside it.
 *
 * ⛔ THE COUNT IS `subagents_open` AND NEVER `rows.length`. § 8: "the count is the wire's, never
 * `subagents.length`"; § 2.1 lists counting the array among the client's forbidden computations.
 * AT-D3-4's second RED is precisely a count that saturates at the cap while the seat runs more.
 * The two are rendered side by side rather than reconciled, so a disagreement between the wire's
 * count and the served list is visible instead of being silently resolved here.
 *
 * ⛔ § 9 F11: WITH NO `detail`, "the intern list falls back to `subagents[]` AND SAYS IT IS CAPPED".
 * Showing the seat object's capped array as if it were the complete list is that row's Never.
 *
 * ⛔ AN EMPTY LIST IS ABSENT, NOT DRAWN EMPTY (AT-D3-14's panel half, `nulls-a`: "the drill-down's
 * intern list is absent rather than showing an empty list"): `listed` is false and the DOM draws no
 * list at all.
 *
 * ⚠ D2 PUBLISHES NO FIELD TABLE FOR `detail`'s OPEN-CALL LIST — § 8.2.3 names the list and stops
 * (§ 14 item 1 asks for the table). So this reads the keys the read surface actually sends and
 * renders nothing it cannot source, which is the same rule § 5.2 applies to the timeline.
 */
function internBlock(seat, detailFailed, missingDetail, sc) {
    const open = seat?.subagents_open ?? null;
    const detail = seat?.detail ?? null;

    if ((detail === null || detail === undefined) && missingDetail === WAITING) {
        return { open, sourced: false, capped: false, statement: LIST_WAITING, rows: [], listed: false, failed: false };
    }

    if (detail === null || detail === undefined) {
        // F11's fallback — the seat object's own capped array, and the sentence that says so. With
        // no failed request behind it (a body that simply carried no `detail`), the list still has
        // no uncapped source, and it says the same thing.
        const rows = (Array.isArray(seat?.subagents) ? seat.subagents : []).map((s) => ({
            call_id: s.call_id ?? null,
            label: s.title ?? UNTITLED,
            untitled: (s.title ?? null) === null,
            type: s.subagent_type ?? null,
            started_at: sc(s.started_at),
            orphan_due_at: null,
        }));

        return { open, sourced: false, capped: true, statement: LIST_NOT_SOURCED, rows, listed: rows.length > 0, failed: detailFailed };
    }

    const rows = internCalls(detail.open_calls).map((call) => ({
        // The join key, and the only thing an untitled intern has (§ 5.6, AT-D3-4's GREEN).
        call_id: call.call_id ?? null,
        label: call.title ?? UNTITLED,
        untitled: (call.title ?? null) === null,
        // § 5.6: a null `subagent_type` draws no type tag; the label is unaffected.
        type: call.subagent_type ?? null,
        // § 8: a seat-clock claim, "rendered as a labelled timestamp and never as *how long it
        // has been running*" — there is no server-clock start for a subagent on any read
        // surface, so no duration exists to render and none is invented.
        started_at: sc(call.opened_at),
        // Not a duration: the ceiling the server will orphan this call at, drawn as the instant it
        // is. It is `detail`'s own member and is rendered because an intern that is about to be
        // closed by a ceiling rather than by its own stop is a different fact from one that is
        // running.
        orphan_due_at: clockTime(call.orphan_due_at ?? null),
    }));

    return { open, sourced: true, capped: false, statement: null, rows, listed: rows.length > 0, failed: false };
}

/**
 * § 4.3's **recent activity** row and § 5.2's timeline rule: "renders, per row, only fields
 * something upstream DECLARES on a stored event" — `kind` and `event_time` (D1 § 4.3), and
 * `received_at` (D2 § 6.4's `events.received_at`, `NOT NULL`), which is the basis of the age.
 *
 * ⛔ NO PER-KIND DETAIL IS RENDERED. The response is not specified to carry a `data` member and
 * this one does not send one, so nothing is drawn from it.
 *
 * ⛔ § 9 F10: A FAILED REQUEST IS NEVER AN EMPTY WINDOW. "An empty timeline, which reads as *this
 * seat did nothing*" is that row's Never, so a failure reads F10's own words — *could not load recent
 * activity — HTTP N* — over whatever rows the earlier pages already delivered, which stay.
 */
function activityBlock(timeline, age, failure, sc) {
    const failed = failure === null ? null : timelineFailure(failure.status ?? null);

    if (timeline === null || timeline === undefined) {
        return { fetched: false, statement: failed ?? WINDOW_NOT_FETCHED, failed: failed !== null, rows: [], next_before: null };
    }

    const rows = (timeline.events ?? []).map((event) => ({
        kind: event.kind ?? null,
        event_time: sc(event.event_time),
        received_at: clockTime(event.received_at ?? null),
        // § 14 item 17's meantime clause again: the format is closed, the wording is this
        // renderer's, so the age is the bare duration under its own labelled element.
        age: age(event.received_at ?? null),
    }));

    return {
        fetched: true,
        statement: failed ?? (rows.length === 0 ? NO_ACTIVITY : null),
        failed: failed !== null,
        rows,
        // § 8.1's additive member: the cursor the SERVER issued for the next page. `null` on the
        // last page, and never derived here from a row's `received_at` — a whole batch shares one.
        next_before: timeline.next_before ?? null,
    };
}

/** § 9 F10's cell, verbatim in its form: *could not load recent activity — HTTP N*. */
export function timelineFailure(status) {
    return status === null
        ? 'could not load recent activity — no response'
        : `could not load recent activity — HTTP ${status}`;
}

/**
 * § 4.3's **transport** block — **`fetch-fresh`**, ONE *as of* stamp: "both ages, `no_data_since`,
 * `clock_skew_ms`, `spool_lag_events`, `oldest_unsent_age_s`, `seq_epoch`, `last_seq`", with § 5.2's
 * heartbeat freshness "beside the receipt age under the panel's one *as of* stamp".
 *
 * ⛔ THE RECEIPT AGE IS READ AT THE STAMP AND NEVER TICKED. `delivery.last_receipt_at` is one of the
 * ten members the feed never re-sends for its own sake (§ 2.4), so its age is the stamp — the
 * `server_time` of whatever delivered the block — minus the receipt, both SERVER clocks: a reading of
 * a moment, labelled with the moment. It moves when a new delivery moves the stamp (a poll, a whole-
 * object patch), and at no other instant (AT-D3-6's panel half). Measured from the browser's own
 * clock it would read *no data for 3h* on a machine three hours fast (AT-D3-10's panel RED); measured
 * from the ticking corrected clock it would be the live desk's forbidden ticked receipt age.
 *
 * ⛔ THE QUIET AGE BESIDE IT DOES TICK — it is version-bearing (§ 2.4), and "the two halves are not
 * symmetric there". It is the same readout the rest of the panel draws, not a second one.
 *
 * ⛔ A NULL IS NEVER A ZERO (§ 5.6, AT-D3-14's panel half): a null receipt reads *no data yet*, a null
 * spool lag, oldest-unsent age, last sequence or heartbeat reads *not reported*, a null
 * `no_data_since` or `seq_epoch` draws nothing, and a null skew is no line at all.
 */
function transportBlock(delivery, activity, nowMs, stamp) {
    const at = wireMs(stamp);
    const d = delivery ?? {};
    const receipt = d.last_receipt_at ?? null;
    const heartbeat = d.last_heartbeat_at ?? null;
    const heartbeatAge = heartbeat === null || at === null ? null : ageFrom(heartbeat, at);

    return {
        as_of: asOf(stamp),
        receipt_age: receipt === null ? NO_DATA_YET : receiptAgeAt(receipt, at),
        quiet_age: quietAgeLine(activity, nowMs),
        heartbeat: heartbeat === null ? NOT_REPORTED : heartbeatAge,
        no_data_since: clockTime(d.no_data_since ?? null),
        clock_skew: skewNote(d.clock_skew_ms ?? null),
        spool_lag_events: Number.isInteger(d.spool_lag_events) ? String(d.spool_lag_events) : NOT_REPORTED,
        oldest_unsent: typeof d.oldest_unsent_age_s === 'number' ? formatDuration(d.oldest_unsent_age_s) : NOT_REPORTED,
        seq_epoch: d.seq_epoch ?? null,
        last_seq: Number.isInteger(d.last_seq) ? String(d.last_seq) : NOT_REPORTED,
    };
}

/**
 * § 4.3's **derivation** block — **`fetch-fresh`**, one *as of* stamp: `computed_at`, `fold_lag_ms`,
 * `cursor_event_id`, "and the *this state is N behind* line on the terms § 7.4 states". § 7.4 names
 * this block as the lag line's second surface, "where the same number appears under that block's
 * stamp" — so `fold_lag_ms` is drawn AS that line, once, and the block's stamp dates it. "No delta ever
 * refreshes `derivation`" (§ 4.3), so this stamp moves on a snapshot or a fetch and on nothing else.
 */
function derivationBlock(derivation, stamp) {
    return {
        as_of: asOf(stamp),
        computed_at: clockTime(derivation?.computed_at ?? null),
        cursor_event_id: derivation?.cursor_event_id === null || derivation?.cursor_event_id === undefined
            ? null
            : String(derivation.cursor_event_id),
        lag_line: derivationLagLine(derivation?.fold_lag_ms),
    };
}

/**
 * § 4.3's **reporter** block — one *as of* stamp: `version`, `platform`, `selftest_failed` and
 * `enabled` patch live; `uptime_s` is `fetch-fresh` and is re-sent under the shallow merge whenever
 * one of the first three moves, "so the block's stamp advances with it".
 *
 * § 5.6: a null version or platform reads *not reported* — never the last one held; a null uptime
 * reads *not reported*, never `0`; a null `enabled` is "before the first heartbeat" and applies no
 * *reporting disabled* treatment at all. § 5.2: a non-empty `selftest_failed` is "a list of named
 * checks, up to its bound of 8".
 */
function reporterBlock(reporter, enabled, stamp) {
    const r = reporter ?? {};

    return {
        as_of: asOf(stamp),
        version: r.version ?? NOT_REPORTED,
        platform: r.platform ?? NOT_REPORTED,
        uptime: typeof r.uptime_s === 'number' ? formatDuration(r.uptime_s) : NOT_REPORTED,
        selftest_failed: Array.isArray(r.selftest_failed) ? r.selftest_failed.map(String) : [],
        // § 7.1's `disabled` words, the desk's own — one spelling of one fact.
        enabled: enabled === false ? LABEL.disabled : null,
    };
}

/** § 7.2's *since reporter start* framing, with the uptime — or § 5.6's words for a null uptime. */
function sinceReporterStart(reporter) {
    const uptime = reporter?.uptime_s;

    return typeof uptime === 'number'
        ? `${SINCE_REPORTER_START} — uptime ${formatDuration(uptime)}`
        : `${SINCE_REPORTER_START} — uptime ${NOT_REPORTED}`;
}

/** The heartbeat counters named in one `RAISED_BY` cell, as `{name, value}`, in name order. */
function raisingCounters(badge, heartbeatCounters) {
    const names = RAISED_BY[badge] ?? [];
    const matches = (counter) => names.some((name) => {
        const family = name.match(/^([^<]+\.)<[^>]+>$/);

        return family === null ? counter === name : counter.startsWith(family[1]);
    });

    return Object.entries(heartbeatCounters ?? {})
        .filter(([name]) => matches(name))
        .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
        .map(([name, value]) => ({ name, value: String(value) }));
}

/** The sum of a set of counter values, or `null` when there are none to sum. */
function sumOf(rows) {
    return rows.length === 0 ? null : rows.reduce((total, row) => total + Number(row.value), 0);
}

/**
 * § 7.2's `N`, for the five lines that carry one — each from the one surface that carries it, and
 * `null` where no read surface does, which the line then says rather than filling in.
 */
function badgeCount(badge, seat, raising) {
    const counters = seat?.detail?.counters ?? null;

    switch (badge) {
        case 'lossy':
            return sumOf(raising);
        case 'batches_rejected':
            // § 7.2: "the count read from `detail`'s `seat_counters` rows for `batches_refused.<error>`".
            return counters === null ? null : sumOf(Object.entries(counters)
                .filter(([name]) => name.startsWith('batches_refused.'))
                .map(([, value]) => ({ value })));
        case 'seq_gap':
            return Number.isInteger(counters?.seq_gap) ? counters.seq_gap : null;
        case 'clock_skew': {
            const ms = seat?.delivery?.clock_skew_ms;

            return typeof ms === 'number' ? `${ms > 0 ? '+' : ''}${ms / 1000}` : null;
        }
        case 'fold_lag': {
            const ms = seat?.derivation?.fold_lag_ms;

            return typeof ms === 'number' ? formatDuration(ms / 1000) : null;
        }
        default:
            // `counters_omitted`'s N rides the heartbeat event as `counters_omitted` (D1 § 9.3) and
            // is on no read surface D2 publishes, so it is not reported.
            return null;
    }
}

/**
 * § 4.3's **badges** row: "every member of `badges[]`, each with its meaning and its counter value
 * from `detail`, *since reporter start* framing for D1's array, and ONE cluster-scoped *oldest badge
 * since HH:MM* line — `badges_since` is the minimum over the present members and is never stamped on
 * an individual badge" (§ 7.2).
 *
 * ⛔ D1's TWELVE ARE *SINCE REPORTER START*, NEVER *NOW* (§ 7.2, D2 § 7.3): each carries the counters
 * that raised it and the reporter's uptime, "so *lossy, 1 event, 41 days of uptime* is never read as
 * *lossy now*". D2's seven are current conditions and are drawn as such. `epoch_reset` is in both
 * (§ 7.2), so its line says which side observed it.
 *
 * ⛔ AN UNRECOGNISED BADGE CARRIES ITS RAW STRING AND SAYS SO (§ 5.4, § 9 F9), never the nearest line.
 * ⛔ WITH NO `detail` (§ 9 F11) the counter values are *unavailable*, never zero.
 */
function badgeBlock(seat, detailFailed, missingDetail) {
    const badges = Array.isArray(seat?.badges) ? seat.badges : [];
    const heartbeatCounters = seat?.detail?.heartbeat_counters ?? null;
    const noDetail = (seat?.detail ?? null) === null;
    const since = clockTime(seat?.badges_since ?? null);

    const rows = badges.map((badge) => {
        if (!BADGES.has(badge) || !Object.hasOwn(BADGE_LINE, badge)) {
            return { badge: String(badge), recognised: false, origin: null, line: `${badge} (${UNRECOGNISED})`, counters: null, since_reporter_start: null };
        }

        const { origin, line } = BADGE_LINE[badge];
        const fromReporter = origin === 'D1' || origin === 'both';
        const raising = fromReporter && !noDetail ? raisingCounters(badge, heartbeatCounters) : [];
        const count = noDetail && badge !== 'clock_skew' && badge !== 'fold_lag' ? null : badgeCount(badge, seat, raising);
        const filled = line.replace(/\bN\b/, count === null ? (noDetail ? missingDetail : NOT_REPORTED) : String(count));

        return {
            badge,
            recognised: true,
            origin,
            line: badge === 'epoch_reset' ? `${filled} — ${epochObservers(seat, missingDetail)}` : filled,
            counters: !fromReporter ? null : (noDetail ? missingDetail : (heartbeatCounters === null ? NOT_REPORTED : raising)),
            since_reporter_start: origin === 'D1' ? sinceReporterStart(seat?.reporter ?? null) : null,
        };
    });

    return {
        present: rows.length > 0,
        rows,
        // § 7.2: "one line for the whole cluster, not a stamp per badge" — and none with no badges
        // (§ 5.6: `badges_since` is null exactly when `badges` is empty).
        since: since === null ? null : `oldest badge since ${since}`,
        unavailable: noDetail && detailFailed,
    };
}

/**
 * § 7.2's `epoch_reset` row: "the drill-down says which side observed it, because D1's reporter and
 * D2's server raise it independently" — the reporter by its `state_reset` counter (D1 § 9.3), the
 * server by its `seq_epoch_change` counter (D2 § 7.1). ⚠ The words are not ratified.
 */
function epochObservers(seat, missingDetail) {
    const reporter = Number(seat?.detail?.heartbeat_counters?.state_reset ?? 0) > 0;
    const server = Number(seat?.detail?.counters?.seq_epoch_change ?? 0) > 0;

    if ((seat?.detail ?? null) === null) {
        return `observed by: ${missingDetail}`;
    }

    if (reporter && server) {
        return 'observed by the reporter and by the server';
    }

    if (reporter || server) {
        return `observed by the ${reporter ? 'reporter' : 'server'}`;
    }

    return `observed by: ${NOT_REPORTED}`;
}

/**
 * § 4.3's **session** block: `session_id`, start (seat clock), `source`, `project_label`,
 * `harness_label`, `model_label`. § 5.2: a null `session` is *no session open*, "a fact, not a blank".
 * § 5.6, member by member: a null start draws no start line — "never the seat's first-seen time"; a
 * null source is no source tag — "never defaulted to `startup`"; a null project or harness label omits
 * the line — never `install_id` or `reporter.version` in its place; a null model label is omitted.
 */
function sessionBlock(session, modelLabel, sc) {
    if (session === null) {
        return { present: false, statement: NO_SESSION_OPEN, model_label: modelLabel };
    }

    return {
        present: true,
        statement: null,
        session_id: session.session_id ?? null,
        started_at: sc(session.started_at),
        source: session.source ?? null,
        project_label: session.project_label ?? null,
        harness_label: session.harness_label ?? null,
        model_label: modelLabel,
    };
}

/**
 * § 5.2's **counters** row: "`detail`'s `seat_counters` rows and the reporter's `heartbeat_counters` /
 * `heartbeat_predicates` snapshots — **`fetch-fresh`** by construction, `detail` exists only on the
 * fetch … The reporter's are labelled **since reporter start** with `reporter.uptime_s` beside them —
 * never as *now*". Stamped with the detail response's own `server_time`.
 *
 * ⛔ A NULL SNAPSHOT IS *not reported*, AND NO `detail` AT ALL IS F11's *unavailable* — never a column
 * of zeros, which would say nothing has happened.
 */
function countersBlock(seat, detailFailed, missingDetail, stamp) {
    const detail = seat?.detail ?? null;

    if (detail === null) {
        return { available: false, statement: missingDetail, as_of: null, server: [], reporter: null, predicates: null, failed: detailFailed };
    }

    const rows = (object) => (object === null || object === undefined
        ? NOT_REPORTED
        : Object.entries(object)
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([name, value]) => ({ name, value: typeof value === 'object' ? JSON.stringify(value) : String(value) })));

    return {
        available: true,
        statement: null,
        as_of: asOf(stamp),
        server: rows(detail.counters ?? {}),
        reporter: { since: sinceReporterStart(seat?.reporter ?? null), rows: rows(detail.heartbeat_counters) },
        predicates: rows(detail.heartbeat_predicates),
        failed: false,
    };
}

/**
 * § 4.3's **raw** block: `state_version` and the applied `seq_epoch` / `last_seq`, "so a rendered
 * state can be correlated with the wire". `last_seq` is `fetch-fresh` under the transport block's
 * stamp (§ 4.3), which is why it is read from the same delivered `delivery` object.
 */
function rawBlock(seat) {
    return {
        state_version: Number.isInteger(seat?.state_version) ? String(seat.state_version) : null,
        seq_epoch: seat?.delivery?.seq_epoch ?? null,
        last_seq: Number.isInteger(seat?.delivery?.last_seq) ? String(seat.delivery.last_seq) : NOT_REPORTED,
    };
}

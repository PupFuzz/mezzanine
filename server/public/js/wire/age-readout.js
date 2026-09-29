/**
 * Every AGE READOUT, over the clock offset the client protocol holds — `docs/design/FLOOR.md`
 * § 2.4, Appendix B row 4, card#7341 step 4. Gated by AT-D3-10's floor half.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY AGE IS THE CORRECTED CLOCK MINUS A SERVER-CLOCK INSTANT, AND NOTHING ELSE. The corrected
 * clock is `browser_now + clock_offset_ms` (§ 2.4), where the offset is the one
 * `wire/fleet-client.js` holds from the last `server_time` it saw. The browser's own clock is
 * admitted on this page at exactly one place, the floor's wall clock (§ 6.2 A17), and it is not
 * here: a viewer whose machine is three hours fast would otherwise read *nothing done for 3h* on
 * every desk of a fleet that is reporting normally (AT-D3-10's RED). No clock is read in this
 * file — the browser's reading is an argument, which is also what lets the harness replay a
 * skewed browser at all.
 *
 * ⛔ A SEAT-CLOCK INSTANT IS NEVER AN AGE. `action.started_at`, `context.sampled_at`,
 * `activity.last_event_time`, `session.started_at`, `blocked_since` and `subagents[].started_at`
 * are the seat's own claims (§ 2.4's seat-clock bullet); they are drawn as labelled timestamps
 * and subtracted from nothing. Subtracting one from the corrected clock measures the skew between
 * two machines and calls it work — a seat whose clock runs ten minutes fast would show a call that
 * started in the future (AT-D3-10's second RED).
 *
 * ⛔ ONE STRING PER FACT, WRITTEN HERE AND NOWHERE ELSE. § 2.4's wording table fixes the words
 * three of these ages are spoken in, and both the desk and the drill-down draw them, so the words
 * live in `wire/` beside the format — the panel imports them rather than spelling them a second
 * time (hoisted from `drilldown/drilldown-model.js` at this, their second caller).
 * `Tests\Feature\Floor\TheAgeReadoutReadsTheServerClockTest` re-derives each wording from
 * § 2.4's table on every run, so a word edited here or there reds.
 *
 * ⚠ WHAT IS NOT HERE, named rather than left as a silence. § 5.3's sweep age and ingest-recency
 * age are fleet readouts with no published wording (§ 14 item 17), which the lobby draws as
 * labelled timestamps, and the floor's status strip (`floor/status-strip.js`, step 8) draws them the
 * same way, through the lobby's own `indicators()`. The derivation lag is
 * `fetch-fresh` and never ticked (§ 7.4), so it is no 1 s readout — its WORDING is here
 * (`derivationLagLine`), and so is the receipt age's for both its surfaces (`receiptAgeAt`): the
 * drill-down reads each at its block's *as of* stamp rather than at the ticking clock (Appendix B
 * step 10), and one fact keeps one string.
 */

import { clockTime } from './clock.js';
import { ageFrom, correctedNowMs, formatDuration } from './duration.js';

/**
 * § 2.4: "Ages re-render every 1 s, which is the unit the smallest age is rendered in" — § 12's
 * *Age readout refresh*. The one definition the ticker below reads.
 */
export const AGE_REFRESH_MS = 1000;

/**
 * § 5.6, `activity.last_received_at`: the never-reported seat reads *nothing done yet* — "never
 * *nothing done for 0s*, which would claim a measurement at this instant".
 */
export const NOTHING_DONE_YET = 'nothing done yet';

/**
 * The label every seat-clock claim carries (§ 2.4's seat-clock bullet: "rendered **as the seat's
 * own claim**, labelled *seat clock*"). One spelling, read by `seatClock` below and by the one form
 * that puts the label inside a parenthetical of its own — § 7.6's *was:* currency label, *(last
 * event 12:47, seat clock)* — so the two forms cannot label one claim two ways.
 */
export const SEAT_CLOCK = 'seat clock';

/**
 * A seat-clock instant, LABELLED as one: `HH:MM:SS (seat clock)`, from the wire's own digits
 * (`wire/clock.js`). `null` in, `null` out — the caller draws the member's own absence.
 *
 * ⭐ THE PRECISION IS `HH:MM:SS`, ON EVERY SURFACE, AND THAT WAS DECIDED RATHER THAN INHERITED
 * (card#7341 step 5, the builder's call per comment 6246 item 3). FLOOR's worked instances write a
 * seat-clock claim as `HH:MM` — *since 14:31 (seat clock)*, *(last event 12:47, seat clock)* —
 * and § 7.1 is explicit that a worked instance is never a rule. Every timestamp form FLOOR DOES
 * publish as a rule carries seconds — § 2.4's *as of HH:MM:SS* stamp, § 4.1's *membership as of
 * HH:MM:SS*, § 6.2 A14's *last message HH:MM:SS*, § 9's *not live since HH:MM:SS* — and the desk
 * itself draws one of them beside these claims (§ 7.4's lag line). A desk that wrote its seat-clock
 * claims in minutes beside a stamp in seconds would render one kind of fact at two precisions, and
 * truncating a seat's claim to the minute throws away the digits the wire sent for no reader's
 * benefit. So one primitive, `wire/clock.js`'s `clockTime`, and one precision.
 */
export function seatClock(wireTime) {
    const at = clockTime(wireTime);

    return at === null ? null : `${at} (${SEAT_CLOCK})`;
}

/**
 * § 2.4's **quiet age**, verbatim — *nothing done for 4m 12s* — from `activity.last_received_at`.
 *
 * A null basis is § 5.6's *nothing done yet*, whatever the clock. A basis with no corrected clock
 * (`nowMs` null) draws NO line: the null-basis sentence would claim the seat never reported, which
 * is a claim about the seat, and the missing clock is the client's own state.
 */
export function quietAgeLine(activity, nowMs) {
    if ((activity?.last_received_at ?? null) === null) {
        return NOTHING_DONE_YET;
    }

    const quiet = nowMs === null ? null : ageFrom(activity.last_received_at, nowMs);

    return quiet === null ? null : `nothing done for ${quiet}`;
}

/**
 * § 2.4's **action elapsed**, verbatim — *running for 2m 05s* — from `action.started_received_at`.
 * "Both ends are the server clock, which is what makes it the one honest duration over an
 * action"; `action.started_at` is drawn beside it by `seatClock` and subtracted from nothing.
 * `null` when no call is open (§ 5.6: "never a stale last action") or no corrected clock exists.
 */
export function actionElapsedLine(action, nowMs) {
    const elapsed = action === null || nowMs === null
        ? null
        : ageFrom(action.started_received_at ?? null, nowMs);

    return elapsed === null ? null : `running for ${elapsed}`;
}

/**
 * § 2.4's **receipt age** on the DESK — *no data for 11m*, from `delivery.last_receipt_at` —
 * under the `dark-only` marker: on a `stale` or `offline` seat, "on no other desk and in no other
 * state", ticking. A `live` desk renders no receipt age at all, because the feed never re-sends
 * the member and a value ticked from a held copy would draw a healthy seat dark.
 *
 * `null` on every other desk, on a null basis (§ 5.6: the dark desk's age "has nothing to draw
 * and is not drawn"), and with no corrected clock.
 */
export function receiptAgeLine(seat, nowMs) {
    const dark = seat.link_state === 'stale' || seat.link_state === 'offline';

    return !dark ? null : receiptAgeAt(seat.delivery?.last_receipt_at ?? null, nowMs);
}

/**
 * § 2.4's **receipt age** wording — *no data for 11m* — measured at the instant `atMs`: the ONE
 * spelling of it, which the desk's `dark-only` readout above ticks at the corrected clock and the
 * drill-down's transport block reads at its own *as of* stamp, never ticked (`fetch-fresh`,
 * Appendix B step 10). `null` on a null basis or a null instant; the caller draws that member's
 * absence (§ 5.6).
 */
export function receiptAgeAt(lastReceiptAt, atMs) {
    const age = atMs === null ? null : ageFrom(lastReceiptAt, atMs);

    return age === null ? null : `no data for ${age}`;
}

/**
 * § 2.4's **derivation lag** wording — *this state is 1m 57s behind* — over `derivation.fold_lag_ms`,
 * a duration D2 computes and sends, so this only formats it. The ONE spelling: § 7.4's lag line on the
 * desk puts its inline stamp after it, and the drill-down's derivation block draws it under that
 * block's stamp. `null` for a value that is not a number.
 */
export function derivationLagLine(foldLagMs) {
    return typeof foldLagMs === 'number' && Number.isFinite(foldLagMs)
        ? `this state is ${formatDuration(foldLagMs / 1000)} behind`
        : null;
}

/**
 * One desk's age readouts at the corrected instant `nowMs` (or `null`: no corrected clock yet).
 *
 * `context_age` is the gauge's own age (§ 5.1, "the gauge's numerals and its own age"), from the
 * server-clock `context.sampled_received_at`. § 14 item 17 leaves its WORDING to the renderer, so
 * it is the bare duration and no sentence — the same form the drill-down draws it in.
 */
export function deskAgeReadout(seat, nowMs) {
    const context = seat.context ?? null;

    return {
        quiet_age: quietAgeLine(seat.activity ?? null, nowMs),
        action_elapsed: actionElapsedLine(seat.action ?? null, nowMs),
        receipt_age: receiptAgeLine(seat, nowMs),
        context_age: context === null || nowMs === null ? null : ageFrom(context.sampled_received_at ?? null, nowMs),
        seat_clock: {
            action_started_at: seatClock(seat.action?.started_at ?? null),
            activity_last_event_time: seatClock(seat.activity?.last_event_time ?? null),
            context_sampled_at: seatClock(context?.sampled_at ?? null),
            session_started_at: seatClock(seat.session?.started_at ?? null),
            blocked_since: seatClock(seat.blocked_since ?? null),
            subagents: (seat.subagents ?? []).map((s) => ({
                call_id: s.call_id ?? null,
                started_at: seatClock(s.started_at ?? null),
            })),
        },
    };
}

/**
 * Every desk's readouts, from the client protocol's held seats and its clock offset.
 *
 * @param {Map<string, object>} seats   `FleetClient#seats` — key → the held seat object
 * @param {number|null} offsetMs        `FleetClient#clockOffsetMs`
 * @param {number} browserNowMs         the browser's own clock, read by the CALLER
 * @return {{now_ms: number|null, desks: Object<string, object>}}
 */
export function floorAgeReadouts(seats, offsetMs, browserNowMs) {
    const nowMs = correctedNowMs(offsetMs, browserNowMs);

    return {
        now_ms: nowMs,
        desks: Object.fromEntries([...seats].map(([key, seat]) => [key, deskAgeReadout(seat, nowMs)])),
    };
}

/**
 * § 2.5's *1 s tick*: "every age readout, and nothing else". Renders once at once, then every
 * `AGE_REFRESH_MS`, until `stop()`.
 *
 * ⛔ THE TIMER AND THE CLOCK ARE INJECTED. A page passes the browser's own timer functions and
 * clock; the harness passes its scenario clock, which is what lets a replay put the browser three hours
 * ahead of the server and read what every desk says.
 *
 * ⛔ NO ANIMATION MAY BE DRIVEN BY IT (§ 6.3), and in particular not the wall clock, which advances
 * on `feed.heartbeat` and on nothing else (§ 6.2 A17). `render` receives ages and nothing else.
 *
 * @param {{seats: Map, clockOffsetMs: number|null}} source  a `FleetClient`
 * @param {{now: function(): number}} clock                  the browser's own clock
 * @param {{setInterval: Function, clearInterval: Function}} timers
 * @param {function(object): void} render                    receives `floorAgeReadouts(...)`
 */
export function startAgeTicker(source, clock, timers, render) {
    const tick = () => render(floorAgeReadouts(source.seats, source.clockOffsetMs, clock.now()));

    tick();

    const handle = timers.setInterval(tick, AGE_REFRESH_MS);

    return { stop: () => timers.clearInterval(handle) };
}

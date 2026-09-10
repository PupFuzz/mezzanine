/**
 * The desk drill-down panel's model — `docs/design/FLOOR.md § 4.3`'s table, § 5.2's rules, § 8's
 * intern join, and § 5.6's null render for every nullable member it touches.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY DECISION THIS PANEL MAKES IS IN THIS FILE, AND `main.js` MAKES NONE. There is no
 * browser on the build host, so the DOM half is exercised by nothing; keeping every string,
 * every absence render and every selection here is what keeps the unverifiable half free of
 * judgement. If a rule appears in `main.js` that is not here, it is in the wrong file.
 *
 * ⛔ IT RENDERS WHAT THE WIRE CARRIES AND NOTHING ELSE. Every value below names a member of
 * `docs/design/FLEET-STATE.md § 8.2.1` (the seat object) or of § 8.2.3's `detail` / § 8.2's
 * timeline response. § 5.4: "a rendered fact with no field is a fact the client invented."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT § 4.3 ASKS FOR THAT THIS SLICE DOES NOT DRAW, named here rather than left to be
 * discovered as a silence. None of it is an oversight and none of it is buildable-and-skipped:
 *
 *   · the header's PLAIN-LANGUAGE STATE LINE and the currency label (§ 7.1, § 7.3). Both are
 *     the DESK's render tables, built with the desk; this panel carries the `render_state`
 *     member itself, membership-tested per § 5.4, and draws no sentence it would then own a
 *     second copy of.
 *   · the TRANSPORT, DERIVATION, REPORTER, BADGES, SESSION, RETIREMENT and RAW blocks. Each is
 *     `fetch-fresh` and owes § 2.4's *as of* stamp, and the badge block additionally needs
 *     § 7.2's 18 members with a sentence each — a published member set that needs its own
 *     document-derived guard, as `lobby/render-state.js` has. They are a slice, not a line.
 *     ⛔ This is why NO *as of* stamp is drawn anywhere below: the stamp is owed by a
 *     `fetch-fresh` value, this slice renders none of the ten, and a stamp over values that do
 *     not need one would be a marker with nothing behind it.
 *   · LIVE PATCHING while the panel is open (§ 4.3). No delta-feed client exists in this
 *     repository yet — `lobby/main.js` says so of its own screen — so there is nothing to patch
 *     from. The model is pure over one fetch pair and takes the corrected clock as an argument,
 *     which is what a patching caller will need anyway.
 *   · the SIDE TABLE's stools and its *+N more* tag (§ 8, Appendix B step 5). That artifact is
 *     the DESK's, from the seat object's capped `subagents[]`, and there is no desk: the floor
 *     screen is card#9208-blocked on a D2 read surface for an authored map. What this panel owes
 *     § 8 is the UNCAPPED list, which is below and is a different artifact from a different
 *     source. The count beside it is `subagents_open` — the wire's — and never the list's length
 *     (§ 2.1's forbidden computations, AT-D3-4's second RED).
 *
 * ⚠ TWO AGES ARE RENDERED IN A WORDING THIS DOCUMENT HAS NOT CHOSEN, and § 14 item 17 is what
 * permits it: the context sample's age and the timeline row's age are named there as sites whose
 * FORMAT is closed and whose WORDING is "the renderer's" in the meantime. So each is drawn as
 * the bare output of § 2.4's function beside its own labelled element, and no sentence is minted
 * around it — a bare duration under a label is the smallest thing that can be right, and the
 * smallest thing to retire when that item closes. The two ages that DO have a published wording
 * take it verbatim: *running for 2m 05s* (§ 2.4's action elapsed) and *nothing done for 4m 12s*
 * (the quiet age), and neither is spelled a second way here.
 */

import { clockTime } from '../wire/clock.js';
import { ageFrom } from '../wire/duration.js';
import { isRenderState } from '../lobby/render-state.js';

/**
 * § 5.6's default for a member whose element space is drawn unconditionally. ⛔ It is never a
 * zero: "a zero is a measurement and a null is the absence of one" (§ 7.5).
 */
export const NOT_REPORTED = 'not reported';

/**
 * § 8 / § 5.6 for a `subagents[].title` of `null` — "the honest orphan D1 § 6.8 and D2 § 8.2.1
 * both refuse to paper over". ⛔ NEVER the `subagent_type`, the tool name or the word
 * *subagent*: that is AT-D3-4's first RED, "a label for a spawn event that was never received".
 */
export const UNTITLED = 'untitled';

/** § 5.2, verbatim: an empty timeline window is a fact, not an empty panel. */
export const NO_ACTIVITY = 'no activity in this window';

/** § 4.3, verbatim, when `task.degraded` — a better tier's value was dropped past its bound. */
export const STALE_TITLE_DROPPED = 'stale title dropped';

/** § 5.4 / AT-D3-11: an unrecognised member renders as unrecognised, carrying the raw string. */
export const UNRECOGNISED = 'unrecognised';

/** § 5.5: the client narrating its own request outcome, never a seat's fact. */
export const WINDOW_NOT_FETCHED = 'the recent-activity window has not been fetched';

/** § 5.5, likewise: this response carried no `detail`, so the uncapped list has no source. */
export const LIST_NOT_SOURCED = 'this response carries no detail member — the uncapped list has no source';

/** § 2.4: with no corrected clock there is no honest age, and the panel says so rather than tick. */
export const NO_CORRECTED_CLOCK = 'ages are not shown — this response carried no server clock';

/**
 * A seat-clock instant, LABELLED as one — § 2.4: "a timestamp is not a duration", and every
 * seat-clock value on this page is a narrative claim by the seat rather than something the
 * server measured. `null` in, `null` out; the caller renders the member's own absence.
 */
function seatClock(wireTime) {
    const at = clockTime(wireTime);

    return at === null ? null : `${at} (seat clock)`;
}

/**
 * § 5.2's task-reference rule: a link "**only** when a base URL is configured for that reference
 * shape … with no configured base it renders as plain text. A guessed URL is a link that goes
 * somewhere wrong, which is worse than no link".
 *
 * The two shapes are D2 § 4.9's — `card#N` (tier 1) and `<repo>#N` (tier 2) — and a `ref` of any
 * other shape gets no link at all rather than being forced into the nearer of the two.
 *
 * ⚠ NOTHING IN THIS DEPLOYMENT CONFIGURES A BASE, so today this returns `null` for every ref it
 * is given. § 14 item 3 is the open question that would supply one; until it answers, the
 * absence of a base is the reason there is no link, and it is not a defect in this function.
 */
export function taskRefLink(ref, bases) {
    if (typeof ref !== 'string') {
        return null;
    }

    const configured = bases ?? {};
    const card = ref.match(/^card#(\d+)$/);

    if (card !== null) {
        return typeof configured.card === 'string'
            ? configured.card.replace('{id}', card[1])
            : null;
    }

    const repo = ref.match(/^([A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)?)#(\d+)$/);

    if (repo !== null) {
        return typeof configured.repo === 'string'
            ? configured.repo.replace('{repo}', repo[1]).replace('{id}', repo[2])
            : null;
    }

    return null;
}

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
 * § 5.4's membership test, applied to the one enum member this panel renders.
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

/**
 * The panel, from the two requests § 4.3 puts on its open.
 *
 * @param seat      `GET /api/fleet/seats/{install}/{seat}` — the seat object plus `detail`
 * @param timeline  `GET …/timeline?limit=50`'s body, or `null` when it has not been fetched
 * @param options   `{ now_ms }` — the CORRECTED clock (§ 2.4), and `{ ref_bases }` (§ 5.2)
 */
export function drillDownModel(seat, timeline, options = {}) {
    const now = options.now_ms;
    const ages = typeof now === 'number' && Number.isFinite(now);
    const age = (wireTime) => (ages ? ageFrom(wireTime, now) : null);

    return {
        // § 3.1: `(install_id, seat_id)` is the identity and the whole of it. Never null.
        seat: { seat_id: seat?.seat_id ?? null, install_id: seat?.install_id ?? null },
        render_state: member(seat?.render_state),
        ages_available: ages,
        // § 9's rule for every failure path: the viewer SEES it. A panel that quietly dropped
        // every age because one response arrived without a server clock would look like a seat
        // with nothing to report, which is the confusion this whole product exists to prevent.
        no_clock_statement: ages ? null : NO_CORRECTED_CLOCK,
        task: taskBlock(seat?.task ?? null, options.ref_bases),
        action: actionBlock(seat?.action ?? null, age),
        quiet_age: quietAge(seat?.activity ?? null, age),
        context: contextBlock(seat?.context ?? null, age),
        interns: internBlock(seat, age),
        activity: activityBlock(timeline, age),
    };
}

/**
 * § 4.3's **current task** row: `task.title`, the tier that answered (`task.source`), the
 * reference as a link when a base is configured for its shape, and *stale title dropped* when
 * `task.degraded`.
 *
 * ⚠ `task.ref` IS NULL ON EVERY SEAT THIS DEPLOYMENT SERVES, and that is D2's state rather than
 * this panel's: D2 § 4.9 builds tier 3 (telemetry, `ref = null`) and leaves tiers 1 and 2 as
 * "the stated columns they populate" — tier 1's producer is designed in no document here, and
 * tier 2's join is unestablished (card#7957). So the reference line and the link are code that
 * runs on a value nothing currently mints, which is why `task.source` is rendered beside the
 * title: "a floor showing tier 3 everywhere is visibly a floor whose board integration is dark".
 */
function taskBlock(task, refBases) {
    if (task === null) {
        return { present: false, statement: NOT_REPORTED };
    }

    const ref = task.ref ?? null;

    return {
        present: true,
        title: task.title ?? null,
        source: task.source ?? null,
        // § 5.6, `task.ref`: "the title renders with NO LINK AND NO REFERENCE TEXT — not an
        // empty link, not *(no reference)*".
        ref,
        ref_href: taskRefLink(ref, refBases),
        as_of: clockTime(task.as_of ?? null),
        degraded_note: task.degraded === true ? STALE_TITLE_DROPPED : null,
    };
}

/**
 * § 4.3's **current action** row. The elapsed time is § 2.4's action elapsed, verbatim, and it
 * is computed from `started_received_at` — the SERVER clock — because "both ends are the server
 * clock, which is what makes it the one honest duration over an action". `started_at` is the
 * seat's own claim and is rendered beside it as a labelled timestamp, subtracted from nothing.
 */
function actionBlock(action, age) {
    if (action === null) {
        // § 5.6, `action`: no monitor content, "never a stale last action".
        return { present: false, statement: NOT_REPORTED };
    }

    const elapsed = age(action.started_received_at ?? null);

    return {
        present: true,
        call_id: action.call_id ?? null,
        tool_name: action.tool_name ?? null,
        // § 5.6: a null descriptor shows `tool_name` alone — "never a descriptor synthesized
        // from the tool name".
        descriptor: action.descriptor ?? null,
        started_at: seatClock(action.started_at ?? null),
        elapsed: elapsed === null ? null : `running for ${elapsed}`,
        // § 5.1: labels, stored for the intern join, and nothing on this page gates on them.
        agent_scope: action.agent_scope ?? null,
        parent_call_id: action.parent_call_id ?? null,
    };
}

/**
 * § 2.4's **quiet age**, verbatim: *nothing done for 4m 12s*, from `activity.last_received_at`.
 * § 5.6 gives its null the sentence *nothing done yet* — "never *nothing done for 0s*, which
 * would claim a measurement at this instant" — and that is the reachable never-reported seat.
 */
function quietAge(activity, age) {
    const quiet = age(activity?.last_received_at ?? null);

    return {
        line: quiet === null ? 'nothing done yet' : `nothing done for ${quiet}`,
        last_kind: activity?.last_kind ?? null,
        last_event_time: seatClock(activity?.last_event_time ?? null),
    };
}

/**
 * § 4.3's **context gauge**: the bar, the percentage to one decimal, `used_tokens /
 * total_tokens` when non-null, the sample's own age, and `context.source`.
 *
 * ⛔ A NULL `context` READS *not reported* AND DRAWS NO BAR — not a bar at 0 % (§ 5.6, § 7.5,
 * AT-D3-14). `bar` is `null` rather than `0` for exactly that reason: a zero here is the one
 * defect this gauge is famous for.
 *
 * ⛔ NO PERCENTAGE IS RECOMPUTED FROM THE TOKEN PAIR (§ 5.6, `context.total_tokens`), and the
 * bar still renders when the numerals are null, because `used_pct` is not nullable.
 */
function contextBlock(context, age) {
    // ⛔ THE SECOND CONDITION IS BOUNDARY VALIDATION, NOT A DEFENCE AGAINST A STATE THAT CANNOT
    // HAPPEN. D2 § 8.2.1 declares `used_pct` NOT nullable, so an object arriving without a
    // readable one is a malformed wire object rather than a seat state — and the one thing this
    // gauge may never do is turn that into a bar at 0 %, which is exactly what `Number(null)`
    // would produce two lines below. A percentage the wire did not send is a percentage this
    // panel does not report, on the same terms as a sample that was never taken.
    const pct = typeof context?.used_pct === 'number' && Number.isFinite(context.used_pct)
        ? context.used_pct
        : null;

    if (context === null || pct === null) {
        return { reported: false, statement: NOT_REPORTED, bar: null, pct: null };
    }

    const used = context.used_tokens ?? null;
    const total = context.total_tokens ?? null;

    return {
        reported: true,
        bar: pct,
        pct: `${pct.toFixed(1)} %`,
        numerals: used === null || total === null ? NOT_REPORTED : `${used} / ${total}`,
        // D1 § 6.11 / § 4.3: `harness` or `computed`, never mixed and never averaged.
        source: context.source ?? null,
        sampled_at: seatClock(context.sampled_at ?? null),
        // The sample's own age, from the SERVER-clock receipt. § 14 item 17's meantime clause
        // owns the wording, which is why this is the bare duration and no sentence.
        age: age(context.sampled_received_at ?? null),
    };
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
 * ⚠ D2 PUBLISHES NO FIELD TABLE FOR `detail`'s OPEN-CALL LIST — § 8.2.3 names the list and stops
 * (§ 14 item 1 asks for the table). So this reads the keys the read surface actually sends and
 * renders nothing it cannot source, which is the same rule § 5.2 applies to the timeline.
 */
function internBlock(seat, age) {
    const open = seat?.subagents_open ?? null;
    const detail = seat?.detail ?? null;

    if (detail === null || detail === undefined) {
        return { open, sourced: false, statement: LIST_NOT_SOURCED, rows: [] };
    }

    return {
        open,
        sourced: true,
        statement: null,
        rows: internCalls(detail.open_calls).map((call) => ({
            // The join key, and the only thing an untitled intern has (§ 5.6, AT-D3-4's GREEN).
            call_id: call.call_id ?? null,
            label: call.title ?? UNTITLED,
            untitled: (call.title ?? null) === null,
            // § 5.6: a null `subagent_type` draws no type tag; the label is unaffected.
            type: call.subagent_type ?? null,
            // § 8: a seat-clock claim, "rendered as a labelled timestamp and never as *how long
            // it has been running*" — there is no server-clock start for a subagent on any read
            // surface, so no duration exists to render and none is invented.
            started_at: seatClock(call.opened_at ?? null),
            // Not a duration: the ceiling the server will orphan this call at, drawn as the
            // instant it is. It is `detail`'s own member and is rendered because an intern that
            // is about to be closed by a ceiling rather than by its own stop is a different fact
            // from one that is running.
            orphan_due_at: clockTime(call.orphan_due_at ?? null),
        })),
    };
}

/**
 * § 4.3's **recent activity** row and § 5.2's timeline rule: "renders, per row, only fields
 * something upstream DECLARES on a stored event" — `kind` and `event_time` (D1 § 4.3), and
 * `received_at` (D2 § 6.4's `events.received_at`, `NOT NULL`), which is the basis of the age.
 *
 * ⛔ NO PER-KIND DETAIL IS RENDERED. The response is not specified to carry a `data` member and
 * this one does not send one, so nothing is drawn from it.
 */
function activityBlock(timeline, age) {
    if (timeline === null || timeline === undefined) {
        return { fetched: false, statement: WINDOW_NOT_FETCHED, rows: [], next_before: null };
    }

    const rows = (timeline.events ?? []).map((event) => ({
        kind: event.kind ?? null,
        event_time: seatClock(event.event_time ?? null),
        received_at: clockTime(event.received_at ?? null),
        // § 14 item 17's meantime clause again: the format is closed, the wording is this
        // renderer's, so the age is the bare duration under its own labelled element.
        age: age(event.received_at ?? null),
    }));

    return {
        fetched: true,
        statement: rows.length === 0 ? NO_ACTIVITY : null,
        rows,
        // § 8.1's additive member: the cursor the SERVER issued for the next page. `null` on the
        // last page, and never derived here from a row's `received_at` — a whole batch shares one.
        next_before: timeline.next_before ?? null,
    };
}

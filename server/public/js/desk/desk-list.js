/**
 * THE LIST VIEW — `docs/design/FLOOR.md` Appendix B row 15 (slice A) and § 4.5's second rule: one
 * seat as lines of text, every fact `desk/desk-render.js`'s `deskModel()` emits, painted below the
 * room drawing at every window size — beside the drawing, never in its place (§ 4.5's first rule).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS IS A HEADLESS MODEL AND NOT A PAGE FUNCTION. Row 8's text render was `floor/main.js`'s
 * `deskLine()`, which carried the nameplate, the glyph, the label line, the quiet age and the badge and
 * unrecognised lists and dropped the rest — a `fold_lag` seat on it was marked only by its raw badge id,
 * § 7.4's lag line and § 7.3's note missing — and it lived in a DOM entry no harness can load. It is
 * deleted; the page paints the lines this returns and composes none.
 *
 * ⛔ EVERY STRING IS THE MODEL's, AS THE MODEL DECIDED IT. What this file decides is the text form of
 * the members that are NOT already a string — the gauge, the side table and its *+N more*, the
 * bubble, the open-call count, the unconfirmed flag, whether the held render moves and the
 * subagent's-call marker — and it reads out the strings the model carries inside an object (the lag
 * line, the monitor's text, the action's start and elapsed). The worded numbers — the open-call count
 * and *+N more* — are `floor/desk-layout.js`'s `OPEN_CALLS` and `MORE`, imported rather than re-worded,
 * as the drill-down imports them: one fact, one wording on every render.
 *
 * ⛔ SINCE card#11058 THIS ROW IS A GUARANTEED HOME. The desk draws only the ruled glance set (the
 * operator's ruling of 2026-10-02, Q0 (a) + Q1 (B)); every fact it gave up — every raw unrecognised
 * string among them — is printed here AND in the drill-down, which
 * `Tests\Feature\Floor\TheNewDeskKeepsEveryLeafTest` holds of every leaf the desk drew before.
 *
 * ⛔ THE POPULATION IS THE MODEL's OUTPUT, NOT A LIST WRITTEN HERE. `NOT_LISTED` names, BY PATH, the
 * leaves of `deskModel()`'s output this row does not print, each with its reason; every other leaf is
 * on the row. `Tests\Feature\Floor\TheListViewRendersEveryDeskMemberTest` derives the leaves from the
 * model's real output over every run of every checked-in fixture file that draws a desk, and holds
 * each one printed — by perturbing it and watching the row change — or named here, and seen at a
 * non-default value on at least one run. So a member added to the desk model lands on this row or reds
 * the build.
 *
 * Path notation: members joined by `.`, and an array's elements as `[]` (`side_table.stools[].label`).
 */

import { DASH } from './desk-render.js';
import { MORE, OPEN_CALLS } from '../floor/desk-layout.js';

/**
 * The words this row adds for a member that carries no string of its own. ⚠ NOT RATIFIED — the
 * marks the drawing carries as a picture (the empty chair of a seat the client cannot confirm, the
 * small marker on a monitor showing a subagent's call) have no published wording, so the list view's
 * are these until the operator rules on them.
 */
export const UNCONFIRMED = 'unconfirmed';

export const SUBAGENT_CALL = "a subagent's call";

/**
 * Whether the desk's held render is drawn moving — § 6.2's `motion`, `held.motion` on the model — in
 * words. ⚠ NOT RATIFIED, for the same reason. The drill-down prints them (card#11058 PR-A) and so does
 * this row's second line (card#11058 B1); a desk with no character holds no render and reads *still*.
 */
export const MOVING = 'moving';

export const STILL = 'still';

/** The prefix the monitor's line is read under — ⚠ NOT RATIFIED, for the same reason. */
export const MONITOR = 'monitor';

/** Row 8's cluster prefixes, kept: the words the page already carried for these lists. */
export const BADGES_PREFIX = 'badges: ';

export const UNRECOGNISED_PREFIX = 'unrecognised: ';

/**
 * Every leaf of `deskModel()`'s output this row does not print, by path, with its reason.
 * `carried_by` names the top-level string member whose printed text already contains this leaf's
 * value, and the guard checks that containment on every desk rather than taking the reason's word for it.
 */
export const NOT_LISTED = Object.freeze({
    seat_id: {
        reason: 'printed as the nameplate, which is the same string (§ 5.1)',
        carried_by: 'nameplate',
    },
    desk_label: {
        reason: "the label line's non-raw form, which the DESK draws (the operator's ruling of 2026-10-02, Q0: no raw "
            + 'unrecognised string on the desk); this row prints `label_line` itself, raw string included',
    },
    desk_currency: {
        reason: "the currency label's non-raw form, which the DESK draws (Q0); this row prints `currency_label` itself, "
            + 'raw member included',
    },
    'render_state.value': {
        reason: "§ 5.1's one switch: its text forms are the glyph and the label line the model already derived "
            + 'from it, and an unrecognised value is on both, raw',
    },
    'render_state.recognised': {
        reason: "carried by the glyph, which reads `unrecognised: <raw>` exactly when this is false",
    },
    character: {
        reason: 'the same fact as the pose: the model draws no character exactly when the pose is the empty chair',
    },
    'dark.since': {
        reason: "spliced into the label line by `labelLine()` (§ 2.4's dark-only pair); printing it again "
            + 'would be one fact twice',
        carried_by: 'label_line',
    },
    'dark.age': {
        reason: "spliced into the label line by `labelLine()` (§ 2.4's dark-only pair); printing it again "
            + 'would be one fact twice',
        carried_by: 'label_line',
    },
    'lag.overlay': {
        reason: "§ 7.4's hatch is drawn over the art, and the list has no art; § 7.4's text on this row is the "
            + 'lag line, beside the `fold_lag` badge',
    },
    'gauge.bar': {
        reason: "the bar's length; the same `used_pct` is on the row as the gauge's percentage",
    },
    'bubble.truncated': {
        reason: "carried by the bubble's text, which ends in rule 4's mark exactly when this is true",
    },
    'side_table.stools[].untitled': {
        reason: "carried by the stool's label, which reads *untitled* exactly when this is true (§ 5.6)",
    },
    'side_table.stools[].call_id': {
        reason: "the intern join key (§ 5.1): stored to attribute an intern's calls, drawn by no element",
    },
    'held.animation_id': {
        reason: "the held render is the drawing's motion (§ 6.2); the list animates nothing, and the state it "
            + 'moves for is on the row as the glyph, the pose and the label line',
    },
    'held.form': {
        reason: "§ 6.4's reduced-motion form of a drawn loop; the list draws no loop to reduce",
    },
    'held.frame_interval_ms': {
        reason: "a drawn loop's frame rate; the list draws no loop",
    },
});

/** The joins, and nothing else: parts a member left null or empty are dropped, never printed blank. */
function join(...parts) {
    const kept = parts.filter((part) => part !== null && part !== undefined && part !== '');

    return kept.length === 0 ? null : kept.join(DASH);
}

/** § 5.1's context gauge: its percentage and numerals, its source, clock and age — or *not reported*. */
function gaugeLine(gauge) {
    return gauge.reported
        ? join(gauge.pct, gauge.numerals, gauge.source, gauge.sampled_at, gauge.age)
        : gauge.statement;
}

/** § 5.1's monitor: its light, what it shows, and the subagent's-call marker. */
function monitorLine(monitor) {
    const head = `${MONITOR} ${monitor.lit}`;
    const body = join(monitor.text, monitor.subagent_call ? SUBAGENT_CALL : null);

    return body === null ? head : `${head}: ${body}`;
}

/** § 8's side table, the desk's half: one line per stool, then *+N more* from the model's count. */
function stoolLines(table) {
    const lines = table.stools.map((stool) => ({ role: 'stool', text: join(stool.label, stool.type, stool.started_at) }));

    return table.more === null ? lines : [...lines, { role: 'stool-more', text: MORE(table.more) }];
}

/**
 * One seat's row as TYPED lines: each line beside the ROLE that composed it — the member (or the
 * member group) it prints. `deskListRow()` is these lines' text; the desk leaf guard
 * (`TheNewDeskKeepsEveryLeafTest`) reads the roles, so a fact is carried only by a line of its own
 * member, never by a line that happens to share its text.
 *
 * @param {object} desk one desk model: `desk/desk-render.js`'s `deskModel()` output
 * @returns {list<{role: string, text: string}>} in order, each text non-empty
 */
export function deskListLines(desk) {
    return [
        { role: 'nameplate', text: join(desk.nameplate, desk.install_id) },
        {
            role: 'render',
            text: join(desk.glyph, desk.pose, desk.lighting, desk.held?.motion === true ? MOVING : STILL,
                desk.unconfirmed ? UNCONFIRMED : null),
        },
        { role: 'label_line', text: desk.label_line },
        { role: 'currency_label', text: desk.currency_label },
        { role: 'lag', text: desk.lag?.line ?? null },
        { role: 'config_note', text: desk.config_note },
        { role: 'monitor', text: monitorLine(desk.monitor) },
        { role: 'action', text: desk.action === null ? null : join(desk.action.started_at, desk.action.elapsed) },
        { role: 'open_calls', text: desk.open_calls === null ? null : OPEN_CALLS(desk.open_calls) },
        { role: 'quiet_age', text: desk.quiet_age },
        { role: 'last', text: join(desk.last_kind, desk.last_event_time) },
        { role: 'gauge', text: gaugeLine(desk.gauge) },
        { role: 'model_label', text: desk.model_label },
        { role: 'badges', text: desk.badges.length === 0 ? null : BADGES_PREFIX + desk.badges.join(', ') },
        { role: 'unrecognised', text: desk.unrecognised.length === 0 ? null : UNRECOGNISED_PREFIX + desk.unrecognised.join(', ') },
        { role: 'oldest_badge_since', text: desk.oldest_badge_since },
        ...stoolLines(desk.side_table),
        {
            role: 'bubble',
            text: desk.bubble === null ? null : join(desk.bubble.text, desk.bubble.source, desk.bubble.degraded_note),
        },
    ].filter((line) => line.text !== null && line.text !== undefined && line.text !== '');
}

/**
 * THE ENTRY POINT — one seat's row of the list view: `deskListLines()`'s text.
 *
 * @param {object} desk one desk model: `desk/desk-render.js`'s `deskModel()` output, as a frame's
 *                      `desks` carries it (never `null` — a retired seat has no desk and no row)
 * @returns {string[]} the row's lines, in order, each non-empty; the first is the nameplate's
 */
export function deskListRow(desk) {
    return deskListLines(desk).map((line) => line.text);
}

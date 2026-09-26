/**
 * THE LIST VIEW — `docs/design/FLOOR.md` Appendix B row 15 (slice A) and § 4.5's capability floor:
 * one seat as lines of text, every fact `desk/desk-render.js`'s `deskModel()` emits, no map.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS IS A HEADLESS MODEL AND NOT A PAGE FUNCTION. Row 8's text render was `floor/main.js`'s
 * `deskLine()`, which carried six members and dropped the rest — a `fold_lag` seat on it was marked
 * only by its raw badge id, § 7.4's lag line and § 7.3's note missing — and it lived in a DOM entry
 * no harness can load. It is deleted; the page paints the lines this returns and composes none.
 *
 * ⛔ EVERY STRING IS THE MODEL's, AS THE MODEL DECIDED IT. What this file decides is the text form of
 * the members that are NOT already a string — the gauge, the side table and its *+N more*, the
 * bubble, the open-call count, the unconfirmed flag and the subagent's-call marker — and it reads out
 * the strings the model carries inside an object (the lag line, the monitor's text, the action's start
 * and elapsed). The two worded numbers are the scene's own wordings (`floor/desk-layout.js`'s
 * `OPEN_CALLS` and `MORE`), imported rather than re-worded: one fact, one wording on both renders.
 *
 * ⛔ THE POPULATION IS THE MODEL's OUTPUT, NOT A LIST WRITTEN HERE. `NOT_LISTED` names, BY PATH, the
 * leaves of `deskModel()`'s output this row does not print, each with its reason; every other leaf is
 * on the row. `Tests\Feature\Floor\TheListViewRendersEveryDeskMemberTest` derives the leaves from the
 * model's real output over § 11's fixtures and holds each one printed — by perturbing it and watching
 * the row change — or named here, and seen at a non-default value on at least one run. So a member
 * added to the desk model lands on this row or reds the build.
 *
 * Path notation: members joined by `.`, and an array's elements as `[]` (`side_table.stools[].label`).
 */

import { DASH } from './desk-render.js';
import { MORE, OPEN_CALLS } from '../floor/desk-layout.js';

/**
 * The words this row adds for a member that carries no string of its own. ⚠ NOT RATIFIED — the two
 * marks the drawing carries as a picture (the empty chair of a seat the client cannot confirm, the
 * small marker on a monitor showing a subagent's call) have no published wording, so the list view's
 * are these until the operator rules on them.
 */
export const UNCONFIRMED = 'unconfirmed';

export const SUBAGENT_CALL = "a subagent's call";

/** The prefix the monitor's line is read under — ⚠ NOT RATIFIED, for the same reason. */
export const MONITOR = 'monitor';

/** Row 8's two cluster prefixes, kept: the words the page already carried for these lists. */
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
    'held.motion': {
        reason: 'the held render is the drawing\'s motion (§ 6.2); what stops it — a lag, `config_invalid`, an '
            + 'unrecognised value — is on the row in its own words',
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
    const lines = table.stools.map((stool) => join(stool.label, stool.type, stool.started_at));

    return table.more === null ? lines : [...lines, MORE(table.more)];
}

/**
 * THE ENTRY POINT — one seat's row of the list view.
 *
 * @param {object} desk one desk model: `desk/desk-render.js`'s `deskModel()` output, as a frame's
 *                      `desks` carries it (never `null` — a retired seat has no desk and no row)
 * @returns {string[]} the row's lines, in order, each non-empty; the first is the nameplate's
 */
export function deskListRow(desk) {
    return [
        join(desk.nameplate, desk.install_id),
        join(desk.glyph, desk.pose, desk.lighting, desk.unconfirmed ? UNCONFIRMED : null),
        desk.label_line,
        desk.currency_label,
        desk.lag?.line ?? null,
        desk.config_note,
        monitorLine(desk.monitor),
        desk.action === null ? null : join(desk.action.started_at, desk.action.elapsed),
        desk.open_calls === null ? null : OPEN_CALLS(desk.open_calls),
        desk.quiet_age,
        join(desk.last_kind, desk.last_event_time),
        gaugeLine(desk.gauge),
        desk.model_label,
        desk.badges.length === 0 ? null : BADGES_PREFIX + desk.badges.join(', '),
        desk.unrecognised.length === 0 ? null : UNRECOGNISED_PREFIX + desk.unrecognised.join(', '),
        desk.oldest_badge_since,
        ...stoolLines(desk.side_table),
        desk.bubble === null ? null : join(desk.bubble.text, desk.bubble.source, desk.bubble.degraded_note),
    ].filter((line) => line !== null && line !== undefined && line !== '');
}

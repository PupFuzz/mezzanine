/**
 * `docs/design/FLOOR.md § 7.2` and `§ 7.6`'s published member sets that no other module carries —
 * the 18 badges, the five `link_state`s and the five `activity_state`s — so that § 5.4's
 * unrecognised-member rule and § 9 F9 are MEMBERSHIP TESTS against a set, as § 7.6 requires:
 * "*a member the client does not know* is unimplementable against a set that lives nowhere".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE COPY EACH, GUARDED. The other three sets already have their one home —
 * `lobby/render-state.js` (`render_state`) and `desk/desk-render.js` (`unknown_reason`,
 * `api_error_type`, whose keys are those sets) — and none of them is repeated here.
 * `Tests\Feature\Floor\AnUnrecognisedMemberRendersAsUnrecognisedTest` re-derives these three from
 * § 7.2's and § 7.6's tables on every run and set-differences both directions, so a member the
 * document gains or drops reds rather than being silently rendered as *unrecognised* or silently
 * accepted.
 *
 * ⛔ NO ORDER. § 7.6 publishes `link_state` with "no order at all", and neither of the other two
 * is iterated; these are sets, and only `has()` is read.
 */

/** § 7.2 — D1's twelve `degraded` members plus D2's seven server-derived badges, `epoch_reset` in both. */
export const BADGES = Object.freeze(new Set([
    'lossy',
    'batches_rejected',
    'harness_contract_moved',
    'reporter_behind',
    'value_clamped',
    'counters_omitted',
    'index_overflow',
    'invalid_tool_name',
    'bad_session_id',
    'config_invalid',
    'statusline_degraded',
    'epoch_reset',
    'seq_gap',
    'seq_collision',
    'clock_skew',
    'reporter_ahead',
    'fold_lag',
    'derivation_error',
]));

/** § 7.6 — `link_state`, five members. */
export const LINK_STATES = Object.freeze(new Set(['live', 'catching_up', 'stale', 'offline', 'disabled']));

/** § 7.6 — `activity_state`, five members. */
export const ACTIVITY_STATES = Object.freeze(new Set(['working', 'idle', 'blocked', 'stalled', 'unknown']));

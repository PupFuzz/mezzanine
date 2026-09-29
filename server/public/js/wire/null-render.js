/**
 * `docs/design/FLOOR.md § 5.6`'s null render — the WORDS every surface speaks an absence in,
 * written once.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED AT THE DESK, THE THIRD CALLER (card#7341 step 5). `lobby/lobby-model.js` and
 * `drilldown/drilldown-model.js` each wrote their own `NOT_REPORTED`, and the desk render needs
 * the same word for the same fact — a gauge with no sample, numerals with no tokens. § 5.6 states
 * one default for every surface ("where the element's own space is drawn unconditionally, it reads
 * ***not reported***"), so three spellings of it are three chances for one of them to drift into
 * *n/a* or *—* while the other two stay right. Both earlier callers import from here and
 * re-export the name, so their published surface is unchanged.
 *
 * ⛔ AN ABSENCE IS NEVER A ZERO (§ 7.5's "Zeroed" bullet, AT-D3-14). Nothing in this file is a
 * number, and no caller may substitute one for these words.
 */

/** § 5.6's default for a member whose element space is drawn unconditionally. */
export const NOT_REPORTED = 'not reported';

/**
 * § 8 / § 5.6 for a `subagents[].title` of `null` — "the honest orphan D1 § 6.8 and D2 § 8.2.1
 * both refuse to paper over". The desk's stool and the panel's intern row say it in one word.
 * ⛔ NEVER the `subagent_type`, the tool name or the word *subagent*: that is AT-D3-4's first
 * RED, "a label for a spawn event that was never received".
 */
export const UNTITLED = 'untitled';

/**
 * § 5.6's `delivery.no_data_since` and `delivery.last_receipt_at`, and § 7.1's `offline` row: the
 * provisioned-never-reported seat reads ***no data yet*** — "never *no data since null* and never
 * an age beside it". § 5.6 names it as one fact on two surfaces ("both render *no data yet* — on
 * different surfaces, from different fields"), which is why it lives beside the other shared words.
 */
export const NO_DATA_YET = 'no data yet';

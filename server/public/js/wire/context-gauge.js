/**
 * The CONTEXT GAUGE — `docs/design/FLOOR.md § 5.1`'s gauge row and its numerals row, § 4.3's
 * panel row, and § 5.6's null render for `context`, `context.used_tokens` and
 * `context.total_tokens` — decided once for both surfaces that draw it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED AT ITS SECOND CALLER (card#7341 step 5). `drilldown/drilldown-model.js` wrote this as
 * its private `contextBlock`; the desk draws the same gauge from the same member, and D3 states
 * the null render ONCE for both (§ 5.6: "the gauge reads ***not reported*** and **the bar is
 * absent** — not a bar at 0 %"). A second copy of that rule is exactly where the one defect this
 * gauge is famous for — the clean zero, `docs/KANBAN.md § G-1` with a progress bar around it —
 * gets a second place to come back.
 *
 * ⛔ IT DECIDES THE GAUGE, NOT THE AGE. The sample's own age is § 2.4's arithmetic over the
 * server-clock `context.sampled_received_at`, which `wire/age-readout.js` (the desk's 1 s tick)
 * and the panel's own clock each compute; the caller hands the rendered age in, so this file
 * reads no clock and knows nothing about which surface it is on.
 */

import { NOT_REPORTED } from './null-render.js';
import { seatClock } from './age-readout.js';

/**
 * @param {object|null} context  D2 § 8.2.1's `context` member
 * @param {string|null} age      the sample's age, already rendered by § 2.4's function, or `null`
 *
 * ⛔ A NULL `context` READS *not reported* AND DRAWS NO BAR — not a bar at 0 % (§ 5.6, § 7.5,
 * AT-D3-14). `bar` is `null` rather than `0` for exactly that reason.
 *
 * ⛔ NO PERCENTAGE IS RECOMPUTED FROM THE TOKEN PAIR (§ 5.6, `context.total_tokens`), and the bar
 * still renders when the numerals are null, because `used_pct` is not nullable.
 */
export function contextGauge(context, age) {
    // ⛔ THE SECOND CONDITION IS BOUNDARY VALIDATION, NOT A DEFENCE AGAINST A STATE THAT CANNOT
    // HAPPEN. D2 § 8.2.1 declares `used_pct` NOT nullable, so an object arriving without a
    // readable one is a malformed wire object rather than a seat state — and the one thing this
    // gauge may never do is turn that into a bar at 0 %, which is exactly what `Number(null)`
    // would produce. A percentage the wire did not send is a percentage no surface reports, on
    // the same terms as a sample that was never taken.
    const pct = typeof context?.used_pct === 'number' && Number.isFinite(context.used_pct)
        ? context.used_pct
        : null;

    if ((context ?? null) === null || pct === null) {
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
        age,
    };
}

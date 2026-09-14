/**
 * `docs/design/FLOOR.md § 2.4`'s DURATION FORMAT — "one function, and every duration and every
 * age this page renders is its output".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A RESTATEMENT OF SEVEN CLAUSES AND IT IS GUARDED, NOT TRUSTED. § 2.4 states the
 * format as a rule in prose and `tools/design/verify-floor.py` re-implements it in PYTHON to
 * reproduce that section's boundary table. A browser cannot run that verifier, so the copy
 * cannot be deleted — which makes this the GUARD case of the restatement rule:
 * `Tests\Feature\DrillDown\DurationFormatMatchesTheDocumentTest` RE-DERIVES § 2.4's boundary
 * table from `FLOOR.md` on every run and requires this function to reproduce every row of it. A
 * hand-written expected table in that test would be a THIRD copy, which is how two copies come
 * to agree while the document says something else.
 *
 * ⛔ IT LIVES IN `wire/` BECAUSE § 2.4 SAYS THERE IS ONE OF IT. "Shared by every rendered
 * duration on this page, with no exception and no second form." A copy beside the first screen
 * that needed one is the shape that produced the three exemplars *no single rule produces*,
 * which card#9209 was opened to retire — so it is hoisted at its FIRST caller rather than at its
 * second, on the document's own instruction rather than on the general rule.
 *
 * ⛔ THIS FILE FIXES THE FORMAT AND NO WORDING. The strings a duration is rendered INSIDE — the
 * four § 2.4 publishes verbatim, and the ones § 14 item 17 leaves to the renderer — belong to
 * the surface that draws them. A wording written here would be one fact's string living outside
 * the section that owns the fact.
 */

/**
 * An `rfc3339_ms` wire instant, as milliseconds since the epoch.
 *
 * ⛔ `Date.parse` IS GIVEN AN UNAMBIGUOUS INPUT OR NOTHING. `rfc3339_ms`
 * (`docs/design/FLEET-STATE.md § 8.2.1`) is `2026-08-23T14:23:14.201Z` — UTC-designated, so its
 * epoch value carries no timezone question at all. A value in the STORE's own spelling
 * (`2026-08-23 14:23:14.201`, `docs/design/FLEET-STATE.md § 6.3`) is *not* that, and V8 reads it
 * as a LOCAL time — an age off by the viewer's UTC offset, rendered with total confidence. So
 * the shape is matched before it is parsed, and anything else answers `null`: the caller then
 * renders § 5.6's absence rather than a wrong number.
 */
export function wireMs(wireTime) {
    if (typeof wireTime !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/.test(wireTime)) {
        return null;
    }

    const ms = Date.parse(wireTime);

    return Number.isFinite(ms) ? ms : null;
}

/**
 * `docs/design/FLOOR.md § 2.1` row 1 — `clock_offset_ms = server_time − browser_now`, "refreshed
 * on every message and response that carries `server_time`".
 *
 * `null` when the response carried no readable `server_time`: the caller keeps the offset it
 * already had rather than adopting a zero, because a zero is the claim *the two clocks agree*.
 */
export function clockOffsetMs(serverTime, browserNowMs) {
    const server = wireMs(serverTime);

    return server === null ? null : server - browserNowMs;
}

/**
 * § 2.4's seven clauses, as one function: a number of seconds in, one string out.
 *
 * ⛔ A NON-NUMBER THROWS RATHER THAN RENDERING `0s`. Clause 7 is explicit that `0s` is "not the
 * render of a *missing* duration: a null basis renders § 5.6's absence … and never `0s`, which
 * would claim a measurement at this instant". A function that answered `0s` for a null basis
 * would put that claim one careless call away, and it would be invisible on screen. The caller
 * checks its basis; this refuses to guess for it.
 */
export function formatDuration(seconds) {
    if (typeof seconds !== 'number' || !Number.isFinite(seconds)) {
        throw new TypeError(
            'a duration is a number of seconds — a null or missing basis renders the absence '
            + 'FLOOR.md 5.6 states for that member at the call site, never 0s',
        );
    }

    // Clause 1: truncated to whole seconds, and never negative. `Math.trunc` rather than
    // `Math.floor`, so a value between −1 and 0 lands on 0 rather than on −1.
    const total = Math.max(0, Math.trunc(seconds));

    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;

    // Clause 3: the largest unit whose value is non-zero, and the one below it — nothing above
    // it and nothing below the second. Clause 2: hours continue past 24 and there is no day
    // unit. Clause 7: zero renders `0s`, which is this chain's last branch and its only zero.
    const [first, firstUnit, second, secondUnit] = h > 0
        ? [h, 'h', m, 'm']
        : (m > 0 ? [m, 'm', s, 's'] : [s, 's', 0, null]);

    // Clause 4: the second unit is dropped when its value is zero. Clause 5: the first value is
    // unpadded and the second is zero-padded to two digits. Clause 6: one space between the two
    // units, none between a value and its letter, and the letters are never pluralised.
    return second === 0 || secondUnit === null
        ? `${first}${firstUnit}`
        : `${first}${firstUnit} ${String(second).padStart(2, '0')}${secondUnit}`;
}

/**
 * An AGE: `docs/design/FLOOR.md § 2.1` row 2's kind of computation — "any D2 timestamp
 * subtracted from the corrected clock and rendered through § 2.4's duration format".
 *
 * `nowMs` is the CORRECTED clock (`browser_now + clock_offset_ms`) and never the browser's own:
 * § 2.4 admits the viewer's machine clock at exactly one place on this product, and it is the
 * floor's wall clock rather than any age.
 *
 * `null` when the basis is null or unreadable — the caller then renders § 5.6's absence for that
 * member, which is a stated string per member and never this function's to pick.
 */
export function ageFrom(wireTime, nowMs) {
    const then = wireMs(wireTime);

    return then === null ? null : formatDuration((nowMs - then) / 1000);
}

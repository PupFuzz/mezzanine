/**
 * Wire-clock formatting, shared by every screen that renders an instant D2 sent.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED AT ITS SECOND CALLER (card#8300), not at its Nth. `lobby/lobby-model.js` wrote this
 * for card#7341; `coord/coord-model.js` needs exactly it, and a second copy of one behaviour is
 * a defect rather than a style choice — the first thing two copies do is agree with each other
 * until one of them is edited. Anything a third screen needs of the wire's clock belongs here
 * too rather than beside it.
 */

/**
 * `HH:MM:SS` out of an RFC3339-with-milliseconds server-clock timestamp (D2 § 8.2), which is the
 * form `docs/design/FLOOR.md § 4.1` publishes for the membership stamp ("membership as of
 * 14:23:14") and § 5.7 for the coordination receipt stamps.
 *
 * ⛔ IT READS THE WIRE'S OWN DIGITS AND CONVERTS NOTHING. Parsing to a `Date` and formatting
 * would render the VIEWER's timezone for a SERVER-clock fact, and § 2.4 is emphatic that the
 * viewer's own machine clock is admitted at exactly one place on this product (§ 6.2 A17's wall
 * clock, which is the floor's and is labelled as the client's own). D3 publishes the FORM and
 * states no timezone rule for it, so the zero-assumption render is the server's own digits.
 * ⚠ REPORTED, NOT INVENTED: that leaves an operator in another zone reading UTC. It is a
 * question for the review loop, not something to answer by picking a conversion here.
 *
 * ⛔ IT IS NOT A DURATION AND NEVER BECOMES ONE. § 2.4 draws that line itself — "a timestamp is
 * not a duration" — and it is why both callers can render an instant while § 14 item 17's
 * wording gap is still open on the ages beside them.
 *
 * `null` for a null or unreadable value — the caller applies its own section's absence render,
 * and NEVER a zero, an epoch, or the string "null".
 */
export function clockTime(wireTime) {
    if (typeof wireTime !== 'string') {
        return null;
    }

    const m = wireTime.match(/T(\d{2}):(\d{2}):(\d{2})/);

    return m === null ? null : `${m[1]}:${m[2]}:${m[3]}`;
}

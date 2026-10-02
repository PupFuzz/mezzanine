/**
 * card#11058 — THE ONE "RAW UNRECOGNISED STRING ON THE DESK" PREDICATE (the operator's ruling of
 * 2026-10-02, Q0: no raw unrecognised string is drawn on the desk). Its two readers are
 * `desk-leaves-probe.mjs` (TheNewDeskKeepsEveryLeafTest's criterion 10, which imports it) and
 * `SeatFurnitureNeverOverlapsTest`'s clause (e), which runs it under `node` over every element of a scene
 * in one process (`--stdin`). There is no second copy.
 *
 * A drawn text carries a raw value when:
 *   · the value's tokens (split on spaces, punctuation and symbols — `_` is punctuation) appear as a WHOLE
 *     run of the text's tokens; or
 *   · the text is drawn CUT (the mark at its end) and it ends in a run of the value's tokens cut at the
 *     mark: zero or more whole tokens, then the last drawn token a prefix of the value's next token. With
 *     no whole token before it, that last token must be at least `MIN_CUT_PREFIX` characters long — a
 *     one- or two-letter stub identifies no value, and would flag a descriptor cut to *Bash: co…* as a
 *     raw *cosmic_rays*. A 64-character single-token id cut to fit a chip is still the raw id.
 */

export const MARK = '…';

export const MIN_CUT_PREFIX = 3;

export const tokens = (s) => s.split(/[\s\p{P}\p{S}]+/u).filter((t) => t !== '');

/**
 * @param {string} text the text as drawn
 * @param {boolean} truncated whether the layout cut it
 * @param {string} value one raw value (the part of a `field: value` line after `: `)
 */
export function drawsRaw(text, truncated, value) {
    const raw = tokens(value);

    if (raw.length === 0) {
        return false;
    }

    const cut = truncated === true && text.endsWith(MARK);
    const t = tokens(cut ? text.slice(0, -MARK.length) : text);

    for (let i = 0; i < t.length; i++) {
        let k = 0;

        while (k < raw.length && i + k < t.length && t[i + k] === raw[k]) {
            k++;
        }

        if (k === raw.length) {
            return true;
        }

        // The cut run: t[i..] is raw[0..whole) whole, then a stub of raw[whole].
        const whole = t.length - 1 - i;
        const stub = t[t.length - 1];

        if (cut && whole < raw.length && k >= whole && raw[whole].startsWith(stub) && (whole >= 1 || stub.length >= MIN_CUT_PREFIX)) {
            return true;
        }
    }

    return false;
}

// `node raw-on-desk.mjs --stdin`: `[[text, truncated, value], …]` in, `[bool, …]` out.
if (process.argv[1] && process.argv[1].endsWith('raw-on-desk.mjs') && process.argv.includes('--stdin')) {
    const { readFileSync } = await import('node:fs');

    console.log(JSON.stringify(JSON.parse(readFileSync(0, 'utf8')).map(([text, truncated, value]) => drawsRaw(text, truncated, value))));
}

/**
 * The probe the PHP suite drives the DESK's thought bubble through — `node`, no dependencies,
 * no network, no DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2],
 * so the same probe runs against `public/js/desk` and against a MUTATED COPY of it in a temp
 * directory — which is how every planted control in `tests/Feature/Desk` re-mints its defect
 * against the real code rather than against a second copy of the logic.
 *
 * stdin  — JSON: `{ "seat": <a seat object>, "ref_bases": {…}|null,
 *                   "character_probe": ["working", …],
 *                   "layout": { "bubbles": [...], "char_w": 10, "line_h": 20,
 *                               "no_measurer": false } }`
 * stdout — JSON: `{ "bubble", "characters", "no_character_states", "max_chars",
 *                   "truncation_mark", "layout", "measurer_error" }`
 *
 * ⚠ THE MEASURER IS THE PROBE'S, AND THAT IS THE POINT. `docs/design/FLOOR.md § 5.1` rule 4
 * requires the box to be sized from MEASURED text and forbids a guess, so the module takes the
 * measurement as an argument and there is nothing about a font on this host to take it from.
 * The stub here is `w = characters × char_w`, which is a measurement of the fixture rather than
 * of a drawing — it exercises the rule that the module never invents one.
 *
 * Any throw exits non-zero with the message on stderr, EXCEPT the measurer refusal, which is a
 * stated outcome and is reported as `measurer_error`.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node desk-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const url = (file) => pathToFileURL(join(dir, file)).href;

const bubble = await import(url('task-bubble.js'));

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');

let layout = null;
let measurerError = null;

if (payload.layout) {
    const charW = payload.layout.char_w ?? 10;
    const lineH = payload.layout.line_h ?? 20;
    const measure = (text) => ({ w: [...text].length * charW, h: lineH });

    try {
        layout = bubble.bubbleLayout(
            payload.layout.bubbles ?? [],
            payload.layout.no_measurer === true ? null : measure,
        );
    } catch (error) {
        measurerError = error.message;
    }
}

console.log(JSON.stringify({
    bubble: bubble.taskBubble(payload.seat ?? null, { ref_bases: payload.ref_bases ?? null }),
    // Positional rather than keyed, so a probe of `null` or of a non-string is expressible.
    characters: (payload.character_probe ?? []).map((state) => bubble.deskDrawsCharacter(state)),
    no_character_states: [...bubble.NO_CHARACTER_STATES],
    max_chars: bubble.MAX_CHARS,
    truncation_mark: bubble.TRUNCATION_MARK,
    layout,
    measurer_error: measurerError,
}, null, 2));

/**
 * The probe the PHP suite drives `lobby/cab-position.js`'s `resolveCab()` through — under `node`, no
 * DOM, no dependencies: the pure function that decides, on a render, whether the viewer's cab is
 * re-seated on the model's resolved position or left where a ride in flight put it (impl review r1
 * finding 2, card#7343 row 16).
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/lobby` or a MUTATED COPY of it — so a planted control re-mints its defect in the
 * real code.
 *
 * stdin  — JSON: `{ "calls": [ { "cab", "building": { "composed", "elevator": { "at" } }, "riding" } … ] }`
 * stdout — JSON: `{ "results": [ <resolveCab()'s return, one per call> … ] }`
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node cab-position-probe.mjs <lobby-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const { resolveCab } = await import(pathToFileURL(join(dir, 'cab-position.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify({
    results: payload.calls.map(({ cab, building, riding }) => resolveCab(cab, building, riding)),
}));

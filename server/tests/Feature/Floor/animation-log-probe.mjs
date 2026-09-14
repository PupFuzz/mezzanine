/**
 * The probe the PHP suite drives `wire/animation-log.js` through — `node`, no dependencies, no
 * network, no DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2], so
 * the same probe runs against `public/js/wire` and against a MUTATED COPY of it — which is how
 * every planted control in `tests/Feature/Floor` re-mints its defect against the real code.
 *
 * stdin  — JSON: `{ "ops": [ { "op": "edge" | "enterHeld", "args": {…} },
 *                            { "op": "leaveHeld", "episode": <ref>, "args": {…} } ] }`
 *          where `<ref>` is a literal id, `{ "returned_by": n }` (the id op n returned) or
 *          `{ "row": n }` (the `episode_id` of row n as the log held it when this op ran). A key
 *          left out of `args` reaches the module as `undefined`, which is how an omitted `at` is
 *          expressed in JSON.
 * stdout — JSON: `{ "results": [ { "op", "episode", "returned", "error": null | { "name",
 *                   "message" }, "rows_before", "rows_after" } ], "rows" }`
 *
 * ⛔ A THROW IS AN OUTCOME HERE, NOT A CRASH. Each op's error is caught into its own result with
 * its class NAME and message, so a test can require the module's refusal by name and reject any
 * other throw — a `TypeError` off an `undefined` lookup is a module that lost its guard, and a
 * probe that only reported "it threw" would pass it.
 */

import { join } from 'node:path';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node animation-log-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const { createAnimationLog } = await import(pathToFileURL(join(dir, 'animation-log.js')).href);

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
const log = createAnimationLog();
const results = [];

for (const step of payload.ops ?? []) {
    const rowsBefore = log.rows;
    let episode = step.episode;

    if (episode !== null && typeof episode === 'object') {
        episode = 'returned_by' in episode
            ? results[episode.returned_by].returned
            : rowsBefore[episode.row].episode_id;
    }

    const result = { op: step.op, episode: episode ?? null, returned: null, error: null };

    try {
        const returned = step.op === 'leaveHeld'
            ? log.leaveHeld(episode, step.args ?? {})
            : log[step.op](step.args ?? {});
        result.returned = returned ?? null;
    } catch (error) {
        result.error = { name: error?.name ?? typeof error, message: String(error?.message ?? error) };
    }

    result.rows_before = rowsBefore;
    result.rows_after = log.rows;
    results.push(result);
}

console.log(JSON.stringify({ results, rows: log.rows }, null, 2));

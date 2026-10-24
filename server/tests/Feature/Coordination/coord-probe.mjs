/**
 * The probe the PHP suite drives the coordination client model through — `node`, no
 * dependencies, no network, no DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2],
 * so the same probe runs against `public/js/coord` and against a MUTATED COPY of it in a temp
 * directory — which is how every planted control in `tests/Feature/Coordination` re-mints its
 * defect against the real code rather than against a second copy of the logic.
 *
 * stdin  — JSON: `{ "messages": [<feed envelope>, …], "install_id": "<room>",
 *                   "join": { "<agent name>": "<seat_id>" } | null,
 *                   "reasons": {…}, "checks": {…}, "clock_probe": ["<rfc3339>", …],
 *                   "memory": { "bound": <n> | absent, "refs": ["<post_ref>", …] } }`
 * stdout — JSON: `{ "round_members", "messages", "model", "unresolved", "clock", "memory" }` —
 *          `unresolved` is `unresolvedEnds()` per drawn round, the client's event-log line for it, and
 *          `memory` is `post-memory.js` driven over `refs`: each sight's `first()` answer, the size
 *          after, and the module's `POST_MEMORY` (card#12239).
 *
 * Any throw exits non-zero with the message on stderr: a probe that swallowed one would turn a
 * client that crashes on an object shape D2 permits into a green test.
 */

import { readFileSync } from 'node:fs';
import { join as joinPath } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node coord-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const model = await import(pathToFileURL(joinPath(dir, 'coord-model.js')).href);

const { POST_MEMORY, PostMemory } = await import(pathToFileURL(joinPath(dir, 'post-memory.js')).href);

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
let memory = null;

if (payload.memory !== undefined) {
    const held = payload.memory.bound === undefined ? new PostMemory() : new PostMemory(payload.memory.bound);
    const first = payload.memory.refs.map((ref) => held.first(ref));

    memory = { first, size: held.size, bound: POST_MEMORY };
}
const built = model.coordModel(payload.messages ?? [], {
    install_id: payload.install_id ?? null,
    join: payload.join ?? null,
    reasons: payload.reasons ?? {},
    checks: payload.checks ?? {},
});

console.log(JSON.stringify({
    round_members: model.COORD_ROUND_MEMBERS,
    messages: [model.COORD_ROUND_MESSAGE],
    model: built,
    unresolved: built.rounds.map((round) => model.unresolvedEnds(round)),
    clock: (payload.clock_probe ?? []).map((v) => model.clockTime(v)),
    memory,
}, null, 2));

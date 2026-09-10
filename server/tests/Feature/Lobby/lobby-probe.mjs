/**
 * The probe the PHP suite drives the lobby's client model through — `node`, no dependencies, no
 * network, no DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2],
 * so the same probe runs against `public/js/lobby` and against a MUTATED COPY of it in a temp
 * directory — which is how every planted control in `tests/Feature/Lobby` re-mints its defect
 * against the real code rather than against a second copy of the logic.
 *
 * stdin  — JSON: `{ "snapshot": <a GET /api/fleet/snapshot body>,
 *                   "observations": [[held, total], …] }`
 * stdout — JSON: `{ "render_states": [...], "model": {...}, "budget": {...} }`
 *
 * Any throw exits non-zero with the message on stderr: a probe that swallowed one would turn a
 * client that crashes on an unrecognised member into a green test (AT-D3-11's whole subject).
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node lobby-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const url = (file) => pathToFileURL(join(dir, file)).href;

const { RENDER_STATES, isRenderState } = await import(url('render-state.js'));
const model = await import(url('lobby-model.js'));

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');

const budget = new model.DiscrepancyBudget();
const admitted = (payload.observations ?? []).map(([held, total]) => budget.admits(held, total));

console.log(JSON.stringify({
    render_states: RENDER_STATES,
    // The membership predicate, sampled on values the caller names — so a test can assert the
    // client does not KNOW a value as well as that it does not render it as one.
    membership: Object.fromEntries((payload.membership_probe ?? []).map((v) => [String(v), isRenderState(v)])),
    model: payload.snapshot === undefined ? null : model.lobbyModel(payload.snapshot),
    budget: { admitted, spent: budget.spent },
}, null, 2));

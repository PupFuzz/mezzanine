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
 *                   "layout": <the floors GET /api/building answered (§ 4.6, D2 § 8.7), or absent =
 *                              the empty layout's floors, `[]`>,
 *                   "observations": [[held, total], …],
 *                   "cab": <the stop the viewer last rode to, or absent>,
 *                   "primitives": <absent, or a list of argument lists for `floors()` / `plates()`>,
 *                   "scenario": <absent, or a scripted run of the building surface — below> }`
 * stdout — JSON: `{ "render_states": [...], "model": {...}, "building": {...},
 *                   "primitives": { "floors": [...], "plates": [...] } | null, "budget": {...},
 *                   "scenario": [<one record per step>] | null }`
 *
 * THE SCENARIO drives the shipped `lobby-entry.js` and `../wire/building.js` against a scripted
 * `fetch` — `{ "responses": { "<path>": [{ "status", "body" } | { "status", "text" } |
 * { "unreachable": true }, …] }, "rendered": [install_id, …], "steps": [{ "do": "enter" |
 * "snapshot" | "rooms" | "room.map" | "building.layout", … }] }` — and records, after every step,
 * every request issued so far IN ORDER, what the step returned, the rooms the client holds, and the
 * lobby it would render. ⛔ A REQUEST THE SCENARIO SCRIPTED NO RESPONSE FOR EXITS NON-ZERO: the
 * client reads a `fetch` that throws as *the browser could not reach the server*, so an unscripted
 * request answered by a throw would turn a request the test never expected into a green failure
 * render.
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
const building = await import(url('building-model.js'));

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');

const budget = new model.DiscrepancyBudget();
const admitted = (payload.observations ?? []).map(([held, total]) => budget.admits(held, total));

console.log(JSON.stringify({
    render_states: RENDER_STATES,
    // The membership predicate, sampled on values the caller names — so a test can assert the
    // client does not KNOW a value as well as that it does not render it as one.
    membership: Object.fromEntries((payload.membership_probe ?? []).map((v) => [String(v), isRenderState(v)])),
    model: payload.snapshot === undefined ? null : model.lobbyModel(payload.snapshot, payload.layout ?? []),
    // § 4.1's cross-section and its elevator. `cab` is the viewer's own position (§ 4.5:
    // navigation is never state), so it is an INPUT here and is never read out of the snapshot.
    building: payload.snapshot === undefined
        ? null
        : building.buildingModel(payload.snapshot, payload.cab ?? null, payload.layout ?? []),
    // The composition PRIMITIVES, called directly: `floors()` and `plates()`, once per argument list
    // named here, each list spread after the snapshot. So `[]` calls with no layout argument at all
    // and `[null]` passes `null`, the two a JSON `layout` member cannot tell apart.
    primitives: payload.primitives === undefined ? null : {
        floors: payload.primitives.map((args) => model.floors(payload.snapshot, ...args)),
        plates: payload.primitives.map((args) => building.plates(payload.snapshot, ...args)),
    },
    budget: { admitted, spent: budget.spent },
    scenario: payload.scenario === undefined ? null : await runScenario(payload.scenario),
}, null, 2));

/** The scripted run — see this file's header. Imported only when asked for. */
async function runScenario(scenario) {
    const entry = await import(url('lobby-entry.js'));
    const { Building } = await import(url('../wire/building.js'));

    const queues = Object.create(null);

    for (const [path, list] of Object.entries(scenario.responses ?? {})) {
        queues[path] = [...list];
    }

    const requests = [];
    const unscripted = [];

    const fetchImpl = async (path) => {
        requests.push(path);

        const next = (queues[path] ?? []).shift();

        if (next === undefined) {
            unscripted.push(path);
            throw new Error(`unscripted request: GET ${path}`);
        }

        if (next.unreachable === true) {
            throw new TypeError('Failed to fetch');
        }

        return {
            status: next.status,
            ok: next.status >= 200 && next.status < 300,
            json: async () => (next.text !== undefined ? JSON.parse(next.text) : next.body),
        };
    };

    const surface = new Building(fetchImpl);
    const rendered = scenario.rendered ?? [];
    const records = [];
    let snapshot = null;

    for (const step of scenario.steps ?? []) {
        let result = null;

        switch (step.do) {
            case 'enter':
                snapshot = await entry.enter(fetchImpl, surface);
                break;
            case 'snapshot':
                snapshot = await entry.fetchSnapshot(fetchImpl);
                break;
            case 'rooms':
                await surface.enterRooms(step.rooms);
                break;
            case 'room.map':
                result = await surface.applyRoomMap(step.message, step.rendered ?? rendered);
                break;
            case 'building.layout':
                result = await surface.applyLayout(step.message);
                break;
            default:
                throw new Error(`unknown scenario step: ${step.do}`);
        }

        const body = snapshot !== null && snapshot.ok ? snapshot.body : null;

        records.push({
            requests: [...requests],
            result,
            snapshot_status: snapshot === null ? null : snapshot.status,
            layout_failure: surface.layoutFailure,
            rooms: Object.fromEntries(rendered.map((id) => [id, surface.room(id)])),
            lobby: body === null ? null : model.lobbyModel(body, surface.floors, surface.layoutFailure),
            building: body === null ? null : building.buildingModel(body, payload.cab ?? null, surface.floors),
        });
    }

    if (unscripted.length > 0) {
        console.error(`the client issued requests the scenario scripted no response for: ${unscripted.join(', ')}`);
        process.exit(3);
    }

    return records;
}

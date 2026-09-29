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
 *                   "notices": [[held, total], …],           // § 4.1's words for each pair
 *                   "cab": <the stop the viewer last rode to, or absent>,
 *                   "primitives": <absent, or a list of argument lists for `floors()` / `plates()`>,
 *                   "scenario": <absent, or a scripted run of the building surface — below> }`
 * stdout — JSON: `{ "render_states": [...], "model": {...}, "building": {...},
 *                   "primitives": { "floors": [...], "plates": [...] } | null, "notices": [...],
 *                   "scenario": [<one record per step>] | null }`
 *
 * THE SCENARIO drives the shipped lobby entry — `lobby-screen.js` over the client protocol
 * (`../wire/fleet-client.js`) and `../wire/building.js` — against a scripted `fetch` and a stream
 * that opens and never speaks: `{ "responses": { "<path>": [{ "status", "body" } | { "status",
 * "text" } | { "unreachable": true }, …] }, "rendered": [install_id, …], "steps": [{ "do": "enter" |
 * "refresh" | "rooms" | "room.map" | "building.layout", … }] }`. `enter` is the page's own start —
 * the protocol's connect, then the render that asks for the layout once the snapshot has applied;
 * `refresh` is the lobby's Refresh control. It records, after every step, every request issued so
 * far IN ORDER, what the step returned, the protocol's phase, the rooms the client holds, and the
 * lobby it would render. ⛔ The stream here never delivers a message — what the stream drives is the
 * HARNESS's (`tests/Feature/Floor/fleet-client-probe.mjs`, AT-D3-15); this probe asks what the
 * lobby's entry fetches and composes, which is Appendix B row 13's gate. ⛔ A REQUEST THE SCENARIO SCRIPTED NO RESPONSE FOR EXITS NON-ZERO: the
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

import { scriptedFetch } from '../Support/scripted-fetch.mjs';

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
    // § 4.1's two ratified sentences, worded for each `(held, total)` pair named here.
    notices: (payload.notices ?? []).map(([held, total]) => model.discrepancyNotice(held, total)),
    scenario: payload.scenario === undefined ? null : await runScenario(payload.scenario),
}, null, 2));

/** The scripted run — see this file's header. Imported only when asked for. */
async function runScenario(scenario) {
    const { LobbyScreen } = await import(url('lobby-screen.js'));
    const { Building } = await import(url('../wire/building.js'));
    const { FleetClient } = await import(url('../wire/fleet-client.js'));

    // ⚠ THE SCRIPTED FETCH IS `../Support/scripted-fetch.mjs`, HOISTED AT ITS SECOND CALLER
    // (card#7341 step 3). This probe scripts no response with a `delay_ms` and passes no `schedule`,
    // so every response settles on the next microtask.
    const { fetch: fetchImpl, requests, unscripted } = scriptedFetch(scenario.responses);

    /** A stream that opens and never speaks — this probe asks what the ENTRY fetches, not what a feed drives. */
    class SilentEventSource {
        addEventListener() {}

        close() {}
    }

    // No scheduler: this client recovers nothing, so a refused cold read stays refused and the
    // run is exactly the steps it scripts.
    const client = new FleetClient(fetchImpl, SilentEventSource, { now: () => 0 });
    const surface = new Building(fetchImpl);
    const screen = new LobbyScreen(client, surface);
    const rendered = scenario.rendered ?? [];
    const records = [];
    const settle = () => new Promise((resolve) => setImmediate(resolve));

    for (const step of scenario.steps ?? []) {
        let result = null;
        let beforeSnapshot = null;

        switch (step.do) {
            case 'enter': {
                client.start();
                // The page's first render, which runs before the snapshot has answered — and what
                // had been asked for by then, which is § 4.4's "then" made observable.
                const first = screen.render(payload.cab ?? null);

                beforeSnapshot = [...requests];
                await first;
                await settle();
                break;
            }
            case 'refresh':
                await screen.refresh();
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

        // The page renders after every settled event; the render is what makes the entry's layout
        // request once a snapshot has applied.
        const frame = await screen.render(payload.cab ?? null);

        records.push({
            requests: [...requests],
            before_snapshot: beforeSnapshot,
            result,
            phase: client.phase,
            layout_failure: surface.layoutFailure,
            rooms: Object.fromEntries(rendered.map((id) => [id, surface.room(id)])),
            lobby: frame.summary,
            building: frame.building,
            discrepancy: frame.discrepancy,
            failure: frame.failure,
        });
    }

    if (unscripted.length > 0) {
        console.error(`the client issued requests the scenario scripted no response for: ${unscripted.join(', ')}`);
        process.exit(3);
    }

    return records;
}

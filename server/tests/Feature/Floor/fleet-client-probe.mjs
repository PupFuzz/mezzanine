/**
 * The probe the PHP suite drives the SHIPPED client protocol through — `node`, no dependencies, no
 * network, no DOM, no real clock. `docs/design/FLOOR.md` Appendix B row 3's **harness**.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2], so
 * the same probe runs against `public/js/wire` and against a MUTATED COPY of it — which is how
 * every planted control in this directory re-mints its defect against the real code.
 *
 * stdin  — JSON: a fixture run, `{ snapshot-less: everything is scripted }`:
 *   `{ "http":       { "<pathname>": [ {status, body|text, delay_ms} | {unreachable}, … ] },
 *      "messages":   [ { "at_ms": N, "envelope": <a `mezzanine` event's data> }, … ],
 *      "until_ms":   N,
 *      "status_keys":[ "<install/seat>", … ]   // keys to poll readStatus() for, held or not
 *      "repeat":     N                          // run the same scenario N times IN ONE PROCESS
 *      "browser_clock_ms": N                    // the BROWSER's clock at scenario t=0 (default 0)
 *      "age_ticker": true                       // start `age-readout.js`'s 1 s ticker after start()
 *      "desk_floor": true                       // start `desk/desk-floor.js` after start(): its own
 *                                               //  1 s tick, and `render()` after every settled
 *                                               //  event that is not a tick
 *      "floor":      { "key": "<floor>",        // start `floor/floor-screen.js` after start():
 *                      "local_hours": N,        //  `enter()` once, then `render()` after every
 *                      "local_minutes": N,      //  settled event. `local_*` is the VIEWER's civil
 *                                               //  time for § 4.2's clock and sky; absent, the
 *                                               //  scenario clock read as UTC
 *                      "seat": "<seat_id>",     //  § 4.4's `/floor/{floor}/{seat_id}` deep link
 *                      "scene": {               //  Appendix B row 14's scene inputs, as the painter
 *                        "measurer": {glyph_w, line_h},  //  supplies them on a page: a measurer that
 *                        "character": {w, h},   //  answers a stated width per glyph, the tree's
 *                        "box": {width, height} //  SCENE_W/SCENE_H, and — only in a planted control —
 *                      },                       //  a box other than resources/floor/furniture-box.js's
 *                      "asset_failures": [ { "at_ms": N,  //  § 9 F14 as the painter reports it:
 *                        "tileset_images": "<url>" | "assets": [ "<id>", … ] } ],  // every image of
 *                                               //  a held tileset, or the ids named
 *                      "panel": [ { "at_ms": N, //  the drill-down's USER actions, on the scenario
 *                        "action": "open" | "close" | "retry" | "more",   // clock: `open` names
 *                        "install_id": "…", "seat_id": "…" } ],           // the desk (§ 4.3)
 *                      "viewport": {width, height},  //  the viewer's viewport in CSS px (Appendix B
 *                                               //  row 15's capability floor) — absent, § 12's viewport
 *                                               //  floor itself (`VIEWPORT_FLOOR`), so every run written
 *                                               //  before row 15 draws the floor it always drew
 *                      "surface": {width, height},   //  the drawing's size, when not the viewport's
 *                      "camera": [ { "at_ms": N,  //  the viewer's camera acts (row 15): `wheel` at a
 *                        "act": "wheel", "x", "y", "delta_y", "delta_mode"?, "ctrl_key"? } | { "act": "drag", "dx", "dy" }
 *                        | { "act": "zoom", "notches" } | { "act": "fit" } | { "act": "resize", "width", "height" } ] }
 *                                               //  surface point (`delta_mode` the WheelEvent's, absent
 *                                               //  0; `ctrl_key` its `ctrlKey`, a pinch, absent false),
 *                                               //  a drag, the keyboard's and the zoom buttons' zoom
 *                                               //  about the centre, the fit-floor control, and a
 *                                               //  viewport resize. A resize is followed by a render, as
 *                                               //  a page renders on one; the other four are NOT — a
 *                                               //  page repaints the drawing's view and renders nothing
 *      "lobby":      true                       // start `lobby/lobby-screen.js` after start(),
 *                                               //  `render()` after every settled event — the
 *                                               //  LOBBY (Appendix B row 9) over this same client
 *      "reduce":     true                       // § 6.4's `prefers-reduced-motion: reduce`, as a
 *                                               //  page reads it — every § 6.2 row draws its
 *                                               //  reduced-motion form and logs `motion: false`
 *      "durations":  [ <seconds>, … ]           // `formatDuration` sampled on these, for a test's
 *                                               //  expected string — the shipped format, not a copy
 *      "health_counters": [ {…}|null, … ]       // § 5.3's fleet-counters render sampled on these
 *      "recovery":   true                       // FLOOR § 2.2 steps 7–9: construct the client WITH
 *                                               //  its scheduler, on the scenario's queue, so it
 *                                               //  detects a dead feed and re-opens; and the fake
 *                                               //  stream fires `open` / `error` as a browser's does
 *      "stream_refusals": [ {from_ms, until_ms} ] // an `EventSource` constructed inside a window
 *                                               //  errors before `open` — the `503` a maintenance
 *                                               //  window answers a re-open with (AT-D3-8)
 *    }`
 *   A message entry `{ "at_ms": N, "end": true }` ends the current stream at N — the server closing
 *   it, which a browser reports as `error` — rather than delivering an envelope.
 * stdout — JSON: `{ "runs": [ <one run per repeat> ], "durations": [ … ], "health_counters": [ … ] }`, each run
 *   `{ "records": [ … ], "final": <the last record>, "unscripted": [], "listeners": [ {type: n} ],
 *      "pending_timers": N, "rejections": [], "age_renders": [ {at, readouts} ],
 *      "streams": [ {opened_at, open_fired, refused, ended_at, closed_at} ],
 *      "desk_renders": [ {at, trigger, frame} ], "floor_renders": [ {at, frame} ],
 *      "lobby_renders": [ {at, frame} ], "camera_acts": [ {at, act, before, after, glide_ms} ],
 *      "animation_log": [ <§ 11 rows> ] }`
 *   and each record
 *   `{ "at", "label", "outcome", "seats", "event_log", "requests", "phase", "clock_offset_ms",
 *      "read_status": { "<key>": {missing, failStreak, confirmedAt} }, "discrepancy_state",
 *      "strip", "failure" }` — the last two the SHIPPED status strip and failure renders over the
 *   client's own `feed`, which is what AT-D3-7's strip half and AT-D3-8 read without a floor.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE BROWSER'S CLOCK IS THE SCENARIO CLOCK PLUS `browser_clock_ms`, and nothing else. That
 * one offset is how AT-D3-10 puts the viewer's machine three hours ahead of the server: the client
 * and the age ticker both read it, and neither can tell it from a real fast clock. A run that
 * names none starts the browser at the epoch, which is itself a wrong clock, and every existing
 * run's recorded offset is measured against it.
 *
 * ⛔ `Date.now` IS THAT SAME BROWSER CLOCK for the length of a replay, and the real one is put
 * back after it. No shipped module here reads it — `age-readout.js` and `fleet-client.js` are
 * both scanned for it — so this exists for one reader: a PLANTED module that reads the browser's
 * own clock where it should read the corrected one must read the scenario's browser, three hours
 * fast, and not the build host's calendar, or AT-D3-10's RED would be red for a reason nobody wrote.
 *
 * ⛔ THE AGE TICKER IS THE SHIPPED ONE, ON THE SCENARIO'S OWN TIMER. `setInterval` here schedules
 * a repeating event on the same queue every message and response is on, so an age render lands
 * at an exact scenario instant and is recorded as `age_renders[]` — the headless observation of
 * every age readout AT-D3-10's floor half asserts on.
 *
 * ⛔ THE DESK FLOOR IS THE SHIPPED ONE, OVER THE SHIPPED CLIENT, WRITING THE SHIPPED ANIMATION LOG
 * THROUGH THE SHIPPED ANIMATION SET. `render()` is § 2.5's apply path, so the probe calls it after
 * each settled event exactly as a page calls it after an apply — which is also what drains the
 * client's wire journal, so a run whose floor is not started journals and animates nothing. Its
 * tick is the shipped ticker on the scenario's timer, and a tick drains nothing. Every frame is
 * recorded as `desk_renders[]` with the trigger that drew it — the headless observation of a desk
 * that AT-D3-5 and AT-D3-14's desk half assert on — and every § 6.2 row the floor started as
 * `animation_log`, which is what AT-D3-1, AT-D3-2, AT-D3-13 and two render halves read.
 *
 * ⛔ THE SCENARIO CLOCK IS THE ONLY CLOCK, AND IT STARTS AT `start()`. Every `at_ms` and every
 * `delay_ms` is measured on it, and a response resolves at `request time + delay_ms`. That is what
 * makes § 2.2's 500 ms connect window REACHABLE at all: against a live D2 the stream delivers a
 * message no sooner than one visibility lag after the row is inserted, so the race would need a
 * snapshot response slower than that. The client cannot tell a scripted 500 ms from a real 2.5 s.
 *
 * ⛔ THE FAKE `EventSource` DROPS EVERY MESSAGE SCHEDULED BEFORE IT WAS CONSTRUCTED, because D2
 * never replays: no `id:` is sent, `Last-Event-ID` is ignored, and the cursor starts at head. A
 * fake that delivered them would hide the exact loss § 2.2's open-then-fetch order exists to
 * prevent, and the ordering test would pass against a client that got the order wrong.
 *
 * ⛔ ONE EXPLICIT `setImmediate` TURN SETTLES EACH EVENT. Every continuation this client runs is a
 * promise continuation, and the microtask queue drains COMPLETELY before a `setImmediate` callback
 * runs — so one turn is a fixed point, not a guess at "enough ticks". A probe that guessed would
 * make the determinism check flaky instead of red.
 *
 * ⛔ UNHANDLED REJECTIONS ARE RECORDED, NOT SWALLOWED. A `200` whose body is not an object, read as
 * a success, throws inside the client's own fetch continuation — and a probe that let that pass
 * would report a green run for a client that has stopped discovering anything for the rest of the
 * connection.
 *
 * ⛔ THE FLOOR SCREEN IS THE SHIPPED ONE, OVER THE SHIPPED CLIENT AND THE SHIPPED BUILDING CLIENT,
 * AND IT IS MUTUALLY EXCLUSIVE WITH `desk_floor`. Both own § 2.5's apply path and both drain the
 * journal, so a scenario naming the two would be a run with two renderers racing for one drain — and
 * the floor screen already runs the desk floor inside itself. A scenario naming both is refused
 * rather than resolved in favour of one.
 *
 * ⛔ THE LOBBY IS THE SHIPPED ONE TOO, AND IT IS EXCLUSIVE WITH BOTH OTHER RENDERERS FOR THEIR REASON:
 * it drains the journal (it applies `building.layout` from it), so a run naming it beside `floor` or
 * `desk_floor` would be two renderers racing for one drain. Its building requests go through the
 * microtask transport the floor's do, for the floor's reason: the lobby awaits them inside its render.
 *
 * ⛔ THE VIEWER'S CIVIL TIME IS THE SCENARIO'S, NEVER THE BUILD HOST'S. § 4.2's sky has four phases
 * by the viewer's own hour, and a host in another zone would read a different one out of the same
 * fixture — a test whose answer depends on where it ran. So the probe supplies § 4.2's civil-time
 * reader: the scenario's own `local_hours`/`local_minutes` where it states them, else the scenario
 * clock read as UTC, and the shipped `Date`-based reader is left for a browser.
 *
 * ⛔ A FIXTURE NAMES A SHIPPED FILE RATHER THAN COPYING IT (Appendix B row 14). Anywhere in the
 * payload, the string `@json:<repo path>` is replaced by that file parsed and `@text:<repo path>` by
 * its text — so a run "on the shipped default map" replays `resources/floor/default.tmj` itself and
 * a tileset response is the vendored `.tsx`, never a copy that drifts from them — and
 * `@box.width` / `@box.height`, optionally `*N` and then `+N` or `-N`, by the furniture box
 * `resources/floor/furniture-box.js` declares, so a stubbed map sized against the box moves with it.
 * The scene's box and desk sprite are that same module, imported from disk.
 *
 * ⛔ AN UNSCRIPTED REQUEST IS REPORTED IN THE RECORD, NOT AS AN EXIT CODE. A planted control must
 * red on the FIELD its plant diverges on; a plant that also happens to issue an extra request
 * would otherwise kill the probe and "pass" for the wrong reason.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { scriptedFetch } from '../Support/scripted-fetch.mjs';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node fleet-client-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const { FleetClient } = await import(pathToFileURL(join(dir, 'fleet-client.js')).href);
const { startAgeTicker } = await import(pathToFileURL(join(dir, 'age-readout.js')).href);
const { formatDuration } = await import(pathToFileURL(join(dir, 'duration.js')).href);
const { createAnimationLog } = await import(pathToFileURL(join(dir, 'animation-log.js')).href);
const { startDeskFloor } = await import(pathToFileURL(join(dir, '..', 'desk', 'desk-floor.js')).href);
const { startFloorScreen, VIEWPORT_FLOOR } = await import(pathToFileURL(join(dir, '..', 'floor', 'floor-screen.js')).href);
const { statusStrip } = await import(pathToFileURL(join(dir, '..', 'floor', 'status-strip.js')).href);
const { failureRender } = await import(pathToFileURL(join(dir, 'failure-render.js')).href);
const { startLobbyScreen } = await import(pathToFileURL(join(dir, '..', 'lobby', 'lobby-screen.js')).href);
const { healthCounters } = await import(pathToFileURL(join(dir, '..', 'lobby', 'lobby-model.js')).href);

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const furniture = await import(pathToFileURL(join(repoRoot, 'resources', 'floor', 'furniture-box.js')).href);

/** The payload with every `@json:` / `@text:` / `@box.` reference replaced (see the header). */
function substitute(node) {
    if (typeof node === 'string') {
        if (node.startsWith('@json:')) {
            return JSON.parse(readFileSync(join(repoRoot, node.slice(6)), 'utf8'));
        }

        if (node.startsWith('@text:')) {
            return readFileSync(join(repoRoot, node.slice(6)), 'utf8');
        }

        const box = /^@box\.(width|height)(?:\*(\d+))?(?:([+-])(\d+))?$/.exec(node);

        if (box !== null) {
            const n = furniture.FURNITURE_BOX[box[1]] * Number(box[2] ?? 1);
            const k = Number(box[4] ?? 0);

            return box[3] === '-' ? n - k : n + k;
        }

        return node;
    }

    if (Array.isArray(node)) {
        return node.map(substitute);
    }

    if (node !== null && typeof node === 'object') {
        return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, substitute(v)]));
    }

    return node;
}

const fx = substitute(JSON.parse(readFileSync(0, 'utf8') || '{}'));

let rejections = [];
process.on('unhandledRejection', (error) => {
    rejections.push(String(error?.message ?? error));
});

const turn = () => new Promise((resolve) => setImmediate(resolve));

const runs = [];

for (let i = 0; i < (fx.repeat ?? 1); i++) {
    runs.push(await replay(fx));
}

console.log(JSON.stringify({
    runs,
    durations: (fx.durations ?? []).map((seconds) => formatDuration(seconds)),
    // § 5.3's fleet-counters render, sampled on the `counters` objects the caller names — the shipped
    // function, for AT-D3-14's panel half (`health_counters: [<counters>|null, …]`).
    health_counters: (fx.health_counters ?? []).map((counters) => healthCounters(counters)),
}, null, 2));

async function replay(scenario) {
    rejections = [];

    let now = 0;
    const clock = { now: () => (scenario.browser_clock_ms ?? 0) + now };
    const realDateNow = Date.now;
    Date.now = clock.now;

    const timers = [];
    let seq = 0;
    const schedule = (at, label, fire) => {
        const timer = { at, seq: seq++, label, fire };

        timers.push(timer);

        return timer;
    };

    // ⛔ TWO TRANSPORTS OVER ONE RESPONSE MAP, AND THE SPLIT IS THE POINT. The protocol's requests
    // settle on the SCENARIO CLOCK, because § 2.2's 500 ms connect window is a timing rule the
    // protocol is judged on. The building surface's settle on the next MICROTASK, because the floor
    // screen awaits them INSIDE its own apply path (§ 4.4's entry, and the `room.map` act § 2.5
    // gives it) — a response that could only settle when this loop pumps would deadlock a render
    // the loop is itself awaiting. § 4.4 states no timing rule for either building request, so
    // nothing is lost: what a test asserts about them is WHICH path was asked for and WHAT the
    // screen did with the answer.
    // The asset route's requests (Appendix B row 14's tilesets) are awaited inside the render too,
    // so they take the building's transport for the building's reason.
    const microtask = (path) => path.startsWith('/api/building') || path.startsWith('/art/');
    const buildingPaths = Object.fromEntries(Object.entries(scenario.http ?? {})
        .filter(([path]) => microtask(path)));
    const fleetPaths = Object.fromEntries(Object.entries(scenario.http ?? {})
        .filter(([path]) => !microtask(path)));

    const http = scriptedFetch(fleetPaths, {
        schedule: (delay, label, fire) => schedule(now + delay, label, fire),
        clock: () => now,
    });
    const buildingHttp = scriptedFetch(buildingPaths);

    const streams = [];

    const recovery = scenario.recovery === true;
    const refusedAt = (t) => (scenario.stream_refusals ?? []).some((w) => t >= w.from_ms && t < w.until_ms);

    class FakeEventSource {
        constructor(path) {
            this.path = path;
            this.openedAt = now;
            this.listeners = {};
            this.refused = false;
            this.openFired = false;
            streams.push(this);

            // ⛔ ONLY A RECOVERY SCENARIO HEARS `open` AND `error`. Every scenario written before
            // Appendix B step 8 replays exactly as it did — the same events on the same queue — and
            // a client with no scheduler registers no listener for either.
            if (recovery) {
                const refused = refusedAt(now);

                schedule(now, refused ? 'stream refused' : 'stream open', () => {
                    if (this.closedAt !== undefined) {
                        return 'closed';
                    }

                    if (refused) {
                        this.refused = true;
                        this.endedAt = now;
                        this.fire('error');

                        return 'refused';
                    }

                    this.openFired = true;
                    this.fire('open');

                    return 'opened';
                });
            }
        }

        addEventListener(type, callback) {
            (this.listeners[type] ??= []).push(callback);
        }

        fire(type) {
            for (const callback of this.listeners[type] ?? []) {
                callback({ type });
            }
        }

        close() {
            this.closedAt = now;
        }
    }

    for (const message of scenario.messages ?? []) {
        if (message.end === true) {
            schedule(message.at_ms, 'stream end', () => {
                const es = streams[streams.length - 1];

                if (es === undefined || es.closedAt !== undefined || es.refused || es.endedAt !== undefined) {
                    return 'dropped';
                }

                es.endedAt = now;
                es.fire('error');

                return 'ended';
            });

            continue;
        }

        const label = `message ${message.envelope.t} ${message.envelope.seat_id ?? ''} ${message.envelope.state_version ?? ''}`;

        schedule(message.at_ms, label, () => {
            const es = streams[streams.length - 1];

            if (es === undefined || es.openedAt > message.at_ms || es.closedAt !== undefined
                || es.refused || es.endedAt !== undefined) {
                return 'dropped';
            }

            for (const callback of es.listeners.mezzanine ?? []) {
                callback({ data: JSON.stringify(message.envelope) });
            }

            return 'delivered';
        });
    }

    // The recovery's scheduler, on the scenario queue. A cancelled timer is REMOVED from the queue
    // rather than left to fire as a no-op, so a cancellation takes no turn and draws no render.
    const recoveryTimers = {
        after(ms, fire) {
            return schedule(now + ms, 'recovery timer', () => {
                fire();

                return 'fired';
            });
        },
        cancel(timer) {
            const i = timers.indexOf(timer);

            if (i >= 0) {
                timers.splice(i, 1);
            }
        },
    };

    const client = new FleetClient(http.fetch, FakeEventSource, clock, recovery ? recoveryTimers : null);
    const records = [];
    const ageRenders = [];
    const deskRenders = [];
    const floorRenders = [];
    const lobbyRenders = [];
    const cameraActs = [];
    const log = createAnimationLog();
    let floor = null;
    let screen = null;
    let lobby = null;

    if ([scenario.desk_floor === true, scenario.floor !== undefined, scenario.lobby === true].filter(Boolean).length > 1) {
        throw new Error('a scenario names more than one of `desk_floor`, `floor` and `lobby`: two renderers, one journal');
    }

    // The shipped ticker's timer, on the scenario queue: one repeating event per interval.
    const intervals = new Set();
    let intervalId = 0;
    const timersImpl = {
        setInterval(fire, ms) {
            const id = ++intervalId;
            const arm = (at) => schedule(at, 'age tick', () => {
                if (!intervals.has(id)) {
                    return 'cleared';
                }

                fire();
                arm(at + ms);

                return 'ticked';
            });

            intervals.add(id);
            arm(now + ms);

            return id;
        },
        clearInterval(id) {
            intervals.delete(id);
        },
    };

    const snap = (label, outcome = null) => {
        const seats = [...client.seats];
        const keys = [...new Set([...seats.map(([k]) => k), ...(scenario.status_keys ?? [])])].sort();

        records.push({
            at: now,
            label,
            outcome,
            seats: Object.fromEntries(seats.map(([k, v]) => [k, JSON.parse(JSON.stringify(v))])),
            event_log: client.eventLog,
            // The protocol's requests in the scenario clock's order, then the building's. A test
            // asserts over a FILTERED surface (`seatRequests()`, `snapshotRequests()`), so the two
            // lists are concatenated rather than interleaved on a clock only one of them reads.
            requests: [...http.requests, ...buildingHttp.requests],
            phase: client.phase,
            clock_offset_ms: client.clockOffsetMs,
            read_status: Object.fromEntries(keys.map((k) => [k, client.readStatus(k)])),
            discrepancy_state: client.discrepancyState(),
            strip: JSON.parse(JSON.stringify(statusStrip(client.feed, client.fleet, { reduce: scenario.reduce === true }))),
            failure: JSON.parse(JSON.stringify(failureRender(client.feed))),
        });
    };

    // Before `start()`: constructed, holding nothing, having asked for nothing.
    snap('pre-start');

    client.start();

    if (scenario.age_ticker === true) {
        startAgeTicker(client, clock, timersImpl, (readouts) => {
            ageRenders.push({ at: now, readouts: JSON.parse(JSON.stringify(readouts)) });
        });
    }

    if (scenario.desk_floor === true) {
        floor = startDeskFloor(client, clock, timersImpl, log, (frame, trigger) => {
            deskRenders.push({ at: now, trigger, frame: JSON.parse(JSON.stringify(frame)) });
        }, { reduce: scenario.reduce === true });
    }

    if (scenario.floor !== undefined) {
        screen = startFloorScreen(client, buildingHttp.fetch, clock, log, (frame) => {
            floorRenders.push({ at: now, frame: JSON.parse(JSON.stringify(frame)) });
        }, {
            floor: scenario.floor.key,
            seat: scenario.floor.seat ?? null,
            reduce: scenario.reduce === true,
            local_time: (ms) => (scenario.floor.local_hours === undefined
                ? { hours: new Date(ms).getUTCHours(), minutes: new Date(ms).getUTCMinutes() }
                : { hours: scenario.floor.local_hours, minutes: scenario.floor.local_minutes ?? 0 }),
            viewport: scenario.floor.viewport ?? VIEWPORT_FLOOR,
            surface: scenario.floor.surface,
        });
    }

    // Appendix B row 14's scene inputs, as the painter supplies them on a page: the furniture box and
    // the desk sprite from `resources/floor/furniture-box.js`, a measurer that answers a stated width
    // per glyph, and the character's size as the fixture states it.
    if (screen !== null && scenario.floor.scene !== undefined) {
        const { measurer, character, box } = scenario.floor.scene;

        screen.sceneInputs({
            box: box ?? furniture.FURNITURE_BOX,
            desk_sprite: furniture.DESK_SPRITE,
            measure: (text) => ({ w: [...String(text)].length * measurer.glyph_w, h: measurer.line_h }),
            character,
        });
    }

    // § 9 F14, as the painter reports it — each an event on the scenario queue, followed by a render
    // like any other event. `tileset_images` names a HELD tileset and fails every image it declares.
    for (const failure of scenario.floor?.asset_failures ?? []) {
        schedule(failure.at_ms, 'asset failure', () => {
            if (screen === null) {
                return 'no floor';
            }

            const held = failure.tileset_images === undefined ? null : screen.tilesets().get(failure.tileset_images);

            if (failure.tileset_images !== undefined && held?.tileset === undefined) {
                throw new Error(`asset_failures names the tileset ${failure.tileset_images}, which the page does not hold`);
            }

            screen.assetsFailed(held === null ? failure.assets : held.tileset.images);

            return 'reported';
        });
    }

    // The drill-down's user actions (Appendix B row 10), each an event on the scenario queue like a
    // message. None is awaited inside its event: the panel's requests settle on the scenario clock,
    // which only this loop advances, so awaiting one here would wait for itself.
    for (const act of scenario.floor?.panel ?? []) {
        schedule(act.at_ms, `panel ${act.action}`, () => {
            if (screen === null) {
                return 'no floor';
            }

            switch (act.action) {
                case 'open':
                    screen.openPanel(act.install_id, act.seat_id);
                    break;
                case 'close':
                    screen.closePanel();
                    break;
                case 'retry':
                    screen.retryPanel();
                    break;
                case 'more':
                    screen.morePanel();
                    break;
                default:
                    throw new Error(`unknown panel action ${act.action}`);
            }

            return act.action;
        });
    }

    // Appendix B row 15's camera acts, each an event on the scenario queue, recorded with the camera
    // before and after it. Only a resize is followed by a render (see the header).
    for (const act of scenario.floor?.camera ?? []) {
        schedule(act.at_ms, act.act === 'resize' ? 'viewport resize' : `camera ${act.act}`, () => {
            if (screen === null) {
                return 'no floor';
            }

            const before = screen.camera();
            let glide = null;

            switch (act.act) {
                case 'wheel':
                    screen.wheel({ x: act.x, y: act.y }, { deltaY: act.delta_y, deltaMode: act.delta_mode ?? 0, ctrlKey: act.ctrl_key ?? false });
                    break;
                case 'zoom':
                    screen.zoomStep(act.notches);
                    break;
                case 'drag':
                    screen.drag(act.dx, act.dy);
                    break;
                case 'fit':
                    glide = screen.fitFloor().glide_ms;
                    break;
                case 'resize':
                    screen.resize({ width: act.width, height: act.height });
                    break;
                default:
                    throw new Error(`unknown camera act ${act.act}`);
            }

            cameraActs.push({ at: now, act, before, after: screen.camera(), glide_ms: glide });

            return act.act;
        });
    }

    if (scenario.lobby === true) {
        lobby = startLobbyScreen(client, buildingHttp.fetch, (frame) => {
            lobbyRenders.push({ at: now, frame: JSON.parse(JSON.stringify(frame)) });
        });
    }

    await turn();
    floor?.render();

    if (lobby !== null) {
        await lobby.render();
        await turn();
    }

    if (screen !== null) {
        // § 4.4's floor entry: the layout, then each room's map. It runs after `start()` because
        // "deep-linking to a floor on a cold start runs the whole of § 2.2 first".
        await screen.enter();
        await turn();
        await screen.render();
    }

    snap('start');

    for (;;) {
        timers.sort((a, b) => a.at - b.at || a.seq - b.seq);

        const timer = timers[0];

        if (timer === undefined || timer.at > scenario.until_ms) {
            break;
        }

        timers.shift();
        now = timer.at;

        const outcome = timer.fire();

        await turn();

        // A tick re-reads ages and a camera act moves the viewer's head: neither is an apply, and a
        // page renders after neither.
        if (timer.label !== 'age tick' && !timer.label.startsWith('camera ')) {
            floor?.render();

            if (screen !== null) {
                await screen.render();
                await turn();
            }

            if (lobby !== null) {
                await lobby.render();
                await turn();
            }
        }

        snap(timer.label, outcome ?? null);
    }

    Date.now = realDateNow;

    return {
        records,
        final: records[records.length - 1],
        unscripted: [...http.unscripted, ...buildingHttp.unscripted],
        listeners: streams.map((s) => Object.fromEntries(
            Object.entries(s.listeners).map(([type, list]) => [type, list.length]),
        )),
        pending_timers: timers.length,
        streams: streams.map((s) => ({
            opened_at: s.openedAt,
            open_fired: s.openFired,
            refused: s.refused,
            ended_at: s.endedAt ?? null,
            closed_at: s.closedAt ?? null,
        })),
        rejections: [...rejections],
        age_renders: ageRenders,
        desk_renders: deskRenders,
        floor_renders: floorRenders,
        lobby_renders: lobbyRenders,
        camera_acts: cameraActs,
        animation_log: log.rows,
    };
}

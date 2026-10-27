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
 *                        "character": {w, h},   //  answers a stated width per glyph PER TYPE ROLE
 *                                               //  (`Support/harness-measurer.mjs`), the tree's
 *                        "box": {width, height} //  SCENE_W/SCENE_H, and — only in a planted control —
 *                      },                       //  a box other than resources/floor/furniture-box.js's
 *                      "asset_failures": [ { "at_ms": N,  //  § 9 F14 as the painter reports it:
 *                        "tileset_images": "<url>" | "assets": [ "<id>", … ] } ],  // every image of
 *                                               //  a held tileset, or the ids named
 *                      "panel": [ { "at_ms": N, //  the drill-down's USER actions, on the scenario
 *                        "action": "open" | "close" | "retry" | "more",   // clock: `open` names
 *                        "install_id": "…", "seat_id": "…" } ],           // the desk (§ 4.3)
 *                      "viewport": {width, height},  //  the viewer's window in CSS px, which is the
 *                                               //  drawing surface the screen is handed (Appendix B
 *                                               //  row 15) — absent, `HARNESS_SURFACE`
 *                                               //  (`../Support/harness-surface.mjs`), the size every
 *                                               //  run written before card#7341's 2026-10-01 ruling
 *                                               //  was drawn at
 *                      "camera": [ { "at_ms": N,  //  the viewer's camera acts (row 15, § 4.5): `scroll`, a
 *                        "act": "scroll", "delta_x"?, "delta_y"?, "delta_mode"? } | { "act": "ctrl_wheel", "x", "y",
 *                        "delta_y", "delta_mode"? } | { "act": "pinch", "from": {x, y}, "to": {x, y}, "factor" }
 *                        | { "act": "drag", "dx", "dy" } | { "act": "zoom", "notches" } | { "act": "fit" }
 *                        | { "act": "resize", "width", "height" } ] }
 *                                               //  plain wheel event (the screen's `pan()`; each delta
 *                                               //  absent 0, `delta_mode` the WheelEvent's, absent 0), a
 *                                               //  Ctrl+wheel event at a surface point (its `zoom()`), one
 *                                               //  step of a touch pinch (its `pinch()`; the midpoint
 *                                               //  `from` → `to`, the spread's ratio `factor`) — the wheel's
 *                                               //  two recorded with the `consumed` they answer —
 *                                               //  a drag, the keyboard's and the zoom buttons' zoom
 *                                               //  about the centre, the fit-floor control, and a
 *                                               //  viewport resize. A resize is followed by a render, as
 *                                               //  a page renders on one; the other four are NOT — a
 *                                               //  page repaints the drawing's view and renders nothing
 *      "lobby":      true                       // start `lobby/lobby-screen.js` after start(),
 *                                               //  `render()` after every settled event — the
 *                                               //  LOBBY (Appendix B rows 9 and 24) over this same client
 *                                               //  (`"floor": null` and `"lobby": false` start
 *                                               //  neither: how a test replays a run written for one
 *                                               //  of them under `desk_floor` — the same bytes, on
 *                                               //  another page) — or an object: `{ "surface": {width,
 *                                               //  height},` the room's drawing surface (absent,
 *                                               //  `HARNESS_SURFACE`), `"lobby_href": "<url>"` the cab's
 *                                               //  *Lobby* stop's href, `"tick": true` the 1 s tick
 *                                               //  (`age-readout.js`'s ticker, the page's `tickView()`
 *                                               //  painted with the trigger `tick`), `"camera": [ { "at_ms":
 *                                               //  N, "act": "fit" } | { "act": "focus_elevator" } | {
 *                                               //  "act": "focus_board" } | { "act": "scroll", … } | {
 *                                               //  "act": "ctrl_wheel", … } | { "act": "pinch", … } (the
 *                                               //  floor's three) | { "act": "zoom", "notches" } | {
 *                                               //  "act": "drag", "dx", "dy" } | { "act": "resize",
 *                                               //  "width", "height" } ]`, none followed by a render, and
 *                                               //  `"ride": [ … ]` — the floor's `floor.ride` acts, each
 *                                               //  the lobby screen's method (`lobby.ride`, card#12113),
 *                                               //  recorded in `ride_acts[]` and painted into
 *                                               //  `lobby_renders[]` with the trigger `ride`;
 *                                               //  `"local_hours": N, "local_minutes": N` the viewer's
 *                                               //  civil time for the sky, as the floor's
 *      "floor.lobby_href": "<url>"              // the floor page's own *Back to the lobby* link's href,
 *                                               //  handed to the screen as the page hands it (§ 4.5's
 *                                               //  ride, card#11795) — the cab's lobby stop
 *      "floor.ride": [ { "at_ms": N, "act": "ride" | "entered" | "shut" | "choose", "key": "<floor>"|null
 *                        | "opened" | "abandoned" | "arrived" | "returned" | "cab_closed" | "escape",
 *                        "cancelable": bool | "arrive_by_ride", "expires_in_ms": N } ]
 *                                               //  the elevator ride's acts (§ 4.5): each the screen's
 *                                               //  method of that name (`cab_closed` its `cabClosed()`,
 *                                               //  `escape` the cab's `cancel` with its `cancelable`), its
 *                                               //  answer recorded in `ride_acts[]`, and — when it answered
 *                                               //  — `rideView()` drawn as the page paints it (`trigger`
 *                                               //  `ride`). None is followed by a render: a ride act
 *                                               //  applies nothing
 *      "arrive_by_ride": { "expires_in_ms": N } // with a `floor` or a `lobby`: the page entered by a ride —
 *                                               //  the screen's `arriveByRide()` before its first render, as
 *                                               //  `floor/main.js` and `lobby/main.js` call it from the head
 *                                               //  script's record
 *      "scene_inputs_after_enter": true | { "at_ms": N }
 *                                               // with a `floor.scene`: the scene's inputs supplied AFTER
 *                                               //  § 4.4's `enter()` and one `render()` (`true`), or as an
 *                                               //  event at N followed by a render — the art answering
 *                                               //  after the layout, which a page meets on every cold load:
 *                                               //  the frames before it carry a floor and no scene
 *      "render_before_enter": true              // with a `floor`: one `render()` BEFORE § 4.4's
 *                                               //  `enter()`, as the page draws one while that entry's
 *                                               //  layout request is still in flight — its
 *                                               //  ResizeObserver, the stream's events and the art's
 *                                               //  answer each ask for a render (`floor/main.js`)
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
 *      "session_lifetime_ms": N                 // with `recovery`: the session lifetime a page hands
 *                                               //  the client (card#12434), which turns its session
 *                                               //  keepalive on. Absent, the client sends none
 *    }`
 *   A message entry `{ "at_ms": N, "end": true }` ends the current stream at N — the server closing
 *   it, which a browser reports as `error` — rather than delivering an envelope.
 * stdout — JSON: `{ "runs": [ <one run per repeat> ], "durations": [ … ], "health_counters": [ … ] }`, each run
 *   `{ "records": [ … ], "final": <the last record>, "unscripted": [], "listeners": [ {type: n} ],
 *      "pending_timers": N, "rejections": [], "age_renders": [ {at, readouts} ],
 *      "streams": [ {opened_at, open_fired, refused, ended_at, closed_at} ],
 *      "desk_renders": [ {at, trigger, frame, …} ], "floor_renders": [ {at, trigger, frame} ] — `trigger`
 *      `render` for a render, `walk end` for the paint-only refresh at the end of a walk or of any other effect in flight, `ride` for the frame a page paints
 *      after a ride act (`rideView()`), "ride_acts": [ {at, act, answer, ride, scene_view_ride} ] — each ride act's
 *      answer, the `ride` of the frame painted after it (`null` when it answered `null` and nothing was painted)
 *      and the `ride` of the 1 s tick's scene over that frame's desks (`sceneView()`),
 *      "lobby_renders": [ {at, trigger, frame} ] — `trigger` `render`, `ride` for the frame a page paints
 *      after a ride act (`rideView()`) and `tick` for the 1 s tick's (`tickView()`) — "camera_acts": [ {at,
 *      act, before, after, glide_ms} ] on either page —
 *      "animation_log": [ <§ 11 rows> ] }`
 *   and each record
 *   `{ "at", "label", "outcome", "seats", "event_log", "requests", "phase", "clock_offset_ms",
 *      "read_status": { "<key>": {missing, failStreak, confirmedAt} }, "discrepancy_state",
 *      "strip", "failure" }` — the last two the SHIPPED status strip and failure renders over the
 *   client's own `feed`, which is what AT-D3-7's strip half and AT-D3-8 read without a floor.
 *   A `desk_renders[]` entry whose trigger is `apply` also carries AT-D3-1's per-render record:
 *   `{ "stilled", "held": { "<key>": {object, missing} }, "removals": [ {key, cause} ], "rows" }` —
 *   the stilled floor (§ 9 F6), each held seat's object and § 2.3 row 5's state, the removals that
 *   render's journal applied with their `cause`, and the animation-log rows that render wrote.
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
import { HARNESS_SURFACE } from '../Support/harness-surface.mjs';
import { harnessMeasurer } from '../Support/harness-measurer.mjs';

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
const { startFloorScreen } = await import(pathToFileURL(join(dir, '..', 'floor', 'floor-screen.js')).href);
const { statusStrip } = await import(pathToFileURL(join(dir, '..', 'floor', 'status-strip.js')).href);
const { failureRender } = await import(pathToFileURL(join(dir, 'failure-render.js')).href);
const { startLobbyScreen } = await import(pathToFileURL(join(dir, '..', 'lobby', 'lobby-screen.js')).href);
const { healthCounters } = await import(pathToFileURL(join(dir, '..', 'lobby', 'lobby-model.js')).href);

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const furniture = await import(pathToFileURL(join(repoRoot, 'resources', 'floor', 'furniture-box.js')).href);
const themeRegistry = await import(pathToFileURL(join(repoRoot, 'resources', 'floor', 'themes', 'index.js')).href);
// The character tree's seeded draw, from disk, as the page hands the scene the tree's (a departure's wait, card#12194).
const { draw: seededDraw } = await import(pathToFileURL(join(repoRoot, 'resources', 'characters', 'index.js')).href);
// FLOOR.md § 10.6 item 1: every theme the registry names, from disk, as the page imports each by the asset route.
const themeModules = Object.fromEntries(await Promise.all(themeRegistry.THEMES.map(async (name) => [
    name,
    await import(pathToFileURL(join(repoRoot, 'resources', 'floor', 'themes', name, 'theme.js')).href),
])));

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

/**
 * The viewer's civil time for § 4.2's clock and sky, on the floor or the lobby (`floor` or `lobby` in the
 * scenario): its own `local_hours`/`local_minutes` where it states them, else the scenario clock read as
 * UTC — see the header's *the viewer's civil time is the scenario's*.
 */
function viewerTime(run) {
    return (ms) => (run.local_hours === undefined
        ? { hours: new Date(ms).getUTCHours(), minutes: new Date(ms).getUTCMinutes() }
        : { hours: run.local_hours, minutes: run.local_minutes ?? 0 });
}

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

    const client = new FleetClient(http.fetch, FakeEventSource, clock, recovery ? recoveryTimers : null,
        { sessionLifetimeMs: scenario.session_lifetime_ms ?? null });
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
    // `lobby: true` is the lobby with no camera acts; an object states them (see the header).
    const lobbyRun = scenario.lobby === true ? {} : (typeof scenario.lobby === 'object' && scenario.lobby !== null ? scenario.lobby : null);

    if ([scenario.desk_floor === true, (scenario.floor ?? null) !== null, lobbyRun !== null].filter(Boolean).length > 1) {
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
        // What each apply render drained, observed at the one drain rather than re-derived: the
        // desk floor calls `takeWire()` once per `render()`, and never on a tick.
        let drained = [];
        const takeWire = client.takeWire.bind(client);
        client.takeWire = () => (drained = takeWire());
        let logged = 0;

        floor = startDeskFloor(client, clock, timersImpl, log, (frame, trigger) => {
            const render = { at: now, trigger, frame: JSON.parse(JSON.stringify(frame)) };

            // AT-D3-1's per-render record (Appendix B row 15 (c)): what § 11's precedence compares
            // one render with the one before it on, and the rows that render wrote. A tick drains
            // nothing and writes no row, so only an apply carries it.
            if (trigger === 'apply') {
                const rows = log.rows;

                render.stilled = (client.feed?.signed_out ?? null) !== null;
                render.held = Object.fromEntries([...client.seats].map(([k, v]) => [k, {
                    object: JSON.parse(JSON.stringify(v)),
                    missing: client.readStatus(k).missing,
                }]));
                render.removals = drained.filter((entry) => entry.t === 'seat.removed')
                    .map((entry) => ({ key: `${entry.install_id}/${entry.seat_id}`, cause: entry.cause }));
                render.rows = rows.slice(logged);
                logged = rows.length;
                drained = [];
            }

            deskRenders.push(render);
        }, { reduce: scenario.reduce === true });
    }

    if ((scenario.floor ?? null) !== null) {
        screen = startFloorScreen(client, buildingHttp.fetch, clock, log, (frame, trigger = 'render') => {
            floorRenders.push({ at: now, trigger, frame: JSON.parse(JSON.stringify(frame)) });
        }, {
            floor: scenario.floor.key,
            seat: scenario.floor.seat ?? null,
            reduce: scenario.reduce === true,
            local_time: viewerTime(scenario.floor),
            surface: scenario.floor.viewport ?? HARNESS_SURFACE,
            lobby_href: scenario.floor.lobby_href ?? null,
            // FLOOR § 6.2's walk note item 4: a walk's last frame, on the scenario queue under its own
            // label — a paint-only refresh the screen draws through the same callback as `walk end`,
            // which the loop below never follows with a render.
            timers: {
                after: (ms, fire) => schedule(now + ms, 'walk end', () => {
                    fire();

                    return 'refreshed';
                }),
                cancel: (timer) => {
                    const i = timers.indexOf(timer);

                    if (i >= 0) {
                        timers.splice(i, 1);
                    }
                },
            },
        });
    }

    // Appendix B row 14's scene inputs, as the painter supplies them on a page: the furniture box from
    // `resources/floor/furniture-box.js`, a measurer that answers a stated width per glyph in each type
    // role, the character's size as the fixture states it, and the themes (FLOOR.md § 10.6) through the
    // page's own `themeInputs()`. A run may reject the registry's import (`themes.registry: false`) or a
    // theme's (`themes.reject: [name]`), and each is reported failed as `loadArt()` reports it.
    let supplyScene = null;

    if (screen !== null && scenario.floor.scene !== undefined) {
        const { measurer, character, box, themes: spec = {} } = scenario.floor.scene;
        const { themeInputs, THEME_REGISTRY } = await import(pathToFileURL(join(dir, '..', 'floor', 'painter.js')).href);
        const registry = spec.registry === false ? null : themeRegistry;
        const rejected = registry === null ? [] : registry.THEMES.filter((name) => (spec.reject ?? []).includes(name));
        const modules = registry === null ? {} : Object.fromEntries(registry.THEMES.map((name) => [name, rejected.includes(name) ? null : themeModules[name]]));

        supplyScene = () => {
            screen.sceneInputs({
                box: box ?? furniture.FURNITURE_BOX,
                measure: harnessMeasurer(measurer),
                character,
                draw: seededDraw,
                themes: themeInputs(registry, modules),
            });
            screen.assetsFailed([...(registry === null ? [THEME_REGISTRY] : []), ...rejected.map((name) => `theme:${name}`)]);
        };
    }

    // The art's answer (`floor/main.js`'s `loadArt().then(…)`): at once, before the entry — or, under
    // `scene_inputs_after_enter`, after it, so the frames between carry a floor and no scene (card#11795 m5-3).
    const sceneLate = scenario.scene_inputs_after_enter ?? null;

    if (supplyScene !== null && sceneLate === null) {
        supplyScene();
    }

    if (supplyScene !== null && typeof sceneLate === 'object' && sceneLate !== null) {
        schedule(sceneLate.at_ms, 'art answered', () => {
            supplyScene();

            return 'supplied';
        });
    }

    // § 4.5's elevator ride (card#11795): each act the screen's method, its answer recorded, and the frame the
    // page paints after it — `rideView()` — drawn with the trigger `ride`. No render follows (the loop below).
    const rideActs = [];
    // The lobby's screen, started below beside the floor's — the ride's methods are the same on both (`wire/ride.js`).
    if (lobbyRun !== null) {
        lobby = startLobbyScreen(client, buildingHttp.fetch, clock, log, (frame) => {
            lobbyRenders.push({ at: now, trigger: 'render', frame: JSON.parse(JSON.stringify(frame)) });
        }, {
            surface: lobbyRun.surface ?? HARNESS_SURFACE,
            reduce: scenario.reduce === true,
            local_time: viewerTime(lobbyRun),
            lobby_href: lobbyRun.lobby_href ?? null,
        });
    }

    const rider = () => screen ?? lobby;
    const rideMethod = {
        ride: () => rider().ride(),
        entered: () => rider().entered(),
        shut: () => rider().shut(),
        choose: (act) => rider().choose(act.key ?? null),
        opened: () => rider().opened(),
        abandoned: () => rider().abandoned(),
        arrived: () => rider().arrived(),
        returned: () => rider().returned(),
        cab_closed: () => rider().cabClosed(),
        escape: (act) => rider().escape(act.cancelable === true),
        arrive_by_ride: (act) => rider().arriveByRide({ expires_in_ms: act.expires_in_ms }),
    };
    const rideAct = (act) => {
        const method = rideMethod[act.act];

        if (method === undefined) {
            throw new Error(`unknown ride act ${act.act}`);
        }

        const answer = method(act);
        const frame = answer === null ? null : rider().rideView();

        if (frame !== null) {
            (screen === null ? lobbyRenders : floorRenders).push({ at: now, trigger: 'ride', frame: JSON.parse(JSON.stringify(frame)) });
        }

        rideActs.push({
            at: now,
            act,
            answer: answer === null ? null : JSON.parse(JSON.stringify(answer)),
            ride: frame === null ? null : JSON.parse(JSON.stringify(frame.ride)),
            // The 1 s tick's scene over the same frame, as the page's ticker would draw it now — the floor's `sceneView()`
            // over its desks, the lobby's `tickView()` (whose scene the tick re-reads).
            scene_view_ride: frame === null ? null : JSON.parse(JSON.stringify(
                screen !== null ? screen.sceneView(frame.desks)?.ride ?? null : lobby.tickView()?.scene?.ride ?? null)),
        });

        return act.act;
    };

    for (const act of [...(scenario.floor?.ride ?? []), ...(lobbyRun?.ride ?? [])]) {
        schedule(act.at_ms, `ride ${act.act}`, () => (rider() === null ? 'no screen' : rideAct(act)));
    }

    if (rider() !== null && scenario.arrive_by_ride !== undefined) {
        rideAct({ act: 'arrive_by_ride', expires_in_ms: scenario.arrive_by_ride.expires_in_ms });
    }

    // The lobby's 1 s tick (`lobby/main.js`'s ticker): the directory's ages re-read, nothing drained, no camera moved.
    if (lobby !== null && lobbyRun.tick === true) {
        startAgeTicker(client, clock, timersImpl, () => {
            const frame = lobby.tickView();

            if (frame !== null) {
                lobbyRenders.push({ at: now, trigger: 'tick', frame: JSON.parse(JSON.stringify(frame)) });
            }
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

    /** A `scroll` act's plain wheel event, as the screen's `pan()` takes it. */
    const scrollDelta = (act) => ({ deltaX: act.delta_x ?? 0, deltaY: act.delta_y ?? 0, deltaMode: act.delta_mode ?? 0 });

    // Appendix B row 15's camera acts, each an event on the scenario queue, recorded with the camera
    // before and after it. Only a resize is followed by a render (see the header).
    for (const act of scenario.floor?.camera ?? []) {
        schedule(act.at_ms, act.act === 'resize' ? 'viewport resize' : `camera ${act.act}`, () => {
            if (screen === null) {
                return 'no floor';
            }

            const before = screen.camera();
            let glide = null;
            let consumed;

            switch (act.act) {
                case 'scroll':
                    ({ consumed } = screen.pan(scrollDelta(act)));
                    break;
                case 'ctrl_wheel':
                    ({ consumed } = screen.zoom({ x: act.x, y: act.y }, { deltaY: act.delta_y, deltaMode: act.delta_mode ?? 0 }));
                    break;
                case 'pinch':
                    screen.pinch(act.from, act.to, act.factor);
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

            cameraActs.push({ at: now, act, before, after: screen.camera(), glide_ms: glide, ...(consumed === undefined ? {} : { consumed }) });

            return act.act;
        });
    }

    // Appendix B row 24's camera acts on the lobby, each an event on the scenario queue, recorded with the camera before
    // and after it. None is followed by a render (see the header).
    for (const act of lobbyRun?.camera ?? []) {
        schedule(act.at_ms, `camera ${act.act}`, () => {
            const before = lobby.camera();
            let glide = null;
            let consumed;

            switch (act.act) {
                case 'fit':
                    glide = lobby.fitLobby().glide_ms;
                    break;
                case 'focus_elevator':
                    glide = lobby.focusElevator()?.glide_ms ?? null;
                    break;
                case 'focus_board':
                    glide = lobby.focusBoard()?.glide_ms ?? null;
                    break;
                case 'scroll':
                    ({ consumed } = lobby.pan(scrollDelta(act)));
                    break;
                case 'ctrl_wheel':
                    ({ consumed } = lobby.zoom({ x: act.x, y: act.y }, { deltaY: act.delta_y, deltaMode: act.delta_mode ?? 0 }));
                    break;
                case 'pinch':
                    lobby.pinch(act.from, act.to, act.factor);
                    break;
                case 'zoom':
                    lobby.zoomStep(act.notches);
                    break;
                case 'drag':
                    lobby.drag(act.dx, act.dy);
                    break;
                case 'resize':
                    lobby.resize({ width: act.width, height: act.height });
                    break;
                default:
                    throw new Error(`unknown lobby camera act ${act.act}`);
            }

            cameraActs.push({ at: now, act, before, after: lobby.camera(), glide_ms: glide, ...(consumed === undefined ? {} : { consumed }) });

            return act.act;
        });
    }

    await turn();
    floor?.render();

    if (lobby !== null) {
        await lobby.render();
        await turn();
    }

    if (screen !== null) {
        // The page's frame drawn before the entry has answered (card#11825): on a page the entry and
        // the renders run concurrently, so a frame can be drawn while the layout is still pending.
        if (scenario.render_before_enter === true) {
            await screen.render();
        }

        // § 4.4's floor entry: the layout, then each room's map. It runs after `start()` because
        // "deep-linking to a floor on a cold start runs the whole of § 2.2 first".
        await screen.enter();
        await turn();
        await screen.render();

        if (sceneLate === true && supplyScene !== null) {
            supplyScene();
            await screen.render();
        }
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

        // A tick re-reads ages, a camera act moves the viewer's head, a ride act is the viewer's own ride
        // (§ 4.5), and a walk's end is a paint-only refresh (FLOOR § 6.2's walk note item 4): none is an
        // apply, and a page renders after none.
        if (timer.label !== 'age tick' && timer.label !== 'walk end' && !timer.label.startsWith('camera ') && !timer.label.startsWith('ride ')) {
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
        ride_acts: rideActs,
        animation_log: log.rows,
    };
}

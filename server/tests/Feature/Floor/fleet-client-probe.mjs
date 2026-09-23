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
 *      "reduce":     true                       // § 6.4's `prefers-reduced-motion: reduce`, as a
 *                                               //  page reads it — every § 6.2 row draws its
 *                                               //  reduced-motion form and logs `motion: false`
 *      "durations":  [ <seconds>, … ]           // `formatDuration` sampled on these, for a test's
 *                                               //  expected string — the shipped format, not a copy
 *    }`
 * stdout — JSON: `{ "runs": [ <one run per repeat> ], "durations": [ … ] }`, each run
 *   `{ "records": [ … ], "final": <the last record>, "unscripted": [], "listeners": [ {type: n} ],
 *      "pending_timers": N, "rejections": [], "age_renders": [ {at, readouts} ],
 *      "desk_renders": [ {at, trigger, frame} ], "animation_log": [ <§ 11 rows> ] }`
 *   and each record
 *   `{ "at", "label", "outcome", "seats", "event_log", "requests", "phase", "clock_offset_ms",
 *      "read_status": { "<key>": {missing, failStreak, confirmedAt} }, "discrepancy_state" }`.
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
 * ⛔ AN UNSCRIPTED REQUEST IS REPORTED IN THE RECORD, NOT AS AN EXIT CODE. A planted control must
 * red on the FIELD its plant diverges on; a plant that also happens to issue an extra request
 * would otherwise kill the probe and "pass" for the wrong reason.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

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

const fx = JSON.parse(readFileSync(0, 'utf8') || '{}');

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
}, null, 2));

async function replay(scenario) {
    rejections = [];

    let now = 0;
    const clock = { now: () => (scenario.browser_clock_ms ?? 0) + now };
    const realDateNow = Date.now;
    Date.now = clock.now;

    const timers = [];
    let seq = 0;
    const schedule = (at, label, fire) => timers.push({ at, seq: seq++, label, fire });

    const http = scriptedFetch(scenario.http, {
        schedule: (delay, label, fire) => schedule(now + delay, label, fire),
    });

    const streams = [];

    class FakeEventSource {
        constructor(path) {
            this.path = path;
            this.openedAt = now;
            this.listeners = {};
            streams.push(this);
        }

        addEventListener(type, callback) {
            (this.listeners[type] ??= []).push(callback);
        }

        close() {
            this.closedAt = now;
        }
    }

    for (const message of scenario.messages ?? []) {
        const label = `message ${message.envelope.t} ${message.envelope.seat_id ?? ''} ${message.envelope.state_version ?? ''}`;

        schedule(message.at_ms, label, () => {
            const es = streams[streams.length - 1];

            if (es === undefined || es.openedAt > message.at_ms || es.closedAt !== undefined) {
                return 'dropped';
            }

            for (const callback of es.listeners.mezzanine ?? []) {
                callback({ data: JSON.stringify(message.envelope) });
            }

            return 'delivered';
        });
    }

    const client = new FleetClient(http.fetch, FakeEventSource, clock);
    const records = [];
    const ageRenders = [];
    const deskRenders = [];
    const log = createAnimationLog();
    let floor = null;

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
            requests: [...http.requests],
            phase: client.phase,
            clock_offset_ms: client.clockOffsetMs,
            read_status: Object.fromEntries(keys.map((k) => [k, client.readStatus(k)])),
            discrepancy_state: client.discrepancyState(),
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

    await turn();
    floor?.render();
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

        if (timer.label !== 'age tick') {
            floor?.render();
        }

        snap(timer.label, outcome ?? null);
    }

    Date.now = realDateNow;

    return {
        records,
        final: records[records.length - 1],
        unscripted: [...http.unscripted],
        listeners: streams.map((s) => Object.fromEntries(
            Object.entries(s.listeners).map(([type, list]) => [type, list.length]),
        )),
        pending_timers: timers.length,
        rejections: [...rejections],
        age_renders: ageRenders,
        desk_renders: deskRenders,
        animation_log: log.rows,
    };
}

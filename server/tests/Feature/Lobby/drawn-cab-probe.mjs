/**
 * The probe the PHP suite drives the REAL `LobbyScreen` through the exact race impl review r2 found
 * (card#7343 row 16): a render's own await outlasting a ride. Under `node`, no DOM — `FleetClient` and
 * `Building` run for real, against a scripted `fetch` whose `GET /api/building` response is held open
 * on a promise this probe resolves on its own schedule, and a captured stream handler this probe fires
 * a real `building.layout` message through, so the SAME code path `render()`'s docblock describes (drain
 * the journal, `applyLayout()`, `draw()`) runs unmocked.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/lobby` (its `..` reaches `public/js/wire` and `public/js/floor` too) or a MUTATED
 * COPY of the whole `public/js` tree — so a planted control re-mints its defect in the real code.
 *
 * ⛔ THE TWO SCENARIOS. Both start from one drawn, composed building (floors `a`, `b`; the cab resolves
 * to `a`, the first plate) and then trigger a SECOND render whose `applyLayout()` → `fetchLayout()` is
 * held pending — the "stale" render. While it is pending, this probe calls the REAL `screen.ride()`
 * (moving the cab to `b`, as `main.js`'s click handler does) and:
 *  · `mid_ride` — releases the stale render's fetch WHILE the ride is still in flight (`riding` true);
 *  · `after_arrival` — calls the real `screen.returned()` FIRST (the ride has arrived — reduced motion's
 *    glide is ~instant) and only then releases the fetch, so the stale render lands with `riding`
 *    already false, the case the `riding`-gated fix (impl review r1) got wrong.
 * Both must draw the cab at `b` — the ride's destination, never `a`, the stale render's origin.
 *
 * stdin  — `{}` (nothing to configure; both scenarios always run)
 * stdout — JSON: `{ "mid_ride": { "at": string|null, "riding": boolean },
 *                   "after_arrival": { "at": string|null, "riding": boolean } }`
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node drawn-cab-probe.mjs <lobby-module-dir>  (no stdin payload needed)');
    process.exit(2);
}

const url = (file) => pathToFileURL(join(dir, file)).href;

const { LobbyScreen } = await import(url('lobby-screen.js'));
const { Building } = await import(url('../wire/building.js'));
const { FleetClient, FEED_VERSION } = await import(url('../wire/fleet-client.js'));

const SNAPSHOT = { api_version: 1, server_time: '2025-01-01T00:00:00.000Z', fleet: { seats_total: 0, seats_live: 0 }, installs: [] };
const LAYOUT_V1 = { layout: { layout_version: 1, floors: [{ floor: 'a', rooms: [] }, { floor: 'b', rooms: [] }] }, rooms: [] };

/**
 * One full run of a scenario: draw a composed building, ride it while a second render's layout fetch
 * is held pending, and release that fetch at the moment `releaseAt` names.
 *
 * @param {'mid_ride'|'after_arrival'} releaseAt
 */
async function run(releaseAt) {
    let messageHandler = null;
    let pendingLayout = null;

    const fetchImpl = (path) => {
        if (path === '/api/fleet/snapshot') {
            return Promise.resolve(jsonResponse(SNAPSHOT));
        }

        if (path === '/api/building') {
            if (pendingLayout === null) {
                // The FIRST layout fetch — render #1's — settles at once, so the building composes.
                return Promise.resolve(jsonResponse(LAYOUT_V1));
            }

            // The SECOND — render #2's, triggered by the injected `building.layout` message below —
            // is held on a promise this function resolves explicitly, at the moment the scenario names.
            return pendingLayout.promise.then(() => jsonResponse(LAYOUT_V1));
        }

        throw new Error(`drawn-cab-probe: unscripted request ${path}`);
    };

    class CapturingEventSource {
        addEventListener(type, handler) {
            if (type === 'mezzanine') {
                messageHandler = handler;
            }
        }

        close() {}
    }

    const client = new FleetClient(fetchImpl, CapturingEventSource, { now: () => 0 });
    const building = new Building(fetchImpl);
    const screen = new LobbyScreen(client, building, { surface: { width: 1280, height: 800 } });

    client.start();
    await settle();

    // Render #1: composes the building (floors a, b), no cab ridden yet — resolves to the first plate.
    let cab = null;
    const first = await screen.render(() => cab);

    if (first.building?.composed !== true || first.building.elevator.at !== 'a') {
        throw new Error(`drawn-cab-probe: the first render did not compose at 'a': ${JSON.stringify(first.building)}`);
    }

    // A real `building.layout` message — the stream's own path (§ 2.5) — so render #2 takes the
    // `applyLayout()` branch and its `fetchLayout()` is the one this probe holds pending.
    pendingLayout = deferred();
    messageHandler({
        data: JSON.stringify({
            t: 'building.layout', feed_version: FEED_VERSION, server_time: '2025-01-01T00:00:01.000Z',
            install_id: null, map_version: null, layout_version: 2,
        }),
    });

    const stalePromise = screen.render(() => cab);

    await settle();

    // The ride — the REAL `LobbyScreen#ride()`, exactly as `main.js`'s click handler calls it — while
    // render #2's fetch is still pending.
    const ride = screen.ride();

    if (ride === null) {
        throw new Error('drawn-cab-probe: ride() returned null — nothing to ride from the composed building');
    }

    cab = ride.cab;

    if (releaseAt === 'after_arrival') {
        screen.returned();
    }

    pendingLayout.resolve();

    const stale = await stalePromise;

    return { at: stale.building?.elevator?.at ?? null, riding: stale.riding };
}

function jsonResponse(body) {
    return { status: 200, ok: true, json: async () => body, text: async () => JSON.stringify(body) };
}

function deferred() {
    let resolve;
    const promise = new Promise((r) => {
        resolve = r;
    });

    return { promise, resolve: () => resolve() };
}

function settle() {
    return new Promise((r) => setTimeout(r, 0));
}

process.stdout.write(JSON.stringify({
    mid_ride: await run('mid_ride'),
    after_arrival: await run('after_arrival'),
}));

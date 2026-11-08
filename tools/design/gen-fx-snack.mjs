// The generator of `fx-snack` (docs/design/FLOOR.md § 11, AT-D3-32; card#12749, card#12785, committed by card#12787) and
// of `fx-restroom` (AT-D3-33; card#12786): it writes server/tests/Feature/Floor/fixtures/fx-snack.json and fx-restroom.json
// and the shipped-default variants their runs draw over, server/tests/Feature/Floor/fixtures/maps/snack-*.json and
// restroom-*.json (`VARIANTS` names them).
//
//   node tools/design/gen-fx-snack.mjs            rewrite the two fixtures and the maps
//   node tools/design/gen-fx-snack.mjs --check    write nothing; exit 1 naming each file that differs from what it
//                                                 would write (a stale copy after a default.tmj change, or a hand edit)
//   node tools/design/gen-fx-snack.mjs <run>      print that one run of either fixture as JSON to stdout, write nothing
//
// The fixture is built in two stages, each its card's own derivation: stage 1 lays card#12749's runs over the hourly
// windows; stage 2 is card#12785's transform of them onto the chained breaks, which moves each run's clock and adds
// the runs that card introduced. Stage 1's runs exist only as stage 2's input.
//
// Each variant map is `resources/floor/default.tmj` with a stated edit — so a layout change to the default (card#12787
// moved the PM's desk) is carried into every variant by re-running this, never by editing four copies by hand. An
// edit names the gid each cell held before it; a default whose cell no longer holds it is refused rather than
// overwritten, because the variant's note would then describe a map it no longer is.
//
// fx-snack's runs reference the default by `@json:` and carry no copy of it, and every instant in them comes from the
// seeded snack schedule, never from a route length — so a re-laid desk changes the maps and leaves fx-snack.json as it
// was; `--check` says so. fx-restroom's runs are the same, but for the instants a note places INSIDE a visit, which are
// read off the shipped floor screen's own legs (stage 3) — a re-laid route moves those, and `--check` says so too.
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const FX = join(ROOT, 'server', 'tests', 'Feature', 'Floor', 'fixtures');
const MAPS = 'server/tests/Feature/Floor/fixtures/maps';
const { draw } = await import(pathToFileURL(join(ROOT, 'resources', 'characters', 'seed.js')).href);
const arg = process.argv[2] ?? null;
const CHECK = arg === '--check';
const ONLY = arg !== null && !CHECK ? arg : null;

// ─── the variant maps ────────────────────────────────────────────────────────────────────────────────────────────
const WALL = 3;
const SNACK_TABLE = 14;
const SNACK_TABLE_LOW = 15;
const RESTROOM_DOOR = 16;
const cells = (cols, rows) => cols.flatMap((c) => rows.map((r) => [c, r]));
const span = (a, b) => Array.from({ length: b - a + 1 }, (_, i) => a + i);
/** name → edits: { layer, cells: [[col, row]…], from: the gid each cell holds on the default, to: the gid it gets }. */
const VARIANTS = {
    // the default without its corner: both snack tables lifted
    'snack-none': [
        { layer: 'furniture', cells: [[213, 51]], from: SNACK_TABLE, to: 0 },
        { layer: 'furniture', cells: [[216, 61]], from: SNACK_TABLE_LOW, to: 0 },
    ],
    // the tall table moved into the left strip
    'snack-moved': [
        { layer: 'furniture', cells: [[213, 51]], from: SNACK_TABLE, to: 0 },
        { layer: 'furniture', cells: [[2, 30]], from: 0, to: SNACK_TABLE },
    ],
    // a wall from the office's front return to the front edge at x 1584: no route reaches the tall table
    'snack-sealed': [
        { layer: 'walls', cells: cells([198], span(40, 86)), from: 0, to: WALL },
    ],
    // the tall table boxed into a pocket under the office's front return that holds two spots, the low table lifted.
    // The pocket's way in is an 80 px gap in its bottom wall (x 1,624–1,704), narrower than a walker's body and both of
    // the `clear` tier's margins, so the floor `spotsNear()` counts the table's spots over — open for nobody's walk at
    // `clear` — stays the pocket's, which holds two; the seats' routes reach the table through it at the `feet` tier (the
    // right strip under the pocket is narrower than a body and its margins). card#12787: the way in was a gap in the
    // front return, through the PM office beside the PM's desk, and the moved desk covers it.
    'snack-two-spots': [
        { layer: 'walls', cells: cells([197], span(40, 54)), from: 0, to: WALL },
        { layer: 'walls', cells: cells([...span(197, 202), ...span(213, 221)], [55]), from: 0, to: WALL },
        { layer: 'furniture', cells: [[216, 61]], from: SNACK_TABLE_LOW, to: 0 },
    ],
    // card#12786: the default without its restroom door
    'restroom-none': [
        { layer: 'furniture', cells: [[214, 81]], from: RESTROOM_DOOR, to: 0 },
    ],
    // a wall from below the low table down to the front edge at x 1600, and along y 560 to the door: the door's forecourt
    // is closed off, so no route reaches its threshold
    'restroom-sealed': [
        { layer: 'walls', cells: cells([200], span(70, 86)), from: 0, to: WALL },
        { layer: 'walls', cells: cells(span(201, 213), [70]), from: 0, to: WALL },
    ],
    // a pocket round the door's forecourt — x 1608 down to the front edge, y 600 along to the door — entered through an
    // 80 px gap in its top wall (x 1,624–1,704), narrower than a seat's body and the `clear` tier's margins: the floor
    // `spotsNear()` counts the waiting spots over — open for nobody's walk at `clear` — holds exactly one past the
    // threshold, and every desk reaches the door through the gap at the `snug` tier
    'restroom-one-spot': [
        { layer: 'walls', cells: cells([201], span(75, 86)), from: 0, to: WALL },
        { layer: 'walls', cells: cells([202, 213], [75]), from: 0, to: WALL },
    ],
    // card#12841: the door moved up the right wall to (1,712, 496) — its tile x 1,712–1,776, y 360–504 — both snack tables
    // lifted to make room, and a wall at x 1,664 from y 496 to 608: the door's forecourt is a corridor three lattice cells
    // wide straight down in front of the door and inside its lane, so a walker coming out and one going in both walk the
    // lane's whole depth, and the first spot `spotsNear()` finds with no lane is in it
    'restroom-corridor': [
        { layer: 'furniture', cells: [[213, 51]], from: SNACK_TABLE, to: 0 },
        { layer: 'furniture', cells: [[216, 61]], from: SNACK_TABLE_LOW, to: 0 },
        { layer: 'furniture', cells: [[214, 81]], from: RESTROOM_DOOR, to: 0 },
        { layer: 'furniture', cells: [[214, 62]], from: 0, to: RESTROOM_DOOR },
        { layer: 'walls', cells: cells([208], span(62, 75)), from: 0, to: WALL },
    ],
};

function variant(base, name) {
    const map = JSON.parse(JSON.stringify(base));
    for (const { layer, cells: at, from, to } of VARIANTS[name]) {
        const l = map.layers.find((x) => x.type === 'tilelayer' && x.name === layer);
        for (const [c, r] of at) {
            const i = r * l.width + c;
            if (l.data[i] !== from) {
                throw new Error(`${name}: ${layer} cell (${c}, ${r}) holds ${l.data[i]} on the default, not ${from}`);
            }
            l.data[i] = to;
        }
    }
    return map;
}

// ─── stage 1: card#12749's runs, over the hourly windows ───────────────────────────────────────────────────────────
const snap4 = JSON.parse(readFileSync(join(FX, 'fx-snapshot-4.json'), 'utf8'));
const walk = JSON.parse(readFileSync(join(FX, 'fx-intern-walk.json'), 'utf8'));
const tpl = walk.runs.arrive;
const BASE = snap4.runs.instrument.http['/api/fleet/snapshot'][0].body;
const T0 = Date.parse(BASE.server_time);
const WIN = 3600;

const clone = (v) => JSON.parse(JSON.stringify(v));
const iso = (ms) => new Date(ms).toISOString();
/** Every ISO instant in `node` moved by `d` ms. */
function shift(node, d) {
    if (typeof node === 'string') {
        return /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(node) ? iso(Date.parse(node) + d) : node;
    }
    if (Array.isArray(node)) {
        return node.map((x) => shift(x, d));
    }
    if (node !== null && typeof node === 'object') {
        return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, shift(v, d)]));
    }
    return node;
}
const startOf = (key, hour) => hour * WIN + (draw(key, `snack:${hour}`) % WIN);
const dwellOf = (key, hour) => 45 + (draw(key, `snack-dwell:${hour}`) % 31);
const H0 = Math.floor(T0 / 1000 / WIN);

/** The first hour from H0 on whose windows for `keys` all begin within `span` s of the first, and not in its first `lead` s. */
function hourWhere(keys, within, lead) {
    for (let h = H0; h < H0 + 200000; h++) {
        const starts = keys.map((k) => startOf(k, h));
        if (Math.max(...starts) - Math.min(...starts) <= within && Math.min(...starts) - h * WIN >= lead + 2) {
            return h;
        }
    }
    throw new Error(`no hour found for ${keys}`);
}

const SEATS = Object.fromEntries(BASE.installs[0].seats.map((s) => [s.seat_id, s]));
const PM_CALL = SEATS['aimla-pm'].subagents[0].call_id;
const K = { pm: 'aimla/aimla-pm', i1: 'aimla/aimla-impl-1', i2: 'aimla/aimla-impl-2', rv: 'aimla/aimla-review', pmIntern: `aimla/aimla-pm~${PM_CALL}` };

/**
 * One run: the snapshot `seats` (seat_id → patch over fx-snapshot-4's object), its window anchor (`at_s`, the corrected
 * server second the run's browser clock 0 stands at, which shifts every instant of the run), the deltas, heartbeats.
 */
function run({ note, at_s, seats = {}, deltas = [], beats = null, until_ms, map = '@json:resources/floor/default.tmj', maps = null, extra_http = {}, reduce = false }) {
    const body = clone(BASE);
    body.installs[0].seats = body.installs[0].seats.map((s) => ({ ...s, ...(seats[s.seat_id] ?? {}) }));
    const d = at_s * 1000 - T0;
    const shifted = shift(body, d);
    const http = {
        '/api/fleet/snapshot': [{ status: 200, body: shifted }],
        '/api/building': clone(tpl.http['/api/building']),
        '/api/building/rooms/aimla/map': maps ?? [{ status: 200, body: { install_id: 'aimla', source: 'default', map_version: null, map } }],
        '/art/floor/tiles/floor-plane.tsx': clone(tpl.http['/art/floor/tiles/floor-plane.tsx']),
        ...extra_http,
    };
    const server = (ms) => iso(at_s * 1000 + ms);
    const fleet = (ms) => ({ db: 'ok', fold: 'ok', sweep: 'ok', sweep_last_run_at: server(ms - 2000), ingest_last_receipt_at: server(ms), max_fold_lag_ms: 50, seats_total: 4, seats_live: 4 });
    const messages = [];
    for (const ms of beats ?? range(500, until_ms, 15000)) {
        messages.push({ at_ms: ms, envelope: { feed_version: 2, t: 'feed.heartbeat', server_time: server(ms), fleet: fleet(ms) } });
    }
    for (const m of deltas) {
        messages.push(m.t === 'room.map'
            ? { at_ms: m.at_ms, envelope: { feed_version: 2, t: 'room.map', server_time: server(m.at_ms), install_id: 'aimla', map_version: m.map_version, revision_id: m.revision_id } }
            : { at_ms: m.at_ms, envelope: { feed_version: 2, t: 'seat.delta', server_time: server(m.at_ms), install_id: 'aimla', seat_id: m.seat, state_version: m.v, at: server(m.at_ms), changed: Object.keys(m.patch), patch: m.patch } });
    }
    messages.sort((a, b) => a.at_ms - b.at_ms);
    return { note, http, messages, until_ms, floor: clone(tpl.floor), ...(reduce ? { reduce: true } : {}) };
}
function range(from, to, step) {
    const out = [];
    for (let v = from; v < to; v += step) out.push(v);
    return out;
}

const LEAD = 5;
const runs = {};

// idle-trip: aimla-impl-2 idle; the run's clock 0 stands LEAD s before its window.
{
    const h = hourWhere([K.i2], 0, LEAD);
    const s = startOf(K.i2, h);
    runs['idle-trip'] = run({ note: `\`aimla-impl-2\`, idle from the snapshot, through its window and back, then silence. The run's clock 0 is the corrected server second ${iso(s * 1000 - LEAD * 1000)}: hour ${h}'s window for \`${K.i2}\` begins ${LEAD} s later (draw('${K.i2}', 'snack:${h}') mod 3600 = ${s - h * WIN}), and its dwell is ${dwellOf(K.i2, h)} s.`,
        at_s: s - LEAD, until_ms: 150000 });
}

// working-window: aimla-impl-1 working through its own window — the control.
{
    const h = hourWhere([K.i1], 0, LEAD);
    const s = startOf(K.i1, h);
    runs['working-window'] = run({ note: `\`aimla-impl-1\`, \`working\`, through its own window (hour ${h}, second ${s - h * WIN}): the control — no trip.`, at_s: s - LEAD, until_ms: 40000 });
}

// intern-trip: aimla-pm's element flipped working → idle before its window; the seat itself working.
{
    const h = hourWhere([K.pmIntern], 0, LEAD);
    const s = startOf(K.pmIntern, h);
    runs['intern-trip'] = run({ note: `\`aimla-pm\`'s intern \`${PM_CALL}\` flipped \`working → idle\` at 2 s, before its window (hour ${h}, ${LEAD} s after clock 0; dwell ${dwellOf(K.pmIntern, h)} s); the seat stays \`working\`.`,
        at_s: s - LEAD, until_ms: 150000,
        deltas: [{ at_ms: 2000, seat: 'aimla-pm', v: SEATS['aimla-pm'].state_version + 1, patch: { subagents: [{ ...SEATS['aimla-pm'].subagents[0], activity_state: 'idle' }] } }] });
}

const IDLE = { render_state: 'idle', link_state: 'live', activity_state: 'idle', open_calls: 0, open_turn: false, action: null, longest_open_call: null };
const i2 = (() => {
    const h = hourWhere([K.i2], 0, LEAD);

    return { h, s: startOf(K.i2, h), dwell: dwellOf(K.i2, h) };
})();
const i2note = `hour ${i2.h}'s window for \`${K.i2}\` begins ${LEAD} s after clock 0 (second ${i2.s - i2.h * WIN} of the hour), its dwell ${i2.dwell} s`;
const V2 = SEATS['aimla-impl-2'].state_version;
const seatBody = (seatId, v, atMs, base) => {
    const seat = shift(clone(SEATS[seatId]), base * 1000 - T0);

    seat.state_version = v;

    return { api_version: 1, server_time: iso(base * 1000 + atMs), ...seat, detail: null };
};

runs['intern-old-reporter'] = (() => {
    const h = hourWhere([K.pmIntern], 0, LEAD);
    const s = startOf(K.pmIntern, h);
    return run({ note: `\`aimla-pm\` badged \`update_required\`, its one element reading \`working\` — what a reporter that never sends \`subagent.activity\` leaves every open dispatch at (fx-intern-walk's \`old-reporter\`) — through its intern's window (hour ${h}, ${LEAD} s after clock 0): never idle, so no trip.`,
        at_s: s - LEAD, until_ms: 40000, seats: { 'aimla-pm': { badges: ['lossy', 'update_required'] } } });
})();

runs['recall-mid-trip'] = run({ note: `idle-trip (${i2note}), then at 35 s, while the walker stands at the table, \`idle → working\`: the desk renders \`working\` at once and the walker walks home along its route, sitting down to work on arrival (the operator's ruling of 2026-11-02, *"Walk back"*).`,
    at_s: i2.s - LEAD, until_ms: 90000, deltas: [{ at_ms: 35000, seat: 'aimla-impl-2', v: V2 + 1, patch: { render_state: 'working', activity_state: 'working' } }] });

runs['leave-mid-trip'] = run({ note: `idle-trip (${i2note}), then at 35 s, while the walker stands at the table, \`idle → stale\`: one A2, walked from the spot.`,
    at_s: i2.s - LEAD, until_ms: 100000, deltas: [{ at_ms: 35000, seat: 'aimla-impl-2', v: V2 + 1, patch: { render_state: 'stale', link_state: 'stale' } }] });

runs['intern-stop-mid-trip'] = (() => {
    const h = hourWhere([K.pmIntern], 0, LEAD);
    const s = startOf(K.pmIntern, h);
    const pm = SEATS['aimla-pm'];

    return run({ note: `intern-trip (hour ${h}, ${LEAD} s after clock 0), then at 7 s, on its walk out, the element lost (\`subagents_open\` 1 → 0): one A10, walked from where the intern is.`,
        at_s: s - LEAD, until_ms: 90000,
        deltas: [
            { at_ms: 2000, seat: 'aimla-pm', v: pm.state_version + 1, patch: { subagents: [{ ...pm.subagents[0], activity_state: 'idle' }] } },
            { at_ms: 7000, seat: 'aimla-pm', v: pm.state_version + 2, patch: { subagents: [], subagents_open: 0 } },
        ] });
})();

runs['two-visitors'] = (() => {
    const h = hourWhere([K.i2, K.i1], 20, LEAD);
    const first = Math.min(startOf(K.i2, h), startOf(K.i1, h));

    return run({ note: `\`aimla-impl-2\` and \`aimla-impl-1\` both idle; hour ${h}'s windows begin ${startOf(K.i2, h) - h * WIN} and ${startOf(K.i1, h) - h * WIN} s into the hour, the first ${LEAD} s after clock 0 — both at the tall table at once.`,
        at_s: first - LEAD, until_ms: 170000, seats: { 'aimla-impl-1': IDLE } });
})();

runs['tall-and-low'] = (() => {
    const h = hourWhere([K.i2, K.pmIntern], 20, LEAD);
    const first = Math.min(startOf(K.i2, h), startOf(K.pmIntern, h));
    const pm = SEATS['aimla-pm'];

    return run({ note: `\`aimla-impl-2\` idle and \`aimla-pm\`'s intern idle (its seat working); hour ${h}'s windows begin ${startOf(K.i2, h) - h * WIN} and ${startOf(K.pmIntern, h) - h * WIN} s into the hour, the first ${LEAD} s after clock 0 — an agent at the tall table and an intern at the low one at once.`,
        at_s: first - LEAD, until_ms: 170000, seats: { 'aimla-pm': { subagents: [{ ...pm.subagents[0], activity_state: 'idle' }] } } });
})();

runs['past-the-last-spot'] = (() => {
    const keys = [K.i1, K.i2, K.rv];
    const h = hourWhere(keys, 20, LEAD);
    const first = Math.min(...keys.map((k) => startOf(k, h)));

    return run({ note: `\`aimla-impl-1\`, \`aimla-impl-2\` and \`aimla-review\` idle over \`fixtures/maps/snack-two-spots.json\`, whose tall table has exactly two spots; hour ${h}'s windows begin ${keys.map((k) => startOf(k, h) - h * WIN).join(', ')} s into the hour, the first ${LEAD} s after clock 0 — the third to come takes no trip.`,
        at_s: first - LEAD, until_ms: 170000, seats: { 'aimla-impl-1': IDLE, 'aimla-review': { ...IDLE, blocked_since: null } },
        map: `@json:${MAPS}/snack-two-spots.json` });
})();

runs['no-corner'] = run({ note: `idle-trip (${i2note}) over \`fixtures/maps/snack-none.json\`, the shipped default without the two snack tiles: a floor with no corner.`,
    at_s: i2.s - LEAD, until_ms: 40000, map: `@json:${MAPS}/snack-none.json` });

runs['no-route'] = run({ note: `idle-trip (${i2note}) over \`fixtures/maps/snack-sealed.json\`, the shipped default with a wall from the office's front return to the front edge at x 1584: the tall table stands where no route reaches.`,
    at_s: i2.s - LEAD, until_ms: 40000, map: `@json:${MAPS}/snack-sealed.json` });

runs['reload-mid-trip'] = run({ note: `idle-trip (${i2note}), then at 46 s — thirty seconds or more into the dwell — a delta two versions ahead for \`aimla-impl-2\`, whose resync fetch returns the seat still idle: a non-delta entry, so the trip is re-derived from it and continues at its phase.`,
    at_s: i2.s - LEAD, until_ms: 90000,
    deltas: [{ at_ms: 46000, seat: 'aimla-impl-2', v: V2 + 2, patch: { activity_state: 'idle' } }],
    extra_http: { '/api/fleet/seats/aimla/aimla-impl-2': [{ status: 200, body: seatBody('aimla-impl-2', V2 + 2, 46000, i2.s - LEAD) }] } });

runs['reload-mid-trip-viewer'] = run({ note: `reload-mid-trip's SECOND VIEWER: a page whose clock 0 is one loop frame after reload-mid-trip's resync instant (${i2note}, so clock 0 here is ${LEAD + 41}.25 s after reload-mid-trip's clock 0): it enters the trip at its phase.`,
    at_s: i2.s + 41.25, until_ms: 40000, beats: [0, 15000, 30000] });

runs['feed-down'] = run({ note: `\`aimla-impl-2\` idle; one heartbeat at 0.5 s and the stream silent after it, past § 9 F1's 45 s, before its window (hour ${i2.h}, 50 s after clock 0): no trip.`,
    at_s: i2.s - 50, until_ms: 140000, beats: [500] });

runs['feed-dies-mid-trip'] = run({ note: `idle-trip (${i2note}), the stream silent from 0.5 s, so F1's verdict lands during the trip: the trip finishes and no other starts.`,
    at_s: i2.s - LEAD, until_ms: 120000, beats: [500] });

runs['map-moves-table'] = run({ note: `idle-trip (${i2note}), then at 35 s, during the dwell, a \`room.map\` revision — \`fixtures/maps/snack-moved.json\`, the tall table moved into the left strip, as map version 4: the lattice key moved, so the trip ends and the sleeper is at its desk.`,
    at_s: i2.s - LEAD, until_ms: 60000,
    maps: [{ status: 200, body: { install_id: 'aimla', source: 'default', map_version: null, map: '@json:resources/floor/default.tmj' } },
        { status: 200, body: { install_id: 'aimla', source: 'authored', map_version: 4, map: `@json:${MAPS}/snack-moved.json` } }],
    deltas: [{ at_ms: 35000, t: 'room.map', map_version: 4, revision_id: 912 }] });

runs['stilled-mid-trip'] = run({ note: `idle-trip (${i2note}), then at 35 s, during the dwell, a delta two versions ahead for \`aimla-impl-1\` whose resync fetch answers 401: the floor stilled (§ 9 F6), the trip ends and the sleeper is at its desk.`,
    at_s: i2.s - LEAD, until_ms: 60000,
    deltas: [{ at_ms: 35000, seat: 'aimla-impl-1', v: SEATS['aimla-impl-1'].state_version + 2, patch: { activity_state: 'working' } }],
    extra_http: { '/api/fleet/seats/aimla/aimla-impl-1': [{ status: 401, body: { api_version: 1, server_time: iso((i2.s - LEAD) * 1000 + 35000), error: 'token_revoked' } }] } });

// ─── stage 2: card#12785's chained breaks ───────────────────────────────────────────────────────────────────────
// card#12785 re-derived every run from stage 1's: each timeline moved by ONE constant (every ISO instant in it), so a
// run's content is stage 1's and only its clock 0 lands where the walker under test's chained break (`scene.js`'s
// `snackDay()`) begins a stated few seconds later; its new runs are derived from stage 1's. Ported verbatim from that
// card's build, inside a function so its names do not meet stage 1's.
const sc = await import(pathToFileURL(join(ROOT, 'server', 'public', 'js', 'floor', 'scene.js')).href);

function chained(old) {
    const PM = 'aimla/aimla-pm';
    const I1 = 'aimla/aimla-impl-1';
    const I2 = 'aimla/aimla-impl-2';
    const RV = 'aimla/aimla-review';
    const N = 'aimla/aimla-pm~01K3TA6G7H8J9K0M1N2P3Q4R5T';
    const ALL = [PM, I1, I2, RV, N];

    const breaks = (key, from, to) => sc.snackBreaks(draw, key, from, to);
    const quiet = (key, from, to) => breaks(key, from, to).length === 0;
    const c0Of = (run) => Date.parse(run.http['/api/fleet/snapshot'][0].body.server_time) / 1000;
    const iso = (s) => new Date(Math.round(s * 1000)).toISOString();

    // card#12786: no walker's RESTROOM break falls in a run's window — from 400 s before its clock 0 to 200 s after —
    // so a snack run draws no restroom visit (the shipped default places the door since that card).
    const restroomQuiet = (ref) => ALL.every((k) => sc.snackBreaks(draw, k, ref - 400, ref + 200, sc.RESTROOM_CHAIN).length === 0);

    // Search days [d0, d1) for a reference second `ref` (the run's clock 0) satisfying `ok(ref)`, the primary walker's
    // break at ref + 5, with no restroom break near it.
    function search(primary, ok, d0 = 19900, d1 = 21200) {
        for (let day = d0; day < d1; day++) {
            for (const s of sc.snackDay(draw, primary, day)) {
                if (ok(s - 5, s) && restroomQuiet(s - 5)) {
                    return s - 5;
                }
            }
        }
        throw new Error(`no instant for ${primary}`);
    }

    // Shift every ISO instant of a value by `d` seconds.
    function shift(v, d) {
        return JSON.parse(JSON.stringify(v).replace(/"(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z)"/g, (_, t) => `"${new Date(Date.parse(t) + d * 1000).toISOString()}"`));
    }

    const runs = {};
    const notes = {};
    const W = (s) => s; // documentation helper

    // ── Timeline A: aimla-impl-2 (idle) at clock 0 + 5; next-break is its following break. ───────────────────────────────
    const A_old = c0Of(old.runs['idle-trip']);
    const A = search(I2, (ref, s) => {
        const next = breaks(I2, s + 1, s + 7200)[0].start_s;

        return [PM, I1, N].every((k) => quiet(k, ref - 400, ref + 200) && quiet(k, next - 5 - 400, next - 5 + 160)) && restroomQuiet(next - 5);
    });
    const A_s = A + 5;
    const A_next = breaks(I2, A_s + 1, A_s + 7200)[0].start_s;
    const dA = A - A_old;
    const brk = (k, s) => sc.snackBreak(draw, k, s);
    const A_b = brk(I2, A_s);

    for (const name of ['idle-trip', 'recall-mid-trip', 'leave-mid-trip', 'no-corner', 'no-route', 'reload-mid-trip', 'reload-mid-trip-viewer',
        'feed-down', 'feed-dies-mid-trip', 'map-moves-table', 'stilled-mid-trip']) {
        runs[name] = shift(old.runs[name], dA);
    }

    // ── Timeline B: aimla-review (blocked) at clock 0 + 5 — the control (was working-window). ───────────────────────────
    const B = search(RV, (ref) => [PM, I1, I2, N].every((k) => quiet(k, ref - 400, ref + 60)));
    runs['blocked-window'] = shift(old.runs['working-window'], B - c0Of(old.runs['working-window']));

    // ── Timeline C: aimla-pm's intern at clock 0 + 5. ───────────────────────────────────────────────────────────────────
    const C = search(N, (ref) => [PM, I1, I2].every((k) => quiet(k, ref - 400, ref + 160)));
    for (const name of ['intern-trip', 'intern-old-reporter', 'intern-stop-mid-trip']) {
        runs[name] = shift(old.runs[name], C - c0Of(old.runs['intern-trip']));
    }

    // ── Timeline E: aimla-impl-1 (working) at clock 0 + 5 — busy-trip. ──────────────────────────────────────────────────
    const E = search(I1, (ref) => [PM, I2, N].every((k) => quiet(k, ref - 400, ref + 160)));

    // ── two-visitors: impl-2 at + 5 and impl-1 3–15 s later; two-visitors-late, a page loaded during both. ──────────────
    let tvGap = null;
    const TV = search(I2, (ref, s) => {
        const other = breaks(I1, s + 3, s + 15);

        if (other.length !== 1 || ![PM, N].every((k) => quiet(k, ref - 400, ref + 200))) {
            return false;
        }

        tvGap = other[0].start_s - s;

        return true;
    });
    runs['two-visitors'] = shift(old.runs['two-visitors'], TV - c0Of(old.runs['two-visitors']));

    // ── tall-and-low: impl-2 at + 5 and the (idle) intern 3–20 s later. ─────────────────────────────────────────────────
    let tlGap = null;
    const TL = search(I2, (ref, s) => {
        const other = breaks(N, s + 3, s + 20);

        if (other.length !== 1 || ![PM, I1].every((k) => quiet(k, ref - 400, ref + 200))) {
            return false;
        }

        tlGap = other[0].start_s - s;

        return true;
    });
    runs['tall-and-low'] = shift(old.runs['tall-and-low'], TL - c0Of(old.runs['tall-and-low']));

    // ── past-the-last-spot: impl-1, impl-2, review idle, all three within 20 s, the first at + 5. ───────────────────────
    let pl = null;
    const PL = search(I1, (ref, s) => {
        const a = breaks(I2, s - 25, s + 25);
        const b = breaks(RV, s - 25, s + 25);

        if (a.length !== 1 || b.length !== 1) {
            return false;
        }

        const all = [s, a[0].start_s, b[0].start_s];

        if (Math.max(...all) - Math.min(...all) > 25) {
            return false;
        }

        const first = Math.min(...all);

        if (![PM, N].every((k) => quiet(k, first - 5 - 400, first - 5 + 200))) {
            return false;
        }

        pl = { I1: s, I2: a[0].start_s, RV: b[0].start_s, first };

        return true;
    }, 19000, 23000);
    const PL0 = pl.first - 5;
    runs['past-the-last-spot'] = shift(old.runs['past-the-last-spot'], PL0 - c0Of(old.runs['past-the-last-spot']));

    // ── derived runs ────────────────────────────────────────────────────────────────────────────────────────────────────
    const deltaOf = (run) => run.messages.find((m) => m.envelope.t === 'seat.delta');
    const clone = (v) => JSON.parse(JSON.stringify(v));

    // recall-mid-trip: idle → blocked (attention) at 35 s, while the walker stands at the table.
    {
        const r = runs['recall-mid-trip'];
        const m = deltaOf(r);
        m.envelope.changed = ['render_state', 'activity_state', 'blocked_since'];
        m.envelope.patch = { render_state: 'blocked', activity_state: 'blocked', blocked_since: m.envelope.at };
    }

    // wakes-mid-trip: the old recall — idle → working at 35 s; the trip goes on, and the walker thinks of food.
    runs['wakes-mid-trip'] = shift(old.runs['recall-mid-trip'], dA);
    runs['wakes-mid-trip'].until_ms = 120000;

    // busy-trip: aimla-impl-1, working, through its break; at 35 s, on the dwell, its call closes with the turn open
    // (A3 → A4, working → working): the trip goes on.
    {
        const r = shift(clone(old.runs['working-window']), E - c0Of(old.runs['working-window']));
        const hb = r.messages.filter((m) => m.envelope.t === 'feed.heartbeat');
        const proto = hb[hb.length - 1];
        const msgs = [];

        for (let at = 500; at < 150000; at += 15000) {
            const m = clone(proto);
            const t = E + at / 1000;
            m.at_ms = at;
            m.envelope.server_time = iso(t);
            m.envelope.fleet.sweep_last_run_at = iso(t - 2);
            m.envelope.fleet.ingest_last_receipt_at = iso(t);
            msgs.push(m);
        }

        const seat = r.http['/api/fleet/snapshot'][0].body.installs[0].seats.find((x) => x.seat_id === 'aimla-impl-1');
        msgs.push({
            at_ms: 35000,
            envelope: {
                feed_version: 2,
                t: 'seat.delta',
                server_time: iso(E + 35),
                install_id: 'aimla',
                seat_id: 'aimla-impl-1',
                state_version: seat.state_version + 1,
                at: iso(E + 35),
                changed: ['action', 'longest_open_call', 'open_calls'],
                patch: { action: null, longest_open_call: null, open_calls: 0 },
            },
        });
        msgs.sort((a, b) => a.at_ms - b.at_ms);
        r.messages = msgs;
        r.until_ms = 150000;
        runs['busy-trip'] = r;
    }

    // two-visitors-late: two-visitors' page loaded 15 s after the second break began — both walkers on their way.
    const lateBy = 5 + tvGap + 15;
    {
        const r = shift(clone(runs['two-visitors']), lateBy);
        r.messages = r.messages.filter((m) => m.at_ms + lateBy * 1000 < 170000).map((m) => m);
        r.until_ms = 150000;
        runs['two-visitors-late'] = r;
    }

    // next-break: idle-trip's walker at its NEXT break, a page loaded 5 s before it.
    runs['next-break'] = shift(clone(old.runs['idle-trip']), dA + (A_next - A_s));

    // ── notes ───────────────────────────────────────────────────────────────────────────────────────────────────────────
    const at = (k, s) => {
        const day = Math.floor(s / 86400);
        const days = [day - 1, day].map((d) => sc.snackDay(draw, k, d));
        const d = days[1].includes(s) ? day : day - 1;
        const i = sc.snackDay(draw, k, d).indexOf(s);

        return `break ${i} of day ${d}'s chain for \`${k}\` (second ${s}; dwell ${brk(k, s).dwell_s} s, line ${brk(k, s).line})`;
    };
    const clock = (ref) => `The run's clock 0 is the corrected server second ${iso(ref)}`;
    const aNote = `${clock(A)}: ${at(I2, A_s)} begins 5 s later`;
    notes['idle-trip'] = `\`aimla-impl-2\`, idle from the snapshot, through its break and back, then silence. ${aNote}.`;
    notes['blocked-window'] = `\`aimla-review\`, \`blocked\`, through its own break (${at(RV, B + 5)}, 5 s after clock 0): the control — no trip. ${clock(B)}.`;
    notes['intern-trip'] = `\`aimla-pm\`'s intern \`01K3TA6G7H8J9K0M1N2P3Q4R5T\` flipped \`working → idle\` at 2 s, before its break (${at(N, C + 5)}, 5 s after clock 0); the seat stays \`working\`. ${clock(C)}.`;
    notes['intern-old-reporter'] = `\`aimla-pm\` badged \`update_required\`, its one element reading \`working\` — what a reporter that never sends \`subagent.activity\` leaves every open dispatch at (fx-intern-walk's \`old-reporter\`) — through its intern's break (${at(N, C + 5)}, 5 s after clock 0): busy at its word, so it goes with a food thought. ${clock(C)}.`;
    notes['recall-mid-trip'] = `idle-trip (${aNote}), then at 35 s, while the walker stands at the table, \`idle → blocked\` (an attention request): the desk renders \`blocked\` at once and the walker walks home along its route, raising its hand on arrival (the operator's ruling of 2026-11-02, *"Walk back"*, card#12785's attention clause).`;
    notes['wakes-mid-trip'] = `idle-trip (${aNote}), then at 35 s, while the walker stands at the table, \`idle → working\`: no recall (card#12785) — the trip goes on, the walker carrying its break's food thought from that render, and sits down to work at its end.`;
    notes['leave-mid-trip'] = `idle-trip (${aNote}), then at 35 s, while the walker stands at the table, \`idle → stale\`: one A2, walked from the spot.`;
    notes['intern-stop-mid-trip'] = `intern-trip (${at(N, C + 5)}, 5 s after clock 0), then at 7 s, on its walk out, the element lost (\`subagents_open\` 1 → 0): one A10, walked from where the intern is. ${clock(C)}.`;
    notes['busy-trip'] = `\`aimla-impl-1\`, \`working\` at an open call, through its break (${at(I1, E + 5)}, 5 s after clock 0) with its food thought, and at 35 s, on the dwell, the call closes with the turn open (\`open_calls\` 1 → 0: A3 → A4, \`working\` → \`working\`): the trip goes on, and the walker sits down thinking at its end. ${clock(E)}.`;
    notes['two-visitors'] = `\`aimla-impl-2\` and \`aimla-impl-1\` both idle; ${at(I2, TV + 5)} begins 5 s after clock 0 and ${at(I1, TV + 5 + tvGap)} ${tvGap} s after it — both at the tall table at once. ${clock(TV)}.`;
    notes['two-visitors-late'] = `two-visitors' page loaded ${lateBy} s after its clock 0 — 15 s after \`aimla-impl-1\`'s break began, both walkers then on their way: a page that decides both breaks on its first pass, in start-second order, seats them as two-visitors' continuous viewer did. ${clock(TV + lateBy)}.`;
    notes['tall-and-low'] = `\`aimla-impl-2\` idle and \`aimla-pm\`'s intern idle (its seat working); ${at(I2, TL + 5)} begins 5 s after clock 0 and ${at(N, TL + 5 + tlGap)} ${tlGap} s after it — an agent at the tall table and an intern at the low one at once. ${clock(TL)}.`;
    notes['past-the-last-spot'] = `\`aimla-impl-1\`, \`aimla-impl-2\` and \`aimla-review\` idle over \`fixtures/maps/snack-two-spots.json\`, whose tall table has exactly two spots; ${at(I1, pl.I1)}, ${at(I2, pl.I2)} and ${at(RV, pl.RV)}, the first 5 s after clock 0 — the third to come takes no trip. ${clock(PL0)}.`;
    notes['no-corner'] = `idle-trip (${aNote}) over \`fixtures/maps/snack-none.json\`, the shipped default without the two snack tiles: a floor with no corner.`;
    notes['no-route'] = `idle-trip (${aNote}) over \`fixtures/maps/snack-sealed.json\`, the shipped default with a wall from the office's front return to the front edge at x 1584: the tall table stands where no route reaches.`;
    notes['reload-mid-trip'] = `idle-trip (${aNote}), then at 46 s — thirty seconds or more into the dwell — a delta two versions ahead for \`aimla-impl-2\`, whose resync fetch returns the seat still idle: a non-delta entry, so the trip is re-derived from it and continues at its phase.`;
    notes['reload-mid-trip-viewer'] = `reload-mid-trip's SECOND VIEWER: a page whose clock 0 is one loop frame after reload-mid-trip's resync instant (${aNote}, so clock 0 here is 46.25 s after reload-mid-trip's clock 0): it enters the trip at its phase.`;
    notes['next-break'] = `idle-trip's walker at its NEXT break: ${clock(A_next - 5)}, and ${at(I2, A_next)} begins 5 s later — ${A_next - A_s} s after idle-trip's break, in a later hour, derived by this page from the day's anchor and never from idle-trip's.`;
    notes['feed-down'] = `\`aimla-impl-2\` idle; one heartbeat at 0.5 s and the stream silent after it, past § 9 F1's 45 s, before its break (${at(I2, A_s)}, 50 s after clock 0): no trip.`;
    notes['feed-dies-mid-trip'] = `idle-trip (${aNote}), the stream silent from 0.5 s, so F1's verdict lands during the trip: the trip finishes and no other starts.`;
    notes['map-moves-table'] = `idle-trip (${aNote}), then at 35 s, during the dwell, a \`room.map\` revision — \`fixtures/maps/snack-moved.json\`, the tall table moved into the left strip, as map version 4: the lattice key moved, so the trip ends and the sleeper is at its desk.`;
    notes['stilled-mid-trip'] = `idle-trip (${aNote}), then at 35 s, during the dwell, a delta two versions ahead for \`aimla-impl-1\` whose resync fetch answers 401: the floor stilled (§ 9 F6), the trip ends and the sleeper is at its desk.`;

    const ORDER = ['idle-trip', 'blocked-window', 'busy-trip', 'wakes-mid-trip', 'intern-trip', 'intern-old-reporter', 'recall-mid-trip', 'leave-mid-trip',
        'intern-stop-mid-trip', 'two-visitors', 'two-visitors-late', 'tall-and-low', 'past-the-last-spot', 'no-corner', 'no-route', 'reload-mid-trip',
        'reload-mid-trip-viewer', 'next-break', 'feed-down', 'feed-dies-mid-trip', 'map-moves-table', 'stilled-mid-trip'];
    return {
        note: "docs/design/FLOOR.md § 11's `fx-snack` (card#12749, card#12785; AT-D3-32): the snack corner's trips. Every run is fx-snapshot-4's four `aimla` seats over the shipped default map (or the stated variant under `fixtures/maps/`), with every instant of the run moved by one constant so that the run's clock 0 is the corrected server second its note states — each walker's breaks are the chain `scene.js`'s `snackDay()` derives from the day's anchor, `day × 86400 + draw(key, 'snack-anchor:' + day) mod 3600`, each break `3600 + draw(key, 'snack-gap:' + s) mod …` seconds after the one before, re-derived by the test through the character tree's own `draw()`. Heartbeats every 15 s keep the feed live where the run's note does not say otherwise. Generated by `tools/design/gen-fx-snack.mjs`; regenerate rather than edit.",
        runs: Object.fromEntries(ORDER.map((k) => {
            const { note, ...rest } = runs[k];

            return [k, { note: notes[k], ...rest }];
        })),
    };
}

// ─── stage 3: card#12786's restroom visits, fx-restroom ───────────────────────────────────────────────────────────
// Every run is fx-snapshot-4's four `aimla` seats over the shipped default (or a stated variant), its clock 0 a corrected
// server second a stated few seconds before the walker under test's RESTROOM break (`scene.js`'s `snackDay()` under
// `RESTROOM_CHAIN`), with no other walker's break of either chain near the run unless the run says so. The frames a visit's
// legs take on the shipped default are read off the shipped floor screen itself — idle-visit and intern-visit are replayed
// through the harness's probe and the instant a later delta lands is derived from the legs it drew — so a re-laid map
// moves those instants with it, and `--check` says so.
function restroomRuns() {
    const PM = 'aimla/aimla-pm';
    const I1 = 'aimla/aimla-impl-1';
    const I2 = 'aimla/aimla-impl-2';
    const RV = 'aimla/aimla-review';
    const N = `aimla/aimla-pm~${PM_CALL}`;
    const ALL = [PM, I1, I2, RV, N];
    const R = sc.RESTROOM_CHAIN;
    const rBreaks = (k, a, b) => sc.snackBreaks(draw, k, a, b, R);
    const sBreaks = (k, a, b) => sc.snackBreaks(draw, k, a, b);
    const rDay = (k, d) => sc.snackDay(draw, k, d, R);
    const iso = (s) => new Date(Math.round(s * 1000)).toISOString();
    // No break of either chain for `keys` in [ref − 400, ref + span]; `free` lists keys whose restroom breaks are the run's.
    const quiet = (ref, span, free = []) => ALL.every((k) => sBreaks(k, ref - 400, ref + span).length === 0
        && (free.includes(k) || rBreaks(k, ref - 400, ref + span).length === 0));
    const breakNote = (k, s) => {
        const day = Math.floor(s / 86400);
        const d = rDay(k, day).includes(s) ? day : day - 1;

        return `restroom break ${rDay(k, d).indexOf(s)} of day ${d}'s chain for \`${k}\` (second ${s}; dwell ${sc.restroomBreak(draw, k, s).dwell_s} s)`;
    };
    const clock = (ref) => `The run's clock 0 is the corrected server second ${iso(ref)}`;
    // The first break of `primary` (days d0 … d1) for which `ok(ref, s)` holds, its clock 0 five seconds before it.
    function search(primary, ok, d0 = 19900, d1 = 60000) {
        for (let day = d0; day < d1; day++) {
            for (const s of rDay(primary, day)) {
                if (ok(s - 5, s)) {
                    return s - 5;
                }
            }
        }

        throw new Error(`no restroom instant for ${primary}`);
    }
    const IDLE_SEAT = { render_state: 'idle', link_state: 'live', activity_state: 'idle', open_calls: 0, open_turn: false, action: null, longest_open_call: null };
    const pm = SEATS['aimla-pm'];
    const pmIdle = { subagents: [{ ...pm.subagents[0], activity_state: 'idle' }] };
    // Each run's own arguments, kept so a LATE VIEWER of it — the same run loaded `off` seconds after its clock 0 — can be
    // built from them (card#12786 review r1).
    const argsOf = new Map();
    const make = (o) => {
        const r = run({ ...o, note: '' });

        argsOf.set(r, o);

        return r;
    };
    const runs = {};
    const notes = {};
    // One run of the harness's probe over a scenario, its first A26 visit of `walker` and the frame it reached.
    const probe = (scenario, walker) => {
        const out = spawnSync('node', [join(ROOT, 'server/tests/Feature/Floor/fleet-client-probe.mjs'), join(ROOT, 'server/public/js/wire')],
            { input: JSON.stringify(scenario), maxBuffer: 1 << 30, cwd: join(ROOT, 'server') });

        if (out.status !== 0) {
            throw new Error(`the probe failed over a restroom run: ${out.stderr}`);
        }

        for (const r of JSON.parse(out.stdout).runs[0].floor_renders) {
            for (const fx of r.frame.scene?.effects ?? []) {
                if (fx.animation_id === 'A26' && fx.walker_key === walker && fx.leg_kind === 'restroom') {
                    return fx;
                }
            }
        }

        throw new Error(`the probe drew no visit for ${walker}`);
    };
    const legsOf = (fx) => {
        const at = (kind, why) => fx.legs.find((l) => l.kind === kind && (why === undefined || l.why === why));

        return { door: at('stand', 'door'), inside: at('inside'), wait: at('stand', 'wait'), home: fx.legs[fx.legs.length - 1] };
    };
    // A visit's frames as ms from the run's clock 0, its break 5 s after it.
    const msOf = (f) => 5000 + f * 250;

    // ── idle-visit: aimla-impl-2 idle, its break at clock 0 + 5, nothing else near. ─────────────────────────────────────────
    const A = search(I2, (ref) => quiet(ref, 360, [I2]) && rBreaks(I2, ref - 400, ref + 360).length === 1
        && (() => {
            const next = rBreaks(I2, ref + 6, ref + 6 + R.gap.max)[0].start_s;

            return quiet(next - 5, 360, [I2]);
        })());
    const A_s = A + 5;
    const A_dwell = sc.restroomBreak(draw, I2, A_s).dwell_s;
    const A_next = rBreaks(I2, A_s + 1, A_s + R.gap.max)[0].start_s;
    const aUntil = 5000 + (A_dwell + 90) * 1000;

    runs['idle-visit'] = make({ at_s: A, until_ms: aUntil });
    notes['idle-visit'] = `\`aimla-impl-2\`, idle from the snapshot: out, the leaf, in, the dwell, out, home, then silence. ${clock(A)}: ${breakNote(I2, A_s)} begins 5 s later.`;

    const aVisit = probe(runs['idle-visit'], I2);
    const aLegs = legsOf(aVisit);
    // Instants inside idle-visit's visit, as ms from its clock 0.
    const dwellAt = (fx, s) => msOf(legsOf(fx).inside.start + sc.ELEVATOR_DOOR.close) + s * 1000;
    const thirtyIn = dwellAt(aVisit, 30);
    const closing2 = msOf(aLegs.inside.start + 2);
    const V2 = SEATS['aimla-impl-2'].state_version;
    const aNote = `${clock(A)}: ${breakNote(I2, A_s)} begins 5 s later; on the shipped default its walker reaches the threshold ${(aLegs.door.start / 4).toFixed(2)} s after it`;

    runs['next-visit'] = make({ at_s: A_next - 5, until_ms: 40000 });
    notes['next-visit'] = `idle-visit's walker at its NEXT restroom break: ${clock(A_next - 5)}, and ${breakNote(I2, A_next)} begins 5 s later — ${A_next - A_s} s after idle-visit's, derived by this page from the day's anchor and never from idle-visit's.`;

    runs['blocked-visit'] = (() => {
        const B = search(RV, (ref) => quiet(ref, 120, [RV]));

        notes['blocked-visit'] = `\`aimla-review\`, \`blocked\`, through its own restroom break (${breakNote(RV, B + 5)}, 5 s after clock 0): the control — no visit, the leaf never moves. ${clock(B)}.`;

        return make({ at_s: B, until_ms: 60000 });
    })();

    // ── busy-visit: aimla-impl-1 working, a working → working delta on the dwell. ──────────────────────────────────────────
    {
        const E = search(I1, (ref) => quiet(ref, 360, [I1]));
        const r = make({ at_s: E, until_ms: 5000 + (sc.restroomBreak(draw, I1, E + 5).dwell_s + 90) * 1000 });
        const fx = probe(r, I1);
        const at = dwellAt(fx, 20);
        const v1 = SEATS['aimla-impl-1'].state_version;

        r.messages.push({ at_ms: at, envelope: { feed_version: 2, t: 'seat.delta', server_time: iso(E + at / 1000), install_id: 'aimla', seat_id: 'aimla-impl-1', state_version: v1 + 1, at: iso(E + at / 1000), changed: ['action', 'longest_open_call', 'open_calls'], patch: { action: null, longest_open_call: null, open_calls: 0 } } });
        r.messages.sort((a, b) => a.at_ms - b.at_ms);
        runs['busy-visit'] = r;
        notes['busy-visit'] = `\`aimla-impl-1\`, \`working\` at an open call, through its restroom break (${breakNote(I1, E + 5)}, 5 s after clock 0) with the line, its desk keeping every fact; at ${at} ms, twenty seconds into the dwell, the call closes with the turn open (A3 → A4, \`working → working\`): the visit goes on. ${clock(E)}.`;
    }

    // ── intern-visit: aimla-pm's intern idle, the seat working. ────────────────────────────────────────────────────────────
    const C = search(N, (ref) => quiet(ref, 360, [N]));
    const cUntil = 5000 + (sc.restroomBreak(draw, N, C + 5).dwell_s + 90) * 1000;

    runs['intern-visit'] = make({ at_s: C, until_ms: cUntil, seats: { 'aimla-pm': pmIdle } });
    notes['intern-visit'] = `\`aimla-pm\`'s intern \`${PM_CALL}\` at \`idle\` from the snapshot, the seat itself \`working\`, through the intern's restroom break (${breakNote(N, C + 5)}, 5 s after clock 0). ${clock(C)}.`;

    const cVisit = probe(runs['intern-visit'], N);
    const cThirty = dwellAt(cVisit, 30);

    runs['intern-stop-inside'] = make({ at_s: C, until_ms: cThirty + 40000, seats: { 'aimla-pm': pmIdle },
        deltas: [{ at_ms: cThirty, seat: 'aimla-pm', v: pm.state_version + 1, patch: { subagents: [], subagents_open: 0 } }] });
    notes['intern-stop-inside'] = `intern-visit, then at ${cThirty} ms — thirty seconds into the dwell, the leaf closed over the intern — the element lost (\`subagents_open\` 1 → 0): one A10, walked from the threshold once the leaf has opened. ${clock(C)}.`;

    // ── card#12993: a walker that arrives by the elevator after its break began, or during it, takes no trip for it. ────
    const NONE = { subagents: [], subagents_open: 0 };
    const GAIN = 65000;
    const STOP = GAIN + 41000;
    const working = { ...pm.subagents[0], activity_state: 'working', started_at: iso(C + (GAIN - 6000) / 1000) };

    runs['intern-arrives-in-its-break'] = make({ at_s: C, until_ms: STOP + 70000, seats: { 'aimla-pm': NONE },
        deltas: [
            { at_ms: GAIN, seat: 'aimla-pm', v: pm.state_version + 1, patch: { subagents: [working], subagents_open: 1 } },
            { at_ms: STOP, seat: 'aimla-pm', v: pm.state_version + 2, patch: { subagents: [], subagents_open: 0 } },
        ] });
    notes['intern-arrives-in-its-break'] = `\`aimla-pm\` with no interns, then at ${GAIN} ms its intern \`${PM_CALL}\` gained \`working\` (\`subagents_open\` 0 → 1; \`started_at\` 6 s earlier on the seat's clock), ${(GAIN - 5000) / 1000} s after the intern's restroom break began (${breakNote(N, C + 5)}, 5 s after clock 0) — before the intern existed — and at ${STOP} ms, 41 s after the gain, lost (1 → 0): it arrives, sits, takes no trip for that break, and leaves from its stool by the elevator (the sequence seen live on 2026-11-08). ${clock(C)}.`;

    runs['seat-arrives-in-its-break'] = make({ at_s: A, until_ms: 60000, seats: { 'aimla-impl-2': { render_state: 'stale', link_state: 'stale' } },
        deltas: [{ at_ms: 2000, seat: 'aimla-impl-2', v: V2 + 1, patch: { render_state: 'idle', link_state: 'live' } }] });
    notes['seat-arrives-in-its-break'] = `\`aimla-impl-2\` \`stale\` from the snapshot, then at 2000 ms \`stale → idle\`: its A1 walk from the elevator is in flight when its restroom break begins (${breakNote(I2, A + 5)}, 5 s after clock 0), so it takes no trip for that break and sits at its desk. ${clock(A)}.`;

    // A resync for the seat while its intern waits at the elevator: the intern walks on. Clock 0 well clear of every break.
    {
        const Q = search(N, (ref) => quiet(ref + 2000 - 600, 720)) + 2000;
        const resync = seatBody('aimla-pm', pm.state_version + 3, 13000, Q);

        Object.assign(resync, NONE);
        runs['intern-leave-resync'] = make({ at_s: Q, until_ms: 90000, seats: { 'aimla-pm': { subagents: [{ ...pm.subagents[0], activity_state: 'working' }] } },
            deltas: [
                { at_ms: 5000, seat: 'aimla-pm', v: pm.state_version + 1, patch: NONE },
                { at_ms: 13000, seat: 'aimla-pm', v: pm.state_version + 3, patch: { open_calls: 0 } },
            ],
            extra_http: { '/api/fleet/seats/aimla/aimla-pm': [{ status: 200, body: resync }] } });
        notes['intern-leave-resync'] = `\`aimla-pm\`'s intern \`${PM_CALL}\` seated \`working\` from the snapshot, lost at 5000 ms (\`subagents_open\` 1 → 0), and at 13000 ms, while its walk out is in flight, a delta two versions ahead for \`aimla-pm\` whose resync fetch returns the seat without it: a non-delta entry for the seat, which leaves the intern walking out to the elevator. No break of either chain falls near. ${clock(Q)}.`;
    }

    // ── the interrupts over idle-visit. ─────────────────────────────────────────────────────────────────────────────────
    const blocked = (at) => ({ at_ms: at, seat: 'aimla-impl-2', v: V2 + 1, patch: { render_state: 'blocked', activity_state: 'blocked', blocked_since: iso(A + at / 1000) } });

    runs['recall-inside'] = make({ at_s: A, until_ms: thirtyIn + 40000, deltas: [blocked(thirtyIn)] });
    notes['recall-inside'] = `idle-visit (${aNote}), then at ${thirtyIn} ms — thirty seconds into the dwell — \`idle → blocked\` (an attention request): the field turns vacant, the leaf opens, the walker walks home and the leaf closes behind it.`;
    runs['recall-closing'] = make({ at_s: A, until_ms: closing2 + 40000, deltas: [blocked(closing2)] });
    notes['recall-closing'] = `idle-visit (${aNote}), then at ${closing2} ms — two frames into the leaf's closing over the walker — \`idle → blocked\`: the leaf reverses from where it is, the field never turns, the walker walks home.`;
    runs['leave-inside'] = make({ at_s: A, until_ms: thirtyIn + 60000, deltas: [{ at_ms: thirtyIn, seat: 'aimla-impl-2', v: V2 + 1, patch: { render_state: 'stale', link_state: 'stale' } }] });
    notes['leave-inside'] = `idle-visit (${aNote}), then at ${thirtyIn} ms, inside, \`idle → stale\`: the leaf opens and one A2 walks from the threshold.`;
    runs['reload-inside'] = make({ at_s: A, until_ms: aUntil,
        deltas: [{ at_ms: thirtyIn, seat: 'aimla-impl-2', v: V2 + 2, patch: { activity_state: 'idle' } }],
        extra_http: { '/api/fleet/seats/aimla/aimla-impl-2': [{ status: 200, body: seatBody('aimla-impl-2', V2 + 2, thirtyIn, A) }] } });
    notes['reload-inside'] = `idle-visit (${aNote}), then at ${thirtyIn} ms — thirty seconds into the dwell — a delta two versions ahead for \`aimla-impl-2\`, whose resync fetch returns the seat still idle: a non-delta entry, so the visit is re-derived from it and continues at its phase, inside.`;
    runs['reload-inside-viewer'] = make({ at_s: A + thirtyIn / 1000 + 0.25, until_ms: aUntil - thirtyIn, beats: range(0, aUntil - thirtyIn, 15000) });
    notes['reload-inside-viewer'] = `reload-inside's SECOND VIEWER: a page whose clock 0 is one loop frame after reload-inside's resync instant (${aNote}, so clock 0 here is ${thirtyIn / 1000 + 0.25} s after idle-visit's): its first render draws the leaf closed and the field occupied, no walker on the floor.`;
    runs['restroom-feed-down'] = make({ at_s: A_s - 50, until_ms: 260000, beats: [500] });
    notes['restroom-feed-down'] = `\`aimla-impl-2\` idle; one heartbeat at 0.5 s and the stream silent after it, past § 9 F1's 45 s, before its restroom break (${breakNote(I2, A_s)}, 50 s after clock 0): no visit, the leaf never moves.`;
    runs['feed-dies-inside'] = make({ at_s: A, until_ms: aUntil, beats: [500] });
    notes['feed-dies-inside'] = `idle-visit (${aNote}), the stream silent from 0.5 s, so F1's verdict lands at 45.5 s, inside: the visit finishes and no other starts.`;
    runs['no-door'] = make({ at_s: A, until_ms: 40000, map: `@json:${MAPS}/restroom-none.json` });
    notes['no-door'] = `idle-visit (${aNote}) over \`fixtures/maps/restroom-none.json\`, the shipped default without the door's tile: a floor with no restroom.`;
    runs['restroom-no-route'] = make({ at_s: A, until_ms: 40000, map: `@json:${MAPS}/restroom-sealed.json` });
    notes['restroom-no-route'] = `idle-visit (${aNote}) over \`fixtures/maps/restroom-sealed.json\`, the shipped default with walls closing the door's forecourt off: its threshold stands where no route reaches.`;
    runs['map-removes-door'] = make({ at_s: A, until_ms: thirtyIn + 20000,
        maps: [{ status: 200, body: { install_id: 'aimla', source: 'default', map_version: null, map: '@json:resources/floor/default.tmj' } },
            { status: 200, body: { install_id: 'aimla', source: 'authored', map_version: 5, map: `@json:${MAPS}/restroom-none.json` } }],
        deltas: [{ at_ms: thirtyIn, t: 'room.map', map_version: 5, revision_id: 913 }] });
    notes['map-removes-door'] = `idle-visit (${aNote}), then at ${thirtyIn} ms, inside, a \`room.map\` revision — \`fixtures/maps/restroom-none.json\` as map version 5: the lattice key moved, so the visit ends, the sleeper is at its desk and the door is gone.`;
    runs['stilled-inside'] = make({ at_s: A, until_ms: thirtyIn + 20000,
        deltas: [{ at_ms: thirtyIn, seat: 'aimla-impl-1', v: SEATS['aimla-impl-1'].state_version + 2, patch: { activity_state: 'working' } }],
        extra_http: { '/api/fleet/seats/aimla/aimla-impl-1': [{ status: 401, body: { api_version: 1, server_time: iso(A + thirtyIn / 1000), error: 'token_revoked' } }] } });
    notes['stilled-inside'] = `idle-visit (${aNote}), then at ${thirtyIn} ms, inside, a delta two versions ahead for \`aimla-impl-1\` whose resync fetch answers 401: the floor stilled (§ 9 F6), the visit ends, the door closed and vacant, the sleeper at its desk.`;

    // ── two-at-the-door: impl-2 at + 5, impl-1 2–10 s after it, both idle. ─────────────────────────────────────────────────
    let tGap = null;
    const T = search(I2, (ref, s) => {
        const other = rBreaks(I1, s + 2, s + 10);

        if (other.length !== 1 || !quiet(ref, 700, [I1, I2]) || rBreaks(I2, ref - 400, ref + 700).length !== 1 || rBreaks(I1, ref - 400, ref + 700).length !== 1) {
            return false;
        }

        tGap = other[0].start_s - s;

        return true;
    });
    const tUntil = 5000 + (tGap + sc.restroomBreak(draw, I2, T + 5).dwell_s + sc.restroomBreak(draw, I1, T + 5 + tGap).dwell_s + 120) * 1000;

    runs['two-at-the-door'] = make({ at_s: T, until_ms: tUntil, seats: { 'aimla-impl-1': IDLE_SEAT } });
    notes['two-at-the-door'] = `\`aimla-impl-2\` and \`aimla-impl-1\` both idle; ${breakNote(I2, T + 5)} begins 5 s after clock 0 and ${breakNote(I1, T + 5 + tGap)} ${tGap} s after it: the second waits at spot 1 until the first's exit has closed the leaf. ${clock(T)}.`;

    const second = probe(runs['two-at-the-door'], I1);
    const waitMid = msOf(0) + (legsOf(second).wait.start + legsOf(second).wait.frames / 2) * 250 + tGap * 1000;

    runs['recall-waiting'] = make({ at_s: T, until_ms: waitMid + 60000, seats: { 'aimla-impl-1': IDLE_SEAT },
        deltas: [{ at_ms: Math.round(waitMid / 250) * 250, seat: 'aimla-impl-1', v: SEATS['aimla-impl-1'].state_version + 1, patch: { render_state: 'blocked', activity_state: 'blocked', blocked_since: iso(T + Math.round(waitMid / 250) / 4) } }] });
    notes['recall-waiting'] = `two-at-the-door, then at ${Math.round(waitMid / 250) * 250} ms, halfway through \`aimla-impl-1\`'s wait at its spot, \`idle → blocked\`: it walks home from its spot. ${clock(T)}.`;

    const lateBy = 5 + tGap + 3;
    runs['reload-queue'] = make({ at_s: T + lateBy, until_ms: tUntil - lateBy * 1000, seats: { 'aimla-impl-1': IDLE_SEAT } });
    notes['reload-queue'] = `two-at-the-door's page loaded ${lateBy} s after its clock 0 — 3 s after \`aimla-impl-1\`'s break began, both walkers then on their way: a page that decides both breaks on its first pass, in start-second order, gives each the spot and the turn two-at-the-door's continuous viewer gave it. ${clock(T + lateBy)}.`;

    // ── three-at-the-door: four walkers — the occupant, one waiting at spot 1, one at spot 2, and a fourth whose break comes
    // after the first waiter has left spot 1: it takes spot 1, nearer the door, and still goes in after the one at spot 2.
    let q = null;
    search(I2, (ref, s) => {
        const d1 = sc.restroomBreak(draw, I2, s).dwell_s;
        const others = [I1, RV, PM, N];

        for (const a of others) {
            const ab = rBreaks(a, s + 2, s + 60);

            if (ab.length !== 1) continue;

            const da = ab[0].dwell_s;

            for (const c of others.filter((x) => x !== a)) {
                const cb = rBreaks(c, ab[0].start_s + 2, s + 90);

                if (cb.length !== 1) continue;

                for (const d of others.filter((x) => x !== a && x !== c)) {
                    // after the occupant has left the door (the first waiter then leaves spot 1), before the first waiter frees it
                    const db = rBreaks(d, s + d1 + 25, s + d1 + da - 5);

                    if (db.length === 1 && quiet(ref, 900, [I2, a, c, d]) && [I2, a, c, d].every((k) => rBreaks(k, ref - 400, ref + 900).length === 1)) {
                        q = { s, a, c, d, as: ab[0].start_s, cs: cb[0].start_s, ds: db[0].start_s };

                        return true;
                    }
                }
            }
        }

        return false;
    }, 19900, 80000);
    {
        const ref = q.s - 5;
        const seats = { 'aimla-impl-1': IDLE_SEAT, 'aimla-review': { ...IDLE_SEAT, blocked_since: null } };
        const dw = (k, s) => sc.restroomBreak(draw, k, s).dwell_s;

        runs['three-at-the-door'] = make({ at_s: ref, until_ms: 5000 + (q.ds - q.s + dw(q.c, q.cs) + dw(q.d, q.ds) + dw(q.a, q.as) + 200) * 1000, seats });
        notes['three-at-the-door'] = `four walkers at one door — \`aimla-impl-1\` and \`aimla-review\` patched idle, \`aimla-pm\` working: ${breakNote(I2, q.s)} 5 s after clock 0, the occupant; ${breakNote(q.a, q.as)}, waiting at spot 1; ${breakNote(q.c, q.cs)}, waiting at spot 2; and ${breakNote(q.d, q.ds)}, which comes after the first waiter has left spot 1 for the door — it takes spot 1, nearer the door than spot 2, and goes in after the walker there, whose break began first. ${clock(ref)}.`;
    }

    // ── past-the-last-spot: three walkers at a door with one waiting spot. ─────────────────────────────────────────────────
    {
        let p = null;
        const ref = search(I2, (r, s) => {
            const a = rBreaks(I1, s + 2, s + 30);
            const c = rBreaks(RV, s + 2, s + 30);

            if (a.length !== 1 || c.length !== 1 || a[0].start_s === c[0].start_s || !quiet(r, 400, [I1, I2, RV])
                || [I1, I2, RV].some((k) => rBreaks(k, r - 400, r + 400).length !== 1)) {
                return false;
            }

            p = { a: a[0].start_s, c: c[0].start_s };

            return true;
        }, 19900, 80000);

        runs['restroom-past-the-last-spot'] = make({ at_s: ref, until_ms: 5000 + (sc.restroomBreak(draw, I2, ref + 5).dwell_s + 60) * 1000,
            seats: { 'aimla-impl-1': IDLE_SEAT, 'aimla-review': { ...IDLE_SEAT, blocked_since: null } }, map: `@json:${MAPS}/restroom-one-spot.json` });
        notes['restroom-past-the-last-spot'] = `\`aimla-impl-2\`, \`aimla-impl-1\` and \`aimla-review\` idle over \`fixtures/maps/restroom-one-spot.json\`, whose door has exactly one waiting spot: ${breakNote(I2, ref + 5)} 5 s after clock 0, ${breakNote(I1, p.a)} and ${breakNote(RV, p.c)} — the third to come takes no visit. ${clock(ref)}.`;
    }

    // ── card#12841: the lane in front of the door, over restroom-corridor. ────────────────────────────────────────────────
    {
        const corridor = `@json:${MAPS}/restroom-corridor.json`;

        // lane-two-at-the-door: two-at-the-door's walkers and breaks over the corridor — the second waits outside the lane
        // and leaves its spot once the first, on its way out down the corridor, is clear of it.
        runs['lane-two-at-the-door'] = make({ at_s: T, until_ms: tUntil, seats: { 'aimla-impl-1': IDLE_SEAT }, map: corridor });
        notes['lane-two-at-the-door'] = `two-at-the-door's walkers and breaks (${breakNote(I2, T + 5)} 5 s after clock 0, ${breakNote(I1, T + 5 + tGap)} ${tGap} s after it) over \`fixtures/maps/restroom-corridor.json\`, whose door's forecourt is a corridor down its lane: the second waits at a spot outside the lane and leaves it once the first, walking out down the corridor, is clear of the lane. ${clock(T)}.`;

        // lane-straight-in: aimla-impl-1's break comes as aimla-impl-2 is about to come out, timed so that its walk straight
        // to the door reaches the threshold after the door has freed and sets foot in the lane while the first is still in
        // it — the frames read off the floor screen's own legs for each walker's lone visit over the corridor.
        // (The probe is handed the variant itself: the file it would read is the one this run of the generator writes.)
        const inline = variant(JSON.parse(readFileSync(join(ROOT, 'resources', 'floor', 'default.tmj'), 'utf8')), 'restroom-corridor');
        const lone = (k) => {
            const ref = search(k, (r) => quiet(r, 360, [k]) && rBreaks(k, r - 400, r + 360).length === 1);

            return probe(run({ note: '', at_s: ref, until_ms: 5000 + (sc.restroomBreak(draw, k, ref + 5).dwell_s + 90) * 1000, seats: { 'aimla-impl-1': IDLE_SEAT }, map: inline }), k);
        };
        const first = lone(I2);
        const second = lone(I1);
        const firstHome = first.legs[first.legs.length - 1];
        // In the first visit's frames: its walk out of the door (`W`), the door free (`F`) and the lane clear (`C`) behind it,
        // for its lone visit's dwell; a longer dwell moves all three by four frames a second.
        const W = firstHome.start;
        const F = first.claim.end;
        const C = sc.laneFrame(first, first.lane, W, false);
        const wo = second.legs[0].frames;
        const en = sc.laneFrame(Object.freeze({ legs: [second.legs[0]], frames: wo, frame_interval_ms: second.frame_interval_ms }), second.lane, 0, true);
        let gap = null;
        const ref = search(I2, (r, s1) => {
            const shift = 4 * (sc.restroomBreak(draw, I2, s1).dwell_s - first.dwell_s);
            const later = rBreaks(I1, s1 + 60, s1 + 200);

            if (later.length !== 1 || !quiet(r, 700, [I1, I2]) || rBreaks(I2, r - 400, r + 700).length !== 1 || rBreaks(I1, r - 400, r + 700).length !== 1) {
                return false;
            }

            const d = 4 * (later[0].start_s - s1);

            // The door is free by its arrival, and the lane is not by the frame it would set foot in it.
            if (F + shift <= d + wo && d + en < C + shift) {
                gap = later[0].start_s - s1;

                return true;
            }

            return false;
        }, 19900, 200000);
        const sUntil = 5000 + (gap + sc.restroomBreak(draw, I1, ref + 5 + gap).dwell_s + 120) * 1000;

        runs['lane-straight-in'] = make({ at_s: ref, until_ms: sUntil, seats: { 'aimla-impl-1': IDLE_SEAT }, map: corridor });
        notes['lane-straight-in'] = `\`aimla-impl-2\` and \`aimla-impl-1\` both idle over \`fixtures/maps/restroom-corridor.json\`: ${breakNote(I2, ref + 5)} 5 s after clock 0 and ${breakNote(I1, ref + 5 + gap)} ${gap} s after it — timed, off the floor screen's own legs for each walker's lone visit over this map, so that the second's walk straight to the door would reach the threshold after the door has freed behind the first but set foot in the lane while the first, walking out down the corridor, is still in it: the second waits at a spot outside the lane instead. ${clock(ref)}.`;
    }

    // ── the two chains, one walker. ───────────────────────────────────────────────────────────────────────────────────
    {
        // snack-then-restroom: impl-1 (working), its snack break at + 5 and its restroom break 10–30 s later.
        let gap = null;
        const ref = (() => {
            for (let day = 19900; day < 80000; day++) {
                for (const s of sc.snackDay(draw, I1, day)) {
                    const rb = rBreaks(I1, s + 10, s + 30);
                    const ref0 = s - 5;

                    if (rb.length === 1 && sBreaks(I1, ref0 - 400, ref0 + 400).length === 1 && rBreaks(I1, ref0 - 400, ref0 + 400).length === 1
                        && [PM, I2, RV, N].every((k) => sBreaks(k, ref0 - 400, ref0 + 400).length === 0 && rBreaks(k, ref0 - 400, ref0 + 400).length === 0)) {
                        gap = rb[0].start_s - s;

                        return ref0;
                    }
                }
            }

            throw new Error('no snack-then-restroom instant');
        })();

        runs['snack-then-restroom'] = make({ at_s: ref, until_ms: 5000 + (gap + 75 + 150 + 120) * 1000 });
        notes['snack-then-restroom'] = `\`aimla-impl-1\`, \`working\`: its snack break (second ${ref + 5}) 5 s after clock 0 and ${breakNote(I1, ref + 5 + gap)} ${gap} s after it, while it walks to or stands at the tall table: it walks from the table to the door on the frame its snack dwell ends, the line changing there, and home from the door. ${clock(ref)}.`;
    }
    {
        // restroom-then-snack: impl-2 (idle), its restroom break at + 5 and its snack break 25–85 s later, inside.
        let gap = null;
        const ref = search(I2, (r, s) => {
            const sb = sBreaks(I2, s + 25, s + 85);

            if (sb.length !== 1 || sc.restroomBreak(draw, I2, s).dwell_s < sb[0].start_s - s + 5 || rBreaks(I2, r - 400, r + 400).length !== 1
                || sBreaks(I2, r - 400, r + 400).length !== 1 || ![PM, I1, RV, N].every((k) => sBreaks(k, r - 400, r + 400).length === 0 && rBreaks(k, r - 400, r + 400).length === 0)) {
                return false;
            }

            gap = sb[0].start_s - s;

            return true;
        }, 19900, 80000);

        runs['restroom-then-snack'] = make({ at_s: ref, until_ms: 5000 + (sc.restroomBreak(draw, I2, ref + 5).dwell_s + 75 + 150) * 1000 });
        notes['restroom-then-snack'] = `\`aimla-impl-2\`, idle: ${breakNote(I2, ref + 5)} 5 s after clock 0 and its snack break ${gap} s after it, while it is inside: it walks from the door to the tall table on the frame the leaf has opened for its exit, and home from the table. ${clock(ref)}.`;
    }

    // ── queue-of-three: three idle walkers, their breaks about a minute apart, served one after another. ───────────────────
    {
        let q3 = null;
        const ref = search(I2, (r, s) => {
            const a = rBreaks(I1, s + 30, s + 90);
            const c = a.length === 1 ? rBreaks(RV, a[0].start_s + 30, a[0].start_s + 90) : [];

            if (a.length !== 1 || c.length !== 1 || !quiet(r, 700, [I1, I2, RV]) || [I1, I2, RV].some((k) => rBreaks(k, r - 400, r + 700).length !== 1)) {
                return false;
            }

            q3 = { a: a[0].start_s, c: c[0].start_s };

            return true;
        }, 19900, 80000);

        runs['queue-of-three'] = make({ at_s: ref, until_ms: 520000, seats: { 'aimla-impl-1': IDLE_SEAT, 'aimla-review': { ...IDLE_SEAT, blocked_since: null } } });
        notes['queue-of-three'] = `\`aimla-impl-2\`, \`aimla-impl-1\` and \`aimla-review\` idle: ${breakNote(I2, ref + 5)} 5 s after clock 0, ${breakNote(I1, q3.a)} and ${breakNote(RV, q3.c)} — each comes while the one before is inside, waits, and goes in in turn. ${clock(ref)}.`;
    }

    // ── LATE VIEWERS (card#12786 review r1): each route below replayed by a page loaded `off` seconds after the continuous
    // run's clock 0 — the same seats, the same breaks, the rest of the run — whose timeline must be the continuous viewer's.
    const LATE = [150, 170, 200, 260, 330];
    const lateNames = [];

    for (const route of ['queue-of-three', 'two-at-the-door', 'snack-then-restroom', 'restroom-then-snack', 'lane-two-at-the-door', 'lane-straight-in']) {
        const o = argsOf.get(runs[route]);

        for (const off of LATE) {
            const name = `${route}-at-${off}`;

            runs[name] = run({ ...o, note: '', at_s: o.at_s + off, until_ms: Math.max(30000, o.until_ms - off * 1000) });
            notes[name] = `${route}'s LATE VIEWER: a page whose clock 0 is ${off} s after ${route}'s — ${clock(o.at_s + off)} — over the same seats and breaks: it draws every break still in flight as ${route}'s continuous viewer drew it.`;
            lateNames.push(name);
        }
    }

    const ORDER = ['idle-visit', 'busy-visit', 'blocked-visit', 'intern-visit', 'two-at-the-door', 'three-at-the-door', 'queue-of-three', 'restroom-past-the-last-spot',
        'lane-two-at-the-door', 'lane-straight-in',
        'recall-waiting', 'recall-inside', 'recall-closing', 'leave-inside', 'intern-stop-inside', 'intern-arrives-in-its-break', 'seat-arrives-in-its-break', 'intern-leave-resync', 'snack-then-restroom', 'restroom-then-snack',
        'reload-inside', 'reload-inside-viewer', 'reload-queue', 'next-visit', 'no-door', 'restroom-no-route', 'restroom-feed-down', 'feed-dies-inside',
        'map-removes-door', 'stilled-inside', ...lateNames];

    return {
        note: "docs/design/FLOOR.md § 11's `fx-restroom` (card#12786; AT-D3-33): the restroom door's visits. Every run is fx-snapshot-4's four `aimla` seats over the shipped default map (or the stated variant under `fixtures/maps/`), every instant of the run moved by one constant so that the run's clock 0 is the corrected server second its note states — each walker's restroom breaks are the chain `scene.js`'s `snackDay()` derives under `RESTROOM_CHAIN` from the day's anchor, `day × 86400 + draw(key, 'restroom-anchor:' + day) mod 3600`, each break `3600 + draw(key, 'restroom-gap:' + s) mod …` seconds after the one before, re-derived by the test through the character tree's own `draw()`; no other break of either chain falls near a run unless its note says so. An instant a note places inside a visit is read off the shipped floor screen's own legs for that visit. Heartbeats every 15 s keep the feed live where the run's note does not say otherwise. Generated by `tools/design/gen-fx-snack.mjs`; regenerate rather than edit.",
        runs: Object.fromEntries(ORDER.map((k) => {
            const { note, ...rest } = runs[k];

            return [k, { note: notes[k], ...rest }];
        })),
    };
}

// ─── output ──────────────────────────────────────────────────────────────────────────────────────────────────────
const stage1 = { runs };
const out = chained(stage1);
const restroom = restroomRuns();
if (ONLY !== null) {
    const held = out.runs[ONLY] ?? restroom.runs[ONLY] ?? null;
    if (held === null) {
        console.error(`no run named ${ONLY}; the runs are: ${[...Object.keys(out.runs), ...Object.keys(restroom.runs)].join(' ')}`);
        process.exit(2);
    }
    process.stdout.write(JSON.stringify(held));
    process.exit(0);
}

const base = JSON.parse(readFileSync(join(ROOT, 'resources', 'floor', 'default.tmj'), 'utf8'));
const files = [
    [join(FX, 'fx-snack.json'), `${JSON.stringify(out)}\n`],
    [join(FX, 'fx-restroom.json'), `${JSON.stringify(restroom)}\n`],
    ...Object.keys(VARIANTS).map((name) => [join(ROOT, MAPS, `${name}.json`), `${JSON.stringify(variant(base, name))}\n`]),
];
let stale = 0;
for (const [path, bytes] of files) {
    if (!CHECK) {
        writeFileSync(path, bytes);
        continue;
    }
    let held = null;
    try {
        held = readFileSync(path, 'utf8');
    } catch {
        // a missing file is stale like a differing one
    }
    if (held !== bytes) {
        stale++;
        console.error(`stale: ${path.slice(ROOT.length + 1)} differs from what the generator writes`);
    }
}
if (CHECK) {
    console.log(stale === 0 ? `fresh: ${files.length} files match the generator` : `${stale} stale — run node tools/design/gen-fx-snack.mjs`);
    process.exit(stale === 0 ? 0 : 1);
}
console.log(files.map(([p]) => p.slice(ROOT.length + 1)).join('\n'));

// The generator of `fx-snack` (docs/design/FLOOR.md § 11, AT-D3-32; card#12749, card#12785, committed by card#12787):
// it writes server/tests/Feature/Floor/fixtures/fx-snack.json and the four shipped-default variants its runs draw
// over, server/tests/Feature/Floor/fixtures/maps/snack-{none,moved,sealed,two-spots}.json.
//
//   node tools/design/gen-fx-snack.mjs            rewrite the fixture and the four maps
//   node tools/design/gen-fx-snack.mjs --check    write nothing; exit 1 naming each file that differs from what it
//                                                 would write (a stale copy after a default.tmj change, or a hand edit)
//   node tools/design/gen-fx-snack.mjs <run>      print that one run of the fixture as JSON to stdout, write nothing
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
// was; `--check` says so.
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

    // Search days [d0, d1) for a reference second `ref` (the run's clock 0) satisfying `ok(ref)`, the primary walker's
    // break at ref + 5.
    function search(primary, ok, d0 = 19900, d1 = 21200) {
        for (let day = d0; day < d1; day++) {
            for (const s of sc.snackDay(draw, primary, day)) {
                if (ok(s - 5, s)) {
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

        return [PM, I1, N].every((k) => quiet(k, ref - 400, ref + 200) && quiet(k, next - 5 - 400, next - 5 + 160));
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

// ─── output ──────────────────────────────────────────────────────────────────────────────────────────────────────
const stage1 = { runs };
const out = chained(stage1);
if (ONLY !== null) {
    if (!(ONLY in out.runs)) {
        console.error(`no run named ${ONLY}; the runs are: ${Object.keys(out.runs).join(' ')}`);
        process.exit(2);
    }
    process.stdout.write(JSON.stringify(out.runs[ONLY]));
    process.exit(0);
}

const base = JSON.parse(readFileSync(join(ROOT, 'resources', 'floor', 'default.tmj'), 'utf8'));
const files = [
    [join(FX, 'fx-snack.json'), `${JSON.stringify(out)}\n`],
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

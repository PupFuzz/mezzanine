// The generator of `fx-turn-back` (docs/design/FLOOR.md § 11, AT-D3-36; card#12466): it writes
// server/tests/Feature/Floor/fixtures/fx-turn-back.json.
//
//   node tools/design/gen-fx-turn-back.mjs            rewrite the fixture and its fuzz set (fx-turn-back-fuzz.json)
//   node tools/design/gen-fx-turn-back.mjs --check    write nothing; exit 1 when either file differs from what it would write
//                                                     (a stale copy after a route, a wait or a map changed, or a hand edit)
//   node tools/design/gen-fx-turn-back.mjs --fuzz     rewrite the fuzz set alone, from the committed fx-turn-back.json
//   node tools/design/gen-fx-turn-back.mjs <run>      print that one run as JSON to stdout, write nothing
//
// Every run is a run already committed — fx-elevator's `leave-stale`, `two-walkers`, `mass-departure` or
// `return-from-stale`, fx-snack's `leave-mid-trip`, fx-restroom's `leave-inside` — with the deltas its note names added.
// Every instant a note places INSIDE a walk is read off the legs the shipped floor screen draws for that walk: the run so
// far is replayed through the harness's own probe, and the delta lands a stated fraction of the way through the named
// leg. So a re-routed desk, a moved plate or a re-seeded wait moves the instants with it, and `--check` says so.
//
// The FUZZ SET (card#12466 design pass, after review round 2 found with a seeded fuzz what nine hand-placed runs missed):
// `fx-turn-back-fuzz.json`, every run a committed base run with one to four flips of one walker at seeded instants — the
// committed `FUZZ` seeds below, an LCG over each — and the dense closing-cab runs (`DENSE`), a flip at an offset into
// turn-in-closing-cab's turn-round at which the holder's end instant used to spin. No probe is run for it: its instants
// are the seed's, not read off any leg, so a seed reaches whatever leg its instant lands in.
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const FX = join(ROOT, 'server', 'tests', 'Feature', 'Floor', 'fixtures');
const OUT = join(FX, 'fx-turn-back.json');
const OUT_FUZZ = join(FX, 'fx-turn-back-fuzz.json');
const arg = process.argv[2] ?? null;
const CHECK = arg === '--check';
const FUZZ_ONLY = arg === '--fuzz';
const ONLY = arg !== null && !CHECK && !FUZZ_ONLY ? arg : null;
const { LOOP_FPS } = await import(pathToFileURL(join(ROOT, 'server', 'public', 'js', 'wire', 'animation-set.js')).href);
const { ELEVATOR_DOOR: D } = await import(pathToFileURL(join(ROOT, 'server', 'public', 'js', 'floor', 'scene.js')).href);
const I = 1000 / LOOP_FPS;

const load = (name) => JSON.parse(readFileSync(join(FX, `${name}.json`), 'utf8'));
const elevator = load('fx-elevator');
const snack = load('fx-snack');
const restroom = load('fx-restroom');
const clone = (v) => JSON.parse(JSON.stringify(v));

const IMPL_1 = 'aimla/aimla-impl-1';
const IMPL_2 = 'aimla/aimla-impl-2';
/** `aimla-impl-1`'s state version in fx-elevator's leave-stale and return-from-stale, before any delta added here. */
const V1 = 31108;

/** One replay through the harness's probe — the shipped floor screen over the run. */
function probe(scenario) {
    const out = spawnSync('node', [join(ROOT, 'server/tests/Feature/Floor/fleet-client-probe.mjs'), join(ROOT, 'server/public/js/wire')],
        { input: JSON.stringify(scenario), maxBuffer: 1 << 30, cwd: join(ROOT, 'server') });

    if (out.status !== 0) {
        throw new Error(`the probe failed: ${out.stderr}`);
    }

    return JSON.parse(out.stdout).runs[0];
}

/** The walk of `seat` a run's render at `at` ms draws, of `id` — `{ effect, at }`, the walk's own start on the run's clock. */
function walkAt(result, seat, at, id) {
    // Several renders can land at one instant (one per delta of a tick); the walk is the last of them that draws it.
    const fx = result.floor_renders.filter((x) => x.at === at && x.trigger === 'render' && x.frame.scene !== null).reverse()
        .map((x) => x.frame.scene.effects.find((e) => e.animation_id === id && `${e.install_id}/${e.seat_id}` === seat && e.frames > 0))
        .find((e) => e !== undefined);

    if (fx === undefined) {
        throw new Error(`no ${id} walk of ${seat} on the render at ${at} ms`);
    }

    return { effect: fx, at: at - fx.elapsed_ms };
}

/** The instant (whole ms on the run's clock) `frac` of the way through the first leg of `walk` that `pick` selects. */
function inLeg(walk, pick, frac) {
    const leg = walk.effect.legs.find(pick);

    if (leg === undefined) {
        throw new Error(`the walk has no such leg: ${walk.effect.legs.map((l) => l.kind + (l.why ? `:${l.why}` : '')).join(' ')}`);
    }

    return Math.round(walk.at + (leg.start + frac * leg.frames) * I);
}

const isLeg = (kind, why) => (l) => l.kind === kind && (why === undefined || l.why === why);

/** A walk's last `walk` leg — a departure's glide from its spot onto the threshold as the leaves open on it (card#12793). */
const lastWalk = (l, i, legs) => l.kind === 'walk' && !legs.slice(i + 1).some((m) => m.kind === 'walk');

/** The instant of a committed run's one seat delta — read off the run, which its own generator may re-time. */
const leaveAt = (run) => run.messages.find((m) => m.envelope.t === 'seat.delta').at_ms;

/** A seat delta at `at_ms`, its envelope's clock fields read off the template's first delta (`shift` ms per run ms, or 0). */
function delta(at, seat, v, patch, clockOf) {
    const changed = Object.keys(patch);
    const when = clockOf(at);

    return { at_ms: at, envelope: { feed_version: 2, t: 'seat.delta', server_time: when, install_id: 'aimla', seat_id: seat, state_version: v, at: when, changed, patch } };
}

/** A run: the template's bytes, its messages with `added` merged in time order, its note and its horizon. */
function make(template, added, note, until, extra = {}) {
    const r = clone(template);

    r.messages = [...r.messages, ...added].sort((a, b) => a.at_ms - b.at_ms);
    r.until_ms = until;
    r.note = note;

    return { ...r, ...extra };
}

// The fx-elevator runs carry one server instant on every message; the snack and restroom runs carry the run's clock 0 plus
// the message's own instant.
const constClock = (template) => {
    const first = template.messages.find((m) => m.envelope.t === 'seat.delta');

    return () => first.envelope.server_time;
};
const runClock = (template) => {
    const c0 = Date.parse(template.http['/api/fleet/snapshot'][0].body.server_time);

    return (at) => new Date(c0 + at).toISOString();
};

const LIVE = (state) => ({ render_state: state, link_state: 'live', activity_state: state });
const STALE = { render_state: 'stale', link_state: 'stale' };
const runs = {};
const notes = {};
const order = [];
const add = (name, run) => {
    runs[name] = run;
    order.push(name);
};

// ── the fuzz set (fx-turn-back-fuzz.json) ────────────────────────────────────────────────────────────────────────────────
//
// SEVEN BASES, each a committed run and the one walker its flips turn: fx-elevator's leave-stale (`aimla-impl-1` out at
// 1000 ms), two-walkers (`aimla-impl-1` the second departure), return-then-leave's arrival alone, fx-restroom's
// leave-inside, fx-snack's leave-mid-trip, mass-departure (its last departure) and return-from-stale with its interns (the
// seat out again with both calls closed, then back without them). Each scenario flips the walker one to four times,
// alternating the base's two patches from a seeded instant `t0 + 60 ms` plus a draw over `span`, the flips 5–125 ms apart
// one time in seven and 80 ms–2.7 s apart otherwise; it runs on 40 s past its last flip.
const FUZZ_BASES = (() => {
    const OFF = { render_state: 'offline', link_state: 'offline' };
    const arrive = clone(elevator.runs['return-then-leave']);
    const a = arrive.messages.find((m) => m.envelope.t === 'seat.delta' && m.at_ms === 1000);

    arrive.messages = arrive.messages.filter((m) => m.envelope.t !== 'seat.delta' || m.at_ms <= 1000);

    const visit = restroom.runs['leave-inside'];
    const trip = snack.runs['leave-mid-trip'];
    const mass = elevator.runs['mass-departure'];
    const massDeltas = mass.messages.filter((m) => m.envelope.t === 'seat.delta');
    const massLast = massDeltas.at(-1);
    const back = elevator.runs['return-from-stale'];

    return [
        { name: 'leave-stale', t: elevator.runs['leave-stale'], clock: constClock, seat: 'aimla-impl-1', v: V1, seq: [LIVE('working'), STALE], t0: 1000, span: 9000 },
        { name: 'two-walkers', t: elevator.runs['two-walkers'], clock: constClock, seat: 'aimla-impl-1', v: 31152, seq: [LIVE('working'), OFF], t0: 1750, span: 9000 },
        { name: 'arrive', t: arrive, clock: constClock, seat: 'aimla-impl-1', v: a.envelope.state_version, seq: [OFF, LIVE('working')], t0: 1000, span: 9000 },
        { name: 'restroom', t: visit, clock: runClock, seat: 'aimla-impl-2', v: 20545, seq: [LIVE('idle'), STALE], t0: leaveAt(visit), span: 9000 },
        { name: 'snack', t: trip, clock: runClock, seat: 'aimla-impl-2', v: 20545, seq: [LIVE('idle'), STALE], t0: leaveAt(trip), span: 9000 },
        {
            name: 'mass', t: mass, clock: constClock, seat: massLast.envelope.seat_id, seq: [LIVE('working'), STALE], t0: massLast.at_ms, span: 14000,
            v: Math.max(...massDeltas.filter((m) => m.envelope.seat_id === massLast.envelope.seat_id).map((m) => m.envelope.state_version)),
        },
        {
            name: 'interns', t: back, clock: constClock, seat: 'aimla-impl-1', v: V1, t0: 1000, span: 9000,
            // Back with the run's own patch less its interns: a closed `call_id` never reopens (D1 § 8.6), so the interns
            // that turned round with the seat are gone for good, and every later flip moves the seat alone.
            seq: [{ ...STALE, subagents: [], subagents_open: 0 }, { ...back.messages.find((m) => m.envelope.t === 'seat.delta').envelope.patch, subagents: [], subagents_open: 0 }],
        },
    ];
})();

/**
 * THE COMMITTED SEEDS — review round 2's own two sweeps, which drew `per_base` scenarios over every base in the order above,
 * an LCG from each seed — and the scenarios of each base kept (`take`, by index; every base is named). Kept where the sweep
 * found the turn-round reading a label on the tree it reviewed (`61957df5`, merged with `dev`): seed 7's mass 4, 5, 21, 23,
 * 27 and 37 and seed 99's mass 20 and 35 (a walker seated from a mid-floor turn-round's `line` wait, ~560 px from its desk),
 * seed 7's interns 3, 12 and 24 and seed 99's interns 22 (the same, beside interns), seed 7's arrive 11 and interns 39 and
 * seed 99's interns 15 (the end timer spinning); and a spread of the rest. The set is sized for the suite's wall time: every
 * run is a probe of its own, run one per core (AT-D3-36).
 */
const FUZZ = [
    { seed: 7, per_base: 40, take: { 'leave-stale': [0, 13, 26], 'two-walkers': [0, 13, 26], arrive: [0, 11, 26], restroom: [0, 13, 26], snack: [0, 13, 26], mass: [4, 5, 21, 23, 27, 37], interns: [0, 3, 12, 24, 39] } },
    { seed: 99, per_base: 40, take: { 'leave-stale': [5], 'two-walkers': [5], arrive: [5], restroom: [5], snack: [5], mass: [20, 35], interns: [15, 22] } },
];

/** One seed's scenarios, `[name, run]` in base order, each run's note naming its flips. */
function fuzzRuns({ seed, per_base: perBase, take }) {
    let s = seed;
    const rnd = () => {
        s = (s * 1664525 + 1013904223) >>> 0;

        return s / 2 ** 32;
    };
    const out = [];

    for (const b of FUZZ_BASES) {
        const clockOf = b.clock(b.t);

        for (let i = 0; i < perBase; i++) {
            const flips = 1 + Math.floor(rnd() * 4);
            let at = b.t0 + 60 + Math.floor(rnd() * b.span);
            const added = [];
            let v = b.v;

            for (let k = 0; k < flips; k++) {
                added.push(delta(at, b.seat, ++v, b.seq[k % 2], clockOf));
                at += rnd() < 0.15 ? 5 + Math.floor(rnd() * 120) : 80 + Math.floor(rnd() * 2600);
            }

            if (!take[b.name].includes(i)) {
                continue;
            }

            const states = added.map((d) => `\`${d.envelope.patch.render_state}\` at ${d.at_ms} ms`).join(', ');

            out.push([`fuzz-${b.name}-s${seed}-${i}`,
                make(b.t, added, `fuzz seed ${seed}, scenario ${i} of ${b.name}: \`${b.seat}\` flips to ${states}; every walker stays where it is drawn and the leaves keep their motion.`, at + 40000)]);
        }
    }

    return out;
}

/**
 * THE DENSE RUNS: turn-in-closing-cab's and turn-in-open-cab's turn-round, flipped back out `d` ms after the return —
 * offsets at which the turn-round's frames end part-way through a frame, so an end instant read with float noise
 * re-armed the holder's end timer at 0 ms for ever (review round 2: 17 of 336 dense sweeps hung; these are three of them).
 */
const DENSE = [['turn-in-closing-cab', 300], ['turn-in-closing-cab', 840], ['turn-in-open-cab', 240]];

function denseRuns(turnBack) {
    return DENSE.map(([base, d]) => {
        const r0 = turnBack[base];
        const last = r0.messages.filter((m) => m.envelope.t === 'seat.delta').at(-1);
        const flip = { at_ms: last.at_ms + d, envelope: { ...last.envelope, state_version: last.envelope.state_version + 1, changed: Object.keys(STALE), patch: STALE } };

        return [`dense-${base}-${d}`, make(r0, [flip], `${base}, then \`aimla-impl-1\` \`working → stale\` again ${d} ms after its return at ${last.at_ms} ms, inside the turn-round that return drew: it turns from where it is and the run ends — the holder's end instants are whole milliseconds.`, last.at_ms + d + 30000)];
    });
}

/** The fuzz file over a set of turn-back runs — the generator's own, or the committed file's. */
function fuzzFile(turnBack) {
    return {
        note: "docs/design/FLOOR.md § 11's `fx-turn-back-fuzz` (card#12466; AT-D3-36): one walker flipped one to four times at seeded instants over seven committed runs, and turn-in-closing-cab's and turn-in-open-cab's turn-rounds flipped again at offsets whose end instants fall part-way through a frame. Generated by `tools/design/gen-fx-turn-back.mjs` from its committed `FUZZ` seeds and `DENSE` offsets; regenerate rather than edit.",
        runs: Object.fromEntries([...FUZZ.flatMap(fuzzRuns), ...denseRuns(turnBack)]),
    };
}

if (FUZZ_ONLY) {
    writeFileSync(OUT_FUZZ, `${JSON.stringify(fuzzFile(load('fx-turn-back').runs))}\n`);
    console.log(OUT_FUZZ.slice(ROOT.length + 1));
    process.exit(0);
}

// ── aimla-impl-1 leaving alone: fx-elevator's leave-stale, its A2 at 1000 ms. ────────────────────────────────────────────
const leave = elevator.runs['leave-stale'];
const lc = constClock(leave);
const alone = walkAt(probe(make(leave, [], '', 60000)), IMPL_1, 1000, 'A2');
const back = (at) => delta(at, 'aimla-impl-1', V1 + 1, LIVE('working'), lc);
const lone = [
    ['turn-mid-floor', inLeg(alone, isLeg('walk'), 0.5), 'half-way along its walk to the call plate', 'it turns where it is and walks its route home'],
    ['turn-pressing', inLeg(alone, isLeg('stand', 'press'), 0.5), 'half-way through its press at the main call plate', 'it turns from the plate and walks home'],
    ['turn-at-threshold', inLeg(alone, isLeg('stand', 'wait'), 0.5), 'half-way through its wait at its spot beside the lane in front of the doors (card#12793), the leaves still shut', 'it walks home from its spot and the leaves never move'],
    ['turn-doors-opening', inLeg(alone, lastWalk, 0.5), 'half-way along its glide from its spot onto the threshold as the leaves open on it', 'it walks home from where it is, the leaves finishing their opening and closing behind it'],
    ['turn-in-open-cab', inLeg(alone, isLeg('cab'), 0.5 * D.step / alone.effect.legs.find(isLeg('cab')).frames), 'stepped into the cab, the leaves still open', 'it steps back out and walks home, the leaves closing behind it'],
    ['turn-in-closing-cab', inLeg(alone, isLeg('cab'), (D.step + D.close / 2) / alone.effect.legs.find(isLeg('cab')).frames), 'in the cab half-way through the leaves closing over it', 'the ride completes — the leaves close as they began to — they open again on it the next frame, and it walks out and home'],
];

for (const [name, at, where, what] of lone) {
    add(name, make(leave, [back(at)], '', at + 20000));
    notes[name] = `\`aimla-impl-1\` \`working → stale\` at 1000 ms, as fx-elevator's leave-stale, then \`stale → working\` at ${at} ms, ${where} (read off the shipped floor's own legs for its walk): ${what}, and sits on that walk's last frame; the desk reads \`working\` from the return's render.`;
}

{
    const ends = Math.ceil(alone.at + alone.effect.frames * I);

    add('turn-after-ride', make(leave, [back(ends + 1000)], '', ends + 21000));
    notes['turn-after-ride'] = `the control: leave-stale, then \`stale → working\` at ${ends + 1000} ms, a second after its walk out has ended (at ${ends} ms): an ordinary A1 — the leaves open on it in the cab and it walks out to its desk, with no turn-round.`;
}

// ── repeated flips: back, out again, back again — each landing half-way along the walk the one before it drew. ─────────────
{
    const t1 = inLeg(alone, isLeg('walk'), 0.5);
    const once = make(leave, [back(t1)], '', 60000);
    const t2 = inLeg(walkAt(probe(once), IMPL_1, t1, 'A1'), isLeg('walk'), 0.5);
    const twice = make(leave, [back(t1), delta(t2, 'aimla-impl-1', V1 + 2, STALE, lc)], '', 60000);
    const t3 = inLeg(walkAt(probe(twice), IMPL_1, t2, 'A2'), isLeg('walk'), 0.5);

    add('turn-twice', make(leave, [back(t1), delta(t2, 'aimla-impl-1', V1 + 2, STALE, lc), delta(t3, 'aimla-impl-1', V1 + 3, LIVE('working'), lc)], '', t3 + 20000));
    notes['turn-twice'] = `repeated flips: leave-stale, then \`stale → working\` at ${t1} ms half-way along the walk out, \`working → stale\` at ${t2} ms half-way along the walk home, and \`stale → working\` at ${t3} ms half-way along the walk out again to the call plate — each turns the walker round from where it is; the person sits on the last walk's last frame.`;
}

// ── the queue: two-walkers, aimla-impl-1 the second departure, waiting at the spot beside the threshold. ───────────────────
{
    const two = elevator.runs['two-walkers'];
    const tc = constClock(two);
    const second = walkAt(probe(make(two, [], '', 60000)), IMPL_1, 1750, 'A2');
    const V = 31152;
    const ret = (at) => delta(at, 'aimla-impl-1', V + 1, LIVE('working'), tc);
    const queued = inLeg(second, isLeg('stand', 'wait'), 0.5);
    const cab = second.effect.legs.find(isLeg('cab'));
    // The last to step in after `aimla-review`, half-way through the leaves closing over the two of them.
    const riding = inLeg(second, isLeg('cab'), (D.step + D.close / 2) / cab.frames);

    add('turn-queued', make(two, [ret(queued)], '', queued + 20000));
    notes['turn-queued'] = `two-walkers (\`aimla-review\` on the threshold, \`aimla-impl-1\` at the spot beside it), then \`aimla-impl-1\` \`offline → working\` at ${queued} ms, half-way through its wait at its spot: it walks home from the spot, and \`aimla-review\` boards on its own wait — a cancel ahead of nobody, which re-times nobody's past.`;
    add('turn-riding-together', make(two, [ret(riding)], '', riding + 20000));
    notes['turn-riding-together'] = `two-walkers, then \`aimla-impl-1\` \`offline → working\` at ${riding} ms, when it has stepped into the cab after \`aimla-review\` and the leaves are half-way through closing over the two of them: the ride completes with both inside, and on the next frame the leaves open again on \`aimla-impl-1\` alone, which walks out and home.`;
}

// ── stepped in at a rider spot beside the threshold, the leaves still open for the boarders behind it: mass-departure. ─────
{
    const mass = elevator.runs['mass-departure'];
    const mc = constClock(mass);
    const result = probe(make(mass, [], '', 60000));
    const r = result.floor_renders.filter((x) => x.trigger === 'render' && x.frame.scene !== null).at(-1);
    // A boarder that steps to a spot of its own (its cab leg's `enter`) with frames in the cab before the leaves close.
    const open = (e) => {
        const cab = e.legs.find(isLeg('cab'));

        return cab !== undefined && cab.enter !== undefined && cab.frames - D.step - D.close > 0;
    };
    const fx = r.frame.scene.effects.filter((e) => e.animation_id === 'A2' && e.frames > 0 && open(e)).sort((a, b) => (a.seat_id < b.seat_id ? -1 : 1))[0];
    const walk = walkAt(result, `${fx.install_id}/${fx.seat_id}`, r.at, 'A2');
    const cab = walk.effect.legs.find(isLeg('cab'));
    const at = inLeg(walk, isLeg('cab'), (D.step / 2) / cab.frames);
    const v = mass.messages.find((m) => m.envelope.seat_id === fx.seat_id).envelope.state_version;

    add('turn-in-cab-at-a-spot', make(mass, [delta(at, fx.seat_id, v + 1, LIVE('working'), mc)], '', at + 20000));
    notes['turn-in-cab-at-a-spot'] = `mass-departure, then \`${fx.install_id}/${fx.seat_id}\` \`stale → working\` at ${at} ms, half-way through its step from the threshold to a rider spot of its own, the leaves still open for the boarders behind it: it steps back out from where it is and walks home, and the others ride on.`;
}

// ── leaving again on its way in: fx-elevator's return-then-leave, its A1 at 1000 ms, out again in each leg of the arrival. ──
{
    const arrive = elevator.runs['return-then-leave'];
    const ac = constClock(arrive);
    const first = clone(arrive);

    first.messages = first.messages.filter((m) => m.at_ms <= 1000);

    const arrival = walkAt(probe(make(first, [], '', 60000)), IMPL_1, 1000, 'A1');
    const v = first.messages.find((m) => m.envelope.seat_id === 'aimla-impl-1').envelope.state_version;
    const cases = [
        ['turn-round-in-cab', inLeg(arrival, isLeg('cab'), 0.5), 'in the cab, half-way through the leaves opening on it', 'it never steps out: the leaves finish opening and close over it'],
        ['turn-round-at-door', inLeg(arrival, isLeg('stand', 'step'), 0.5), 'stepping out onto the threshold, the leaves open', 'it steps back in and the leaves close over it'],
        ['turn-round-mid-floor', inLeg(arrival, isLeg('walk'), 0.5), 'half-way along its walk to the desk', 'it turns where it is and walks out by the main call plate, waits its own draw and boards'],
    ];

    for (const [name, at, where, what] of cases) {
        const r = make(first, [delta(at, 'aimla-impl-1', v + 1, { render_state: 'offline', link_state: 'offline' }, ac)], '', at + 60000);

        add(name, r);
        notes[name] = `\`aimla-impl-1\`, held \`offline\`, \`offline → working\` at 1000 ms, as fx-elevator's return-then-leave, then \`working → offline\` at ${at} ms, ${where}: ${what}; the desk reads \`offline\` from that render.`;
    }
}

// ── waiting its turn at the plates beside its desk: mass-departure's walker that waits in line. ────────────────────────────
{
    const mass = elevator.runs['mass-departure'];
    const mc = constClock(mass);
    const result = probe(make(mass, [], '', 60000));
    const r = result.floor_renders.filter((x) => x.trigger === 'render' && x.frame.scene !== null).at(-1);
    const fx = r.frame.scene.effects.filter((e) => e.animation_id === 'A2' && e.frames > 0 && e.legs.some(isLeg('stand', 'line')))
        .sort((a, b) => (a.seat_id < b.seat_id ? -1 : 1))[0];
    const seat = `${fx.install_id}/${fx.seat_id}`;
    const walk = walkAt(result, seat, r.at, 'A2');
    const at = inLeg(walk, isLeg('stand', 'line'), 0.5);
    const v = mass.messages.find((m) => m.envelope.seat_id === fx.seat_id).envelope.state_version;

    add('turn-at-desk', make(mass, [delta(at, fx.seat_id, v + 1, LIVE('working'), mc)], '', at + 20000));
    notes['turn-at-desk'] = `mass-departure, then \`${seat}\` — a walker waiting its turn at the call plates beside its desk — \`stale → working\` at ${at} ms, half-way through that wait: it is home, and is drawn seated at once, with no walk.`;
}

// ── a seat and its interns turning together: return-from-stale, then out again with both interns stopped. ────────────────
{
    const ret = elevator.runs['return-from-stale'];
    const rc = constClock(ret);
    const arrival = walkAt(probe(make(ret, [], '', 60000)), IMPL_1, 1000, 'A1');
    const at = inLeg(arrival, isLeg('walk'), 0.5);

    add('turn-with-interns', make(ret, [delta(at, 'aimla-impl-1', V1 + 1, { ...STALE, subagents: [], subagents_open: 0 }, rc)], '', at + 60000));
    notes['turn-with-interns'] = `return-from-stale (\`aimla-impl-1\` back with two interns walking in beside it), then at ${at} ms, half-way along its walk to the desk, \`working → stale\` with both interns' calls closed (\`subagents\` emptied, \`subagents_open\` 2 → 0): the seat turns round to the elevator from where it is, and in the same render each intern turns round from where it is (item 16) — three walkers turning together.`;
}

// ── off a snack trip, and out of the restroom door. ───────────────────────────────────────────────────────────────────────
{
    const trip = snack.runs['leave-mid-trip'];
    const sc = runClock(trip);
    const out = walkAt(probe(make(trip, [], '', trip.until_ms)), IMPL_2, leaveAt(trip), 'A2');
    const at = inLeg(out, isLeg('walk'), 0.5);

    add('turn-from-snack', make(trip, [delta(at, 'aimla-impl-2', 20546, LIVE('idle'), sc)], '', at + 30000));
    notes['turn-from-snack'] = `fx-snack's leave-mid-trip (\`aimla-impl-2\` at the tall table goes \`idle → stale\` at ${leaveAt(trip)} ms, its A2 walked from its spot), then \`stale → idle\` at ${at} ms, half-way along that walk to the call plate: it turns back to its desk from where it is, and its break is over — the trip the A2 took over is not drawn again.`;
}

{
    const visit = restroom.runs['leave-inside'];
    const rc = runClock(visit);
    const out = walkAt(probe(make(visit, [], '', visit.until_ms)), IMPL_2, leaveAt(visit), 'A2');
    const at = inLeg(out, isLeg('inside'), 0.5);

    add('turn-from-restroom', make(visit, [delta(at, 'aimla-impl-2', 20546, LIVE('idle'), rc)], '', at + 30000));
    notes['turn-from-restroom'] = `fx-restroom's leave-inside (\`aimla-impl-2\` inside the restroom goes \`idle → stale\` at ${leaveAt(visit)} ms: the leaf opens and its A2 comes out first), then \`stale → idle\` at ${at} ms, while it is still coming out: it finishes coming out — the leaf opening and closing behind it as the A2 began it — and walks home from the door.`;
}

// ── nested turns (card#12466 review round 1): flip, flip back, flip again — each flip landing in a named leg of the
// turn-round the flip before it drew, both directions, and out of a restroom door. Each instant is read off the walk the
// previous flip's render drew, replaying the run so far, so a re-timed turn moves the next flip with it.
{
    // A chain: the template's run with `first` added, then each step's delta at `pick(walk)` — `walk` the `drawn` walk the
    // render of the delta before it drew — its state version one past the last.
    const chain = (template, clockOf, key, first, steps, until) => {
        const seat = key.slice(key.indexOf('/') + 1);
        const added = [...first];
        let v = added.at(-1).envelope.state_version;

        for (const { drawn, patch, pick } of steps) {
            const last = added.at(-1).at_ms;
            const at = pick(walkAt(probe(make(template, added, '', last + 60000)), key, last, drawn));

            added.push(delta(at, seat, ++v, patch, clockOf));
        }

        return make(template, added, '', added.at(-1).at_ms + until);
    };
    const leg = (kind, why, frac) => (w) => inLeg(w, isLeg(kind, why), frac);
    // Inside the cab while the leaves open again on a ride that completed (`door`'s opening frames).
    const reopen = (w) => Math.round(w.at + (w.effect.door.start + D.open / 2) * I);
    // Inside the cab while the leaves of the ride still close (`door_rest`'s last frames).
    const ride = (w) => Math.round(w.at + ((w.effect.door_rest.start + w.effect.door_rest.frames) / 2) * I);
    const OUT_1 = { patch: STALE };
    const BACK_1 = { patch: LIVE('working') };
    const closingCab = lone.find(([n]) => n === 'turn-in-closing-cab')[1];
    const openCab = lone.find(([n]) => n === 'turn-in-open-cab')[1];
    const doorsOpening = lone.find(([n]) => n === 'turn-doors-opening')[1];
    const nested = (name, first, steps, note) => {
        add(name, chain(leave, lc, IMPL_1, first, steps, 20000));
        notes[name] = `leave-stale (\`aimla-impl-1\` \`working → stale\` at 1000 ms), then ${note} — every flip from where the figure is.`;
    };

    nested('turn-again-in-reopen', [back(closingCab)], [{ ...OUT_1, drawn: 'A1', pick: reopen }, { ...BACK_1, drawn: 'A2', pick: leg('cab', undefined, 0.5) }],
        `back to \`working\` at ${closingCab} ms in the closing cab (the ride completes and the leaves open again), \`stale\` again while they open on it, and \`working\` again half-way through the cab it stays in`);
    nested('turn-again-in-ride', [back(closingCab)], [{ ...OUT_1, drawn: 'A1', pick: ride }, { ...BACK_1, drawn: 'A2', pick: leg('cab', undefined, 0.5) }],
        `back to \`working\` at ${closingCab} ms in the closing cab, \`stale\` again while the leaves are still closing on that ride, and \`working\` again while it rides on behind them`);
    nested('turn-again-at-step', [back(closingCab)], [{ ...OUT_1, drawn: 'A1', pick: leg('stand', 'step', 0.5) }, { ...BACK_1, drawn: 'A2', pick: leg('stand', 'step', 0.5) }],
        `back to \`working\` at ${closingCab} ms in the closing cab, \`stale\` again as it steps out onto the threshold, and \`working\` again as it steps back in`);
    nested('turn-again-stepping-out', [back(openCab)], [{ ...OUT_1, drawn: 'A1', pick: leg('cab', undefined, 0.5) }, { ...BACK_1, drawn: 'A2', pick: leg('cab', undefined, 0.5) }],
        `back to \`working\` at ${openCab} ms in the open cab, \`stale\` again half-way through its step back out, and \`working\` again in the cab it stays in`);
    nested('turn-again-mid-floor', [back(doorsOpening)], [{ ...OUT_1, drawn: 'A1', pick: leg('walk', undefined, 0.1) }, { ...BACK_1, drawn: 'A2', pick: leg('walk', undefined, 0.5) }],
        `back to \`working\` at ${doorsOpening} ms on the threshold as the leaves open, \`stale\` again a tenth of the way home with the leaves still moving, and \`working\` again half-way along its walk to the call plate`);

    // Out again in the last quarter frame of a ride's closing: the turn-round rides on behind the leaves for part of a frame,
    // so it ends between two frames — the holder ends it at its instant (`#ended()`); then back once the cab has gone.
    nested('turn-out-at-ride-end', [back(closingCab)], [
        { ...OUT_1, drawn: 'A1', pick: (w) => Math.round(w.at + (w.effect.door_rest.start + w.effect.door_rest.frames - 0.25) * I) },
        { ...BACK_1, drawn: 'A2', pick: (w) => Math.round(w.at + 2000) },
    ], `back to \`working\` at ${closingCab} ms in the closing cab, \`stale\` again a quarter frame before those leaves shut — it rides on behind them, its walk ending part-way through a frame — and \`working\` two seconds later, an ordinary arrival`);

    // Out of the restroom door: leave-inside's A2 comes out first; back, out again and back again while it is still coming out.
    // The chain carries each run's own first delta, so its template is the run without it.
    const visit = clone(restroom.runs['leave-inside']);
    const rc = runClock(visit);
    const first = visit.messages.find((m) => m.envelope.t === 'seat.delta');

    visit.messages = visit.messages.filter((m) => m !== first);

    add('turn-again-from-restroom', chain(visit, rc, IMPL_2, [first], [
        { patch: LIVE('idle'), drawn: 'A2', pick: leg('inside', undefined, 0.5) },
        { patch: STALE, drawn: 'A1', pick: leg('inside', undefined, 0.5) },
        { patch: LIVE('idle'), drawn: 'A2', pick: leg('walk', undefined, 0.5) },
    ], 30000));
    notes['turn-again-from-restroom'] = `fx-restroom's leave-inside (\`aimla-impl-2\` inside the restroom goes \`idle → stale\` at ${first.at_ms} ms and its A2 comes out first), then \`idle\` while it is still coming out, \`stale\` again while it is still coming out, and \`idle\` again half-way along its walk to the call plate — it finishes coming out once, and every flip after it turns from where it is.`;

    // On its way in: fx-elevator's return-then-leave's arrival at 1000 ms, out in a leg of it, back, and out again.
    const arrive = clone(elevator.runs['return-then-leave']);

    const ac = constClock(arrive);
    const arrival = arrive.messages.find((m) => m.envelope.t === 'seat.delta' && m.at_ms === 1000);

    arrive.messages = arrive.messages.filter((m) => m.envelope.t !== 'seat.delta');

    const OFF = { patch: { render_state: 'offline', link_state: 'offline' } };
    const inbound = (name, steps, note) => {
        add(name, chain(arrive, ac, IMPL_1, [arrival], steps, 60000));
        notes[name] = `\`aimla-impl-1\`, held \`offline\`, \`offline → working\` at 1000 ms as fx-elevator's return-then-leave, then ${note} — every flip from where the figure is.`;
    };

    inbound('turn-round-again-in-cab', [{ ...OFF, drawn: 'A1', pick: leg('cab', undefined, 0.5) }, { ...BACK_1, drawn: 'A2', pick: leg('cab', undefined, 0.5) },
        { ...OFF, drawn: 'A1', pick: reopen }],
    '`offline` in the cab as the leaves open on it, `working` again while they close over it, and `offline` again as they open on it once more');
    inbound('turn-round-again-at-door', [{ ...OFF, drawn: 'A1', pick: leg('stand', 'step', 0.5) }, { ...BACK_1, drawn: 'A2', pick: leg('stand', 'step', 0.5) },
        { ...OFF, drawn: 'A1', pick: leg('walk', undefined, 0.5) }],
    '`offline` as it steps out onto the threshold, `working` again as it steps back in, and `offline` again half-way along its walk home');
    inbound('turn-round-again-queued', [{ ...OFF, drawn: 'A1', pick: leg('walk', undefined, 0.5) }, { ...BACK_1, drawn: 'A2', pick: leg('stand', 'wait', 0.5) },
        { ...OFF, drawn: 'A1', pick: leg('walk', undefined, 0.5) }],
    '`offline` half-way along its walk to the desk, `working` again while it waits at the elevator, and `offline` again half-way along its walk home');
}

// ── the late viewer: a page loaded during turn-mid-floor's walk home. ───────────────────────────────────────────────────────
{
    const t = inLeg(alone, isLeg('walk'), 0.5);
    const cont = make(leave, [back(t)], '', 60000);
    const home = walkAt(probe(cont), IMPL_1, t, 'A1');
    const mid = inLeg(home, isLeg('walk'), 0.5);
    const late = clone(leave);
    const snap = late.http['/api/fleet/snapshot'][0].body;
    const seat = snap.installs[0].seats.find((s) => s.seat_id === 'aimla-impl-1');

    Object.assign(seat, LIVE('working'), { state_version: V1 + 1 });
    add('turn-mid-floor-late', { ...late, messages: [], until_ms: 20000, note: '' });
    notes['turn-mid-floor-late'] = `turn-mid-floor's LATE VIEWER: a page whose snapshot is taken at ${mid} ms of turn-mid-floor, half-way along the walk home — \`aimla-impl-1\` \`working\` at the return's version, every other seat as there: a snapshot animates nothing (§ 6.5), so it draws the person seated, and every desk as turn-mid-floor draws it once that walk has ended.`;
}

const out = {
    note: "docs/design/FLOOR.md § 11's `fx-turn-back` (card#12466; AT-D3-36): a main agent whose seat starts working again on its way out turns back to its desk, and one that leaves again on its way in turns round to the elevator. Every run is a committed run — fx-elevator's `leave-stale`, `two-walkers`, `mass-departure` or `return-from-stale`, fx-snack's `leave-mid-trip`, fx-restroom's `leave-inside` — with the deltas its note names added, each at an instant read off the legs the shipped floor screen draws for the walk it lands in. Generated by `tools/design/gen-fx-turn-back.mjs`; regenerate rather than edit.",
    runs: Object.fromEntries(order.map((k) => {
        const { note, ...rest } = runs[k];

        return [k, { note: notes[k], ...rest }];
    })),
};

if (ONLY !== null) {
    if (out.runs[ONLY] === undefined) {
        console.error(`no run named ${ONLY}; the runs are: ${order.join(' ')}`);
        process.exit(2);
    }

    process.stdout.write(JSON.stringify(out.runs[ONLY]));
    process.exit(0);
}

const files = [[OUT, `${JSON.stringify(out)}\n`], [OUT_FUZZ, `${JSON.stringify(fuzzFile(out.runs))}\n`]];

if (CHECK) {
    let stale = 0;

    for (const [path, bytes] of files) {
        let held = null;

        try {
            held = readFileSync(path, 'utf8');
        } catch {
            // a missing file is stale like a differing one
        }

        const name = path.slice(FX.length + 1);

        if (held === bytes) {
            console.log(`fresh: ${name} matches the generator`);
        } else {
            stale++;
            console.log(`stale: server/tests/Feature/Floor/fixtures/${name} differs from what the generator writes — run node tools/design/gen-fx-turn-back.mjs`);
        }
    }

    process.exit(stale === 0 ? 0 : 1);
}

for (const [path, bytes] of files) {
    writeFileSync(path, bytes);
    console.log(path.slice(ROOT.length + 1));
}

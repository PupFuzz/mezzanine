/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsClockIsSetNotAccumulatedTest` reads the 3-D floor's clock through —
 * `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 2's clock legs (card#13033 stage 2 slice 5): `floor3d/clip-clock.js`
 * asked, over the shipped character tree and the animation set's own held renderings, which form each character shows at
 * an instant. argv[2] is the client's `wire/` directory (the shipped one or a MUTATED COPY of the tree); stdout is
 * `{ "legs": { "<leg>": [defect, …] }, "printed": { … } }`.
 *
 * ⛔ THE EXPECTED FRAME IS DERIVED HERE, NOT READ BACK: `⌊T × fps⌋ + loopOffset(key, length)` mod `length`, from the
 * tree's own `loopOffset()` and the rendering's own `frame_interval_ms`, so a clock that drops the offset, keys on the
 * row's class, or keeps its own time reds against arithmetic it does not share.
 */

import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
const HERE = fileURLToPath(new URL('.', import.meta.url));
const C = await import(pathToFileURL(join(HERE, '..', '..', '..', '..', 'resources', 'characters', 'index.js')).href);
const { heldRendering } = await import(pathToFileURL(join(wire, 'animation-set.js')).href);
const K = await import(pathToFileURL(join(wire, '..', 'floor3d', 'clip-clock.js')).href);

const legs = { pose: [], offset: [], phase: [], still: [], repeat: [], walk: [], forms: [] };
const printed = { reads: 0, pairs: {} };

/** The seat keys the probe reads: a corpus wide enough that a pair of them differs in phase for every loop length. */
const KEYS = Array.from({ length: 24 }, (_, i) => `aimla:aimla-seat-${i}`);
/** The instants read, ms after the paint: across several loops, on and between frame boundaries. */
const INSTANTS = [0, 1, 249, 250, 251, 999, 1000, 1749, 2250, 4003, 7777];

const expectedFrame = (key, held, since, length) => (Math.floor(since / held.frame_interval_ms) + C.loopOffset(key, length)) % length;

// ── every held row's form, moving: the pose, the blink, the marks at the frame the arithmetic names ──
for (const [id, form] of Object.entries(K.HELD_FORMS)) {
    const held = heldRendering(id, true);

    if (held.motion !== true || typeof held.frame_interval_ms !== 'number') {
        legs.forms.push(`CONTROL: ${id}'s held rendering does not move (${JSON.stringify(held)}) — its loop was not read`);
        continue;
    }

    const length = K.loopLength(C, form);
    const clip = form.clip === undefined ? null : C.CLIPS[form.clip];

    for (const key of KEYS) {
        for (const since of INSTANTS) {
            const got = K.heldAt(C, { key, pose: 'present', held }, since);
            const f = expectedFrame(key, held, since, length);

            printed.reads++;

            if (got.frame !== f) {
                legs.pose.push(`${id} ${key} at ${since} ms is on loop frame ${got.frame}, not ${f}`);
            }

            if (clip !== null && got.pose !== clip[f % clip.length]) {
                legs.pose.push(`${id} ${key} at ${since} ms shows ${got.pose}, not its clip's ${clip[f % clip.length]}`);
            }

            if (form.blink && got.blink !== (f % K.BLINK_LOOP === K.BLINK_LOOP - 1)) {
                legs.pose.push(`${id} ${key} at ${since} ms has its eyes ${got.blink ? 'shut' : 'open'} on loop frame ${f}`);
            }

            if (form.mark === 'zs' && JSON.stringify(got.marks.levels) !== JSON.stringify(C.zLevels(f % C.SLEEP_LOOP))) {
                legs.pose.push(`${id} ${key} at ${since} ms lights its z's ${got.marks.levels}, not the pulse's ${C.zLevels(f % C.SLEEP_LOOP)}`);
            }

            if (form.mark !== undefined && form.mark !== 'zs' && got.marks.levels[0] !== C.markerLevel(f % C.SLEEP_LOOP)) {
                legs.pose.push(`${id} ${key} at ${since} ms lights its marker ${got.marks.levels[0]}, not the pulse's ${C.markerLevel(f % C.SLEEP_LOOP)}`);
            }

            // the next step is the next frame boundary: no step drawn twice, none skipped
            const boundary = held.frame_interval_ms - (since % held.frame_interval_ms);

            if (length > 1 && got.next !== boundary) {
                legs.pose.push(`${id} ${key} at ${since} ms asks for its next frame in ${got.next} ms, not at the step ${boundary} ms on`);
            }
        }
    }

    // ── the offset: at the paint, a key whose offset is not 0 is not on frame 0 ──
    const offsetKey = KEYS.find((k) => C.loopOffset(k, length) !== 0);

    if (offsetKey === undefined) {
        legs.offset.push(`CONTROL: no key of the corpus has a loop offset other than 0 over ${id}'s ${length} frames`);
    } else if (K.heldAt(C, { key: offsetKey, pose: 'present', held }, 0).frame === 0) {
        legs.offset.push(`${id} ${offsetKey} is on loop frame 0 at the paint, its offset ${C.loopOffset(offsetKey, length)} not applied`);
    }

    // ── two characters in one state at one instant: some pair shows different forms ──
    const pair = KEYS.flatMap((a) => KEYS.map((b) => [a, b])).find(([a, b]) => C.loopOffset(a, length) !== C.loopOffset(b, length));

    if (pair === undefined) {
        legs.phase.push(`CONTROL: no two keys of the corpus differ in offset over ${id}'s ${length} frames — the phase was not measured`);
    } else {
        const [a, b] = pair.map((key) => K.heldAt(C, { key, pose: 'present', held }, 1000));

        printed.pairs[id] = [...pair, a.frame, b.frame];

        if (a.frame === b.frame) {
            legs.phase.push(`${id}: ${pair[0]} and ${pair[1]} differ in offset and are on one loop frame (${a.frame}) at one instant`);
        }
    }

    // ── a held loop repeats: a whole loop later it is on the same frame ──
    for (const key of KEYS.slice(0, 4)) {
        const a = K.heldAt(C, { key, pose: 'present', held }, 500);
        const b = K.heldAt(C, { key, pose: 'present', held }, 500 + length * held.frame_interval_ms);

        if (a.frame !== b.frame || a.pose !== b.pose) {
            legs.repeat.push(`${id} ${key} is on frame ${a.frame} and a loop later on ${b.frame}`);
        }
    }

    // ── a held rendering whose `motion` is false: its still at every instant, and no step asked for ──
    for (const reduced of [heldRendering(id, true, true), heldRendering(id, false)]) {
        for (const since of INSTANTS) {
            const got = K.heldAt(C, { key: KEYS[1], pose: 'present', held: reduced }, since);
            const still = K.heldAt(C, { key: KEYS[1], pose: 'present', held: reduced }, 0);

            if (got.frame !== null || got.next !== null || JSON.stringify(got) !== JSON.stringify(still)) {
                legs.still.push(`${id} held without motion (${JSON.stringify(reduced)}) at ${since} ms is ${JSON.stringify(got)} — not its still, unstepped`);
            }
        }
    }
}

// ── the still forms are the reduced cells: every z and the marker at full brightness, the clip's first pose ──
const stillZs = K.heldAt(C, { key: KEYS[0], pose: 'asleep', held: heldRendering('A6', true, true) }, 0);

if (JSON.stringify(stillZs.marks?.levels) !== JSON.stringify(C.zLevels(0).map(() => 3)) || stillZs.pose !== 'asleep') {
    legs.still.push(`A6's still is ${JSON.stringify(stillZs)} — not the asleep pose with every z at full brightness`);
}

// ── an edge row: the walk steps while it runs and stands once it has ended ──
const fx = { frames: 12, frame_interval_ms: 250, elapsed_ms: 0 };
const span = fx.frames * fx.frame_interval_ms;

for (const since of [0, 260, 1010, span - 1, span, span + 5000]) {
    const { t, running } = K.inFlightAt(fx, span, since);
    const pose = K.walkAt(C, fx, t, running);
    const want = since < span ? C.CLIPS.walk[Math.floor(since / 250) % C.CLIPS.walk.length] : C.CLIPS.walk[0];

    if (t !== since || running !== since < span || pose !== want) {
        legs.walk.push(`the walk at ${since} ms reads t ${t}, running ${running}, pose ${pose} — not ${since} ms, ${since < span}, ${want}`);
    }
}

if (K.inFlightAt({ ...fx, frames: 0 }, span, 10).running) {
    legs.walk.push('a walk drawn without its motion (frames 0) reads as running');
}

if (printed.reads === 0) {
    legs.forms.push('CONTROL: no held form was read');
}

process.stdout.write(JSON.stringify({ legs, printed }));

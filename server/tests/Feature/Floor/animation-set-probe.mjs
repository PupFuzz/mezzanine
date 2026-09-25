/**
 * The probe the PHP suite reads and drives the SHIPPED ANIMATION SET through — `node`, no
 * dependencies, no network, no DOM. `docs/design/FLOOR.md` Appendix B row 6's **animation set**.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2], so
 * the same probe runs against `public/js/wire` and against a MUTATED COPY of it — which is how the
 * closed-set guard's controls re-mint their defect against the real code rather than against a
 * second copy of the table.
 *
 * ⛔ IT READS THE FUNCTIONS, NOT ONLY THE TABLE THEY READ. `forms`, `loops` and `frame_interval_ms`
 * are `animationForm()`, `loops()` and `frameIntervalMs()` sampled over EVERY id the set carries in
 * both reduced states, and `held_renderings` is `heldRendering()` over every id in every
 * (`permitted`, `reduce`) combination — so a guard reads what a renderer would actually get.
 *
 * ⛔ AND IT DRIVES THE SET OVER THE REAL LOG. `ops` below runs `edges()`, `held()` and
 * `displaced()` against a real `createAnimationLog()`, which is the only way a row the set writes
 * for a trigger this probe cannot reach through a page — A16's, whose trigger is Appendix B row 7's
 * slot function — is seen at all rather than taken on the module's word.
 *
 * stdin  — JSON: `{ "reduce": bool, "ops": [ { "op": "edges", "journal": [ … ] }
 *                                          | { "op": "held", "desks": {…}, "seats": {…}, "at": N }
 *                                          | { "op": "displaced", "install_id", "seat_id",
 *                                              "arriving", "at" } ] }`
 *          `held`'s `seats` is an object keyed as the client keys its seats and is handed to the
 *          module as the `Map` it takes.
 * stdout — JSON: `{ "set", "fired_by", "heartbeat_rows", "heartbeat", "delta_row_drivers",
 *                   "loop_fps", "loops", "frame_interval_ms", "forms", "held_renderings",
 *                   "outside", "rows", "errors" }`
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node animation-set-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const set = await import(pathToFileURL(join(dir, 'animation-set.js')).href);
const { createAnimationLog } = await import(pathToFileURL(join(dir, 'animation-log.js')).href);

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
const ids = Object.keys(set.ANIMATION_SET);

const log = createAnimationLog();
const live = new set.AnimationSet(log, { reduce: payload.reduce === true });
const errors = [];

for (const step of payload.ops ?? []) {
    try {
        if (step.op === 'edges') {
            live.edges(step.journal ?? [], step.at);
        } else if (step.op === 'held') {
            live.held(step.desks ?? {}, new Map(Object.entries(step.seats ?? {})), step.at);
        } else if (step.op === 'displaced') {
            live.displaced(step.install_id, step.seat_id, step.arriving, step.at);
        } else {
            errors.push({ op: step.op, name: 'UnknownOp', message: `the probe has no op named ${step.op}` });
        }
    } catch (error) {
        errors.push({ op: step.op, name: error?.name ?? typeof error, message: String(error?.message ?? error) });
    }
}

console.log(JSON.stringify({
    set: set.ANIMATION_SET,
    fired_by: set.FIRED_BY,
    heartbeat_rows: [...set.HEARTBEAT_ROWS],
    heartbeat: set.HEARTBEAT,
    delta_row_drivers: set.DELTA_ROW_DRIVERS,
    loop_fps: set.LOOP_FPS,
    loops: Object.fromEntries(ids.map((id) => [id, set.loops(id)])),
    frame_interval_ms: Object.fromEntries(ids.map((id) => [id, set.frameIntervalMs(id)])),
    forms: Object.fromEntries(ids.map((id) => [id, {
        motion: set.animationForm(id, false),
        reduced: set.animationForm(id, true),
    }])),
    held_renderings: Object.fromEntries(ids.map((id) => [id, {
        permitted: set.heldRendering(id, true, false),
        permitted_reduced: set.heldRendering(id, true, true),
        treated: set.heldRendering(id, false, false),
    }])),
    // The out-of-table answers, so a guard can read that the functions refuse an id the set does
    // not carry rather than inventing a form or a rate for it.
    outside: {
        form: set.animationForm('A999', false),
        loops: set.loops('A999'),
        frame_interval_ms: set.frameIntervalMs('A999'),
        held_rendering: set.heldRendering('A999', true, false),
    },
    rows: log.rows,
    errors,
}, null, 2));

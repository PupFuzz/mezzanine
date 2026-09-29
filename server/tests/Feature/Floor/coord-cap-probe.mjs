/**
 * The probe for § 5.7's bound on the coordination envelopes the client protocol holds (§ 14 item 25)
 * — `node`, no dependencies, no network, no DOM, no real clock. card#7341 step 8.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2]
 * (`public/js/wire` or a mutated copy of it); the client protocol, the floor screen and the animation
 * log are the shipped ones.
 *
 * ⛔ WHY A PROBE OF ITS OWN, AND WHY IT GENERATES ENVELOPES. The bound is a thousand envelopes, and a
 * fixture spelling a thousand of them out byte by byte is a file no reviewer reads; the harness probe
 * renders after every message, and a floor render over a thousand held envelopes a thousand times is
 * a million coordination objects rendered to test a list's length. So the envelopes past the fixture's
 * own are GENERATED here by one stated rule — `generate` below — and delivered in bulk, with a render
 * only where a step asks for one. The rule is in this file, so every byte a test replays is still in
 * the diff.
 *
 * stdin — JSON:
 *   `{ "http":  { … }                       // the scripted responses a floor needs (a fixture's own)
 *      "floor": "<floor key>" | null        // run the floor screen over the client, or not
 *      "steps": [
 *        { "deliver": [ <envelope>, … ] },  // delivered in order on the one stream
 *        { "generate": { "t": "coord.round" | "coord.thread", "thread_ref", "install_id", "count",
 *                        "from", "to", "targets", "post_prefix" } },   // `count` envelopes, the n-th
 *                                           //  round's `post_ref` `<post_prefix><n>`
 *        { "render": true }                 // one floor render
 *      ] }`
 * stdout — JSON: `{ "steps": [ { held, threads: {ref: {threads, rounds, first_post}}, truncated,
 *   beads: {ref: label}, animated: {A19, A20} } ] }`, one per step.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { scriptedFetch } from '../Support/scripted-fetch.mjs';

const dir = process.argv[2];

const { FleetClient } = await import(pathToFileURL(join(dir, 'fleet-client.js')).href);
const { createAnimationLog } = await import(pathToFileURL(join(dir, 'animation-log.js')).href);
const { startFloorScreen, VIEWPORT_FLOOR } = await import(pathToFileURL(join(dir, '..', 'floor', 'floor-screen.js')).href);

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
const turn = () => new Promise((resolve) => setImmediate(resolve));

let listener = null;

class FakeEventSource {
    addEventListener(type, callback) {
        if (type === 'mezzanine') {
            listener = callback;
        }
    }

    close() {}
}

const clock = { now: () => 0 };
const http = scriptedFetch(payload.http ?? {});
const client = new FleetClient(http.fetch, FakeEventSource, clock);
const log = createAnimationLog();
const screen = payload.floor === null || payload.floor === undefined
    ? null
    : startFloorScreen(client, http.fetch, clock, log, () => {}, {
        floor: payload.floor,
        local_time: () => ({ hours: 9, minutes: 45 }),
        // Appendix B row 15: the viewer's viewport, required — § 12's viewport floor, the drawn floor.
        viewport: VIEWPORT_FLOOR,
    });

client.start();
await turn();

if (screen !== null) {
    await screen.enter();
    await turn();
    await screen.render();
}

const deliver = (envelope) => listener({ data: JSON.stringify(envelope) });

const steps = [];

for (const step of payload.steps ?? []) {
    if (step.deliver !== undefined) {
        step.deliver.forEach(deliver);
    }

    if (step.generate !== undefined) {
        const g = step.generate;

        for (let n = 1; n <= g.count; n++) {
            const body = g.t === 'coord.round'
                ? {
                    thread_ref: g.thread_ref, post_ref: `${g.post_prefix}${n}`, install_id: g.install_id,
                    from: g.from ?? null, attribution: 'resolved', to: g.to ?? [], targets: g.targets ?? [],
                    carrier: 'announce', declares_close: false,
                    received_at: '2026-08-23T14:30:00.000Z', posted_at: null,
                }
                : {
                    thread_ref: g.thread_ref, install_id: g.install_id, lifecycle: 'opened', subject: null,
                    subject_truncated: false, carrier: 'announce', opened_by: null, attribution: 'unattributable',
                    participants: [], received_at: '2026-08-23T14:30:00.000Z', posted_at: null,
                };

            deliver({
                feed_version: 1,
                t: g.t,
                server_time: '2026-08-23T14:30:00.000Z',
                [g.t === 'coord.round' ? 'coord_round' : 'coord_thread']: body,
            });
        }
    }

    await turn();

    let beads = {};

    if (step.render === true && screen !== null) {
        await screen.render();
        await turn();
    }

    const held = client.coordMessages;
    const threads = {};

    for (const e of held) {
        const body = e.t === 'coord.round' ? e.coord_round : e.coord_thread;
        const t = (threads[body.thread_ref] ??= { threads: 0, rounds: 0, first_post: null });

        if (e.t === 'coord.round') {
            t.rounds++;
            t.first_post ??= body.post_ref;
        } else {
            t.threads++;
        }
    }

    const { coordModel } = await import(pathToFileURL(join(dir, '..', 'coord', 'coord-model.js')).href);

    for (const install of new Set(held.map((e) => (e.coord_round ?? e.coord_thread).install_id))) {
        for (const line of coordModel(held, { install_id: install, truncated: client.coordTruncated }).threads) {
            beads[line.thread_ref] = line.beads_label;
        }
    }

    steps.push({
        held: held.length,
        threads,
        truncated: client.coordTruncated,
        beads,
        animated: {
            A19: log.rows.filter((r) => r.animation_id === 'A19').length,
            A20: log.rows.filter((r) => r.animation_id === 'A20').length,
        },
    });
}

console.log(JSON.stringify({ steps, unscripted: http.unscripted }));

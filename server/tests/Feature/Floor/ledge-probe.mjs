/**
 * card#12445 — THE LEDGE HOLDS ONE BIRD, OVER THE IN-FLIGHT HOLDER: `floor/in-flight.js`'s `EffectsInFlight` admitting
 * portal birds (A22, as `scene.js` emitted them over a fixture run) at chosen instants, so
 * `ABirdFliesToItsDestinationTest` can read whether a bird whose ledge part would meet a held bird's waits, and by how
 * much. `node`, no dependencies.
 *
 * argv[2]: the module tree's root (`server/public/js` or a mutated copy). stdin: `{ interval_ms, scenarios: [{ name,
 * admit: [{ at_ms, effect }] }] }` — each scenario a fresh holder, each effect admitted by a render at `at_ms`. stdout:
 * `{ scenarios: [{ name, held: [{ cause, leg, started_at, wait_frames, span_frames, perch, held_at_end }] }] }` — every
 * admitted bird as `current()` hands it to the painter at its own admission, and whether — before the next admission —
 * it is still held one millisecond before its span ends (`held_at_end`).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const root = process.argv[2];
const input = JSON.parse(readFileSync(0, 'utf8'));
const { EffectsInFlight } = await import(pathToFileURL(join(root, 'floor', 'in-flight.js')).href);

const ctx = {
    journal: [],
    anchors: new Map(),
    threshold: null,
    unconfirmed: () => null,
    stilled: false,
    side: () => 'neither',
    lattice_key: null,
};

const scenarios = input.scenarios.map(({ name, admit }) => {
    const holder = new EffectsInFlight(input.interval_ms);
    const held = [];

    for (const { at_ms, effect } of admit) {
        holder.render([effect], at_ms, ctx);

        const fx = holder.current(at_ms).find((h) => h.started_at === at_ms && h.cause === effect.cause && h.leg === effect.leg);

        if (fx === undefined) {
            continue;
        }

        // Read before the next admission's render, which drops whatever has ended by then.
        const end = fx.started_at + fx.span_frames * input.interval_ms;

        held.push({
            cause: fx.cause,
            leg: fx.leg ?? null,
            started_at: fx.started_at,
            wait_frames: fx.wait_frames,
            span_frames: fx.span_frames,
            perch: fx.perch,
            held_at_end: holder.current(end - 1).some((h) => h.cause === fx.cause && h.leg === fx.leg && h.started_at === fx.started_at),
        });
    }

    return { name, held };
});

process.stdout.write(JSON.stringify({ scenarios }));

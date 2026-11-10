// The fleet arm's probe (card#12239): reads `{seats, messages, floors}` on stdin, builds the fleet join
// over every seat, and renders the fleet-scope posts once per floor — printing each floor's rounds as
// the floor screen would receive them, with the § 6.2 rows it would fire for each. The module directory is argv[2], so a test can hand it a mutated
// copy of the shipped tree.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const { buildFleetJoin } = await import(pathToFileURL(join(dir, 'coord-join.js')).href);
const { edgesOf, fleetModel } = await import(pathToFileURL(join(dir, '..', 'coord', 'coord-model.js')).href);
const { keyOf } = await import(pathToFileURL(join(dir, '..', 'wire', 'seat-key.js')).href);
const input = JSON.parse(readFileSync(0, 'utf8'));
const fleet = buildFleetJoin(input.seats);
// The held seats by key, as the floor screen hands `edgesOf()` the client's map: a fleet-scope end's room is its seat's.
const held = new Map(input.seats.map((seat) => [keyOf(seat), seat]));
const out = { join: fleet.join, reasons: fleet.reasons, floors: {} };

for (const [floor, keys] of Object.entries(input.floors)) {
    const model = fleetModel(input.messages, { ...fleet, here: new Set(keys) });

    out.floors[floor] = Object.fromEntries(model.rounds.map((r) => [r.post_ref, {
        animations: r.animations,
        travellers: r.travellers,
        departure: r.portal.departure,
        arrivals: r.portal.arrivals,
        // The § 6.2 rows the floor screen fires for this post — `edgesOf()`, the one list it reads.
        edges: edgesOf(r, null, held),
    }]));
}

process.stdout.write(JSON.stringify(out));

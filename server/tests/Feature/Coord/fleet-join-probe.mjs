// The fleet arm's probe (card#12239): reads `{seats, messages, floors}` on stdin, builds the fleet join
// over every seat, and renders the fleet-scope posts once per floor — printing each floor's rounds as
// the floor screen would receive them. The module directory is argv[2], so a test can hand it a mutated
// copy of the shipped tree.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const { buildFleetJoin } = await import(pathToFileURL(join(dir, 'coord-join.js')).href);
const { fleetModel } = await import(pathToFileURL(join(dir, '..', 'coord', 'coord-model.js')).href);
const input = JSON.parse(readFileSync(0, 'utf8'));
const fleet = buildFleetJoin(input.seats);
const out = { join: fleet.join, reasons: fleet.reasons, floors: {} };

for (const [floor, keys] of Object.entries(input.floors)) {
    const model = fleetModel(input.messages, { ...fleet, here: new Set(keys) });

    out.floors[floor] = Object.fromEntries(model.rounds.map((r) => [r.post_ref, {
        animations: r.animations,
        travellers: r.travellers,
        departure: r.portal.departure,
        arrivals: r.portal.arrivals,
        elsewhere: r.elsewhere,
    }]));
}

process.stdout.write(JSON.stringify(out));

/**
 * The probe `TheReservedDeskSeatsItsRoleAndNobodyElseTest` drives `floor/floor-layout.js`'s
 * `assignSlots()` through directly — `node`, no dependencies, no DOM — for the values a fixture run
 * cannot carry: a relayed `protocol_agent_role` that is not a string or `null`. argv[2] is the shipped
 * `wire/` directory (or a mutated copy's), the harness rig's own module dir, so a planted copy of the
 * whole tree is judged exactly as the shipped tree is.
 *
 * stdin — JSON: a list of `{seats, slotCount, reservation}`.
 * stdout — JSON: for each, `{slots: {key: slot}, holder, eligible}` from `assignSlots()`.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node assign-slots-probe.mjs <wire-module-dir>');
    process.exit(2);
}

const { assignSlots } = await import(pathToFileURL(join(dir, '..', 'floor', 'floor-layout.js')).href);
const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map(({ seats, slotCount, reservation }) => {
    const out = assignSlots(seats, slotCount, reservation);

    return { slots: Object.fromEntries(out.slots), holder: out.holder, eligible: out.eligible };
})));

// Drives `wire/clock.js` — the one time converter (card#9446) — over the cases on stdin, under the
// module directory named by argv[2] (the shipped `public/js`, or a planted copy of it). Each case is
// `[wire, zone]`; a null zone is the runtime's own, which is `TZ` under node and the browser's on a page.
import { readFileSync } from 'node:fs';

const { clockTime, dateTimeLabel, zoneLabel } = await import(`${process.argv[2]}/wire/clock.js`);
const payload = JSON.parse(readFileSync(0, 'utf8'));
const zone = (z) => (z === null ? undefined : z);

process.stdout.write(JSON.stringify({
    cases: payload.cases.map(([wire, z]) => ({
        clock: clockTime(wire, zone(z)),
        label: dateTimeLabel(wire, zone(z)),
    })),
    zone_label: zoneLabel(payload.zone_at_ms, zone(payload.zone)),
}));

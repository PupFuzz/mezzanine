// T10's probe (card#13117): reads `{seats, delta, room}` on stdin — the seat objects a client holds and the one
// `seat.delta` a room move published — and prints room `room`'s D2 § 8.3.3 rule 1 join twice: over the held seats,
// then over the same seats with the delta's patch merged into its seat by § 8.3.1's shallow merge. The rooms are
// grouped by `lobby-model.js`'s `roomsOf()` and joined by `coord-join.js`'s `buildJoin()`, as the floor screen does
// (`floor/floor-screen.js`'s `#coordinate()`). The module directory is argv[2].
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const { buildJoin } = await import(pathToFileURL(join(dir, 'coord-join.js')).href);
const { roomsOf } = await import(pathToFileURL(join(dir, '..', 'lobby', 'lobby-model.js')).href);
const input = JSON.parse(readFileSync(0, 'utf8'));

const joined = (seats) => buildJoin(roomsOf(seats).get(input.room) ?? []);
const merged = input.seats.map((seat) => (seat.seat_id === input.delta.seat_id ? { ...seat, ...input.delta.patch } : seat));

process.stdout.write(JSON.stringify({ before: joined(input.seats), after: joined(merged) }));

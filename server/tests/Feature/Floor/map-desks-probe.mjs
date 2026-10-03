/**
 * The probe `TheFloorReadsWhichDeskAMapReservesTest` drives `floor/floor-layout.js`'s `mapDesks()`
 * through — `node`, no dependencies, no DOM. The shipped floor module directory (`public/js/floor`,
 * or a mutated copy's) is argv[2], so a planted copy is judged exactly as the shipped tree is.
 *
 * stdin — JSON: a list of Tiled map documents.
 * stdout — JSON: `mapDesks(map)` for each, in the same order.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node map-desks-probe.mjs <module-dir>');
    process.exit(2);
}

const { mapDesks } = await import(pathToFileURL(join(dir, 'floor-layout.js')).href);
const maps = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(maps.map((map) => mapDesks(map))));

/**
 * The probe the PHP suite sweeps the floor's back wall through — `floor/scene.js`'s `backWall()`, the band
 * the scene draws over a span — under `node`, for every width a test names. No DOM and no layout: what comes
 * back is the band exactly as the shipped function emits it, for a test to read where each of its parts lies.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. A planted defect in `scene.js` (a mutated copy
 * of the tree, `DrivesAShippedClientModule::mutatedModules()`) re-mints in what this prints.
 *
 * argv[2] — the client's `wire/` directory, as every probe of this rig is handed it (`server/public/js/wire`, or
 *   the same path under a mutated copy); `scene.js` is its sibling `../floor/scene.js`.
 * stdin — JSON: `{ "widths": [ <int> … ] }`.
 * stdout — JSON: `{ "bands": [ <backWall({x: 0, y: 0, width}, null)> … ] }`, one per width, in order.
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node frame-probe.mjs <wire-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const { backWall } = await import(pathToFileURL(join(dir, '..', 'floor', 'scene.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify({
    bands: payload.widths.map((width) => backWall({ x: 0, y: 0, width }, null)),
}));

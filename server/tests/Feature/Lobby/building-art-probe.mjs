/**
 * The probe the PHP suite reads the lobby's building drawing through — `lobby/building-scene.js`'s
 * `buildingScene()`, `buildingArt()`, `CAB` and `cabStyle()`, with `wire/camera.js`'s `glideMs()` — under
 * `node`. No DOM: what comes back is the shapes the page paints and the cab's style, as data.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/lobby` or a MUTATED COPY of the tree it sits in — so a planted control re-mints its
 * defect in the real code.
 *
 * stdin — JSON: `{ "buildings": [ [ plate … ] … ], "cabs": [ { "plates": [ plate … ], "level", "reduce"?, "ms"? } … ] }`
 *   — each building a list of `building-model.js` plates (`floor`, `level`, and whatever facts the plate
 *   carries); each cab a building, the level the cab stands at, and either `reduce` (the ride's glide,
 *   `glideMs(reduce)`) or `ms` (a render's).
 * stdout — JSON: `{ "buildings": [ { "scene", "art" } … ], "cab": [ shape … ], "cabs": [ { "ms", "style" } … ] }`.
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node building-art-probe.mjs <lobby-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const { CAB, buildingArt, buildingScene, cabStyle } = await import(pathToFileURL(join(dir, 'building-scene.js')).href);
const { glideMs } = await import(pathToFileURL(join(dir, '..', 'wire', 'camera.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify({
    buildings: (payload.buildings ?? []).map((plates) => {
        const scene = buildingScene(plates);

        return { scene, art: buildingArt(scene) };
    }),
    cab: CAB,
    cabs: (payload.cabs ?? []).map(({ plates, level, reduce, ms }) => {
        const glide = reduce === undefined ? ms : glideMs(reduce);

        return { ms: glide, style: cabStyle(buildingScene(plates), level, glide) };
    }),
}));

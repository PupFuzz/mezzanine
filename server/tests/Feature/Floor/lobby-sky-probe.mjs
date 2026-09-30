/**
 * The probe `TheLobbySkyIsTheFloorsA17Test` drives the lobby sky's PAINT through — `node`, no
 * dependencies, no DOM. The shipped modules' directory (`public/js/wire`, or a mutated copy's) is
 * argv[2]; the floor's phase function and the lobby's surface style are read from beside it, so a
 * planted copy is judged exactly as the shipped tree is.
 *
 * stdout — JSON: `{ "phases": { "<hour>": <skyPhase(hour)> }, "styles": { "<phase>|null": <surfaceStyle(scene,
 *   phase)> }, "undrawn": <surfaceStyle(null, "night")> }` — every phase `floor/floor-layout.js`'s
 *   `skyPhase()` returns over a day's civil hours, the lobby surface's style for each of them and for
 *   no phase at all (`null`, no live feed yet) over a scene with an extent, and the style with no
 *   building drawn.
 */

import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node lobby-sky-probe.mjs <module-dir>');
    process.exit(2);
}

const root = dirname(dir);
const { skyPhase } = await import(pathToFileURL(join(root, 'floor', 'floor-layout.js')).href);
const { buildingScene, surfaceStyle } = await import(pathToFileURL(join(root, 'lobby', 'building-scene.js')).href);

const phases = Object.fromEntries(Array.from({ length: 24 }, (_, hour) => [hour, skyPhase(hour)]));
const scene = buildingScene([{ floor: 'a', level: 0 }]);
const styles = Object.fromEntries([...new Set(Object.values(phases)), null]
    .map((phase) => [String(phase), surfaceStyle(scene, phase)]));

process.stdout.write(JSON.stringify({ phases, styles, undrawn: surfaceStyle(null, 'night') }, null, 2));

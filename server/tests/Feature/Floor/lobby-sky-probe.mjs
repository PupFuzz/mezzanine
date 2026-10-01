/**
 * The probe `TheLobbySkyIsTheFloorsA17Test` drives the lobby sky's PAINT through — `node`, no
 * dependencies, no DOM. The shipped modules' directory (`public/js/wire`, or a mutated copy's) is
 * argv[2]; the floor's phase function and the lobby's surface style are read from beside it, so a
 * planted copy is judged exactly as the shipped tree is.
 *
 * stdout — JSON: `{ "phases": { "<hour>": <skyPhase(hour)> }, "styles": { "<phase>|null": <surfaceStyle(scene,
 *   phase)> }, "windows": { "<phase>|null": {stops, windows, stars, suns, moons, lit} }, "undrawn":
 *   <surfaceStyle(null, "night")>, "unwindowed": <windowArt(null, "night")> }` — every phase
 *   `floor/floor-layout.js`'s `skyPhase()` returns over a day's civil hours, the lobby surface's style and
 *   the plates' windows (`windowArt()`, counted by what each shape paints) for each of them and for no
 *   phase at all (`null`, no live feed yet) over a two-plate scene, and both with no building drawn.
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
const { buildingScene, surfaceStyle, windowArt } = await import(pathToFileURL(join(root, 'lobby', 'building-scene.js')).href);

const phases = Object.fromEntries(Array.from({ length: 24 }, (_, hour) => [hour, skyPhase(hour)]));
const scene = buildingScene([{ floor: 'a', level: 0 }, { floor: 'b', level: 1 }]);
const styles = Object.fromEntries([...new Set(Object.values(phases)), null]
    .map((phase) => [String(phase), surfaceStyle(scene, phase)]));


/** Every shape of a window layer, its paint servers' stops included. */
const flat = (shapes) => shapes.flatMap((s) => [s, ...flat(s.children ?? [])]);
const fills = (art, fill) => flat(art.shapes).filter((s) => s.attrs.fill === fill).length;
const windows = Object.fromEntries([...new Set(Object.values(phases)), null].map((phase) => {
    const art = windowArt(scene, phase);

    return [String(phase), {
        stops: flat(art.shapes).filter((s) => s.el === 'stop').map((s) => s.attrs['stop-color']),
        windows: flat(art.shapes).filter((s) => String(s.attrs.fill ?? '').startsWith('url(#')).length,
        // The reference window's inks — star, sun, moon and a lit city window — counted where they are painted.
        stars: fills(art, '#e8ecff'),
        suns: fills(art, '#ffe9a3'),
        moons: fills(art, '#fff3cf'),
        lit: fills(art, '#ffcf7d'),
    }];
}));

process.stdout.write(JSON.stringify({ phases, styles, windows, undrawn: surfaceStyle(null, 'night'), unwindowed: windowArt(null, 'night') }, null, 2));

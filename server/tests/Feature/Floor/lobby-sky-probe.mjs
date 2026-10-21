/**
 * The probe `TheLobbySkyIsTheFloorsA17Test` drives the lobby sky's PAINT through — `node`, no dependencies, no DOM. The
 * shipped modules' directory (`public/js/wire`, or a mutated copy's) is argv[2]; the floor's phase function and its one
 * phase→paint table, and the lobby room's surface style and window, are read from beside it, so a planted copy is judged
 * exactly as the shipped tree is.
 *
 * stdout — JSON: `{ "phases": { "<hour>": <skyPhase(hour)> }, "paint": { "<phase>|null": <skyPaint(phase)> },
 *   "surface": <surfaceStyle(lobbyScene())>, "undrawn": <surfaceStyle(null)>, "windows": { "<phase>|null": {stops,
 *   windows, stars, suns, moons, lit, moving} } }` — every phase `floor/floor-layout.js`'s `skyPhase()` returns over a
 *   day's civil hours, the table's paint for each, the drawn room's surface style and the undrawn one's, and the room's
 *   one window (`building-scene.js`'s `windowArt()`, counted by what each shape paints) for each phase and for no phase
 *   at all (`null`, no live feed yet). `moving` counts the window's shapes that carry a transition, an animation or an
 *   `<animate>` — A17's sky STEPS.
 */

import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node lobby-sky-probe.mjs <module-dir>');
    process.exit(2);
}

const root = dirname(dir);
const { skyPhase, skyPaint } = await import(pathToFileURL(join(root, 'floor', 'floor-layout.js')).href);
const { lobbyScene, surfaceStyle, windowArt } = await import(pathToFileURL(join(root, 'lobby', 'building-scene.js')).href);

const phases = Object.fromEntries(Array.from({ length: 24 }, (_, hour) => [hour, skyPhase(hour)]));
const every = [...new Set(Object.values(phases)), null];

/** Every shape of a window layer, its paint servers' stops included. */
const flat = (shapes) => shapes.flatMap((s) => [s, ...flat(s.children ?? [])]);
const fills = (art, fill) => flat(art.shapes).filter((s) => s.attrs.fill === fill).length;
const windows = Object.fromEntries(every.map((phase) => {
    const art = windowArt(phase);

    return [String(phase), {
        stops: flat(art.shapes).filter((s) => s.el === 'stop').map((s) => s.attrs['stop-color']),
        windows: flat(art.shapes).filter((s) => String(s.attrs.fill ?? '').startsWith('url(#')).length,
        // The reference window's inks — star, sun, moon and a lit city window — counted where they are painted.
        stars: fills(art, '#e8ecff'),
        suns: fills(art, '#ffe9a3'),
        moons: fills(art, '#fff3cf'),
        lit: fills(art, '#ffcf7d'),
        moving: flat(art.shapes).filter((s) => s.el.startsWith('animate') || /transition|animation/.test(String(s.attrs.style ?? ''))).length,
    }];
}));

process.stdout.write(JSON.stringify({
    phases,
    paint: Object.fromEntries(every.map((phase) => [String(phase), skyPaint(phase)])),
    surface: surfaceStyle(lobbyScene()),
    undrawn: surfaceStyle(null),
    windows,
}, null, 2));

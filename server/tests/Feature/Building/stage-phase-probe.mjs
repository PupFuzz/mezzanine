/**
 * The probe `Tests\Feature\Building\TheBuildingViewStacksTheFloorsTest` reads `building/building-screen.js`'s
 * `stagePhase()` through — what the stage shows as the page starts, draws its first stack, loses it, and runs without
 * WebGL 2 (card#13033's polish: the bare list stood for seconds before the first frame). argv[2] is the module directory
 * (the shipped `public/js/building` or a MUTATED COPY of it); stdout is `{ "legs": { "phase": [defect, …] } }`.
 *
 * ⛔ THE PAGE's SEQUENCE, STEP BY STEP: each step is the state the page holds after one event it meets in order, and the
 * phase it must show then — so a planted defect that holds the placeholder too long, drops it too early or shows it with
 * no WebGL reds at the step it breaks.
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node stage-phase-probe.mjs <building-module-dir>');
    process.exit(2);
}

const { stagePhase } = await import(pathToFileURL(join(dir, 'building-screen.js')).href);

/** Frames as the screen draws them — only the members the phase reads differ. */
const frame = (layout) => ({ floors: [], stack: null, layout });
const STACK = { slabs: [] };

const sequences = {
    'a page with WebGL 2, its first stack drawn': [
        ['the module has run, nothing has answered', { webgl: true, art: false, frame: null, stack: null }, 'drawing'],
        ['the first frame, the snapshot not yet applied', { webgl: true, art: false, frame: frame('pending'), stack: null }, 'drawing'],
        ['the layout held, the art not yet answered', { webgl: true, art: false, frame: frame('ready'), stack: null }, 'drawing'],
        ['the art answered before the layout', { webgl: true, art: true, frame: frame('pending'), stack: null }, 'drawing'],
        ['the first stack stood', { webgl: true, art: true, frame: frame('ready'), stack: STACK }, 'stage'],
        ['the stage collapsed to no size', { webgl: true, art: true, frame: frame('ready'), stack: null }, 'list'],
        ['the stage sized again', { webgl: true, art: true, frame: frame('ready'), stack: STACK }, 'stage'],
    ],
    'a page with WebGL 2 whose building cannot be drawn': [
        ['the layout request failed', { webgl: true, art: true, frame: frame('failed'), stack: null }, 'list'],
        ['every answer in, no floor with an extent', { webgl: true, art: true, frame: frame('ready'), stack: null }, 'list'],
    ],
    'a page without WebGL 2': [
        ['the module has run', { webgl: false, art: false, frame: null, stack: null }, 'list'],
        ['the layout held and the art answered', { webgl: false, art: true, frame: frame('ready'), stack: null }, 'list'],
    ],
};

const defects = [];

for (const [sequence, steps] of Object.entries(sequences)) {
    for (const [step, state, want] of steps) {
        const got = stagePhase(state);

        if (got !== want) {
            defects.push(`${sequence}, at "${step}": the stage shows ${JSON.stringify(got)}, not ${JSON.stringify(want)}`);
        }
    }
}

process.stdout.write(JSON.stringify({ legs: { phase: defects } }));

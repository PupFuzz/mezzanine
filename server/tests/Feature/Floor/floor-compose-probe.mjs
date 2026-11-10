/**
 * The probe `Tests\Feature\Floor\TheFloorCompositionIsOneForBothScreensTest` reads `floor/floor-compose.js`'s exported
 * member list through: argv[2] is the module directory — the shipped `public/js/floor` or a MUTATED COPY of it — and
 * stdout is `{ "scene_input_keys": [ … ] }`, the module's own `SCENE_INPUT_KEYS`. stdin carries the rig's payload, unread.
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node floor-compose-probe.mjs <floor-module-dir>');
    process.exit(2);
}

const { SCENE_INPUT_KEYS } = await import(pathToFileURL(join(dir, 'floor-compose.js')).href);

process.stdout.write(JSON.stringify({ scene_input_keys: SCENE_INPUT_KEYS }));

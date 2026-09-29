/**
 * The probe the PHP suite builds the lobby's plate rows through — `lobby/plate-row.js`, the elements
 * `lobby/main.js` stands at each plate's rect — under `node`, with a stand-in `document`. No DOM, no
 * layout: what comes back is the element tree the shipped module constructs, every inline style it
 * sets and every text it writes, for a test to read where each text sits under the camera's transform.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/wire` or a MUTATED COPY of the tree it sits in — and the row module is read from
 * `../lobby/plate-row.js` beside it, so a planted control re-mints its defect in the real code.
 *
 * stdin — JSON: `{ "frames": [ { "building", "scene" } … ] }` — lobby frames the harness drew
 *   (`fleet-client-probe.mjs`'s `lobby_renders[].frame`), each with the building composed; or
 *   `{ "label_max": [ camera … ] }` — cameras (`{surface, bounds, zoom, x, y}`), each handed to
 *   `lobby/building-scene.js`'s `labelMax()`, the width a label wraps within (card#7343 r4b).
 * stdout — JSON: `{ "frames": [ { "rows": [ <node> … ] } … ] }`, a row per plate in the building's
 *   order, each node
 *   `{ "tag", "style": {…}, "href"?, "text"?, "children": [ <node | {"text"}> … ] }` — `text` an
 *   element's own `textContent` where the module set one, a child `{"text"}` a text node; or, for
 *   `label_max`, `{ "label_max": [ px … ] }`, one per camera.
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node plate-row-probe.mjs <wire-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

/** A stand-in element: the members `plate-row.js` may use, and nothing that lays anything out. */
function element(tag) {
    const node = {
        tag,
        style: {},
        dataset: {},
        children: [],
        append(...nodes) {
            node.children.push(...nodes);
        },
    };

    return node;
}

const doc = {
    createElement: (tag) => element(tag),
    createTextNode: (text) => ({ text }),
};

function serialise(node) {
    if (node.tag === undefined) {
        return { text: node.text };
    }

    return {
        tag: node.tag,
        style: { ...node.style },
        ...(node.href === undefined ? {} : { href: node.href }),
        ...(node.textContent === undefined ? {} : { text: node.textContent }),
        dataset: { ...node.dataset },
        children: node.children.map(serialise),
    };
}

const { plateRow } = await import(pathToFileURL(join(dir, '..', 'lobby', 'plate-row.js')).href);
const { labelMax } = await import(pathToFileURL(join(dir, '..', 'lobby', 'building-scene.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

if (payload.label_max !== undefined) {
    process.stdout.write(JSON.stringify({ label_max: payload.label_max.map((camera) => labelMax(camera)) }));
} else {
    process.stdout.write(JSON.stringify({
        frames: payload.frames.map(({ building, scene }) => ({
            rows: building.plates.map((plate) => serialise(
                plateRow(doc, plate, scene.plates[plate.level].rect, plate.floor === building.elevator.at),
            )),
        })),
    }));
}

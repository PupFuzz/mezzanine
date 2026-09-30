/**
 * The probe the PHP suite builds the lobby's plate rows through — `lobby/plate-row.js`, the elements
 * `lobby/main.js` stands at each plate's rect — under `node`, with a stand-in `document`. No DOM, no
 * layout: what comes back is the element tree the shipped module constructs, every inline style it
 * sets and every text it writes, for a test to read where each text sits under the camera's transform.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/wire` or a MUTATED COPY of the tree it sits in — and the row module is read from
 * `../lobby/plate-row.js` beside it, so a planted control re-mints its defect in the real code. The one
 * exception, `labelPlan()` below, is not a defect surface: it is `lobby/main.js`'s OWN three-line
 * composition of `building-scene.js`'s exported `labelSide()`/`labelMaxLeft()`/`labelMax()`/
 * `labelLines()`, copied here so the probe can hand `plateRow()` the SAME shape `main.js` does without
 * this file importing `main.js` itself (a DOM entry, which builds no rows of its own to read back).
 *
 * stdin — JSON: `{ "frames": [ { "building", "scene", "camera" } … ] }` — lobby frames the harness drew
 *   (`fleet-client-probe.mjs`'s `lobby_renders[].frame`, `camera` its own `frame.camera`), each with the
 *   building composed, one row per plate built under `labelPlan(camera)`'s decision; or
 *   `{ "label_plan": [ camera … ] }` — cameras (`{surface, bounds, zoom, x, y}`), each handed to EVERY
 *   geometry function `building-scene.js` exports for this — `labelMax()`, `labelMaxLeft()`,
 *   `labelSide()`, `labelLines()` — separately, for a test that reads each function's OWN answer rather
 *   than only the composed decision `labelPlan()` picks between them.
 * stdout — JSON: `{ "frames": [ { "rows": [ <node> … ], "label": <labelPlan(camera)> } … ] }`, a row
 *   per plate in the building's order, each node
 *   `{ "tag", "style": {…}, "href"?, "text"?, "children": [ <node | {"text"}> … ] }` — `text` an
 *   element's own `textContent` where the module set one, a child `{"text"}` a text node; or, for
 *   `label_plan`, `{ "label_plan": [ { "max", "maxLeft", "side", "lines" } … ] }`, one per camera —
 *   `labelMax()`, `labelMaxLeft()`, `labelSide()`, `labelLines()` of it, in that order.
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
const { labelLines, labelMax, labelMaxLeft, labelSide } = await import(pathToFileURL(join(dir, '..', 'lobby', 'building-scene.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

/** `lobby/main.js`'s own `labelPlan()` — see this file's header for why it is copied rather than imported. */
function labelPlan(camera) {
    const side = labelSide(camera);

    return { side, maxWidth: side === 'left' ? labelMaxLeft(camera) : labelMax(camera), lines: labelLines(camera), zoom: camera.zoom };
}

if (payload.label_plan !== undefined) {
    process.stdout.write(JSON.stringify({
        label_plan: payload.label_plan.map((camera) => ({
            max: labelMax(camera), maxLeft: labelMaxLeft(camera), side: labelSide(camera), lines: labelLines(camera),
        })),
    }));
} else {
    process.stdout.write(JSON.stringify({
        frames: payload.frames.map(({ building, scene, camera }) => {
            const label = labelPlan(camera);

            return {
                label,
                rows: building.plates.map((plate) => serialise(
                    plateRow(doc, plate, scene.plates[plate.level].rect, plate.floor === building.elevator.at, label),
                )),
            };
        }),
    }));
}

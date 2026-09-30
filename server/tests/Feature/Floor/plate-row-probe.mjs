/**
 * The probe the PHP suite builds the lobby's plate rows through — `lobby/plate-row.js`, the elements
 * `lobby/main.js` stands at each plate's rect — under `node`, with a stand-in `document`. No DOM, no
 * layout: what comes back is the element tree the shipped module constructs, every inline style it
 * sets and every text it writes, for a test to read where each text sits under the camera's transform.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING (card#7343 r4's fix round: `plateRow()`
 * builds ONE static shape now, reading every geometry and paint number through a CSS custom property —
 * see `plate-row.js`'s own header — so this probe's job is simpler than the r1–r3 mechanism's own: build
 * the row (structural — every property a `var()` reference, never a number), and separately call
 * `label-paint.js`'s `showLabels()` against the SAME camera to read back what those `var()`s resolve to.
 * Nothing here re-derives the geometry; a planted defect in either module re-mints in the real code.
 *
 * stdin — JSON: `{ "frames": [ { "building", "scene", "camera" } … ] }` — lobby frames the harness drew
 *   (`fleet-client-probe.mjs`'s `lobby_renders[].frame`, `camera` its own `frame.camera`), each with the
 *   building composed, one row per plate; or
 *   `{ "show_labels": [ camera … ] }` — cameras (`{surface, bounds, zoom, x, y}`), each handed to
 *   `showLabels()` alone, for a test that reads its own written properties against a camera with NO row
 *   built at all — P5's own "a camera move without a render" check.
 * stdout — JSON: `{ "frames": [ { "rows": [ <node> … ], "label": <showLabels() read back> } … ] }`, a row
 *   per plate in the building's order, each node
 *   `{ "tag", "style": {…}, "href"?, "text"?, "children": [ <node | {"text"}> … ] }` — `text` an
 *   element's own `textContent` where the module set one, a child `{"text"}` a text node, and `style`
 *   the row's OWN inline style EXACTLY as `plate-row.js` set it — a `var(--label-…)` reference where the
 *   shipped module reads one, never a number this probe computed; or, for `show_labels`,
 *   `{ "show_labels": [ { "zoom", "scale", "side", "left", "width", "lines", "ink", "textInk", "backing",
 *   "halo", "align" } … ] }`, one per camera — `showLabels()`'s own written properties, read back off a
 *   stand-in element.
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

/**
 * A stand-in element: the members `plate-row.js` may use, and nothing that lays anything out.
 * `setAttribute` (r4 review round: the accessible-name chain) stores into `attrs`, read back by
 * `serialise()` alongside `id` and `tabIndex` — both real, reflected DOM/IDL properties, so plain
 * assignment (`el.id = …`, `el.tabIndex = …`) is what the shipped module does too.
 */
function element(tag) {
    const node = {
        tag,
        style: {},
        dataset: {},
        attrs: {},
        children: [],
        append(...nodes) {
            node.children.push(...nodes);
        },
        setAttribute(name, value) {
            node.attrs[name] = String(value);
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
        ...(node.id === undefined ? {} : { id: node.id }),
        ...(node.tabIndex === undefined ? {} : { tabIndex: node.tabIndex }),
        ...(node.textContent === undefined ? {} : { text: node.textContent }),
        attrs: { ...node.attrs },
        dataset: { ...node.dataset },
        children: node.children.map(serialise),
    };
}

const { plateRow } = await import(pathToFileURL(join(dir, '..', 'lobby', 'plate-row.js')).href);
const { showLabels } = await import(pathToFileURL(join(dir, '..', 'lobby', 'label-paint.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

/**
 * `showLabels()` run against a stand-in `#lobby-floors` — the custom properties it writes, read back as
 * plain values (never a `var()` string: this IS the write, not a reference to it).
 */
function shownLabel(camera) {
    const props = new Map();
    const floorsEl = { style: { setProperty: (k, v) => props.set(k, v) }, dataset: {} };

    showLabels(floorsEl, camera);

    return {
        zoom: camera.zoom,
        scale: props.has('--label-scale') ? Number(props.get('--label-scale')) : null,
        side: floorsEl.dataset.labelSide ?? null,
        left: props.has('--label-left') ? Number.parseFloat(props.get('--label-left')) : null,
        width: props.has('--label-width') ? Number.parseFloat(props.get('--label-width')) : null,
        lines: props.has('--label-lines') ? Number(props.get('--label-lines')) : null,
        ink: props.get('--label-ink') ?? null,
        textInk: props.get('--label-text-ink') ?? null,
        backing: props.get('--label-backing') ?? null,
        halo: props.get('--label-halo') ?? null,
        align: props.get('--label-align') ?? null,
    };
}

if (payload.show_labels !== undefined) {
    process.stdout.write(JSON.stringify({ show_labels: payload.show_labels.map(shownLabel) }));
} else {
    process.stdout.write(JSON.stringify({
        frames: payload.frames.map(({ building, scene, camera }) => ({
            label: shownLabel(camera),
            rows: building.plates.map((plate) => serialise(
                plateRow(doc, plate, scene.plates[plate.level].rect, plate.floor === building.elevator.at),
            )),
        })),
    }));
}

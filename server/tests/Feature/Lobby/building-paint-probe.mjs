/**
 * The probe the PHP suite drives the lobby's building-drawing PAINT through — `lobby/building-paint.js`'s
 * `buildingDrawing()`, `keepDrawing()` and `paintBuilding()` — under `node`, with a stand-in DOM. No real
 * DOM, no layout: what comes back is what the shipped functions actually DID to a stand-in tree, not a
 * reading of the shipped source's text (impl review r1, card#7343 row 16).
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the
 * shipped `public/js/lobby` or a MUTATED COPY of the tree it sits in — so a planted control re-mints its
 * defect in the real code.
 *
 * stdin — JSON: `{ "renders": [ { "scene": <buildingScene()'s, or null>, "level": number|null,
 *   "glide": number, "sky": <A17's phase>|null } … ] }` — `buildingDrawing(doc)` is built once, then `keepDrawing(rows, drawing)`
 *   and `paintBuilding()` are called once per render, `painted` threaded through as the real page does.
 *
 * stdout — JSON: `{
 *   "pointerEvents": string|undefined,   -- the drawing's own style, set once at construction
 *   "ariaHidden": string|null,
 *   "removes": number,                   -- how many times `drawing.remove()` was actually called
 *   "prepends": number,                  -- how many times `rows.prepend(drawing)` was actually called
 *   "renders": [ { "hidden": bool, "box": string|null, "shapeCount": number,
 *                   "windowSky": [ <the windows' gradient stop colours> ], "windowCount": number,
 *                   "cabStyle": {…}, "cabDisplay": string } … ]
 * }`
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node building-paint-probe.mjs <lobby-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

/** A stand-in DOM node: the members `building-paint.js` may use, and nothing that lays anything out. */
function element(tag) {
    const node = {
        tag,
        style: {},
        children: [],
        parentNode: null,
        hidden: false,
        attrs: {},
        setAttribute(name, value) {
            node.attrs[name] = String(value);
        },
        getAttribute(name) {
            return node.attrs[name] ?? null;
        },
        append(...nodes) {
            for (const n of nodes) {
                detach(n);
                node.children.push(n);
                n.parentNode = node;
            }
        },
        prepend(...nodes) {
            for (const n of [...nodes].reverse()) {
                detach(n);
                node.children.unshift(n);
                n.parentNode = node;
            }
        },
        remove() {
            detach(node);
        },
        replaceChildren(...nodes) {
            for (const c of node.children) {
                c.parentNode = null;
            }

            node.children = [];

            for (const n of nodes) {
                detach(n);
                node.children.push(n);
                n.parentNode = node;
            }
        },
    };

    return node;
}

function detach(node) {
    if (node.parentNode === null) {
        return;
    }

    const i = node.parentNode.children.indexOf(node);

    if (i !== -1) {
        node.parentNode.children.splice(i, 1);
    }

    node.parentNode = null;
}

const doc = {
    createElement: (tag) => element(tag),
    createElementNS: (_ns, tag) => element(tag),
};

const { buildingDrawing, keepDrawing, paintBuilding } = await import(pathToFileURL(join(dir, 'building-paint.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

const rows = element('ul');
const { drawing, art, scenery, windows, cabNode } = buildingDrawing(doc);

// The two counters the four named defects are read off — real call counts on the real objects, not a
// reading of the source that called them.
let removes = 0;
let prepends = 0;

const realRemove = drawing.remove.bind(drawing);

drawing.remove = () => {
    removes += 1;
    realRemove();
};

const realPrepend = rows.prepend.bind(rows);

rows.prepend = (...nodes) => {
    if (nodes.includes(drawing)) {
        prepends += 1;
    }

    realPrepend(...nodes);
};

let painted = null;
const renders = [];

for (const { scene, level, glide, sky = null } of payload.renders) {
    keepDrawing(rows, drawing);
    painted = paintBuilding(doc, { drawing, art, scenery, windows, cabNode }, scene, level, glide, painted, sky);
    renders.push({
        hidden: drawing.hidden,
        box: painted?.box ?? null,
        shapeCount: scenery.children.length,
        // The windows' gradient stops as painted — A17's sky the windows show after this render.
        windowSky: windows.children
            .filter((n) => n.tag === 'defs')
            .flatMap((d) => d.children.flatMap((g) => g.children.map((stop) => stop.attrs['stop-color']))),
        windowCount: windows.children.length,
        cabStyle: { ...cabNode.style },
        cabDisplay: cabNode.style.display ?? '',
    });
}

process.stdout.write(JSON.stringify({
    pointerEvents: drawing.style.pointerEvents,
    ariaHidden: drawing.getAttribute('aria-hidden'),
    removes,
    prepends,
    renders,
}));

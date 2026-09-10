/**
 * The probe the PHP suite drives the coordination client model through — `node`, no
 * dependencies, no network, no DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2],
 * so the same probe runs against `public/js/coord` and against a MUTATED COPY of it in a temp
 * directory — which is how every planted control in `tests/Feature/Coordination` re-mints its
 * defect against the real code rather than against a second copy of the logic.
 *
 * stdin  — JSON: `{ "messages": [<feed envelope>, …], "install_id": "aimla",
 *                   "join": { "<agent name>": "<seat_id>" } | null,
 *                   "clock_probe": ["<rfc3339>", …], "drive_main": true }`
 * stdout — JSON: `{ "thread_members", "round_members", "model", "clock", "main" }`
 *
 * ⚠ `main` DRIVES `main.js` OVER A STUB, AND THE STUB IS NOT A BROWSER. It is the smallest
 * object that satisfies the four DOM calls that file makes, and it exists for exactly one
 * property: that `main.js` reaches `coordModel` with NO `join`, so no line is drawn. Nothing
 * about layout, paint or the shape of an element is tested by it and nothing on this host could
 * be. What it CAN catch is a join synthesised in the thin layer, which is the one decision that
 * file is forbidden to make and the one that would look correct on screen.
 *
 * Any throw exits non-zero with the message on stderr: a probe that swallowed one would turn a
 * client that crashes on an object shape D2 permits into a green test.
 */

import { readFileSync } from 'node:fs';
import { join as joinPath } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node coord-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const url = (base, file) => pathToFileURL(joinPath(base, file)).href;

const model = await import(url(dir, 'coord-model.js'));

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
const probes = payload.clock_probe ?? [];

/** The four DOM calls `main.js` makes, and not one more. */
function stubRoot() {
    const el = () => ({ textContent: '', dataset: {}, children: [] });
    const slots = new Map();
    const doc = { createElement: (tag) => Object.assign(el(), { tag }) };
    const node = {
        ownerDocument: doc,
        querySelector(selector) {
            if (!slots.has(selector)) {
                slots.set(selector, Object.assign(el(), {
                    replaceChildren: (...kids) => { slots.get(selector).children = kids; },
                }));
            }

            return slots.get(selector);
        },
        read: () => Object.fromEntries([...slots].map(([k, v]) => [k, {
            text: v.textContent,
            rows: v.children.map((c) => ({ text: c.textContent, data: c.dataset })),
        }])),
    };

    return node;
}

let main = null;

if (payload.drive_main === true) {
    const { renderCoordLayer } = await import(url(dir, 'main.js'));
    const root = stubRoot();
    const returned = renderCoordLayer(root, payload.messages ?? [], payload.install_id ?? null);

    main = { dom: root.read(), model: returned };
}

console.log(JSON.stringify({
    main,
    thread_members: model.COORD_THREAD_MEMBERS,
    round_members: model.COORD_ROUND_MEMBERS,
    messages: [model.COORD_THREAD_MESSAGE, model.COORD_ROUND_MESSAGE],
    model: model.coordModel(payload.messages ?? [], {
        install_id: payload.install_id ?? null,
        join: payload.join ?? null,
    }),
    clock: probes.map((v) => model.clockTime(v)),
}, null, 2));

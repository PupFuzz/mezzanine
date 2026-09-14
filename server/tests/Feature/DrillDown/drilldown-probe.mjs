/**
 * The probe the PHP suite drives the drill-down panel's client through — `node`, no
 * dependencies, no network, no DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2],
 * so the same probe runs against `public/js/drilldown` and against a MUTATED COPY of it in a
 * temp directory — which is how every planted control in `tests/Feature/DrillDown` re-mints its
 * defect against the real code rather than against a second copy of the logic.
 *
 * stdin  — JSON: `{ "seat": <a GET /api/fleet/seats/… body>, "timeline": <its body|null>,
 *                   "now_ms": <the corrected clock>, "ref_bases": {…}|null,
 *                   "durations": [<seconds>, …], "drive_main": true }`
 * stdout — JSON: `{ "model", "durations", "selections", "main" }`
 *
 * ⚠ `main` DRIVES `main.js` OVER A STUB, AND THE STUB IS NOT A BROWSER. It is the smallest
 * object that satisfies the DOM calls that file makes, and it exists for one property: that the
 * strings reaching the page are the MODEL's, so a decision taken in the thin layer — a zero
 * where the model said *not reported*, a title where the model said **untitled** — is visible to
 * a test. Nothing about layout, paint or the shape of an element is tested by it and nothing on
 * this host could be.
 *
 * Any throw exits non-zero with the message on stderr: a probe that swallowed one would turn a
 * client that crashes on an object shape D2 permits into a green test.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node drilldown-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const url = (file) => pathToFileURL(join(dir, file)).href;

const model = await import(url('drilldown-model.js'));
const duration = await import(url('../wire/duration.js'));

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
const seat = payload.seat ?? null;
const openCalls = seat?.detail?.open_calls ?? [];

/** The DOM calls `main.js` makes, and not one more. */
function stubRoot() {
    const el = () => ({ textContent: '', dataset: {}, children: [], attributes: {} });
    const slots = new Map();
    const doc = { createElement: (tag) => Object.assign(el(), { tag }) };

    return {
        ownerDocument: doc,
        querySelector(selector) {
            if (!slots.has(selector)) {
                slots.set(selector, Object.assign(el(), {
                    replaceChildren: (...kids) => { slots.get(selector).children = kids; },
                    setAttribute: (k, v) => { slots.get(selector).attributes[k] = v; },
                    removeAttribute: (k) => { delete slots.get(selector).attributes[k]; },
                }));
            }

            return slots.get(selector);
        },
        read: () => Object.fromEntries([...slots].map(([k, v]) => [k, {
            text: v.textContent,
            attributes: v.attributes,
            rows: v.children.map((c) => ({ text: c.textContent, data: c.dataset })),
        }])),
    };
}

let main = null;

if (payload.drive_main === true) {
    const { renderDrillDown } = await import(url('main.js'));
    const root = stubRoot();

    renderDrillDown(root, seat, payload.timeline ?? null, {
        now_ms: payload.now_ms,
        ref_bases: payload.ref_bases ?? null,
    });

    main = { dom: root.read() };
}

console.log(JSON.stringify({
    main,
    model: seat === null ? null : model.drillDownModel(seat, payload.timeline ?? null, {
        now_ms: payload.now_ms,
        ref_bases: payload.ref_bases ?? null,
    }),
    // § 2.4's function, sampled on the inputs the caller names — the boundary table's own
    // column, handed in from the document rather than written here.
    durations: (payload.durations ?? []).map((s) => duration.formatDuration(s)),
    // The two selections D3 states for one list, so a test can hold the contradiction visible
    // rather than assert only the one this client renders (`drilldown-model.js`).
    selections: {
        interns: model.internCalls(openCalls).map((c) => c.call_id),
        subagent_scoped: model.subagentScopedCalls(openCalls).map((c) => c.call_id),
    },
    refs: (payload.ref_probe ?? []).map((ref) => model.taskRefLink(ref, payload.ref_bases ?? null)),
}, null, 2));

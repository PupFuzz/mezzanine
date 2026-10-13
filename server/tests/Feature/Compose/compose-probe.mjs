/**
 * The probe AT-C8 drives the shipped compose block through — `node`, no dependencies, no network, no
 * DOM. `docs/design/BOARD-TASK.md § 15.12`, card#9417.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. The module directory is argv[2]
 * (`public/js/drilldown`, or a MUTATED COPY of the tree), and the three files driven are the ones the
 * page runs: `compose-box.js` (state and the one request), `compose-model.js` (every sentence) and
 * `main.js`'s `renderCompose()` (the slots), over a stub that has exactly the members that function
 * touches.
 *
 * stdin  — JSON: `{ "cases": [ { "name", "seat", "unconfigured"?, "answer"? } ] }` where `answer` is
 *          `{status, body}`, `{status, raw}` (a body that is not JSON), or `"none"` (no response
 *          reached the browser); a case with no `answer` renders the target line only.
 * stdout — JSON: `{ name: { target, button, button_hidden, enabled, in_flight, receipt, text_after,
 *          request } }`
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node compose-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const url = (file) => pathToFileURL(join(dir, file)).href;
const { ComposeBox } = await import(url('compose-box.js'));
const { renderCompose } = await import(url('main.js'));
const { SENTENCES } = await import(url('compose-model.js'));

const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');

/** The DOM calls `renderCompose()` makes, and not one more. */
function stubRoot() {
    const slots = new Map();

    return {
        querySelector(selector) {
            if (!slots.has(selector)) {
                slots.set(selector, { textContent: '', hidden: false, disabled: false, value: '', dataset: {}, parentElement: { hidden: false } });
            }

            return slots.get(selector);
        },
        slot: (selector) => slots.get(selector) ?? null,
    };
}

// The client's whole sentence map, by key — AT-C8 holds it to exactly the table's keys.
const out = { __sentence_keys: Object.keys(SENTENCES) };

for (const c of payload.cases ?? []) {
    let release = null;
    let sent = null;

    const fetch = (path, init) => {
        sent = { path, method: init.method, redirect: init.redirect, headers: init.headers, body: JSON.parse(init.body) };

        return new Promise((resolve, reject) => {
            release = () => {
                if (c.answer === 'none') {
                    reject(new TypeError('Failed to fetch'));

                    return;
                }

                resolve({
                    status: c.answer.status,
                    ok: c.answer.status >= 200 && c.answer.status < 300,
                    json: async () => (c.answer.raw !== undefined ? JSON.parse(c.answer.raw) : c.answer.body),
                });
            };
        });
    };

    const box = new ComposeBox(fetch, { csrf: 'csrf-probe-token', unconfigured: c.unconfigured ?? null });
    const root = stubRoot();
    const read = () => ({
        target: root.slot('[data-panel-compose-target]')?.textContent ?? null,
        receipt: root.slot('[data-panel-compose-receipt]')?.hidden ? null : root.slot('[data-panel-compose-receipt]')?.textContent ?? null,
    });

    renderCompose(root, box.view(c.seat));

    const first = read();
    const result = {
        target: first.target,
        button: root.slot('[data-panel-compose-post]').textContent,
        button_hidden: root.slot('[data-panel-compose-post]').hidden,
        enabled: !root.slot('[data-panel-compose-post]').disabled,
        text_disabled: root.slot('[data-panel-compose-text]').disabled,
    };

    if (c.answer !== undefined) {
        root.slot('[data-panel-compose-text]').value = c.text ?? 'the message';

        const pending = box.post(c.seat, c.text ?? 'the message');

        renderCompose(root, box.view(c.seat));
        result.in_flight = read().receipt;

        // A second press while the first is in flight sends nothing (§ 15.7: no duplicate comment).
        const before = sent;
        box.post(c.seat, 'pressed twice');
        result.second_press_sent = sent !== before;

        release();
        await pending;

        renderCompose(root, box.view(c.seat));
        result.receipt = read().receipt;
        result.text_after = root.slot('[data-panel-compose-text]').value;

        // A later render (the panel's 1 s tick) leaves both where they are.
        root.slot('[data-panel-compose-text]').value = result.text_after === '' ? 'typed after' : result.text_after;
        renderCompose(root, box.view(c.seat));
        result.receipt_after_tick = read().receipt;
        result.text_after_tick = root.slot('[data-panel-compose-text]').value;
        result.request = sent;
    }

    out[c.name] = result;
}

console.log(JSON.stringify(out, null, 2));

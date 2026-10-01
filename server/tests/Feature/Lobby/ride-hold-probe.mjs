/**
 * The probe the PHP suite drives the lobby's ride hold through — `lobby/ride-hold.js`, the capture-phase
 * `click` listener that keeps a plate link from navigating while a ride is in flight — under `node`, with a
 * stand-in building. No DOM.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. The module directory is argv[2] — the shipped
 * `public/js/lobby` or a MUTATED COPY of it — so every planted control re-mints its defect in the real code.
 *
 * The stand-in building holds what the page stands in `#lobby-building`: a plate row (`li` with
 * `data-floor`, as `plate-row.js` builds it) whose label holds the link, the name inside it, and a status
 * line outside it; and an unclaimed room's row (`li` with no `data-floor`, § 9 F17) holding its link. A
 * target's `closest()` answers a bare tag (`a`) or a tag with one data attribute (`li[data-floor]`); any
 * other selector throws, so a module asking something this stand-in cannot answer fails the probe rather
 * than passing it.
 *
 * stdin — JSON: `{ "clicks": [ { "riding": bool, "on": "link" | "name" | "status" | "row" | "unclaimed" } … ] }`
 *   — `link` the plate's `<a>`, `name` the name's `<span>` inside it, `status` a status part outside it,
 *   `row` the plate's `<li>`, `unclaimed` an unclaimed room's `<a>`.
 * stdout — JSON: `{ "listeners": [ { "type", "capture" } … ], "clicks": [ { "on", "riding", "default_prevented" } … ] }`
 *   — every listener the module added to the building, and for each click, run through every `click`
 *   listener, whether its default action (the link's navigation) was prevented.
 *
 * Any throw exits non-zero with the message on stderr.
 */

import { join } from 'node:path';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (dir === undefined) {
    console.error('usage: node ride-hold-probe.mjs <lobby-module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

function node(tag, parent, dataset = {}) {
    return {
        tag,
        parent,
        dataset,
        closest(selector) {
            const m = /^([a-z]+)(?:\[data-([a-z-]+)\])?$/.exec(selector);

            if (m === null) {
                throw new Error(`the stand-in answers no selector ${JSON.stringify(selector)}`);
            }

            for (let n = this; n !== null; n = n.parent) {
                if (n.tag === m[1] && (m[2] === undefined || n.dataset[m[2]] !== undefined)) {
                    return n;
                }
            }

            return null;
        },
    };
}

const listeners = [];
const building = node('div', null);

building.addEventListener = (type, listener, options) => {
    listeners.push({ type, listener, capture: options === true || options?.capture === true });
};

const floors = node('ul', building);
const row = node('li', floors, { floor: 'f1' });
const label = node('div', row);
const link = node('a', label);
const targets = {
    row,
    link,
    name: node('span', link),
    status: node('span', label),
    unclaimed: node('a', node('li', floors)),
};

let riding = false;
const { holdPlateLinks } = await import(pathToFileURL(join(dir, 'ride-hold.js')).href);

holdPlateLinks(building, () => riding);

const { clicks } = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify({
    listeners: listeners.map(({ type, capture }) => ({ type, capture })),
    clicks: clicks.map((click) => {
        riding = click.riding;

        const event = {
            type: 'click',
            target: targets[click.on],
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };

        for (const l of listeners.filter((entry) => entry.type === 'click')) {
            l.listener(event);
        }

        return { on: click.on, riding: click.riding, default_prevented: event.defaultPrevented };
    }),
}));

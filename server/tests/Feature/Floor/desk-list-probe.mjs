/**
 * The probe `TheListViewRendersEveryDeskMemberTest` drives the SHIPPED list view through —
 * `server/public/js/desk/desk-list.js` (Appendix B row 15, slice A), or a mutated copy of it. `node`,
 * no dependencies, no DOM.
 *
 * argv[2] — the tree's `wire/` directory (the harness's own convention); the module is `../desk/`.
 * stdin   — `{ "desks": [ { "run": "<run>", "key": "<install/seat>", "model": <deskModel() output> } ] }`,
 *           every distinct desk model the fixtures' runs drew.
 * stdout  — `{ "leaves": { "<path>": { "nondefault_runs": [ … ] } }, "excluded": [ "<path>", … ],
 *              "not_listed": [ "<path>", … ], "defects": [ "<sentence>", … ] }`
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE POPULATION IS DERIVED FROM THE MODELS, NEVER LISTED. A path is a LEAF when no desk carries an
 * object or a non-empty array there; a path some desk carries an object at is interior, and its
 * children are the leaves (so `lag`, null on a live desk and an object on a lagged one, contributes
 * `lag.line` and `lag.overlay`, and a member null on every desk stays a leaf that is never non-default).
 *
 * ⛔ "PRINTED" IS MEASURED ON THE ROW, NOT TAKEN FROM THE MODULE's SAY-SO. For every leaf a desk carries
 * at a non-default value, the leaf is replaced in a copy of that desk — a string or a number by a
 * sentinel that must then appear verbatim on the row, a `true` by `false`, which must change the row —
 * and the row is re-derived. A leaf whose replacement leaves no trace on the row is not on the row.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node desk-list-probe.mjs <wire-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const { deskListRow, NOT_LISTED } = await import(pathToFileURL(join(dir, '..', 'desk', 'desk-list.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

/** The guard's own definition of a default: not null, not false, not 0, not empty. */
const isDefault = (v) => v === null || v === undefined || v === false || v === 0 || v === ''
    || (Array.isArray(v) && v.length === 0);

const isBranch = (v) => (Array.isArray(v) && v.length > 0) || (v !== null && typeof v === 'object' && !Array.isArray(v));

/** Every `[concrete path, normalised path, value]` under one model, branches included. */
function walk(value, concrete = [], normal = '', out = []) {
    out.push([concrete, normal, value]);

    if (Array.isArray(value)) {
        value.forEach((v, i) => walk(v, [...concrete, i], `${normal}[]`, out));
    } else if (value !== null && typeof value === 'object') {
        for (const [k, v] of Object.entries(value)) {
            walk(v, [...concrete, k], normal === '' ? k : `${normal}.${k}`, out);
        }
    }

    return out;
}

function setAt(root, path, value) {
    const copy = structuredClone(root);
    let node = copy;

    for (const step of path.slice(0, -1)) {
        node = node[step];
    }

    node[path[path.length - 1]] = value;

    return copy;
}

const walked = payload.desks.map((d) => ({ ...d, nodes: walk(d.model).slice(1) }));
const interior = new Set();

for (const d of walked) {
    for (const [, normal, value] of d.nodes) {
        if (isBranch(value)) {
            interior.add(normal);
        }
    }
}

const leaves = {};
const excluded = new Set();
const defects = [];
let sentinel = 0;

for (const d of walked) {
    const row = deskListRow(d.model);
    const where = `[${d.run}] ${d.key}`;

    if (!Array.isArray(row) || row.some((line) => typeof line !== 'string' || line === '')) {
        defects.push(`${where}: the row is not a list of non-empty strings: ${JSON.stringify(row)}`);
        continue;
    }

    for (const [concrete, normal, value] of d.nodes) {
        if (interior.has(normal)) {
            continue;
        }

        if (Object.hasOwn(NOT_LISTED, normal)) {
            excluded.add(normal);

            const carrier = NOT_LISTED[normal].carried_by;

            if (carrier !== undefined && !isDefault(value)) {
                const held = d.model[carrier];

                if (typeof held !== 'string' || !held.includes(String(value))) {
                    defects.push(`${where}: \`${normal}\` is excluded as carried by \`${carrier}\`, and \`${carrier}\` `
                        + `(${JSON.stringify(held)}) does not carry ${JSON.stringify(value)}`);
                }
            }

            continue;
        }

        leaves[normal] ??= { nondefault_runs: [] };

        if (isDefault(value)) {
            continue;
        }

        if (!leaves[normal].nondefault_runs.includes(d.run)) {
            leaves[normal].nondefault_runs.push(d.run);
        }

        let printed;

        if (typeof value === 'boolean') {
            printed = JSON.stringify(deskListRow(setAt(d.model, concrete, false))) !== JSON.stringify(row);
        } else if (typeof value === 'string' || typeof value === 'number') {
            sentinel += 1;

            const mark = typeof value === 'number' ? 900000000 + sentinel : `⟦leaf-${sentinel}⟧`;

            printed = deskListRow(setAt(d.model, concrete, mark)).join('\n').includes(String(mark));
        } else {
            printed = false;
        }

        if (!printed) {
            defects.push(`${where}: \`${normal}\` = ${JSON.stringify(value)} is neither on the row nor named in NOT_LISTED`);
        }
    }

}

console.log(JSON.stringify({
    leaves,
    excluded: [...excluded].sort(),
    not_listed: Object.keys(NOT_LISTED).sort(),
    defects,
}));

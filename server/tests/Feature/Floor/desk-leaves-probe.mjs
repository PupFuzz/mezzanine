/**
 * card#11058 B1 — THE DESK LEAF WALKER. `node`, no dependencies, no DOM.
 *
 * At this commit it has one mode, `--snapshot <file>`: the BASELINE of today's desk (design § 5 B1
 * criterion 5). It walks every seat in every fixture file under `server/tests` (a path holding
 * `fixtures`, deduplicated by content) plus the planted seats of `desk-leaves/fx-desk-leaves-planted.json`,
 * each as the variants below, and records — per desk — every (kind, member, value) leaf the REAL
 * `deskLayout()` draws over the REAL `deskModel()` today, together with the seat and the variant that
 * drew it, so the record replays without the fixture files that fed it.
 *
 * ⛔ THE KEY IS `source | ordinal | seat_id | variant`, ordinal counted within its source, and the
 * walker refuses (exit 2) to write a baseline whose keys are not unique.
 *
 * argv: `--snapshot <file> --commit <sha>` — the file to write, and the commit the walk read.
 */

import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const SERVER = join(HERE, '..', '..', '..');
const args = process.argv.slice(2);
const opt = (name) => {
    const i = args.indexOf(name);

    return i >= 0 ? args[i + 1] : null;
};
const JS = opt('--js') ?? join(SERVER, 'public', 'js');
const mod = async (p) => import(pathToFileURL(join(JS, p)).href);

const { deskModel } = await mod('desk/desk-render.js');
const { deskLayout } = await mod('floor/desk-layout.js');
const { SUBAGENT_CALL, MONITOR, UNCONFIRMED } = await mod('desk/desk-list.js');
const { deskAgeReadout } = await mod('wire/age-readout.js');

// ── the clock and the harness measurer ────────────────────────────────────────────────────────────
export const NOW_MS = Date.parse('2026-08-23T14:23:22.400Z');
const GLYPH_W = 6;
const LINE_H = 12;
const measure = (text) => ({ w: [...String(text)].length * GLYPH_W, h: LINE_H });
const ctxFor = (placeholder) => ({
    box: { width: 440, height: 228 },
    measure,
    character: { w: 18, h: 32 },
    sprite: { url: 'desk.png', w: 116, h: 57 },
    placeholder,
});

// ── the population: every fixture seat, then the planted seats ──────────────────────────────────
function walkJson(dir, out = []) {
    for (const name of readdirSync(dir).sort()) {
        const p = join(dir, name);

        if (statSync(p).isDirectory()) {
            walkJson(p, out);
        } else if (name.endsWith('.json') && p.includes('fixtures')) {
            out.push(p);
        }
    }

    return out;
}

function seatsIn(value, out) {
    if (Array.isArray(value)) {
        value.forEach((v) => seatsIn(v, out));
    } else if (value && typeof value === 'object') {
        if (typeof value.seat_id === 'string' && typeof value.install_id === 'string' && 'render_state' in value && 'badges' in value) {
            out.push(value);
        }

        Object.values(value).forEach((v) => seatsIn(v, out));
    }

    return out;
}

const hashOf = (seat) => createHash('sha1').update(JSON.stringify(seat)).digest('hex').slice(0, 16);

const population = [];
const seen = new Set();

for (const file of walkJson(join(SERVER, 'tests'))) {
    for (const seat of seatsIn(JSON.parse(readFileSync(file, 'utf8')), [])) {
        const key = JSON.stringify(seat);

        if (!seen.has(key)) {
            seen.add(key);
            population.push({ source: relative(join(SERVER, '..'), file), seat, planted: false });
        }
    }
}

for (const { source, seat } of JSON.parse(readFileSync(join(HERE, 'desk-leaves', 'fx-desk-leaves-planted.json'), 'utf8')).seats) {
    population.push({ source, seat, planted: true });
}

// ── the variants: confirmed/unconfirmed for a fixture seat; × live/stilled × motion/reduce and a
//    placeholder for a planted one ──────────────────────────────────────────────────────────────────
const variants = [];

for (const c of population) {
    if (!c.planted) {
        for (const missing of [false, true]) {
            variants.push({ ...c, missing, stilled: false, reduce: false, placeholder: false });
        }

        continue;
    }

    for (const missing of [false, true]) {
        for (const stilled of [false, true]) {
            for (const reduce of [false, true]) {
                variants.push({ ...c, missing, stilled, reduce, placeholder: false });
            }
        }
    }

    variants.push({ ...c, missing: false, stilled: false, reduce: false, placeholder: true });
}

const variantKey = (v) => `${v.missing ? 'unconfirmed' : 'confirmed'}${v.stilled ? '+stilled' : ''}`
    + `${v.reduce ? '+reduce' : ''}${v.placeholder ? '+placeholder' : ''}`;
const facts = (seat, v) => ({ missing: v.missing, derivation_stamp: seat.server_time ?? '2026-08-23T14:23:14.400Z', stilled: v.stilled });

// ── TODAY's leaves: (kind, member, value) per thing the painter draws, from the real layout ──────
function strings(value, out = []) {
    if (typeof value === 'string' || typeof value === 'number') {
        out.push(String(value));
    } else if (Array.isArray(value)) {
        value.forEach((v) => strings(v, out));
    } else if (value && typeof value === 'object') {
        Object.values(value).forEach((v) => strings(v, out));
    }

    return out;
}

/** A text the layout cut, recovered to the model's full value — the shortest model string it is a prefix of. */
function fullValue(model, member, text, truncated) {
    if (!truncated) {
        return text;
    }

    const prefix = text.slice(0, -1);
    const pool = member === 'unrecognised' ? [...strings(model.unrecognised), ...strings(model.badges)] : strings(model[member]);

    return pool.filter((s) => s.startsWith(prefix)).sort((a, b) => a.length - b.length)[0] ?? text;
}

function todayLeaves(model, placeholder) {
    const { elements, bubble } = deskLayout(model, ctxFor(placeholder));
    const leaves = [];
    const add = (kind, member, value) => leaves.push([kind, member, String(value)]);
    const stoolIx = { 'stool-label': 0, 'stool-type': 0, 'stool-started': 0 };
    const STOOL_FIELD = { 'stool-label': 'label', 'stool-type': 'type', 'stool-started': 'started_at' };

    for (const e of elements) {
        if (typeof e.text === 'string') {
            const member = e.member ?? e.kind;

            if (e.kind in stoolIx) {
                // The stools' columns are cut and share prefixes: each is recovered from ITS stool, by
                // element order; a null field draws no element (§ 5.6).
                const stools = model.side_table.stools;
                const field = STOOL_FIELD[e.kind];

                while (stools[stoolIx[e.kind]][field] === null) {
                    stoolIx[e.kind]++;
                }

                add(e.kind, member, stools[stoolIx[e.kind]++][field]);
                continue;
            }

            if (e.kind === 'unrecognised') {
                model.unrecognised.forEach((l) => add('unrecognised', 'unrecognised', l));
                continue;
            }

            // Criterion 4: each *+N more* tag is a leaf of its own, its text as drawn.
            if (e.kind === 'stool-more') {
                add('stool-more', 'side_table', e.text);
                continue;
            }

            if (e.kind === 'badge-more') {
                add('badge-more', 'badges', e.text);
                continue;
            }

            // § 5.1: with no call open the monitor shows the desk's STATE LINE — the label fact drawn twice.
            if (e.kind === 'monitor-text' && fullValue(model, member, e.text, e.truncated) === model.label_line) {
                add('monitor-text', 'label_line', model.label_line);
                continue;
            }

            // A badge element carries its id.
            add(e.kind, member, e.kind === 'badge' ? e.badge : fullValue(model, member, e.text, e.truncated));

            if (e.kind === 'badge' && e.unrecognised) {
                add('badge.unrecognised', 'unrecognised', `badges: ${e.badge}`);
            }

            continue;
        }

        switch (e.kind) {
            case 'character':
                add('character', 'pose', model.pose);
                add('character.motion', 'held', e.animation?.motion ? 'moving' : 'still');
                break;
            case 'chair':
                add('chair', 'pose', 'empty-chair');

                if (e.unconfirmed) {
                    add('chair.unconfirmed', 'unconfirmed', UNCONFIRMED);
                }

                break;
            case 'desk-sprite':
                add('lighting', 'lighting', e.lighting);
                break;
            case 'monitor':
                add('monitor.lit', 'monitor', `${MONITOR} ${e.lit}`);
                break;
            case 'subagent-marker':
                add('subagent-marker', 'monitor', SUBAGENT_CALL);
                break;
            case 'lag-overlay':
                add('lag-overlay', 'lag', model.lag.line);
                break;
            case 'gauge-bar':
                add('gauge-bar', 'gauge', model.gauge.pct);
                break;
            case 'placeholder':
                // The page's F14 render — outside rule (i).
                add('placeholder', null, 'present');
                break;
            default:
                // The stool (its label/type/start are text leaves) and the side table.
                break;
        }
    }

    if (bubble) {
        // As drawn (cut at MAX_CHARS); the panel's full title extends it.
        add('bubble', 'bubble', bubble.text);

        if (bubble.source) {
            add('bubble.source', 'bubble', bubble.source);
        }

        if (bubble.degraded_note) {
            add('bubble.degraded', 'bubble', bubble.degraded_note);
        }
    }

    return leaves;
}

// ── the walk ─────────────────────────────────────────────────────────────────────────────────────
const ordinals = new Map();
const runs = [];

for (const v of variants) {
    const model = deskModel(v.seat, deskAgeReadout(v.seat, NOW_MS), facts(v.seat, v), { reduce: v.reduce });

    if (model === null) {
        continue;
    }

    const seatKey = `${v.source}\u0000${JSON.stringify(v.seat)}`;

    if (!ordinals.has(v.source)) {
        ordinals.set(v.source, new Map());
    }

    const inSource = ordinals.get(v.source);

    if (!inSource.has(seatKey)) {
        inSource.set(seatKey, inSource.size);
    }

    runs.push({
        key: `${v.source}|${inSource.get(seatKey)}|${v.seat.seat_id}|${variantKey(v)}`,
        seat: v.seat,
        variant: { missing: v.missing, stilled: v.stilled, reduce: v.reduce, placeholder: v.placeholder },
        leaves: todayLeaves(model, v.placeholder),
    });
}

const SNAPSHOT = opt('--snapshot');

if (SNAPSHOT === null) {
    console.error('usage: node desk-leaves-probe.mjs --snapshot <file> --commit <sha>');
    process.exit(2);
}

const keys = runs.map((r) => r.key);

if (new Set(keys).size !== keys.length) {
    console.error('the baseline keys are not unique — refusing to write a baseline two desks share a key in');
    process.exit(2);
}

const seats = {};

for (const r of runs) {
    seats[hashOf(r.seat)] = r.seat;
}

const lines = [
    '{',
    `  "about": ${JSON.stringify('card#11058 B1 — the leaves of the desk as it was before card#11058 B1 redrew it, written by desk-leaves-probe.mjs --snapshot over the real deskLayout() and deskModel() at the commit named below. A record of a desk that no longer exists: it is never regenerated after that commit.')},`,
    `  "commit": ${JSON.stringify(opt('--commit'))},`,
    `  "now": ${JSON.stringify(new Date(NOW_MS).toISOString())},`,
    `  "measurer": { "glyph_w": ${GLYPH_W}, "line_h": ${LINE_H} },`,
    '  "seats": {',
    Object.entries(seats).map(([h, s]) => `    ${JSON.stringify(h)}: ${JSON.stringify(s)}`).join(',\n'),
    '  },',
    '  "desks": {',
    runs.map((r) => `    ${JSON.stringify(r.key)}: ${JSON.stringify({ seat: hashOf(r.seat), variant: r.variant, leaves: r.leaves })}`).join(',\n'),
    '  }',
    '}',
];

writeFileSync(SNAPSHOT, `${lines.join('\n')}\n`);
console.log(`${population.length} seats (${population.filter((p) => p.planted).length} planted) → ${runs.length} desks, `
    + `${runs.reduce((n, r) => n + r.leaves.length, 0)} leaves; ${keys.length} keys, unique`);

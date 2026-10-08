/**
 * card#11058 B1 — THE DESK LEAF GUARD's probe, driven by `TheNewDeskKeepsEveryLeafTest`. `node`, no
 * dependencies, no DOM. It runs the REAL modules of a client tree — the shipped `server/public/js`, or a
 * mutated copy (`--js`) — and prints its findings as JSON.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * TWO RULES (design § 2.1, binding as § 5 B1's criteria).
 *
 *  (i)  EVERY LEAF THE DESK DREW BEFORE card#11058 IS CARRIED BY THE DRILL-DOWN AND BY THE LIST. The
 *       leaves are the committed baseline `desk-leaves/fx-desk-leaves-before-11058.json` — written by this
 *       file's `--snapshot` mode over the real `deskLayout()` of `dev` at 0105953, before B1 changed it.
 *       That mode is not kept here: the layout it read no longer exists, so the record is never regenerated
 *       in place. RECOVERING IT, against durable anchors — 0105953 is on `dev`; the walker is the first
 *       commit of PR #268 (95243c9), which the squash merge orphans and GitHub keeps on the PR's own ref:
 *           git fetch origin pull/268/head
 *           git worktree add --detach /tmp/before 0105953
 *           cd /tmp/before/server/tests/Feature/Floor && mkdir -p desk-leaves
 *           git show 95243c9:server/tests/Feature/Floor/desk-leaves-probe.mjs > desk-leaves-probe.mjs
 *           git show 95243c9:server/tests/Feature/Floor/desk-leaves/fx-desk-leaves-planted.json > desk-leaves/fx-desk-leaves-planted.json
 *           node desk-leaves-probe.mjs --snapshot /tmp/baseline.json --commit 0105953589d4eb734a318c9cd3c39c31a912f5bd
 *       — the planted seats of THAT commit (70; the file here has grown since), and the output is the
 *       committed baseline byte for byte (run and compared with `cmp` on 2026-10-02). Each baseline desk
 *       is replayed from its own recorded seat and variant through the CURRENT `deskModel()`; the panel is
 *       read AS RENDERED — the real `renderDrillDown()` into a fake root holding exactly the
 *       `data-panel-*` slots `floor.blade.php` declares, `hidden` honoured on every slot and every row
 *       (criterion 3), WITHOUT `detail` (§ 9 F11, criterion 11); the list is `deskListLines()`, TYPED by
 *       role (criterion 1). A leaf matches only a home of its own type, as a multiset per desk, one rendered
 *       part consumed per occurrence, never by substring; and the parts a rendered text gives are a
 *       PARTITION — a text contributes itself or pieces of itself, never both (criterion 2).
 *  (ii) THE DESK DRAWS EXACTLY THE RULED SET. The EXPECTED multiset is `EXPECTED`, a table of predicates
 *       one row per element of design § 2.2, each citing its ruling (criterion 9), derived from the model
 *       and the variant alone — never from the layout code; the ACTUAL is read from the real
 *       `placeDesk()` / `placeBubbles()` output through `adapt()` (criterion 7's adapter). Equal both
 *       ways. Over every seat of every fixture file plus the planted seats, as their variants.
 *  Q0   No raw unrecognised string on the desk's model-derived state and badge elements (criterion 10).
 *
 * argv: `--js <tree>`, repeatable — each tree judged in turn (default: the shipped tree) ·
 *       `--untyped-list` (the list read untyped — criterion 2's control only) · `--root <dir>` (the
 *       baseline and the planted seats; default `desk-leaves/`) · `--blade <file>` (the floor page whose
 *       `data-panel-*` slots the fake root holds; default the shipped view) — the last two for controls.
 * stdout: `{ population, baseline, panel_homes, list_homes, rows, results: [{ js, desks, leaves,
 *          findings: [{ rule, … }] }] }`.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { drawsRaw } from './raw-on-desk.mjs';
import { harnessMeasurer } from '../Support/harness-measurer.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const SERVER = join(HERE, '..', '..', '..');
const args = process.argv.slice(2);
const opt = (name) => {
    const i = args.indexOf(name);

    return i >= 0 ? args[i + 1] : null;
};
const TREES = args.flatMap((a, i) => (args[i - 1] === '--js' ? [a] : []));
const UNTYPED_LIST = args.includes('--untyped-list');

// The modules of the tree being judged — re-bound per tree by `load()`, so one process judges many.
let deskModel;
let MORE;
let placeDesk;
let placeBubbles;
let deskListLines;
let drillDownModel;
let renderDrillDown;
let deskAgeReadout;

async function load(js) {
    const mod = async (p) => import(pathToFileURL(join(js, p)).href);

    ({ deskModel } = await mod('desk/desk-render.js'));
    ({ MORE } = await mod('floor/desk-layout.js'));
    ({ placeDesk, placeBubbles } = await mod('floor/scene.js'));
    ({ deskListLines } = await mod('desk/desk-list.js'));
    ({ drillDownModel } = await mod('drilldown/drilldown-model.js'));
    ({ renderDrillDown } = await mod('drilldown/main.js'));
    ({ deskAgeReadout } = await mod('wire/age-readout.js'));
}

let findings = [];
const finding = (f) => findings.push(f);

// ── the baseline (rule i's population), its keys asserted unique AT LOAD (criterion 5) ───────────
const DATA = opt('--root') ?? join(HERE, 'desk-leaves');
const BASELINE_FILE = join(DATA, 'fx-desk-leaves-before-11058.json');
const baselineText = readFileSync(BASELINE_FILE, 'utf8');
const baseline = JSON.parse(baselineText);
// JSON.parse keeps the LAST of two equal keys silently, so the count is read off the text as written.
const keysAsWritten = (baselineText.slice(baselineText.indexOf('"desks": {')).match(/^ {4}"[^"]*": \{"seat":/gm) ?? []).length;

if (keysAsWritten !== Object.keys(baseline.desks).length) {
    finding({ rule: 'baseline', what: `the baseline writes ${keysAsWritten} desk keys and parses to ${Object.keys(baseline.desks).length} — two desks share a key` });
}

const NOW_MS = Date.parse(baseline.now);
// The desk is laid out with the planted fixture's measurer, one width per type role (card#11058 B2); the
// baseline's own `measurer` field is a record of how the desk before B1 was measured: nothing reads it.
const PLANTED = JSON.parse(readFileSync(join(DATA, 'fx-desk-leaves-planted.json'), 'utf8'));
const measure = harnessMeasurer(PLANTED.measurer);
const BOX = { width: 440, height: 228 };
const ctxFor = (placeholder) => ({ box: BOX, measure, character: { w: 18, h: 32 }, sprite: { url: 'desk.png', w: 116, h: 57 }, placeholder, failed: new Set() });
const facts = (seat, v) => ({ missing: v.missing, derivation_stamp: seat.server_time ?? '2026-08-23T14:23:14.400Z', stilled: v.stilled });
const modelOf = (seat, v) => deskModel(seat, deskAgeReadout(seat, NOW_MS), facts(seat, v), { reduce: v.reduce });

// ── rule (ii)'s population: every fixture seat (deduplicated) and every planted seat, as variants ──
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

for (const { source, seat } of PLANTED.seats) {
    population.push({ source, seat, planted: true });
}

const variants = [];

for (const c of population) {
    const flags = c.planted
        ? [false, true].flatMap((missing) => [false, true].flatMap((stilled) => [false, true].map((reduce) => ({ missing, stilled, reduce, placeholder: false }))))
            .concat([{ missing: false, stilled: false, reduce: false, placeholder: true }])
        : [false, true].map((missing) => ({ missing, stilled: false, reduce: false, placeholder: false }));

    for (const f of flags) {
        variants.push({ ...c, variant: f });
    }
}

const variantKey = (v) => `${v.missing ? 'unconfirmed' : 'confirmed'}${v.stilled ? '+stilled' : ''}${v.reduce ? '+reduce' : ''}${v.placeholder ? '+placeholder' : ''}`;

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// RULE (ii) — THE EXPECTED SET, AS DATA (criterion 9). One row per element of design § 2.2:
// `{ kind, when(m, v), value(m, v) → string | list<string>, ruling }`. Every value is derived from the
// model and the variant by the RULINGS' words, never by calling the layout. A row with no ruling fails.
// ═════════════════════════════════════════════════════════════════════════════════════════════════
const TREATMENT = ['config_invalid', 'fold_lag'];
const BADGES_ON_DESK = 2;
const INTERN_CAP = 8;
const rawOf = (m, field) => m.unrecognised.find((u) => u.startsWith(`${field}: `))?.slice(field.length + 2) ?? null;

/** The label line with no raw unrecognised string (Q0 read literally): the non-raw forms of design § 2.2. */
function nonRawLabel(m) {
    if (!m.render_state.recognised) {
        return 'unrecognised';
    }

    if (m.label_line !== null && rawOf(m, 'api_error_type') !== null && m.render_state.value === 'stalled') {
        return 'API error — unrecognised';
    }

    if (m.label_line !== null && rawOf(m, 'unknown_reason') !== null && m.render_state.value === 'unknown') {
        return 'unknown — unrecognised reason';
    }

    return m.label_line;
}

/** The currency label with no raw `activity_state` (Q0): `was: unrecognised (…)`. */
function nonRawCurrency(m) {
    const raw = rawOf(m, 'activity_state');

    return m.currency_label !== null && raw !== null ? m.currency_label.replace(`was: ${raw}`, 'was: unrecognised') : m.currency_label;
}

/** Q1 (B) + Q0: the badge row's ORDER — treatment badges, then recognised badges in the wire's order. */
function badgeOrder(m) {
    const unknown = new Set(m.unrecognised.filter((u) => u.startsWith('badges: ')).map((u) => u.slice(8)));
    const known = m.badges.filter((b) => !unknown.has(b));

    return [...known.filter((b) => TREATMENT.includes(b)), ...known.filter((b) => !TREATMENT.includes(b))];
}

/**
 * N, AS DESIGN § 2.2 DEFINES IT ONCE: the unusual items whose raw form is not drawn on the desk = every
 * line of the model's `unrecognised` list + every recognised badge not in the row.
 */
const flagN = (m) => m.unrecognised.length + badgeOrder(m).length - Math.min(BADGES_ON_DESK, badgeOrder(m).length);

const internsMore = (m) => (m.side_table.more ?? 0) + Math.max(0, m.side_table.stools.length - INTERN_CAP);
const art = (v) => !v.placeholder;

export const EXPECTED = [
    { kind: 'placeholder', when: (m, v) => v.placeholder, value: () => 'present', ruling: '§ 9 F14 — the placeholder stands in for the images only (spec)' },
    { kind: 'character', when: (m, v) => art(v) && m.character, value: (m) => m.pose, ruling: 'Q0 (a) — the character' },
    { kind: 'character.motion', when: (m, v) => art(v) && m.character, value: (m) => (m.held?.motion === true ? 'moving' : 'still'), ruling: 'Q0 (a) — the character; § 7.4 / § 6.2 — motion stops under a treatment' },
    {
        kind: 'chair',
        when: (m, v) => art(v),
        value: (m, v) => (m.character ? 'occupied' : (v.missing ? 'unconfirmed' : 'empty-chair')),
        ruling: '§ 10.6 — the chair behind every creature; § 7.1 — stale / offline\'s empty chair; § 2.3 row 5 — the unconfirmed chair',
    },
    { kind: 'desk-sprite', when: (m, v) => art(v), value: () => 'present', ruling: 'furniture — the desk itself (§ 10.4)' },
    { kind: 'monitor-frame', when: (m, v) => art(v), value: () => 'present', ruling: '§ 10.6 — the monitor\'s frame, the art round the screen' },
    { kind: 'desk-props', when: (m, v) => art(v), value: () => 'present', ruling: '§ 10.6 — the desk props, the desk\'s left third' },
    { kind: 'monitor.lit', when: () => true, value: (m) => m.monitor.lit, ruling: 'Q1 (B) — the monitor; § 7.1 — disabled\'s monitor is off' },
    {
        kind: 'monitor-text',
        when: (m) => m.monitor.lit !== 'off' && (m.action === null ? nonRawLabel(m) : m.monitor.text) !== null,
        value: (m) => (m.action === null ? nonRawLabel(m) : m.monitor.text),
        ruling: 'Q1 (B) — the monitor\'s task text; Q0 — never a raw string',
    },
    { kind: 'plate', when: () => true, value: () => 'present', ruling: 'Q0 (a) — the name; Q2 — the nameplate' },
    { kind: 'nameplate', when: () => true, value: (m) => m.seat_id, ruling: 'Q0 (a) — the name; Q2 — the nameplate' },
    { kind: 'chip', when: () => true, value: (m) => (m.glyph.startsWith('unrecognised') ? 'unrecognised' : m.glyph), ruling: 'Q4 (a) — the chip shows the model\'s glyph; Q0 — the fixed word for a raw one' },
    { kind: 'label', when: (m) => nonRawLabel(m) !== null, value: nonRawLabel, ruling: 'the desk\'s state text — Q1 (B)\'s carrier of the state (FLAGGED for the operator at B1\'s checkpoint); Q0 — non-raw' },
    { kind: 'currency', when: (m) => nonRawCurrency(m) !== null, value: nonRawCurrency, ruling: 'Q0 (a) — warning treatments: § 7.3\'s currency label (FLAGGED for the operator at B1\'s checkpoint)' },
    { kind: 'lag', when: (m) => (m.lag?.line ?? null) !== null, value: (m) => m.lag.line, ruling: 'Q0 (a) — warning treatments: § 7.4\'s lag line (FLAGGED for the operator at B1\'s checkpoint)' },
    { kind: 'lag-overlay', when: (m) => m.lag !== null, value: () => 'present', ruling: 'Q0 (a) — warning treatments: § 7.4\'s hatch' },
    { kind: 'group.lighting', when: () => true, value: (m) => m.lighting, ruling: 'Q0 (a) — warning treatments: § 7.3\'s dimming' },
    { kind: 'gauge-bar', when: (m) => m.gauge.reported, value: (m) => String(m.gauge.bar), ruling: 'Q1 (B) — the context bar' },
    { kind: 'gauge-pct', when: (m) => m.gauge.reported, value: (m) => m.gauge.pct, ruling: 'Q1 (B) — the context % (the model\'s own string, criterion 8)' },
    { kind: 'badge', when: (m) => badgeOrder(m).length > 0, value: (m) => badgeOrder(m).slice(0, BADGES_ON_DESK), ruling: 'Q1 (B) — up to two badges, treatment first; Q0 (a) — the two treatment badges; no unrecognised id' },
    { kind: 'flag', when: (m) => flagN(m) > 0, value: (m) => `⚠ +${flagN(m)}`, ruling: 'Q0 (a) — one flag ⚠ +N for anything else unusual (N as design § 2.2 defines it)' },
    {
        kind: 'side-table',
        when: (m, v) => art(v),
        value: (m) => String(Math.max(4, Math.min(m.side_table.stools.length, INTERN_CAP))),
        ruling: '§ 10.6 — the side table at every desk with art, at least four seats and one per intern past them (§ 12)',
    },
    { kind: 'stool', when: (m) => m.side_table.stools.length > 0, value: (m) => m.side_table.stools.slice(0, INTERN_CAP).map((s) => (s.untitled ? 'untitled' : 'titled')), ruling: 'Q1 (B) — the intern sprites; Q3; § 8 — none hidden' },
    { kind: 'stool-more', when: (m) => internsMore(m) > 0, value: (m) => MORE(internsMore(m)), ruling: '§ 8 — the +N more tag past the cap (criterion 4)' },
    { kind: 'quiet-age', when: (m) => m.quiet_age !== null, value: (m) => m.quiet_age, ruling: 'Q5 (b) — the quiet age stays on the desk' },
    {
        kind: 'facts-plate',
        when: (m) => nonRawLabel(m) !== null || nonRawCurrency(m) !== null || (m.lag?.line ?? null) !== null || m.gauge.reported
            || badgeOrder(m).length > 0 || flagN(m) > 0 || m.quiet_age !== null,
        value: () => 'present',
        ruling: 'card#11468 — the operator\'s "A is fine" (2026-10-07): the facts column on one plate, drawn when the desk carries a row of it',
    },
    { kind: 'bubble', when: (m) => m.character && m.bubble !== null, value: (m) => m.bubble.text, ruling: 'Q0 (a) — the task bubble; § 5.1 rules 1–6' },
    {
        kind: 'bubble.second',
        when: (m) => m.character && m.bubble !== null && (m.bubble.source !== null || m.bubble.degraded_note !== null),
        value: (m) => [m.bubble.source, m.bubble.degraded_note].filter((s) => s !== null && s !== undefined).join(' · '),
        ruling: 'Q0 (a) — the task bubble; § 5.1 — its source and degraded note',
    },
];

for (const row of EXPECTED) {
    if (typeof row.ruling !== 'string' || row.ruling.trim() === '') {
        finding({ rule: 'ruling', what: `the expected-set row \`${row.kind}\` cites no ruling` });
    }
}

const RULING = Object.fromEntries(EXPECTED.map((r) => [r.kind, r.ruling]));

/** The ACTUAL multiset, read from the placed desk as the painter is handed it — criterion 7's adapter. */
function adapt(desk) {
    const out = [];
    const add = (kind, value) => out.push([kind, String(value)]);

    for (const e of desk.elements) {
        switch (e.kind) {
            case 'character':
                add('character', e.pose);
                // A null `animation` — `desk.held` null — is a desk drawn still.
                add('character.motion', e.animation?.motion === true ? 'moving' : 'still');
                break;
            case 'chair':
                add('chair', e.occupied ? 'occupied' : (e.unconfirmed ? 'unconfirmed' : 'empty-chair'));
                break;
            case 'side-table':
                add('side-table', e.seats);
                break;
            case 'desk-sprite':
            case 'plate':
            case 'facts-plate':
            case 'lag-overlay':
            case 'placeholder':
                add(e.kind, 'present');
                break;
            case 'monitor':
                add('monitor.lit', e.lit);
                break;
            case 'badge':
                // The id is the value; the text as drawn rides along for the cut check below.
                out.push(['badge', String(e.badge), e.text]);
                break;
            case 'gauge-bar':
                add('gauge-bar', e.pct);
                break;
            case 'stool':
                add('stool', e.untitled ? 'untitled' : 'titled');
                break;
            default:
                // monitor-text, label, currency, lag, gauge-pct, flag, stool-more, quiet-age, nameplate,
                // chip — and anything else the layout draws, which the expected set then names extra.
                add(e.kind, typeof e.text === 'string' ? e.text : 'present');
        }
    }

    // The group's lighting: the placed desk's own, which `painter.js` classes the whole desk by.
    add('group.lighting', desk.lighting);

    if (desk.bubble !== null) {
        add('bubble', desk.bubble.text);

        if (desk.bubble.second !== null) {
            add('bubble.second', desk.bubble.second);
        }
    }

    return out;
}

/** A value drawn cut (ends with the mark) is the expected value it is a prefix of. */
const cutOf = (drawn, value) => drawn === value || (drawn.endsWith('…') && drawn.length > 1 && value.startsWith(drawn.slice(0, -1)));

/**
 * The width design § 2.2 gives each text kind at this box, derived from the box and the art column
 * (216 + 4) — the guard's own reading of the table, not the layout's constants. A text drawn CUT whose
 * whole value measures within this width was cut for nothing, and is a finding (a cut is owed only to a
 * value that does not fit).
 */
const FACTS_W = BOX.width - (216 + 4);

/** The type role each text kind is measured in — the nameplate is the name role (Q2), the monitor's text the screen role (FLOOR.md § 10.6). */
const roleOf = (kind) => ({ nameplate: 'name', 'monitor-text': 'screen' }[kind] ?? 'fact');
const TEXT_W = {
    nameplate: 148, 'monitor-text': 82, chip: 160 - 12, badge: 108 - 6, label: FACTS_W, currency: FACTS_W, lag: FACTS_W,
    'gauge-pct': 60, flag: FACTS_W, 'stool-more': FACTS_W, 'quiet-age': FACTS_W, bubble: BOX.width - 6, 'bubble.second': BOX.width - 6,
};

function ruleTwo(where, m, v, desk) {
    const expected = [];

    for (const row of EXPECTED) {
        if (row.when(m, v)) {
            const values = row.value(m, v);

            for (const value of Array.isArray(values) ? values : [values]) {
                expected.push([row.kind, String(value)]);
            }
        }
    }

    const actual = adapt(desk);
    const used = new Array(actual.length).fill(false);

    for (const [kind, value] of expected) {
        const i = actual.findIndex(([k, a], j) => !used[j] && k === kind && cutOf(a, value));

        if (i < 0) {
            finding({ rule: 'ii-missing', kind, value, where, ruling: RULING[kind] });
            continue;
        }

        used[i] = true;

        const drawn = actual[i][2] ?? actual[i][1];

        if (drawn !== value && drawn.endsWith('…') && TEXT_W[kind] !== undefined && measure(value, roleOf(kind)).w <= TEXT_W[kind]) {
            finding({ rule: 'ii-cut-fits', kind, value, where, drawn, ruling: RULING[kind] });
        }
    }

    actual.forEach(([kind, value], j) => {
        if (!used[j]) {
            finding({ rule: 'ii-extra', kind, value, where, ruling: RULING[kind] ?? 'none — no ruled element draws this' });
        }
    });

    // Q0 (criterion 10): no element derived from the model's state or badges carries a raw value of the
    // model's `unrecognised` list — `raw-on-desk.mjs`'s predicate, the one copy, cut texts included. The
    // nameplate and the bubble are outside it.
    const raws = m.unrecognised.map((u) => u.slice(u.indexOf(': ') + 2));

    for (const e of desk.elements) {
        if (!['chip', 'label', 'currency', 'monitor-text', 'badge', 'flag'].includes(e.kind) || typeof e.text !== 'string') {
            continue;
        }

        for (const raw of raws) {
            if (drawsRaw(e.text, e.truncated, raw)) {
                finding({ rule: 'no-raw', kind: e.kind, value: e.text, where, raw, ruling: 'Q0 (a) — no raw unrecognised string on the desk' });
            }
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════
// RULE (i) — the panel as rendered, the list as typed lines, a partition of their parts.
// ═════════════════════════════════════════════════════════════════════════════════════════════════
const BLADE = opt('--blade') ?? join(SERVER, 'resources', 'views', 'floor.blade.php');
const SLOTS = new Set(readFileSync(BLADE, 'utf8').match(/data-panel-[a-z-]+/g) ?? []);

/**
 * The fake page: exactly the slots `floor.blade.php` declares (`null` for any other, as a real
 * `querySelector` answers), each with a `parentElement` for `putLabelled()`.
 */
function fakeRoot() {
    const nodes = new Map();
    const element = () => ({ textContent: '', hidden: false, children: [], dataset: {} });
    const slot = () => ({
        ...element(),
        attrs: {},
        parentElement: { hidden: false },
        setAttribute(k, v) { this.attrs[k] = v; },
        removeAttribute(k) { delete this.attrs[k]; },
        replaceChildren(...c) { this.children = c; },
    });

    return {
        nodes,
        querySelector(sel) {
            const name = /^\[(data-panel-[a-z-]+)\]$/.exec(sel)?.[1] ?? null;

            if (name === null || !SLOTS.has(name)) {
                return null;
            }

            if (!nodes.has(name)) {
                nodes.set(name, slot());
            }

            return nodes.get(name);
        },
        ownerDocument: { createElement: () => element() },
    };
}

/**
 * Every part a rendered text can give, each with its SPAN in the text: the text whole, the pieces of
 * each split on ` — `, then ` · `, then `, ` and the first `: ` (the head, the tail and the tail's
 * `, ` pieces). Two parts of one text OVERLAP when their spans do — a piece overlaps the whole it is
 * cut from — and `consume()` never takes two overlapping parts of one text: the parts a text gives are
 * a partition of it (criterion 2).
 */
function parts(text) {
    const out = [{ s: 0, e: text.length }];
    const split = (s, e, sep, push = true) => {
        const pieces = [];
        let at = s;

        for (;;) {
            const i = text.indexOf(sep, at);

            if (i < 0 || i >= e) {
                pieces.push({ s: at, e });
                break;
            }

            pieces.push({ s: at, e: i });
            at = i + sep.length;
        }

        if (pieces.length > 1 && push) {
            out.push(...pieces);
        }

        return pieces;
    };

    for (const a of split(0, text.length, ' — ')) {
        for (const b of split(a.s, a.e, ' · ')) {
            split(b.s, b.e, ', ');

            const i = text.indexOf(': ', b.s);

            if (i > b.s && i < b.e) {
                out.push({ s: b.s, e: i }, { s: i + 2, e: b.e });
                split(i + 2, b.e, ', ');
            }
        }
    }

    return out.filter((p) => p.e > p.s).map((p) => ({ ...p, part: text.slice(p.s, p.e) }));
}

/** One rendered text of a home: its parts, and the spans consumed so far. */
const textOf = (home, text) => ({ home, text, parts: parts(text), taken: [] });

/**
 * THE PARTITION's LOCK. ⚠ This is the line criterion 2's control plants away: without it a text gives
 * both itself and its pieces, which is how the design-time `parts()` let one list line carry two leaves.
 */
const overlaps = (t, p) => t.taken.some((q) => q.s < p.e && p.s < q.e);

/**
 * Take one part matching `value` from `texts` in `homes`, never overlapping a part already taken —
 * `exact`ly equal, or (`extend`) a value the desk drew CUT, carried by a part that extends it.
 */
function take(texts, homes, value, extend = false) {
    for (const t of texts) {
        if (homes !== null && !homes.includes(t.home)) {
            continue;
        }

        for (const p of t.parts) {
            const same = extend
                ? value.endsWith('…') && value.length > 1 && p.part.startsWith(value.slice(0, -1))
                : p.part === value;

            if (same && !overlaps(t, p)) {
                t.taken.push(p);

                return true;
            }
        }
    }

    return false;
}

/**
 * A leaf carried whole, or piece by piece on the dash it was joined with — all or nothing; an exact
 * reading is tried before a cut one, so a cut value never takes a longer part an exact one fits.
 */
function carried(texts, homes, value) {
    const pieces = value.split(' — ');

    for (const extend of [false, true]) {
        if (take(texts, homes, value, extend)) {
            return true;
        }

        if (pieces.length < 2) {
            continue;
        }

        const saved = texts.map((t) => t.taken.length);

        if (pieces.every((p) => take(texts, homes, p, extend && p === pieces[pieces.length - 1]))) {
            return true;
        }

        texts.forEach((t, i) => t.taken.splice(saved[i]));
    }

    return false;
}

/** The partition, asserted directly over every rendered text after matching (criterion 2). */
function partitionDefects(texts, where) {
    for (const t of texts) {
        for (let i = 0; i < t.taken.length; i++) {
            for (let j = i + 1; j < t.taken.length; j++) {
                const a = t.taken[i];
                const b = t.taken[j];

                if (a.s < b.e && b.s < a.e) {
                    finding({ rule: 'partition', what: `«${t.text.slice(0, 60)}» gave both «${a.part.slice(0, 40)}» and «${b.part.slice(0, 40)}»`, where, home: t.home });
                }
            }
        }
    }
}

/**
 * THE HOMES, TYPED (criteria 1, 6). Each leaf — by its kind where the kind decides, else its member —
 * names the panel slots and the list roles that may carry it. A slot named here and not declared on
 * the page is a finding (criterion 11).
 */
const HOMES_BY_MEMBER = {
    nameplate: { panel: ['seat'], list: ['nameplate'] },
    pose: { panel: ['desk'], list: ['render'] },
    held: { panel: ['desk'], list: ['render'] },
    lighting: { panel: ['desk'], list: ['render'] },
    unconfirmed: { panel: ['desk'], list: ['render'] },
    glyph: { panel: ['desk'], list: ['render'] },
    label_line: { panel: ['line'], list: ['label_line'] },
    currency_label: { panel: ['currency'], list: ['currency_label'] },
    lag: { panel: ['lag', 'derivation-asof'], list: ['lag'] },
    config_note: { panel: ['note'], list: ['config_note'] },
    monitor: { panel: ['monitor', 'action'], list: ['monitor'] },
    open_calls: { panel: ['open-calls'], list: ['open_calls'] },
    action: { panel: ['action-started', 'action-elapsed'], list: ['action'] },
    quiet_age: { panel: ['quiet'], list: ['quiet_age'] },
    last_kind: { panel: ['last-kind'], list: ['last'] },
    last_event_time: { panel: ['last-kind'], list: ['last'] },
    gauge: { panel: ['context', 'context-tokens', 'context-source', 'context-age'], list: ['gauge'] },
    model_label: { panel: ['model'], list: ['model_label'] },
    badges: { panel: ['badges'], list: ['badges'] },
    oldest_badge_since: { panel: ['badges-since'], list: ['oldest_badge_since'] },
    side_table: { panel: ['interns'], list: ['stool'] },
    bubble: { panel: ['task', 'task-ref', 'task-source', 'task-degraded'], list: ['bubble'] },
};

const HOMES_BY_KIND = {
    // An unrecognised badge's id: its row in the panel's badges block, its id on the list's badges line.
    badge: { panel: ['badges'], list: ['badges'] },
    // An unrecognised value's `field: value` line: the panel's unrecognised rows, the list's unrecognised line.
    unrecognised: { panel: ['unrecognised'], list: ['unrecognised'] },
    'badge.unrecognised': { panel: ['unrecognised'], list: ['unrecognised'] },
    // § 8's tag: the panel's open count (checked as a count, below) and the list's *+N more* line.
    'stool-more': { panel: ['interns-open'], list: ['stool-more'] },
};

const homesOf = (kind, member) => HOMES_BY_KIND[kind] ?? HOMES_BY_MEMBER[member];
export const PANEL_HOMES = [...new Set([...Object.values(HOMES_BY_MEMBER), ...Object.values(HOMES_BY_KIND)].flatMap((h) => h.panel))].sort();
export const LIST_HOMES = [...new Set([...Object.values(HOMES_BY_MEMBER), ...Object.values(HOMES_BY_KIND)].flatMap((h) => h.list))].sort();

for (const slot of PANEL_HOMES) {
    if (!SLOTS.has(`data-panel-${slot}`)) {
        finding({ rule: 'slots', what: `the guard types leaves to [data-panel-${slot}], which floor.blade.php does not declare` });
    }
}

/** The panel AS RENDERED, without `detail` (§ 9 F11): every visible slot's text and every visible row's. */
function renderedPanel(seat, v, withDetail) {
    const body = { ...structuredClone(seat), server_time: '2026-08-23T14:23:14.400Z' };

    if (!withDetail) {
        delete body.detail;
    }

    const pm = drillDownModel(body, null, {
        now_ms: NOW_MS,
        detail_pending: false,
        detail_failure: withDetail ? null : { status: 503 },
        floor: seat.install_id,
        missing: v.missing,
        stilled: v.stilled,
        reduce: v.reduce,
    });
    const root = fakeRoot();

    renderDrillDown(root, pm);

    const texts = [];

    for (const [name, el] of root.nodes) {
        const home = name.slice('data-panel-'.length);

        if (el.hidden || el.parentElement.hidden) {
            continue;
        }

        if (el.children.length > 0) {
            // Criterion 3: a hidden row is not read.
            el.children.filter((li) => !li.hidden && li.textContent !== '').forEach((li) => texts.push(textOf(home, li.textContent)));
        } else if (el.textContent !== '') {
            texts.push(textOf(home, el.textContent));
        }
    }

    return texts;
}

function renderedList(m) {
    return deskListLines(m).map((line) => textOf(line.role, line.text));
}

function ruleOne(key, entry) {
    const seat = baseline.seats[entry.seat];
    const v = entry.variant;
    const m = modelOf(seat, v);
    const where = key;

    if (m === null) {
        finding({ rule: 'baseline', what: 'the baseline desk has no desk model now', where });

        return 0;
    }

    // The leaves as a multiset per (member, value): one fact drawn by two kinds (the label row and the
    // monitor with no call open) is one leaf, its multiplicity the largest count of one kind.
    const groups = new Map();
    let stoolTag = null;
    const drawnBadges = [];
    let badgeTag = false;

    for (const [kind, member, value] of entry.leaves) {
        if (kind === 'placeholder') {
            continue;
        }

        if (kind === 'stool-more') {
            stoolTag = value;
            continue;
        }

        if (kind === 'badge-more') {
            badgeTag = true;
            continue;
        }

        if (kind === 'badge') {
            drawnBadges.push(value);
        }

        const homes = homesOf(kind, member);
        const k = `${JSON.stringify(homes)}\u0000${member}\u0000${value}`;
        const g = groups.get(k) ?? { kinds: new Map(), member, value, homes, kind };

        g.kinds.set(kind, (g.kinds.get(kind) ?? 0) + 1);
        groups.set(k, g);
    }

    // Criterion 4: the badge row's *+N more* is carried when every badge it counted is — each badge past
    // the row is a leaf of its own, owed by the panel's full badge list and the list's `badges:` line.
    if (badgeTag) {
        const left = [...drawnBadges];

        for (const id of m.badges) {
            const i = left.indexOf(id);

            if (i >= 0) {
                left.splice(i, 1);
                continue;
            }

            const k = `badge-more\u0000badges\u0000${id}`;
            const g = groups.get(k) ?? { kinds: new Map(), member: 'badges', value: id, homes: HOMES_BY_MEMBER.badges, kind: 'badge-more' };

            g.kinds.set('badge-more', (g.kinds.get('badge-more') ?? 0) + 1);
            groups.set(k, g);
        }
    }

    const panel = renderedPanel(seat, v, false);
    const list = renderedList(m);
    // Longest first, so a short value never takes a part a longer one needed.
    const ordered = [...groups.values()].sort((a, b) => b.value.length - a.value.length);
    let n = 0;

    for (const g of ordered) {
        const times = Math.max(...g.kinds.values());
        const kinds = [...g.kinds.keys()].join('/');

        for (let k = 0; k < times; k++) {
            n++;

            if (g.homes === undefined) {
                finding({ rule: 'i-untyped', kind: kinds, member: g.member, value: g.value, where });
                continue;
            }

            if (!carried(panel, g.homes.panel, g.value)) {
                const withDetail = carried(renderedPanel(seat, v, true), g.homes.panel, g.value);

                finding({ rule: withDetail ? 'i-panel-without-detail' : 'i-panel', kind: kinds, member: g.member, value: g.value, homes: g.homes.panel, where });
            }

            if (!carried(list, UNTYPED_LIST ? null : g.homes.list, g.value)) {
                finding({ rule: 'i-list', kind: kinds, member: g.member, value: g.value, homes: g.homes.list, where });
            }
        }
    }

    // Criterion 4: § 8's *+N more* — in the list as its own line, in the panel as the open count the
    // tag and the drawn stools add up to.
    if (stoolTag !== null) {
        n++;

        const count = Number(/^\+(\d+) more$/.exec(stoolTag)?.[1] ?? NaN);
        const stools = entry.leaves.filter(([kind]) => kind === 'stool-label').length;

        if (!take(panel, ['interns-open'], String(stools + count))) {
            finding({ rule: 'i-panel', kind: 'stool-more', member: 'side_table', value: stoolTag, homes: ['interns-open'], where });
        }

        if (!take(list, UNTYPED_LIST ? null : ['stool-more'], stoolTag)) {
            finding({ rule: 'i-list', kind: 'stool-more', member: 'side_table', value: stoolTag, homes: ['stool-more'], where });
        }
    }

    partitionDefects(panel, where);
    partitionDefects(list, where);

    return n;
}

// ── run: every tree named (default: the shipped one), each judged from the same population ───────
const staticFindings = findings;
const results = [];

for (const js of TREES.length > 0 ? TREES : [join(SERVER, 'public', 'js')]) {
    findings = [...staticFindings];
    await load(js);
    results.push({ js, ...judge() });
}

console.log(JSON.stringify({
    population: {
        fixture_seats: population.filter((p) => !p.planted).length,
        planted: population.filter((p) => p.planted).length,
    },
    baseline: { desks: Object.keys(baseline.desks).length, commit: baseline.commit },
    panel_homes: PANEL_HOMES,
    list_homes: LIST_HOMES,
    rows: EXPECTED.map((r) => ({ kind: r.kind, ruling: r.ruling })),
    results,
}));

/** Both rules over one tree. */
function judge() {
    let leaves = 0;

    for (const [key, entry] of Object.entries(baseline.desks)) {
        leaves += ruleOne(key, entry);
    }

    const placed = [];

    for (const c of variants) {
        const m = modelOf(c.seat, c.variant);

        if (m === null) {
            continue;
        }

        const key = `${c.source} · ${c.seat.seat_id} (${variantKey(c.variant)})`;
        const desk = placeDesk(m, key, c.seat.install_id, null, { x: 0, y: 0 }, ctxFor(c.variant.placeholder), {});

        placeBubbles([desk], measure, BOX.width, [key]);
        placed.push({ key, m, v: c.variant, desk });
    }

    for (const p of placed) {
        ruleTwo(p.key, p.m, p.v, p.desk);
    }

    return { desks: placed.length, leaves, findings };
}

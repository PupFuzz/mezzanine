#!/usr/bin/env node
// Self-test for the ratified floor-preview artifact (`docs/design/floor-preview/`).
//
// Node, no dependencies, no network, no browser. Run:
//   node tools/design/floor-preview.selftest.mjs
//   node tools/design/floor-preview.selftest.mjs --html <path>   # judge another revision
//
// WHAT IT ASSERTS. `docs/design/FLOOR.md` § 7.1 defines TEN `render_state` members and gives
// every one of them a render; § 5.4 and § 9 F9 require a member the client does NOT know to
// render as explicitly unrecognised, carrying its raw string, never mapped to the nearest known
// member and never crashing (AT-D3-11). The preview once carried FOUR independent lookups keyed
// on `render_state`, each covering only the four members the frozen sample fleet contains, so a
// seat in any of the other six threw a TypeError out of an array destructure or emitted
// `undefined` into the DOM (card#7943). This suite holds the repair in three layers:
//
//   1. THE MEMBER SET IS NOT STORED HERE. It is re-derived from FLOOR.md § 7.1's own table on
//      every run and compared to the artifact's `RENDER_STATES` in order, so a member added to
//      D3 with no row in the artifact reds this gate rather than going silently unrendered.
//   2. THE TABLE'S SHAPE. Every row carries every cell, names a chip class the artifact's own
//      stylesheet defines, and declares whether its desk draws a character — so a row that
//      omits a cell is loud rather than an `undefined` at a render site.
//   3. THE BEHAVIOUR. A probe seat is driven through all ten members, through the null/absent
//      edges § 7.1 and § 5.6 state for them, and through eight values that are NOT members at
//      all, and the DOM the artifact produces is read back each time.
//
// EVERY CHECK HERE CAN FAIL, AND § 6 PROVES IT rather than asserting it. The artifact's source
// is mutated back into the pre-fix shape — the unrecognised fallback removed and the six
// non-sample rows deleted — and the whole behavioural sweep is REQUIRED to go red under it. The
// two derivations that are pure comparison get their own discriminating controls.
//
// ⚠ WHAT THIS IS NOT EVIDENCE ABOUT. There is no browser here and no HTML parser: the DOM below
// is a stub that records what the artifact's own code writes into it, so what is asserted is the
// RULE — the attribute values, the class names and the strings — and never the picture. Nothing
// here has been laid out, painted, or seen. A green run says the artifact emits the right values;
// it says nothing about how any of it LOOKS, and it cannot: `filter`, `opacity` and every colour
// in the table are read by a renderer that never ran. jsdom would not close that gap either —
// it does no layout and no paint — so the gap is named rather than papered over.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const argv = process.argv.slice(2);
const htmlArgIdx = argv.indexOf('--html');
const HTML_PATH = htmlArgIdx >= 0
  ? argv[htmlArgIdx + 1]
  : join(REPO, 'docs', 'design', 'floor-preview', 'floor-preview.html');

let failures = 0;
/** @param {boolean} cond @param {string} what */
function check(cond, what) {
  if (cond) { console.log(`  ok   ${what}`); } else { console.log(`  FAIL ${what}`); failures++; }
}
/** @param {string} s */
function section(s) { console.log(`\n${s}`); }

// ---------------------------------------------------------------------------------------------
// The artifact, and the script inside it
// ---------------------------------------------------------------------------------------------
const HTML = readFileSync(HTML_PATH, 'utf8');
const scriptMatch = HTML.match(/<script>\n([\s\S]*?)\n<\/script>/);
if (!scriptMatch) {
  console.log(`  FAIL no <script> block found in ${HTML_PATH} — nothing was measured`);
  process.exit(1);
}
const SCRIPT = scriptMatch[1];

// The names the suite reaches for. A top-level `const` in a vm script is not a property of the
// context's global object, so the probe is appended to the source and resolves each name through
// a direct `eval` — a name that does not exist in the revision under test is simply absent from
// the probe, which is how this suite can be pointed at a pre-fix revision and go red rather than
// crash on the way in.
const PROBE_NAMES = [
  'STATE_RENDER', 'UNRECOGNISED_RENDER', 'RENDER_STATES', 'UNKNOWN_REASONS',
  'isRenderState', 'renderFor', 'chipText', 'poseOf', 'hasCharacter', 'labelFor',
  'render', 'FLEET', 'FLOORS',
];
const PROBE_EPILOGUE = `
;globalThis.__probe = {};
for (const __n of ${JSON.stringify(PROBE_NAMES)}) { try { globalThis.__probe[__n] = eval(__n); } catch (e) { /* absent in this revision */ } }
`;

/** The stub DOM. It records; it does not lay out, parse or paint. */
function makeDom() {
  const escapeText = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const byId = new Map();
  class El {
    constructor(tag) {
      this.tagName = tag; this.className = ''; this.children = []; this.attrs = {};
      this.dataset = {}; this.title = ''; this.offsetWidth = 0;
      this._html = ''; this._text = null;
      this.style = { setProperty(k, v) { this[k] = v; } };
      const owned = new Set();
      this.classList = { add: (c) => owned.add(c), remove: (c) => owned.delete(c), contains: (c) => owned.has(c) };
    }
    // `innerHTML` is assigned markup; `textContent` is assigned TEXT and read back as markup,
    // which is exactly what the artifact's `esc()` helper relies on. Getting that escaping right
    // is load-bearing: it is how an `undefined` reaches the page as the four visible characters.
    set innerHTML(v) { this._html = String(v); this.children = []; }
    get innerHTML() { return this._html; }
    set textContent(v) { this._text = String(v); this._html = escapeText(v); }
    get textContent() { return this._text; }
    appendChild(c) { this.children.push(c); return c; }
    addEventListener() { }
    setAttribute(k, v) { this.attrs[k] = String(v); }
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; }
    getBoundingClientRect() { return { width: 1240, height: 689, left: 0, top: 0 }; }
    querySelectorAll() { return []; }
    scrollIntoView() { }
    closest() { return null; }
    setPointerCapture() { }
  }
  for (const id of ['room', 'world', 'drill', 'floors', 'feeddot', 'feedstatus', 'zin', 'zout', 'zfit', 'zall']) {
    const e = new El('div'); e.id = id; byId.set(id, e);
  }
  const document = {
    createElement: (t) => new El(t),
    getElementById: (id) => byId.get(id) || null,
    querySelectorAll: () => [],
  };
  return { document, byId };
}

/** Evaluate a revision of the artifact's script in a fresh context. */
function load(source) {
  const dom = makeDom();
  const sandbox = {
    document: dom.document, TextEncoder, console,
    // Real timers would keep the process alive and would let the simulated feed step the room
    // while the suite is reading it. Nothing here needs either.
    setInterval: () => 0, clearInterval: () => { }, setTimeout: () => 0,
  };
  vm.createContext(sandbox);
  vm.runInContext(source + PROBE_EPILOGUE, sandbox, { filename: 'floor-preview.html<script>' });
  return { dom, probe: sandbox.__probe, world: dom.byId.get('world') };
}

// ---------------------------------------------------------------------------------------------
// 1. The member set, re-derived from FLOOR.md § 7.1 rather than stored here
// ---------------------------------------------------------------------------------------------
section('1. the ten render_state members, re-derived from FLOOR.md § 7.1');
const FLOOR_MD = readFileSync(join(REPO, 'docs', 'design', 'FLOOR.md'), 'utf8');
const s71 = FLOOR_MD.slice(
  FLOOR_MD.indexOf('### 7.1 The render per state'),
  FLOOR_MD.indexOf('### 7.2 Badges'),
);
const reasonTableAt = s71.indexOf('| `unknown_reason` | Sentence |');
// The rows BELOW the header separator, and only while they stay contiguous — the header row's
// own first cell is the field's name (`| \`render_state\` | Desk | …`) and would otherwise read
// as an eleventh member, which is the shape that makes a parsed count agree with nothing.
const firstCell = (block) => {
  const lines = block.split('\n');
  const sep = lines.findIndex((l) => /^\|\s*-{3,}/.test(l));
  if (sep < 0) return [];
  const out = [];
  for (const line of lines.slice(sep + 1)) {
    if (!line.startsWith('|')) break;
    const m = line.match(/^\|\s*`([a-z_]+)`\s*\|/);
    if (!m) break;
    out.push(m[1]);
  }
  return out;
};
const DOC_STATES = firstCell(s71.slice(0, reasonTableAt));
const DOC_REASONS = firstCell(s71.slice(reasonTableAt));
// An empty parse is a measurement that never happened. These two counts are D3's own claims
// ("`render_state` has **ten** members", "The seven `unknown_reason` members"), so a parse that
// silently returned nothing cannot pass for agreement.
check(DOC_STATES.length === 10, `parsed ${DOC_STATES.length} render_state members from § 7.1 — D3 says ten`);
check(DOC_REASONS.length === 7, `parsed ${DOC_REASONS.length} unknown_reason members from § 7.1 — D3 says seven`);

const P = load(SCRIPT).probe;
const eq = (a, b) => Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((v, i) => v === b[i]);
check(Array.isArray(P.RENDER_STATES) && eq(P.RENDER_STATES, DOC_STATES),
  `the artifact's RENDER_STATES is § 7.1's ten in § 7.1's order: ${JSON.stringify(P.RENDER_STATES)}`);
check(!!P.STATE_RENDER && eq(Object.keys(P.STATE_RENDER), DOC_STATES),
  'the render table has one row per § 7.1 member, in that order — the member set is written ONCE');
check(!!P.UNKNOWN_REASONS
  && eq(Object.keys(P.UNKNOWN_REASONS).slice().sort(), DOC_REASONS.slice().sort()),
  'the unknown_reason sentences cover § 7.1\'s seven members exactly');

// ---------------------------------------------------------------------------------------------
// 2. The table's shape — a row that omits a cell must be loud, not an `undefined` downstream
// ---------------------------------------------------------------------------------------------
section('2. every row carries every cell, and every chip class is defined in the stylesheet');
const CELLS = ['desk', 'pose', 'marker', 'chip', 'screen', 'lit', 'current', 'label'];
const DESKS = ['character', 'empty-chair', 'cleared-desk'];
const rows = P.STATE_RENDER
  ? Object.entries(P.STATE_RENDER).concat([['(unrecognised)', P.UNRECOGNISED_RENDER]])
  : [];
check(rows.length === 11, `${rows.length} rows to judge — the ten members plus § 5.4's unrecognised render`);
const chipClasses = [];
for (const [name, row] of rows) {
  const missing = CELLS.filter((c) => !row || !Object.prototype.hasOwnProperty.call(row, c));
  check(missing.length === 0, `${name}: carries all ${CELLS.length} cells${missing.length ? ` — missing ${missing.join(', ')}` : ''}`);
  if (missing.length) continue;
  check(DESKS.includes(row.desk), `${name}: desk "${row.desk}" is one of § 7.1's three desk renders`);
  check((row.pose === null) === (row.desk !== 'character'),
    `${name}: has a pose iff it draws a character — § 7.5, "an empty chair has no pose at all"`);
  check(typeof row.label === 'function', `${name}: its label line is a function of the seat`);
  check(typeof row.lit === 'boolean' && typeof row.current === 'boolean', `${name}: lit/current are booleans`);
  check(/^#[0-9a-f]{6}$/i.test(row.screen), `${name}: screen tint "${row.screen}" is a colour`);
  check(HTML.includes(`.${row.chip}{`), `${name}: chip class .${row.chip} is defined in the artifact's own stylesheet`);
  if (row.marker !== null) {
    check(Array.isArray(row.marker) && row.marker.length === 2 && HTML.includes(`.mk.${row.marker[1]}{`),
      `${name}: marker ${JSON.stringify(row.marker)} names a .mk class the stylesheet defines`);
  }
  chipClasses.push(row.chip);
}
check(new Set(chipClasses).size === chipClasses.length,
  'no two rows share a chip class — a state cannot be mistaken for another at a glance');

// ---------------------------------------------------------------------------------------------
// 3. The behavioural sweep
// ---------------------------------------------------------------------------------------------
const PROBE_SEAT = 'probe-seat';
const BASE = {
  desk: 'cabin', seat: PROBE_SEAT, link_state: 'live', action: null, open_calls: 0, open_turn: false,
  context: null, model_label: null, task: { title: 'a task', source: 'issue', ref: '#1' }, subagents: [],
  activity: { last_kind: 'turn.end', last_event_time: '14:24:50' },
};
// One well-formed seat per member: the fields D2 sends a seat IN that state, so what is measured
// is the state's render and not a fixture's holes.
const MEMBER_CASES = [
  ['working', { action: { tool_name: 'Bash', descriptor: 'Bash: composer test', started_at: '14:26:03', running: '2m 59s' }, open_calls: 1, open_turn: true }],
  ['idle', { quiet_for: '4m 12s' }],
  ['blocked', { blocked_since: '14:21', open_turn: true }],
  ['stalled', { api_error_type: 'rate_limit' }],
  ['unknown', { unknown_reason: 'turn_killed_by_clear' }],
  ['catching_up', { activity: { last_kind: 'tool.start', last_event_time: '12:47:10' } }],
  ['stale', { delivery: { no_data_since: '14:18', last_receipt_at: '14:18:02' }, no_data_for: '11m' }],
  ['offline', { delivery: { no_data_since: '12:23', last_receipt_at: '12:23:04' }, no_data_for: '2h 06m' }],
  ['disabled', {}],
  ['retired', { retired: { at: '2026-08-20', by: 'aimla-pm', reason: 'host decommissioned' } }],
];
// The edges § 7.1 and § 5.6 state for those same members. Each is a null the wire really sends.
const EDGE_CASES = [
  ['offline', { delivery: null }, 'no data yet', 'the provisioned-never-reported seat: *no data yet* alone, never "no data since null"'],
  ['stalled', {}, 'API error', 'a null api_error_type keeps the pose and the label without an error string'],
  ['unknown', { unknown_reason: null }, '', 'a null unknown_reason draws no line rather than an invented reason'],
  ['unknown', { unknown_reason: 'reasons' }, 'unrecognised unknown_reason — reasons', 'an unrecognised unknown_reason carries its raw string'],
  ['retired', { retired: { at: '2026-08-20', reason: 'host decommissioned' } }, 'retired 2026-08-20 — host decommissioned', 'the seat.retired MESSAGE carries no `by`, and the line is built from what arrived'],
];
// Values that are NOT § 7.1 members. `pondering` is AT-D3-11's own; `thinking` is the one the
// artifact's own comments warn is a pose and not a state; the prototype-chain names are the shape
// that makes a bare `TABLE[value]` lookup return something plausible for a value nobody sent.
const STRANGERS = ['pondering', 'thinking', 'constructor', '__proto__', 'toString', '', 'WORKING', null];

const KNOWN_CHIPS = P.STATE_RENDER ? Object.values(P.STATE_RENDER).map((r) => r.chip) : [];
const PLATE_RE = /<span class='gl ([^']+)'>([\s\S]*?)<\/span><span class='ln'>([\s\S]*?)<\/span>/;

/**
 * Drive one value through the artifact and read the DOM back.
 * @returns {{threw:Error|null, chipClass:string|null, chipText:string|null, label:string|null,
 *            aria:string|null, svg:string, markers:{glyph:string,cls:string}[], bubbles:number,
 *            desats:number}}
 */
function drive(ctx, value, extra) {
  const { probe, world } = ctx;
  const lobbyEl = ctx.dom.byId.get('floors');
  probe.FLEET.aimla[0] = { ...BASE, render_state: value, ...extra };
  let threw = null;
  try { probe.render(); } catch (e) { threw = e; }
  const kids = world.children || [];
  const plate = kids.find((c) => c.className === 'plate' && c.innerHTML.includes(PROBE_SEAT));
  const m = plate ? plate.innerHTML.match(PLATE_RE) : null;
  const hit = kids.find((c) => c.className === 'hit' && String(c.getAttribute('aria-label')).startsWith(PROBE_SEAT));
  return {
    threw,
    chipClass: m ? m[1] : null, chipText: m ? m[2] : null, label: m ? m[3] : null,
    plateHtml: plate ? plate.innerHTML : null,
    aria: hit ? hit.getAttribute('aria-label') : null,
    svg: world.innerHTML || '',
    markers: kids.filter((c) => c.className.startsWith('mk ')).map((c) => ({ glyph: c.textContent, cls: c.className.slice(3) })),
    bubbles: kids.filter((c) => c.className === 'bub').length,
    desats: (world.innerHTML.match(/class="desat"/g) || []).length,
    // The lobby's per-floor summary for the probe's own floor — § 4.1, AT-D3-15.
    lobby: lobbyEl && lobbyEl.children.length ? lobbyEl.children[0].innerHTML : null,
  };
}

/** The counts the lobby line actually claims, summed. AT-D3-15: it never invents a count — and
 *  it may not lose one either, which is the direction the filter failed in. */
function lobbyTotal(line) {
  if (line === null) return null;
  const cell = line.match(/<span class='c'>([\s\S]*)<\/span>/);
  if (!cell) return null;
  return cell[1].split(' · ').reduce((n, part) => n + Number(part.trim().split(' ')[0] || 0), 0);
}

/** The whole sweep, as one function, so § 6 can run it against a deliberately broken source. */
function sweep(source, report) {
  const ctx = load(source);
  const { probe } = ctx;
  const say = report ? check : (cond, what) => { if (!cond) failed.push(what); };
  const failed = [];
  if (!probe.FLEET || !probe.render) {
    say(false, 'the artifact exposes FLEET and render() — nothing could be driven');
    return failed;
  }
  // Only the probe seat may carry a task, so the thought bubble is an unambiguous observation of
  // which desks draw a character (§ 5.1 rule 3 — a bubble is anchored to the character).
  for (const inst of probe.FLOORS) for (const s of probe.FLEET[inst]) { if (s.seat !== PROBE_SEAT) s.task = null; }
  const aimlaSeats = probe.FLEET.aimla.length;
  const otherDesats = probe.FLEET
    ? probe.FLOORS.flatMap((i) => probe.FLEET[i]).filter((s) => s.seat !== PROBE_SEAT)
      .filter((s) => probe.renderFor && probe.renderFor(s.render_state).current === false).length
    : 0;

  for (const [member, extra] of MEMBER_CASES) {
    const r = drive(ctx, member, extra);
    const row = probe.STATE_RENDER ? probe.STATE_RENDER[member] : null;
    say(!r.threw, `${member}: renders without throwing${r.threw ? ` — ${r.threw}` : ''}`);
    if (r.threw) continue;
    say(r.chipText === member.toUpperCase(), `${member}: the chip carries the member itself — got ${JSON.stringify(r.chipText)}`);
    say(!!row && r.chipClass === row.chip, `${member}: the chip wears its own row's class — got ${JSON.stringify(r.chipClass)}`);
    say(r.label !== null && !r.label.includes('undefined'), `${member}: the state line carries no "undefined" — got ${JSON.stringify(r.label)}`);
    say(r.label !== '', `${member}: the state line is not empty`);
    say(!r.svg.includes('undefined'), `${member}: nothing in the floor SVG reads "undefined"`);
    say(r.aria === `${PROBE_SEAT} — ${member}`, `${member}: the desk's accessible label carries the raw state — got ${JSON.stringify(r.aria)}`);
    if (row) {
      say(r.bubbles === (row.desk === 'character' ? 1 : 0),
        `${member}: draws a thought bubble iff its desk draws a character (${row.desk})`);
      say(r.desats === otherDesats + (row.current ? 0 : 1),
        `${member}: carries the not-current treatment iff § 7.3 says its pose may not be read as now`);
      if (row.marker) {
        say(r.markers.some((k) => k.glyph === row.marker[0] && k.cls === row.marker[1]),
          `${member}: its ${JSON.stringify(row.marker[0])} marker is on the floor`);
      }
    }
    say(!r.markers.some((k) => k.glyph === 'undefined'), `${member}: no marker reads "undefined"`);
    say(lobbyTotal(r.lobby) === aimlaSeats,
      `${member}: the lobby line counts every seat on the floor (${aimlaSeats}) — got ${lobbyTotal(r.lobby)}`);
    say(r.lobby !== null && r.lobby.includes(member), `${member}: the lobby names the member itself`);
  }

  for (const [member, extra, expected, why] of EDGE_CASES) {
    const r = drive(ctx, member, extra);
    say(!r.threw, `${member} edge — ${why}: renders without throwing${r.threw ? ` — ${r.threw}` : ''}`);
    if (r.threw) continue;
    say(r.label === expected, `${member} edge — ${why}: line is ${JSON.stringify(expected)}, got ${JSON.stringify(r.label)}`);
  }

  for (const stranger of STRANGERS) {
    const shown = JSON.stringify(stranger);
    const r = drive(ctx, stranger, {});
    say(!r.threw, `${shown}: renders without throwing${r.threw ? ` — ${r.threw}` : ''}`);
    if (r.threw) continue;
    say(r.chipText !== null && r.chipText.startsWith('UNRECOGNISED'),
      `${shown}: the chip says UNRECOGNISED — got ${JSON.stringify(r.chipText)}`);
    say(r.chipText !== null && r.chipText.includes(String(stranger)),
      `${shown}: the chip CARRIES the raw string (§ 5.4)`);
    say(!KNOWN_CHIPS.includes(r.chipClass),
      `${shown}: is NOT dressed as one of the ten — got class ${JSON.stringify(r.chipClass)}`);
    say(r.plateHtml !== null && !r.plateHtml.includes('undefined'),
      `${shown}: the plate emits no "undefined" — got ${JSON.stringify(r.plateHtml)}`);
    say(!r.svg.includes('undefined'), `${shown}: nothing in the floor SVG reads "undefined"`);
    say(r.desats === otherDesats + 1, `${shown}: the desk is treated as NOT-CURRENT (§ 5.4)`);
    say(r.aria === `${PROBE_SEAT} — ${stranger}`, `${shown}: the accessible label carries the raw value verbatim`);
    // The lobby is the fifth site of the same class and it failed SILENTLY: iterating § 7.1's
    // fixed order is a filter, so a seat in no member set fell out of its own floor's count.
    say(lobbyTotal(r.lobby) === aimlaSeats,
      `${shown}: the lobby line still counts every seat on the floor (${aimlaSeats}) — got ${lobbyTotal(r.lobby)}`);
    say(r.lobby !== null && r.lobby.includes('unrecognised'),
      `${shown}: and says the extra seat is unrecognised — got ${JSON.stringify(r.lobby)}`);
  }
  return failed;
}

section('3. every § 7.1 member, its stated null edges, and eight values that are not members');
sweep(SCRIPT, true);

// ---------------------------------------------------------------------------------------------
// 4. Controls — each check above is re-run against something that MUST make it red
// ---------------------------------------------------------------------------------------------
section('4. the controls: the sweep re-run against the defect it exists to catch');
const ANCHOR = 'function renderFor(v){return isRenderState(v)?STATE_RENDER[v]:UNRECOGNISED_RENDER;}';
const anchorHits = SCRIPT.split(ANCHOR).length - 1;
check(anchorHits === 1, `the control's anchor is present exactly once (${anchorHits}) — a control that no longer bites is not a control`);
if (anchorHits === 1) {
  // The pre-fix shape, re-minted: the § 5.4 fallback removed, and the six members the frozen
  // sample fleet does not contain deleted from the table. This is the defect card#7943 closes.
  const BROKEN = SCRIPT.replace(ANCHOR, 'function renderFor(v){return STATE_RENDER[v];}')
    + '\n;for(const k of ["stalled","unknown","catching_up","offline","disabled","retired"])delete STATE_RENDER[k];\n';
  const failedUnderBroken = sweep(BROKEN, false);
  check(failedUnderBroken.length > 0,
    `the sweep goes RED against the pre-fix shape — ${failedUnderBroken.length} checks fail, first: ${JSON.stringify(failedUnderBroken[0] || null)}`);
  check(failedUnderBroken.some((f) => f.startsWith('offline:') && f.includes('throwing')),
    'and it goes red the way the defect did: a seat in an uncovered member THROWS out of the render');
}
// The lobby is the fifth site and it failed SILENTLY — no throw, no glyph, just a seat missing
// from its floor's count — so the sweep's ability to catch THAT has to be shown on its own: the
// control above throws long before the lobby is reached, and a check that only ever ran behind a
// crash is a check nobody has seen work.
const LOBBY_ANCHOR = '.concat(unheard.map(v=>seen[v]+" unrecognised ("+String(v)+")"))';
const lobbyHits = SCRIPT.split(LOBBY_ANCHOR).length - 1;
check(lobbyHits === 1, `the lobby control's anchor is present exactly once (${lobbyHits})`);
if (lobbyHits === 1) {
  const FILTERED = SCRIPT.replace(LOBBY_ANCHOR, '');   // § 7.1's order as a FILTER, as it was
  const failedUnderFilter = sweep(FILTERED, false);
  check(failedUnderFilter.some((f) => f.includes('counts every seat') || f.includes('still counts every seat')),
    `the sweep goes RED when the lobby summary silently drops an unrecognised seat — ${failedUnderFilter.length} checks fail, e.g. ${JSON.stringify(failedUnderFilter.find((f) => f.includes('counts every seat')) || null)}`);
}
// The two derivations that are pure comparison get their own discriminating controls, because a
// comparison that has only ever been shown agreeing is not evidence that it can disagree.
check(!eq(DOC_STATES, ['working', 'idle', 'blocked', 'stale']),
  'the member-set comparison rejects the four-member set the defect shipped');
check(/undefined/.test('<rect fill="undefined"/>') && !/undefined/.test('<rect fill="#8ce8b0"/>'),
  'the "undefined" detector fires on the exact string the pre-fix SCREENC lookup emitted, and not on a real colour');

console.log(`\n${failures === 0 ? 'PASS' : `FAIL — ${failures} check(s)`}  (${HTML_PATH})`);
process.exit(failures === 0 ? 0 : 1);

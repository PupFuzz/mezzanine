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
//   1. NEITHER THE MEMBER SETS NOR WHAT THEY RENDER ARE STORED HERE. Three published tables are
//      re-derived from FLOOR.md on every run — § 7.1's ten `render_state` members WITH their
//      Label line column, § 7.1's seven `unknown_reason` sentences, and § 7.6's twelve
//      `api_error_type` phrases — and each is compared to the artifact CELL BY CELL. A member
//      added to D3 with no row reds this gate; so does a row whose rendered text has drifted
//      from the document's. ⛔ An earlier revision of this file parsed only the member NAMES
//      while three surfaces claimed it checked the sentences: a sentence rewritten to say the
//      opposite of D3 still ran green. That is why § 4 mutates one cell of each set and requires
//      this layer to go red.
//   2. THE TABLE'S SHAPE. Every row carries every cell, names a chip class the artifact's own
//      stylesheet defines, and declares whether its desk draws a character — so a row that
//      omits a cell is loud rather than an `undefined` at a render site.
//   3. THE BEHAVIOUR. A probe seat is driven through all ten members — with D3's OWN worked
//      example values, so the rendered line is compared to § 7.1's column literally — through
//      the null/absent edges § 7.1 and § 5.6 state, through the sibling membership sets on
//      `unknown_reason` and `api_error_type`, and through eight values that are NOT members at
//      all. The DOM the artifact produces is read back each time.
//   4. THE SCOPE DECLARATION (§ 5, § 6). The artifact implements PART of D3 deliberately, so it
//      declares WHICH part, per render surface, in one machine-readable table — and that
//      declaration is checked rather than believed: § 5.4's six membership-tested surfaces are
//      re-derived from the document (twice over, plus its own count in words) and
//      set-differenced against the table BOTH WAYS. A surface D3 gains with no row reds; a row
//      for a surface D3 does not publish reds. Absent-because-out-of-scope and absent-because-
//      missed are what card#7341's implementer cannot otherwise tell apart.
//   5. THE TWO IDENTITY-KEYED LOOKUPS (§ 7) and § 5.6's INTERN NULLS (§ 9). A seat's `desk`
//      against the floor map's slots, and an install against the client's theme map, are keyed
//      on identity and not on a member set, so layer 1's primitive does not reach them; § 3.2's
//      overflow rule and § 9 F13 say what both owe — a labelled overflow row, a notice reading
//      *floor map is short N desks*, and never a dropped seat. § 9 holds the intern label's two
//      nulls apart: a null `subagent_type` draws NO tag, a null `title` draws *untitled*.
//
//   6. THE FLOOR / BAND BOUNDARY (§ 8). Layer 5 asserts the homeless seat's desk IS DRAWN and
//      cannot see WHERE — which is how the row shipped painted over sola's tea bar with every
//      structural fact above green. D3 § 3.2 puts the overflow row BELOW THE FLOOR, so the
//      artifact extends the canvas by the band and draws the row in that strip, and the whole
//      question becomes one invariant with one scalar per floor per direction: nothing the FLOOR
//      emits reaches below `FH`, nothing the BAND emits rises above it. ⛔ An unmeasurable shape
//      makes that assertion FAIL — the honest answer is *not measured*, never *clear*.
//
// EVERY CHECK HERE CAN FAIL, AND THE CONTROLS PROVE IT rather than asserting it. Each planted
// mutation is anchored, its anchor is asserted to occur exactly once, and the relevant layer is
// REQUIRED to go red; the derivations that are pure comparison get their own discriminating
// controls. HOW MANY of each this file carries is printed on its own last line and is counted at
// run time — a figure written into a header is a figure that drifts from the file below it, which
// is why neither this header nor either README carries one.
//
// ⚠ WHAT THIS IS NOT EVIDENCE ABOUT. There is no browser here and no HTML parser: the DOM below
// is a stub that records what the artifact's own code writes into it, so what is asserted is the
// RULE — the attribute values, the class names and the strings — and never the picture. Nothing
// here has been laid out, painted, or seen. A green run says the artifact emits the right values;
// it says nothing about how any of it LOOKS, and it cannot: `filter`, `opacity` and every colour
// in the table are read by a renderer that never ran. jsdom would not close that gap either —
// it does no layout and no paint — so the gap is named rather than papered over. § 8 narrows that
// gap by ONE fact and does not close it: it is arithmetic on emitted coordinates, so z-order,
// opacity and the difference between a box and the ink inside it are all outside what it can say.
// Where a bound cannot be computed exactly it is computed WIDE — a superset of the real ink — so
// that the only error it can make is to over-report a crossing.

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
let checks = 0;
/** @param {boolean} cond @param {string} what */
function check(cond, what) {
  checks++;
  if (cond) { console.log(`  ok   ${what}`); } else { console.log(`  FAIL ${what}`); failures++; }
}
/** @param {string} s */
function section(s) { console.log(`\n${s}`); }

// ---------------------------------------------------------------------------------------------
// Two primitives this file used to carry N copies of
// ---------------------------------------------------------------------------------------------
/**
 * The text between two anchors, or `null` when either is missing or the closing one does not
 * follow the opening one. Either end may be `null`, meaning the document's own start or end — so
 * a ONE-SIDED slice goes through this too, rather than being the shape below spelled out again.
 *
 * ⛔ THIS EXISTS BECAUSE AN `indexOf` RESULT USED AS A SLICE BOUND IS A TRAP, and this file carried
 * the shape at every parse site it has. `indexOf` answers **-1** for an anchor that has been
 * renamed, and `slice(-1, …)` / `slice(…, -1)` do not mean "from the start" and "to the end" — they
 * mean "the last character" and "everything but the last character". Renaming § 7.1's closing
 * anchor (`### 7.2 Badges`) therefore handed the parse below a quarter of the document instead of
 * § 7.1's own few thousand characters, and the parse found ten member rows somewhere in there and
 * reported agreement. The population silently became the document, in the direction nobody watches.
 * ⚠ THE FIGURES ARE NOT WRITTEN HERE. They are PRINTED by § 6's anchor controls on every run
 * ("widened from N chars to M"), because a number restated in a comment arguing that restated
 * numbers drift is the defect demonstrating itself — and this comment carried a stale one.
 * `null` is the honest answer, and every caller here treats it as a FAILURE — never as an
 * empty-but-clean parse. § 4's anchor controls plant exactly that rename and require it to go red.
 * @param {string} md @param {string|null} open @param {string|null} close @returns {string|null}
 */
function sliceBetween(md, open, close) {
  let i = 0;
  if (open !== null) { i = md.indexOf(open); if (i < 0) return null; }
  let j = md.length;
  if (close !== null) {
    j = md.indexOf(close, i + (open === null ? 0 : open.length));
    if (j < 0) return null;
  }
  return md.slice(i, j);
}
let controls = 0;
/**
 * A planted mutation's anchor, asserted present exactly once. Every control in this file goes
 * through here, so HOW MANY controls it carries is COUNTED at run time and printed at the end —
 * `docs/design/floor-preview/README.md` and `tools/design/README.md` point at that line rather
 * than restating a number that drifts the moment a control is added or deleted.
 * @param {number} hits @param {string} what @returns {boolean} whether the control can be planted
 */
function control(hits, what) {
  controls++;
  check(hits === 1, `control ${controls} is anchored exactly once (${hits}) — ${what}`);
  return hits === 1;
}
/** How many times an anchor occurs. A control that no longer bites is not a control. */
const hitsOf = (haystack, anchor) => haystack.split(anchor).length - 1;

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
  'STATE_RENDER', 'UNRECOGNISED_RENDER', 'RENDER_STATES', 'UNKNOWN_REASONS', 'API_ERROR_TYPES',
  'MEMBER_SETS', 'isMember', 'unheardFields', 'isCurrent', 'apiErrorLine',
  'isRenderState', 'renderFor', 'chipText', 'poseOf', 'hasCharacter', 'labelFor',
  'render', 'FLEET', 'FLOORS', 'D3_SCOPE', 'placeFloor', 'themeFor', 'internLabel',
  'UNTHEMED', 'overflowSlot', 'floorBody', 'floorBand', 'FH', 'BAND_H', 'BAND_DESK_Y',
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
  for (const id of ['room', 'world', 'drill', 'floors', 'scope', 'fleetcount', 'feeddot', 'feedstatus', 'zin', 'zout', 'zfit', 'zall']) {
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
const S71_ANCHORS = ['### 7.1 The render per state', '### 7.2 Badges'];
const s71 = sliceBetween(FLOOR_MD, ...S71_ANCHORS);
check(s71 !== null, `§ 7.1's own bounds were both found — ${s71 === null ? 'THEY WERE NOT' : `${s71.length} chars`}`);
// § 7.1 carries TWO tables and the second one's header is the boundary between them. It is an
// anchor like any other, so it goes through `sliceBetween` — the `indexOf` this used to hold was
// the same trap one level in: a renamed `| \`unknown_reason\` | Sentence |` gave the state table
// "all but the last character of § 7.1" (the reason rows included) and the reason table one
// character, and neither slice announced it.
const REASON_HDR = '| `unknown_reason` | Sentence |';
// The rows BELOW the header separator, and only while they stay contiguous — the header row's
// own first cell is the field's name (`| \`render_state\` | Desk | …`) and would otherwise read
// as an eleventh member, which is the shape that makes a parsed count agree with nothing.
// Each row is returned as its cells, so a caller can read the member AND what D3 says renders
// for it. Reading only the member name is what let a mutated sentence pass this suite.
const rowsOf = (block) => {
  const lines = block.split('\n');
  const sep = lines.findIndex((l) => /^\|\s*-{3,}/.test(l));
  if (sep < 0) return [];
  const out = [];
  for (const line of lines.slice(sep + 1)) {
    if (!line.startsWith('|')) break;
    const cells = line.replace(/^\|/, '').replace(/\|\s*$/, '').split(' | ').map((c) => c.trim());
    const m = cells[0].match(/^`([a-z_]+)`$/);
    if (!m) break;
    out.push({ member: m[1], cells });
  }
  return out;
};
// D3 writes a published render as the LEADING ITALIC SPAN of its cell, with the reasoning in
// prose after it. That span is the literal string the artifact owes; backticks inside it are
// markdown (`/clear`), never part of the rendered text.
const publishedLine = (cell) => {
  const m = (cell || '').match(/^\*([^*][^*]*)\*/);
  return m ? m[1].replace(/`/g, '') : null;
};
const s71States = s71 === null ? null : sliceBetween(s71, null, REASON_HDR);
const s71Reasons = s71 === null ? null : sliceBetween(s71, REASON_HDR, null);
check(s71States !== null && s71Reasons !== null,
  `§ 7.1's two tables were parted at ${JSON.stringify(REASON_HDR)} — ${s71States === null || s71Reasons === null ? 'THE BOUNDARY WAS NOT FOUND' : `${s71States.length} chars of state rows, ${s71Reasons.length} of reason rows`}`);
const DOC_STATE_ROWS = s71States === null ? [] : rowsOf(s71States);
const DOC_REASON_ROWS = s71Reasons === null ? [] : rowsOf(s71Reasons);
const DOC_STATES = DOC_STATE_ROWS.map((r) => r.member);
const DOC_REASONS = DOC_REASON_ROWS.map((r) => r.member);
// § 7.6's twelve `api_error_type` members live in their own section, under a column headed
// *The line beside the raw value* — which is the render's shape, not just a glossary.
const S76_ANCHORS = ['### 7.6 The three remaining member sets', '## 8. Interns'];
const s76 = sliceBetween(FLOOR_MD, ...S76_ANCHORS);
check(s76 !== null, `§ 7.6's own bounds were both found — ${s76 === null ? 'THEY WERE NOT' : `${s76.length} chars`}`);
const API_HDR = '| `api_error_type` | The line beside the raw value |';
const s76Api = s76 === null ? null : sliceBetween(s76, API_HDR, null);
check(s76Api !== null,
  `§ 7.6's own table was found at ${JSON.stringify(API_HDR)} — ${s76Api === null ? 'IT WAS NOT' : `${s76Api.length} chars`}`);
const DOC_API_ROWS = s76Api === null ? [] : rowsOf(s76Api);
// An empty parse is a measurement that never happened. These two counts are D3's own claims
// ("`render_state` has **ten** members", "The seven `unknown_reason` members"), so a parse that
// silently returned nothing cannot pass for agreement.
check(DOC_STATES.length === 10, `parsed ${DOC_STATES.length} render_state members from § 7.1 — D3 says ten`);
check(DOC_REASONS.length === 7, `parsed ${DOC_REASONS.length} unknown_reason members from § 7.1 — D3 says seven`);
check(DOC_API_ROWS.length === 12, `parsed ${DOC_API_ROWS.length} api_error_type members from § 7.6 — D3 says twelve`);

const P = load(SCRIPT).probe;
const eq = (a, b) => Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((v, i) => v === b[i]);
check(Array.isArray(P.RENDER_STATES) && eq(P.RENDER_STATES, DOC_STATES),
  `the artifact's RENDER_STATES is § 7.1's ten in § 7.1's order: ${JSON.stringify(P.RENDER_STATES)}`);
check(!!P.STATE_RENDER && eq(Object.keys(P.STATE_RENDER), DOC_STATES),
  'the render table has one row per § 7.1 member, in that order — the member set is written ONCE');

// ⭐ The MEMBERS agreeing is the cheap half. What the artifact renders FOR each member is the
// half that gets edited, and comparing only names is a check that cannot see a sentence rewritten
// to say the opposite of what D3 published. Both sibling sets are compared cell by cell — and
// § 4 mutates one of each and requires this to go red, because that is the only thing that shows
// the comparison is happening at all.
function compareSets(probe, say) {
  for (const [label, docRows, table] of [
    ['unknown_reason', DOC_REASON_ROWS, probe.UNKNOWN_REASONS],
    ['api_error_type', DOC_API_ROWS, probe.API_ERROR_TYPES],
  ]) {
    say(!!table && eq(Object.keys(table).slice().sort(), docRows.map((r) => r.member).sort()),
      `${label}: the artifact covers D3's members exactly`);
    if (!table) continue;
    for (const row of docRows) {
      const want = publishedLine(row.cells[1]);
      say(want !== null && table[row.member] === want,
        `${label} \`${row.member}\`: renders D3's own sentence — want ${JSON.stringify(want)}, got ${JSON.stringify(table[row.member])}`);
    }
  }
}
compareSets(P, check);

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
// ⭐ The field values are D3's OWN worked examples from § 7.1's Label line column, so the line
// the artifact produces can be compared to that column LITERALLY rather than to a paraphrase.
const MEMBER_CASES = [
  ['working', { action: { tool_name: 'Bash', descriptor: 'Bash: composer test', started_at: '14:26:03', running: '2m 59s' }, open_calls: 1, open_turn: true }],
  ['idle', { quiet_for: '4m 12s' }],
  ['blocked', { blocked_since: '14:31', open_turn: true }],
  ['stalled', { api_error_type: 'rate_limit' }],
  ['unknown', { unknown_reason: 'turn_killed_by_clear' }],
  ['catching_up', { activity: { last_kind: 'tool.start', last_event_time: '12:47' } }],
  ['stale', { delivery: { no_data_since: '14:18', last_receipt_at: '14:18:02' }, no_data_for: '11m' }],
  ['offline', { delivery: { no_data_since: '12:23', last_receipt_at: '12:23:04' }, no_data_for: '2h 06m' }],
  ['disabled', {}],
  ['retired', { retired: { at: '2026-08-20', by: 'aimla-pm', reason: 'host decommissioned' } }],
];
// The edges § 7.1 and § 5.6 state for those same members, plus the SIBLING membership sets.
// The fourth column is what the desk's currency must be: § 5.4 treats a desk carrying an
// unrecognised value in ANY membership-tested field as not-current, not only `render_state`.
const EDGE_CASES = [
  ['offline', { delivery: null }, 'no data yet', false, 'the provisioned-never-reported seat: *no data yet* alone, never "no data since null"'],
  ['stalled', {}, 'API error', true, 'a null api_error_type keeps the pose and the label without an error string'],
  ['stalled', { api_error_type: 'overloaded' }, 'API error — overloaded (the API was overloaded)', true, 'a known api_error_type carries the raw value AND § 7.6\'s phrase beside it'],
  ['stalled', { api_error_type: 'quantum_flux' }, 'API error — quantum_flux (unrecognised)', false, 'a THIRTEENTH api_error_type carries its raw string with the unrecognised marker, and the desk stops being current'],
  ['unknown', { unknown_reason: null }, '', true, 'a null unknown_reason draws no line rather than an invented reason'],
  ['unknown', { unknown_reason: 'reasons' }, 'reasons (unrecognised unknown_reason)', false, 'an unrecognised unknown_reason carries its raw string, and the desk stops being current'],
  ['catching_up', { activity: { last_kind: 'tool.start', last_event_time: null } }, 'replaying history', false, 'a null activity.last_event_time drops the TIMESTAMP and keeps the state — never the four characters `null` on a desk label'],
  ['retired', { retired: { at: '2026-08-20', reason: 'host decommissioned' } }, 'retired 2026-08-20 — host decommissioned', false, 'the seat.retired MESSAGE carries no `by`, and the line is built from what arrived'],
];
// Values that are NOT § 7.1 members. `pondering` is AT-D3-11's own; `thinking` is the one the
// artifact's own comments warn is a pose and not a state; the prototype-chain names are the shape
// that makes a bare `TABLE[value]` lookup return something plausible for a value nobody sent.
const STRANGERS = ['pondering', 'thinking', 'constructor', '__proto__', 'toString', '', 'WORKING', null];

// § 7.1's Label line column, per member, as the literal string the desk owes.
const DOC_LABEL = {};
for (const row of DOC_STATE_ROWS) DOC_LABEL[row.member] = publishedLine(row.cells[2]);
// The three members for which § 7.1 publishes PROSE rather than a literal, each with the reason
// it is exempt. An unlisted member whose column parses to nothing is a FAILURE above, never a
// silent skip — that is how this exemption stays three members wide instead of growing quietly.
const LABEL_NOT_COMPARABLE = {
  working: '"the action\'s descriptor" — the line is a wire field, not a fixed string',
  unknown: '"one sentence per `unknown_reason` (below)" — the literals are that table\'s, checked in § 1',
  // ⚠ § 7.1's cell for `stalled` reads *API error — rate limit*: the § 7.6 phrase with the RAW
  // VALUE ELIDED. That contradicts § 5.4 ("the line carries the raw string either way"), § 7.6's
  // column heading ("The line beside the raw value") and § 5.1 ("rendered verbatim"). The
  // artifact follows the two rule statements and the heading; the literal is therefore NOT
  // comparable, and the properties both readings agree on are asserted instead, below. The
  // tension is reported to D3's owner rather than settled here.
  stalled: '§ 7.1\'s example elides the raw value that § 5.4, § 5.1 and § 7.6 all require — reported to D3, asserted by property',
};

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
  const otherDesats = probe.isCurrent
    ? probe.FLOORS.flatMap((i) => probe.FLEET[i])
      .filter((s) => s.seat !== PROBE_SEAT && !probe.isCurrent(s)).length
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
    // ⭐ THE LINE ITSELF, against § 7.1's Label line column. "is a function, non-empty, and free
    // of `undefined`" is satisfied by any string at all, including a wrong one — and the label
    // cells are the part of the table most likely to be edited.
    const want = DOC_LABEL[member];
    if (LABEL_NOT_COMPARABLE[member]) {
      say(true, `${member}: not string-comparable — ${LABEL_NOT_COMPARABLE[member]}`);
    } else if (want !== null && want !== undefined) {
      say(r.label === want,
        `${member}: the state line is § 7.1's own, verbatim — want ${JSON.stringify(want)}, got ${JSON.stringify(r.label)}`);
    } else {
      say(false, `${member}: § 7.1's Label line column parsed to nothing and this member is not on the exempt list — the comparison measured nothing`);
    }
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
    if (member === 'stalled') {
      // The two properties § 7.1's abbreviated example and D3's rule statements BOTH agree on.
      say(r.label.includes(extra.api_error_type),
        `stalled: the line carries the raw \`api_error_type\` verbatim (§ 5.1, § 5.4) — got ${JSON.stringify(r.label)}`);
      const phrase = P.API_ERROR_TYPES ? P.API_ERROR_TYPES[extra.api_error_type] : null;
      say(!!phrase && r.label.includes(phrase),
        `stalled: and § 7.6's phrase beside it — want ${JSON.stringify(phrase)} inside the line`);
    }
    say(lobbyTotal(r.lobby) === aimlaSeats,
      `${member}: the lobby line counts every seat on the floor (${aimlaSeats}) — got ${lobbyTotal(r.lobby)}`);
    say(r.lobby !== null && r.lobby.includes(member), `${member}: the lobby names the member itself`);
  }

  for (const [member, extra, expected, wantCurrent, why] of EDGE_CASES) {
    const r = drive(ctx, member, extra);
    say(!r.threw, `${member} edge — ${why}: renders without throwing${r.threw ? ` — ${r.threw}` : ''}`);
    if (r.threw) continue;
    say(r.label === expected, `${member} edge — ${why}: line is ${JSON.stringify(expected)}, got ${JSON.stringify(r.label)}`);
    say(r.desats === otherDesats + (wantCurrent ? 0 : 1),
      `${member} edge — ${why}: desk currency is ${wantCurrent ? 'CURRENT' : 'NOT-CURRENT'} (§ 5.4)`);
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
if (control(hitsOf(SCRIPT, ANCHOR), 'the pre-fix `renderFor` shape')) {
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
if (control(hitsOf(SCRIPT, LOBBY_ANCHOR), "the lobby summary's silent filter")) {
  const FILTERED = SCRIPT.replace(LOBBY_ANCHOR, '');   // § 7.1's order as a FILTER, as it was
  const failedUnderFilter = sweep(FILTERED, false);
  check(failedUnderFilter.some((f) => f.includes('counts every seat') || f.includes('still counts every seat')),
    `the sweep goes RED when the lobby summary silently drops an unrecognised seat — ${failedUnderFilter.length} checks fail, e.g. ${JSON.stringify(failedUnderFilter.find((f) => f.includes('counts every seat')) || null)}`);
}
// ⭐ Every comparison against FLOOR.md's CONTENT gets a mutation of its own. An earlier revision
// of this suite parsed only the member NAMES while three surfaces claimed it checked the
// sentences: rewriting `turn_killed_by_clear` to say the opposite still ran green, because
// nothing ever read column 2. A comparison that has only been seen agreeing is not evidence.
for (const [set, anchor, wrong, what] of [
  ['unknown_reason', 'turn_killed_by_clear:"the last turn was killed by a /clear"',
    'turn_killed_by_clear:"the seat is fine, nothing happened"',
    'an unknown_reason SENTENCE rewritten to say the opposite of § 7.1'],
  ['api_error_type', 'overloaded:"the API was overloaded"',
    'overloaded:"everything is fine"',
    'an api_error_type PHRASE rewritten to say the opposite of § 7.6'],
]) {
  if (!control(hitsOf(SCRIPT, anchor), what)) continue;
  const failed = [];
  // Only this set's own reds count. A control that accepted its sibling's failure as proof would
  // pass while the half it exists to exercise did nothing.
  compareSets(load(SCRIPT.replace(anchor, wrong)).probe,
    (cond, w) => { if (!cond && w.startsWith(set)) failed.push(w); });
  check(failed.length > 0, `caught: ${what} — ${failed.length} red, e.g. ${JSON.stringify(failed[0] || null)}`);
}
// And the same for the § 7.1 Label line comparison, which is the cell most likely to be edited.
const LABEL_ANCHOR = 'label:s=>"finished — nothing done for "+s.quiet_for';
if (control(hitsOf(SCRIPT, LABEL_ANCHOR), "§ 7.1's `idle` label cell reverted to its pre-fix wording")) {
  // Exactly the pre-fix `idle` line: the age with `finished — ` dropped, which turns § 7.1's
  // positive observation back into the silence § 7.5 refuses.
  const failedUnderBareAge = sweep(SCRIPT.replace(LABEL_ANCHOR, 'label:s=>"nothing done for "+s.quiet_for'), false);
  check(failedUnderBareAge.some((f) => f.startsWith('idle:') && f.includes('verbatim')),
    `the sweep goes RED when a label cell stops being § 7.1's line — e.g. ${JSON.stringify(failedUnderBareAge.find((f) => f.includes('verbatim')) || null)}`);
}
// The two derivations that are pure comparison get their own discriminating controls, because a
// comparison that has only ever been shown agreeing is not evidence that it can disagree.
check(!eq(DOC_STATES, ['working', 'idle', 'blocked', 'stale']),
  'the member-set comparison rejects the four-member set the defect shipped');
check(/undefined/.test('<rect fill="undefined"/>') && !/undefined/.test('<rect fill="#8ce8b0"/>'),
  'the "undefined" detector fires on the exact string the pre-fix SCREENC lookup emitted, and not on a real colour');

// ---------------------------------------------------------------------------------------------
// 5. THE SCOPE DECLARATION, against D3's own set of render surfaces — in BOTH directions
// ---------------------------------------------------------------------------------------------
// The artifact implements PART of D3 on purpose: making it complete would make it a second full
// implementation of D3's render surfaces — the floor built twice — and card#7341 a port of it
// rather than the build. What that costs the reader is the thing this layer repairs: an absent
// render surface is otherwise indistinguishable from one that was MISSED. The artifact declares
// its scope per surface in `D3_SCOPE`, and the declaration is checked here rather than believed.
//
// ⭐ THE POPULATION IS NOT STORED HERE EITHER. § 5.4 states the membership rule over its own
// surfaces TWICE — once as the rule ("a `render_state`, `link_state` … or badge the client does
// not know") and once as the publication sentence naming where each set lives — and states their
// COUNT in words. All three are re-derived below and cross-checked, so a parse that quietly
// returned nothing cannot pass for agreement, and the scope table is set-differenced against the
// result BOTH WAYS: a surface D3 publishes with no row reds, and a row for a surface D3 does not
// publish reds. ⇒ D3 gaining a SEVENTH surface reds this gate instead of passing silently.
section("5. the scope declaration, against § 5.4's own six render surfaces, both ways");
const NUMBER_WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
  'nine', 'ten', 'eleven', 'twelve'];
const S54_ANCHORS = ['### 5.4 What is never rendered', '### 5.5 The client'];
/** D3's membership-tested render surfaces, read out of § 5.4. `null` when the anchors are gone —
 *  which is a FAILURE below, never a silently empty comparison. */
function docSurfaces(floorMd) {
  // Both anchors are required, in order — `sliceBetween`'s whole reason for existing, and this was
  // the site where the trap was found: a § 5.4 that had been renamed away handed the parse below
  // the WHOLE DOCUMENT, which contains six surface names somewhere, and the gate reported
  // `ok parsed 6 render surfaces from § 5.4` while § 5.4 published nothing.
  const s54 = sliceBetween(floorMd, ...S54_ANCHORS);
  if (s54 === null) return null;
  const RULE = '- **An unrecognised enum member guessed into a known one.**';
  const PUB = '**"The client does not know" is a membership test';
  const ruleText = sliceBetween(s54, RULE, PUB);
  if (ruleText === null) return null;
  // ⚠ THE FOURTH SITE OF THE SAME CLASS, and it was safe only by a chain: `s54.indexOf(PUB)` as a
  // slice bound is fine ONLY because `sliceBetween` above already refused a missing `PUB` — a
  // guarantee carried by a different variable, three lines away, that a later edit to either line
  // silently breaks. It goes through the primitive instead, where the guarantee is the slice's own.
  // The next bold paragraph ends this one; there may not BE a next one, and `null` for the closing
  // anchor is that case rather than a `-1` to remember to test for.
  const pubText = sliceBetween(s54, PUB, '\n  **') ?? sliceBetween(s54, PUB, null);
  if (pubText === null) return null;
  // Link TARGETS are not prose: `#72-badges-every-member-has-a-render` would otherwise answer for
  // the word `badges` that the sentence itself is supposed to carry.
  const names = (t) => [...new Set([...t.replace(/\]\([^)]*\)/g, '')
    .matchAll(/`([a-z_]+)`|\bbadges?\b/g)].map((m) => m[1] || 'badge'))];
  // D3 backticks five of the six and writes the sixth as the English word `badge(s)` — in both
  // enumerations and in § 7.2's own table header — so the word is read as the name it is.
  const spelled = (pubText.match(/all (\w+)\s+sets are published here/) || [])[1];
  return {
    rule: names(ruleText),
    surfaces: names(pubText),
    spelled: NUMBER_WORDS.indexOf(String(spelled)),
  };
}
const STATUSES = ['implemented', 'partial', 'not implemented'];
/** The derivation's own controls: an empty or half-parsed § 5.4 must be loud. */
function checkDerivation(d, say) {
  say(d !== null, "§ 5.4's two enumerations were both found — the population is D3's, not this file's");
  if (!d) return;
  say(d.surfaces.length > 0, `parsed ${d.surfaces.length} render surfaces from § 5.4: ${JSON.stringify(d.surfaces)}`);
  say(eq(d.rule.slice().sort(), d.surfaces.slice().sort()),
    `§ 5.4's rule sentence and its publication sentence name the same surfaces — ${JSON.stringify(d.rule)} vs ${JSON.stringify(d.surfaces)}`);
  say(d.spelled === d.surfaces.length,
    `and D3's own count of them, spelled in words, is the number parsed (${d.spelled} vs ${d.surfaces.length})`);
}
/** The set difference, both ways, plus the shape of a row. Reusable so § 6 can re-run it. */
function compareScope(probe, surfaces, say) {
  const rows = Array.isArray(probe.D3_SCOPE) ? probe.D3_SCOPE : null;
  say(!!rows && rows.length > 0, 'the artifact carries a D3_SCOPE table');
  if (!rows) return;
  const declared = rows.map((r) => r.surface);
  say(new Set(declared).size === declared.length, 'scope: no surface is declared twice');
  for (const s of surfaces) {
    say(declared.includes(s),
      `scope: § 5.4 publishes \`${s}\` and the scope table declares what this artifact does with it`);
  }
  for (const s of declared) {
    say(surfaces.includes(s),
      `scope: the scope table's row \`${s}\` is a surface § 5.4 actually publishes`);
  }
  for (const r of rows) {
    say(STATUSES.includes(r.status),
      `scope \`${r.surface}\`: status ${JSON.stringify(r.status)} is one of ${STATUSES.join(' / ')}`);
    say(typeof r.note === 'string' && r.note.trim().length > 0,
      `scope \`${r.surface}\`: names the render it is implemented by, or the reason there is none`);
    // `implemented` is the claim that this artifact renders the surface FROM a member set of D3's,
    // so the row carries that set — and a half-there surface (`link_state`, rendered raw and never
    // membership-tested) may not wear the same word as one that is closed.
    say((r.status === 'implemented') === !!r.table,
      `scope \`${r.surface}\`: carries its member set iff it says implemented — ${r.status}, table ${r.table ? 'present' : 'null'}`);
  }
  // The declaration is load-bearing, not decorative: the membership test is DERIVED from these
  // rows, so there is no second list of tested fields to forget to edit.
  say(!!probe.MEMBER_SETS
    && eq(Object.keys(probe.MEMBER_SETS), rows.filter((r) => r.table).map((r) => r.surface)),
    `the artifact membership-tests exactly the surfaces its scope table gives a member set: ${JSON.stringify(Object.keys(probe.MEMBER_SETS || {}))}`);
}
const DOC_SURFACES = docSurfaces(FLOOR_MD);
checkDerivation(DOC_SURFACES, check);
if (DOC_SURFACES) compareScope(P, DOC_SURFACES.surfaces, check);
// The declaration is also on the PAGE, painted from the same table — one source, two readers.
const scopePanel = load(SCRIPT).dom.byId.get('scope').innerHTML;
for (const r of (P.D3_SCOPE || [])) {
  check(scopePanel.includes(r.surface) && scopePanel.includes(r.status),
    `the page's scope panel carries \`${r.surface}\` and its status — painted from D3_SCOPE, never re-typed`);
}

// ---------------------------------------------------------------------------------------------
// 6. The scope controls — one per DIRECTION, because a set difference that has only ever been
//    seen agreeing is not evidence that it can disagree
// ---------------------------------------------------------------------------------------------
section('6. the scope controls: each direction re-run against something that MUST make it red');
// DIRECTION 1 — D3 GAINS A SEVENTH SURFACE. The mutation is on D3's side and is a well-formed
// one: both of § 5.4's enumerations gain `sprocket_state` and the count in words goes to seven,
// so what is simulated is a document that grew a surface, not a document that broke. The scope
// table then has no row for it, and the gate must say so.
const D3_MUTATIONS = [
  ['`api_error_type` or badge', '`api_error_type`, `sprocket_state` or badge'],
  ['and `api_error_type` in', 'and `sprocket_state` and `api_error_type` in'],
  ['all six\n  sets are published here', 'all seven\n  sets are published here'],
];
let grownDoc = FLOOR_MD;
let anchorsOk = true;
for (const [from, to] of D3_MUTATIONS) {
  if (!control(hitsOf(grownDoc, from), `a D3 that GREW a seventh surface: ${JSON.stringify(from)}`)) { anchorsOk = false; continue; }
  grownDoc = grownDoc.split(from).join(to);
}
if (anchorsOk) {
  const grown = docSurfaces(grownDoc);
  const derivationFailed = [];
  checkDerivation(grown, (cond, what) => { if (!cond) derivationFailed.push(what); });
  check(derivationFailed.length === 0 && grown.surfaces.length === 7 && grown.spelled === 7,
    `the control's D3 is a document that GREW a surface, not one that broke: ${JSON.stringify(grown.surfaces)}`);
  const failed = [];
  compareScope(P, grown.surfaces, (cond, what) => { if (!cond) failed.push(what); });
  const hit = failed.find((f) => f.includes('§ 5.4 publishes `sprocket_state`'));
  check(!!hit, `a D3 surface with no row in the scope table goes RED — ${JSON.stringify(hit || null)}`);
}
// DIRECTION 2 — THE SCOPE TABLE CLAIMS A SURFACE D3 DOES NOT PUBLISH. The mutation is on the
// artifact's side: a row is added for a surface that exists nowhere in D3. A one-directional
// check would pass this — every one of D3's six still has a row — which is exactly the direction
// a stale declaration drifts in as D3 moves under it.
const ROW_ANCHOR = '  {surface:"badge",status:"not implemented",table:null,';
if (control(hitsOf(SCRIPT, ROW_ANCHOR), 'a scope row for a surface D3 does not publish') && DOC_SURFACES) {
  const INVENTED = SCRIPT.replace(ROW_ANCHOR,
    '  {surface:"sprocket_state",status:"not implemented",table:null,note:"planted by the control"},\n'
    + ROW_ANCHOR);
  const failed = [];
  compareScope(load(INVENTED).probe, DOC_SURFACES.surfaces,
    (cond, what) => { if (!cond) failed.push(what); });
  const hit = failed.find((f) => f.includes("row `sprocket_state`"));
  check(!!hit, `a row for a surface D3 does not publish goes RED — ${JSON.stringify(hit || null)}`);
}
// DIRECTION 3 — THE DERIVATION'S OWN ANCHORS, at EVERY site that takes a slice of D3. Neither
// direction above is worth anything if the slice they run over is not § 5.4 — and § 5.4 was one of
// THREE sites with the identical shape. The fix was the `sliceBetween` primitive at the top of
// this file, so this control is one loop over the three anchor pairs rather than three patches:
// each closing anchor is renamed in turn, and the slice must come back `null` rather than widening
// to whatever `indexOf`'s -1 hands it. ⚠ The widths are PRINTED, on every run, because the number
// IS the finding and a number restated in a comment about restated numbers is the defect writing
// itself down: the last revision of this file carried a figure here that the run had already
// moved past.
for (const [label, [open, close]] of [
  ['§ 7.1', S71_ANCHORS], ['§ 7.6', S76_ANCHORS], ['§ 5.4', S54_ANCHORS],
]) {
  const trueSlice = sliceBetween(FLOOR_MD, open, close);
  if (!control(hitsOf(FLOOR_MD, close), `${label}'s closing anchor ${JSON.stringify(close)}`)) continue;
  check(trueSlice !== null && trueSlice.length > 0, `${label}: the true slice is ${trueSlice ? trueSlice.length : 'NOT MEASURED'} chars`);
  // A rename that leaves the heading readable and leaves NOTHING of the old anchor to match on.
  const MOVED = FLOOR_MD.replace(close, close.replace(' ', ' RENAMED-'));
  check(sliceBetween(MOVED, open, close) === null,
    `${label}: a renamed closing anchor makes the slice NULL rather than widening`);
  // …and this is what it was widening TO, which is why the miss was invisible.
  const preFix = MOVED.slice(MOVED.indexOf(open), MOVED.indexOf(close));
  check(trueSlice !== null && preFix.length > trueSlice.length * 5,
    `${label}: and the shape this replaces widened from ${trueSlice ? trueSlice.length : '?'} chars to ${preFix.length} — still PASS`);
}
// ⭐ AND THE ONE-SIDED FORM, which is where the remaining three sites of the class went. `null` for
// an end means the document's own end — and the whole risk of widening a primitive is that the new
// branch is the one nobody exercises, so a `null` end must NOT be allowed to make a MISSING anchor
// look like a satisfied one. Both directions, on the two real anchors, with positive controls
// showing the slice lands where it is supposed to rather than merely being non-null.
for (const [label, anchor, held] of [
  ['§ 7.1\'s two tables', REASON_HDR, s71], ['§ 7.6\'s own table', API_HDR, s76],
]) {
  if (!control(hitsOf(FLOOR_MD, anchor), `${label}, parted at ${JSON.stringify(anchor.slice(0, 28))}`)) continue;
  const MOVED = held === null ? '' : held.replace(anchor, anchor.replace('| `', '| `RENAMED-'));
  check(sliceBetween(MOVED, null, anchor) === null && sliceBetween(MOVED, anchor, null) === null,
    `${label}: a renamed boundary makes BOTH one-sided slices NULL — the open end never covers for a missing anchor`);
  // …and what the shape this replaces would have handed them instead: one character, and all but
  // one character, neither of which announces itself.
  check(MOVED.slice(0, MOVED.indexOf(anchor)).length === MOVED.length - 1
    && MOVED.slice(MOVED.indexOf(anchor)).length === 1,
  `${label}: and the shape it replaces would have handed back ${MOVED.length - 1} chars and 1 char — silently`);
}
check(sliceBetween('abcdef', null, 'cd') === 'ab' && sliceBetween('abcdef', 'cd', null) === 'cdef'
  && sliceBetween('abcdef', null, null) === 'abcdef',
'sliceBetween lands a one-sided slice exactly — "ab", "cdef", and the whole string for two nulls');
// § 5.4's own derivation, not only its slice: the whole layer above must go red, not just return.
{
  const [, close] = S54_ANCHORS;
  const MOVED = FLOOR_MD.replace(close, '### 5.5b The client');
  const failed = [];
  checkDerivation(docSurfaces(MOVED), (cond, what) => { if (!cond) failed.push(what); });
  check(docSurfaces(MOVED) === null && failed.some((f) => f.includes('both found')),
    `a § 5.4 whose closing anchor has moved goes RED rather than widening — ${JSON.stringify(failed[0] || null)}`);
}
// …and § 7.1's, which is the site the round-2 fix did not reach: a renamed `### 7.2 Badges` used to
// hand `rowsOf` a quarter of the document, and it parsed ten members out of it and passed.
{
  const [open, close] = S71_ANCHORS;
  const MOVED = FLOOR_MD.replace(close, '### 7.2b Badges');
  check(sliceBetween(MOVED, open, close) === null && rowsOf(sliceBetween(MOVED, open, close) || '').length === 0,
    '§ 7.1: a renamed `### 7.2 Badges` parses ZERO members — which is the `parsed 10` check above going red');
  const preFix = MOVED.slice(MOVED.indexOf(open), MOVED.indexOf(close));
  const preFixRows = rowsOf(preFix.slice(0, preFix.indexOf('| `unknown_reason` | Sentence |')));
  check(preFixRows.length === 10,
    `and the shape it replaces parsed ${preFixRows.length} members out of ${preFix.length} chars and stayed PASS — the whole finding, in one number`);
}

// ---------------------------------------------------------------------------------------------
// 7. § 9 F13 — a seat the floor's map has no slot for is PLACED, never dropped and never a crash
// ---------------------------------------------------------------------------------------------
// The two lookups keyed on IDENTITY rather than on an enum member, which is why the member-set
// primitive of § 1 does not reach them: the seat's `desk` against the map's slots, and the
// install against the client's own theme map. Both used to be unguarded, and they failed in
// OPPOSITE directions on the same seat — the floor SVG iterated a fixed four-slot list and
// silently DROPPED the seat, while the overlay pass read `THEMES[inst].desks[s.desk].x` and threw
// the whole render away. § 3.2's overflow rule and § 9 F13 state the behaviour that is neither:
// the surplus seats render in a labelled overflow row under a persistent notice reading
// *floor map is short N desks*, and F13's Never column is one phrase — dropping a seat.
section('7. § 9 F13 — an undeclared desk key and an unthemed install both render, and are labelled');
/** Drive a floor that is short of desks. @returns the failures, so § 7's controls can re-run it. */
function f13(source, report, mode) {
  const failed = [];
  const say = report ? check : (cond, what) => { if (!cond) failed.push(what); };
  // The artifact renders itself once as it loads, and the sample fleet already contains a seat
  // its floor's map has no slot for — so a revision carrying the pre-fix lookup throws on the way
  // IN, before anything can be driven. That is this check failing, not the harness failing.
  let ctx = null, loadThrew = null;
  try { ctx = load(source); } catch (e) { loadThrew = e; }
  if (loadThrew) { say(false, `${mode}: the floor renders without throwing — ${loadThrew}`); return failed; }
  const { probe, world } = ctx;
  if (!probe.FLEET || !probe.render) { say(false, `${mode}: the artifact exposes FLEET and render()`); return failed; }
  const seat = `f13-${mode}`;
  if (mode === 'undeclared-desk') {
    // aimla's map declares four slots and the fleet already fills all four, so a fifth seat at a
    // desk key the map does not name has nowhere to sit at all.
    probe.FLEET.aimla.push({ ...BASE, seat, desk: 'no-such-desk', render_state: 'idle', quiet_for: '1m' });
  } else {
    // An install the client's theme map has never seen: no palette and NO DESK SLOTS, so § 3.2's
    // overflow rule is already the whole answer and every one of its seats is placed by it.
    probe.FLOORS.push('mystery');
    probe.FLEET.mystery = [{ ...BASE, seat, desk: 'a1', render_state: 'idle', quiet_for: '1m' }];
  }
  let threw = null;
  try { probe.render(); } catch (e) { threw = e; }
  say(!threw, `${mode}: the floor renders without throwing${threw ? ` — ${threw}` : ''}`);
  if (threw) return failed;
  const kids = world.children || [];
  say(kids.some((c) => c.className === 'hit' && String(c.getAttribute('aria-label')).startsWith(seat)),
    `${mode}: the seat is on the floor with its own hit target — F13's Never is "dropping a seat"`);
  say(kids.some((c) => c.className === 'plate' && c.innerHTML.includes(seat)),
    `${mode}: and carries its nameplate and state line, the same render as any other seat`);
  const svg = world.innerHTML || '';
  // ⭐ Both counts below are RE-DERIVED from the artifact's own placement of its own fleet, never
  // written down here: the sample fleet already contains a seat with no slot (sola's `b2`), so a
  // stored figure would be a second home for a number the fixture moves.
  const shorts = probe.FLOORS.map((i) => probe.placeFloor(probe.themeFor(i), probe.FLEET[i]).short);
  const homeless = shorts.reduce((a, b) => a + b, 0);
  const shortFloors = shorts.filter((n) => n > 0).length;
  say(homeless > 0 && shortFloors > 0, `${mode}: the driven fleet really is short of desks (${homeless} seats over ${shortFloors} floors)`);
  // ⭐ THE WHOLE SENTENCE, not its opening. Matching `/floor map is short/` alone left the COUNT
  // and the NOUN free to drift to anything at all — `short 0 chairs` passed it — and the count is
  // the only part of the notice that carries information. Each short floor's expected sentence is
  // built from that floor's OWN re-derived `short`, and the whole set is required, in full.
  const wanted = shorts.filter((n) => n > 0)
    .map((n) => `floor map is short ${n} desk${n === 1 ? '' : 's'}`);
  say(wanted.every((w) => svg.includes(w))
    && (svg.match(/floor map is short/g) || []).length === shortFloors,
    `${mode}: every short floor carries § 5.5's notice IN FULL — ${JSON.stringify(wanted)}`);
  say((svg.match(/OVERFLOW/g) || []).length === shortFloors,
    `${mode}: and each one labels the row (§ 3.2), rather than drawing a desk low and unexplained`);
  // The desk ITSELF, not only the label around it: F13 gives the surplus seat "same desk, same
  // render" (§ 3.2), and a row drawn with a notice and no desk in it is the silent drop wearing
  // the fix's label. This is the one observable that direction has.
  say((svg.match(/class="overflow-desk"/g) || []).length === homeless,
    `${mode}: every homeless seat's own DESK is drawn in that row (${homeless}) — "same desk, same render" (§ 3.2)`);
  say(!svg.includes('undefined'), `${mode}: nothing in the floor SVG reads "undefined"`);
  return failed;
}
f13(SCRIPT, true, 'undeclared-desk');
f13(SCRIPT, true, 'unthemed-install');
// The control for each half, planted at the shape it actually had.
const PLACE_ANCHOR = 'for(const {seat:s0,slot,D} of placeFloor(TH,FLEET[inst]).placed){\n      const s={...s0,__install:inst};';
if (control(hitsOf(SCRIPT, PLACE_ANCHOR), 'the unguarded desk lookup')) {
  // ⚠ `slot` is re-declared in the planted shape ON PURPOSE. The overlay pass reads it (each
  // overlay declares the storey region it belongs to), so a replacement that dropped it would
  // throw a ReferenceError on the FIRST seat — and this control asserts a THROW, so it would go
  // green having never reached the undeclared desk key it exists to exercise.
  const UNGUARDED = SCRIPT.replace(PLACE_ANCHOR,
    'for(const s0 of FLEET[inst]){\n      const s={...s0,__install:inst};const D=TH.desks[s.desk];const slot=s.desk;');
  const failed = f13(UNGUARDED, false, 'undeclared-desk');
  check(failed.some((f) => f.includes('without throwing')),
    `the unguarded desk lookup goes RED, the way the defect did — ${JSON.stringify(failed.find((f) => f.includes('without throwing')) || null)}`);
}
const OVERFLOW_ANCHOR = '  for(const p of over) g+=`<g class="overflow-desk">${deskSVG(T,p.D,p.seat)}</g>`;';
if (control(hitsOf(SCRIPT, OVERFLOW_ANCHOR), "an overflow row drawn with its label and no desk in it")) {
  // The surplus desk is simply not drawn — no throw, no glyph, a seat that exists and is
  // invisible. The notice is what makes that visible to this suite, which is why F13 states it.
  const DROPPED = SCRIPT.replace(OVERFLOW_ANCHOR, '');
  const failed = f13(DROPPED, false, 'undeclared-desk');
  check(failed.some((f) => f.includes('own DESK is drawn')),
    `a floor that draws the row's label and not the desk in it goes RED — ${JSON.stringify(failed.find((f) => f.includes('own DESK is drawn')) || null)}`);
}
const THEME_ANCHOR = 'const themeFor=inst=>THEMES[inst]||UNTHEMED;';
if (control(hitsOf(SCRIPT, THEME_ANCHOR), 'the unguarded theme lookup')) {
  const RAW = SCRIPT.replace(THEME_ANCHOR, 'const themeFor=inst=>THEMES[inst];');
  const failed = f13(RAW, false, 'unthemed-install');
  check(failed.some((f) => f.includes('without throwing')),
    `the unguarded theme lookup goes RED — ${JSON.stringify(failed.find((f) => f.includes('without throwing')) || null)}`);
}

// ---------------------------------------------------------------------------------------------
// 8. THE BOUNDARY BETWEEN THE FLOOR AND THE BAND — the structural invariant
// ---------------------------------------------------------------------------------------------
// § 7 asserts that every homeless seat's desk IS DRAWN. It says nothing about WHERE, and the row
// shipped drawn on top of sola's tea bar for exactly that reason: every structural fact above was
// correct — the three label strings, `S=4 seats=5 short=1` — and none of them could catch a desk
// slab painted over a counter and a character sitting on the cups.
//
// ⭐ WHAT THIS LAYER IS NOW, AND WHY IT IS SMALLER THAN IT WAS. D3 § 3.2 puts the overflow row
// BELOW THE FLOOR, and the artifact now does that: an overflowing floor's canvas is `FH + BAND_H`
// tall and the row is drawn in the appended strip. Two earlier rounds of this file measured every
// shape of an overflow desk against every shape of `furnish()`, pairwise, because the row was
// drawn INSIDE the floor and had to be threaded between the furniture. That whole layer — with its
// per-theme `overflow:{x,y}` declarations, its "clear run" comments and its arbitrary-shape bbox
// extractor — was machinery for an out-of-spec placement, and it is deleted. The question it
// answered is not asked any more: the floor's shapes stop at `FH`, the band's start there, and
// collision between them is impossible by construction at ANY row length.
//
// What is left is the one thing that is now load-bearing, and it is ONE SCALAR PER FLOOR IN EACH
// DIRECTION: nothing `floorBody` emits reaches below `FH`, and nothing `floorBand` emits rises
// above it (nor falls past the strip the canvas was actually extended by). If either moves, the
// two regions overlap again and the deleted layer's failure is back.
//
// ⛔ AN UNMEASURABLE SHAPE MAKES THIS FAIL. It does not fall out of the measurement, and it is
// never reported as clear. The layer this replaces had a stated guarantee that "unmeasurable is
// loud" and the guarantee was false in four demonstrated forms — an arc command, a `<polygon>`, a
// `NaN` width and an unmodelled `stroke-width` — three of which stayed GREEN with an obstacle
// drawn on the desk, because each fell through a silent `else` and left a shorter list of boxes
// that still looked clean. So: every element of the fragment is classified, a tag this file does
// not measure is NAMED, any unreadable number or unmodelled transform voids the whole fragment's
// measurement, and the answer is then `not measured` rather than a min/max. § 8's controls plant
// each of those forms and require the measurement to go red.
//
// ⚠ WHAT THIS IS AND IS NOT. It is arithmetic on the artifact's own emitted coordinates. Nothing
// here is laid out or painted, so it says nothing about how any of it LOOKS — only that no shape's
// coordinates cross the boundary. Where a bound cannot be computed exactly it is computed WIDE:
// bezier control points, an arc's ±2r around its endpoints, a stroke's half-width, and a `<text>`
// baseline ± 1.5 em are all supersets of the real ink. Wide is the safe direction here — it can
// only over-report a crossing, never miss one.
section('8. nothing the floor emits reaches below FH, and nothing the band emits rises above it');

/** Every number in a string, in order. */
const svgNums = (s) => (String(s).match(/-?\d*\.?\d+(?:e[-+]?\d+)?/gi) || []).map(Number);
/** One attribute's raw value, ANCHORED at the start of the name: a bare `\bwidth="` also matches
 *  `stroke-width="`, because `-` is a word boundary — so a rect's box could be built from its
 *  stroke's width and be wrong by the whole shape. */
const attrOf = (s, k) => {
  const m = String(s).match(new RegExp(`(?<![-\\w])${k}="([^"]*)"`));
  return m ? m[1] : null;
};
/** A numeric attribute: its value, `d` when the attribute is absent, or `null` when it is present
 *  and NOT a finite number. `null` is unmeasurable — `width="NaN"` read as 0 is a shape that
 *  vanishes from the measurement instead of failing it, which is one of the four forms above. */
const numOf = (s, k, d = 0) => {
  const v = attrOf(s, k);
  if (v === null) return d;
  if (String(v).trim() === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
};
/** A path's box from its own command stream, or `null` if ANY command or number cannot be read.
 *  The full command set is handled — including `A`, whose absence was a silent `else { i++ }` and
 *  therefore an arc that measured as nothing at all. An arc is bounded by ±2r about its endpoints:
 *  the ellipse's centre lies within (rx, ry) of each endpoint and every point of the arc lies
 *  within (rx, ry) of the centre, so 2r is a proof, not a guess. */
function pathBox(d) {
  const toks = String(d).match(/[a-zA-Z]|-?\d*\.?\d+(?:e[-+]?\d+)?/g) || [];
  let i = 0, x = 0, y = 0, sx = 0, sy = 0, cmd = '', bad = false;
  const pts = [];
  const n = () => {
    const v = Number(toks[i++]);
    if (!Number.isFinite(v)) { bad = true; return 0; }
    return v;
  };
  const need = (k) => { if (i + k > toks.length) bad = true; return !bad; };
  while (i < toks.length && !bad) {
    if (/[a-zA-Z]/.test(toks[i])) cmd = toks[i++];
    if (!cmd) { bad = true; break; }
    const rel = cmd === cmd.toLowerCase(), c = cmd.toUpperCase();
    if (c === 'Z') { x = sx; y = sy; continue; }
    if (i >= toks.length) { bad = true; break; }
    if (c === 'M') { if (!need(2)) break; const a = n(), b = n(); x = rel ? x + a : a; y = rel ? y + b : b; sx = x; sy = y; pts.push([x, y]); cmd = rel ? 'l' : 'L'; }
    else if (c === 'L') { if (!need(2)) break; const a = n(), b = n(); x = rel ? x + a : a; y = rel ? y + b : b; pts.push([x, y]); }
    else if (c === 'H') { if (!need(1)) break; const a = n(); x = rel ? x + a : a; pts.push([x, y]); }
    else if (c === 'V') { if (!need(1)) break; const a = n(); y = rel ? y + a : a; pts.push([x, y]); }
    else if (c === 'Q') { if (!need(4)) break; const a = n(), b = n(), e = n(), f = n(); pts.push([rel ? x + a : a, rel ? y + b : b]); x = rel ? x + e : e; y = rel ? y + f : f; pts.push([x, y]); }
    else if (c === 'T') { if (!need(2)) break; const e = n(), f = n(); x = rel ? x + e : e; y = rel ? y + f : f; pts.push([x, y]); }
    else if (c === 'C') { if (!need(6)) break; const a = n(), b = n(), e = n(), f = n(), g = n(), h = n(); pts.push([rel ? x + a : a, rel ? y + b : b], [rel ? x + e : e, rel ? y + f : f]); x = rel ? x + g : g; y = rel ? y + h : h; pts.push([x, y]); }
    else if (c === 'S') { if (!need(4)) break; const e = n(), f = n(), g = n(), h = n(); pts.push([rel ? x + e : e, rel ? y + f : f]); x = rel ? x + g : g; y = rel ? y + h : h; pts.push([x, y]); }
    else if (c === 'A') {
      if (!need(7)) break;
      const rx = Math.abs(n()), ry = Math.abs(n());
      n(); n(); n();                                   // x-axis rotation and the two flags
      const e = n(), f = n(), x0 = x, y0 = y;
      x = rel ? x + e : e; y = rel ? y + f : f;
      pts.push([Math.min(x0, x) - 2 * rx, Math.min(y0, y) - 2 * ry],
        [Math.max(x0, x) + 2 * rx, Math.max(y0, y) + 2 * ry]);
    } else { bad = true; }                             // an unknown command is NOT skipped
  }
  if (bad || !pts.length || pts.some((p) => !Number.isFinite(p[0]) || !Number.isFinite(p[1]))) return null;
  return { x0: Math.min(...pts.map((p) => p[0])), x1: Math.max(...pts.map((p) => p[0])),
    y0: Math.min(...pts.map((p) => p[1])), y1: Math.max(...pts.map((p) => p[1])) };
}
/** The transforms this file models. Anything else — `skew`, `matrix`, a malformed list — is `?`,
 *  which voids the box rather than being ignored. */
function parseTransform(s) {
  const out = [];
  for (const m of String(s).matchAll(/([a-zA-Z]+)\(([^)]*)\)/g)) {
    const v = svgNums(m[2]);
    if (m[1] === 'translate') out.push({ k: 't', a: v[0] || 0, b: v[1] || 0 });
    else if (m[1] === 'rotate') out.push({ k: 'r', a: v[0] || 0, b: v[1] || 0, c: v[2] || 0 });
    else if (m[1] === 'scale') out.push({ k: 's', a: v[0], b: v.length > 1 ? v[1] : v[0] });
    else out.push({ k: '?' });
  }
  return out;
}
/** A box through a transform list, or `null` if any of them is unmodelled or carries a value that
 *  is not a finite number. A rotated box becomes the box of its four rotated corners — again a
 *  superset of the shape inside it. */
function applyTransforms(b, tf) {
  for (let k = tf.length - 1; k >= 0 && b; k--) {
    const t = tf[k];
    if (t.k === 't') {
      if (!Number.isFinite(t.a) || !Number.isFinite(t.b)) return null;
      b = { x0: b.x0 + t.a, x1: b.x1 + t.a, y0: b.y0 + t.b, y1: b.y1 + t.b };
    } else if (t.k === 's') {
      if (!Number.isFinite(t.a) || !Number.isFinite(t.b)) return null;
      const xs = [b.x0 * t.a, b.x1 * t.a], ys = [b.y0 * t.b, b.y1 * t.b];
      b = { x0: Math.min(...xs), x1: Math.max(...xs), y0: Math.min(...ys), y1: Math.max(...ys) };
    } else if (t.k === 'r') {
      if (!Number.isFinite(t.a) || !Number.isFinite(t.b) || !Number.isFinite(t.c)) return null;
      const r = t.a * Math.PI / 180, co = Math.cos(r), si = Math.sin(r);
      const cs = [[b.x0, b.y0], [b.x1, b.y0], [b.x0, b.y1], [b.x1, b.y1]]
        .map(([px, py]) => [t.b + (px - t.b) * co - (py - t.c) * si, t.c + (px - t.b) * si + (py - t.c) * co]);
      b = { x0: Math.min(...cs.map((p) => p[0])), x1: Math.max(...cs.map((p) => p[0])),
        y0: Math.min(...cs.map((p) => p[1])), y1: Math.max(...cs.map((p) => p[1])) };
    } else return null;
  }
  return b;
}
/** A stroked shape is painted HALF its stroke width OUTSIDE its geometry, so the geometric box is
 *  not the painted one — the fourth of the four forms that stayed green. `null` on a width that
 *  cannot be read. */
function strokePad(rest) {
  const st = attrOf(rest, 'stroke');
  if (st === null || st === 'none') return 0;
  const w = numOf(rest, 'stroke-width', 1);
  return w === null ? null : Math.abs(w) / 2;
}
const SHAPE_TAGS = ['rect', 'circle', 'ellipse', 'line', 'path'];
const TEXT_TAGS = ['text'];
const STRUCTURAL_TAGS = ['g', 'title'];          // carry a transform or a string; paint no shape
/** One shape element's own box, before transforms. `null` = unmeasurable. */
function shapeBox(tag, rest) {
  if (tag === 'rect') {
    const x = numOf(rest, 'x'), y = numOf(rest, 'y'), w = numOf(rest, 'width'), h = numOf(rest, 'height');
    return [x, y, w, h].some((v) => v === null) ? null : { x0: x, x1: x + w, y0: y, y1: y + h };
  }
  if (tag === 'circle') {
    const cx = numOf(rest, 'cx'), cy = numOf(rest, 'cy'), r = numOf(rest, 'r');
    return [cx, cy, r].some((v) => v === null) ? null : { x0: cx - r, x1: cx + r, y0: cy - r, y1: cy + r };
  }
  if (tag === 'ellipse') {
    const cx = numOf(rest, 'cx'), cy = numOf(rest, 'cy'), rx = numOf(rest, 'rx'), ry = numOf(rest, 'ry');
    return [cx, cy, rx, ry].some((v) => v === null) ? null : { x0: cx - rx, x1: cx + rx, y0: cy - ry, y1: cy + ry };
  }
  if (tag === 'line') {
    const a = numOf(rest, 'x1'), c = numOf(rest, 'y1'), d = numOf(rest, 'x2'), e = numOf(rest, 'y2');
    return [a, c, d, e].some((v) => v === null) ? null
      : { x0: Math.min(a, d), x1: Math.max(a, d), y0: Math.min(c, e), y1: Math.max(c, e) };
  }
  return pathBox(attrOf(rest, 'd') || '');
}
/** A `<text>`'s VERTICAL extent, bounded rather than laid out. There are no font metrics here, so
 *  the box is the baseline ± 1.5 em — wider than any ascender or descender a font draws, which is
 *  the safe direction for a boundary question. Its WIDTH is not bounded and is not claimed, so a
 *  `<text>` carrying a transform (which would fold that unbounded width into y) is unmeasurable,
 *  as is one with no readable `font-size`. The layer this replaces excluded `<text>` by name, and
 *  a label is exactly the kind of thing that would be drawn across the boundary. */
function textBox(rest) {
  if (attrOf(rest, 'transform') !== null) return null;
  const x = numOf(rest, 'x', null), y = numOf(rest, 'y', null), fs = numOf(rest, 'font-size', null);
  if (x === null || y === null || fs === null) return null;
  return { x0: x, x1: x, y0: y - 1.5 * fs, y1: y + 1.5 * fs };
}
/**
 * Every element of an SVG fragment, as absolute boxes.
 * @returns {{boxes:{tag:string,b:object}[], unmeasurable:string[], seen:number, balanced:boolean}}
 *   `unmeasurable` NAMES what could not be measured — the whole point of the layer.
 */
function svgShapes(svg) {
  const boxes = [], unmeasurable = [], stack = [];
  const re = /<(\/?)([a-zA-Z][\w-]*)\b([^>]*?)\/?>/g;
  let m, seen = 0, balanced = true;
  while ((m = re.exec(svg))) {
    const [, close, tag, rest] = m;
    if (tag === 'g') {
      if (close) { if (stack.length) stack.pop(); else balanced = false; }
      else stack.push(parseTransform(attrOf(rest, 'transform') || ''));
      continue;
    }
    if (STRUCTURAL_TAGS.includes(tag) || close) continue;
    seen++;
    let b;
    if (SHAPE_TAGS.includes(tag)) b = shapeBox(tag, rest);
    else if (TEXT_TAGS.includes(tag)) b = textBox(rest);
    else { unmeasurable.push(`<${tag}> is not a tag this file measures`); continue; }
    const pad = strokePad(rest);
    if (b && pad !== null) b = { x0: b.x0 - pad, x1: b.x1 + pad, y0: b.y0 - pad, y1: b.y1 + pad };
    else b = null;
    if (b) b = applyTransforms(b, parseTransform(attrOf(rest, 'transform') || ''));
    if (b) b = applyTransforms(b, stack.flat());
    if (b && [b.x0, b.x1, b.y0, b.y1].every((v) => Number.isFinite(v))) boxes.push({ tag, b });
    else unmeasurable.push(`<${tag}> could not be measured`);
  }
  // An unbalanced `<g>` would silently shift every box after it by another group's transform, and
  // the shift would look exactly like a floor that happens to be clear.
  return { boxes, unmeasurable, seen, balanced: balanced && stack.length === 0 };
}
/**
 * A fragment's vertical extent — or the fact that it HAS none that can be trusted.
 * ⛔ `ok` is false unless every element of the fragment was measured. `minY`/`maxY` are then null,
 * and a caller that reports "clear" from a null is reporting a measurement that never happened.
 */
function extentOf(svg) {
  const r = svgShapes(svg);
  const ok = r.unmeasurable.length === 0 && r.balanced && r.boxes.length === r.seen && r.seen > 0;
  return { ...r, ok,
    minY: ok ? Math.min(...r.boxes.map((s) => s.b.y0)) : null,
    maxY: ok ? Math.max(...r.boxes.map((s) => s.b.y1)) : null };
}
/** How a fragment's measurement reads in a check's message — including when it failed. */
const measured = (e) => `${e.boxes.length}/${e.seen} measured`
  + (e.unmeasurable.length ? `, NOT MEASURED: ${[...new Set(e.unmeasurable)].slice(0, 3).join('; ')}` : '')
  + (e.balanced ? '' : ', UNBALANCED <g>');

// D2 caps `subagents[]` at 8 and `deskSVG` hangs the intern tray OUTSIDE the slot, so a seat at
// that cap is the widest and tallest desk the artifact can draw.
const WIDEST_SEAT = {
  ...BASE, desk: 'nowhere', render_state: 'working', open_turn: true, open_calls: 1,
  action: { tool_name: 'Bash', descriptor: 'Bash: x', started_at: '14:00:00', running: '1m' },
  subagents: Array.from({ length: 8 }, (_, i) => ({ title: `intern ${i}`, subagent_type: 'coder' })),
};
/** The layer, reusable so the controls below can re-run it against a planted defect. */
function boundary(probe, say) {
  const failed = [];
  const record = (cond, what) => { if (!cond) failed.push(what); say(cond, what); };
  if (!probe.floorBody || !probe.floorBand || !probe.placeFloor || !probe.themeFor
    || !probe.overflowSlot || !Number.isFinite(probe.FH) || !Number.isFinite(probe.BAND_H)) {
    record(false, 'the artifact exposes its floor, its band, its placement and its own FH/BAND_H');
    return failed;
  }
  // ⛔ The boundary must be READ from the artifact, not assumed: `y > undefined` is `false`, so a
  // missing `FH` would make every comparison below answer "inside" and this layer would pass over
  // anything at all. That is the vacuous-bounds defect this file caught in itself last round.
  const FH = probe.FH, BAND_H = probe.BAND_H;
  record(FH > 0 && BAND_H > 0, `the boundary was read from the artifact — FH ${FH}, band ${BAND_H}`);
  // ⭐ THE POPULATION IS THE ARTIFACT'S OWN FLOOR LIST, re-derived here, plus the unthemed floor —
  // which declares NO slots, so every one of its seats overflows and its band is the widest row the
  // sample can produce. A floor added to the artifact is measured without this file being edited.
  const floors = probe.FLOORS.map((i) => [i, probe.themeFor(i), probe.FLEET[i]])
    .concat([['(unthemed)', probe.UNTHEMED, probe.FLEET[probe.FLOORS[0]]]]);
  record(floors.length >= 2, `the floor population is the artifact's own (${floors.map((f) => f[0]).join(', ')})`);
  let banded = 0;
  // …and one real band fragment is kept, so the header's own extent can be re-derived from what the
  // artifact EMITS rather than from a copy of `y0+88` and a font size held here.
  let bandFragment = '';
  for (const [name, T, rawSeats] of floors) {
    const seats = rawSeats.map((s) => ({ ...s, __install: name }));
    const P = probe.placeFloor(T, seats);
    const body = extentOf(probe.floorBody(name, T, P));
    record(body.ok, `${name}: every shape the FLOOR emits was measured (${measured(body)})`);
    if (body.ok) {
      record(body.maxY <= FH,
        `${name}: and none of it reaches below the floor — deepest y ${body.maxY.toFixed(1)} against FH ${FH}`);
    }
    const bandSvg = probe.floorBand(T, P);
    if (P.short === 0) {
      record(bandSvg === '', `${name}: has a slot for every seat, and draws no band at all`);
      continue;
    }
    banded++;
    bandFragment = bandSvg;
    const band = extentOf(bandSvg);
    record(band.ok, `${name}: every shape the BAND emits was measured (${measured(band)})`);
    if (band.ok) {
      record(band.minY >= FH,
        `${name}: and the whole band is BELOW the floor (§ 3.2) — highest y ${band.minY.toFixed(1)} against FH ${FH}`);
      record(band.maxY <= FH + BAND_H,
        `${name}: and inside the strip the canvas was extended by — deepest y ${band.maxY.toFixed(1)} against ${FH + BAND_H}`);
    }
  }
  record(banded > 0, `the sample fleet really draws a band (${banded} floor(s)) — an empty sweep is not a clean one`);
  // …and at every row length, which is the claim the deleted layer could not make. `overflowSlot`
  // varies x and w only, so this is the assertion that no future edit gives it a y that depends on
  // `n`. The row is built here rather than found, so `n` is this loop's to choose.
  const T0 = probe.themeFor(probe.FLOORS[0]);
  const wide = [];
  for (let n = 1; n <= 12; n++) {
    const placed = Array.from({ length: n }, (_, i) => ({
      seat: { ...WIDEST_SEAT, seat: `widest-${i}`, __install: probe.FLOORS[0] },
      slot: null, D: probe.overflowSlot(i, n),
    }));
    const e = extentOf(probe.floorBand(T0, { placed, S: 0, short: n }));
    if (!(e.ok && e.minY >= FH && e.maxY <= FH + BAND_H)) {
      wide.push(`n=${n}: ${e.ok ? `y ${e.minY.toFixed(1)}–${e.maxY.toFixed(1)}` : measured(e)}`);
    }
  }
  record(wide.length === 0,
    `the band clears the floor for a row of n = 1..12 widest-case seats — the invariant does not depend on the row's length${wide.length ? ` — ${wide[0]}` : ''}`);
  // ⚠ THE OVERLAY PASS IS IN NEITHER FRAGMENT, and this is the ONE fact about it this file can
  // state. The buttons, nameplates, thought bubbles and markers are HTML in CSS pixels over an SVG
  // in user units; their SIZES are a browser's answer and not this file's, which is why
  // `tools/design/floor-preview.browser.mjs` exists and why it is the gate that judges the picture.
  // What is arithmetic — and therefore belongs here, as the cheap backstop that runs with no
  // browser — is the bubble's ANCHOR, which is a pure user-unit offset above the desk.
  //
  // ⛔ AND THE THRESHOLD IS THE HEADER, NOT `FH`. An earlier revision compared the anchor against
  // `FH` and passed a bubble that sat squarely on the band's own arithmetic line: `FH` is where the
  // STRIP begins, and the strip begins with two lines of text the row must not cover. The header's
  // extent is re-derived from the emitted band — its own `.bandhdr` elements, bounded by the same
  // `textBox` every other `<text>` on this floor goes through — rather than restated from `y0+88`
  // and a font size, which would be this file asserting the artifact's arithmetic against itself.
  const bub = Number((SCRIPT.match(/bub\.style\.top=PY\(D\.y-(\d+)\)/) || [])[1]);
  const hdrSvg = [...String(bandFragment).matchAll(/<text\b([^>]*class="bandhdr"[^>]*)>/g)]
    .map((m) => textBox(m[1]));
  record(hdrSvg.length >= 2 && hdrSvg.every((b) => b && Number.isFinite(b.y1)),
    `the band's header block was found and bounded — ${hdrSvg.length} line(s), ${hdrSvg.filter((b) => !b).length} unmeasurable`);
  const hdrBottom = hdrSvg.length && hdrSvg.every((b) => b) ? Math.max(...hdrSvg.map((b) => b.y1)) : null;
  record(Number.isFinite(bub) && Number.isFinite(probe.BAND_DESK_Y) && hdrBottom !== null
    && probe.BAND_DESK_Y - bub >= hdrBottom,
  `a band desk's thought bubble is anchored BELOW the band's header — ${probe.BAND_DESK_Y - bub} (desk ${probe.BAND_DESK_Y} less the artifact's own ${bub}) against the header's deepest bound ${hdrBottom === null ? 'NOT MEASURED' : hdrBottom.toFixed(1)}`);
  return failed;
}
boundary(P, check);

// THE CONTROLS. Both directions of the invariant, and each of the four forms in which the deleted
// layer's "unmeasurable is loud" guarantee was false. Every one is required to go RED.
for (const [anchor, planted, what, want] of [
  // DIRECTION 1 — a furniture shape pushed below the line. This is the collision the whole deleted
  // layer existed to catch, in the form it survives as: furniture reaching into the strip.
  ['[[560,300],[30,300],[940,952]]', '[[560,300],[30,300],[940,1152]]',
    "a furniture shape pushed BELOW the floor's own FH", 'reaches below the floor'],
  // DIRECTION 2 — the band raised above the line, which is the defect this round is fixing: the
  // row drawn inside the floor, over whatever is already there.
  ['const y0=FH;', 'const y0=FH-260;',
    'the overflow band raised back up INTO the floor', 'is BELOW the floor'],
  // …and the same direction one layer in: the band where it belongs, but its DESKS too high in it,
  // so what crosses the line is the character and the bubble anchored above the desk. This is the
  // control for the overlay leg, which the band's own top cannot fail for.
  ['const BAND_DESK_Y=FH+300;', 'const BAND_DESK_Y=FH+100;',
    "the band's desks raised so their bubbles hang over the floor", 'anchored BELOW the band'],
  // ⭐ AND THE CONTROL THAT SEPARATES THE NEW THRESHOLD FROM THE OLD ONE. `FH+200` puts the
  // bubble's anchor at `FH+48` — inside the strip, so the leg this replaces (anchor ≥ `FH`) stayed
  // GREEN on it, and squarely on the band's header, which is the defect. A strengthened check whose
  // only control also fails the weak version has not been shown to be stronger.
  ['const BAND_DESK_Y=FH+300;', 'const BAND_DESK_Y=FH+200;',
    "the band's desks at FH+200 — clear of the floor, ON the header: the value the OLD `>= FH` leg passed",
    'anchored BELOW the band'],
  // …and the UNMEASURABLE forms, each planted into a floor that must then refuse to report a clean
  // measurement rather than a shorter list of boxes. Of the four forms in which the deleted layer's
  // "unmeasurable is loud" guarantee was demonstrably false, two are now MEASURED and have their
  // unit controls below instead — an arc command (`pathBox` bounds it) and a `stroke-width`
  // (`strokePad` pads by it). The other two are planted here, with the two generalisations of the
  // same class: a tag this file does not own, a number it cannot read, a path command it does not
  // implement, and a transform it does not model.
  ['/* tea bar bottom-left */', '/* tea bar bottom-left */ g+=`<polygon points="60,980 300,980 180,1996"/>`;',
    'a <polygon> — a tag this file does not measure', 'was measured'],
  ['/* tea bar bottom-left */', '/* tea bar bottom-left */ g+=`<rect x="60" y="980" width="NaN" height="1200"/>`;',
    'a NaN width', 'was measured'],
  ['/* tea bar bottom-left */', '/* tea bar bottom-left */ g+=`<path d="M60 980 W 300 1980"/>`;',
    'an unknown path command', 'was measured'],
  ['/* tea bar bottom-left */', '/* tea bar bottom-left */ g+=`<rect x="60" y="980" width="10" height="1200" transform="skewX(20)"/>`;',
    'an unmodelled transform', 'was measured'],
]) {
  if (!control(hitsOf(SCRIPT, anchor), what)) continue;
  const failed = boundary(load(SCRIPT.replace(anchor, planted)).probe, () => { });
  const hit = failed.find((f) => f.includes(want));
  check(!!hit, `${what} goes RED on "${want}" — ${JSON.stringify(hit || null)}`);
  // …and the floor with nothing planted in it stays green. A layer that reds every floor whatever
  // you do to one of them is not measuring the floors, it is measuring itself.
  check(!failed.some((f) => f.startsWith('aimla:')),
    `…and aimla, which carries no planted defect, stays green — ${failed.length} red in all`);
}
// The measurement's own discriminating controls: a predicate that has only ever answered one way
// is indistinguishable from one that cannot answer the other.
check(extentOf('<rect x="0" y="10" width="4" height="6"/>').maxY === 16
  && extentOf('<rect x="0" y="10" width="4" height="6"/>').minY === 10,
  'extentOf reads a plain box exactly — y 10–16');
check(extentOf('<rect x="0" y="10" width="4" height="6" stroke="#fff" stroke-width="4"/>').maxY === 18,
  "…and a stroke's half-width outside it — y 10–16 becomes 8–18");
check(extentOf('<polygon points="0,0 1,1"/>').ok === false
  && extentOf('<polygon points="0,0 1,1"/>').maxY === null,
  'extentOf answers NOT MEASURED — never a number — for a tag it does not own');
check(extentOf('<g transform="translate(0,50)"><rect x="0" y="10" width="4" height="6"/>').ok === false,
  '…and for an unbalanced <g>, whose transform would otherwise shift every later shape silently');
check(pathBox('M0 0 a10 10 0 0 1 20 0') !== null && pathBox('M0 0 a10 10 0 0 1 20 0').y1 >= 20,
  'pathBox bounds an ARC rather than skipping it — the exact command that measured as nothing');
check(pathBox('M0 0 W 20 20') === null, '…and refuses an unknown command outright');
// `\bwidth=` matches `stroke-width=` — `-` is a word boundary — so an attribute name must be
// anchored on its left or a rect's box can be built from its stroke's width.
check(attrOf('<rect stroke-width="4" width="10" height="2"/>', 'width') === '10'
  && attrOf('<rect stroke-width="4"/>', 'width') === null,
  "attrOf reads `width` and not `stroke-width`, whichever comes first in the tag");

// ---------------------------------------------------------------------------------------------
// 9. § 5.6's two intern nulls — one label, two rules, and they are NOT the same rule
// ---------------------------------------------------------------------------------------------
// § 5.6 (`subagents[].subagent_type`): "the type tag beside the label is not drawn" — a null type
// renders NOTHING, because a substitute states a fact the wire never sent. § 5.1 and AT-D3-4:
// a null `title` renders *untitled*, and falling back to `subagent_type`, to the tool name or to
// *subagent* is that test's first RED. They sit on one line of the drill-down and a fix that
// treated them alike would break the compliant one to fix its neighbour, so both are asserted.
section("9. the intern label: a null subagent_type draws NO tag, a null title draws *untitled*");
const INTERN_CASES = [
  [{ subagent_type: 'coder', title: 'rebuild the doc set' }, 'coder · rebuild the doc set', 'both present'],
  [{ subagent_type: null, title: 'rebuild the doc set' }, 'rebuild the doc set', 'a null type draws no tag AND no separator (§ 5.6)'],
  [{ subagent_type: 'coder', title: null }, 'coder · untitled', 'a null title is *untitled* — never the type, the tool name or "subagent" (§ 5.1, AT-D3-4)'],
  [{ subagent_type: null, title: null }, 'untitled', 'both null: the label is *untitled* alone'],
];
function internLabels(probe, say) {
  say(typeof probe.internLabel === 'function', 'the artifact states the intern label as one function');
  if (typeof probe.internLabel !== 'function') return;
  for (const [el, want, why] of INTERN_CASES) {
    const got = probe.internLabel(el);
    say(got === want, `intern label — ${why}: want ${JSON.stringify(want)}, got ${JSON.stringify(got)}`);
  }
}
internLabels(P, check);
// The sample fleet carries both edges, so the artifact a reader opens shows them rather than
// only claiming them.
const SAMPLE_INTERNS = (P.FLEET ? Object.values(P.FLEET).flat() : [])
  .flatMap((s) => s.subagents || []);
check(SAMPLE_INTERNS.some((a) => a.subagent_type === null) && SAMPLE_INTERNS.some((a) => a.title === null),
  'the sample fleet contains an intern with no type and one with no title — both edges are visible in the artifact');
// The control is the exact pre-fix default, which is what makes the first case above evidence.
const INTERN_ANCHOR = 'function internLabel(a){return (a.subagent_type?a.subagent_type+" · ":"")+(a.title||"untitled");}';
if (control(hitsOf(SCRIPT, INTERN_ANCHOR), "each of the intern label's two nulls collapsed into the other's rule")) {
  const DEFAULTED = SCRIPT.replace(INTERN_ANCHOR,
    'function internLabel(a){return (a.subagent_type||"intern")+" · "+(a.title||"untitled");}');
  const failed = [];
  internLabels(load(DEFAULTED).probe, (cond, what) => { if (!cond) failed.push(what); });
  check(failed.some((f) => f.includes('null type')),
    `a null subagent_type defaulted to "intern" goes RED — ${JSON.stringify(failed.find((f) => f.includes('null type')) || null)}`);
  // …and the OTHER null must not move with it: a control that accepted any red at all would pass
  // a "fix" that also deleted § 5.1's *untitled*.
  const BOTH = SCRIPT.replace(INTERN_ANCHOR,
    'function internLabel(a){return (a.subagent_type?a.subagent_type+" · ":"")+(a.title||a.subagent_type||"subagent");}');
  const failedBoth = [];
  internLabels(load(BOTH).probe, (cond, what) => { if (!cond) failedBoth.push(what); });
  check(failedBoth.some((f) => f.includes('null title')),
    `and a null title falling back to the type goes RED too — ${JSON.stringify(failedBoth.find((f) => f.includes('null title')) || null)}`);
}

// ---------------------------------------------------------------------------------------------
// 10. The lobby's seat count — a figure DERIVED from the fleet, never a figure beside it
// ---------------------------------------------------------------------------------------------
// The header read the literal `9 seats · 8 live`, written into the markup next to the `FLEET` it
// was derived from. Adding § 9 F13's sample seat moved the fleet and left the figure stating the
// old one — a derived value does not announce that its basis moved. It is counted from `FLEET` now,
// and `live` is § 7.3's `link_state` member rather than a guess at one.
section('10. the lobby count is derived from FLEET, not written beside it');
{
  const seats = P.FLOORS.flatMap((i) => P.FLEET[i] || []);
  const want = `${seats.length} seats · ${seats.filter((s) => s.link_state === 'live').length} live`;
  const painted = load(SCRIPT).dom.byId.get('fleetcount');
  check(!!painted && painted.textContent === want,
    `the lobby's count is the fleet's own — want ${JSON.stringify(want)}, painted ${JSON.stringify(painted ? painted.textContent : null)}`);
  // ⛔ AND IT IS SHOWN TO FOLLOW THE DATA. Comparing a derivation to the same derivation is not
  // evidence that the page is deriving anything: a re-frozen literal would satisfy the check above
  // exactly as well. So one seat's `link_state` is moved off `live` and the painted string is
  // required to MOVE with it.
  const ANCHOR = 'seat:"sola-mailer",render_state:"working",link_state:"live"';
  if (control(hitsOf(SCRIPT, ANCHOR), "a sample seat's link_state moved off `live`")) {
    const MOVED = load(SCRIPT.replace(ANCHOR, ANCHOR.replace('link_state:"live"', 'link_state:"stale"')));
    const moved = MOVED.dom.byId.get('fleetcount');
    check(!!moved && moved.textContent !== want && /^\d+ seats · \d+ live$/.test(moved.textContent),
      `…and it MOVES when the fleet does — ${JSON.stringify(want)} becomes ${JSON.stringify(moved ? moved.textContent : null)}`);
  }
}

// ⭐ THE COUNTS ARE COUNTED, NOT WRITTEN DOWN. This line is the ONE place the number of checks and
// the number of planted controls is stated; the header above, `docs/design/floor-preview/README.md`
// and `tools/design/README.md` all point at it rather than carrying a figure of their own. A number
// restated in four files is a number that is wrong in three of them one commit later.
console.log(`\n${failures === 0 ? 'PASS' : `FAIL — ${failures} check(s)`}  `
  + `${checks} checks, ${controls} planted controls  (${HTML_PATH})`);
process.exit(failures === 0 ? 0 : 1);

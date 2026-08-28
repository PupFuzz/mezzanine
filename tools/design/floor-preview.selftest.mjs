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
//   5. THE TWO IDENTITY-KEYED LOOKUPS (§ 7) and § 5.6's INTERN NULLS (§ 8). A seat's `desk`
//      against the floor map's slots, and an install against the client's theme map, are keyed
//      on identity and not on a member set, so layer 1's primitive does not reach them; § 3.2's
//      overflow rule and § 9 F13 say what both owe — a labelled overflow row, a notice reading
//      *floor map is short N desks*, and never a dropped seat. § 8 holds the intern label's two
//      nulls apart: a null `subagent_type` draws NO tag, a null `title` draws *untitled*.
//
// EVERY CHECK HERE CAN FAIL, AND THE CONTROLS PROVE IT rather than asserting it. TWELVE anchored
// mutations: the pre-fix lookup shape, the lobby's silent filter, a rewritten `unknown_reason`
// sentence, a rewritten `api_error_type` phrase, a label cell reverted to its pre-fix wording, a
// D3 that grew a SEVENTH render surface, a scope row for a surface D3 does not publish, the
// unguarded desk lookup, an overflow row drawn with its label and no desk in it, the unguarded
// theme lookup, and each of the intern label's two nulls collapsed into the other's rule. Each is
// anchored, the anchor count is asserted, and the relevant layer is REQUIRED to go red. The
// derivations that are pure comparison get their own discriminating controls.
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
  'STATE_RENDER', 'UNRECOGNISED_RENDER', 'RENDER_STATES', 'UNKNOWN_REASONS', 'API_ERROR_TYPES',
  'MEMBER_SETS', 'isMember', 'unheardFields', 'isCurrent', 'apiErrorLine',
  'isRenderState', 'renderFor', 'chipText', 'poseOf', 'hasCharacter', 'labelFor',
  'render', 'FLEET', 'FLOORS', 'D3_SCOPE', 'placeFloor', 'themeFor', 'internLabel',
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
  for (const id of ['room', 'world', 'drill', 'floors', 'scope', 'feeddot', 'feedstatus', 'zin', 'zout', 'zfit', 'zall']) {
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
const DOC_STATE_ROWS = rowsOf(s71.slice(0, reasonTableAt));
const DOC_REASON_ROWS = rowsOf(s71.slice(reasonTableAt));
const DOC_STATES = DOC_STATE_ROWS.map((r) => r.member);
const DOC_REASONS = DOC_REASON_ROWS.map((r) => r.member);
// § 7.6's twelve `api_error_type` members live in their own section, under a column headed
// *The line beside the raw value* — which is the render's shape, not just a glossary.
const s76 = FLOOR_MD.slice(
  FLOOR_MD.indexOf('### 7.6 The three remaining member sets'),
  FLOOR_MD.indexOf('## 8. Interns'),
);
const DOC_API_ROWS = rowsOf(s76.slice(s76.indexOf('| `api_error_type` | The line beside the raw value |')));
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
  const hits = SCRIPT.split(anchor).length - 1;
  check(hits === 1, `content control anchored exactly once (${hits}): ${what}`);
  if (hits !== 1) continue;
  const failed = [];
  // Only this set's own reds count. A control that accepted its sibling's failure as proof would
  // pass while the half it exists to exercise did nothing.
  compareSets(load(SCRIPT.replace(anchor, wrong)).probe,
    (cond, w) => { if (!cond && w.startsWith(set)) failed.push(w); });
  check(failed.length > 0, `caught: ${what} — ${failed.length} red, e.g. ${JSON.stringify(failed[0] || null)}`);
}
// And the same for the § 7.1 Label line comparison, which is the cell most likely to be edited.
const LABEL_ANCHOR = 'label:s=>"finished — nothing done for "+s.quiet_for';
const labelHits = SCRIPT.split(LABEL_ANCHOR).length - 1;
check(labelHits === 1, `the label control is anchored exactly once (${labelHits})`);
if (labelHits === 1) {
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
/** D3's membership-tested render surfaces, read out of § 5.4. `null` when the anchors are gone —
 *  which is a FAILURE below, never a silently empty comparison. */
function docSurfaces(floorMd) {
  const s54 = floorMd.slice(floorMd.indexOf('### 5.4 What is never rendered'),
    floorMd.indexOf('### 5.5 The client'));
  const RULE = '- **An unrecognised enum member guessed into a known one.**';
  const PUB = '**"The client does not know" is a membership test';
  const ruleAt = s54.indexOf(RULE), pubAt = s54.indexOf(PUB);
  if (ruleAt < 0 || pubAt < 0 || pubAt < ruleAt) return null;
  const end = s54.indexOf('\n  **', pubAt + 4);          // the next bold paragraph ends this one
  const pubText = s54.slice(pubAt, end < 0 ? undefined : end);
  // Link TARGETS are not prose: `#72-badges-every-member-has-a-render` would otherwise answer for
  // the word `badges` that the sentence itself is supposed to carry.
  const names = (t) => [...new Set([...t.replace(/\]\([^)]*\)/g, '')
    .matchAll(/`([a-z_]+)`|\bbadges?\b/g)].map((m) => m[1] || 'badge'))];
  // D3 backticks five of the six and writes the sixth as the English word `badge(s)` — in both
  // enumerations and in § 7.2's own table header — so the word is read as the name it is.
  const spelled = (pubText.match(/all (\w+)\s+sets are published here/) || [])[1];
  return {
    rule: names(s54.slice(ruleAt, pubAt)),
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
  const hits = grownDoc.split(from).length - 1;
  check(hits === 1, `the seventh-surface control is anchored exactly once (${hits}): ${JSON.stringify(from)}`);
  if (hits !== 1) { anchorsOk = false; continue; }
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
const rowHits = SCRIPT.split(ROW_ANCHOR).length - 1;
check(rowHits === 1, `the invented-row control is anchored exactly once (${rowHits})`);
if (rowHits === 1 && DOC_SURFACES) {
  const INVENTED = SCRIPT.replace(ROW_ANCHOR,
    '  {surface:"sprocket_state",status:"not implemented",table:null,note:"planted by the control"},\n'
    + ROW_ANCHOR);
  const failed = [];
  compareScope(load(INVENTED).probe, DOC_SURFACES.surfaces,
    (cond, what) => { if (!cond) failed.push(what); });
  const hit = failed.find((f) => f.includes("row `sprocket_state`"));
  check(!!hit, `a row for a surface D3 does not publish goes RED — ${JSON.stringify(hit || null)}`);
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
  say((svg.match(/floor map is short/g) || []).length === shortFloors,
    `${mode}: every short floor carries § 5.5's persistent notice — *floor map is short N desks*`);
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
const PLACE_ANCHOR = 'for(const {seat:s0,D} of placeFloor(TH,FLEET[inst]).placed){\n      const s={...s0,__install:inst};';
const placeHits = SCRIPT.split(PLACE_ANCHOR).length - 1;
check(placeHits === 1, `the pre-fix desk-lookup control is anchored exactly once (${placeHits})`);
if (placeHits === 1) {
  const UNGUARDED = SCRIPT.replace(PLACE_ANCHOR,
    'for(const s0 of FLEET[inst]){\n      const s={...s0,__install:inst};const D=TH.desks[s.desk];');
  const failed = f13(UNGUARDED, false, 'undeclared-desk');
  check(failed.some((f) => f.includes('without throwing')),
    `the unguarded desk lookup goes RED, the way the defect did — ${JSON.stringify(failed.find((f) => f.includes('without throwing')) || null)}`);
}
const OVERFLOW_ANCHOR = '    for(const p of over) g+=`<g class="overflow-desk">${deskSVG(T,p.D,p.seat)}</g>`;';
const overflowHits = SCRIPT.split(OVERFLOW_ANCHOR).length - 1;
check(overflowHits === 1, `the silent-drop control is anchored exactly once (${overflowHits})`);
if (overflowHits === 1) {
  // The surplus desk is simply not drawn — no throw, no glyph, a seat that exists and is
  // invisible. The notice is what makes that visible to this suite, which is why F13 states it.
  const DROPPED = SCRIPT.replace(OVERFLOW_ANCHOR, '');
  const failed = f13(DROPPED, false, 'undeclared-desk');
  check(failed.some((f) => f.includes('own DESK is drawn')),
    `a floor that draws the row's label and not the desk in it goes RED — ${JSON.stringify(failed.find((f) => f.includes('own DESK is drawn')) || null)}`);
}
const THEME_ANCHOR = 'const themeFor=inst=>THEMES[inst]||UNTHEMED;';
const themeHits = SCRIPT.split(THEME_ANCHOR).length - 1;
check(themeHits === 1, `the unthemed-install control is anchored exactly once (${themeHits})`);
if (themeHits === 1) {
  const RAW = SCRIPT.replace(THEME_ANCHOR, 'const themeFor=inst=>THEMES[inst];');
  const failed = f13(RAW, false, 'unthemed-install');
  check(failed.some((f) => f.includes('without throwing')),
    `the unguarded theme lookup goes RED — ${JSON.stringify(failed.find((f) => f.includes('without throwing')) || null)}`);
}

// ---------------------------------------------------------------------------------------------
// 8. § 5.6's two intern nulls — one label, two rules, and they are NOT the same rule
// ---------------------------------------------------------------------------------------------
// § 5.6 (`subagents[].subagent_type`): "the type tag beside the label is not drawn" — a null type
// renders NOTHING, because a substitute states a fact the wire never sent. § 5.1 and AT-D3-4:
// a null `title` renders *untitled*, and falling back to `subagent_type`, to the tool name or to
// *subagent* is that test's first RED. They sit on one line of the drill-down and a fix that
// treated them alike would break the compliant one to fix its neighbour, so both are asserted.
section("8. the intern label: a null subagent_type draws NO tag, a null title draws *untitled*");
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
const internHits = SCRIPT.split(INTERN_ANCHOR).length - 1;
check(internHits === 1, `the intern-label control is anchored exactly once (${internHits})`);
if (internHits === 1) {
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

console.log(`\n${failures === 0 ? 'PASS' : `FAIL — ${failures} check(s)`}  (${HTML_PATH})`);
process.exit(failures === 0 ? 0 : 1);

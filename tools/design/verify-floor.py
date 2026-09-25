#!/usr/bin/env python3
"""D3 verification gate: docs/design/FLOOR.md.

TWELVE guard classes, G1-G12, one per defect class this document can carry that a reader will not
reliably catch.  Every population below is RE-DERIVED on each run -- from this document's own
tables, or from docs/design/FLEET-STATE.md (D2) and docs/design/EVENT-SCHEMA.md (D1) -- and never
from a list stored here.  A number or a member list written into a checker is one free to disagree
with the document it is checking, and it survives exactly the pass that falsifies it.

  G1  animation totality, both directions      an animation named with no row in section 6.2, a row
                                               whose driver is not a field or message D2 declares
  G2  source-field closure against D2           a rendered fact whose source field D2 does not send,
                                               plus the RESIDUE: D2 fields rendered nowhere
  G3  the subagent-cap arithmetic, re-added     6,112 / 8,192 / 263 -> 2,080 / 7 / 15 / 7,953 / 8,216
  G4  section 12 <-> definition site            each number as a whole token at the section it cites,
                                               then PERTURBED to prove the match can fail there
  G5  acceptance-test closure                   fixture <-> test both ways; every test has a RED;
                                               AT ids contiguous from 1; ORDINAL REDs contiguous from
                                               Second, and bound BOTH WAYS to the FIXTURE NAMES of the
                                               suite a test names (read with `ast`, not grepped -- a
                                               grep is satisfied by the suite's own docstring); the
                                               build order gates every artifact a test reads, and
                                               every Build bullet of a test the harness drives
                                               declares the harness, while a test naming no fixture
                                               and not the harness may name an instrument whose own
                                               Appendix B row gates the test; the
                                               animation-log schema's two homes; the episode walk's
                                               own episode and row counts
  G6  Appendix A counts + D2 `D3`-marker cover  an obligation with no row; a marker section nobody cites
  G7  state and badge render closure            a D2 enum member with no render, or a render for a
                                               member D2 does not declare
  G8  the desk-slot worked example              FNV-1a-32 re-computed for every published key;
                                               `S` against the SHIPPED DEFAULT map file -- present,
                                               its `desks` objects are counted and held pairwise
                                               disjoint on half-open rects (each is the furniture
                                               box its desk is drawn inside, section 10.3); absent,
                                               section 10.3 must SAY so and no map may exist in the tree;
                                               the desk sprite's size against its PNG; and the
                                               READ PATHS section 10.3 says a room's map is fetched
                                               from must be exactly the ones D2 section 8.7 declares;
                                               and the worked floors laid at the furniture box --
                                               section 4.6's two rows and D2 section 8.7's authored
                                               rooms and worked room map -- re-derived from the box
  G9  D2 section 6.5's delivery contract        a render row sourcing one of the TEN non-version-
                                               bearing members without `fetch-fresh` / `dark-only`;
                                               a section 5 table this gate has no column for; a table
                                               row anywhere else naming one of the ten and declaring
                                               neither a marker nor `named-not-rendered`
  G10 null-render closure, both directions      a member D2 section 8.2.1 marks `Null? yes` with no
                                               stated null render, or a null render for a member D2
                                               does not mark nullable
  G11 a worked example against its rule         a WORKED EXAMPLE that contradicts the rule statement
                                               governing it, over TWO facts. (a) the composed
                                               `api_error_type` line: section 7.1's `stalled` cell
                                               against section 7.6's member/phrase table, and
                                               section 5.1's *rendered verbatim* illustration
                                               against the MEMBERS rather than the phrases.
                                               (b) WHERE the `activity_state` currency label is
                                               drawn: section 7.6's five rows must agree with each
                                               other, and every worked instance elsewhere -- found
                                               structurally, by a *was:* span or the words `activity
                                               state` -- must state the placement they agree on
  G12 the duration format                      section 2.4's clauses re-implemented as a function
                                               and its boundary table REPRODUCED row by row; then
                                               every duration inside a PUBLISHED RENDERED SPAN --
                                               section 2.4's Verbatim column and section 7.1's Label
                                               line -- held to it as a FIXED POINT, and section 7.1's
                                               two dark rows re-derived ARITHMETICALLY from the
                                               timestamp in their own span and the corrected clock
                                               their own prose states

Two things are NOT mechanizable and say so in the output rather than reporting a clean over a
population they never measured (canon: a clean result over an unnamed population reports where the
searcher stopped):

  * G6's SEMANTIC half.  The mechanized recognizer is NOT the literal `D3` alone -- that reported
    clean while D2 section 4.7 and section 4.8 placed three render obligations on this document, in
    the words "rendered in the drill-down".  It is `D3` plus the render-directed phrasings the two
    upstream documents actually use, over BOTH of them.  An obligation phrased in none of those forms
    is still not grep-derivable, so the rows resting on no marker section are printed ROW BY ROW.
  * G9's PROSE half.  G9's table population is DERIVED -- every markdown table in the document, found
    structurally -- and a table row outside the render map that names one of the ten now REDS unless
    it declares itself `named-not-rendered`.  What is left outside is prose, which no set difference
    reaches, so every prose mention is printed IN FULL as named residue rather than folded into a
    pass.  In full: the previous revision printed the first twelve of nineteen beside the true count,
    which is a cap that reads as a complete list.
  * G4's RESIDUE.  Every number G4 matches is then perturbed, and the ones some other value would
    also have satisfied are printed individually rather than counted as passes.  That list is the
    honest statement of which section 12 rows this gate is actually holding.

Each check that can be silent about its own subject carries a CONTROL that aborts rather than
reporting clean when its extractor finds nothing (canon: a check that cannot fail is a decoration).
"""
import ast
import json
import re
import struct
import sys
import pathlib
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).parent.parent.parent
DOC = ROOT / "docs/design/FLOOR.md"
D2 = ROOT / "docs/design/FLEET-STATE.md"
D1 = ROOT / "docs/design/EVENT-SCHEMA.md"

fail = []
raw = DOC.read_text()
lines = raw.split("\n")
d2_raw = D2.read_text()
d1_raw = D1.read_text()

WORD = {0: "zero", 1: "one", 2: "two", 3: "three", 4: "four", 5: "five", 6: "six", 7: "seven",
        8: "eight", 9: "nine", 10: "ten", 11: "eleven", 12: "twelve", 13: "thirteen",
        14: "fourteen", 15: "fifteen", 16: "sixteen", 17: "seventeen", 18: "eighteen",
        19: "nineteen", 20: "twenty", 21: "twenty-one", 22: "twenty-two", 23: "twenty-three",
        24: "twenty-four", 25: "twenty-five", 26: "twenty-six", 27: "twenty-seven",
        28: "twenty-eight", 29: "twenty-nine", 30: "thirty", 31: "thirty-one", 32: "thirty-two",
        33: "thirty-three", 34: "thirty-four", 35: "thirty-five", 36: "thirty-six",
        37: "thirty-seven", 38: "thirty-eight", 39: "thirty-nine", 40: "forty"}
NUM = {v: k for k, v in WORD.items()}


# ---------------------------------------------------------------- helpers ----
def anchors_of(path):
    """GitHub-flavoured heading anchors.  Same algorithm as the D1 and D2 verifiers'."""
    out, seen = set(), {}
    for line in path.read_text().splitlines():
        m = re.match(r"^(#{1,6})\s+(.*?)\s*$", line)
        if not m:
            continue
        text = re.sub(r"`([^`]*)`", r"\1", m.group(2))
        text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)
        text = re.sub(r"[*~]", "", text)
        a = re.sub(r"[^\w\- ]", "", text.lower()).replace(" ", "-")
        if a in seen:
            seen[a] += 1
            a = f"{a}-{seen[a]}"
        else:
            seen[a] = 0
        out.add(a)
    return out


def heading_index(text):
    """[level, title, anchor, start_line, end_line] for every heading, in order."""
    hs, seen = [], {}
    src = text.split("\n")
    for i, line in enumerate(src):
        m = re.match(r"^(#{1,6})\s+(.*?)\s*$", line)
        if not m:
            continue
        title = m.group(2)
        a = re.sub(r"`([^`]*)`", r"\1", title)
        a = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", a)
        a = re.sub(r"[*~]", "", a)
        a = re.sub(r"[^\w\- ]", "", a.lower()).replace(" ", "-")
        if a in seen:
            seen[a] += 1
            a = f"{a}-{seen[a]}"
        else:
            seen[a] = 0
        hs.append([len(m.group(1)), title, a, i, len(src)])
    for k in range(len(hs)):
        for j in range(k + 1, len(hs)):
            if hs[j][0] <= hs[k][0]:
                hs[k][4] = hs[j][3]
                break
    return hs


HEADS = heading_index(raw)
BY_ANCHOR = {h[2]: h for h in HEADS}
D2_HEADS = heading_index(d2_raw)
D1_HEADS = heading_index(d1_raw)


def section_text(anchor, src_lines=None, index=None):
    idx = index if index is not None else BY_ANCHOR
    h = idx.get(anchor)
    if h is None:
        return None
    src = src_lines if src_lines is not None else lines
    return "\n".join(src[h[3]:h[4]])


def strip_code(s):
    s = re.sub(r"```.*?```", lambda m: "\n" * m.group(0).count("\n"), s, flags=re.S)
    return re.sub(r"`[^`\n]*`", lambda m: " " * len(m.group(0)), s)


def cells(row):
    return [c.strip() for c in row.strip().strip("|").split("|")]


def table_rows(text, header_re):
    """Data rows of the first table in `text` whose header line matches.

    INDENT-TOLERANT, and that is not a nicety: a markdown table nested under a list item is indented,
    and a `^\\|` test reads every one of its rows as prose.  This file's own header patterns are
    `^\\|`-anchored, so both the header test and the row walk normalise with `lstrip()` and the rows
    are returned LEFT-STRIPPED -- every caller's `^\\|` regex then reads an indented row unchanged."""
    src = text.split("\n")
    for i, line in enumerate(src):
        if re.search(header_re, line.lstrip()):
            out, j = [], i + 2
            while j < len(src) and src[j].lstrip().startswith("|"):
                out.append(src[j].lstrip())
                j += 1
            return out
    return None


def table_rows_all(text, header_re):
    """EVERY matching table's data rows in `text`, not just the first.

    `table_rows` returns the first table only, which is right where a section declares one.  D2
    § 8.3.3 declares TWO objects under one header, and reading the first would publish half a
    surface while reporting clean over the other half -- the same under-read `all_tables` below
    exists to prevent for this document's own tables."""
    src, out, i = text.split("\n"), [], 0
    while i < len(src):
        if re.search(header_re, src[i].lstrip()):
            j = i + 2
            while j < len(src) and src[j].lstrip().startswith("|"):
                out.append(src[j].lstrip())
                j += 1
            i = j
        else:
            i += 1
    return out


def all_tables(src_lines):
    """EVERY markdown table in a document, found by STRUCTURE: (start_index, header_line, [rows]).

    Which tables exist is not written anywhere in this file.  It is re-derived on each run, because
    the defect this closes is a STORED population rather than a wrong one: G9's table list used to be
    five header patterns written here, so when § 5.6 was added -- thirty-six null-render rows, seven
    of them sourcing one of D2 § 6.5's ten -- it entered the gate's blind spot and nothing reddened,
    while § 2.4 went on claiming the marker rule held over every § 5 row and that this file reds when
    one does not.  Both halves of that sentence were false, and no check could say so.

    Unlike `table_rows`, this finds EVERY table, not the first whose header matches -- so a second
    table sharing a header shape is read rather than silently skipped.

    INDENT-TOLERANT for the same reason `table_rows` is, and it is the same stored-population defect
    one shape further in: a `^\\|` test does not under-read a LIST of tables, it under-reads the
    STRUCTURE, so a render table indented under a list item left this gate's population without
    reddening anything -- while this document already authors indented tables (section 2.4's duration
    table, section 11's episode walk).  The tuple still keys by POSITION -- `i` is the header's 0-based
    index in the UNMODIFIED source, so `_start + 3 + _j` is still the 1-based line of row `_j` -- and
    only the TEXT handed on is left-stripped, because every header pattern and row regex here is
    `^\\|`-anchored."""
    out, i, n = [], 0, len(src_lines)
    while i < n - 1:
        cur = src_lines[i].lstrip()
        if cur.startswith("|") and re.match(r"^\|[\s\-:|]+\|\s*$", src_lines[i + 1].lstrip()):
            j, rows = i + 2, []
            while j < n and src_lines[j].lstrip().startswith("|"):
                rows.append(src_lines[j].lstrip())
                j += 1
            out.append((i, cur, rows))
            i = j
        else:
            i += 1
    return out


FIELDISH = re.compile(r"^[a-z_][a-z0-9_]*(\[\])?(\.[a-z_][a-z0-9_]*(\[\])?)*$")

# A whole numeric (or word) token: not glued to a word character, and not a fragment of a longer
# number -- neither the tail of `1,280` nor the head of `8.2.1`.  An earlier revision used
# `(?![\w,.])` on both sides, which rejected every value that happened to end a sentence and so
# reported "the number is not at its definition site" for numbers that were.
WHOLE = r"(?<!\w)(?<!\d,)(?<!\d\.)%s(?!\w)(?![.,]\d)"


def prose(pattern):
    """Make a multi-word pattern tolerant of the line wraps prose actually contains.

    A markdown TABLE row is one line by construction and cannot wrap, so a `^\\|`-anchored pattern is
    structurally safe.  PROSE is not: D1 § 12.2 places a render obligation in the words "readable in
    its\\ndrill-down", and D2 § 6.5 grants the `dark-only` carve-out in the words "so its
    `last_receipt_at`\\nis frozen".  A line-scoped or space-literal pattern reads neither.  Every
    multi-word pattern matched against document prose in this file goes through here, so the next
    re-flow of an upstream paragraph cannot quietly un-find what this gate is holding."""
    return pattern.replace(" ", r"\s+")


def field_tokens(cell):
    """Backticked tokens in a cell that have the shape of a wire field or message type."""
    return {t for t in re.findall(r"`([^`]+)`", cell) if FIELDISH.match(t)}


# ---------------------------------------------------- 0. structural checks ----
doc_anchors = anchors_of(DOC)
n_links = 0
for m in re.finditer(r"\]\(([^)\s]+)\)", strip_code(raw)):
    target, line = m.group(1), raw[:m.start()].count("\n") + 1
    n_links += 1
    if target.startswith("#"):
        if target[1:] not in doc_anchors:
            fail.append(f"L{line}: dead in-doc anchor {target}")
    elif target.startswith("http"):
        continue
    else:
        path, _, frag = target.partition("#")
        fp = (DOC.parent / path).resolve()
        if not fp.exists():
            fail.append(f"L{line}: missing file {target}")
        elif frag and frag not in anchors_of(fp):
            fail.append(f"L{line}: dead anchor in {path}: #{frag}")
if n_links < 150:
    fail.append(f"CONTROL: only {n_links} markdown links found — the link extractor is broken, and "
                f"a link check that reads no links reports clean over everything")

for i, line in enumerate(lines, 1):
    if re.search(r"\b(TODO|TBD|FIXME|XXX)\b", line):
        fail.append(f"L{i}: placeholder marker: {line.strip()[:90]}")

n_table_breaks = 0
for i in range(1, len(lines) - 1):
    if lines[i].strip() or not (lines[i - 1].startswith("|") and lines[i + 1].startswith("|")):
        continue
    nxt = lines[i + 2] if i + 2 < len(lines) else ""
    if not re.match(r"^\|[\s\-:|]+\|\s*$", nxt):
        n_table_breaks += 1
        fail.append(f"L{i + 1}: blank line severs a table body — the row below it renders as a new "
                    f"table's header and every row after it loses its column names")

# ------------------------------------------- the D2 populations, re-derived ---
d2_by_anchor = {h[2]: h for h in D2_HEADS}
d2_lines = d2_raw.split("\n")
sec_821 = section_text("821-the-seat-state-object", d2_lines, d2_by_anchor)
sec_824 = section_text("824-the-fleet-health-object", d2_lines, d2_by_anchor)
sec_823 = section_text("823-the-seat-detail-response", d2_lines, d2_by_anchor)
sec_83 = section_text("83-the-websocket-delta-feed", d2_lines, d2_by_anchor)
sec_42 = section_text("42-render-precedence", d2_lines, d2_by_anchor)
sec_43 = section_text("43-the-derivation-function", d2_lines, d2_by_anchor)

d2_fields, d2_fleet, d2_msgs, d2_detail = set(), set(), set(), set()

rows = table_rows(sec_821 or "", r"^\| Field \| Type \| Null\? \| Bounds \| Example \|")
if not rows:
    fail.append("CONTROL: D2 § 8.2.1's field table did not parse — every field check below would "
                "then compare against an empty set and report clean over everything")
else:
    for r in rows:
        m = re.match(r"^\|\s*`([A-Za-z_][\w.\[\]]*)`\s*\|", r)
        if m:
            d2_fields.add(m.group(1))
    if len(d2_fields) < 50:
        fail.append(f"CONTROL: only {len(d2_fields)} field names parsed from D2 § 8.2.1")

rows = table_rows(sec_824 or "", r"^\| Field \| Type \| Null\? \| Bounds \| Example \|")
if not rows:
    fail.append("CONTROL: D2 § 8.2.4's fleet-object table did not parse")
else:
    for r in rows:
        m = re.match(r"^\|\s*`([a-z_]+)`\s*\|", r)
        if m:
            d2_fleet.add(m.group(1))
    if len(d2_fleet) < 8:
        fail.append(f"CONTROL: only {len(d2_fleet)} fleet fields parsed from D2 § 8.2.4")

rows = table_rows(sec_83 or "", r"^\| Message `t` \| Direction \| When \| Payload \|")
if not rows:
    fail.append("CONTROL: D2 § 8.3's message table did not parse — G1 could not tell a message "
                "type from an invented one")
else:
    for r in rows:
        m = re.match(r"^\|\s*`([a-z_.]+)`\s*\|", r)
        if m:
            d2_msgs.add(m.group(1))
    if len(d2_msgs) < 4:
        fail.append(f"CONTROL: only {len(d2_msgs)} feed message types parsed from D2 § 8.3")

# D2 § 8.3.3's coordination objects -- the FIFTH surface D2 declares a field on, added when D2
# gained it (card#9212).  It is read the same way as the four above: from D2's own table, on every
# run, so a field that leaves D2 leaves this set with it.
sec_833 = section_text("833-the-coordination-objects", d2_lines, d2_by_anchor)
d2_coord = set()
rows = table_rows_all(sec_833 or "", r"^\| Field \| Type \| Null\? \| Bounds \| Example \|")
if not rows:
    fail.append("CONTROL: D2 § 8.3.3's coordination field tables did not parse — every coordination "
                "field this document renders would then be checked against an empty set and red as "
                "invented, which is a failure that names the wrong cause")
else:
    for r in rows:
        m = re.match(r"^\|\s*`([A-Za-z_][\w.\[\]]*)`\s*\|", r)
        if m:
            d2_coord.add(m.group(1))
    if len(d2_coord) < 20:
        fail.append(f"CONTROL: only {len(d2_coord)} coordination field names parsed from D2 § 8.3.3, "
                    f"which declares two objects — the reader is under-reading one of them")

d2_detail = {t for t in re.findall(r"`([a-z_]+)`", sec_823 or "")}
if "detail" not in d2_detail:
    fail.append("CONTROL: D2 § 8.2.3's `detail` member did not parse; the drill-down's source "
                "column would then read as an invented field")

ALLOWED = (d2_fields | d2_msgs | d2_detail | d2_coord
           | d2_fleet | {"fleet." + f for f in d2_fleet})


def base_field(t):
    """`badges[]` and `badges` name one field.  A trailing `[]` is D2's OWN array notation -- its
    § 8.2.1 table writes `subagents[].title` -- so stripping it here is reading D2's spelling, not
    widening the check: `banana[]` still resolves to `banana` and still fails."""
    return t[:-2] if t.endswith("[]") else t


def declared(t):
    return t in ALLOWED or base_field(t) in ALLOWED

# ------------------------------------- G1. animation totality, both directions ---
ANIM_HEADER = r"^\| # \| Class \| Animation \| Where \| Driving fact \(D2\) \|"
ANIM_DRIVER_COL = 4
anim_rows = table_rows(raw, ANIM_HEADER)
anim_ids, anim_drivers, g1_bad, driverless, anim_class = set(), {}, [], 0, {}
if not anim_rows:
    fail.append("G1 CONTROL: section 6.2's animation table did not parse — the closed set this "
                "document's headline claim rests on would be unread, and every animation would "
                "pass unchecked")
else:
    for r in anim_rows:
        c = cells(r)
        m = re.match(r"^\*\*(A\d+)\*\*$", c[0])
        if not m:
            continue
        anim_ids.add(m.group(1))
        anim_class[m.group(1)] = c[1].strip("`") if len(c) > 1 else ""
        toks = field_tokens(c[ANIM_DRIVER_COL]) if len(c) > ANIM_DRIVER_COL else set()
        anim_drivers[m.group(1)] = toks
        if not toks:
            driverless += 1
            continue
        for t in toks:
            if not declared(t):
                g1_bad.append(t)
                fail.append(
                    f"G1: animation {m.group(1)}'s driving fact `{t}` is not a field D2 § 8.2.1 "
                    f"declares, a fleet field of § 8.2.4, or a feed message type of § 8.3 — so the "
                    f"animation is driven by something the wire does not carry, which is an "
                    f"animation with no event wearing a field name")
    if len(anim_ids) < 10:
        fail.append(f"G1 CONTROL: only {len(anim_ids)} animation rows parsed from section 6.2")
    bad_class = sorted(a for a, k in anim_class.items() if k not in ("edge", "held"))
    for a in bad_class:
        fail.append(f"G1: animation {a}'s Class cell reads `{anim_class[a]}`, which is neither `edge` "
                    f"nor `held` — the log's causality rule is selected by that cell, so a row "
                    f"outside the two classes is a row whose animation-log contract is undefined")
    if not bad_class and len({k for k in anim_class.values()}) < 2:
        fail.append("G1 CONTROL: section 6.2's rows are all one class — the split the log's two "
                    "causality rules rest on would be unexercised, and every held render would be "
                    "checked against the edge rule or none")
    if driverless > 1:
        fail.append(f"G1: {driverless} animation rows name no wire field or message at all. Exactly "
                    f"one may (A16, whose driver is the rendered seat set); a second is an "
                    f"animation whose driver is prose")

anim_table_span = ""
if anim_rows:
    anim_table_span = "\n".join(anim_rows)
mentioned = set()
for m in re.finditer(r"\bA(\d{1,2})\b", raw.replace(anim_table_span, "")):
    mentioned.add("A" + m.group(1))
if anim_ids:
    for a in sorted(mentioned - anim_ids, key=lambda s: int(s[1:])):
        fail.append(f"G1: `{a}` is referred to in this document and has no row in section 6.2 — an "
                    f"animation with no row is a defect, not a flourish")
    for a in sorted(anim_ids - mentioned, key=lambda s: int(s[1:])):
        fail.append(f"G1: `{a}` has a row in section 6.2 and is referred to nowhere else — either "
                    f"it is unreachable or a section that should bind it does not")
if not mentioned:
    fail.append("G1 CONTROL: no animation id found outside section 6.2 — the reverse direction "
                "would be vacuously clean")

# ------------------------------- G2. source-field closure against D2, + residue ---
g2_seen, g2_checked = set(), 0
SOURCE_TABLES = [
    (r"^\| Rendered element \| D2 field \| Example \| When null / absent \|", 1, "5.1"),
    (r"^\| Rendered element \| Source \| Example \| Rule \|", 1, "5.2"),
    (r"^\| Rendered element \| Source \| Rule \|", 1, "5.3"),
    # § 5.7's coordination render map (card#8300).  Its header is DELIBERATELY not § 5.1's shape:
    # `table_rows` returns the FIRST table whose header matches, so a second table sharing § 5.1's
    # four column names would be read by G9 (which walks every table) and skipped by this loop --
    # checked for its markers and not for whether its fields exist.  A distinct header makes the
    # two populations the same population.
    (r"^\| Rendered element \| D2 field \| Example \| Null / unresolved render \|", 1, "5.7"),
    (ANIM_HEADER, ANIM_DRIVER_COL, "6.2"),
    (r"^\| Panel section \| Contents \| Source \|", 2, "4.3"),
]
for header, col, where in SOURCE_TABLES:
    rows = table_rows(raw, header)
    if not rows:
        fail.append(f"G2 CONTROL: the source table of section {where} did not parse — every field "
                    f"it names would go unchecked")
        continue
    for r in rows:
        c = cells(r)
        if len(c) <= col:
            continue
        for t in field_tokens(c[col]):
            g2_checked += 1
            g2_seen.add(base_field(t))
            if not declared(t):
                fail.append(
                    f"G2: section {where} renders a fact whose source is `{t}`, which D2 declares "
                    f"nowhere — not in § 8.2.1's seat object, § 8.2.4's fleet object, § 8.2.3's "
                    f"detail member, § 8.3's message table or § 8.3.3's coordination objects. A "
                    f"rendered fact with no field is a fact the client invented")
if g2_checked < 40:
    fail.append(f"G2 CONTROL: only {g2_checked} source tokens extracted from the render map — the "
                f"extractor is broken and this check reports clean over an unread population")
g2_residue = sorted(f for f in d2_fields if f not in g2_seen and f not in ("install_id", "seat_id"))

# ------------------------------------ G3. the subagent-cap arithmetic, re-added ---
sec81 = section_text("81-the-cap-stays-at-8--the-arithmetic-and-the-reason") or ""
g3 = {}
if not sec81:
    fail.append("G3 CONTROL: section 8.1 not found — the cap arithmetic would be unchecked")
else:
    pats = {
        "worst": r"worst-case delta at the cap of 8 \| \*\*([\d,]+) B\*\*",
        "bound": r"per-message bound \| \*\*([\d,]+) B\*\*",
        "spare": r"\| spare \| \*\*([\d,]+) B\*\*",
        "elem": r"each further subagent element \| \*\*([\d,]+) B\*\*",
        "fit": r"further elements that fit \| \*\*(\d+)\*\*",
        "reach": r"the cap could reach \| \*\*(\d+)\*\*",
        "reach_b": r"worst-case delta of \*\*([\d,]+) B\*\*",
        "breach": r"16 breaches \| \*\*([\d,]+) B\*\*",
        "over": r"which is \*\*(\d+) B over\*\*",
    }
    for k, p in pats.items():
        m = re.search(p, sec81)
        if not m:
            fail.append(f"G3 CONTROL: section 8.1's `{k}` figure did not parse; the arithmetic "
                        f"would then rest on a number nothing re-adds")
        else:
            g3[k] = int(m.group(1).replace(",", ""))
    if len(g3) == len(pats):
        want_spare = g3["bound"] - g3["worst"]
        want_fit = want_spare // g3["elem"]
        want_reach = 8 + want_fit
        want_reach_b = g3["worst"] + want_fit * g3["elem"]
        want_breach = want_reach_b + g3["elem"]
        want_over = want_breach - g3["bound"]
        for name, stated, want in (("spare", g3["spare"], want_spare),
                                   ("elements that fit", g3["fit"], want_fit),
                                   ("cap reachable", g3["reach"], want_reach),
                                   ("worst case at that cap", g3["reach_b"], want_reach_b),
                                   ("worst case one past it", g3["breach"], want_breach),
                                   ("bytes over the bound", g3["over"], want_over)):
            if stated != want:
                fail.append(f"G3: section 8.1 states {name} = {stated:,}; re-derived from its own "
                            f"three inputs ({g3['worst']:,} B worst case, {g3['bound']:,} B bound, "
                            f"{g3['elem']:,} B per element) it is {want:,}")
        if want_breach <= g3["bound"]:
            fail.append("G3: the cap one past the stated maximum does NOT breach the bound, so the "
                        "boundary this section exists to locate is in the wrong place")
    for k, v in (("worst", g3.get("worst")), ("bound", g3.get("bound")), ("elem", g3.get("elem"))):
        if v is None:
            continue
        if not re.search(r"\b" + f"{v:,}".replace(",", "[,]?") + r"\b", d2_raw):
            fail.append(f"G3: the input {v:,} is attributed to D2 and appears nowhere in D2 — a "
                        f"cited figure that its source does not contain is a figure this document "
                        f"minted and labelled Cited")

sec12 = section_text("12-every-number-and-where-it-comes-from") or ""

# ---- G2, THE RULE'S OWN SCOPE.  Section 12's G2 row ENUMERATES the tables half (a) reads, which
# makes SOURCE_TABLES above one fact with two homes -- and this is the home that has already gone
# false once: it claimed "section 5, section 6.2 or section 7" while the map held five headers and
# none of them was in section 7, and the whole of the check was the claim.  The repair then was to
# rewrite the prose.  Prose nothing re-derives goes false again at the next table added, so the two
# are set-differenced here in BOTH directions, exactly as G9's marker-rule scope is.  Neither side
# is stored: the map is the list above, the claim is read out of the document on every run.
_i = sec12.find("**G2 source-field closure**")
_j = sec12.find("*(b)*", _i) if _i >= 0 else -1
if _i < 0 or _j < 0:
    fail.append("G2 CONTROL: section 12's G2 row, or the *(a)* half of it that enumerates the "
                "source tables, did not parse. That enumeration is this gate's population written "
                "down in prose, and unparsed it would agree with the map by never being read")
else:
    _claimed = set(re.findall(r"\[§ (\d+(?:\.\d+)?)\]\(#", sec12[_i:_j]))
    _mapped = {t[2] for t in SOURCE_TABLES}
    if not _claimed:
        fail.append("G2 CONTROL: section 12's G2 row names no in-document section in its *(a)* "
                    "half, so its scope claim is empty and would set-difference clean against any "
                    "map")
    for w in sorted(_mapped - _claimed):
        fail.append(f"G2: this gate reads the source column of section {w} and section 12's G2 row "
                    f"does not name it. A reader auditing which tables are closed against D2 would "
                    f"be told a smaller set than the tool actually reads")
    for w in sorted(_claimed - _mapped):
        fail.append(f"G2: section 12's G2 row claims the source column of section {w} is closed "
                    f"against D2 and this gate has no entry for that table, so nothing checks it. "
                    f"That is the over-claim this row already shipped once")

# --------------------------- G4. section 12 <-> definition site, with perturbation ---
g4_rows = g4_nums = g4_disc = 0
g4_residue = []
rows = table_rows(sec12, r"^\| Value \| Number \| Basis \| Where \|")
if not rows:
    fail.append("G4 CONTROL: section 12's number table did not parse — every figure in this "
                "document would be unbound to its definition site")
else:
    for r in rows:
        c = cells(r)
        if len(c) < 4:
            continue
        g4_rows += 1
        m = re.search(r"\]\(#([^)]+)\)", c[3])
        if not m:
            fail.append(f"G4: section 12 row `{c[0]}` cites no in-document section, so its number "
                        f"is bound to nothing")
            continue
        target = section_text(m.group(1))
        if target is None:
            fail.append(f"G4: section 12 row `{c[0]}` cites #{m.group(1)}, which is not a section")
            continue
        for tok in re.findall(r"\d[\d,]*(?:\.\d+)?", c[1]):
            val = tok.replace(",", "")
            if val.endswith(".0"):
                val = val[:-2]
            try:
                n = int(val)
            except ValueError:
                continue
            g4_nums += 1
            forms = [tok, f"{n:,}", str(n)]
            if n in WORD:
                forms.append(WORD[n])
            found = any(re.search(WHOLE % re.escape(f), target) for f in forms)
            if not found:
                fail.append(
                    f"G4: section 12 says `{c[0]}` is {tok}, and {tok} does not appear as a whole "
                    f"value at the section it cites (#{m.group(1)}). One of the two homes moved")
                continue
            alt = n + 1
            alt_forms = [f"{alt:,}", str(alt)] + ([WORD[alt]] if alt in WORD else [])
            if any(re.search(WHOLE % re.escape(f), target) for f in alt_forms):
                g4_residue.append(f"{c[0]} = {tok} at #{m.group(1)} ({alt} would also have matched)")
            else:
                g4_disc += 1

# ----------------------------------------------- G5. acceptance-test closure ----
at_heads = [h for h in HEADS if h[1].startswith("AT-D3-")]
at_ids = []
for h in at_heads:
    m = re.match(r"AT-D3-(\d+)\b", h[1])
    if m:
        at_ids.append(int(m.group(1)))
if not at_ids:
    fail.append("G5 CONTROL: no acceptance-test heading parsed — the AT population is unread")
else:
    if sorted(at_ids) != list(range(1, len(at_ids) + 1)):
        fail.append(f"G5: the acceptance-test ids are {sorted(at_ids)} — not contiguous from 1, so "
                    f"a test has been renumbered, duplicated or dropped and a cross-reference "
                    f"elsewhere now points at a different test than it did")
    for h in at_heads:
        body = "\n".join(lines[h[3]:h[4]])
        if "**RED" not in body:
            fail.append(f"G5: `{h[1]}` states no RED. A test never seen to fail is not evidence; "
                        f"it is a decoration that reports the harness ran")

# G5, THE RED ENUMERATION -- and its BINDING to the suite that is supposed to run it.
#
# The check above asserted PRESENCE: one `**RED` anywhere in the body and the test was satisfied.
# That is a floor, and AT-D3-12 outgrew it -- it now enumerates TWELVE ordinal REDs, and a presence
# check reports clean whether the suite carries twelve of them, three, or none.  The failure mode is
# not hypothetical: card#8301 stripped its own tenth RED in a revert experiment and this gate still
# printed ALL D3 CHECKS PASS over a document that still described it.  Two things are added, and
# neither stores a population:
#
#   CONTIGUITY.  The ordinals a body uses must run Second, Third, ... with no gap and no repeat.
#   The FIRST red is deliberately unnumbered in this document's style, so the sequence starts at
#   Second; a gap means a RED was deleted and its neighbours never renumbered, and a repeat means
#   two REDs answer to one name, which is the same defect a duplicated AT id would be.
#
#   THE BINDING, BOTH DIRECTIONS, over the ORDINALS.  A body that names EXACTLY ONE `*.selftest.*`
#   file is claiming that file runs its REDs, so every ordinal here must appear in that file as
#   `<ORDINAL> RED`, and every `<ORDINAL> RED` in that file must appear here.  A doc RED with no
#   fixture is a claim nothing holds; a fixture ordinal with no doc RED is a test the specification
#   has stopped describing.  ⚠ WHAT IT DOES NOT COVER, said rather than implied: the UNNUMBERED
#   REDs, and every fixture that carries no ordinal at all -- the suite deliberately holds more
#   fixtures than the document enumerates (a strict parser's fail-closed cases, the controls), so
#   this is a binding over the ordinals and not a bijection over the fixtures.
G5_ORDINALS = ["First", "Second", "Third", "Fourth", "Fifth", "Sixth", "Seventh", "Eighth",
               "Ninth", "Tenth", "Eleventh", "Twelfth", "Thirteenth", "Fourteenth", "Fifteenth"]
_g5_ord_re = re.compile(r"\*\*(%s) RED" % "|".join(G5_ORDINALS), re.I)
g5_bound, g5_ord_total = [], 0
for h in at_heads:
    body = "\n".join(lines[h[3]:h[4]])
    seen = [m.group(1).capitalize() for m in _g5_ord_re.finditer(body)]
    if not seen:
        continue
    g5_ord_total += len(seen)
    want = G5_ORDINALS[1:1 + len(seen)]
    if seen != want:
        fail.append(f"G5: `{h[1]}` numbers its REDs {seen} -- expected {want}, contiguous from "
                    f"Second (the first RED is unnumbered in this document's style). A gap means a "
                    f"RED was deleted without renumbering its neighbours; a repeat means two REDs "
                    f"answer to one name")
        continue
    suites = sorted(set(re.findall(r"`([\w./-]+\.selftest\.(?:py|mjs|sh))`", body)))
    if len(suites) != 1:
        g5_bound.append(f"{h[1]}: {len(seen)} ordinal RED(s), bound to no single suite "
                        f"({suites or 'none named'}) -- enumeration checked, execution NOT")
        continue
    src = ROOT / suites[0]
    if not src.is_file():
        fail.append(f"G5 CONTROL: `{h[1]}` names `{suites[0]}` as its suite and no such file "
                    f"exists -- the binding below would be vacuous, which reads as a pass")
        continue
    # ⛔ THE ORDINAL IS LOOKED FOR IN THE FIXTURE NAMES, NOT IN THE FILE'S TEXT, and the difference
    # was measured rather than reasoned: the first revision of this check grepped the whole file and
    # a MUTANT THAT STRIPPED THE ELEVENTH RED FROM ITS FIXTURE STILL PASSED, because the suite's own
    # docstring says the words "eleventh RED" in prose. A binding satisfied by a comment binds
    # nothing. So the names are read out of the suite's `case(...)` / `eq(...)` calls with `ast`,
    # which is the same population its own `N fixtures run` line counts.
    if src.suffix != ".py":
        g5_bound.append(f"{h[1]}: {len(seen)} ordinal RED(s), suite `{suites[0]}` is not Python -- "
                        f"its fixture names cannot be read structurally, so enumeration is checked "
                        f"and the binding is NOT")
        continue
    try:
        suite_ast = ast.parse(src.read_text())
    except SyntaxError as e:
        fail.append(f"G5 CONTROL: `{suites[0]}` does not parse ({e}) -- its fixture names are "
                    f"unread, so the binding would be vacuous")
        continue
    fixture_names = [n.args[0].value for n in ast.walk(suite_ast)
                     if isinstance(n, ast.Call) and isinstance(n.func, ast.Name)
                     and n.func.id in ("case", "eq") and n.args
                     and isinstance(n.args[0], ast.Constant) and isinstance(n.args[0].value, str)]
    if not fixture_names:
        fail.append(f"G5 CONTROL: no fixture name parsed out of `{suites[0]}` -- the binding below "
                    f"would compare against an empty set, which reads as a pass")
        continue
    suite_text = "\n".join(fixture_names)
    in_suite = {o for o in G5_ORDINALS if re.search(r"\b%s RED\b" % o, suite_text, re.I)}
    missing = [o for o in seen if o not in in_suite]
    extra = sorted(in_suite - set(seen), key=G5_ORDINALS.index)
    if missing:
        fail.append(f"G5: `{h[1]}` describes {missing} RED(s) that `{suites[0]}` does not carry -- "
                    f"a RED a document states and no fixture plants is a claim nothing holds")
    if extra:
        fail.append(f"G5: `{suites[0]}` carries {extra} RED(s) that `{h[1]}` does not describe -- "
                    f"the specification has stopped describing a test that still runs")
    if not missing and not extra:
        g5_bound.append(f"{h[1]}: {len(seen)} ordinal RED(s) <-> `{suites[0]}`, both directions")
if not g5_ord_total:
    fail.append("G5 CONTROL: no ordinal RED parsed anywhere in the acceptance tests -- the "
                "extractor found nothing, so the contiguity and binding checks below are vacuous")

# G5, second half: AN AT IS GATED AT OR AFTER THE STEP THAT BUILDS EVERY ARTIFACT ITS GREEN READS.
# Section 11 states the rule over EVERY artifact.  The check used to hold it over ONE -- the drill-down
# -- by grepping each test body for "drill-down|panel", so a test reading the status strip five steps
# early, or the desk render two steps early, was outside it; and it compared against MAX(gate steps), so
# a test co-gated at the artifact's own step satisfied the rule while an EARLIER unqualified gate on the
# same test still stood on nothing.  Both are closed here, and the population is now three things, all
# re-derived:
#
#   THE ARTIFACT -> STEP MAP, from Appendix B's own Artifact cells: every BOLD span in an Artifact cell
#   is an artifact this document names, and its row is the step that builds it.  Nothing is written
#   here, so renaming or renumbering an artifact moves the check with it.  A name appearing in two rows
#   reds -- an artifact built at two steps has no step.
#
#   WHAT EACH TEST READS, from the test's own Build bullets: a `**Reads:**` clause naming artifacts by
#   those same names.  This is a JUDGEMENT -- whether a GREEN sentence reads the desk or merely stands
#   on it is not decidable by grep -- so the judgement lives in the document, where a reviewer can
#   disagree with it, and the tool holds the arithmetic over it.  Section 11 already required exactly
#   this for the drill-down ("declares the drill-down in its Build"); this is that rule over every
#   artifact.  A test with no Reads clause reds rather than passing.
#
#   WHICH HALF EACH GATE GATES, from the Appendix B Gate cell's own qualifier: `AT-D3-6 (floor half)`
#   gates that half alone; an UNQUALIFIED mention gates the WHOLE test, every half of it.  That is what
#   makes co-gating stop masking: the row-10 mention of a split test is qualified, so it discharges the
#   panel half and leaves the floor half's step-8 mention to be checked on its own artifacts.
appB = table_rows(raw, r"^\| Order \| Artifact \| Gate \|") or []
step_of, artifact_step, g5_unread, g5_halves, g5_landed = {}, {}, [], 0, []
if not appB:
    fail.append("G5 CONTROL: Appendix B's build-order table did not parse — every acceptance test's "
                "gate step would be unread and the ordering rule below would be vacuous")
else:
    dd_step, artifact_dupe = None, []    # step_of: AT -> [(step, half-or-None)]
    for r in appB:
        c = cells(r)
        if len(c) < 3:
            continue
        if not c[0].isdigit():
            # A row the ordering rule cannot compare is a row it must not silently skip: every bold
            # name in it would go unregistered and every gate in it unenforced, while the run
            # reported clean (card#7341's rows 14-16 design round found this on a proposed `8a`).
            fail.append(f"G5: Appendix B row `{c[0]}` has an Order cell that is not an integer step. "
                        f"The ordering rule compares integer steps, so this row's artifacts and gates "
                        f"would be read by nothing — a suffixed row is a row the gate cannot see. Use a "
                        f"new number and state the dependency order in the cell")
            continue
        n = int(c[0])
        for a in re.findall(r"\*\*([^*]+)\*\*", c[1]):
            key = re.sub(r"[`\s]+", " ", a).strip().lower()
            if key in artifact_step and artifact_step[key] != n:
                artifact_dupe.append((key, artifact_step[key], n))
            artifact_step[key] = n
        if "drill-down" in c[1]:
            dd_step = n if dd_step is None else min(dd_step, n)
        for m in re.finditer(r"\[(AT-D3-\d+)\]\([^)]*\)", c[2]):
            tail = c[2][m.end():m.end() + 40]
            q = re.match(r"[\s*]*\(([^)]*?)\s+half\)", tail)
            step_of.setdefault(m.group(1), []).append((n, q.group(1).strip().lower() if q else None))
    for k, a_, b_ in artifact_dupe:
        fail.append(f"G5: Appendix B names the artifact `{k}` at step {a_} and again at step {b_}. An "
                    f"artifact built at two steps has no step, and every test that reads it would be "
                    f"checked against whichever row this parse saw last")
    # G5, THE LANDED MARKER HAS ONE FORM (card#7341 Q9).  A row whose step has landed says so in
    # its Artifact cell, and the rule above reads every BOLD span in that cell as an artifact -- so
    # a bold `✅ LANDED …` registered as a phantom artifact (step 3 measured it: writing its own
    # marker bold moved the artifact count by one), and the one row written unbolded to dodge that
    # left the table holding two forms of one thing.  Skipping bold markers in the parse
    # would have kept both forms accepted, so the check does the opposite: every marker is held to
    # ONE form, unbolded, at the head of the Artifact cell, and anything else reds -- a bold marker,
    # a marker elsewhere in the cell, and a marker in the Gate cell, where one sat on row 1.
    #   A MARKER is recognised by the glyph or by `landed` followed by a date, case-insensitive,
    # because `What landed is …` is prose two rows carry and is not a status claim.
    G5_MARKER_FORM = "✅ landed YYYY-MM-DD (card#N …) — "
    g5_marker_at_head = re.compile(r"^✅ landed \d{4}-\d{2}-\d{2} \(card#\d+[^()]*\) — ")
    g5_marker_any = re.compile(r"✅|\blanded\s+\d{4}-\d{2}-\d{2}", re.I)
    for r in appB:
        c = cells(r)
        if len(c) < 3 or not c[0].isdigit():
            continue
        n = int(c[0])
        for a in re.findall(r"\*\*([^*]+)\*\*", c[1]):
            # the SAME recognizer as the legs below: a bold name that merely contains the word
            # (`**landed-state animation**`) is an artifact, not a status claim
            if g5_marker_any.search(a):
                phantom = re.sub(r"[`\s]+", " ", a).strip().lower()
                fail.append(f"G5: Appendix B step {n}'s Artifact cell carries a BOLD status marker "
                            f"`**{a}**` — every bold span in an Artifact cell is registered as an "
                            f"artifact, so this one is a phantom artifact named `{phantom}`. A "
                            f"landed step's marker has one form, `{G5_MARKER_FORM}`, unbolded, at "
                            f"the head of the Artifact cell")
        head = g5_marker_at_head.match(c[1])
        if head:
            g5_landed.append(n)
        rest = c[1][head.end():] if head else c[1]
        if g5_marker_any.search(rest):
            fail.append(f"G5: Appendix B step {n}'s Artifact cell carries a status marker that is not "
                        f"the one form `{G5_MARKER_FORM}` at the head of the cell — "
                        f"`{g5_marker_any.search(rest).group(0)}…`. Two forms of one marker is two "
                        f"formats for one thing, and a reader cannot tell which rows have landed "
                        f"without reading every cell's prose")
        if g5_marker_any.search(c[2]):
            fail.append(f"G5: Appendix B step {n}'s Gate cell carries a status marker "
                        f"(`{g5_marker_any.search(c[2]).group(0)}…`). A landed step is marked once, "
                        f"in its Artifact cell, as `{G5_MARKER_FORM}` — the Gate cell names the gate")
    if dd_step is None:
        fail.append("G5 CONTROL: no Appendix B row names the drill-down as its artifact, so the step "
                    "that builds it is unknown and every panel-asserting test would pass this rule")
    if len(artifact_step) < 10:
        fail.append(f"G5 CONTROL: only {len(artifact_step)} artifact names parsed out of Appendix B's "
                    f"Artifact cells — the map every test is checked against is nearly empty, and an "
                    f"empty map passes every test")
    if len(step_of) < 15:
        fail.append(f"G5 CONTROL: only {len(step_of)} acceptance tests are cited by an Appendix B row "
                    f"— the gate-cell parse is broken and the ordering rule reads an empty population")

    BUILD_RE = re.compile(r"^- \*\*Build(?:\s*—\s*the\s+(.+?)\s+half[^:*]*)?:\*\*", re.M)
    g5_bullets = []                      # (test, half, bullet text, declared names or None), in order
    for h in at_heads:
        name = re.match(r"(AT-D3-\d+)", h[1]).group(1)
        body = "\n".join(lines[h[3]:h[4]])
        # every Build bullet, its half name, and the artifacts its `Reads:` clause declares
        halves = {}
        marks = list(BUILD_RE.finditer(body))
        if not marks:
            fail.append(f"G5 CONTROL: `{name}` has no **Build** bullet, so what it reads cannot be "
                        f"declared and the build-order rule is vacuous on it")
        for k, m in enumerate(marks):
            seg = body[m.end(): marks[k + 1].start() if k + 1 < len(marks) else len(body)]
            seg = seg.split("\n- **")[0]
            half = (m.group(1) or "").strip().lower() or None
            mr = re.search(r"\*\*Reads:\*\*(.*)", seg, re.S)
            if not mr:
                fail.append(
                    f"G5: `{name}`'s Build bullet"
                    f"{' for the ' + half + ' half' if half else ''} declares no **Reads:** clause. "
                    f"Section 11's rule is over every artifact a GREEN reads, and which artifacts "
                    f"those are is a reading of the prose that no gate can make for the document — so "
                    f"the test states them, by Appendix B's own artifact names, and this gate holds "
                    f"the arithmetic. A test that declares nothing would otherwise be gated anywhere")
                halves.setdefault(half, set())
                g5_bullets.append((name, half, seg, None))
                continue
            names = {re.sub(r"[`\s]+", " ", a).strip().lower()
                     for a in re.findall(r"\*\*([^*]+)\*\*", mr.group(1))}
            g5_bullets.append((name, half, seg, names))
            for a in sorted(names - set(artifact_step)):
                fail.append(f"G5: `{name}` declares that it reads `{a}`, which no Appendix B Artifact "
                            f"cell names. Either the artifact is built by no step — in which case "
                            f"nothing schedules it — or the name has drifted from the one Appendix B "
                            f"uses, and a name that matches nothing is checked against nothing")
            halves.setdefault(half, set()).update(names & set(artifact_step))
        if name not in step_of:
            fail.append(f"G5: `{name}` is gated by no Appendix B row — a test nothing schedules is a "
                        f"test an implementer has no moment to run, and Appendix B claims to be the "
                        f"whole of what is built")
            continue
        declared_halves = set(halves) - {None}
        for st, q in step_of[name]:
            if q is not None and q not in declared_halves:
                fail.append(f"G5: Appendix B step {st} gates `{name}`'s *{q} half* and the test "
                            f"declares no Build bullet for a half of that name (it declares "
                            f"{sorted(declared_halves) or 'none'}). A qualifier naming a half that "
                            f"does not exist gates nothing at all, which reads exactly like a gate")
        for half, arts in halves.items():
            # an UNQUALIFIED mention gates every half; a qualified one gates its own
            gates = [st for st, q in step_of[name] if q is None or q == half]
            if not gates:
                fail.append(f"G5: `{name}`'s *{half} half* is gated by no Appendix B row — the test is "
                            f"split and only some of its halves are scheduled, so the rest run at no "
                            f"stated moment")
                continue
            need = max((artifact_step[a] for a in arts), default=0)
            for st in sorted(gates):
                if st < need:
                    blocking = sorted(a for a in arts if artifact_step[a] > st)
                    fail.append(
                        f"G5: Appendix B step {st} gates "
                        f"{'`' + name + '`' if half is None else '`' + name + '`s *' + half + ' half*'}"
                        f", which declares it reads {blocking} — built at "
                        f"{[artifact_step[a] for a in blocking]}. Section 11: a test is gated at or "
                        f"after the step that builds EVERY artifact its GREEN reads. A gate on an "
                        f"artifact that does not exist yet is one an implementer skips or satisfies "
                        f"by building out of order; if the test has an earlier half too, it SPLITS, "
                        f"with each half named at its own step in the Gate cell. Being co-gated later "
                        f"as well does not discharge this gate — that is what an unqualified mention "
                        f"means")
        g5_halves += len(halves)
        # the recognizer half, KEPT as a failure: prose naming the panel with no half declaring it
        if dd_step is not None and re.search(r"drill-down|\bpanel\b", body) \
                and not any(artifact_step.get(a, -1) >= dd_step for arts in halves.values() for a in arts):
            fail.append(
                f"G5: `{name}`'s body names the drill-down or the panel and no half of it declares an "
                f"artifact built at or after step {dd_step}, where the drill-down is built. Either the "
                f"test reads the panel — in which case the half that does says so in its **Reads:** "
                f"clause and is gated there — or the mention is not a reading and the body should not "
                f"suggest it is")
        for a in sorted({re.sub(r"[`\s]+", " ", x).strip().lower()
                         for x in re.findall(r"\*\*([^*]+)\*\*", body)} & set(artifact_step)):
            if not any(a in arts for arts in halves.values()):
                g5_unread.append((name, a))
    for a in sorted(step_of):
        if int(a.rsplit("-", 1)[1]) not in at_ids:
            fail.append(f"G5: Appendix B gates `{a}`, which is not an acceptance test in this document")

# G5, second half (b): THE RECORD IS NOT THE LOBBY'S, AND THE WORDING IS WHAT MAKES THAT CHECKABLE.
# The client's event log is written by the client protocol at Appendix B step 3; the lobby, at step 9,
# renders it.  Calling it "the lobby log" names the RENDERER where the ARTIFACT is meant, and that is
# what gated three acceptance tests six steps after the thing they read.  The class has been re-minted
# twice -- fixed in three tests, then found in five more sites, then in three beyond those -- so the
# wording is guarded rather than re-swept by hand a fourth time.  A site may QUOTE the wrong name (this
# document has to, to forbid it), and this document marks a quoted wording with emphasis, so an
# occurrence is legal exactly when it is emphasised and a defect when it is used bare.
# Written wrap-tolerant BY HAND rather than through `prose()`: `prose` rewrites every literal space
# as `\s+`, including one INSIDE a character class, which turns `[- ]` into `[-\s+]` — a class that
# matches ONE whitespace character where a wrapped phrase has three. The plant that broke the phrase
# over a line break passed against the first version of this check for exactly that reason. `prose`
# is right for the twelve patterns that use it, none of which contains a class with a space in it.
LOBBY_LOG = re.compile(r"(?:the\s+)?lobby(?:['\u2019]s)?\s+(?:event[-\s]+)?log", re.I)
lobby_log_quoted = 0
for m in LOBBY_LOG.finditer(raw):
    ln = raw[:m.start()].count("\n") + 1
    before, after = raw[max(0, m.start() - 1):m.start()], raw[m.end():m.end() + 1]
    before2 = raw[max(0, m.start() - 2):m.start() - 1]
    # SINGLE-asterisk emphasis only: this document's mark for a wording it is quoting rather than
    # using.  `**bold**` is how it stresses a wording it MEANS, so admitting bold here would leave the
    # forbidden name one keystroke of emphasis away from legal.
    if before == "*" and after == "*" and before2 != "*":
        lobby_log_quoted += 1
        continue
    fail.append(
        f"L{ln}: `{m.group(0)}` names the LOBBY as the client event log's home. The record is the "
        f"client protocol's artifact, written as the client acts (section 5.5); the lobby at Appendix "
        f"B step 9 is one renderer of it, and the protocol builds it at step 3. Naming the renderer "
        f"where the artifact is meant is what gated three acceptance tests on a screen built six "
        f"steps after the thing they read — and it has been re-minted twice since. Where this "
        f"document must QUOTE the wrong name in order to forbid it, it emphasises it")
if not lobby_log_quoted:
    fail.append("G5 CONTROL: the phrase this check forbids appears nowhere at all, not even in the "
                "emphasised form section 11 uses to forbid it — so the recognizer has stopped "
                "matching and every bare use of it would now pass")

# G5, third half: THE ANIMATION-LOG SCHEMA HAS TWO HOMES IN SECTION 11 -- the row tuple and the
# per-class field table -- and it is now on its THIRD revision (single row -> edge/held -> phase ->
# episode_id).  Every one of those revisions widened the tuple, and the prose beside the table counts
# the table's rows.  A count and a list are one fact with two homes, and the count was left reading
# "four" against a five-row table for a whole revision.  Both directions are checked here so the next
# widening cannot land in one home only.
m_tuple = re.search(r"`\((animation_id[^`)]*)\)`", raw)
if not m_tuple:
    fail.append("G5 CONTROL: section 11's animation-log row tuple did not parse — the schema's two "
                "homes could not be compared and this check would report clean over both")
else:
    log_tuple = [t.strip() for t in m_tuple.group(1).split(",") if t.strip()]
    log_rows = table_rows(raw, r"^\| Field \| On an `edge` row \|") or []
    log_table = []
    for r in log_rows:
        m2 = re.match(r"^\|\s*`([a-z_]+)`\s*\|", r)
        if m2:
            log_table.append(m2.group(1))
    if not log_table:
        fail.append("G5 CONTROL: section 11's per-class log field table did not parse")
    for f_ in log_table:
        if f_ not in log_tuple:
            fail.append(f"G5: section 11's log field table gives `{f_}` a per-class meaning and the "
                        f"row tuple beside it does not carry that field — the schema has two homes "
                        f"and a revision landed in one of them")
    if len(log_tuple) < 6:
        fail.append(f"G5 CONTROL: only {len(log_tuple)} fields parsed from the log row tuple")
    m3 = re.search(prose(r"The ([a-z-]+) fields below take their meaning"), raw)
    if not m3:
        fail.append("G5 CONTROL: section 11 no longer states how many of the log's fields take their "
                    "meaning from the row's class, so the field table's size has no stated home to "
                    "disagree with")
    elif log_table and NUM.get(m3.group(1)) != len(log_table):
        fail.append(f"G5: section 11 says {m3.group(1)} fields take their meaning from the row's "
                    f"class and the table below it has {len(log_table)} rows — one fact, two homes, "
                    f"and this is the count that read `four` against five rows for a whole revision")

# G5, fourth half: THE EPISODE WALK AND THE SENTENCE THAT COUNTS IT.  Section 11 walks
# `fx-clear-trace` delta by delta and names each held render's entry and exit as an (A_n, episode N)
# pair, then states in prose how many episodes and how many rows that walk yields.  The two are one
# fact with two homes and the prose read `six`/`eleven` over a table yielding five and nine -- a
# figure that survived the pass that falsified it, because nothing re-computed it.  Both figures are
# re-derived here FROM THE TABLE'S OWN PAIRS, so the next edit to the walk moves the count with it.
# The walk table is INDENTED under a list item, which is exactly why nothing had ever read it: every
# table parser in this file tested `startswith("|")` and read an indented row as prose.  `table_rows`
# and `all_tables` are indent-tolerant now, so this table is in the document-wide population too; it is
# still found HERE by its own header, because this check needs the walk's ROW ORDER and reads it from
# `lines` directly rather than through a population keyed by header shape.
walk_rows = None
for _i, _l in enumerate(lines):
    if re.match(r"^\s*\| At \| The facts that moved \| Episodes \|", _l):
        walk_rows, _j = [], _i + 2
        while _j < len(lines) and lines[_j].lstrip().startswith("|"):
            walk_rows.append(lines[_j].strip())
            _j += 1
        break
if not walk_rows:
    fail.append("G5 CONTROL: section 11's `fx-clear-trace` episode walk did not parse — the count "
                "sentence beside it would have nothing to disagree with, which is the state it was "
                "in when it read `six`/`eleven` over a five-episode, nine-row table")
else:
    # ORDERED, because the predicate section 11 states and section 12's G5 row promises is a
    # positional one: a `left` row's `episode_id` matches an `entered` row THAT PRECEDES IT.  A
    # set difference is position-free -- it sees an id present and calls the pair matched -- so a
    # walk whose exit was written above its own entry satisfied it, and the failure message below
    # went on saying "with no `entered` row before it" over a check that had never looked at
    # `before`.  Both halves are asserted now: the id must exist, and its `entered` must be at an
    # EARLIER ROW.  The counts below stay set-derived, which is what they always were.
    entered_seq, left_seq = [], []
    for _ri, r in enumerate(walk_rows):
        c = cells(r)
        ep_cell = c[2] if len(c) > 2 else ""
        for aid, phase_, ep in re.findall(r"(A\d+)\s+\*\*(entered|left)\*\*\s+\(episode\s+(\d+)\)",
                                          ep_cell):
            (entered_seq if phase_ == "entered" else left_seq).append(((aid, int(ep)), _ri))
    entered, left = {k for k, _ in entered_seq}, {k for k, _ in left_seq}
    if not entered:
        fail.append("G5 CONTROL: the episode walk parsed but names no `entered` episode — the pair "
                    "recognizer is broken and both figures below would be re-derived as zero")
    entered_at = {}
    for k, _ri in entered_seq:
        entered_at.setdefault(k, _ri)
    orphan = sorted(left - entered)
    for o in orphan:
        fail.append(f"G5: the episode walk gives {o[0]} episode {o[1]} a `left` row with no `entered` "
                    f"row anywhere in the walk. Section 11's own predicate is that a `left` row's "
                    f"`episode_id` must match an `entered` row that precedes it, and the walk is the "
                    f"fixture that predicate is asserted against")
    for k, _ri in sorted(left_seq, key=lambda kv: kv[1]):
        if k in entered_at and entered_at[k] >= _ri:
            fail.append(f"G5: the episode walk gives {k[0]} episode {k[1]} a `left` row at walk row "
                        f"{_ri + 1} and its `entered` row is at walk row {entered_at[k] + 1} — not "
                        f"BEFORE it. Section 11's predicate is positional: a `left` row's "
                        f"`episode_id` must match an `entered` row that PRECEDES it, so an episode "
                        f"that is left where it has not yet been entered falsifies the fixture the "
                        f"predicate is asserted against, and the id-only check this replaced could "
                        f"not see it — a set difference has no positions in it")
    n_ep, n_rows = len(entered), len(entered) + len(left)
    m_cnt = re.search(prose(r"\*\*([A-Za-z-]+) `held` episodes, ([A-Za-z-]+) `held` rows\*\*"), raw)
    if not m_cnt:
        fail.append("G5 CONTROL: section 11 no longer states how many held episodes and rows its "
                    "episode walk yields, so the walk's own arithmetic has no stated home to check")
    else:
        said_ep, said_rows = NUM.get(m_cnt.group(1).lower()), NUM.get(m_cnt.group(2).lower())
        if said_ep != n_ep or said_rows != n_rows:
            fail.append(
                f"G5: section 11 says {m_cnt.group(1)} `held` episodes and {m_cnt.group(2)} `held` "
                f"rows, and the episode walk above it yields {WORD.get(n_ep, n_ep)} episodes "
                f"({sorted(entered)}) and {WORD.get(n_rows, n_rows)} rows "
                f"({len(entered)} entered + {len(left)} left). One fact, two homes — and this is the "
                f"pair that read six and eleven over a table yielding five and nine, because the "
                f"sentence was written once and never re-derived from the walk it describes")

fx_rows = table_rows(raw, r"^\| Fixture \| Contents \|")
fx_declared = set()
if not fx_rows:
    fail.append("G5 CONTROL: the fixture table did not parse — every fixture named in a test would "
                "go unchecked")
else:
    for r in fx_rows:
        m = re.match(r"^\|\s*`(fx-[a-z0-9-]+)`\s*\|", r)
        if m:
            fx_declared.add(m.group(1))
    if len(fx_declared) < 5:
        fail.append(f"G5 CONTROL: only {len(fx_declared)} fixtures parsed from the fixture table")
fx_used = set(re.findall(r"`(fx-[a-z0-9-]+)`", raw)) - fx_declared
fx_used |= {m for m in re.findall(r"`(fx-[a-z0-9-]+)`", "\n".join(
    "\n".join(lines[h[3]:h[4]]) for h in at_heads))}
if fx_declared:
    for f in sorted(fx_used - fx_declared):
        fail.append(f"G5: `{f}` is used by a test and is declared in no fixture row — a test whose "
                    f"fixture is described nowhere cannot be built from this document alone")
    for f in sorted(fx_declared - fx_used):
        fail.append(f"G5: `{f}` is declared as a fixture and used by no test")

# G5, fifth half: A BUILD BULLET THE HARNESS DRIVES DECLARES THE HARNESS.  The ordering rule above
# holds a test to the artifacts its `Reads:` clause declares, so a clause that omits one is a gate the
# rule cannot see under -- and the harness is the artifact fixture-replaying tests kept omitting:
# AT-D3-1's instrument half stood at step 2 while its GREEN needed step 3's harness.  Which bullets
# the harness drives is decided from the document's own vocabulary, never from a list of bullets:
#
#   THE FIXTURES are section 11's fixture table (`fx_declared`, above).  A TEST any of whose Build
#   bullets names one is a harness test, and EVERY bullet of it is harness-driven -- per test, not per
#   bullet, because a split test's later halves replay "the same fixture" or "both runs above" by
#   reference, and a per-bullet fixture match under-covers exactly those.  A test whose bullets name
#   the harness itself (`the harness`, the bold name Appendix B builds it under) is a harness test too.
#
#   THE OTHER INSTRUMENTS are Appendix B's bold artifact names whose head noun is `gate` / `gates` --
#   the other thing this document RUNS rather than reads.  The animation log is not among them: it
#   records what the harness replays and replays nothing itself.
#
# THE CLASSIFICATION DECIDES, AND IT IS CHECKED FIRST.  A harness test's every bullet must declare
# `the harness`, whatever else its clause names: a gate named beside a replayed fixture does not stand
# in for the harness that replays it.  Checking the instrument first -- the first revision of this
# half -- let a harness test swap the harness for a step-0 gate and pass, which is the round-1
# review's MAJOR on PupFuzz/mezzanine#164.
#
# AN INSTRUMENT COVERS ONLY A TEST ITS OWN APPENDIX B ROW GATES.  The classification is fed by
# recognizers -- a fixture name the token reads, or the test's own mention of the harness -- so a test
# can leave the harness class by what it omits: a test naming no fixture whose `the harness` is
# swapped for a gate name, or a fixture name written unbackticked, bold or as a link.  Round 2 of the
# same review measured exactly that.  A third recognizer would be one more thing to write around, so
# the exemption is anchored on the build order instead: a bullet that is not a harness test's is
# covered by an instrument only when the row that BUILDS that instrument also GATES the test (any half
# of it -- AT-D3-12's lineage half runs the provenance gates its manifest half is gated on at step 0).
# A test gated elsewhere that names a gate in place of what runs it reds, whatever the recognizers saw.
# Any other bullet reds too, because what runs it is undeclared and a check that guessed would pass
# exactly the bullet it cannot read.
#
# WHAT THIS HALF CANNOT DO.  It catches the ACCIDENTAL class -- a harness-driven test that forgets the
# harness.  It cannot prove a `Reads:` clause is true: a deliberately false declaration, such as a test
# added to row 0's Gate cell that reads only step-0 artifacts, passes, and stays a review question.
#
# A backticked name beginning `fx` that the fixture table does not declare is a CONTROL: the predicate
# cannot recognise that fixture, so it cannot classify the test on it -- and a malformed name dropped
# out of the harness class is exactly what would let a gate name cover the test.  The token is read
# wider than the table's own `fx-[a-z0-9-]+` shape -- any separator, any case -- so a malformed name
# (`fx_gap`, `FX-gap`) is seen rather than skipped.
G5_HARNESS = "the harness"
G5_FX_TOKEN = re.compile(r"`(fx[^`\n]*)`", re.I)
g5_harness_bullets, g5_instrument_bullets = [], []
g5_other_instruments = sorted(a for a in artifact_step if re.search(r"\bgates?$", a))
if appB and G5_HARNESS not in artifact_step:
    fail.append(f"G5 CONTROL: no Appendix B Artifact cell names `{G5_HARNESS}` in bold, so the "
                f"artifact every harness-driven Build bullet must declare has no step, and the "
                f"harness half would hold every bullet to a name nothing builds")
if appB and fx_declared and G5_HARNESS in artifact_step:
    g5_by_test = {}
    for name, half, seg, reads in g5_bullets:
        g5_by_test.setdefault(name, []).append((half, seg, reads))
    for name, bl in g5_by_test.items():
        fx_named = {t for _, seg, _ in bl for t in G5_FX_TOKEN.findall(seg)}
        for t in sorted(fx_named - fx_declared):
            fail.append(f"G5 CONTROL: `{name}` names `{t}` in a Build bullet and section 11's fixture "
                        f"table declares no such fixture, so the harness half cannot recognise what "
                        f"the test replays and classifies it on the fixtures it does recognise, or on "
                        f"none")
        fx_replayed = sorted(fx_named & fx_declared)
        harness_test = bool(fx_replayed) or any(
            re.search(r"\bthe\s+harness\b", seg, re.I) for _, seg, _ in bl)
        for i, (half, seg, reads) in enumerate(bl, 1):
            if reads is None:            # reds above: a bullet with no Reads clause declares nothing
                continue
            label = (f"`{name}`'s Build bullet {i} of {len(bl)}"
                     f"{' (the ' + half + ' half)' if half else ''}")
            instruments = sorted(reads & set(g5_other_instruments))
            if harness_test:
                g5_harness_bullets.append(label)
                if G5_HARNESS not in reads:
                    fail.append(
                        f"G5: {label} is driven by the harness — its test "
                        f"{'replays ' + str(fx_replayed) if fx_replayed else 'names the harness'}"
                        f" — and its **Reads:** clause does not declare `{G5_HARNESS}`."
                        + (f" It names {instruments} instead, and that does not stand in for the "
                           f"harness: only a test that names no fixture and not the harness, and is "
                           f"gated by the row that builds the instrument, may name it instead."if instruments else "")
                        + f" A bullet that replays a fixture reads the harness as surely as anything "
                          f"it asserts on; leave it out and the ordering rule cannot see that the "
                          f"test needs step {artifact_step[G5_HARNESS]}'s artifact, which is how "
                          f"AT-D3-1's instrument half stood at step 2")
            elif any(st == artifact_step[i] for st, _ in step_of.get(name, []) for i in instruments):
                g5_instrument_bullets.append(label)
            else:
                named = (f"names {instruments}, built at step "
                         f"{sorted({artifact_step[i] for i in instruments})}, and `{name}` is gated at "
                         f"step {sorted({st for st, _ in step_of.get(name, [])})}: an instrument "
                         f"covers only a test that the Appendix B row building it also gates"
                         if instruments else
                         f"names no instrument (one of {g5_other_instruments})")
                fail.append(
                    f"G5: {label} belongs to a test that names no fixture from section 11's table "
                    f"and does not name the harness, and its **Reads:** clause {named}. What runs "
                    f"the test is undeclared, so whether its gate stands on the harness cannot be "
                    f"decided, and a check that guessed would pass the one bullet it cannot read")
    if not g5_harness_bullets:
        fail.append("G5 CONTROL: no Build bullet was recognised as driven by the harness — the "
                    "fixture names or the Build-bullet parse are unread, and the harness half would "
                    "pass every bullet over an empty population")

# -------------------------------------- G6. Appendix A counts + marker coverage ---
appA = section_text("appendix-a--every-obligation-addressed-to-this-document") or ""
t_rows = table_rows(appA, r"^\| # \| D2 source \| Obligation \| Discharged in \|") or []
u_rows = table_rows(appA, r"^\| # \| D1 source \| Obligation \| Discharged in \|") or []
if not t_rows or not u_rows:
    fail.append("G6 CONTROL: Appendix A's obligation tables did not parse — the coverage claim "
                "would be a sentence with nothing behind it")
n_t, n_u = len(t_rows), len(u_rows)
m = re.search(prose(r"addresses this document in \*\*([a-z-]+)\*\* places"), appA)
if not m:
    fail.append("G6: Appendix A no longer states how many places D2 addresses this document, so "
                "the size of its own population is unstated")
elif NUM.get(m.group(1)) != n_t:
    fail.append(f"G6: Appendix A says D2 addresses this document in {m.group(1)} places and its "
                f"table has {n_t} rows. The two are one fact with two homes")
m = re.search(prose(r"addresses it in \*\*([a-z-]+)\*\* more"), appA)
if not m:
    fail.append("G6: Appendix A no longer states how many D1 obligations it carries")
elif NUM.get(m.group(1)) != n_u:
    fail.append(f"G6: Appendix A says D1 addresses it in {m.group(1)} more places and its table "
                f"has {n_u} rows")

def cited_sections(rows):
    out = set()
    for r in rows:
        c = cells(r)
        if len(c) < 2:
            continue
        for s in re.findall(r"§\s*(\d+(?:\.\d+)*)", c[1]):
            out.add(s)
    return out


cited_d2, cited_d1 = cited_sections(t_rows), cited_sections(u_rows)


def numbered_section_of(heads, line_no):
    """The numbered section a line falls in; None for a section with no number."""
    best = None
    for h in heads:
        if h[3] <= line_no < h[4]:
            m = re.match(r"^(\d+(?:\.\d+)*)\.?\s", h[1])
            if m:
                best = m.group(1)
    return best


# THE RECOGNIZER.  `D3` alone is not it, and reporting that it was is how three render obligations
# reached review undischarged: D2 § 4.7 and § 4.8 address this document in the words "rendered in the
# drill-down" and never say `D3`, so a grep for the marker was clean over them.  A marker this check
# cannot see is an obligation this check does not hold, so the population is the phrasings the two
# upstream documents ACTUALLY use for a render-directed clause -- re-derived over both of them, never
# stored as a section list here.
MARKERS = [r"\bD3\b", r"rendered in the drill-down", r"the drill-down can say",
           r"visible in the drill-down", r"\bmust render\b", r"renders as quiet",
           r"readable in its drill-down"]
# WRAP TOLERANCE, and it is the root cause rather than a nicety.  The recognizer used to scan LINE BY
# LINE, so a marker phrase broken across a line wrap matched nothing -- and D1 § 12.2 is typeset
# `...readable in its\ndrill-down`, which is exactly how its render obligation reached review
# undischarged.  Adding the phrase to the list above, on its own, would have changed NOTHING: the
# check would still have been clean over it.  Six of the seven phrases contain a space and are
# therefore wrap-vulnerable; today only one of them actually wraps, which is luck and not a property.
MARKER_RE = [re.compile(prose(p)) for p in MARKERS]
# A decisions register RESTATES obligations that are stated where they belong; requiring a citation of
# it would file one obligation twice.  Nothing else is exempt -- D2 § 14 IS cited, at item 9.
RESTATING = {"D2": {"13"}, "D1": {"15"}}


def marked_sections(src_lines, heads, which):
    """Scan the whole document, not line by line -- see MARKER_RE.  Matching over the joined text and
    mapping each match's offset back to a line keeps the attribution exact (a marker in § 8.2.1 marks
    § 8.2.1, not § 8) while making a wrapped phrase visible."""
    out = set()
    text = "\n".join(src_lines)
    for rx in MARKER_RE:
        for m in rx.finditer(text):
            s = numbered_section_of(heads, text.count("\n", 0, m.start()))
            if s is None or s in RESTATING[which]:
                continue
            out.add(s)
    return out


marked = marked_sections(d2_lines, D2_HEADS, "D2")
marked_d1 = marked_sections(d1_raw.split("\n"), D1_HEADS, "D1")
if len(marked) < 10 or len(marked_d1) < 3:
    fail.append(f"G6 CONTROL: the recognizer found {len(marked)} marked D2 sections and "
                f"{len(marked_d1)} marked D1 sections — it has stopped matching, and a coverage "
                f"check over an empty marker population reports clean over both documents")
uncovered = sorted(marked - cited_d2)
for s in uncovered:
    fail.append(f"G6: D2 § {s} carries a render-directed marker and no row of Appendix A cites it "
                f"from a D2-attributed position — an obligation this document did not notice is "
                f"indistinguishable from one it declined")
uncovered_d1 = sorted(marked_d1 - cited_d1)
for s in uncovered_d1:
    fail.append(f"G6: D1 § {s} carries a render-directed marker and no row of Appendix A's D1 table "
                f"cites it — the same hole as the D2 half, on the document nobody thought to grep")


def semantic_remainder(rows, marks):
    """Rows resting on no marker section: the half found by reading, printed rather than counted."""
    out = []
    for r in rows:
        c = cells(r)
        if len(c) < 2:
            continue
        if not any(x in marks for x in re.findall(r"§\s*(\d+(?:\.\d+)*)", c[1])):
            out.append(f"{c[0]} ({c[1]})")
    return out


semantic_rows = semantic_remainder(t_rows, marked)
semantic_rows_d1 = semantic_remainder(u_rows, marked_d1)
semantic = len(semantic_rows)

# -------------------------------- G7. state and badge render closure, from D2 ----
def enum_members(field):
    m = re.search(r"^\|\s*`" + re.escape(field) + r"`\s*\|[^|]*\|[^|]*\|([^|]*)\|",
                  sec_821 or "", re.M)
    return set(re.findall(r"`([a-z_]+)`", m.group(1))) if m else set()


link_m = enum_members("link_state")
act_m = enum_members("activity_state")
if not link_m or not act_m:
    fail.append("G7 CONTROL: D2's link/activity member sets did not parse from § 8.2.1's bounds "
                "cells — every render-closure comparison below would be vacuous")
render_m = (link_m - {"live"}) | act_m | {"retired"}
if link_m and act_m and len(render_m) != 10:
    fail.append(f"G7: `render_state` re-derived from D2 § 4.2's construction (retired + the four "
                f"non-live link values + the five activity values) has {len(render_m)} members, "
                f"not the 10 D2 states")

ur_rows = table_rows(sec_43 or "", r"^\| Last turn's `end_reason` \| `unknown_reason` \|") or []
ur_m = set()
for r in ur_rows:
    c = cells(r)
    if len(c) >= 2:
        ur_m |= set(re.findall(r"`([a-z_]+)`", c[1]))
if len(ur_m) != 7:
    fail.append(f"G7 CONTROL: {len(ur_m)} `unknown_reason` members parsed from D2 § 4.3, not the "
                f"seven it declares — the extractor is broken")

# The LONGEST `badges` array D2 publishes: § 8.2.2's snapshot carries one member and § 8.3.2's
# worst-case block carries all eighteen, so taking the first match reads the wrong population.
cands = re.findall(prose(r'"badges": \[(.*?)\]'), d2_raw, re.S)
badge_m = set(re.findall(r'"([a-z_]+)"', max(cands, key=len))) if cands else set()
if len(badge_m) != 18:
    fail.append(f"G7 CONTROL: {len(badge_m)} badges parsed from D2 § 8.3.2's worst-case block, not "
                f"the 18 § 8.2.1 bounds the array at")

def rendered_members(header_re):
    out = set()
    for r in table_rows(raw, header_re) or []:
        m2 = re.match(r"^\|\s*`([a-z_]+)`\s*\|", r)
        if m2:
            out.add(m2.group(1))
    return out


state_rows = table_rows(raw, r"^\| `render_state` \| Desk \| Label line \|") or []
state_rendered = rendered_members(r"^\| `render_state` \| Desk \| Label line \|")
ur_rendered = rendered_members(r"^\| `unknown_reason` \| Sentence \|")
badge_rendered = rendered_members(r"^\| Badge \| Origin \| Rendered on the desk \|")
# The three sets section 5.4's unrecognised-member rule tests against and section 7.6 publishes.  An
# earlier revision of section 12 and of tools/design/README.md claimed link_state and activity_state
# closure that this file did not implement -- the claim was the whole of the check, and `disabled` was
# missing from section 7.3 underneath it.
link_rendered = rendered_members(
    r"^\| `link_state` \| What it says about the seat \| Currency treatment \|")
act_rendered = rendered_members(
    r"^\| `activity_state` \| What it says the seat is doing \| Rendered as \|")
aet_rendered = rendered_members(r"^\| `api_error_type` \| The line beside the raw value \|")

# `api_error_type`'s members live in D1, not D2: D2 § 8.2.1 cites "D1 § 6.4's 12 members" without
# repeating them, so this is the one set re-derived from D1 -- and D2's own count is the control.
d1_by_anchor = {h[2]: h for h in D1_HEADS}
sec_d1_64 = section_text("64-turnend", d1_raw.split("\n"), d1_by_anchor)
aet_m = set()
m_aet = re.search(r"^\|\s*`api_error_type`\s*\|(.*)$", sec_d1_64 or "", re.M)
if not m_aet:
    fail.append("G7 CONTROL: D1 § 6.4's `api_error_type` row did not parse — the twelve members "
                "section 7.6 publishes would be compared against an empty set")
else:
    aet_m = set(re.findall(r"`([a-z_]+)`", m_aet.group(1))) - {"null"}
m_cnt = re.search(prose(r"D1 § 6\.4's (\d+) members"), sec_821 or "")
if m_cnt and aet_m and int(m_cnt.group(1)) != len(aet_m):
    fail.append(f"G7: D2 § 8.2.1 sources `api_error_type` to \"D1 § 6.4's {m_cnt.group(1)} members\" "
                f"and D1 § 6.4 declares {len(aet_m)} — two documents disagree about the size of one "
                f"set, and this document publishes it")
elif not m_cnt:
    fail.append("G7 CONTROL: D2 § 8.2.1 no longer states how many `api_error_type` members D1 § 6.4 "
                "declares — the cross-check on the one set re-derived from D1 is gone")

if not state_rows or not ur_rendered or not badge_rendered or not link_rendered or not act_rendered \
        or not aet_rendered:
    fail.append("G7 CONTROL: one of this document's render tables did not parse (render_state, "
                "unknown_reason, badges, link_state, activity_state, api_error_type) — the set "
                "difference would be clean because it was empty")
# `declared_set`, not `declared`: the module-level `declared()` predicate is defined above and this
# loop used to SHADOW it, so any check added after G7 that asked whether a token is a D2 field got a
# set where it expected a function.  Renaming here fixes it at the binding rather than at each caller.
for name, src, declared_set, rendered in (("render_state", "D2", render_m, state_rendered),
                                          ("unknown_reason", "D2", ur_m, ur_rendered),
                                          ("badge", "D2", badge_m, badge_rendered),
                                          ("link_state", "D2", link_m, link_rendered),
                                          ("activity_state", "D2", act_m, act_rendered),
                                          ("api_error_type", "D1 § 6.4", aet_m, aet_rendered)):
    if not declared_set or not rendered:
        continue
    for x in sorted(declared_set - rendered):
        fail.append(f"G7: `{x}` is a `{name}` member {src} can produce and this document gives it no "
                    f"render — a member with no render is a condition the fleet reports and nobody "
                    f"sees, and section 5.4's unrecognised-member rule would demote every seat "
                    f"carrying it")
    for x in sorted(rendered - declared_set):
        fail.append(f"G7: `{x}` is rendered here as a `{name}` member and {src} declares no such "
                    f"member — a render branch no input can reach")

# ------------------ G2, second half: the section 7 tables (MAJOR 3's population) ----
# Section 12's G2 row claimed "section 5, section 6.2 OR SECTION 7" and SOURCE_TABLES above is five
# headers, none of them in section 7 -- so a fabricated D2 field planted in section 7.1's state table,
# section 7.2's badge table or section 7.6's member tables left the gate GREEN, while the same
# fabrication in section 5.1 RED.  The claim was the whole of the check.
#
# Section 7's cells are PROSE about renders, not source columns, so their backticked tokens are a
# mixture: D2 fields, D2 field LEAVES (D2's own shorthand -- section 8.2.1 writes `delivery.last_receipt_at`
# and section 7.1 writes `last_receipt_at`), enum MEMBER VALUES (`working`, `lossy`, `rate_limit`), D1
# COUNTER names (section 7.2's index_overflow row cites three by name) and D1 EVENT KINDS
# (`attention.request`).  Every one of those five classes is re-derivable upstream, so the check is a
# classifier rather than a narrower claim: a token in none of the five is a field this document
# invented.  This runs after G7 because it needs G7's six member sets.
d1_counters, d1_kinds = set(), set()
sec_d1_93 = section_text("93-degradation-counters", d1_raw.split("\n"), d1_by_anchor)
crows = table_rows(sec_d1_93 or "", r"^\| Counter \| Meaning \| Consequence when non-zero \|")
if not crows:
    fail.append("G2 CONTROL: D1 section 9.3's counter table did not parse — every counter name the "
                "badge rows of section 7.2 cite would read as an invented field")
else:
    for r in crows:
        for t in re.findall(r"`([a-z_.<>]+)`", cells(r)[0]):
            t = re.sub(r"\.<[^>]*>$", "", t)
            d1_counters.add(t)
            d1_counters.add(t.split(".")[0])
    if len(d1_counters) < 20:
        fail.append(f"G2 CONTROL: only {len(d1_counters)} D1 counter names parsed from section 9.3")
for h in D1_HEADS:
    m = re.match(r"^6\.\d+\s+`([a-z]+\.[a-z_]+)`", h[1])
    if m:
        d1_kinds.add(m.group(1))
m = re.search(prose(r"the (\d+) currently-defined kinds are listed"), d1_raw)
if not m:
    fail.append("G2 CONTROL: D1 no longer states how many event kinds it defines — the kind "
                "population would have no cross-check")
elif int(m.group(1)) != len(d1_kinds):
    fail.append(f"G2: D1 says it defines {m.group(1)} event kinds and section 6's headings declare "
                f"{len(d1_kinds)} — two homes for one set")

enum_values = set()
for s in (render_m, ur_m, badge_m, link_m, act_m, aet_m):
    enum_values |= s
leaves = {t.rsplit(".", 1)[-1] for t in d2_fields if "." in t}
if not enum_values or not leaves:
    fail.append("G2 CONTROL: the enum-member or field-leaf vocabulary is empty, so every prose token "
                "in section 7 would fail as an invented field and the check would be unreadable")

S7_TABLES = [
    (r"^\| `render_state` \| Desk \| Label line \|", "7.1 render_state"),
    (r"^\| `unknown_reason` \| Sentence \|", "7.1 unknown_reason"),
    (r"^\| Badge \| Origin \| Rendered on the desk \|", "7.2 badges"),
    (r"^\| Condition \| The desk shows \| The activity state \| Treatment \|", "7.3 currency"),
    (r"^\| `link_state` \| What it says about the seat \| Currency treatment \|", "7.6 link_state"),
    (r"^\| `activity_state` \| What it says the seat is doing \| Rendered as \|", "7.6 activity_state"),
    (r"^\| `api_error_type` \| The line beside the raw value \|", "7.6 api_error_type"),
]
g2_s7_checked = 0
for header, where in S7_TABLES:
    trows = table_rows(raw, header)
    if not trows:
        fail.append(f"G2 CONTROL: the table of section {where} did not parse — every field it names "
                    f"would go unchecked, which is exactly the hole this half was added to close")
        continue
    for r in trows:
        c = cells(r)
        for cell in c[1:]:                       # column 0 is the member name; G7 owns that, both ways
            for t in re.findall(r"`([^`]+)`", cell):
                if not FIELDISH.match(t):
                    continue
                g2_s7_checked += 1
                if declared(t) or t in leaves or t in enum_values or t in d1_counters \
                        or t in d1_kinds:
                    continue
                fail.append(
                    f"G2: section {where} names `{t}`, which is not a field D2 declares, not the leaf "
                    f"of one, not a member of any of the six enum sets this document publishes, not a "
                    f"D1 section 9.3 counter and not a D1 event kind. A rendered fact with no field is "
                    f"a fact the client invented, and section 7 is where an invented one is least "
                    f"likely to be noticed by a reader")
# CONTROL, and deliberately NOT a token-count threshold.  Three of the seven tables above name no
# field at all in their prose columns today, which is a property of the document and not of the
# extractor, so a count floor would either be met vacuously or fire on a correct document.  The
# control that means something is a CAPABILITY test, evaluated on every run: feed the classifier the
# exact shape of the defect this half exists to catch and require it to reject it.  A check that
# cannot fail is a decoration, and this one proves it can, every time it runs.
_probe = "context.burn_rate"
if declared(_probe) or _probe in leaves or _probe in enum_values or _probe in d1_counters \
        or _probe in d1_kinds:
    fail.append(f"G2 CONTROL: the section 7 classifier ACCEPTS the fabricated field `{_probe}` — one "
                f"of its five vocabularies has widened to admit anything, so this half would report "
                f"clean over an invented D2 field, which is the exact defect it was added for")
if g2_s7_checked < 15:
    fail.append(f"G2 CONTROL: only {g2_s7_checked} tokens extracted from section 7's tables, against "
                f"{len(S7_TABLES)} tables that all parsed — the cell walk is reading the wrong columns")

# ------------------------------------------ G8. the desk-slot worked example ----
def fnv1a32(s):
    h = 2166136261
    for b in s.encode():
        h = ((h ^ b) * 16777619) & 0xFFFFFFFF
    return h


sec32 = section_text("32-the-desk-slot-function") or ""
m = re.search(prose(r"offset basis (\d+), prime (\d+)"), sec32)
if not m or (int(m.group(1)), int(m.group(2))) != (2166136261, 16777619):
    fail.append("G8 CONTROL: section 3.2's FNV-1a constants did not parse or do not match the "
                "function this check implements — the worked example would be checked against a "
                "different hash than the document specifies")
m = re.search(prose(r"the shipped default map, S = (\d+)"), sec32)
S = int(m.group(1)) if m else 0
if not S:
    fail.append("G8 CONTROL: section 3.2's slot count did not parse")
slot_rows = table_rows(sec32, r"^\| Seat \| `h` \| `h mod \d+` \| Probes \| Slot \|") or []
if len(slot_rows) < 4:
    fail.append(f"G8 CONTROL: {len(slot_rows)} rows parsed from section 3.2's worked assignment")
parsed = []
for r in slot_rows:
    c = cells(r)
    m2 = re.match(r"^`([^`]+)`$", c[0])
    if not m2:
        continue
    parsed.append((m2.group(1), int(c[1]), int(c[2]), int(c[3]),
                   int(re.sub(r"\D", "", c[4]))))
for key, h_stated, mod_stated, probes_stated, slot_stated in parsed:
    h = fnv1a32(key)
    if h != h_stated:
        fail.append(f"G8: section 3.2 states h(`{key}`) = {h_stated}; FNV-1a-32 over its own "
                    f"stated constants gives {h}")
    if S and h % S != mod_stated:
        fail.append(f"G8: section 3.2 states h(`{key}`) mod {S} = {mod_stated}; it is {h % S}")
if S and parsed:
    occ = {}
    for key, *_ in sorted(parsed, key=lambda t: (fnv1a32(t[0]), t[0].split("/")[-1])):
        h = fnv1a32(key)
        i = 0
        while (h + i) % S in occ:
            i += 1
        occ[(h + i) % S] = (key, i)
    for key, _h, _m, probes_stated, slot_stated in parsed:
        got_slot = [s for s, (k, _) in occ.items() if k == key][0]
        got_probes = occ[got_slot][1]
        if (got_slot, got_probes) != (slot_stated, probes_stated):
            fail.append(f"G8: section 3.2 assigns `{key}` slot {slot_stated} after "
                        f"{probes_stated} probes; the function it publishes assigns slot "
                        f"{got_slot} after {got_probes}")

sec33 = section_text("33-collision-displacement-and-why-a-desk-move-is-itself-an-event") or ""
m = re.search(prose(r"provisioning `([^`]+)` \(h = (\d+),\s*h mod (\d+) = \*\*(\d+)\*\*\)"), sec33)
if not m:
    fail.append("G8 CONTROL: section 3.3's collision example did not parse — the one worked case "
                "of the displacement rule would be unchecked")
else:
    key = "aimla/" + m.group(1)
    h, mod_s, mod_v = fnv1a32(key), int(m.group(3)), int(m.group(4))
    if h != int(m.group(2)) or h % mod_s != mod_v:
        fail.append(f"G8: section 3.3's collision example states h(`{key}`) = {m.group(2)} mod "
                    f"{mod_s} = {mod_v}; re-computed it is {h} mod {mod_s} = {h % mod_s}")
    m2 = re.search(prose(r"collides with `([^`]+)` \(h = (\d+), slot (\d+)\)"), sec33)
    if not m2:
        fail.append("G8 CONTROL: section 3.3 names no incumbent for the collision")
    else:
        inc_h = fnv1a32("aimla/" + m2.group(1))
        if inc_h != int(m2.group(2)):
            fail.append(f"G8: section 3.3 states the incumbent's h = {m2.group(2)}; it is {inc_h}")
        if h % mod_s != inc_h % mod_s:
            fail.append("G8: section 3.3's 'collision' pair does not collide under the stated "
                        "function — the worked case does not exercise the rule it illustrates")
        if (h < inc_h) is not True:
            fail.append("G8: section 3.3 says the arriving seat takes the slot, but it does not "
                        "sort lower in the (h, seat_id) order the function uses")

# ---- G8b. `S` against the SHIPPED DEFAULT map file, and its absence declared rather than implied ----
# WHAT MOVED UNDER card#9208's REVERSAL (2026-09-12) AND WHAT DID NOT.  The file this leg counts is no
# longer "the client's build artifact": it is the SHIPPED DEFAULT, the one map every room renders until
# an operator authors one, and an authored room's `S` lives in a database column this gate cannot read.
# The three branches are unchanged, because the claim they hold is unchanged -- section 10.3 declares
# a path and either the file is there (COUNTED), or it is not and the document says so (ABSENT), or the
# two disagree (CONTRADICTED / MISPLACED).  What is NEW is G8d below: the document used to be required
# to say there was NO read path, and now it is required to NAME the ones D2 declares.
# WHAT THIS REPLACES.  The leg above reads `S` out of section 3.2's prose and re-derives the worked
# table from it -- which checks the document against itself and nothing else.  Until card#9208 the
# sentence it read called the map SHIPPED while NO Tiled map existed anywhere in this repository, so
# the one figure in this document that is a COUNT OF A FILE was asserted by the prose citing it: a
# gate satisfying itself out of the document it is judging.  Both populations below are re-derived --
# the two admitted map spellings from section 10.1 clause 1's own allowlist, the object layer's name
# and the artifact's path from section 10.3 -- because a spelling or a path stored here is one free to
# disagree with the document while this check reports clean.
sec103 = section_text("103-the-floor-map") or ""
sec101 = section_text("101-the-manifest-and-the-two-gates") or ""

ABSENCE = prose(r"No floor map is vendored in this repository today")
# The sweep looks for a map ANYWHERE, because "no map is vendored" is a claim about the
# repository and not about one path.  What it skips is named rather than filtered silently,
# and each member is a tree this repository does not author: the object store, an installed
# dependency tree, and runtime scratch.  A map placed in one of them is outside the claim.
SWEEP_SKIP = {".git", "node_modules", "vendor", "storage"}
# What this leg reads out of each present map, by path, for G8e below: its `desks` objects and its
# grid.  Filled in the COUNTED branch; empty means no map was read, which G8e reports by name.
g8_maps = {}

m_sp = re.search(prose(r"\*\*`(\.tm[a-z])`, `(\.tm[a-z])`\*\* — Tiled's map"), sec101)
m_layer = re.search(prose(r"object layer named `([a-z_]+)`"), sec103)
m_path = re.search(prose(r"the \*\*shipped default\*\*, `([^`]+)`"), sec103)
m_s103 = re.search(prose(r"The shipped default map declares \*\*(\d+)\*\*"), sec103)
g8_branch = "NOT MEASURED"
if not m_sp:
    fail.append("G8 CONTROL: section 10.1 clause 1 no longer names Tiled's two map spellings in the "
                "form this leg re-derives them from, so the map file could not be resolved in either "
                "spelling and its absence would read as a clean")
elif not m_layer:
    fail.append("G8 CONTROL: section 10.3 no longer names the object layer the slots live on, so a "
                "map file could be counted on the wrong layer or on none")
elif not m_path:
    fail.append("G8 CONTROL: section 10.3 declares no path for the shipped default map — the one map "
                "the repository ships is the one every unauthored room renders, and a file nothing "
                "names the location of is one no gate can ever read")
else:
    SPELLINGS = {m_sp.group(1), m_sp.group(2)}
    layer_name = m_layer.group(1)
    declared = m_path.group(1)
    absence_declared = re.search(ABSENCE, sec103) is not None
    if not any(declared.endswith(s) for s in SPELLINGS):
        fail.append(f"G8: section 10.3 declares the shipped default at `{declared}`, whose suffix is "
                    f"none of Tiled's map spellings {sorted(SPELLINGS)} that section 10.1 clause 1 "
                    f"admits — the declared path could not be a map")
    stem = re.sub(r"\.[^.]+$", "", declared)
    candidates = [ROOT / (stem + s) for s in sorted(SPELLINGS)]
    in_tree = sorted(
        str(q.relative_to(ROOT))
        for q in ROOT.rglob("*")
        if q.is_file() and q.suffix in SPELLINGS and not (SWEEP_SKIP & set(q.relative_to(ROOT).parts))
    )
    present = [q for q in candidates if q.is_file()]

    def desk_objects(q):
        """The named object layer's objects as (id, x, y, w, h), read from the file in its own
        spelling; None when the layer is not exactly one.  An object missing a rect member raises,
        which the caller reports as a map this gate cannot read."""
        if q.suffix == ".tmj":
            doc = json.loads(q.read_text())
            hit = [l for l in doc.get("layers", [])
                   if l.get("name") == layer_name and l.get("type") == "objectgroup"]
            if len(hit) != 1:
                return None
            return [(o.get("id"), float(o["x"]), float(o["y"]), float(o["width"]), float(o["height"]))
                    for o in hit[0].get("objects", [])]
        root = ET.parse(q).getroot()
        hit = [g for g in root.iter("objectgroup") if g.get("name") == layer_name]
        if len(hit) != 1:
            return None
        return [(o.get("id"), float(o.get("x")), float(o.get("y")),
                 float(o.get("width")), float(o.get("height")))
                for o in hit[0].findall("object")]

    def map_grid(q):
        """Section 10.3's grid row — `width`, `height`, `tilewidth`, `tileheight` — read from the
        file in its own spelling.  A missing member raises, which the caller reports."""
        if q.suffix == ".tmj":
            doc = json.loads(q.read_text())
            return {k: int(doc[k]) for k in ("width", "height", "tilewidth", "tileheight")}
        root = ET.parse(q).getroot()
        return {k: int(root.get(k)) for k in ("width", "height", "tilewidth", "tileheight")}

    if in_tree and absence_declared:
        g8_branch = "CONTRADICTED"
        fail.append(f"G8: section 10.3 declares that no floor map is vendored in this repository, and "
                    f"these Tiled maps are in the tree: {in_tree}. One of the two is false, and the "
                    f"document is the copy nothing re-derives")
    elif in_tree and not present:
        g8_branch = "MISPLACED"
        fail.append(f"G8: Tiled maps exist in this repository — {in_tree} — and none of them is at "
                    f"the path section 10.3 declares ({[str(q.relative_to(ROOT)) for q in candidates]}), "
                    f"so the shipped default is not where this document says it is — a floor-v1 map "
                    f"landing at the per-room path the reversed ruling declared is this branch by name")
    elif present:
        g8_branch = f"COUNTED from {', '.join(str(q.relative_to(ROOT)) for q in present)}"
        # A stray map BESIDE the default is the same claim broken -- section 10.3 declares ONE shipped
        # map -- and until this leg existed it was not detected at all: `present` took the branch and
        # the strays went uncounted, so the sentence "the gate holds the tree in both directions" was
        # true only while no default existed (found by the third review pass of card#9208's reversal).
        strays = sorted(set(in_tree) - {str(q.relative_to(ROOT)) for q in present})
        if len(present) > 1:
            # Section 10.3 admits either spelling of the ONE default, never both: two files at one
            # stem are two shipped maps free to disagree, with nothing saying which is served.
            g8_branch += "; TWO SPELLINGS"
            fail.append(f"G8: the shipped default exists in both Tiled spellings — "
                        f"{[str(q.relative_to(ROOT)) for q in present]} — and section 10.3 declares "
                        f"one map in either spelling, not one in each; two files at one stem are two "
                        f"answers to *where does a room's map come from*")
        if strays:
            g8_branch += f"; MISPLACED beside it: {strays}"
            fail.append(f"G8: section 10.3 declares one shipped map, at "
                        f"{[str(q.relative_to(ROOT)) for q in present]}, and these Tiled maps sit "
                        f"beside it at paths it does not declare: {strays} — a second shipped map is "
                        f"a second answer to *where does a room's map come from*, which is what the "
                        f"one-default rule (D2 § 13 row 44) exists to refuse")
        for q in present:
            try:
                objs = desk_objects(q)
                g8_maps[str(q.relative_to(ROOT))] = (objs or [], map_grid(q))
            except Exception as exc:                      # a map this gate cannot read is a RED
                fail.append(f"G8: `{q.relative_to(ROOT)}` could not be parsed as a Tiled map "
                            f"({type(exc).__name__}: {exc}) — `S` cannot be checked against a file "
                            f"nothing can read, and a skip here is how the count went unchecked before")
                continue
            n_desks = None if objs is None else len(objs)
            # G8, THE SLOTS ARE PAIRWISE DISJOINT (card#7341's rows 14-16 round, PR #227 F1).  Section
            # 10.3 makes each `desks` object the furniture box its desk is drawn INSIDE, so the
            # operator's no-overlap ruling holds by construction only while the map's objects are
            # pairwise disjoint on HALF-OPEN rects -- `[x, x+w) × [y, y+h)`, section 4.6's footprint
            # rule, so a shared edge is not a shared pixel.  The console refuses nothing for an
            # authored map yet (section 14 item 28); the shipped default is held here.
            for i, a in enumerate(objs or []):
                for b in (objs or [])[i + 1:]:
                    if (a[1] < b[1] + b[3] and b[1] < a[1] + a[3]
                            and a[2] < b[2] + b[4] and b[2] < a[2] + a[4]):
                        fail.append(f"G8: `{q.relative_to(ROOT)}` `{layer_name}` objects id {a[0]} and "
                                    f"id {b[0]} share a pixel — section 10.3 makes each object the "
                                    f"furniture box its desk is drawn inside, so two objects that "
                                    f"intersect on half-open rects are two desks drawn over each "
                                    f"other, and the no-overlap ruling AT-D3-20 holds rests on the "
                                    f"shipped default being pairwise disjoint")
            if objs:
                g8_branch += (f"; {len(objs)} `{layer_name}` objects held pairwise disjoint on "
                              f"half-open rects")
            if n_desks is None:
                fail.append(f"G8: `{q.relative_to(ROOT)}` declares no single object layer named "
                            f"`{layer_name}`, which section 10.3 requires and section 3.2's slot "
                            f"function reads its slots from")
            elif S and n_desks != S:
                fail.append(f"G8: `{q.relative_to(ROOT)}` declares {n_desks} objects on its "
                            f"`{layer_name}` layer and this document states S = {S} — the map and the "
                            f"slot count disagree, and every worked assignment above is computed "
                            f"against the wrong modulus")
    else:
        g8_branch = "ABSENT"
        if not absence_declared:
            fail.append(f"G8: no map file exists at either spelling of the path section 10.3 declares "
                        f"({[str(q.relative_to(ROOT)) for q in candidates]}), and section 10.3 does "
                        f"not declare the absence either — so S = {S} is a count of a file that does "
                        f"not exist, stated by the only document that cites it")
    if m_s103 and S and int(m_s103.group(1)) != S:
        fail.append(f"G8: section 10.3 states the shipped default declares {m_s103.group(1)} slots and "
                    f"section 3.2 states S = {S} — one count, two homes, and the map is not there to "
                    f"settle which is right")
    elif not m_s103:
        fail.append("G8 CONTROL: section 10.3 no longer restates the shipped default's slot count in "
                    "the form this leg closes against section 3.2, so the two homes are unguarded")

# ---- G8c. the desk sprite's declared size, against the FILE -----------------------------------
# Section 12's viewport row waited on "a desk's rendered width [being] a measured number rather than
# a design intent" (section 14 item 7).  The tileset landed on 2026-09-12 and section 10.3 now states
# that width -- which makes it a number with two homes, a document and a PNG, and the document is the
# copy nothing re-derives.  G4 already binds section 12's row to section 10.3's sentence; this leg
# binds that sentence to the bytes, so the pair cannot drift from the file together.
#
# BOTH POPULATIONS ARE READ OUT OF THE DOCUMENT: the dimensions AND the path come from section 10.3's
# own sentence, so re-curating the tileset or renaming the file moves this check with it rather than
# leaving a stored `116` behind.  The PNG header is the authority -- IHDR width/height at a fixed
# offset, which is the format's own declaration about itself and needs no decoder.
#
# Two branches, and the summary prints which one ran.  DECLARED: the file must exist, be a PNG, and
# agree.  UNDECLARED: section 10.3 states no sprite measurement, which is the correct state while no
# tileset is vendored -- and it is not a silent skip, because section 12 cannot then carry the row
# either: G4 reds any figure that is not a whole token at the section it cites.
SPRITE_DECL = prose(r"A desk sprite is (\d+) px wide and (\d+) px tall\*\* \(`([^`]+)`\)")
m_sprite = re.search(SPRITE_DECL, sec103)
g8c_branch = "UNDECLARED — section 10.3 measures no sprite, so section 12 may cite none (G4 holds that half)"
if m_sprite:
    _dw, _dh, _rel = int(m_sprite.group(1)), int(m_sprite.group(2)), m_sprite.group(3)
    _sprite = ROOT / _rel
    if not _sprite.is_file():
        g8c_branch = f"MISSING — `{_rel}`"
        fail.append(f"G8: section 10.3 states a desk sprite is {_dw}x{_dh} px and names "
                    f"`{_rel}`, and no such file exists — a measurement of a file that is not "
                    f"there is the defect card#9208 found in `S`, in a second number")
    else:
        _hdr = _sprite.read_bytes()[:24]
        if _hdr[:8] != b"\x89PNG\r\n\x1a\n" or len(_hdr) < 24:
            g8c_branch = f"UNREADABLE — `{_rel}`"
            fail.append(f"G8: `{_rel}` is not a PNG this gate can read a size out of — clause 1 of "
                        f"section 10.1 admits the suffix and nothing established the dimensions, "
                        f"and a size this gate could not establish is a red rather than a skip")
        else:
            _aw, _ah = struct.unpack(">II", _hdr[16:24])
            g8c_branch = f"MEASURED from {_rel}: {_aw}x{_ah} px"
            if (_aw, _ah) != (_dw, _dh):
                fail.append(f"G8: section 10.3 states the desk sprite is {_dw}x{_dh} px and "
                            f"`{_rel}` is {_aw}x{_ah} px — the document and the file disagree, and "
                            f"section 12's viewport row rests on the document's copy")

# ---- G8e. the FURNITURE BOX at the cap and the shipped default's GRID, against their FILES ---------
# Appendix B row 14's slice B (card#7341 step 11) gave section 12 two more Measured rows -- the
# furniture box at the cap, and the shipped default's pixel size -- and each is a number with two
# homes: a sentence in section 10.3 and a file.  G4 binds section 12's row to section 10.3's sentence;
# this leg binds the sentence to the bytes, exactly as G8c does for the sprite, so a moved box or a
# re-authored map reds here rather than surviving in prose.  BOTH POPULATIONS ARE READ OUT OF THE
# DOCUMENT: the figures come from section 10.3's own sentences, the box's path from the box sentence,
# and the map from the path G8b resolved.
#
# The box is parsed with the ONE shape `App\Floor\FurnitureBox` admits -- and the shape is READ OUT
# OF THAT CLASS (its `DECLARATION` constant, a PCRE this leg translates), never copied here (PR #232
# round 1, MINOR-4): a copy would be a second home for the one contract that keeps PHP's reading and
# `import`'s equal, free to drift from it while this gate reported clean.  The translation binds
# Python's `\d` to ASCII with `re.ASCII`, as PCRE's is without `/u` (round 2, MINOR-B: `4٤0` matched
# here and int()'d to 440 while PHP refused it), and the control below screens exactly three things:
# the flag set (`m` alone), the capture-group count (two), and the two escapes a PHP single-quoted
# literal can carry whose bytes are NOT the pattern's (`\\`, `\'`), refused rather than mistranslated.
# It screens nothing else: an escape the two engines read differently (`\Z`, `\h`) passes it, and is
# kept out only by the pattern as it stands carrying none (round 3, hygiene b).  And the shipped
# default's every `desks` object is held AT LEAST the box: section 10.3 makes the object the box its
# desk is drawn inside, so an object smaller than it is section 9 F21's undersized line on every
# viewer's floor -- the state the default shipped in until slice B, and the one this leg exists to
# keep it out of.
BOX_DECL = prose(r"The furniture box at the cap is (\d+) px wide and (\d+) px tall\*\* \(`([^`]+)`\)")
GRID_DECL = prose(r"The shipped default's grid is ([\d,]+) px wide and ([\d,]+) px tall\*\*")
BOX_READER = ROOT / "server/app/Floor/FurnitureBox.php"
BOX_LINE = None
_decl = re.search(r"private const DECLARATION = '/(.+)/([a-z]*)';", BOX_READER.read_text()) if BOX_READER.is_file() else None
if _decl is None:
    fail.append(f"G8 CONTROL: `{BOX_READER.relative_to(ROOT)}` no longer declares the furniture box's one admitted "
                f"shape as `private const DECLARATION = '/…/m';`, so this leg has no shape to read the box with "
                f"and will not guess one")
elif (_decl.group(2) != "m" or len(re.findall(r"(?<!\\)\((?!\?)", _decl.group(1))) != 2
      or "\\\\" in _decl.group(1) or "\\'" in _decl.group(1)):
    fail.append(f"G8 CONTROL: `FurnitureBox::DECLARATION` is `/{_decl.group(1)}/{_decl.group(2)}` — this leg "
                f"translates a multiline PCRE with exactly two capture groups (width, height), no other flag, and "
                f"neither of the two PHP single-quote escapes (`\\\\`, `\\'`) whose bytes are not the pattern's")
else:
    # A PHP single-quoted string keeps every backslash EXCEPT in the two escapes the control above
    # refuses, so past it the pattern's bytes are the PCRE's own; its one flag, `m`, is Python's
    # `re.M`, and `re.ASCII` binds `\d` to the digits PCRE matches without `/u`.  The two groups are
    # counted as UNESCAPED `(` -- the pattern's own `\(` is a literal paren, not a group.
    BOX_LINE = re.compile(_decl.group(1), re.M | re.ASCII)
m_box = re.search(BOX_DECL, sec103)
g8e_box = "NOT MEASURED"
g8e_grid = "NOT MEASURED"
_box = None
if BOX_LINE is None:
    pass
elif not m_box:
    fail.append("G8 CONTROL: section 10.3 no longer states the furniture box at the cap in the form this "
                "leg reads (`The furniture box at the cap is N px wide and N px tall** (`path`)`), so "
                "section 12's Measured row for it is bound to prose nothing re-derives")
else:
    _bw, _bh, _brel = int(m_box.group(1)), int(m_box.group(2)), m_box.group(3)
    _bsrc = ROOT / _brel
    if not _bsrc.is_file():
        g8e_box = f"MISSING — `{_brel}`"
        fail.append(f"G8: section 10.3 states the furniture box at the cap is {_bw}x{_bh} px and names "
                    f"`{_brel}` as its one source, and no such file exists — a measurement of a file "
                    f"that is not there, in a third number")
    else:
        _found = BOX_LINE.findall(_bsrc.read_text())
        if len(_found) != 1:
            g8e_box = f"UNREADABLE — `{_brel}` declares the box {len(_found)} times in the one admitted shape"
            fail.append(f"G8: `{_brel}` declares the furniture box {len(_found)} times in the one shape "
                        f"`App\\Floor\\FurnitureBox` admits — `export const FURNITURE_BOX = "
                        f"Object.freeze({{ width: N, height: N }});`, integers, nothing computed — and "
                        f"it must declare it exactly once; a box this gate cannot read is one the console "
                        f"would validate maps against by guessing")
        else:
            _box = (int(_found[0][0]), int(_found[0][1]))
            g8e_box = f"MEASURED from {_brel}: {_box[0]}x{_box[1]} px"
            if _box != (_bw, _bh):
                fail.append(f"G8: section 10.3 states the furniture box at the cap is {_bw}x{_bh} px and "
                            f"`{_brel}` declares {_box[0]}x{_box[1]} px — the document and the file "
                            f"disagree, and section 12's box row rests on the document's copy")
m_grid = re.search(GRID_DECL, sec103)
if not m_grid:
    fail.append("G8 CONTROL: section 10.3 no longer states the shipped default's grid in pixels in the form "
                "this leg reads (`The shipped default's grid is N px wide and N px tall**`), so section "
                "12's Measured row for it is bound to prose nothing re-derives")
elif not g8_maps:
    fail.append("G8: section 10.3 states the shipped default's grid in pixels and no map file was read to "
                "hold it against — the figure is a measurement of a file this run never opened")
else:
    _gw, _gh = int(m_grid.group(1).replace(",", "")), int(m_grid.group(2).replace(",", ""))
    for _mrel, (_mobjs, _mgrid) in g8_maps.items():
        _pw, _ph = _mgrid["width"] * _mgrid["tilewidth"], _mgrid["height"] * _mgrid["tileheight"]
        g8e_grid = f"MEASURED from {_mrel}: {_pw}x{_ph} px ({_mgrid['width']}x{_mgrid['height']} tiles of {_mgrid['tilewidth']}x{_mgrid['tileheight']})"
        if (_pw, _ph) != (_gw, _gh):
            fail.append(f"G8: section 10.3 states the shipped default's grid is {_gw:,}x{_gh:,} px and "
                        f"`{_mrel}` is {_pw:,}x{_ph:,} px (`width × tilewidth` by `height × tileheight`) "
                        f"— the document and the file disagree, and section 12's grid row and its "
                        f"viewport arithmetic rest on the document's copy")
        if _box is not None:
            for _o in _mobjs:
                if _o[3] < _box[0] or _o[4] < _box[1]:
                    fail.append(f"G8: `{_mrel}` `desks` object id {_o[0]} is {_o[3]:g}x{_o[4]:g} px, "
                                f"smaller than the furniture box at the cap ({_box[0]}x{_box[1]}) — "
                                f"section 10.3 makes each object the box its desk is drawn inside, so an "
                                f"object smaller than it is F21's undersized line on every viewer's floor, "
                                f"and the shipped default is the map every unauthored room renders")
            g8e_grid += f"; {len(_mobjs)} `desks` objects held at least the box"

# ---- G8f. section 12's VIEWPORT arithmetic, re-derived from the map, the box and the viewport floor ----
# The viewport row restates, in prose, how wide the shipped default is in furniture boxes and what the
# camera's fit zoom is at the viewport floor.  Until PR #232's round-1 review that cell CLAIMED to be
# gate-bound while three planted edits to it exited 0 (MAJOR-2).  This leg is the binding: the row
# count and the boxes per row are re-derived from the map's `desks` objects (grouped by `y`), the box
# from G8e, the grid from G8b, and the viewport floor from section 12's own row, and each figure the
# cell states -- `R rows of N furniture boxes: N × W px = P px`, `on a grid **G px wide**`, `fit zoom is
# **V ÷ G ≈ Z**` -- is recomputed and held.  A figure the cell states in another form is a CONTROL red,
# not a skip.
sec12_text = section_text("12-every-number-and-where-it-comes-from") or ""
VIEW_ROW = r"^\| Floor viewport floor \| \*\*([\d,]+) × ([\d,]+) CSS px\*\* \|(.*)$"
m_view = re.search(VIEW_ROW, sec12_text, re.M)
g8f = "NOT MEASURED"
if not m_view:
    fail.append("G8 CONTROL: section 12's `Floor viewport floor` row no longer carries `**W × H CSS px**` as "
                "its Number cell, so the viewport arithmetic has no viewport to be re-derived against")
elif _box is None or not g8_maps:
    fail.append("G8: section 12's viewport arithmetic could not be re-derived — the box or the map was not "
                "read (see the G8 lines above), and the cell's figures stand unbound on this run")
else:
    _vw, _vh = int(m_view.group(1).replace(",", "")), int(m_view.group(2).replace(",", ""))
    _cell = m_view.group(3)
    m_rows = re.search(r"(\d+) rows? of (\d+) furniture boxes: (\d+) × ([\d,]+) px = ([\d,]+) px", _cell)
    m_wide = re.search(r"on a grid \*\*([\d,]+) px wide\*\*", _cell)
    m_fit = re.search(r"fit zoom is \*\*([\d,]+) ÷ ([\d,]+) ≈ (0\.\d+)\*\*", _cell)
    if not (m_rows and m_wide and m_fit):
        fail.append("G8 CONTROL: section 12's viewport cell no longer states its arithmetic in the three forms this "
                    "leg reads — `R rows of N furniture boxes: N × W px = P px`, `on a grid **G px wide**`, "
                    "`fit zoom is **V ÷ G ≈ Z**` — so the figures it does state are bound to nothing")
    else:
        for _mrel, (_mobjs, _mgrid) in g8_maps.items():
            _by_y = {}
            for _o in _mobjs:
                _by_y.setdefault(_o[2], []).append(_o)
            _per_row = sorted({len(v) for v in _by_y.values()})
            _pw = _mgrid["width"] * _mgrid["tilewidth"]
            _ph = _mgrid["height"] * _mgrid["tileheight"]
            _fit = min(_vw / _pw, _vh / _ph)
            g8f = (f"MEASURED from {_mrel}: {len(_by_y)} row(s) of {_per_row} objects, grid {_pw}x{_ph} px, "
                   f"fit {_vw}/{_pw} = {_fit:.4f} ({'width' if _vw / _pw <= _vh / _ph else 'height'} binds)")
            if len(_per_row) != 1:
                fail.append(f"G8: `{_mrel}` lays its `desks` objects in rows of unequal length {_per_row}, and "
                            f"section 12's viewport arithmetic assumes rows of one length")
                continue
            _n = _per_row[0]
            _want = {
                "rows": (int(m_rows.group(1)), len(_by_y)),
                "boxes per row": (int(m_rows.group(2)), _n),
                "multiplier": (int(m_rows.group(3)), _n),
                "box width": (int(m_rows.group(4).replace(",", "")), _box[0]),
                "desk across": (int(m_rows.group(5).replace(",", "")), _n * _box[0]),
                "grid width": (int(m_wide.group(1).replace(",", "")), _pw),
                "fit's viewport": (int(m_fit.group(1).replace(",", "")), _vw),
                "fit's grid": (int(m_fit.group(2).replace(",", "")), _pw),
            }
            for _name, (_stated, _real) in _want.items():
                if _stated != _real:
                    fail.append(f"G8: section 12's viewport cell states {_name} = {_stated:,} and the map, the box "
                                f"and the viewport floor re-derive {_real:,} — the cell's arithmetic drifted from "
                                f"what it is stated to be computed from (`{_mrel}`, `{_brel}`)")
            if m_fit.group(3) != f"{_fit:.2f}":
                fail.append(f"G8: section 12's viewport cell states a fit zoom of {m_fit.group(3)} and "
                            f"{_vw:,} ÷ {_pw:,} is {_fit:.4f}, {_fit:.2f} to two places — the cell's zoom drifted "
                            f"from the grid it is computed on")
            if _vw / _pw > _vh / _ph:
                fail.append(f"G8: section 12's viewport cell computes its fit zoom on the grid's WIDTH and on "
                            f"`{_mrel}` the height binds first ({_vh} ÷ {_ph} < {_vw} ÷ {_pw}) — the stated zoom "
                            f"is not the fit")

# ---- G8d. THE READ PATHS section 10.3 names must be EXACTLY the ones D2 § 8.7 declares -------------
# Under the 2026-09-09 ruling this document was required to say the map had NO read path, and D2 was
# required to say so too (its section 8.2 declared "no fifth endpoint ... none that serves a floor
# map").  card#9208's reversal inverted both: the map is served, so section 10.3 must NAME the surface
# it is fetched from -- and a path this document names that D2 does not declare is card#8075's defect
# in its exact shape, a renderer fetching from a surface nobody designed.  The closure runs BOTH ways
# against D2 section 8.7's own table: every path 10.3 names must be a `GET` row somewhere in D2, and
# every `GET` row of section 8.7 must be named by 10.3 -- so dropping the map path while the layout
# path stays is a red, not a quieter green.  Every population is re-derived: 10.3's paths from its own
# backticked `GET /api/...` spans, D2's rows from its tables on every run, so a surface D2 moves or
# renames moves this check with it.
d3_paths = set(re.findall(r"`GET (/api/[^`\s]+)`", sec103))
d2_paths, d2_87_paths = set(), set()
for _m in re.finditer(r"^\|\s*`GET`\s*\|\s*`([^`\s?]+)", d2_raw, re.M):
    d2_paths.add(_m.group(1))
_sec87 = section_text("87-the-building-surface--the-layout-the-room-maps-and-the-message-that-says-one-changed",
                      d2_lines, d2_by_anchor) or ""
for _m in re.finditer(r"^\|\s*`GET`\s*\|\s*`([^`\s?]+)", _sec87, re.M):
    d2_87_paths.add(_m.group(1))
if len(d2_paths) < 4:
    fail.append(f"G8d CONTROL: only {len(d2_paths)} `GET` rows parsed out of D2's endpoint tables — the "
                f"declared set is under-read, and every path section 10.3 names would red as undeclared")
if len(d2_87_paths) < 2:
    fail.append(f"G8d CONTROL: D2 § 8.7's table yields {len(d2_87_paths)} `GET` rows — the building "
                f"surface's own population did not parse, so the equality below would be vacuous")
if not d3_paths:
    fail.append("G8d CONTROL: section 10.3 names no `GET /api/...` read path for a room's map — under "
                "card#9208's reversal the map is served, so a section that names no surface is the "
                "silence card#8075's renderer filled in with a surface of its own")
for _p in sorted(d3_paths - d2_paths):
    fail.append(f"G8d: section 10.3 fetches from `GET {_p}` and no D2 endpoint table declares that path "
                f"(D2 declares {sorted(d2_paths)}) — a read surface this document names and the "
                f"contract does not, which is card#8075's shape")
for _p in sorted(d2_87_paths - d3_paths):
    fail.append(f"G8d: D2 § 8.7 declares `GET {_p}` for the building and section 10.3 no longer names "
                f"it — the document has stopped saying where that document comes from, which is the "
                f"silence this leg exists to refuse")

# ---- G8g. the WORKED FLOORS laid at the furniture box: section 4.6's two rows and D2 § 8.7 ----------
# Appendix B row 14's slice C re-derived three worked examples to section 12's Measured box -- section
# 4.6's *both of the operator's floors* table, D2 § 8.7's sentence on the worked floor's authored rooms,
# and D2 § 8.7's worked `GET /api/building/rooms/aimla/map` document -- and each carries the box, or a
# figure computed from it, in prose.  G8e binds the box to its file; this leg binds those copies to
# G8e's box (PR #234 round 1, F2 and F4), so a moved box reds here instead of leaving a worked floor
# that the console would refuse.  EVERY FIGURE IS READ OUT OF THE DOCUMENTS and re-derived: each grid's
# pixel size from its tile count, the office pitch from the office width and the stated gap, the
# floor's extent from the rooms and the hallway, each room's desks against the box, each floor's
# placed rooms pairwise disjoint on half-open rects (§ 4.6's own rule), and § 8.7's worked JSON parsed
# as JSON.  A figure stated in another form is a CONTROL red, never a skip.
def _n(s):
    return int(s.replace(",", ""))


sec46 = section_text("46-the-building-layout") or ""
g8g = []
_OFFICE = (r"each map a (\d+) × (\d+) grid of (\d+) px tiles — ([\d,]+) × ([\d,]+) px — with one `desks` "
           r"object at \[§ 12\]\([^)]*\)'s furniture box, ([\d,]+) × ([\d,]+) px")
_PITCH = r"the \*i\*-th office at `\{x: ([\d,]+)·i, y: ([\d,]+)\}` — ([\d,]+) px between neighbours"
_HALL = r"`hallway`: a (\d+) × (\d+) grid of corridor tiles, ([\d,]+) × ([\d,]+) px"
_EXTENT = r"The floor's extent is their union, ([\d,]+) × ([\d,]+) px"
_COUNT = r"^\| \*a hallway with (\d+) office rooms"
_ROOM = (r"`(\w+)`, (\w+) seats?, a (\d+) × (\d+) grid of (?:(\d+) px|the same) tiles — ([\d,]+) × ([\d,]+) "
         r"px — with `S = (\d+)`, (\w+) rows? of (\w+)")
_AT = r"`(\w+)` at `\{x: ([\d,]+), y: ([\d,]+)\}`"
_forms = {k: re.findall(v, sec46, re.M) for k, v in
          (("office", _OFFICE), ("pitch", _PITCH), ("hallway", _HALL), ("extent", _EXTENT), ("count", _COUNT))}
_rooms = re.findall(_ROOM, sec46)
_bad = [k for k, v in _forms.items() if len(v) != 1]
if _box is None:
    fail.append("G8g: section 4.6's and D2 § 8.7's worked floors could not be held to the furniture box — "
                "G8e read no box on this run (see the G8 lines above), so their figures stand unbound")
elif _bad or len(_rooms) != 2:
    fail.append(f"G8g CONTROL: section 4.6's worked floors no longer state {_bad or ['the two rooms of the second floor']} "
                f"in the form this leg reads (each form exactly once; the second floor's rooms as "
                f"``name`, N seats, a C × R grid of T px tiles — W × H px — with `S = N`, R rows of N`), "
                f"so the figures they do state are bound to nothing")
else:
    _c, _r, _t, _w, _h, _bw, _bh = map(_n, _forms["office"][0])
    _pitch, _oy, _gap = map(_n, _forms["pitch"][0])
    _hc, _hr, _hw, _hh = map(_n, _forms["hallway"][0])
    _ew, _eh = map(_n, _forms["extent"][0])
    _k = _n(_forms["count"][0])
    _want = {
        "the office's box": ((_bw, _bh), _box),
        "the office's pixel size": ((_w, _h), (_c * _t, _r * _t)),
        "the hallway's pixel size": ((_hw, _hh), (_hc * _t, _hr * _t)),
        "the office pitch": (_pitch, _w + _gap),
        "the offices' y": (_oy, _hh),
        "the floor's extent": ((_ew, _eh), (max(_hw, _pitch * (_k - 1) + _w), _oy + _h)),
    }
    for _name, (_stated, _real) in _want.items():
        if _stated != _real:
            fail.append(f"G8g: section 4.6's office floor states {_name} = {_stated} and it re-derives "
                        f"{_real} from the row's own figures and the furniture box {_box[0]}x{_box[1]}")
    if _w < _box[0] or _h < _box[1] or _gap <= 0:
        fail.append(f"G8g: section 4.6's office map is {_w}x{_h} px at a {_gap} px gap, and a room must hold "
                    f"one desk at the furniture box {_box[0]}x{_box[1]} (§ 14 item 28(1)) with its neighbours "
                    f"disjoint")
    _ext = {}
    _tile = None
    for _nm, _seats, _cc, _rr, _tt, _pw, _ph, _s, _rows, _per in _rooms:
        _tile = int(_tt) if _tt else _tile
        _rows_n, _per_n = NUM.get(_rows, -1), NUM.get(_per, -1)
        _ext[_nm] = (_n(_pw), _n(_ph))
        if _tile is None or (_n(_pw), _n(_ph)) != (int(_cc) * _tile, int(_rr) * _tile):
            fail.append(f"G8g: section 4.6 states `{_nm}`'s map is {_pw} × {_ph} px and its grid "
                        f"{_cc} × {_rr} of {_tile} px tiles re-derives otherwise")
        if int(_s) != _rows_n * _per_n:
            fail.append(f"G8g: section 4.6 states `{_nm}` has `S = {_s}` laid as {_rows} row(s) of {_per}")
        if _per_n * _box[0] > _n(_pw) or _rows_n * _box[1] > _n(_ph):
            fail.append(f"G8g: section 4.6 lays `{_nm}`'s desks {_rows} row(s) of {_per} at the furniture box "
                        f"{_box[0]}x{_box[1]}, which needs {_per_n * _box[0]}x{_rows_n * _box[1]} px, on a "
                        f"{_pw} × {_ph} px map")
    _row2 = next((l for l in sec46.split("\n") if all(f"`{nm}`, " in l for nm in _ext)), "")
    _at = {nm: (_n(x), _n(y)) for nm, x, y in re.findall(_AT, _row2)}
    if set(_at) != set(_ext):
        fail.append(f"G8g CONTROL: section 4.6's second floor places {sorted(_at)} and sizes {sorted(_ext)} — "
                    f"a room this leg cannot both place and size is one whose overlap it cannot judge")
    else:
        _ns = sorted(_at)
        for _i, _a in enumerate(_ns):
            for _b in _ns[_i + 1:]:
                (_ax, _ay), (_aw, _ah) = _at[_a], _ext[_a]
                (_bx, _by), (_bw2, _bh2) = _at[_b], _ext[_b]
                if _ax < _bx + _bw2 and _bx < _ax + _aw and _ay < _by + _bh2 and _by < _ay + _ah:
                    fail.append(f"G8g: section 4.6 places `{_a}` and `{_b}` so that their maps intersect — "
                                f"a worked floor that § 9 F18 would name")
    g8g.append(f"section 4.6: the office floor ({_k} × {_w}x{_h} at pitch {_pitch}, extent {_ew}x{_eh}) and "
               f"{len(_rooms)} room(s) of the second floor re-derived")

    # D2 § 8.7: the sentence on the worked floor's authored rooms, and both worked JSON documents.
    _m87 = re.search(r"are authored, ([\d,]+) px wide \(one desk\s+at \[FLOOR\.md § 12\]\([^)]*\)'s furniture "
                     r"box[^)]*\)\), which is why they may sit ([\d,]+) px apart", _sec87)
    _jsons = {}
    for _lead in (r"\*\*`GET /api/building`, worked:\*\*", r"\*\*`GET /api/building/rooms/(\w+)/map`, worked\*\*"):
        _mj = re.search(_lead + r".*?```json\n(.*?)\n```", _sec87, re.S)
        if _mj:
            try:
                _jsons[_lead] = json.loads(_mj.group(_mj.lastindex))
            except ValueError:
                pass
    if not _m87 or len(_jsons) != 2:
        fail.append("G8g CONTROL: D2 § 8.7 no longer states its worked floor's authored rooms as `are authored, "
                    "W px wide (one desk at [FLOOR.md § 12](…)'s furniture box …), which is why they may sit "
                    "D px apart`, or its two worked JSON documents did not parse — so the figures it does "
                    "state are bound to nothing")
    else:
        _w87, _apart = _n(_m87.group(1)), _n(_m87.group(2))
        if _w87 < _box[0]:
            fail.append(f"G8g: D2 § 8.7 states its authored rooms are {_w87} px wide, one desk at the furniture "
                        f"box, and the box is {_box[0]} px wide")
        if _apart < _w87:
            fail.append(f"G8g: D2 § 8.7 says its rooms may sit {_apart} px apart because they are {_w87} px "
                        f"wide — rooms that far apart intersect")
        _xs = sorted(r["origin"]["x"] for f in _jsons[r"\*\*`GET /api/building`, worked:\*\*"]["layout"]["floors"]
                     for r in f["rooms"] if "origin" in r)
        if len(_xs) < 2 or any(b - a != _apart for a, b in zip(_xs, _xs[1:])):
            fail.append(f"G8g: D2 § 8.7 says its planned rooms sit {_apart} px apart and the worked layout "
                        f"places them at x = {_xs}")
        _map = _jsons[r"\*\*`GET /api/building/rooms/(\w+)/map`, worked\*\*"]["map"]
        _gw, _gh = _map["width"] * _map["tilewidth"], _map["height"] * _map["tileheight"]
        _objs = [o for l in _map["layers"] if l.get("name") == "desks" for o in l.get("objects", [])]
        if not _objs:
            fail.append("G8g CONTROL: D2 § 8.7's worked room map carries no `desks` object, and a room map "
                        "holds at least one since § 14 item 28(1)")
        for _o in _objs:
            if _o["width"] < _box[0] or _o["height"] < _box[1]:
                fail.append(f"G8g: D2 § 8.7's worked room map has `desks` object id {_o['id']} at "
                            f"{_o['width']}x{_o['height']} px, smaller than the furniture box "
                            f"{_box[0]}x{_box[1]} — the document the console would refuse")
            if _o["x"] < 0 or _o["y"] < 0 or _o["x"] + _o["width"] > _gw or _o["y"] + _o["height"] > _gh:
                fail.append(f"G8g: D2 § 8.7's worked room map has `desks` object id {_o['id']} outside its "
                            f"{_gw}x{_gh} px grid")
        g8g.append(f"D2 § 8.7: rooms {_w87} px wide at {_apart} px apart (layout x = {_xs}), and "
                   f"{len(_objs)} `desks` object(s) of the worked room map on its {_gw}x{_gh} px grid")
g8g = "; ".join(g8g) or "NOT MEASURED"

# --------------------- G9. D2 § 6.5's delivery contract, re-derived from D2 ----
# G2 asks whether a rendered field EXISTS in D2 § 8.2.1.  All ten of the members below do, which is
# why G2 was clean over a receipt age that freezes on every live desk: a field-existence check cannot
# see a DELIVERY contract.  D2 § 6.5 excludes these ten from the version-bearing set, so no delta ever
# carries them for their own sake, and D2 states the consequence as a rule on the render layer -- every
# quantity rendered from one of them must be one that cannot be moving as it is read.  This document
# carries that rule as two markers; every render row sourcing one of the ten must carry one.
sec_65 = section_text("65-the-fold", d2_lines, d2_by_anchor)
ten = set()
rows = table_rows(sec_65 or "", r"^\| Not version-bearing \| Why it moves")
if not rows:
    fail.append("G9 CONTROL: D2 § 6.5's non-version-bearing table did not parse — every render row "
                "below would be checked against an empty exclusion set and report clean over the "
                "whole class")
else:
    for r in rows:
        c = cells(r)
        ten |= {t for t in re.findall(r"`([a-z_.]+)`", c[0]) if "." in t}
if rows and len(ten) != 10:
    fail.append(f"G9: D2 § 6.5 now excludes {len(ten)} members from the version-bearing set, not the "
                f"ten this document's § 2.4 names and marks — {sorted(ten)}. The exclusion list moved "
                f"upstream and this document's freshness rule did not move with it")
leaf_of = {t.rsplit(".", 1)[-1]: t for t in ten}
MARKED_FRESH = ("fetch-fresh", "dark-only")
# WHICH member `dark-only` belongs to, re-derived from D2 § 6.5's own carve-out sentence rather than
# written in here: "...is rendered only on a `stale` or `offline` seat -- a seat that by definition is
# receiving nothing, so its `last_receipt_at` is frozen".  The marker is a permission granted to ONE
# member, and a checker holding its own copy of that fact is a checker free to disagree with D2.
DARK_MEMBER = None
m_dark = re.search(prose(r"so its `([a-z_.]+)` is frozen"), sec_65 or "")
if not m_dark:
    fail.append("G9 CONTROL: D2 § 6.5's carve-out sentence did not parse, so which member "
                "`dark-only` is granted to is unknown — the marker would then be accepted on any of "
                "the ten and the per-member half of this check would be vacuous")
else:
    DARK_MEMBER = leaf_of.get(m_dark.group(1), m_dark.group(1))
    if DARK_MEMBER not in ten:
        fail.append(f"G9 CONTROL: D2 § 6.5's carve-out names `{m_dark.group(1)}`, which is not one of "
                    f"the ten it excludes — the carve-out and the exclusion list have diverged")
# THE COLUMN MAP -- and it is deliberately NOT the population any more.  The population is every
# table `all_tables` finds; this list only says WHICH COLUMN of a render table names the source and
# WHETHER that table renders on the DESK -- two facts about a table's shape, neither of which can be
# derived from the ten.  `None` means detect over the whole row: § 4.3's panel table names the members
# by their LEAF names in its contents cell, not as dotted paths in a source column, § 5.5's narration
# table has no source column at all, and § 7.1's per-state table spreads its renders across its Desk,
# Label line and Never columns.
#
# The DESK flag is what makes `dark-only` mean something.  `dark-only` is a permission to render one
# member ON THE DESK; a desk table sourcing that member and marking it `fetch-fresh` would be claiming
# the drill-down's rule on the desk's surface.  § 5.1 and § 7.1 are the two desk render tables, and
# § 7.1 was outside this map entirely until it was found rendering the receipt age with nothing but a
# bare `dark-only` TOKEN standing between it and any marker at all.
#
# The map being incomplete is now a FAILURE rather than a silence -- see the § 5 control below.  That
# is the whole repair: a stored list that under-reads is not wrong in a way anything can see, and this
# one under-read for two revisions while the document claimed the opposite.
G9_TABLES = [
    (r"^\| Rendered element \| D2 field \| Example \| When null / absent \|", 1, "5.1", True),
    (r"^\| Rendered element \| Source \| Example \| Rule \|", 1, "5.2", False),
    (r"^\| Rendered element \| Source \| Rule \|", 1, "5.3", False),
    # § 5.7 renders BETWEEN desks and not on one, so `dark-only` -- a permission on the desk -- is
    # not its to claim; `is_desk` is False for the same reason § 5.2's and § 5.3's are.
    (r"^\| Rendered element \| D2 field \| Example \| Null / unresolved render \|", 1, "5.7", False),
    (r"^\| Rendered narration \| The client's own record \| Rule \|", None, "5.5", False),
    (r"^\| D2 member \| What renders when it is null \|", 0, "5.6", False),
    (ANIM_HEADER, ANIM_DRIVER_COL, "6.2", False),
    (r"^\| Panel section \| Contents \| Source \|", None, "4.3", False),
    (r"^\| `render_state` \| Desk \| Label line \| Animation \| Never \|", None, "7.1", True),
]
DOC_TABLES = all_tables(lines)
if len(DOC_TABLES) < 25:
    fail.append(f"G9 CONTROL: only {len(DOC_TABLES)} markdown tables found in this document — the "
                f"structural table finder is broken, and every population below is derived from it")


def g9_map_entry(header_line):
    for k, (hdr, _col, _where, _desk) in enumerate(G9_TABLES):
        if re.search(hdr, header_line):
            return k
    return None


# THE § 5 POPULATION CONTROL.  § 5 is derived from the heading numbers, never listed here, so a
# § 5.7 added tomorrow is in this loop the moment it exists.  § 2.4 states the marker rule over EVERY
# § 5 row whose source is one of the ten, so a § 5 table this gate has no column for is that rule with
# nothing behind it — which is precisely what § 5.6 was.  Refusing loudly is the point: the previous
# revision read four of the six § 5 tables and reported clean.
for _start, _header, _rows in DOC_TABLES:
    _sec = numbered_section_of(HEADS, _start)
    if _sec is None or not (_sec == "5" or _sec.startswith("5.")):
        continue
    if g9_map_entry(_header) is None:
        fail.append(f"G9: section {_sec} carries a table this gate has no column map for — its header "
                    f"is `{_header.strip()[:80]}`. Section 2.4 states the two markers as a rule over "
                    f"every section 5 row whose source is one of D2 § 6.5's ten, and a section 5 "
                    f"table outside this map is that rule with nothing behind it on that table — "
                    f"which is how section 5.6's ten-sourcing rows reached review carrying no marker")

g9_rows = g9_hits = 0
g9_covered = set()
# The population is keyed by POSITION -- the LINE NUMBER of each row -- and not by the row's text.
# Keying on the text made membership a property of the BYTES: a row byte-identical to a mapped table's
# row, pasted into a table this map has no column for, tested as `in` the population and was skipped by
# the outside-the-map rule below without ever having been checked by the inside-the-map rule either.
# The escape needs no ill intent to open: two tables that render one member with one wording is the
# ordinary way a document restates itself, which is the class this whole pass exists to close.
g9_pop_lines = set()
g9_matched = set()


def g9_hits_in(scope):
    hits = {t for t in re.findall(r"`([a-z_.]+)`", scope) if t in ten}
    # A member named INSIDE a compound span -- `(seq_epoch, last_seq)` -- is still a member this
    # row renders, and matching only whole spans let exactly that one through unmarked.  LEAF names
    # too: D2's own shorthand IS the leaf, which is what § 7.1's offline row uses.
    for span in re.findall(r"`([^`]+)`", scope):
        for tok in re.split(r"[^a-z_.]+", span):
            if tok in ten:
                hits.add(tok)
            elif tok in leaf_of:
                hits.add(leaf_of[tok])
    return hits


for _start, _header, _rows in DOC_TABLES:
    k = g9_map_entry(_header)
    if k is None:
        continue
    g9_matched.add(k)
    _hdr, col, where, is_desk = G9_TABLES[k]
    for _j, r in enumerate(_rows):
        c = cells(r)
        g9_rows += 1
        # `all_tables` yields the header's 0-based index; the data rows start two lines below it.
        g9_pop_lines.add(_start + 3 + _j)
        scope = r if col is None else (c[col] if len(c) > col else "")
        hits = g9_hits_in(scope)
        if not hits:
            continue
        g9_hits += 1
        g9_covered |= hits
        present = {mk for mk in MARKED_FRESH if mk in r}
        # PER MEMBER, not per row.  The row-scoped test asked only whether SOME marker appeared
        # anywhere in the row, so section 5.1's receipt-age row -- the one row this whole guard was
        # built for -- stayed GREEN with `dark-only` deleted, because the same row also says the raw
        # value is `fetch-fresh` IN THE DRILL-DOWN.  One member's marker for a different surface
        # satisfied the test for the member whose desk rule had just been removed.
        for m_ in sorted(hits):
            legal = {"fetch-fresh", "dark-only"} if m_ == DARK_MEMBER else {"fetch-fresh"}
            if not (present & legal):
                fail.append(
                    f"G9: section {where} renders from `{m_}`, which D2 § 6.5 excludes from the "
                    f"version-bearing set, and the row carries none of {sorted(legal)}. No "
                    f"delta ever carries that member for its own sake, so a client's copy freezes at "
                    f"the last full object it received — an age ticked from it reads *no data for N* "
                    f"on a seat that is reporting perfectly")
        if "dark-only" in present and DARK_MEMBER not in hits:
            fail.append(
                f"G9: section {where} carries `dark-only`, which D2 § 6.5's own carve-out grants to "
                f"`{DARK_MEMBER}` and to no other member — the carve-out is that a stale or offline "
                f"seat is receiving nothing, so THAT value is frozen at the server too. A row marking "
                f"any other of the ten `dark-only` claims a freshness guarantee D2 gives one member")
        if is_desk and DARK_MEMBER in hits and "dark-only" not in present:
            fail.append(
                f"G9: section {where} renders on the DESK and this row sources `{DARK_MEMBER}` "
                f"without `dark-only`. On the desk that member is renderable only on a stale or "
                f"offline seat; marking it `fetch-fresh` here would claim the desk renders it from a "
                f"response that has just answered, which is the drill-down's rule and not this "
                f"table's — and it is the substitution a row-scoped marker test could not see")
for k, (_hdr, _col, where, _desk) in enumerate(G9_TABLES):
    if k not in g9_matched:
        fail.append(f"G9 CONTROL: the table of section {where} did not parse — every bookkeeping "
                    f"member it renders would go unmarked and unchecked")
if ten and not g9_hits:
    fail.append("G9 CONTROL: no render row names one of D2 § 6.5's ten — the detector is broken, and "
                "a delivery-contract check that finds nothing to check reports clean over the class "
                "it exists for")
# PER-MEMBER COVERAGE, and it closes a hole a plant found rather than a hole a reader did.  The count
# check above ("§ 6.5 now excludes N members, not the ten") sees a member REMOVED upstream.  It cannot
# see one SUBSTITUTED: rename `reporter.uptime_s` in D2's exclusion table and the set is still ten, the
# renamed member is sourced by no row so nothing is checked, and this document goes on marking a name
# D2 no longer excludes.  Requiring every member of the ten to be sourced by at least one row makes
# the substitution loud -- and it is a true invariant of this document, which renders all ten.
g9_unsourced = sorted(ten - g9_covered)
for m_ in g9_unsourced:
    fail.append(f"G9: `{m_}` is one of D2 § 6.5's ten and NO row of the tables this check reads "
                f"sources it. Either this document dropped a render it used to carry, or the member "
                f"was renamed upstream and this document is still marking the old name — in which "
                f"case every marker naming it is governing a member D2 no longer excludes")
if not re.search(r"FLEET-STATE\.md#65-the-fold", raw):
    fail.append("G9: this document renders values D2 § 6.5 excludes from the feed and cites § 6.5 "
                "nowhere — the section that decides whether a rendered fact is deliverable is not a "
                "section a render map may leave uncited")
# THE OTHER TABLE ROWS -- a FAILURE class now, not a disclosure.  This used to be "residue class B":
# a table row outside the column map naming one of the ten was printed and passed.  That is the same
# stored-denominator defect one level out -- a render table added in section 7 or section 9 would be
# announced and admitted -- so the rule is inverted.  Outside the render map nothing is RENDERED from
# one of the ten, so the ONE legal state for such a row is `named-not-rendered`, the document's own
# token for a row that NAMES a member without rendering a quantity from it (a fixture's contents, an
# upstream derivation rule quoted, an obligation restated).  A MARKER in such a row no longer exempts
# it: that was a bare token-presence test, and it admitted section 7.1's two desk renders of the
# receipt age on the strength of the string `dark-only` appearing somewhere in the line.  The two rows
# that genuinely DISCUSS a marker rather than obey one are recognised by ROLE, immediately below.
# Every exempted row is still printed WITH THE GROUND IT STANDS ON, because an exemption nobody can
# see is a silence with extra steps.
EXEMPT = "named-not-rendered"

# THE TWO ROWS THAT MAY CARRY A MARKER WITHOUT RENDERING FROM ONE OF THE TEN, and they are found by
# their ROLE rather than by the token they happen to contain.  A marker token in a row used to exempt
# that row outright, which made the outside-the-map rule a BARE TOKEN-PRESENCE TEST: any row anywhere
# could name one of the ten and buy its way past by writing `fetch-fresh` somewhere in its prose, and
# a row could name the marker for a SURFACE IT DOES NOT RENDER ON and be admitted for it.  Two rows
# genuinely do define or describe the vocabulary rather than use it, and both are derivable:
#
#   (a) THE MARKER'S OWN DEFINITION ROW -- a row of the table whose KEY CELL IS the marker.  That is
#       section 2.4's marker table today; if it moves section, this finds it there, because what is
#       being recognised is the row's subject and not its address.
#   (b) THE GATE'S OWN DESCRIPTION ROW -- a row of section 12's guard-class table, the table in which
#       this file's checks are written down.  Found by that table's own header, for the same reason.
#
# Everything else outside the render map has exactly one legal state: `named-not-rendered`, the
# document's own token for a row that NAMES a member and draws no quantity from it.
def _norm_key(cell_text):
    return re.sub(r"[`*_ ]", "", cell_text).strip().lower()


GUARD_TABLE_HEADER = r"^\| Check \| What the tool re-derives \| Status \|"
g9_vocab_lines, g9_gatedoc_lines = set(), set()
for _start, _header, _rows in DOC_TABLES:
    _is_guard = re.search(GUARD_TABLE_HEADER, _header) is not None
    for _j, r in enumerate(_rows):
        _ln = _start + 3 + _j
        if _is_guard:
            g9_gatedoc_lines.add(_ln)
        c = cells(r)
        if c and _norm_key(c[0]) in set(MARKED_FRESH):
            g9_vocab_lines.add(_ln)
if not g9_vocab_lines:
    fail.append("G9 CONTROL: no table row in this document has one of the two markers as its key "
                "cell, so section 2.4's marker table — the row that DEFINES what `fetch-fresh` and "
                "`dark-only` permit — was not found. The vocabulary exemption below would then be "
                "granted to nothing, or, worse, the definition row itself would red")
if not g9_gatedoc_lines:
    fail.append("G9 CONTROL: section 12's guard-class table did not parse, so the row in which this "
                "gate is written down could not be told apart from a render row")

g9_prose, g9_exempt = [], []
for i, line in enumerate(lines, 1):
    if i in g9_pop_lines:
        continue
    hit = sorted(g9_hits_in(line))
    if not hit:
        continue
    if not line.lstrip().startswith("|"):
        g9_prose.append((i, hit[0]))
        continue
    if EXEMPT in line:
        g9_exempt.append((i, hit[0], EXEMPT))
        continue
    if i in g9_vocab_lines:
        g9_exempt.append((i, hit[0], "marker definition"))
        continue
    if i in g9_gatedoc_lines:
        g9_exempt.append((i, hit[0], "this gate's own description"))
        continue
    fail.append(
        f"G9: L{i} is a TABLE ROW outside this document's render map and it names `{hit[0]}`, one of "
        f"D2 § 6.5's ten, without declaring `{EXEMPT}` — and it is neither the marker's own "
        f"definition row nor a row of section 12's guard-class table, the only two rows entitled to "
        f"discuss a marker rather than obey one. Either the row renders a quantity from a member no "
        f"delta re-sends — in which case it owes `fetch-fresh` or `dark-only` AND this table owes a "
        f"column in G9's map, so the marker is checked against the member instead of merely being "
        f"present — or it only NAMES the member, in which case it says so with `{EXEMPT}`. Writing a "
        f"marker into the prose of an unmapped row buys neither: that was a token-presence test, and "
        f"it admitted section 7.1's two desk renders of the receipt age unchecked")
if not g9_exempt:
    fail.append(f"G9 CONTROL: no table row outside the render map names one of the ten at all — this "
                f"document quotes D2's exclusion list, its fixtures set those members and Appendix A "
                f"restates the obligations over them, so an empty set here means the detector stopped "
                f"matching and the class above would be vacuously clean")

# G9, THE RULE'S OWN SCOPE.  Section 2.4 states the marker rule and ENUMERATES the tables it holds
# over.  That sentence is a second home for this gate's column map, and it is the home that went false:
# it named five tables while section 5.6 sat outside the gate with six unmarked ten-sourcing rows, and
# it named seven while section 7.1 rendered the receipt age on the desk unchecked.  A prose list nobody
# re-derives is a claim that survives the change that falsifies it, so the two are set-differenced here
# in BOTH directions.  Neither side is stored: the map is the map above, the list is read out of the
# document.
sec_24 = section_text("24-the-clock-and-every-age-on-the-page") or ""
_i = sec_24.find("Every row of the render map")
_j = sec_24.find("reds when one", _i) if _i >= 0 else -1
if _i < 0 or _j < 0:
    fail.append("G9 CONTROL: section 2.4's marker-rule sentence — the one that enumerates the tables "
                "the rule holds over — did not parse. That sentence is the rule's own statement of "
                "its scope, and it is the half that has gone false twice; unparsed, it would agree "
                "with this gate's column map by never being read")
else:
    claimed = set(re.findall(r"\[§ (\d+(?:\.\d+)?)\]", sec_24[_i:_j]))
    mapped = {t[2] for t in G9_TABLES}
    if not claimed:
        fail.append("G9 CONTROL: section 2.4's marker-rule sentence names no section at all, so its "
                    "scope claim is empty and would set-difference clean against any map")
    for w in sorted(mapped - claimed):
        fail.append(f"G9: this gate holds the marker rule over section {w}'s table and section 2.4's "
                    f"marker-rule sentence does not name it. The rule and the population it runs over "
                    f"are one fact with two homes, and the prose home is the one that has gone false "
                    f"twice — first over section 5.6, then over section 7.1")
    for w in sorted(claimed - mapped):
        fail.append(f"G9: section 2.4 claims the marker rule holds over section {w} and this gate has "
                    f"no column map for that table, so nothing enforces it there. A rule stated over "
                    f"a table the gate cannot read is the exact shape section 5.6 shipped in")

# ------------------------- G10. null-render closure, re-derived from D2 § 8.2.1 ----
# Decision 13 ("a null is rendered as *not reported*, never as a zero") is unobeyable without a stated
# behaviour PER MEMBER, and section 1.1 claims this document carries one for every rendered fact.  The
# population is D2's own `Null? yes` column -- re-derived here, never stored -- and section 5.6 is the
# one home for the answers.  An earlier revision distributed them across whichever section 5 row
# happened to name the member, which stated them for a third of the population and silently omitted the
# rest, including `activity.last_received_at`: the only age a `live` desk carries, null on the
# heartbeat-only seat D2 § 3.1 rule 4 puts on the wire.
d2_nullable = set()
rows = table_rows(sec_821 or "", r"^\| Field \| Type \| Null\? \| Bounds \| Example \|")
if not rows:
    fail.append("G10 CONTROL: D2 § 8.2.1's field table did not parse for the Null? column — the "
                "null-render population would be empty and every member would report covered")
else:
    for r in rows:
        c = cells(r)
        if len(c) < 3:
            continue
        m = re.match(r"^`([A-Za-z_][\w.\[\]]*)`$", c[0])
        if m and c[2].replace("*", "").strip().lower() == "yes":
            d2_nullable.add(m.group(1))
    if len(d2_nullable) < 20:
        fail.append(f"G10 CONTROL: only {len(d2_nullable)} nullable members parsed from D2 § 8.2.1's "
                    f"Null? column — the extractor is reading the wrong column or the wrong table")

null_rendered = set()
nr_rows = table_rows(raw, r"^\| D2 member \| What renders when it is null \|")
if nr_rows is None:
    fail.append("G10 CONTROL: section 5.6's null-render table did not parse — the claim that every "
                "nullable member has a stated null render would rest on nothing at all")
else:
    for r in nr_rows:
        m = re.match(r"^\|\s*`([A-Za-z_][\w.\[\]]*)`\s*\|", r)
        if m:
            null_rendered.add(m.group(1))
    if len(null_rendered) != len(nr_rows):
        fail.append(f"G10: section 5.6 has {len(nr_rows)} rows and {len(null_rendered)} parse as a "
                    f"backticked D2 member in the first column — a row whose subject cannot be read "
                    f"is a null render bound to no member")
if d2_nullable and null_rendered:
    for x in sorted(d2_nullable - null_rendered):
        fail.append(f"G10: D2 § 8.2.1 marks `{x}` `Null? yes` and section 5.6 states no null render "
                    f"for it. Decision 13's rule is unobeyable on that member, and the implementer "
                    f"who reaches for the obvious default writes the zero decision 13 forbids")
    for x in sorted(null_rendered - d2_nullable):
        fail.append(f"G10: section 5.6 states a null render for `{x}`, which D2 § 8.2.1 does not mark "
                    f"nullable — a render branch no input can select, and if D2 stopped marking it "
                    f"nullable the row is now describing a case that cannot arrive")
# The count section 12 publishes is the same fact with a second home.
m = re.search(r"\| D2 § 8\.2\.1's nullable members \| \*\*(\d+)\*\* \|", sec12)
if not m:
    fail.append("G10 CONTROL: section 12 no longer publishes the nullable-member count, so the "
                "size of this population has no stated home to disagree with")
elif d2_nullable and int(m.group(1)) != len(d2_nullable):
    fail.append(f"G10: section 12 states {m.group(1)} nullable members and D2 § 8.2.1 marks "
                f"{len(d2_nullable)} — and `fx-nulls`' two-seat split is sized from that number")

# ---- G11. the composed `api_error_type` line: section 7.6's form vs its worked instances ----
# Section 7.1's `stalled` Label line cell is a WORKED INSTANCE of a line section 7.6 owns, and from
# this document's first revision until card#7966 it was a second, shorter READING of it:
# *API error - rate limit*, section 7.6's
# PHRASE with the raw value ELIDED, against that table's own column heading ("The line beside the raw
# value"), against section 5.4 ("the line carries the raw string either way"), against section 5.1
# ("rendered verbatim") and against the same row's own `Never` column ("`api_error_type` is always on
# the line").  Five statements, one row contradicting itself, and nothing red -- because the two sites
# could not be DIFFERENCED: the composition itself, the order and the separators of <raw value> and
# <phrase>, was published at neither, so each site was free to guess and neither was wrong against
# anything.  Section 7.6 now publishes it once; this holds the instances against that table.
#
# Both populations are read out of the document.  Nothing below stores a member, a phrase or a string.
def leading_italic(cell):
    """The published render at the head of a cell: D3 writes it as the LEADING ITALIC SPAN, with the
    reasoning in prose after it.  Backticks inside are markdown, never part of the rendered text."""
    m = re.match(r"\s*\*([^*][^*]*)\*", cell or "")
    return m.group(1).replace("`", "") if m else None


AET_PAIRS = {}
for _r in table_rows(raw, r"^\| `api_error_type` \| The line beside the raw value \|") or []:
    _c = cells(_r)
    if len(_c) >= 2:
        _m = re.match(r"^`([a-z_]+)`$", _c[0])
        _p = leading_italic(_c[1])
        if _m and _p:
            AET_PAIRS[_m.group(1)] = _p
if len(AET_PAIRS) != 12:
    fail.append(f"G11 CONTROL: {len(AET_PAIRS)} member/phrase pairs parsed out of section 7.6's "
                f"`api_error_type` table, not the twelve it publishes — every comparison below would "
                f"be run against a population that was never read, and an empty one passes silently")


def composed_of(line, pairs):
    """The member whose composition `line` is, or None.  The test IS the two rules: the raw value on
    the line VERBATIM, and its phrase AFTER it.  Deliberately not a regex over a stored template --
    a template written here is a third home for the composition, and the defect this closes is
    exactly a second one."""
    for member, phrase in pairs.items():
        i = line.find(member)
        if i < 0:
            continue
        if line.find(phrase, i + len(member)) > i:
            return member
    return None


# THE CAPABILITY TEST, run every time rather than asserted once, and BOTH WAYS -- a predicate that
# rejected everything would pass a rejection-only control while making the real check below fire for
# a reason that has nothing to do with the document.  Fed (a) the exact shape section 7.1 published
# from this document's first revision on, the phrase alone with the raw value elided, which it must
# REJECT, and (b) a line it composes itself from the same table, which it must ACCEPT as that member.
if AET_PAIRS:
    _k = sorted(AET_PAIRS)[0]
    _defect = "API error — " + AET_PAIRS[_k]
    if composed_of(_defect, AET_PAIRS) is not None:
        fail.append(f"G11 CONTROL: the composed-line test ACCEPTS {_defect!r} — section 7.6's phrase "
                    f"with the raw value elided, which is the defect this class exists to catch. A "
                    f"check that admits its own defect is a decoration")
    _good = f"API error — {_k} ({AET_PAIRS[_k]})"
    if composed_of(_good, AET_PAIRS) != _k:
        fail.append(f"G11 CONTROL: the composed-line test REJECTS {_good!r}, which it composed out of "
                    f"section 7.6's own row for `{_k}`. It is refusing everything, so its verdict on "
                    f"section 7.1's cell below carries no information either way")

_stalled_cell = None
for _r in state_rows:
    _c = cells(_r)
    if _c and _c[0] == "`stalled`" and len(_c) >= 3:
        _stalled_cell = _c[2]
if _stalled_cell is None:
    fail.append("G11 CONTROL: section 7.1's `stalled` row did not parse, so the one worked instance "
                "of section 7.6's composed line was never read and this class would be clean over "
                "nothing")
else:
    _line = leading_italic(_stalled_cell)
    if _line is None:
        fail.append("G11 CONTROL: section 7.1's `stalled` Label line cell publishes no leading "
                    "italic span, so there is no rendered string to compare — the cell may have "
                    "become prose, which is a change this class must not pass in silence")
    elif AET_PAIRS and composed_of(_line, AET_PAIRS) is None:
        fail.append(
            f"G11: section 7.1's `stalled` Label line reads {_line!r} and that is not section 7.6's "
            f"composed line for any of its twelve members — the raw wire value must be ON the line, "
            f"verbatim, with that member's phrase BESIDE it. EITHER SITE may be the one that moved, "
            f"and this gate cannot tell you which: the cell may have dropped the raw value (the "
            f"defect card#7966 fixed, against section 7.6's column heading, section 5.4, section 5.1 "
            f"and this row's own `Never` column), or section 7.6's phrase for that member may have "
            f"been rewritten without the instance following it. Read both before editing either")

# The same defect one section over, and it is where it was ILLUSTRATED rather than composed: section
# 5.1's row says `api_error_type` is *rendered verbatim* and then showed the reader section 7.6's
# PHRASE as the example of verbatimness.  An illustration that contradicts the rule it illustrates is
# read as the rule, because it is the concrete half.
_sec51 = section_text("51-the-desk") or ""
_m51 = re.search(r"^\|[^|]*\|\s*`api_error_type`\s*\|.*$", _sec51, re.M)
if not _m51:
    fail.append("G11 CONTROL: section 5.1's `api_error_type` render row did not parse — the rule "
                "whose illustration went false has no readable home here")
else:
    _c51 = cells(_m51.group(0))
    _rule51 = _c51[3] if len(_c51) >= 4 else ""
    _eg = re.search(r"verbatim\*{0,2}\s*—?\s*e\.g\.\s*(`[a-z_]+`|\*[^*]+\*)", _rule51)
    if not _eg:
        fail.append("G11 CONTROL: section 5.1's `api_error_type` row no longer illustrates *rendered "
                    "verbatim* with an example at all. The illustration is the half that went false, "
                    "so losing it silently would take this check with it")
    elif AET_PAIRS and _eg.group(1).strip("`*") not in AET_PAIRS:
        fail.append(
            f"G11: section 5.1 says `api_error_type` is *rendered verbatim* and illustrates it with "
            f"{_eg.group(1)}, which is not one of the twelve MEMBERS section 7.6 publishes. If it is "
            f"one of that table's phrases, the site stating the rule is showing the reader the value "
            f"the rule forbids — which is what it did from this document's first revision on")
# ...and the precondition that leg's discrimination rests on, checked rather than assumed: the test
# is membership in the KEYS, so it can only tell a member from a phrase while the two vocabularies
# are disjoint.  A table row whose phrase equalled a member name would make it pass on the very
# substitution it exists to catch, silently.
_collide = sorted(p for p in AET_PAIRS.values() if p in AET_PAIRS)
if _collide:
    fail.append(f"G11 CONTROL: section 7.6 publishes {_collide[0]!r} as both a PHRASE and a member "
                f"KEY, so the verbatim test above cannot tell the two apart on that row and would "
                f"pass on the phrase — the exact substitution it exists to catch")

# ---- G11, second FACT: WHERE the `activity_state` currency label is drawn ----
# The same class as the two above -- a worked example against the rule statement governing it -- on a
# different fact, and it went undetected for exactly the same reason the composed line did: the two
# sites disagreed and NOTHING could difference them.  Section 7.6's `activity_state` table OWNS the
# render form and says the *was: X (...)* form goes UNDER the label, in five rows.  Section 7.3's
# `catching_up` and `disabled` rows said IN THE LABEL ONLY -- a ONE-element reading, under which a
# `catching_up` desk's single line carries `activity.last_event_time` twice, once as its own
# timestamp and once inside the *was:* parenthetical.  A third instance was in section 7.6's OWN
# `link_state` table.  card#7966 ruled section 7.6 the owner and corrected all three; this holds them.
#
# NOTHING BELOW IS STORED.  The placement phrase is READ OUT of section 7.6's five rows -- so if that
# table is ever amended, this gate requires the worked instances to follow the amendment rather than
# the string that happened to be right today.
PREPOSITIONS = ("in", "under", "on", "inside", "beside", "below", "above", "within", "beneath")
PLACEMENT_RE = re.compile(r"\b(?:%s) the label\b" % "|".join(PREPOSITIONS))
ACT_HDR = r"^\| `activity_state` \| What it says the seat is doing \| Rendered as \|"
_act_rows = table_rows(raw, ACT_HDR) or []
_act_placements, _act_rows_seen = set(), 0
for _r in _act_rows:
    _c = cells(_r)
    if len(_c) >= 3 and re.match(r"^`[a-z_]+`$", _c[0]):
        _act_rows_seen += 1
        _act_placements |= set(PLACEMENT_RE.findall(_c[2]))
if _act_rows_seen != 5:
    fail.append(f"G11 CONTROL: {_act_rows_seen} `activity_state` rows parsed out of section 7.6, not "
                f"the five it publishes — the RULE this leg holds its instances against was never "
                f"read, and an empty rule accepts every instance in silence")
elif len(_act_placements) != 1:
    fail.append(f"G11 CONTROL: section 7.6's five `activity_state` rows state "
                f"{len(_act_placements)} different placements for the currency label "
                f"({sorted(_act_placements)}) — the rule statement disagrees with ITSELF, so no "
                f"instance below can be judged against it. That is a rule-against-rule amendment to "
                f"section 7.6 and is raised here rather than picked")
else:
    PLACEMENT = next(iter(_act_placements))

    def placement_ok(cell_text):
        """Every placement this cell states must be section 7.6's.  A cell that states none is not
        an instance of this rule and passes without being asked to carry the phrase."""
        return all(m == PLACEMENT for m in PLACEMENT_RE.findall(cell_text))

    # THE CAPABILITY TEST, run every time and BOTH WAYS, on strings composed from the phrase just
    # read rather than typed here.  The defect arm substitutes a preposition the rule does NOT use --
    # chosen from the recognizer's own alternation, so nothing here stores which one is right; the
    # pre-fix cells read *in the label only* against section 7.6's *under*.  It must REJECT that and
    # ACCEPT the same cell built with section 7.6's own phrase; without the second arm a predicate
    # that refused everything would look like a working guard.
    _was = "as *was: working (last event 12:47, seat clock)*"
    _other = next(p for p in PREPOSITIONS if not PLACEMENT.startswith(p + " "))
    _defect_cell = f"{_other} the label only, {_was}"
    if placement_ok(_defect_cell):
        fail.append(f"G11 CONTROL: the placement test ACCEPTS {_defect_cell!r}, whose preposition is "
                    f"not section 7.6's — the shape of the one-element reading card#7966 corrected, "
                    f"which puts `activity.last_event_time` on the desk twice. A check that admits "
                    f"its own defect is a decoration")
    if not placement_ok(f"{PLACEMENT}, {_was}"):
        fail.append(f"G11 CONTROL: the placement test REJECTS a cell built from section 7.6's own "
                    f"phrase {PLACEMENT!r}. It is refusing everything, so its verdict on the "
                    f"instances below carries no information either way")

    # THE POPULATION, derived STRUCTURALLY from every table in the document rather than listed here:
    # a cell is an instance of section 7.6's form if it carries a *was:* span or names the `activity
    # state` in words.  Two tables are excluded BY ROLE, and both exclusions are load-bearing:
    #
    #   * section 7.6's own `activity_state` rows are the RULE, not an instance of it.  They are
    #     checked above, by having to agree with EACH OTHER.
    #   * section 12's guard-class table DESCRIBES this gate.  Its row for G11 necessarily QUOTES the
    #     defect -- "section 7.3's rows read *in the label only*" -- because that is what a row
    #     documenting a guard says, and a recognizer that reads it as an instance FAILS ON THE
    #     CORRECTION ITSELF: the more thoroughly the defect is written up, the redder the gate, while
    #     a silent fix scores clean.  It fired exactly that way on this leg's own documentation row
    #     before the carve-out existed.  Section 12 renders nothing, so nothing is lost by it, and
    #     G9 already recognises those rows by the same role for the same kind of reason.
    _instances = 0
    for _start, _hdr, _rows in DOC_TABLES:
        if re.search(ACT_HDR, _hdr) or re.search(GUARD_TABLE_HEADER, _hdr):
            continue
        for _j, _row in enumerate(_rows):
            for _cell in cells(_row):
                # ⛔ THE TEST IS "STATES NO CONTRADICTING PLACEMENT", NOT "STATES THE PLACEMENT",
                # and the difference is a hole this gate DECLARES rather than closes.  A stricter
                # tier -- every cell carrying a *was:* span must contain section 7.6's phrase
                # verbatim -- was written, run, and REMOVED, because it fired on section 7.1's own
                # `catching_up` cell, which says the form is "drawn under this line" while POINTING
                # AT section 7.3 and section 7.6.  That cell is correct; it MENTIONS the form in
                # order to say the timestamp is not the Label line's, and no structural test here
                # can tell a mention from a placement.  Enforcing the literal would have made this a
                # STYLE rule that reds on a correct paraphrase -- the inverted shape where a careful
                # write-up scores worse than a careless one.  The cost is stated in section 12's
                # limit (3): a cell that re-words the placement out of the recognizer's vocabulary
                # escapes by matching nothing.
                if "*was:" not in _cell and "activity state" not in _cell:
                    continue
                _found = PLACEMENT_RE.findall(_cell)
                if not _found:
                    continue
                _instances += 1
                if not placement_ok(_cell):
                    fail.append(
                        f"G11: line {_start + 3 + _j} renders section 7.6's `activity_state` form "
                        f"and places it {sorted(set(_found))}, where section 7.6's own five rows "
                        f"say {PLACEMENT!r}. The currency label is a SECOND rendered element, drawn "
                        f"under section 7.1's Label line and not inside it — the one-element "
                        f"reading makes a `catching_up` desk carry `activity.last_event_time` "
                        f"twice in one line (card#7966). EITHER SITE may be the one that moved: "
                        f"section 7.6 owns the form, so an amendment there is followed here, and a "
                        f"drift here is the defect. Read both before editing either")
    if _instances == 0:
        fail.append("G11 CONTROL: no worked instance of section 7.6's `activity_state` placement "
                    "was found anywhere in this document, so this leg is clean over an empty "
                    "population — which is what a silently-narrowed recognizer looks like")

# ---- G12. the duration format: section 2.4's function, and every rendered duration ----
# Until card#9209 this document rendered durations and published no format for them.  Section 7.1's
# Label cells carried `4m 12s`, `11m` and `2h 06m` -- three exemplars NO SINGLE RULE PRODUCES -- and
# section 2.4's own fourth row carried `117 s`, a fourth form again.  Nothing could red, because
# there was no rule for an example to contradict: the class G11 catches (a worked example against
# the rule that governs it) needs a rule at one end of it.  Section 2.4 now publishes the function;
# this gate is the other end.
#
# NOTHING BELOW STORES A DURATION STRING.  The function is re-implemented from section 2.4's
# clauses -- the same standing G8 has for the desk-slot hash, which is likewise re-computed here
# from the document's stated function rather than compared to a table of answers -- and every
# expected output is READ OUT of the document, either as a boundary-table row or as the arithmetic
# a section 7.1 cell states about itself.
_s24 = section_text("24-the-clock-and-every-age-on-the-page") or ""

# The clause COUNT is a restatement of the clause list, so it is guarded rather than trusted: the
# prose says how many clauses the function has, and the clauses are counted.
_clause_nos = re.findall(r"^  (\d+)\. \*\*", _s24, re.M)
_m_stated = re.search(r"those (\w+) clauses", _s24)
if not _clause_nos:
    fail.append("G12 CONTROL: no numbered clause parsed out of section 2.4's duration rule, so the "
                "function below was re-implemented from nothing this gate can see")
elif not _m_stated:
    fail.append("G12 CONTROL: section 2.4 no longer states how many clauses the duration rule has, "
                "so the count that binds the prose to the list is gone and a dropped clause is "
                "silent")
elif NUM.get(_m_stated.group(1)) != len(_clause_nos):
    fail.append(f"G12: section 2.4 says the duration rule has {_m_stated.group(1)} clauses and "
                f"{len(_clause_nos)} are written. One of the two moved without the other")
elif [int(n) for n in _clause_nos] != list(range(1, len(_clause_nos) + 1)):
    fail.append(f"G12: section 2.4's duration clauses are numbered {_clause_nos} — not contiguous "
                f"from 1, so a clause cited by number elsewhere now points at a different one")


def render_duration(seconds, cap=2, drop=True, pad=True):
    """Section 2.4's function, re-implemented from its clauses.  The three keyword arguments are
    NOT options the document offers -- they exist so the capability control below can build the
    string each clause FORBIDS and prove the check rejects it, per clause.  Called with defaults,
    this is the published function and nothing else."""
    s = int(seconds) if seconds > 0 else 0          # clause 1: truncate, never negative
    parts = [("h", s // 3600), ("m", s // 60 % 60), ("s", s % 60)]
    first = next((i for i, (_, v) in enumerate(parts) if v), None)
    if first is None:                                # clause 7: zero renders `0s`
        return "0s"
    out = [f"{parts[first][1]}{parts[first][0]}"]    # clause 5: the first value is unpadded
    for unit, val in parts[first + 1:first + cap]:   # clause 3: at most `cap` units
        if val == 0 and drop:                        # clause 4: a zero second unit is dropped
            continue
        out.append(f"{val:02d}{unit}" if pad else f"{val}{unit}")  # clause 5: the second is padded
    return " ".join(out)                             # clause 6: one space, no plural


# The boundary table IS the document's statement of what the function returns, so reproducing it is
# the check that this implementation is the published one.  A parse that finds nothing is the
# failure, never a clean run over an empty table.
_bnd = table_rows(_s24, r"^\| Seconds in \| Renders \| The clause it is here for \|") or []
_bnd_pairs = []
for _r in _bnd:
    _c = cells(_r)
    if len(_c) < 2:
        continue
    _in = _c[0].replace("−", "-").replace(",", "").strip("*` ")
    _outm = re.match(r"^`([^`]+)`$", _c[1])
    if not _outm:
        fail.append(f"G12 CONTROL: section 2.4's boundary row for {_c[0]!r} states its output as "
                    f"{_c[1]!r} rather than as a backticked string, so this gate cannot tell the "
                    f"rendered string from the prose around it")
        continue
    try:
        _bnd_pairs.append((float(_in), _outm.group(1)))
    except ValueError:
        fail.append(f"G12 CONTROL: section 2.4's boundary row input {_c[0]!r} is not a number of "
                    f"seconds, so the row asserts nothing this gate can evaluate")
if len(_bnd_pairs) < 2:
    fail.append(f"G12 CONTROL: {len(_bnd_pairs)} boundary rows parsed out of section 2.4 — the "
                f"function below was reproduced against nothing, and an empty population passes "
                f"in silence")
for _sec, _want in _bnd_pairs:
    _got = render_duration(_sec)
    if _got != _want:
        fail.append(f"G12: section 2.4 says {_sec:g} seconds renders {_want!r} and its own clauses, "
                    f"re-implemented, return {_got!r}. EITHER END may be the one that moved — a "
                    f"clause edited without its outputs, or an output edited without its clause")

DUR_RE = re.compile(r"(?<![\w:.−-])\d+ ?[hms](?: \d+ ?[hms])*(?![\w:])")


def parse_duration(tok):
    """Seconds in a duration-shaped token, or None.  Deliberately LOOSER than the format: it reads
    `2h 6m`, `11m 00s` and `117 s` too, because the strings this gate exists to catch are exactly
    the ones the format would never emit, and a parser that only read legal strings would report
    clean by failing to see them."""
    total, seen = 0, False
    for val, unit in re.findall(r"(\d+) ?([hms])", tok):
        total += int(val) * {"h": 3600, "m": 60, "s": 1}[unit]
        seen = True
    return total if seen else None


SPAN_RE = re.compile(r"\s*(\*{1,3})(.+?)\1(?!\*)")


def published_span(cell):
    """The rendered string a cell publishes, and where it ENDS: its LEADING emphasis span,
    `*x*` / `**x**` / `***x***`.  Same convention G11 reads, widened to the doubled and tripled
    delimiters section 2.4's Verbatim column uses.  Prose AFTER the span is not a rendered string
    and is not read -- section 7.1's dark cells argue about `300 s` and `900 s` thresholds in
    theirs, and neither is drawn, so the end offset is returned rather than recovered with an
    `index()` the backtick-stripping above can make raise."""
    m = SPAN_RE.match(cell or "")
    return (m.group(2).replace("`", ""), m.end()) if m else (None, 0)


# THE CAPABILITY CONTROL, run every pass and PER CLAUSE.  A fixed-point test whose function agreed
# with nothing would reject every string and this gate would fire on the document for reasons that
# have nothing to do with it; one whose function agreed with everything would accept `11m 00s`.
# Both directions are proven from the document's own boundary rows: the canonical output must be
# accepted, and each per-clause perturbation of it -- the undropped zero unit, the unpadded second
# unit, the third unit -- must be rejected.
_cap_pos = _cap_neg = 0
for _sec, _want in _bnd_pairs:
    if render_duration(_sec) != _want:
        continue                                     # already failed above; not a control result
    _cap_pos += 1
    for _label, _variant in (("clause 4, the zero unit undropped", render_duration(_sec, drop=False)),
                             ("clause 5, the second unit unpadded", render_duration(_sec, pad=False)),
                             ("clause 3, a third unit", render_duration(_sec, cap=3))):
        if _variant == _want:
            continue                                 # this row does not exercise that clause
        _cap_neg += 1
        if render_duration(parse_duration(_variant)) == _variant:
            fail.append(f"G12 CONTROL: the fixed-point test ACCEPTS {_variant!r} — {_label} — which "
                        f"section 2.4 forbids and which is the shape this gate exists to catch. A "
                        f"check that admits its own defect is a decoration")
if _bnd_pairs and _cap_neg == 0:
    fail.append("G12 CONTROL: section 2.4's boundary table exercises none of clauses 3, 4 and 5 — "
                "no row of it can be perturbed into a string the format forbids, so the "
                "discrimination this gate rests on was never demonstrated on this run")

# ---- G12 leg A: every duration inside a PUBLISHED RENDERED SPAN is a fixed point ---------------
_dur_rows = table_rows(_s24, r"^\| Duration \| Field \| The string, verbatim \| Where it may appear \|") or []
if not _dur_rows:
    fail.append("G12 CONTROL: section 2.4's four-wording duration table did not parse, so half of "
                "this gate's population was never read")
_spans = []                                          # (where, the published string)
for _r in _dur_rows:
    _c = cells(_r)
    if len(_c) >= 3:
        _sp, _ = published_span(_c[2])
        if _sp is None:
            fail.append(f"G12 CONTROL: section 2.4's duration row {_c[0]!r} publishes no emphasised "
                        f"string in its Verbatim column, so the wording it fixes cannot be read")
        else:
            _spans.append((f"section 2.4 row {_c[0]}", _sp))
for _r in state_rows:
    _c = cells(_r)
    if len(_c) >= 3 and re.match(r"^`[a-z_]+`$", _c[0]):
        _sp, _ = published_span(_c[2])
        if _sp is not None:
            _spans.append((f"section 7.1 `{_c[0].strip('`')}` Label line", _sp))
if not state_rows:
    fail.append("G12 CONTROL: section 7.1's per-state table did not parse, so the Label line cells "
                "this class was opened over were never read")

_g12_tokens = 0
for _where, _sp in _spans:
    for _tok in DUR_RE.findall(_sp):
        _secs = parse_duration(_tok)
        if _secs is None:
            continue
        _g12_tokens += 1
        _canon = render_duration(_secs)
        if _tok != _canon:
            fail.append(
                f"G12: {_where} renders the duration {_tok!r}, and section 2.4's format returns "
                f"{_canon!r} for the same {_secs} seconds. A worked example that contradicts the "
                f"rule governing it is read AS the rule, because it is the concrete half — and "
                f"before card#9209 there was no rule here for one to contradict, which is how "
                f"three mutually inconsistent exemplars stood. EITHER END may be the one that "
                f"moved: read section 2.4's clauses and this cell before editing either")
if _g12_tokens == 0:
    fail.append("G12 CONTROL: no duration token was found in ANY published rendered span, so leg A "
                "is clean over an empty population — which is what a silently-narrowed recognizer "
                "looks like, and this document renders at least four")

# ---- G12 leg B: section 7.1's dark rows re-derived ARITHMETICALLY from their own worked moment --
# The `stale` and `offline` cells each state a *since* timestamp INSIDE the rendered span and the
# corrected clock they were read at in the prose AFTER it.  The age is then not an opinion: it is
# the subtraction, formatted.  A cell that keeps its age while its timestamps move -- or the
# reverse -- stops describing one moment, which is what the `offline` row's own prose ("at the same
# 14:29 so the two rows describe one moment rather than two") claims and nothing checked.
HHMM = re.compile(r"(?<![\d:])([0-2]?\d):([0-5]\d)(?![\d:])")
_g12_arith = 0
for _r in state_rows:
    _c = cells(_r)
    if len(_c) < 3 or not re.match(r"^`[a-z_]+`$", _c[0]):
        continue
    _sp, _span_end = published_span(_c[2])
    if _sp is None:
        continue
    _toks = [t for t in DUR_RE.findall(_sp) if parse_duration(t) is not None]
    _in_span = HHMM.findall(_sp)
    if not _toks or not _in_span:
        continue                                     # not a worked since/age pair
    _member = _c[0].strip("`")
    _prose = _c[2][_span_end:]
    _read_at = HHMM.findall(strip_code(_prose))
    if len(_in_span) != 1 or len(_read_at) != 1:
        fail.append(f"G12 CONTROL: section 7.1's `{_member}` cell carries {len(_in_span)} clock "
                    f"times in its rendered span and {len(_read_at)} in the prose after it. This "
                    f"leg needs exactly one of each — the instant the age is measured FROM, and "
                    f"the corrected clock it is read AT — and cannot tell which is which "
                    f"otherwise")
        continue
    _since = int(_in_span[0][0]) * 3600 + int(_in_span[0][1]) * 60
    _now = int(_read_at[0][0]) * 3600 + int(_read_at[0][1]) * 60
    _want = render_duration((_now - _since) % 86400)
    _g12_arith += 1
    if _toks[0] != _want:
        fail.append(
            f"G12: section 7.1's `{_member}` row is worked at {_read_at[0][0]}:{_read_at[0][1]} over "
            f"a seat dark since {_in_span[0][0]}:{_in_span[0][1]}, which section 2.4's format renders "
            f"{_want!r} — and the cell reads {_toks[0]!r}. The row no longer describes one moment")
if _g12_arith == 0:
    fail.append("G12 CONTROL: no section 7.1 cell was found stating both a *since* timestamp and an "
                "age, so leg B measured nothing. The `stale` and `offline` rows are the two this "
                "leg exists for")

# ---- G13. a Never cell that forbids the empty desk is SCOPED to the client's confirmation ----
#
# Section 2.3's row 5 (card#7341 step 3, the operator's 2026-09-14 ruling) makes a HELD seat the
# client cannot currently confirm render section 7.1's empty chair.  Two Never cells forbade exactly
# that in ABSOLUTE terms -- `idle`'s ("never the empty desk") and `disabled`'s ("a seat that is off
# and a seat that is gone must not look alike") -- so the document would state the rule and its own
# prohibition at once, and no gate read a Never cell at all.
#
# ⛔ THE THIRD CELL IS THE ONE A NARROWER CHECK MISSES.  Appendix A's U5 RESTATES the off-versus-gone
# obligation and names section 7.1 as where it is discharged.  A check reading only 7.1's table
# returns CLEAN over a document that scopes the rule in 7.1 and publishes it absolutely at the row
# citing 7.1 as its proof -- which is what happened (card#7341, pass-6 MAJOR 4), and is why this
# check reads the DISCHARGE cell too.  It never reads U5's Obligation cell: that carries D1
# § 6.14's own sentence, which this document does not get to scope.
#
# ⚠ WHAT IT CANNOT DO: judge whether a scoping clause is the RIGHT one, or that it qualifies the
# prohibition rather than sitting elsewhere in the same cell.  It holds a cell that forbids the empty
# desk to CARRYING the qualification in the words all three cells use for it; the argument for the
# scope is section 7.3's and a reviewer's.
#
# ⛔ THE PHRASE, NOT THE WORD `confirm`.  Each of these cells goes on to explain what happens once a
# read has failed "enough to leave it unconfirmed" — so a check greping the cell for `confirm` stays
# green with the QUALIFICATION deleted and the explanation left behind, which is a gate that cannot
# fail on the one edit it exists to catch.  The selftest plants exactly that edit.
G13_SCOPED = re.compile(r"client can (?:currently )?confirm the seat", re.I)
g13_rows = table_rows(raw, r"^\|\s*`render_state`\s*\|\s*Desk\s*\|\s*Label line\s*\|\s*Animation\s*\|\s*Never\s*\|")
g13_seen = {}
if g13_rows is None:
    fail.append("G13: section 7.1's render_state table header was not found — this check could not "
                "run at all, which is a false clean and not a skip")
else:
    if len(g13_rows) != len(render_m):
        fail.append(f"G13: section 7.1's table has {len(g13_rows)} data rows against "
                    f"{len(render_m)} `render_state` members — the row walk itself is broken, so "
                    f"every verdict below would be about a table this gate cannot read")
    for state, forbids, what in (("idle", "empty", "the empty desk"),
                                 ("disabled", "offline", "rendering as `offline`")):
        row = next((r for r in g13_rows if r.startswith(f"| `{state}`")), None)
        if row is None:
            fail.append(f"G13: no `{state}` row in section 7.1's table — the cell this gate reads "
                        f"has moved or been deleted")
            continue
        c = cells(row)
        if len(c) != 5:
            fail.append(f"G13: section 7.1's `{state}` row has {len(c)} cells, not five")
            continue
        never = c[4]
        if forbids not in never.lower():
            fail.append(f"G13: `{state}`'s Never cell no longer forbids {what} at all — the anchor "
                        f"this gate reads has moved, so its silence would mean nothing")
            continue
        g13_seen[state] = bool(G13_SCOPED.search(never))
        if not g13_seen[state]:
            fail.append(f"G13: section 7.1's `{state}` Never cell forbids {what} unconditionally and "
                        f"names no scoping to the client's own confirmation — section 2.3 row 5 "
                        f"makes that a live exception: a seat the client cannot confirm renders the "
                        f"empty chair, which is the very render this cell forbids")

g13_u_rows = table_rows(raw, r"^\|\s*#\s*\|\s*D1 source\s*\|\s*Obligation\s*\|\s*Discharged in\s*\|")
g13_u5 = None
if g13_u_rows is None:
    fail.append("G13: Appendix A's table of obligations D1 addresses to the render layer was not "
                "found — this leg could not run at all")
else:
    hits = [r for r in g13_u_rows if "must not look alike" in r and "`enabled: false`" in r]
    if len(hits) != 1:
        fail.append(f"G13: {len(hits)} rows of Appendix A restate D1 § 6.14's off-versus-gone "
                    f"sentence, not the one this gate reads")
    else:
        c = cells(hits[0])
        if len(c) != 4:
            fail.append(f"G13: Appendix A's U5 row has {len(c)} cells, not four")
        elif "7.1" not in c[3]:
            fail.append("G13: Appendix A's U5 no longer names section 7.1 as where the obligation is "
                        "discharged — the contradiction this leg reads is between that cell and "
                        "7.1's own, so the anchor has moved")
        else:
            g13_u5 = bool(G13_SCOPED.search(c[3]))
            if not g13_u5:
                fail.append("G13: Appendix A's U5 states the off-versus-gone obligation as discharged "
                            "in section 7.1 and names no scoping to the client's own confirmation — "
                            "but 7.1's `disabled` cell holds that distinction only while the client "
                            "can confirm the seat, so the document publishes the obligation as "
                            "absolute at the very row that cites the scoped section as its proof")


# ------------------------------------------------------------------ report ----
print(f"anchors: {len(doc_anchors)}; links checked: {n_links}; severed tables: {n_table_breaks}")
print(f"D2 populations re-derived (none written into this checker): "
      f"{len(d2_fields)} seat fields, {len(d2_fleet)} fleet fields, {len(d2_msgs)} message types, "
      f"{len(d2_coord)} coordination fields, "
      f"{len(render_m)} render_state / {len(link_m)} link / {len(act_m)} activity / "
      f"{len(ur_m)} unknown_reason members, {len(badge_m)} badges")
print(f"G1  animations: {len(anim_ids)} rows, {len(mentioned)} referred to elsewhere, "
      f"{len(anim_ids ^ mentioned)} in symmetric difference; drivers checked against D2: "
      f"{sum(len(v) for v in anim_drivers.values())}, unresolved {len(g1_bad)}")
print(f"G2  source tokens checked against D2: {g2_checked} ({len(g2_seen)} distinct); "
      f"residue — D2 seat fields this document renders nowhere: {len(g2_residue)}")
for f in g2_residue:
    print(f"    G2 residue — declared by D2 § 8.2.1, rendered by no row here · {f}")
print(f"G3  cap arithmetic re-added from {len(g3)} parsed figures: "
      f"{g3.get('worst')} + k×{g3.get('elem')} against {g3.get('bound')} B")
print(f"G4  section 12 rows: {g4_rows}; numbers traced to a definition site: {g4_nums}; "
      f"PROVEN discriminating by perturbation: {g4_disc}; residue: {len(g4_residue)}")
for r in g4_residue:
    print(f"    G4 residue — a wrong value this gate would NOT notice · {r}")
print(f"G5  acceptance tests: {len(at_ids)}; fixtures declared {len(fx_declared)}, used "
      f"{len(fx_used)}, symmetric difference {len(fx_declared ^ fx_used)}; build order: "
      f"{len(artifact_step)} artifacts re-derived from Appendix B's Artifact cells, "
      f"{sum(len(v) for v in step_of.values())} gate mentions over {g5_halves} declared test halves, "
      f"every half checked against EVERY artifact it declares and at EVERY step that gates it; "
      f"landed steps, each marked in the one form at the head of its Artifact cell: {g5_landed}")
print(f"    G5 residue — an artifact name a test's body EMPHASISES and its `Reads:` clause does not "
      f"declare: {len(g5_unread)}. Printed in full, never capped: naming an artifact is not reading "
      f"one, so these are not failures — but the gap between what a body names and what it declares "
      f"is where an undeclared read hides, and a count would hide it again")
for _n, _a in g5_unread:
    print(f"    G5 residue — named but not declared as read · {_n}: `{_a}`")
print(f"    G5 harness: {len(g5_harness_bullets)} Build bullets of tests the harness drives, each "
      f"required to declare it whatever else it names; {len(g5_instrument_bullets)} of tests naming "
      f"no fixture and not the harness, run by an instrument ({g5_other_instruments}) whose own "
      f"Appendix B row gates the test: {g5_instrument_bullets}. Classified from the fixture table and "
      f"the harness's own name first, Appendix B's gates second — no bullet is listed in this tool. "
      f"NOT MECHANIZED: whether a `Reads:` clause is true — a deliberately false declaration is a "
      f"review question")
print(f"    G5 ordinal REDs: {g5_ord_total} across the acceptance tests, each sequence checked "
      f"CONTIGUOUS from Second. Which of them are bound to a suite is printed rather than counted — "
      f"a test whose REDs no fixture file claims has had its ENUMERATION checked and its EXECUTION "
      f"not, and that is a different thing to be told than a number")
for _b in g5_bound:
    print(f"    G5 ordinal REDs · {_b}")
print(f"G6  Appendix A: {n_t} D2 rows + {n_u} D1 rows; render-directed markers found in "
      f"{len(marked)} D2 sections ({sorted(marked)}) and {len(marked_d1)} D1 sections "
      f"({sorted(marked_d1)}); uncovered {len(uncovered)} D2 / {len(uncovered_d1)} D1; "
      f"rows resting on a marker section: {n_t - semantic}, SEMANTIC remainder "
      f"{semantic} D2 + {len(semantic_rows_d1)} D1 — printed row by row below, because a count is "
      f"not a verification of the half no recognizer reaches")
for r in semantic_rows:
    print(f"    G6 semantic remainder — a D2 obligation found by READING, not by the recognizer · {r}")
for r in semantic_rows_d1:
    print(f"    G6 semantic remainder — a D1 obligation found by READING, not by the recognizer · {r}")
print(f"G7  render closure, both directions: {len(state_rendered)}/{len(render_m)} render_state, "
      f"{len(ur_rendered)}/{len(ur_m)} unknown_reason, {len(badge_rendered)}/{len(badge_m)} badges, "
      f"{len(link_rendered)}/{len(link_m)} link_state, {len(act_rendered)}/{len(act_m)} "
      f"activity_state, {len(aet_rendered)}/{len(aet_m)} api_error_type (the last from D1 § 6.4)")
print(f"G8d the read paths: section 10.3 names {len(d3_paths)} `GET /api/...` path(s) for the building, "
      f"held to set-equality with D2 § 8.7's {len(d2_87_paths)} `GET` rows and to membership of D2's "
      f"{len(d2_paths)} — the INVERSE of the rule this leg held under the 2026-09-09 ruling, which "
      f"required the document to say there was none")
print(f"G8  desk-slot keys re-hashed: {len(parsed)} at S={S}, plus section 3.3's collision pair; "
      f"the map artifact: {g8_branch}. The two branches are different claims and the output says "
      f"which one ran — COUNTED means S was held against the shipped default's `desks` layer; ABSENT means it "
      f"was held against nothing but this document's own declaration that there is no file, "
      f"which is the strongest true claim available and is NOT evidence about the number. The "
      f"tree sweep for a map skips {sorted(SWEEP_SKIP)}.")
print(f"    G8 the desk sprite section 12's viewport row waited on: {g8c_branch}. MEASURED means "
      f"the size was read out of the PNG's own IHDR header and held against section 10.3's "
      f"sentence, both the dimensions and the path re-derived from that sentence.")
print(f"    G8 the furniture box at the cap (Appendix B row 14, slice B): {g8e_box}. MEASURED means the box "
      f"was read out of its one declaration line — the shape `App\\Floor\\FurnitureBox` admits — and held "
      f"against section 10.3's sentence, the path re-derived from that sentence.")
print(f"    G8 the shipped default's grid: {g8e_grid}. MEASURED means `width × tilewidth` by `height × "
      f"tileheight` was read out of the map and held against section 10.3's sentence, and every `desks` "
      f"object was held at least the box above.")
print(f"    G8 section 12's viewport arithmetic: {g8f}. MEASURED means the rows, the boxes per row, the desk "
      f"across, the grid width and the fit zoom the viewport cell states were each recomputed from the map, "
      f"the box and the row's own viewport floor and held equal.")
print(f"    G8 the worked floors laid at the furniture box: {g8g}. Each figure section 4.6's two rows and D2 "
      f"§ 8.7 state was re-derived from the box above, the rows' own tile counts and the worked JSON, and held.")
print(f"G11 the composed `api_error_type` line: {len(AET_PAIRS)} member/phrase pairs re-derived from "
      f"section 7.6, section 7.1's worked instance held against them, section 5.1's verbatim "
      f"illustration held against the MEMBERS; both predicates fed their own defect on this run and "
      f"rejected it")
# Reported per BRANCH, because the two ways this leg can fail to run are different findings and a
# single "NOT MEASURED" would report the wrong one -- and because claiming the predicate rejected its
# own defect on a run where the predicate never executed is a false line in a gate's own output.
if _act_rows_seen != 5:
    print(f"G11 the `activity_state` currency label's PLACEMENT: NOT MEASURED — {_act_rows_seen} "
          f"section 7.6 rows parsed, not five, so the RULE was never read and no instance was judged")
elif len(_act_placements) != 1:
    print(f"G11 the `activity_state` currency label's PLACEMENT: NOT MEASURED — section 7.6's five "
          f"rows state {sorted(_act_placements)}, so the rule disagrees with ITSELF and no instance "
          f"can be judged against it")
else:
    print(f"G11 the `activity_state` currency label's PLACEMENT: {_act_rows_seen} section 7.6 rows "
          f"read and agreeing on {next(iter(_act_placements))!r}; worked instances found elsewhere "
          f"in the document by structure and held against it: {_instances}; the predicate was fed "
          f"its own defect on this run and rejected it")
print(f"G12 the duration format: {len(_clause_nos)} clauses re-implemented from section 2.4 and "
      f"{len(_bnd_pairs)} boundary rows reproduced from it; the fixed-point test was ACCEPTED on "
      f"{_cap_pos} of the document's own outputs and fed {_cap_neg} per-clause perturbations of "
      f"them on this run, rejecting each; durations held inside a published rendered span: "
      f"{_g12_tokens}; section 7.1 rows re-derived arithmetically from their own worked moment: "
      f"{_g12_arith}. NOT reached: a duration in PROSE, which is the same residue G9 has")
print(f"G10 null-render closure: {len(d2_nullable)} members D2 § 8.2.1 marks nullable, "
      f"{len(null_rendered)} given a null render by section 5.6, "
      f"{len(d2_nullable ^ null_rendered)} in symmetric difference")
print(f"G9  D2 § 6.5's non-version-bearing members re-derived: {len(ten)}; markdown tables found by "
      f"structure {len(DOC_TABLES)}, of which {len(g9_matched)} are render tables with a column in "
      f"the map ({sum(1 for t in G9_TABLES if t[3])} of them DESK tables, where `dark-only` is the "
      f"marker in force); render rows scanned {g9_rows}, rows sourcing one of the ten {g9_hits}, "
      f"every one marked legally FOR THE MEMBER IT SOURCES")
print(f"    G9 residue — PROSE mentions of the ten: {len(g9_prose)} (including § 2.4's own "
      f"definition site). PRINTED IN FULL, never capped: an earlier revision printed the first 12 of "
      f"19 and the count beside it, so the list looked complete on the pass that hid seven of it")
for ln, f in g9_prose:
    print(f"    G9 residue — prose mention, unchecked by this gate · L{ln}: `{f}`")
print(f"    G9 table rows outside the render map: {len(g9_exempt)}, every one of them accounted for "
      f"and listed below WITH THE GROUND IT STANDS ON — a row here that declared no `{EXEMPT}` and is "
      f"neither the marker's definition row nor a row of section 12's guard-class table is a FAILURE "
      f"above, not an entry in this list. Vocabulary rows found by role: {len(g9_vocab_lines)} "
      f"marker-definition, {len(g9_gatedoc_lines)} guard-class")
for ln, f, ex in g9_exempt:
    print(f"    G9 outside the render map, {ex} · L{ln}: `{f}`")
print(f"G13 the empty-desk Never cells, scoped to the client's own confirmation (section 2.3 row 5): "
      f"section 7.1 rows walked {len(g13_rows or [])}; cells read and scoped "
      f"{sorted(s for s, ok in g13_seen.items() if ok)}; Appendix A's U5 discharge cell scoped: "
      f"{g13_u5}. The U5 leg is why this is not two cells: a gate reading only 7.1's table returned "
      f"CLEAN over the same obligation published absolutely one appendix away")
print("NOT MECHANIZED, and read by a human instead: (a) Appendix A's SEMANTIC half — an obligation "
      "upstream addresses to the render layer in none of the recognizer's phrasings cannot be found "
      "by grep; the rows above are its members, printed rather than counted, and naming them is not "
      "verifying them. (b) whether a `Cited` number matches what D2 says, as opposed to appearing at "
      "its D3 home. (c) G9's prose residue, above. (d) whether any of this renders legibly, which is "
      "a review question and not a checkable one.")

if fail:
    print(f"\nFAILURES ({len(fail)}):")
    for f in fail:
        print("  -", f)
    sys.exit(1)
print("ALL D3 CHECKS PASS")

#!/usr/bin/env python3
"""D3 verification gate: docs/design/FLOOR.md.

TEN guard classes, G1-G10, one per defect class this document can carry that a reader will not
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
                                               AT ids contiguous from 1; the build order gates every
                                               artifact a test reads; the animation-log schema's two
                                               homes; the episode walk's own episode and row counts
  G6  Appendix A counts + D2 `D3`-marker cover  an obligation with no row; a marker section nobody cites
  G7  state and badge render closure            a D2 enum member with no render, or a render for a
                                               member D2 does not declare
  G8  the desk-slot worked example              FNV-1a-32 re-computed for every published key
  G9  D2 section 6.5's delivery contract        a render row sourcing one of the TEN non-version-
                                               bearing members without `fetch-fresh` / `dark-only`;
                                               a section 5 table this gate has no column for; a table
                                               row anywhere else naming one of the ten and declaring
                                               neither a marker nor `named-not-rendered`
  G10 null-render closure, both directions      a member D2 section 8.2.1 marks `Null? yes` with no
                                               stated null render, or a null render for a member D2
                                               does not mark nullable

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
import re
import sys
import pathlib

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
    """Data rows of the first table in `text` whose header line matches."""
    src = text.split("\n")
    for i, line in enumerate(src):
        if re.search(header_re, line):
            out, j = [], i + 2
            while j < len(src) and src[j].startswith("|"):
                out.append(src[j])
                j += 1
            return out
    return None


def all_tables(src_lines):
    """EVERY markdown table in a document, found by STRUCTURE: (start_index, header_line, [rows]).

    Which tables exist is not written anywhere in this file.  It is re-derived on each run, because
    the defect this closes is a STORED population rather than a wrong one: G9's table list used to be
    five header patterns written here, so when § 5.6 was added -- thirty-six null-render rows, seven
    of them sourcing one of D2 § 6.5's ten -- it entered the gate's blind spot and nothing reddened,
    while § 2.4 went on claiming the marker rule held over every § 5 row and that this file reds when
    one does not.  Both halves of that sentence were false, and no check could say so.

    Unlike `table_rows`, this finds EVERY table, not the first whose header matches -- so a second
    table sharing a header shape is read rather than silently skipped."""
    out, i, n = [], 0, len(src_lines)
    while i < n - 1:
        if src_lines[i].startswith("|") and re.match(r"^\|[\s\-:|]+\|\s*$", src_lines[i + 1]):
            j, rows = i + 2, []
            while j < n and src_lines[j].startswith("|"):
                rows.append(src_lines[j])
                j += 1
            out.append((i, src_lines[i], rows))
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

d2_detail = {t for t in re.findall(r"`([a-z_]+)`", sec_823 or "")}
if "detail" not in d2_detail:
    fail.append("CONTROL: D2 § 8.2.3's `detail` member did not parse; the drill-down's source "
                "column would then read as an invented field")

ALLOWED = (d2_fields | d2_msgs | d2_detail
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
                    f"detail member or § 8.3's message table. A rendered fact with no field is a "
                    f"fact the client invented")
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

# --------------------------- G4. section 12 <-> definition site, with perturbation ---
sec12 = section_text("12-every-number-and-where-it-comes-from") or ""
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
step_of, artifact_step, g5_unread, g5_halves = {}, {}, [], 0
if not appB:
    fail.append("G5 CONTROL: Appendix B's build-order table did not parse — every acceptance test's "
                "gate step would be unread and the ordering rule below would be vacuous")
else:
    dd_step, artifact_dupe = None, []    # step_of: AT -> [(step, half-or-None)]
    for r in appB:
        c = cells(r)
        if len(c) < 3 or not c[0].isdigit():
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
                continue
            names = {re.sub(r"[`\s]+", " ", a).strip().lower()
                     for a in re.findall(r"\*\*([^*]+)\*\*", mr.group(1))}
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
            late = max(artifact_step[a] for a in arts) if arts else 0
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
            _ = late
        g5_halves += 1
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
LOBBY_LOG = re.compile(r"(?:the\s+)?lobby(?:'s)?\s+(?:event[-\s]+)?log", re.I)
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
# The walk table is INDENTED under a list item, so `table_rows`'s `startswith("|")` cannot read it --
# which is exactly why nothing had ever read it.  Found by structure, tolerant of the indent.
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
    entered, left = set(), set()
    for r in walk_rows:
        c = cells(r)
        ep_cell = c[2] if len(c) > 2 else ""
        for aid, phase_, ep in re.findall(r"(A\d+)\s+\*\*(entered|left)\*\*\s+\(episode\s+(\d+)\)",
                                          ep_cell):
            (entered if phase_ == "entered" else left).add((aid, int(ep)))
    if not entered:
        fail.append("G5 CONTROL: the episode walk parsed but names no `entered` episode — the pair "
                    "recognizer is broken and both figures below would be re-derived as zero")
    orphan = sorted(left - entered)
    for o in orphan:
        fail.append(f"G5: the episode walk gives {o[0]} episode {o[1]} a `left` row with no `entered` "
                    f"row before it. Section 11's own predicate is that a `left` row's `episode_id` "
                    f"must match an `entered` row that precedes it, and the walk is the fixture that "
                    f"predicate is asserted against")
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
m = re.search(prose(r"the shipped `aimla` map, S = (\d+)"), sec32)
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
# one of the ten, so the only legal states for such a row are: it carries a marker (it is part of the
# marker vocabulary itself -- section 2.4's marker table, section 12's G9 row), or it carries
# `named-not-rendered`, the document's own token for a row that NAMES a member without rendering a
# quantity from it (a fixture's contents, an upstream derivation rule quoted, an obligation restated).
# Neither ⇒ red.  The exempted rows are still printed, because an exemption nobody can see is a
# silence with extra steps.
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
        if c and _norm_key(c[0]) in {m.replace("-", "") for m in MARKED_FRESH} | set(MARKED_FRESH):
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
    if not line.startswith("|"):
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

# ------------------------------------------------------------------ report ----
print(f"anchors: {len(doc_anchors)}; links checked: {n_links}; severed tables: {n_table_breaks}")
print(f"D2 populations re-derived (none written into this checker): "
      f"{len(d2_fields)} seat fields, {len(d2_fleet)} fleet fields, {len(d2_msgs)} message types, "
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
      f"every half checked against EVERY artifact it declares and at EVERY step that gates it")
print(f"    G5 residue — an artifact name a test's body EMPHASISES and its `Reads:` clause does not "
      f"declare: {len(g5_unread)}. Printed in full, never capped: naming an artifact is not reading "
      f"one, so these are not failures — but the gap between what a body names and what it declares "
      f"is where an undeclared read hides, and a count would hide it again")
for _n, _a in g5_unread:
    print(f"    G5 residue — named but not declared as read · {_n}: `{_a}`")
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
print(f"G8  desk-slot keys re-hashed: {len(parsed)} at S={S}, plus section 3.3's collision pair")
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

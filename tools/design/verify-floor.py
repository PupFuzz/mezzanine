#!/usr/bin/env python3
"""D3 verification gate: docs/design/FLOOR.md.

NINE guard classes, G1-G9, one per defect class this document can carry that a reader will not
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
                                               AT ids contiguous from 1
  G6  Appendix A counts + D2 `D3`-marker cover  an obligation with no row; a marker section nobody cites
  G7  state and badge render closure            a D2 enum member with no render, or a render for a
                                               member D2 does not declare
  G8  the desk-slot worked example              FNV-1a-32 re-computed for every published key
  G9  D2 section 6.5's delivery contract        a render row sourcing one of the TEN non-version-
                                               bearing members without `fetch-fresh` / `dark-only`

Two things are NOT mechanizable and say so in the output rather than reporting a clean over a
population they never measured (canon: a clean result over an unnamed population reports where the
searcher stopped):

  * G6's SEMANTIC half.  The mechanized recognizer is NOT the literal `D3` alone -- that reported
    clean while D2 section 4.7 and section 4.8 placed three render obligations on this document, in
    the words "rendered in the drill-down".  It is `D3` plus the render-directed phrasings the two
    upstream documents actually use, over BOTH of them.  An obligation phrased in none of those forms
    is still not grep-derivable, so the rows resting on no marker section are printed ROW BY ROW.
  * G9's PROSE half.  G9 reads the render tables and the panel table, where a source column names a
    field.  A bookkeeping member reintroduced in prose is outside that population, so the count of
    prose mentions is printed as named residue rather than folded into a pass.
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


FIELDISH = re.compile(r"^[a-z_][a-z0-9_]*(\[\])?(\.[a-z_][a-z0-9_]*(\[\])?)*$")

# A whole numeric (or word) token: not glued to a word character, and not a fragment of a longer
# number -- neither the tail of `1,280` nor the head of `8.2.1`.  An earlier revision used
# `(?![\w,.])` on both sides, which rejected every value that happened to end a sentence and so
# reported "the number is not at its definition site" for numbers that were.
WHOLE = r"(?<!\w)(?<!\d,)(?<!\d\.)%s(?!\w)(?![.,]\d)"


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
m = re.search(r"addresses this document in \*\*([a-z-]+)\*\* places", appA)
if not m:
    fail.append("G6: Appendix A no longer states how many places D2 addresses this document, so "
                "the size of its own population is unstated")
elif NUM.get(m.group(1)) != n_t:
    fail.append(f"G6: Appendix A says D2 addresses this document in {m.group(1)} places and its "
                f"table has {n_t} rows. The two are one fact with two homes")
m = re.search(r"addresses it in \*\*([a-z-]+)\*\* more", appA)
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
           r"visible in the drill-down", r"\bmust render\b", r"renders as quiet"]
# A decisions register RESTATES obligations that are stated where they belong; requiring a citation of
# it would file one obligation twice.  Nothing else is exempt -- D2 § 14 IS cited, at item 9.
RESTATING = {"D2": {"13"}, "D1": {"15"}}


def marked_sections(src_lines, heads, which):
    out = set()
    for i, line in enumerate(src_lines):
        if not any(re.search(p, line) for p in MARKERS):
            continue
        s = numbered_section_of(heads, i)
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
cands = re.findall(r'"badges": \[(.*?)\]', d2_raw, re.S)
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
m_cnt = re.search(r"D1 § 6\.4's (\d+) members", sec_821 or "")
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
for name, src, declared, rendered in (("render_state", "D2", render_m, state_rendered),
                                      ("unknown_reason", "D2", ur_m, ur_rendered),
                                      ("badge", "D2", badge_m, badge_rendered),
                                      ("link_state", "D2", link_m, link_rendered),
                                      ("activity_state", "D2", act_m, act_rendered),
                                      ("api_error_type", "D1 § 6.4", aet_m, aet_rendered)):
    if not declared or not rendered:
        continue
    for x in sorted(declared - rendered):
        fail.append(f"G7: `{x}` is a `{name}` member {src} can produce and this document gives it no "
                    f"render — a member with no render is a condition the fleet reports and nobody "
                    f"sees, and section 5.4's unrecognised-member rule would demote every seat "
                    f"carrying it")
    for x in sorted(rendered - declared):
        fail.append(f"G7: `{x}` is rendered here as a `{name}` member and {src} declares no such "
                    f"member — a render branch no input can reach")

# ------------------------------------------ G8. the desk-slot worked example ----
def fnv1a32(s):
    h = 2166136261
    for b in s.encode():
        h = ((h ^ b) * 16777619) & 0xFFFFFFFF
    return h


sec32 = section_text("32-the-desk-slot-function") or ""
m = re.search(r"offset basis (\d+), prime (\d+)", sec32)
if not m or (int(m.group(1)), int(m.group(2))) != (2166136261, 16777619):
    fail.append("G8 CONTROL: section 3.2's FNV-1a constants did not parse or do not match the "
                "function this check implements — the worked example would be checked against a "
                "different hash than the document specifies")
m = re.search(r"the shipped `aimla` map, S = (\d+)", sec32)
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
m = re.search(r"provisioning `([^`]+)` \(h = (\d+),\s*h mod (\d+) = \*\*(\d+)\*\*\)", sec33)
if not m:
    fail.append("G8 CONTROL: section 3.3's collision example did not parse — the one worked case "
                "of the displacement rule would be unchecked")
else:
    key = "aimla/" + m.group(1)
    h, mod_s, mod_v = fnv1a32(key), int(m.group(3)), int(m.group(4))
    if h != int(m.group(2)) or h % mod_s != mod_v:
        fail.append(f"G8: section 3.3's collision example states h(`{key}`) = {m.group(2)} mod "
                    f"{mod_s} = {mod_v}; re-computed it is {h} mod {mod_s} = {h % mod_s}")
    m2 = re.search(r"collides with `([^`]+)` \(h = (\d+), slot (\d+)\)", sec33)
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
# (header, detection column, label).  `None` means detect over the whole row: § 4.3's panel table
# names the members by their LEAF names in its contents cell, not as dotted paths in a source column.
G9_TABLES = [
    (r"^\| Rendered element \| D2 field \| Example \| When null / absent \|", 1, "5.1"),
    (r"^\| Rendered element \| Source \| Example \| Rule \|", 1, "5.2"),
    (r"^\| Rendered element \| Source \| Rule \|", 1, "5.3"),
    (ANIM_HEADER, ANIM_DRIVER_COL, "6.2"),
    (r"^\| Panel section \| Contents \| Source \|", None, "4.3"),
]
g9_rows = g9_hits = 0
for header, col, where in G9_TABLES:
    trows = table_rows(raw, header)
    if not trows:
        fail.append(f"G9 CONTROL: the table of section {where} did not parse — every bookkeeping "
                    f"member it renders would go unmarked and unchecked")
        continue
    for r in trows:
        c = cells(r)
        g9_rows += 1
        scope = r if col is None else (c[col] if len(c) > col else "")
        hits = {t for t in re.findall(r"`([a-z_.]+)`", scope) if t in ten}
        # A member named INSIDE a compound span -- `(seq_epoch, last_seq)` -- is still a member this
        # row renders, and matching only whole spans let exactly that one through unmarked.
        for span in re.findall(r"`([^`]+)`", scope):
            for tok in re.split(r"[^a-z_.]+", span):
                if tok in ten:
                    hits.add(tok)
                elif tok in leaf_of:
                    hits.add(leaf_of[tok])
        if not hits:
            continue
        g9_hits += 1
        if any(mk in r for mk in MARKED_FRESH):
            continue
        fail.append(
            f"G9: section {where} renders from {sorted(hits)}, which D2 § 6.5 excludes from the "
            f"version-bearing set, and the row carries neither `fetch-fresh` nor `dark-only`. No "
            f"delta ever carries that member for its own sake, so a client's copy freezes at the "
            f"last full object it received — an age ticked from it reads *no data for N* on a seat "
            f"that is reporting perfectly")
if ten and not g9_hits:
    fail.append("G9 CONTROL: no render row names one of D2 § 6.5's ten — the detector is broken, and "
                "a delivery-contract check that finds nothing to check reports clean over the class "
                "it exists for")
if not re.search(r"FLEET-STATE\.md#65-the-fold", raw):
    fail.append("G9: this document renders values D2 § 6.5 excludes from the feed and cites § 6.5 "
                "nowhere — the section that decides whether a rendered fact is deliverable is not a "
                "section a render map may leave uncited")
# RESIDUE, printed rather than folded into a pass: G9 reads table rows whose columns name a field.
# A bookkeeping member reintroduced in PROSE is outside that population.
table_lines = set()
for header, col, where in G9_TABLES:
    for r in table_rows(raw, header) or []:
        table_lines.add(r)
g9_prose = []
for i, line in enumerate(lines, 1):
    if line in table_lines or line.startswith("|"):
        continue
    hit = sorted({t for t in re.findall(r"`([a-z_.]+)`", line) if t in ten})
    if hit:
        g9_prose.append((i, hit[0]))

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
      f"{len(fx_used)}, symmetric difference {len(fx_declared ^ fx_used)}")
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
print(f"G9  D2 § 6.5's non-version-bearing members re-derived: {len(ten)}; render rows scanned "
      f"{g9_rows}, rows sourcing one of the ten {g9_hits}, all marked `fetch-fresh` or `dark-only`")
print(f"    G9 residue — prose mentions of the ten outside the render tables this check reads: "
      f"{len(g9_prose)} (including § 2.4's own definition site). This check holds the TABLES; a "
      f"held-and-ticked value reintroduced in a sentence is read by a human")
for ln, f in g9_prose[:12]:
    print(f"    G9 residue — prose mention, unchecked by this gate · L{ln}: `{f}`")
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

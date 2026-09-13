#!/usr/bin/env python3
"""Verification gate for the D1 design doc: links, anchors, JSON, bans, finding ids."""
import json, re, sys, pathlib

ROOT = pathlib.Path(__file__).parent.parent.parent
DOC = ROOT / "docs/design/EVENT-SCHEMA.md"
fail = []

def anchors_of(path):
    """GitHub-flavoured heading anchors for a markdown file."""
    out = set()
    seen = {}
    for line in path.read_text().splitlines():
        m = re.match(r"^(#{1,6})\s+(.*?)\s*$", line)
        if not m:
            continue
        text = m.group(2)
        text = re.sub(r"`([^`]*)`", r"\1", text)          # code spans
        text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)  # links
        text = re.sub(r"[*~]", "", text)                   # emphasis (NOT _: GitHub keeps it)
        a = text.lower()
        a = re.sub(r"[^\w\- ]", "", a)                     # drop punctuation
        a = a.replace(" ", "-")
        if a in seen:
            seen[a] += 1
            a = f"{a}-{seen[a]}"
        else:
            seen[a] = 0
        out.add(a)
    return out

def strip_code(s):
    """Blank out inline code spans and fenced blocks so regex literals are not read as links."""
    s = re.sub(r"```.*?```", lambda m: "\n" * m.group(0).count("\n"), s, flags=re.S)
    return re.sub(r"`[^`\n]*`", lambda m: " " * len(m.group(0)), s)

doc_anchors = anchors_of(DOC)
raw = DOC.read_text()
text = raw

# ---- 1. links -----------------------------------------------------------------
for m in re.finditer(r"\]\(([^)\s]+)\)", strip_code(text)):
    target = m.group(1)
    line = text[:m.start()].count("\n") + 1
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

# links inside VERSIONING.md too (we edited it)
V = ROOT / "docs/VERSIONING.md"
vt = V.read_text()
v_anchors = anchors_of(V)
for m in re.finditer(r"\]\(([^)\s]+)\)", strip_code(vt)):
    target = m.group(1)
    line = vt[:m.start()].count("\n") + 1
    if target.startswith("#"):
        if target[1:] not in v_anchors:
            fail.append(f"VERSIONING L{line}: dead anchor {target}")
    elif not target.startswith("http"):
        path, _, frag = target.partition("#")
        fp = (V.parent / path).resolve()
        if not fp.exists():
            fail.append(f"VERSIONING L{line}: missing file {target}")
        elif frag and frag not in anchors_of(fp):
            fail.append(f"VERSIONING L{line}: dead anchor in {path}: #{frag}")

# ---- 2. json fences -----------------------------------------------------------
n_json = 0
for m in re.finditer(r"```json\n(.*?)```", text, re.S):
    body = m.group(1)
    line = text[:m.start()].count("\n") + 1
    if "…" in body or "..." in body:
        continue
    try:
        json.loads(body)
        n_json += 1
    except Exception as e:
        fail.append(f"L{line}: json parse error: {e}")

# ---- 3. banned adjectives without a number ------------------------------------
BAN = r"\b(short|frequent|appropriate|reasonable|a while|soon)\b"
for i, line in enumerate(text.splitlines(), 1):
    for m in re.finditer(BAN, line, re.I):
        if not re.search(r"\d", line):
            fail.append(f"L{i}: banned adjective {m.group(0)!r} with no number: {line.strip()[:100]}")

# ---- 4. finding ids must appear nowhere ---------------------------------------
for pat in [r"\bB-[1-6]\b", r"\bM-(?:[1-9]|1[0-2])\b", r"\bm-(?:[1-9]|10)\b", r"\bh-[1-4]\b"]:
    for m in re.finditer(pat, text):
        line = text[:m.start()].count("\n") + 1
        fail.append(f"L{line}: review finding id leaked: {m.group(0)}")

# ---- 5. every enum field is classified ----------------------------------------
# The population is RE-DERIVED from the field tables on every run, never stored.  An
# earlier draft carried two hand-written lists, each asserting it was "the complete list"
# of its side of the same population; between them they omitted five of the wire's enum
# fields, which therefore inherited neither VERSIONING rule 4 nor rule 7.  A partition
# asserted by prose decays; this is what stops it.
lines = text.split("\n")
cls = re.search(r"\| Wire enum field \| Minted by \| Unknown member \| Value set owned by \|\n"
                r"\|[-|]+\|\n(.*?)\n\n", text, re.S)
if not cls:
    fail.append("§ 6.0's enum classification table not found — the enum population cannot be "
                "checked, so this gate would report clean over an unclassified field")
    classified = set()
else:
    classified = {re.search(r"`([^`]*)`", r).group(1)
                  for r in cls.group(1).split("\n")
                  if r.startswith("|") and re.search(r"`([^`]*)`", r)}

def kinds_by_line(lines):
    """The event kind each line sits under. § 6's headings are the only declaration of it,
    and both the enum check and the counter-name check below key on it — so it is derived
    once here rather than by two walkers free to drift apart."""
    out, kind = [], None
    for line in lines:
        h = re.match(r"^#{2,4}\s+\d+(?:\.\d+)?\s+`([a-z.]+)`", line)
        if h:
            kind = h.group(1)
        elif re.match(r"^#{2,4}\s", line):
            kind = None
        out.append(kind)
    return out

line_kind = kinds_by_line(lines)

n_enum, n_unclassified = 0, 0
for i, line in enumerate(lines, 1):
    kind = line_kind[i - 1]
    if not line.startswith("|"):
        continue
    if not re.search(r"\|\s*enum\s*\||\|\s*array\\<enum\\>\s*\|", line):
        continue
    fld = re.search(r"^\|\s*`([a-z_][a-z0-9_]*)`\s*\|", line)
    if not fld:
        continue
    n_enum += 1
    name, bare = (f"{kind}.{fld.group(1)}" if kind else fld.group(1)), fld.group(1)
    if name not in classified and bare not in classified:
        n_unclassified += 1
        fail.append(f"L{i}: enum field {name!r} appears in no row of § 6.0's classification "
                    f"table — it inherits neither the rule-4 nor the rule-7 obligation")

# ---- 6. TODO / TBD ------------------------------------------------------------
for i, line in enumerate(text.splitlines(), 1):
    if re.search(r"\b(TODO|TBD|FIXME|XXX)\b", line):
        fail.append(f"L{i}: placeholder marker: {line.strip()[:100]}")

# ---- 7. counter-name grammar (§ 6.0 rule 4) -----------------------------------
# Rule 4 states the grammar once — `<family>.<wire field>`, the field's FULL dotted name,
# "never a kind spelled with an underscore and never a bare field name".  A round that
# stated the grammar in prose and shipped no check minted a non-conforming counter name in
# the same commit; a grammar nobody checks is a grammar that decays.  Both populations here
# are RE-DERIVED every run: the wire fields from § 6's own kind headings and field tables
# (via kinds_by_line above — the same derivation the enum check uses), and the counter names
# from every literal in the document.  The wire-field set is a deliberate SUPERSET of the
# `data` keys: it takes any backticked first cell under a kind heading, so the check is a
# guard against the shapes rule 4 names — a bare field name, an underscored kind, a
# non-canonical placeholder — and not a proof that a tail names a `data` key specifically.
WIRE_FIELD_FAMILIES = ("enum_value_unknown", "value_clamped", "data_truncated")
GRAMMAR_EXCEPTION = "enum_value_unknown.notification_type"   # the one rule 4 names

wire_fields = set()
for i, line in enumerate(lines):
    if line_kind[i] and line.startswith("|"):
        f = re.search(r"^\|\s*`([a-z_][a-z0-9_]*)`\s*\|", line)
        if f:
            wire_fields.add(f"{line_kind[i]}.{f.group(1)}")

n_counter = 0
_cn = re.compile(r"\b(" + "|".join(WIRE_FIELD_FAMILIES) +
                 r")\.(<[a-z ]+>|\*|[A-Za-z_][A-Za-z0-9_.]*)")
for i, line in enumerate(lines, 1):
    for m in _cn.finditer(line):
        n_counter += 1
        fam, tail = m.group(1), m.group(2).rstrip(".")
        name = f"{fam}.{tail}"
        if name == GRAMMAR_EXCEPTION or tail in ("*", "<wire field>") or tail in wire_fields:
            continue
        why = ("a non-canonical placeholder — rule 4's spelling is `<wire field>`"
               if tail.startswith("<") else
               f"{tail!r} is not a dotted wire field any § 6 table declares — rule 4 forbids "
               f"a bare field name and a kind spelled with an underscore")
        fail.append(f"L{i}: counter name `{name}` violates § 6.0 rule 4's grammar: {why}")

# ---- 8. no blank line severs a table body -------------------------------------
# A blank line between two `|`-rows ends the table in GitHub markdown: the row below it
# becomes a new table's HEADER and every row after it loses its column names.  This has now
# been hand-deleted in two consecutive rounds and re-minted in the same commit as its own
# fix, which is what makes it a check rather than a third manual delete.  Legitimate table
# separations are kept: those are followed by a real header, i.e. a `|---|` delimiter row.
n_table_breaks = 0
for i in range(1, len(lines) - 1):
    if lines[i].strip() or not (lines[i - 1].startswith("|") and lines[i + 1].startswith("|")):
        continue
    n_table_breaks += 1
    nxt = lines[i + 2] if i + 2 < len(lines) else ""
    if re.match(r"^\|[\s\-:|]+\|\s*$", nxt):
        continue
    fail.append(f"L{i + 1}: blank line severs a table body — the row below it is a data row, "
                f"so it renders as a new table's header and the rows after it lose their "
                f"column names. A legitimate table separation is followed by a header row "
                f"plus its `|---|` delimiter")

# ---- 9. capped objects: every one is dispositioned, and the exempt ones' arithmetic ------
# § 6.0 rule 5 obliges a reduction rule of every capped object A SEAT CAN GROW past its cap,
# and exempts the ones carrying one member per row of a declared table — on arithmetic stated
# in § 6.14.  Both halves decay silently if nothing re-derives them: a fourth capped object
# added with neither a rule nor an exemption inherits an obligation nobody notices, and a row
# added to either member table falsifies a byte count that goes on being quoted.  A written
# number becomes an authority the passes that falsify it never revisit, so the numbers below
# are RE-DERIVED from the member tables and the document's own figures are checked against
# them — never the other way round.
# every capped object inside `data` is dispositioned: a reduction rule, or a named exemption.
# The caps themselves are READ FROM the field tables — a cap this tool carried as a literal
# would be one more number free to disagree with the document it is checking.
# The type is matched ANYWHERE in the row rather than in cell 2: § 6.11's field table carries an
# extra source-key column, so a position-bound predicate is blind to a capped object added there —
# to exactly the row this check exists to catch.  Check 6 above was hardened against that same
# table for the same reason; one population, one predicate shape.
capped, caps = {}, {}
for i, line in enumerate(lines, 1):
    if not (line_kind[i - 1] and line.startswith("|")):
        continue
    f = re.match(r"^\|\s*`([a-z_][a-z0-9_]*)`\s*\|", line)
    c = re.search(r"≤\s*([\d.]+)\s*(B|KiB)", line)
    if not f or not c or not re.search(r"\|\s*object\s*\|", line):
        continue
    capped[f"{line_kind[i - 1]}.{f.group(1)}"] = i
    caps[f.group(1)] = int(float(c.group(1)) * (1024 if c.group(2) == "KiB" else 1))
    if not re.search(r"reduction rule", line):
        fail.append(f"L{i}: capped object `{f.group(1)}` states neither a reduction rule nor an "
                    f"exemption from § 6.0 rule 5 — rule 5's claim is only true of objects that "
                    f"say which one they are")

WORD = {"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7}

def rows_of(header_re, start_re=None):
    """Data rows of the first table whose header matches, optionally after a heading."""
    i0 = 0
    if start_re:
        for i, line in enumerate(lines):
            if re.match(start_re, line):
                i0 = i
                break
        else:
            return None
    for i in range(i0, len(lines)):
        if re.match(header_re, lines[i]):
            out, j = [], i + 2                       # skip header + |---| delimiter
            while j < len(lines) and lines[j].startswith("|"):
                out.append(lines[j])
                j += 1
            return out
    return None

def names_of(rows):
    return [m.group(1) for m in (re.match(r"^\|\s*`([a-z_][a-z0-9_]*)`\s*\|", r) for r in rows) if m]

pred_rows = rows_of(r"^\| Predicate \| Branches \|", r"^### 9\.4 ")
self_rows = rows_of(r"^\| Member \| Asserts \| Stated at \|")
n_pred = n_self = worst_pred = worst_self = 0
if pred_rows is None or self_rows is None:
    fail.append("§ 9.4's predicate table or § 6.14's `selftest` member table not found — the two "
                "exempt objects' bounds cannot be re-derived, so this gate would report clean "
                "over an unchecked exemption")
else:
    preds, checks = names_of(pred_rows), names_of(self_rows)
    n_pred, n_self = len(preds), len(checks)
    # `"name":{"true":N,"false":M}` = 21 B of keys and punctuation + name + two integers at the
    # JS-safe-integer ceiling § 6.0 admits; `"name":"pass"` = 9 B + name, both values 4 B.
    digits = len(str(2 ** 53 - 1))
    sum_pred, sum_self = sum(map(len, preds)), sum(map(len, checks))
    worst_pred = 2 + n_pred * (21 + 2 * digits) + sum_pred + (n_pred - 1)
    worst_self = 2 + n_self * 9 + sum_self + (n_self - 1)
    for label, worst, formula in (
            ("predicates", worst_pred,
             rf"2 \+ {n_pred}×\(21 \+ {2 * digits}\) \+ {sum_pred} \+ {n_pred - 1}"),
            ("selftest", worst_self,
             rf"2 \+ {n_self}×9 \+ {sum_self} \+ {n_self - 1}")):
        cap = caps.get(label)
        if cap is None:
            fail.append(f"§ 6.14: no cap found on `{label}`'s field-table row — its exemption "
                        f"from § 6.0 rule 5 rests on a cap this gate cannot read")
            continue
        if worst > cap:
            fail.append(f"§ 6.14 `{label}`: worst case is {worst} B against a {cap} B cap — the "
                        f"member table has outgrown the exemption, so the field now owes § 6.0 "
                        f"rule 5 a reduction rule and a `data_truncated` member")
        if len(re.findall(formula, raw)) == 0:
            fail.append(f"§ 6.14 `{label}`: no stated arithmetic matches the member table — "
                        f"expected the terms `{formula}` = {worst} B, re-derived from "
                        f"{'§ 9.4' if label == 'predicates' else 'the member table'}")
        # Every figure this document states for these two fields is checked against the
        # re-derivation, wherever it is restated — § 6.14 derives them, § 6.14's field table and
        # § 14 quote them, and a quoted number is exactly the kind that survives the pass that
        # falsifies it.  A line naming BOTH fields is ambiguous and is skipped rather than
        # guessed at; § 15's register rows are historical and name both by construction.
        other = "selftest" if label == "predicates" else "predicates"
        lbl = re.compile(rf"`(?:reporter\.heartbeat\.)?{label}`")
        oth = re.compile(rf"`(?:reporter\.heartbeat\.)?{other}`")
        for i, line in enumerate(lines, 1):
            if not lbl.search(line) or oth.search(line):
                continue
            for pat, want, what in (
                    (r"worst case \**(\d[\d,]*) B", worst, "worst case"),
                    (r"\**(\d[\d,]*) B\** worst case", worst, "worst case"),
                    (r"\bat \**(\d[\d,]*) B\b", worst, "worst case"),
                    (r"(\d+) B under the cap", cap - worst, "headroom"),
                    (r"(\d+) B spare", cap - worst, "headroom")):
                for m in re.finditer(pat, line):
                    stated = int(m.group(1).replace(",", ""))
                    if stated != want:
                        fail.append(f"L{i}: stated {what} {stated} B for `{label}` disagrees with "
                                    f"{want} B re-derived from its member table")

m_three = re.search(r"Exactly (\w+) objects inside `data` carry a serialized cap", raw)
if not m_three:
    fail.append("§ 6.0 rule 5 no longer states how many capped objects it partitions — the claim "
                "it has no silent members is then unfalsifiable")
elif WORD.get(m_three.group(1)) != len(capped):
    fail.append(f"§ 6.0 rule 5 claims {m_three.group(1)} capped objects inside `data`; § 6's field "
                f"tables declare {len(capped)}: {', '.join(sorted(capped))}")

# ---- 10. the heartbeat example is the basis of § 10.3's residency arithmetic --------------
# § 10.3 sizes a quiet seat's spool from the § 6.14 worked example, and that figure has been
# wrong twice: once from a 500 B assumption, once from a reading of the example that omitted
# its `counters` object — each time producing a residency the coupling argument then quoted.
# The example is right there in the document, so the figure is MEASURED here instead of read
# off: every number in that paragraph is recomputed from the example on every run.
hb = None
for m in re.finditer(r"```json\n(.*?)```", raw, re.S):
    body = m.group(1)
    if "…" in body or "..." in body:
        continue
    try:
        obj = json.loads(body)
    except Exception:
        continue
    if isinstance(obj, dict) and obj.get("kind") == "reporter.heartbeat":
        hb = obj
hb_bytes = 0
if hb is None:
    fail.append("§ 6.14's `reporter.heartbeat` worked example not found or does not parse — "
                "§ 10.3's residency arithmetic has no measured basis")
else:
    ser = lambda o: len(json.dumps(o, separators=(",", ":")).encode())
    hb_bytes = ser(hb)
    per_day = hb_bytes * 1440
    days = 32 * 1024 * 1024 / per_day
    # § 10.3 is a blockquote and the prose wraps, so match against a line-joined copy:
    # a check that silently fails to find its subject reports clean over an unread paragraph.
    flat = re.sub(r"\n>?[ \t]*", " ", raw)
    checks_103 = [
        (r"worked example in \[§ 6\.14\]\([^)]*\) serializes to \*\*([\d,]+) B\*\*",
         hb_bytes, 0, "the example's serialized size"),
        (r"At ([\d,]+) B, 1,440/day", hb_bytes, 0, "the per-heartbeat size"),
        (r"1,440/day is \*\*~([\d.]+) MB/day\*\*", per_day / 1e6, 0.05, "MB/day"),
        (r"fills 32 MiB in \*\*~([\d.]+) days\*\*", days, 0.1, "days to fill the spool"),
        (r"leaves the oldest event ([\d.]+) days past a 10-day dedup window",
         days - 10, 0.1, "the margin over the dedup window"),
        (r"its ([\d,]+) B `counters` object", ser(hb["data"]["counters"]), 0,
         "the counters object omitted by the earlier reading"),
    ]
    for pat, want, tol, what in checks_103:
        m = re.search(pat, flat)
        if not m:
            fail.append(f"§ 10.3: no stated figure matches {what} — expected {want:,.1f} measured "
                        f"from § 6.14's example, and a paragraph the gate cannot read is a "
                        f"paragraph nothing re-measures")
        elif abs(float(m.group(1).replace(",", "")) - want) > tol:
            fail.append(f"§ 10.3: stated {what} {m.group(1)} disagrees with {want:,.2f} measured "
                        f"from § 6.14's worked example ({hb_bytes} B serialized)")

# ---- 11. the coordination roster's resolution sites: one contract, held at every site stating it --
# § 3.1 resolves the coordination roster from an ORDERED list of sites, and the order is a
# cross-repository contract (card#9296): a reporter reading only the home path finds no roster on a
# multi-agent install, whose framework points `$COORD_CONFIG` into the coordination repository, and
# `disagreed` becomes unreachable with the roster readable on the box.  The contract is stated at
# that list, exercised at AT-27 and named as unestablished at § 18.13 row 6.  No site is written
# here: the list is re-derived from § 3.1 on every run and every other surface is held against it.
# What this cannot check is the reporter half -- that a build reads the sites in this order --
# which is AT-27's.
sec31 = re.search(r"^### 3\.1 .*?(?=^### 3\.2 )", raw, re.S | re.M)
m_sites = re.search(r"^\*\*Where the roster is, in resolution order(?:[^\n]*\n)+?\n"
                    r"((?:\d+\. [^\n]*\n(?:   [^\n]*\n)*)+)", sec31.group(0) if sec31 else "", re.M)
sites = []
if m_sites:
    for item in re.split(r"^\d+\. ", m_sites.group(1), flags=re.M):
        tok = re.search(r"`([^`\n]+)`", item)
        if tok:
            sites.append(tok.group(1))
at27 = re.search(r"^### AT-27 .*?(?=^### |^## )", raw, re.S | re.M)
row6 = next((l for l in raw.splitlines() if l.startswith("| **That a protocol agent name identifies")), None)
if not sites or not at27 or row6 is None:
    fail.append(f"check 11 CONTROL: § 3.1's roster resolution order parsed as {sites}; AT-27 "
                f"{'found' if at27 else 'NOT found'}; § 18.13 row 6 {'found' if row6 else 'NOT found'} "
                f"— a contract the check cannot read, or a surface it cannot find, is one nothing holds "
                f"to the other")
else:
    for i, line in enumerate(raw.splitlines(), 1):
        for tok in re.findall(r"`(\$COORD_[A-Za-z_]+|[^`\s]*[/\\]coordination\.config\.json)`", line):
            if tok not in sites:
                fail.append(f"L{i}: `{tok}` names a location for the coordination config that is not a "
                            f"site of § 3.1's resolution order {sites} — a second place a builder can "
                            f"read the roster from, which the contract does not declare")
    for s in sites:
        if f"`{s}`" not in at27.group(0):
            fail.append(f"AT-27 never names `{s}`, a site of § 3.1's roster resolution order — a "
                        f"build that never reads it passes every case, which is how reading one site "
                        f"made `disagreed` unreachable")
    pos = [row6.find(f"`{s}`") for s in sites]
    if -1 in pos or pos != sorted(pos):
        fail.append(f"§ 18.13 row 6 does not name § 3.1's roster resolution sites {sites} in their "
                    f"order — the residual it names is stated against a contract it no longer matches")

    # The DELIVERY half (card#9296 round 3).  The sites are only reached if the variable reaches the
    # flusher, and § 2.3 declares more than one way a flusher starts: the OS-supervised one inherits
    # nothing from the harness, holds the exclusive lock and heartbeats in steady state, and a
    # contract that named only the hook-spawned start left it on the home path.  So every start path
    # § 2.3's first numbered list declares must be named by § 3.1's DECLARED leg and by AT-27.  The
    # paths are re-derived from § 2.3's list labels on every run; none is written here.  What this
    # cannot check is that a unit's or task's launch delivers the value, or that the installer
    # rewrites it when the value changes -- both are installer card#7336's, and § 18.13 row 6
    # names them as not established.
    sec23 = re.search(r"^### 2\.3 .*?(?=^### )", raw, re.S | re.M)
    m_starts = re.search(r"((?:^\d+\. \*\*[^*\n]+\*\*[^\n]*\n(?:   [^\n]*\n)*)+)",
                         sec23.group(0) if sec23 else "", re.M)
    starts = re.findall(r"^\d+\. \*\*([^*\n]+)\*\*", m_starts.group(1), re.M) if m_starts else []
    declared = re.search(r"^- \*\*DECLARED\b.*?(?=^- \*\*)", sec31.group(0), re.S | re.M)
    if not starts or not declared:
        fail.append(f"check 11 CONTROL: § 2.3's flusher start paths parsed as {starts}; § 3.1's "
                    f"DECLARED leg {'found' if declared else 'NOT found'} — the delivery half of the "
                    f"roster contract cannot be held to a start-path list the check cannot read")
    else:
        flat = lambda s: " ".join(s.replace("*", "").split()).lower()
        for label in starts:
            for where, body in (("§ 3.1's DECLARED leg", declared.group(0)), ("AT-27", at27.group(0))):
                if flat(label) not in flat(body):
                    fail.append(f"{where} never names § 2.3's flusher start path \"{label}\" — a start "
                                f"the roster contract does not reach is one whose heartbeat reads the "
                                f"home path, which is how the supervised start was left `unchecked`")

# ---- 12. the protocol agent name's byte bound: one figure, held at every home that states it ------
# § 12.1 step 10 refuses only a `≤ N B` figure a § 6 field row states (card#9283), so § 6.14's
# `protocol_agent_name` row carries one rather than a pointer (card#9296 round 3).  That figure is a
# restatement of the bound § 18.6 gives a protocol agent name on the wire, and D2 restates it twice
# more -- § 6.4's column and § 8.2.1's row -- and the store's migration sizes the column a further
# time (card#9296 round 4).  Every home is read on every run and each must equal § 18.6's: a D2
# column narrower than the ingest's figure is a name the ingest accepts and the fold then cannot
# store, with every gate green.  `opened_by` is the subject § 18.6 bounds a single agent name on,
# named like any subject -- not a population.  The migration home is the LAST migration that sizes
# the column, because that is the store's effective width; a later widening is read, not the original.
def first_byte_bound(row):
    m = re.search(r"≤\s*([\d,]+)\s*B\b", row or "")
    return int(m.group(1).replace(",", "")) if m else None
sec614 = re.search(r"^### 6\.14 .*?(?=^### |^## )", raw, re.S | re.M)
sec186 = re.search(r"^#### 18\.6 .*?(?=^#### |^### |^## )", raw, re.S | re.M)
hb_name_row = next((l for l in (sec614.group(0) if sec614 else "").splitlines()
                    if l.startswith("| `protocol_agent_name` |")), None)
wire_name_row = next((l for l in (sec186.group(0) if sec186 else "").splitlines()
                      if l.startswith("| `opened_by` |")), None)
d2_raw = (ROOT / "docs" / "design" / "FLEET-STATE.md").read_text()
d2_sec64 = re.search(r"^### 6\.4 .*?(?=^### )", d2_raw, re.S | re.M)
d2_sec821 = re.search(r"^#### 8\.2\.1 .*?(?=^#### )", d2_raw, re.S | re.M)
d2_ddl = re.search(r"^\s*protocol_agent_name\s+VARCHAR\((\d+)\)",
                   d2_sec64.group(0) if d2_sec64 else "", re.M)
d2_name_row = next((l for l in (d2_sec821.group(0) if d2_sec821 else "").splitlines()
                    if l.startswith("| `protocol_agent_name` |")), None)
mig_widths = [(m.name, int(w))
              for m in sorted((ROOT / "server" / "database" / "migrations").glob("*.php"))
              for w in re.findall(r"string\(\s*'protocol_agent_name'\s*,\s*(\d+)\s*\)", m.read_text())]
name_bounds = {
    "D1 § 18.6 `opened_by`": first_byte_bound(wire_name_row),
    "D1 § 6.14 `protocol_agent_name`": first_byte_bound(hb_name_row),
    "D2 § 6.4 `protocol_agent_name VARCHAR`": int(d2_ddl.group(1)) if d2_ddl else None,
    "D2 § 8.2.1 `protocol_agent_name`": first_byte_bound(d2_name_row),
    f"migration {mig_widths[-1][0] if mig_widths else '(none sizes the column)'}":
        mig_widths[-1][1] if mig_widths else None,
}
unread = [home for home, n in name_bounds.items() if n is None]
if unread:
    fail.append(f"check 12 CONTROL: no byte bound could be read at {unread} (parsed {name_bounds}) — "
                f"a home the check cannot read, or one stating no figure, is one nothing holds to "
                f"the bound the ingest enforces")
else:
    ref = name_bounds["D1 § 18.6 `opened_by`"]
    for home, n in name_bounds.items():
        if n != ref:
            fail.append(f"{home} states {n} B, and § 18.6 bounds a protocol agent name at {ref} B — "
                        f"the ingest refuses by § 6.14, the store is sized by D2's column and the "
                        f"migration, and a name between two of them is accepted by one and fails at "
                        f"the other")

print(f"json blocks parsed: {n_json}; doc anchors: {len(doc_anchors)}; "
      f"enum fields re-derived: {n_enum}, {n_enum - n_unclassified} classified; "
      f"counter-name mentions checked: {n_counter} against {len(wire_fields)} wire fields; "
      f"blank lines between table rows: {n_table_breaks}; "
      f"capped objects dispositioned: {len(capped)}; "
      f"heartbeat example re-serialized: {hb_bytes} B; "
      f"exempt-object bounds re-derived: predicates {worst_pred} B from {n_pred} members, "
      f"selftest {worst_self} B from {n_self} members; "
      f"roster resolution sites re-derived from § 3.1, in order: {sites}; "
      f"flusher start paths re-derived from § 2.3: {starts if sites and at27 and row6 is not None else 'not read'}; "
      f"protocol agent name bound per home: {name_bounds}")
if fail:
    print(f"\nFAILURES ({len(fail)}):")
    for f in fail:
        print("  -", f)
    sys.exit(1)
print("ALL CHECKS PASS")

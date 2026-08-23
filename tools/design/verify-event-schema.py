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
capped, caps = {}, {}
for i, line in enumerate(lines, 1):
    if not (line_kind[i - 1] and line.startswith("|")):
        continue
    f = re.match(r"^\|\s*`([a-z_][a-z0-9_]*)`\s*\|\s*object\s*\|", line)
    c = re.search(r"≤\s*([\d.]+)\s*(B|KiB)", line)
    if not f or not c:
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

print(f"json blocks parsed: {n_json}; doc anchors: {len(doc_anchors)}; "
      f"enum fields re-derived: {n_enum}, {n_enum - n_unclassified} classified; "
      f"counter-name mentions checked: {n_counter} against {len(wire_fields)} wire fields; "
      f"blank lines between table rows: {n_table_breaks}; "
      f"capped objects dispositioned: {len(capped)}; "
      f"heartbeat example re-serialized: {hb_bytes} B; "
      f"exempt-object bounds re-derived: predicates {worst_pred} B from {n_pred} members, "
      f"selftest {worst_self} B from {n_self} members")
if fail:
    print(f"\nFAILURES ({len(fail)}):")
    for f in fail:
        print("  -", f)
    sys.exit(1)
print("ALL CHECKS PASS")

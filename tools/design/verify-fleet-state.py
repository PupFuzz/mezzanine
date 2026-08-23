#!/usr/bin/env python3
"""D2 verification gate: docs/design/FLEET-STATE.md.

Ten guard classes, one per defect class the round-1 review of this document found by hand.
Every population below is RE-DERIVED on each run -- from this document's own tables, from the
JSON blocks it publishes, or from docs/design/EVENT-SCHEMA.md -- and never from a list stored
here.  A number written into a checker is a number free to disagree with the document it is
checking, and it survives exactly the pass that falsifies it.

  G1  DDL ENUM members vs prose reachability      (R1-10: `server_purge`, a member no path emits)
  G2  field table <-> worked examples, both ways  (R1-22: four container objects with no row)
  G3  byte figures re-derived by serialization    (R1-16: "Measured" figures nothing could measure)
  G4  cross-document enum containment + counts    (R1-19: a comment saying "four" over six members)
  G5  section 12 <-> definition-site agreement    (R1-18, R1-33: a number table that drifted)
  G6  Appendix A counts + D1 marker coverage      (R1-24: an obligation with no row)
  G7  feed message-type closure                   (R1-11: `fleet.health` in no table)
  G8  counter closure, with Stored/Exposed        (R1-15: 14 counters with no home)
  G9  fixture arity                               (R1-21: "nine events" against a ten-row trace)
  G10 retention chain 8 < 10 < 14                 (regression guard on the one number D1 says
                                                   corrupts a timeline silently)
  G11 section 10's trace, counted from its table  (R1-21: "one transition row" against two)

Two classes are NOT fully mechanizable and say so in the output rather than reporting a clean
over a population they never measured (canon: a clean result over an unnamed population reports
where the searcher stopped):

  * G6's SEMANTIC half.  An obligation D1 addresses to "a consumer" without the `D2` marker --
    Appendix A row S29 is one -- cannot be found by grep.  The marker half is checked; the
    semantic half is reported as manual, and FLEET-STATE section 14 item 13 is the request that
    would close it.
  * G5's "Cited" rows.  This gate checks that a number appears at the D2 section that owns it.
    Whether that number is what D1 actually says is a cross-document claim over prose, and it is
    read by a human.

Each check that can be silent about its own subject carries a CONTROL that aborts rather than
reporting clean when its extractor finds nothing (canon #9: a check that cannot fail is a
decoration).
"""
import json, re, sys, pathlib

ROOT = pathlib.Path(__file__).parent.parent.parent
DOC = ROOT / "docs/design/FLEET-STATE.md"
D1 = ROOT / "docs/design/EVENT-SCHEMA.md"

fail, notes = [], []
raw = DOC.read_text()
lines = raw.split("\n")
d1_raw = D1.read_text()

WORD = {"zero": 0, "one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7,
        "eight": 8, "nine": 9, "ten": 10, "eleven": 11, "twelve": 12, "thirteen": 13,
        "fourteen": 14, "eighteen": 18, "twenty-eight": 28, "twenty-nine": 29, "thirty": 30,
        "thirty-three": 33, "thirty-four": 34}


# ---------------------------------------------------------------- helpers ----
def anchors_of(path):
    """GitHub-flavoured heading anchors.  Same algorithm as verify-event-schema.py's."""
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


def strip_code(s):
    s = re.sub(r"```.*?```", lambda m: "\n" * m.group(0).count("\n"), s, flags=re.S)
    return re.sub(r"`[^`\n]*`", lambda m: " " * len(m.group(0)), s)


def heading_index(text):
    """[(level, title, anchor, start_line, end_line)] for every heading, in order."""
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


def section_text(anchor):
    h = BY_ANCHOR.get(anchor)
    return None if h is None else "\n".join(lines[h[3]:h[4]])


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


def cells(row):
    return [c.strip() for c in row.strip().strip("|").split("|")]


def json_blocks():
    out = []
    for m in re.finditer(r"```json\n(.*?)```", raw, re.S):
        line = raw[:m.start()].count("\n") + 1
        try:
            out.append((line, json.loads(m.group(1))))
        except Exception as e:
            fail.append(f"L{line}: json parse error: {e}")
    return out


BLOCKS = json_blocks()


def flatten(obj, prefix=""):
    """Wire paths of a seat object: containers AND leaves; `arr[].field` for arrays."""
    out = set()
    if isinstance(obj, dict):
        for k, v in obj.items():
            p = f"{prefix}.{k}" if prefix else k
            out.add(p)
            if isinstance(v, (dict, list)):
                out |= flatten(v, p)
    elif isinstance(obj, list):
        for el in obj:
            if isinstance(el, (dict, list)):
                out |= flatten(el, prefix + "[]")
    return out


SER = lambda o: len(json.dumps(o, separators=(",", ":")).encode())


def near(stated, want, tol=0.0):
    return abs(stated - want) <= tol


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
if n_links < 100:
    fail.append(f"CONTROL: only {n_links} markdown links found — the link extractor is broken, "
                f"and a link check that reads no links reports clean over everything")

for i, line in enumerate(lines, 1):
    if re.search(r"\b(TODO|TBD|FIXME|XXX)\b", line):
        fail.append(f"L{i}: placeholder marker: {line.strip()[:90]}")

# a blank line between two table rows severs the table (D1's check 8, same defect shape)
for i in range(1, len(lines) - 1):
    if lines[i].strip() or not (lines[i - 1].startswith("|") and lines[i + 1].startswith("|")):
        continue
    nxt = lines[i + 2] if i + 2 < len(lines) else ""
    if not re.match(r"^\|[\s\-:|]+\|\s*$", nxt):
        fail.append(f"L{i + 1}: blank line severs a table body — the row below it renders as a "
                    f"new table's header and every row after it loses its column names")

# ------------------------------------- G1. DDL ENUM member reachability -------
# A member this document MINTS must be produced by some rule this document states; a member it
# INHERITS from a D1 value set is reachable by D1's construction and owes no D2 prose.  The
# partition is re-derived by asking D1, not by a list here -- which is what makes it survive a
# member moving from one side to the other.
sec64 = section_text("64-ddl")
g1_members, g1_minted, g1_lonely = set(), set(), []
if not sec64:
    fail.append("G1 CONTROL: section 6.4 not found — the DDL cannot be read")
else:
    sql = re.search(r"```sql\n(.*?)```", sec64, re.S)
    if not sql:
        fail.append("G1 CONTROL: no ```sql block in section 6.4")
    else:
        decls = re.findall(r"ENUM\(([^)]*)\)", sql.group(1), re.S)
        if len(decls) < 20:
            fail.append(f"G1 CONTROL: only {len(decls)} ENUM declarations parsed out of the DDL — "
                        f"the extractor is broken and this check would pass over everything")
        for d in decls:
            g1_members |= set(re.findall(r"'([^']+)'", d))
        for m in sorted(g1_members):
            w = r"\b" + re.escape(m) + r"\b"
            if re.search(w, d1_raw):
                continue                      # D1 owns the value set
            g1_minted.add(m)
            if len(re.findall(w, raw)) <= 1:
                g1_lonely.append(m)
                fail.append(
                    f"G1: `{m}` is an ENUM member this document mints (it appears nowhere in D1) "
                    f"and occurs exactly once — its own declaration. No rule, no edge table and no "
                    f"acceptance test produces it, so it is a member no path can emit and a render "
                    f"branch a consumer would build and never reach")

# ---------------------- G2. field table <-> worked examples, both directions ---
sec821 = section_text("821-the-seat-state-object")
g2_table, g2_seen = set(), set()
if not sec821:
    fail.append("G2 CONTROL: section 8.2.1 not found")
else:
    rows = table_rows(sec821, r"^\| Field \| Type \| Null\? \| Bounds \| Example \|")
    if not rows:
        fail.append("G2 CONTROL: section 8.2.1's field table not found")
    else:
        for r in rows:
            m = re.match(r"^\|\s*`([A-Za-z_][\w.\[\]]*)`\s*\|", r)
            if m:
                g2_table.add(m.group(1))
        if len(g2_table) < 50:
            fail.append(f"G2 CONTROL: only {len(g2_table)} field names parsed from section 8.2.1")

seat_objs = []


def collect_seats(o):
    if isinstance(o, dict):
        if {"install_id", "seat_id", "state_version"} <= set(o) and not {"t", "patch"} & set(o):
            seat_objs.append(o)          # the delta ENVELOPE also carries those three keys
        for v in o.values():
            collect_seats(v)
    elif isinstance(o, list):
        for v in o:
            collect_seats(v)


for _, b in BLOCKS:
    collect_seats(b)
if not seat_objs:
    fail.append("G2 CONTROL: no seat object found in any JSON block — the set comparison would be "
                "vacuously clean in both directions")
for o in seat_objs:
    g2_seen |= flatten(o)
if g2_table and g2_seen:
    for miss in sorted(g2_table - g2_seen):
        fail.append(f"G2: `{miss}` has a row in section 8.2.1 and appears in no worked example — "
                    f"the field is declared and never shown")
    for miss in sorted(g2_seen - g2_table):
        fail.append(f"G2: `{miss}` appears in a worked example and has NO row in section 8.2.1 — "
                    f"so it has no declared type, nullability or bound, and an implementer cannot "
                    f"tell whether an unpopulated one is null or an object of nulls (which are "
                    f"different patches under section 8.3.1's shallow merge)")

# ----------------------------- G3. byte figures, re-derived by serialization ---
snapshot = next((b for _, b in BLOCKS if isinstance(b, dict) and "installs" in b), None)
deltas = [b for _, b in BLOCKS if isinstance(b, dict) and b.get("t") == "seat.delta"]
typ_delta = next((d for d in deltas if "install_id" not in d.get("patch", {})), None)
worst_delta = next((d for d in deltas if "install_id" in d.get("patch", {})), None)
figs = {}
if snapshot is None or typ_delta is None or worst_delta is None:
    fail.append("G3 CONTROL: the three published blocks (snapshot, typical delta, worst-case "
                "delta) were not all found — every byte figure in this document would then be "
                "unchecked while this gate reported clean")
else:
    typ_seat = snapshot["installs"][0]["seats"][0]
    figs = {
        "typical seat": SER(typ_seat),
        "worst seat": SER(worst_delta["patch"]),
        "envelope": SER(snapshot) - SER(typ_seat),
        "typical delta": SER(typ_delta),
        "worst delta": SER(worst_delta),
    }
    e, tn, wn = figs["envelope"], figs["typical seat"], figs["worst seat"]
    wd = figs["worst delta"]
    checks = [
        (rf"\| seat state, typical \| \*\*{tn:,} B\*\*", "typical seat object"),
        (rf"\| seat state, worst case \| \*\*{wn:,} B\*\*", "worst-case seat object"),
        (rf"\| snapshot envelope \| \*\*{e} B\*\*", "snapshot envelope"),
        (rf"\| delta, typical \| \*\*{figs['typical delta']} B\*\*", "typical delta"),
        (rf"\| delta, worst case \| \*\*{wd:,} B\*\*", "worst-case delta"),
        (rf"\*\*~?{round((e + 4 * tn) / 1000, 1)} KB\*\* typical, \*\*~?{round((e + 4 * wn) / 1000):,} KB\*\* worst",
         "4-seat snapshot"),
        (rf"\*\*~{round((e + 50 * tn) / 1000):,} KB\*\* typical, \*\*~{round((e + 50 * wn) / 1000):,} KB\*\* worst",
         "50-seat snapshot"),
        (rf"200 seats \(~{round((e + 200 * tn) / 1000):,} KB\)", "pagination trigger"),
        (rf"\*\*{8192 / wd:.2f}×\*\*", "message-bound ratio"),
        (rf"\*\*{8192 - wd:,} B\*\* spare", "message-bound headroom"),
        (rf"worst-case delta is {wd:,} B", "worst-case delta, restated at section 8.3"),
        (rf"\*\*{wn:,} B\*\* worst", "worst-case seat object, restated at section 12"),
        (rf"\*\*{tn:,} B\*\* typical", "typical seat object, restated at section 12"),
        (rf"{8192 / wd:.2f}× the measured worst case", "message-bound ratio at section 12"),
    ]
    for pat, what in checks:
        if not re.search(pat, raw):
            fail.append(f"G3: no stated figure matches the {what} — re-derived as "
                        f"{figs} from the published blocks. A figure this gate cannot find is a "
                        f"figure nothing re-measures")
    # the derived traffic figure: 8,940/day -> msg/s -> KiB/s at 50 seats
    per_s = 8940 / 86400
    kib = per_s * 50 * figs["typical delta"] / 1024
    if not re.search(rf"\*\*~{kib:.1f} KiB/s\*\*", raw):
        fail.append(f"G3: section 8.3's per-client traffic figure does not match "
                    f"{per_s * 50:.2f} msg/s × {figs['typical delta']} B = {kib:.2f} KiB/s")
    if not re.search(rf"~{kib:.1f} KiB/s", section_text("12-every-number-and-where-it-comes-from") or ""):
        fail.append(f"G3: section 12 does not carry the {kib:.1f} KiB/s traffic figure")

# ------------------- G4. cross-document enum containment, with prose counts ----
def d1_enum_set(field):
    r"""D1's declared value set for a wire enum, re-derived from D1 itself.

    Two shapes, because D1 uses two: a field-table row whose Bounds cell lists the members
    `a` \| `b` \| ..., and -- for `resolution` / `resolution_source` -- a mapping table whose
    last two columns ARE the two value sets.  Reading only the first shape would leave
    `resolution_source` with an empty upstream set, and an empty upstream set makes a
    containment check pass over anything."""
    out = set()
    for m in re.finditer(rf"^\|\s*`{re.escape(field)}`\s*\|(.*)$", d1_raw, re.M):
        cell = m.group(1)
        if r"\|" not in cell:
            continue
        vals = re.findall(r"`([a-z_]+)`", cell)
        if len(vals) >= 4:
            out |= set(vals)
    hdr = re.search(r"^\| First of these to arrive[^\n]*\| `resolution` \| `resolution_source` \|$",
                    d1_raw, re.M)
    if hdr and field in ("resolution", "resolution_source"):
        col = 1 if field == "resolution" else 2
        body = d1_raw[hdr.end():].split("\n")[2:]
        for row in body:
            if not row.startswith("|"):
                break
            c = [x.strip() for x in row.strip().strip("|").split("|")]
            if len(c) == 3:
                out |= set(re.findall(r"`([a-z_]+)`", c[col]))
    return out


def d2_enum_set(col):
    m = re.search(rf"^\s*{re.escape(col)}\s+ENUM\((.*?)\)", sec64 or "", re.S | re.M)
    return set(re.findall(r"'([^']+)'", m.group(1))) if m else set()


g4_checked = 0
for col, comment_pat, kind in [
        ("abort_reason", r"abort_reason\s+adds\s+([a-z_, ]+)", "calls"),
        ("close_source", r"close_source\s+adds\s+([a-z_, ]+)", "calls"),
        ("resolution", r"resolution\s+adds\s+([a-z_, ]+)", "attention"),
        ("resolution_source", r"resolution_source adds\s+([a-z_, ]+)", "attention")]:
    d2set, d1set = d2_enum_set(col), d1_enum_set(col)
    if not d2set:
        fail.append(f"G4 CONTROL: no `{col}` ENUM found in the DDL")
        continue
    if not d1set:
        fail.append(f"G4 CONTROL: D1 declares no value set for `{col}` that this extractor can "
                    f"read — the containment claim is then unfalsifiable")
        continue
    g4_checked += 1
    ext = d2set - d1set
    m = re.search(comment_pat, sec64)
    if not m:
        fail.append(f"G4: the DDL states no extension list for `{col}`; this document's set "
                    f"extends D1's by {sorted(ext)} and an implementer validating D1's closed "
                    f"enums at the ingest has no list of what may not cross the wire")
        continue
    named = {x.strip() for x in m.group(1).replace(" and ", ",").split(",") if x.strip()}
    if named != ext:
        fail.append(f"G4: the DDL comment names {sorted(named)} as `{col}`'s server-side members; "
                    f"differencing the ENUM against D1's declared set gives {sorted(ext)}")
# the two counts stated in prose
for pat, want_cols in [(r"\b([A-Za-z]+) members of these two columns", ("abort_reason", "close_source")),
                       (r"\b([A-Za-z]+) members of resolution and \w+ of resolution_source",
                        ("resolution", "resolution_source"))]:
    m = re.search(pat, sec64 or "")
    if not m:
        fail.append(f"G4: the DDL no longer states how many members of {want_cols} are server-side "
                    f"— the claim that they never cross the wire is then unfalsifiable")
        continue
    stated = WORD.get(m.group(1).lower())
    if stated is None:
        fail.append(f"G4: the DDL states {m.group(1)!r} server-side members for {want_cols}, which "
                    f"is not a number word this gate can compare")
        continue
    if want_cols[0] == "abort_reason":
        actual = len((d2_enum_set("abort_reason") - d1_enum_set("abort_reason")) |
                     (d2_enum_set("close_source") - d1_enum_set("close_source")))
    else:
        actual = len(d2_enum_set("resolution") - d1_enum_set("resolution"))
    if stated != actual:
        fail.append(f"G4: the DDL comment says {m.group(1)} server-side members for {want_cols}; "
                    f"the ENUMs differenced against D1 give {actual}")

# `badges`' bound is the size of a union this document declares in two places
srv = re.search(r"^`([^`]+)` · .*?— \*\*(\w+) declared", section_text(
    "72-this-planes-own-counters-and-badges") or "", re.M | re.S)
n_srv = 0
if not srv:
    fail.append("G4 CONTROL: section 7.2's server badge list not found — the `badges` bound rests "
                "on a population this gate cannot read")
else:
    sec72 = section_text("72-this-planes-own-counters-and-badges")
    blk = re.search(r"(`seq_gap`(?:.|\n)*?)— \*\*(\w+) declared", sec72)
    srv_set = set(re.findall(r"`([a-z_]+)`", blk.group(1)))
    n_srv = len(srv_set)
    if WORD.get(blk.group(2).lower()) != n_srv:
        fail.append(f"G4: section 7.2 says {blk.group(2)} server badges declared and lists {n_srv}")
    d1_badges = set()
    tbl = re.search(r"\| Member \| Raised by \| What a consumer should render \|\n\|[-|]+\|\n((?:\|.*\n)+)",
                    d1_raw)
    if not tbl:
        fail.append("G4 CONTROL: D1 section 9.3's member table not found — the `badges` bound "
                    "cannot be re-derived from the two populations it claims to be the union of")
    else:
        d1_badges = {re.match(r"^\|\s*`([a-z_]+)`", r).group(1)
                     for r in tbl.group(1).strip().split("\n")
                     if re.match(r"^\|\s*`([a-z_]+)`", r)}
        union = len(d1_badges | srv_set)
        m = re.search(r"\*\*0…(\d+)\*\* — the union of D1's (\d+) `degraded` members and .*?'s (\d+)",
                      sec821 or "")
        if not m:
            fail.append("G4: section 8.2.1 no longer states the `badges` bound as a union of two "
                        "named populations, so the bound cannot be re-derived")
        elif (int(m.group(1)), int(m.group(2)), int(m.group(3))) != (union, len(d1_badges), n_srv):
            fail.append(f"G4: section 8.2.1 states badges 0…{m.group(1)} as the union of "
                        f"{m.group(2)} + {m.group(3)}; re-derived from D1 section 9.3 and this "
                        f"document's section 7.2 that is {union} as the union of "
                        f"{len(d1_badges)} + {n_srv}")
        # every badge named in section 7.1's "Badge raised" column is a member of one of the two
        s71 = section_text("71-d1s-server-side-counters--where-they-live")
        rows = table_rows(s71 or "", r"^\| D1 § 12\.7 counter \| Stored \| Exposed \| Badge raised \|")
        if not rows:
            fail.append("G4 CONTROL: section 7.1's counter table not found")
        for r in rows or []:
            c = cells(r)
            if len(c) < 4:
                continue
            for b in re.findall(r"\*\*`([a-z_]+)`\*\*", c[3]):
                if b not in srv_set and b not in d1_badges:
                    fail.append(f"G4: section 7.1 raises the badge `{b}`, which is in neither D1 "
                                f"section 9.3's twelve nor section 7.2's {n_srv} — so section "
                                f"8.2.1's `badges` bound is a bound over the wrong population")
                elif b not in srv_set:
                    fail.append(f"G4: section 7.1 has a SERVER-side counter raising `{b}`, a "
                                f"member of D1 section 9.3's `degraded` array. That array is what "
                                f"the REPORTER knows about itself and section 7.2 says the server "
                                f"set is \"never merged into it\"; a server-raised member makes the "
                                f"reporter's observation and the server's indistinguishable on the "
                                f"wire. The server badge set is {sorted(srv_set)}")

# --------------------- G5. section 12's numbers vs their definition sites ------
sec12 = section_text("12-every-number-and-where-it-comes-from")
g5_rows = g5_nums = 0
if not sec12:
    fail.append("G5 CONTROL: section 12 not found")
else:
    rows = table_rows(sec12, r"^\| Value \| Number \| Basis \| Where \|")
    if not rows or len(rows) < 30:
        fail.append(f"G5 CONTROL: section 12's table parsed as {len(rows or [])} rows — the number "
                    f"table cannot be audited and this gate would report clean over it")
    for r in rows or []:
        c = cells(r)
        if len(c) < 4:
            continue
        g5_rows += 1
        anchors = re.findall(r"\]\(#([\w\-]+)\)", c[3])
        if not anchors:
            continue
        target = "\n".join(section_text(a) or "" for a in anchors)
        if not target.strip():
            fail.append(f"G5: section 12 row {c[0]!r} points at {anchors} and no such section "
                        f"exists, so its number has no definition site")
            continue
        for numtok in re.findall(r"\d[\d,.]*", c[1]):
            n = numtok.rstrip(".").replace(",", "")
            if n in ("", "0", "1", "2", "3", "4", "5", "8", "10", "12", "14", "15", "20", "50"):
                # single- and low-double-digit tokens collide with prose everywhere; they are
                # checked by the specific guards that own them (G3, G10) rather than by substring
                variants = [numtok.rstrip(".")]
            else:
                variants = {numtok.rstrip("."), n, f"{int(n):,}" if n.isdigit() else n}
            g5_nums += 1
            if not any(v in target for v in variants):
                fail.append(f"G5: section 12 row {c[0]!r} states {numtok!r}, which appears nowhere "
                            f"in the section it cites ({', '.join(anchors)}) — the table and its "
                            f"definition site have drifted apart, which is the one failure a "
                            f"one-table audit exists to prevent")

# ------------------------------- G6. Appendix A, re-derived where it can be ----
appA = section_text("appendix-a--every-d1-obligation-and-where-it-is-discharged")
n_must = n_further = 0
if not appA:
    fail.append("G6 CONTROL: Appendix A not found")
else:
    must_rows = table_rows(appA, r"^\| # \| Obligation \| Discharged in \| Tested by \|") or []
    further_rows = table_rows(appA, r"^\| # \| D1 source \| Obligation \| Discharged in \|") or []
    n_must, n_further = len(must_rows), len(further_rows)
    if not n_must or not n_further:
        fail.append("G6 CONTROL: one of Appendix A's two tables did not parse — its completeness "
                    "claim cannot be checked")
    m = re.search(r"carries \*\*(\w+) numbered `D2-MUST`", appA)
    if not m or WORD.get(m.group(1).lower()) != n_must:
        fail.append(f"G6: Appendix A says {m.group(1) if m else '?'} numbered constraints and its "
                    f"table has {n_must} rows")
    m = re.search(r"in \*\*([\w-]+)\*\* further places", appA)
    if not m or WORD.get(m.group(1).lower()) != n_further:
        fail.append(f"G6: Appendix A says {m.group(1) if m else '?'} further obligations and its "
                    f"table has {n_further} rows")
    m = re.search(r"### The ([\w-]+) further obligations", appA)
    if not m or WORD.get(m.group(1).lower()) != n_further:
        fail.append(f"G6: Appendix A's heading says {m.group(1) if m else '?'} further "
                    f"obligations and its table has {n_further} rows")
    m = re.search(r"([\w-]+) of the ([\w-]+) carry the literal marker", appA)
    if not m:
        fail.append("G6: Appendix A no longer states how its population splits into the "
                    "grep-derivable half and the semantic half, so the tool's coverage of it is "
                    "not stated where a reader will see it")
    elif WORD.get(m.group(2).lower()) != n_further:
        fail.append(f"G6: Appendix A's marker/semantic split is stated over "
                    f"{m.group(2)} obligations; the table has {n_further}")

    # the marker half, re-derived from D1: every D1 SECTION containing a literal `D2` must be
    # cited by some row of Appendix A.  Sections, not lines: one obligation can span lines and
    # one line can carry two, so a line count is not a row count and never was.
    d1_heads = heading_index(d1_raw)
    d1_lines = d1_raw.split("\n")
    # D1's acceptance tests and its decision register RESTATE obligations that are imposed
    # normatively elsewhere -- an "alternative considered" cell naming D2 is not an obligation.
    # The exclusion is derived from D1's own headings rather than listed here, and the excluded
    # set is printed, so the population this check runs over is named rather than assumed.
    restating = {re.match(r"^(\d+)", h[1]).group(1) for h in d1_heads
                 if h[0] == 2 and re.match(r"^\d+", h[1])
                 and re.search(r"acceptance tests|decisions taken", h[1], re.I)}
    if len(restating) != 2:
        fail.append(f"G6 CONTROL: expected D1 to carry an acceptance-test section and a decision "
                    f"register; the heading walker found {sorted(restating)}. The obligation "
                    f"population cannot be partitioned into normative and restating halves")
    marked = set()
    for i, line in enumerate(d1_lines):
        if "D2" not in line:
            continue
        owner = None
        for h in d1_heads:
            if h[3] <= i < h[4]:
                num = re.match(r"^(\d+(?:\.\d+)*)", h[1])
                if num:
                    owner = num.group(1)
        if owner and owner.split(".")[0] not in restating:
            marked.add(owner)
    if len(marked) < 5:
        fail.append(f"G6 CONTROL: only {len(marked)} D1 sections carry the `D2` marker — the "
                    f"section walker is broken and this half of the check is vacuous")
    cited = set(re.findall(r"§\s*(\d+(?:\.\d+)*)", appA.split("### ")[0]))
    for r in must_rows + further_rows:
        for s in re.findall(r"§\s*(\d+(?:\.\d+)*)", r):
            cited.add(s)
            # a row citing 12.6 discharges the parent's numbered constraints too
    for sec in sorted(marked):
        if sec in cited:
            continue
        # A row citing a SUBSECTION covers the marker in its parent (a § 12.6 citation covers a
        # `D2-MUST` marker sitting under § 12).  The reverse is NOT true and used to be allowed:
        # a row citing § 10 would then silently cover § 10.1, § 10.2 and § 10.4, so dropping a
        # subsection's only citation reded nothing.
        if any(c.startswith(sec + ".") for c in cited):
            continue
        fail.append(f"G6: D1 § {sec} addresses this document by name (`D2`) and no Appendix A row "
                    f"cites it — an obligation a downstream document did not notice is "
                    f"indistinguishable from one it declined")

# ----------------------------------------- G7. feed message-type closure -------
sec83 = section_text("83-the-websocket-delta-feed")
declared_types, fleet_fields = set(), set()
if not sec83:
    fail.append("G7 CONTROL: section 8.3 not found")
else:
    rows = table_rows(sec83, r"^\| Message `t` \| Direction \| When \| Payload \|") or []
    declared_types = {m.group(1) for m in
                      (re.match(r"^\|\s*`([a-z]+\.[a-z_]+)`", r) for r in rows) if m}
    if len(declared_types) < 3:
        fail.append("G7 CONTROL: section 8.3's message table parsed fewer than three types")
sec824 = section_text("824-the-fleet-health-object")
if not sec824:
    fail.append("G7 CONTROL: section 8.2.4 not found — `fleet.<field>` tokens cannot be told "
                "apart from `fleet.<message type>`, so this check would flag or forgive at random")
else:
    rows = table_rows(sec824, r"^\| Field \| Type \| Null\? \| Bounds \| Example \|") or []
    fleet_fields = {m.group(1) for m in
                    (re.match(r"^\|\s*`([a-z_]+)`", r) for r in rows) if m}
if declared_types and fleet_fields:
    for m in re.finditer(r"`((?:seat|fleet|feed)\.[a-z_]+)`", raw):
        tok, line = m.group(1), raw[:m.start()].count("\n") + 1
        head, _, tail = tok.partition(".")
        if head == "fleet" and tail in fleet_fields:
            continue
        if tok not in declared_types:
            fail.append(f"L{line}: G7: `{tok}` is used as a feed message type and has no row in "
                        f"section 8.3's table, which declares {sorted(declared_types)} — a message "
                        f"an acceptance test requires and the contract does not declare")

# --------------------------------------------------- G8. counter closure ------
s71 = section_text("71-d1s-server-side-counters--where-they-live")
s72 = section_text("72-this-planes-own-counters-and-badges")
counters = set()
if not s71 or not s72:
    fail.append("G8 CONTROL: section 7.1 or 7.2 not found")
else:
    r71 = table_rows(s71, r"^\| D1 § 12\.7 counter \| Stored \| Exposed \| Badge raised \|") or []
    r72 = table_rows(s72, r"^\| Counter \| Stored \| Exposed \| Incremented when \| Consequence \|") or []
    if len(r71) < 10 or len(r72) < 10:
        fail.append(f"G8 CONTROL: counter tables parsed as {len(r71)} and {len(r72)} rows")
    for rows, sec, ncols in ((r71, "7.1", 4), (r72, "7.2", 5)):
        for r in rows:
            c = cells(r)
            if len(c) < ncols:
                fail.append(f"G8: a section {sec} row has {len(c)} cells, not {ncols}: {r[:70]}")
                continue
            for n in re.findall(r"`([a-z_][a-z_.<>]*)`", c[0]):
                counters.add(n)
            if not c[1] or c[1] == "—":
                fail.append(f"G8: counter {c[0]} states no storage table. Section 7.1 declares "
                            f"that these sections answer where each counter is stored, which "
                            f"surface exposes it and which badge it raises; a counter with no "
                            f"stated home is a counter two implementers put in two places")
            if not c[2] or c[2] == "—":
                fail.append(f"G8: counter {c[0]} states no exposure surface")
            if not re.search(r"seat_counters|global_counters|batches|seat_state|sessions", c[1]):
                fail.append(f"G8: counter {c[0]}'s Stored cell names no table: {c[1]!r}")
    # D1's own population, re-derived from D1 rather than from a list here
    tbl = re.search(r"### 12\.7 Server-side counters(.*?)\n---", d1_raw, re.S)
    if not tbl:
        fail.append("G8 CONTROL: D1 section 12.7 not found — section 7.1's completeness claim "
                    "cannot be checked against the population it claims to cover")
    else:
        d1_ctr = set()
        for r in tbl.group(1).split("\n"):
            m = re.match(r"^\|\s*`([a-z_][a-z_.<>]*)`(?:\s*/\s*`([a-z_]+)`)?[^|]*\|", r)
            if m:
                d1_ctr.add(m.group(1))
                if m.group(2):
                    d1_ctr.add(m.group(2))
        d2_ctr = set()
        for r in r71:
            m = re.match(r"^\|\s*`([a-z_][a-z_.<>]*)`(?:\s*/\s*`([a-z_]+)`)?[^|]*\|", r)
            if m:
                d2_ctr.add(m.group(1))
                if m.group(2):
                    d2_ctr.add(m.group(2))
        if len(d1_ctr) < 12:
            fail.append(f"G8 CONTROL: only {len(d1_ctr)} counters parsed from D1 section 12.7")
        for miss in sorted(d1_ctr - d2_ctr):
            fail.append(f"G8: D1 section 12.7 defines `{miss}` and section 7.1 gives it no row — "
                        f"the counter has no storage, no surface and no badge on this plane")
        for extra in sorted(d2_ctr - d1_ctr):
            fail.append(f"G8: section 7.1 carries `{extra}`, which D1 section 12.7 does not "
                        f"define; this plane's own counters belong in section 7.2")
    # every counter MENTIONED with a counting verb must be declared in one of the two tables
    for m in re.finditer(r"(?:counting|counts|increments|counted)\s+`([a-z_][a-z_]*)`", raw):
        tok, line = m.group(1), raw[:m.start()].count("\n") + 1
        if tok not in counters and not any(tok.startswith(c) for c in counters):
            fail.append(f"L{line}: G8: `{tok}` is written as a counter and has no row in section "
                        f"7.1 or 7.2 — it has no storage, no exposure surface and nothing that "
                        f"would ever read it")

# --------------------------------------------------- G9. fixture arity --------
sec11 = section_text("11-acceptance-tests")
g9 = 0
if not sec11:
    fail.append("G9 CONTROL: section 11 not found")
else:
    pat = re.compile(r"\[§ (\d+)\]\(#(\d+)-[\w\-]+\)'s \*\*([\w-]+)\*\* events")
    for m in pat.finditer(sec11):
        g9 += 1
        stated = WORD.get(m.group(3).lower())
        target = None
        for h in HEADS:
            if re.match(rf"^{m.group(1)}\.?\s", h[1]) and h[0] == 2:
                target = "\n".join(lines[h[3]:h[4]])
                break
        if target is None:
            fail.append(f"G9: a fixture cites § {m.group(1)}, which does not exist")
            continue
        actual = len(re.findall(r"^\|\s*E\d+\s*\|", target, re.M))
        if stated != actual:
            fail.append(f"G9: a fixture is described as § {m.group(1)}'s **{m.group(3)}** events; "
                        f"§ {m.group(1)}'s table has {actual} event rows. Replaying the wrong "
                        f"number replays a different fixture than the one the test asserts against")
    if g9 == 0:
        fail.append("G9 CONTROL: no fixture states an event count in the checked form — either the "
                    "fixture table changed shape or this check is vacuous")

# ------------------------------------------------ G10. the retention chain ----
sec67 = section_text("67-retention-and-purge")
chain = ()
if not sec67:
    fail.append("G10 CONTROL: section 6.7 not found")
else:
    m = re.search(r"spool residency\s+(\d+) days.*?dedup window\s+(\d+) days.*?"
                  r"event retention (\d+) days", sec67, re.S)
    if not m:
        fail.append("G10 CONTROL: section 6.7's retention chain block did not parse — the one "
                    "inequality D1 says corrupts a timeline silently is then unguarded")
    else:
        chain = tuple(int(x) for x in m.groups())
        if not chain[0] < chain[1] < chain[2]:
            fail.append(f"G10: the retention chain reads {chain[0]} < {chain[1]} < {chain[2]}, "
                        f"which is false. A retention below the dedup window silently re-ingests "
                        f"re-sent events as new ones")
        for n, what, where in ((chain[1], "dedup window", "Dedup window"),
                               (chain[2], "event retention", "\\*\\*Event retention\\*\\*")):
            if not re.search(rf"\| {where} \| \*?\*?{n} days", sec12 or ""):
                fail.append(f"G10: the chain's {what} is {n} days and section 12's row does not "
                            f"agree — the chain and the number table have separate copies")

# --------------------------------- G11. section 10's trace, counted from it ----
sec10 = section_text("10-worked-example-the-clear-trace-folded-end-to-end")
n_ev = n_delta = n_trans = 0
if not sec10:
    fail.append("G11 CONTROL: section 10 not found")
else:
    rows = [r for r in sec10.split("\n") if re.match(r"^\|\s*E\d+\s*\|", r)]
    n_ev = len(rows)
    n_delta = sum(1 for r in rows if re.search(r"\|\s*yes", cells(r)[-1] + "|") or
                  cells(r)[-1].startswith("yes"))
    n_trans = len(re.findall(r"transition row \d+ of (\d+)", sec10))
    claimed = set(re.findall(r"transition row \d+ of (\d+)", sec10))
    if n_ev < 5:
        fail.append("G11 CONTROL: section 10's event table did not parse")
    if len(claimed) > 1:
        fail.append(f"G11: section 10's rows disagree about how many transition rows the trace "
                    f"has: {sorted(claimed)}")
    if claimed and int(claimed.pop()) != n_trans:
        fail.append("G11: section 10 marks a different number of transition rows than it claims")
    m = re.search(r"\*\*([\w-]+) events, ([\w-]+) deltas, ([\w-]+) transition rows?\.\*\*", sec10)
    if not m:
        fail.append("G11: section 10 no longer closes with its three counts, so nothing states "
                    "the property the acceptance test asserts")
    else:
        for word, actual, what in ((m.group(1), n_ev, "events"),
                                   (m.group(2), n_delta, "deltas"),
                                   (m.group(3), n_trans, "transition rows")):
            if WORD.get(word.lower()) != actual:
                fail.append(f"G11: section 10 claims {word} {what}; its own table has {actual}")

# ------------------------------------------------------------------ report ----
print(f"json blocks parsed: {len(BLOCKS)}; anchors: {len(doc_anchors)}; links: {n_links}")
print(f"G1  ENUM members re-derived: {len(g1_members)} "
      f"({len(g1_minted)} minted by this document, {len(g1_lonely)} unreachable)")
print(f"G2  wire fields: {len(g2_table)} declared, {len(g2_seen)} in {len(seat_objs)} worked "
      f"seat objects, {len(g2_table ^ g2_seen)} in symmetric difference")
print(f"G3  byte figures re-serialized: {figs}")
print(f"G4  cross-document enum sets checked: {g4_checked}; server badges: {n_srv}")
print(f"G5  section 12 rows: {g5_rows}, numbers traced to a definition site: {g5_nums}")
print(f"G6  Appendix A: {n_must} + {n_further} rows; "
      f"D1 sections carrying the `D2` marker: {len(marked) if appA else 0} "
      f"(D1's restating sections {sorted(restating) if appA else []} excluded: an acceptance "
      f"test and a decision register restate obligations imposed elsewhere)")
print(f"G7  feed message types declared: {len(declared_types)}; "
      f"fleet-object fields exempted: {len(fleet_fields)}")
print(f"G8  counters declared: {len(counters)}")
print(f"G9  fixtures with a stated arity: {g9}")
print(f"G10 retention chain: {chain}")
print(f"G11 section 10's trace: {n_ev} events, {n_delta} deltas, {n_trans} transition rows")
print("NOT MECHANIZED, and read by a human instead: (a) Appendix A's SEMANTIC half — a D1 "
      "obligation addressed to \"a consumer\" with no `D2` marker cannot be found by grep, and "
      "row S29 is a found instance; FLEET-STATE § 14 item 13 is the request that would close it. "
      "(b) whether a `Cited` number matches what D1 says, as opposed to appearing at its D2 home. "
      "(c) every MySQL behavioural claim, which needs a provisioned host.")

if fail:
    print(f"\nFAILURES ({len(fail)}):")
    for f in fail:
        print("  -", f)
    sys.exit(1)
print("ALL D2 CHECKS PASS")

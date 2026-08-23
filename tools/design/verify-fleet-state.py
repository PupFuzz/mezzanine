#!/usr/bin/env python3
"""D2 verification gate: docs/design/FLEET-STATE.md.

ELEVEN guard classes, G1-G11, one per defect class a review of this document found by hand.
Every population below is RE-DERIVED on each run -- from this document's own tables, from the
JSON blocks it publishes, or from docs/design/EVENT-SCHEMA.md -- and never from a list stored
here.  A number written into a checker is a number free to disagree with the document it is
checking, and it survives exactly the pass that falsifies it.

  G1  DDL ENUM members vs prose reachability      (R1-10: `server_purge`, a member no path emits)
  G2  field table <-> worked examples, both ways  (R1-22: four container objects with no row)
  G3  byte figures re-derived by serialization    (R1-16: "Measured" figures nothing could measure)
  G4  cross-document enum containment + counts    (R1-19: a comment saying "four" over six members)
  G5  section 12 <-> definition-site agreement    (R1-18, R1-33: a number table that drifted;
                                                   R2-8: and the substring search that could not
                                                   see it drift, repaired below)
  G6  Appendix A counts + D1 marker coverage      (R1-24: an obligation with no row; R2-9: and the
                                                   D1/D2 namespace collision that forgave it)
  G7  feed message-type closure                   (R1-11: `fleet.health` in no table)
  G8  counter closure, with Stored/Exposed, BOTH  (R1-15: 14 counters with no home; and the
      directions                                   mirror direction, a section 7.2 row no rule
                                                   writes, which the forward check could not see)
  G9  fixture arity                               (R1-21: "nine events" against a ten-row trace)
  G10 retention chain 8 < 10 < 14                 (regression guard on the one number D1 says
                                                   corrupts a timeline silently)
  G11 section 10's trace, counted from its table  (R1-21: "one transition row" against two)

Three things are NOT fully mechanizable and say so in the output rather than reporting a clean
over a population they never measured (canon: a clean result over an unnamed population reports
where the searcher stopped):

  * G6's SEMANTIC half.  An obligation D1 addresses to "a consumer" without the `D2` marker --
    Appendix A row S29 is one -- cannot be found by grep.  The marker half is checked; the SIZE
    of the semantic half is re-derived per row and printed (it is fourteen of twenty-nine, not
    the one an earlier revision of that paragraph claimed), and FLEET-STATE section 14 item 13 is
    the request that would close it.
  * G5's RESIDUE.  Every number G5 matches is then perturbed, and the ones some other value would
    also have satisfied are printed individually rather than counted as passes.  That list is the
    honest statement of which section 12 rows this gate is actually holding.
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
        "fourteen": 14, "fifteen": 15, "sixteen": 16, "seventeen": 17, "eighteen": 18,
        "twenty-eight": 28, "twenty-nine": 29, "thirty": 30, "thirty-three": 33,
        "thirty-four": 34}


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
# Five of this check's own inputs used to be WRITTEN HERE -- 8940/86400, 8192, and the seat counts
# 4 / 50 / 200 -- which is the failure mode this file's own docstring names: a change to any
# component of the 8,940 sum, or to the message bound, left the arithmetic comparing against a
# constant in the checker while it reported clean.  Each is now re-derived from the section that
# defines it, on every run, and the sum is re-ADDED from its six named components rather than read
# off the total the document states for them.
def g3_input(what, pattern, where, cast=int, group=1):
    m = re.search(pattern, section_text(where) or "")
    if not m:
        fail.append(f"G3 CONTROL: {what} could not be re-derived from section {where} — this "
                    f"check's own input would then be a number written into the checker, free to "
                    f"disagree with the document it is checking")
        return None
    return cast(m.group(group).replace(",", ""))


# the delta volume, re-ADDED from its six components; the stated total is CHECKED, never trusted
DAY_S = 24 * 60 * 60
sec83_txt = section_text("83-the-websocket-delta-feed") or ""
delta_day = None
m83 = re.search(r"State-changing events per seat-day at the ceiling:\s*(.*?)=\s*\*\*([\d,]+)\*\*",
                sec83_txt, re.S)
if not m83:
    fail.append("G3 CONTROL: section 8.3's delta-volume sum did not parse — the feed traffic "
                "figures would then rest on a total nothing re-adds")
else:
    parts = [int(x.replace(",", "")) for x in re.findall(r"([\d,]+)\s+[a-z]", m83.group(1))]
    stated = int(m83.group(2).replace(",", ""))
    if len(parts) < 6:
        fail.append(f"G3 CONTROL: only {len(parts)} components parsed out of section 8.3's "
                    f"delta-volume sum; a partial sum would agree with the stated total by luck")
    elif sum(parts) != stated:
        fail.append(f"G3: section 8.3 adds {' + '.join(str(p) for p in parts)} = {sum(parts):,} "
                    f"and states **{stated:,}** — every feed volume, the queue sizing and the "
                    f"per-client traffic figure descend from this one addition")
    else:
        delta_day = sum(parts)

# the message bound, from the sentence that sets it, in bytes
msg_bound = g3_input("the 8 KiB message bound", r"\*\*Message bound: (\d+) KiB\.\*\*",
                     "83-the-websocket-delta-feed")
if msg_bound is not None:
    msg_bound *= 1024
# the seat counts the snapshot figures are stated for, from the size table's own row labels
sec821_txt = section_text("821-the-seat-state-object") or ""
seat_counts = [int(x) for x in re.findall(r"\| snapshot, (\d+) seats \|", sec821_txt)]
page_seats = g3_input("the pagination trigger's seat count", r"Past \*\*(\d+) seats\*\*",
                      "821-the-seat-state-object")
if len(seat_counts) != 2:
    fail.append(f"G3 CONTROL: section 8.2.1's size table names {len(seat_counts)} snapshot seat "
                f"counts, not two — the fleet-snapshot figures cannot be re-derived")

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
        (rf"worst-case delta is {wd:,} B", "worst-case delta, restated at section 8.3"),
        (rf"\*\*{wn:,} B\*\* worst", "worst-case seat object, restated at section 12"),
        (rf"\*\*{tn:,} B\*\* typical", "typical seat object, restated at section 12"),
    ]
    if len(seat_counts) == 2:
        small, large = seat_counts
        checks += [
            (rf"\*\*~?{round((e + small * tn) / 1000, 1)} KB\*\* typical, "
             rf"\*\*~?{round((e + small * wn) / 1000):,} KB\*\* worst", f"{small}-seat snapshot"),
            (rf"\*\*~{round((e + large * tn) / 1000):,} KB\*\* typical, "
             rf"\*\*~{round((e + large * wn) / 1000):,} KB\*\* worst", f"{large}-seat snapshot"),
        ]
    if page_seats is not None:
        checks.append((rf"{page_seats} seats \(~{round((e + page_seats * tn) / 1000):,} KB\)",
                       "pagination trigger"))
    if msg_bound is not None:
        checks += [
            (rf"\*\*{msg_bound / wd:.2f}×\*\*", "message-bound ratio"),
            (rf"\*\*{msg_bound - wd:,} B\*\* spare", "message-bound headroom"),
            (rf"{msg_bound / wd:.2f}× the measured worst case", "message-bound ratio at section 12"),
        ]
    for pat, what in checks:
        if not re.search(pat, raw):
            fail.append(f"G3: no stated figure matches the {what} — re-derived as "
                        f"{figs} from the published blocks. A figure this gate cannot find is a "
                        f"figure nothing re-measures")
    # the derived traffic figures, every input re-derived above: the re-added per-seat-day delta
    # count, a day in seconds, and the fleet size the document states the figure for
    if delta_day is not None and len(seat_counts) == 2:
        large = seat_counts[1]
        per_s = delta_day / DAY_S
        fleet_s = per_s * large
        kib = fleet_s * figs["typical delta"] / 1024
        for pat, what in [
            (rf"\*\*{delta_day:,}\*\*, i\.e\. \*\*{per_s:.3f} msg/s/seat\*\*", "per-seat delta rate"),
            (rf"\*\*{fleet_s:.1f} msg/s\*\*", f"{large}-seat feed rate"),
            (rf"\*\*~{kib:.1f} KiB/s\*\* per connected client", "per-client traffic"),
            (rf"\({fleet_s:.2f} × {figs['typical delta']} B = "
             rf"{round(round(fleet_s, 2) * figs['typical delta']):,} B/s\)", "the traffic arithmetic"),
        ]:
            if not re.search(pat, sec83_txt):
                fail.append(f"G3: section 8.3's {what} does not match the value re-derived from "
                            f"its own inputs — {delta_day:,}/seat/day ÷ {DAY_S:,} s × {large} "
                            f"seats × {figs['typical delta']} B = {kib:.2f} KiB/s")
        sec12_txt = section_text("12-every-number-and-where-it-comes-from") or ""
        for pat, what in [
            (rf"\*\*{delta_day:,}/seat/day = {per_s:.3f} msg/s/seat\*\*; {fleet_s:.1f} msg/s",
             "the delta-volume row"),
            (rf"~{kib:.1f} KiB/s", "the per-client traffic row"),
        ]:
            if not re.search(pat, sec12_txt):
                fail.append(f"G3: section 12's {what} does not carry the re-derived value "
                            f"({delta_day:,}/seat/day, {per_s:.3f} msg/s/seat, {fleet_s:.1f} msg/s, "
                            f"{kib:.1f} KiB/s)")
        # the backpressure queue, sized in seconds of that same fleet rate
        q = re.search(r"\*\*(\d+) messages or (\d+) KiB[^*]*\*\*",
                      section_text("85-gaps-reconnect-and-why-state_version-is-not-seq") or "")
        if not q:
            fail.append("G3 CONTROL: section 8.5's outbound queue bound did not parse, so the "
                        "seconds-of-traffic it is sized in rests on nothing")
        elif not re.search(rf"{q.group(1)} messages is ~{round(int(q.group(1)) / fleet_s)} seconds",
                           section_text("85-gaps-reconnect-and-why-state_version-is-not-seq")):
            fail.append(f"G3: section 8.5 sizes its {q.group(1)}-message queue in seconds of the "
                        f"fleet's ceiling traffic; at the re-derived {fleet_s:.2f} msg/s that is "
                        f"~{round(int(q.group(1)) / fleet_s)} seconds")

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
# What used to be here was `if any(v in target ...)` over the WHOLE text of the cited section,
# which is not a check at all for a one- or two-digit value: a bare `7` is satisfied by the `7`
# inside "~70 minutes", and section 12's fold visibility lag was moved 2 s -> 7 s against this
# gate and it printed ALL D2 CHECKS PASS.  Twenty-four of the fifty rows carry only such values --
# every ceiling, the whole retention chain, the sweep cadence, the message bound -- so the guard
# was a coin flip on the digit over the half of the table it exists for.
#
# The match is now specific in two independent ways, and the gate MEASURES its own discrimination
# per number rather than asserting it:
#
#   1. The number must appear at the definition site as a WHOLE numeric token -- neither side
#      alphanumeric, and no digit or decimal group continuing it -- so a digit inside a longer
#      number can no longer answer for it.  Where section 12's Number cell writes a unit beside
#      the number ("2 s", "15 min", "5,000 rows"), that unit must also follow the token at the
#      site across at most a little markdown noise, which is what makes "2-second" and "**2 s**"
#      both count and "2 seats" not answer for "2 s".
#   2. Every token that matches is then PERTURBED: the same pattern with every other last digit.
#      The token counts as DISCRIMINATED only if all nine perturbations fail to match.  One that
#      an alternative value also satisfies is a number this gate cannot hold, and it is printed
#      as residue rather than counted as a pass -- because a count of vacuous passes reports
#      where the searcher stopped, not the state of the table.
UNIT = r"(?:[A-Za-z][A-Za-z%]*(?:/[A-Za-z]+)*|%)"
# a number is a standalone quantity, not part of an identifier (SHA-256), a version fragment or a
# superscript (2**53-1); the leading class is what keeps `256` out of `SHA-256`
NUMTOK = re.compile(r"(?<![0-9A-Za-z.,\-−⁰¹²³⁴-⁹])"
                    r"(\d[\d,]*(?:\.\d+)*)"
                    r"(?![⁰¹²³⁴-⁹])"
                    r"\s?(" + UNIT + r")?")


def site_pattern(num, unit):
    """A number, as a whole token at its definition site, with its unit if the table gave one."""
    variants = {num}
    if "," in num:
        variants.add(num.replace(",", ""))
    elif num.isdigit() and len(num) > 3:
        variants.add(f"{int(num):,}")
    alt = "|".join(re.escape(v) for v in sorted(variants, key=len, reverse=True))
    pat = rf"(?<![0-9A-Za-z.,])(?:{alt})(?![0-9])(?![.,]\d)"
    if unit:
        # the unit's first three characters, so "min" answers for "minutes" and "sea" for "seats",
        # across the markdown and hyphenation the document actually uses ("60-second", "**2 s**")
        pat += r"[\s*_~`\-–—]{0,3}" + re.escape(unit[:3])
    return pat


def perturbations(num):
    """The same number with a different last digit -- nine wrong values the site must reject."""
    out = []
    for d in "0123456789":
        if d == num[-1]:
            continue
        out.append(num[:-1] + d)
    return out


sec12 = section_text("12-every-number-and-where-it-comes-from")
g5_rows = g5_nums = g5_unit = g5_disc = g5_skipped = 0
g5_residue = []
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
        got = [m.group(1) for m in NUMTOK.finditer(c[1])]
        # digit runs the extractor deliberately declined to treat as a standalone quantity --
        # a superscript exponent, an identifier suffix (SHA-256) -- counted so the denominator
        # this check runs over is printed rather than assumed
        g5_skipped += max(0, len(re.findall(r"\d[\d,]*(?:\.\d+)*", c[1])) - len(got))
        for m in NUMTOK.finditer(c[1]):
            num, unit = m.group(1), m.group(2)
            g5_nums += 1
            # the unit is a STRENGTHENING of the whole-token match, not a substitute for it: a
            # definition site is free to write the quantity without its unit (`LIMIT 5000` is
            # SQL and cannot carry one), so a unit-anchored miss falls back to the whole token
            # and the row is reported at the weaker tier rather than passed as if it were strong
            matched = unit if unit and re.search(site_pattern(num, unit), target) else None
            if unit and matched:
                g5_unit += 1
            elif not re.search(site_pattern(num, None), target):
                fail.append(
                    f"G5: section 12 row {c[0]!r} states {num!r}"
                    f"{' ' + unit if unit else ''}, which does not appear as that quantity in the "
                    f"section it cites ({', '.join(anchors)}) — the table and its definition site "
                    f"have drifted apart, which is the one failure a one-table audit exists to "
                    f"prevent")
                continue
            # the per-row control: this gate must be able to say NO for this row
            collide = [p for p in perturbations(num)
                       if re.search(site_pattern(p, matched), target)]
            if collide:
                g5_residue.append(f"{c[0]}: {num}{' ' + unit if unit else ''} at "
                                  f"{'/'.join(anchors)} — {', '.join(collide[:4])} would match too")
            else:
                g5_disc += 1

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
    split = re.search(r"\*\*([\w-]+)\*\* of the ([\w-]+) cite a D1 section that carries\s+"
                      r"the literal marker `D2`; the other \*\*([\w-]+)\*\*", appA, re.S)
    if not split:
        fail.append("G6: Appendix A no longer states how its population splits into the "
                    "marker-derivable half and the semantic half, so the tool's coverage of it is "
                    "not stated where a reader will see it")
    elif WORD.get(split.group(2).lower()) != n_further:
        fail.append(f"G6: Appendix A's marker/semantic split is stated over "
                    f"{split.group(2)} obligations; the table has {n_further}")

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
    # `cited` used to be built by matching `§ n` over the WHOLE row, which does not distinguish a
    # D1 section number from a D2 one -- and Appendix A's "Discharged in" column is nothing but D2
    # section links.  Eleven `§ 6.4` links to this document's own `#64-ddl` therefore satisfied the
    # requirement that some row cite D1 § 6.4, so stripping every real D1 § 6.4 citation from the
    # D1-source column left this gate green.  Same hole for D1 § 8.2 through a D2 `§ 8.2.1` link.
    # The extraction is now NAMESPACED: a citation counts as a D1 citation only where the document
    # attributes it to D1, which is exactly three places.
    def d1_refs(text):
        """`§ n` written as a bare D1 attribution -- never a `[§ n](#in-doc-anchor)` D2 link."""
        return set(re.findall(r"§\s*(\d+(?:\.\d+)*)",
                              re.sub(r"\[[^\]]*\]\([^)]*\)", " ", text)))

    cited = set()
    # (a) any link into D1 itself, wherever it appears -- the anchor names the section
    for a in re.findall(r"\]\(EVENT-SCHEMA\.md#(\d+(?:-\d+)*)-", appA):
        cited.add(a.replace("-", "."))
    for m in re.finditer(r"D1\s*§\s*(\d+(?:\.\d+)*)", appA):
        cited.add(m.group(1))          # (b) an explicit "D1 § n" anywhere in the appendix
    # (c) the D1 SOURCE column of a further-obligations row, and the numbered-constraints table's
    #     obligation cell where it has already named D1 -- never the Discharged or Tested columns
    for r in further_rows:
        c = cells(r)
        if len(c) >= 2:
            cited |= d1_refs(c[1])
    for r in must_rows:
        c = cells(r)
        if len(c) >= 2 and "D1" in c[1]:
            cited |= d1_refs(c[1])
    if not cited:
        fail.append("G6 CONTROL: no D1 section citation could be attributed to a D1 column or a "
                    "`D1 §` attribution in Appendix A — the marker-coverage half is then vacuous")

    # the marker/semantic SPLIT, re-derived per row rather than restated.  The paragraph that
    # states it claimed twenty-eight and one against a real fourteen-row semantic half, and the
    # scope of section 14 item 8's closure rests on that number -- so it is a derived figure now.
    g6_marker = [cells(r)[0] for r in further_rows
                 if len(cells(r)) >= 2 and (d1_refs(cells(r)[1]) & marked)]
    g6_semantic = [cells(r)[0] for r in further_rows if cells(r)[0] not in g6_marker]
    if split:
        stated = (WORD.get(split.group(1).lower()), WORD.get(split.group(3).lower()))
        if stated != (len(g6_marker), len(g6_semantic)):
            fail.append(
                f"G6: Appendix A states its split as {split.group(1)} marker-derivable / "
                f"{split.group(3)} semantic; re-derived per row against the D1 sections that "
                f"actually carry the marker, it is {len(g6_marker)} / {len(g6_semantic)}. The "
                f"semantic half is the manual remainder section 14 item 8 scopes its closure by, "
                f"so understating it overstates what this tool covers. Semantic rows: "
                f"{', '.join(g6_semantic)}")

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

# The document's WRITING idiom for a counter, in the two word orders it actually uses: the verb
# before the name (`counting \`x\``) and the name before the verb (`\`x\` increments`, `\`x\` is
# counted`).  ONE definition, used by BOTH directions of G8 below.  A second copy would let the two
# drift, and the reverse direction's whole point is that it asks the SAME question as the forward
# one -- "is there a rule that writes this?" -- of a population the forward one cannot see.
WRITER_RE = (r"(?:counting|counts|increments|counted)\s+`{name}`"
             r"|`{name}`(?:\s+is)?\s+(?:counted|incremented|increments)")

# --------------------------------------------------- G8. counter closure ------
s71 = section_text("71-d1s-server-side-counters--where-they-live")
s72 = section_text("72-this-planes-own-counters-and-badges")
counters, claims_health, d2_own = set(), set(), set()
# the fleet-health surface's own closed list, read from the `counters` row of section 8.2.4
health_counters = set()
_hc = re.search(r"^\|\s*`counters`\s*\|[^|]*\|[^|]*\|(.*?)\|[^|]*\|\s*$", sec824 or "", re.M)
if not _hc:
    fail.append("G8 CONTROL: section 8.2.4 declares no `counters` member, so every counter that "
                "names fleet health as its exposure surface would be checked against nothing")
else:
    health_counters = set(re.findall(r"`([a-z_][a-z_.<>]*)`", _hc.group(1)))
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
                if sec == "7.2":
                    d2_own.add(n)
            if not c[1] or c[1] == "—":
                fail.append(f"G8: counter {c[0]} states no storage table. Section 7.1 declares "
                            f"that these sections answer where each counter is stored, which "
                            f"surface exposes it and which badge it raises; a counter with no "
                            f"stated home is a counter two implementers put in two places")
            if not c[2] or c[2] == "—":
                fail.append(f"G8: counter {c[0]} states no exposure surface")
            elif "fleet health" in c[2].lower():
                claims_health.update(re.findall(r"`([a-z_][a-z_.<>]*)`", c[0]))
            if not re.search(r"seat_counters|global_counters|batches|seat_state|sessions", c[1]):
                fail.append(f"G8: counter {c[0]}'s Stored cell names no table: {c[1]!r}")
    # A named surface that does not carry the thing is the same defect as no surface named at all,
    # one layer down: nine counters declared "fleet health" against a fleet object whose eight
    # fields were none of them.  Only this surface is checked, because it is the only one with a
    # closed field list -- "seat detail" returns every `seat_counters` row by construction
    # (section 8.2.3), and a badge exposure is G4's subject.  The declared list is intersected with
    # the real counter population so that a prose identifier in the same cell cannot join it.
    health_counters &= counters
    if len(health_counters) < 5:
        fail.append(f"G8 CONTROL: section 8.2.4's `counters` member names {len(health_counters)} "
                    f"actual counters — too few to be the fleet-health population, so the "
                    f"membership check below would forgive almost anything")
    for n in sorted(claims_health):
        if n not in health_counters:
            fail.append(
                f"G8: counter `{n}` names **fleet health** as its exposure surface and section "
                f"8.2.4's `counters` member does not carry it, so it is readable on no surface "
                f"this document defines — which is a counter that reads zero forever, by way of a "
                f"surface instead of a write path")

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
        # the PROSE count of that population, in both places this document states it.  It said
        # sixteen against seventeen, because subtracting the one gauge from the seventeen ROWS
        # misses that the first row carries two counters -- a count of rows is not a count of names.
        d1_gauges = {m.group(1) for m in
                     re.finditer(r"^\|\s*`([a-z_][a-z_.<>]*)`[^|]*\|[^|]*gauge", tbl.group(1), re.M)}
        n_d1_counters = len(d1_ctr - d1_gauges)
        if not d1_gauges:
            fail.append("G8 CONTROL: no gauge found in D1 section 12.7, so the counter count below "
                        "is being taken over a population that still includes one")
        for pat, where in [(r"defines \*\*(\w+)\*\* counters the ingest", "section 7.1"),
                           (r"\| The (\w+) server-side counters", "Appendix A row S16")]:
            m = re.search(pat, raw)
            if not m:
                fail.append(f"G8: {where} no longer states how many counters D1 section 12.7 "
                            f"defines, so its completeness claim is unsized")
            elif WORD.get(m.group(1).lower()) != n_d1_counters:
                fail.append(f"G8: {where} says {m.group(1)} counters in D1 section 12.7; the names "
                            f"parsed out of that table, less the {len(d1_gauges)} gauge(s) "
                            f"{sorted(d1_gauges)}, give {n_d1_counters}")
        for miss in sorted(d1_ctr - d2_ctr):
            fail.append(f"G8: D1 section 12.7 defines `{miss}` and section 7.1 gives it no row — "
                        f"the counter has no storage, no surface and no badge on this plane")
        for extra in sorted(d2_ctr - d1_ctr):
            fail.append(f"G8: section 7.1 carries `{extra}`, which D1 section 12.7 does not "
                        f"define; this plane's own counters belong in section 7.2")
    # every counter MENTIONED with a counting verb must be declared in one of the two tables
    for m in re.finditer(WRITER_RE.format(name="([a-z_][a-z_]*)"), raw):
        tok, line = m.group(1) or m.group(2), raw[:m.start()].count("\n") + 1
        if tok not in counters and not any(tok.startswith(c) for c in counters):
            fail.append(f"L{line}: G8: `{tok}` is written as a counter and has no row in section "
                        f"7.1 or 7.2 — it has no storage, no exposure surface and nothing that "
                        f"would ever read it")
    # ...and the SAME CHECK THE OTHER WAY, because one direction is half a guard.  The pass above
    # catches a WRITER WITH NO ROW; it cannot see a ROW WITH NO WRITER, which is how a counter
    # deleted from this plane's rules can survive in section 7.2 as a name nothing increments --
    # measured: re-adding `offline_quiesced_attention` to that table passed the forward check
    # untouched, raising `counters declared` from 39 to 40 and nothing else.  Section 7.2 is this
    # document's OWN counter population, so every name it declares must be named again by the rule
    # that writes it, somewhere outside the declaring table.  Section 7.1's names are D1's, written
    # by the ingest, whose rules this document does not restate -- so only 7.2 is checked this way.
    # Section 11 is excised alongside 7.2: an acceptance test ASSERTS that a rule increments a
    # counter, which is not the same thing as a rule that increments it, and a counter whose only
    # mention in the whole document is a test is a counter no implementer is ever told to write.
    outside, excised = raw, True
    # Each excision is measured on its OWN, not by one length test over both: section 11 is an
    # order of magnitude longer than section 7.2, so a combined threshold reports whichever
    # excision it likes when either fails -- a control that names the wrong cause is a control
    # that sends the next reader to the wrong place.
    for txt, what, why in ((s72, "section 7.2", "read the declaring table as its own writer"),
                           (section_text("11-acceptance-tests"), "section 11",
                            "read an acceptance test's assertion as a rule")):
        shorter = outside.replace(txt or "\x00", "\n", 1)
        if not txt or len(shorter) >= len(outside):
            excised = False
            fail.append(f"G8 CONTROL: {what}'s text did not excise from the document, so the "
                        f"writer check below would {why}")
        outside = shorter
    if not d2_own:
        fail.append("G8 CONTROL: no section 7.2 counter names parsed, so the writer check below "
                    "would run over an empty population")
    elif excised:   # a failed excision already failed above; do not also run the check on it
        for n in sorted(d2_own):
            if not re.search(WRITER_RE.format(name=re.escape(n)), outside):
                fail.append(f"G8: section 7.2 declares `{n}` and no rule outside that table names "
                            f"it — a counter with a row, a storage table and an exposure surface "
                            f"that nothing increments, which reads zero forever and so reports "
                            f"that the rule it instruments never fired")

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

# ---------------- the count of guard classes, which is itself a prose count ----
# Section 14 item 8 and section 12's status table state how much of this document is tool-checked.
# They said "ten" against eleven for a whole revision.  A gate that checks every other count in
# the document and not the count OF ITSELF is the one restatement nobody guards.
n_toolchecked = sum(1 for r in (table_rows(sec12 or "", r"^\| Check \| What the tool re-derives \| Status \|") or [])
                    if len(cells(r)) == 3 and cells(r)[2].startswith("**tool-checked**"))
m = re.search(r"mechanises \*\*(\w+)\*\* guard classes \(G1–G(\d+)\)",
              section_text("14-open-questions-for-the-review-loop") or "")
if not n_toolchecked:
    fail.append("CONTROL: section 12's tool-checked status rows did not parse, so the claim that "
                "this document is checked cannot be sized")
elif not m:
    fail.append("section 14 item 8 no longer states how many guard classes this tool mechanises, "
                "so the scope of its own closure is unstated")
else:
    stated, top = WORD.get(m.group(1).lower()), int(m.group(2))
    if not (stated == top == n_toolchecked):
        fail.append(f"section 14 item 8 says {m.group(1)} guard classes G1–G{top}; section 12's "
                    f"status table marks {n_toolchecked} rows **tool-checked**. The three numbers "
                    f"are one fact with three homes and they must agree")

# ------------------------------------------------------------------ report ----
print(f"json blocks parsed: {len(BLOCKS)}; anchors: {len(doc_anchors)}; links: {n_links}")
print(f"G1  ENUM members re-derived: {len(g1_members)} "
      f"({len(g1_minted)} minted by this document, {len(g1_lonely)} unreachable)")
print(f"G2  wire fields: {len(g2_table)} declared, {len(g2_seen)} in {len(seat_objs)} worked "
      f"seat objects, {len(g2_table ^ g2_seen)} in symmetric difference")
print(f"G3  byte figures re-serialized: {figs}")
print(f"    inputs re-derived from the document (none written into this checker): "
      f"delta volume {delta_day}/seat-day re-added from {len(parts) if m83 else 0} components, "
      f"message bound {msg_bound} B, snapshot seat counts {seat_counts}, "
      f"pagination trigger {page_seats} seats, seconds/day {DAY_S}")
print(f"G4  cross-document enum sets checked: {g4_checked}; server badges: {n_srv}")
print(f"G5  section 12 rows: {g5_rows}; numbers traced to a definition site: {g5_nums} "
      f"({g5_unit} matched with their unit, {g5_nums - g5_unit} as a whole token only); "
      f"PROVEN discriminating by perturbation: {g5_disc}; residue: {len(g5_residue)}; "
      f"digit runs not treated as a standalone quantity: {g5_skipped}")
for r in g5_residue:
    print(f"    G5 residue — a wrong value this gate would NOT notice · {r}")
print(f"G6  Appendix A: {n_must} + {n_further} rows "
      f"({len(g6_marker) if appA else 0} marker-derivable, {len(g6_semantic) if appA else 0} "
      f"semantic — the manual remainder, re-derived per row, not restated); "
      f"D1 sections cited from a D1-attributed position: {len(cited) if appA else 0}; "
      f"D1 sections carrying the `D2` marker: {len(marked) if appA else 0} "
      f"(D1's restating sections {sorted(restating) if appA else []} excluded: an acceptance "
      f"test and a decision register restate obligations imposed elsewhere)")
print(f"G7  feed message types declared: {len(declared_types)}; "
      f"fleet-object fields exempted: {len(fleet_fields)}")
print(f"G8  counters declared: {len(counters)}, of which section 7.2's own: {len(d2_own)} (each "
      f"checked for a rule that WRITES it — the document's counting-verb idiom, in either word "
      f"order — outside that table and outside section 11's tests); fleet-health counters "
      f"declared by section 8.2.4: {len(health_counters)}")
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

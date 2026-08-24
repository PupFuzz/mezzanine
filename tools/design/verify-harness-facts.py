#!/usr/bin/env python3
"""D1 verification gate: bind this document's harness facts to the installed harness binary.

Two bindings, one rung apart:

  * KEY NAMES (rounds 3+).  Every fixture key in the appendix must be a key the installed
    build's own payload schema declares for that hook.
  * ENUM VALUE SETS (round 4).  Every harness-sourced enum's value set is re-derived from
    the binary and asserted against EVERY place the document states it.  Round 3 bound key
    names and built a guard for them; it did not bind value sets, which is how a
    `notification_type` row carrying 14 of the build's 16 declared members survived three
    review rounds -- omitting exactly the two the `elicitation` branch depends on.

Ground truth is the INSTALLED HARNESS BINARY's own declarations, re-derived on every run.
Nothing here is a stored number, and every extractor carries a control that ABORTS rather
than reporting clean when it cannot discriminate (canon #9: a check that cannot fail is a
decoration).
"""
import json, re, sys, pathlib
from functools import lru_cache

ROOT = pathlib.Path(__file__).parent.parent.parent
DOC  = ROOT / "docs/design/EVENT-SCHEMA.md"
BIN  = pathlib.Path("/home/aimlapm/.local/share/claude/versions/2.1.240")
fail, notes = [], []

# ---------- re-derive the harness ground truth (never a stored figure) -------------
COMMON = {"session_id","transcript_path","cwd","prompt_id","permission_mode",
          "agent_id","agent_type","effort","hook_event_name"}
def _keys_after(s, start):
    """Collect `,key:` at brace/paren depth 0, walking forward from a declaration."""
    d = i = 0; i = start; out = set()
    while i < len(s) and i < start + 4000:
        c = s[i]
        if c in "([{":
            d += 1
        elif c in ")]}":
            if d == 0:
                break
            d -= 1
        elif c == "," and d == 0:
            m = re.match(r",([a-z_]+):", s[i:])
            if m:
                out.add(m.group(1))
        i += 1
    return out

TXT = BIN.read_bytes().decode("latin-1") if BIN.exists() else ""

HOOK_DECL = {}          # hook -> [offset just past its `hook_event_name:Ht("X")`]

def harness_truth():
    hooks = {}
    for m in re.finditer(r'hook_event_name:Ht\("([A-Za-z]+)"\)', TXT):
        HOOK_DECL.setdefault(m.group(1), []).append(m.end())
        hooks.setdefault(m.group(1), set()).update(_keys_after(TXT, m.end()))
    # CONTROL (canon #9): the extractor must be shown capable of the other answer, or its
    # "every key resolves" verdict is a decoration.  SessionStart's key IS `source`; the
    # string `session_start_reason` occurs nowhere in this build.  If the extractor cannot
    # tell those apart it cannot tell anything apart, so refuse to report a clean.
    ss = hooks.get("SessionStart", set())
    if "source" not in ss or "session_start_reason" in ss:
        raise SystemExit("harness-truth extractor failed its control: "
                         f"SessionStart keys = {sorted(ss)}")
    return {k: v | COMMON for k, v in hooks.items()}

# ---------- re-derive harness ENUM VALUE SETS from the binary ----------------------
_ARR = re.compile(r'\[((?:\s*(?:"[^"]*"|\.\.\.[A-Za-z_$][\w$]*)\s*,?)+)\]')

def _literal_array(expr, depth=0):
    """Resolve a JS array literal, following `...spread` identifiers and one-line consts."""
    if depth > 6:
        raise ValueError("array resolution too deep")
    out = []
    for tok in re.finditer(r'"([^"]*)"|\.\.\.([A-Za-z_$][\w$]*)', expr):
        if tok.group(1) is not None:
            out.append(tok.group(1))
        else:
            out.extend(_ident_array(tok.group(2), depth + 1))
    return out

def _assignments(name):
    """Offsets just past each `<name>=` in the bundle, at an identifier boundary.

    A literal `str.find` scan, not `re.search(r'\\b<name>\\s*=')`: an unanchored `\\b`
    pattern over a 340 MB bundle costs ~13 s per lookup and is what made an earlier
    version of this tool take minutes.
    """
    i, out = 0, []
    tok = name + "="
    while True:
        i = TXT.find(tok, i)
        if i < 0:
            return out
        if i == 0 or not (TXT[i - 1].isalnum() or TXT[i - 1] in "_$."):
            out.append(i + len(tok))
        i += len(tok)

@lru_cache(maxsize=None)
def _ident_array(name, depth=0):
    """`name=["a","b"]` somewhere in the bundle."""
    for pos in _assignments(name):
        m = re.match(r'\s*(\[[^\]]*\])', TXT[pos:pos + 4000])
        if m:
            return _literal_array(m.group(1), depth)
    raise ValueError(f"identifier {name!r} is not an array literal in this build")

def _resolve_expr(expr, depth=0):
    """Resolve a zod-ish field expression to its literal member list.

    Handles `Dr([...])`, `Dr(IDENT)`, and `IDENT()` -> `ve(()=>...)` indirection.
    """
    if depth > 6:
        raise ValueError("expression resolution too deep")
    expr = expr.strip()
    m = re.match(r'^[A-Za-z_$][\w$]*\(\s*(\[[^\]]*\])', expr)          # Dr(["a","b"])
    if m:
        return _literal_array(m.group(1), depth)
    m = re.match(r'^[A-Za-z_$][\w$]*\(\s*([A-Za-z_$][\w$]*)\s*\)', expr)  # Dr(nWb)
    if m:
        try:
            return _ident_array(m.group(1), depth + 1)
        except ValueError:
            pass
    m = re.match(r'^([A-Za-z_$][\w$]*)\(\s*\)', expr)                  # xha()
    if m:
        for pos in _assignments(m.group(1)):
            d = re.match(r'\s*ve\(\(\)\s*=>\s*(.{0,600})', TXT[pos:pos + 800], re.S)
            if d:
                return _resolve_expr(d.group(1), depth + 1)
        raise ValueError(f"cannot resolve {m.group(1)!r}")
    raise ValueError(f"unrecognised value-set expression: {expr[:60]!r}")

@lru_cache(maxsize=None)
def binary_value_set(spec):
    """`Hook.field` -> the member list this build declares for it."""
    hook, _, field = spec.partition(".")
    if hook == "Notification" and field == "notification_type":
        # not a payload enum -- the payload types it as a plain string.  The build's own
        # matcher metadata is what declares the routed set.
        m = re.search(r'fieldToMatch:"notification_type",values:(\[[^\]]*\])', TXT)
        if not m:
            raise ValueError("Notification matcher metadata not found in this build")
        return tuple(_literal_array(m.group(1)))
    # reuse the single full-file pass harness_truth() already made, rather than re-scanning
    # 340 MB per lookup -- a gate slow enough to skip is a gate that decays.
    if hook not in HOOK_DECL:
        raise ValueError(f"hook {hook!r} is not declared in this build")
    for pos in HOOK_DECL[hook]:
        f = re.search(r'[,{]%s:(.{0,400})' % re.escape(field), TXT[pos:pos + 600])
        if f:
            return tuple(_resolve_expr(f.group(1)))
    raise ValueError(f"{hook} declares no field {field!r} in this build")

def value_set_controls():
    """Show the resolver capable of the other answer, or abort rather than report clean."""
    a = set(binary_value_set("SessionStart.source"))
    b = set(binary_value_set("PreCompact.trigger"))
    if not a or not b:
        raise SystemExit("value-set extractor control FAILED: a resolved set is empty")
    if a == b:
        raise SystemExit("value-set extractor control FAILED: two different declarations "
                         f"resolved to the same set {sorted(a)} -- it cannot discriminate")
    try:
        got = binary_value_set("SessionStart.no_such_field_at_all")
    except ValueError:
        pass
    else:
        raise SystemExit("value-set extractor control FAILED: a fabricated field resolved "
                         f"to {got!r} instead of raising -- it would 'confirm' anything")
    return len(a), len(b)

if not BIN.exists():
    fail.append(f"harness binary absent at {BIN} — ground truth cannot be re-derived; "
                "this check reports nothing rather than a false clean")
    HOOKS = {}
else:
    HOOKS = harness_truth()
    notes.append(f"harness hooks re-derived from the installed binary: {len(HOOKS)}")
    ca, cb = value_set_controls()
    notes.append(f"value-set extractor control passed (two declarations resolved to "
                 f"{ca} and {cb} distinct members; a fabricated field raised)")

text = DOC.read_text()
lines = text.split("\n")

# ---------- 1. fixture keys must be real keys of their hook ------------------------
# population: every JSON object in the appendix that carries hook_event_name.
fixtures = []
for m in re.finditer(r"```json\n(\{.*?\})\n```", text, re.S):
    try:
        o = json.loads(m.group(1))
    except Exception:
        continue
    if isinstance(o, dict) and "hook_event_name" in o:
        fixtures.append((text[:m.start()].count("\n") + 1, o))
notes.append(f"captured-payload fixtures found: {len(fixtures)}")
if HOOKS and not fixtures:
    fail.append("no captured-payload fixtures found — the MEASURED claims have no measurement")
for line, o in fixtures:
    h = o["hook_event_name"]
    if h not in HOOKS:
        fail.append(f"L{line}: fixture claims hook {h!r}, which the installed harness does not declare")
        continue
    for k in o:
        if k not in HOOKS[h]:
            fail.append(f"L{line}: fixture {h} carries key {k!r}, absent from the harness's own schema")

# ---------- 2. every hook the doc names is classified -----------------------------
subtbl = re.search(r"\| Hook \| What the reporter does with it \| Events \|\n\|[-|]+\|\n(.*?)\n\n",
                   text, re.S)
sub  = set(re.findall(r"^\| `([A-Za-z]+)` \|", subtbl.group(1), re.M)) if subtbl else set()
if not subtbl:
    fail.append("§ 6.0's hook subscription table not found — the hook population cannot be derived")
notsub_row = re.search(r"does \*\*not\*\* subscribe: (.+?) \|", text, re.S)
notsub = set(re.findall(r"`([A-Za-z]+)`", notsub_row.group(1))) if notsub_row else set()
notes.append(f"hooks subscribed: {len(sub)}; explicitly not-subscribed: {len(notsub)}")
for h in sorted(HOOKS):
    if re.search(rf"`{h}`", text) and h not in sub and h not in notsub:
        fail.append(f"hook {h!r} is referenced but appears in neither the subscription table "
                    f"nor the not-subscribed list — undeclared, not decided")

# ---------- 3. every subscribed hook that can be measured has a fixture ------------
# EVERY subscribed hook has a fixture -- a real capture or a labelled DOCS-CITED stub.
# A missing fixture is a failure, never a skip: the whole point of the guard is that it
# cannot report clean over a hook it never looked at.
fixture_hooks = {o["hook_event_name"] for _, o in fixtures}
for h in sorted(sub & set(HOOKS)):
    if h not in fixture_hooks:
        fail.append(f"subscribed hook {h!r} has NO fixture -- neither a capture nor a "
                    f"DOCS-CITED stub, so the drift guard would skip it")
i171, i172 = text.find("### 17.1"), text.find("### 17.2")
stub_block = text[i171:i172]
def _off(lineno):
    return sum(len(x) + 1 for x in lines[:lineno])
stubs    = {o["hook_event_name"] for l, o in fixtures if i171 < _off(l) < i172}
captures = [(l, o) for l, o in fixtures if not (i171 < _off(l) < i172)]
notes.append(f"  of which DOCS-CITED stubs: {len(stubs)} ({', '.join(sorted(stubs))})")
if stubs and "**not** a capture" not in stub_block:
    fail.append("stub fixtures are not labelled as stubs -- a stub read as a capture is a "
                "false MEASURED, which is this document's own defect class")

# ---------- 3b. the appendix's own reproduced-capture counts are re-derived --------
# The raw capture run is not committed, so "56 payloads" is provenance and cannot be
# checked.  What IS reproduced here can be, and the document states those two numbers.
n_cap, n_cap_hooks = len(captures), len({o["hook_event_name"] for _, o in captures})
vol = re.search(r"\| Volume \| \*\*56 payloads across 10 hook events\*\* captured; "
                r"\*\*(\d+) reproduced below\*\*.*?\*\*(\d+)\*\* hooks", text)
if not vol:
    fail.append("§ 17's Volume row is not in the form this check re-derives — the "
                "reproduced-capture counts would go unchecked")
else:
    if int(vol.group(1)) != n_cap:
        fail.append(f"§ 17 claims {vol.group(1)} reproduced captures; the appendix contains {n_cap}")
    if int(vol.group(2)) != n_cap_hooks:
        fail.append(f"§ 17 claims {vol.group(2)} distinct hooks among the reproduced captures; "
                    f"the appendix contains {n_cap_hooks}")
notes.append(f"reproduced captures re-derived from the appendix: {n_cap} across {n_cap_hooks} hooks")

# ---------- 4. every harness-fact row carries exactly one state -------------------
tbl = re.search(r"\| Fact this design rests on \| Verbatim key \| State \|\n\|[-|]+\|\n(.*?)\n\n",
                text, re.S)
if not tbl:
    fail.append("§ 6.0's harness-fact table not found — the marker population cannot be derived")
    rows = []
else:
    rows = [r for r in tbl.group(1).split("\n") if r.startswith("|")]
notes.append(f"harness-fact rows: {len(rows)}")
for r in rows:
    states = [s for s in ("MEASURED", "DOCS-CITED", "UNVERIFIED") if s in r]
    if not states:
        fail.append(f"harness-fact row carries NO state marker: {r[:110]}")
for want in ("MEASURED", "DOCS-CITED", "UNVERIFIED"):
    if not any(want in r for r in rows):
        fail.append(f"no harness-fact row is marked {want} — the three-state rule is not in use")

# ---------- 5. UNVERIFIED rows must carry a cost and a closure --------------------
for r in rows:
    if "UNVERIFIED" in r and not re.search(r"[Cc]ost if wrong|not needed|Nothing to close|"
                                           r"[Rr]ead it at|Closed by|moves with it", r):
        fail.append(f"UNVERIFIED row names no cost-if-wrong and no closure act: {r[:110]}")

# ---------- 6. HARNESS ENUM VALUE SETS: doc == binary, at every stated site --------
# This is the round-4 binding.  Population: § 6.0's enum-source table, whose own coverage
# against the doc's full enum population is checked by verify-event-schema.py -- so a new
# harness-sourced enum cannot escape both tools.
BARE = re.compile(r"^[a-z][a-z0-9_]*$")

def section_bounds(num):
    """Line range of the `### <num> ...` (or `## <num>`) heading's section."""
    start = None
    for i, l in enumerate(lines):
        m = re.match(r"^#{2,4}\s+(\d+(?:\.\d+)?)[\s.]", l)
        if not m:
            continue
        if start is None and m.group(1) == num:
            start = i
        elif start is not None:
            return start, i
    return (start, len(lines)) if start is not None else (None, None)

def value_runs(line):
    """Maximal runs of backticked bare identifiers joined only by ` \\| ` or `, `."""
    toks = [(m.start(), m.end(), m.group(1)) for m in re.finditer(r"`([^`]*)`", line)]
    runs, cur, prev_end = [], [], None
    for s, e, v in toks:
        joiner = line[prev_end:s] if prev_end is not None else None
        if cur and BARE.match(v) and joiner in (r" \| ", ", "):
            cur.append(v)
        else:
            if len(cur) >= 2:
                runs.append(cur)
            cur = [v] if BARE.match(v) else []
        prev_end = e
    if len(cur) >= 2:
        runs.append(cur)
    return runs

def table_of(idx):
    """Line range of the contiguous `|`-prefixed block containing line idx."""
    a = b = idx
    while a > 0 and lines[a - 1].startswith("|"):
        a -= 1
    while b + 1 < len(lines) and lines[b + 1].startswith("|"):
        b += 1
    return a, b

src_tbl = re.search(r"\| Harness enum set \| Binary declaration \| This document states it at \| "
                    r"Members this reporter adds \|\n\|[-|]+\|\n(.*?)\n\n", text, re.S)
if not src_tbl:
    fail.append("§ 6.0's harness enum-source table not found — no value set can be bound to "
                "the binary, which is the round-4 check reporting nothing rather than clean")
    src_rows = []
else:
    src_rows = [r for r in src_tbl.group(1).split("\n") if r.startswith("|")]
    src_lo = text[:src_tbl.start(1)].count("\n")
    src_hi = src_lo + len(src_rows)

notes.append(f"harness enum value-set bindings: {len(src_rows)}")
checked = 0
for r in src_rows:
    cells = [c.strip() for c in r.strip().strip("|").split("|")]
    if len(cells) != 4:
        fail.append(f"enum-source row is not four cells: {r[:110]}")
        continue
    setname, binspec, locator, extras_cell = cells
    spec = binspec.strip("`")
    try:
        want = set(binary_value_set(spec))
    except ValueError as e:
        fail.append(f"enum-source row {setname}: {e}")
        continue
    extras = set(re.findall(r"`([^`]*)`", extras_cell))
    lm = re.match(r"§\s*(\d+(?:\.\d+)?)\s*›\s*(table\s+)?`([^`]*)`", locator)
    if not lm:
        fail.append(f"enum-source row {setname}: locator {locator!r} is not "
                    "`§ N.N › `label`` or `§ N.N › table `label``")
        continue
    sec, is_table, label = lm.group(1), bool(lm.group(2)), lm.group(3)
    lo, hi = section_bounds(sec)
    if lo is None:
        fail.append(f"enum-source row {setname}: § {sec} not found")
        continue
    hits = []
    for i in range(lo, hi):
        if src_rows and src_lo <= i <= src_hi:      # never match the binding table itself
            continue
        l = lines[i]
        if not l.startswith("|"):
            continue
        first = re.search(r"`([^`]*)`", l)
        if not first or first.group(1) != label:
            continue
        if is_table or value_runs(l):
            hits.append(i)
    if len(hits) != 1:
        fail.append(f"enum-source row {setname}: locator {locator!r} matched {len(hits)} "
                    f"document sites, expected exactly 1 — the site moved or is ambiguous")
        continue
    if is_table:
        a, b = table_of(hits[0])
        got = {v for i in range(a, b + 1) for run in value_runs(lines[i]) for v in run}
    else:
        runs = value_runs(lines[hits[0]])
        got = set(max(runs, key=len))
    expect = want | extras
    if got != expect:
        missing, extra = sorted(expect - got), sorted(got - expect)
        fail.append(
            f"L{hits[0]+1}: value-set drift for {setname} at {locator} — "
            f"the installed binary declares {len(want)} members"
            + (f"; MISSING from the document: {missing}" if missing else "")
            + (f"; stated but NOT declared by the binary: {extra}" if extra else ""))
    checked += 1
notes.append(f"harness enum value sets asserted against the binary: {checked}")

# ---------- 7. round-2 finding ids must not leak ----------------------------------
for pat in [r"\bB-[1-6]\b", r"\bM-(?:[1-9]|1[0-2])\b", r"\bm-(?:[1-9]|1[0-5])\b", r"\bh-[1-4]\b"]:
    for m in re.finditer(pat, text):
        fail.append(f"L{text[:m.start()].count(chr(10))+1}: review finding id leaked: {m.group(0)}")

# ---------- report ----------------------------------------------------------------
for n in notes:
    print(n)
if fail:
    print(f"\nFAILURES ({len(fail)}):")
    for f in fail:
        print("  -", f)
    sys.exit(1)
print("HARNESS-FACT CHECKS PASS")

#!/usr/bin/env python3
"""D1 verification gate, round 3.

Extends verify.py (links, anchors, JSON, bans, finding ids, placeholders) with the checks
that would have caught THIS round's defect class: harness facts transcribed from another
product with nothing binding them to a source.

Ground truth is the INSTALLED HARNESS BINARY's own payload-schema declarations, re-derived
on every run.  Nothing here is a stored number.
"""
import json, re, sys, pathlib

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

def harness_truth():
    txt = BIN.read_bytes().decode("latin-1")
    hooks = {}
    for m in re.finditer(r'hook_event_name:Ht\("([A-Za-z]+)"\)', txt):
        hooks.setdefault(m.group(1), set()).update(_keys_after(txt, m.end()))
    # CONTROL (canon #9): the extractor must be shown capable of the other answer, or its
    # "every key resolves" verdict is a decoration.  SessionStart's key IS `source`; the
    # string `session_start_reason` occurs nowhere in this build.  If the extractor cannot
    # tell those apart it cannot tell anything apart, so refuse to report a clean.
    ss = hooks.get("SessionStart", set())
    if "source" not in ss or "session_start_reason" in ss:
        raise SystemExit("harness-truth extractor failed its control: "
                         f"SessionStart keys = {sorted(ss)}")
    return {k: v | COMMON for k, v in hooks.items()}

if not BIN.exists():
    fail.append(f"harness binary absent at {BIN} — ground truth cannot be re-derived; "
                "this check reports nothing rather than a false clean")
    HOOKS = {}
else:
    HOOKS = harness_truth()
    notes.append(f"harness hooks re-derived from the installed binary: {len(HOOKS)}")

text = DOC.read_text()

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
stub_block = text[text.find("### 17.1"):text.find("### 17.2")]
stubs = {o["hook_event_name"] for l, o in fixtures
         if text.find("### 17.1") < sum(len(x)+1 for x in text.split(chr(10))[:l]) < text.find("### 17.2")}
notes.append(f"  of which DOCS-CITED stubs: {len(stubs)} ({', '.join(sorted(stubs))})")
if stubs and "**not** a capture" not in stub_block:
    fail.append("stub fixtures are not labelled as stubs -- a stub read as a capture is a "
                "false MEASURED, which is this document's own defect class")

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

# ---------- 6. round-2 finding ids must not leak ----------------------------------
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

#!/usr/bin/env python3
"""harness-fixture-drift.selftest.py — hermetic acceptance for bin/harness-fixture-drift.py.

WHY THIS FILE EXISTS. The guard's whole value is that it goes RED on a divergence nobody
else can see, and a guard nobody has watched fail is indistinguishable from a guard that
always passes — which is exactly the state the fixtures were already in. So nothing here
asserts on a code path by reading it: every block drives the REAL script as a REAL
subprocess against PLANTED copies of the two ends, and states the verdict it expects.

THE THREE ARMS, AND WHY THEY ARE KEPT APART.
  * § 2 DRIFT (exit 1) — the two ends disagree. One mutation per case, on ONE end, so a
    green is attributable: a case that changed two things could pass for the wrong reason.
  * § 3 FAIL-LOUD (exit 2) — the AUTHORITY cannot be read at all. This is the arm a drift
    guard most often gets wrong, because the tempting behaviour (fall back, skip, report
    clean over what could be read) is invisible and feels harmless. Exit 2 is asserted
    distinctly from exit 1 throughout: "the gate is broken" reported as "your fixtures are
    wrong" sends an author to regenerate files that were already correct.
  * § 1 CONTROL — the real repository passes, and the run STATES its population. Without
    it, every red below is consistent with a gate that fails on everything.

EVERY PLANT IS ANCHORED. `plant()` requires its anchor to occur an exact number of times and
raises otherwise, so a refactor that moves the text this file mutates turns the suite RED
rather than silently turning a RED case into a GREEN one.

NO NETWORK, NO CREDENTIAL, NO BOARD, NO HARNESS BINARY. This runs on a public runner, and
deliberately needs nothing that `tools/design/verify-harness-facts.py` needs — the doc↔fixture
seam is checkable without the harness, which is why this guard can be a CI gate and that one
cannot.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GUARD = REPO / "bin" / "harness-fixture-drift.py"
DOC = REPO / "docs/design/EVENT-SCHEMA.md"
FIXTURES = REPO / "fleet-reporter/fixtures/hooks"
WORKFLOW = REPO / ".github/workflows/harness-fixture-drift.yml"
CONSUMER = REPO / "tools/design/verify-harness-facts.py"

fails = 0


def ok(msg: str) -> None:
    print(f"  ok   {msg}")


def bad(msg: str) -> None:
    global fails
    fails += 1
    print(f"  FAIL {msg}", file=sys.stderr)


def eq(what: str, want, got) -> None:
    ok(what) if want == got else bad(f"{what} — expected {want!r} got {got!r}")


def contains(what: str, needle: str, haystack: str) -> None:
    (ok(what) if needle in haystack
     else bad(f"{what} — {needle!r} not in output:\n{haystack[:1200]}"))


def plant(text: str, old: str, new: str, count: int = 1) -> str:
    """Replace an anchor that MUST be present exactly `count` times.

    A plant whose anchor no longer matches raises rather than quietly doing nothing: a case
    that plants no defect and then observes no failure is a green that means the opposite of
    what it reads like.
    """
    seen = text.count(old)
    if seen != count:
        raise AssertionError(f"plant anchor {old[:70]!r} occurs {seen}x, expected {count}x — "
                             f"the document moved; fix this suite rather than the assertion")
    return text.replace(old, new, count)


def stage(doc_edit=None, fixture_edit=None) -> tuple[Path, Path]:
    """A throwaway copy of the two ends, with at most one mutation applied to each."""
    d = Path(tempfile.mkdtemp(prefix="hfd-"))
    doc = d / "EVENT-SCHEMA.md"
    fix = d / "hooks"
    text = DOC.read_text(encoding="utf-8")
    doc.write_text(doc_edit(text) if doc_edit else text, encoding="utf-8")
    shutil.copytree(FIXTURES, fix)
    if fixture_edit:
        fixture_edit(fix)
    return doc, fix


def run(doc: Path, fix: Path, *extra: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [sys.executable, str(GUARD), f"--doc={doc}", f"--fixtures={fix}", *extra],
        capture_output=True, text=True, cwd=str(REPO))


def edit_json(fix: Path, hook: str, fn) -> None:
    p = fix / f"{hook}.json"
    obj = json.loads(p.read_text(encoding="utf-8"))
    fn(obj)
    p.write_text(json.dumps(obj, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


# ── § 1. CONTROL — the real repository passes, and says over what ─────────────────────────
print("== 1. CONTROL — the committed fixtures ARE the appendix, and the run states its "
      "population ==")
r = run(DOC, FIXTURES)
eq("the real doc + the real fixtures → exit 0", 0, r.returncode)
contains("  … and the verdict names the authority it read", "EVENT-SCHEMA.md § 17", r.stdout)

# The population is PRINTED and asserted here, so a later green cannot be a gate that looked
# at nothing. The figures are re-derived from the document by this suite — not typed — so a
# legitimate re-capture moves both sides together instead of reddening on its own correction.
doc_text = DOC.read_text(encoding="utf-8")
sys.path.insert(0, str(REPO / "tools" / "design"))
import d1_appendix  # noqa: E402  (path set above)
ap = d1_appendix.parse_appendix(doc_text)
n_cap, n_cap_hooks = len(ap.captures), len({p.hook for p in ap.captures})
n_stub, n_stub_hooks = len(ap.stubs), len({p.hook for p in ap.stubs})
n_files = len(d1_appendix.derive_fixtures(ap.payloads))
eq("the population is non-empty on all three counts (an empty gate cannot fail)",
   (True, True, True), (n_cap > 0, n_stub > 0, n_files > 0))
contains("  … and the run REPORTS that population rather than only a verdict",
         f"{n_cap} captured payloads across {n_cap_hooks} hooks, and {n_stub} DOCS-CITED "
         f"stubs across {n_stub_hooks} hooks", r.stdout)
contains("  … including how many files it therefore expects",
         f"{n_files} vendored fixture files derived", r.stdout)
eq("  … and the derived file count equals what is committed",
   n_files, len(list(FIXTURES.glob("*.json"))))

# A staged (copied) clean pair must also pass — otherwise every red below could be an
# artefact of staging rather than of the plant.
doc_c, fix_c = stage()
eq("a STAGED clean copy also passes (so staging itself plants nothing)",
   0, run(doc_c, fix_c).returncode)


# ── § 2. DRIFT (exit 1) — one mutation, one end, each seen RED ────────────────────────────
print("\n== 2. DRIFT — every way the two ends can disagree, one variable at a time (exit 1) ==")

DRIFT: list[tuple[str, dict, str]] = []

# 2a. A VALUE changed in a fixture. The smallest possible divergence, and the one a reviewer
#     is least likely to see in a diff of a JSON blob.
DRIFT.append((
    "a fixture VALUE edited (SessionStart source=startup → startup_)",
    dict(fixture_edit=lambda f: edit_json(f, "SessionStart",
                                          lambda o: o["shapes"][0].__setitem__("source", "startup_"))),
    "SessionStart.json has DRIFTED"))

# 2b. A KEY dropped from a fixture — the shape the reporter's own key check reads, so a
#     silently-dropped key makes `harness_payload_keys` assert against less than D1 states.
DRIFT.append((
    "a fixture KEY dropped (SessionStart shape loses `cwd`)",
    dict(fixture_edit=lambda f: edit_json(f, "SessionStart",
                                          lambda o: o["shapes"][0].pop("cwd"))),
    "SessionStart.json has DRIFTED"))

# 2c. ORDER changed. Same shapes, different sequence: invisible to any set comparison, and a
#     real loss — § 17's order is what pairs a shape with the prose describing it.
DRIFT.append((
    "a fixture's shapes REORDERED (same shapes, different order)",
    dict(fixture_edit=lambda f: edit_json(f, "SessionStart",
                                          lambda o: o["shapes"].reverse())),
    "SessionStart.json has DRIFTED"))

# 2d. `_source` flipped. A stub relabelled as a capture is a false MEASURED — D1's own
#     headline defect class — and nothing else in the repo would notice.
DRIFT.append((
    "a fixture's `_source` flipped (StopFailure stub relabelled a capture)",
    dict(fixture_edit=lambda f: edit_json(f, "StopFailure",
                                          lambda o: o.__setitem__("_source", "capture"))),
    "StopFailure.json has DRIFTED"))

# 2e. A fixture file deleted.
DRIFT.append((
    "a fixture file DELETED (Stop.json)",
    dict(fixture_edit=lambda f: (f / "Stop.json").unlink()),
    "Stop.json is MISSING"))

# 2f. A fixture file for a hook the appendix does not publish. Invisible to any check that
#     iterates only the EXPECTED set.
DRIFT.append((
    "an EXTRA fixture file the appendix does not produce (Bogus.json)",
    dict(fixture_edit=lambda f: (f / "Bogus.json").write_text('{"_source":"capture","shapes":[]}\n')),
    "Bogus.json is not derived from § 17"))

# 2g. A stray non-JSON file in the fixture directory — the reporter ships this whole
#     directory as part of its installed artifact, so nothing in it is unexamined.
DRIFT.append((
    "a stray non-JSON file in the fixture directory (notes.txt)",
    dict(fixture_edit=lambda f: (f / "notes.txt").write_text("scratch\n")),
    "notes.txt is not derived from § 17"))

# 2h. THE CARD'S OWN CASE, and the direction that produced the near-miss: the APPENDIX is
#     edited alone. Every other check in this repo stays green on this one.
DRIFT.append((
    "THE DOCUMENT edited alone — a key added to a § 17 payload (the card#7930 shape)",
    dict(doc_edit=lambda t: plant(
        t,
        '"hook_event_name":"SubagentStart"}',
        '"hook_event_name":"SubagentStart","new_field_from_the_recapture":null}')),
    "SubagentStart.json has DRIFTED"))

# 2i. A payload MOVED across the stub heading in the document: same bytes, different
#     `_source`. Read as "which side of § 17.1 is it on", never from the fixture.
def _move_precompact_into_stubs(t: str) -> str:
    block = ('**PreCompact (trigger=manual)**\n\n```json\n'
             '{"session_id":"11111111-2222-4333-8444-000000000005",'
             '"transcript_path":"~/.claude/projects/-home-agent-proj/'
             '11111111-2222-4333-8444-000000000005.jsonl","cwd":"/home/agent/proj",'
             '"prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000004",'
             '"hook_event_name":"PreCompact","trigger":"manual",'
             '"custom_instructions":null}\n```\n\n')
    t = plant(t, block, "")
    return plant(t, "**StopFailure** — *DOCS-CITED stub", block + "**StopFailure** — *DOCS-CITED stub")

DRIFT.append((
    "a payload MOVED across the DOCS-CITED heading (capture → stub, same bytes)",
    dict(doc_edit=_move_precompact_into_stubs),
    "PreCompact.json has DRIFTED"))

for name, kw, needle in DRIFT:
    doc_p, fix_p = stage(**kw)
    r = run(doc_p, fix_p)
    eq(f"{name} → exit 1", 1, r.returncode)
    contains(f"  … and names it: {needle}", needle, r.stdout)
    contains("  … annotated for Actions", "::error::harness-fixture-drift:", r.stdout)
    contains("  … and the fix-it names --write", "--write", r.stdout)


# ── § 3. FAIL-LOUD (exit 2) — the authority cannot be read ────────────────────────────────
print("\n== 3. FAIL-LOUD — an unreadable authority must RED, never pass quietly (exit 2) ==")

LOUD: list[tuple[str, dict, str]] = []

LOUD.append((
    "the appendix HEADING renamed — the parser's only anchor",
    dict(doc_edit=lambda t: plant(t, "\n## 17. Appendix", "\n## 17. Captured payloads")),
    "found 0"))

LOUD.append((
    "a SECOND `## N. Appendix` heading — ambiguous, never 'pick one'",
    dict(doc_edit=lambda t: t + "\n## 99. Appendix — a second one\n"),
    "found 2"))

LOUD.append((
    "the DOCS-CITED stub heading renamed — `_source` would be decided by nothing",
    dict(doc_edit=lambda t: plant(t, "### 17.1 DOCS-CITED stubs",
                                  "### 17.1 Hooks that could not be driven")),
    "DOCS-CITED stub"))

LOUD.append((
    "a payload block parked OUTSIDE the appendix (invisible to every gate that reads it)",
    dict(doc_edit=lambda t: plant(
        t, "\n## 17. Appendix",
        '\n```json\n{"hook_event_name":"Stop","session_id":"x"}\n```\n\n## 17. Appendix')),
    "OUTSIDE the appendix"))

LOUD.append((
    "a payload block AFTER the stub subsection, where no region classifies it",
    dict(doc_edit=lambda t: plant(
        t, "### 17.2 Two facts visible only in the ordering",
        '### 17.2 Two facts visible only in the ordering\n\n```json\n'
        '{"hook_event_name":"Stop","session_id":"x"}\n```\n')),
    "AFTER the DOCS-CITED stub subsection"))

LOUD.append((
    "an UNPARSEABLE JSON block inside the appendix (never silently skipped)",
    dict(doc_edit=lambda t: plant(t, "**SessionStart (source=resume)**",
                                  "```json\n{not json at all\n```\n\n"
                                  "**SessionStart (source=resume)**")),
    "does not parse"))

LOUD.append((
    "a JSON block in the appendix with NO `hook_event_name` (unclassifiable, not ignorable)",
    dict(doc_edit=lambda t: plant(t, "**SessionStart (source=resume)**",
                                  '```json\n{"an": "example"}\n```\n\n'
                                  "**SessionStart (source=resume)**")),
    "carries no `hook_event_name`"))

LOUD.append((
    "ZERO captured payloads — an empty population is not a clean result",
    dict(doc_edit=lambda t: _strip_capture_blocks(t)),
    "NO captured payloads"))

LOUD.append((
    "one hook publishing on BOTH sides of the stub heading (`_source` irreducible)",
    dict(doc_edit=lambda t: plant(
        t, "**StopFailure** — *DOCS-CITED stub",
        '```json\n{"hook_event_name":"PreCompact","trigger":"manual"}\n```\n\n'
        "**StopFailure** — *DOCS-CITED stub")),
    "BOTH sides"))


def _strip_capture_blocks(t: str) -> str:
    """Delete every ```json block between the appendix heading and the stub subsection."""
    lo = t.index("\n## 17. Appendix")
    hi = t.index("\n### 17.1 DOCS-CITED stubs")
    body, n = re.subn(r"```json\n.*?\n```\n", "", t[lo:hi], flags=re.S)
    if n == 0:
        raise AssertionError("plant removed no capture blocks — the appendix moved")
    return t[:lo] + body + t[hi:]


for name, kw, needle in LOUD:
    doc_p, fix_p = stage(**kw)
    r = run(doc_p, fix_p)
    eq(f"{name} → exit 2 (NOT 0, and NOT 1)", 2, r.returncode)
    contains(f"  … and says why: {needle}", needle, r.stderr)

# The two paths that are not document content at all.
d = Path(tempfile.mkdtemp(prefix="hfd-"))
r = run(d / "no-such-doc.md", FIXTURES)
eq("a missing DOCUMENT → exit 2", 2, r.returncode)
contains("  … and refuses to fall back to the committed fixtures",
         "no fallback to the committed fixtures", r.stderr)

r = run(DOC, d / "no-such-dir")
eq("a missing FIXTURE DIRECTORY → exit 2, never an empty pass", 2, r.returncode)
contains("  … and says an absence is not a pass", "is not an empty pass", r.stderr)

# THE SHARED § 17 PARSER MISSING. This gate deliberately carries no local spelling of the
# appendix grammar, so its absence must be exit 2 — a gate that fell back to a private copy
# would be the duplication this card exists to remove, growing back inside its own guard.
# Driven by running the script from a tree that has no `tools/design/`.
lonely = Path(tempfile.mkdtemp(prefix="hfd-")) / "bin"
lonely.mkdir(parents=True)
shutil.copy2(GUARD, lonely / GUARD.name)
r = subprocess.run([sys.executable, str(lonely / GUARD.name), f"--doc={DOC}",
                    f"--fixtures={FIXTURES}"], capture_output=True, text=True)
eq("the shared appendix parser MISSING → exit 2, never a private fallback", 2, r.returncode)
contains("  … and says it will not guess the grammar", "will not guess one", r.stderr)

# AN UNEXPECTED FAILURE IS EXIT 2 TOO, and this is the arm that is easiest to get wrong: an
# uncaught exception exits 1 by default, which is this gate's DRIFT code, so a permission
# error would reach a reviewer as "your fixtures are wrong". Driven with a REAL unreadable
# fixture rather than by reading the handler.
doc_p, fix_p = stage()
unreadable = fix_p / "Stop.json"
unreadable.chmod(0o000)
r = run(doc_p, fix_p)
unreadable.chmod(0o644)
if r.returncode == 0:      # running as root: the chmod does not deny us, so nothing was driven
    ok("an UNREADABLE fixture → not measured (this seat can read a 0o000 file; likely root)")
else:
    eq("an UNREADABLE fixture → exit 2, NOT exit 1 (a broken gate is not a drift verdict)",
       2, r.returncode)
    contains("  … and says the fixtures were not judged", "were NOT judged", r.stderr)


# ── § 4. --write repairs from the AUTHORITY, and is a no-op when there is nothing to do ───
print("\n== 4. --write regenerates from § 17 rather than by hand ==")
doc_p, fix_p = stage(fixture_edit=lambda f: edit_json(
    f, "PostToolUse", lambda o: o["shapes"][0].__setitem__("tool_name", "Wrong")))
eq("a planted drift is RED before --write", 1, run(doc_p, fix_p).returncode)
w = run(doc_p, fix_p, "--write")
eq("  … --write exits 0", 0, w.returncode)
contains("  … and names the file it rewrote", "updated PostToolUse.json", w.stdout)
eq("  … after which the guard passes", 0, run(doc_p, fix_p).returncode)
eq("  … and the repaired file is byte-identical to the committed one",
   (FIXTURES / "PostToolUse.json").read_text(), (fix_p / "PostToolUse.json").read_text())

doc_p, fix_p = stage(fixture_edit=lambda f: (f / "SubagentStop.json").unlink())
w = run(doc_p, fix_p, "--write")
eq("--write recreates a DELETED fixture", 0, w.returncode)
contains("  … and says it wrote it", "wrote SubagentStop.json", w.stdout)
eq("  … byte-identical to the committed one",
   (FIXTURES / "SubagentStop.json").read_text(), (fix_p / "SubagentStop.json").read_text())

doc_p, fix_p = stage(fixture_edit=lambda f: (f / "Bogus.json").write_text("{}\n"))
w = run(doc_p, fix_p, "--write")
eq("--write removes a stale `<Hook>.json` — the namespace it generates", 0, w.returncode)
eq("  … and it is gone", False, (fix_p / "Bogus.json").exists())

# …and NOT anything else. A repair flag that deletes files it cannot account for is a worse
# surprise than the drift it was fixing, so a stray outside the generated namespace is
# REPORTED — which is why a --write run can still exit 1.
doc_p, fix_p = stage(fixture_edit=lambda f: (f / "notes.txt").write_text("scratch\n"))
w = run(doc_p, fix_p, "--write")
eq("--write does NOT delete a file outside the generated namespace → exit 1", 1, w.returncode)
eq("  … and the file is still there", True, (fix_p / "notes.txt").exists())
contains("  … and it says it will not delete what it did not write",
         "deletes nothing else", w.stdout)
contains("  … and does not tell the author to re-run --write they just ran",
         "this WAS a --write run", w.stdout)

doc_p, fix_p = stage()
w = run(doc_p, fix_p, "--write")
eq("--write on a clean tree is a NO-OP", 0, w.returncode)
contains("  … and says so rather than claiming a repair", "nothing to write", w.stdout)

# --write must NOT run when the authority is unreadable: repairing from a document that
# could not be parsed is how a stale fixture set gets blessed.
doc_p, fix_p = stage(doc_edit=lambda t: plant(t, "\n## 17. Appendix", "\n## 17. Captured"))
before = sorted((p.name, p.read_text()) for p in fix_p.iterdir())
w = run(doc_p, fix_p, "--write")
eq("--write over an UNREADABLE authority → exit 2", 2, w.returncode)
eq("  … and it wrote nothing", before, sorted((p.name, p.read_text()) for p in fix_p.iterdir()))


# ── § 5. META-CONTROL — the pass in § 1 was not vacuous ───────────────────────────────────
print("\n== 5. META-CONTROL — the guard is capable of the other answer on every file ==")
# § 1 shows one green over fifteen files. If the comparison silently skipped a file, that
# green would look identical. So mutate EVERY file in turn and require each to red ALONE,
# naming ITSELF — fifteen single-variable controls, one per member of the population.
missed = []
for hook_file in sorted(FIXTURES.glob("*.json")):
    hook = hook_file.stem
    doc_p, fix_p = stage(fixture_edit=lambda f, h=hook: edit_json(
        f, h, lambda o: o["shapes"].append({"hook_event_name": h, "planted": True})))
    rr = run(doc_p, fix_p)
    if rr.returncode != 1 or f"{hook}.json has DRIFTED" not in rr.stdout:
        missed.append(f"{hook} (exit {rr.returncode})")
eq("EVERY vendored fixture reds ALONE when mutated (no file is silently unchecked)",
   [], missed)


# ── § 6. THE SEAM HAS ONE PARSER — the consolidation this card also owns ──────────────────
print("\n== 6. § 17 has ONE parser in this repo, and the guard's consumer uses it ==")
consumer = CONSUMER.read_text(encoding="utf-8")
contains("verify-harness-facts.py imports the shared appendix parser",
         "from d1_appendix import", consumer)


def uncommented(src: str) -> str:
    """`src` with its `#` comments removed, so a comment ABOUT the grammar is not the grammar.

    Load-bearing, and it was a real false positive on the first run of this check: the
    consolidation commit's own explanatory comment quotes the `text.find("### 17.1")` literal
    it deleted, and a scanner that cannot tell code from prose forbids a file from NAMING the
    thing it no longer does — which gets the honest write-up deleted instead of the defect.
    """
    import io, tokenize
    out, last = [], (1, 0)
    for tok in tokenize.generate_tokens(io.StringIO(src).readline):
        if tok.type == tokenize.COMMENT:
            continue
        if tok.start[0] != last[0]:
            out.append("\n")
        out.append(tok.string)
        last = tok.end
    return "".join(out)


# The three spellings the second parser was made of. A second parser is free to disagree with
# this one about what § 17 contains, and the first thing a disagreement produces is one gate
# reporting clean over a population the other one can see — this card's own defect, one level
# up. Matched against CODE only.
SECOND_PARSER = [r"```json", r'"### 17\.1"', r'"### 17\.2"']
code = uncommented(consumer)
resurrected = [pat for pat in SECOND_PARSER if re.search(pat, code)]
eq("  … and its CODE carries no second spelling of the § 17 grammar", [], resurrected)
# CONTROL, both ways. The empty list above is a measurement only if this check can produce a
# non-empty one — and only if the comment-stripping has not made it blind to real code.
straw = ('for m in re.finditer(r"```json\\n(\\{.*?\\})\\n```", text):\n'
         '    i, j = text.find("### 17.1"), text.find("### 17.2")\n')
eq("  … the check FIRES on the pre-consolidation spelling in code (control)",
   3, len([pat for pat in SECOND_PARSER if re.search(pat, uncommented(straw))]))
eq("  … and does NOT fire on the same text inside a comment (the false positive it had)",
   0, len([pat for pat in SECOND_PARSER
           if re.search(pat, uncommented("# " + straw.replace("\n", "\n# ")))]))


# ── § 7. THE CALLER — a guard wired to one side is dark where the defect lands ────────────
print("\n== 7. The WORKFLOW runs the guard on BOTH ends of the seam ==")
wf = WORKFLOW.read_text(encoding="utf-8")
eq("the workflow invokes the guard", True, "python3 bin/harness-fixture-drift.py" in wf)
eq("  … and this suite, so the guard's own reds are re-proven on every change to it",
   True, "python3 bin/harness-fixture-drift.selftest.py" in wf)
# The path list is the load-bearing part: the card#7930 divergence was the DOCUMENT edited
# alone, so a trigger listing only the fixtures would be dark in exactly that case.
for needed in ("docs/design/EVENT-SCHEMA.md", "fleet-reporter/fixtures/**",
               "bin/harness-fixture-drift.py", "tools/design/d1_appendix.py",
               "tools/design/verify-harness-facts.py"):
    eq(f"  … triggered on {needed}", True, f"'{needed}'" in wf)
eq("  … on pull_request AND on push to the integration branches",
   True, "pull_request:" in wf and "branches: [dev, main]" in wf)
# CONTROL: the membership test above must be capable of the other answer.
eq("  … and that path test REJECTS a path the workflow does not list (control)",
   False, "'docs/design/FLOOR.md'" in wf)
eq("the workflow does not claim to be a required check it is not",
   True, "NOT A REQUIRED STATUS CHECK" in wf)


print()
if fails:
    print(f"harness-fixture-drift.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("harness-fixture-drift.selftest: all checks passed")

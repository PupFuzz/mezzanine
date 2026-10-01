#!/usr/bin/env python3
r"""change-pr-body.selftest.py — hermetic acceptance for bin/change-pr-body.py and the house map.

WHY THIS FILE EXISTS. `bin/change-pr-body.py` makes ONE claim worth anything: the body it emits
MEETS the fleet standard. A claim like that decays silently — upstream's linter is re-vendored,
the standard's allowed set moves, somebody adds a section to the template — and a generator that
has quietly stopped complying is byte-indistinguishable from one that never did. So nothing here
reads the generator's source and agrees with it: every case drives the REAL generator as a REAL
subprocess against a STAGED repository, and pipes its real output into the REAL vendored linter.

THE ARMS, AND WHY THEY ARE KEPT APART.
  * § 1 CONTROL — the generator's own output passes `bin/pr-body-lint.py` clean. This is the
    claim; everything else exists so that a green here means something.
  * § 2 RED — one mutation per case, applied to that same passing body, each naming the ONE rule
    it must provoke. Without this arm § 1 is consistent with a linter that passes everything,
    which is the exact failure mode a vendored judge can drift into. Its DENOMINATOR — which
    rules exist — is read out of the linter's source, never counted here, so a re-vendor that
    adds a rule reds this arm for under-coverage instead of leaving it quietly incomplete.
  * § 2b UPSTREAM AI-ATTRIBUTION — the lines the operator's 2026-09-27 instruction forbids (the
    Claude Code model line this generator used to emit, a session link, a co-author trailer),
    planted back into the body and judged by the newest cached coord linter (0.58.0 or later),
    which has the `ai-attribution` rule the vendored copy does not yet carry. That linter is read
    from the plugin cache (`CPB_UPSTREAM_LINT` overrides the path, and an override naming no file
    FAILS) and is NOT vendored here, so where it is absent — a CI runner — the arm prints
    `NOT RUN` by name instead of passing; § 4's whole-body pattern check still runs there. Once the
    vendored copy carries the rule, § 2 drives the same mutations against it on every run.
  * § 3 REFUSALS (rc 2) — the inputs the generator must decline instead of inventing. A
    generator that invents a `Built:` value produces a fabricated attestation, which is worse
    than the gap it fills, so the refusal is the behaviour under test and not an edge case.
  * § 4 SHAPE — the properties the standard states that a lint cannot see: `## Upgrade warnings`
    present ONLY when asked (the IN table admits no empty one), the author markers present, the
    scope line carrying a derivation rather than a tally, and the diagnostics on STDERR so that
    `> body.md` writes a body.
  * § 5 HOUSE MAP — `CLAUDE.md`'s `change-pr-body:house-map` block, held to the linter's own
    `ALLOWED_H2`. The map is the answer an author reads instead of re-deriving it; a map that
    has drifted from the judge sends them to write a section that reds.

NO NETWORK, NO CREDENTIAL, NO BOARD, AND NO DEPENDENCE ON THIS CHECKOUT'S GIT STATE. Each case
stages its own repository under a temp directory — which is not tidiness: a CI checkout of a pull
request is a DETACHED HEAD, so a suite that ran the generator against the checkout would exercise
the detached-HEAD refusal in CI and the ordinary path on a workstation, and the two runs would be
testing different programs.
"""
from __future__ import annotations

import atexit
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GEN = REPO / "bin" / "change-pr-body.py"
LINT = REPO / "bin" / "pr-body-lint.py"
ORIENTATION = REPO / "CLAUDE.md"
MAP_MARKER = "change-pr-body:house-map"

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
     else bad(f"{what} — {needle!r} not in:\n{haystack[:1200]}"))


def absent(what: str, needle: str, haystack: str) -> None:
    (ok(what) if needle not in haystack
     else bad(f"{what} — {needle!r} IS present in:\n{haystack[:1200]}"))


def plant(text: str, old: str, new: str, count: int = 1) -> str:
    """Replace an anchor that MUST be present exactly `count` times.

    A mutation whose anchor no longer matches raises rather than quietly doing nothing: a case
    that plants no defect and then observes no failure is a green that reads as its opposite.
    """
    seen = text.count(old)
    if seen != count:
        raise AssertionError(f"mutation anchor {old[:70]!r} occurs {seen}x, expected {count}x — "
                             f"the generator's output moved; fix this suite, not the assertion")
    return text.replace(old, new, count)


# ── The vendored judge's own allowed set, READ rather than retyped ────────────────────────────
# Retyping the tuple here would make this suite a THIRD copy of the standard's section set, and
# the copy nothing guards — the one that goes stale at the next re-vendor while still passing.
_spec = importlib.util.spec_from_file_location("pr_body_lint", LINT)
_lint_mod = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_lint_mod)
ALLOWED_H2 = _lint_mod.ALLOWED_H2


STAGED: list[Path] = []


def _sweep() -> None:
    if os.environ.get("CPB_KEEP_STAGED"):
        print(f"change-pr-body.selftest: kept staged dir(s) (CPB_KEEP_STAGED is set): "
              f"{', '.join(str(d) for d in STAGED)}")
        return
    for d in STAGED:
        shutil.rmtree(d, ignore_errors=True)


atexit.register(_sweep)


def stage(*, commit_on_feature: bool = True, detach: bool = False) -> Path:
    """A throwaway repository: `dev`, a `feature` branch off it, and an `origin` that is itself.

    `origin` is this same repository added as a remote and fetched, because the generator
    prefers `origin/<base>` over a local `<base>` on purpose and a staged tree with no remote
    would silently exercise the fallback instead of the path every real run takes.
    """
    d = Path(tempfile.mkdtemp(prefix="cpb-"))
    STAGED.append(d)
    work = d / "work"
    work.mkdir()

    def g(*args: str) -> None:
        proc = subprocess.run(["git", *args], cwd=work, capture_output=True, text=True)
        if proc.returncode != 0:
            raise AssertionError(f"staging: git {' '.join(args)} failed: {proc.stderr}")

    g("init", "-q", "-b", "dev")
    g("config", "user.email", "selftest@example.invalid")
    g("config", "user.name", "change-pr-body selftest")
    (work / "a.txt").write_text("a\n", encoding="utf-8")
    g("add", "a.txt")
    g("commit", "-q", "-m", "base commit")
    g("remote", "add", "origin", str(work))
    g("fetch", "-q", "origin")
    g("checkout", "-q", "-b", "feature")
    if commit_on_feature:
        (work / "b.txt").write_text("b\n", encoding="utf-8")
        g("add", "b.txt")
        g("commit", "-q", "-m", "the change this PR merges")
    if detach:
        g("checkout", "-q", "--detach", "HEAD")
    return work


def generate(work: Path, *extra: str) -> subprocess.CompletedProcess:
    argv = [sys.executable, str(GEN),
            "--built", "dispatched (coder ×1 / mechanic ×0)",
            "--coordinated-in", "card#9801", *extra]
    return subprocess.run(argv, cwd=work, capture_output=True, text=True)


def lint(body: str, linter: Path = LINT) -> tuple[int, dict]:
    proc = subprocess.run([sys.executable, str(linter), "--body-file=-", "--json"],
                          input=body, capture_output=True, text=True)
    if proc.returncode not in (0, 1):
        raise AssertionError(f"{linter} could not judge the body (rc {proc.returncode}): "
                             f"{proc.stderr}")
    return proc.returncode, json.loads(proc.stdout)


def rules(body: str, linter: Path = LINT) -> list[str]:
    _, verdict = lint(body, linter)
    return sorted({f["rule"] for f in verdict["findings"]})


def rule_ids(linter: Path) -> set[str]:
    """The rule ids a linter can emit, read out of its source — see § 2 for why never retyped."""
    return set(re.findall(r'Finding\("([a-z-]+)"', linter.read_text(encoding="utf-8")))


# ── § 1 CONTROL — the generator's output meets the standard ───────────────────────────────────
print("\n§ 1 CONTROL — the generated skeleton is judged by the real vendored linter")

WORK = stage()
run = generate(WORK)
eq("the generator exits 0 on an ordinary change branch", 0, run.returncode)
BODY = run.stdout
eq("  … and its body is CLEAN against `bin/pr-body-lint.py`", [], rules(BODY))
eq("  … which is the claim this file exists to keep true", 0, lint(BODY)[0])

# Both machine-read fields must be READ OFF the body by the linter's own parser, not merely
# present as text: a field inside a fence is present to a human and absent to the parser, which
# is the defect the fleet linter was first pointed at.
_, verdict = lint(BODY)
eq("  … `Built:` is parsed off the body, not merely present",
   "dispatched (coder ×1 / mechanic ×0)", verdict["fields"]["Built"])
eq("  … `**Coordinated in:**` likewise", "card#9801", verdict["fields"]["Coordinated in"])

# Every H2 the generator emits is DERIVED from the output and checked against the judge's set,
# so a section added to the template with no home in the standard reds here rather than on a PR.
emitted = [h.strip() for h in re.findall(r"(?m)^##[ \t]+(.+?)[ \t]*$", BODY)]
eq("  … every H2 it emits is one the standard admits",
   [], [h for h in emitted
        if not any(h.lower().startswith(a.lower()) for a in ALLOWED_H2)])
eq("  … and it emits at least one section (an empty body would pass § 1 vacuously)",
   True, bool(emitted))


# ── § 2 RED — one mutation per case, each naming the rule it must provoke ─────────────────────
print("\n§ 2 RED — the green above is discriminating: each mutation reds its own rule")

SCOPE = BODY.splitlines()[0]
BUILT = "Built: dispatched (coder ×1 / mechanic ×0)"
COORD = "**Coordinated in:** card#9801"

MUTANTS = (
    ("a house section is added back",
     lambda b: plant(b, "## Highlights", "## Evidence"), ["heading-not-allowed"]),
    ("the scope line is dropped, so the body opens on a heading",
     lambda b: plant(b, SCOPE + "\n\n", ""), ["scope-line"]),
    ("the `Built:` line is dropped",
     lambda b: plant(b, BUILT + "\n", ""), ["built-missing"]),
    ("the `**Coordinated in:**` line is dropped",
     lambda b: plant(b, COORD + "\n", ""), ["coordinated-missing"]),
    ("a `FROM:` attribution line is put back on top",
     lambda b: "FROM: mezzanine-solo\n" + b, ["attribution-line"]),
    ("a highlight opens on a phrase the OUT table homes elsewhere",
     lambda b: plant(b, "## Highlights\n", "## Highlights\n\nWhy this change was made.\n"),
     ["banned-opener"]),
    ("a reading is written into the body",
     lambda b: plant(b, "## Highlights\n",
                     "## Highlights\n\n- CI is green at 4f2a91c.\n"),
     ["live-state-reading"]),
)

# The lines the operator's 2026-09-27 instruction (card#10673) keeps off every GitHub-bound text,
# each planted where this generator used to write its attribution trailer — the first is that
# trailer's exact old spelling. Driven by § 2 when the vendored linter carries `ai-attribution`,
# and by § 2b against the newest cached coord linter either way.
AI_LINES = (
    ("the Claude Code model line this generator used to emit is put back",
     "🤖 Generated with [Claude Code](https://claude.com/claude-code)"
     " — implemented by the mezzanine `coder` subagent"),
    ("a Claude session link is put back", "https://claude.ai/code/session_EXAMPLE"),
    ("a Claude co-author trailer is put back", "Co-Authored-By: Claude <noreply@anthropic.com>"),
)
AI_MUTANTS = tuple(
    (what, lambda b, line=line: plant(b, COORD + "\n", COORD + "\n\n" + line + "\n"),
     ["ai-attribution"])
    for what, line in AI_LINES)

LINT_RULE_IDS = rule_ids(LINT)
if "ai-attribution" in LINT_RULE_IDS:
    MUTANTS += AI_MUTANTS

for what, mutate, want in MUTANTS:
    eq(f"{what} → reds", want, rules(mutate(BODY)))

# ⛔ THE DENOMINATOR IS DERIVED FROM THE JUDGE, NEVER COUNTED BY HAND. "One mutation per rule" is
# a coverage claim, and a coverage claim over a population nobody re-computes reports where the
# author stopped rather than what is covered: re-vendor a linter that adds an eighth rule and
# `MUTANTS` silently under-covers while still reading as complete. The rule ids are read out of
# the linter's own source — the same refusal to retype a set that makes this file IMPORT
# `ALLOWED_H2` instead of listing it — and the control below proves the reader discriminates,
# because a regex that matched nothing would make this check vacuously green.
eq("CONTROL: the rule-id derivation actually finds rules in the linter's source",
   True, len(LINT_RULE_IDS) >= 5 and "heading-not-allowed" in LINT_RULE_IDS)
eq("  … and every rule the vendored linter can emit has a mutation that provokes it",
   set(), LINT_RULE_IDS - {rule for _, _, want in MUTANTS for rule in want})
eq("  … with no mutation claiming a rule the linter does not have",
   set(), {rule for _, _, want in MUTANTS for rule in want} - LINT_RULE_IDS)

# META-CONTROL: the suite above proves each mutation reds; this proves the UNMUTATED body is what
# was being mutated — a `plant()` anchor that stopped matching would have raised, but an anchor
# that matched a DIFFERENT occurrence would not.
eq("META-CONTROL: the body under mutation is still the clean one", [], rules(BODY))


# ── § 2b UPSTREAM AI-ATTRIBUTION — the newest cached coord linter, from the plugin cache ─────
print("\n§ 2b UPSTREAM AI-ATTRIBUTION — the generated body against the cached coord `ai-attribution`")

# The newest cached coord release, not a pinned one: a pin to 0.58.0 would turn this arm into a
# silent NOT RUN on the first plugin update. An explicit CPB_UPSTREAM_LINT that names no file is
# an operator error and FAILS — only the absent-by-default case (a CI runner) is NOT RUN.
def _newest_cached_lint() -> Path:
    root = Path.home() / ".claude/plugins/cache/agent-board-framework/coord"
    found = [(tuple(int(n) for n in d.name.split(".")), d / "templates/bin/pr-body-lint.py")
             for d in (root.iterdir() if root.is_dir() else ())
             if re.fullmatch(r"\d+\.\d+\.\d+", d.name)]
    return max(found)[1] if found else root / "<none cached>/templates/bin/pr-body-lint.py"


UPSTREAM_OVERRIDE = os.environ.get("CPB_UPSTREAM_LINT")
UPSTREAM_LINT = Path(UPSTREAM_OVERRIDE) if UPSTREAM_OVERRIDE else _newest_cached_lint()
if UPSTREAM_OVERRIDE and not UPSTREAM_LINT.is_file():
    bad(f"CPB_UPSTREAM_LINT names {UPSTREAM_LINT}, which is not a file — an explicit override "
        f"that resolves to nothing is a misconfiguration, not an absent linter")
elif not UPSTREAM_LINT.is_file():
    print(f"  NOT RUN — no upstream linter at {UPSTREAM_LINT} (set CPB_UPSTREAM_LINT). The "
          f"`ai-attribution` judgement of this body is UNVERIFIED on this run"
          + (" — § 2 drove it against the vendored linter." if "ai-attribution" in LINT_RULE_IDS
             else "."))
else:
    # CONTROL: the arm judges nothing unless the linter it reads actually has the rule.
    eq(f"CONTROL: {UPSTREAM_LINT.name} at that path carries the `ai-attribution` rule",
       True, "ai-attribution" in rule_ids(UPSTREAM_LINT))
    eq("the generated body is CLEAN against it — no model line, no session link",
       [], rules(BODY, UPSTREAM_LINT))
    for what, mutate, want in AI_MUTANTS:
        eq(f"{what} → reds", want, rules(mutate(BODY), UPSTREAM_LINT))


# ── § 3 REFUSALS — what it declines rather than invents ───────────────────────────────────────
print("\n§ 3 REFUSALS — rc 2, with the cause named on stderr")


def refused(what: str, run_: subprocess.CompletedProcess, needle: str) -> None:
    if run_.returncode != 2:
        bad(f"{what} — expected rc 2, got {run_.returncode}; stdout:\n{run_.stdout[:400]}")
        return
    if run_.stdout.strip():
        bad(f"{what} — a refusal wrote a BODY to stdout:\n{run_.stdout[:400]}")
        return
    contains(what, needle, run_.stderr)


refused("an empty range is refused rather than described",
        generate(stage(commit_on_feature=False)), "nothing to merge")
refused("a detached HEAD is refused rather than named `HEAD`",
        generate(stage(detach=True)), "HEAD is detached")
refused("a base branch that does not exist here is refused",
        generate(WORK, "--base", "no-such-branch"), "neither `origin/no-such-branch`")
refused("a head that does not resolve is refused",
        generate(WORK, "--head", "no-such-ref"), "does not resolve to a commit")
refused("an empty `Built:` value is refused, never emitted as a blank field",
        subprocess.run([sys.executable, str(GEN), "--built", "  ",
                        "--coordinated-in", "card#9801"],
                       cwd=WORK, capture_output=True, text=True), "--built is empty")
refused("a `**Coordinated in:**` value spanning lines is refused",
        subprocess.run([sys.executable, str(GEN), "--built", "in-session",
                        "--coordinated-in", "card#9801\ncard#9802"],
                       cwd=WORK, capture_output=True, text=True), "spans more than one line")
# The options that wrote AI attribution into the body are GONE, so a caller still passing one is
# refused by argparse rather than having it dropped silently or written anywhere.
for _removed in ("--agent", "--session-url"):
    refused(f"the removed `{_removed}` option is refused, not accepted and ignored",
            generate(WORK, _removed, "x"), "unrecognized arguments: %s" % _removed)

# ⛔ GIT'S OWN WORDS REACH THE REFUSAL. The cause here is NOT one of this program's four named
# causes, and before `Git.said()` existed it was reported as one of them ("this is not a git
# repository") — a wrong-but-specific cause. The staged defect is a config git cannot parse, which
# is the cheapest way to make git fail for a reason the program cannot enumerate; dubious
# ownership and a corrupt object store are the same shape and are NOT separately staged, because
# what is under test is that the words are carried, not which words they are.
BROKEN = stage()
(BROKEN / ".git" / "config").write_text("this is not a git config\n", encoding="utf-8")
broken_run = generate(BROKEN)
refused("a git failure the program cannot enumerate is not reported as one that it can",
        broken_run, "git said:")
contains("  … and git's own words are what it carries", "bad config", broken_run.stderr)
absent("  … rather than a cause the program made up", "is not a git repository",
       broken_run.stderr)

# ⛔ THE SECOND GIT SITE, DRIVEN SEPARATELY, BECAUSE THE FIRST FIX SKIPPED IT. `resolve_base` used
# to write `if Git(...).rc == 0` and drop the object, so this case — the store cannot be read, the
# `--git-dir` probe still passes — reported "fetch it first", which cannot help. One policy is
# only one policy if every site is driven; the case that proves it is the one that was missed.
CORRUPT = stage()
_head = subprocess.run(["git", "rev-parse", "origin/dev"], cwd=CORRUPT,
                       capture_output=True, text=True).stdout.strip()
_loose = CORRUPT / ".git" / "objects" / _head[:2] / _head[2:]
if not _loose.exists():
    bad(f"staging: expected a loose object at {_loose} — this git packed it, and the case cannot "
        f"be planted as written")
else:
    _loose.chmod(0o644)   # git writes loose objects read-only; they are immutable to git, not to us
    _loose.write_bytes(b"not a git object")
    corrupt_run = generate(CORRUPT)
    refused("a base whose OBJECT cannot be read carries git's diagnosis, not 'fetch it first'",
            corrupt_run, "git said:")
    contains("  … and the diagnosis is the store's, not a guess about the ref",
             "corrupt", corrupt_run.stderr)
    # The refusal's own advice is still printed — it is the ANSWER ("neither ref resolves") and
    # stays true; what the clause adds is the DIAGNOSIS the author needs to know it will not help.
    contains("  … alongside the answer the function actually has",
             "neither `origin/dev`", corrupt_run.stderr)
    # Both candidates fail with the same sentence; it is reported ONCE, not as two problems.
    eq("  … and one cause is reported once, not once per candidate tried",
       1, corrupt_run.stderr.count("git said:"))

# argparse's own refusal, asserted because `--built` being REQUIRED is the design decision that
# keeps this program from ever writing an attestation nobody made.
no_built = subprocess.run([sys.executable, str(GEN), "--coordinated-in", "card#9801"],
                          cwd=WORK, capture_output=True, text=True)
eq("omitting `--built` altogether is a usage fault, not a default", 2, no_built.returncode)
contains("  … and it names the missing field", "--built", no_built.stderr)


# ── § 4 SHAPE — what the standard states and a lint cannot see ────────────────────────────────
print("\n§ 4 SHAPE — the properties the standard states that no rule checks")

absent("`## Upgrade warnings` is ABSENT by default (the IN table admits no empty one)",
       "## Upgrade warnings", BODY)
warned = generate(WORK, "--upgrade-warnings")
eq("  … and present when the author declares the installer must act", 0, warned.returncode)
contains("  … as an H2", "## Upgrade warnings", warned.stdout)
eq("  … still clean against the linter", [], rules(warned.stdout))

contains("the judgement sections are left as author markers", "<!-- AUTHOR:", BODY)
contains("the scope line carries the DERIVATION that re-prints the range",
         "git log --oneline", SCOPE)
eq("  … and no tally of it (canon #16: this generator runs once, the branch keeps growing)",
   [], re.findall(r"\b\d+ commits?\b", SCOPE))

# STDOUT IS THE BODY AND ONLY THE BODY — `> body.md` must not capture diagnostics.
contains("the still-yours checklist goes to STDERR", "Still yours:", run.stderr)
absent("  … and never to stdout", "Still yours:", BODY)

# THE BASE IT ACTUALLY USED IS NAMED, because nothing here fetches and a stale `origin/<base>`
# widens the range the scope line claims. The ref and a sha, not a freshness verdict.
contains("the resolved base REF is named on stderr", "`origin/dev`", run.stderr)
eq("  … with a sha beside it, so a stale tip is visible",
   True, bool(re.search(r"`origin/dev` at [0-9a-f]{7,40}", run.stderr)))
contains("  … and the stale-base consequence is stated, not left to be inferred",
         "widens the range", run.stderr)

# ⛔ THE BODY ENDS ON THE MACHINE LINES. Nothing follows `**Coordinated in:**` — the slot the
# Claude Code model line and the session link used to fill stays empty rather than being filled
# with an agent line nobody agreed a spelling for. Asserted on the text as well as by § 2b,
# because § 2b does not run where the upstream linter is absent.
eq("the body's last line is `**Coordinated in:**` — no attribution trailer after it",
   COORD, BODY.rstrip("\n").splitlines()[-1])
# … and nowhere else in the body either. The last-line check only guards the old trailer slot; this
# is the placement-independent half, and it runs on every runner (CI included) because it needs
# no upstream linter. Patterns are the three AI_LINES shapes, matched loosely.
AI_ATTRIBUTION_RE = re.compile(
    r"Generated with \[?Claude|claude\.com/claude-code|claude\.ai/code/session|"
    r"Co-Authored-By:\s*Claude", re.I)
eq("none of the three AI_LINES attribution shapes anywhere in the generated body (a subset of upstream `ai-attribution`)",
   [], AI_ATTRIBUTION_RE.findall(BODY))
eq("  CONTROL: the pattern catches every AI_LINES shape",
   [True] * len(AI_LINES), [bool(AI_ATTRIBUTION_RE.search(line)) for _, line in AI_LINES])
contains("the checklist names the verbatim fields as unjudged beyond one line",
         "written VERBATIM", run.stderr)


# ── § 5 HOUSE MAP — CLAUDE.md's mapping, held to the judge's own allowed set ───────────────────
print("\n§ 5 HOUSE MAP — `CLAUDE.md`'s `change-pr-body:house-map`, against `ALLOWED_H2`")

orientation = ORIENTATION.read_text(encoding="utf-8")
block = re.search(r"<!--\s*" + re.escape(MAP_MARKER) + r"\b.*?-->", orientation, re.S)
if not block:
    bad(f"`{MAP_MARKER}` block is ABSENT from CLAUDE.md — the map an author reads is gone, and "
        f"every assertion below would otherwise pass over an empty population")
else:
    # ⛔ THE ROW GRAMMAR IS ASSERTED BEFORE THE ROWS ARE READ, because a SELECTOR silently drops
    # what it does not match. `startswith("## ") and "|" in line` is how the rows are found, so a
    # mistyped row — `# What you do | …`, or an arrow where the pipe should be — is not a failing
    # row, it is NO row: it leaves the map, the author still reads it, and nothing grades it. The
    # block is prose down to its first row and rows from there to `-->`; every line in that tail
    # must BE a row. This is the same defect shape as the empty-population zero below, one level
    # finer — that one catches the whole map vanishing, this one catches a row vanishing.
    ROW_RE = re.compile(r"^##[ \t]+\S.*\|.*\S.*$")
    lines = [line.rstrip() for line in block.group(0).splitlines()]
    body_lines = [line for line in lines if line.strip() != "-->"]

    # ⛔ THE POPULATION IS STRUCTURAL, NOT POSITIONAL, AND THE DIFFERENCE IS A MEASURED HOLE. The
    # first cut graded "every line after the FIRST matching row", which leaves the leading edge
    # unreachable: a mistyped row inserted immediately ABOVE the first real one is read as part
    # of the preamble, so the suite stays fully green while that row is ungraded and still in
    # front of an author — the exact failure the assertion exists to close, one position out of
    # reach. No prose line in this block starts with `##`, so the population is every line whose
    # lstrip does, wherever it sits, and a row that has stopped being a row FAILS instead of
    # leaving the set.
    candidates = [line for line in body_lines if line.lstrip().startswith("##")]
    eq("every `##` line in the block IS a row (a mistyped one would vanish, not fail)",
       [], [line for line in candidates if not ROW_RE.match(line)])

    rows = [line.strip() for line in candidates if ROW_RE.match(line)]
    # The wrong-population zero, closed FIRST: an empty or truncated block makes every check
    # below vacuously green, which is the one way this section could report clean while the map
    # it grades has disappeared.
    eq("the block carries rows to grade", True, len(rows) >= 2)
    for row in rows:
        house, dest = (part.strip() for part in row.split("|", 1))
        # Every LHS must be a heading the judge actually REFUSES. A map row for a heading the
        # standard has come to admit would send an author to rewrite a section that was fine.
        eq(f"`{house}` is a heading the linter refuses",
           ["heading-not-allowed"], rules(f"scope.\n\n{house}\n\nbody\n\n{BUILT}\n{COORD}\n"))
        if dest.startswith("## "):
            name = dest[3:].strip()
            eq(f"  … and its destination `{dest}` is one the standard admits",
               True, any(name.lower().startswith(a.lower()) for a in ALLOWED_H2))
            # CONTROL: the destination is not merely IN the tuple — it passes the real judge, so
            # the row's advice is executable and not just consistent with a list.
            eq(f"  … and a body using `{dest}` passes",
               [], rules(f"scope.\n\n{dest}\n\nbody\n\n{BUILT}\n{COORD}\n"))
        else:
            ok(f"  … and its destination is outside the body: {dest}")

    # CONTROL for the two predicates above: a heading the standard DOES admit must not read as
    # refused, or "`X` is a heading the linter refuses" would be true of everything.
    eq("CONTROL: an admitted heading is NOT refused by the same probe",
       [], rules(f"scope.\n\n## Highlights\n\nbody\n\n{BUILT}\n{COORD}\n"))


print()
if fails:
    print(f"change-pr-body.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("change-pr-body.selftest: all checks passed")

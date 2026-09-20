#!/usr/bin/env python3
r"""change-pr-body.selftest.py — hermetic acceptance for bin/change-pr-body.py and the house map.

WHY THIS FILE EXISTS. `bin/change-pr-body.py` makes ONE claim worth anything: the body it emits
MEETS the fleet standard. A claim like that decays silently — upstream's linter is re-vendored,
the standard's allowed set moves, somebody adds a section to the template — and a generator that
has quietly stopped complying is byte-indistinguishable from one that never did. So nothing here
reads the generator's source and agrees with it: every case drives the REAL generator as a REAL
subprocess against a STAGED repository, and pipes its real output into the REAL vendored linter.

THE FIVE ARMS, AND WHY THEY ARE KEPT APART.
  * § 1 CONTROL — the generator's own output passes `bin/pr-body-lint.py` clean. This is the
    claim; everything else exists so that a green here means something.
  * § 2 RED — one mutation per case, applied to that same passing body, each naming the ONE rule
    it must provoke. Without this arm § 1 is consistent with a linter that passes everything,
    which is the exact failure mode a vendored judge can drift into.
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


def lint(body: str) -> tuple[int, dict]:
    proc = subprocess.run([sys.executable, str(LINT), "--body-file=-", "--json"],
                          input=body, capture_output=True, text=True)
    if proc.returncode not in (0, 1):
        raise AssertionError(f"pr-body-lint could not judge the body (rc {proc.returncode}): "
                             f"{proc.stderr}")
    return proc.returncode, json.loads(proc.stdout)


def rules(body: str) -> list[str]:
    _, verdict = lint(body)
    return sorted({f["rule"] for f in verdict["findings"]})


# ── § 1 CONTROL — the generator's output meets the standard ───────────────────────────────────
print("\n§ 1 CONTROL — the generated skeleton is judged by the real vendored linter")

WORK = stage()
run = generate(WORK, "--agent", "implemented by the mezzanine `coder` subagent",
               "--session-url", "https://claude.ai/code/session_EXAMPLE")
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
for what, mutate, want in MUTANTS:
    eq(f"{what} → reds", want, rules(mutate(BODY)))

# META-CONTROL: the suite above proves each mutation reds; this proves the UNMUTATED body is what
# was being mutated — a `plant()` anchor that stopped matching would have raised, but an anchor
# that matched a DIFFERENT occurrence would not.
eq("META-CONTROL: the body under mutation is still the clean one", [], rules(BODY))


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
# The same shape across the other one-line fields — the sibling audit, driven rather than argued.
refused("an `--agent` spanning lines is refused, not welded into the trailer",
        generate(WORK, "--agent", "the coder\nFROM: somebody"),
        "--agent spans more than one line")
refused("a `--session-url` spanning lines likewise",
        generate(WORK, "--session-url", "https://example.invalid/a\nhttps://example.invalid/b"),
        "--session-url spans more than one line")

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

# ⛔ THE PASS-THROUGH RESIDUE, MEASURED RATHER THAN CLAIMED. The header says the SKELETON passes
# and that `--agent` / `--session-url` are unjudged; these two cases are what makes that
# qualification a fact. They must NOT be "fixed" by validating the fields here — the emit/judge
# separation is the design, and the lint step is the answer.
poisoned = generate(WORK, "--agent", "CI is green at 4f2a91c")
eq("an `--agent` carrying a live-state reading passes THROUGH and reds the linter",
   ["live-state-reading"], rules(poisoned.stdout))
poisoned_url = generate(WORK, "--session-url", "FROM: mezzanine-solo")
eq("a `--session-url` carrying an attribution line does the same",
   ["attribution-line"], rules(poisoned_url.stdout))
contains("  … which is why the checklist names the fields as unjudged",
         "passed through UNJUDGED", run.stderr)
no_url = generate(WORK)
contains("a missing --session-url is NAMED rather than invented",
         "NO session URL", no_url.stderr)
absent("  … and no URL is fabricated in the body", "claude.ai/code/session", no_url.stdout)
contains("the producing agent is recorded beside the model line, not as a `FROM:` line",
         "[Claude Code](https://claude.com/claude-code) — implemented by the mezzanine", BODY)


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
    first_row = next((i for i, line in enumerate(body_lines) if ROW_RE.match(line)), None)
    if first_row is None:
        bad("the `change-pr-body:house-map` block contains NO row matching the row grammar — "
            "the map an author reads has become prose nothing grades")
        tail = []
    else:
        tail = body_lines[first_row:]
    eq("every line after the block's first row IS a row (a mistyped one would vanish, not fail)",
       [], [line for line in tail if not ROW_RE.match(line)])

    rows = [line.strip() for line in tail if ROW_RE.match(line)]
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

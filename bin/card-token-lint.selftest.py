#!/usr/bin/env python3
"""card-token-lint.selftest.py — hermetic, network-free acceptance for bin/card-token-lint.py.

WHY THIS FILE EXISTS. `card-token-lint.py` is a MERGE GATE, and a gate is worth exactly as
much as the evidence that it can fail. Both of its failure directions are silent:
  - a FALSE NEGATIVE (it waves through a token the correlator drops) restores the very
    silent-no-op class the lint was built to close, and
  - a FALSE POSITIVE (it reds an ordinary English title) trains the next author to switch the
    gate off.
So nothing here asserts on an exit code alone: every block drives the REAL script as a REAL
subprocess — the same surface the workflow uses — over fixtures whose verdicts are stated.

RED-FIRST. § 7 is the meta-control: the whole accept corpus is re-run against a deliberately
WRONG grammar (the pre-widening hash-only spelling) and must go RED. Without it, § 2 passing
would be consistent with a lint that accepts everything.

§ 8 leaves the lint and checks its CALLER — the workflow line that invokes it — because two
of the hazards here live in the invocation, not the code, and both are silent.

NO NETWORK, NO CREDENTIAL, NO BOARD. This runs on a public runner.
"""
from __future__ import annotations

import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
LINT = REPO / "bin" / "card-token-lint.py"
MOVER = REPO / "bin" / "promote-cards-by-token"

fails = 0


def ok(msg: str) -> None:
    print(f"  ok   {msg}")


def bad(msg: str) -> None:
    global fails
    fails += 1
    print(f"  FAIL {msg}", file=sys.stderr)


def eq(what: str, want, got) -> None:
    ok(what) if want == got else bad(f"{what} — expected {want!r} got {got!r}")


def run(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, str(LINT), *args],
                          capture_output=True, text=True, cwd=str(REPO))


def stub_authority(pattern: str) -> Path:
    """A throwaway file carrying ONE `CARD_RE='…'` line, for --grammar-source."""
    d = Path(tempfile.mkdtemp())
    p = d / "stub-mover"
    p.write_text(f"#!/usr/bin/env bash\nCARD_RE='{pattern}'\n", encoding="utf-8")
    return p


print("== 1. Grammar extraction is real, and every failure of it is LOUD (exit 2) ==")
# RED when: load_accept_pattern grows a fallback literal, or stops distinguishing 2 from 1.
r = run("--branch", "feat/card-7343-thing")
eq("the real mover's CARD_RE extracts and the lint runs", 0, r.returncode)
eq("  … and the run PRINTS the grammar it used (no silent verdict)",
   True, "grammar: " in r.stdout)
eq("  … which is the mover's line, not a literal in the lint",
   True, r"\bcard([-#][0-9]+|[0-9]{2,})" in r.stdout)

r = run("--branch", "x", "--grammar-source", "/nonexistent/mover")
eq("missing authority → exit 2, not 0 and not 1", 2, r.returncode)
eq("  … and says so", True, "grammar authority not found" in r.stderr)

nogrammar = Path(tempfile.mkdtemp()) / "no-card-re"
nogrammar.write_text("#!/usr/bin/env bash\nOTHER_RE='x'\n", encoding="utf-8")
r = run("--branch", "x", "--grammar-source", str(nogrammar))
eq("authority present but line renamed → exit 2", 2, r.returncode)
eq("  … and refuses to hardcode instead", True, "do NOT hardcode" in r.stderr)

dupe = Path(tempfile.mkdtemp()) / "two-card-re"
dupe.write_text("CARD_RE='a'\nCARD_RE='b'\n", encoding="utf-8")
r = run("--branch", "x", "--grammar-source", str(dupe))
eq("TWO authority lines → exit 2 (ambiguous, never 'pick one')", 2, r.returncode)

posix = stub_authority(r"\bcard[[:digit:]]+")
r = run("--branch", "x", "--grammar-source", str(posix))
eq("a POSIX bracket class in the ACCEPT grammar → exit 2, never a guessed translation",
   2, r.returncode)
eq("  … and names the class it refused", True, "[:digit:]" in r.stderr)

print("== 2. ACCEPT set — spellings a correlator parses must PASS on both surfaces ==")
# RED when: the accept test stops reading the extracted grammar, or anchors wrongly.
ACCEPT = [
    "card#7343",            # canonical
    "card-7343",            # branch-ergonomic alias, equal authority
    "card7343",             # glued, >= 2 digits
    "card#3054_fix",        # left-anchored only: a suffix is irrelevant
    "CARD#7343",            # the grammar is case-insensitive
    "card-12",              # exactly two digits, the floor
]
for tok in ACCEPT:
    for surface in ("--branch", "--title"):
        r = run(surface, f"work on {tok} today")
        eq(f"{tok!r} passes on {surface}", 0, r.returncode)

print("== 3. NEAR-MISS set — card-ish but unparseable must FAIL (exit 1) with a fix-it ==")
# RED when: CARDISH_RE narrows below the accept grammar, or the whole-token pin is dropped.
NEAR_MISS = {
    "cards #7343": "7343",   # plural + space-hash: the shape the mover's own probe MISSES
    "card #7343": "7343",    # spaced
    "card_7343": "7343",     # underscore
    "card:7343": "7343",     # colon
    "card.7343": "7343",     # dot
    "card4": "4",            # single-digit glued: below the two-digit floor
    "card-#7343": "7343",    # two separator units, neither arm of the accept grammar
    "card#٣": "",       # Unicode digit: card-ish (detector keeps \d) but ASCII-only accept
}
for tok, digits in NEAR_MISS.items():
    r = run("--title", f"fix the thing ({tok})")
    eq(f"{tok!r} FAILS", 1, r.returncode)
    eq(f"  … {tok!r} is annotated for Actions", True, "::error::card-token-lint:" in r.stdout)
    want = f"card-{digits or '<id>'}"
    eq(f"  … and the hint names a spelling that PARSES ({want})", True, want in r.stdout)

print("== 4. The detector is a strict SUPERSET of the mover's own near-miss probe ==")
# The binding this file exists to make mechanical: anything the MOVER would warn about at
# release time must already have been REJECTED here at PR time. Both patterns are EXTRACTED,
# so neither side can be widened or narrowed without this going red.
mover_src = MOVER.read_text(encoding="utf-8")
accept_lit = re.search(r"^CARD_RE='([^']*)'\s*$", mover_src, re.M)
near_lit = re.search(r"^NEAR_MISS_RE='([^']*)'\s*$", mover_src, re.M)
eq("the mover's CARD_RE is extractable", True, accept_lit is not None)
eq("the mover's NEAR_MISS_RE is extractable", True, near_lit is not None)
if accept_lit and near_lit:
    # ONE POSIX construct is translated, EXPLICITLY: `[[:space:]]` → `\s`. This is the
    # DETECTOR side, where a slightly broader Python class is the safe direction (it can only
    # make this repo's gate flag MORE); the lint itself refuses to translate the ACCEPT
    # grammar at all, for the opposite reason. Anything else POSIX-shaped fails here loudly
    # rather than being silently mistranslated.
    def to_python(ere: str) -> str:
        out = ere.replace("[[:space:]]", r"\s")
        leftover = re.findall(r"\[:[a-z]+:\]", out)
        assert not leftover, f"untranslated POSIX class {leftover} in {ere!r}"
        return out

    mover_accept = re.compile(to_python(accept_lit.group(1)), re.I)
    mover_near = re.compile(to_python(near_lit.group(1)), re.I)
    sys.path.insert(0, str(REPO / "bin"))
    import importlib.util
    spec = importlib.util.spec_from_file_location("ctl", LINT)
    ctl = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(ctl)

    # The corpus is DERIVED from the fixtures above plus separator/digit combinations, not
    # hand-listed as verdicts: membership is "the mover would warn", computed here.
    corpus = list(ACCEPT) + list(NEAR_MISS) + [
        f"card{sep}{n}" for sep in ("", "-", "#", "_", ":", ".", " #", "\t#")
        for n in ("1", "12", "7343")
    ] + ["discard 5 items", "wildcards 3", "card 2 in prose", "#123", "card2go"]
    warned = [s for s in corpus if mover_near.search(s) and not mover_accept.search(s)]
    eq("the mover's probe warns about SOMETHING in the corpus (a control on this check)",
       True, len(warned) > 0)
    missed = [s for s in warned if not ctl.CARDISH_RE.search(s)]
    eq("every string the MOVER would warn about is card-ish HERE (superset holds)",
       [], missed)

print("== 5. PROSE STAYS SILENT — a false positive is how a gate gets switched off ==")
# RED when: a bare space becomes a separator, or the left `\b` anchor is dropped.
for quiet in ["supports card 2 in prose", "discard 5 items", "wildcards 3",
              "fixes #123", "refactor the dashboard", "feat/pixi-floor-camera", ""]:
    r = run("--title", quiet)
    eq(f"{quiet!r} passes (no card-ish token)", 0, r.returncode)
r = run()
eq("no surfaces at all → clean, not a crash", 0, r.returncode)

print("== 6. PER-OCCURRENCE — a good token beside a bad one does NOT rescue the bad one ==")
# RED when: the check goes per-string and suppresses on a co-present parseable token.
r = run("--title", "floor camera (card#7343, cards #7344, card_7345)")
eq("a rescued-by-luck title still FAILS", 1, r.returncode)
eq("  … and BOTH lost tokens are named, not just the first",
   2, r.stdout.count("::error::card-token-lint:"))
# The complement, stated so the count above cannot be read as "every #N is a card": a BARE
# `#7345` is a PR reference, not a card-ish token, and is correctly invisible here.
r = run("--title", "floor camera (cards #7344/#7345)")
eq("  … while a bare '#N' beside a card-ish token is NOT counted as a second one",
   1, r.stdout.count("::error::card-token-lint:"))

print("== 7. META-CONTROL — the verdict is driven by the EXTRACTED grammar, not by luck ==")
# Re-run § 2's accept corpus against the PRE-WIDENING hash-only grammar. If the lint were
# vacuous (accepting everything), these would still pass — they must not.
old = stub_authority(r"\bcard#([0-9]+)")
reds = sorted(tok for tok in ACCEPT
              if run("--title", f"work on {tok} today",
                     "--grammar-source", str(old)).returncode == 1)
# EXACTLY the three shapes the pre-widening hash-only grammar could not parse: the `card-`
# alias and the glued form. Asserted as a SET, not a count — a count would still pass if the
# lint reddened the wrong three. `card#3054_fix` is green here on purpose: the card-ish token
# ends at the digits, so what is judged is `card#3054`, which that grammar does parse.
eq("under a NARROWER grammar the same accept corpus goes RED (so § 2 was not vacuous)",
   ["card-12", "card-7343", "card7343"], reds)

print("== 8. The WORKFLOW invokes the lint safely — two measured hazards, pinned ==")
# This block leaves the lint and checks its CALLER, because both hazards live there and both
# are silent: an injected title executes, and a `--`-leading title reds the gate with an
# argparse error naming nothing the author did wrong (measured while writing this).
wf = (REPO / ".github/workflows/card-token-lint.yml").read_text(encoding="utf-8")
run_lines = [ln for ln in wf.splitlines() if "card-token-lint.py" in ln and "run:" in ln]
eq("exactly one workflow line invokes the lint", 1, len(run_lines))
if run_lines:
    call = run_lines[0]
    eq("  … via the --opt=VALUE form (a title of '--help' is a VALUE, not an option)",
       True, "--branch=" in call and "--title=" in call)
    eq("  … reading env vars, never interpolating ${{ }} into the shell (injection sink)",
       True, "${{" not in call)
    # CONTROL: the assertions above must be capable of the other answer.
    bad = 'run: python3 bin/card-token-lint.py --branch "${{ github.event.pull_request.head.ref }}"'
    eq("  … and those two checks REJECT the unsafe spelling (control)",
       (False, False), ("--branch=" in bad and "--title=" in bad, "${{" not in bad))
eq("the workflow passes the title through env:", True, "PR_TITLE: ${{" in wf)
eq("the workflow passes the head ref through env:", True, "HEAD_REF: ${{" in wf)

print()
if fails:
    print(f"card-token-lint.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("card-token-lint.selftest: all checks passed")

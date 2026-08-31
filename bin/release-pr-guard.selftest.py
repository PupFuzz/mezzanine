#!/usr/bin/env python3
"""release-pr-guard.selftest.py — hermetic, network-free acceptance for bin/release-pr-guard.py.

WHY THIS FILE EXISTS. `release-pr-guard.py` is a merge gate, and a gate is worth exactly as
much as the evidence that it can fail. Its two failure directions are both silent and both
expensive:
  - a FALSE NEGATIVE restores the 2026-08-30 state — a release PR merging green with no bump,
    no changelog section and the integration branch as its head — which is not noticed until
    the tagger fails AFTER the merge, and
  - a FALSE POSITIVE reds a legitimate release and trains the next author to switch it off.
So nothing here asserts on an exit code alone in isolation: every arm below is a SINGLE-VARIABLE
mutation of one well-formed control (§ 3), driven through the REAL script as a REAL subprocess
against a REAL git repository. That construction is what makes the reds evidence rather than a
check that always fails — if § 3 goes red, every red below it means nothing.

REAL GIT, NOT A STUBBED READER. The guard reads both sides' content with `git show`, so the
fixtures are actual `git init` repos with actual commits: the same surface CI drives. A fake
content reader would have hidden the two hazards § 6 measures — an unresolvable base rev and a
path missing at a revision are different failures and only git tells them apart.

REAL AUTHORITIES, COPIED IN. Each fixture repo carries the repo's own `.release-pr.json` and
`.github/workflows/auto-tag-version.yml`, so the extraction under test is the extraction that
runs in CI. § 1 then mutates those copies to prove every extraction failure is LOUD.

§ 9 is the one that counts: PR #38's real shape, reconstructed, refused on all three rules.

NO NETWORK, NO CREDENTIAL, NO BOARD. This runs on a public runner.
"""
from __future__ import annotations

import importlib.util
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GUARD = REPO / "bin" / "release-pr-guard.py"
WORKFLOW = REPO / ".github" / "workflows" / "release-pr-guard.yml"
AUTHORITIES = (".release-pr.json", ".github/workflows/auto-tag-version.yml")

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
    return subprocess.run([sys.executable, str(GUARD), *args],
                          capture_output=True, text=True, cwd=str(REPO))


# --- Fixture construction ---------------------------------------------------------------------

UNRELEASED_ONLY = "# Changelog\n\n## [Unreleased]\n\n- nothing yet\n"


def changelog_with(version: str) -> str:
    """A changelog in the shape release flow step 4 leaves behind: a fresh empty `[Unreleased]`
    above the section the release just retitled."""
    return (f"# Changelog\n\n## [Unreleased]\n\n## [{version}] - 2026-08-30\n\n"
            f"- **card#8174** — a line.\n")


def git(repo: Path, *args: str) -> None:
    r = subprocess.run(["git", "-C", str(repo), *args], capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit(f"fixture git failed: {' '.join(args)}\n{r.stderr}")


def make_repo(*, base_version: str | None = "0.1.0",
              head_version: str | None = "0.2.0",
              head_changelog: str | None = None,
              base_branch: str = "main",
              head_branch: str = "release/v0.2.0") -> Path:
    """A two-branch fixture repo carrying this repo's real authority files.

    `None` for a version or the changelog means the FILE IS ABSENT at that side — the
    unmeasurable states § 6 exercises, which is why they are expressed as absence rather than
    as an empty string.
    """
    repo = Path(tempfile.mkdtemp(prefix="relguard-fx-"))
    git(repo, "init", "-q", "-b", base_branch)
    git(repo, "config", "user.email", "selftest@example.invalid")
    git(repo, "config", "user.name", "selftest")
    for rel in AUTHORITIES:
        dst = repo / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(REPO / rel, dst)
    (repo / "docs").mkdir(exist_ok=True)

    if base_version is not None:
        (repo / "VERSION").write_text(base_version + "\n", encoding="utf-8")
    (repo / "docs" / "CHANGELOG.md").write_text(UNRELEASED_ONLY, encoding="utf-8")
    git(repo, "add", "-A")
    git(repo, "commit", "-qm", "base")

    git(repo, "checkout", "-q", "-b", head_branch)
    if head_version is None:
        if (repo / "VERSION").exists():
            (repo / "VERSION").unlink()
    else:
        (repo / "VERSION").write_text(head_version + "\n", encoding="utf-8")
    if head_changelog is None:
        (repo / "docs" / "CHANGELOG.md").unlink()
    else:
        (repo / "docs" / "CHANGELOG.md").write_text(head_changelog, encoding="utf-8")
    git(repo, "add", "-A")
    git(repo, "commit", "-qm", "head", "--allow-empty")
    return repo


def guard(repo: Path, *, base_ref: str = "main", head_ref: str = "release/v0.2.0",
          base_rev: str = "main", head_rev: str = "HEAD") -> subprocess.CompletedProcess:
    return run("--repo", str(repo), "--base-ref", base_ref, "--head-ref", head_ref,
               "--base-rev", base_rev, "--head-rev", head_rev)


def rules_flagged(r: subprocess.CompletedProcess) -> list[str]:
    """Which of R1/R2/R3 the run actually named — an exit code alone would not distinguish a
    guard that reds for the right reason from one that reds for any reason."""
    return sorted(set(re.findall(r"::error::release-pr-guard: (R[123])", r.stdout)))


# =============================================================================================
print("== 1. Every AUTHORITY extraction is real, and every failure of it is LOUD (exit 2) ==")
# RED when: an extraction grows a fallback literal, or stops distinguishing "moved" from "fine".

fx = make_repo(head_changelog=changelog_with("0.2.0"))
r = guard(fx)
eq("the real authorities extract and the guard runs", 0, r.returncode)
eq("  … and the run PRINTS the release branch it derived (no silent verdict)",
   True, "release branch 'main' <- .github/workflows/auto-tag-version.yml" in r.stdout)
eq("  … the tag_format it derived", True, "tag_format 'v{{version}}'" in r.stdout)
eq("  … and the accepted VERSION spelling it derived",
   True, r"accepted VERSION `^[0-9]+\.[0-9]+\.[0-9]+" in r.stdout)

# The tagger workflow is BOTH the release-branch authority and the VERSION-spelling authority,
# so its absence must be loud, not a shrug that falls back to "main" and a private semver.
fx = make_repo(head_changelog=changelog_with("0.2.0"))
(fx / ".github/workflows/auto-tag-version.yml").unlink()
r = guard(fx)
eq("tagger workflow absent → exit 2, not 0 and not 1", 2, r.returncode)
eq("  … and says the authority is missing", True, "authority not found" in r.stderr)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
p = fx / ".github/workflows/auto-tag-version.yml"
p.write_text(p.read_text().replace("    branches: [main]", "    branches:\n      - main"),
             encoding="utf-8")
r = guard(fx)
eq("tagger trigger reformatted (block no longer matches) → exit 2", 2, r.returncode)
eq("  … and refuses to hardcode the branch name",
   True, "do NOT hardcode the branch name here" in r.stderr)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
p = fx / ".github/workflows/auto-tag-version.yml"
p.write_text(p.read_text().replace("branches: [main]", "branches: [main, release]"),
             encoding="utf-8")
r = guard(fx)
eq("two release branches → exit 2 (the guard refuses to pick)", 2, r.returncode)
eq("  … and says a human decides", True, "refuses to pick" in r.stderr)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
p = fx / ".github/workflows/auto-tag-version.yml"
p.write_text(p.read_text().replace("grep -qE", "grep -q"), encoding="utf-8")
r = guard(fx)
eq("VERSION validation renamed away → exit 2", 2, r.returncode)
eq("  … and names WHY a private copy is refused (post-merge red)",
   True, "would green a release the tagger then reds after the merge" in r.stderr)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
p = fx / ".github/workflows/auto-tag-version.yml"
p.write_text(p.read_text() + "\n#          extra: grep -qE '^x$'\n", encoding="utf-8")
r = guard(fx)
eq("VERSION validation DUPLICATED → exit 2 (ambiguity is not a pass)", 2, r.returncode)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
p = fx / ".github/workflows/auto-tag-version.yml"
p.write_text(p.read_text().replace("[0-9]+\\.[0-9]+\\.[0-9]+",
                                   "[[:digit:]]+\\.[0-9]+\\.[0-9]+"), encoding="utf-8")
r = guard(fx)
eq("POSIX bracket class in the VERSION grammar → exit 2, not a guessed translation",
   2, r.returncode)
eq("  … and says it refuses to translate", True, "Refusing to guess a translation" in r.stderr)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
(fx / ".release-pr.json").write_text("{not json", encoding="utf-8")
r = guard(fx)
eq("unparseable .release-pr.json → exit 2", 2, r.returncode)

fx = make_repo(head_changelog=changelog_with("0.2.0"))
(fx / ".release-pr.json").write_text('{"promote": {}}', encoding="utf-8")
r = guard(fx)
eq("`tag_format` missing → exit 2, no fallback 'v'+version", 2, r.returncode)
eq("  … and says there is no second copy", True, "no second copy" in r.stderr)


# =============================================================================================
print("== 2. APPLICABILITY — a PR into any other base is untouched and passes trivially ==")
# The gate must be inert off the release path. Deliberately fed a fixture that violates ALL
# THREE rules: if applicability ever leaks, this goes red, which is the point of using the
# worst possible input for the pass case.
fx = make_repo(base_version="0.1.0", head_version="0.1.0", head_changelog=UNRELEASED_ONLY)
r = guard(fx, base_ref="dev", head_ref="dev", head_rev="HEAD")
eq("base=dev with a triple-violating tree → exit 0", 0, r.returncode)
eq("  … and says NOT APPLICABLE rather than OK", True, "NOT APPLICABLE" in r.stdout)
eq("  … and asserts nothing", [], rules_flagged(r))
r = guard(fx, base_ref="feature/stacked", head_ref="feature/leaf")
eq("a stacked PR (base is a feature branch) → exit 0", 0, r.returncode)
# CONTROL for the two above: the SAME tree judged on the release path must go red, or the
# passes above would be evidence of nothing.
r = guard(fx, base_ref="main", head_ref="dev")
eq("  CONTROL: the same tree with base=main goes RED on all three", 1, r.returncode)
eq("  … naming R1, R2 and R3", ["R1", "R2", "R3"], rules_flagged(r))
# `refs/heads/` prefixes are normalised, so a caller passing a full ref does not silently
# become "not the release branch" and get waved through.
r = guard(fx, base_ref="refs/heads/main", head_ref="refs/heads/dev")
eq("a full refs/heads/ base ref is still recognised as the release branch", 1, r.returncode)


# =============================================================================================
print("== 3. THE CONTROL — a well-formed release PR PASSES (so every red below is evidence) ==")
CONTROL = dict(base_version="0.1.0", head_version="0.2.0",
               head_changelog=changelog_with("0.2.0"))
fx = make_repo(**CONTROL)
r = guard(fx, base_ref="main", head_ref="release/v0.2.0")
eq("well-formed release PR → exit 0", 0, r.returncode)
eq("  … with no rule flagged", [], rules_flagged(r))
eq("  … and it says what it did NOT check (deploy/wire verdicts, bump SIZE)",
   True, "says nothing about the deploy verdict" in r.stdout)
if r.returncode != 0:
    print(r.stdout, r.stderr, file=sys.stderr)


# =============================================================================================
print("== 4. R1 — HEAD BRANCH SHAPE, one variable at a time off § 3's control ==")
fx = make_repo(**CONTROL)
r = guard(fx, head_ref="dev")
eq("head is the integration branch → RED", 1, r.returncode)
eq("  … R1 alone (R2 and R3 still hold — the mutation was single-variable)",
   ["R1"], rules_flagged(r))
eq("  … and the message names the delete-on-merge hazard, not just a name mismatch",
   True, "delete_branch_on_merge` ENABLED" in r.stdout)

r = guard(fx, head_ref="feat/card-8174-thing")
eq("head is an ordinary feature branch → RED on R1", ["R1"], rules_flagged(r))

r = guard(fx, head_ref="release/0.2.0")
eq("head drops the tag_format's `v` → RED on R1 (the form is DERIVED, not eyeballed)",
   ["R1"], rules_flagged(r))

r = guard(fx, head_ref="release/v0.3.0")
eq("head names a DIFFERENT version than VERSION ships → RED on R1", ["R1"], rules_flagged(r))
eq("  … and says the two statements of one fact disagree",
   True, "two statements of one fact" in r.stdout)

r = guard(fx, head_ref="release/v0.2.0-suffix")
eq("head is the right branch plus a suffix → RED on R1 (equality, not prefix match)",
   ["R1"], rules_flagged(r))

# R1 is DERIVED from tag_format: change the authority, and the expected branch follows with no
# edit to the guard. This is the assertion that would go red if anyone hardcoded `release/v`.
fx2 = make_repo(**CONTROL, head_branch="release/mezzanine-0.2.0")
p = fx2 / ".release-pr.json"
p.write_text(p.read_text().replace('"tag_format": "v{{version}}"',
                                   '"tag_format": "mezzanine-{{version}}"'), encoding="utf-8")
r = guard(fx2, head_ref="release/mezzanine-0.2.0")
eq("under a CHANGED tag_format the guard expects the new form and passes it", 0, r.returncode)
r = guard(fx2, head_ref="release/v0.2.0")
eq("  … and the OLD form now goes RED (so R1 tracks the authority, not a literal)",
   ["R1"], rules_flagged(r))


# =============================================================================================
print("== 5. R2 — VERSION BUMPED, including the exact #38 equality ==")
fx = make_repo(base_version="0.1.0", head_version="0.1.0",
               head_changelog=changelog_with("0.1.0"), head_branch="release/v0.1.0")
r = guard(fx, head_ref="release/v0.1.0")
eq("VERSION unchanged (the #38 defect) → RED", 1, r.returncode)
eq("  … R2 alone", ["R2"], rules_flagged(r))
eq("  … and the message names the AFTER-the-merge failure it prevents",
   True, "fails AFTER the merge" in r.stdout)

fx = make_repo(base_version="0.2.0", head_version="0.1.0",
               head_changelog=changelog_with("0.1.0"), head_branch="release/v0.1.0")
r = guard(fx, head_ref="release/v0.1.0")
eq("VERSION moved BACKWARDS → RED on R2", ["R2"], rules_flagged(r))
eq("  … and says tags are immutable rather than repeating the 'unchanged' text",
   True, "BACKWARDS" in r.stdout)

fx = make_repo(base_version="0.1.9", head_version="0.1.10",
               head_changelog=changelog_with("0.1.10"), head_branch="release/v0.1.10")
r = guard(fx, head_ref="release/v0.1.10")
eq("0.1.10 > 0.1.9 — numeric, not lexical (a string compare would red this)", 0, r.returncode)

fx = make_repo(base_version="0.2.0-rc.1", head_version="0.2.0",
               head_changelog=changelog_with("0.2.0"))
r = guard(fx, head_ref="release/v0.2.0")
eq("0.2.0 > 0.2.0-rc.1 end-to-end (prerelease ranking is wired, not just unit-tested)",
   0, r.returncode)
fx = make_repo(base_version="0.2.0", head_version="0.2.0-rc.1",
               head_changelog=changelog_with("0.2.0-rc.1"), head_branch="release/v0.2.0-rc.1")
r = guard(fx, head_ref="release/v0.2.0-rc.1")
eq("  … and the reverse goes RED on R2 (the ranking discriminates)", ["R2"], rules_flagged(r))

# The ordering itself, against semver.org § 11's own worked chain. Imported rather than driven
# through eight fixture repos: the two end-to-end cases above prove the wiring, this proves the
# spec compliance the wiring depends on.
spec = importlib.util.spec_from_file_location("relguard", GUARD)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
CHAIN = ["1.0.0-alpha", "1.0.0-alpha.1", "1.0.0-alpha.beta", "1.0.0-beta", "1.0.0-beta.2",
         "1.0.0-beta.11", "1.0.0-rc.1", "1.0.0"]
keys = [mod.precedence_key(v) for v in CHAIN]
eq("semver.org § 11's worked chain is strictly increasing",
   True, all(a < b for a, b in zip(keys, keys[1:])))
# CONTROL: a comparison that must be FALSE, so "strictly increasing" is not vacuously true of
# a key function that returns a constant.
eq("  CONTROL: the chain is NOT increasing in reverse (the key discriminates)",
   False, all(a < b for a, b in zip(keys[::-1], keys[::-1][1:])))
eq("  … 1.0.0 outranks every prerelease of it",
   True, all(mod.precedence_key("1.0.0") > k for k in keys[:-1]))
eq("  … 0.10.0 outranks 0.9.0 (numeric core, not lexical)",
   True, mod.precedence_key("0.10.0") > mod.precedence_key("0.9.0"))


# =============================================================================================
print("== 6. R3 — CHANGELOG SECTION for the new version ==")
fx = make_repo(base_version="0.1.0", head_version="0.2.0", head_changelog=UNRELEASED_ONLY)
r = guard(fx, head_ref="release/v0.2.0")
eq("only `## [Unreleased]` (the #38 changelog) → RED", 1, r.returncode)
eq("  … R3 alone", ["R3"], rules_flagged(r))
eq("  … and says an [Unreleased] heading does not count",
   True, "does not count" in r.stdout)

fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog="# Changelog\n\n## [Unreleased] 0.2.0\n\n- a line.\n")
r = guard(fx, head_ref="release/v0.2.0")
eq("the version named INSIDE the Unreleased heading → still RED on R3",
   ["R3"], rules_flagged(r))

fx = make_repo(base_version="0.1.0", head_version="0.2.1",
               head_changelog=changelog_with("0.2.10"), head_branch="release/v0.2.1")
r = guard(fx, head_ref="release/v0.2.1")
eq("a `0.2.10` section does NOT discharge `0.2.1` (token boundaries, not substring)",
   ["R3"], rules_flagged(r))

fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog="# Changelog\n\n## v0.2.0\n\n- a line.\n")
r = guard(fx, head_ref="release/v0.2.0")
eq("a `## v0.2.0` heading satisfies R3 (the FORMAT is docs/PLAN.md § 4's, not this gate's)",
   0, r.returncode)
# The asymmetric boundary's other side, both directions. A letter LEFT of the version is the
# legitimate `v` prefix; a digit left of it is a different version entirely.
fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog=changelog_with("10.2.0"))
r = guard(fx, head_ref="release/v0.2.0")
eq("  … but a `## [10.2.0]` section does NOT discharge `0.2.0` (digit-left still bounded)",
   ["R3"], rules_flagged(r))
fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog=changelog_with("0.2.0-rc.1"))
r = guard(fx, head_ref="release/v0.2.0")
eq("  … and a `## [0.2.0-rc.1]` section does NOT discharge `0.2.0` (letter/`-` right bounded)",
   ["R3"], rules_flagged(r))
fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog="# Changelog\n\n## 0.2.0 - 2026-08-30\n\n- a line.\n")
r = guard(fx, head_ref="release/v0.2.0")
eq("an unbracketed `## 0.2.0 - date` heading also satisfies R3", 0, r.returncode)
fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog="# Changelog\n\nsome prose mentioning 0.2.0 in a line.\n")
r = guard(fx, head_ref="release/v0.2.0")
eq("  CONTROL: the version in PROSE is not a section → RED on R3", ["R3"], rules_flagged(r))


# =============================================================================================
print("== 7. FAIL LOUD — every unmeasurable state is exit 2, never a pass ==")
fx = make_repo(head_version=None, head_changelog=changelog_with("0.2.0"))
r = guard(fx, head_ref="release/v0.2.0")
eq("VERSION absent at the HEAD → exit 2", 2, r.returncode)
eq("  … and refuses to guess one", True, "cannot guess one" in r.stderr)

fx = make_repo(base_version=None, head_changelog=changelog_with("0.2.0"))
r = guard(fx, head_ref="release/v0.2.0")
eq("VERSION absent on the RELEASE BRANCH → exit 2 (not 'no baseline, so anything is a bump')",
   2, r.returncode)

fx = make_repo(**CONTROL)
r = guard(fx, head_ref="release/v0.2.0", base_rev="origin/main")
eq("base rev unresolvable (the shallow-clone / never-fetched case) → exit 2", 2, r.returncode)
eq("  … and names the ref it could not resolve", True, "origin/main" in r.stderr)

fx = make_repo(head_version="0.2", head_changelog=changelog_with("0.2"),
               head_branch="release/v0.2")
r = guard(fx, head_ref="release/v0.2")
eq("VERSION unparseable at the head → exit 2, not a rule verdict", 2, r.returncode)
eq("  … and cites the tagger's own accepted spelling",
   True, "auto-tag-version.yml" in r.stderr)

fx = make_repo(base_version="banana", head_changelog=changelog_with("0.2.0"))
r = guard(fx, head_ref="release/v0.2.0")
eq("VERSION unparseable on the release branch → exit 2", 2, r.returncode)

fx = make_repo(head_changelog=None)
r = guard(fx, head_ref="release/v0.2.0")
eq("docs/CHANGELOG.md absent at the head → exit 2", 2, r.returncode)
eq("  … and says removing the file does not remove the obligation",
   True, "do not remove the obligation by removing the file" in r.stderr)

# CONTROL for this whole block: exit 2 must be DISTINGUISHABLE from exit 1, or the split that
# sends authors and maintainers to different files is decoration.
fx = make_repo(**CONTROL)
r = guard(fx, head_ref="dev")
eq("  CONTROL: a broken RULE is exit 1, never 2 (the two verdicts stay distinct)",
   1, r.returncode)


# =============================================================================================
print("== 8. The WORKFLOW invokes the guard safely, and its FILTERS are the measured ones ==")
wf = WORKFLOW.read_text(encoding="utf-8")
run_lines = [ln for ln in wf.splitlines() if "release-pr-guard.py" in ln and "run:" in ln]
eq("exactly one workflow line invokes the guard", 1, len(run_lines))
if run_lines:
    call = run_lines[0]
    eq("  … via the --opt=VALUE form (a ref of '--help' is a VALUE, not an option)",
       True, "--base-ref=" in call and "--head-ref=" in call)
    eq("  … reading env vars, never interpolating ${{ }} into the shell (injection sink)",
       True, "${{" not in call)
    unsafe = 'run: python3 bin/release-pr-guard.py --base-ref "${{ github.event.pull_request.base.ref }}"'
    eq("  … and those two checks REJECT the unsafe spelling (control)",
       (False, False), ("--base-ref=" in unsafe and "--head-ref=" in unsafe, "${{" not in unsafe))
eq("the workflow passes the base ref through env:", True, "BASE_REF: ${{" in wf)
eq("the workflow passes the head ref through env:", True, "HEAD_REF: ${{" in wf)
# `edited` is load-bearing here for a DIFFERENT reason than in card-token-lint.yml: `edited`
# fires when a PR's BASE is changed, and a PR retargeted from dev to main is the one transition
# that turns this gate on. Without it, that PR is judged by a run that decided NOT APPLICABLE.
eq("subscribes to `edited` (a base change is how a PR enters this gate's scope)",
   True, bool(re.search(r"^\s*types:.*\bedited\b", wf, re.M)))
eq("subscribes to `synchronize` (a push is how the author fixes R2/R3)",
   True, bool(re.search(r"^\s*types:.*\bsynchronize\b", wf, re.M)))
# NO `branches:` filter — see the workflow's own comment. Under a filter, a PR into `dev`
# produces NO RUN, and docs/VERSIONING.md § Branch model states that a required check with no
# run reads as PENDING, not passed: the gate would deadlock every feature PR the day someone
# made it required. Applicability is decided IN the guard (§ 2) precisely so it can't.
eq("no `branches:` filter (a filtered required check reads as pending, never as passed)",
   False, bool(re.search(r"^\s*branches:", wf, re.M)))
eq("  CONTROL: that check can see a branches: filter when one is present (positive control)",
   True, bool(re.search(r"^\s*branches:", "on:\n  pull_request:\n    branches: [main]\n", re.M)))
eq("the workflow fetches the base ref before measuring against it",
   True, "git fetch" in wf)
eq("the workflow checks out the PR HEAD sha, not the merge ref",
   True, "pull_request.head.sha" in wf)
eq("the workflow runs THIS selftest before judging anybody's PR",
   True, "release-pr-guard.selftest.py" in wf)


# =============================================================================================
print("== 9. REPLAY — PR #38's real shape, refused on all three rules ==")
# Reconstructed from the merged PR: head `dev`, base `main`, VERSION 0.1.0 on BOTH sides, and a
# changelog carrying only `## [Unreleased]`. Hermetic here so it runs on a runner with no
# history; the same replay was also driven against the real commits (head 7139cd7, main
# 556ac3f) and produced the same three errors.
fx = make_repo(base_version="0.1.0", head_version="0.1.0",
               head_changelog=UNRELEASED_ONLY, head_branch="dev")
r = guard(fx, base_ref="main", head_ref="dev")
eq("PR #38's shape is REFUSED", 1, r.returncode)
eq("  … on all three rules, named", ["R1", "R2", "R3"], rules_flagged(r))
eq("  … R1: the head would have deleted the integration branch on merge",
   True, "delete_branch_on_merge` ENABLED" in r.stdout)
eq("  … R2: VERSION '0.1.0' both sides — UNCHANGED", True, "UNCHANGED" in r.stdout)
eq("  … R3: no released section at all", True, "released sections found: none" in r.stdout)
# CONTROL: fixing all three on the SAME fixture shape makes it pass, so the refusal above is a
# verdict about #38 and not about the fixture builder.
fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog=changelog_with("0.2.0"), head_branch="release/v0.2.0")
r = guard(fx, base_ref="main", head_ref="release/v0.2.0")
eq("  CONTROL: the same PR done correctly PASSES", 0, r.returncode)


print()
if fails:
    print(f"release-pr-guard.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("release-pr-guard.selftest: all checks passed")

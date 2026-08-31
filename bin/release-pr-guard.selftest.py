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

import atexit
import datetime as dt
import importlib.util
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GUARD = REPO / "bin" / "release-pr-guard.py"
WORKFLOW = REPO / ".github" / "workflows" / "release-pr-guard.yml"
# `bin/promote-cards-by-token` is here because R4 extracts the card-token grammar from it — the
# same line `bin/card-token-lint.py` reads. Copied in for the same reason as the other two: the
# extraction under test must be the extraction that runs in CI.
AUTHORITIES = (".release-pr.json", ".github/workflows/auto-tag-version.yml",
               "bin/promote-cards-by-token")

fails = 0

# Every fixture is a real git repo in a temp dir, and this file now builds about forty of them
# per run — several carrying a 600 KB changelog, all carrying a copy of the 44 KB mover. Left
# behind they are thousands of inodes per run on a shared box; the run that added § 11 filled
# this machine's /tmp inode table to 88%. Removed at exit rather than at each use so a failing
# arm can still be inspected mid-run, and `RELGUARD_KEEP_FIXTURES=1` keeps them for debugging.
FIXTURES: list[Path] = []


def _sweep_fixtures() -> None:
    if os.environ.get("RELGUARD_KEEP_FIXTURES"):
        print(f"release-pr-guard.selftest: kept {len(FIXTURES)} fixture dir(s) "
              f"(RELGUARD_KEEP_FIXTURES is set)")
        return
    for d in FIXTURES:
        shutil.rmtree(d, ignore_errors=True)


atexit.register(_sweep_fixtures)


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


def unreleased_with(*cards: str, extra: str = "") -> str:
    """A changelog whose `[Unreleased]` section carries a line-initial bullet per card."""
    body = "".join(f"- **card#{c}** — what card#{c} did.\n" for c in cards)
    return f"# Changelog\n\n## [Unreleased]\n\n{body}{extra}"


def git(repo: Path, *args: str, when: dt.datetime | None = None) -> None:
    env = None
    if when is not None:
        # Both dates pinned: R5's window is measured against the COMMITTER date, and a fixture
        # that set only the author date would look aged while testing nothing.
        env = dict(os.environ, GIT_AUTHOR_DATE=when.isoformat(),
                   GIT_COMMITTER_DATE=when.isoformat())
    r = subprocess.run(["git", "-C", str(repo), *args], capture_output=True, text=True, env=env)
    if r.returncode != 0:
        raise SystemExit(f"fixture git failed: {' '.join(args)}\n{r.stderr}")


def make_repo(*, base_version: str | None = "0.1.0",
              head_version: str | None = "0.2.0",
              head_changelog: str | None = None,
              base_changelog: str = UNRELEASED_ONLY,
              older_changelog: str | None = None,
              older_days_ago: int = 30,
              base_branch: str = "main",
              head_branch: str = "release/v0.2.0") -> Path:
    """A two-branch fixture repo carrying this repo's real authority files.

    `None` for a version or the head changelog means the FILE IS ABSENT at that side — the
    unmeasurable states § 7 exercises, which is why they are expressed as absence rather than
    as an empty string.

    `older_changelog` prepends one commit dated `older_days_ago` days back, which is how § 11
    puts a changelog size OUTSIDE R5's fourteen-day window: with it the growth R5 measures is
    head-minus-that, without it the file has no history before the cutoff and the growth is its
    whole size. Same head bytes, two verdicts — that difference IS the window.
    """
    repo = Path(tempfile.mkdtemp(prefix="relguard-fx-"))
    FIXTURES.append(repo)
    git(repo, "init", "-q", "-b", base_branch)
    git(repo, "config", "user.email", "selftest@example.invalid")
    git(repo, "config", "user.name", "selftest")
    (repo / "docs").mkdir(exist_ok=True)

    if older_changelog is not None:
        # A commit BEFORE the pre-window one, touching something else. It exists so that a
        # clone deep enough to reach `older` is still genuinely SHALLOW — without it, a
        # `--depth 3` clone of a three-commit history is a COMPLETE clone and git writes no
        # graft at all, which would have made § 11's "shallow but sufficient" arm assert
        # something false about itself. Measured, not assumed: that arm did not red under a
        # mutation that refuses every shallow clone, which is how the fixture's own claim was
        # caught.
        (repo / ".seed").write_text("seed\n", encoding="utf-8")
        git(repo, "add", "-A")
        git(repo, "commit", "-qm", "seed",
            when=dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=older_days_ago * 2))
        (repo / "docs" / "CHANGELOG.md").write_text(older_changelog, encoding="utf-8")
        git(repo, "add", "-A")
        git(repo, "commit", "-qm", "older",
            when=dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=older_days_ago))

    for rel in AUTHORITIES:
        dst = repo / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(REPO / rel, dst)
    if base_version is not None:
        (repo / "VERSION").write_text(base_version + "\n", encoding="utf-8")
    (repo / "docs" / "CHANGELOG.md").write_text(base_changelog, encoding="utf-8")
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


def shallow_clone(repo: Path, head_branch: str, base_branch: str = "main",
                  depth: int = 1) -> Path:
    """A truncated clone of a fixture. `depth` is the variable § 11 moves: R5 refuses only the
    shallow clone that cannot see past the cutoff, not every shallow clone."""
    dst = Path(tempfile.mkdtemp(prefix="relguard-shallow-")) / "clone"
    FIXTURES.append(dst.parent)
    r = subprocess.run(["git", "clone", "-q", "--depth", str(depth), "--branch", head_branch,
                        f"file://{repo}", str(dst)], capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit(f"fixture shallow clone failed:\n{r.stderr}")
    # The base fetch uses the SAME depth as the clone, for the reason the workflow's own comment
    # records: a shallower fetch writes a graft boundary that is global to history traversal and
    # would truncate the HEAD branch too. Hardcoding `--depth=1` here made the depth-3 arm below
    # fail — the fixture was faithfully reproducing the defect that measurement then found in
    # the workflow.
    git(dst, "fetch", "-q", "--no-tags", f"--depth={depth}", "origin",
        f"+refs/heads/{base_branch}:refs/remotes/origin/{base_branch}")
    return dst


def guard(repo: Path, *, base_ref: str = "main", head_ref: str = "release/v0.2.0",
          title: str = "", base_rev: str = "main",
          head_rev: str = "HEAD") -> subprocess.CompletedProcess:
    return run("--repo", str(repo), "--base-ref", base_ref, "--head-ref", head_ref,
               "--title", title, "--base-rev", base_rev, "--head-rev", head_rev)


def rules_flagged(r: subprocess.CompletedProcess) -> list[str]:
    """Which of R1-R5 the run actually named — an exit code alone would not distinguish a
    guard that reds for the right reason from one that reds for any reason."""
    return sorted(set(re.findall(r"::error::release-pr-guard: (R[1-5])", r.stdout)))


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
# R4 reads the PR TITLE, which on a fork PR is attacker-controlled free text — the one input
# here where `${{ }}` inline in a `run:` block is a live injection sink rather than a
# discipline. Asserted on the invocation line itself (the `${{` check above covers it), and
# separately that the title reaches the guard at all: a title that never arrives makes R4
# silently half-blind, which is a false-clean and not a failure anyone would see.
eq("the workflow passes the PR title through env: (R4's second surface)",
   True, "PR_TITLE: ${{" in wf)
if run_lines:
    eq("  … and hands it to the guard as --title= (option-safe, like the two refs)",
       True, "--title=" in run_lines[0])
# R5 measures fourteen days of history. At depth 1 the guard exits 2 on EVERY PR (§ 11), so
# this line is not an optimisation someone could quietly revert without noticing.
eq("the workflow checks out FULL history (R5 measures growth, which needs it)",
   True, bool(re.search(r"^\s*fetch-depth:\s*0\s*$", wf, re.M)))
eq("  CONTROL: that check rejects the depth-1 spelling it replaced",
   False, bool(re.search(r"^\s*fetch-depth:\s*0\s*$", "        with:\n          fetch-depth: 1\n",
                         re.M)))
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
# ⛔ …and does NOT pass `--depth` to that fetch. MEASURED: `git fetch --depth=1` re-shallows a
# repository cloned in full, and the boundary is global to history traversal — a six-commit head
# branch collapsed to two and `is-shallow-repository` flipped to true, which would have made R5
# exit 2 on every PR while `fetch-depth: 0` above looked like it had handled it. This assertion
# is the only thing standing between that one-word optimisation and a gate that always reds.
fetch_lines = [ln for ln in wf.splitlines()
               if "git fetch" in ln and ln.lstrip().startswith("run:")]
eq("exactly one fetch line", 1, len(fetch_lines))
if fetch_lines:
    eq("  … with NO --depth (a shallow fetch silently undoes the full checkout)",
       False, "--depth" in fetch_lines[0])
    eq("  … CONTROL: that check catches the spelling it replaced",
       True, "--depth" in "run: git fetch --no-tags --depth=1 origin \"+refs/heads/x:y\"")
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


# =============================================================================================
print("== 10. R4 — the card's changelog bullet, and every carve-out that lets one go ==")
# THE DEFECT THIS ARM GUARDS: on 2026-08-30 a sweep of `dev` found 30 cards named in commit
# subjects and 25 bulleted — card#7335 (the WHOLE fleet-reporter), #7455, #7456, #7457, #7521
# and #7929 had merged with no changelog entry. Nothing checked it. Every red below is a plant
# of that exact shape, and § 10's own control comes first so the reds are evidence.
# The fixture's own branch NAME is irrelevant to R4 — the guard is told the head REF as an
# argument (that is what a PR event carries) and reads content at `HEAD`. Every arm below sets
# the ref it means, so the dict fixes only the two sides' content and the base branch.
R4FX = dict(base_version="0.1.0", head_version="0.1.0", base_branch="dev")


def r4(repo, **kw):
    kw.setdefault("base_ref", "dev")
    kw.setdefault("head_ref", "card-8174-changelog-gate")
    kw.setdefault("base_rev", "dev")
    return guard(repo, **kw)


# --- THE CONTROL: a card-bearing feature PR that DID write its bullet passes.
fx = make_repo(**R4FX, head_changelog=unreleased_with("8174"))
r = r4(fx)
eq("a feature PR naming card#8174 WITH its bullet → exit 0", 0, r.returncode)
eq("  … with no rule flagged", [], rules_flagged(r))
if r.returncode != 0:
    print(r.stdout, r.stderr, file=sys.stderr)

# --- THE PLANT: the same PR with the bullet gone. The changelog is NOT empty — it carries some
# OTHER card's bullet — so this fails on the card's identity, not on "the file looks bare".
fx = make_repo(**R4FX, head_changelog=unreleased_with("7929"))
r = r4(fx)
eq("the bullet missing (another card's bullet present) → RED", 1, r.returncode)
eq("  … R4 alone (single-variable off the control above)", ["R4"], rules_flagged(r))
eq("  … and the message names the card and the exact line to add",
   True, "card#8174" in r.stdout and "`- **card#8174** — `" in r.stdout)
eq("  … and names the surface that created the obligation",
   True, "head ref 'card-8174-changelog-gate'" in r.stdout)

# --- The rule is LINE-INITIAL, which docs/PLAN.md § 4 says in as many words (their incident:
# a prose mention discharged another card's obligation). Both near-miss shapes must still red.
fx = make_repo(**R4FX,
               head_changelog="# Changelog\n\n## [Unreleased]\n\n  - **card#8174** — indented.\n")
r = r4(fx)
eq("an INDENTED bullet does not satisfy R4 (line-initial is the rule)", ["R4"], rules_flagged(r))
fx = make_repo(**R4FX,
               head_changelog="# Changelog\n\n## [Unreleased]\n\n- see **card#8174** for why.\n")
r = r4(fx)
eq("bold-ANYWHERE in a bullet does not satisfy R4", ["R4"], rules_flagged(r))

# --- Section scoping: a bullet under a RELEASED section is a released entry, not this PR's.
fx = make_repo(**R4FX, head_changelog=("# Changelog\n\n## [Unreleased]\n\n"
                                       "## [0.1.0] - 2026-08-30\n\n- **card#8174** — old.\n"))
r = r4(fx)
eq("a bullet under a RELEASED section does not discharge the Unreleased obligation",
   ["R4"], rules_flagged(r))

# --- The TITLE is the second surface, and it stands alone.
fx = make_repo(**R4FX, head_branch="chore/tidy", head_changelog=unreleased_with())
r = r4(fx, head_ref="chore/tidy", title="ci(release): gate the bullet (card#8174)")
eq("a token in the TITLE alone creates the obligation → RED", ["R4"], rules_flagged(r))
eq("  … and the message says the title is what named it", True, "PR title" in r.stdout)
fx = make_repo(**R4FX, head_branch="chore/tidy", head_changelog=unreleased_with("8174"))
r = r4(fx, head_ref="chore/tidy", title="ci(release): gate the bullet (card#8174)")
eq("  … and the same PR WITH the bullet passes (the title surface discriminates)",
   0, r.returncode)

# --- Spelling: the branch says `card-8174`, the bullet says `card#8174`. One obligation.
fx = make_repo(**R4FX, head_branch="card-8174-x", head_changelog=unreleased_with("8174"))
r = r4(fx, head_ref="card-8174-x")
eq("branch-ergonomic `card-8174` is discharged by a `card#8174` bullet (id, not spelling)",
   0, r.returncode)
# CONTROL: the guard is not simply ignoring the branch — the same branch with no bullet reds.
fx = make_repo(**R4FX, head_branch="card-8174-x", head_changelog=unreleased_with())
r = r4(fx, head_ref="card-8174-x")
eq("  CONTROL: the same branch with no bullet DOES red (the surface is really read)",
   ["R4"], rules_flagged(r))

# --- Two cards named, one bulleted: the message must name the MISSING one and only it.
fx = make_repo(**R4FX, head_branch="card-8174-x", head_changelog=unreleased_with("8174"))
r = r4(fx, head_ref="card-8174-x", title="two cards (card#7929)")
eq("a second card named in the title is a second obligation → RED", ["R4"], rules_flagged(r))
eq("  … naming card#7929 and NOT card#8174 (which is discharged)",
   (True, False),
   ("no line-initial `- **card#7929**`" in r.stdout,
    "no line-initial `- **card#8174**`" in r.stdout))

# --- A card-ish token the GRAMMAR does not accept invents no obligation. That is
# card-token-lint's defect to report; R4 refusing to guess an id is the honest half.
fx = make_repo(**R4FX, head_branch="chore/tidy", head_changelog=unreleased_with())
r = r4(fx, head_ref="chore/tidy", title="cards #8174 and other prose")
eq("`cards #8174` (plural — the correlators drop it) creates NO R4 obligation", 0, r.returncode)
r = r4(fx, head_ref="chore/tidy", title="card#8174 and other prose")
eq("  CONTROL: the parseable spelling in the same slot DOES create one",
   ["R4"], rules_flagged(r))

# --- THE REVERT CARVE-OUT. The exemption is the REMOVAL, not a title or a branch name: the
# bullet exists at the base and does not at the head.
fx = make_repo(**R4FX, base_changelog=unreleased_with("8174"),
               head_changelog=unreleased_with())
r = r4(fx)
eq("a PR that REMOVES card#8174's bullet is exempt → exit 0", 0, r.returncode)
eq("  … and SAYS it exempted rather than staying silent",
   True, "R4 exempt: card#8174" in r.stdout)
# CONTROL, single-variable: the same head with a base that never had the bullet is the
# ordinary miss and must red. Without this the exemption would be indistinguishable from R4
# simply not firing.
fx = make_repo(**R4FX, base_changelog=unreleased_with(), head_changelog=unreleased_with())
r = r4(fx)
eq("  CONTROL: same head, base with no bullet → RED (the exemption discriminates)",
   ["R4"], rules_flagged(r))

# --- THE BASE-REF CARVE-OUT. On the release path step 4 has just emptied `[Unreleased]`, so a
# release PR whose title cites a card must NOT be asked for a bullet under it.
fx = make_repo(base_version="0.1.0", head_version="0.2.0",
               head_changelog=changelog_with("0.2.0"))
r = guard(fx, base_ref="main", head_ref="release/v0.2.0",
          title="Release v0.2.0 (card#8174)")
eq("a release PR citing a card is NOT asked for an [Unreleased] bullet → exit 0",
   0, r.returncode)
eq("  … and says so rather than passing silently", True, "R4 NOT APPLICABLE" in r.stdout)
# CONTROL: the same title and the same (empty-Unreleased) changelog on a FEATURE PR reds — so
# the pass above is the base ref's doing, not the fixture's.
fx = make_repo(**R4FX, head_changelog=changelog_with("0.2.0"))
r = r4(fx, head_ref="chore/tidy", title="Release v0.2.0 (card#8174)")
eq("  CONTROL: the same title + changelog into `dev` DOES red (base ref is what carved it out)",
   ["R4"], rules_flagged(r))

# --- THE BACK-MERGE / REBASE CASE is out of scope at the TRIGGER, not by an exemption: the
# documented sync branch names no card on either surface. This is why R4 reads the head ref and
# the title and NOT commit subjects — a back-merge CONTAINS card-bearing subjects.
fx = make_repo(**R4FX, head_branch="sync/main-to-dev-post-v0.1.0",
               head_changelog=unreleased_with())
r = r4(fx, head_ref="sync/main-to-dev-post-v0.1.0", title="Back-merge main to dev post v0.1.0")
eq("the documented back-merge branch names no card → R4 never fires", 0, r.returncode)
eq("  … and says it is not applicable, naming the tokenless reason",
   True, "R4 NOT APPLICABLE — neither the head ref nor the PR title names a card" in r.stdout)

# --- FAIL LOUD: a changelog with no `## [Unreleased]` heading cannot be judged by R4's rule.
fx = make_repo(**R4FX, head_changelog="# Changelog\n\n## [0.1.0]\n\n- **card#8174** — x.\n")
r = r4(fx)
eq("no `## [Unreleased]` heading + a named card → exit 2, not a verdict", 2, r.returncode)
eq("  … and says the rule is scoped to that section", True, "R4's rule is scoped" in r.stderr)

# --- FAIL LOUD: the card grammar is EXTRACTED, and every failure to extract it is exit 2.
fx = make_repo(**R4FX, head_changelog=unreleased_with("8174"))
(fx / "bin/promote-cards-by-token").unlink()
r = r4(fx)
eq("card-grammar authority absent → exit 2, no private copy of the grammar", 2, r.returncode)
eq("  … and names the authority it could not read", True, "authority not found" in r.stderr)
fx = make_repo(**R4FX, head_changelog=unreleased_with("8174"))
p = fx / "bin/promote-cards-by-token"
p.write_text(p.read_text() + "\nCARD_RE='\\bcard([0-9]+)'\n", encoding="utf-8")
r = r4(fx)
eq("card-grammar authority DUPLICATED → exit 2 (ambiguity is not a pass)", 2, r.returncode)
eq("  … and refuses to hardcode the grammar", True, "rather than hardcoding" in r.stderr)


# =============================================================================================
print("== 11. R5 — changelog SIZE against a threshold derived from the file's own growth ==")
# `docs/PLAN.md § 4` promised this gate ("our addition — a size gate") and D-11 recorded it as
# shipped; it did not exist until this card. The threshold is cliff (1 MiB) minus the bytes the
# file grew in the last 14 days, measured from history on every run — so the arms below are
# differential on the HISTORY, not just on the size.
BIG = 600_000
BIGGER = 700_000
CLIFF = 1024 * 1024


def sized_changelog(nbytes: int, *cards: str) -> str:
    head = unreleased_with(*(cards or ("8174",)))
    pad = nbytes - len(head.encode("utf-8"))
    return head + ("x" * max(0, pad))


R5FX = dict(base_version="0.1.0", head_version="0.1.0", base_branch="dev")

# --- CONTROL: a small changelog is nowhere near the threshold.
fx = make_repo(**R5FX, head_changelog=unreleased_with("8174"))
r = r4(fx)
eq("a small changelog → exit 0, R5 silent", 0, r.returncode)
eq("  … and the run PRINTS the threshold it derived (no silent verdict)",
   True, f"threshold {CLIFF:,} B" in r.stdout or "threshold " in r.stdout)

# --- THE PLANT: 600,000 B with no history older than the window. All of it counts as
# fourteen-day growth, so the threshold is 1 MiB - 600,000 B and the head is over it.
fx = make_repo(**R5FX, head_changelog=sized_changelog(BIG))
r = r4(fx)
eq("600,000 B grown entirely inside the window → RED", 1, r.returncode)
eq("  … R5 alone (the bullet is present, so R4 is silent)", ["R5"], rules_flagged(r))
eq("  … and the message names the size, the threshold and the cliff",
   (True, True, True),
   (f"{BIG:,} B at the head" in r.stdout, f"{CLIFF - BIG:,} B threshold" in r.stdout,
    f"{CLIFF:,} B" in r.stdout))
eq("  … and names the remedy as a HUMAN decision, not one the gate makes",
   True, "archive released sections" in r.stdout)

# --- THE DIFFERENTIAL THAT PROVES THE WINDOW IS REAL: the SAME head bytes, but 590,000 of them
# already existed 30 days ago. Growth is then 10,000 B, the threshold rises, and it passes.
fx = make_repo(**R5FX, head_changelog=sized_changelog(BIG),
               older_changelog=sized_changelog(590_000), older_days_ago=30)
r = r4(fx)
eq("the same 600,000 B, but 590,000 of it OUTSIDE the 14-day window → exit 0", 0, r.returncode)
eq("  … and the run says it measured the growth from that older commit",
   True, "grew 10,00" in r.stdout)
# CONTROL: move the same older commit INSIDE the window and the identical head reds again.
fx = make_repo(**R5FX, head_changelog=sized_changelog(BIG),
               older_changelog=sized_changelog(590_000), older_days_ago=3)
r = r4(fx)
eq("  CONTROL: the same older commit dated 3 days back (inside the window) → RED again",
   ["R5"], rules_flagged(r))

# --- R5 REFUSES ONLY THE PRs THAT MAKE IT WORSE. An over-threshold file that reds every PR in
# the repo reds the archiving PR too, and that is the gate people switch off.
fx = make_repo(**R5FX, base_changelog=sized_changelog(BIG),
               head_changelog=sized_changelog(BIG))
r = r4(fx)
eq("over threshold but this PR does not grow it → exit 0", 0, r.returncode)
eq("  … as a WARNING that still states the measurement",
   True, "R5 WARNING (not a failure)" in r.stdout)
fx = make_repo(**R5FX, base_changelog=sized_changelog(BIGGER),
               head_changelog=sized_changelog(BIG))
r = r4(fx)
eq("the ARCHIVING PR (over threshold, shrinking) is never blocked by R5", 0, r.returncode)
# CONTROL: one byte of growth on the same over-threshold file is the refused case.
fx = make_repo(**R5FX, base_changelog=sized_changelog(BIG),
               head_changelog=sized_changelog(BIG + 1))
r = r4(fx)
eq("  CONTROL: the same file plus ONE byte → RED (the grew-test discriminates)",
   ["R5"], rules_flagged(r))

# --- FAIL LOUD: a shallow clone cannot be measured, and an unmeasurable size is not a small
# size. This is the false-clean the whole rule turns on: `git log` reports "nothing before the
# cutoff" for a truncated history in the same words it uses for a young file.
fx = make_repo(**R5FX, head_branch="card-8174-changelog-gate",
               head_changelog=sized_changelog(BIG))
sh = shallow_clone(fx, "card-8174-changelog-gate", base_branch="dev")
r = guard(sh, base_ref="dev", head_ref="card-8174-changelog-gate", base_rev="origin/dev")
eq("a SHALLOW clone with nothing before the cutoff → exit 2, never a pass", 2, r.returncode)
eq("  … and says an unmeasurable size is not a small size",
   True, "an unmeasurable size is not a small size" in r.stderr)
# CONTROL: the same fixture at full depth reaches a verdict (exit 1 here — it IS over
# threshold), so the 2 above is the clone's doing and not the fixture's.
r = r4(fx)
eq("  CONTROL: the same repo at full depth reaches a RULE verdict, not a 2", 1, r.returncode)

# --- …and shallowness ALONE is not the refusal. Refusing every shallow clone would be the easy
# rule and would red work that is fine — this repo's own working checkout is shallow at the root
# commit and can answer for every date after it. ONE fixture, TWO clones, depth the only
# variable: at depth 1 the pre-window commit is unreachable (ambiguous, exit 2), at depth 3 it
# is reachable, so truncation cannot have hidden it and the same shallow repo is measured.
fx = make_repo(**R5FX, head_branch="card-8174-changelog-gate",
               head_changelog=sized_changelog(BIG),
               older_changelog=sized_changelog(590_000), older_days_ago=30)
sh = shallow_clone(fx, "card-8174-changelog-gate", base_branch="dev", depth=1)
r = guard(sh, base_ref="dev", head_ref="card-8174-changelog-gate", base_rev="origin/dev")
eq("shallow, pre-window commit CUT OFF → exit 2 (the ambiguous case)", 2, r.returncode)
sh = shallow_clone(fx, "card-8174-changelog-gate", base_branch="dev", depth=3)
# ASSERT THE FIXTURE'S OWN PREMISE. "Shallow but sufficient" is only a test of anything if the
# clone really is shallow; a `--depth N` clone of an N-commit history is COMPLETE and git writes
# no graft, which is what this arm was silently doing before the fixture grew a seed commit.
eq("  (fixture premise) the depth-3 clone is genuinely SHALLOW", "true",
   subprocess.run(["git", "-C", str(sh), "rev-parse", "--is-shallow-repository"],
                  capture_output=True, text=True).stdout.strip())
r = guard(sh, base_ref="dev", head_ref="card-8174-changelog-gate", base_rev="origin/dev")
eq("shallow, but the pre-window commit IS reachable → measured, exit 0", 0, r.returncode)
eq("  … and it measured the growth from that commit, not from zero",
   True, "grew 10,00" in r.stdout)


print()
if fails:
    print(f"release-pr-guard.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("release-pr-guard.selftest: all checks passed")

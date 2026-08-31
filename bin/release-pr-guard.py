#!/usr/bin/env python3
"""release-pr-guard.py — refuse a PR into the release branch that is not shaped like a release.

THE DEFECT THIS CLOSES — a twelve-step documented flow with nothing behind it.
`docs/VERSIONING.md` specifies the release act in twelve numbered steps and nothing enforced
any of them. On 2026-08-30, PR #38 was opened `dev` -> `main` with no `VERSION` bump and no
changelog section, and it MERGED GREEN, breaking three documented rules at once:

  1. HEAD WAS `dev`. `delete_branch_on_merge` is enabled on this repo, so GitHub deletes a
     PR's head branch the moment it merges — a `dev`-headed release PR deletes the integration
     branch as a side effect of a successful release. `dev` survived, and the ruleset's
     `deletion` rule on it is the likely reason; that is an INFERENCE from `dev` still existing,
     not a refusal anyone witnessed, and it is the backstop `docs/VERSIONING.md § The release-PR
     head hazard` says to treat as a guess and not lean on. A release should not be depending on
     an untested interaction to avoid destroying the branch every open PR is based on.
  2. NO `VERSION` BUMP. `auto-tag-version` then failed AFTER the merge — "v0.1.0 already exists
     at 556ac3f, but this push is 9d80650. main moved without VERSION moving" — which is a
     correct report arriving at the one moment nobody can act on it cheaply. The commit is
     already on `main`; the only remedy is another release PR.
  3. NO CHANGELOG SECTION, so the tag that `auto-tag-version` would have minted owed an entry
     that does not exist (`docs/VERSIONING.md § The core rules`, rule 3).

WHY A CONTENT GATE AND NOT AN ACTOR GATE. The obvious reading of #38 is "an agent merged a
`main`-targeted PR, so restrict who may merge". That control cannot be built here: this install
has ONE GitHub identity shared by the agent and the operator (a second identity is a cost the
operator has declined), so no actor-based rule — CODEOWNERS, a bypass list, a required
reviewer — can tell the two apart. Every such rule would either block the operator or admit the
agent. So this gate asks WHAT is being merged, never WHO is merging it. That is
actor-independent, free, and — unlike a permission — it states its verdict to the author while
the fix is still one commit.

WHAT THIS GATE ASSERTS — three rules, and only on a PR whose base is the release branch:
  * R1 — HEAD BRANCH SHAPE. The head ref must be exactly `release/` + the tag this PR would
    mint (see AUTHORITY SOURCING). It is an EQUALITY against the head's own `VERSION`, not a
    shape match, so `release/v0.3.0` carrying `VERSION` 0.2.0 is also refused: the branch name
    and the version it ships are two statements of one fact and this is the only place they
    can be held together before the tag is minted from the second one.
  * R2 — `VERSION` BUMPED. `VERSION` at the head must be strictly greater than `VERSION` at
    the release branch's tip, by semver precedence. EQUAL is exactly #38; LOWER is worse.
  * R3 — CHANGELOG SECTION. `docs/CHANGELOG.md` at the head must carry a level-2 section
    naming the new version, and the `[Unreleased]` heading does not count as one (step 4 of
    the release flow RETITLES it — a version still sitting under `Unreleased` is precisely the
    not-yet-released state).

AND WHAT IT DELIBERATELY DOES NOT ASSERT:
  * A PR into any other base is NOT TOUCHED and passes trivially. This gate has nothing to say
    about a feature PR.
  * NO CARD TOKEN is required. That is `card-token-lint`'s surface, and it does not require one
    either.
  * NO CHANGELOG PROSE JUDGEMENT. R3 asks whether the section exists, never whether it is any
    good, complete, or non-empty. `docs/PLAN.md § 4` owns the entry format; a gate that graded
    prose would be inventing a rule nobody wrote.
  * NOT flow steps 5 and 6 — the deploy verdict for both targets and the wire verdict. Those
    are human judgement stated in release notes, and a gate that grepped for a phrase would
    claim to have checked a judgement when it had checked a string. They stay unenforced, and
    saying so here is more honest than a decoration that reads as coverage.
  * NOT step 8's "wait for CI", step 9's "a human merges it", or step 11's back-merge: the
    first is about other checks, the second is the actor question this file cannot answer, and
    the third happens after this PR is gone.

AUTHORITY SOURCING — DERIVED, NOT RETYPED, AND LOUD WHEN THE AUTHORITY MOVES.
This repo already established the doctrine in `bin/card-token-lint.py`: a gate that hardcodes a
second copy of a rule becomes, on the first change to the real one, a gate that is confidently
wrong. Three facts this guard needs are therefore EXTRACTED at run time, and every extraction
failure is exit 2 — never a fallback to a guessed value:

  * THE RELEASE BRANCH, from `on.push.branches` in `.github/workflows/auto-tag-version.yml`.
    That workflow's trigger IS the operational definition of "the branch where a push mints a
    tag", and `.release-pr.json` explicitly REFUSES to carry a `main_branch` key (read its
    `_note`), so the trigger is the only machine-readable statement of it in this repo.
  * THE TAG FORMAT, from `tag_format` in `.release-pr.json`, which `bin/promote-cards-by-token`
    also reads. The release branch name is composed as `release/` + the instantiated tag, so
    R1 tracks a tag-format change with no edit here.
  * THE ACCEPTED `VERSION` SPELLING, from the `grep -qE '…'` validation in
    `auto-tag-version.yml`. This one matters more than it looks: that regex is what will judge
    `VERSION` AFTER the merge, and a guard with its own looser copy would green a release that
    then reds post-merge — which is the exact harm shape of #38, rebuilt by the gate that
    exists to prevent it.

THE TWO REMAINING LITERALS ARE NAMED RATHER THAN HIDDEN. `release/` and `docs/CHANGELOG.md`
are written out below because no machine-readable authority in this repo states either; both
are prose rules in `docs/VERSIONING.md` (§ Release flow steps 2 and 4, § The core rules rule 3)
and both are cited at their definition. Where a literal is unavoidable the honest thing is to
say which doc owns it, not to pretend it was derived.

SEMVER: TWO DIFFERENT QUESTIONS, DELIBERATELY ANSWERED BY TWO DIFFERENT THINGS. "Is this an
accepted spelling?" is the repo's ruling and comes from the extracted regex. "Which of these
two is greater?" is semver.org § 11, a published spec that no file in this repo restates — so
it is implemented here, structurally, rather than derived from a regex that says nothing about
ordering. `bin/release-pr-guard.selftest.py § 5` holds that implementation against the spec's
own worked chain.

THE HEAD BRANCH IS REFUSED GENERICALLY, NOT BY NAMING `dev`. `delete_branch_on_merge` deletes
WHATEVER branch heads the PR; the harm is proportional to how much that branch was worth, and
`dev` is merely the most expensive instance. Encoding "not dev" would state a narrower rule
than the one the hazard actually implies and would wave through the next long-lived branch that
is not called `dev`.

USAGE
    release-pr-guard.py --base-ref REF --head-ref REF [--repo DIR]
                        [--head-rev REV] [--base-rev REV]
`--base-ref`/`--head-ref` are the PR's branch NAMES (from the event). `--head-rev` (default
`HEAD`) and `--base-rev` (default `origin/<release branch>`) are the git revisions whose CONTENT
is measured; both are read with `git show`, so this never depends on a working tree matching the
refs it claims to judge.

Exit 0 = clean, or the PR does not target the release branch.
Exit 1 = this PR breaks a rule — the author fixes the PR.
Exit 2 = the guard COULD NOT MEASURE — missing `VERSION`, unreadable base rev, unparseable
         semver, absent `docs/CHANGELOG.md`, or an authority that moved. Someone fixes the repo
         or this guard. The split matters: a 1 and a 2 send different people to different
         files, and collapsing them would send authors to rename branches that were fine.
A state this guard cannot measure is never a pass. An unmeasurable state reported green is the
false-clean class this repo has spent weeks removing (`docs/VERSIONING.md § The failure
direction must be safe`), and a release gate is the last place to mint another one.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent

# --- The authorities, by path. Each is read at run time; none is restated. -------------------
TAG_WORKFLOW = ".github/workflows/auto-tag-version.yml"
RELEASE_CONFIG = ".release-pr.json"

# --- The two literals with no machine-readable owner in this repo. ---------------------------
# `docs/VERSIONING.md § Release flow` step 2 ("Branch `release/v<version>` off `dev`") and
# § The release-PR head hazard ("always a throwaway `release/v<version>` branch"). Only the
# PREFIX is a literal; the version part is composed from the extracted tag format.
RELEASE_BRANCH_PREFIX = "release/"
# `docs/VERSIONING.md § The core rules` rule 3 ("The changelog lives at `docs/CHANGELOG.md`").
CHANGELOG_PATH = "docs/CHANGELOG.md"

# The prose doc that owns every rule below, named for a human who wants the ruling rather than
# the diagnosis. This guard enforces what that file says; where they disagree, it wins.
CANON_DOC = "docs/VERSIONING.md"

# `on: / push: / branches: [x]` in the tagger's trigger. Anchored as a contiguous block so a
# `branches:` under some OTHER trigger (a future `pull_request:`) cannot be mistaken for the
# push trigger's. Matched exactly once or this exits 2.
_TAG_TRIGGER_RE = re.compile(
    r"^on:[ \t]*\n[ \t]+push:[ \t]*\n[ \t]+branches:[ \t]*\[([^\]\n]*)\][ \t]*$", re.M)

# The tagger's `VERSION` validation: a bash single-quoted ERE handed to `grep -qE`. Bash single
# quotes admit no escapes, so `[^']*` consumes the whole literal.
_SEMVER_AUTHORITY_RE = re.compile(r"grep -qE '([^']*)'")

# POSIX bracket classes mean something else to Python's `re` (a syntax error before 3.12, a
# character set of `[:space]` after). The guard REFUSES rather than translating, for the reason
# `bin/card-token-lint.py` gives: a silent mistranslation of an ACCEPT grammar is a confidently
# wrong gate, and here it would decide whether a release version is legal.
_POSIX_CLASS_RE = re.compile(r"\[:[a-z]+:\]")

# A version's structural parts, for ORDERING only (semver.org § 11). Validity is the extracted
# authority's ruling, not this pattern's — this runs only after that check has passed.
_PRECEDENCE_RE = re.compile(r"^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z][0-9A-Za-z.-]*))?$")
_NUMERIC_IDENT_RE = re.compile(r"^[0-9]+$")


class Unmeasurable(Exception):
    """The guard cannot certify anything. Always exit 2, never a verdict about the PR."""


# --- Authority extraction --------------------------------------------------------------------

def _read_authority(repo: Path, rel: str) -> str:
    p = repo / rel
    if not p.is_file():
        raise Unmeasurable(
            f"authority not found at {p} — this guard derives the release branch, the tag "
            f"format and the accepted VERSION spelling from the repo's own files and has no "
            f"fallback copy of any of them (that is deliberate; see AUTHORITY SOURCING).")
    return p.read_text(encoding="utf-8")


def load_release_branch(repo: Path) -> str:
    """The branch a push to which mints a tag — read off the tagger's own trigger."""
    text = _read_authority(repo, TAG_WORKFLOW)
    found = _TAG_TRIGGER_RE.findall(text)
    if len(found) != 1:
        raise Unmeasurable(
            f"expected exactly one `on:/push:/branches: [...]` block in {TAG_WORKFLOW}, found "
            f"{len(found)} — the tagger's trigger moved or was reformatted. Teach this guard "
            f"the new shape; do NOT hardcode the branch name here.")
    branches = [b.strip().strip("'\"") for b in found[0].split(",") if b.strip()]
    if len(branches) != 1:
        raise Unmeasurable(
            f"{TAG_WORKFLOW} tags pushes to {branches} — more than one release branch. This "
            f"guard assumes exactly one and refuses to pick; a human decides what a release "
            f"PR into each of them must look like.")
    return branches[0]


def load_tag_format(repo: Path) -> str:
    """`tag_format` from the release config — the same key `promote-cards-by-token` reads."""
    text = _read_authority(repo, RELEASE_CONFIG)
    try:
        cfg = json.loads(text)
    except json.JSONDecodeError as exc:
        raise Unmeasurable(
            f"{RELEASE_CONFIG} is not valid JSON ({exc}) — the tag format cannot be read, so "
            f"the expected release-branch name cannot be composed.") from exc
    fmt = cfg.get("tag_format")
    if not isinstance(fmt, str) or "{{version}}" not in fmt:
        raise Unmeasurable(
            f"{RELEASE_CONFIG} has no usable `tag_format` (got {fmt!r}); it must be a string "
            f"containing `{{{{version}}}}`. The expected release-branch name is composed from "
            f"it and there is no second copy to fall back to.")
    return fmt


def load_version_pattern(repo: Path) -> re.Pattern:
    """The accepted `VERSION` spelling, extracted from the tagger's post-merge validation."""
    text = _read_authority(repo, TAG_WORKFLOW)
    found = _SEMVER_AUTHORITY_RE.findall(text)
    if len(found) != 1:
        raise Unmeasurable(
            f"expected exactly one `grep -qE '…'` VERSION validation in {TAG_WORKFLOW}, found "
            f"{len(found)} — the tagger's validation moved, was renamed, or was duplicated. "
            f"This guard must accept EXACTLY what the tagger accepts; a private copy here "
            f"would green a release the tagger then reds after the merge.")
    pattern = found[0]
    posix = _POSIX_CLASS_RE.findall(pattern)
    if posix:
        raise Unmeasurable(
            f"the VERSION validation in {TAG_WORKFLOW} uses the POSIX bracket class(es) "
            f"{sorted(set(posix))}, which Python's `re` does not read the same way. Refusing "
            f"to guess a translation of an ACCEPT grammar; decide the Python spelling "
            f"deliberately and teach this guard about it.")
    try:
        return re.compile(pattern)
    except re.error as exc:
        raise Unmeasurable(f"the VERSION validation in {TAG_WORKFLOW} does not compile as a "
                           f"Python regex ({exc}): {pattern!r}.") from exc


# --- Content, read from git revisions --------------------------------------------------------

def _git(repo: Path, *args: str) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(["git", "-C", str(repo), *args],
                              capture_output=True, text=True)
    except FileNotFoundError:
        raise Unmeasurable("git is not on PATH — this guard reads both sides' content with "
                           "`git show` and cannot measure anything without it.") from None


def resolve_rev(repo: Path, rev: str, role: str) -> str:
    r = _git(repo, "rev-parse", "--verify", "--quiet", f"{rev}^{{commit}}")
    if r.returncode != 0 or not r.stdout.strip():
        raise Unmeasurable(
            f"cannot resolve the {role} revision {rev!r} in {repo} — a shallow clone, a ref "
            f"that was never fetched, or a typo. The comparison this guard exists to make is "
            f"unmeasurable without it, and an unmeasurable comparison is not a pass.")
    return r.stdout.strip()


def read_at(repo: Path, rev: str, path: str) -> str | None:
    """File content at a revision, or None if the file does not exist there."""
    r = _git(repo, "show", f"{rev}:{path}")
    return None if r.returncode != 0 else r.stdout


def read_version(repo: Path, rev: str, role: str, version_re: re.Pattern) -> str:
    """`rev` is a RESOLVED commit sha (see `resolve_rev`), which is why messages abbreviate it."""
    raw = read_at(repo, rev, "VERSION")
    if raw is None:
        raise Unmeasurable(
            f"VERSION does not exist at the {role} revision ({rev[:7]}). It is the single source "
            f"of truth for the repo's version ({CANON_DOC} § The core rules, rule 1); this "
            f"guard cannot guess one.")
    version = raw.strip("\n")
    if not version_re.match(version):
        raise Unmeasurable(
            f"VERSION at the {role} revision ({rev[:7]}) is not an accepted version "
            f"string: "
            f"{version!r}. The accepted spelling is `{version_re.pattern}`, extracted from "
            f"{TAG_WORKFLOW} — the same check that will judge it after the merge, which is why "
            f"this is refused here rather than left to red a commit already on the release "
            f"branch.")
    return version


# --- Semver precedence (semver.org § 11) -----------------------------------------------------

def precedence_key(version: str):
    """A sort key implementing semver precedence for the spellings the authority accepts.

    Ordering is semver.org § 11 — a published spec, not a rule this repo owns — so it is
    implemented structurally rather than read off a validity regex, which says nothing about
    order. Build metadata is not handled because the extracted authority does not accept it;
    if it ever does, this raises rather than ranking something it was never taught.
    """
    m = _PRECEDENCE_RE.match(version)
    if not m:
        raise Unmeasurable(
            f"{version!r} passed the accepted-spelling check but this guard cannot rank it. "
            f"The accepted spelling in {TAG_WORKFLOW} has been widened past what semver "
            f"precedence is implemented for here (build metadata, most likely). Refusing to "
            f"invent an ordering: teach this guard the new one deliberately.")
    major, minor, patch, pre = m.group(1), m.group(2), m.group(3), m.group(4)
    core = (int(major), int(minor), int(patch))
    if pre is None:
        # "a pre-release version has lower precedence than the associated normal version"
        return (core, 1, ())
    ids = []
    for ident in pre.split("."):
        if _NUMERIC_IDENT_RE.match(ident):
            # "identifiers consisting of only digits are compared numerically" and "numeric
            # identifiers always have lower precedence than alphanumeric identifiers"
            ids.append((0, int(ident), ""))
        else:
            ids.append((1, 0, ident))
    # A larger set of pre-release fields outranks a smaller one when all preceding identifiers
    # are equal — which is exactly how Python compares a longer tuple against its own prefix.
    return (core, 0, tuple(ids))


# --- The three rules -------------------------------------------------------------------------

def check_head_branch(head_ref: str, expected: str) -> list[str]:
    """R1 — the head ref is exactly the release branch for the version this PR ships."""
    if head_ref == expected:
        return []
    msg = (f"R1 head branch: this PR's head is {head_ref!r}, but a release PR's head must be "
           f"{expected!r} — `{RELEASE_BRANCH_PREFIX}` + the tag this PR would mint, composed "
           f"from `tag_format` in {RELEASE_CONFIG} and VERSION at the head.")
    if not head_ref.startswith(RELEASE_BRANCH_PREFIX):
        msg += (f" This repo has `delete_branch_on_merge` ENABLED, so GitHub DELETES the head "
                f"branch the instant the PR merges: a release headed by any branch you meant "
                f"to keep destroys it as a side effect of succeeding. That is why the head is "
                f"always a throwaway release branch and never a long-lived one. The ruleset's "
                f"`deletion` rule may catch it, but {CANON_DOC} § The release-PR head hazard "
                f"says do not lean on that — it is a guess about an interaction, and the "
                f"throwaway branch costs one command.")
    else:
        msg += (" The branch name and the VERSION it ships are two statements of one fact; "
                "here they disagree, and the tag is minted from the second one, so the branch "
                "name is about to be quietly wrong about what shipped.")
    return [msg]


def check_version_bump(head_version: str, base_version: str, release_branch: str) -> list[str]:
    """R2 — VERSION at the head is strictly greater than VERSION at the release branch tip."""
    head_key, base_key = precedence_key(head_version), precedence_key(base_version)
    if head_key > base_key:
        return []
    if head_key == base_key:
        return [f"R2 VERSION bump: VERSION is {head_version!r} at the head and {base_version!r} "
                f"on `{release_branch}` — UNCHANGED. This is exactly the 2026-08-30 defect: the "
                f"merge lands, `auto-tag-version` then finds `v{base_version}` already on a "
                f"different commit and fails AFTER the merge, when the only remedy left is "
                f"another release PR. Bump VERSION ({CANON_DOC} § Bump sizing) — the bump plus "
                f"its changelog entry IS the release act."]
    return [f"R2 VERSION bump: VERSION is {head_version!r} at the head but {base_version!r} on "
            f"`{release_branch}` — this PR moves the version BACKWARDS. Tags are immutable and "
            f"`auto-tag-version` never moves one, so a lower version cannot be released at all; "
            f"a broken release ships the next version up ({CANON_DOC} § Anti-patterns)."]


def changelog_sections(text: str) -> list[str]:
    """Level-2 heading texts, excluding the `Unreleased` one.

    Release flow step 4 RETITLES `## [Unreleased]`, so a version sitting under an Unreleased
    heading is the not-yet-released state and must not satisfy R3. Nothing else about the
    heading's spelling is asserted: `docs/PLAN.md § 4` owns the format and this guard has no
    business ruling on it.
    """
    out = []
    for line in text.splitlines():
        m = re.match(r"^##[ \t]+(.*\S)[ \t]*$", line)
        if m and "unreleased" not in m.group(1).lower():
            out.append(m.group(1))
    return out


def check_changelog(text: str, version: str) -> list[str]:
    """R3 — a released section names the new version."""
    # Bounded so a section for `0.2.10` cannot discharge `0.2.1`'s obligation, and `10.2.0`
    # cannot discharge `0.2.0`. Nothing is asserted about brackets, dates or ordering —
    # `docs/PLAN.md § 4` owns the format and this gate has no business ruling on it.
    #
    # THE TWO SIDES ARE DELIBERATELY ASYMMETRIC. The left boundary bars only digits, `.` and
    # `-`; the right bars those AND letters. A LETTER on the left is the legitimate `v0.2.0`
    # spelling — the tag format's own prefix — and barring it would red a real release for a
    # heading style no document in this repo forbids, which is the false-positive direction
    # that gets a gate switched off. A letter or `-` on the RIGHT is `0.2.0-rc.1`: a DIFFERENT
    # version, whose section must not discharge `0.2.0`'s obligation. Measured, not assumed:
    # the selftest asserted `## v0.2.0` should pass and this bound was refusing it.
    token = re.compile(r"(?<![0-9.\-])" + re.escape(version) + r"(?![0-9A-Za-z.\-])")
    sections = changelog_sections(text)
    if any(token.search(s) for s in sections):
        return []
    seen = ", ".join(repr(s) for s in sections) if sections else "none"
    return [f"R3 changelog: {CHANGELOG_PATH} at the head carries no released section naming "
            f"{version!r} (released sections found: {seen}). Every tag `v<version>` owes a "
            f"changelog entry ({CANON_DOC} § The core rules, rule 3); release flow step 4 "
            f"retitles `## [Unreleased]` to the new version and opens a fresh empty one. An "
            f"`[Unreleased]` heading does not count — that is the state this step ends."]


# --- Entry point -----------------------------------------------------------------------------

def _strip_ref(ref: str) -> str:
    return ref[len("refs/heads/"):] if ref.startswith("refs/heads/") else ref


def run(args) -> int:
    repo = Path(args.repo).resolve()
    base_ref = _strip_ref(args.base_ref)
    head_ref = _strip_ref(args.head_ref)

    release_branch = load_release_branch(repo)

    if base_ref != release_branch:
        print(f"release-pr-guard: NOT APPLICABLE — this PR targets {base_ref!r}, and the "
              f"release branch is {release_branch!r} (from `on.push.branches` in "
              f"{TAG_WORKFLOW}).")
        print("  Nothing about a non-release PR is this gate's business; it asserts nothing "
              "and passes.")
        return 0

    tag_format = load_tag_format(repo)
    version_re = load_version_pattern(repo)

    base_rev = args.base_rev if args.base_rev else f"origin/{release_branch}"
    head_sha = resolve_rev(repo, args.head_rev, "head")
    base_sha = resolve_rev(repo, base_rev, f"base (`{release_branch}` tip)")

    head_version = read_version(repo, head_sha, "head", version_re)
    base_version = read_version(repo, base_sha, f"`{release_branch}`", version_re)

    changelog = read_at(repo, head_sha, CHANGELOG_PATH)
    if changelog is None:
        raise Unmeasurable(
            f"{CHANGELOG_PATH} does not exist at the head revision ({head_sha[:7]}). Every tag "
            f"owes a changelog entry ({CANON_DOC} § The core rules, rule 3), so its absence is "
            f"not a release with nothing to say — it is a release this guard cannot check. "
            f"Restore the file; do not remove the obligation by removing the file.")

    expected_head = RELEASE_BRANCH_PREFIX + tag_format.replace("{{version}}", head_version)

    print(f"release-pr-guard: {head_ref} -> {base_ref} (the release branch).")
    print(f"  authorities:  release branch {release_branch!r} <- {TAG_WORKFLOW}; "
          f"tag_format {tag_format!r} <- {RELEASE_CONFIG};")
    print(f"                accepted VERSION `{version_re.pattern}` <- {TAG_WORKFLOW}")
    print(f"  measured:     VERSION {head_version!r} at head {head_sha[:7]} vs "
          f"{base_version!r} at {release_branch} {base_sha[:7]}")
    print(f"  expected head branch: {expected_head!r}")

    problems = check_head_branch(head_ref, expected_head)
    problems += check_version_bump(head_version, base_version, release_branch)
    problems += check_changelog(changelog, head_version)

    if not problems:
        print("release-pr-guard: OK — head branch, VERSION bump and changelog section all "
              "hold. This gate says nothing about the deploy verdict, the wire verdict, or "
              f"whether the bump is the right SIZE ({CANON_DOC} § Release flow steps 5-6, "
              "§ Bump sizing): those are yours.")
        return 0

    for p in problems:
        print(f"::error::release-pr-guard: {p}")
    print()
    print(f"release-pr-guard: FAIL — {len(problems)} of 3 release rules broken. Every one of "
          f"them is cheap to fix now and expensive after the merge: on 2026-08-30 all three "
          f"were broken at once, the PR merged green, the tagger failed AFTER the fact, and "
          f"the integration branch survived only because a ruleset backstop that had never "
          f"been exercised happened to hold.")
    print(f"The flow this enforces is {CANON_DOC} § Release flow, steps 2, 3, 4 and 7. Fix the "
          f"PR and push; this gate re-runs on every push, and on a base change.")
    return 1


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(
        description="Refuse a PR into the release branch that is not shaped like a release "
                    "(head branch, VERSION bump, changelog section).")
    ap.add_argument("--base-ref", required=True, help="the PR's base branch name")
    ap.add_argument("--head-ref", required=True, help="the PR's head branch name")
    ap.add_argument("--repo", default=str(REPO_ROOT),
                    help="repo root: where the authorities live and where git runs")
    ap.add_argument("--head-rev", default="HEAD",
                    help="git revision whose content is the head's (default: HEAD)")
    ap.add_argument("--base-rev", default=None,
                    help="git revision for the release branch tip "
                         "(default: origin/<release branch>)")
    args = ap.parse_args(argv)
    try:
        return run(args)
    except Unmeasurable as exc:
        print(f"::error::release-pr-guard: {exc}", file=sys.stderr)
        print("release-pr-guard: CANNOT MEASURE (exit 2) — this is not a verdict about the PR. "
              "A guard that cannot measure must red, never pass.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

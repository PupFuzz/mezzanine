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

WHAT THIS GATE ASSERTS — five rules in TWO FAMILIES, applicable on different conditions.

RELEASE SHAPE (R1-R3) — only on a PR whose base is the release branch:
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

CHANGELOG DISCIPLINE (R4-R5) — on EVERY PR, because that is where the entries are written:
  * R4 — THE CARD'S BULLET. If the head ref or the PR title names a card, `docs/CHANGELOG.md`
    at the head must carry a line-initial `- **card#NNNN**` bullet for that card under
    `## [Unreleased]`. `docs/PLAN.md § 4` owns the rule; this is its first enforcement.
  * R5 — CHANGELOG SIZE. `docs/CHANGELOG.md` at the head must stay below `cliff − the bytes it
    actually grew in the last HEADROOM_DAYS`, so the file reds while there is still a fortnight
    of runway before the contents API silently truncates it. `docs/PLAN.md § 4`'s "our addition
    — a size gate" is this, and until now it did not exist.

WHY R4 EXISTS — the same defect shape as #38, one branch over, measured at SIX instances.
`docs/CHANGELOG.md`'s own preamble states the per-PR obligation without qualification and
`docs/PLAN.md § 4` owns it, and on 2026-08-30 a sweep of `dev` (every `card#NNNN` in a commit
subject, minus every `^- **card#NNNN` bullet in the file) found 30 cards referenced and 25
bulleted: card#7335 (the WHOLE fleet-reporter), #7455, #7456, #7457, #7521 and #7929 had no
entry at all. The first release would have collected a changelog missing an entire subsystem.
Nothing checked it — `card-token-lint` checks token SPELLING, R3 checks that a SECTION exists,
and the string `CHANGELOG` appeared in no other workflow. The six were backfilled in PR #44;
R4 is the fix for the hole that let them through.
⚠ REPLAYED AGAINST THE REAL COMMITS, WHICH SPLITS THE SIX. R4 refuses card#7335 (`c4eb1e4`),
card#7456 (`faf416e`) and card#7929 (`84d61e3`) with exit 1, and passes each of them again at
`ebc7e28` where PR #44 added the bullet. The other three — card#7455 (`9d5e5af`), card#7457
(`1ec468a`) and card#7521 (`24be09d`) — merged BEFORE `docs/CHANGELOG.md` existed (it was created
on 2026-08-25 in `ceea110`), so this guard exits 2 on them: "the file this rule is about is not
here". They were a real gap in the released record and the backfill was right, but they were not
violations of a rule at the time and R4 could not have prevented them. Saying so is worth more
than a round number.

WHY R4 LIVES IN *THIS* FILE AND NOT IN A NEW WORKFLOW. This is the only gate in the repo that
sees a feature PR at all: it has no `branches:` filter (see the workflow's own comment for the
two reasons) and decides applicability internally. A new path-filtered workflow would have the
required-check pending trap baked in from birth.

R4'S CARVE-OUTS — a gate that reds on correct work gets switched off, so the exemptions are
argued rather than assumed. THE PRECEDENT is `release-consistency.yml`'s back-merge exemption
in the framework repo, which keys on `BASE_REF` and on a documented branch SHAPE, never on
"did the artifact change?" — because inferring the class from the artifact is circular.
  1. BASE IS THE RELEASE BRANCH ⇒ R4 DOES NOT APPLY. Keyed on the base ref exactly as the
     precedent is. Release flow step 4 RETITLES `## [Unreleased]` and opens a fresh EMPTY one,
     so on a release PR the section R4 reads is empty BY CONSTRUCTION and every bullet it would
     look for has just moved into the version section. Requiring one there would red the
     correctly-executed step 4. The release path already has its own rule about that file (R3).
  2. THIS PR REMOVES THE CARD'S BULLET ⇒ that card is EXEMPT. A revert of work that has not
     been released yet does not owe an entry — it owes the DELETION of one, because
     `[Unreleased]` describes the tree as it will ship and the reverted change will not. So the
     exemption is not a name, a title prefix or an opt-out token (all three would be prose a
     reviewer cannot check and an author can type): it is the OBSERVABLE that the bullet exists
     at the base and does not at the head. You cannot claim it without actually deleting a
     visible line, and you cannot claim it at all for a card that never had a bullet.
     ⚠ Measured against the base TIP, not the merge base, because the workflow fetches the base
     at depth 1 and a merge base needs history the base side does not have. The imprecision is
     ONE-DIRECTIONAL and it is the safe direction: the exemption can only mis-fire when the
     bullet is present on the base tip and absent at the head, which means the MERGE RESULT
     still carries a bullet for that card — the release still collects the entry, which is the
     whole property R4 protects.
  3. A BACK-MERGE OR A PURE REBASE IS OUT OF SCOPE AT THE TRIGGER, NOT BY AN EXEMPTION, and
     that is the reason the surfaces are the head ref and the title rather than the commit
     SUBJECTS. `sync/main-to-dev-post-v0.1.0` names no card on either surface, so R4 never
     fires on it — while a subject-keyed rule would drag every back-merge into scope and demand
     it re-add bullets the release had just retitled away. `docs/PLAN.md § 4` said
     "title/subjects"; the two surfaces the correlators actually parse are the branch name and
     the title (`bin/card-token-lint.py § SURFACES`), and § 4 now says so.

R4'S KNOWN LIMIT, STATED RATHER THAN COVERED OVER. R4 asserts the bullet EXISTS at the head; it
never asserts that THIS PR wrote it. A second PR for a card that already has a bullet therefore
passes without touching the changelog. That is deliberate: the repo's convention for a card
landing twice is to EXTEND the one bullet (card#7456's covers two commits), so a diff-shaped
rule would red the case where the existing bullet already says the right thing — and "grade
whether the bullet was extended enough" is prose judgement no document owns. The residual miss
is a second landing that adds nothing; the release still gets an entry for the card, which is
the property the six-instance defect was about.

R5'S THRESHOLD IS DERIVED AT RUN TIME FROM THIS FILE'S OWN HISTORY, not a constant.
`docs/PLAN.md § 4` states the rule as `threshold = cliff − 14 days of measured growth`, and
that is implemented literally: the guard reads `docs/CHANGELOG.md`'s size at the newest commit
whose committer date is at least HEADROOM_DAYS before the HEAD COMMIT'S OWN date, and the
difference from the head's size IS the fourteen days of growth. Nothing is extrapolated and no
rate is stored — a growth figure written down here would be a number the loop cites instead of
measures, and it would be most wrong exactly when growth accelerates. Two consequences worth
naming: the reference instant is the head COMMIT's date rather than the wall clock, so the same
PR gets the same verdict tomorrow and the fixtures are deterministic; and a SHALLOW clone that
finds NOTHING before the cutoff is exit 2, never a pass, because a truncated history reports
that in the same breath as a file younger than the window and would silently hand back the cliff
itself as the threshold — a false-clean of exactly the shape this repo keeps removing. A shallow
clone that DOES find a commit before the cutoff is measured normally: truncation removes only
commits older than the graft boundary, so it cannot have hidden the one that was found.

R5 DOES NOT BLOCK WORK THAT DOES NOT MAKE IT WORSE. An over-threshold shared file that reds
every PR in the repo is the archetype of a gate people switch off, and it would red the very PR
that archives the file. So R5 fails only when the head is over threshold AND the head is LARGER
than the base; over threshold while flat or shrinking is a loud `::warning::` and exit 0.

AND WHAT IT DELIBERATELY DOES NOT ASSERT:
  * NO CARD TOKEN is required. R4 fires on a token that is there; a PR that names no card owes
    nothing and passes, which is `card-token-lint`'s posture and `docs/PLAN.md § 4`'s rule.
  * NO CHANGELOG PROSE JUDGEMENT. R3 asks whether the section exists and R4 whether the bullet
    exists — never whether either is any good, complete, or non-empty. `docs/PLAN.md § 4` owns
    the entry format; a gate that graded prose would be inventing a rule nobody wrote.
  * NOT the "cite only the card the PR is about" rule (`docs/PLAN.md § 4`). A PR that names a
    card for context acquires a real changelog obligation under R4 — which is the doc's own
    stated consequence, not a new one — and nothing here can tell a context citation from a
    subject citation.
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
  * THE CARD-TOKEN GRAMMAR (R4), from `CARD_RE` in `bin/promote-cards-by-token` — the vendored
    mover's copy of the bridge accept-set, and the ONLY spelling of that grammar in this repo.
    `bin/card-token-lint.py` reads the same line for the same reason. R4 must consider exactly
    the tokens the correlators consider: a private copy here would either invent an obligation
    for a token no board move will ever see, or miss one that does.
    ⚠ The GRAMMAR has one home; the twenty lines that READ it now exist twice, here and in
    `bin/card-token-lint.py`. Hoisting them into a shared helper is the right fix and is filed
    rather than done: it edits `card-token-lint.py`, which this change's scope excludes.

THE REMAINING LITERALS ARE NAMED RATHER THAN HIDDEN. `release/`, `docs/CHANGELOG.md`, the
`## [Unreleased]` heading, the `- **card#NNNN**` bullet form and the 1 MiB cliff are written out
below because no machine-readable authority in this repo states any of them; each is a prose
rule (`docs/VERSIONING.md § Release flow` steps 2 and 4 and § The core rules rule 3;
`docs/PLAN.md § 4` for the last three) and each is cited at its definition. Where a literal is
unavoidable the honest thing is to say which doc owns it, not to pretend it was derived.

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
    release-pr-guard.py --base-ref REF --head-ref REF [--title TEXT] [--repo DIR]
                        [--head-rev REV] [--base-rev REV]
`--base-ref`/`--head-ref` are the PR's branch NAMES and `--title` its title (all from the
event). `--head-rev` (default `HEAD`) and `--base-rev` (default `origin/<base ref>`) are the git
revisions whose CONTENT is measured; both are read with `git show`, so this never depends on a
working tree matching the refs it claims to judge.

Exit 0 = clean. The release-shape rules may have been NOT APPLICABLE; R4 and R5 were judged.
Exit 1 = this PR breaks a rule — the author fixes the PR.
Exit 2 = the guard COULD NOT MEASURE — missing `VERSION`, unreadable base rev, unparseable
         semver, absent `docs/CHANGELOG.md`, no `## [Unreleased]` heading to scope R4 to, a
         shallow clone R5 cannot measure growth in, or an authority that moved. Someone fixes
         the repo or this guard. The split matters: a 1 and a 2 send different people to
         different files, and collapsing them would send authors to rename branches that were
         fine.
A state this guard cannot measure is never a pass. An unmeasurable state reported green is the
false-clean class this repo has spent weeks removing (`docs/VERSIONING.md § The failure
direction must be safe`), and a release gate is the last place to mint another one.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent

# --- The authorities, by path. Each is read at run time; none is restated. -------------------
TAG_WORKFLOW = ".github/workflows/auto-tag-version.yml"
RELEASE_CONFIG = ".release-pr.json"
# The vendored mover's copy of the bridge accept-set — the only spelling of the card-token
# grammar in this repo, and the same line `bin/card-token-lint.py` extracts.
CARD_GRAMMAR_SOURCE = "bin/promote-cards-by-token"
CARD_GRAMMAR_CONST = "CARD_RE"

# --- The literals with no machine-readable owner in this repo. -------------------------------
# `docs/VERSIONING.md § Release flow` step 2 ("Branch `release/v<version>` off `dev`") and
# § The release-PR head hazard ("always a throwaway `release/v<version>` branch"). Only the
# PREFIX is a literal; the version part is composed from the extracted tag format.
RELEASE_BRANCH_PREFIX = "release/"
# `docs/VERSIONING.md § The core rules` rule 3 ("The changelog lives at `docs/CHANGELOG.md`").
CHANGELOG_PATH = "docs/CHANGELOG.md"
# `docs/PLAN.md § 4` ("a line-initial `- **card#NNNN** — …` bullet under `## [Unreleased]`"),
# restated in `docs/CHANGELOG.md`'s own preamble. Both halves are literals: the heading R4 is
# scoped to, and the bullet form. "Bold-anywhere is not accepted — line-initial is the rule."
UNRELEASED_HEADING = "## [Unreleased]"

# The prose doc that owns the release-shape rules (R1-R3), named for a human who wants the
# ruling rather than the diagnosis. This guard enforces what that file says; where they
# disagree, it wins.
CANON_DOC = "docs/VERSIONING.md"
# The prose doc that owns the changelog-discipline rules (R4-R5), on the same terms.
CHANGELOG_CANON_DOC = "docs/PLAN.md § 4"

# --- R5's two policy constants. The THRESHOLD is neither of them: it is derived per run. ------
# The cliff. GitHub's contents API returns files up to 1 MiB inline and silently declines to
# carry the content of anything larger (`"content": ""`), which is the failure `docs/PLAN.md
# § 4` names — a reader gets an empty changelog rather than an error. This is an EXTERNAL fact
# about a platform, not a rule this repo owns and not one this guard can verify: § 4 is the
# in-repo record of it and is where the figure comes from.
CONTENTS_API_CLIFF_BYTES = 1024 * 1024
# The notice window, from `docs/PLAN.md § 4` ("threshold = cliff − 14 days of measured growth").
# It is a policy figure, not a measurement: it is how much warning the maintainer gets to land
# the remedy (archiving released sections is a human PR), and it is the only number here that a
# person chose. Growth over that window is MEASURED from git history on every run.
HEADROOM_DAYS = 14

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

# The card-grammar authority is a BASH single-quoted assignment: `CARD_RE='…'`. Bash single
# quotes admit no escapes, so `[^']*` consumes the whole literal. Same extraction shape as the
# two above, and the same refusal to keep a private copy.
_CARD_AUTHORITY_LINE_RE = re.compile(r"^" + CARD_GRAMMAR_CONST + r"='([^']*)'\s*$", re.M)

# A line-initial changelog bullet naming a card, in the ONE spelling `docs/PLAN.md § 4`
# accepts. `^-[ ]\*\*card#N\*\*` and nothing looser: § 4 records that bold-anywhere was tried
# upstream and that a prose mention discharged another card's obligation, which is why the
# rule is line-initial. The id is captured; the `#` form is required in the BULLET even though
# a BRANCH may spell the same card `card-8174` (that spelling is hostile in a heading and the
# doc's own example uses `#`).
_BULLET_RE = re.compile(r"^- \*\*card#([0-9]+)\*\*", re.M)

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


def load_card_grammar(repo: Path) -> re.Pattern:
    """The card-token accept grammar, extracted from the vendored mover (R4's authority).

    Same doctrine as the two loaders above and as `bin/card-token-lint.py`: the grammar has one
    home in this repo and this is not it. A private copy would decide, on the first upstream
    widening, that a token the bridge correlates carries no changelog obligation — or that one
    it drops does.
    """
    text = _read_authority(repo, CARD_GRAMMAR_SOURCE)
    found = _CARD_AUTHORITY_LINE_RE.findall(text)
    if len(found) != 1:
        raise Unmeasurable(
            f"expected exactly one `{CARD_GRAMMAR_CONST}='…'` line in {CARD_GRAMMAR_SOURCE}, "
            f"found {len(found)} — the card-token grammar authority moved, was renamed, or was "
            f"duplicated. R4 must consider exactly the tokens the correlators consider; teach "
            f"this guard the new location rather than hardcoding the grammar here.")
    pattern = found[0]
    posix = _POSIX_CLASS_RE.findall(pattern)
    if posix:
        raise Unmeasurable(
            f"`{CARD_GRAMMAR_CONST}` in {CARD_GRAMMAR_SOURCE} uses the POSIX bracket class(es) "
            f"{sorted(set(posix))}, which Python's `re` does not read the same way. Refusing to "
            f"guess a translation of an ACCEPT grammar — the same refusal "
            f"`bin/card-token-lint.py` makes about this same line.")
    try:
        return re.compile(pattern, re.I)
    except re.error as exc:
        raise Unmeasurable(f"`{CARD_GRAMMAR_CONST}` in {CARD_GRAMMAR_SOURCE} does not compile "
                           f"as a Python regex ({exc}): {pattern!r}.") from exc


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


def read_bytes_at(repo: Path, rev: str, path: str) -> bytes | None:
    """File content at a revision as BYTES, or None if the file does not exist there.

    R5 measures a SIZE, and a size is bytes. `read_at` decodes, and `len` of the decoded string
    undercounts every multi-byte character — this changelog is full of them (`⛔`, `⭐`, `§`),
    so the decoded length would report the file smaller than the API will find it, in the one
    direction a size gate must never be wrong.
    """
    try:
        r = subprocess.run(["git", "-C", str(repo), "show", f"{rev}:{path}"],
                           capture_output=True)
    except FileNotFoundError:
        raise Unmeasurable("git is not on PATH — this guard reads both sides' content with "
                           "`git show` and cannot measure anything without it.") from None
    return None if r.returncode != 0 else r.stdout


def decode_changelog(raw: bytes, where: str) -> str:
    """The changelog's text, or exit 2. A decode failure is not a verdict about the PR."""
    try:
        return raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise Unmeasurable(
            f"{CHANGELOG_PATH} at the {where} is not valid UTF-8 ({exc}) — the rules about its "
            f"headings and bullets cannot be applied to bytes this guard cannot read.") from exc


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


# --- R1-R3: release shape (base is the release branch) ---------------------------------------

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


def _heading_text(line: str) -> str | None:
    """The text of a level-2 heading line, or None if this line is not one."""
    m = re.match(r"^##[ \t]+(.*\S)[ \t]*$", line)
    return m.group(1) if m else None


def _is_unreleased_heading(heading: str) -> bool:
    """ONE predicate for 'this is the Unreleased section', used by R3 and R4 both.

    They are two halves of one statement — R3 must not accept a version sitting under it, R4
    must look for its bullets underneath it — so a second spelling of "which heading is the
    Unreleased one" could put the two rules on opposite sides of the same heading.
    """
    return "unreleased" in heading.lower()


def changelog_sections(text: str) -> list[str]:
    """Level-2 heading texts, excluding the `Unreleased` one.

    Release flow step 4 RETITLES `## [Unreleased]`, so a version sitting under an Unreleased
    heading is the not-yet-released state and must not satisfy R3. Nothing else about the
    heading's spelling is asserted: `docs/PLAN.md § 4` owns the format and this guard has no
    business ruling on it.
    """
    out = []
    for line in text.splitlines():
        h = _heading_text(line)
        if h is not None and not _is_unreleased_heading(h):
            out.append(h)
    return out


def unreleased_region(text: str) -> str | None:
    """The lines under the first `## [Unreleased]` heading, up to the next level-2 heading.

    None means there is NO Unreleased heading — which is not "no bullets", it is a changelog
    R4's rule cannot be applied to, and the caller turns it into exit 2.
    """
    lines = text.splitlines()
    start = None
    for i, line in enumerate(lines):
        h = _heading_text(line)
        if h is not None and _is_unreleased_heading(h):
            start = i + 1
            break
    if start is None:
        return None
    end = len(lines)
    for j in range(start, len(lines)):
        if _heading_text(lines[j]) is not None:
            end = j
            break
    return "\n".join(lines[start:end])


def bulleted_card_ids(region: str) -> set[str]:
    """Card ids carrying a line-initial `- **card#N**` bullet in this region."""
    return set(_BULLET_RE.findall(region))


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


# --- R4: the card's changelog bullet ---------------------------------------------------------

def cards_named(accept_re: re.Pattern, surfaces: list[tuple[str, str]]) -> dict[str, list[str]]:
    """{card id: the surfaces that named it}, first-seen order, over head ref and PR title.

    The two surfaces are the two the correlators parse (`bin/card-token-lint.py § SURFACES`);
    the PR BODY is not one of them and commit SUBJECTS deliberately are not either — see R4'S
    CARVE-OUTS item 3 in the module docstring for why keying on subjects would drag every
    back-merge into scope.
    """
    named: dict[str, list[str]] = {}
    for name, text in surfaces:
        for m in accept_re.finditer(text or ""):
            digits = re.search(r"[0-9]+", m.group(0))
            if digits is None:          # unreachable for this grammar; not defended against
                continue                # beyond skipping, because inventing an id is worse
            named.setdefault(digits.group(0), [])
            if name not in named[digits.group(0)]:
                named[digits.group(0)].append(name)
    return named


def check_card_bullets(head_region: str, base_region: str | None,
                       named: dict[str, list[str]]) -> tuple[list[str], list[str]]:
    """R4 — every card this PR names owes a line-initial bullet under `## [Unreleased]`.

    Returns (problems, notes); a note is an exemption that fired, printed so a reader can see
    the gate DECIDED rather than skipped.
    """
    at_head = bulleted_card_ids(head_region)
    at_base = bulleted_card_ids(base_region) if base_region is not None else set()
    problems, notes = [], []
    for cid, surfaces in named.items():
        if cid in at_head:
            continue
        if cid in at_base:
            notes.append(
                f"R4 exempt: card#{cid} had a bullet on the base and this PR REMOVES it. That "
                f"is a revert of unreleased work, which owes the deletion of an entry rather "
                f"than one more — `[Unreleased]` describes the tree as it will ship. No other "
                f"way to claim this exemption exists: it is the removal itself, not a title or "
                f"a branch name.")
            continue
        problems.append(
            f"R4 changelog bullet: this PR names card#{cid} ({', '.join(surfaces)}), but "
            f"{CHANGELOG_PATH} at the head carries no line-initial `- **card#{cid}**` bullet "
            f"under `{UNRELEASED_HEADING}`. Add one — the line must START with "
            f"`- **card#{cid}** — ` (bold anywhere else in the line does not count, and a "
            f"bullet under a released section does not either). {CHANGELOG_CANON_DOC} owns the "
            f"rule; on 2026-08-30 a sweep of `dev` found SIX cards with no entry at all, "
            f"including the whole fleet-reporter, because nothing had ever checked this. The "
            f"only case that owes no bullet is a revert of unreleased work, and it is "
            f"recognised by REMOVING the card's existing bullet, not by naming itself a revert.")
    return problems, notes


# --- R5: changelog size, against a threshold derived from this file's own growth --------------

def is_shallow(repo: Path) -> bool:
    r = _git(repo, "rev-parse", "--is-shallow-repository")
    if r.returncode != 0:
        raise Unmeasurable(
            f"`git rev-parse --is-shallow-repository` failed in {repo} ({r.stderr.strip()}) — "
            f"R5 cannot tell a full history from a truncated one, and a truncated one silently "
            f"reports no growth.")
    return r.stdout.strip() == "true"


def commit_date(repo: Path, sha: str) -> dt.datetime:
    """The head commit's own committer date — R5's reference instant.

    The wall clock is deliberately NOT used: it would give the same PR a different verdict
    tomorrow and would make every fixture in the selftest a race against the clock.
    """
    r = _git(repo, "show", "-s", "--format=%cI", sha)
    if r.returncode != 0 or not r.stdout.strip():
        raise Unmeasurable(f"cannot read the committer date of {sha[:7]} — R5's window is "
                           f"measured back from it and cannot be placed without it.")
    try:
        return dt.datetime.fromisoformat(r.stdout.strip())
    except ValueError as exc:
        raise Unmeasurable(f"git reported an unreadable committer date for {sha[:7]}: "
                           f"{r.stdout.strip()!r} ({exc}).") from exc


def size_before(repo: Path, head_sha: str, cutoff: dt.datetime) -> tuple[int, str | None]:
    """`docs/CHANGELOG.md`'s size at `cutoff`, and the commit it was read from.

    (0, None) means the file did not exist yet — a real answer for a file younger than the
    window, and the ONE answer a truncated history cannot be told apart from.

    SHALLOWNESS IS CHECKED SECOND, AND ONLY IN THE AMBIGUOUS CASE. Refusing every shallow clone
    outright would be the easy rule and it would red work that is fine: if a commit before the
    cutoff IS found, truncation cannot have affected the answer, because a shallow graft removes
    only commits OLDER than its boundary and the commit we found is newer than that boundary by
    construction. The unmeasurable state is precisely "found nothing, in a history that might
    have had something" — measured, not assumed: this repo's own working checkout is shallow at
    the root commit and can answer for every date after it.
    """
    r = _git(repo, "log", "-1", "--format=%H", f"--before={cutoff.isoformat()}",
             head_sha, "--", CHANGELOG_PATH)
    if r.returncode != 0:
        raise Unmeasurable(f"`git log` failed while looking for {CHANGELOG_PATH} as of "
                           f"{cutoff.isoformat()} ({r.stderr.strip()}).")
    sha = r.stdout.strip()
    if not sha:
        if is_shallow(repo):
            raise Unmeasurable(
                f"the repository at {repo} is a SHALLOW clone AND has no commit touching "
                f"{CHANGELOG_PATH} before {cutoff.date().isoformat()}, so R5 cannot tell "
                f"'the file did not exist {HEADROOM_DAYS} days ago' from 'that part of the "
                f"history was not fetched'. Read as the first, this guard would compute a "
                f"threshold of the whole {CONTENTS_API_CLIFF_BYTES:,} B cliff and pass "
                f"anything. Check out with full history (`fetch-depth: 0`); an unmeasurable "
                f"size is not a small size.")
        return 0, None
    blob = read_bytes_at(repo, sha, CHANGELOG_PATH)
    if blob is None:
        raise Unmeasurable(f"{CHANGELOG_PATH} is absent at {sha[:7]}, the commit `git log` "
                           f"named as its last change before {cutoff.isoformat()} — git "
                           f"contradicted itself and R5 refuses to guess a size.")
    return len(blob), sha


def check_changelog_size(head_size: int, base_size: int, threshold: int,
                         growth: int) -> tuple[list[str], list[str]]:
    """R5 — the head's changelog must sit below `cliff − the growth of the last window`.

    Failing only when this PR MAKES IT WORSE is the deliberate half. An over-threshold shared
    file that reds every PR in the repo reds the archiving PR too, and a gate that stands
    between the maintainer and its own remedy is a gate that gets deleted.
    """
    if head_size <= threshold:
        return [], []
    over = head_size - threshold
    common = (f"{CHANGELOG_PATH} is {head_size:,} B at the head, {over:,} B OVER the "
              f"{threshold:,} B threshold. That threshold is the {CONTENTS_API_CLIFF_BYTES:,} B "
              f"contents-API truncation cliff minus the {growth:,} B this file actually grew in "
              f"the last {HEADROOM_DAYS} days ({CHANGELOG_CANON_DOC}'s size gate) — so being "
              f"over it means roughly one more window's growth reaches a cliff where the API "
              f"returns the file's content as EMPTY rather than as an error. The remedy is a "
              f"human decision this gate does not make: archive released sections out of "
              f"{CHANGELOG_PATH}, or amend {CHANGELOG_CANON_DOC} deliberately.")
    if head_size > base_size:
        return [f"R5 changelog size: {common} This PR ADDS {head_size - base_size:,} B to it. "
                f"R5 refuses only the PRs that make an over-threshold file bigger; a PR that "
                f"leaves it alone or shrinks it passes with a warning, so the archiving work is "
                f"never blocked by the condition it fixes."], []
    return [], [f"R5 WARNING (not a failure): {common} This PR does not grow it "
                f"({base_size:,} B -> {head_size:,} B), so R5 does not refuse it."]


# --- Entry point -----------------------------------------------------------------------------

def _strip_ref(ref: str) -> str:
    return ref[len("refs/heads/"):] if ref.startswith("refs/heads/") else ref


def run(args) -> int:
    repo = Path(args.repo).resolve()
    base_ref = _strip_ref(args.base_ref)
    head_ref = _strip_ref(args.head_ref)
    title = args.title or ""

    release_branch = load_release_branch(repo)
    is_release_pr = base_ref == release_branch

    # Both sides' revisions are needed on EVERY path now: R4's exemption and R5's growth test
    # are both statements about the base->head difference, so a non-release PR is measured too.
    base_rev = args.base_rev if args.base_rev else f"origin/{base_ref}"
    head_sha = resolve_rev(repo, args.head_rev, "head")
    base_sha = resolve_rev(repo, base_rev, f"base (`{base_ref}` tip)")

    raw = read_bytes_at(repo, head_sha, CHANGELOG_PATH)
    if raw is None:
        raise Unmeasurable(
            f"{CHANGELOG_PATH} does not exist at the head revision ({head_sha[:7]}). Every tag "
            f"owes a changelog entry ({CANON_DOC} § The core rules, rule 3) and every "
            f"card-bearing PR owes a bullet in it ({CHANGELOG_CANON_DOC}), so its absence is "
            f"not a change with nothing to say — it is a change this guard cannot check. "
            f"Restore the file; do not remove the obligation by removing the file.")
    changelog = decode_changelog(raw, f"head revision ({head_sha[:7]})")
    head_size = len(raw)

    base_raw = read_bytes_at(repo, base_sha, CHANGELOG_PATH)
    # Absent on the base is a real state (the file was added by this PR), not missing data: it
    # means zero bytes to grow from and no bullet to have removed. Both readings fail SAFE —
    # towards R5 seeing growth and R4 refusing the exemption.
    base_size = len(base_raw) if base_raw is not None else 0
    base_changelog = (decode_changelog(base_raw, f"base revision ({base_sha[:7]})")
                      if base_raw is not None else None)

    problems: list[str] = []
    notes: list[str] = []
    applicable: list[str] = []

    print(f"release-pr-guard: {head_ref} -> {base_ref}"
          f"{' (the release branch).' if is_release_pr else '.'}")
    print(f"  authorities:  release branch {release_branch!r} <- {TAG_WORKFLOW}")

    # --- R1-R3: release shape, only on the release path --------------------------------------
    if is_release_pr:
        applicable += ["R1", "R2", "R3"]
        tag_format = load_tag_format(repo)
        version_re = load_version_pattern(repo)
        head_version = read_version(repo, head_sha, "head", version_re)
        base_version = read_version(repo, base_sha, f"`{release_branch}`", version_re)
        expected_head = RELEASE_BRANCH_PREFIX + tag_format.replace("{{version}}", head_version)

        print(f"                tag_format {tag_format!r} <- {RELEASE_CONFIG};")
        print(f"                accepted VERSION `{version_re.pattern}` <- {TAG_WORKFLOW}")
        print(f"  measured:     VERSION {head_version!r} at head {head_sha[:7]} vs "
              f"{base_version!r} at {release_branch} {base_sha[:7]}")
        print(f"  expected head branch: {expected_head!r}")

        problems += check_head_branch(head_ref, expected_head)
        problems += check_version_bump(head_version, base_version, release_branch)
        problems += check_changelog(changelog, head_version)
    else:
        print(f"  R1-R3 NOT APPLICABLE — this PR targets {base_ref!r}, and the release branch "
              f"is {release_branch!r} (from `on.push.branches` in {TAG_WORKFLOW}). The "
              f"release-SHAPE rules have nothing to say about a feature PR.")

    # --- R4: the card's changelog bullet, off the release path only ---------------------------
    # Keyed on the BASE REF exactly as `release-consistency.yml`'s back-merge exemption is: on
    # the release path, step 4 has just retitled `[Unreleased]` and emptied it by construction,
    # so requiring a bullet there would red the correctly-executed step.
    if is_release_pr:
        print(f"  R4 NOT APPLICABLE — base is the release branch: release flow step 4 empties "
              f"`{UNRELEASED_HEADING}`, so a bullet under it is not owed here. The bullets were "
              f"owed on the feature PRs this release collects.")
    else:
        accept_re = load_card_grammar(repo)
        named = cards_named(accept_re, [("head ref " + repr(head_ref), head_ref),
                                        ("PR title", title)])
        print(f"                card grammar `{accept_re.pattern}` <- {CARD_GRAMMAR_SOURCE}")
        if not named:
            print(f"  R4 NOT APPLICABLE — neither the head ref nor the PR title names a card, "
                  f"so no changelog bullet is owed ({CHANGELOG_CANON_DOC}: tokenless PRs owe "
                  f"nothing). A back-merge or a rebase lands here, by naming no card rather "
                  f"than by any exemption.")
        else:
            applicable.append("R4")
            region = unreleased_region(changelog)
            if region is None:
                raise Unmeasurable(
                    f"{CHANGELOG_PATH} at the head ({head_sha[:7]}) has no "
                    f"`{UNRELEASED_HEADING}` heading, and this PR names "
                    f"card#{', card#'.join(named)}. R4's rule is scoped to that section, so "
                    f"with no such heading the rule cannot be applied at all — which is a "
                    f"broken changelog, not a PR that satisfies it. Restore the heading "
                    f"({CHANGELOG_CANON_DOC}).")
            base_region = (unreleased_region(base_changelog)
                           if base_changelog is not None else None)
            # Per NAMED card, not the whole bullet list: on this repo that list is already
            # thirty entries long and would bury the one fact a reader wants.
            at_head = bulleted_card_ids(region)
            print(f"  measured:     cards named -> bulleted under `{UNRELEASED_HEADING}`: "
                  + ", ".join(f"card#{c}: {'yes' if c in at_head else 'NO'}" for c in named))
            r4_problems, r4_notes = check_card_bullets(region, base_region, named)
            problems += r4_problems
            notes += r4_notes

    # --- R5: changelog size, on every path ----------------------------------------------------
    applicable.append("R5")
    cutoff = commit_date(repo, head_sha) - dt.timedelta(days=HEADROOM_DAYS)
    window_size, window_sha = size_before(repo, head_sha, cutoff)
    growth = max(0, head_size - window_size)
    threshold = CONTENTS_API_CLIFF_BYTES - growth
    window_desc = (f"{window_sha[:7]} carried {window_size:,} B" if window_sha
                   else "the file did not exist yet")
    print(f"  measured:     {CHANGELOG_PATH} {head_size:,} B at the head "
          f"({base_size:,} B at the base); grew {growth:,} B since "
          f"{cutoff.date().isoformat()} ({window_desc})")
    print(f"                threshold {threshold:,} B = {CONTENTS_API_CLIFF_BYTES:,} B cliff "
          f"- {HEADROOM_DAYS} days of that measured growth ({CHANGELOG_CANON_DOC})")
    r5_problems, r5_notes = check_changelog_size(head_size, base_size, threshold, growth)
    problems += r5_problems
    notes += r5_notes

    for n in notes:
        print(f"::warning::release-pr-guard: {n}")

    if not problems:
        print(f"release-pr-guard: OK — {', '.join(applicable)} hold "
              f"({len(applicable)} rule(s) applied; the rest were NOT APPLICABLE above and "
              f"asserted nothing). This gate says nothing about the deploy verdict, the wire "
              f"verdict, whether the bump is the right SIZE ({CANON_DOC} § Release flow steps "
              f"5-6, § Bump sizing), or whether a changelog entry is any GOOD "
              f"({CHANGELOG_CANON_DOC}): those are yours.")
        return 0

    for p in problems:
        print(f"::error::release-pr-guard: {p}")
    print()
    print(f"release-pr-guard: FAIL — {len(problems)} broken, of the {len(applicable)} rule(s) "
          f"that applied to this PR ({', '.join(applicable)}). Every one of them is cheap to "
          f"fix now and expensive after the merge: on 2026-08-30 all three release rules were "
          f"broken at once, the PR merged green, the tagger failed AFTER the fact, and the "
          f"integration branch survived only because a ruleset backstop that had never been "
          f"exercised happened to hold — and a sweep the same day found six cards whose work "
          f"had merged with no changelog entry at all.")
    print(f"The rules this enforces are {CANON_DOC} § Release flow steps 2, 3, 4 and 7 (R1-R3) "
          f"and {CHANGELOG_CANON_DOC} (R4-R5). Fix the PR and push; this gate re-runs on every "
          f"push, and on a title or base change.")
    return 1


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(
        description="Refuse a PR into the release branch that is not shaped like a release "
                    "(head branch, VERSION bump, changelog section), and refuse any PR that "
                    "names a card without a changelog bullet or that pushes the changelog "
                    "past its size threshold.")
    ap.add_argument("--base-ref", required=True, help="the PR's base branch name")
    ap.add_argument("--head-ref", required=True, help="the PR's head branch name")
    ap.add_argument("--title", default="",
                    help="the PR title (R4's second surface; absent means not checked)")
    ap.add_argument("--repo", default=str(REPO_ROOT),
                    help="repo root: where the authorities live and where git runs")
    ap.add_argument("--head-rev", default="HEAD",
                    help="git revision whose content is the head's (default: HEAD)")
    ap.add_argument("--base-rev", default=None,
                    help="git revision for the base branch tip (default: origin/<base ref>)")
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

#!/usr/bin/env python3
r"""change-pr-body.py — generate a CHANGE PR's body, in the shape the fleet standard admits.

THE GAP THIS FILLS, MEASURED (card#9801 comment 5714, re-measured 2026-09-20 before this file
existed). `CLAUDE.md` tells every author that a PR body is produced by the helper script and never
hand-written, and the only helper the fleet ships is `release-pr-body`. Run against a PR into the
integration branch it refuses:

    $ release-pr-body --base origin/dev --head HEAD
    release-pr-body: could not resolve version (pass --version, or set version_extract_cmd, or
    set version_file+version_regex)                                                     [rc 2]

That refusal is CORRECT and is not a misconfiguration to fix. `release-pr-body` is structurally a
RELEASE generator — a version line, `## Bundled`, `## Release artifacts`, a tag mapping — and
forcing `--version` onto a `dev` PR would emit a body asserting a release that is not happening.
⇒ The author of the commonest PR class in this repository had exactly two options, and both break
a rule: hand-write the body, or generate a false release body. This file is the third.

⛔ IT IS DELIBERATELY NOT AN EDIT TO `release-pr-body`. That helper is FLEET-owned
(`~/.local/bin/release-pr-body` → `agent-board-toolkit`), every install runs the same copy, and a
convention that spans installs is proposed to the fleet, never adopted unilaterally by one seat.
A `--change` mode on the fleet helper is very likely the right END state — the gap is every
install's, not this repository's — but that is a proposal for the fleet to agree, and until it
does, this repository owns its own route to compliance rather than owing it to a change it cannot
make. What would move there is the EMISSION; the house map below stays local either way.

WHAT IT EMITS, AND WHY EXACTLY THIS. `skills/release-pr/SKILL.md § PR body` "governs **every** PR
body an agent writes — feature, fix, docs, dependency, release", so the closed section set binds a
change PR too. `Bundled` and `Release artifacts` come from the row its own IN table marks
`Release PRs only`, and no other admitted section is so marked, which leaves a change PR exactly:

    the scope line      one line, FIRST, naming the range this merges against its base
    ## Highlights       what the installer gets and what changes for them
    ## Upgrade warnings ONLY when the installer cannot deploy correctly without an action
                        (`--upgrade-warnings`; absent that need there is no section at all,
                        not an empty one — the IN table is explicit)
    the machine lines   `Built:`, `**Coordinated in:**`, and the attribution trailer

`CLAUDE.md § PR bodies are judged against the fleet standard` carries the map from this
repository's former house sections onto that set, and its `change-pr-body:house-map` block is what
`bin/change-pr-body.selftest.py` holds to the linter's own `ALLOWED_H2`.

⛔ IT EMITS; IT DOES NOT JUDGE. The verdict on a body is `bin/pr-body-lint.py`'s — upstream's own
program, vendored — and nothing here re-implements any part of it (canon #5: a second answer to
"does this body meet the standard" is the divergence that matters, because one of the two would be
green). The selftest drives the real linter over this generator's real output, so the claim that
the output complies is measured on every CI run rather than asserted in this paragraph.

⚠ NO COUNT IN THE SCOPE LINE, AND THE GENERATOR EXEMPTION IS THE REASON RATHER THAN AN OVERSIGHT.
The standard exempts generated output from canon #16 "where the generator RUNS" — `release-pr-body`
re-derives its tally on every run, and a release PR's body is regenerated at each base update. This
generator runs ONCE, when the PR is opened, and the branch keeps growing underneath the body it
produced; a commit tally written here would be false at the next push with nothing re-deriving it.
So the scope line names its RANGE and carries the `git log` command that re-prints the commits,
which is what the IN table asks of a hand-written scope line for the same reason.

WHAT IT REFUSES TO GUESS, rather than filling in plausibly:
  * `--built` and `--coordinated-in` are REQUIRED. A `Built:` count cannot be reconstructed from a
    tree — nothing in a worktree records how many contexts touched it — so a value this program
    invented would be a fabricated attestation, strictly worse than the gap it fills.
  * the VALUE SET for `Built:` is `built-line.md`'s and is NOT checked here. This program
    refuses only a value it could not write as one field (empty, or spanning lines); whether
    `dispatched (coder ×2 / mechanic ×0)` is the true count is plane 1's question, on the body's
    edit history, and a second copy of that value set here is the duplication canon #5 forbids.
  * the JUDGEMENT sections are left as `<!-- AUTHOR: … -->` markers — the same marker
    `release-pr-body` leaves on `## Highlights`, so one grep (`<!-- AUTHOR:`) finds an unfilled
    body of either kind. What belongs in them is the standard's call, not this program's.

Exit codes: 0 = a body on stdout | 2 = refused, with the cause named on stderr. STDOUT IS THE BODY
AND ONLY THE BODY; the checklist of what the author must still fill goes to STDERR, so
`change-pr-body.py … > body.md` writes a body and not a body with diagnostics welded into it.
"""
from __future__ import annotations

import argparse
import subprocess
import sys

# The attribution trailer's first line, fixed. Which AGENT produced the PR is appended to THIS
# line (`--agent`) rather than written as a `FROM:` line at the top: operator directive,
# 2026-09-17, fleet-wide. `pr-body-lint.py`'s `attribution-line` rule reds the `FROM:` spelling.
MODEL_LINE = "🤖 Generated with [Claude Code](https://claude.com/claude-code)"

AUTHOR_MARK = "<!-- AUTHOR:"

HIGHLIGHTS_PLACEHOLDER = (
    "<!-- AUTHOR: one line per substantive change, in the INSTALLER's terms — the command that\n"
    "     now refuses, the file that must exist before first run, the behaviour that changed\n"
    "     under a key they already set. Never the builder's terms (the symbol that moved, the\n"
    "     guard added, the copies deleted): a change with no installer-visible face gets NO line\n"
    "     here and is carried by `docs/CHANGELOG.md`. Delete this comment when you have written\n"
    "     them. -->")

UPGRADE_PLACEHOLDER = (
    "<!-- AUTHOR: an INSTRUCTION with its ordering, not a description of the change — the\n"
    "     migration to run, the config key that must exist before deploy, the restart, the\n"
    "     credential a tool now needs. The bar is ACTION: if the installer can deploy correctly\n"
    "     without reading this, the section does not belong in the body at all (re-run without\n"
    "     --upgrade-warnings). Delete this comment when you have written it. -->")


def refuse(message: str) -> int:
    sys.stderr.write("change-pr-body: %s\n" % message)
    return 2


def git(*args: str) -> tuple[int, str]:
    """`(rc, stdout-stripped)` for one git command. stderr is left to the caller's terminal."""
    proc = subprocess.run(["git", *args], capture_output=True, text=True)
    return proc.returncode, proc.stdout.strip()


def resolve_base(base: str) -> tuple[str | None, str]:
    """The base branch as `(commit-ish that exists here, the name to PRINT)`.

    `origin/<base>` is preferred over a local `<base>` deliberately: the PR merges into the
    REMOTE's branch, a stale local `dev` is the ordinary state of a worktree, and a range cut
    against it would name commits the PR does not carry. The printed name stays the plain branch
    name, because that is what the PR's base is called on GitHub.
    """
    for candidate in ("origin/%s" % base, base):
        rc, _ = git("rev-parse", "--verify", "--quiet", "%s^{commit}" % candidate)
        if rc == 0:
            return candidate, base
    return None, base


def build_body(scope_line: str, highlights: str, upgrade: str | None,
               built: str, coordinated_in: str, agent: str | None,
               session_url: str | None) -> str:
    parts = [scope_line, "", "## Highlights", "", highlights, ""]
    if upgrade is not None:
        parts += ["## Upgrade warnings", "", upgrade, ""]
    parts += ["Built: %s" % built, "**Coordinated in:** %s" % coordinated_in, ""]
    parts.append(MODEL_LINE + (" — %s" % agent if agent else ""))
    if session_url:
        parts += ["", session_url]
    return "\n".join(parts) + "\n"


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(
        prog="change-pr-body.py",
        description="Emit a CHANGE PR's body in the shape `skills/release-pr/SKILL.md § PR body` "
                    "admits, with the machine-read lines in place and the judgement sections "
                    "left for the author. For a RELEASE PR use `release-pr-body` instead.",
        epilog="exit: 0 = body on stdout | 2 = refused (cause on stderr)")
    ap.add_argument("--built", required=True,
                    help="the `Built:` value, verbatim. REQUIRED: the value set is "
                         "`built-line.md`'s and the count is yours to attest — this program "
                         "never invents one.")
    ap.add_argument("--coordinated-in", required=True, dest="coordinated_in",
                    help="the `**Coordinated in:**` value, verbatim (e.g. `card#9801`).")
    ap.add_argument("--base", default="dev",
                    help="the branch this PR merges INTO (default: %(default)s)")
    ap.add_argument("--head", default="HEAD",
                    help="the branch this PR merges FROM (default: %(default)s)")
    ap.add_argument("--agent",
                    help="appended to the model-attribution line, which is where the producing "
                         "agent is recorded (operator directive 2026-09-17). A PR body carries "
                         "no `FROM:` line.")
    ap.add_argument("--session-url",
                    help="the session URL for the attribution trailer. Omitted when not given, "
                         "rather than invented.")
    ap.add_argument("--upgrade-warnings", action="store_true",
                    help="emit `## Upgrade warnings`. Leave it off unless the installer cannot "
                         "deploy or upgrade correctly without an action: the standard admits no "
                         "empty one.")
    args = ap.parse_args(argv)

    for name, value in (("--built", args.built), ("--coordinated-in", args.coordinated_in)):
        if not value.strip():
            return refuse("%s is empty. The field is machine-read off the body and an empty one "
                          "reads as absent, which is the defect the field exists to make "
                          "visible." % name)
        if "\n" in value or "\r" in value:
            return refuse("%s spans more than one line. Both fields are parsed as ONE line and "
                          "only the first would be read." % name)

    # THE SAME SHAPE, AUDITED ACROSS THE OTHER FIELDS THAT LAND ON ONE LINE (canon #7). `--agent`
    # and `--session-url` are not machine-read, so they get their own reason rather than the one
    # above: each is written INTO the attribution trailer, and a value carrying a newline splits
    # that trailer into text the author never wrote and would have to notice to fix.
    for name, value in (("--agent", args.agent), ("--session-url", args.session_url)):
        if value is not None and ("\n" in value or "\r" in value):
            return refuse("%s spans more than one line, and it is written into the attribution "
                          "trailer as one." % name)

    rc, _ = git("rev-parse", "--git-dir")
    if rc != 0:
        return refuse("this is not a git repository, so there is no range to name.")

    base_ref, base_name = resolve_base(args.base)
    if base_ref is None:
        return refuse("neither `origin/%s` nor `%s` resolves to a commit here — name the branch "
                      "this PR merges into with --base, and fetch it first." % (args.base,
                                                                                args.base))

    rc, head_sha = git("rev-parse", "--verify", "--quiet", "%s^{commit}" % args.head)
    if rc != 0:
        return refuse("`%s` does not resolve to a commit here (--head)." % args.head)

    head_name = args.head
    if args.head == "HEAD":
        rc, branch = git("rev-parse", "--abbrev-ref", "HEAD")
        if rc != 0 or branch == "HEAD":
            return refuse("HEAD is detached, so the body cannot name the branch this PR merges "
                          "from — pass --head <branch>, or check the branch out.")
        head_name = branch

    rc, merge_base = git("merge-base", base_ref, head_sha)
    if rc != 0 or not merge_base:
        return refuse("`%s` and `%s` share no merge base, so there is no range this PR merges."
                      % (base_ref, head_name))

    rc, revs = git("rev-list", "%s..%s" % (merge_base, head_sha))
    if rc != 0:
        return refuse("could not list the range `%s..%s`." % (merge_base, head_sha))
    if not revs:
        # NOT a warning. A body describing an empty range describes nothing, and the author is
        # one commit away from a real one; emitting it would produce a scope line whose own
        # command prints nothing.
        return refuse("`%s` carries no commit that `%s` does not, so this PR has nothing to "
                      "merge. Commit first, then generate the body." % (head_name, base_name))

    short = merge_base[:12]
    scope_line = ("**`%s` → `%s`.** Merges the commits this branch carries since `%s`, which "
                  "`git log --oneline %s..%s` re-prints." % (head_name, base_name, short,
                                                             short, head_name))

    body = build_body(scope_line, HIGHLIGHTS_PLACEHOLDER,
                      UPGRADE_PLACEHOLDER if args.upgrade_warnings else None,
                      args.built.strip(), args.coordinated_in.strip(), args.agent,
                      args.session_url)
    sys.stdout.write(body)

    # STDERR: what is NOT done. A generator that printed nothing here would read as "this body is
    # finished", and the sections it left are the ones that carry the whole value to the reader.
    sys.stderr.write(
        "change-pr-body: a SKELETON is on stdout. Still yours:\n"
        "  * every `%s … -->` marker — delete the marker, write the section.\n"
        "  * read it back as the person INSTALLING this, then judge it:\n"
        "      python3 bin/pr-body-lint.py --body-file <the body file>\n" % AUTHOR_MARK)
    if not args.session_url:
        sys.stderr.write("  * the attribution trailer has NO session URL (--session-url was not "
                         "given).\n")
    return 0


if __name__ == "__main__":
    # The same property upstream's `stdio-encoding` fragment pins, without vendoring a region
    # this repository would then owe a manifest row and a pin for: this program writes `🤖`, `→`
    # and `⛔`, and a stream left on the platform's codec dies INSIDE the write on the first one,
    # leaving the body file empty. Pinned here rather than at the N write sites.
    for _stream in (sys.stdout, sys.stderr):
        _stream.reconfigure(encoding="utf-8", errors="backslashreplace", newline="\n")
    sys.exit(main(sys.argv[1:]))

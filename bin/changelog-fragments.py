#!/usr/bin/env python3
"""changelog-fragments.py — one changelog note per change, assembled into the CHANGELOG at release.

THE DEFECT THIS CLOSES (card#12935). Every change PR used to write its `- **card#NNNN** — …`
bullet at the top of `## [Unreleased]` in `docs/CHANGELOG.md`, so any two PRs built side by side
edited the same lines and the second to merge conflicted. Measured on 2026-11-06: about seven
such conflicts in one day of parallel builds, each costing a builder round-trip and a CI run.
Now each change PR adds ONE small file, `docs/changelog.d/<card>.md`, and two PRs never touch
the same path; the release PR gathers the files into the version's section and deletes them.

THIS FILE IS THE ONE PRIMITIVE FOR THAT FORMAT. `bin/release-pr-guard.py` imports it (R4, R8,
R9 and R6a's fragment excuse) and the release flow runs it (`docs/VERSIONING.md § Release flow`
step 4), so the guard that admits a fragment and the assembler that consumes it cannot disagree
about what a fragment is. `docs/changelog.d/README.md` is the author-facing statement of the
format; `docs/PLAN.md § 4` owns the changelog rules themselves.

THE FORMAT, AS CHECKED HERE.
  * NAME — `<card>.md`, or `<card>-<slug>.md` for a further PR on a card whose first note is
    still unreleased (slug: lowercase letters, digits and single hyphens). `<card>` is the card
    number with no leading zero. `README.md` is the directory's own documentation and is never a
    fragment. Anything else in the directory, a subdirectory included, is refused by name: a file
    the assembler skipped would be a change that shipped with no note.
  * CONTENT — exactly ONE line, newline-terminated, in the bullet form `docs/PLAN.md § 4` names:
    `- **card#<card>** — <text>`, the card in the bullet equal to the card in the name. It is the
    line that will appear in the CHANGELOG, byte for byte, so it is written in the same style as
    the bullets already there.
ORDER. The assembled section lists the notes by card number, highest first, and by file name
within one card. It is derived from the files alone, so the same set of files gives the same
section on any machine, in any clone depth, in any merge order.

USAGE
    changelog-fragments.py check    [--repo DIR]
    changelog-fragments.py print    [--repo DIR]
    changelog-fragments.py assemble --date YYYY-MM-DD [--version X.Y.Z] [--into-existing]
                                    [--repo DIR]
`check` validates every fragment in the checkout. `print` writes the assembled bullet block to
stdout and touches nothing. `assemble` (release flow step 4) adds `## [<version>] — <date>`
directly under `## [Unreleased]` holding every fragment's line, then deletes the fragments it
used; `--version` defaults to the root `VERSION` file, which step 3 has just bumped. After the
release branch takes in later `dev` work, run it again with `--into-existing` and it folds the new
fragments into the SAME section rather than opening a second one (`assemble`'s docstring says why
that is a flag and not a guess).

Exit 0 = done. Exit 1 = refused: a fragment is malformed, or the CHANGELOG is not in a state the
assembler can write to — the message names the file and the fix. Exit 2 = bad arguments.
"""
from __future__ import annotations

import argparse
import datetime as dt
import re
import sys
from pathlib import Path
from typing import NamedTuple

REPO_ROOT = Path(__file__).resolve().parent.parent

# `docs/PLAN.md § 4` owns these three literals; `docs/changelog.d/README.md` states them to authors.
FRAGMENT_DIR = "docs/changelog.d"
README_NAME = "README.md"
CHANGELOG_PATH = "docs/CHANGELOG.md"
VERSION_PATH = "VERSION"
UNRELEASED_HEADING = "## [Unreleased]"

_NAME_RE = re.compile(r"^([1-9][0-9]*)(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?\.md$")
# The bullet form, line-initial, with the em dash `docs/PLAN.md § 4` writes. The text after it
# must start with a non-space character, so an empty note is refused.
_LINE_RE = re.compile(r"^- \*\*card#([0-9]+)\*\* — \S")
# Any line-initial card bullet, the shape `bin/release-pr-guard.py`'s `_BULLET_RE` matches.
_BULLET_RE = re.compile(r"^- \*\*card#([0-9]+)\*\*")
_DATE_RE = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}$")


class Refused(Exception):
    """The author or the release author fixes something. Always exit 1, message names the fix."""


class Fragment(NamedTuple):
    # A NamedTuple rather than a dataclass: the guard loads this file by path with importlib,
    # and a dataclass looks its module up in `sys.modules`, where a path-loaded one is absent.
    name: str     # the file name inside FRAGMENT_DIR
    card: int
    line: str     # the bullet, without its newline


def is_fragment_name(name: str) -> bool:
    """True for every name in FRAGMENT_DIR that is meant to be a fragment (all but the README)."""
    return name != README_NAME


def card_of_name(name: str) -> str | None:
    """The card id a fragment's NAME claims, or None when the name is not a fragment name."""
    m = _NAME_RE.match(name)
    return m.group(1) if m else None


def parse_fragment(name: str, raw: bytes) -> Fragment:
    """One fragment, or `Refused` naming the file and exactly what to change."""
    where = f"{FRAGMENT_DIR}/{name}"
    if "/" in name:
        raise Refused(f"{where}: a fragment lives directly in {FRAGMENT_DIR}/, never in a "
                      f"subdirectory — move it up to {FRAGMENT_DIR}/<card>.md.")
    card = card_of_name(name)
    if card is None:
        raise Refused(f"{where}: not a fragment name. Name it {FRAGMENT_DIR}/<card>.md (the card "
                      f"number, no leading zero), or <card>-<slug>.md for a further note on a "
                      f"card that already has one waiting for release.")
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise Refused(f"{where}: not valid UTF-8 ({exc}). Save it as UTF-8.") from exc
    expected = f"- **card#{card}** — <what changed, in the same style as the CHANGELOG's bullets>"
    if "\r" in text:
        raise Refused(f"{where}: carries a carriage return. Save it with Unix line endings.")
    if not text.endswith("\n") or text.count("\n") != 1:
        final = "" if text.endswith("\n") else " and no final one"
        raise Refused(f"{where}: must be exactly ONE line ending in a newline — the bullet that "
                      f"will appear in the CHANGELOG — and it has {text.count(chr(10))} "
                      f"newline(s){final}. Write it as:\n    {expected}")
    line = text[:-1]
    m = _LINE_RE.match(line)
    if not m:
        raise Refused(f"{where}: the line must start `- **card#{card}** — ` (hyphen, space, the "
                      f"bold card token, space, em dash, space) and carry the note after it. "
                      f"Write it as:\n    {expected}")
    if m.group(1) != card:
        raise Refused(f"{where}: the file is named for card#{card} but its bullet names "
                      f"card#{m.group(1)}. The name and the bullet are one statement — rename the "
                      f"file to {FRAGMENT_DIR}/{m.group(1)}.md or fix the bullet.")
    return Fragment(name=name, card=int(card), line=line)


def parse_all(entries: list[tuple[str, bytes]]) -> tuple[list[Fragment], list[str]]:
    """(fragments, problems) over (name, content) pairs; the README is skipped, nothing else."""
    frags, problems = [], []
    for name, raw in entries:
        if not is_fragment_name(name):
            continue
        try:
            frags.append(parse_fragment(name, raw))
        except Refused as exc:
            problems.append(str(exc))
    return frags, problems


def _key(f: Fragment) -> tuple[int, str]:
    """Highest card number first, then the name WITHOUT `.md` — so `30.md` sorts before
    `30-second.md` (compared whole, `-` sorts before `.` and the second part would come first)."""
    return (-f.card, f.name[:-len(".md")])


def ordered(frags: list[Fragment]) -> list[Fragment]:
    """The assembled order — a function of the files alone."""
    return sorted(frags, key=_key)


def render(frags: list[Fragment]) -> str:
    """The bullet block exactly as it appears in the CHANGELOG."""
    return "".join(f.line + "\n" for f in ordered(frags))


# --- The CHANGELOG's headings: one reading, shared with bin/release-pr-guard.py -----------------

def heading_text(line: str) -> str | None:
    """The text of a level-2 heading line, or None if this line is not one."""
    m = re.match(r"^##[ \t]+(.*\S)[ \t]*$", line)
    return m.group(1) if m else None


def is_unreleased_heading(heading: str) -> bool:
    """ONE predicate for 'this is the Unreleased section' — R3, R4, R8 and the assembler all ask."""
    return "unreleased" in heading.lower()


def unreleased_span(lines: list[str]) -> tuple[int, int] | None:
    """(index of the Unreleased heading, index of the next level-2 heading or len) — or None."""
    for i, line in enumerate(lines):
        h = heading_text(line.rstrip("\n"))
        if h is not None and is_unreleased_heading(h):
            for j in range(i + 1, len(lines)):
                if heading_text(lines[j].rstrip("\n")) is not None:
                    return i, j
            return i, len(lines)
    return None


def assemble(changelog: str, version: str, date: str, frags: list[Fragment],
             into_existing: bool = False) -> str:
    """The CHANGELOG with `## [version] — date` under `## [Unreleased]` holding every fragment.

    `into_existing` is the CATCH-UP run: the release branch took in later `dev` work after a first
    run, so the section already exists as the first one under `[Unreleased]`. The new fragments are
    folded into it and the whole bullet list is re-ordered by the same rule, and no second section
    opens. It is a flag rather than a guess because the two states look identical in the file: a
    section for this version directly under `[Unreleased]` is ALSO what the previous release left
    behind, and folding notes into a version that has already shipped would rewrite a released
    section (`docs/PLAN.md § 4`: released sections are immutable). So without the flag an existing
    section is refused, and with it a missing one is.
    """
    lines = changelog.splitlines(keepends=True)
    span = unreleased_span(lines)
    if span is None:
        raise Refused(f"{CHANGELOG_PATH} has no `{UNRELEASED_HEADING}` heading to put the release "
                      f"section under. Restore it (`docs/PLAN.md § 4`).")
    u, nxt = span
    legacy = [ln.rstrip("\n") for ln in lines[u + 1:nxt] if ln.strip()]
    if legacy:
        raise Refused(f"{CHANGELOG_PATH} carries text under `{UNRELEASED_HEADING}`, which only the "
                      f"fragments in {FRAGMENT_DIR}/ may supply now. Move each entry into its own "
                      f"fragment and delete it there, then run this again. Found:\n    "
                      + "\n    ".join(legacy))
    token = re.compile(r"\[" + re.escape(version) + r"\]")
    for k in range(nxt, len(lines)):
        h = heading_text(lines[k].rstrip("\n"))
        if h is not None and token.search(h) and k != nxt:
            raise Refused(f"{CHANGELOG_PATH} already carries a `[{version}]` section that is not "
                          f"the newest one ({h!r}). A version is released once; pick the next "
                          f"version (`docs/VERSIONING.md § Bump sizing`).")
    heading = f"## [{version}] — {date}\n"
    existing_h = heading_text(lines[nxt].rstrip("\n")) if nxt < len(lines) else None
    keyed: list[tuple[int, int, str, str]] = []
    exists = existing_h is not None and bool(token.search(existing_h))
    if exists and not into_existing:
        raise Refused(f"{CHANGELOG_PATH} already carries `{existing_h}` directly under "
                      f"`{UNRELEASED_HEADING}`. If that is the release this branch is cutting and "
                      f"it has just taken in later `{FRAGMENT_DIR}/` notes from dev, run again "
                      f"with --into-existing. If that version has already been released, bump VERSION "
                      f"first (`docs/VERSIONING.md § Release flow`, step 3): a released section is "
                      f"never added to.")
    if into_existing and not exists:
        raise Refused(f"--into-existing, but the first section under `{UNRELEASED_HEADING}` is "
                      f"{existing_h!r}, not `[{version}]`. Run without it to open the section.")
    if exists:
        end = next((k for k in range(nxt + 1, len(lines))
                    if heading_text(lines[k].rstrip("\n")) is not None), len(lines))
        for ln in lines[nxt + 1:end]:
            body = ln.rstrip("\n")
            if not body.strip():
                continue
            m = _BULLET_RE.match(body)
            if not m:
                raise Refused(f"the existing `{existing_h}` section carries a line that is not a "
                              f"card bullet, so it cannot be re-ordered safely: {body!r}. Fold "
                              f"the new fragments in by hand, or move that line out first.")
            keyed.append((-int(m.group(1)), 0, "", body))
        # Stable: an existing bullet keeps its place among its card's, ahead of any new note.
        rest = lines[end:]
    elif not frags:
        raise Refused(f"there are no fragments in {FRAGMENT_DIR}/ to assemble, so `[{version}]` "
                      f"would be an empty section. A release collects the notes its changes "
                      f"wrote; if it really has none, write its section by hand.")
    else:
        rest = lines[nxt:]
    keyed += [(_key(f)[0], 1, _key(f)[1], f.line) for f in frags]
    bullets = [body + "\n" for *_, body in sorted(keyed, key=lambda t: t[:3])]
    head = lines[:u + 1]
    if not head[-1].endswith("\n"):
        head[-1] += "\n"
    section = [heading, "\n"] + bullets + (["\n"] if rest else [])
    return "".join(head + ["\n"] + section + rest)


# --- The checkout ---------------------------------------------------------------------------------

def read_dir(repo: Path) -> list[tuple[str, bytes]]:
    """(name, bytes) for every entry in FRAGMENT_DIR; a subdirectory is reported as `<name>/x`."""
    d = repo / FRAGMENT_DIR
    if not d.is_dir():
        raise Refused(f"{d} does not exist. It holds {README_NAME} on every branch; restore it.")
    out = []
    for p in sorted(d.iterdir()):
        if p.is_dir():
            out.append((p.name + "/", b""))
        else:
            out.append((p.name, p.read_bytes()))
    return out


def _load(repo: Path) -> list[Fragment]:
    frags, problems = parse_all(read_dir(repo))
    if problems:
        raise Refused("malformed fragment(s) — nothing was changed:\n  " + "\n  ".join(problems))
    return frags


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Check, print or assemble the per-change changelog "
                                             "notes in docs/changelog.d/.")
    ap.add_argument("command", choices=("check", "print", "assemble"))
    ap.add_argument("--repo", default=str(REPO_ROOT), help="repo root (default: this checkout)")
    ap.add_argument("--version", default=None,
                    help="assemble: the version the section names (default: the VERSION file)")
    ap.add_argument("--date", default=None, help="assemble: the release date, YYYY-MM-DD")
    ap.add_argument("--into-existing", action="store_true",
                    help="assemble: fold the notes into this version's section, already written "
                         "by an earlier run on the same release branch (a catch-up with dev)")
    args = ap.parse_args(argv)
    repo = Path(args.repo).resolve()
    try:
        frags = _load(repo)
        if args.command == "check":
            print(f"changelog-fragments: OK — {len(frags)} fragment(s) in {FRAGMENT_DIR}/ are "
                  f"well-formed.")
            return 0
        if args.command == "print":
            sys.stdout.write(render(frags))
            return 0
        if args.date is None or not _DATE_RE.match(args.date):
            ap.error("assemble needs --date YYYY-MM-DD (the release's merge date)")
        try:
            dt.date.fromisoformat(args.date)
        except ValueError:
            ap.error(f"--date {args.date!r} is not a calendar date")
        version = args.version
        if version is None:
            vp = repo / VERSION_PATH
            if not vp.is_file():
                raise Refused(f"no --version given and {vp} does not exist.")
            version = vp.read_text(encoding="utf-8").strip()
        cl = repo / CHANGELOG_PATH
        new = assemble(cl.read_text(encoding="utf-8"), version, args.date, frags,
                       into_existing=args.into_existing)
        cl.write_text(new, encoding="utf-8")
        for f in frags:
            (repo / FRAGMENT_DIR / f.name).unlink()
        print(f"changelog-fragments: assembled {len(frags)} fragment(s) into `## [{version}] — "
              f"{args.date}` in {CHANGELOG_PATH} and deleted them. Commit both with `git add -A "
              f"{CHANGELOG_PATH} {FRAGMENT_DIR}`.")
        return 0
    except Refused as exc:
        print(f"changelog-fragments: REFUSED — {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""no-roundtable-refs.py — red on any reference to the fleet's PRIVATE coordination repository in a
tracked file of this PUBLIC one (card#12260).

WHY IT EXISTS. This repository is public; the fleet's cross-project coordination repository is
private. The operator ruled on 2026-10-23 that no real reference to it may sit in this repository:
no quote of one of its posts, no issue or comment number or link into it, and not its name. Every
tracked file is in scope — docs, changelogs, code comments, tests, fixtures, tooling, workflows and
CLAUDE.md — because a public reader reads all of them alike. Before this check, references had
accumulated across every one of those kinds of file; `--root` over a checkout of an earlier commit
re-prints where.

WHAT A HIT IS. One of the `FORMS` below, matched case-insensitively over the whole text of a file,
so a form split across a line break still matches:

  repo-name      the private repository's name — which also catches every link into it
  thread-number  the word for that repository followed by an issue number, with or without `#`
                 (the `#` form and the `issue N` form)
  rt-number      the fleet's two-letter short form for a thread of it, followed by a number
  share-path     a path into the fleet share tree, which is keyed by that repository's issue number

The forms are the shapes the 2026-10-23 sweep found; `--selftest` plants one of each and watches it
red. WHAT IT CANNOT SEE: a thread cited by a bare `#N` with no form word beside it (that is
indistinguishable from a PR or issue number of this repository), a bare comment id, and a quotation
of a post that names neither the repository nor a thread. Those were cleaned by hand in card#12260;
review is what catches a new one.

THE POPULATION. `git ls-files` — every tracked file — read as UTF-8 with replacement; a file holding
a NUL byte is binary and skipped (a binary can still carry the text, and that is not seen here).

EXEMPTIONS — THIS LIST ONLY SHRINKS. `EXEMPT` names a (path, exact matched text) pair this repository
cannot clean in place, with the reason and the owner who can. A pair is consumed one hit at a time, so
an exemption never covers a SECOND occurrence in the same file, and an exemption that no longer
matches anything REDS as stale — deleting the reference without deleting its row is a red, so the
list cannot outlive what it covers. Adding a row is a decision for review, not a way to go green.

Usage: python3 bin/no-roundtable-refs.py             # check this checkout (exit 1 on any hit)
       python3 bin/no-roundtable-refs.py --selftest  # plant each form and see it red; controls
       python3 bin/no-roundtable-refs.py --root DIR  # check another git checkout
"""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile

# The word for the repository, spelled so this file's own text is never a hit.
_W = "round" + "table"

FORMS = {
    "repo-name": re.compile(r"agent-" + _W, re.IGNORECASE),
    "thread-number": re.compile(r"\b" + _W + r"\b[\s(\[`'\"]*(?:issue\s*)?#?\s*\d+", re.IGNORECASE),
    "rt-number": re.compile(r"\brt\s*#\s*\d+", re.IGNORECASE),
    "share-path": re.compile(r"\bshare/issue-\d+", re.IGNORECASE),
}

# (repo-relative path, exact matched text, reason and owner). See the module docstring: only shrinks.
EXEMPT = [
    ("CLAUDE.md", _W + " #255",
     "inside the coord:solo-orientation managed block, which orientation-sync rewrites from the coord "
     "plugin's templates/solo-CLAUDE.md; an in-block edit is refused or erased by the next sync. "
     "Owner: the coord plugin (agent-board-framework); cleared when upstream's template drops it."),
]


def tracked_files(root: str) -> list[str]:
    out = subprocess.run(["git", "-C", root, "ls-files", "-z"], check=True,
                         stdout=subprocess.PIPE).stdout
    return [p for p in out.decode("utf-8", "surrogateescape").split("\0") if p]


def scan(root: str) -> tuple[list[str], int]:
    """Return (failure lines, files read)."""
    pending = {}
    for path, text, _why in EXEMPT:
        pending.setdefault((path, text), 0)
        pending[(path, text)] += 1
    failures: list[str] = []
    read = 0
    for rel in tracked_files(root):
        full = os.path.join(root, rel)
        if not os.path.isfile(full) or os.path.islink(full):
            continue
        with open(full, "rb") as fh:
            data = fh.read()
        if b"\0" in data:
            continue
        read += 1
        text = data.decode("utf-8", "replace")
        for form, rx in FORMS.items():
            for m in rx.finditer(text):
                key = (rel, m.group(0))
                if pending.get(key, 0) > 0:
                    pending[key] -= 1
                    continue
                line = text.count("\n", 0, m.start()) + 1
                failures.append("HIT %s:%d [%s] %r" % (rel, line, form, m.group(0)))
    for (path, text), left in pending.items():
        if left > 0:
            failures.append("STALE EXEMPTION %s %r — it matches nothing now; delete its EXEMPT row"
                            % (path, text))
    return failures, read


def check(root: str) -> int:
    failures, read = scan(root)
    for f in failures:
        print(f)
    if failures:
        print("no-roundtable-refs: FAIL — %d finding(s) over %d tracked text file(s). This repository "
              "is public; restate the decision in this file's own words and drop the reference."
              % (len(failures), read))
        return 1
    print("no-roundtable-refs: ok — %d tracked text file(s), %d exemption(s) still matching"
          % (read, len(EXEMPT)))
    return 0


def selftest() -> int:
    """Plant one of each form into a throwaway git repo and see each red; then the controls."""
    bad = 0

    def expect(name: str, ok: bool, detail: str = "") -> None:
        nonlocal bad
        print(("  ok     " if ok else "  FAIL   ") + name + (" — " + detail if detail and not ok else ""))
        if not ok:
            bad += 1

    def repo(files: dict[str, bytes]) -> str:
        d = tempfile.mkdtemp(prefix="no-rt-refs-")
        subprocess.run(["git", "init", "-q", d], check=True)
        for rel, data in files.items():
            p = os.path.join(d, rel)
            os.makedirs(os.path.dirname(p), exist_ok=True)
            with open(p, "wb") as fh:
                fh.write(data)
        subprocess.run(["git", "-C", d, "add", "-A"], check=True)
        return d

    exempt_file = {"CLAUDE.md": ("x " + _W + " #255 y\n").encode()}
    planted = {
        "repo-name": "see PupFuzz/agent-" + _W + " for it",
        "repo-name (link)": "https://github.com/PupFuzz/agent-" + _W + "/issues/12",
        "thread-number": "ratified on " + _W.capitalize() + " #341.",
        "thread-number (no #)": "as " + _W + " 349 measured",
        "thread-number (line break)": "cited. " + _W.capitalize() + "\n[#349](x)",
        "thread-number (issue)": "on the " + _W + " issue #12",
        "rt-number": "agreed on r" + "t#478 ask 2",
        "rt-number (spaced)": "per R" + "T #606",
        "share-path": "`share/" + "issue-347/kanban-solo/deploy.sh`",
    }
    for name, body in planted.items():
        d = repo(dict(exempt_file, **{"docs/x.md": (body + "\n").encode()}))
        failures, _ = scan(d)
        expect("planted %s reds" % name,
               len(failures) == 1 and failures[0].startswith("HIT docs/x.md"), repr(failures))

    # Controls: the bare word as a concept, and lookalikes, must pass.
    clean = ("the " + _W + " channel; a " + _W + " thread; PR #340; card#12260; art#12; "
             "start#5; sharecard/issue-3\n")
    d = repo(dict(exempt_file, **{"docs/x.md": clean.encode()}))
    failures, _ = scan(d)
    expect("control: concept mentions and lookalikes pass", failures == [], repr(failures))

    # The exemption covers exactly one occurrence, in exactly its file.
    d = repo({"CLAUDE.md": ("x " + _W + " #255 y\nz " + _W + " #255\n").encode()})
    failures, _ = scan(d)
    expect("exemption does not cover a second occurrence",
           len(failures) == 1 and "CLAUDE.md:2" in failures[0], repr(failures))
    d = repo(dict(exempt_file, **{"docs/x.md": ("x " + _W + " #255\n").encode()}))
    failures, _ = scan(d)
    expect("exemption does not cover another file",
           len(failures) == 1 and "docs/x.md" in failures[0], repr(failures))

    # A stale exemption reds.
    d = repo({"docs/x.md": b"nothing here\n"})
    failures, _ = scan(d)
    expect("stale exemption reds", len(failures) == 1 and failures[0].startswith("STALE"),
           repr(failures))

    # Untracked files are outside the population; binaries are skipped.
    d = repo(exempt_file)
    with open(os.path.join(d, "untracked.md"), "w") as fh:
        fh.write("agent-" + _W + "\n")
    failures, _ = scan(d)
    expect("untracked file is not read", failures == [], repr(failures))

    # This file and its workflow must not be hits over themselves.
    here = os.path.dirname(os.path.abspath(__file__))
    with open(os.path.abspath(__file__), "rb") as fh:
        own = fh.read().decode("utf-8")
    expect("this file's own text is not a hit",
           not any(rx.search(own) for rx in FORMS.values()))

    if bad:
        print("no-roundtable-refs --selftest: %d arm(s) FAILED" % bad)
        return 1
    print("no-roundtable-refs --selftest: every planted form red; every control passed.")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--root", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    a = ap.parse_args()
    return selftest() if a.selftest else check(a.root)


if __name__ == "__main__":
    sys.exit(main())

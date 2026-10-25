#!/usr/bin/env python3
"""no-roundtable-refs.py — red on any reference to the fleet's PRIVATE coordination repositories, or
any other private fleet name, in a tracked file of this PUBLIC one (card#12260, card#12292,
card#12325).

WHY IT EXISTS. This repository is public; the fleet's cross-project coordination repository is
private. The operator ruled on 2026-10-23 that no real reference to it may sit in this repository:
no quote of one of its posts, no issue or comment number or link into it, and not its name. Every
tracked file is in scope — docs, changelogs, code comments, tests, fixtures, tooling, workflows and
CLAUDE.md — because a public reader reads all of them alike. Before this check, references had
accumulated across every one of those kinds of file; `--root` over a checkout of an earlier commit
re-prints where.

The operator ruled again on 2026-10-24 that two other teams' coordination repositories, also
private, get the same treatment: their names, and the organisation that holds one of them, are
replaced by made-up placeholders (`synthetic-org/coordination-fixture`, the placeholder the
coordination test fixtures already use), and this check reds on them too (card#12292).

The operator ruled on 2026-10-25 that no private information may be revealed in this repository at
all. Another team's private implementation repository had been named here, which the two forms above
did not cover, and those forms spelled the private names they guard against in this file, where any
reader could reassemble them. Since card#12325 every private name is held as a DIGEST
(`PRIVATE_NAME_DIGESTS`), never as text, and one form, `private-name`, matches them all.

WHAT A HIT IS. One of the `FORMS` below, matched case-insensitively over the whole text of a file,
so a form split across a line break still matches:

  repo-name      the private repository's name, its words joined by any run of `-`, `_` or
                 whitespace (a line break included) — which also catches every link into it
  thread-number  the word for that repository, up to three non-word characters (a line break
                 included), an optional `thread`, `post` or `issue`, an optional `#`, then a number
  rt-number      the fleet's two-letter short form for a thread of it, then any run of spaces,
                 `#`, `:` or `-` (none included, so the glued form counts), then a number
  share-path     a path into the fleet share tree, which is keyed by that repository's issue number
  private-name   a private fleet name whose digest is in `PRIVATE_NAME_DIGESTS`: another team's
                 private repository, the organisation that holds one, or the distinctive label of
                 a private hostname. A file's words are its runs of ASCII letters and digits; every
                 run of 1 to `_MAX_WORDS` consecutive words separated only by `-`, `_` or whitespace
                 (a line break included) is lower-cased, joined by `-` and digested, so a name
                 matches in any case, with any of those separators, wrapped across a line, and inside
                 a link or an `org/repo` reference. A hit prints its place and length, never its text.

THE DIGEST LIST, AND ADDING A NAME. `PRIVATE_NAME_DIGESTS` holds a sha256 per name, under a fixed
prefix, and no name: the names live with the seat that maintains this repository, outside it. To add
one, pipe the name in on stdin (never argv, which a shell history keeps) and paste the line printed:

    printf '%s\n' '<name>' | python3 bin/no-roundtable-refs.py --hash

A name is up to `_MAX_WORDS` words joined by `-`, `_` or spaces; a hostname is listed by its
distinctive label (its dots are not separators, so the whole host can never match). What the digest
does NOT do is make a name unguessable: anyone who suspects a name can digest it and compare, and a
team's bare name (below) is a public starting point for a guess. It keeps the names out of the text
of this repository, which is what the ruling asks; it is not a secret.

The forms are the shapes the 2026-10-23 and 2026-10-24 sweeps found; `--selftest` plants one of each
and watches it red, and checks that the placeholder does not. The real private names cannot be planted
by a check that does not hold them, so `--selftest` plants made-up names against a made-up digest list
(the mechanism), and holds the real list to its shape and to the controls below (the data); whether a
real digest is the digest of the name meant is checked only where the name is, with `--hash`.

WHAT IT CANNOT SEE: a thread cited by a bare `#N` with no form word beside it (that is
indistinguishable from a PR or issue number of this repository), a bare comment id, a quotation of a
post that names neither the repository nor a thread, a private name whose digest is not listed, a
name of more than `_MAX_WORDS` words, a name written camelCase or glued (`acmeRepo` is one word, not
two), a name with non-ASCII letters, and private information that is not a name at all (a quoted
post, a person's detail). A team's bare name is no hit: it is also the install id, seat names and
floor slug this repository uses as example values, so only the joined repository and organisation
names are matched. The bare-number cases were cleaned by hand in card#12260 and card#12292; review
is what catches a new one.

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
import hashlib
import os
import re
import subprocess
import sys
import tempfile

# The word for the repository, spelled so this file's own text is never a hit.
_W = "round" + "table"

# Private fleet names, as digests only (card#12325). See the module docstring: the names are not in
# this repository; `--hash` prints the line for a new one. One line per name, no comment saying which.
_DIGEST_SALT = b"no-roundtable-refs/private-name/v1\0"
_MAX_WORDS = 3
PRIVATE_NAME_DIGESTS = frozenset({
    "86159ffb285537d5e3322250f2bcb96fda283403969e1e8a6589e190b222abdb",
    "ec8e677033159d1d398cc9cfea601ab29844ba2dd94229ce91c497168630f36a",
    "7f6559d29114067510cbea7593fdbb2659f5dbe05a93f426225621eb3be33043",
    "2023aa52c92aa90d363b162184b4aaf8ad71b7d5c42b790b5c74b02b35fcb22d",
    "9165240cc35d799505e4b41e1d5cc9688a4ba91ec85118c532371f251eeff17d",
    "87712d971eb87dbf01da7f5751e1aa7d7335f70e410cf834ead88e3e6628883b",
})

_WORD = re.compile(r"[A-Za-z0-9]+")
_WORD_SPLIT = re.compile(r"([A-Za-z0-9]+)")
_SEP = re.compile(r"[\s_-]+")
_NAME = re.compile(r"[A-Za-z0-9]+(?:[\s_-]+[A-Za-z0-9]+){0,%d}" % (_MAX_WORDS - 1))


def _digest(joined: str) -> str:
    return hashlib.sha256(_DIGEST_SALT + joined.encode("ascii")).hexdigest()


def name_digest(name: str) -> str:
    """The digest a name is listed by. Refuses what `private_name_hits` could never match."""
    name = name.strip()
    if not _NAME.fullmatch(name):
        raise ValueError("a name is 1 to %d runs of ASCII letters and digits joined by `-`, `_` or "
                         "spaces; list a hostname by its distinctive label" % _MAX_WORDS)
    return _digest("-".join(w.lower() for w in _WORD.findall(name)))


def _listed(text: str, digests: frozenset[str], seen: set[str]) -> bool:
    """Whether any run of words in `text` is listed — the same runs `private_name_hits` walks, built
    a column at a time so a file with no hit (nearly every file) costs no loop per word. `seen` holds
    the runs already found unlisted in earlier files, so each distinct run is digested once a scan."""
    pieces = _WORD_SPLIT.split(text)  # gap, word, gap, word, …, gap
    words = "\n".join(pieces[1::2]).lower().split("\n") if len(pieces) > 1 else []
    seps: dict[str, bool] = {}
    ok = [seps[g] if g in seps else seps.setdefault(g, bool(_SEP.fullmatch(g)))
          for g in pieces[2:-1:2]]  # ok[i]: words[i] and words[i + 1] may join
    runs = set(words)
    col: list = words
    for n in range(2, _MAX_WORDS + 1):
        col = [p + "-" + w if p is not None and f else None
               for p, w, f in zip(col, words[n - 1:], ok[n - 2:])]
        runs.update(col)
    runs.discard(None)
    runs -= seen
    if any(_digest(r) in digests for r in runs):
        return True
    seen |= runs
    return False


def private_name_hits(text: str, digests: frozenset[str], seen: set[str]) -> list[tuple[int, int]]:
    """(start, end) of every run of words in `text` whose joined form's digest is listed."""
    if not _listed(text, digests, seen):
        return []
    words = list(_WORD.finditer(text))
    hits = []
    for i, first in enumerate(words):
        parts: list[str] = []
        for j in range(i, min(i + _MAX_WORDS, len(words))):
            if j > i and not _SEP.fullmatch(text, words[j - 1].end(), words[j].start()):
                break
            parts.append(words[j].group(0).lower())
            if _digest("-".join(parts)) in digests:
                hits.append((first.start(), words[j].end()))
    return hits


FORMS = {
    "repo-name": re.compile(r"agent[\s_-]+" + _W, re.IGNORECASE),
    "thread-number": re.compile(r"\b" + _W + r"\b[^\w]{0,3}(?:(?:thread|post|issue)\s*)?#?\s*\d+",
                                re.IGNORECASE),
    "rt-number": re.compile(r"\brt[\s#:-]*\d+", re.IGNORECASE),
    "share-path": re.compile(r"\bshare/issue-\d+", re.IGNORECASE),
}
PRIVATE_FORM = "private-name"

# (repo-relative path, exact matched text, reason and owner). See the module docstring: only shrinks.
EXEMPT = [
    ("CLAUDE.md", _W + " #255",
     "line 188, inside the coord:solo-orientation managed block (its BEGIN marker is line 1 and its "
     "END marker line 764 of CLAUDE.md, verified 2026-10-23), which orientation-sync rewrites from the coord "
     "plugin's templates/solo-CLAUDE.md; an in-block edit is refused or erased by the next sync. "
     "Owner: the coord plugin (agent-board-framework); cleared when upstream's template drops it."),
]


def tracked_files(root: str) -> list[str]:
    out = subprocess.run(["git", "-C", root, "ls-files", "-z"], check=True,
                         stdout=subprocess.PIPE).stdout
    return [p for p in out.decode("utf-8", "surrogateescape").split("\0") if p]


def scan(root: str, digests: frozenset[str] = PRIVATE_NAME_DIGESTS) -> tuple[list[str], int]:
    """Return (failure lines, files read). `digests` is the private-name list; `--selftest` swaps in
    a made-up one."""
    pending = {}
    for path, text, _why in EXEMPT:
        pending.setdefault((path, text), 0)
        pending[(path, text)] += 1
    failures: list[str] = []
    read = 0
    seen: set[str] = set()
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
        spans = [(form, m.start(), m.end()) for form, rx in FORMS.items() for m in rx.finditer(text)]
        spans += [(PRIVATE_FORM, a, b) for a, b in private_name_hits(text, digests, seen)]
        for form, start, end in spans:
            key = (rel, text[start:end])
            if pending.get(key, 0) > 0:
                pending[key] -= 1
                continue
            line = text.count("\n", 0, start) + 1
            shown = ("%d characters, not echoed" % (end - start) if form == PRIVATE_FORM
                     else repr(text[start:end]))
            failures.append("HIT %s:%d [%s] %s" % (rel, line, form, shown))
    for (path, text), left in pending.items():
        if left > 0:
            failures.append("STALE EXEMPTION %s %r — it matches nothing now; delete its EXEMPT row"
                            % (path, text))
    return failures, read


def hash_names(lines) -> int:
    """`--hash`: print the `PRIVATE_NAME_DIGESTS` line for each name on stdin, never the name."""
    rc = 0
    for n, raw in enumerate(lines, 1):
        if not raw.strip():
            continue
        try:
            d = name_digest(raw)
        except ValueError as e:
            print("line %d: refused — %s" % (n, e), file=sys.stderr)
            rc = 1
            continue
        print('    "%s",' % d)
        print("line %d: %s" % (n, "already listed" if d in PRIVATE_NAME_DIGESTS else "NOT listed yet"),
              file=sys.stderr)
    return rc


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
    """Plant one of each form into a throwaway git repo and see each red; then the controls. Every
    throwaway repo sits under one temporary directory, removed when the run ends."""
    with tempfile.TemporaryDirectory(prefix="no-rt-refs-") as scratch:
        return _selftest(scratch)


def _selftest(scratch: str) -> int:
    bad = 0

    def expect(name: str, ok: bool, detail: str = "") -> None:
        nonlocal bad
        print(("  ok     " if ok else "  FAIL   ") + name + (" — " + detail if detail and not ok else ""))
        if not ok:
            bad += 1

    def repo(files: dict[str, bytes]) -> str:
        d = tempfile.mkdtemp(dir=scratch)
        subprocess.run(["git", "init", "-q", d], check=True)
        for rel, data in files.items():
            p = os.path.join(d, rel)
            os.makedirs(os.path.dirname(p), exist_ok=True)
            with open(p, "wb") as fh:
                fh.write(data)
        subprocess.run(["git", "-C", d, "add", "-A"], check=True)
        return d

    ex_text = EXEMPT[0][1]  # the real EXEMPT row's text, never a second copy of it
    exempt_file = {"CLAUDE.md": ("x " + ex_text + " y\n").encode()}
    # Every number below is invented for the plant (9001 and up); none names a real thread.
    planted = {
        "repo-name": "see PupFuzz/agent-" + _W + " for it",
        "repo-name (link)": "https://github.com/PupFuzz/agent-" + _W + "/issues/9001",
        "repo-name (underscore)": "the agent_" + _W.upper() + " repo",
        "repo-name (space)": "the Agent " + _W.capitalize() + " repository",
        "repo-name (line break)": "the agent-\n" + _W + " repository",
        "thread-number": "ratified on " + _W.capitalize() + " #9002.",
        "thread-number (no #)": "as " + _W + " 9003 measured",
        "thread-number (line break)": "cited. " + _W.capitalize() + "\n[#9004](x)",
        "thread-number (issue)": "on the " + _W + " issue #9005",
        "thread-number (thread)": "on the " + _W + " thread 9006",
        "thread-number (post)": "in " + _W + ": post #9007",
        "thread-number (colon)": "see " + _W + ":9008",
        "rt-number": "agreed on r" + "t#9009 ask 2",
        "rt-number (spaced)": "per R" + "T #9010",
        "rt-number (glued, upper)": "per R" + "T9011",
        "rt-number (colon)": "per r" + "t:9012",
        "rt-number (dash)": "per r" + "t-9013",
        "share-path": "`share/" + "issue-9014/kanban-solo/deploy.sh`",
    }
    for name, body in planted.items():
        d = repo(dict(exempt_file, **{"docs/x.md": (body + "\n").encode()}))
        failures, _ = scan(d)
        expect("planted %s reds" % name,
               len(failures) == 1 and failures[0].startswith("HIT docs/x.md"), repr(failures))

    # private-name — THE MECHANISM, on made-up names against a made-up digest list (the real names
    # are not in this repository to plant). A one-, two- and three-word name, each in every separator,
    # a case variant, wrapped across a line, and inside a link and an `org/repo` reference.
    fake = ("hostlabel", "zz-private-org", "acme-secret-repo")
    fake_digests = frozenset(name_digest(n) for n in fake)
    private_plants = {}
    for n in fake:
        words = n.split("-")
        private_plants[n + " (dash, ref)"] = "see Org/" + "-".join(words) + "#9022 for it"
        private_plants[n + " (underscore, upper)"] = "the " + "_".join(words).upper() + " repo"
        private_plants[n + " (space, title)"] = "the " + " ".join(w.capitalize() for w in words) + " one"
        private_plants[n + " (line break)"] = "the " + "\n".join(words) + " one"
        private_plants[n + " (wrapped dash)"] = "the " + "-\n  ".join(words) + " one"
        private_plants[n + " (repo link)"] = "https://github.com/x/" + "-".join(words) + "/issues/9023"
        private_plants[n + " (org link)"] = "https://github.com/" + "-".join(words) + "/x/pull/9024"
        private_plants[n + " (host label)"] = "https://kanban." + "-".join(words) + ".example/b/14"
        private_plants[n + " (longer name)"] = "the " + "-".join(words) + "-fork repo"
    for name, body in private_plants.items():
        d = repo(dict(exempt_file, **{"docs/x.md": (body + "\n").encode()}))
        failures, _ = scan(d, fake_digests)
        expect("planted private-name %s reds, without echoing the name" % name,
               len(failures) == 1 and failures[0].startswith("HIT docs/x.md:")
               and "[private-name]" in failures[0]
               and not any(w in failures[0].lower().replace("[private-name]", "")
                           for w in name.split(" ")[0].split("-")),
               repr(failures))
        # CONTROL: the same plant passes with no digest listed — the list is what fired.
        failures, _ = scan(d, frozenset())
        expect("control: planted private-name %s passes with an empty digest list" % name,
               failures == [], repr(failures))
    # CONTROL: lookalikes of the made-up names pass under the made-up list.
    lookalike = ("hostlabels; host-label; acmesecretrepo; acme.secret.repo; acme/secret/repo; "
                 "acme secret repos; zz-private-organisation; zzprivate-org\n")
    d = repo(dict(exempt_file, **{"docs/x.md": lookalike.encode()}))
    failures, _ = scan(d, fake_digests)
    expect("control: private-name lookalikes pass", failures == [], repr(failures))
    # The digest a name is listed by: any case or separator gives one digest; what could never match
    # is refused rather than listed.
    expect("name_digest is the same for every case and separator",
           len({name_digest(v) for v in ("acme-secret-repo", "Acme_Secret  repo", "ACME secret-REPO",
                                           " acme-secret-repo\n")}) == 1)
    for refused in ("kanban.example.com", "a-b-c-d-e", "", "-acme", "acme/secret"):
        try:
            name_digest(refused)
            ok = False
        except ValueError:
            ok = True
        expect("name_digest refuses %r" % refused, ok)
    proc = subprocess.run([sys.executable, os.path.abspath(__file__), "--hash"],
                          input="acme-secret-repo\n", capture_output=True, text=True)
    expect("--hash prints the digest line and never the name",
           proc.returncode == 0 and name_digest("acme-secret-repo") in proc.stdout
           and "acme" not in proc.stdout and "NOT listed yet" in proc.stderr, repr(proc))
    proc = subprocess.run([sys.executable, os.path.abspath(__file__), "--hash"],
                          input="kanban.example.com\n", capture_output=True, text=True)
    expect("--hash refuses a name that could never match", proc.returncode == 1
           and proc.stdout == "", repr(proc))

    # private-name — THE DATA: the real list is well-formed. Whether each digest is the digest of the
    # name meant is checked where the names are, with `--hash` (module docstring).
    expect("PRIVATE_NAME_DIGESTS is non-empty and every entry is a sha256 hex digest",
           bool(PRIVATE_NAME_DIGESTS)
           and all(re.fullmatch(r"[0-9a-f]{64}", h) for h in PRIVATE_NAME_DIGESTS),
           repr(sorted(PRIVATE_NAME_DIGESTS)))

    # Controls: the bare word as a concept, and lookalikes, must pass.
    clean = ("the " + _W + " channel; a " + _W + " thread; PR #9015; card#9016; art#9017; "
             "start#9018; sharecard/issue-9019; smart 9020; agentroundup; part-9021\n")
    d = repo(dict(exempt_file, **{"docs/x.md": clean.encode()}))
    failures, _ = scan(d)
    expect("control: concept mentions and lookalikes pass", failures == [], repr(failures))

    # Control: the placeholder the private names were replaced by, and each team's bare name as the
    # install id / seat / floor slug this repository uses as example values, must pass.
    teams = ("aimla", "sola")
    placeholder = ('"thread_ref": "synthetic-org/coordination-fixture#900742", '
                   '"post_ref": "synthetic-org/coordination-fixture#900742-c900743"; '
                   + "; ".join("install %s, seat %s-pm, the %s floor, %scoordination, %s-orgs"
                               % (t, t, t, t, t) for t in teams) + "\n")
    d = repo(dict(exempt_file, **{"docs/x.md": placeholder.encode()}))
    failures, _ = scan(d)
    expect("control: the placeholder and bare team names pass", failures == [], repr(failures))

    # The exemption covers exactly one occurrence, in exactly its file.
    d = repo({"CLAUDE.md": ("x " + ex_text + " y\nz " + ex_text + "\n").encode()})
    failures, _ = scan(d)
    expect("exemption does not cover a second occurrence",
           len(failures) == 1 and "CLAUDE.md:2" in failures[0], repr(failures))
    d = repo(dict(exempt_file, **{"docs/x.md": ("x " + ex_text + "\n").encode()}))
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
           not any(rx.search(own) for rx in FORMS.values())
           and private_name_hits(own, PRIVATE_NAME_DIGESTS, set()) == [])

    if bad:
        print("no-roundtable-refs --selftest: %d arm(s) FAILED" % bad)
        return 1
    print("no-roundtable-refs --selftest: every planted form red; every control passed.")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--hash", action="store_true",
                    help="read private names on stdin, one per line; print each one's digest line")
    ap.add_argument("--root", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    a = ap.parse_args()
    if a.hash:
        return hash_names(sys.stdin)
    return selftest() if a.selftest else check(a.root)


if __name__ == "__main__":
    sys.exit(main())

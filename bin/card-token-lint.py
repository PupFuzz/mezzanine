#!/usr/bin/env python3
"""card-token-lint.py — fail a PR whose branch name or title carries a card-ish token
the kanban correlators CANNOT parse.

THE DEFECT THIS CLOSES — a silent no-op class.
A PR is correlated to its board-14 card by ONE grammar, in two places: the webhook bridge
(`GitHubPrCardMoveClassifier`, which moves the card as the PR opens/merges) and this repo's
`bin/promote-cards-by-token` (which promotes it on release). A token that LOOKS like a card
reference but does not match that grammar — plural `cards #7343` in a title, `card_7343`,
`card #7343`, single-digit glued `card4` — is not an error anywhere: the bridge logs a
WARNING and DROPS the event, which from the board's side is indistinguishable from a PR that
legitimately names no card. The card silently stops tracking reality and somebody moves it by
hand weeks later. CI is the first surface where that near-miss can be made loud to the person
who can still fix it for free: the author, before merge, while renaming the branch costs
nothing.

WHAT THIS LINT ASSERTS, and what it deliberately does not:
  * It NEVER requires a card token. A PR that names no card at all is valid and passes. That
    is the whole reason the near-miss is invisible, and turning this into a "must cite a card"
    gate would be a different (unasked, and wrong) policy.
  * It fails ONLY on a token that LOOKS like a card reference but does not parse.

GRAMMAR SOURCING — ONE COPY IN THIS REPO, AND IT IS NOT HERE. The accept-set is EXTRACTED at
run time from `CARD_RE` in `bin/promote-cards-by-token` (see AUTHORITY_SOURCE below), which is
itself a vendored copy of the upstream mover whose accept-set is pinned to the bridge constant.
Retyping the grammar here would make this a second hand-maintained copy with nothing binding
the two — exactly the duplication that lets one grammar bug multiply. If the authority line
moves or is renamed, extraction FAILS LOUDLY (exit 2) rather than falling back to a stale
literal: a lint that silently reverts to a guessed grammar is worse than no lint. So when the
mover is re-vendored after an upstream widening, this gate inherits the new verdicts with no
edit here — which is the point. A gate that hardcodes an accept-set becomes, on the first
upstream widening, a gate that rejects spellings the bridge accepts; that is worse than no
gate, because it is confidently wrong at the one moment the author is already confused.

THE DETECTOR IS HAND-WRITTEN, AND THAT IS NOT AN INCONSISTENCY. `CARDISH_RE` below answers a
different question from the accept grammar — "did the author MEAN a card reference?" — and it
is deliberately BROADER than both the accept grammar and the mover's own `NEAR_MISS_RE`. The
mover's probe does not match the PLURAL form at all (`cards #7343`), and the plural is an
OBSERVED near-miss shape upstream, so extracting it here would have inherited a blind spot.
The superset relation is not asserted by this comment: `bin/card-token-lint.selftest.py` § 4
extracts `NEAR_MISS_RE` from the mover and measures that every near-miss it flags is also
card-ish here.

PER-OCCURRENCE, NOT PER-STRING — the deliberate divergence from the mover's own near-miss
warning, which goes quiet when the SAME line also carries a parseable token. That suppression
is right for a warning (the release did correlate to something) and wrong for a gate: a
well-formed `card#7343` sitting beside `cards #7344/#7345` would rescue a title whose other
two cards are lost silently. That rescue is luck, not design. Here every card-ish occurrence
is judged on its own.

SURFACES: branch name (head ref) and PR title — the two the bridge parses. The PR BODY is
deliberately NOT linted: the bridge never reads it, so a card-ish token there correlates
nothing and rejecting it would be inventing a rule.

KNOWN, ACCEPTED FALSE POSITIVE: an ordinary word of the shape `card<single-digit><letters>`
(`card2go`) is card-ish but does not parse, so it fails. That is the price of the accept
grammar's two-digit floor on the glued form, and the floor exists precisely so such a word
does NOT silently name card 2. The escape is to rename the branch.

USAGE
    card-token-lint.py [--branch REF] [--title TEXT] [--grammar-source PATH]
Each surface is optional; an absent surface is simply not checked. Exit 0 = clean; 1 = at
least one unparseable card-ish token; 2 = the lint could not run (grammar extraction failed).
Emits GitHub `::error::` annotations when running under Actions.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent

# The in-repo grammar authority (see GRAMMAR SOURCING above): the vendored mover's POSIX-ERE
# copy of the bridge accept-set. It is the only spelling of the grammar in this repo.
AUTHORITY_SOURCE = "bin/promote-cards-by-token"
ACCEPT_CONST = "CARD_RE"

# The upstream prose owner of the accept-set, named for a human who wants the ruling rather
# than the regex. Cross-repo on purpose — this repo does not restate the grammar anywhere,
# including in docs/KANBAN.md.
CANON_DOC = ("PupFuzz/agent-board-framework "
             "plugins/coord/docs/BRIDGE-WRITEBACK.md § The `card#<task-id>` convention")

# CARD-ISH DETECTOR — the "did the author MEAN a card reference?" probe, and the only
# hand-written pattern here (see THE DETECTOR IS HAND-WRITTEN above).
#   `cards?`               singular AND plural; the plural spaced form is an observed
#                          near-miss shape that the mover's own probe does not flag at all.
#   `(?:[-#_:.]|\s#){0,2}` up to two separator UNITS: covers glued (0), `card-`/`card#`/
#                          `card_`/`card:`/`card.` (1) and `card #`/`cards #` (the two-char
#                          unit `\s#`). BOUNDED at 2 so it cannot bridge an arbitrary gap and
#                          pair an unrelated "cards" with a distant number.
#   leading `\b`           same left anchor as the accept grammar, so `discard 5 items`,
#                          `wildcards 3` and a bare `#123` PR reference are NOT card-ish.
#   `\s#` and not `\s`     A BARE SPACE IS DELIBERATELY NOT A SEPARATOR. "supports card 2 in
#                          prose" is English, and PROSE MUST STAY SILENT — making a bare space
#                          a separator reds an ordinary title and tells its author to rename a
#                          token they never wrote, which is the inverse of the defect this
#                          lint exists to fix and the surest way to get the gate switched off
#                          by the first person it annoys. `\s` rather than a literal space so
#                          a tab-then-hash is still caught.
#   `\d`, not `[0-9]`      ON PURPOSE, and NOT a drift from the ASCII-only accept grammar:
#                          this is the DETECTOR. It must still SEE `card#٣` in order to
#                          REJECT it; narrowing it to ASCII would wave a Unicode-digit token
#                          straight through to a correlator that drops it silently.
#   ends at the digits     any suffix (`card#3054_fix`) is irrelevant to the verdict, matching
#                          the accept grammar's left-anchor-only rule.
CARDISH_RE = re.compile(r"\bcards?(?:[-#_:.]|\s#){0,2}\d+", re.I)

# The authority is a BASH single-quoted assignment: `CARD_RE='…'`. Bash single quotes cannot
# contain a single quote, so `[^']*` consumes the whole literal with no escape handling.
_AUTHORITY_LINE_RE = re.compile(r"^CARD_RE='([^']*)'\s*$", re.M)

# POSIX ERE constructs that do NOT mean the same thing to Python's `re`. Only the bracket
# CLASSES matter in practice (`[[:space:]]` is a syntax error in Python before 3.12 and a
# character set of `[:space]` after), and the mover's near-miss pattern already uses one — so
# this is a live hazard, not a hypothetical. The guard REFUSES rather than translating: a
# translator is a second implementation of somebody else's regex dialect, and a silent
# mistranslation of an ACCEPT grammar is precisely the confidently-wrong gate this file's
# GRAMMAR SOURCING section refuses to become. If an upstream widening ever puts one in
# `CARD_RE`, this exits 2 and a human decides what the Python spelling should be.
_POSIX_CLASS_RE = re.compile(r"\[:[a-z]+:\]")


def load_accept_pattern(source: Path) -> str:
    """Extract `CARD_RE`'s literal pattern from the mover, as written.

    Raises SystemExit(2) — never returns a fallback. Exit 2, NOT 1: "the lint is broken" must
    be distinguishable from "this PR carries a bad token", because a broken lint reported as a
    violation sends the author renaming a branch that was already fine.
    """
    if not source.is_file():
        print(f"::error::card-token-lint: grammar authority not found at {source} — the lint "
              f"cannot certify anything without it (no silent fallback).", file=sys.stderr)
        raise SystemExit(2)
    matches = _AUTHORITY_LINE_RE.findall(source.read_text(encoding="utf-8"))
    if len(matches) != 1:
        print(f"::error::card-token-lint: expected exactly one `{ACCEPT_CONST}='…'` line in "
              f"{source}, found {len(matches)} — the authority line moved, was renamed, or was "
              f"duplicated. Fix the extraction (or point --grammar-source at the new "
              f"authority); do NOT hardcode the pattern here.", file=sys.stderr)
        raise SystemExit(2)
    pattern = matches[0]
    posix = _POSIX_CLASS_RE.findall(pattern)
    if posix:
        print(f"::error::card-token-lint: `{ACCEPT_CONST}` in {source} uses the POSIX bracket "
              f"class(es) {sorted(set(posix))}, which Python's `re` does not read the same way. "
              f"Refusing to guess a translation of an ACCEPT grammar. Decide the Python "
              f"spelling deliberately and teach this lint about it.", file=sys.stderr)
        raise SystemExit(2)
    return pattern


def _digits(token: str) -> str:
    """The ASCII digits to put in the SUGGESTED spelling, or "" (→ the literal `<id>`).

    ASCII `[0-9]`, not `\\d`, and not for symmetry's sake: this feeds a fix-it hint, and a hint
    must name a spelling the accept grammar actually parses. That grammar is ASCII-only, so
    echoing a Unicode digit back — `card#٣` → "use card-٣" — would hand the author a rename
    that fails this same gate again. With no ASCII digits present the hint degrades to
    `card-<id>`, which is the honest advice.
    """
    d = re.search(r"[0-9]+", token)
    return d.group(0) if d else ""


def check_surface(name: str, text: str, accept_re: re.Pattern) -> list[str]:
    """Return one message per card-ish token in `text` that the correlators cannot parse."""
    bad: list[str] = []
    for m in CARDISH_RE.finditer(text or ""):
        token = m.group(0)
        # Parseable iff the accept grammar consumes the card-ish token WHOLE — `.match`
        # anchors the left end, `.end() == len(token)` pins the right. `card#7343`,
        # `card-7343` and glued `card7343` qualify; `cards #7343`, `card #7343`, `card_7343`
        # and single-digit glued `card4` do not (accept_re either fails outright or stops
        # short of the digits). The verdict is READ OFF the extracted authority, never
        # restated — which is why this comment names examples and not a rule.
        a = accept_re.match(token)
        if a is not None and a.end() == len(token):
            continue
        bad.append(
            f"{name} carries {token!r}, which the card-token grammar "
            f"({accept_re.pattern}) cannot parse — the board move would silently no-op. "
            f"Use card-{_digits(token) or '<id>'} (branch-ergonomic) or "
            f"card#{_digits(token) or '<id>'}."
        )
    return bad


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(
        description="Reject card-token spellings the kanban correlators cannot parse "
                    "(branch name + PR title)."
    )
    ap.add_argument("--branch", default="", help="head ref / branch name")
    ap.add_argument("--title", default="", help="PR title")
    ap.add_argument("--grammar-source", default=None,
                    help=f"path to the grammar authority (default: {AUTHORITY_SOURCE})")
    args = ap.parse_args(argv)

    source = (Path(args.grammar_source) if args.grammar_source
              else REPO_ROOT / AUTHORITY_SOURCE)
    accept_re = re.compile(load_accept_pattern(source), re.I)

    problems = check_surface("branch name", args.branch, accept_re)
    problems += check_surface("PR title", args.title, accept_re)

    if not problems:
        print("card-token-lint: OK — no card-ish token that a correlator would fail to parse.")
        print(f"  grammar: {accept_re.pattern}  (extracted from {AUTHORITY_SOURCE})")
        print(f"  branch:  {args.branch!r}")
        print(f"  title:   {args.title!r}")
        return 0

    for p in problems:
        print(f"::error::card-token-lint: {p}")
    print()
    print("card-token-lint: FAIL — a card-ish token that does not parse is WORSE than no "
          "token: the correlator logs a warning, drops it, and the card silently stops "
          "tracking reality.")
    print("Accepted spellings (one grammar, both surfaces): card#<id>, card-<id>, and glued "
          "card<id> with TWO OR MORE digits — single-digit glued (card4) does NOT parse, which "
          "is what stops an ordinary word like 'card2go' from naming a card. Prefer card-<id> "
          "in a branch name ('#' is hostile in shell and URL contexts).")
    print(f"Authority (the accept-set's single owner): {CANON_DOC}.")
    print("Rename the branch (and/or edit the PR title) and push; this gate re-runs on both.")
    return 1


if __name__ == "__main__":
    sys.exit(main())

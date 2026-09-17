#!/usr/bin/env python3
"""pr-body-fields.py — fail a PR whose body is missing an audit-trail field, judged through the
PARSER THAT ACTUALLY CONSUMES IT rather than through a pattern written here.

THE TWO REQUIRED FIELDS ARE `Built:` AND `Coordinated in:`. Both are framework contract, both are
read by one upstream function, and neither carries a name this repository has any opinion about.

THE DEFECT THIS CLOSES — a silent destruction class, not forgetful authors (card#9767).
These fields are added to a PR body BY HAND and have no generator: `release-pr-body` emits neither,
so regenerating a body DELETES them, silently, every time. That is measured. `PupFuzz/mezzanine#176`
carried its hand-added attribution, a peer re-read that body AT THE API to verify it rather than
resting on a claim, and a later regeneration destroyed the line — so a careful verification was made
FALSE by a mechanism neither seat was watching, which is worse than the original miss because the
fleet then believes the thing is fixed. `PupFuzz/mezzanine#180` carries no `Built:` line at all.
A convention with no check is a comment.

⛔ AND A MISSING `Built:` IS NOT TIDINESS. `built-line.md` makes it mandatory on every PR body with
no seat exempt, and a missing line is a defined plane-1 review finding — so every regeneration
silently mints a finding no author committed, on a PR that is then reviewed against it.

⛔ PRESENCE ONLY. THIS PROGRAM NEVER READS, PRINTS OR JUDGES A VALUE. Whether `Built:`'s value is in
the canonical set is `review-prep.py`'s question and whether the count is TRUE is a human's; this
answers only whether the line is there. Not reading the value is also why no body text ever reaches
this program's output: a PR body is attacker-influenced text, and a guard that echoed it back would
be a new emission surface for nothing.

⛔ IT ASSERTS NOTHING ABOUT ANY OTHER LINE, AND THE SILENCE IS DELIBERATE. A `FROM:` line was in this
gate's original scope and was REMOVED by operator directive on 2026-09-17: a PR body is written for
an INSTALLER to read, protocol addressing lines do not belong in a PR description, and which agent
produced the work is recorded beside the model attribution in the trailer instead. So this gate does
not require `FROM:` — and it does not REJECT one either, because whether a stray addressing line
should be refused has not been ruled, and a gate that reds on something unruled is worse than a gate
that stays silent on it. A third field naming the producing agent is coming; its spelling is not
agreed across the fleet, so there is no stub for it here. It is added when it is ruled.

⛔ ONE PARSER, NO LINE WINDOW, AND THE ABSENCE OF A WINDOW IS THE POINT. `Built:` and
`Coordinated in:` are consumed by `review-prep.py`'s `_audit_field`: line-anchored over the WHOLE
body, both the bold and the plain spelling, read OUTSIDE the body's fences. A windowed rule — "it
must be near the top" — would red bodies that are CORRECT: `PupFuzz/mezzanine#185` carries `Built:`
at line 44 and `_audit_field` reads it today. The selftest holds that case and runs a windowed grep
over the same body to show it would red.

AND A HAND-WRITTEN REGEX HERE WOULD BE THE NEXT DEFECT IN A MEASURED SERIES. `_audit_field` exists
because TWO hand-written regexes strict in OPPOSITE directions — one demanding the `**` wrapper, one
refusing it — reported a COMPLIANT PR as ABSENT, on the surface a reviewer is told to CITE rather
than re-derive, so the false accusation is what got carried into the round. Adding a third copy of
that grammar here would re-mint exactly that.

WHERE THE PARSER COMES FROM. `bin/coord_audit_field.py` is a VENDORED copy of upstream's function
and the fence primitives it needs; its header carries the provenance, why a copy rather than an
import, and exactly what its pin does and does not prove. `bin/vendor-pin-check.sh` pins its body
and runs in the same workflow, BEFORE this program's verdict is trusted.

WHAT THIS DOES NOT ASSERT, said plainly rather than left to a green run to imply:
  * not that `Built:`'s value is in the canonical set, nor that its count is TRUE; `review-prep.py`
    checks the shape and `pr-review-flow.md` Stage 1 plane 1 owns the truth question;
  * not that `Coordinated in:` names a thread that exists;
  * not that a body which regenerates LATER still carries them — this is a gate at merge time, and
    the durable fix is for the generator to EMIT the set, which is the toolkit owner's call.

USAGE
  python3 bin/pr-body-fields.py --body-file=PATH     # `-` reads the body from stdin
EXIT
  0  every required field is present and non-empty
  1  at least one is absent — the report names which, and the remedy
  2  usage error, or the body could not be read
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import coord_audit_field                                     # noqa: E402

PASS, FAIL = "PASS", "FAIL"

EXIT_OK, EXIT_MISSING, EXIT_USAGE = 0, 1, 2

# ── THE REQUIRED SET ──────────────────────────────────────────────────────────────────────────
# Both fields come from the SAME contract (`built-line.md` and the review flow it feeds) and are
# read by the SAME function, so this is a list of names and their remedies — nothing per-field to
# keep in sync, and a field is added or removed by editing this tuple and nothing else.
#
# `name` is the field's spelling WITHOUT its colon, because that is what `_audit_field` takes: it
# builds the accepted spellings — `**Name:**` and plain `Name:` — from the name itself. Writing a
# pattern here instead would be the third copy of a grammar that exists BECAUSE there were two.
REQUIRED_FIELDS = (
    ("Built",
     "add `Built: …` or `**Built:** …` on its own line, at the start of a line, with a non-blank "
     "value and OUTSIDE any fenced block — a fenced line is a quoted example, not the body's own "
     "claim. The value states how the work was produced; `built-line.md` owns the canonical forms "
     "and makes the line mandatory with no seat exempt. ⛔ NEVER RECONSTRUCT A COUNT AFTER THE "
     "FACT to satisfy this gate: a plausible number assembled from a transcript is a FABRICATED "
     "ATTESTATION and is strictly worse than the visible gap, because the gap is legible and the "
     "number is not."),
    ("Coordinated in",
     "add `Coordinated in: …` or `**Coordinated in:** …` on its own line, outside any fenced "
     "block, naming the card or thread this work was coordinated in (for example `card#9767`). "
     "It is the PR <-> coord-thread audit anchor a reviewer is told to CITE rather than "
     "re-derive. A user-direct change with no pre-existing thread is the one exemption and the "
     "author states it in the body."),
)

READER = "review-prep.py::_audit_field"
WINDOW = "the WHOLE body, outside fenced examples, in either the bold or the plain spelling"


def judge(body):
    """[(name, remedy, verdict)] for every required field, in declared order.

    The VALUE `_audit_field` returns is tested for truthiness and then DISCARDED — it is never
    returned, stored or printed. That is the presence-only rule expressed in code rather than
    trusted to every caller.
    """
    return [(name, remedy,
             PASS if coord_audit_field._audit_field(body, name) else FAIL)
            for name, remedy in REQUIRED_FIELDS]


def report(results, out):
    """Print the verdict table and the remedies. NO BODY TEXT IS EVER WRITTEN."""
    out.write("pr-body-fields — the audit-trail fields a PupFuzz/mezzanine PR body must carry\n")
    width = max(len(name) for name, _r, _v in results) + 1
    for name, _remedy, verdict in results:
        out.write("  %-4s  %-*s  %s — read by `%s` over %s\n"
                  % (verdict, width, name + ":",
                     "present, non-empty" if verdict == PASS else "ABSENT", READER, WINDOW))
    missing = [(n, r) for n, r, v in results if v == FAIL]
    if not missing:
        out.write("\nBoth required fields are present. Values are NOT read or judged here.\n")
        return
    for name, remedy in missing:
        out.write("\n%s: IS ABSENT.\n  fix: %s\n" % (name, remedy))
    out.write("\nWHY THIS IS A GATE AND NOT A REMINDER: these fields have no generator. A "
              "regenerated body drops them, silently, and a peer's verified result is then false "
              "with nothing saying so (card#9767).\n")


def main(argv):
    body_file = None
    for arg in argv[1:]:
        if arg.startswith("--body-file="):
            body_file = arg[len("--body-file="):]
        else:
            sys.stderr.write("usage: pr-body-fields.py --body-file=PATH   (`-` reads stdin)\n")
            return EXIT_USAGE
    if body_file is None:
        sys.stderr.write("usage: pr-body-fields.py --body-file=PATH   (`-` reads stdin)\n")
        return EXIT_USAGE
    try:
        if body_file == "-":
            body = sys.stdin.read()
        else:
            with open(body_file, "r", encoding="utf-8") as fh:
                body = fh.read()
    except OSError as exc:
        # A body that could not be READ is never reported as a body with no fields: that would be
        # an unreadable input rendering as a real verdict about somebody's PR.
        sys.stderr.write("pr-body-fields: the PR body could not be read: %s\n" % exc)
        return EXIT_USAGE
    results = judge(body)
    report(results, sys.stdout)
    return EXIT_OK if all(v == PASS for _n, _r, v in results) else EXIT_MISSING


if __name__ == "__main__":
    sys.exit(main(sys.argv))

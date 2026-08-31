#!/usr/bin/env python3
"""harness-fixture-drift.py — the vendored hook fixtures must BE D1 § 17, not resemble it.

THE DEFECT THIS CLOSES — a restatement with neither a pointer nor a guard (card#7946).
`fleet-reporter/fixtures/hooks/<HookEventName>.json` vendors `docs/design/EVENT-SCHEMA.md`
§ 17's captured harness payloads VERBATIM, and nothing checked that the two agree. Editing
§ 17 alone left the fixtures stale with EVERY CHECK GREEN — the reporter's own
`harness_payload_keys` check reads the fixtures, so it validates the copy against itself,
and `verify-harness-facts.py` reads the appendix, so it validates the original against the
harness binary. Neither can see the seam between them.

That is not a hypothetical. It DIVERGED under the card#7930 implementer's hands during the
work, and every check stayed green while it was diverged; they noticed only because they
happened to be editing both ends.

WHY A GUARD RATHER THAN A POINTER. Canon #16's remedy for a duplicated claim is delete-and-
point where the consumer can follow a pointer, and guard where it cannot. `selftest` runs on
the seat at install time and LOADS these fixtures inline; a program cannot follow a prose
pointer to § 17 at run time. So the fixtures stay, and this is what makes them a derivation
instead of a copy.

DERIVED, NOT COMPARED FIELD-BY-FIELD. The fixture set is a pure mechanical reduction of the
appendix — group the payloads by `hook_event_name`, keep document order, tag the group with
the `_source` its region declares — so this gate REGENERATES all fifteen files from § 17 and
requires the committed bytes to be exactly that. There is no hand-written expectation here to
go stale, and `--write` repairs a divergence from the authority rather than by hand. The
appendix parser is `tools/design/d1_appendix.py`, shared with `verify-harness-facts.py`, so
§ 17's grammar has one spelling in this repo rather than two that may disagree.

FAIL-LOUD, NEVER FAIL-QUIET. Every way of not being able to READ the authority — the appendix
heading renamed, the DOCS-CITED stub subsection gone, a payload block parked where nothing
classifies it, an empty region — is exit 2 with the reason. A guard that cannot measure must
RED; a silent pass over an unmeasurable state is the false clean this whole gate exists
against, and it is worse than no gate because it certifies the fixtures nobody looked at.

USAGE
    harness-fixture-drift.py [--doc PATH] [--fixtures DIR] [--write]
Exit 0 = the fixtures ARE § 17.  1 = drift (run --write, or fix the appendix).  2 = the gate
could not run, and exit 2 is kept distinct from 1 so "the guard is broken" never reads as
"your fixtures are wrong". Emits GitHub `::error::` annotations when running under Actions.
"""
from __future__ import annotations

import argparse
import difflib
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT / "tools" / "design"))

try:
    from d1_appendix import (  # noqa: E402  (path set above; no package to import from)
        AppendixError,
        SOURCE_CAPTURE,
        SOURCE_STUB,
        derive_fixtures,
        parse_appendix,
    )
except ImportError as _e:      # the § 17 grammar lives in ONE module; there is no local copy
    print(f"::error::harness-fixture-drift: the shared appendix parser "
          f"tools/design/d1_appendix.py could not be imported ({_e}). This gate does not carry "
          f"its own spelling of § 17's grammar and will not guess one — exit 2.", file=sys.stderr)
    raise SystemExit(2)

DOC_DEFAULT = "docs/design/EVENT-SCHEMA.md"
FIXTURES_DEFAULT = "fleet-reporter/fixtures/hooks"


def die(msg: str) -> int:
    """Exit 2: the gate could not run. Never a fallback, never a partial verdict."""
    print(f"::error::harness-fixture-drift: {msg}", file=sys.stderr)
    return 2


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(
        description="Assert fleet-reporter's vendored hook fixtures against D1 § 17's "
                    "captured payloads, from which they are wholly derived.")
    ap.add_argument("--doc", default=None, help=f"path to D1 (default: {DOC_DEFAULT})")
    ap.add_argument("--fixtures", default=None,
                    help=f"path to the vendored fixture directory (default: {FIXTURES_DEFAULT})")
    ap.add_argument("--write", action="store_true",
                    help="rewrite the fixtures from § 17 instead of reporting the drift")
    args = ap.parse_args(argv)

    doc = Path(args.doc) if args.doc else REPO_ROOT / DOC_DEFAULT
    fixdir = Path(args.fixtures) if args.fixtures else REPO_ROOT / FIXTURES_DEFAULT

    if not doc.is_file():
        return die(f"D1 not found at {doc} — the fixtures' authority is unreadable, so this "
                   f"gate certifies nothing (no fallback to the committed fixtures).")
    if not fixdir.is_dir():
        return die(f"the vendored fixture directory {fixdir} does not exist. The reporter "
                   f"installs it as part of its artifact and its `harness_payload_keys` check "
                   f"reads it at install time; its absence is not an empty pass.")

    try:
        appendix = parse_appendix(doc.read_text(encoding="utf-8"))
        want = derive_fixtures(appendix.payloads)
    except AppendixError as e:
        return die(f"{doc}: {e}")

    # THE DENOMINATOR, PRINTED EVERY RUN. A gate whose population it never states is a gate
    # whose clean result cannot be told apart from a gate that looked at nothing. The section
    # number is the DOCUMENT's, read back from the heading, not a literal in this file.
    caps, stubs = appendix.captures, appendix.stubs
    print(f"harness-fixture-drift: authority {doc} § {appendix.number}")
    print(f"  § {appendix.number} publishes {len(caps)} captured payloads across "
          f"{len({p.hook for p in caps})} hooks, and {len(stubs)} DOCS-CITED stubs across "
          f"{len({p.hook for p in stubs})} hooks (`{SOURCE_CAPTURE}` / `{SOURCE_STUB}`)")
    print(f"  → {len(want)} vendored fixture files derived; comparing against {fixdir}")
    sec = f"§ {appendix.number}"

    problems: list[str] = []
    written: list[str] = []

    # EVERY ENTRY IN THE DIRECTORY IS JUDGED, not just the ones the appendix names. A file the
    # derivation does not produce is either a hook D1 dropped (whose fixture would then be
    # asserted against nothing) or a stray — both are drift, and neither is visible to a check
    # that only iterates the expected set.
    on_disk = sorted(p.name for p in fixdir.iterdir())
    expected_names = {f"{hook}.json" for hook in want}
    for name in on_disk:
        if name in expected_names:
            continue
        # `--write` OWNS `<Hook>.json` IN THIS DIRECTORY AND NOTHING ELSE. It regenerates that
        # namespace, so removing a stale member of it is repairing its own output; anything
        # else in the directory it did not create, and a repair flag that deletes files it
        # cannot account for is a worse surprise than the drift it was fixing. Those are
        # reported instead — including under --write, which is why --write can still exit 1.
        if args.write and name.endswith(".json") and (fixdir / name).is_file():
            (fixdir / name).unlink()
            written.append(f"removed {name} (no payload for it in the appendix)")
            continue
        problems.append(f"{fixdir/name} is not derived from {sec} — the appendix "
                        f"publishes no payload for it. Delete it, or publish the "
                        f"payload it vendors in the appendix."
                        + (" (--write does not remove it: this gate generates "
                           "`<HookEventName>.json` and deletes nothing else.)"
                           if args.write else ""))

    for hook in sorted(want):
        path = fixdir / f"{hook}.json"
        text = want[hook]
        got = path.read_text(encoding="utf-8") if path.is_file() else None
        if got == text:
            continue
        if args.write:
            path.write_text(text, encoding="utf-8")
            written.append(f"{'wrote' if got is None else 'updated'} {path.name}")
            continue
        if got is None:
            problems.append(
                f"{path} is MISSING. {sec} publishes payload(s) for {hook} and the reporter's "
                f"`harness_payload_keys` check treats a missing fixture as a failure, never a "
                f"skip.")
            continue
        diff = "".join(difflib.unified_diff(
            got.splitlines(keepends=True), text.splitlines(keepends=True),
            fromfile=f"{path} (committed)", tofile=f"{hook} derived from {sec}", n=2))
        problems.append(f"{path} has DRIFTED from {sec}:\n{diff.rstrip()}")

    if args.write:
        if written:
            print(f"harness-fixture-drift: rewrote the fixtures from {sec} —")
            for w in sorted(written):
                print(f"  {w}")
            print(f"Review the diff: {sec} is the authority, so every line here is a change "
                  f"the appendix already made and the fixtures had not caught up with.")
        elif not problems:
            print(f"harness-fixture-drift: nothing to write — the fixtures already ARE {sec}.")
        if not problems:
            return 0
        # Something in the directory --write does not own is still wrong. Say so and exit 1
        # rather than 0: a repair run that reports success over a state it did not repair is
        # the false clean this gate exists against.

    if not problems:
        print(f"harness-fixture-drift: OK — every vendored fixture is byte-identical to the "
              f"reduction of {sec}, and every file in the directory is one the appendix "
              f"produces.")
        return 0

    for p in problems:
        print(f"::error::harness-fixture-drift: {p}")
    print()
    print(f"harness-fixture-drift: FAIL — {len(problems)} vendored fixture(s) do not match "
          f"D1 {sec}, which is their sole authority.")
    print(f"This is the card#7930 near-miss made loud: {sec} and the fixtures diverged once "
          "already with every other check green, because the reporter validates the COPY and "
          "the doc gates validate the ORIGINAL, and nothing looked at the seam.")
    if args.write:
        print("FIX: this WAS a --write run and the fixtures it generates are now correct; "
              "what is left is in the directory but outside that namespace. Resolve it by "
              "hand — deliberately, since this gate will not delete a file it did not write.")
    else:
        print(f"FIX: edit {sec} of {DOC_DEFAULT} if the appendix is wrong; then run")
        print("       python3 bin/harness-fixture-drift.py --write")
        print("     and commit the regenerated fixtures. Never hand-edit a fixture: it is a "
              "generated file, and a hand edit is how the next divergence starts.")
    return 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception:                                  # noqa: BLE001 — see below
        # AN UNEXPECTED FAILURE IS EXIT 2, NEVER EXIT 1. Left to propagate, Python exits 1 —
        # this gate's DRIFT code — so an unreadable file or a permission error would arrive at
        # a reviewer as "your fixtures are wrong" and send them to regenerate files that were
        # already correct. The traceback is printed in full: the gate could not run, and
        # saying why is the result.
        import traceback
        traceback.print_exc()
        print("::error::harness-fixture-drift: the gate itself failed (see the traceback "
              "above). This is exit 2 — the fixtures were NOT judged, and this is not a "
              "statement that they have drifted.", file=sys.stderr)
        sys.exit(2)

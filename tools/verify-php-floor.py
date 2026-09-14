#!/usr/bin/env python3
"""verify-php-floor.py — the PHP floor `server/composer.json` DECLARES must cover what
`server/composer.lock` actually REQUIRES, and the two must be in step. card#9203.

WHY IT EXISTS — the defect it would have caught, measured, not imagined.
`server/composer.json` declared `"php": "^8.3"` while the committed `composer.lock` carried
`symfony/*` v8.1.5, every one of which requires `php >=8.4.1`. The lock therefore COULD NOT BE
INSTALLED on the version the repository declared as its floor; `composer install` on PHP 8.3.33
exited 2 with eighteen "your php version does not satisfy" problems, measured by the first run of
the `php-tests` lane. Nothing in the repository noticed for as long as it was true, because:

  * `composer validate --strict` is CLEAN in that state — it checks that the lock is in step with
    composer.json's *content hash*, not that the declared platform can carry the locked packages.
    It passed throughout. (It is still worth running, and the lane runs it: it catches the OTHER
    half — a composer.json edit with no lock update — and this tool does not duplicate it.)
  * every local run was on a host above the floor, which hides the gap by construction;
  * `bin/deploy.sh`'s A6 precondition, whose entire purpose is to keep exactly this failure OUT of
    the maintenance window, RESTATED the floor as a hand-written case list — so it faithfully
    enforced a claim that was no longer true and passed a host on which the deploy was certain to
    break with the app already down.

THE INVARIANT. min(composer.json's `require.php`) >= max over composer.lock of each package's
`require.php` lower bound. Where that does not hold, the declared floor is a promise the lock
cannot keep, and every consumer of the declaration — the deploy precondition, the CI pin, the host
an operator provisions — is reading a false number.

WHY A SECOND CONSTRAINT PARSER EXISTS, AND WHY IT IS NOT DUPLICATION TO FOLD AWAY.
`bin/deploy.sh` also derives the floor, in bash. It cannot call this file: it runs on the prod host
against a tree it has not checked out yet, and adding `python3` to that host's requirements is an
infrastructure decision, not a side effect of a version bump. The two derivations answer different
questions for different runtimes (this one: "does the DECLARATION cover the LOCK?", in CI, on the
working tree; deploy.sh's: "does THIS HOST satisfy the TARGET RELEASE's declaration?", on the prod
host, out of the object database). Divergence fails SAFE in both: each refuses on a constraint it
cannot interpret rather than guessing one.

USAGE
  tools/verify-php-floor.py                 # check; exit 0 clean, 1 on a violation
  tools/verify-php-floor.py --github-output # also emit floor=/minor= to $GITHUB_OUTPUT
  tools/verify-php-floor.py --root DIR      # a tree other than the repository this file is in

SEEN TO FAIL (canon #9) — this check is not a decoration; break it and watch it red:
  sed -i 's/"php": "[^"]*"/"php": "^8.3"/' server/composer.json && tools/verify-php-floor.py
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path

Version = tuple[int, int, int]


def parse_version(text: str) -> Version | None:
    """`8.4.1` / `8.4` / `8` -> a 3-tuple. Anything else -> None."""
    m = re.fullmatch(r"v?(\d+)(?:\.(\d+))?(?:\.(\d+))?", text.strip())
    if not m:
        return None
    return (int(m.group(1)), int(m.group(2) or 0), int(m.group(3) or 0))


def _conjunction_lower_bound(expr: str) -> Version | None:
    """Lower bound of a space-separated AND of comparators, or of an `A - B` range.

    An unrecognised token returns None rather than a guess — a floor check that silently ignores
    a constraint it does not understand reports where the searcher stopped, not the tree's state.
    """
    expr = expr.strip()
    if not expr:
        return None

    # Hyphenated range: `8.1 - 8.5`. The lower bound is the left operand.
    m = re.fullmatch(r"(v?[\d.]+)\s+-\s+(v?[\d.]+)", expr)
    if m:
        return parse_version(m.group(1))

    lower: Version = (0, 0, 0)
    for token in expr.split():
        # `<9.0`, `<=8.5` and `!=8.4.3` place no lower bound at all.
        if re.fullmatch(r"(<|<=|!=)\s*v?[\d.]+", token):
            continue
        m = re.fullmatch(r"(\^|~|>=|>|=|==)?(v?[\d.]+)(\.\*)?", token)
        if not m:
            return None
        version = parse_version(m.group(2).rstrip("."))
        if version is None:
            return None
        # `>X.Y.Z` is really "> that release"; taking its lower bound AS X.Y.Z is permissive by at
        # most one patch level, and permissive is the safe direction for a MAXIMUM-of-lower-bounds
        # check — it can never invent a requirement the lock does not have.
        lower = max(lower, version)
    return lower


def constraint_lower_bound(constraint: str) -> Version | None:
    """Lowest PHP version that can satisfy a composer constraint. None if uninterpretable.

    Alternatives (`^7.4 || ^8.0`) take the MINIMUM of the branches: any one of them satisfies.
    """
    best: Version | None = None
    for alternative in re.split(r"\s*\|\|?\s*", constraint.strip()):
        bound = _conjunction_lower_bound(alternative)
        if bound is None:
            return None
        best = bound if best is None else min(best, bound)
    return best


def fmt(version: Version) -> str:
    return "%d.%d.%d" % version


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--root", default=None, help="repository root (default: this file's repo)")
    ap.add_argument("--github-output", action="store_true",
                    help="append floor=/minor= to $GITHUB_OUTPUT for a workflow to consume")
    args = ap.parse_args()

    root = Path(args.root) if args.root else Path(__file__).resolve().parent.parent
    manifest_path = root / "server" / "composer.json"
    lock_path = root / "server" / "composer.lock"

    manifest = json.loads(manifest_path.read_text())
    lock = json.loads(lock_path.read_text())

    declared = (manifest.get("require") or {}).get("php")
    if not declared:
        print(f"⛔ {manifest_path} declares no `require.php`. The floor has no owner.",
              file=sys.stderr)
        return 1

    declared_floor = constraint_lower_bound(declared)
    if declared_floor is None:
        print(f"⛔ cannot interpret {manifest_path}'s php constraint {declared!r}.", file=sys.stderr)
        print("   Widen this tool deliberately, or simplify the constraint. It will not guess.",
              file=sys.stderr)
        return 1

    problems: list[str] = []

    # 1. The lock must be carrying the same declaration. `composer update --lock` is what puts it
    #    back in step; a mismatch means the lock was not regenerated after composer.json moved.
    locked_platform = (lock.get("platform") or {}).get("php")
    if locked_platform != declared:
        problems.append(
            f"composer.lock's platform.php is {locked_platform!r} but composer.json declares "
            f"{declared!r} — run `composer update --lock` in server/."
        )

    # 2. The declaration must cover every package the lock actually pins.
    worst: Version = (0, 0, 0)
    worst_packages: list[str] = []
    unreadable: list[str] = []
    for section in ("packages", "packages-dev"):
        for package in lock.get(section) or []:
            requirement = (package.get("require") or {}).get("php")
            if not requirement:
                continue
            bound = constraint_lower_bound(requirement)
            if bound is None:
                unreadable.append(f"{package['name']} ({requirement})")
                continue
            entry = f"{package['name']} {package['version']} ({requirement})"
            if bound > worst:
                worst, worst_packages = bound, [entry]
            elif bound == worst:
                worst_packages.append(entry)

    if unreadable:
        # Named, not swallowed: this check's honest output where it cannot establish something is
        # to say WHICH package it could not read, on the surface the reader is already looking at.
        problems.append(
            "php constraints this tool cannot interpret, so they were NOT covered by this check: "
            + ", ".join(sorted(unreadable))
        )

    if declared_floor < worst:
        problems.append(
            f"composer.json declares {declared!r} (floor {fmt(declared_floor)}) but composer.lock "
            f"pins packages needing at least {fmt(worst)}. The declared floor CANNOT install this "
            f"lock — `composer install` on {fmt(declared_floor)} exits 2. Raise the declaration, "
            f"or re-resolve the lock against the declared floor. Packages at {fmt(worst)}: "
            + ", ".join(sorted(worst_packages)[:4])
            + (f", … ({len(worst_packages)} in all)" if len(worst_packages) > 4 else "")
        )

    if problems:
        print("⛔ server/'s declared PHP floor does not hold:", file=sys.stderr)
        for problem in problems:
            print(f"  - {problem}", file=sys.stderr)
        return 1

    print(f"ok — server/composer.json declares php {declared} (floor {fmt(declared_floor)}); "
          f"the highest php requirement in composer.lock is {fmt(worst)}, held by "
          f"{len(worst_packages)} package(s), e.g. {sorted(worst_packages)[0]}.")

    if args.github_output:
        target = os.environ.get("GITHUB_OUTPUT")
        if not target:
            print("⛔ --github-output but $GITHUB_OUTPUT is unset.", file=sys.stderr)
            return 1
        major, minor, _ = declared_floor
        with open(target, "a", encoding="utf-8") as handle:
            handle.write(f"floor={fmt(declared_floor)}\n")
            handle.write(f"minor={major}.{minor}\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())

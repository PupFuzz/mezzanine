#!/usr/bin/env python3
"""asset-provenance.py — the two asset gates from docs/design/FLOOR.md section 10.1.

WHAT IT DOES.

  Gate 1 — every asset has a row.  Every file under a declared asset tree has exactly one row
  in the manifest (docs/ATTRIBUTION.md), that row's SHA-256 matches the file's bytes, its
  licence identifier is in the CLOSED allowlist, and no row names a file that is not there.

  Gate 2 — no vendored character art.  Under the character tree the gate is an ALLOWLIST, in
  two clauses, exactly as section 10.1 argues it must be:
    1. every file carries one of .ts / .js / .md, so a format nobody anticipated is refused
       rather than merely a format somebody listed;
    2. no file carries a `data:image/` URI or a single base64-shaped literal over 1,024 B,
       because clause 1 cannot see image bytes pasted INSIDE a file it admits.

  The lineage check — AT-D3-12's lineage half, and NOT a third gate: section 10.1 names two and
  this invents no more. It reads resources/characters/LINEAGE.md rather than the tree and
  requires the fields section 10.2 obliges a port to record — upstream URL, the COMMIT, the MIT
  copyright line and permission notice reproduced in full, and what was deliberately not taken.
  The commit is the one to watch: a port whose upstream commit nobody recorded is a port nobody
  can tell from a fork.

WHY IT EXISTS.  The repository is MIT and public. An asset whose licence nobody recorded is
the only way an incompatible one ever ships, and by the time it matters the person who added
it has moved on. The SHA-256 column is what makes a later swap of the BYTES visible without
re-reading the source — so yes, editing a listed file reds this gate until its row is
refreshed, and that is the point of the column rather than a cost of it.

WHAT IT DOES NOT DO, said out loud so nobody reads a green as more than it is:

  * It cannot refuse a generator that FETCHES upstream art at run time. Nothing that inspects
    a tree can. Section 10.1 names this residue; the lineage file's deliberate-omissions
    section and human review are what stand against it.
  * It only looks under the repo-root `resources/`. A picture parked under server/, docs/ or
    tools/ is invisible to it.
  * Clause 2 finds a base64 run in the raw bytes and in a whitespace-stripped copy (so a blob
    broken across lines is still caught), but a blob deliberately split by punctuation and
    concatenated at run time would pass it — as would one encoded in a single case, because a
    run must contain upper, lower AND a digit to be called a literal rather than a row of
    comment dividers.
  * Clause 2 matches a data URI (a MIME subtype followed by `;` or `,`), not the bare string
    `data:image/` — otherwise the lineage file could not write down the rule it lives under.

EXIT CODES.  0 clean · 1 a gate failed · 2 UNMEASURABLE — a malformed manifest, a missing
declared tree layout, nothing to measure. 2 is never silently a 0: a gate that cannot see its
population has not reported on it.

Python 3 standard library only. No network, no credential, no dependency.
"""

from __future__ import annotations

import argparse
import hashlib
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent

# --- the declarations, each in exactly one place ---------------------------------------------

# The asset root: `resources/` at the REPOSITORY root, entire, with nothing enumerated below it.
#
# It is the whole directory rather than a list of named trees, and that is a deliberate reversal
# of this gate's first draft. The draft enumerated `resources/characters` and `resources/floor`
# to avoid sweeping in Laravel's views, CSS and app JavaScript — but `docs/PLAN.md § 0` **D-16**
# puts the application under `server/`, so Laravel's resources are at `server/resources/` and
# were never in scope. With that premise gone, the enumeration bought nothing and cost a real
# hole: a `resources/tiles/` added by somebody who did not think to edit this tuple would have
# been covered by no gate at all, and would have reported clean.
#
# So the rule is the simple one — anything under the repo-root `resources/` is an asset and owes
# a provenance row — and card #7341's tileset is covered on the day it lands, whatever it is
# called. `server/resources/` is Laravel's and is NOT scanned.
ASSET_TREES = ("resources",)

# Gate 2's tree. Its allowlist is stricter than Gate 1's because the claim it enforces is an
# ABSENCE: the character sprites are generated, so no image file may exist here at all.
CHARACTER_TREE = "resources/characters"
CHARACTER_EXTENSIONS = frozenset({".ts", ".js", ".md"})
BASE64_MAX_BYTES = 1024

# The licence allowlist is CLOSED. Widening it is an OPERATOR decision (section 10.1), never an
# implementer's: the repository is MIT and public, so an asset whose terms are stricter than the
# repository's is a term the repository cannot honour.
LICENCE_ALLOWLIST = frozenset({"CC0-1.0", "MIT"})

MANIFEST = "docs/ATTRIBUTION.md"
MANIFEST_BEGIN = "<!-- asset-manifest:begin -->"
MANIFEST_END = "<!-- asset-manifest:end -->"
MANIFEST_COLUMNS = ("path", "source url", "author", "spdx", "retrieved", "sha-256")

# A DATA URI, not the STRING "data:image/". The two are different things and conflating them
# makes the gate unable to tell a picture from a document that describes the rule — the lineage
# file and this manifest both have to be able to write the forbidden construct's name in prose.
# So the match requires a real MIME subtype followed by the `,` or `;` that a URI actually
# carries: `data:image/png;base64,` and `data:image/svg+xml,<svg` match, a backticked mention of
# `data:image/` does not.
DATA_IMAGE_RE = re.compile(rb"data:image/[a-z0-9][a-z0-9.+-]*\s*[;,]", re.IGNORECASE)
# A long run of base64 ALPHABET is not on its own a base64 LITERAL, and the difference matters
# because `-`, `_` and `/` are all in that alphabet: a block of `// ---------` comment dividers
# concatenates, once whitespace is stripped, into exactly such a run. Measured on this tree at
# the time of writing, the longest innocent run is 129 B — under the ceiling, but the mechanism
# is real and its failure mode is a gate accusing a comment of being a picture.
#
# So a match must ALSO look like encoded bytes: at least one uppercase, one lowercase and one
# digit inside the run. Dividers have none of the three; prose has no digits; a base64-encoded
# image has all three within its first few dozen characters. This narrows what clause 2 claims
# rather than widening what it admits — a hand-crafted blob of one case would evade it, and
# that is a deliberate shortcut nobody takes by accident, whereas the divider is an accident
# somebody takes eventually.
BASE64_RUN_RE = re.compile(rb"[A-Za-z0-9+/=_-]{%d,}" % (BASE64_MAX_BYTES + 1))
BASE64_LOOKS_ENCODED = (re.compile(rb"[A-Z]"), re.compile(rb"[a-z]"), re.compile(rb"[0-9]"))
WHITESPACE_RE = re.compile(rb"\s+")
ISO_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")

failures: list[str] = []
notes: list[str] = []


def looks_encoded(run: bytes) -> bool:
    """Does this run of base64 alphabet actually look like encoded bytes? See BASE64_RUN_RE."""
    return all(rx.search(run) for rx in BASE64_LOOKS_ENCODED)


def fail(gate: str, msg: str) -> None:
    failures.append(f"{gate}: {msg}")


def unmeasurable(msg: str) -> None:
    print(f"UNMEASURABLE — {msg}", file=sys.stderr)
    print("  (exit 2: a gate that cannot see its population has not reported on it)", file=sys.stderr)
    sys.exit(2)


# --- enumeration ------------------------------------------------------------------------------

def enumerate_assets() -> list[str]:
    """Every file under every declared asset tree, repo-relative, sorted.

    Walks the filesystem rather than asking git: the checkout is what ships, and an untracked
    picture sitting in the tree is exactly the thing this gate should catch before it is added.
    """
    found: list[str] = []
    for tree in ASSET_TREES:
        root = REPO / tree
        if not root.exists():
            notes.append(f"{tree}: absent (0 files)")
            continue
        if not root.is_dir():
            unmeasurable(f"declared asset tree {tree} exists but is not a directory")
        count = 0
        for p in sorted(root.rglob("*")):
            if p.is_dir():
                continue
            found.append(p.relative_to(REPO).as_posix())
            count += 1
        notes.append(f"{tree}: {count} file(s)")
    return sorted(found)


# --- the manifest ------------------------------------------------------------------------------

def parse_manifest() -> dict[str, dict[str, str]]:
    """The manifest's rows, keyed by path.

    Deliberately strict, and every structural surprise is exit 2 rather than a skipped row: a
    row this parser silently failed to read is an asset nobody checked, which is the one outcome
    worse than a red gate.
    """
    path = REPO / MANIFEST
    if not path.is_file():
        unmeasurable(f"{MANIFEST} does not exist — there is no manifest to check against")
    text = path.read_text(encoding="utf-8")
    if text.count(MANIFEST_BEGIN) != 1 or text.count(MANIFEST_END) != 1:
        unmeasurable(f"{MANIFEST} must contain exactly one {MANIFEST_BEGIN} and one {MANIFEST_END}")
    block = text.split(MANIFEST_BEGIN, 1)[1].split(MANIFEST_END, 1)[0]

    lines = [ln.strip() for ln in block.splitlines() if ln.strip().startswith("|")]
    if len(lines) < 3:
        unmeasurable(f"{MANIFEST}: the manifest block holds no table (found {len(lines)} table line(s))")

    def cells(line: str) -> list[str]:
        return [c.strip() for c in line.strip().strip("|").split("|")]

    header = [c.lower() for c in cells(lines[0])]
    if tuple(header) != MANIFEST_COLUMNS:
        unmeasurable(
            f"{MANIFEST}: header is {header!r}, expected {list(MANIFEST_COLUMNS)!r} — "
            "the six columns section 10.1 requires, in order"
        )

    rows: dict[str, dict[str, str]] = {}
    for lineno, line in enumerate(lines[2:], start=3):
        c = cells(line)
        if len(c) != len(MANIFEST_COLUMNS):
            unmeasurable(f"{MANIFEST}: row {lineno} has {len(c)} cells, expected {len(MANIFEST_COLUMNS)}")
        row = dict(zip(MANIFEST_COLUMNS, c))
        p = row["path"].strip("`")
        if p in rows:
            unmeasurable(f"{MANIFEST}: {p} has more than one row — which one is the provenance?")
        row["path"] = p
        rows[p] = row
    if not rows:
        unmeasurable(f"{MANIFEST}: the manifest block declares no rows")
    return rows


def sha256_of(rel: str) -> str:
    return hashlib.sha256((REPO / rel).read_bytes()).hexdigest()


# --- gate 1 --------------------------------------------------------------------------------------

def gate1(assets: list[str], rows: dict[str, dict[str, str]]) -> None:
    for rel in assets:
        row = rows.get(rel)
        if row is None:
            fail("GATE 1", f"{rel} has no ATTRIBUTION row — an asset whose licence nobody recorded")
            continue
        want = row["sha-256"].strip("`").lower()
        got = sha256_of(rel)
        if want != got:
            fail("GATE 1", f"{rel}: SHA-256 row says {want[:16]}…, file is {got[:16]}… — the bytes moved")
        spdx = row["spdx"].strip("`")
        if spdx not in LICENCE_ALLOWLIST:
            fail(
                "GATE 1",
                f"{rel}: licence {spdx!r} is not in the closed allowlist "
                f"{sorted(LICENCE_ALLOWLIST)} — widening it is an operator decision",
            )
        if not row["retrieved"] or not ISO_DATE_RE.match(row["retrieved"]):
            fail("GATE 1", f"{rel}: retrieved {row['retrieved']!r} is not an ISO date (YYYY-MM-DD)")
        if not row["source url"].strip("`[]() ").startswith(("http://", "https://")):
            fail("GATE 1", f"{rel}: source URL {row['source url']!r} is not a URL a human can check")
        if not row["author"].strip("`"):
            fail("GATE 1", f"{rel}: no author — the attribution obligation has no subject")

    asset_set = set(assets)
    for rel in sorted(rows):
        if rel not in asset_set:
            fail("GATE 1", f"{rel} has an ATTRIBUTION row but no such file under any asset tree")


# --- gate 2 --------------------------------------------------------------------------------------

def gate2(assets: list[str]) -> None:
    tree = CHARACTER_TREE + "/"
    members = [a for a in assets if a.startswith(tree)]
    if not members:
        notes.append(f"{CHARACTER_TREE}: absent — gate 2 clause 1 has no tree to classify")
    for rel in members:
        ext = Path(rel).suffix
        if ext not in CHARACTER_EXTENSIONS:
            fail(
                "GATE 2 clause 1",
                f"{rel}: extension {ext or '(none)'!r} is not one of "
                f"{sorted(CHARACTER_EXTENSIONS)} — the character tree holds source and lineage, "
                "and character art is GENERATED, never vendored",
            )
            continue
        raw = (REPO / rel).read_bytes()
        if DATA_IMAGE_RE.search(raw):
            fail("GATE 2 clause 2", f"{rel}: carries a data:image/ URI — image bytes inside an admitted file")
        hit = None
        for label, blob in (("as written", raw), ("with whitespace stripped", WHITESPACE_RE.sub(b"", raw))):
            for m in BASE64_RUN_RE.finditer(blob):
                if looks_encoded(m.group(0)):
                    hit = (label, len(m.group(0)))
                    break
            if hit:
                break
        if hit:
            fail(
                "GATE 2 clause 2",
                f"{rel}: a base64-shaped literal of {hit[1]} B ({hit[0]}) exceeds the "
                f"{BASE64_MAX_BYTES} B ceiling",
            )


# --- the lineage check (AT-D3-12's lineage half) ------------------------------------------------
# NOT a third gate — section 10.1 names two, and this does not invent a third. It is the half of
# AT-D3-12 that reads the lineage file rather than the tree, and it is here because it runs over
# the same artifacts on the same trigger. Watch its commit-SHA RED in particular: a port whose
# upstream commit nobody recorded is a port nobody can tell from a fork.

LINEAGE = CHARACTER_TREE + "/LINEAGE.md"
MIT_NOTICE_RE = re.compile(r"The above copyright notice and this permission notice shall be included")
LINEAGE_REQUIRED = (
    ("the upstream repository URL", re.compile(r"https?://[^\s)>`]+/[^\s)>`]+")),
    ("the upstream commit SHA (40 hex)", re.compile(r"\b[0-9a-f]{40}\b")),
    ("the MIT copyright line", re.compile(r"Copyright \(c\) \d{4}\s+\S")),
    ("the MIT permission notice (a link is not a reproduction)", MIT_NOTICE_RE),
    ("a section saying what was deliberately NOT taken, and why",
     re.compile(r"not\s+taken", re.IGNORECASE)),
)


def lineage_check(assets: list[str]) -> None:
    if not any(a.startswith(CHARACTER_TREE + "/") for a in assets):
        notes.append(f"{LINEAGE}: no character tree — lineage check has nothing to read")
        return
    path = REPO / LINEAGE
    if not path.is_file():
        fail("LINEAGE", f"{LINEAGE} is missing — the port has no record of where it came from")
        return
    text = path.read_text(encoding="utf-8")
    for what, rx in LINEAGE_REQUIRED:
        if not rx.search(text):
            fail("LINEAGE", f"{LINEAGE} does not carry {what}")

    # Section 10.2: "The MIT notice ships with the distribution, in docs/ATTRIBUTION.md AND in
    # the lineage file … a link is not a reproduction." Both, not either — so the manifest is
    # checked for the notice too. This is the one duplication the gate REQUIRES rather than
    # forbids: a licence notice has to accompany the distribution, and its text is immutable, so
    # the usual argument against a second copy does not reach it.
    if MIT_NOTICE_RE.search(text) and not MIT_NOTICE_RE.search((REPO / MANIFEST).read_text(encoding="utf-8")):
        fail("LINEAGE", f"{MANIFEST} does not reproduce the MIT permission notice that {LINEAGE} carries "
                        "— section 10.2 requires it in both, and a link is not a reproduction")


# --- main -------------------------------------------------------------------------------------

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true", help="print only failures and the verdict")
    args = ap.parse_args()

    assets = enumerate_assets()
    if not assets:
        unmeasurable(
            "no files under any declared asset tree "
            f"({', '.join(ASSET_TREES)}) — nothing was measured, which is not the same as clean"
        )

    rows = parse_manifest()
    gate1(assets, rows)
    gate2(assets)
    lineage_check(assets)

    if not args.quiet:
        print("Asset trees measured:")
        for n in notes:
            print(f"  {n}")
        print(f"Manifest: {MANIFEST} — {len(rows)} row(s) against {len(assets)} asset file(s)")

    if failures:
        print(f"\n{len(failures)} PROVENANCE FAILURE(S):")
        for f in failures:
            print(f"  {f}")
        return 1
    print("\nBOTH ASSET GATES PASS + LINEAGE COMPLETE")
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""asset-provenance.py — the two asset gates from docs/design/FLOOR.md section 10.1.

WHAT IT DOES.

  Gate 1 — every asset has a row, AND the row says where the asset came from.  Every file under
  a declared asset tree has exactly one row in the manifest (docs/ATTRIBUTION.md); that row's
  SHA-256 matches the file's bytes; its licence identifier is in the CLOSED allowlist; its
  `origin` is one of exactly two values and is CONSISTENT WITH ITS OWN SOURCE URL; and no row
  names a file that is not there.

    origin = first-party  drawn or written FOR this repository. The source URL must be an
                          IN-REPO reference (this repository's own URL).
    origin = licensed     obtained from outside. The source URL must be a genuine EXTERNAL one.

  A row with no origin, or an origin outside the pair, fails. The column is typed so that
  "where did this picture come from" is a membership test rather than prose, and so that the
  one lie a machine CAN catch — a row claiming first-party over somebody else's URL — is
  caught. See the honesty note below for the much larger class it cannot catch.

  Gate 2 — every asset is a file Gate 1 can see.  Under the character tree the gate is an
  ALLOWLIST, in two clauses, exactly as section 10.1 argues it must be:
    1. every file carries one of .ts / .js / .md / .svg / .png, so a format nobody anticipated
       is refused rather than merely a format somebody listed. (.svg because the ratified art
       direction is vector-first and section 4.5 requires resolution independence; .png as the
       one lossless raster, which the Tiled tilesets of section 10.3 already ship in.)
    2. no TEXT-BEARING file in that tree carries a `data:image/` URI or a single base64-shaped
       literal over 1,024 B — because an asset embedded inside another file has NO PATH OF ITS
       OWN, therefore no manifest row, therefore no provenance, and it is invisible to Gate 1
       by construction, since Gate 1 walks paths.

  UNTIL 2026-08-27 GATE 2 ASSERTED AN ABSENCE — no image file in the character tree at all —
  which was the mechanised form of "the sprites are generated". The operator ratified an art
  direction under which original art ships as files (FLOOR section 10.4), so that assertion
  became false about the product being built. It was not relaxed; it was replaced, by the claim
  Gate 1 needs in order to mean anything.

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

  * IT DOES NOT PROVE A ROW IS TRUE.  This is the big one and it is new. The old Gate 2 was
    self-verifying: an absence needs no truthful claim from anybody. This one rests on a
    declaration. Vendor somebody's commercial art as a .png, write `first-party` / `MIT` in its
    row, and every check here passes. What stands in its place is the closed licence allowlist,
    the origin/URL consistency check, the lineage file's deliberate-omissions section, FLOOR
    section 10.5's IP line, and REVIEW. Section 10.1 names the residue in full.
  * IT CANNOT SEE A CHARACTER SOMEBODY ELSE OWNS.  Nothing that reads file types, hashes and
    licence strings can look at a drawing and recognise a Pikachu. FLOOR section 10.5 states
    that rule and states that review, not this script, enforces it.
  * IT TRUSTS THE EXTENSION.  Clause 1 classifies by suffix and sniffs no magic bytes, so an
    .avif renamed to .png passes it. That is a known gap rather than an oversight: clause 1
    exists to refuse the format nobody anticipated, not to defeat somebody deliberately hiding
    one, and the line above already concedes the deliberate case.
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
  * Clause 2's alphabet is base64's own — A-Z a-z 0-9 + / = — and NOTHING ELSE. base64url's `-`
    and `_` are deliberately outside it, which narrows what the clause claims and is the whole
    reason a complex .svg passes: SVG path data is broken up by `.`, `-`, `,` and spaces, so a
    run of it is not a run of this alphabet. A gate that reds on correct work gets switched
    off, which costs more than the gate was ever worth. The selftest carries the discriminating
    pair — a complex first-party SVG passes, an SVG with an inlined data:image blob fails.
  * Clause 2 skips BINARY admitted formats (.png). The clause reasons about a file that is a
    CONTAINER for an undeclared asset; a raster file is not a container for one, it IS the
    asset, and it has a row of its own.
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

# Gate 2's tree. Its file-type allowlist is stricter than Gate 1's (which imposes none) because
# the claim it enforces is that every asset here is a FILE GATE 1 CAN SEE: a known format, with
# a path, with a row. Each member has a reason, stated in FLOOR section 10.1 clause 1 and not
# restated here; what is here is the split the code needs and the document does not.
CHARACTER_TREE = "resources/characters"
#   TEXT-BEARING: clause 2 reads inside these, because a text file CAN be a container for an
#   undeclared asset. `.svg` is in this half rather than the other half deliberately — an SVG
#   that inlines a data:image blob is a raster asset wearing a vector file's extension, and
#   clause 2 is the only thing in this repository that can tell the two apart.
CHARACTER_TEXT_EXTENSIONS = frozenset({".ts", ".js", ".md", ".svg"})
#   BINARY: admitted by clause 1, skipped by clause 2. A raster file is not a container for an
#   undeclared asset; it IS the asset, and it carries a manifest row of its own.
CHARACTER_BINARY_EXTENSIONS = frozenset({".png"})
CHARACTER_EXTENSIONS = CHARACTER_TEXT_EXTENSIONS | CHARACTER_BINARY_EXTENSIONS
BASE64_MAX_BYTES = 1024

# The licence allowlist is CLOSED. Widening it is an OPERATOR decision (section 10.1), never an
# implementer's: the repository is MIT and public, so an asset whose terms are stricter than the
# repository's is a term the repository cannot honour.
LICENCE_ALLOWLIST = frozenset({"CC0-1.0", "MIT"})

# The `origin` set is CLOSED at two, and unlike the licence allowlist it is not an operator gate
# — it is a TYPE. A third value invented at a row is a value nobody decided, and "where did this
# come from" answered in free text is a question nobody can re-ask a year later.
ORIGIN_FIRST_PARTY = "first-party"
ORIGIN_LICENSED = "licensed"
ORIGIN_SET = (ORIGIN_FIRST_PARTY, ORIGIN_LICENSED)

# This repository's own URL, so that `first-party` can be checked against something rather than
# merely recorded. It is declared here, once, for the same reason ASSET_TREES is: a second copy
# is a second thing to keep true. A `first-party` row must point INSIDE it and a `licensed` row
# must point OUTSIDE it — which is the ONE inconsistency in this class a machine can catch, and
# the module docstring is explicit that the lie it cannot catch is much the larger set.
REPO_URL_RE = re.compile(r"^https?://(?:www\.)?github\.com/PupFuzz/mezzanine(?:[/#?]|$)", re.I)

MANIFEST = "docs/ATTRIBUTION.md"
MANIFEST_BEGIN = "<!-- asset-manifest:begin -->"
MANIFEST_END = "<!-- asset-manifest:end -->"
MANIFEST_COLUMNS = ("path", "origin", "source url", "author", "spdx", "retrieved", "sha-256")

# A DATA URI, not the STRING "data:image/". The two are different things and conflating them
# makes the gate unable to tell a picture from a document that describes the rule — the lineage
# file and this manifest both have to be able to write the forbidden construct's name in prose.
# So the match requires a real MIME subtype followed by the `,` or `;` that a URI actually
# carries: `data:image/png;base64,` and `data:image/svg+xml,<svg` match, a backticked mention of
# `data:image/` does not.
DATA_IMAGE_RE = re.compile(rb"data:image/[a-z0-9][a-z0-9.+-]*\s*[;,]", re.IGNORECASE)
# THE ALPHABET IS BASE64'S OWN, AND NOTHING ELSE: A-Z a-z 0-9 + / = .
#
# An earlier revision also admitted `-` and `_` (base64URL's two extra characters). That was a
# gate looking for a superset of what it claimed to find, and the cost landed on the format the
# 2026-08-27 amendment newly admits: SVG. Path data — `d="M12.5 3.2c-1.1 0-2 .9-2 2"` — is long,
# mixed-case and dense with digits, and with `-` in the class it concatenates, under the
# whitespace-stripped pass, into exactly the shape this clause hunts. A GATE THAT REDS ON
# CORRECT WORK GETS SWITCHED OFF, which costs more than the gate was ever worth.
#
# Narrowed to base64's own alphabet, path data cannot form a run at all: it is broken up by `.`,
# `-`, `,` and spaces, none of which are in the class. What is given up is a base64URL-encoded
# blob, which is not what a data URI or a pasted image ever uses (DATA_IMAGE_RE catches the
# former regardless), so the claim narrows and the coverage does not.
#
# A long run of the alphabet is still not on its own a base64 LITERAL: `=` and `/` are both in
# it, so a block of `// ==========` dividers concatenates, once whitespace is stripped, into
# exactly such a run. Measured on this tree at the time of writing, the longest innocent run is
# 129 B — under the ceiling, but the mechanism is real and its failure mode is a gate accusing a
# comment of being a picture. So a match must ALSO look like encoded bytes: at least one
# uppercase, one lowercase and one digit inside the run. Dividers have none of the three; prose
# has no digits; a base64-encoded image has all three within its first few dozen characters.
# This narrows what clause 2 claims rather than widening what it admits — a hand-crafted blob of
# one case would evade it, and that is a deliberate shortcut nobody takes by accident, whereas
# the divider is an accident somebody takes eventually.
BASE64_RUN_RE = re.compile(rb"[A-Za-z0-9+/=]{%d,}" % (BASE64_MAX_BYTES + 1))
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
            f"the {len(MANIFEST_COLUMNS)} columns section 10.1 requires, in order"
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
        url = row["source url"].strip("`[]() ")
        if not url.startswith(("http://", "https://")):
            fail("GATE 1", f"{rel}: source URL {row['source url']!r} is not a URL a human can check")
        if not row["author"].strip("`"):
            fail("GATE 1", f"{rel}: no author — the attribution obligation has no subject")

        # The origin column, and the ONE consistency it makes checkable. A missing cell and a
        # cell outside the pair are separate failures rather than one, because "nobody said" and
        # "somebody made a value up" are different things to go and fix.
        origin = row["origin"].strip("`").lower()
        if not origin:
            fail(
                "GATE 1",
                f"{rel}: no origin — the row does not say where this asset came from. "
                f"It is one of {list(ORIGIN_SET)}",
            )
        elif origin not in ORIGIN_SET:
            fail(
                "GATE 1",
                f"{rel}: origin {origin!r} is not in the closed set {list(ORIGIN_SET)} — "
                "a third value is a value nobody decided",
            )
        elif origin == ORIGIN_FIRST_PARTY and not REPO_URL_RE.match(url):
            fail(
                "GATE 1",
                f"{rel}: origin is {ORIGIN_FIRST_PARTY!r} but its source URL {url!r} is not this "
                "repository's — a row claiming it was drawn here while pointing somewhere else "
                "contradicts itself",
            )
        elif origin == ORIGIN_LICENSED and REPO_URL_RE.match(url):
            fail(
                "GATE 1",
                f"{rel}: origin is {ORIGIN_LICENSED!r} but its source URL {url!r} is this "
                "repository's — a licensed asset came from OUTSIDE, and its row has to say where",
            )

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
                f"{sorted(CHARACTER_EXTENSIONS)} — an allowlist refuses the format nobody "
                "anticipated, and admitting one is a change to FLOOR section 10.1 with a reason "
                "beside it, not an edit here",
            )
            continue
        if ext in CHARACTER_BINARY_EXTENSIONS:
            # Admitted by clause 1, skipped by clause 2 on purpose: clause 2 hunts an asset
            # embedded inside a CONTAINER, and a raster file is not a container for one — it is
            # the asset, and it has a row.
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

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

  Gate 2 — every asset is a file Gate 1 can see.  Over the SAME population Gate 1 walks — ALL of
  the repo-root resources/, not the character tree it was scoped to until 2026-08-27 — the gate
  is an ALLOWLIST, in three clauses, exactly as section 10.1 argues it must be:
    1. every file carries one of .ts / .js / .md / .svg / .png / .tmx / .tmj / .tsx / .tsj, so a
       format nobody anticipated is refused rather than merely a format somebody listed. (.svg
       because the ratified art direction is vector-first and section 4.5 requires resolution
       independence; .png as the one lossless raster, which the Tiled tilesets of section 10.3
       already ship in; the four Tiled formats because section 10.3 makes Tiled the map format,
       and a map is an asset like any other.)
       ⚠ .tsx IS AMBIGUOUS EVERYWHERE EXCEPT HERE: it is Tiled's Tileset XML in this list and
       TypeScript-JSX in the rest of the world. Under resources/ only the first meaning is in
       play — this repository's React-free generator is .js/.ts — so the collision is harmless
       today and is named anyway, here and in section 10.1 clause 1, because an allowlist that
       silently admits a second file type under one suffix is a trap for the next reader.
    2. no TEXT-BEARING file carries a `data:image/` URI or a single base64-shaped literal over
       1,024 B — because an asset embedded inside another file has NO PATH OF ITS OWN, therefore
       no manifest row, therefore no provenance, and it is invisible to Gate 1 by construction,
       since Gate 1 walks paths. THE 1,024 B CEILING HAS NO CARVE-OUT FOR THE MAP FORMATS, and
       clause 3 is the reason it needs none.
    3. every Tiled artifact stores its tile layer data PLAINLY (CSV) and embeds no tileset image.
       Read out of the artifact itself — the `encoding` / `compression` of each tile layer, and
       whether each <image> names a source — never inferred from the bytes: no heuristic, no
       alphabet, no ceiling to tune.
       WHY IT IS HERE. Tiled's default layer encoding is base64, and base64 layer data is exactly
       the shape clause 2 hunts, so widening clause 2 to resources/ without this would put the
       two clauses in collision over a correct map. The alternative — exempting the map formats
       from clause 2 — re-opens the hole clause 2 exists to close, because image bytes pasted
       into an exempted map have no path either. This clause REMOVES the base64 instead of
       reasoning about how long it is, so clause 2 needs no exemption and a correct map carries
       no base64 run at all. Its second half is the true positive: an embedded tileset image is
       image bytes inside a map with no path and no row, which is precisely clause 2's subject.

  UNTIL card#7913 GATE 2 RAN OVER THE CHARACTER TREE ONLY, while Gate 1 already ran over all of
  resources/. That was correct while Gate 2 asserted an absence peculiar to that tree; it was a
  leftover the moment the 2026-08-27 amendment rewrote its claim as a UNIVERSAL one — "every asset
  is a file Gate 1 can see" says nothing about characters. The tree about to receive this project's
  first vendored third-party art (resources/floor/, card#7341) was the one tree Gate 2 did not
  inspect, so the widening happened BEFORE that tree exists rather than on top of its contents.
  Note that Gate 2 has TWO scoping knobs, the tree AND the extension allowlist, and widening only
  one of them is not a widening: with the tree widened and the allowlist left alone, every Tiled
  artifact fails clause 1 by name for a reason that has nothing to do with what it contains.

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
    concatenated at run time would pass it — as would ANY run missing one of the three character
    classes, because a run must contain upper, lower AND a digit to be called a literal rather
    than a row of comment dividers.
    ⚠ THAT SECOND EVASION IS NOT ONLY A DELIBERATE ONE, and this bullet said it was until
    card#7913 measured it. Base64 of little-endian uint32 Tiled GIDs with small values — Tiled's
    ORDINARY uncompressed output — routinely contains no digit. Measured over a uniform
    1,200-tile map at every GID in 0..255: the run is 6,400 B in all 256 cases, 142 carry no
    digit and 28 carry no lowercase, and 154 of the 256 therefore pass clause 2 AT ANY LENGTH.
    A machine takes this shortcut by accident; the old wording said nobody did, and a reader
    drew a conclusion from a green on the strength of it. Clause 3 closes it for the map formats
    by removing the base64 rather than by tuning this predicate; for every other text-bearing
    format the residue stands, is real, and is what this bullet is for.
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
  * Clause 3 reads STRUCTURE, not pixels. It can say that a map's layer data is stored plainly
    and that every <image> names a file; it cannot say the file that <image> names is the file
    the row describes, and it has nothing to say about a map that is correct in every particular
    and references somebody else's tileset. That is Gate 1's row and review's job, as above.
  * Clause 3 parses .tmx / .tsx with xml.etree, which resolves no external entities but DOES
    expand internal ones, so a hand-written entity-expansion bomb committed to resources/ could
    stall this gate. Accepted rather than defended against: the population is this repository's
    own reviewed tree, the payload has to survive code review to get there, and the failure mode
    is a CI job that hangs rather than a green — a gate that stops is not a gate that lies.

EXIT CODES.  0 clean · 1 a gate failed · 2 UNMEASURABLE — a malformed manifest, a missing
declared tree layout, nothing to measure. 2 is never silently a 0: a gate that cannot see its
population has not reported on it.

Python 3 standard library only. No network, no credential, no dependency.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import xml.etree.ElementTree as ET
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

# GATE 2 HAS NO TREE OF ITS OWN. It walks ASSET_TREES — the same population Gate 1 walks — and
# there is deliberately no second constant here to widen next time. Until card#7913 this read
# `CHARACTER_TREE = "resources/characters"`, which was correct while Gate 2 asserted an absence
# peculiar to that tree and was a leftover once the 2026-08-27 amendment rewrote its claim as a
# universal one. A Gate-2 scope that can drift from Gate 1's is the defect, not the value it
# happened to hold: a `resources/floor/` added by somebody who did not think to edit a tuple was
# covered by Gate 1 and by NEITHER Gate 2 clause, and reported clean. Same argument, and the same
# resolution, as the ASSET_TREES comment above.
#
# Its file-type allowlist is stricter than Gate 1's (which imposes none) because the claim it
# enforces is that every asset here is a FILE GATE 1 CAN SEE: a known format, with a path, with a
# row. Each member has a reason, stated in FLOOR section 10.1 clause 1 and not restated here;
# what is here is the split the code needs and the document does not.
#   TEXT-BEARING: clause 2 reads inside these, because a text file CAN be a container for an
#   undeclared asset. `.svg` is in this half rather than the other half deliberately — an SVG
#   that inlines a data:image blob is a raster asset wearing a vector file's extension, and
#   clause 2 is the only thing in this repository that can tell the two apart. The four Tiled
#   formats are text for the same reason and one more: clause 3 has to READ them.
ASSET_TEXT_EXTENSIONS = frozenset({".ts", ".js", ".md", ".svg", ".tmx", ".tmj", ".tsx", ".tsj"})
#   BINARY: admitted by clause 1, skipped by clause 2. A raster file is not a container for an
#   undeclared asset; it IS the asset, and it carries a manifest row of its own.
ASSET_BINARY_EXTENSIONS = frozenset({".png"})
ASSET_EXTENSIONS = ASSET_TEXT_EXTENSIONS | ASSET_BINARY_EXTENSIONS
BASE64_MAX_BYTES = 1024

# Clause 3's population, split by how it has to be PARSED rather than by what it means. `.tmx` and
# `.tmj` are maps, `.tsx` and `.tsj` are tilesets, and the clause makes no use of that distinction:
# a tileset can carry an embedded image and a map can carry an embedded tileset that carries one,
# so both checks run over both, and neither is skipped for being "the other kind of file".
#
# ⚠ `.tsx` HERE MEANS TILED TILESET XML, NOT TYPESCRIPT-JSX. The suffix is genuinely ambiguous and
# this is the one place in the repository where the ambiguity could bite: a React component dropped
# under resources/ would be admitted by clause 1 and then handed to an XML parser, which would fail
# it by name — a red for the wrong reason, but a red. Today nothing under resources/ is JSX and the
# generator is plain .js/.ts (FLOOR section 10.2), so the collision is harmless; it is written down
# because the next reader cannot be expected to re-derive it from a suffix.
TILED_XML_EXTENSIONS = frozenset({".tmx", ".tsx"})
TILED_JSON_EXTENSIONS = frozenset({".tmj", ".tsj"})
TILED_EXTENSIONS = TILED_XML_EXTENSIONS | TILED_JSON_EXTENSIONS

# The ONE layer encoding clause 3 admits, and the two formats state it differently.
#
# TMX: the published format documents `encoding` on <data> as "when used, valid values are base64
# and csv", with no encoding meaning one <tile> element per GID — so on the XML side the attribute
# is the authority and its ABSENCE is a third, distinct form.
#
# JSON: the published format documents `encoding` as "csv (DEFAULT) or base64", which means a
# correct CSV layer is not obliged to carry the key at all. ⛔ TILED WAS NOT RUN — it is not
# installed on the machine this was built on — so what its writer actually emits for a CSV layer is
# NOT a fact this gate has. Keying the JSON check on `"encoding": "csv"` being PRESENT would stake
# the gate on that unverified fact and red on correct output if it is wrong. So the JSON side keys
# on the SHAPE OF `data` instead, which the format does guarantee: an array of GIDs is plain, a
# string is encoded. The key is still checked when present; it is never required.
TILED_PLAIN_ENCODING = "csv"

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
# This narrows what clause 2 claims rather than widening what it admits — and WHAT IT GIVES UP IS
# LARGER THAN THIS COMMENT USED TO SAY. It read: "a hand-crafted blob of one case would evade it,
# and that is a deliberate shortcut nobody takes by accident". The first half is true; the second
# half is false, and card#7913 measured it false. Tiled's ordinary uncompressed layer data is
# base64 of little-endian uint32 GIDs, and with small GIDs the high bytes are zeros, so the run is
# drawn from a narrow slice of the alphabet: over a uniform 1,200-tile map at every GID in 0..255,
# 142 runs carry no digit and 28 carry no lowercase, so 154 of the 256 pass this predicate — at
# 6,400 B, i.e. AT ANY LENGTH, the ceiling never reached. A MACHINE TAKES THE SHORTCUT BY ACCIDENT,
# routinely, and the sentence that said nobody did was a live claim in a gate's stated residue that
# a reader draws a conclusion from.
#
# The predicate itself is unchanged and still right: the divider is an accident somebody takes
# eventually, and a gate that calls a row of `// ======` a picture gets switched off. What is
# corrected is the CLAIM ABOUT ITS RESIDUE. Clause 3 is the fix for the map formats, and it fixes
# them by removing the base64 rather than by tuning this predicate around a number the census shows
# is noise — 102 RED / 154 pass turns on which tile an artist happened to place first.
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


# --- gate 2 clause 3: the Tiled artifacts ---------------------------------------------------------
# It reads the artifact's OWN declarations — `encoding` / `compression` per tile layer, and whether
# each <image> names a source — and asserts two things about them:
#
#   (a) the tile layer data is stored PLAINLY, as CSV. Not because base64 is dangerous, but because
#       a base64 layer is a 25 KB run of clause 2's exact alphabet inside an admitted file, and the
#       two clauses would then be in permanent collision over correct work. Removing the encoding
#       removes the collision at its source; the alternative, exempting these formats from clause 2,
#       re-opens the hole clause 2 exists to close.
#   (b) no tileset image is embedded. THIS IS THE TRUE POSITIVE, and it is the reason (a) is not
#       merely tidying: Tiled can store an image's bytes inside the map or tileset instead of
#       referencing a file, and image bytes with no path have no manifest row and no provenance —
#       clause 2's subject exactly, arriving in a format clause 2 was never scoped to read.
#
# There is no heuristic here, no alphabet and no ceiling to tune. Every verdict below is a value the
# artifact states about itself, so nothing in this clause turns on a measurement that could move.

def _clause3(rel: str, msg: str) -> None:
    fail("GATE 2 clause 3", f"{rel}: {msg}")


def _unparseable(rel: str, kind: str, err: object) -> None:
    _clause3(
        rel,
        f"is not parseable as {kind} ({err}) — clause 3 therefore cannot establish that its layer "
        "data is plain or that it embeds no image. A check that cannot establish its property "
        "reports that it could not, which is a red: a file admitted by clause 1 and understood by "
        "nothing is not a file Gate 1 can see",
    )


def _json_dicts(node: object):
    """Every dict anywhere in a parsed JSON document — layers nest inside group layers."""
    if isinstance(node, dict):
        yield node
        for v in node.values():
            yield from _json_dicts(v)
    elif isinstance(node, list):
        for v in node:
            yield from _json_dicts(v)


def _tiled_json(rel: str, raw: bytes) -> None:
    try:
        doc = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as e:
        _unparseable(rel, "Tiled JSON (.tmj/.tsj)", e)
        return
    for obj in _json_dicts(doc):
        who = obj.get("name") if isinstance(obj.get("name"), str) else "(unnamed)"
        comp = obj.get("compression")
        if isinstance(comp, str) and comp.strip():
            _clause3(rel, f"tile layer {who!r} is {comp.strip()!r}-compressed — clause 3 requires "
                          f"plain {TILED_PLAIN_ENCODING.upper()} layer data")
        enc = obj.get("encoding")
        if isinstance(enc, str) and enc.strip().lower() != TILED_PLAIN_ENCODING:
            _clause3(rel, f"tile layer {who!r} declares encoding={enc.strip()!r}, not "
                          f"{TILED_PLAIN_ENCODING!r} — set the tile layer format to CSV")
        # THE AUTHORITATIVE TEST ON THE JSON SIDE, and it is deliberately not the `encoding` key.
        # Tiled omits that key entirely when the format is CSV and writes the GIDs as an ARRAY, so
        # a check keyed on the key's presence would red on Tiled's ordinary CSV output. The SHAPE
        # of `data` is stated by the artifact either way: an array is plain, a string is encoded.
        if isinstance(obj.get("data"), str):
            _clause3(rel, f"tile layer {who!r} stores its data as an encoded STRING rather than an "
                          f"array of GIDs — set the tile layer format to CSV")
        img = obj.get("image")
        if isinstance(img, str) and img.strip().lower().startswith("data:"):
            _clause3(rel, "carries an embedded tileset image — an `image` that is a data: URI is "
                          "image BYTES, not a path, so the image has no manifest row and no "
                          "provenance, which is exactly what clause 2 exists to refuse")


def _tiled_xml(rel: str, raw: bytes) -> None:
    try:
        root = ET.fromstring(raw)
    except ET.ParseError as e:
        _unparseable(rel, "Tiled XML (.tmx/.tsx)", e)
        return
    parents = {child: parent for parent in root.iter() for child in parent}

    embedded: set[object] = set()
    for img in root.iter("image"):
        src = img.get("source")
        if src is None:
            embedded.update(img.iter("data"))
            _clause3(rel, "carries an embedded tileset image — an <image> with no source= holds "
                          "its bytes inline, so the image has no path of its own, therefore no "
                          "manifest row, therefore no provenance. Reference the file instead")
        elif src.strip().lower().startswith("data:"):
            _clause3(rel, "carries an embedded tileset image — an <image source=\"data:…\"> is "
                          "image BYTES, not a path, so the image has no manifest row")

    for data in root.iter("data"):
        if data in embedded:
            continue  # already reported, by the <image> rule, as the embedded image it belongs to
        parent = parents.get(data)
        who = (parent.get("name") if parent is not None else None) or "(unnamed)"
        comp = (data.get("compression") or "").strip()
        if comp:
            _clause3(rel, f"tile layer {who!r} is {comp!r}-compressed — clause 3 requires plain "
                          f"{TILED_PLAIN_ENCODING.upper()} layer data")
        enc = (data.get("encoding") or "").strip().lower()
        if enc and enc != TILED_PLAIN_ENCODING:
            _clause3(rel, f"tile layer {who!r} declares encoding={enc!r}, not "
                          f"{TILED_PLAIN_ENCODING!r} — set the tile layer format to CSV")
        elif not enc:
            # TMX's third form: no encoding attribute at all means the GIDs are one <tile> element
            # each. It is PLAIN — it is not the hazard, and clause 2 would never fire on it — and it
            # is refused anyway, deliberately, for the reason clause 1 is an allowlist: one admitted
            # shape per format, each with a reason, beats a set nobody decided. Tiled has not
            # emitted this since 1.0 and the fix is one dropdown, which is why the cost of being
            # wrong here is a sentence rather than a rebuild.
            _clause3(rel, f"tile layer {who!r} declares no encoding — TMX stores such data as one "
                          f"<tile> element per GID. Not refused as a hazard: refused because clause "
                          f"3 admits exactly one layer form per format and that form is "
                          f"{TILED_PLAIN_ENCODING.upper()}. Set the tile layer format to CSV")


def gate2_clause3(rel: str, ext: str, raw: bytes) -> None:
    if ext in TILED_XML_EXTENSIONS:
        _tiled_xml(rel, raw)
    else:
        _tiled_json(rel, raw)


# --- gate 2 --------------------------------------------------------------------------------------

def gate2(assets: list[str]) -> None:
    # Gate 2's population IS Gate 1's population. No `startswith` filter, and no second tree
    # constant to forget to widen — see the ASSET_TEXT_EXTENSIONS comment.
    classified = read = parsed = 0
    for rel in assets:
        ext = Path(rel).suffix
        classified += 1
        if ext not in ASSET_EXTENSIONS:
            fail(
                "GATE 2 clause 1",
                f"{rel}: extension {ext or '(none)'!r} is not one of "
                f"{sorted(ASSET_EXTENSIONS)} — an allowlist refuses the format nobody "
                "anticipated, and admitting one is a change to FLOOR section 10.1 with a reason "
                "beside it, not an edit here",
            )
            # Clause 1 fails CLOSED and the file is not read further, which is why the ORDER of
            # these clauses is load-bearing: a format the allowlist refuses is never opened, so a
            # clause-1 red is never accompanied by a clause-2 or clause-3 verdict about the same
            # file. Widening the tree without widening the allowlist would therefore not widen
            # clause 2 at all — every Tiled artifact would stop here.
            continue
        if ext in ASSET_BINARY_EXTENSIONS:
            # Admitted by clause 1, skipped by clause 2 on purpose: clause 2 hunts an asset
            # embedded inside a CONTAINER, and a raster file is not a container for one — it is
            # the asset, and it has a row.
            continue
        raw = (REPO / rel).read_bytes()
        read += 1
        if ext in TILED_EXTENSIONS:
            parsed += 1
            # Clause 3 runs BEFORE clause 2 and does not suppress it. A base64-encoded layer trips
            # both, and both are printed: clause 3 names the cause (the encoding is a Tiled export
            # setting) and clause 2 names the symptom it produced. Reporting only one of them would
            # be choosing which half of a two-part finding the reader gets.
            gate2_clause3(rel, ext, raw)
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
    # Printed rather than counted silently: "0 Tiled artifact(s) parsed" is the honest reading of
    # this repository today (resources/floor/ does not exist yet), and a clause that measured
    # nothing must say so rather than ride out on the gate's green.
    notes.append(
        f"gate 2: {classified} file(s) classified by clause 1 · {read} text-bearing file(s) read "
        f"by clause 2 · {parsed} Tiled artifact(s) parsed by clause 3"
    )


# --- the lineage check (AT-D3-12's lineage half) ------------------------------------------------
# NOT a third gate — section 10.1 names two, and this does not invent a third. It is the half of
# AT-D3-12 that reads the lineage file rather than the tree, and it is here because it runs over
# the same artifacts on the same trigger. Watch its commit-SHA RED in particular: a port whose
# upstream commit nobody recorded is a port nobody can tell from a fork.

# The character tree is the LINEAGE CHECK's tree and, since card#7913, nothing else's. It used to
# double as Gate 2's scope; that was the leftover card#7913 removed. Here it is load-bearing and
# correct: the port is a fact about `resources/characters/` specifically, and a lineage file is
# owed by a tree that was ported, not by every tree that holds assets.
CHARACTER_TREE = "resources/characters"
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

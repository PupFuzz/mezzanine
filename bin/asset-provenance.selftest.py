#!/usr/bin/env python3
"""Prove-it-can-fail fixtures for bin/asset-provenance.py.

A gate never seen to fail is not evidence; it is a decoration that reports the workflow ran.
So every RED below plants one specific defect in a throwaway repository and requires the gate
to refuse it BY NAME, and the GREEN control requires the same gate to pass the same fixture
with the defect removed — which is what makes the RED attributable to the defect rather than
to the fixture being broken in some other way.

The fixtures are built in a temp directory and the gate is COPIED INTO THEM, so what runs is
the shipped bytes of `bin/asset-provenance.py` against a repository layout it discovers exactly
as it will in CI (its repo root is its own parent's parent). Nothing here touches the real tree
and nothing here deletes anything outside the temp directory the standard library made.

`docs/design/FLOOR.md` AT-D3-12 names the REDs — the unlisted asset, the UNDECLARED PICTURE,
the `origin` column three ways, the swapped bytes, the unanticipated format, the embedded asset
(both containers) and the wrong licence. The rest are the failure modes a strict parser has to
fail CLOSED on, because a row silently skipped is an asset nobody checked.

⚠ THE CONTROL THAT MATTERS MOST IS SECTION 6's COMPLEX SVG, and it is a control rather than a
RED for a reason. Since the 2026-08-27 amendment the character tree admits `.svg`, and SVG path
data is long, mixed-case and dense with digits — exactly the shape clause 2 hunts. A gate that
reds on a legitimately complex drawing GETS SWITCHED OFF, and then it is protecting nothing. So
the pair is asserted together and neither half is evidence alone: a genuinely complex
first-party SVG must PASS, and an SVG with an inlined `data:image/…` blob must FAIL.

Run: `python3 bin/asset-provenance.selftest.py`
"""

from __future__ import annotations

import base64
import hashlib
import json
import shutil
import struct
import subprocess
import sys
import tempfile
from pathlib import Path

GATE = Path(__file__).resolve().parent / "asset-provenance.py"

CLEAN_JS = "export const hi = () => 'hello';\n"
MIT_NOTICE = "The above copyright notice and this permission notice shall be included in all copies."

# A genuinely complex first-party SVG, built around THE EXACT SHAPE THAT MAKES THIS CONTROL
# DISCRIMINATING rather than around "looks complicated".
#
# An ordinary pretty-printed SVG proves nothing here: its path data is broken every few
# characters by `.`, `,`, `"` and spaces, so the longest run of base64-ish characters in one is
# about 20 B — three orders of magnitude under the 1,024 B ceiling, and it would have passed
# clause 2 under ANY alphabet. Measured on this fixture's first draft: longest run 20 B.
#
# The shape that actually collides with clause 2 is MINIFIED, INTEGER-COORDINATE path data —
# what SVGO and every drawing tool's "optimised" export emit — where `-` IS the separator and
# there are no decimals, commas or spaces at all: `M12-3c4-5l6-7q8-9`. Under the alphabet an
# earlier revision of the gate used (`[A-Za-z0-9+/=_-]`, base64URL's superset) that is ONE
# UNBROKEN RUN of thousands of characters containing upper, lower and digits — i.e. clause 2
# reds on a legitimate drawing, and A GATE THAT REDS ON CORRECT WORK GETS SWITCHED OFF.
#
# Under base64's own alphabet the `-` separators break it into fragments of a few bytes. That is
# what the narrowing bought and this fixture is what proves it: restore `_-` to BASE64_RUN_RE and
# the control below goes RED. It was watched doing exactly that.
_MINIFIED_PATH = "M12-3" + "".join(f"c4-5l6-{7 + i % 9}q8-9t2-3S{i % 40}-7" for i in range(90)) + "z"
_PRETTY_PATH = ("M12.5 3.2c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7.4l-4.2-4.2H12.5z"
                "M9.8 21.6c-2.4 0-4.4-2-4.4-4.4s2-4.4 4.4-4.4 4.4 2 4.4 4.4-2 4.4-4.4 4.4z")
COMPLEX_SVG = (
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" role="img">\n'
    f'  <path d="{_MINIFIED_PATH}" fill="#a8e6c3" stroke="#6fbf94" stroke-width="1.4"/>\n'
    + "".join(
        f'  <path d="{_PRETTY_PATH}" fill="#ffc9a3" '
        f'transform="translate({i * 3}.5 {i * 2}.25) rotate({i * 9})"/>\n'
        for i in range(24)
    )
    + '  <title>a first-party creature</title>\n</svg>\n'
)
# A minimal lineage file that satisfies section 10.2's five required facts. Each RED in section 8
# removes exactly one of them, so a red is attributable to the missing fact and not to the
# fixture being vague.
LINEAGE_FIELDS = {
    "url": "Upstream: https://github.com/someone/someproject\n",
    "sha": "Commit: 0123456789abcdef0123456789abcdef01234567\n",
    "copyright": "Copyright (c) 2026 Someone\n",
    "notice": MIT_NOTICE + "\n",
    "omissions": "## What was deliberately not taken, and why\n\nThe bundled art.\n",
}


def lineage_md(drop: str | None = None) -> str:
    return "# Lineage\n\n" + "\n".join(v for k, v in LINEAGE_FIELDS.items() if k != drop)


CLEAN_MD = lineage_md()

failures = 0
ran = 0


def build(tmp: Path, files: dict[str, str | bytes],
          rows: list[tuple[str, str, str, str, str, str, str | None]],
          manifest_body: str | None = None, manifest_notice: bool = True) -> Path:
    """A throwaway repo: the gate at bin/, the given files, and a manifest of the given rows.

    A row is (path, origin, url, author, spdx, retrieved, sha). A SHA cell of None means
    'compute it from the file' — so a fixture states only the thing it is perturbing and
    everything else is correct by construction.
    """
    repo = tmp / "repo"
    (repo / "bin").mkdir(parents=True)
    (repo / "docs").mkdir(parents=True)
    shutil.copy(GATE, repo / "bin" / "asset-provenance.py")
    for rel, content in files.items():
        p = repo / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_bytes(content.encode() if isinstance(content, str) else content)

    if manifest_body is None:
        lines = ["| Path | Origin | Source URL | Author | SPDX | Retrieved | SHA-256 |",
                 "|---|---|---|---|---|---|---|"]
        for path, origin, url, author, spdx, retrieved, sha in rows:
            if sha is None:
                sha = hashlib.sha256((repo / path).read_bytes()).hexdigest()
            lines.append(f"| `{path}` | {origin} | {url} | {author} | {spdx} | {retrieved} | `{sha}` |")
        manifest_body = "\n".join(lines)
    # The notice goes in by default because section 10.2 requires it in BOTH files; the fixture
    # that tests its ABSENCE passes manifest_notice=False.
    notice = MIT_NOTICE + "\n" if manifest_notice else ""
    (repo / "docs" / "ATTRIBUTION.md").write_text(
        "# Attribution\n\n" + notice + "\n<!-- asset-manifest:begin -->\n\n"
        + manifest_body + "\n\n<!-- asset-manifest:end -->\n"
    )
    return repo


def run(repo: Path) -> tuple[int, str]:
    r = subprocess.run([sys.executable, str(repo / "bin" / "asset-provenance.py")],
                       capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def case(name: str, want_code: int, want_text: str | None, files, rows, manifest_body=None,
         manifest_notice=True, forbid_text: str | None = None) -> None:
    """One fixture. `forbid_text` asserts a string is ABSENT from the output.

    The absence assertion exists for section 13's evasion pair, where the finding is that one
    clause DOES NOT fire — a claim no substring match can make, and the whole reason the map
    formats are handled by removing the base64 rather than by measuring how long it is.
    """
    global failures, ran
    ran += 1
    with tempfile.TemporaryDirectory() as td:
        repo = build(Path(td), files, rows, manifest_body, manifest_notice)
        code, out = run(repo)
    ok = (code == want_code
          and (want_text is None or want_text in out)
          and (forbid_text is None or forbid_text not in out))
    if ok:
        print(f"  ok   {name}  (exit {code})")
    else:
        failures += 1
        print(f"  FAIL {name}: wanted exit {want_code}"
              + (f" naming {want_text!r}" if want_text else "")
              + (f" and NOT naming {forbid_text!r}" if forbid_text else "")
              + f", got exit {code}\n---\n{out}\n---")


REPO_URL = "https://github.com/PupFuzz/mezzanine"
URL = "https://example.invalid/x"
PASS = "BOTH ASSET GATES PASS + LINEAGE COMPLETE"
# The two base rows are FIRST-PARTY against the repository's own URL, which is what the real
# manifest carries for them; a `licensed` row is written explicitly wherever a case needs one.
BASE = [
    ("resources/characters/index.js", "first-party", REPO_URL, "Somebody", "MIT", "2026-08-25", None),
    ("resources/characters/LINEAGE.md", "first-party", REPO_URL, "Somebody", "MIT", "2026-08-25", None),
]
FILES = {"resources/characters/index.js": CLEAN_JS, "resources/characters/LINEAGE.md": CLEAN_MD}


def row(path, origin="first-party", url=REPO_URL, author="Somebody", spdx="MIT",
        retrieved="2026-08-25", sha=None):
    """One correct-by-construction row, with only the perturbed cell named at the call site."""
    return (path, origin, url, author, spdx, retrieved, sha)


print("bin/asset-provenance.py — prove-it-can-fail fixtures\n")

print("1. the discriminating control — a clean tree MUST pass, or every RED below is meaningless")
case("clean tree passes", 0, PASS, FILES, BASE)

print("\n2. GATE 1 — every asset has a row, and the row is about THIS file")
case("AT-D3-12 unlisted asset: a file with no row",
     1, "has no ATTRIBUTION row",
     {**FILES, "resources/characters/extra.js": "export const x = 1;\n"}, BASE)
case("AT-D3-12 THE UNDECLARED PICTURE: an .svg the amendment ADMITS, with no manifest row. "
     "Under the old absence gate the file was refused for being an image and the manifest was "
     "never the thing tested",
     1, "creature.svg has no ATTRIBUTION row",
     {**FILES, "resources/characters/creature.svg": COMPLEX_SVG}, BASE)
case("AT-D3-12 swapped bytes: the row stays, the file changes",
     1, "the bytes moved",
     FILES,
     [row("resources/characters/index.js", sha="0" * 64), BASE[1]])
case("orphan row: a row whose file is not there",
     1, "no such file under any asset tree",
     FILES, BASE + [row("resources/characters/ghost.js", sha="0" * 64)])

print("\n3. GATE 1 — the ORIGIN column is CLOSED at two, and is checked against the row's own URL")
case("AT-D3-12 origin RED (a): no origin at all — the row does not say where the asset came from",
     1, "no origin", FILES,
     [row("resources/characters/index.js", origin=""), BASE[1]])
case("AT-D3-12 origin RED (b): `vendored` — a plausible third word nobody decided",
     1, "is not in the closed set", FILES,
     [row("resources/characters/index.js", origin="vendored"), BASE[1]])
case("AT-D3-12 origin RED (c): `first-party` over SOMEBODY ELSE'S URL — the one lie in this "
     "class a machine can catch",
     1, "contradicts itself", FILES,
     [row("resources/characters/index.js", origin="first-party", url=URL), BASE[1]])
case("origin RED (d): `licensed` over THIS repository's URL — a licensed asset came from outside",
     1, "a licensed asset came from OUTSIDE", FILES,
     [row("resources/characters/index.js", origin="licensed", url=REPO_URL), BASE[1]])
case("CONTROL — a genuinely `licensed` row (external URL, allowlisted SPDX) PASSES, so the "
     "column is known to admit both its members and not merely to refuse things",
     0, PASS,
     {**FILES, "resources/characters/tile.png": b"\x89PNG\r\n\x1a\n" + b"\x00" * 64},
     BASE + [row("resources/characters/tile.png", origin="licensed", url=URL, spdx="CC0-1.0")])

print("\n4. GATE 1 — the licence allowlist is CLOSED")
case("AT-D3-12 wrong licence: CC-BY-NC-4.0",
     1, "is not in the closed allowlist",
     FILES,
     [row("resources/characters/index.js", origin="licensed", url=URL, spdx="CC-BY-NC-4.0"), BASE[1]])
case("ISC is refused too — permissive, MIT-compatible, and still not on the list",
     1, "is not in the closed allowlist",
     FILES,
     [row("resources/characters/index.js", origin="licensed", url=URL,
          author="shahar061", spdx="ISC"), BASE[1]])
case("CC0-1.0 is accepted — the allowlist admits both its members, not just MIT",
     0, PASS,
     FILES,
     [row("resources/characters/index.js", spdx="CC0-1.0"), BASE[1]])

print("\n5. GATE 1 — the other columns are load-bearing, not decoration")
case("a retrieved date that is not a date",
     1, "is not an ISO date",
     FILES,
     [row("resources/characters/index.js", retrieved="recently"), BASE[1]])
case("a source that is not a URL a human can check",
     1, "is not a URL",
     FILES,
     [row("resources/characters/index.js", url="the internet"), BASE[1]])
case("no author — the attribution obligation with no subject",
     1, "no author",
     FILES,
     [row("resources/characters/index.js", author=""), BASE[1]])

print("\n6. GATE 2 clause 1 — an ALLOWLIST of file types, so an unanticipated format is refused")
case("AT-D3-12 the unanticipated format: sprites.avif with a COMPLETE, HONEST manifest row. "
     "The row being correct is the point — this tests the file-type allowlist and nothing else",
     1, "sprites.avif",
     {**FILES, "resources/characters/sprites.avif": b"\x00\x00\x00 ftypavif"},
     BASE + [row("resources/characters/sprites.avif", origin="licensed", url=URL)])
case(".webp is refused too — a second raster format buys nothing and doubles the surface",
     1, "sprites.webp",
     {**FILES, "resources/characters/sprites.webp": b"RIFF\x00\x00\x00\x00WEBPVP8 "},
     BASE + [row("resources/characters/sprites.webp", origin="licensed", url=URL)])
case("a sprite sheet disguised with no extension at all",
     1, "is not one of",
     {**FILES, "resources/characters/atlas": b"\x89PNG\r\n\x1a\n"},
     BASE + [row("resources/characters/atlas", origin="licensed", url=URL)])
case("CONTROL — the two formats the amendment ADMITS pass: a first-party .svg and a .png, each "
     "with a row. Without this the allowlist would be indistinguishable from the absence gate "
     "it replaced",
     0, PASS,
     {**FILES, "resources/characters/creature.svg": COMPLEX_SVG,
      "resources/characters/tile.png": b"\x89PNG\r\n\x1a\n" + b"\x00" * 64},
     BASE + [row("resources/characters/creature.svg"),
             row("resources/characters/tile.png", origin="licensed", url=URL, spdx="CC0-1.0")])

print("\n7. GATE 2 clause 2 — an asset with no PATH has no ROW, so it has no provenance")
BLOB = base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"\xde\xad\xbe\xef" * 12000).decode()
case("AT-D3-12 40 KB base64 PNG in a .ts the allowlist admits",
     1, "exceeds the 1024 B ceiling",
     {**FILES, "resources/characters/atlas.ts": f"export const A = '{BLOB}';\n"},
     BASE + [row("resources/characters/atlas.ts")])
WRAPPED = "\n".join(BLOB[i:i + 76] for i in range(0, len(BLOB), 76))
case("the same blob broken across lines — caught by the whitespace-stripped pass",
     1, "with whitespace stripped",
     {**FILES, "resources/characters/atlas.ts": f"export const A = `\n{WRAPPED}\n`;\n"},
     BASE + [row("resources/characters/atlas.ts")])
case("AT-D3-12 THE RASTER WEARING A VECTOR'S EXTENSION: an .svg with an inlined "
     "data:image/png;base64 blob",
     1, "carries a data:image/ URI",
     {**FILES, "resources/characters/creature.svg":
      COMPLEX_SVG.replace("<title>", f'<image href="data:image/png;base64,{BLOB}"/><title>')},
     BASE + [row("resources/characters/creature.svg")])
case("⭐ THE DISCRIMINATING CONTROL — a genuinely complex first-party SVG, carrying a 1,926 B "
     "MINIFIED INTEGER PATH whose separator is `-`, MUST PASS. It is one unbroken run under "
     "base64URL's alphabet and fragments under base64's own, which is the whole of what the "
     "narrowing bought; restore `_-` to BASE64_RUN_RE and this case goes RED, watched. Without "
     "it the RED above is satisfied by a gate that refuses every drawing ever made, and a gate "
     "that reds on correct work gets switched off",
     0, PASS,
     {**FILES, "resources/characters/creature.svg": COMPLEX_SVG},
     BASE + [row("resources/characters/creature.svg")])
case("CONTROL — 40 lines of `// ======` divider, which whitespace-stripping concatenates into a "
     "1,600 B run of pure base64 ALPHABET, is not a picture",
     0, PASS,
     {**FILES, "resources/characters/dividers.js": ("// " + "=" * 37 + "\n") * 40 + "export const x = 1;\n"},
     BASE + [row("resources/characters/dividers.js")])
case("a real data URI in an admitted file",
     1, "carries a data:image/ URI",
     {**FILES, "resources/characters/icon.js": "export const I = 'data:image/png;base64,iVBORw0KGgo=';\n"},
     BASE + [row("resources/characters/icon.js")])
case("an SVG data URI, which carries no `base64` token at all",
     1, "carries a data:image/ URI",
     {**FILES, "resources/characters/icon.js": "export const I = 'data:image/svg+xml,<svg/>';\n"},
     BASE + [row("resources/characters/icon.js")])
case("CONTROL — a lineage file that MENTIONS `data:image/` in prose is not a picture",
     0, PASS,
     {**FILES, "resources/characters/LINEAGE.md":
      CLEAN_MD + "\nNo file here may carry a `data:image/` URI, per FLOOR section 10.1 Gate 2.\n"},
     BASE)
case("CONTROL — a .png's own compressed BYTES are not scanned by clause 2, which reasons about "
     "a file that CONTAINS an undeclared asset and not about one that IS a declared asset",
     0, PASS,
     {**FILES, "resources/characters/tile.png":
      b"\x89PNG\r\n\x1a\n" + BLOB.encode() + b"data:image/png;base64,x"},
     BASE + [row("resources/characters/tile.png", origin="licensed", url=URL, spdx="CC0-1.0")])

print("\n8. THE LINEAGE CHECK — AT-D3-12's lineage half, one RED per required fact")
case("AT-D3-12 lineage RED: the COMMIT SHA is dropped, the repository URL kept",
     1, "does not carry the upstream commit SHA",
     {**FILES, "resources/characters/LINEAGE.md": lineage_md("sha")}, BASE)
case("the upstream repository URL is dropped",
     1, "does not carry the upstream repository URL",
     {**FILES, "resources/characters/LINEAGE.md": lineage_md("url")}, BASE)
case("the MIT copyright line is dropped",
     1, "does not carry the MIT copyright line",
     {**FILES, "resources/characters/LINEAGE.md": lineage_md("copyright")}, BASE)
case("the MIT permission notice is dropped — a link is not a reproduction",
     1, "does not carry the MIT permission notice",
     {**FILES, "resources/characters/LINEAGE.md": lineage_md("notice")}, BASE)
case("no statement of what was deliberately NOT taken — the line between a port and a fork",
     1, "deliberately NOT taken",
     {**FILES, "resources/characters/LINEAGE.md": lineage_md("omissions")}, BASE)
case("the MIT notice is in the lineage file but NOT in the manifest — section 10.2 says both",
     1, "does not reproduce the MIT permission notice",
     FILES, BASE, manifest_notice=False)
case("the lineage file is missing entirely",
     1, "the port has no record of where it came from",
     {"resources/characters/index.js": CLEAN_JS}, [BASE[0]])

print("\n9. GATE 2 IS SCOPED TO ALL OF resources/, not to the character tree (card#7913)")
# THE GAP THIS CARD CLOSED, made executable. Until card#7913, Gate 2 ran over resources/characters/
# while Gate 1 ran over all of resources/ — so `resources/floor/`, the tree about to receive this
# project's FIRST vendored third-party art, was covered by Gate 1 and by NEITHER Gate 2 clause.
# Both cases below PASSED the gate before the widening. Revert either scoping knob and they go
# green again, which is the only way to tell this section from decoration.
case("card#7913 clause 1 OUTSIDE the character tree: a .psd under resources/floor/ with a "
     "COMPLETE, HONEST row. Passed the gate before the widening — the row was all Gate 1 asked "
     "for and clause 1 never looked at the tree",
     1, "sheet.psd",
     {**FILES, "resources/floor/sheet.psd": b"8BPS\x00\x01"},
     BASE + [row("resources/floor/sheet.psd", origin="licensed", url=URL, spdx="CC0-1.0")])
case("card#7913 clause 2 OUTSIDE the character tree: a 40 KB base64 PNG pasted into a .js under "
     "resources/floor/ — image bytes with no path, no row and no provenance, which the old scope "
     "could not see at all",
     1, "exceeds the 1024 B ceiling",
     {**FILES, "resources/floor/atlas.js": f"export const A = '{BLOB}';\n"},
     BASE + [row("resources/floor/atlas.js")])

print("\n10. GATE 2 clause 3 — the Tiled artifacts declare their own encoding, so the gate reads it")
# The four formats admitted by clause 1 for card#7913, and the plain forms of each.
#
# ⛔ TILED WAS NOT RUN. It is not installed on the machine these fixtures were written on, so every
# artifact below is CONSTRUCTED FROM THE PUBLISHED 1.8/1.10 FORMAT SPECS and none of them is a byte
# Tiled emitted. What that leaves unverified is named rather than papered over: whether Tiled's
# JSON writer emits `"encoding": "csv"` or omits it. The spec documents csv as the DEFAULT, so the
# CSV .tmj below carries NO `encoding` key — the harder of the two shapes for the check to accept,
# and the one a check keyed on the key's presence would red on. The .tmx carries `encoding="csv"`,
# which the TMX spec does require. Neither the check nor these fixtures rest on the unverified half:
# the JSON side keys on the shape of `data`.
CSV_TMJ = json.dumps({
    "type": "map", "orientation": "orthogonal", "width": 4, "height": 2, "tilewidth": 16,
    "tileheight": 16, "infinite": False, "tilesets": [{"firstgid": 1, "source": "office.tsx"}],
    "layers": [{"type": "tilelayer", "id": 1, "name": "floor", "width": 4, "height": 2,
                "x": 0, "y": 0, "opacity": 1, "visible": True,
                "data": [1, 2, 3, 4, 5, 6, 7, 8]},
               {"type": "objectgroup", "id": 2, "name": "desks", "objects": []}],
}, indent=1)
CSV_TMX = ('<?xml version="1.0" encoding="UTF-8"?>\n'
           '<map version="1.10" orientation="orthogonal" width="4" height="2" '
           'tilewidth="16" tileheight="16">\n'
           ' <tileset firstgid="1" source="office.tsx"/>\n'
           ' <layer id="1" name="floor" width="4" height="2">\n'
           '  <data encoding="csv">\n1,2,3,4,\n5,6,7,8\n</data>\n'
           ' </layer>\n'
           ' <objectgroup id="2" name="desks"/>\n'
           '</map>\n')
REF_TSX = ('<?xml version="1.0" encoding="UTF-8"?>\n'
           '<tileset version="1.10" name="office" tilewidth="16" tileheight="16" tilecount="4" '
           'columns="2">\n <image source="office.png" width="32" height="32"/>\n</tileset>\n')
REF_TSJ = json.dumps({"name": "office", "tilewidth": 16, "tileheight": 16, "tilecount": 4,
                      "columns": 2, "image": "office.png", "imagewidth": 32, "imageheight": 32})
TILED_PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64


def tiled_files(**over) -> dict:
    """A complete, correct Tiled map set under resources/floor/, with named parts replaceable."""
    base = {"resources/floor/aimla.tmj": CSV_TMJ, "resources/floor/aimla.tmx": CSV_TMX,
            "resources/floor/office.tsx": REF_TSX, "resources/floor/office.tsj": REF_TSJ,
            "resources/floor/office.png": TILED_PNG}
    base.update(over)
    return {**FILES, **base}


def tiled_rows() -> list:
    return BASE + [row(p, origin="licensed", url=URL, spdx="CC0-1.0")
                   for p in ("resources/floor/aimla.tmj", "resources/floor/aimla.tmx",
                             "resources/floor/office.tsx", "resources/floor/office.tsj",
                             "resources/floor/office.png")]


case("⭐ THE CONTROL WITHOUT WHICH EVERY RED BELOW IS MEANINGLESS — a correct CSV map set (.tmj "
     "with an ARRAY and NO `encoding` key, the shape the spec's default permits and the harder "
     "one for the check to accept; .tmx with encoding=\"csv\"; .tsx "
     "and .tsj referencing office.png by path) PASSES all three clauses, carries no base64 run "
     "at all, and needs no carve-out from clause 2's 1,024 B ceiling",
     0, PASS, tiled_files(), tiled_rows())

# Base64 layer data as the published format specifies it — little-endian uint32 GIDs, 1,200 tiles.
# base64 is the documented DEFAULT for the layer format; these bytes are constructed from the spec,
# not captured from Tiled, which was never run here.
def b64_layer(gid: int, n: int = 1200) -> str:
    return base64.b64encode(struct.pack("<%dI" % n, *([gid] * n))).decode()


B64_TMJ = json.dumps({"type": "map", "width": 40, "height": 30, "tilewidth": 16, "tileheight": 16,
                      "tilesets": [{"firstgid": 1, "source": "office.tsx"}],
                      "layers": [{"type": "tilelayer", "name": "floor", "width": 40, "height": 30,
                                  "encoding": "base64", "data": b64_layer(14)}]})
case("clause 3 RED — a .tmj whose layer is base64, the encoding Tiled uses by DEFAULT",
     1, "declares encoding='base64'", tiled_files(**{"resources/floor/aimla.tmj": B64_TMJ}),
     tiled_rows())
ZLIB_TMJ = json.dumps({"type": "map", "width": 40, "height": 30,
                       "layers": [{"type": "tilelayer", "name": "floor", "encoding": "base64",
                                   "compression": "zlib", "data": "eJxjYBgFo2AUjIJRMApGwUgHAAg"}]})
case("clause 3 RED — a zlib-compressed layer",
     1, "is 'zlib'-compressed", tiled_files(**{"resources/floor/aimla.tmj": ZLIB_TMJ}),
     tiled_rows())
GROUP_TMJ = json.dumps({"type": "map", "width": 4, "height": 2, "layers": [
    {"type": "group", "name": "outer", "layers": [
        {"type": "group", "name": "inner", "layers": [
            {"type": "tilelayer", "name": "buried", "encoding": "base64",
             "data": b64_layer(14)}]}]}]})
case("clause 3 RED — a base64 layer buried two GROUP layers deep. A non-recursive walk of "
     "`layers[]` reports this map clean, and Tiled groups layers by default in any real map",
     1, "tile layer 'buried' declares encoding='base64'",
     tiled_files(**{"resources/floor/aimla.tmj": GROUP_TMJ}), tiled_rows())
B64_TMX = CSV_TMX.replace('<data encoding="csv">\n1,2,3,4,\n5,6,7,8\n</data>',
                          f'<data encoding="base64">{b64_layer(14)}</data>')
case("clause 3 RED — the same defect in .tmx, which is a different parser and therefore a "
     "different check that has to be seen failing on its own",
     1, "declares encoding='base64'", tiled_files(**{"resources/floor/aimla.tmx": B64_TMX}),
     tiled_rows())
LEGACY_TMX = CSV_TMX.replace('<data encoding="csv">\n1,2,3,4,\n5,6,7,8\n</data>',
                             "<data>" + "".join(f'<tile gid="{g}"/>' for g in range(1, 9)) + "</data>")
case("clause 3 RED — TMX's third layer form, one <tile> element per GID. It is PLAIN and it is "
     "not the hazard; it is refused because clause 3 admits exactly ONE form per format, and the "
     "message says which reason applies so nobody reads it as a security finding",
     1, "declares no encoding", tiled_files(**{"resources/floor/aimla.tmx": LEGACY_TMX}),
     tiled_rows())

print("\n11. GATE 2 clause 3 — the EMBEDDED TILESET IMAGE, which is the true positive")
EMBEDDED_TSX = ('<?xml version="1.0" encoding="UTF-8"?>\n'
                '<tileset version="1.10" name="office" tilewidth="16" tileheight="16">\n'
                ' <image format="png" width="32" height="32">\n'
                f'  <data encoding="base64">{base64.b64encode(TILED_PNG).decode()}</data>\n'
                ' </image>\n</tileset>\n')
case("⭐ clause 3 RED — a .tsx whose <image> has NO source= and holds the PNG's bytes inline. "
     "This is the hole the card exists to close: image bytes with no path, so no manifest row, "
     "so no provenance — and exempting the map formats from clause 2 would have re-opened it",
     1, "carries an embedded tileset image",
     tiled_files(**{"resources/floor/office.tsx": EMBEDDED_TSX}), tiled_rows())
case("clause 3 RED — the embedded image reported ONCE, by the <image> rule that understands it, "
     "not a second time by the layer-encoding rule that would call its <data> a tile layer",
     1, "carries an embedded tileset image",
     tiled_files(**{"resources/floor/office.tsx": EMBEDDED_TSX}), tiled_rows(),
     forbid_text="tile layer '(unnamed)' declares encoding='base64'")
DATAURI_TSJ = json.dumps({"name": "office", "tilewidth": 16, "tileheight": 16,
                          "image": "data:image/png;base64," + base64.b64encode(TILED_PNG).decode()})
case("clause 3 RED — the JSON form of the same thing: an `image` that is a data: URI rather "
     "than a path",
     1, "carries an embedded tileset image",
     tiled_files(**{"resources/floor/office.tsj": DATAURI_TSJ}), tiled_rows())

print("\n12. clause 3 — a file it cannot PARSE is a red, never a skip")
case("an unparseable .tmj: a check that cannot establish its property says so",
     1, "is not parseable as Tiled JSON",
     tiled_files(**{"resources/floor/aimla.tmj": "{ not json at all"}), tiled_rows())
case("an unparseable .tmx — including the .tsx COLLISION CASE, a TypeScript-JSX file under "
     "resources/ that clause 1 admits by suffix and an XML parser then fails by name",
     1, "is not parseable as Tiled XML",
     tiled_files(**{"resources/floor/office.tsx":
                    "export const Desk = () => <div className='desk'>{seat}</div>;\n"}),
     tiled_rows())

print("\n13. ⭐ WHY CSV AND NOT A CARVE-OUT — clause 2 CANNOT be trusted to catch a base64 layer")
# The finding that decided this card, executable. `looks_encoded()` is an AND over three character
# classes, so a run with no digit passes clause 2 AT ANY LENGTH. Tiled's uncompressed base64 layer
# data is little-endian uint32 GIDs; with small GIDs three bytes in four are zero, and the run is
# drawn from a narrow slice of the alphabet. Over a uniform 1,200-tile map at every GID in 0..255
# the run is 6,400 B in ALL 256 cases and 154 of them pass clause 2 — the verdict turns on WHICH
# TILE the artist placed, which changes no rendered pixel. The pair below is that coin, both faces.
EVADES = json.dumps({"type": "map", "width": 40, "height": 30,
                     "layers": [{"type": "tilelayer", "name": "floor", "encoding": "base64",
                                 "data": b64_layer(1)}]})
REDS = json.dumps({"type": "map", "width": 40, "height": 30,
                   "layers": [{"type": "tilelayer", "name": "floor", "encoding": "base64",
                               "data": b64_layer(14)}]})
case("⭐ GID 1 — the FIRST TILE OF THE TILESET — encodes to a 6,400 B run with no digit and no "
     "lowercase, so clause 2 DOES NOT FIRE ON IT AT ANY LENGTH. Clause 3 catches it anyway, "
     "which is the whole argument for removing the encoding instead of tuning the ceiling",
     1, "GATE 2 clause 3", tiled_files(**{"resources/floor/aimla.tmj": EVADES}), tiled_rows(),
     forbid_text="GATE 2 clause 2")
case("GID 14 — the same map, the same size, one different tile — DOES trip clause 2. Same file, "
     "same encoding, opposite clause-2 verdict: that is the number a carve-out would have had to "
     "be reasoned against, and it is noise",
     1, "exceeds the 1024 B ceiling", tiled_files(**{"resources/floor/aimla.tmj": REDS}),
     tiled_rows())

print("\n14. UNMEASURABLE (exit 2) — every state the gate cannot see its population in")
case("nothing under any asset tree — an empty measurement is not a clean one",
     2, "nothing was measured", {}, [])
# Built by hand rather than through `case()`, because the perturbation is the ABSENCE of a file
# the builder always writes.
with tempfile.TemporaryDirectory() as td:
    ran += 1
    repo = build(Path(td), FILES, BASE)
    (repo / "docs" / "ATTRIBUTION.md").unlink()
    code, out = run(repo)
    if code == 2 and "does not exist" in out:
        print(f"  ok   no manifest at all  (exit {code})")
    else:
        failures += 1
        print(f"  FAIL no manifest at all: wanted exit 2, got {code}\n---\n{out}\n---")
case("two rows for one path — which one is the provenance?",
     2, "more than one row", FILES,
     BASE + [row("resources/characters/index.js", author="Somebody Else")])
case("a row with the wrong number of cells is never SKIPPED",
     2, "cells, expected 7", FILES, BASE,
     manifest_body=("| Path | Origin | Source URL | Author | SPDX | Retrieved | SHA-256 |\n"
                    "|---|---|---|---|---|---|---|\n"
                    "| `resources/characters/index.js` | first-party | x | y | MIT | 2026-08-25 |"))
case("a renamed or reordered column header",
     2, "the 7 columns", FILES, BASE,
     manifest_body=("| File | Origin | Source URL | Author | SPDX | Retrieved | SHA-256 |\n"
                    "|---|---|---|---|---|---|---|\n"
                    "| `resources/characters/index.js` | first-party | u | a | MIT | 2026-08-25 | `x` |"))
case("THE OLD SIX-COLUMN MANIFEST is unmeasurable, not silently accepted — a manifest written "
     "before the 2026-08-27 amendment declares no origin for anything, and reading it as though "
     "it did would be the gate inventing the very fact it exists to check",
     2, "the 7 columns", FILES, BASE,
     manifest_body=("| Path | Source URL | Author | SPDX | Retrieved | SHA-256 |\n"
                    "|---|---|---|---|---|---|\n"
                    "| `resources/characters/index.js` | u | a | MIT | 2026-08-25 | `x` |"))
case("a manifest block with no table in it",
     2, "holds no table", FILES, BASE, manifest_body="(the table used to be here)")

print(f"\n{ran} fixtures run — " + ("ALL PASS" if failures == 0 else f"{failures} FAILED"))
sys.exit(1 if failures else 0)

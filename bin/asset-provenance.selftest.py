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

`docs/design/FLOOR.md` AT-D3-12 names four of these REDs — the unlisted asset, the swapped
bytes, the vendored character (both clauses) and the wrong licence. The rest are the failure
modes a strict parser has to fail CLOSED on, because a row silently skipped is an asset nobody
checked.

Run: `python3 bin/asset-provenance.selftest.py`
"""

from __future__ import annotations

import base64
import hashlib
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

GATE = Path(__file__).resolve().parent / "asset-provenance.py"

CLEAN_JS = "export const hi = () => 'hello';\n"
MIT_NOTICE = "The above copyright notice and this permission notice shall be included in all copies."
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


def build(tmp: Path, files: dict[str, str | bytes], rows: list[tuple[str, str, str, str, str, str | None]],
          manifest_body: str | None = None, manifest_notice: bool = True) -> Path:
    """A throwaway repo: the gate at bin/, the given files, and a manifest of the given rows.

    A row's SHA cell of None means 'compute it from the file' — so a fixture states only the
    thing it is perturbing and everything else is correct by construction.
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
        lines = ["| Path | Source URL | Author | SPDX | Retrieved | SHA-256 |", "|---|---|---|---|---|---|"]
        for path, url, author, spdx, retrieved, sha in rows:
            if sha is None:
                sha = hashlib.sha256((repo / path).read_bytes()).hexdigest()
            lines.append(f"| `{path}` | {url} | {author} | {spdx} | {retrieved} | `{sha}` |")
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
         manifest_notice=True) -> None:
    global failures, ran
    ran += 1
    with tempfile.TemporaryDirectory() as td:
        repo = build(Path(td), files, rows, manifest_body, manifest_notice)
        code, out = run(repo)
    ok = code == want_code and (want_text is None or want_text in out)
    if ok:
        print(f"  ok   {name}  (exit {code})")
    else:
        failures += 1
        print(f"  FAIL {name}: wanted exit {want_code}"
              + (f" naming {want_text!r}" if want_text else "")
              + f", got exit {code}\n---\n{out}\n---")


URL = "https://example.invalid/x"
BASE = [
    ("resources/characters/index.js", URL, "Somebody", "MIT", "2026-08-25", None),
    ("resources/characters/LINEAGE.md", URL, "Somebody", "MIT", "2026-08-25", None),
]
FILES = {"resources/characters/index.js": CLEAN_JS, "resources/characters/LINEAGE.md": CLEAN_MD}

print("bin/asset-provenance.py — prove-it-can-fail fixtures\n")

print("1. the discriminating control — a clean tree MUST pass, or every RED below is meaningless")
case("clean tree passes", 0, "BOTH ASSET GATES PASS + LINEAGE COMPLETE", FILES, BASE)

print("\n2. GATE 1 — every asset has a row, and the row is about THIS file")
case("AT-D3-12 unlisted asset: a file with no row",
     1, "has no ATTRIBUTION row",
     {**FILES, "resources/characters/extra.js": "export const x = 1;\n"}, BASE)
case("AT-D3-12 swapped bytes: the row stays, the file changes",
     1, "the bytes moved",
     FILES,
     [("resources/characters/index.js", URL, "Somebody", "MIT", "2026-08-25", "0" * 64), BASE[1]])
case("orphan row: a row whose file is not there",
     1, "no such file under any asset tree",
     FILES, BASE + [("resources/characters/ghost.js", URL, "Somebody", "MIT", "2026-08-25", "0" * 64)])

print("\n3. GATE 1 — the licence allowlist is CLOSED")
case("AT-D3-12 wrong licence: CC-BY-NC-4.0",
     1, "is not in the closed allowlist",
     FILES,
     [("resources/characters/index.js", URL, "Somebody", "CC-BY-NC-4.0", "2026-08-25", None), BASE[1]])
case("ISC is refused too — permissive, MIT-compatible, and still not on the list",
     1, "is not in the closed allowlist",
     FILES,
     [("resources/characters/index.js", URL, "shahar061", "ISC", "2026-08-25", None), BASE[1]])
case("CC0-1.0 is accepted — the allowlist admits both its members, not just MIT",
     0, "BOTH ASSET GATES PASS + LINEAGE COMPLETE",
     FILES,
     [("resources/characters/index.js", URL, "Somebody", "CC0-1.0", "2026-08-25", None), BASE[1]])

print("\n4. GATE 1 — the other four columns are load-bearing, not decoration")
case("a retrieved date that is not a date",
     1, "is not an ISO date",
     FILES,
     [("resources/characters/index.js", URL, "Somebody", "MIT", "recently", None), BASE[1]])
case("a source that is not a URL a human can check",
     1, "is not a URL",
     FILES,
     [("resources/characters/index.js", "the internet", "Somebody", "MIT", "2026-08-25", None), BASE[1]])
case("no author — the attribution obligation with no subject",
     1, "no author",
     FILES,
     [("resources/characters/index.js", URL, "", "MIT", "2026-08-25", None), BASE[1]])

print("\n5. GATE 2 clause 1 — an ALLOWLIST of file types, so an unanticipated format is refused")
case("AT-D3-12 vendored character: sprites.webp, which an extension DENYLIST would have missed",
     1, "sprites.webp",
     {**FILES, "resources/characters/sprites.webp": b"RIFF\x00\x00\x00\x00WEBPVP8 "},
     BASE + [("resources/characters/sprites.webp", URL, "Somebody", "MIT", "2026-08-25", None)])
case("a sprite sheet disguised with no extension at all",
     1, "is not one of",
     {**FILES, "resources/characters/atlas": b"\x89PNG\r\n\x1a\n"},
     BASE + [("resources/characters/atlas", URL, "Somebody", "MIT", "2026-08-25", None)])

print("\n6. GATE 2 clause 2 — bytes pasted INSIDE a file clause 1 admits")
BLOB = base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"\xde\xad\xbe\xef" * 12000).decode()
case("AT-D3-12 40 KB base64 PNG in a .ts the allowlist admits",
     1, "exceeds the 1024 B ceiling",
     {**FILES, "resources/characters/atlas.ts": f"export const A = '{BLOB}';\n"},
     BASE + [("resources/characters/atlas.ts", URL, "Somebody", "MIT", "2026-08-25", None)])
WRAPPED = "\n".join(BLOB[i:i + 76] for i in range(0, len(BLOB), 76))
case("the same blob broken across lines — caught by the whitespace-stripped pass",
     1, "with whitespace stripped",
     {**FILES, "resources/characters/atlas.ts": f"export const A = `\n{WRAPPED}\n`;\n"},
     BASE + [("resources/characters/atlas.ts", URL, "Somebody", "MIT", "2026-08-25", None)])
case("CONTROL — 40 lines of `// ------` divider, which whitespace-stripping concatenates into a "
     "1,600 B run of pure base64 ALPHABET, is not a picture",
     0, "BOTH ASSET GATES PASS + LINEAGE COMPLETE",
     {**FILES, "resources/characters/dividers.js": ("// " + "-" * 37 + "\n") * 40 + "export const x = 1;\n"},
     BASE + [("resources/characters/dividers.js", URL, "Somebody", "MIT", "2026-08-25", None)])
case("a real data URI in an admitted file",
     1, "carries a data:image/ URI",
     {**FILES, "resources/characters/icon.js": "export const I = 'data:image/png;base64,iVBORw0KGgo=';\n"},
     BASE + [("resources/characters/icon.js", URL, "Somebody", "MIT", "2026-08-25", None)])
case("an SVG data URI, which carries no `base64` token at all",
     1, "carries a data:image/ URI",
     {**FILES, "resources/characters/icon.js": "export const I = 'data:image/svg+xml,<svg/>';\n"},
     BASE + [("resources/characters/icon.js", URL, "Somebody", "MIT", "2026-08-25", None)])
case("CONTROL — a lineage file that MENTIONS `data:image/` in prose is not a picture",
     0, "BOTH ASSET GATES PASS + LINEAGE COMPLETE",
     {**FILES, "resources/characters/LINEAGE.md":
      CLEAN_MD + "\nNo file here may carry a `data:image/` URI, per FLOOR section 10.1 Gate 2.\n"},
     BASE)

print("\n7. THE LINEAGE CHECK — AT-D3-12's lineage half, one RED per required fact")
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

print("\n8. UNMEASURABLE (exit 2) — every state the gate cannot see its population in")
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
     BASE + [("resources/characters/index.js", URL, "Somebody Else", "MIT", "2026-08-25", None)])
case("a row with the wrong number of cells is never SKIPPED",
     2, "cells, expected 6", FILES, BASE,
     manifest_body=("| Path | Source URL | Author | SPDX | Retrieved | SHA-256 |\n"
                    "|---|---|---|---|---|---|\n"
                    "| `resources/characters/index.js` | x | y | MIT | 2026-08-25 |"))
case("a renamed or reordered column header",
     2, "the six columns", FILES, BASE,
     manifest_body=("| File | Source URL | Author | SPDX | Retrieved | SHA-256 |\n"
                    "|---|---|---|---|---|---|\n"
                    "| `resources/characters/index.js` | u | a | MIT | 2026-08-25 | `x` |"))
case("a manifest block with no table in it",
     2, "holds no table", FILES, BASE, manifest_body="(the table used to be here)")

print(f"\n{ran} fixtures run — " + ("ALL PASS" if failures == 0 else f"{failures} FAILED"))
sys.exit(1 if failures else 0)

# Attribution — the asset manifest

**Every asset file in this repository has a row below, and the build fails when one does not.**
The manifest is the contract; [`bin/asset-provenance.py`](../bin/asset-provenance.py) is the
enforcement, and [`docs/design/FLOOR.md § 10.1`](design/FLOOR.md#101-the-manifest-and-the-two-gates)
is the specification both answer to.

A missing row is an asset whose licence nobody recorded, which is the only way an incompatible
asset ever ships. This repository is **MIT** ([`docs/PLAN.md § 0`](PLAN.md#0-decisions-register)
D-02) and public, so an asset whose terms are stricter than the repository's is a term the
repository cannot honour.

## The rules a row lives under

- **The licence allowlist is closed: `CC0-1.0` and `MIT`.** Anything else — `CC-BY-*`,
  `CC-BY-SA-*`, any `-NC` or `-ND` term, ISC, "free for personal use", or an asset with no
  stated licence — is refused. **Widening this list is an operator decision, never an
  implementer's.**
- **The `origin` column is closed at two, and it is a *type*, not a note.** `first-party` means
  drawn or written **for this repository** — its source URL must be **this repository's own**.
  `licensed` means obtained from **outside** — its source URL must be a genuine external one.
  A row with no `origin`, an `origin` outside the pair, or an `origin` its own URL contradicts
  fails the build by name. **A `first-party` row pointing at somebody else's repository is the
  one lie in this class a machine can catch**, and it is caught; the much larger class it
  cannot catch is named under *What a green gate does not mean* below.
- **The SPDX column takes an identifier, not prose.** "Free to use" is not a licence.
- **`retrieved` records which licence was accepted**, because a licence can change after the
  fact and the row is the evidence of what the terms were on the day.
- **The SHA-256 is of the file as vendored**, so a later edit or replacement of the *bytes* is
  visible without re-reading the source. **This means editing a listed file reds the gate until
  its row is refreshed. That is the column working, not the column costing.** Recompute one row
  with `sha256sum <path>` and paste it; there is deliberately no script that rewrites the table,
  because a helper that silently re-syncs the hashes would defeat the only thing the hashes do.
- **The asset root is `resources/` at the repository root, entire** — declared in one place,
  `ASSET_TREES` in `bin/asset-provenance.py`. Not a list of named subdirectories: an enumeration
  leaves a hole the day somebody adds a tree without editing the tuple, and that tree would then
  be covered by no gate while the build stayed green. Laravel's own resources are at
  `server/resources/` (`docs/PLAN.md § 0` D-16) and are **not** scanned — they are application
  code, not assets, and owe no provenance rows.

## The manifest

Seven columns, in the order [`FLOOR.md § 10.1`](design/FLOOR.md#101-the-manifest-and-the-two-gates)
sets out. The gate parses **only** what is between the two markers, and exits 2 — unmeasurable,
never a pass — on any structural surprise it finds there. **A manifest still written in the old
six columns is exit 2 rather than a pass**, deliberately: a manifest with no `origin` column
declares no origin for anything, and reading it as though it did would be the gate inventing the
very fact it exists to check.

<!-- asset-manifest:begin -->

| Path | Origin | Source URL | Author | SPDX | Retrieved | SHA-256 |
|---|---|---|---|---|---|---|
| `resources/characters/portrait-art.js` | licensed | https://github.com/chaitanyagiri/munder-difflin/blob/eb3df9fa70b63b68495a965c45f158105e87b2e6/src/renderer/src/scene/office/portraitArt.ts | Chaitanya Giri (upstream); Mezzanine contributors (port) | MIT | 2026-08-25 | `d19bdd0099f8c4578ced8331792082332325a1448db7ff80d8c33a61d94bca06` |
| `resources/characters/seed.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors; hair palette derived from Chaitanya Giri's recipes | MIT | 2026-08-25 | `21810d3b1f4c013eec9fcccc296027b07a4c66e7bdf61b37d528707b46e423ca` |
| `resources/characters/index.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-08-25 | `422d0ef0216e16830e05cd3d4300b18748f8eeb616b62b811e313e086a12310b` |
| `resources/characters/LINEAGE.md` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-08-25 | `f8ba128eac78503153d988ad902f5b1a2a78faf7e278314e5ec80b7792428f5b` |

<!-- asset-manifest:end -->

**`portrait-art.js` is the one `licensed` row here, and that is the column doing its job.** It is
the single upstream file the port took, so it came from **outside** and its URL is upstream's
blob at the pinned commit. The other three were written here and point at this repository. The
distinction was always true and was previously legible only by reading the URLs and knowing what
they meant; it is now a value a gate can test.

**Why first-party files are listed too.** `seed.js`, `index.js` and `LINEAGE.md` were written
here, not taken from anywhere — and they are still under an asset tree, so they still get rows.
The alternative is an exemption the author declares for their own files, which is exactly the
judgement Gate 1 exists to take out of the author's hands: "this one is mine, it doesn't need a
row" is how the one file that *did* come from somewhere else eventually gets in.

## The character port

The generator under `resources/characters/` is ported from **munder-difflin** at commit
`eb3df9fa70b63b68495a965c45f158105e87b2e6` under the **MIT** licence.
[`resources/characters/LINEAGE.md`](../resources/characters/LINEAGE.md) records the exact files
taken, the changes made, and **what was deliberately not taken and why** (the LimeZu-bound
sprite path, three ISC-derived files, and The Office's cast).

**MIT obliges reproduction of the copyright notice and the permission notice, and a link is not
a reproduction**, so the text is here in full — and again in the lineage file, because
[`FLOOR.md § 10.2`](design/FLOOR.md#102-characters-the-munder-difflin-port) requires it in both.
That duplication is deliberate and is the one kind that is correct: a licence notice has to
*accompany* the distribution rather than point at it, and the text is immutable, so there is
nothing here that can drift out of sync with the other copy.

```
MIT License

Copyright (c) 2026 Chaitanya Giri

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

**The port's pixel art is INTERIM.** The operator ratified a new art direction on 2026-08-26/27
([`FLOOR.md § 10.4`](design/FLOOR.md#104-the-art-direction-as-a-specification)) under which the
product ships original, high-resolution, resolution-independent art of its own. **None of the
licence work above is undone by that** — the port is still here, the MIT obligations are still
owed, and what the port actually bought was the **seed machinery**, which the art direction does
not touch. What changed is `§ 10.1` Gate 2: it used to assert an **absence** — no image file in
the character tree at all — and now asserts that **every asset is a file Gate 1 can see**. Every
file carries an admitted file type — **the list and the reason for each member live at § 10.1
and are deliberately not copied here**, because four copies of that list is what went stale the
last time it moved — and no text-bearing file may carry a `data:image/` URI or a single
base64-shaped literal over 1,024 B, because an asset embedded inside another file has no path,
so no row, so no provenance.

**Since card#7913, Gate 2 runs over ALL of `resources/`, not just the character tree** — the same
population Gate 1 walks. It had been scoped to `resources/characters/`, which was right while it
asserted an absence peculiar to that tree and was a leftover once its claim became a universal
one. It also gained a **third clause** for the Tiled formats § 10.1 clause 1 now admits: layer
data stored plainly as CSV, and **no embedded tileset image**, which is image bytes inside a map
with no path and therefore no row here. As above, § 10.1 owns the clauses and this file does not
restate them.

## The floor map

Not here yet — card **#7341** (floor v1) brings the CC0 tileset and the Tiled map, and adds its
rows to the table above. Because the asset root is `resources/` entire, whatever directory that
card creates under it is covered by Gate 1 on the day it lands, with nothing here to remember to
update first — and since card#7913 by **both Gate 2 clauses and the new third one** as well, which
was the point of settling that card before this tree exists rather than after. What it will need is
the rows: a tileset with no row fails the build, by design. **Two things it will also need, from
`FLOOR.md § 10.1` clause 3:** export the map with the tile layer format set to **CSV**, and let the
tileset **reference** its image by path rather than embedding it. Both are Tiled export settings,
and both fail the build if they are wrong.

## What a green gate does not mean

Stated because a provenance check is exactly the kind of thing people over-read:

- ⚠ **IT DOES NOT MEAN A ROW IS TRUE, and since 2026-08-27 that is the headline caveat rather
  than a footnote.** Gate 2 used to assert an *absence*, and an absence needs no truthful claim
  from anybody — the gate could see for itself. It now rests on a declaration. Vendor somebody
  else's commercial art as a `.png`, write `first-party` / `MIT` in its row, and every check in
  this repository passes. What stands in its place is the closed licence allowlist, the
  `origin`/URL consistency check, the *what was deliberately not taken* section of the lineage
  file, [`FLOOR.md § 10.5`](design/FLOOR.md#105-the-ip-line--stated-and-unenforceable-by-gate)'s
  IP line, and **review** — which is doing more of the work than it used to and is told so here.
  [`FLOOR.md § 10.1`](design/FLOOR.md#101-the-manifest-and-the-two-gates) names the whole
  residue. The trade was taken because the alternative was a gate that kept asserting an absence
  the product no longer has, which proves nothing at all while looking exactly as green.
- **No gate can see a character somebody else owns.** Nothing that reads file types, hashes and
  licence strings can look at a drawing and recognise a franchise character in it. FLOOR § 10.5
  states that rule and states that **review**, not this file, enforces it.
- **Clause 1 trusts the extension.** It classifies by suffix and sniffs no magic bytes, so an
  `.avif` renamed to `.png` passes it. That is a known gap rather than an oversight — clause 1
  exists to refuse the format nobody anticipated, not to defeat somebody deliberately hiding
  one, and the first bullet already concedes the deliberate case.
- **Nothing that inspects a directory can refuse code that fetches upstream art at run time.**
  FLOOR § 10.1 names this residue; the lineage file's deliberate-omissions section and human
  review are what stand against it.
- **Only `resources/` is measured.** An image parked elsewhere in the repository — under
  `server/`, `docs/` or `tools/` — is invisible to this gate.
- **The gate is not a required status check** and this PR does not make it one. Adding a
  workflow does not make it required (`docs/VERSIONING.md § Branch model`); that is a
  repository-settings act, and card **#7344** owns updating the required-check list.

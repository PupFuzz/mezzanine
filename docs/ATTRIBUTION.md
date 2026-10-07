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

- **The licence allowlist is closed: `CC0-1.0`, `ISC` and `MIT`.** Anything else — `CC-BY-*`,
  `CC-BY-SA-*`, any `-NC` or `-ND` term, `Apache-2.0` and every other permissive licence nobody
  has ruled on, "free for personal use", or an asset with no stated licence — is refused.
  **Widening this list is an operator decision, never an implementer's.** `ISC` was admitted by
  operator ruling on **2026-08-31** (card#8301): it is attribution-only and functionally MIT, so
  it is not stricter than this repository's own terms, and the list exists to keep **copyleft and
  non-commercial** terms out rather than to choose between two attribution licences. It admits
  ISC, **not permissive licences as a class** — `Apache-2.0` still fails.
- **A row obliges its licence's notice, in every file that owes it, and the gate checks all of
  them.** `MIT` and `ISC` both grant only *"provided that … this permission notice appear in all
  copies"*; `CC0-1.0` is a public-domain dedication and obliges none. So as soon as any row here
  declares such a licence, **this file** must reproduce that licence's permission notice, and as
  soon as the character tree holds a **port** — a `licensed` row under `resources/characters/` —
  `resources/characters/LINEAGE.md` must exist and reproduce the notice of every licence that tree's
  rows declare — [`FLOOR.md § 10.2`](design/FLOOR.md#102-characters-original-creatures-drawn-by-code)
  asks a port for both homes. A tree of first-party files only owes no lineage file (card#11046). Matched as the licence's own text, because a link is not a
  reproduction and neither is the label. ⭐ **The gate reads the obligation off the same table its
  allowlist is derived from**, so a licence cannot be admitted without its notice being decided —
  which is the defect card#8301's review found: admitting `ISC` had widened the list and left the
  lineage half of the check asking for MIT's.
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
| `resources/characters/creatures.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-06 | `adefa592cb9e9007416c9461122bb8d907128a92cd19686f6df20e5d30515749` |
| `resources/characters/seed.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-06 | `cc2545e8fc6a13cae58f6887a44919a93823ae47d732bea7b71efdfbb467a709` |
| `resources/characters/index.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-06 | `ee9798a180c84a10b3cb88305a1b9935e1b119950782c435afda0ad7c51c4f67` |
| `resources/floor/tiles/floor-plane.tsx` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-09-25 | `71fef94ccfb339af81d98b33ced640a0d256611340c591e160df06a9a2311890` |
| `resources/floor/tiles/floor-plane/wall-strip.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-04 | `7e3c516867896cf61896d9e8557c9c0b8f349408432dca2b1d58d569202426d6` |
| `resources/floor/default.tmj` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-09-12 | `1fedf264f35b46215d890ac3066861060b06622467794dc2885384d1345a6c75` |
| `resources/floor/furniture-box.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-09-25 | `ff86ec98cab36b1c4ae8b75ec7b1972e6842875000a0b25631521ca9f7683ebe` |
| `resources/floor/themes/index.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `084aedec11906eb303001e99f9ddf05414d931417c7d47695912fdd313d60157` |
| `resources/floor/themes/studio/theme.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `37f04d73867349df42642182c054e20053d482c5e160302ba32968873a3bb99e` |
| `resources/floor/tiles/floor-plane/accent.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `437c9295c17acba54ee563516aa8f24ad73431b1a9e6d25bd72013c97de6fdd3` |
| `resources/floor/tiles/floor-plane/armchair.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `abe8944932787bd3372ef18f059f37166960e36bc1df9cc0102612dc7357e670` |
| `resources/floor/tiles/floor-plane/book-pile.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `37fb464552f477fe02ebe0157ff19dac9319425a1ef0693a9d36a85be350ca08` |
| `resources/floor/tiles/floor-plane/bookcase-tall.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `59819474837e250a6904af47347c0167119dc602d27995bb75e69bdb10bcc32e` |
| `resources/floor/tiles/floor-plane/bookcase.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `efad27f946275280fd4c11e3fff29059bd93e839f1d4f48039b7200de266743c` |
| `resources/floor/tiles/floor-plane/cushion.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `13cccd8bb560d5211cb6764be7ad676e8183bb00862ddcfcca89d6e43c59ec3f` |
| `resources/floor/tiles/floor-plane/floor-lamp.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `a6643461838d7e030d3d38ab4f765d344a10861c356f8520d3447a925efaa8a8` |
| `resources/floor/tiles/floor-plane/plant-large.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `e662c7e51a250d158537522d22c01eef522ec921288400c536174b93d962d3a0` |
| `resources/floor/tiles/floor-plane/plant-narrow.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `6861306033201eab9a13a698005c451e137820cfb4c6b6080536b188fc6c2a31` |
| `resources/floor/tiles/floor-plane/plant.svg` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-10-07 | `1cf4c0c61b87f54750c93332abea6783328e6f2df238144daf3aae804210dfb7` |
<!-- asset-manifest:end -->

**Why first-party files are listed too.** The character tree's three files — `creatures.js`,
`seed.js` and `index.js` — were written here, not taken from anywhere, and they are still under an
asset tree, so they still get rows. The alternative is an exemption the author declares for their own
files, which is exactly the judgement Gate 1 exists to take out of the author's hands: "this one is
mine, it doesn't need a row" is how the one file that *did* come from somewhere else eventually gets in.

## The characters — first-party since card#11046

Every character is an original animal or vegetable creature drawn by the code in
`resources/characters/`, written for this repository
([`FLOOR.md § 10.2`](design/FLOOR.md#102-characters-original-creatures-drawn-by-code)). The tree
**holds no port**: the munder-difflin generator it held from card#7340 to card#11046 — and its
`LINEAGE.md` and its `licensed` row — left whole, because no line of upstream's ships any more, read
file by file in § 10.2. Its record stays in git at card#7340's commits and in `docs/PLAN.md § 0`'s
D-07 appends. `upstream's commercial tilesets are never vendored` (D-07's last clause) is untouched.

**MIT obliges reproduction of the copyright notice and the permission notice, and a link is not
a reproduction.** Every `first-party` row above declares `MIT` — this repository's own licence — so
the notice is here in full, as [`LICENSE`](../LICENSE) carries it:

```
MIT License

Copyright (c) 2026 PupFuzz

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

**Gate 2 runs over ALL of `resources/`, not just the character tree** (card#7913) — the same
population Gate 1 walks — and gained a **third clause** for the Tiled formats: layer data stored
plainly as CSV, and **no embedded tileset image**. [`FLOOR.md § 10.1`](design/FLOOR.md#101-the-manifest-and-the-two-gates)
owns the clauses and the admitted file types, and this file does not restate them.

## The floor — first-party since card#11046's row 22

**Every `resources/floor/` row is `first-party` / `MIT`.** The bridge tileset that stood here from
**2026-09-12** — Kenney's CC0 **Furniture Kit**, chosen by the operator *explicitly as a bridge to
first-party vector art* — left the tree with its rows and its `LINEAGE.md` at
[`FLOOR.md`](design/FLOOR.md) Appendix B row 22 (card#11046), when the art it bridged to landed: the
floor's **theme** draws every piece of a floor that carries no fact, as code
([`FLOOR.md § 10.6`](design/FLOOR.md#106-themes--a-floors-design-and-the-house-theme)). The plank and rug
tiles of the floor tileset left with it.

**What the rows cover now, and what they do not.** The theme registry (`themes/index.js`) and each
theme's module (`themes/<name>/theme.js`) are code that DRAWS: the drawings are not files and have no
rows, the source is the asset, and Gate 2 reads it. The floor tileset (`tiles/floor-plane.tsx`) declares
a `kind` on every tile, and each tile's image is an authoring **marker** for Tiled — a plain labelled
outline the floor never draws, which a reader who mistook it for the art is told by the marker itself.
The shipped default map (`default.tmj`) places those kinds. ⚠ **No gate inspects what a theme MEANS to
draw**, as no gate does for the creatures: AT-D3-25's theme gates hold the form — every document
well-formed, self-contained, wordless and taking no seat state — and review carries the look.

**Two things an authored map needs, from `FLOOR.md § 10.1` clause 3:** export it with the tile layer
format set to **CSV**, and let the tileset be **referenced** by path rather than embedded. Both are Tiled
export settings, and both fail the build if they are wrong.

**The image-collection shape stays tested.** The floor tileset is an **image-collection** tileset,
`columns="0"` with one `<tile><image source="…"/></tile>` per marker — the shape the kit's tileset first
brought into the tree — and `bin/asset-provenance.selftest.py` keeps it as a control **and** two REDs of
its own.

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
- ⛔ **This bullet used to say the gate was not a required status check. It was false for eight
  days** — `asset-provenance` was added to both branch rulesets on 2026-08-31 and no document
  moved with it, so this file told a reviewer that a red here did not block a merge while it did.
  **Which checks are required is a repository-settings fact, and no copy of it lives here**:
  [`docs/VERSIONING.md § Branch model`](VERSIONING.md) is its one home and carries the API command
  that re-derives it. Adding a workflow still does not make it required — that is a
  repository-settings act, and it is the part of this that has not changed.

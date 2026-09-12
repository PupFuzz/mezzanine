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
  soon as a row **under `resources/characters/`** declares one, `resources/characters/LINEAGE.md`
  must reproduce it too — [`FLOOR.md § 10.2`](design/FLOOR.md#102-characters-the-munder-difflin-port)
  asks a port for both homes. Matched as the licence's own text, because a link is not a
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
| `resources/characters/portrait-art.js` | licensed | https://github.com/chaitanyagiri/munder-difflin/blob/eb3df9fa70b63b68495a965c45f158105e87b2e6/src/renderer/src/scene/office/portraitArt.ts | Chaitanya Giri (upstream); Mezzanine contributors (port) | MIT | 2026-08-25 | `d19bdd0099f8c4578ced8331792082332325a1448db7ff80d8c33a61d94bca06` |
| `resources/characters/seed.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors; hair palette derived from Chaitanya Giri's recipes | MIT | 2026-08-25 | `21810d3b1f4c013eec9fcccc296027b07a4c66e7bdf61b37d528707b46e423ca` |
| `resources/characters/index.js` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-08-25 | `422d0ef0216e16830e05cd3d4300b18748f8eeb616b62b811e313e086a12310b` |
| `resources/characters/LINEAGE.md` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-08-25 | `f32a34a1152fcb506e020015b9eaa9a3d1ec8066774870ded36f5d7faef426d2` |
| `resources/floor/tiles/furniture-kit/benchCushion.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `5665a099b17ccc7018ceebd9d33aa51fa6f26dc98317f3956385774acf47922c` |
| `resources/floor/tiles/furniture-kit/bookcaseClosed.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `8a97317c91d7be94521634b33319a10279dd47277f1f8e8b13d18f4291c92366` |
| `resources/floor/tiles/furniture-kit/bookcaseOpen.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `5ca87be1fe92f07eebb29d34c568c9bd6315a740b8824be7c3daa0005fd8d217` |
| `resources/floor/tiles/furniture-kit/bookcaseOpenLow.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `40ce094e3f2ec5b18c558e4cfe1c22401cd0dbb305d9e290c8312dba30019ea9` |
| `resources/floor/tiles/furniture-kit/books.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `c511fa97297f3054df2adfdafb88ff63c9c9a08f321659f12a98d0068f7f4ab5` |
| `resources/floor/tiles/furniture-kit/cardboardBoxClosed.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `dd7877bb0cd2787467f4c5b4d46733dc8ff5e2b916a8b0c313ce6e9f61571926` |
| `resources/floor/tiles/furniture-kit/chairDesk.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `e580a6549e80b0e67e72323acbaa3c41c5d04a3659ebd536224dc7429ada7c2a` |
| `resources/floor/tiles/furniture-kit/chairModernCushion.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `ec2a0d2a35dfada8e80f7f760e7841ae8469e5f51af748d7fcf1fd6a6a07c5e3` |
| `resources/floor/tiles/furniture-kit/chairModernFrameCushion.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `dc4640c0bf95fd44c42c1744429a76253044e313ae9bbb49bcb166b48850971f` |
| `resources/floor/tiles/furniture-kit/chairRounded.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `92821cc1bd1c495bfe525f539eda23987efd69c45acfd2776827e01a8da74ef0` |
| `resources/floor/tiles/furniture-kit/coatRack.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `fa27ad3f889efa62330f573f1d953831f17f1bd74d2dda3b353916601eb96d12` |
| `resources/floor/tiles/furniture-kit/computerKeyboard.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `1b556cda0d4cac37a8509dc3d2b6f8aea75e4e7b312b13fd87bd7c19f16a5496` |
| `resources/floor/tiles/furniture-kit/computerMouse.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `9209afe394622e7f366a5549a0433b28bd27897b7ae9aa55b32589f7103cbaa8` |
| `resources/floor/tiles/furniture-kit/computerScreen.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `611380a2050ea8b6ede392758741b43eee46d78dd285fa9814abe5c18fdab4b1` |
| `resources/floor/tiles/furniture-kit/desk.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `d55354ce277cb010e1af510cc8832559c004d24f9670acfeba64177b62cf1c80` |
| `resources/floor/tiles/furniture-kit/deskCorner.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `02162397db5524208d3ec5192362f0562369ac972ffcb549c2ba17a1a1a6e8ff` |
| `resources/floor/tiles/furniture-kit/doorwayOpen.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `0a420204a983816f345c291dbbf15d55b766339f5b8f3848261792db4ff69a7f` |
| `resources/floor/tiles/furniture-kit/floorCorner.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `103b1d2b7722e8eca93c2460c7212ef7a6779991679ac0306f77fc82965b1022` |
| `resources/floor/tiles/furniture-kit/floorCornerRound.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `b213e066505eba2432ddf0111a5746f095ca53c3c97835621fdc93631bed45ed` |
| `resources/floor/tiles/furniture-kit/floorFull.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `f9a64102b9fa6ae5a64aa8da78e899771d044645e1575981f22100389dfb8b1f` |
| `resources/floor/tiles/furniture-kit/floorHalf.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `0a4770c504e39ecc2dfb3e9d1b48c70ea2aae32c8cee5e985da6346ce27fca11` |
| `resources/floor/tiles/furniture-kit/lampRoundFloor.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `da552fc2f2e33cef000d17ec93f7bb518cccfaa6cfc1fef3d847e847c94d8c3f` |
| `resources/floor/tiles/furniture-kit/lampRoundTable.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `175f9f5d64cca3994da9b69a93baadc86d2a8784a09dd1da7ade7708c21a8ea4` |
| `resources/floor/tiles/furniture-kit/lampSquareFloor.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `17af83f19cf4fc99858093a7bc6e754fbd7ffbb362390d7f8409c3c93c8659f3` |
| `resources/floor/tiles/furniture-kit/lampSquareTable.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `225ec7459e78d2b9c5e2fd6d902f0aeb9978768d8ab78e972de5af0525433c1f` |
| `resources/floor/tiles/furniture-kit/lampWall.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `4ed46865d5393b4cfe192dc51a9309b680085d2e979321ed0096826b3af9bae7` |
| `resources/floor/tiles/furniture-kit/laptop.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `6a869cc04b6ec029b90505f650f10bba58e855561c5ee14effddada4ff91a698` |
| `resources/floor/tiles/furniture-kit/plantSmall1.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `0f4319b0e1a25cc71ed9af055f8af7f533a03a00ff8d3c61019437117f7bee8c` |
| `resources/floor/tiles/furniture-kit/plantSmall2.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `9a51e04270312c39d254b253641b9177527b394a11d1dea45b3563c787211eea` |
| `resources/floor/tiles/furniture-kit/plantSmall3.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `b4e343be77e33551b55ab4ce02028b12278e939749b230b6dd57c225f4ad0ee6` |
| `resources/floor/tiles/furniture-kit/pottedPlant.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `9b11ee7c6ec92d9829d01b84f9dce0257dfe38eaf930bfee9c348fb6b9e81d99` |
| `resources/floor/tiles/furniture-kit/rugRectangle.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `5c1756ce6245d7a2add724ef9065965dd33e1fc062036946e9b7c828bfb9cf27` |
| `resources/floor/tiles/furniture-kit/sideTable.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `98a8edd3c942bedcf58701a05b35443b69d8c701453cc4b1bb21f78f2f6fe888` |
| `resources/floor/tiles/furniture-kit/sideTableDrawers.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `71480a3954ea6841b7a923e484371a7e73994fcc9ef6f28f6e14330151870f09` |
| `resources/floor/tiles/furniture-kit/table.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `7bc0e103a8d53ab21ed86b08ade4a1a8b9e9de2b55e21f7aee20b904e8443341` |
| `resources/floor/tiles/furniture-kit/tableCross.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `71539d07ddc9e13c4aebf480803bb352068ed6a0af4036137e3cb8797a14cd21` |
| `resources/floor/tiles/furniture-kit/tableRound.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `e43d1e3336849d3da1bfe14a3ababd86d79cbb4b528086787157c853619aee6b` |
| `resources/floor/tiles/furniture-kit/trashcan.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `204d9cebc1907a2bd4f4be34268bb6307805019a3edf728c3b437ac5e9f78f00` |
| `resources/floor/tiles/furniture-kit/wall.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `38c60212400b3dc672f8d34d666f04e148f43cc202c515457050b3bcd2af8de3` |
| `resources/floor/tiles/furniture-kit/wallCorner.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `83d505e34e4eaa138323917939154458cd04997ac80ab2c3fe01daa54f9c9d66` |
| `resources/floor/tiles/furniture-kit/wallCornerRond.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `2594701f426218cc8a509ccf7dc1a994ac67620609562b2717d9bd3ccd4bd970` |
| `resources/floor/tiles/furniture-kit/wallDoorway.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `d94686203937304b29e75adc7156b3327793e91f5920491c9c4be4e0b3c4e104` |
| `resources/floor/tiles/furniture-kit/wallDoorwayWide.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `c2f05b7639f8b274b77b422abceac00b9aa7c7cb2288f0f4c69d0af6b8529bff` |
| `resources/floor/tiles/furniture-kit/wallHalf.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `67b83c905472d847fbd73566571fa7b479faedb3aae3160309c5e15088d73a4e` |
| `resources/floor/tiles/furniture-kit/wallWindow.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `50f9029b7ff9893b021e1c4c5b016f89ab66f52ce9ae948c0d3796442b235a05` |
| `resources/floor/tiles/furniture-kit/wallWindowSlide.png` | licensed | https://kenney.nl/assets/furniture-kit | Kenney (Kenney Vleugels) | CC0-1.0 | 2026-09-12 | `671bef582ebc9e4c3708b539ddd992442f75cc0a514c3960a73f66ff9581e7e8` |
| `resources/floor/tiles/furniture-kit.tsx` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-09-12 | `3eadf855dbabc1a12621d4fbc21bad1357cfa6b5e346de3b8c1daab0133272a6` |
| `resources/floor/LINEAGE.md` | first-party | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-09-12 | `364f9caa358af2c75d14afc766e1ca92d8fd319097ab509d9d23f5a44e476564` |
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

## The floor tileset — and it is a BRIDGE, not the destination

⚠ **Read this before reading a `resources/floor/` row as a decision about what this product looks
like.** The tileset those rows cover is Kenney's CC0 **Furniture Kit**, chosen by the operator on
**2026-09-12** *explicitly as a bridge to first-party vector art*. It is **pre-rendered raster at
one scale**, so it does **not** meet
[`FLOOR.md § 10.4`](design/FLOOR.md#104-the-art-direction-as-a-specification)'s requirement that the
shipped look be resolution-independent — and it is not meant to. It exists so the floor can be
built and measured against real sprites while the art that ships is drawn, and it leaves the tree
with its rows when that art lands. [`resources/floor/LINEAGE.md`](../resources/floor/LINEAGE.md) is
the record a reader meets beside the files: the terms as read in the pack's own `License.txt`, the
downloaded archive's hash, which renders were curated and why, and what was deliberately not taken.

**`CC0-1.0` obliges no reproduced notice**, which is why no licence text appears below for it — the
notice table in `bin/asset-provenance.py` maps it to `None` because a public-domain dedication is
granted unconditionally, with no permission notice for a copy to carry. Kenney's own `License.txt`
says crediting is *"not mandatory"*; it is done anyway, in every row and in `README.md`.

**The floor MAP is still not here.** Card **#7341** (floor v1) authors it, and a tileset is not a
map: `tools/design/verify-floor.py` still holds
[`FLOOR.md § 10.3`](design/FLOOR.md#103-the-floor-map)'s declared **absence** of one, because its
sweep is for Tiled's two *map* spellings and a `.tsx` tileset is neither. **Two things that map
will need, from `FLOOR.md § 10.1` clause 3:** export it with the tile layer format set to **CSV**,
and let the tileset **reference** its image by path rather than embedding it. Both are Tiled export
settings, and both fail the build if they are wrong.

**One thing card#7913 settled before this tree existed, now tested rather than assumed.** Because
the asset root is `resources/` entire, the directory this tileset created was covered by Gate 1 on
the day it landed and — since card#7913 — by **both Gate 2 clauses and the third one** as well,
with nothing here to remember to update first. That widening had never been run against the shape
the tileset actually takes: an **image-collection** tileset, `columns="0"` with one
`<tile><image source="…"/></tile>` per PNG rather than one image sliced into a grid. It passes all
three clauses, and `bin/asset-provenance.selftest.py` now carries that shape as a control **and**
two REDs of its own, because a green over a shape no fixture had ever fed the parser reports where
the fixtures stopped rather than what the parser does.

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

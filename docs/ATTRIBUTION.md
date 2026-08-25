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

Six columns, in the order [`FLOOR.md § 10.1`](design/FLOOR.md#101-the-manifest-and-the-two-gates)
sets out. The gate parses **only** what is between the two markers, and exits 2 — unmeasurable,
never a pass — on any structural surprise it finds there.

<!-- asset-manifest:begin -->

| Path | Source URL | Author | SPDX | Retrieved | SHA-256 |
|---|---|---|---|---|---|
| `resources/characters/portrait-art.js` | https://github.com/chaitanyagiri/munder-difflin/blob/eb3df9fa70b63b68495a965c45f158105e87b2e6/src/renderer/src/scene/office/portraitArt.ts | Chaitanya Giri (upstream); Mezzanine contributors (port) | MIT | 2026-08-25 | `74c685aa031b61a31f69131e65d2130d5793d1a2ec174b915f6a33da09b5e25f` |
| `resources/characters/seed.js` | https://github.com/PupFuzz/mezzanine | Mezzanine contributors; hair palette derived from Chaitanya Giri's recipes | MIT | 2026-08-25 | `21810d3b1f4c013eec9fcccc296027b07a4c66e7bdf61b37d528707b46e423ca` |
| `resources/characters/index.js` | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-08-25 | `422d0ef0216e16830e05cd3d4300b18748f8eeb616b62b811e313e086a12310b` |
| `resources/characters/LINEAGE.md` | https://github.com/PupFuzz/mezzanine | Mezzanine contributors | MIT | 2026-08-25 | `4ad7b4482364bfee22591e54c76993014bd0d26573ee3cebebe14e3b6b8622b0` |

<!-- asset-manifest:end -->

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

**No character art is vendored, and none may be.** The sprites are generated in code, so § 10.1
Gate 2 asserts an *absence*: the character tree admits `.ts`, `.js` and `.md` only, and no file
in it may carry a `data:image/` URI or a single base64-shaped literal over 1,024 B.

## The floor map

Not here yet — card **#7341** (floor v1) brings the CC0 tileset and the Tiled map, and adds its
rows to the table above. Because the asset root is `resources/` entire, whatever directory that
card creates under it is covered by Gate 1 on the day it lands, with nothing here to remember to
update first. What it will need is the rows: a tileset with no row fails the build, by design.

## What a green gate does not mean

Stated because a provenance check is exactly the kind of thing people over-read:

- **Nothing that inspects a directory can refuse code that fetches upstream art at run time.**
  FLOOR § 10.1 names this residue; the lineage file's deliberate-omissions section and human
  review are what stand against it.
- **Only `resources/` is measured.** An image parked elsewhere in the repository — under
  `server/`, `docs/` or `tools/` — is invisible to this gate.
- **The gate is not a required status check** and this PR does not make it one. Adding a
  workflow does not make it required (`docs/VERSIONING.md § Branch model`); that is a
  repository-settings act, and card **#7344** owns updating the required-check list.

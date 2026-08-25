# Lineage — the procedural character generator

This directory is a **port**, not a fork and not a copy. This file is what lets a later reader
tell those apart: where the code came from, at exactly which commit, what was taken, what was
changed, and — the part that matters most and is easiest to leave out — **what was deliberately
not taken, and why**.

Required by [`docs/design/FLOOR.md` § 10.2](../../docs/design/FLOOR.md); the machine-checked
manifest of the same facts is [`docs/ATTRIBUTION.md`](../../docs/ATTRIBUTION.md).

---

## Upstream

| | |
|---|---|
| Project | **munder-difflin** |
| Repository | <https://github.com/chaitanyagiri/munder-difflin> |
| **Commit ported from** | **`eb3df9fa70b63b68495a965c45f158105e87b2e6`** (committed 2026-08-25T00:14:58Z) |
| Retrieved | 2026-08-25 |
| Licence of the source | **MIT** — `LICENSE` at that commit, blob `c398437653f4bf3fcc4b91f754518b29c482f5fd` |
| Copyright holder | Chaitanya Giri |

**The commit is the point of this table.** A port that records only the repository is
indistinguishable from a fork six months later, because the upstream file it claims to descend
from has moved on and nobody can say what was actually taken.

> ⚠ **GitHub's licence API reports `NOASSERTION` for this repository, and that is not a
> licensing problem — it is a parser result.** Verified 2026-08-25:
> `GET /repos/chaitanyagiri/munder-difflin` → `.license.spdx_id == "NOASSERTION"`. The cause is
> that upstream's `LICENSE` appends a note carving its **bundled pixel art** out of the MIT
> grant, so the file is no longer a byte-exact MIT template and the detector declines to name
> it. The MIT grant **over the source code** is unambiguous in the text, and the carve-out is
> about art this port does not take. Recorded here because an automated licence scan run
> against upstream will surface `NOASSERTION` again and should not restart this analysis.

## The MIT notice, reproduced

MIT requires that the copyright notice and the permission notice be included in all copies or
substantial portions of the Software. **A link is not a reproduction**, so the text is here in
full, exactly as upstream's `LICENSE` carries it:

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

Upstream's `LICENSE` then continues with the art carve-out quoted under
[What was deliberately not taken](#what-was-deliberately-not-taken-and-why) below.

## What was ported

**One upstream file**, and it is first-party to munder-difflin — it carries no *"ported from"*
line, unlike three of its neighbours (see below).

| Upstream path @ `eb3df9f` | blob | Here |
|---|---|---|
| `src/renderer/src/scene/office/portraitArt.ts` (32,800 B) | `d76fa88789ae21f3ad6820eafebf2c82f5f4f70e` | [`portrait-art.js`](portrait-art.js) |

**Read for context, and taken from only as noted:**
`src/renderer/src/scene/office/cast.ts` (4,783 B, blob
`96fd3708e7fec3634fcf935dc805ccb082416adf`). Nothing structural was carried across — it is
`pixi.js`-bound and its roster is The Office's cast. It is listed because it is the
**authoritative** statement of how upstream draws its in-scene sprites (see the stale-comment
note below), and because reading it is what established that no sprite sheet is involved.

### What changed, and why

1. **TypeScript → ES-module JavaScript with JSDoc types.** Nothing in this repository compiles
   anything yet — there is no `package.json`, no bundler, no `tsc`. Card #7340's acceptance line
   is *renders in a plain browser*, and shipping `.ts` would have made that line uncheckable
   until a toolchain lands (card #7344), or forced a second checked-in build artifact beside the
   source. `.js` is admitted by § 10.1 Gate 2 clause 1, loads in a browser with no build step,
   and is imported natively by Vite when the Laravel front end arrives. The types are preserved
   as JSDoc rather than dropped, so `tsc --checkJs` can read them later.
2. **Every name-keyed entry point is gone.** Upstream keyed on `OfficeCharacterName`, a closed
   union of 15 show characters, with a hand-written recipe per name. Mezzanine has seats. The
   exported composers now take a **recipe object** and [`seed.js`](seed.js) derives one from
   `(install_id, seat_id)` — FLOOR § 10.2's rule that a seat looks identical on every browser
   and every reload with nothing stored.
3. **Caching moved up a layer** (to [`index.js`](index.js)) because the cache key changed from a
   character name to a seat key. Upstream's caching strategy is otherwise unchanged.
4. **The paint entry points now size their destination canvas** instead of accepting a scale
   that could disagree with a canvas the caller sized. Upstream already cleared the whole target
   rect, i.e. already assumed it owned the canvas; this finishes that assumption. The reason is
   not tidiness — a canvas sized at one scale and painted at another crops the sprite to a
   corner, and a cropped pixel character still looks like a pixel character. That defect was
   made and caught during this port.
5. **Hair colours were generalised into a shared palette.** Upstream's `hairc` values are
   per-character; [`seed.js`](seed.js)'s `HAIR_COLORS` adopts them as a natural-hair palette
   because they are tuned against this same module's shading maths (`shades()` at 1.22 / 0.68).
   That is derived work from the MIT source and is why the row for `seed.js` in
   `docs/ATTRIBUTION.md` credits upstream alongside this project. **Garment colours are not**
   adopted — upstream's are the show characters' signature colours (see below).
6. **The drawing itself is upstream's.** Every pixel recipe — the head box, the four skin
   palettes, the nine hairstyles, the facial hair, the glasses, the six garment cuts, the heavy
   build, the scene torso and legs, the back-of-head views, the outline pass — is ported
   substantially as written. Where this file says something was changed, everything else was not.

### ⚑ One upstream comment is stale — recorded, not fixed

`portraitArt.ts`'s own header at the pinned commit says:

> *"The in-scene walking sprites still use the LimeZu recolor in cast.ts; this module only
> powers the static portraits in the UI."*

**That is false at this commit**, and `cast.ts` at the very same commit says the opposite and is
the authority, being the file the sentence is about:

> *"Both the static portraits and the in-scene walking sprites are now fully custom-drawn from
> the same per-character recipes in portraitArt.ts … The LimeZu base sheets are no longer used
> for the cast."*

The sentence is not carried into [`portrait-art.js`](portrait-art.js) — copying a claim already
known to be false would be minting it here rather than inheriting it. It is recorded in this
file instead so that a reader who goes upstream and finds it does not conclude that this port
has a LimeZu dependency hiding somewhere. **It is upstream's to fix, not ours**, and nothing in
Mezzanine depends on the answer.

---

## What was deliberately not taken, and why

This is the section that makes this a port. Each item was available, was considered, and was
left behind for the stated reason.

### 1. Every pixel-art asset. All of them, permanently.

Upstream's `LICENSE` carves them out of its own MIT grant:

> *"This MIT License applies to the SOURCE CODE of this project. It does NOT apply to the
> bundled pixel-art assets under `src/renderer/src/assets/` (tilesets and maps). Those are
> "Modern Interiors - RPG Tileset [16X16]" by LimeZu, licensed separately under the LimeZu
> Complete Version license … The Office cast sprites are not LimeZu art — they are drawn
> procedurally in `src/renderer/src/scene/office/portraitArt.ts` and are covered by this MIT
> License like the rest of the source."*

That is a commercial licence Mezzanine holds no grant under, and `docs/PLAN.md § 0` **D-07**
settles it: *the upstream's commercial tilesets are never vendored.* The mechanised half of that
rule is § 10.1 Gate 2 — this directory admits `.ts`, `.js` and `.md` and nothing else, and no
file in it may carry a `data:image/` URI or a base64-shaped literal over 1,024 B.

### 2. `SpriteAdapter.ts` — because it exists only to slice a LimeZu sheet

Its own documentation says so: *"Maps a LimeZu character walk sheet to the 3-row frame grid
CharacterSprite expects."* Its configuration fields are literally annotated `(LimeZu: 16)`,
`(LimeZu: 32)`. It is useless without the art item 1 refuses, and its presence would contradict
Gate 2's whole claim.

**Where walk animation comes from instead:** [`index.js`](index.js)'s `sceneFrames()` — three
walk phases, front and back, composed by the same generator that draws the face. There is no
sheet to slice because there is no sheet.

### 3. `CharacterSprite.ts`, `SpriteAdapter.ts`, `Character.ts` — an ISC obligation nobody has decided to take on

All three carry a *"Ported from / Adapted from shahar061/the-office"* line (verified at the
pinned commit: `CharacterSprite.ts` line 26, and the header comments of the other two).
`shahar061/the-office` is **ISC** — *"Copyright (c) 2026 shahar061"*, confirmed 2026-08-25 via
`GET /repos/shahar061/the-office` → `.license.spdx_id == "ISC"`.

ISC is permissive and compatible with MIT in substance. **It is nevertheless not in § 10.1's
licence allowlist, which is closed to `CC0-1.0` and `MIT`, and § 10.1 states that widening the
allowlist is an operator decision and never an implementer's.** Taking any of the three would
create an ISC attribution obligation this repository has not decided to carry. So none was
taken, and the decision was not made here.

If a future card genuinely needs sprite orchestration or pathfinding from those files, the ask
is a licence-allowlist ruling from the operator **first** — not a quiet import.

### 4. The Office's fifteen cast identities

`cast.ts` carries `michael` / `jim` / `pam` / `dwight` / … with display names, signature shirt
colours and one-line blurbs. Mezzanine does not want them and does not need them: a Mezzanine
character is an **agent seat**, derived from `(install_id, seat_id)`, and borrowing a television
show's characters would give every seat a second identity that means nothing about the seat.
The *generator* was the thing worth having. Upstream's per-character garment colours went with
the identities for the same reason; [`seed.js`](seed.js) draws from an office palette of its own.

### 5. `pixi.js`

Upstream renders through it. This directory has **no dependency of any kind** — it produces
`Uint8ClampedArray` RGBA buffers and, optionally, blits them to a 2D canvas. Whatever the floor
is eventually rendered with, this code does not care and does not have to be rewritten for it.

---

## The residue — what none of this proves

**Nothing that inspects a directory can refuse a generator that fetches upstream art at run
time.** § 10.1 says so outright, and it is repeated here because this file is where the claim
would have to be defended. What stands against it is this section and human review, not the
gate. As of this commit the tree performs no I/O whatsoever: no `fetch`, no `XMLHttpRequest`, no
`Image`, no URL of any kind — asserted by `tools/characters/selftest.mjs` § 7, and visible by
reading three small files.

The browser harness at `tools/characters/harness.html` lives **outside** this tree, so Gate 2
cannot see it. It loads no image either, and its own in-page checks assert that no image
resource was fetched — but that is a check it makes about itself, not a gate anything enforces.

# Lineage — the floor tileset

⚠ **READ THIS FIRST: THIS ART IS A BRIDGE, NOT THE DESTINATION.** The operator's ruling of
**2026-09-12** chose Kenney's CC0 *Furniture Kit* **explicitly as a bridge to first-party vector
art**. [`docs/design/FLOOR.md` § 10.4](../../docs/design/FLOOR.md#104-the-art-direction-as-a-specification)
requires the shipped look to be **high-resolution, whimsical, modern, warm and
resolution-independent** — and this pack is **pre-rendered raster at one scale**. It does not meet
that bar, and it is **not meant to**: it exists so the floor can be built, laid out and measured
against real sprites instead of against an intent, while the art that ships is drawn. Anybody
arriving here to ask *"what does Mezzanine's floor look like?"* has the wrong file — § 10.4 is the
specification and `docs/design/floor-preview/` is the ratified reference. **Nothing about this
directory is a precedent for what the product ships.**

This directory is **vendored art**, not a port: no upstream code is taken, adapted or executed.
What a port owes (`FLOOR.md` § 10.2 — an upstream commit, what was changed) does not apply, and
this file is not the lineage file `bin/asset-provenance.py`'s lineage check reads; that check is
`resources/characters/LINEAGE.md`'s, and it is about a *port*. What **is** owed, and is here, is
the same thing that file exists for: where these bytes came from, under what terms, what was
taken, and — the part easiest to leave out — **what was deliberately not taken, and why**.

The machine-checked manifest of the same facts is [`docs/ATTRIBUTION.md`](../../docs/ATTRIBUTION.md);
`bin/asset-provenance.py` Gate 1 fails the build for any file here without a row.

---

## Upstream

| | |
|---|---|
| Pack | **Furniture Kit** |
| Author | **Kenney** (Kenney Vleugels) — <https://kenney.nl> |
| Page | <https://kenney.nl/assets/furniture-kit> |
| Archive downloaded | <https://kenney.nl/media/pages/assets/furniture-kit/440e0608a4-1677580847/kenney_furniture-kit.zip> |
| SHA-256 of that archive | `e67652d0932cee41683f74711c03d3e192a2af9979ef8e6b237711f5482d46b0` |
| Licence | **CC0-1.0** — Creative Commons Zero, a public-domain dedication |
| Retrieved and verified | **2026-09-12** |

**The archive hash is this file's equivalent of the character port's pinned commit.** Kenney's
packs are re-released in place under the same page URL, so the page alone does not identify what
was taken; the hash does. Re-derive it with:

```
curl -sSL -o kit.zip 'https://kenney.nl/media/pages/assets/furniture-kit/440e0608a4-1677580847/kenney_furniture-kit.zip'
sha256sum kit.zip
```

### The licence, read at the source rather than taken from a summary

The pack ships its own `License.txt` (SHA-256
`bc0de1a0742cb490f9ed4bae0bd284286ebb27e23149b9917c7d8c0d590b8b29`). Its licence block, from the
file as downloaded on 2026-09-12. ⚠ **Two normalisations, named so this reads as a quotation and
not as the file:** the file indents every line with a leading tab, dropped here, and the block stops
before the donate / request / Patreon / Twitter links that follow it. **No word is changed, added or
reordered** — `sha256sum` against the archive is what settles that, which is why the hash is beside
it:

> ```
> Furniture Kit (2.0)
>
> Created/distributed by Kenney (www.kenney.nl)
> Creation date: 20-10-2018 16:21
>
> ------------------------------
>
> License: (Creative Commons Zero, CC0)
> http://creativecommons.org/publicdomain/zero/1.0/
>
> This content is free to use in personal, educational and commercial projects.
> Support us by crediting Kenney or www.kenney.nl (this is not mandatory)
> ```

The download page states the same thing in its own words — its asset table reads
`License` / `Creative Commons CC0`, and its OpenGraph description reads
*"Download this package (140 assets) for free, CC0 licensed!"*. **Two independent statements of
the same term, one of them inside the bytes that were vendored**, which is the bar
`FLOOR.md` § 10.1 sets for a `licensed` row.

⚠ **One discrepancy, recorded because it is the kind of thing a later reader will otherwise
re-investigate.** `License.txt` inside the archive calls the pack **2.0**; the page's *Updates*
panel calls it **1.0, released in 2018**. Both were read on 2026-09-12. Nothing in this
repository turns on the version string — the archive hash above is what identifies the bytes —
but the two do not agree and neither is treated here as authoritative over the other.

**CC0-1.0 obliges no reproduced notice**, which is why no licence text is reproduced below and why
`bin/asset-provenance.py`'s `LICENCE_NOTICES` maps `CC0-1.0` to `None`. It is a public-domain
dedication, granted unconditionally: there is no permission notice for a copy to carry. Crediting
Kenney is **not mandatory** by the licence's own words, and it is done anyway — here, in
`docs/ATTRIBUTION.md`, and in `README.md` — because attribution is cheap and a provenance trail
with a name in it is worth more than one without.

---

## What was taken

**The `Side/` renders only, curated to what a twelve-desk office room needs.** The exact set is
the manifest, not this prose: `bin/asset-provenance.py` walks the tree and every file has a row,
so the list re-derives with

```
ls resources/floor/tiles/furniture-kit/
python3 - <<'PY'
import re, pathlib
print(sum(1 for l in pathlib.Path('resources/floor/tiles/furniture-kit.tsx').read_text().splitlines()
          if re.search(r'<image source=', l)))
PY
```

Filenames are **unchanged from upstream** — `desk.png` is Kenney's `Side/desk.png` byte for byte —
so any row here can be checked against the archive without a rename table in between.

The tiles are grouped by purpose in `furniture-kit.tsx`'s comments: desks and work surfaces,
seating, desktop equipment, meeting tables, plants, lamps, walls and windows, doorways, floors,
storage and clutter, soft furnishing.

### Why `Side/` and not `Isometric/`

**Because the ratified reference is a building seen in cross-section, and the Isometric renders
cannot be composed into one.**

- `docs/design/FLOOR.md` § 4.1 describes the ratified lobby as *"a building seen in section — one
  **floor plate per floor**, stacked, with an elevator as the way between them"*, and § 4.5's camera
  *"zooms out to a building overview, zooms to a floor, and pans by wheel and drag"*. (Both quoted
  with their markdown emphasis as written; no words are dropped.) A stack of floor plates is an
  **elevation**: each floor is a horizontal band, and the camera's zoom is a scale change within
  one projection. Isometric tiles draw each object receding along two axes, which is a
  *different* projection — floors drawn that way cannot stack into a section without each one
  occluding the one above it.
- **Nothing in `docs/design/FLOOR.md` asks for an isometric projection**, and until § 10.3's
  tileset bullet was written the word did not occur in that document at all. Re-derive with
  `grep -n -i isometric docs/design/FLOOR.md`: every hit is inside that one bullet, explaining why
  the projection was not taken — there is no requirement behind any of them.
- The `Side/` set is **one render per object**; `Isometric/` is **four** (`_NE _NW _SE _SW`), so
  the isometric path costs four times the manifest rows and four times the review surface for a
  projection nothing in D3 asks for. Re-derive both counts with
  `ls <archive>/Side/*.png | wc -l` and `ls <archive>/Isometric/*.png | wc -l`.

**Both were not needed.** A renderer draws one projection; a second set is dead weight in the
tree and one more thing every Gate-1 row has to stay true about. If the floor's projection is
ever re-ruled, the isometric set is one download away and this paragraph is the record of why it
was not taken now.

---

## What was deliberately NOT taken, and why

1. **The 3D sources — every `.obj`, `.mtl`, `.fbx`, `.dae`, `.glb` and `.stl`.** `FLOOR.md`
   § 10.1 clause 1 admits `.ts .js .md .svg .png .tmx .tmj .tsx .tsj` under `resources/` and
   nothing else, so **these cannot be vendored at all** — Gate 2 clause 1 fails them by name.
   That is the allowlist working, not a limitation worked around: the pack is catalogued as a 3D
   kit and the PNGs are renders of it, and a renderer this repository does not have is not a
   deliverable. **This is also the honest shape of the bridge** — the thing that would have made
   this pack resolution-independent is exactly the thing § 10.1 refuses.
2. **The whole `Isometric/` directory.** The projection reasoning is above. Not a licence
   question — a rendering one.
3. **Every `Side/` render outside the curated set** — bathrooms, kitchens, bedrooms, laundry,
   televisions, sofas, stairs, and the rest of a domestic furniture catalogue. An office floor
   does not need them, and **every vendored file is a manifest row somebody has to keep true**
   (`FLOOR.md` § 10.1 Gate 1), so vendoring the whole pack would have bought hundreds of rows of
   maintenance for art nothing draws.
4. **`Sample.png` and `Preview.png`** — the pack's own marketing sheets. Not assets the product
   draws.
5. **`Patreon.url`, `Kenney.url`, `Instructions.url`** — shortcut files, not art, and their
   extension is outside clause 1's allowlist anyway.
6. **`License.txt`.** Its extension is not in clause 1's allowlist, so it could not be vendored
   even though it is the evidence for the row. It is quoted **verbatim above** and hashed, which
   is what a reader actually needs; a copy of it under `resources/` would red the build.

---

## The tileset file

`furniture-kit.tsx` is a Tiled **image-collection** tileset — `columns="0"`, one
`<tile><image source="…"/></tile>` per PNG — rather than a sliced grid sheet, because the source
renders are **individually sized** (a wall is 190 px tall, a keyboard is 5 px) and packing them
into one grid would either crop them or pad the sheet with mostly-empty cells.

`tilewidth` and `tileheight` on the `<tileset>` element are Tiled's **bounding** values for a
collection, not a cell size: they are the maxima over the vendored set and no tile is obliged to
fill them. Re-derive every declared dimension straight from the PNG headers — this is the check
that the `.tsx` describes the files beside it, and it reds if either moves:

```
python3 - <<'PY'
import re, struct, pathlib
root = pathlib.Path('resources/floor/tiles')
bad = []
for src, w, h in re.findall(r'<image source="([^"]+)" width="(\d+)" height="(\d+)"',
                            (root / 'furniture-kit.tsx').read_text()):
    d = (root / src).read_bytes()[:24]
    assert d[:8] == b'\x89PNG\r\n\x1a\n', src
    aw, ah = struct.unpack('>II', d[16:24])
    if (aw, ah) != (int(w), int(h)):
        bad.append((src, (aw, ah), (int(w), int(h))))
print('MISMATCHES:', bad or 'none')
PY
```

⛔ **TILED WAS NOT RUN, and that is stated rather than left to be assumed.** It is not installed on
the machine this was vendored on, so `furniture-kit.tsx` is **constructed from the published TMX/TSX
format** and is not a byte Tiled emitted. What that leaves unverified is one thing and it is named:
whether Tiled, on opening and re-saving this file, would write the same attributes in the same
order. What it does **not** leave unverified is anything this repository gates on — every `<image>`
names a `source`, no layer data is declared at all, and both are properties of the bytes as
committed, read by `bin/asset-provenance.py` on every PR. `bin/asset-provenance.selftest.py` makes
the same declaration about its own fixtures, for the same reason.

⛔ **`.tsx` here is Tiled's Tileset XML, not TypeScript-JSX.** `FLOOR.md` § 10.1 clause 1 admits
the suffix for the first meaning only, and clause 3 hands the file to an XML parser — a React
component dropped here would be failed by name.

**Clause 3's two properties hold by construction and are checked on every PR:** every `<image>`
names a `source` (no image bytes are embedded, so every picture has a path, therefore a row,
therefore a provenance), and the file declares no tile-layer data at all, so there is no encoding
to get wrong. `bin/asset-provenance.selftest.py` carries the image-collection form as a control
alongside the grid-sheet one — **the collection shape had never been exercised against clause 3
before this tileset landed**, and a clause nobody had run against the shape the repository was
about to adopt is a clause nobody had evidence about.

---

## The residue — what none of this proves

- **CC0 is a declaration by the upstream author, and no gate can audit it.** Kenney states the
  dedication on the page and inside the archive; this repository reproduces both and hashes what
  it vendored. Nothing here establishes that Kenney held the rights to dedicate — that is the
  same residue `FLOOR.md` § 10.1 names for every `licensed` row and § 10.5 names for the IP line,
  and review, not a script, is what stands against it.
- **The hashes prove the bytes did not move since they were vendored; they do not prove the
  bytes came from where this file says.** Re-running the two `curl` / `sha256sum` commands above
  is what re-establishes that, and it is written down so a later reader can.
- **No map is vendored.** `FLOOR.md` § 10.3 owns that absence and `tools/design/verify-floor.py`
  holds it; a tileset is not a map, and the floor's `.tmj` is still card#7341's to author.

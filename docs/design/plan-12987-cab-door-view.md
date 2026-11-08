# card#12987 — design and build plan: the cab's open doors show the floor they open on

**What this file is.** The design and the build plan for one card, in the shape
`plan-11818-reporter-outdated.md` set: written for the coder who implements it and the reviewer who
attacks it. It owns the *derivation*, the *sequence* and the *acceptance list as observables*; it owns no
rule. Every rule lives in `FLOOR.md`, which this commit amends in the same places the lobby view
(card#12113) amended: § 4.5's ride bullet, § 4.4's route table, § 12's figures, § 13 row 115, § 14 items 38
and 48, Appendix B row 44 (planned, no landing marker), AT-D3-26's red list. A reader who finds the two
disagreeing trusts `FLOOR.md` and fixes this file. When the build has landed this file is history.

**Revision 2 (2026-11-08)** folds design review round 1: the import boundary the lobby page holds moves
the hoist of every shared piece into slice 1 with its own graph check (§ 8, § 3.2); the draw order is the
separating-plane rule with its derivation and a pinned RED (§ 3.3); one `bare` predicate, the floor
page's own (§ 5); the tileset and theme-module states (§ 4, § 5); one `doorView()` signature and the
model's memo (§ 4); the prefetch's real cost and timing (§ 4); walls per room footprint and the top-row run
(§ 3.2); the fixture scenarios behind every RED (§ 6); the retarget of the office checks (§ 8); the sight
line from the eye's true place and the door's aspect (§ 3.5); the import depth re-derived (§ 8). § 9's
three look questions are now seat rulings the operator can reverse.

**Revision 3 (2026-11-08)** folds design review round 2 by DELETING two mechanisms rather than adding to them
(the outside read's recommendation, taken by the seat): **cut 1** — the view's bodies are the map's CELLS and
the draw order is one total order over them (rows far to near, a row boundary's bodies between the rows it
parts, outer to inner within a row), so `tileRegions()`, the stacking question r2's A1 named, the pre-split,
the pairwise graph and its topological sort, the cycle split and the long-side wall rule are gone from the
3D path (§ 3.2, § 3.3), and the tool re-prints every count from the cells and checks the order against an
exact ray cast; **cut 2** — the press-time `fetchLayout()` (r2's A2) is deleted and the rule it broke is
stated: the door view's data fetching writes nothing a screen reads as a fact, and its only failure signal is
`bare` (§ 4, § 6's S8). The minors that survive the cuts are folded (m-b's complete retarget list, m-c's
`deskSlab(foot)`, m-f, m-g, m-h, m-i, m-j); m-d and m-e are dissolved by cut 1 and say so where they stood.
The RED the outside read proposed in the pairwise rule's place — *a nearest-point sort fails on fixture A's
wall-and-plant pair* — was CHECKED and is false over cells (§ 3.3's evidence), so the order's RED is pinned on
the pair the live aimla map actually fails: a piece behind the PM's partition, painted over it.

**Slice 2's build (2026-11-08) folds design review round 3 in place**, by the seat's ruling on the card (no revision 4;
the five required items amended where they stand, in this file and in `FLOOR.md`): **M1** — a piece on the map's last row
tied with the far wall and was painted under it; the far wall is now the first body of its boundary (§ 3.3's key gains a
tier), and the ray-cast check counts a tie instead of skipping it (§ 10); **M3** — a layer's or a tileset's offset puts
cells off the grid; every cell is placed on it once, at the model's input, by its tile index (§ 3.3's *On the grid*;
the slice-2 impl review's F1 showed a rounding snap moved a whole layer a cell across the footprint's edges at an offset of
half a cell or more); **m2** — S3b's
wording (§ 6); **m4** — pieces on one boundary in the floor page's tile order, before any distance (§ 3.3); **m6** — the
random-scene check is committed with tie counting and planted controls (§ 10, `tools/design/door-view.selftest.mjs`). M2
(the wake on a stop's data arriving) and m1 (slice 3's anchors) are slice 3's; m3 (a room at `origin.y > 0` has its top
row undrawn — planned floors only, none live) and m5 (a failed off-floor fetch seen on a later layout — needs a layout
re-save in the same document) are accepted on the record (card comment 16208).

**Design approval.** Operator directive of 2026-11-08, recorded on card#12987, verbatim: *"When standing
in the elevator cab and the door opens or closes, currently a generic office space is shown. I want to see
the actual floor layout and theme as someone would see it for each specific floor. For example, when
opening or closing the doors on sola floor, you would see a wall on the right side and the sola floor theme
color, and desks in front of you. On the AIMLA floor, you would see the cottage theme and the PM's cubicle
wall in distant. On the solo-agent floor, you would see cubicles and the color of that floor."*

## 1. The problem, in the product's terms

The cab's open doors show one hand-drawn office on every floor. `server/public/js/floor/cab-paint.js`
`drawOfficeView()` (card#12052) places two desks and a plant at literals — `desk(-175, -55, 540, 610)`,
`plant(l + 35, 420)`, `desk(-10, 130, 240, 320)` — and reads nothing of the stop; `BEYOND` maps the ride's
`beyond` to it for every floor (`const BEYOND = Object.freeze({ office: drawOfficeView, lobby: drawLobbyView })`),
and `FLOOR.md § 4.5` said so in its own words until this card: *"⚠ The office is one office and the lobby
view is one lobby, whatever the floor or the layout: both are scenery and neither reads a floor's theme."*
The only thing the cab already does per stop is CHOOSE between two pictures: `wire/ride.js` `sceneRide()`
declares `beyond: stop === null ? 'lobby' : 'office'` from the stop the doors open on. The ride therefore
already knows WHICH stop; nothing downstream of it is told.

What a rider should see is derived from what the floor page already holds and draws: the floor's composed
rooms (`floor-layout.js` `placeRooms()`), each room's Tiled map — its `desks` objects, its `wall` and `accent`
cells, its standing pieces by `kind` (`scene.js` `mapTiles()`, `floor-layout.js` `mapDesks()`) — the band's
elevator and its threshold (`scene.js` `backWall()`,
`threshold()`), and the floor's theme (`scene.js` `floorTheme()`, the theme module's `PALETTE` and its
`scenery()` documents). The design below is a PROJECTION of that model from the cab's eye. Nothing in it is
drawn per floor by hand.

### The population, derived from the live store (2026-11-08, this host's sandbox)

The floors a rider can stop at are the layout's floors plus one implicit floor per install the layout does
not place (`FLOOR.md § 4.6`; `App\Building\Building` keys an implicit floor by its install and gives it
`theme: null`). The commands re-print them; the readings are what they printed on the day:

```bash
cd server && php artisan tinker --execute='$l = DB::table("building_layout")->first(); echo $l->document, "\n";'
# {"floors": [{"label": "aimla", "theme": "cottage", "rooms": {"aimla": {"form": "open"}}}]}
cd server && php artisan tinker --execute='foreach (DB::table("installs")->select("install_id")->orderBy("install_id")->get() as $r) echo $r->install_id, "\n";'
# aimla  kanban  mezzanine  sola  solo-agents
cd server && php artisan tinker --execute='foreach (App\Floor\Floors::all() as $r) echo $r->install_id, " map_version=", $r->map_version, " bytes=", strlen($r->map), "\n";'
# aimla map_version=1   solo-agents map_version=3   (each of the shipped default's order: `wc -c resources/floor/default.tmj`)
```

So on this install: the **aimla** floor (key `aimla`, theme `cottage`, its authored map — the lounge floor,
§ 13 row 112); the implicit floors **kanban**, **mezzanine**, **sola**, **solo-agents**, each one room in
the house theme (`studio`, `resources/floor/themes/index.js` `HOUSE_THEME`); `solo-agents` on its authored
map (revision 3), the other three on the shipped default `resources/floor/default.tmj`, which
`GET /api/building/rooms/{install_id}/map` answers for a room with no authored map
(`App\Http\Controllers\BuildingController`, `ShippedDefaultMap::map()`; § 4.4's floor row). The operator's
three examples are therefore three different maps under two themes, and § 3.5 below reads each.

## 2. The shape, and what was rejected

**Chosen — the view behind the doors is a projection of the stop's own floor model, built by the screen
from the building it holds and handed to the cab as data on `scene.ride`.** Three parts, each in the module
that already owns its half:

1. **`wire/ride.js` declares the STOP, not only the kind of view.** `sceneRide()` adds `stop` — the floor's
   key, or `null` for the lobby — beside `beyond`, from the same rule `beyond` already follows (`CHOSEN_ON`:
   the page's own stop before the choice, the chosen one from `chosen` on). `beyond` keeps its two values
   (`office` for a floor's stop, `lobby` for the lobby's) so nothing that pins it moves.
2. **A new headless module, `floor/door-view.js`, derives the MODEL** — `doorView(stopRow, building,
   tilesets, themes)` (§ 4, the one signature) → the room in centimetres relative to the cab's threshold,
   from the stop's plaque row (`lobby/building-model.js` `plates()`: its rooms, `hallway`, `theme`), the
   maps and tilesets the client holds (`wire/building.js` `Building`, `floor/tileset.js` `TilesetLoader`) and
   the theme modules. Pure, DOM-free, driven under `node` like `floor-layout.js` is, and **importing only
   modules the lobby page may load** — which is why slice 1 hoists its inputs out of `scene.js` and
   `desk-layout.js` first (§ 3.2's *Where each reader lives*). The screen that owns the ride attaches the
   model to the scene's ride: `scene.ride = { ...sceneRide(now), view }` — on the floor page in
   `floor-screen.js` where `scene.ride` is composed today (`rideView()`, `#frame()`), on the lobby in
   `lobby-screen.js`'s equivalent. The page performs; it decides nothing (`wire/ride-page.js`'s rule).
3. **`cab-paint.js` `drawFloorView(parent, box, id, view)` PROJECTS the model** with the one `at()` the
   office and the lobby views already use — the eye `OFFICE.view` (= `CAB.back`, 120 cm) behind the doorway
   at `CAB.eye` (155 cm) — clipped to the doorway by `clippedTo()`, into `.cab-beyond`. `BEYOND.office` becomes
   it; `drawOfficeView()`'s shell survives only as the **bare room** § 5 names. `paintCab()` still reads
   `ride` and nothing else: the view it draws is `ride.view`, never the page.

Why this split and not another:

| Rejected | Mechanism it would turn on | Why not |
|---|---|---|
| **A theme function that draws the view** (§ 14 item 38's original idea: `theme.doorView(...)` answering one SVG document) | one more entry in `index.js`'s closed `API`, a document per theme at a rect the scene decides, `<image>` in the cab | A theme draws **a piece that carries no fact at a rect the scene decides** (§ 10.6 item 2); it never lays a floor out (item 4). The door view's content IS the layout — where the walls and desks are — so a theme function would have to be handed the whole floor model and re-project it, in two themes, in two copies of the same perspective arithmetic the cab already owns. The theme contributes what it owns today: its palette and its standing pieces |
| **Fetch a server-rendered view per floor** (`GET /api/building/floors/{key}/door-view`) | a new endpoint composing rooms, maps and theme on the server | The client already composes floors and reads maps (`placeRooms()`, `mapTiles()`); a second composer on the server is two answers to *where is this room* (the drift § 4.6's reader table forbids), and D2 § 8.7 fetches authored documents whole and by version — the client's cache is the one home. Rejected on the same ground § 13 row 112 rejected a per-room shipped file |
| **Re-draw the floor page's own scene through the doorway** (hand `paintCab()` the painted floor and skew it) | an SVG transform over the floor's drawn elements | The floor is drawn in its own oblique art direction with the characters, nameplates, bubbles and every fact on it — § 6.3 says the view through the doors is scenery with none of that; and the lobby page has no floor scene to re-draw. Culling the facts out of a painted scene is harder than projecting the model that has none |
| **Keep one hand-drawn office per THEME** (two pictures) | a second `drawOfficeView` in cottage colours | Satisfies "the cottage theme" and not one word of the rest of the directive: the operator named the wall on the right, the PM's cubicle wall, the cubicles — positions, which only the map holds |
| **Re-implement the readers inside `door-view.js`** (its own sprite offsets, its own standing-piece inputs) so nothing need be hoisted | copies of `deskRects()`'s desk rect and `buildScene()`'s scenery input | Canon #5's second divergent implementation: the floor page and the door view would be free to disagree about where a desk stands. The hoist (§ 3.2) costs one slice with no behaviour change and leaves one reader |

**The one upstream change that makes the downstream ones unnecessary (canon #2):** hoisting the model out
of the paint. Once `door-view.js` exists, the lobby page and the floor page share it, the test drives it
under `node` with fixture maps and no DOM, and the paint is a projection with no decisions in it.

## 3. The derivation — how the view falls out of the floor's data

### 3.1 Frame of reference

The floor is drawn with its back-wall band across the top of its extent and the elevator in the band's
reserved zone (`scene.js` `backWall()`: `lift = { x: span.x + ELEVATOR.dx, … }`, `ELEVATOR = { dx: 32, w: 84,
h: 144, … }`; `threshold(band) = { x: band.elevator.seam, y: band.elevator.y + band.elevator.h }`, the seam
`lift.x + lift.w / 2`). The band spans the floor's extent (`floor-layout.js` `backWallBand(extent)`), so the
**threshold is the point `(extent.x + 74, extent.y)` in floor px** — on every floor, whatever its maps, 74 px
in from the extent's left edge on its top edge. A rider in the cab stands in that wall and faces INTO the
room: down the page, toward the page's own viewer. Facing someone, your right is their left: **the rider's
right is the floor's −x.** The projection's coordinates are therefore

```
X_cm = (threshold.x − px.x) · SCALE        across, positive to the rider's RIGHT
Z_cm = (px.y − threshold.y) · SCALE        depth, positive INTO the room
Y_cm = (px height) · SCALE                 up from the floor, for a piece's drawn height
```

with `SCALE = CAB.door.h / ELEVATOR.h = 200 / 144 ≈ 1.389 cm/px` — the one object drawn on both sides of
the doorway is the door the rider walked through: 144 px tall on the band, 200 cm in the cab (§ 12's new
*Door-view scale* row says why that and not the desk's or the character's size; both rejected there).
The room's height follows: `BAND_H · SCALE = 160 · 1.389 = 222 cm`, which is the "about 2.2 m" § 12's
*Back-wall band height* row already states — derived, not chosen. **The scale is taken from the door's
height alone**: its width would give 110 / 84 ≈ 1.31 cm/px, so lateral extents are 6 % wider than the
door's own aspect would make them; one scale on both axes keeps a square cell square, and the door's
drawn width is the cab's own figure (*Cab interior*), not the band's.

**The eye** is `CAB.eye` = 155 cm above the floor at `Z = −OFFICE.view` = −120 cm, behind the doorway plane
(the office view's `at()`: `s = view / (view + Z)`). Every sight-line figure below is taken from there.

### 3.2 What is read, and what each becomes

The floor is composed exactly as the floor page composes it — the same calls, so there is one answer to
*where is this room*:

| Read from | By | Becomes, in the view |
|---|---|---|
| the stop's plaque row: `rooms[]` (`install_id`, `form`, `origin?`), `hallway`, `theme` | `plates()` over the layout the client holds — the same list the cab's buttons read | the floor to compose; the theme to colour it |
| each room's map and the hallway's | `Building#room(id).held.map` where the room is not mapless (§ 5); `placeRooms(rooms, extents, hallway)` → origins, `extent`, `draw_order` | the **floor plane**: one slab the size of the extent, Z from 0 to `extent.height · SCALE`, X from `(threshold.x − extent.x − extent.width) · SCALE` on the rider's left to `(threshold.x − extent.x) · SCALE` = 74 px · SCALE ≈ 103 cm on the right; a gap between side-by-side rooms (`ROOM_GAP_PX`) is plain floor. **The hallway** (a planned floor only) is read as the floor page reads it — a grid at the floor's origin, seeded with the floor's key (scene.js:1357 `grids.push({ install_id: null, map: input.hallway, origin: { x: 0, y: 0 }, seed: frame.floor.key })`), its own extent standing in for a footprint (scene.js:1385's fallback) — so its cells enter the rows below; it contributes no walls of its own, as a corridor is open to the rooms along it. No live floor is planned, so § 7's planned fixture is where this is first exercised |
| each room's **footprint** (`placeRooms()`'s `footprint`, never the union extent) | arithmetic | the room's **walls**, full height (222 cm), as planes on the footprint's edges: the **far wall** along its bottom edge, a body of zero depth on that row boundary; the **side walls** along its left and right edges, planes of zero width **cut into row strips** so each strip takes its place in § 3.3's order (an interior side wall — a room's edge with another room beyond it — stands between the eye and that room's contents and must be painted after them and before its own). Each room its own, so two rooms of different heights or an L-shaped plan get walls where their rooms end and nowhere else; two rooms whose footprints share an edge contribute one coincident strip per row, drawn once (identical strips deduped). **A footprint's TOP edge is the band the rider stands in**, behind the eye, and is never drawn |
| `wall` cells, one by one | `mapTiles()` cells whose `kind` is `wall`, placed on the grid by their tile index (§ 3.3's *On the grid*) | a **box per cell**, the cell's own 8 px square (11 cm): a **wall** (222 cm) where the cell lies on its room's footprint edge — its left, right or bottom edge — else a **partition** (§ 12's *Partition height*, 120 cm). **A cell in the footprint's TOP ROW is the near wall** — the author outlining the room along the band — and is not drawn, as the footprint's top edge is not: drawn, it would stand at `Z = 0` across the doorway and fill it. **A cell under a desk's slab is not drawn**: the desk stands on it, as the floor page draws the slab over the run (reachable: an author pastes a map with a wall tile under a slot through the console, F21's own route). The rule's one artefact, accepted: where an interior partition meets the perimeter, its end cell lies on the edge and is a 222-cm post 11 cm wide — against the perimeter wall, which the plan's § 3.5 already accepts as a wainscot |
| `accent` cells | `mapTiles()` cells whose `kind` is `accent`, **merged along each row** by `door-view.js`'s own `rowRuns()` — adjacent cells of one row, one rect; a dozen lines, and NOT `tileRegions()`, which is the floor page's lattice-and-draw-order region pass with `misordered()` behind it and stays in `scene.js` | flat **accent runs** on the floor plane, in the theme's accent colour, drawn before every body and in no order among themselves (coplanar and disjoint). Without the merge the solo-agents floor is `node tools/design/door-view-plan.mjs <solo-agents.tmj>`'s *accent cells* figure of polygons; with it, its *runs* figure |
| each room's `desks` objects | `mapDesks(map)`; **`deskSlab(foot)`** (m-c) — the desk's rect from the furniture box's BOTTOM-LEFT point `foot`: `{ x: foot.x + ART_W / 2 − 90, y: foot.y − 100, w: 180, h: 60 }`. `deskRects()` computes that rect inline today as `{ x: mid − 90, y: H − 100, w: 180, h: 60 }` (desk-layout.js:442, 451) and calls `deskSlab({ x: 0, y: H })` after slice 1; the door view calls `deskSlab({ x: slot.x, y: slot.y + slot.h })` — the slot's bottom-left, where `slotOrigin()` anchors the box — so the box's height cancels and the view needs neither the box (`FURNITURE_BOX`, loaded by the floor painter alone) nor `slotOrigin()`. `deskRects()`'s `character` argument is the creature tree's `SCENE_W × SCENE_H`, which only the character rects read and the lobby does not load; the door view never calls `deskRects()` | an **empty desk** per slot, two bodies: its **top**, the slab's rect 0–76 cm high, legs drawn as marks on its near face; its **monitor's back**, `monitorFrame(slab)`'s width (96 px — the one rect `deskRects()`'s `frame` is, hoisted beside `deskSlab()` in slice 2) at the slab's page-right end — **the rider's LEFT third** (the page's right is the rider's left, § 3.1) — one cell deep on the slab's near edge (the page's `y: slab − 46` is up the page, toward the rider), 76–147 cm. Each body is cut into row strips (§ 3.3). Prisms in the theme's `PALETTE.view` colours; no chair, character or seat key: § 6.3's scenery rule, and a chair's back over an empty desk adds a body for no reading the operator named |
| standing kinds — `theme.kinds` less **`PLANE_KINDS`** (m-a: hoisted with `mapTiles()`), as `buildScene()` derives `standing` (scene.js:1379 `const standing = theme.kinds.filter(...)`) | `mapTiles()` cells with `kind`; **`sceneryInput(cell, seed)`** — the document input `buildScene()` builds inline today (scene.js:1454–1461 — `{ kind, w, h, seed, index }`, plus `RESTROOM_PLATES` for the restroom door at :1460), hoisted so both callers hand the theme one shape; the theme's `scenery()` document from it | a **billboard**: the theme's own drawing of the piece, a body of zero depth standing on the cell's floor line at `(X, Z)` of the cell's bottom edge — a row boundary — `w · SCALE` wide and `h · SCALE` tall, shown as an `<image href="data:…">` exactly as the floor shows it (§ 10.6 item 2's asset shape) |
| the theme | `floorTheme(floor, registry)` (house theme for `null`; house theme under F23's notice for an unshipped name); the module's `PALETTE` | the view's colour carriers (§ 3.4); no module held ⇒ § 5's bare room in the sheet's tokens |

**Where each reader lives, and why it must move (M1).** The lobby page's import graph is held by
`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` to three edges into the animation log and set
(`PATH`: `live-page → animation-log`, `lobby-screen → animation-set`, `ride → animation-set`). The readers
above live today in `floor/scene.js` (imports `wire/animation-set.js` at scene.js:32) and
`floor/desk-layout.js` (imports `desk/desk-render.js`, which imports `animation-set.js` at
desk-render.js:51) — neither is in the lobby's graph (`scene importers: []` in the reviewer's closure),
and importing either from `door-view.js` would be a fourth edge and red that test. So slice 1 moves the
pure pieces into modules with no such edge, each name re-exported from where it was so no importer and no
test anchored on the old module moves:

| Piece | From | To | Imports of the new home |
|---|---|---|---|
| `ELEVATOR`, `BAND_H`, a `thresholdOf(extent)` | `scene.js` (`backWall()` reads them) | `floor/floor-layout.js` | none today, none after |
| `floorTheme()`, `unshippedThemeNotice()` | `scene.js` | `floor/floor-theme.js` (new) | none |
| `mapTiles()`, `safeEmbedded()` (its private helper), `PLANE_KINDS`, `RESTROOM_DOOR`, `RESTROOM_PLATES`, and the new `sceneryInput()` | `scene.js` | `floor/map-tiles.js` (new) | `floor-layout.js`, `tileset.js` (no imports of its own) |
| `ART_W` and the new `deskSlab(foot)` | `desk-layout.js` (`deskRects()`'s inline `desk`) | `floor/desk-geometry.js` (new) | none |
| `svgUri` | `floor/painter.js` | `floor/cab-paint.js` | none static (its one dynamic-import site is the declared loader) |
| `monitorFrame(slab)` — slice 2 | `desk-layout.js` (`deskRects()`'s inline `frame`) | `floor/desk-geometry.js` | none |
| `themeInputs()` — slice 2, beside the new `loadThemes()` | `floor/painter.js` | `floor/cab-paint.js` | none static |

**Not moved, since revision 3:** `tileLook()`, `tileRegions()` and `misordered()` stay in `scene.js` (the
door view reads cells, § 3.2's rows above; the region pass is the floor page's own), and `slotOrigin()` stays
(`deskSlab(foot)` takes the slot's bottom-left, which needs no box). `scene.js` and `desk-layout.js` import
each moved name back and re-export it, so every importer keeps working — the probes that read
`PLANE_KINDS`, `mapTiles` and `slotOrigin` through `scene.js` (`walk-probe.mjs:75`,
`ADeskLayerInsideAGroupIsSeatedTest`'s `import(scene)`) included — and `desk-layout.js`'s import of `ART_W`
is a plain named import, so the three mutations whose REPLACEMENT text spells `ART_W` inside that module
(`TheDeskIsReLaidTest.php:265`, `TheNewDeskKeepsEveryLeafTest`'s `HATCH` at :38, and
`SeatFurnitureNeverOverlapsTest.php:163`'s anchor `const colB = ART_W + GUTTER;`) still compile and still
anchor.

**Tests anchored on the moved SOURCE TEXT are retargeted in the same slice (m-b).** `mutatedModules()`
asserts its anchor appears in the named file exactly once (`DrivesAShippedClientModule.php:255–259`), so a
missed anchor fails loudly in CI rather than passing against unmodified code — but slice 1 is "every existing
test green" only if the list is complete. The list is derived, not recalled: run

```bash
grep -rn "ELEVATOR = \|BAND_H\|thresholdOf\|floorTheme\|unshippedThemeNotice\|themes.house, notice\|mapTiles\|safeEmbedded\|RESTROOM_DOOR\|RESTROOM_PLATES\|PLANE_KINDS\|sceneryInput\|ART_W\|deskSlab\|svgUri" server/tests --include=*.php
```

and keep every hit that names the OLD module as the file it mutates or reads. On 2026-11-08 that was:
`TheFloorDrawsItsFrameTest.php:153` (*the band*, anchor `export const BAND_H = 160;` in `scene.js`) and
`:765` (the `BAND_H` regex over `scene.js`'s text), both to `floor-layout.js`; `TheFloorDrawsItsFrameTest.php:196`
(anchor `const ELEVATOR = { dx: 32,` in `scene.js` — moved, exported AND frozen, so the anchor became
`export const ELEVATOR = Object.freeze({ dx: 32,` in `floor-layout.js`, as the tree has it); `TheFloorResolvesItsThemeTest.php:85` (a
mutation inside `floorTheme()`'s body — `: { theme: themes.house, notice: unshippedThemeNotice(floor.theme, floor.name) };`
in `scene.js`), to `floor-theme.js` — a hit r2's list did not have; and `TheNewDeskKeepsEveryLeafTest.php:376–377`
(`svgUri` in `painter.js`), to `cab-paint.js`. `TheFloorsTilesDrawWithoutSeamsTest.php:93–94` reads
`tileLook()` off `scene.js` and is NOT retargeted — `tileLook()` no longer moves. Slice 3's own retarget
(`drawOfficeView`, `BEYOND`) is § 8's.

**The boundary is checked in slice 1, not discovered in slice 3**: that test gains `DOOR_VIEW_ROOTS` — the
modules the view will import (`floor/floor-layout.js`, `floor/floor-theme.js`, `floor/map-tiles.js`,
`floor/tileset.js`, `floor/desk-geometry.js`, `floor/cab-paint.js`) — each walked by `graphDefects()` as a
root beside `lobby/main.js`, with the same defects (an edge into the log or the set, an unfollowable
specifier). Slice 2 adds `floor/door-view.js` to the roots; slice 3's `lobby-screen.js` import then adds no
edge the walk has not already passed. Its RED: a planted `import { LOOP_FPS } from '../wire/animation-set.js'`
in `map-tiles.js` reds in slice 1.

### 3.3 The projection, culling and draw order

- **One eye, one `at()`.** `drawFloorView()` uses the office's projection unchanged: `s = view / (view + Z)`,
  `x = box.x + box.w / 2 + X · s · kx`, `y = box.y + (door.h − eye + (eye − Y) · s) · ky`. The vanishing point
  is the cab's, so the room and the cab are one view from one point (§ 12's *Office view* reasoning, kept).
- **Depth limit: the room's own far wall.** Nothing exists past `Z = extent.height · SCALE` (9.7 m on the
  default map, 11.2 m on solo-agents), so no artificial horizon is needed; the far wall closes the view.
- **The ceiling (m-j)** is a plane at `BAND_H · SCALE` = 222 cm over the whole extent, carrying the office
  view's lights at the office view's pitch over the extent's depth; the bare room's shell (§ 5) takes the
  SAME height — § 12's *Office view* row moves from 280 to the derived 222 and `OFFICE.h` with it, in slice
  3's round (the row carries the planned amendment now and keeps the code's figure, since
  `test_green_section_12s_office_view_is_office` holds the two equal) — so when R1's model arrives and
  replaces the shell, the ceiling and the horizon (the eye's 155 cm, the same on both) stay where they were
  and only the walls and contents change.
- **Culled before it is drawn:** every body is projected as its corners; one whose projected bounding rect
  lies wholly outside the doorway box is skipped. A face is drawn only if it faces the eye: a body's near face
  always (every body has `Z ≥ 0`, the eye is at `Z = −120`); its top only if its height is under `CAB.eye` (a
  partition's and a desk's are, a wall's is not); its left or right end only when the body lies wholly to one
  side of `X = 0`. Partitions beside the doorway at `Z` under ~0.8 m are cut by the door's own frame, as a
  doorway hides what stands beside it (§ 3.5's readings show where that bites).
- **The bodies are on the cell grid in Z (cut 1; replaces revision 2's boxes-and-graph).** Every body the
  view paints lies in ONE ROW of the map's 8-px grid or ON ONE ROW BOUNDARY: a `wall` cell is its own row;
  a side wall is cut into row strips; a desk's top and monitor are cut into row strips, the slab's rows
  being the cell rows its exact rect touches (snapped outward, at most 7 px = 10 cm each way — the view
  draws the desk one cell deeper at most, which at a 10-m room is not a reading); the far wall and every
  billboard are bodies of zero depth on a row boundary. **On the grid (design review round 3's M3):** a layer's
  or a group's offset and a tileset's offset (`mapTiles()` adds both, as Tiled draws them) put a cell off the
  grid, so the model places every cell once, at its input, BY ITS TILE INDEX — its column and row in its layer
  (`mapTiles()`'s `col` and `row`) at the room's origin, moved only by the WHOLE cells of the offset, the part
  under a cell truncated toward zero (`trunc(offset / T) · T`); a billboard's foot line is the boundary under
  its cell so placed — and classifies a cell (top row, under a slab, on a footprint edge) on that place. The
  footprint is the same grid at the same origin, so a cell on its top row or edge is there BY CONSTRUCTION,
  whatever the author dragged the layer by. The residual is under a cell (at most 7 px, 9.7 cm) of where the
  author dragged the layer, toward the tile's own cell, which the view does not draw. *Rejected:* rounding the
  drawn pixels to the nearest grid cell — at an offset of half a cell or more it moves the whole layer one cell
  across the unmoved footprint's edges, drawing the near-wall row across the doorway, turning the perimeter's
  walls into partitions and putting a last-row piece behind the far wall (the slice-2 impl review's F1);
  flooring them — the same for every negative offset. The selftest's `bravo` is dragged on both sides of half a
  cell and below zero, with the rounding and the flooring each planted as a RED. So the order has ONE AXIS and
  needs no graph:

  **far rows first; a boundary's bodies between the row beyond it and the row before it — on a boundary the
  far wall first (M1), then the pieces in the floor page's own tile order (m4); within one row, the body
  farther from the eye's centre line first (`min |X|` over its span, 0 where it straddles `X = 0`), a desk's
  parts taking the desk's own distance and its higher part later; then the map's own tile order.**

  As a sort key: `(−key, tier, …)` with `key = 2·row + 1` for a body in a row and `key = 2·m` for a body on the
  boundary `y = m·T`, `tier` 0 for a plane on a boundary (the far wall) and every body in a row, 1 for a piece;
  then, between two pieces, `index` (the tile order), and otherwise `(−mx, rank, index)` — `mx` the distance
  above, `rank` 0 for a desk's top and 1 for its monitor. **Why the far wall is first on its boundary (M1):** a
  piece on the map's last row stands on the far wall's own plane, so the two are COPLANAR — no separating plane
  orders them — and the revision-3 key broke that tie by `−mx`, which painted the far wall (straddling `X = 0`,
  `mx` 0) over any piece off the centre line and hid it; the shipped default's armchair and aimla's bookcase on
  their last rows survived only by straddling the centre line. A piece stands IN the room, so it is in front.
  **Why tile order between pieces (m4):** two pieces on one foot line are coplanar too; the floor page stacks
  them in tile order (`buildScene()`'s scenery loop), so the view does, rather than by a distance the geometry
  does not ask for.
  *Why it is exactly the separating-plane rule on this population:* two bodies in different rows are
  Z-separated with the eye in front of both, and the farther row is painted first; a boundary body and a row
  body are Z-separated (touching), the boundary body after the row beyond it and before the row before it;
  two bodies in one row span the row's whole depth and — since cells are disjoint, a cell under a slab is not
  drawn, side walls are the footprint's edges and desks are disjoint by F21's own rule — are X-separated, and
  the one nearer the eye's line is the one the eye's side of their separating plane holds, painted later
  (where the eye lies between them no ray meets both, and either order is right); a desk's monitor stands on
  its top, Y-separated with the eye above, so the higher part is later. A within-row key that read each
  body's own `mx` would put a monitor (X-inside its desk's top) BEFORE the top it stands on — the first
  version of this tool did, and the ray cast below caught it; the desk's parts share the desk's `mx`. **A
  total order has no cycle**, so revision 2's pre-split, pairwise verdicts, Kahn's sort and cycle split are
  deleted, and with them r2's A1 (the tool's stacked rects were never the build's bodies — now the cells
  are, on both sides), m-d (every body has a place; nothing is reported as a defect or falls back to the bare
  room) and m-e (there is nothing to detect).
- **Evidence (re-printed by the tool).** ⚠ The tool's ray cast below SKIPS a pair one ray meets at one depth — a
  tie — and that is how M1 passed it: a coplanar piece and far wall were never measured. The build's check
  (`tools/design/door-view.selftest.mjs`, § 10) counts every tie against the order its shared plane owes. `node tools/design/door-view-plan.mjs <map.tmj>` builds the bodies
  above from a map and checks the order against an EXACT RAY CAST — 4,000 rays from the eye through the
  doorway; for every pair of bodies one ray meets at different depths the nearer must be painted later — and
  prints the violations per order, for this order and two others: the nearest-point sort over the same
  bodies, and *pieces last* (every billboard after every other body, the floor page's plane-then-scenery
  habit). On 2026-11-08 over the three live maps its `ray cast` lines read `row violations 0` on each;
  `piecesLast violations 73 — bookcase@8,416 painted over partition ×50; bookcase@8,552 painted over
  partition ×23` on aimla (a bookcase against the right wall BEHIND the PM's partition, painted over it) and
  0 on the other two; and **`nearest violations 0` on all three** — which is why the RED the outside read
  proposed (a nearest-point sort failing on fixture A's wall-and-plant pair) is NOT the RED: over cells, a
  wall's cells nearer than the plant project to the right of the plant and its farther cells are sorted
  before it by either rule; the pair constrains nothing. The random-scene check (the scratch script
  `rowmc.mjs`, the shape r2's `mc.mjs` had — random rooms, runs of cells, desks at unaligned `y`, billboards,
  a second room with a gap half the time, 800 scenes × 300 rays per seed, two seeds) printed, for this order,
  `row violations 0` on both seeds once the two defects it found were fixed (the monitor's `mx`, above, and
  two desks at different `y` in one row — the reason the slab is snapped to rows); the nearest-point sort
  printed a handful (`desk-top over piece`), *pieces last* thousands in a fifth of the scenes, the row order
  without its within-row rule about a thousand (`partition over partition`: adjacent cells in a row DO
  overlap in projection by a sliver), and billboards keyed to their own cell's row instead of the boundary
  hundreds (`far-wall over wall`). The figures are the script's to re-print; § 10 below records the run.
- **Inside the doorway always:** the whole view is drawn into `clippedTo()`'s group, as the office and the
  lobby are; the existing checks (`officeDefects`' clip leg, `seamDefects`) hold it.
- **Cost, derived rather than asserted.** The model is one pass over `mapTiles()`'s cells and one sort; the
  paint is one pass over the sorted bodies. The counts are the tool's `bodies` and `faces inside the
  frustum` lines — on 2026-11-08: default `bodies 534 … inside the doorway's frustum 181`, `per cell 160
  over 80 cells … desk-strip faces 57; billboards 3`, accent runs `0 of 39` in the frustum; aimla `510 … 208`,
  `per cell 267 over 116 cells; merged along row runs 162 over 81 runs; desk-strip faces 27; billboards 4`,
  accent `39 of 39`; solo-agents `1126 … 256`, `per cell 429 over 143 cells; merged along row runs 231 over
  77 runs; desk-strip faces 60; billboards 0`, accent `74 of 222`. So the heaviest live floor paints about
  five hundred SVG nodes per model, once per model and memoised (§ 4); no per-frame work. **Merging faces
  along a row run** (adjacent same-class cells of one row as one body — the run inherits the row and takes
  `min |X|` over its span, so the order is unchanged) is the optimisation the tool prices beside the per-cell
  figure; it is NOT in the build: it halves the face count on aimla and solo-agents and buys nothing on the
  default (every wall in its frustum runs along Z), and five hundred nodes is under what the floor page
  paints for one room. Accent needs its row merge regardless (16,206 cells on solo-agents); that merge is
  `rowRuns()`, a dozen lines in `door-view.js`. **Hairlines between faces** are § 7's named hazard.

### 3.4 The theme in the view

- **Palette.** The view's surfaces are painted by classes in `CAB_STYLE` from CSS custom properties the
  view's root group carries, set from the stop's theme — `--dv-wall-top`, `--dv-wall-bottom`,
  `--dv-skirting`, `--dv-floor`, `--dv-accent`, `--dv-lamplight` from the `PALETTE` rows every theme already
  has (`wall.top/bottom/skirting_top`, `floor.boards`, `accent.top`, `light.lamplight`), and `--dv-desk`,
  `--dv-desk-edge`, `--dv-monitor`, `--dv-partition`, `--dv-partition-cap` from one new row,
  `PALETTE.view`, added to both themes (the desk rows differ in shape today: studio `desk.wood`, cottage
  `desk.paint/top`, so a generic read has no common key). The carrier is the mechanism `cabTheme()` and the
  band's cab already use (`--cab-frame` / `--cab-lamp` set as style, read by class); the mixed rules go in
  `MIXED` with their derived fallbacks, so `HoldsEveryColourMixToAFallback` covers them unchanged. No colour is
  written in the paint; the root group's `style` holds custom-property declarations only (§ 6 holds it to
  that regex). **With no theme module held (m3)** — the registry or the stop's module failed to import, the
  `null` `loadHousePalette()` already answers for it — the root group carries no `style` at all, and every
  `.dv-*` rule's `var(--dv-…, <fallback>)` falls to the sheet's tokens the office view is painted in today
  (`.office-floor`'s `--scene-floor`/`--scene-trim` mix, `.office-wall`'s `--scene-paper`/`--house-wall-2`),
  the same shape as `cabTheme()`'s removed properties and the sheet's `.band-cab` defaults — never black.
  Such a stop is the bare room (§ 5): no billboard can be drawn without the module's `scenery()`.
- **Furniture.** The standing pieces are the theme's own `scenery()` documents, so the cottage floor shows
  the cottage's rattan armchair and baskets and the studio floor its own — the strongest read of "the cottage
  theme" for no new drawing code. Their URIs are made by the one `svgUri` (hoisted from `painter.js` to
  `cab-paint.js`, which the painter re-exports) and cached with the model (§ 4), keyed on the document's
  inputs as the painter's cache is (§ 10.6 item 2's rule). **Seat ruling (§ 9 (1)):** a billboard is the
  floor's drawing of the piece, which faces the floor page's viewer; from the cab the rider is behind it,
  and the view shows it facing the rider anyway — the licence § 4.5 took for the lobby view's bare side
  walls (§ 14 item 39(12)).
- **What is not drawn, by rule.** No window and no daylight: the band's windows are behind the eye, and
  § 10.6 item 3 forbids a static light that says what time it is. No character, nameplate, screen text,
  badge, chip or word (§ 6.3): the desks are empty and the monitors are seen from behind. No A17 sky. No
  motion. The ceiling keeps the office view's lights, scenery in the sheet's tokens.

### 3.5 The three examples, verified against the live maps

The plans below are printed by `tools/design/door-view-plan.mjs` over the live store's maps and the shipped
default (`node tools/design/door-view-plan.mjs <map.tmj>` after dumping a room's map with the `tinker` line
in its header): it reads `resources/floor/tiles/floor-plane.tsx` for each gid's `kind`, classifies each
`wall` CELL as WALL or PARTITION by § 3.2's per-cell rule, and converts to the rider's frame with
`SCALE = 200/144` and the seam at `x = 74`; the tables below quote its lines, which group a column or row of
same-class cells on one line FOR PRINTING ONLY (the model never merges them). It is a design-time reader kept
independent of the build's `door-view.js` so the two can be compared; it assumes a single-room floor, which
every floor on the sandbox is. One character is 32 px; `#` wall, `.` accent, `D` a desk slot's
outline, `P` the reserved desk, letters standing kinds, `E` the threshold; the plans are ABRIDGED to the rows
that carry a wall, a desk edge or the threshold (the tool prints every row). The cm columns are what the
rider's frame makes of them: `X` positive to the RIGHT. Sight lines are from the eye at `(0, 155, −120)`.

**sola — the shipped default (`resources/floor/default.tmj`, 1784 × 696 px, studio).**

```
   0    1    3    4    6    8    9    1    2    4    6    7
   0# EDDDDDDDDDDDDDQ  DDDDDDDDDDDDDD#.L..WDDDDDDDDDDDDDDB.#
  32#  D            D  D            DA...G.D.............D.#
 160#  D            D  D            D#.....D......P......D.#
 288#  DDDDDDDDDDDDDD  DDDDDDDDDDDDDD#..###DDDDDDDDDDDDDDD##
 352#  DDDDDDDDDDDDDD  DDDDDDDDDDDDDD  DDDDDDDDDDDDDDD     #
 640#  DDDDDDDDDDDDDD  DDDDDDDDDDDDDD  DDDDDDDDDDDDDDD     #
```

| Element | Floor px | Rider's frame | Through the doorway |
|---|---|---|---|
| the column of wall cells `x0..8 y8..696` — 86 cells, each on the footprint's left edge → WALL, 222 cm (the top-row cell at `y0` is the near wall, not drawn) | | `X +92..+103 cm`, `Z 11..967 cm` | **a wall on the right**, 1 m off the rider's shoulder, from ~0.8 m (where the frustum's edge, `55 · (120 + Z) / 120`, first reaches 92 cm) to the far wall; 86 bodies in 86 rows, one per row |
| desk #1's slab (`x 114..294`, `y 211..271`) | | `X −306..−56 cm`, `Z 293..376 cm` | **a desk ahead**, left of centre, its right half inside the frustum (half-width 189 cm at `Z = 293`) |
| desk #4's slab | | `X −306..−56 cm`, `Z 788..871 cm` | the next desk down the same column, whole (half-width 416 cm at `Z = 788`) |
| desks #2, #3, #5, #6 (`X ≤ −778 cm`) | | | outside the frustum at their depth — out of view, as a room's far side is from a doorway |
| the plant at `x8..88`, foot `y512` (`Z 711 cm`, `X −19..+92 cm`) against the right wall | | | a billboard on the boundary `y = 512`: painted after the wall's cells in rows `y ≥ 512` (behind it) and before those in rows `y < 512` (beside and in front of it) — revision 1's nearest-point sort over one tall wall box got this pair wrong; over cells, § 3.3 shows, no plausible order does |
| the floor | boards `#d9c7a5`, accent `#dcc196` on 17.9 % of cells | | the sola floor's colour: studio boards, with the PM corner's accent far right |

The operator's words — *a wall on the right side and the sola floor theme color, and desks in front of you*
— are what the default map's data projects to. (A note the derivation surfaces: the "wall on the right" is
the author's perimeter column of wall tiles along `x = 0`, AND the footprint's own left edge one tile behind
it — the rule draws both, the cells 11 cm in front of the room's side-wall strips, which is a coherent
wainscot and not a defect.)

**aimla — the lounge floor (authored map revision 1, 1784 × 696 px, cottage).**

```
   0    1    3    4    6    8    9    1    2    4    6    7
   0# E   B  p Q        DDDDDDDDDDDDDD DDDDDDDDDDDDDDD   S #
 192#     A  g A        D            D D             D    W#
 352################   #                                 p #
 384#..DDDDDDDDDDDDDD..#DDDDDDDDDDDDDD DDDDDDDDDDDDDDD     #
 512#..D.....P......D..#D            D D             D   R #
 672#..DDDDDDDDDDDDDD..#DDDDDDDDDDDDDD DDDDDDDDDDDDDDD     #
```

| Element | Rider's frame | Through the doorway |
|---|---|---|
| the row of wall cells `x8..512 y376..384` — 63 cells, none on a footprint edge → PARTITION, 120 cm (the cell at `x0` in that row is the perimeter column's, a WALL) | `X −608..+92 cm` at `Z 522..533 cm` | **the PM's cubicle wall in the distance**: 5.2 m ahead, crossing the whole view (half-width 294 cm there), a 120-cm panel with the room's far wall 4.5 m behind it; 63 bodies in one row, painted outer to inner |
| desk #3, reserved `pm` (slab `X −306..−56`, `Z 815..899 cm`) | behind the partition | the sight line over the panel's top from the eye at `(155 cm, Z −120)` reaches the desk at `155 − 35 · (815 + 120) / (522 + 120) ≈ 104 cm`: its top (76 cm) is hidden and the monitor's back (to ~147 cm) shows over the panel. A 150-cm panel would hide the desk entirely — § 12's *Partition height* row is where that is decided |
| the column of wall cells `x608..616 y376..688` (39 cells, interior → PARTITION) and its last cell `y688..696` on the footprint's bottom edge (→ a 222-cm WALL post, § 3.2's accepted artefact, standing against the far wall) | `X −753..−742 cm`, `Z 522..967 cm` | the cubicle's far side, left, past the partition |
| the bookcase at `x8..80`, foot `y528` (`Z 733 cm`, `X −8..+92 cm`) against the right wall, BEHIND the partition | | **the pair the order's RED is pinned on**: a billboard on the boundary `y = 528`, painted before the partition's row (`y376..384`) by the row key; *pieces last* would paint it over the panel — the tool's `piecesLast violations 73` on this map |
| the lounge nook (`bookcase@208,0`, `plant@288,0`, `aquarium@376,0`, armchairs at `y 192`) | `X −186..−531 cm` at `Z 0..~200 cm` | beside the doorway, inside the frame's cut (half-width 55–147 cm at those depths): a corner of the armchair at most |
| the floor and walls | cottage boards `#cbd3b3`, walls `#d7e6ee`/`#c4d8e4`, skirting `#5f7f9f`, accent `#e0cfa8` | **the cottage theme** |

*The cottage theme and the PM's cubicle wall in distant* — both fall out of the map and the layout entry's
`theme: "cottage"`.

**solo-agents — the authored map (revision 3, 2024 × 808 px, house theme).**

```
   0    1    3    4    6    8    9    1    2    4    6    7    9
   0  E
  96   #DDDDDDDDDDDDDD....DDDDDDDDDDDDDDD...#DDDDDDDDDDDDDD....#
 256   #D............D..B.D.............D.p.#D............D...L#
 384   #DDDDDDDDDDDDDD##A#DDDDDDDDDDDDDDD#B##DDDDDDDDDDDDDD##p##
 672   #DDDDDDDDDDDDDD....DDDDDDDDDDDDDDD...#DDDDDDDDDDDDDD....#
 704   ###############    ###############   ###############    #
```

| Element | Rider's frame | Through the doorway |
|---|---|---|
| the columns of wall cells `x120..128 y104..400` and `y408..704` (37 cells each, interior → PARTITIONS) | `X −75..−64 cm`, `Z 144..978 cm` | **a cubicle wall** 0.7 m to the left, running from 1.4 m to the far end |
| the rows of wall cells `x120..568 y96..104` (56 cells) and the cross row `x120..1904 y400..408` (223 cells) | `X −686..−64` at `Z 133`; `X −2542..−64` at `Z 556` | the cubicle's near end and the row's cross wall, their tops in view over the first |
| the footprint's left edge (no author tile there) | side-wall strips at `X +103 cm`, one per row | the aisle's right side, 1 m off |
| desks #1 and #4 (`X −350..−100`, `Z 410..493` / `832..915 cm`) | behind the 120-cm partition | their monitors' backs over the panel; the tops under the sight line |
| the floor | accent `#dcc196` on 63.4 % of cells (the aisles between cubicles), boards `#d9c7a5` | **the colour of that floor** — the studio accent fills the aisle the rider looks down |

*Cubicles and the color of that floor* — and the derivation says more precisely what the rider sees: a
1.7 m aisle between the room's wall and the first cubicle's partition, in the floor's accent, with the
cubicle tops stepping away. Nothing here was placed by hand.

## 4. What `scene.ride` carries, and who reads what

| Member | Declared by | Read by | Rule |
|---|---|---|---|
| `beyond` (`office` / `lobby`) | `wire/ride.js` `sceneRide()` — unchanged | `paintCab()`'s `BEYOND` | which drawer |
| `stop` (floor key, or `null`) — NEW | `sceneRide()`, from `CHOSEN_ON.includes(phase) ? chosen.key : current` — the same expression `beyond` is derived from, so the two cannot name different stops | the screen, to resolve the model; the test, to pin it | the stop the doors open on |
| `view` (the model, or `null`) — NEW | the SCREEN, where it composes `scene.ride` today (`floor-screen.js` `rideView()` / `#frame()`; `lobby-screen.js`'s equivalent): `null` when `beyond` is `lobby`, else the memo's model for `stop` (below) | `paintCab()` → `drawFloorView()`; the ride page, to repaint on arrival | never inferred by the painter from the page — the existing RED (*the view inferred from the page*) stays, and a new one plants `globalThis.location` in the model's resolution |

**The one signature.** `doorView(stopRow, building, tilesets, themes)` — `stopRow` is `plates()`'s row
whose `floor === stop`, or `null` for a stop the stops no longer offer (the floor was removed from the
layout mid-ride); `building` the page's `Building` (read through `room()`, `mapless()`, `reportedVersion()`);
`tilesets` the page's `TilesetLoader` (read through `held`); `themes` the loaded registry and modules
(`{ registry, modules }`, the shape `scene.js`'s `themeOf()` reads). Pure: the same inputs give the same
model, and `null` for a `stopRow` gives the bare room in the sheet's tokens (§ 5's R4) — **one builder for every
floor stop**, so `view` is never `null` for one.

**The memo (m4, its key m-h).** The screen holds `#doorViews = new Map()` keyed by every input `doorView()`
reads that can change while a page lives, each term defined:

```
${stop}                                             the floor key
|${building.layoutVersion}                          the layout document's version — rooms, their form and origin, the hallway (one number covers every placeRooms() input)
|${rooms.map((r) => `${r}:${building.mapless(r) ? 'mapless' : building.room(r).held.map_version}`)}
|${urls.map((u) => `${u}:${tilesets.held.get(u)?.tileset ? 'held' : tilesets.held.get(u)?.failure ? 'failed' : 'pending'}`)}
                                                    every tileset URL the held maps name, with its state in TilesetLoader#held
|${theme}|${themes.modules[theme] ? 'module' : 'none'}
```

A hit answers the same object, so the repaint rule below compares identity and never re-derives; a miss runs
`doorView()` once (the cell pass, the one sort and the billboard URIs are its cost, § 3.3). The map is the
screen's and is cleared when the ride ends (`arrived()` or Escape), so a page holds at most the ride's stops.

**Where the stop's data comes from, on each page.** The floor page holds its own floor's maps (§ 4.4's
floor row), their tilesets (`#loadTilesets()` loads the target floor's only, floor-screen.js's reader) and
every theme module (`painter.js` `loadArt()`); the lobby holds the layout alone, no map, no tileset and the
house theme's palette only (`loadHousePalette()`). A ride to ANOTHER floor needs that floor's maps and
tilesets on either page, and its theme module on the lobby. So, **at the press (`opening`)**, the screen
runs one sequence on both pages, under one rule:

**The door view's data fetching writes nothing a screen reads as a fact, and its only failure signal is
`bare` (cut 2; r2's A2).** Revision 2 opened the sequence with `Building#fetchLayout()` so the versions
compared were the server's now. That call sets `#layoutFailure` on any non-200 (building.js:143), which BOTH
screens state as a fact — the floor page as F17's statement and, on a 401, `readRefused(401)`
(floor-screen.js:1173–1179, 1386); the lobby through `lobbyModel()` and the same 401 path
(lobby-screen.js:198–201, 243) — so a press under a transient 5xx would have told a rider who asked for a
ride that the layout failed; and the floor page states about itself that the layout is fetched only when
the client holds none (floor-screen.js:531–545). The step is deleted. What it bought is accepted as the
residual: a map re-saved since the page loaded is held at the page's version for the ride (D2 § 8.7's
`applyRoomMap` ignores a `room.map` for a room the page does not render), the same one-revision staleness
revision 2 already accepted for a re-save during the ride. The three steps that remain write only into
`Building#rooms` and `#reported` for the stops' rooms, the loader's `held` and the cab painter's module
cache, and **no screen reads those for a room off its own floor** — verified by reading each page: the
lobby's `Building` reads are `floors` (lobby-screen.js:131, 243), `applyLayout` (:153), `fetchLayout`
(:160, :180) and `layoutFailure` (:198, :243) — no `room()`, no `reportedVersion()`; the floor page reads
`room()` for `target.rooms` / `frame.rooms` / `floor.rooms` only (floor-screen.js:577, 617, 1109, 1256) and
`#roomRefused()` scans `target.rooms` alone (:1427); and `reportedVersion()` has one reader, `enterRooms()`
itself (building.js:233). The outside read inferred the floor-page half without re-reading it; these are
the lines. S8 in § 6 is the RED.

1. `Building#enterRooms()` for every stop's rooms — once per stop, the stops' calls in parallel
   (`Promise.all`), each serial inside as the primitive is — held by version, so a second ride on a page
   fetches nothing. **The cost, as a derivation, not a bound**: the building's rooms less the page's own
   floor's (§ 1's `installs` line prints them), each answered with its authored map or the shipped default
   (§ 1's byte figures); three rooms on the default are three answers of the same bytes, and this design
   does not deduplicate them (the API answers per room — a cost accepted, named in § 9 (3)).
2. The maps' tilesets through the page's `TilesetLoader` — `#loadTilesets()` generalised to take the rooms
   it loads for, called for every stop's rooms; the lobby constructs a loader of its own for it (m1).
3. On the lobby, every theme module the registry names, through `cab-paint.js`'s one dynamic-import site
   (`loadHousePalette()` generalised to `loadThemes()`, answering the registry and every module), so
   `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s walk still follows one declared loader — its
   needle text and its walk change with it (slice 2) to follow every `THEMES` entry, a strictly wider check
   than today's one module.

**Why the press and not the choice.** The buttons are inert until `shut`, so the earliest choice is
`opening` (1,000 ms, `RIDE_DOOR_MS`) + `entering` (a fade of `1000 / LOOP_FPS` = 250 ms + 1,000 ms) =
**2.25 s after the press** (`ridePhase()`; `LOOP_FPS` = 4), and the chosen floor's doors open 1.25 s after
the choice (`chosen`: a fade and the door motion). Fetching at the choice leaves 1.25 s for the map, its
tileset and (on the lobby) the module, in series; fetching at the press leaves 3.5 s, none of it now spent
on a layout round trip first. § 12's *Cab hold* row
names the operator's round trip to the deployment as unmeasured from this host, so no figure here says
either is enough; the press is the cheaper failure, and a stop whose data is late is the bare room (§ 5)
until it arrives.

**A model that arrives during a phase whose doors move repaints the cab once.** `wire/ride-page.js`
`perform()` returns early on an unchanged phase today; it gains one more case — the phase is `entering`,
`chosen` or `leaving` and `view.cab.view` is not the object last painted (the memo makes identity the
test) — and calls `paintCab()` again. `cabLeaves()` places each leaf's motion at the phase's elapsed time
(`doorTiming()`'s negative delay), so the repaint lands mid-motion as the band's does (the existing *follow
each phase without restarting* check is the precedent and the pin).

**The hop this depends on (canon #7).** The model is built from documents another party authored and the
server serves: the map's `desks` objects, `wall`/`accent` cells and `kind`s. The server DECLARES their
shape (`App\Floor\FloorMap` refuses a map the readers refuse — § 10.3's table; `verify-floor.py`), and the
client CHECKS nothing of it again: `mapDesks()` and `mapTiles()` read what the floor page already reads,
and a map the floor page draws the door view draws. What neither end can verify locally is
named here: the operator's READING of a projection — whether a 120-cm partition and a door-derived scale
make the room the operator pictured — which only the sandbox shows (§ 6's by-hand row).

## 5. A floor whose data the client does not hold — one predicate, and the bare room

**One predicate (m2).** The floor page's own: a room is **mapless** when it was never answered or its last
request failed — `state === null || state.failure !== null` (floor-screen.js's `const mapless = …` in its
room loop) — and under a failure `Building#room().held` is the F16 EXTENT, not a map to draw
(`#fetchRoom()`: *what was held stays — as the extent, under the failure*). Slice 2 hoists that expression
into `Building#mapless(installId)` and the floor page reads it from there, so the two readers are one. **A
floor's view is BARE when any of its rooms is mapless, when any tileset URL a held map names has no
`{ tileset }` entry in the loader's `held` (m-f: `TilesetLoader#held` holds `{ pending }` and `{ failure }`
entries too, tileset.js:431–432 — a URL in `held` is not a set held; `mapTiles()` answers `kind: null` for a
tile whose set is absent, which would draw a floor with no walls, accents or pieces — a floor drawn with
confidence from half its data), or when the stop's theme module is not held.** One definition, per floor, not per room: the view is one picture, and a floor
drawn with one room and a hole where another should be is the composition § 13 decision 30 refuses (a
building composed from a failed fetch is the wrong building drawn with confidence), applied to scenery.

**The routes that reach it**, each named with the sequence a viewer performs:

- **R1 — the data has not answered.** Lobby page on a slow link: press the elevator (the layout is held, the
  stops are listed, the press fired § 4's sequence); press a floor's button at the earliest the buttons
  allow, 2.25 s after the press; `chosen` paints before that floor's map, tileset or module has arrived.
- **R2 — the map request failed.** Any page: a room whose `GET /api/building/rooms/{id}/map` answered a
  non-200 holds F16's failure (`{ held, failure: { status } }`); the floor page draws that room's desks
  mapless. Reproducible in the probe with a scripted fetch answering 503; on the sandbox it has no switch —
  reaching it there takes a failing map route, which this design does not add.
- **R3 — the theme module failed.** The registry or the stop's module rejected (`loadThemes()` answers
  `null` for it); the floor page's F14 path on its own floor, the lobby's `loadHousePalette()` null today.
- **R4 — a stop the stops no longer offer.** The layout removed the floor mid-ride (`#stops()` re-derives
  every frame): `stopRow` is `null` — and with it no theme to read, so R4 is drawn as R3 is (m-i).

**The bare room** is the office view's shell — the floor plane, far and side walls, ceiling and lights of
`OFFICE`'s footprint (520 × 900 cm, the shell `drawOfficeView()` draws today, kept for exactly this) at the
floor view's own ceiling height (222 cm, § 3.3; `OFFICE.h` amended with § 12's row) — in the stop's theme
palette where the module is held (R1 waiting on a map, R2) and in the sheet's tokens, with no `style` on the
root group, where there is no module (R3) or no stop row to name one (R4) — § 3.4's fallback, one reading for
both (m-i) — with **no desk, no wall cell, no accent and no piece**. When R1's data arrives the repaint rule in § 4 replaces it with the floor; R2's and R3's stay bare
for the ride. The model carries `bare: true` and the reason (`mapless` / `tileset` / `theme` / `stop`) so
the probe can tell the renders apart; nothing on the page says so — the floor page's F16 and F14 statements
on arrival are the fact surface, and the door view carries no fact.

What is deliberately NOT defended: a stop with no layout at all. With no layout held there are no floor
stops (`plates()` answers `null` → the panel offers *Lobby* alone), so no floor view is asked for.

## 6. How the test pins it — a mutation that shows the wrong floor's layout or theme must red

The test is `TheElevatorIsAControlOnTheDrawingTest`'s shape (a `node` probe over the fake DOM, a PHP test
applying source mutations and asserting each check reds), extended with a **door-view leg**, plus one check
each in `TheFloorsRideIsNavigationTest`, the lobby graph test and the theme selftest.

**The fixture.** Three synthetic floors in one layout document, with 8-px-cell maps the probe builds inline
(no live data; the shapes are chosen so A and B differ on every axis the design claims and C covers what
they cannot):

- **A** — key `alpha`, `theme` absent (house theme, studio): one `desks` slot LEFT of the seam (`x 200`,
  so its slab is at `X < 0`); a second slot far left (`x 1200`, outside the frustum at its depth — the
  culling reading); one COLUMN of `wall` cells RIGHT of the seam at `x 40..48` down the whole map
  (`X ≈ +36..+47 cm`, interior → partitions, the top-row cell not drawn); **one `plant` tile (80 × 128 px)
  standing just inside that column at `x 48, y 496..504`** (foot `Z ≈ 700 cm`, `X −75..+36 cm`); **one
  `wall` cell inside the first slot's slab** (at `x 240, y` inside the slab's rows — the under-slab reading);
  no accent.
- **B** — key `bravo`, `theme: "cottage"`: one slot far LEFT (`x 900`, culled), a ROW of `wall` cells that
  is a horizontal interior PARTITION at `y 400..408` from `x 0..600` (crossing `X = 0` at `Z ≈ 556 cm`; its
  `x 0` cell lies on the footprint's left edge and is a 222-cm post — § 3.2's artefact, read in S2), the
  floor's cells all `accent`, **one `bookcase` tile (72 px wide) BEHIND the partition at `x 200, foot y 488`** (`Z ≈ 678 cm`,
  `X ≈ −275..−175 cm` — inside the doorway's frustum and overlapping the partition in projection; the pair the
  order's RED is pinned on, aimla's own shape), and a row of `wall` cells along the top row `x 0..400, y 0..8`
  (the near wall: not drawn).
- **C** — key `charlie`, two rooms unplanned (`charlie` and `charlie-2`, side by side with `ROOM_GAP_PX`
  between), the first 296 px tall and the second 496 px (whole 8-px rows; 300 and 500 are not): per-room walls at each footprint's own far edge,
  the gap plain floor with a side wall on each side of it, and a map for `charlie-2` that names a second
  tileset the loader does not hold.

The probe drives `wire/ride.js`'s `Ride` with the floor page's constructor arguments from stop `alpha`:
`press → entered → shut → choose(<stop>)`, reading `sceneRide()` at each step and resolving `doorView()`
with a scripted `Building`, `TilesetLoader` and themes; then paints with `paintCab()` (the module's and the
painter's, as the `beyond` leg does) and reads back, under `.cab-beyond`:

| Scenario | Readings |
|---|---|
| **S1** `entering` (doors on `alpha`, everything held) | `scene.ride.stop` = `'alpha'`; the root group's `--dv-floor` / `--dv-wall-top` = studio's `#d9c7a5` / `#eef0e0`; wall cells: the column's cells less its top-row cell, every projected `x` of each near face RIGHT of the doorway's centre line, each top BELOW the horizon `y = box.y + (door.h − eye) · ky` (partitions); **no cell inside the slab's rows and X span** (the under-slab reading); desk bodies: one desk's strips (the second culled), LEFT of centre, the union of its top strips' near faces equal to the probe's own `at()` over the slab's snapped rect; its monitor's faces after its top's within each row; **draw order: the plant's `<image>` follows every face of cells in rows `y ≥ 504` and precedes every face of cells in rows `y < 504` in document order**; no accent polygon |
| **S2** `chosen('bravo')` (everything held) | `stop` = `'bravo'`; cottage's `#cbd3b3` / `#d7e6ee`; wall cells: the partition row's cells, their near faces spanning the centre line together, their tops BELOW the horizon, the `x 0` cell's top ABOVE it (the post); the top-row cells drawn nowhere (no body with `Z` under a cell's depth); desk bodies: zero; accent polygons ≥ 1; one `<image>` whose `href` equals `svgUri(cottage.scenery(sceneryInput(cell, 'bravo')))` recomputed by the probe, **and it precedes every face of the partition row in document order** (the order's RED) |
| **S3** `chosen('bravo')` with `bravo`'s map scripted to 503 (R2, never held) | `view.bare` = `true`, reason `mapless`; cottage custom properties on the root; no wall, desk, accent or `<image>`; the shell's floor, walls and ceiling present |
| **S3b** `chosen('bravo')` with `bravo`'s map HELD at `map_version` 1, the layout the page holds reporting 2 (a `building.layout` applied before the press), and the press's `enterRooms()` refetch of that held map scripted to 503 — the press fetches no layout (cut 2), so the held map's refetch is its only request for `bravo` (round 3's m2) (m-g: R2 over a held map — `Building#room()` answers `{ held: <the v1 map>, failure: { status: 503 } }`, building.js:291) | `bare`, reason `mapless` — the held v1 map is F16's extent, not a map to draw; otherwise as S3 |
| **S4** `chosen('bravo')` with `bravo`'s map answering 300 ms after `chosen` began (R1) | the first paint as S3 with no failure; after the answer, exactly one further paint under `.cab-beyond` in that phase, S2's readings, and the leaves' animations the same objects as before the repaint (the existing *follow each phase without restarting* reading) |
| **S5** `chosen('bravo')` with the cottage module absent from `themes` (R3) | `bare`, reason `theme`; the root group carries NO `style`; the shell drawn in the sheet's classes |
| **S6** `chosen('charlie')` | two far walls at different `Z` (the two footprints' heights), side-wall strips at four distinct `X` (each footprint's two edges), the gap's floor polygon between them; `charlie-2`'s missing tileset ⇒ `bare`, reason `tileset`, with the whole floor bare (not `charlie` alone) |
| **S7** `chosen('zulu')`, a key the stops do not offer (R4) | `bare`, reason `stop`; the shell in the sheet's tokens (no `style`) |
| **S8** press (`opening`) from `alpha` with `/api/building` scripted to 503, and again to 401 (cut 2's RED) | after the press's sequence has settled: `building.layoutFailure` is `null` as before the press; the client's `readRefused` was never called; `building.floors` is the object held before the press; the stops' rooms were fetched (`GET /api/building/rooms/{id}/map` once per room) and `GET /api/building` was not requested at all |
| every scenario | the scenery census (`officeDefects`' rules): no `text`, no `data-*`, no `aria-label`, no motion, no inline colour on any element — with two declared exceptions, each checked rather than skipped: the root group's `style`, where present, matches `^(--dv-[a-z-]+:#[0-9a-f]{6};)+$` and nothing else; an `<image>`'s `href` is a theme document the probe recomputed (any other `href` is a defect, as today) |

**The REDs, each appended to `test_each_check_goes_red_against_the_defect_it_exists_to_catch`'s table
with a `doorViewDefects` reader, the scenario that catches each named**, and what each would otherwise
let through:

| Mutation (the source line, and its replacement) | Caught by | What it would ship |
|---|---|---|
| `wire/ride.js`: `stop: ride.current` (the key never following the choice) | S2 | the page's own floor behind the opening doors on every ride |
| `floor/door-view.js`: `X = (px.x − threshold.x) · SCALE` (the mirror dropped) | S1 | sola's wall on the LEFT |
| `floor/door-view.js`: the threshold at `extent.x` (the seam's 74 px dropped) | S1 | every wall and desk 103 cm off |
| `floor/door-view.js`: the palette from `themes[registry.HOUSE_THEME]` (the house theme for every stop) | S2 | aimla in studio colours |
| `floor/door-view.js`: a cell under a desk's slab drawn (the slab predicate dropped) | S1 | a partition post poking through a desk top wherever an author laid a wall tile under a slot |
| `floor/door-view.js`: `bare` from `held.map === null` alone (the failure and the tileset ignored) | S3b, S6 | a stale map drawn with confidence under F16's failure (R2 over a held map — S3 alone would not catch it, since there `held` is `null` too); a floor with no walls where a tileset is missing |
| `floor/door-view.js`: the billboards appended after every other body (`[...bodies, ...billboards]` in place of their place in the one order) | S2 | a piece behind a partition painted over it — aimla's bookcase over the PM's cubicle wall, the tool's `piecesLast violations 73` |
| `floor/door-view.js`: the top-row cells drawn (their `y === footprint.y` exclusion dropped) | S2 | a 222-cm slab across the doorway |
| `floor/door-view.js`: walls from the union extent in place of each footprint | S6 | walls where no room ends on a two-height floor |
| `floor/cab-paint.js`: `BEYOND.office` drawing `drawOfficeView` (the model ignored) | S1 | today's one office |
| `floor/cab-paint.js`: `paintCab()` reading `globalThis.location.pathname` to pick the model | S2 | the inference § 4.5 forbids |
| `floor/cab-paint.js`: the billboard's `href` from `scenery()` of the HOUSE theme | S2 | studio furniture on the cottage floor |
| `floor/cab-paint.js`: the root group's `style` set when the module is absent (`--dv-floor:undefined`) | S5 | black surfaces on a failed theme |
| `wire/ride-page.js`: the repaint-on-arrival case removed | S4 | R1's bare room never replaced while the doors open |
| `wire/ride-page.js`: the repaint on EVERY frame | S4 | the leaves restarted each frame |
| the screen's press sequence: `await this.#building.fetchLayout()` planted as its first step (revision 2's step 1) | S8 | a transient `/api/building` failure on a press stated as F17's layout failure, and a 401 on scenery calling `readRefused` |
| `floor/map-tiles.js`: `import { LOOP_FPS } from '../wire/animation-set.js'` planted (slice 1) | the lobby graph test's `DOOR_VIEW_ROOTS` walk | the lobby reaching the set through the view |

And in `TheFloorsRideIsNavigationTest`: `scene.ride.stop` equals the stop the doors open on in every phase
of `RIDES` (RED: `stop: ride.current`), beside its `beyond` check. In `tools/floor-themes/selftest.mjs`:
every theme's `PALETTE.view` carries the house theme's key set (its drift leg — seen to red before the
cottage row lands). § 12's new rows are pinned by `test_green_section_12s_door_view_is_door_view`
(`SCALE`, the partition height, the room height derived from `BAND_H`).

**Seen to fail first (canon #9).** Slice 3's PR lands the probe's green readings and the RED table in one
change; the coder runs the RED table with the production line in place of each mutation and records the
red in the PR's audit trail, as the lobby view's PR did. Slice 1's graph RED is run the same way in slice 1.
The one RED this revision REMOVED, and why: *a nearest-point sort in place of the order* — over cells it is
not wrong on any fixture here or any live map (§ 3.3's `nearest violations 0`; the random scenes found it
wrong only on a desk top over a piece standing inside the desk's rows), so it would be a RED seen to pass,
not to fail. The mutation a coder would actually write is the pieces-last one above.

**By hand, on the sandbox** (`tools/design/floor-ride.browser.mjs` gains a `door-view` row beside
`through-doors`): ride from the lobby to each of sola, aimla and solo-agents with the doors held half open,
and read the three tables in § 3.5 against the screenshots. This is the reading neither end can verify
locally (§ 4's hop).

## 7. What could falsify it, and what is not defended

- **The scale reads wrong to the operator** (the room too large, desks too long). The figure is one § 12
  row with a stated derivation and two rejected alternatives; changing it moves nothing else. The partition
  height likewise.
- **A planned floor with a hallway and rooms at authored origins**: the composition is `placeRooms()`'s own,
  so the door view and the floor page agree by construction; fixture C is unplanned, and a planned fixture
  with a hallway is a cheap addition the coder should make (`server/tests/Feature/Floor/fixtures/maps/`
  holds maps, not layouts; the probe builds its layout inline).
- **Two desks whose slabs intersect, or meet within a row** (F21's intersecting slots, reachable on any
  authored map; two slabs a pixel apart in `y` share a row once snapped): two bodies in one row overlapping
  in X, which § 3.3's within-row rule does not cover. NOT defended: the order is total, so both are painted,
  in `min |X|` order, as the floor page paints them "over each other"; nothing is reported and nothing
  falls back to the bare room. The random-scene check keeps slabs disjoint by construction and so does not
  measure this case; its route is the console's paste of an F21 map.
- **Rooms on cell grids of different sizes on one floor, or a `wall` tile larger than its cell** (slice 2's build;
  round 3's *not covered* list). The model takes the floor's grid from its first room's `tileheight` and draws
  every `wall` cell as that grid's square at the cell its tile index gives it. Every shipped and live map is on one
  8-px grid with an 8 × 8 wall tile (`floor-plane.tsx`'s tile 2), and `App\Floor\FloorMap` accepts
  any positive grid, so the route is an author's map on another grid: NOT defended — the view is drawn on the
  first room's grid and nothing is reported.
- **Hairlines between faces.** Adjacent cells' faces share edges, and the floor page merges runs precisely
  because a shape drawn cell by cell leaves a hairline where a shared edge lands on a fractional device
  pixel (scene.js's ⛔ *WHY ONE AREA AND NOT ITS CELLS* over `tileRegions()`). The door view's faces are
  perspective quads, so the same hairline is possible at every cell edge of a wall running along Z. The
  defence is the paint's, not a merge: every `.dv-*` face rule carries `stroke` of its own fill at
  `stroke-width: .5` (a class rule, so § 6's census — no inline colour — holds), the standard anti-seam
  stroke. The probe cannot see a hairline; the by-hand sandbox reading (§ 6) is where it is checked, and if
  the stroke does not hold, merging faces along a row run (§ 3.3's priced optimisation) is the next step —
  it halves the face count on the two authored maps and keeps the order — not a return to the region pass.
- **A huge map**: the model is one pass over `mapTiles()`'s cells (the floor page already runs it per
  paint) and one sort, once per model, memoised; the paint is linear in the bodies inside the frustum,
  which the tool prints. Not defended further; the probe records the counts.
- **Reduced motion**: every phase is a cut and the view is painted at entry as today. Nothing new.
- **A browser without `color-mix()`**: every new mixed rule is in `MIXED`, so `unmixed()` derives its
  fallback and the existing check holds it.

## 8. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last, each with its docs in the same round
(`FLOOR.md`'s amendments are in this commit; the coder updates the Appendix B row's marker and the AT list
as each lands).

1. **Hoist the readers the view needs out of the modules the lobby page cannot load** (no behaviour
   change; every existing test green). The table in § 3.2 (*Where each reader lives*) is the list:
   `ELEVATOR`, `BAND_H` and `thresholdOf()` into `floor/floor-layout.js`; `floorTheme()` and
   `unshippedThemeNotice()` into `floor/floor-theme.js`; `mapTiles()`, `safeEmbedded()`, `PLANE_KINDS`,
   `RESTROOM_DOOR`, `RESTROOM_PLATES` and the new `sceneryInput()` (which `buildScene()` now calls) into
   `floor/map-tiles.js`; `ART_W` and the new `deskSlab(foot)` (which `deskRects()` now calls with
   `{ x: 0, y: H }`) into `floor/desk-geometry.js`; `svgUri` into `cab-paint.js`. Each name re-exported from
   its old module. **Retarget every anchor § 3.2's grep lists** — `TheFloorDrawsItsFrameTest.php:153`,
   `:196` (`export const ELEVATOR = Object.freeze({ dx: 32,` now — the frozen object slice 1 shipped) and `:765`,
   `TheFloorResolvesItsThemeTest.php:85`, `TheNewDeskKeepsEveryLeafTest.php:376–377` — and re-run the grep on the branch before the PR, so the
   list is the tree's and not this file's. Add `DOOR_VIEW_ROOTS` to
   `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` and its planted-import RED; run it.
2. **The model.** `floor/door-view.js` `doorView(stopRow, building, tilesets, themes)` → the frozen model
   of § 3.2 (cm, rider's frame; the bodies on the cell grid and the one total order of § 3.3 — a sort, no
   graph; `rowRuns()` for the accent; `bare` and its reason per § 5), with a `node` selftest over the
   fixture floors of § 6 asserting the cm readings in § 3.5's shape, the under-slab cell's absence, and the
   order on fixture B's partition-and-bookcase pair; `Building#mapless()` hoisted from the floor page's
   expression;
   `PALETTE.view` in both themes and the selftest's drift leg; `wire/ride.js` `sceneRide()` adds `stop` (its
   RED in `TheFloorsRideIsNavigationTest`); `cab-paint.js` `loadThemes()` beside `loadHousePalette()` and
   the lobby walk's needles updated to follow every `THEMES` entry; `door-view.js` added to `DOOR_VIEW_ROOTS`.
   **As built:** the selftest is `tools/design/door-view.selftest.mjs`, wired into `design-doc-verifiers.yml`'s
   `design-artifact` job — its *fixtures* leg (§ 6's floors A, B — on the grid and dragged off it by each offset the
   selftest's `DRAGS` names — and C, § 7's planned floor with a hallway, with
   S3, S3b, S5, S6 and S7's model readings) and its *order* leg (§ 10), each RED planted in a mirror of the module
   tree; round 3's folds M1, M3, m2, m4 and m6 are in the code, this file and `FLOOR.md`. Two pieces the model
   reads were hoisted where the lobby may load them, each re-exported where it was: `monitorFrame()` into
   `desk-geometry.js` (`deskRects()`'s monitor rect; `TheDeskIsReLaidTest`'s frame RED retargeted onto it) and
   `themeInputs()` into `cab-paint.js` (the shape `loadThemes()` answers). `Building#mapless()`'s RED in
   `TheFloorComposesItsRoomsTest` moved onto the hoisted predicate. ⚠ `floor/painter.js`'s `loadArt()` still loads
   the registry and every theme module through its own `import()`s beside `loadThemes()`: two loaders of one set,
   left for its own change because `loadArt()`'s answer (the raw registry and the failed asset ids) is read by the
   floor page and two probes.
3. **The paint and the wiring.** `cab-paint.js` `drawFloorView()` (projection, culling, the sorted order,
   the ceiling at 222 cm, the anti-seam stroke rules, the bare room, the carriers with their `var()`
   fallbacks and `MIXED` rows); `BEYOND.office` → it; `drawOfficeView()` reduced to the shell the bare room
   draws (its desks, plant, windows and sun gone — § 10.6 item 3) at `OFFICE.h` = 222 (§ 12's *Office view*
   row amended in the same round; `test_green_section_12s_office_view_is_office` reads the new figure).
   **Retarget the office checks (m8, m-b)**: `TheElevatorIsAControlOnTheDrawingTest`'s REDs that mutate
   `drawOfficeView()`'s desk and plant literals (*a character in the office*, *a name in the office*, *the
   office moving* anchor on `desk(-10, 130, 240, 320);`; *an inline hex in the office* on
   `face('office-floor', pts(`) move their anchors onto `drawFloorView()`'s equivalent lines and keep their
   readers; its two `BEYOND` anchors at :321–322 (`const BEYOND = Object.freeze({ office: drawOfficeView, lobby: drawLobbyView });`)
   read the new line, `office: drawFloorView`; `officeDefects`' structural legs (found, clipped, census, no
   inline colour, no motion) read the floor view in every scenario of § 6; `test_the_cab_doors_open_onto_the_office`
   becomes `…_onto_the_floor` over S1. The floor screen attaches `view` and holds the memo; both screens run
   § 4's three-step press sequence (the lobby with its own `TilesetLoader` and `loadThemes()`) — no
   `fetchLayout()` in it, S8 is the check; `ride-page.js`'s repaint-on-arrival case. The probe's door-view
   leg, the scenario table and the RED table (§ 6), `test_green_section_12s_door_view_is_door_view`, the
   browser tool's `door-view` row. Appendix B row 44 gets its landing marker. Title: `… (closes card#12987)`.

**The import depth (m10).** § 12's *Cab hold* row names the module graph's serial import depth as the
lever on a slow round trip and gives the command that prints it; run in `server/public/js` on 2026-11-08
before any slice it printed **12 for `floor/main.js` and 8 for `lobby/main.js`**. Slice 3's PR re-runs it
for both pages and records the figures in its audit trail: the lobby's new chain is
`lobby-screen → door-view → map-tiles → tileset`, which the coder compares against the page's deepest
chain today rather than this file predicting it.

Slice 3 is the one the operator sees. If the operator's reading on the sandbox moves a figure, it is a § 12
row, not a redesign.

## 9. Seat rulings on the three look questions — reversible by the operator

Recorded in `FLOOR.md § 14` item 48 as the seat's rulings of 2026-11-08 (the design-review round's
recommendations taken), each one figure or one rule the operator's reading of the sandbox can reverse:

1. **Billboards face the rider** (§ 3.4). *Rejected:* standing pieces as prisms in the view palette, losing
   the theme's drawings. The cottage's furniture is where "the cottage theme" is most legible, and the cute,
   whimsical direction tolerates a paper cut-out standing in a perspective room.
2. **Partition height 120 cm** — monitors show over the panels, desk tops do not (the sight line at the
   aimla PM desk, from the eye's true place, ≈ 104 cm). *Rejected:* 150 cm (every desk behind a panel
   hidden; the "cubicle wall" read and nothing else), 100 cm (desk tops visible, the panels no longer walls).
3. **Every stop's data fetched at the press**, under § 4's rule — the fetching writes nothing a screen reads
   as a fact, so no layout refetch — the stops in parallel, the tilesets and (on the lobby) the theme modules
   with the maps, the cost stated as the building's rooms once per page with the default's bytes answered per
   room, a map re-saved since the page loaded held at the page's version for the ride, and the earliest
   choice at 2.25 s. *Rejected:* the chosen floor's at the choice, with the bare room until it answers —
   1.25 s against an unmeasured round trip; and revision 2's `GET /api/building` first — a scenery fetch
   that could state F17's failure.
4. **One ceiling height, 222 cm, for the floor view and the bare room** (m-j): § 12's *Office view* row
   gives up its chosen 280 for the height *Door-view scale* derives, so R1's swap from the shell to the floor
   moves no ceiling and no light. *Rejected:* two heights (the swap visibly drops the ceiling); the floor
   view at 280 (the band is 160 px, "about 2.2 m", and the walls would not meet it).

## 10. The random-scene check, as run

The design round's script was a scratch check, never committed (`rowmc.mjs`, the shape of r2's `mc.mjs`); its construction
is § 3.3's: a room of 60–223 × 30–87 cells, half the time a second room beside it with a two-cell gap and
its own depth; up to ten runs of `wall` cells, vertical or horizontal, classified per cell by the footprint
rule; up to six desks at UNALIGNED `y` (slabs disjoint, snapped to rows as the view snaps them), each a top
and a monitor; up to eight standing pieces on row boundaries; the footprints' far walls and side-wall
strips; 300 rays per scene from the eye through random points of the 110 × 200 doorway; for each ray, every
pair of bodies it meets at different depths must have the nearer painted later. Two seeds, 800 scenes each:

```
seed 12345: 800 scenes, 300565 bodies, 240000 rays, 291262 (near, far) pairs owed an order
  row         violations       0  in 0 scenes
  nearest     violations       4  in 4 scenes  — desk-top over piece ×4
  piecesLast  violations    3197  in 162 scenes  — piece over desk-top ×1669; piece over side-wall ×736; piece over partition ×665; …
  rowNoX      violations     896  in 152 scenes  — partition over partition ×863; wall over wall ×32; …
  pieceInRow  violations     711  in 164 scenes  — far-wall over wall ×600; piece over desk-top ×64; …
seed 777:   800 scenes, 291907 bodies, 240000 rays, 291544 (near, far) pairs owed an order
  row         violations       0  in 0 scenes
  nearest     violations       5  in 4 scenes  — desk-top over piece ×5
  piecesLast  violations    3746  in 166 scenes
  rowNoX      violations     927  in 153 scenes
  pieceInRow  violations     669  in 174 scenes
```

`row` is § 3.3's order; `nearest` the nearest-point sort over the same bodies; `piecesLast` every billboard
after every other body (the RED's mutation); `rowNoX` the row order with no within-row rule; `pieceInRow`
billboards keyed to their own cell's row rather than the boundary. Before the two fixes § 3.3 names the
first run printed `row violations 310 in 158 scenes` (seed 12345; monitors before their tops) and then
`43 in 8 scenes` (two desks at different `y` in one row) — the check discriminated before it passed
(canon #9). What it does not measure: intersecting slabs (§ 7), and anything about the rendered look.

**The check as committed (slice 2; round 3's m6).** The scratch script above skipped every pair a ray meets at ONE
depth, which is how round 3's M1 — a piece on the last row painted under the far wall it stands against — passed it.
It is now the *order* leg of `tools/design/door-view.selftest.mjs`, and it differs from the scratch check in three ways:
it checks the BUILD (each random floor is a map built by `doorView()` itself, over the shipped tileset, so the bodies
are the model's and not a restatement of it); some floors have their tile layers dragged −7 to +7 px off the grid and a
quarter of the pieces stand on the last row (M3's and M1's routes); and a TIE is owed an order — the body on the eye's
side of the plane two touching bodies share, a piece over the far wall it stands on, and between two pieces on one foot
line the later in tile order — with any tie no rule names counted and printed. Rays and pieces are re-derived per run
by the command, so no figure is written here:

```
node tools/design/door-view.selftest.mjs --order 800 300 12345 777    # the scratch check's two seeds and size
```

Its controls are planted REDs, each a mirror of the module tree with one defect, required to red the leg: *pieces
last*; the revision-3 key (no tier — M1, red by `far-wall over billboard (a tie)`); no snap (M3); a monitor at its own
distance. The ray cast samples a sliver too thin for three comparator terms — the straddle clause, the inner-edge
distance and a desk's distance ahead of its rank — so each is held by a fixture reading instead (`alpha`'s partition
in a monitor's row, `echo`'s straddling cell and desk), each with its own planted RED, and the rounding and flooring
snaps by `bravo`'s drags. CI runs a smaller default (`node tools/design/door-view.selftest.mjs`) with the same controls.

## 11. Open question — not decided here

**Doors open on arrival.** The outside read noted that a real elevator opens its doors at arrival, not at
the choice, and that moving the destination's door view onto the DESTINATION page — which holds its own
floor's maps, tilesets and theme — would delete this design's whole cross-floor prefetch (§ 4's sequence,
the lobby's `TilesetLoader` and `loadThemes()`, the memo's cross-floor keys, R1, S4 and S8) and leave the
model reading what the page already draws. It changes § 4.5's ride choreography (`chosen` and `leaving`
paint the chosen floor on the DEPARTING page today, and the arrival carries a snapshot of that cab), so it
is the operator's decision and not a fold of this review; the seat raises it separately. Nothing in this
revision forecloses it: `doorView()` and `drawFloorView()` are the same on either page.

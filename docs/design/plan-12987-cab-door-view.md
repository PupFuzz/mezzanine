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
cells merged into runs, its standing pieces by `kind` (`scene.js` `mapTiles()`, `tileRegions()`,
`floor-layout.js` `mapDesks()`) — the band's elevator and its threshold (`scene.js` `backWall()`,
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
| each room's map and the hallway's | `Building#room(id).held.map` where the room is not mapless (§ 5); `placeRooms(rooms, extents, hallway)` → origins, `extent`, `draw_order` | the **floor plane**: one slab the size of the extent, Z from 0 to `extent.height · SCALE`, X from `(threshold.x − extent.x − extent.width) · SCALE` on the rider's left to `(threshold.x − extent.x) · SCALE` = 74 px · SCALE ≈ 103 cm on the right; a gap between side-by-side rooms (`ROOM_GAP_PX`) is plain floor |
| each room's **footprint** (`placeRooms()`'s `footprint`, never the union extent) | arithmetic | the room's **walls**, full height (222 cm): the far wall along the footprint's bottom edge, the side walls along its left and right edges — each room its own, so two rooms of different heights or an L-shaped plan get walls where their rooms end and nowhere else; two rooms whose footprints share an edge contribute one coincident segment, drawn once (identical boxes deduped). **A footprint's TOP edge is the band the rider stands in**, behind the eye, and is never drawn |
| `wall` cells, merged | `mapTiles()` → `tileRegions()` (the scene's one region pass) → rects | a **box** per rect, the rect's own footprint (a cell's 8 px is 11 cm thick): a **wall** (222 cm) where the rect's long side lies on its room's footprint edge, else a **partition** (§ 12's *Partition height*, 120 cm). **A run whose long side lies on the footprint's top edge is the near wall** — the author outlining the room along the band — and is not drawn, as the footprint's top edge is not: drawn, it would stand at `Z = 0` across the doorway and fill it |
| `accent` cells, merged | the same pass | flat **accent regions** on the floor plane, in the theme's accent colour |
| each room's `desks` objects | `mapDesks(map)`; `slotOrigin()`; **`deskSlab(box)`** — the desk's own rect, `{ x: ART_W / 2 − 90, y: H − 100, w: 180, h: 60 }` in the furniture box anchored at the slot's bottom-left — which `deskRects()` calls for its `desk-sprite` (today it computes that rect inline; slice 1 names it so the two readers are one). `deskRects()`'s `character` argument is the creature tree's `SCENE_W × SCENE_H`, which only the character rects read and the lobby does not load; the door view never calls `deskRects()` | an **empty desk** per slot: a top 180 px wide and 60 px deep at the slab's rect, 76 cm high, four legs, a monitor's back on its right third (to 147 cm) and a pushed-in chair — prisms in the theme's `PALETTE.view` colours. No character, chair colour or seat key: § 6.3's scenery rule |
| standing kinds — `theme.kinds` less `PLANE_KINDS`, as `buildScene()` derives `standing` (scene.js's `const standing = theme.kinds.filter(...)`) | `mapTiles()` cells with `kind`; **`sceneryInput(cell, seed)`** — the document input `buildScene()` builds inline today (`{ kind, w, h, seed, index }`, plus `RESTROOM_PLATES` for the restroom door), hoisted so both callers hand the theme one shape; the theme's `scenery()` document from it | a **billboard**: the theme's own drawing of the piece, standing on the cell's floor line at `(X, Z)` of the cell's bottom edge, `w · SCALE` wide and `h · SCALE` tall, shown as an `<image href="data:…">` exactly as the floor shows it (§ 10.6 item 2's asset shape) |
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
| `slotOrigin(slot, box)` | `scene.js` | `floor/floor-layout.js` | none |
| `floorTheme()`, `unshippedThemeNotice()` | `scene.js` | `floor/floor-theme.js` (new) | none |
| `mapTiles()`, `tileLook()`, `tileRegions()`, `safeEmbedded()`, `RESTROOM_DOOR`, `RESTROOM_PLATES`, and the new `sceneryInput()` | `scene.js` | `floor/map-tiles.js` (new) | `floor-layout.js`, `tileset.js` (no imports of its own) |
| `ART_W` and the new `deskSlab(box)` | `desk-layout.js` (`deskRects()`'s inline `desk`) | `floor/desk-geometry.js` (new) | none |
| `svgUri` | `floor/painter.js` | `floor/cab-paint.js` | none static (its one dynamic-import site is the declared loader) |

`scene.js` and `desk-layout.js` import each moved name back and re-export it. Tests anchored on the moved
SOURCE TEXT are retargeted in the same slice, named by the grep that finds them
(`grep -rln "BAND_H = \|export const svgUri" server/tests`): `TheFloorDrawsItsFrameTest`'s *the band* RED
and its `BAND_H` regex over `scene.js`'s text read `floor-layout.js`'s; `TheNewDeskKeepsEveryLeafTest`'s
`svgUri` mutations (lines 376–377) read `cab-paint.js`'s line. **The boundary is checked in slice 1, not
discovered in slice 3**: that test gains `DOOR_VIEW_ROOTS` — the modules the view will import
(`floor/floor-layout.js`, `floor/floor-theme.js`, `floor/map-tiles.js`, `floor/tileset.js`,
`floor/desk-geometry.js`, `floor/cab-paint.js`) — each walked by `graphDefects()` as a root beside
`lobby/main.js`, with the same defects (an edge into the log or the set, an unfollowable specifier). Slice 2
adds `floor/door-view.js` to the roots; slice 3's `lobby-screen.js` import then adds no edge the walk has
not already passed. Its RED: a planted `import { LOOP_FPS } from '../wire/animation-set.js'` in
`map-tiles.js` reds in slice 1.

### 3.3 The projection, culling and draw order

- **One eye, one `at()`.** `drawFloorView()` uses the office's projection unchanged: `s = view / (view + Z)`,
  `x = box.x + box.w / 2 + X · s · kx`, `y = box.y + (door.h − eye + (eye − Y) · s) · ky`. The vanishing point
  is the cab's, so the room and the cab are one view from one point (§ 12's *Office view* reasoning, kept).
- **Depth limit: the room's own far wall.** Nothing exists past `Z = extent.height · SCALE` (9.7 m on the
  default map, 11.2 m on solo-agents), so no artificial horizon is needed; the far wall closes the view.
- **Culled before it is drawn:** every box and billboard is projected as its eight (four) corners; one
  whose projected bounding rect lies wholly outside the doorway box is skipped. A face is drawn only if it
  faces the eye: a box's near face always (every box has `Z ≥ 0`, the eye is at `Z = −120`); its top only if
  its height is under `CAB.eye` (a partition's is, a wall's is not); its left or right end only when the box
  lies wholly to one side of `X = 0`. Partitions beside the doorway at `Z` under ~0.8 m are cut by the door's
  own frame, as a doorway hides what stands beside it (§ 3.5's readings show where that bites).
- **Draw order — the separating-plane rule (M2; replaces revision 1's nearest-point sort, which was
  wrong).** The bodies are axis-aligned boxes in the rider's frame — wall and partition rects, desk prisms,
  and billboards as boxes of zero depth on their foot line — and the eye is in front of every one of them
  (`Z_eye = −120 < 0 ≤ Z_min`) and above every partition top. First the **pre-split** that makes them
  pairwise disjoint: a wall rect intersecting a desk slab's footprint is cut at the slab's bounds and the
  part inside is not drawn (the desk stands on it — the floor page draws the slab over the run); a billboard
  whose foot line lies inside a wall rect is drawn after that rect (the plane's runs are under every piece on
  the floor page too). Then, for every pair whose projected bounding rects overlap, **each axis that separates
  the two gives a verdict — the box on the eye's side of the separating plane is drawn later, and none where
  the eye lies between them on that axis**; one verdict is the pair's order, two agreeing verdicts are the
  same order, and no verdict means no ray from the eye meets both. The pairs are the edges of a graph and
  the order is its topological sort (Kahn's). *Why it holds:* two disjoint boxes are separated on at least
  one axis; a plane through the gap has the eye on one side, every point of the farther box on the other,
  and any ray from the eye crossing the plane meets the nearer box first — the nearer box occludes, so it is
  painted later. *Why the two verdicts never disagree on a pair that overlaps in projection:* if A is nearer
  in X (between the eye's line and B) but B nearer in Z, every sight line to A is shallower than
  `max|X_A| / (Z_min,A + 120)` and every one to B steeper than `min|X_B| / (Z_max,B + 120)`, and the first is
  the smaller — their projections are disjoint, so the pair never reaches the rule. *What revision 1 got
  wrong:* nearest-point sorting puts a wall running along Z (nearest point `Z = 0`) after everything beside
  it; the separating plane between that wall and a plant at `Z = 7 m` standing just inside it is the X plane
  at the wall's face, with the eye on the plant's side — the plant is later. **The rule's residual, named:**
  a cycle in the pairwise graph is possible in principle for axis-aligned boxes; the model detects one
  (Kahn's leaves nodes unplaced), splits the cycle's longest box at the plane of the cycle's first edge and
  sorts again, bounded by the cell count, and reports a cycle that survives as the model's `defect` (the
  probe reds on it; the view draws the bare room). No cycle occurs on the three live maps or the fixtures.
- **Evidence (re-printed by the tool).** `tools/design/door-view-plan.mjs` now prints every pair overlapping
  in projection with its verdict, the cycle check, and every pair the nearest-point sort would reverse; on
  2026-11-08 over the three live maps it printed no cycle and no defect, and among the pairs it ordered —
  `wall@0,0 before plant@8,384 (X-separated; the eye on plant@8,384's side)` on the default map and
  `wall@0,384 before bookcase@8,416` on aimla — are the two the reviewer modelled as painted wrong by
  revision 1, each in its *nearest-point sort would reverse* list. (The tool projects without the doorway's
  frame, so it lists some pairs the paint culls; a culled pair needs no order.)
- **Inside the doorway always:** the whole view is drawn into `clippedTo()`'s group, as the office and the
  lobby are; the existing checks (`officeDefects`' clip leg, `seamDefects`) hold it.
- **Cost, derived rather than asserted.** Element count per paint = planes (1 + accent rects) + per-room
  walls (3 each) + wall rects × ≤ 4 faces + desks × ~11 + billboards; the pairwise pass is over the boxes'
  projected rects, quadratic in a count the tool prints per map (its *boxes* and *overlapping pairs* line —
  on the day, under thirty boxes and under a hundred and ten pairs on the heaviest live map), once per
  model, memoised (§ 4). The accent regions are the open term: `tileRegions()` merges a 63 %-accent floor
  (solo-agents) into row runs per region, and the probe (§ 6) records the count rather than this file
  guessing it. No per-frame work: the view is painted once per phase entry and once more only if its model
  arrives during the phase (§ 4).

### 3.4 The theme in the view

- **Palette.** The view's surfaces are painted by classes in `CAB_STYLE` from CSS custom properties the
  view's root group carries, set from the stop's theme — `--dv-wall-top`, `--dv-wall-bottom`,
  `--dv-skirting`, `--dv-floor`, `--dv-accent`, `--dv-lamplight` from the `PALETTE` rows every theme already
  has (`wall.top/bottom/skirting_top`, `floor.boards`, `accent.top`, `light.lamplight`), and `--dv-desk`,
  `--dv-desk-edge`, `--dv-monitor`, `--dv-chair`, `--dv-partition`, `--dv-partition-cap` from one new row,
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
in its header): it reads `resources/floor/tiles/floor-plane.tsx` for each gid's `kind`, merges wall cells
into rects the way `tileRegions()` does by rows and stacks them, classifies each WALL or PARTITION by § 3.2's
rule, and converts to the rider's frame with `SCALE = 200/144` and the seam at `x = 74`. It is a design-time
reader kept independent of the build's `door-view.js` so the two can be compared; it assumes a single-room
floor, which every floor on the sandbox is. One character is 32 px; `#` wall, `.` accent, `D` a desk slot's
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
| wall run `x0..8 y0..696` (long side on the footprint's left edge → a WALL, 222 cm) | | `X +92..+103 cm`, `Z 0..967 cm` | **a wall on the right**, 1 m off the rider's shoulder, from ~0.8 m (where the frustum's edge, `55 · (120 + Z) / 120`, first reaches 92 cm) to the far wall |
| desk #1's slab (`x 114..294`, `y 211..271`) | | `X −306..−56 cm`, `Z 293..376 cm` | **a desk ahead**, left of centre, its right half inside the frustum (half-width 189 cm at `Z = 293`) |
| desk #4's slab | | `X −306..−56 cm`, `Z 788..871 cm` | the next desk down the same column, whole (half-width 416 cm at `Z = 788`) |
| desks #2, #3, #5, #6 (`X ≤ −778 cm`) | | | outside the frustum at their depth — out of view, as a room's far side is from a doorway |
| `plant@8,384` (foot `Z 711 cm`, `X −19..+92 cm`) against the right wall | | | the pair M2 was found on: X-separated from the wall, the eye on the plant's side — **the plant is painted over the wall** |
| the floor | boards `#d9c7a5`, accent `#dcc196` on 17.9 % of cells | | the sola floor's colour: studio boards, with the PM corner's accent far right |

The operator's words — *a wall on the right side and the sola floor theme color, and desks in front of you*
— are what the default map's data projects to. (A note the derivation surfaces: the "wall on the right" is
the author's perimeter run of wall tiles along `x = 0`, AND the footprint's own left edge one tile behind
it — the rule draws both, the run 11 cm in front of the room's wall, which is a coherent wainscot and not a
defect.)

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
| wall run `x0..512 y376..384` (long side horizontal, not on a footprint edge → a PARTITION, 120 cm) | `X −608..+103 cm` at `Z 522..533 cm` | **the PM's cubicle wall in the distance**: 5.2 m ahead, crossing the whole view (half-width 294 cm there), a 120-cm panel with the room's far wall 4.5 m behind it |
| desk #3, reserved `pm` (slab `X −306..−56`, `Z 815..899 cm`) | behind the partition | the sight line over the panel's top from the eye at `(155 cm, Z −120)` reaches the desk at `155 − 35 · (815 + 120) / (522 + 120) ≈ 104 cm`: its top (76 cm) is hidden and the monitor's back (to ~147 cm) shows over the panel. A 150-cm panel would hide the desk entirely — § 12's *Partition height* row is where that is decided |
| wall run `x608..616 y376..696` (vertical → PARTITION) | `X −753..−742 cm`, `Z 522..967 cm` | the cubicle's far side, left, past the partition |
| `bookcase@8,416` (foot `Z 733 cm`) against the right wall run `x0..8 y384..696` | | M2's second pair: the bookcase later than the wall, by the same X verdict |
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
| wall run `x120..128 y104..400` and `y408..704` (vertical, interior → PARTITIONS) | `X −75..−64 cm`, `Z 144..978 cm` | **a cubicle wall** 0.7 m to the left, running from 1.4 m to the far end |
| wall run `x120..568 y96..104` and the cross run `x120..1904 y400..408` | `X −686..−64` at `Z 133`; `X −2542..−64` at `Z 556` | the cubicle's near end and the row's cross wall, their tops in view over the first |
| the footprint's left edge (no author tile there) | a WALL at `X +103 cm` | the aisle's right side, 1 m off |
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
model, and `null` for a `stopRow` gives the bare room in the house theme (§ 5) — **one builder for every
floor stop**, so `view` is never `null` for one.

**The memo (m4).** The screen holds `#doorViews = new Map()` keyed by the model's inputs that can change:
`${stop}|${rooms.map((r) => `${r}:${building.mapless(r) ? 'mapless' : building.room(r).held.map_version}`)}|${tilesetsHeld}|${theme}|${moduleHeld}`.
A hit answers the same object, so the repaint rule below compares identity and never re-derives; a miss
runs `doorView()` once (the region pass, the pairwise order and the billboard URIs are its cost, § 3.3).
The map is the screen's and is cleared when the ride ends (`arrived()` or Escape), so a page holds at most
the ride's stops.

**Where the stop's data comes from, on each page.** The floor page holds its own floor's maps (§ 4.4's
floor row), their tilesets (`#loadTilesets()` loads the target floor's only, floor-screen.js's reader) and
every theme module (`painter.js` `loadArt()`); the lobby holds the layout alone, no map, no tileset and the
house theme's palette only (`loadHousePalette()`). A ride to ANOTHER floor needs that floor's maps and
tilesets on either page, and its theme module on the lobby. So, **at the press (`opening`)**, the screen
runs one sequence on both pages (m5's corrections folded in):

1. `Building#fetchLayout()` — one `GET /api/building`, so the versions `#holds()` compares against are the
   server's now and not the page's load time: a `room.map` for a room the page does not render is ignored
   (`applyRoomMap`, D2 § 8.7), so without this a long-lived page would prefetch nothing for a floor whose map
   was re-saved since the page loaded and hold the old one. The residual: a re-save DURING the ride is still
   ignored for an unrendered room, so the view can be one revision behind for the ride's length only.
2. `Building#enterRooms()` for every stop's rooms — once per stop, the stops' calls in parallel
   (`Promise.all`), each serial inside as the primitive is — held by version, so a second ride on a page
   fetches nothing. **The cost, as a derivation, not a bound**: the building's rooms less the page's own
   floor's (§ 1's `installs` line prints them), each answered with its authored map or the shipped default
   (§ 1's byte figures); three rooms on the default are three answers of the same bytes, and this design
   does not deduplicate them (the API answers per room — a cost accepted, named in § 9 (3)).
3. The maps' tilesets through the page's `TilesetLoader` — `#loadTilesets()` generalised to take the rooms
   it loads for, called for every stop's rooms; the lobby constructs a loader of its own for it (m1).
4. On the lobby, every theme module the registry names, through `cab-paint.js`'s one dynamic-import site
   (`loadHousePalette()` generalised to `loadThemes()`, answering the registry and every module), so
   `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s walk still follows one declared loader — its
   needle text and its walk change with it (slice 2) to follow every `THEMES` entry, a strictly wider check
   than today's one module.

**Why the press and not the choice.** The buttons are inert until `shut`, so the earliest choice is
`opening` (1,000 ms, `RIDE_DOOR_MS`) + `entering` (a fade of `1000 / LOOP_FPS` = 250 ms + 1,000 ms) =
**2.25 s after the press** (`ridePhase()`; `LOOP_FPS` = 4), and the chosen floor's doors open 1.25 s after
the choice (`chosen`: a fade and the door motion). Fetching at the choice leaves 1.25 s for the map, its
tileset and (on the lobby) the module, in series; fetching at the press leaves 3.5 s. § 12's *Cab hold* row
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
client CHECKS nothing of it again: `mapDesks()`, `mapTiles()` and `tileRegions()` read what the floor page
already reads, and a map the floor page draws the door view draws. What neither end can verify locally is
named here: the operator's READING of a projection — whether a 120-cm partition and a door-derived scale
make the room the operator pictured — which only the sandbox shows (§ 6's by-hand row).

## 5. A floor whose data the client does not hold — one predicate, and the bare room

**One predicate (m2).** The floor page's own: a room is **mapless** when it was never answered or its last
request failed — `state === null || state.failure !== null` (floor-screen.js's `const mapless = …` in its
room loop) — and under a failure `Building#room().held` is the F16 EXTENT, not a map to draw
(`#fetchRoom()`: *what was held stays — as the extent, under the failure*). Slice 2 hoists that expression
into `Building#mapless(installId)` and the floor page reads it from there, so the two readers are one. **A
floor's view is BARE when any of its rooms is mapless, when any tileset a held map names is not in the
loader's `held` (`mapTiles()` answers `kind: null` for a tile whose set is absent, which would draw a floor
with no walls, accents or pieces — a floor drawn with confidence from half its data), or when the stop's
theme module is not held.** One definition, per floor, not per room: the view is one picture, and a floor
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
  every frame): `stopRow` is `null`.

**The bare room** is the office view's shell — the floor plane, far and side walls, ceiling and lights of
`OFFICE`'s size (520 × 900 × 280 cm, the shell `drawOfficeView()` draws today, kept for exactly this) — in
the stop's theme palette where the module is held (R1 waiting on a map, R2) and in the sheet's tokens where
it is not (R3, R4 in the house theme — § 3.4's fallback), with **no desk, no wall run, no accent and no
piece**. When R1's data arrives the repaint rule in § 4 replaces it with the floor; R2's and R3's stay bare
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
  culling reading); one vertical `wall` run RIGHT of the seam at `x 40..48` (`X ≈ +36..+47 cm`); **one
  `plant` tile (80 × 128 px) standing just inside that run at `x 48, y 496..504`** (foot `Z ≈ 700 cm`, `X −75..+36 cm` —
  the pair M2 was found on, overlapping the run in projection, X-separated with the eye on the plant's
  side); no accent.
- **B** — key `bravo`, `theme: "cottage"`: one slot far LEFT (`x 900`, culled), a `wall` run that is a
  horizontal interior PARTITION at `y 400` from `x 0..600` (crossing `X = 0` at `Z ≈ 556 cm`; its short side
  touches `x = 0`, which is what tells *lies on* from *touches* in RED 5), the floor's cells all `accent`, one
  `bookcase` tile at `x 300, y 80`, and a `wall` run along the top row `x 0..400, y 0..8` (the near wall:
  not drawn).
- **C** — key `charlie`, two rooms unplanned (`charlie` and `charlie-2`, side by side with `ROOM_GAP_PX`
  between), the first 300 px tall and the second 500 px: per-room walls at each footprint's own far edge,
  the gap plain floor with a side wall on each side of it, and a map for `charlie-2` that names a second
  tileset the loader does not hold.

The probe drives `wire/ride.js`'s `Ride` with the floor page's constructor arguments from stop `alpha`:
`press → entered → shut → choose(<stop>)`, reading `sceneRide()` at each step and resolving `doorView()`
with a scripted `Building`, `TilesetLoader` and themes; then paints with `paintCab()` (the module's and the
painter's, as the `beyond` leg does) and reads back, under `.cab-beyond`:

| Scenario | Readings |
|---|---|
| **S1** `entering` (doors on `alpha`, everything held) | `scene.ride.stop` = `'alpha'`; the root group's `--dv-floor` / `--dv-wall-top` = studio's `#d9c7a5` / `#eef0e0`; wall boxes: one, every projected `x` of its near face RIGHT of the doorway's centre line, its top ABOVE the horizon `y = box.y + (door.h − eye) · ky`; desk prisms: one (the second culled), LEFT of centre, its near face's projected `x` range equal to the probe's own `at()` over the slab rect; **draw order: the plant's `<image>` follows every face of the wall in document order**; no accent polygon |
| **S2** `chosen('bravo')` (everything held) | `stop` = `'bravo'`; cottage's `#cbd3b3` / `#d7e6ee`; wall boxes: one partition, its near face spanning the centre line, its top BELOW the horizon; the top-row run drawn nowhere (no box with `Z` under a cell's depth); desk prisms: zero; accent polygons ≥ 1; one `<image>` whose `href` equals `svgUri(cottage.scenery(sceneryInput(cell, 'bravo')))` recomputed by the probe |
| **S3** `chosen('bravo')` with `bravo`'s map scripted to 503 (R2) | `view.bare` = `true`, reason `mapless`; cottage custom properties on the root; no wall, desk, accent or `<image>`; the shell's floor, walls and ceiling present |
| **S4** `chosen('bravo')` with `bravo`'s map answering 300 ms after `chosen` began (R1) | the first paint as S3 with no failure; after the answer, exactly one further paint under `.cab-beyond` in that phase, S2's readings, and the leaves' animations the same objects as before the repaint (the existing *follow each phase without restarting* reading) |
| **S5** `chosen('bravo')` with the cottage module absent from `themes` (R3) | `bare`, reason `theme`; the root group carries NO `style`; the shell drawn in the sheet's classes |
| **S6** `chosen('charlie')` | two far walls at different `Z` (the two footprints' heights), four side walls, the gap's floor polygon between them; `charlie-2`'s missing tileset ⇒ `bare`, reason `tileset`, with the whole floor bare (not `charlie` alone) |
| **S7** `chosen('zulu')`, a key the stops do not offer (R4) | `bare`, reason `stop`; house-theme-less shell (no `style`) |
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
| `floor/door-view.js`: a run touching a footprint edge drawn as a WALL (`touches` for *long side lies on*) | S2 | the PM's cubicle wall as a 2.2-m wall hiding the PM's desk and monitor |
| `floor/door-view.js`: `bare` from `held.map === null` alone (the failure and the tileset ignored) | S3, S6 | a stale map drawn with confidence under F16's failure (R2); a floor with no walls where a tileset is missing |
| `floor/door-view.js`: the order by nearest point (`sort((a, b) => b.near.z − a.near.z)`) in place of the pairwise verdicts | S1 | the wall over the plant (M2) |
| `floor/door-view.js`: the top-row run drawn (its `y === 0` exclusion dropped) | S2 | a 222-cm slab across the doorway |
| `floor/door-view.js`: walls from the union extent in place of each footprint | S6 | walls where no room ends on a two-height floor |
| `floor/cab-paint.js`: `BEYOND.office` drawing `drawOfficeView` (the model ignored) | S1 | today's one office |
| `floor/cab-paint.js`: `paintCab()` reading `globalThis.location.pathname` to pick the model | S2 | the inference § 4.5 forbids |
| `floor/cab-paint.js`: the billboard's `href` from `scenery()` of the HOUSE theme | S2 | studio furniture on the cottage floor |
| `floor/cab-paint.js`: the root group's `style` set when the module is absent (`--dv-floor:undefined`) | S5 | black surfaces on a failed theme |
| `wire/ride-page.js`: the repaint-on-arrival case removed | S4 | R1's bare room never replaced while the doors open |
| `wire/ride-page.js`: the repaint on EVERY frame | S4 | the leaves restarted each frame |
| `floor/map-tiles.js`: `import { LOOP_FPS } from '../wire/animation-set.js'` planted (slice 1) | the lobby graph test's `DOOR_VIEW_ROOTS` walk | the lobby reaching the set through the view |

And in `TheFloorsRideIsNavigationTest`: `scene.ride.stop` equals the stop the doors open on in every phase
of `RIDES` (RED: `stop: ride.current`), beside its `beyond` check. In `tools/floor-themes/selftest.mjs`:
every theme's `PALETTE.view` carries the house theme's key set (its drift leg — seen to red before the
cottage row lands). § 12's new rows are pinned by `test_green_section_12s_door_view_is_door_view`
(`SCALE`, the partition height, the room height derived from `BAND_H`).

**Seen to fail first (canon #9).** Slice 3's PR lands the probe's green readings and the RED table in one
change; the coder runs the RED table with the production line in place of each mutation and records the
red in the PR's audit trail, as the lobby view's PR did. Slice 1's graph RED is run the same way in slice 1.

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
- **A cycle in the pairwise order** (§ 3.3's residual): detected, split once, and reported as a defect the
  probe reds on if it survives; the tool prints none on the live maps.
- **A huge map**: `tileRegions()` is already run per paint on the floor page over the same cells; the door
  view runs it once per model, memoised. The pairwise pass is quadratic in the box count the tool prints.
  Not defended further; the probe records the counts.
- **Reduced motion**: every phase is a cut and the view is painted at entry as today. Nothing new.
- **A browser without `color-mix()`**: every new mixed rule is in `MIXED`, so `unmixed()` derives its
  fallback and the existing check holds it.

## 8. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last, each with its docs in the same round
(`FLOOR.md`'s amendments are in this commit; the coder updates the Appendix B row's marker and the AT list
as each lands).

1. **Hoist the readers the view needs out of the modules the lobby page cannot load** (no behaviour
   change; every existing test green). The table in § 3.2 (*Where each reader lives*) is the list:
   `ELEVATOR`, `BAND_H`, `thresholdOf()`, `slotOrigin()` into `floor/floor-layout.js`; `floorTheme()` and
   `unshippedThemeNotice()` into `floor/floor-theme.js`; `mapTiles()`, `tileLook()`, `tileRegions()`,
   `safeEmbedded()`, `RESTROOM_DOOR`, `RESTROOM_PLATES` and the new `sceneryInput()` (which `buildScene()`
   now calls) into `floor/map-tiles.js`; `ART_W` and the new `deskSlab()` (which `deskRects()` now calls)
   into `floor/desk-geometry.js`; `svgUri` into `cab-paint.js`. Each name re-exported from its old module.
   Retarget `TheFloorDrawsItsFrameTest`'s `BAND_H` anchors and `TheNewDeskKeepsEveryLeafTest`'s `svgUri`
   anchors (§ 3.2 names the grep). Add `DOOR_VIEW_ROOTS` to `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`
   and its planted-import RED; run it.
2. **The model.** `floor/door-view.js` `doorView(stopRow, building, tilesets, themes)` → the frozen model
   of § 3.2 (cm, rider's frame, the pre-split and pairwise order of § 3.3, `bare` and its reason per § 5),
   with a `node` selftest over the fixture floors of § 6 asserting the cm readings in § 3.5's shape and the
   order on fixture A's wall/plant pair; `Building#mapless()` hoisted from the floor page's expression;
   `PALETTE.view` in both themes and the selftest's drift leg; `wire/ride.js` `sceneRide()` adds `stop` (its
   RED in `TheFloorsRideIsNavigationTest`); `cab-paint.js` `loadThemes()` beside `loadHousePalette()` and
   the lobby walk's needles updated to follow every `THEMES` entry; `door-view.js` added to `DOOR_VIEW_ROOTS`.
3. **The paint and the wiring.** `cab-paint.js` `drawFloorView()` (projection, culling, the sorted order,
   the bare room, the carriers with their `var()` fallbacks and `MIXED` rows); `BEYOND.office` → it;
   `drawOfficeView()` reduced to the shell the bare room draws (its desks, plant, windows and sun gone —
   § 10.6 item 3). **Retarget the office checks (m8)**: `TheElevatorIsAControlOnTheDrawingTest`'s REDs that
   mutate `drawOfficeView()`'s desk and plant literals (*a character in the office*, *a name in the office*,
   *the office moving* anchor on `desk(-10, 130, 240, 320);`; *an inline hex in the office* on
   `face('office-floor', pts(`) move their anchors onto `drawFloorView()`'s equivalent lines and keep their
   readers; `officeDefects`' structural legs (found, clipped, census, no inline colour, no motion) read the
   floor view in every scenario of § 6; `test_the_cab_doors_open_onto_the_office` becomes
   `…_onto_the_floor` over S1; `test_green_section_12s_office_view_is_office` keeps pinning `OFFICE`'s
   figures, now the bare room's. The floor screen attaches `view` and holds the memo; both screens run § 4's
   press sequence (the lobby with its own `TilesetLoader` and `loadThemes()`); `ride-page.js`'s
   repaint-on-arrival case. The probe's door-view leg, the scenario table and the RED table (§ 6),
   `test_green_section_12s_door_view_is_door_view`, the browser tool's `door-view` row. Appendix B row 44
   gets its landing marker. Title: `… (closes card#12987)`.

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
3. **Every stop's data fetched at the press**, with § 4's corrections: one `GET /api/building` first so the
   versions are current, the stops in parallel, the tilesets and (on the lobby) the theme modules with the
   maps, the cost stated as the building's rooms once per page with the default's bytes answered per room,
   and the earliest choice at 2.25 s. *Rejected:* the chosen floor's at the choice, with the bare room until
   it answers — 1.25 s against an unmeasured round trip.

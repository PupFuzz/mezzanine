# card#12987 — design and build plan: the cab's open doors show the floor they open on

**What this file is.** The design and the build plan for one card, in the shape
`plan-11818-reporter-outdated.md` set: written for the coder who implements it and the reviewer who
attacks it. It owns the *derivation*, the *sequence* and the *acceptance list as observables*; it owns no
rule. Every rule lives in `FLOOR.md`, which this commit amends in the same places the lobby view
(card#12113) amended: § 4.5's ride bullet, § 4.4's route table, § 12's figures, § 13 row 115, § 14 items 38
and 48, Appendix B row 44 (planned, no landing marker), AT-D3-26's red list. A reader who finds the two
disagreeing trusts `FLOOR.md` and fixes this file. When the build has landed this file is history.

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
and `FLOOR.md § 4.5` says so in its own words: *"⚠ The office is one office and the lobby view is one lobby,
whatever the floor or the layout: both are scenery and neither reads a floor's theme (§ 14 item 38 is the
follow-up that would let a theme draw one)."* The only thing the cab already does per stop is CHOOSE between
two pictures: `wire/ride.js` `sceneRide()` declares `beyond: stop === null ? 'lobby' : 'office'` from the stop
the doors open on. The ride therefore already knows WHICH stop; nothing downstream of it is told.

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
cd server && php artisan tinker --execute='foreach (App\Floor\Floors::all() as $r) echo $r->install_id, " map_version=", $r->map_version, "\n";'
# aimla map_version=1   solo-agents map_version=3
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
2. **A new headless module, `floor/door-view.js`, derives the MODEL** — `doorView(floor, held)` → the room in
   centimetres relative to the cab's threshold, from the stop's plaque row (`lobby/building-model.js`
   `plates()`: its rooms, `hallway`, `theme`), the maps and tileset the client holds (`wire/building.js`
   `Building`, `floor/tileset.js` `TilesetLoader`) and the theme module's `PALETTE`. Pure, DOM-free,
   driven under `node` like `floor-layout.js` is. The screen that owns the ride attaches the model to the
   scene's ride: `scene.ride = { ...sceneRide(now), view: doorView(stopRow, held) }` — on the floor page in
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
*Back-wall band height* row already states — derived, not chosen.

### 3.2 What is read, and what each becomes

The floor is composed exactly as the floor page composes it — the same calls, so there is one answer to
*where is this room*:

| Read from | By | Becomes, in the view |
|---|---|---|
| the stop's plaque row: `rooms[]` (`install_id`, `form`, `origin?`), `hallway`, `theme` | `plates()` over the layout the client holds — the same list the cab's buttons read | the floor to compose; the theme to colour it |
| each room's map and the hallway's | `Building#room(id).held.map`; `placeRooms(rooms, extents, hallway)` → origins, `extent`, `draw_order` | the **floor plane**: one slab the size of the extent, Z from 0 to `extent.height · SCALE`, X from `(threshold.x − extent.x − extent.width) · SCALE` on the rider's left to `(threshold.x − extent.x) · SCALE` = 74 px · SCALE ≈ 103 cm on the right; a gap between side-by-side rooms (`ROOM_GAP_PX`) is plain floor |
| the extent's four edges | arithmetic | the room's **walls**, full height (222 cm): the far wall at `Z = depth`, the side walls at the extent's left and right x; the near wall is the band the rider stands in, behind the eye, and is not drawn |
| `wall` cells, merged | `mapTiles()` → `tileRegions()` (the scene's one region pass) → rects | a **box** per rect, the rect's own footprint (a cell's 8 px is 11 cm thick): a **wall** (222 cm) where the rect's long side lies on an extent edge, else a **partition** (§ 12's *Partition height*, 120 cm) |
| `accent` cells, merged | the same pass | flat **accent regions** on the floor plane, in the theme's accent colour |
| each room's `desks` objects | `mapDesks(map)`; `slotOrigin()`; `deskRects(box).desk-sprite` = `{ x: 18, y: H − 100, w: 180, h: 60 }` in the furniture box anchored at the slot's bottom-left | an **empty desk** per slot: a top 180 px wide and 60 px deep at the sprite's rect, 76 cm high, four legs, a monitor's back on its right third and a pushed-in chair — prisms in the theme's `PALETTE.view` colours. No character, chair colour or seat key: § 6.3's scenery rule |
| standing kinds (every `KINDS` member but `wall`/`accent`) | `mapTiles()` cells with `kind`; the theme's `scenery({kind, w, h, seed, index})` document — the same input `buildScene()` hands it | a **billboard**: the theme's own drawing of the piece, standing on the cell's floor line at `(X, Z)` of the cell's bottom edge, `w · SCALE` wide and `h · SCALE` tall, shown as an `<image href="data:…">` exactly as the floor shows it (§ 10.6 item 2's asset shape) |
| the theme | `floorTheme(floor, registry)` (house theme for `null`; house theme under F23's notice for an unshipped name); the module's `PALETTE` | the view's colour carriers (§ 3.4) |

Walls are read from the walls' cells and never from the extent's "F16 mapless" rect; a room with no map held
contributes no plane and no furniture (§ 5).

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
- **Draw order: the painter's algorithm over non-intersecting boxes** — farthest NEAREST point first (a
  box's nearest point to the eye, by `Z` then by `|X|`), floor plane and accents first, the far wall next,
  then boxes and billboards in that order. Desk slots never intersect (F21 refuses the map); walls and
  furniture are disjoint cells; so the known failure of the painter's algorithm (cyclic overlap of
  intersecting bodies) has no population here. Two boxes with one nearest point are ordered by `|X|`
  descending.
- **Inside the doorway always:** the whole view is drawn into `clippedTo()`'s group, as the office and the
  lobby are; the existing checks (`officeDefects`' clip leg, `seamDefects`) hold it.
- **Cost, derived rather than asserted.** Element count per paint = planes (1 + accent rects) + far and side
  walls (3) + wall rects × ≤ 4 faces + desks × ~11 + billboards. Over the three live maps the merged wall
  rects number 5 (aimla), 15 (solo-agents) and 5 (default) — the command in § 3.5 prints them — so the
  heaviest live floor paints well under a few hundred nodes, once per phase entry. The accent regions are the
  open term: `tileRegions()` merges a 63 %-accent floor (solo-agents) into row runs per region, and the probe
  (§ 6) records the count rather than this file guessing it. No per-frame work: the view is painted once per
  phase entry and once more only if its model arrives during the phase (§ 4).

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
  that regex).
- **Furniture.** The standing pieces are the theme's own `scenery()` documents, so the cottage floor shows
  the cottage's rattan armchair and baskets and the studio floor its own — the strongest read of "the cottage
  theme" for no new drawing code. Their URIs are made by the one `svgUri` (hoisted from `painter.js` to
  `cab-paint.js`, which the painter re-exports) and cached per ride, keyed on the document's inputs as the
  painter's cache is (§ 10.6 item 2's rule). **Stated simplification:** a billboard is the floor's drawing of
  the piece, which faces the floor page's viewer; from the cab the rider is behind it, and the view shows it
  facing the rider anyway — the same licence § 4.5 took for the lobby view's bare side walls (§ 14 item
  39(12)), listed in § 14 item 48 for the operator to reverse.
- **What is not drawn, by rule.** No window and no daylight: the band's windows are behind the eye, and
  § 10.6 item 3 forbids a static light that says what time it is. No character, nameplate, screen text,
  badge, chip or word (§ 6.3): the desks are empty and the monitors are seen from behind. No A17 sky. No
  motion. The ceiling keeps the office view's lights, scenery in the sheet's tokens.

### 3.5 The three examples, verified against the live maps

The plans below are printed by a script over the live store's maps and the shipped default
(`php artisan tinker` dumps `Floors::all()`'s `map` per room; the script reads `resources/floor/tiles/floor-plane.tsx`
for each gid's `kind`, merges wall cells into rects the way `tileRegions()` does by rows and stacks them, and
converts to the rider's frame with `SCALE = 200/144` and the seam at `x = 74`). One character is 32 px;
`#` wall, `.` accent, `D` a desk slot's outline, `P` the reserved desk, letters standing kinds, `E` the
threshold; the plans are ABRIDGED to the rows that carry a wall, a desk edge or the threshold (the tool
prints every row). The cm columns are what the rider's frame makes of them: `X` positive to the RIGHT. The
tool is `tools/design/door-view-plan.mjs` — `node tools/design/door-view-plan.mjs <map.tmj>` after dumping a
room's map with the `tinker` line in its header — a design-time reader kept independent of the build's
`door-view.js` so the two can be compared; it classifies each run WALL or PARTITION by § 3.2's rule.

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
| wall run `x0..8 y0..696` (long side on the extent's left edge → a WALL, 222 cm) | | `X +92..+103 cm`, `Z 0..967 cm` | **a wall on the right**, 1 m off the rider's shoulder, from ~0.8 m (where the frustum's edge, `55 · (120 + Z) / 120`, first reaches 92 cm) to the far wall |
| desk #1's sprite (`x 114..294`, `y 211..271`) | | `X −306..−56 cm`, `Z 293..376 cm` | **a desk ahead**, left of centre, its right half inside the frustum (half-width 189 cm at `Z = 293`) |
| desk #4's sprite | | `X −306..−56 cm`, `Z 788..871 cm` | the next desk down the same column, whole (half-width 416 cm at `Z = 788`) |
| desks #2, #3, #5, #6 (`X ≤ −778 cm`) | | | outside the frustum at their depth — out of view, as a room's far side is from a doorway |
| the floor | boards `#d9c7a5`, accent `#dcc196` on 17.9 % of cells | | the sola floor's colour: studio boards, with the PM corner's accent far right |

The operator's words — *a wall on the right side and the sola floor theme color, and desks in front of you*
— are what the default map's data projects to. (A note the derivation surfaces: the "wall on the right" is
the author's perimeter run of wall tiles along `x = 0`, AND the extent's own left edge one tile behind it —
the rule draws both, the run 11 cm in front of the room's wall, which is a coherent wainscot and not a
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
| wall run `x0..512 y376..384` (long side horizontal, not on an edge → a PARTITION, 120 cm) | `X −608..+103 cm` at `Z 522..533 cm` | **the PM's cubicle wall in the distance**: 5.2 m ahead, crossing the whole view (half-width 294 cm there), a 120-cm panel with the room's far wall 4.5 m behind it |
| desk #3, reserved `pm` (sprite `X −306..−56`, `Z 815..899 cm`) | behind the partition | its top (76 cm) is under the sight line over a 120-cm panel from a 155-cm eye (`155 − 35 · 815 / 522 ≈ 100 cm` at the desk); the monitor's back (to ~147 cm) shows over it. A 150-cm panel would hide the desk entirely — § 12's *Partition height* row is where that is decided |
| wall run `x608..616 y376..696` (vertical → PARTITION) | `X −753..−742 cm`, `Z 522..967 cm` | the cubicle's far side, left, past the partition |
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
| the extent's left edge (no author tile there) | a WALL at `X +103 cm` | the aisle's right side, 1 m off |
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
| `view` (the model, or `null`) — NEW | the SCREEN, attaching `doorView(stopRow, held)` where it composes `scene.ride` today (`floor-screen.js` `rideView()` / `#frame()`; `lobby-screen.js`'s equivalent). `stopRow` is `plates()`'s row whose `floor === stop`; `null` for the lobby and for a stop the stops no longer offer | `paintCab()` → `drawFloorView()`; the ride page, to repaint on arrival | never inferred by the painter from the page — the existing RED (*the view inferred from the page*) stays, and a new one plants `globalThis.location` in the model's resolution |

**Where the stop's data comes from, on each page.** The floor page holds its own floor's maps (§ 4.4's
floor row) and every theme module (`painter.js` `loadArt()`); the lobby holds the layout alone, no map, no
tileset and the house theme's palette only (`loadHousePalette()`). A ride to ANOTHER floor needs that floor's
maps on either page. So:

- **At the press (`opening`), the screen asks `Building#enterRooms()` for every stop's rooms not held at
  the version `/api/building` reported** — held by version, so the second ride on a page fetches nothing —
  and the lobby page constructs a `TilesetLoader` and loads the maps' tilesets as the floor page does. The
  population is every room the stops name less the page's own floor's — § 1's `installs` line prints the
  rooms, and each document's size is `wc -c resources/floor/default.tmj` for an unauthored room and
  `strlen($r->map)` over `Floors::all()` for an authored one (the two authored maps on 2026-11-08 were of
  the default's order, under a quarter of a megabyte each) — once per page, on a press the viewer has
  already made; the alternative
  — fetching at `choose`, the chosen floor only — leaves 1.25 s (`ridePhase('chosen')`: a fade and *Ride door
  motion*) between the request and the doors opening — and § 12's *Cab hold* row names the operator's round
  trip to the deployment as unmeasured from this host, so no figure here says that is enough. A press that
  fetches is the cheaper failure: the view is ready when the buttons appear.
- **The lobby loads the theme modules the registry names at the press too**, through `cab-paint.js`'s one
  dynamic-import site (`loadHousePalette()` generalised to `loadThemes()`, answering the registry and every
  module), so `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s walk still follows one declared loader
  — its needle text and its walk change with it (slice 2) to follow every `THEMES` entry, a strictly wider
  check than today's one module.
- **A model that arrives during a phase whose doors move repaints the cab once.** `wire/ride-page.js`
  `perform()` returns early on an unchanged phase today; it gains one more case — the phase is
  `entering`, `chosen` or `leaving` and `view.cab.view` is not the object last painted — and calls
  `paintCab()` again. `cabLeaves()` places each leaf's motion at the phase's elapsed time (`doorTiming()`'s
  negative delay), so the repaint lands mid-motion as the band's does (the existing *follow each phase
  without restarting* check is the precedent and the pin).

**The hop this depends on (canon #7).** The model is built from documents another party authored and the
server serves: the map's `desks` objects, `wall`/`accent` cells and `kind`s. The server DECLARES their
shape (`App\Floor\FloorMap` refuses a map the readers refuse — § 10.3's table; `verify-floor.py`), and the
client CHECKS nothing of it again: `mapDesks()`, `mapTiles()` and `tileRegions()` read what the floor page
already reads, and a map the floor page draws the door view draws. What neither end can verify locally is
named here: the operator's READING of a projection — whether a 120-cm partition and a door-derived scale
make the room the operator pictured — which only the sandbox shows (§ 6's by-hand row).

## 5. A floor with no layout data yet — the reachable routes, and the bare room

Two routes reach a stop whose model cannot be built, each named with the sequence a viewer performs:

- **R1 — the map has not answered.** Lobby page on a slow link (§ 12's *Cab hold* row measured the
  operator's round trip as the open term): press the elevator; the layout is held (the stops are listed),
  the press fired `enterRooms()` for every stop; press a floor's button inside ~1.3 s; `chosen` paints before
  that floor's map document (≈ 200 KB) has arrived. `Building#room(id)` answers `null` (never asked) or a
  record with no `held`.
- **R2 — the map request failed.** Any page: a room whose `GET /api/building/rooms/{id}/map` answered a
  non-200 holds F16's failure (`{ held, failure: { status } }`); the floor page draws that room's desks
  mapless. Reproducible in the probe with a scripted fetch answering 503; on the sandbox it has no switch —
  reaching it there takes a failing map route, which this design does not add.

In both, **the view is the stop's bare room**: the floor plane, far and side walls, ceiling and lights of
`OFFICE`'s size (520 × 900 × 280 cm — the shell `drawOfficeView()` draws today, kept for exactly this), in
the stop's theme palette, with **no desk, no wall run, no accent and no piece** — nothing of a layout the
client does not hold is drawn, which is § 13 decision 30's rule (a building composed from a failed fetch is
the wrong building drawn with confidence) applied to scenery. The theme is known from the layout entry
whatever the map did, so the bare room is still the cottage's or the studio's. When R1's map arrives the
repaint rule in § 4 replaces the bare room with the floor; R2's stays bare for the ride. The model carries
`bare: true` so the probe can tell the two renders apart; nothing on the page says so — the floor page's
F16 statement on arrival is the fact surface, and the door view carries no fact.

What is deliberately NOT defended: a stop with no layout at all. With no layout held there are no floor
stops (`plates()` answers `null` → the panel offers *Lobby* alone), so no floor view is asked for; and a
floor the layout removes mid-ride leaves the stops (`#stops()` re-derives every frame) and `stopRow` is
`null` → the bare room in the house theme, for a ride that will end on § 4.4's redirect anyway.

## 6. How the test pins it — a mutation that shows the wrong floor's layout or theme must red

The test is `TheElevatorIsAControlOnTheDrawingTest`'s shape (a `node` probe over the fake DOM, a PHP test
applying source mutations and asserting each check reds), extended with a **door-view leg**, plus one check
each in `TheFloorsRideIsNavigationTest` and the theme selftest.

**The fixture.** Two synthetic floors in one layout document, each one room with an 8-px-cell map the probe
builds inline (no live data; the shapes are chosen so A and B differ on every axis the design claims):

- **A** — key `alpha`, `theme` absent (house theme, studio): one `desks` slot LEFT of the seam
  (`x 200`, so its sprite is at `X < 0`), one vertical `wall` run RIGHT of the seam at `x 40..48`
  (`X ≈ +36..+47 cm`), no accent.
- **B** — key `bravo`, `theme: "cottage"`: one slot far LEFT (`x 900`, so its sprite lies outside the
  frustum at its depth and is culled), a `wall` run that is a horizontal interior PARTITION at `y 400` from
  `x 0..600` (crossing `X = 0` at `Z ≈ 556 cm`), the floor's cells all `accent`, and one `bookcase` tile at
  `x 300, y 80`. (No slot can lie wholly to the rider's right: the seam is 74 px from the extent's edge and a
  slot is 440 px wide.)

The probe drives `wire/ride.js`'s `Ride` with the floor page's constructor arguments from stop `alpha`:
`press → entered → shut → choose('bravo')`, reading `sceneRide()` at each step and resolving
`doorView()` with a scripted `Building` holding both maps and the tileset; then paints with `paintCab()`
(the module's and the painter's, as the `beyond` leg does) and reads back, under `.cab-beyond`:

| Reading | `entering` (doors on `alpha`) | `chosen` (doors on `bravo`) |
|---|---|---|
| `scene.ride.stop` | `'alpha'` | `'bravo'` |
| the root group's `--dv-floor` / `--dv-wall-top` | studio's `#d9c7a5` / `#eef0e0` | cottage's `#cbd3b3` / `#d7e6ee` |
| wall boxes | one, every projected `x` of its near face RIGHT of the doorway's centre line | one, its near face spanning the centre line, its projected top BELOW the horizon line `y = box.y + (door.h − eye) · ky` (a 120-cm cap seen from a 155-cm eye lies under the horizon; a 222-cm wall's top lies over it — the reading that tells a partition from a wall) |
| desk prisms | one, LEFT of centre | one, out of the frustum (culled → zero) |
| accent polygons / billboards | none / none | ≥ 1 / one `<image>` whose `href` equals `svgUri(cottage.scenery({kind:'bookcase', w:72, h:144, seed:'bravo', index}))` recomputed by the probe |
| the scenery census (`officeDefects`' rules) | no `text`, no `data-*`, no `aria-label`, no motion, no inline colour on any element — with two declared exceptions, each checked rather than skipped: the root group's `style` matches `^(--dv-[a-z-]+:#[0-9a-f]{6};)+$` and nothing else; an `<image>`'s `href` is a theme document the probe recomputed (any other `href` is a defect, as today) | |

**The REDs, each appended to `test_each_check_goes_red_against_the_defect_it_exists_to_catch`'s table
with a `doorViewDefects` reader**, and what each would otherwise let through:

| Mutation (the source line, and its replacement) | What it would ship |
|---|---|
| `wire/ride.js`: `stop: ride.current` (the key never following the choice) | the page's own floor behind the opening doors on every ride |
| `floor/door-view.js`: `X = (px.x − threshold.x) · SCALE` (the mirror dropped) | sola's wall on the LEFT |
| `floor/door-view.js`: the threshold at `extent.x` (the seam's 74 px dropped) | every wall and desk 103 cm off |
| `floor/door-view.js`: the palette from `themes[registry.HOUSE_THEME]` (the house theme for every stop) | aimla in studio colours |
| `floor/door-view.js`: a run touching an edge drawn as a WALL (`touches` for *long side lies on*) | the PM's cubicle wall as a 2.2-m wall hiding the PM's desk and monitor |
| `floor/door-view.js`: the model built while `held.map` is `null` (the bare flag dropped) | a floor drawn with confidence from no map (R2) |
| `floor/cab-paint.js`: `BEYOND.office` drawing `drawOfficeView` (the model ignored) | today's one office |
| `floor/cab-paint.js`: `paintCab()` reading `globalThis.location.pathname` to pick the model | the inference § 4.5 forbids |
| `floor/cab-paint.js`: the billboard's `href` from `scenery()` of the HOUSE theme | studio furniture on the cottage floor |
| `wire/ride-page.js`: the repaint-on-arrival case removed | R1's bare room never replaced while the doors open |
| `wire/ride-page.js`: the repaint on EVERY frame | the leaves restarted each frame — caught by the existing *follow each phase without restarting* reading over the cab's leaves |

And in `TheFloorsRideIsNavigationTest`: `scene.ride.stop` equals the stop the doors open on in every phase
of `RIDES` (RED: `stop: ride.current`), beside its `beyond` check. In `tools/floor-themes/selftest.mjs`:
every theme's `PALETTE.view` carries the house theme's key set (its drift leg — seen to red before the
cottage row lands). § 12's new rows are pinned by `test_green_section_12s_door_view_is_door_view`
(`SCALE`, the partition height, the room height derived from `BAND_H`).

**Seen to fail first (canon #9).** Slice 3's PR lands the probe's green readings and the RED table in one
change; the coder runs the RED table with the production line in place of each mutation and records the
red in the PR's audit trail, as the lobby view's PR did.

**By hand, on the sandbox** (`tools/design/floor-ride.browser.mjs` gains a `door-view` row beside
`through-doors`): ride from the lobby to each of sola, aimla and solo-agents with the doors held half open,
and read the three tables in § 3.5 against the screenshots. This is the reading neither end can verify
locally (§ 4's hop).

## 7. What could falsify it, and what is not defended

- **The scale reads wrong to the operator** (the room too large, desks too long). The figure is one § 12
  row with a stated derivation and two rejected alternatives; changing it moves nothing else. The partition
  height likewise.
- **A planned floor with a hallway and rooms at authored origins**: the composition is `placeRooms()`'s own,
  so the door view and the floor page agree by construction; the probe's fixture is unplanned (origins
  absent), and a planned fixture is a cheap addition the coder should make if `verify-floor.py`'s fixtures
  include one (`server/tests/Feature/Floor/fixtures/maps/` holds maps, not layouts).
- **The painter's-algorithm order** fails only for intersecting bodies, which the map cannot hold (F21,
  disjoint cells). Not defended further.
- **A huge map**: `tileRegions()` is already run per paint on the floor page over the same cells; the door
  view runs it once per model, cached per ride. Not defended further; the probe records the count.
- **Reduced motion**: every phase is a cut and the view is painted at entry as today. Nothing new.
- **A browser without `color-mix()`**: every new mixed rule is in `MIXED`, so `unmixed()` derives its
  fallback and the existing check holds it.

## 8. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last, each with its docs in the same round
(`FLOOR.md`'s amendments are in this commit; the coder updates the Appendix B row's marker and the AT list
as each lands).

1. **Hoist the primitives the view needs out of the modules the lobby page cannot load** (no behaviour
   change; every existing test green). `ELEVATOR` and a `thresholdOf(extent)` into `floor/floor-layout.js`
   (`scene.js` `backWall()` reads it from there, `threshold()` unchanged); `mapTiles()`, `tileLook()`,
   `tileRegions()` into `floor/map-tiles.js` (imports `floor-layout.js` and `tileset.js` only; `scene.js`
   imports and re-exports them so every importer finds them where they were); `svgUri` into `cab-paint.js`
   (`painter.js` re-exports). Run `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` to confirm the new
   modules import neither the log nor the set.
2. **The model.** `floor/door-view.js` `doorView(stopRow, held, themes)` → the frozen model of § 3.2 (cm,
   rider's frame, `bare` when any room's map is not held), with a `node` selftest over the two fixture floors
   of § 6 asserting the cm readings in § 3.5's shape; `PALETTE.view` in both themes and the selftest's drift
   leg; `wire/ride.js` `sceneRide()` adds `stop` (its RED in `TheFloorsRideIsNavigationTest`); `cab-paint.js`
   `loadThemes()` beside `loadHousePalette()` and the lobby walk's needles updated to follow every `THEMES`
   entry.
3. **The paint and the wiring.** `cab-paint.js` `drawFloorView()` (projection, culling, order, the bare
   room, the carriers and `MIXED` rows); `BEYOND.office` → it; `drawOfficeView()` reduced to the shell the
   bare room draws (its desks, plant, windows and sun gone — § 10.6 item 3). The floor screen attaches
   `view` where it composes `scene.ride`; `enterRooms()` for every stop at the press; the lobby screen the
   same plus its `TilesetLoader` and `loadThemes()` at the press; `ride-page.js`'s repaint-on-arrival case.
   The probe's door-view leg and the RED table (§ 6), `test_green_section_12s_door_view_is_door_view`, the
   browser tool's `door-view` row. § 4.5's text already describes this slice; Appendix B row 44 gets its
   landing marker. Title: `… (closes card#12987)`.

Slice 3 is the one the operator sees. If the operator's reading on the sandbox moves a figure, it is a § 12
row, not a redesign.

## 9. Open questions — only where the product intent is genuinely ambiguous

Listed in `FLOOR.md § 14` item 48 with a recommendation each; none gates slices 1–2.

1. **Billboards face the rider** (§ 3.4's stated simplification). *Alternative:* draw standing pieces as
   prisms in the view palette and lose the theme's drawings. **Recommendation: billboards** — the cottage's
   furniture is where "the cottage theme" is most legible, and the cute, whimsical direction tolerates a
   paper cut-out standing in a perspective room.
2. **Partition height 120 cm** — monitors show over the panels, desk tops do not. *Alternative:* 150 cm
   (every desk behind a panel hidden; the operator's "cubicle wall" read, nothing else), or 100 cm (desk
   tops visible). **Recommendation: 120 cm**, re-derived by the operator's reading of the sandbox.
3. **Prefetch every stop's maps at the press** (§ 4). *Alternative:* the chosen floor's at `choose`, with
   the bare room until it arrives. **Recommendation: at the press** — bounded by the building, once per
   page, and it is the only shape that has the view ready when the doors open on a slow link.

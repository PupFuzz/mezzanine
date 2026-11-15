# card#13033 — design and build plan: the restroom door set into its side wall, in 2-D and 3-D

**What this file is.** The design and the build plan for one report on one card, in the shape
`plan-12987-cab-door-view.md` set: written for the coder who implements it and the reviewer who attacks it. It owns
the *derivation*, the *measurement*, the *sequence* and the *acceptance list as observables*; it owns no rule. Every
rule lives in `FLOOR.md`, which this commit amends in place: § 6.7 item 2 (the facing, the side door's geometry and
drawing, the flag sign, the 3-D opening), item 3 rules 1 and 2 and the re-measured lay, item 4 (the walker faded at a
side door), § 12's *Restroom door*, *Restroom plates*, *Restroom threshold* and *Restroom lane* rows, and the two
`restroom-door` rows of § 10.6's theme tables. A reader who finds the two disagreeing trusts `FLOOR.md` and fixes this
file. When the build has landed this file is history.

**The operator's report**, verbatim (2026-11-14): *"In floor 3D view, the bathroom door placement doesn't make sense.
the door needs to be an entry into the wall. The same is actually true in 2D view (the door needs to be rotated 90
degrees)"*. **Decided before this plan**: the occupied sign is a FLAG SIGN projecting from the wall above the doorway at
right angles, carrying the pictogram and the vacant / occupied field, readable in 2-D and 3-D whether the leaf is open
or shut (operator, 2026-11-14); the facing is DERIVED from the wall the tile touches, computed once per door and read by
every consumer, no map property and no rotated tile (seat); 3-D cuts an opening in the side wall's cells where the door
spans it and the leaf moves in the wall's plane (seat).

## 1. The problem, in the product's terms

A door is drawn as a thing you walk INTO; this one is drawn as a thing standing beside a wall. On every map that places
a `restroom-door` the tile stands against the right wall over its whole height, and both renderers ignore the wall:

- **The tile touches the wall, measured.** A script over each map's tile layers (resolving GIDs through
  `resources/floor/tiles/floor-plane.tsx`) prints the door's rect and every `wall` cell within 16 px of it:
  `resources/floor/default.tmj` — door rect x 1,712–1,776, y 512–656, one wall column x 1,776–1,784 spanning it;
  `server/tests/Feature/Floor/fixtures/maps/lounge-floor.json` — the same cell, the same column;
  `fixtures/maps/restroom-corridor.json` — door x 1,712–1,776, y 360–504, the same column. `fixtures/maps/solo-cubicles.json`
  places NO door (`grep -c restroom-door` prints 0) — the coder's investigation listed it among the maps with one; FLOOR.md
  § 6.7 item 3 says only that it *fits* one. Population: three maps with a door, all `right`; none `left`, none `back`.
- **2-D draws it face-on.** `resources/floor/themes/studio/theme.js` `restroomDoor()` (L1099) and
  `resources/floor/themes/cottage/theme.js` `restroomDoor()` (L828) draw an upright frame, pediment and sill in the tile's
  64 × 144 box; `server/public/js/floor/painter.js` L1318–1353 draws the leaf as a panel over `d.recess` narrowing by
  `scaleX`, and the inside walker in a clip of that recess (L1002–1009). The threshold is under the tile's foot
  (`scene.js` `restroomDoor()`'s `thresholdOf()`, L1942–1966), the lane down from the foot (`restroomLane()`, L772).
- **3-D draws it standing in the room.** `floor/floor-world.js` L449–462 stands the leaf and sign rects "on the tile's
  face-on picture"; `floor3d/furniture.js` L352–357 places the theme's `restroom-door` part list (`pieces-3d.js`
  L125–137: a frame with its front toward `+z`) by a pure translation at the tile's foot centre — no `turn` — so the
  frame faces the viewer with the wall at its side; `floor3d/room-paint.js` L353–386 lays the leaf box across the
  recess in the room's `x` and the field in front of the frame. The wall's cells beside it stand unbroken
  (`floor-walls.js` `wallBodies()`): nothing is an opening.
- **The lattice treats the door as furniture.** `scene.js` L1350–1352 pushes every standing tile's rect, the door's
  included, into `obstacles`, so a 64 × 144 patch of floor beside the wall is blocked by a thing that should be in the
  wall.

The coder who investigated listed the face-on assumption at roughly eight sites and stopped. Every one is confirmed
above; its list missed `floor/door-view.js` L213 (the cab's door view draws the same piece through `sceneryInput()`),
and it miscounted the population by one map.

### The population the change must cover

- **Maps with a door**: the three files above, re-derived from each map's `furniture` layer holding GID 16 — the tileset's
  `firstgid` 1 plus tile id 15, the one tile whose `kind` is `restroom-door` (`gen-fx-snack.mjs`'s `RESTROOM_DOOR = 16`);
  `walk-probe.mjs`'s `restroom` answer prints `placed[]`.
- **Code that reads the door's rect or facing, or builds or reads an `inside` leg**:
  `grep -rn "RESTROOM_PLATES\|RESTROOM_RECESS\|restroomLane\|restroom_doors\|sceneryInput\|'inside'" server/public/js server/tests tools resources` —
  the sites § 3 lists, one per line of that grep, re-run on the branch before each slice closes. The pattern is the bare
  string `'inside'`, not `kind === 'inside'`: the first draft's pattern matched the READERS of the leg and missed its
  BUILDERS — `scene.js` L590 (`restroomHome()`), L1028, L2078, L2906 and L3045 all write `kind: 'inside'` (design review
  round 1, F1).
- **Tests and fixtures**: § 6.

## 2. The shape, and what was rejected

**Chosen: derive the facing from the wall, turn every rule with the door, and make the door an opening.** One
primitive, `floor/floor-walls.js`'s `restroomDoorGeometry(cell, g, walls, T)`, answers `{ facing, face, doorway, draw,
plates }` for a door cell from the floor's wall bodies; `map-tiles.js`'s `sceneryInput(cell, seed, geometry, view)` folds
it into the theme's input; the scene attaches it to each door; the painter, the world, the furniture and the room paint
read it. The threshold, lane and
`inside` leg are the existing rules rotated a quarter turn, with the door's own rect no longer an obstacle. Measured
(§ 4): every threshold opens, every route holds at `clear`, the spots flank the approach.

Rejected, each with its mechanism and cost:

- **Rotate the sprite 90°** (the report's literal words): an upright 64 × 144 picture seen edge-on is a 4 px sliver;
  nothing to read, nothing for the flag to hang on. The report's intent — a door *in* the wall — is met by drawing the
  doorway in the floor's own idiom (walls are plan strips, § 10.3) and hanging the one upright thing, the flag, where the
  3/4 view can see it.
- **A map property or a rotated tile for the facing**: ruled out by the seat; and `mapTiles()` strips the flip bits by
  § 10.6 item 6 (`splitGid`), so a rotated tile would be a second channel for one fact the wall already states.
- **Keep the tile as an obstacle** (the forecourt as laid): the door would block floor on the room side of a wall it is
  inside. Measured both ways (§ 4): dropping the rect keeps every route at `clear`; keeping it buys nothing.
- **A lane one body's height deep from the wall FACE** (the elevator's figure carried over literally): the first cell on
  the approach's axis at *Queue spacing* from the threshold is `44 + 75.5 = 119.5` px from the face, which on an 8 px
  grid is 124 — outside a 120 px lane — so such a lane could never move a waiter out of the exit path. Measured on a
  corridor fixture (§ 4): spot 1 (1,652, 628) with it or without it. *Elevator lane* measures from the THRESHOLD's line,
  and so does this design: wall-to-threshold plus one body's height, 164 px on the default.
- **A swinging leaf in 2-D** (the plan symbol, a bar turning about its hinge): more legible than a sliding bar, but a
  second door mechanism against the 3-D slide the seat ruled, and the flag's field carries the state the operator wants
  readable. § 7 Q2 records the seat's ruling.
- **The flag centred over the doorway**: on the shipped default its plate, 112–144 px over the doorway's centre line,
  stands at y 480–512, x 1,738–1,774 — over the low snack table's picture (rect x 1,728–1,776, y 432–496). Mounted over
  the NEAR jamb it stands at y 512–544 over floor. The near-jamb mount is a seat
  ruling, reversible (§ 7 Q1).
- **A walk THROUGH the wall** (the first two drafts: an `inside` leg with a `through` point and glides in and out, a 2-D
  clip at the wall line, a 3-D clipping plane, a re-timed leaf rule and a hoisted early-exit leg to carry it): design
  review round 2 returned six gating findings, four of them minted by that leg or by round 1's fixes to it — the stop
  signal canon #15 names, corrections adding mechanism to hold up one piece. The seat re-derived from the product: the
  operator asked for the door to sit in the wall, not for a walk through it. Dropped whole. The walker does at a side
  door exactly what it does at a back door on today's frames; the only difference is a fade in place of the covering
  leaf (§ 3.4).
- **A restroom closet beyond the wall**: beyond the right wall is outside the room's footprint. A closet means the map
  grows; the operator has not asked for one (§ 7 Q3).

## 3. The derivation — every site, and what each does

### 3.1 The geometry primitive (`floor/floor-walls.js`, with its plates in `floor/map-tiles.js`)

`restroomDoorGeometry(cell, g, walls, T)` — `cell` a `mapTiles()` door cell of the grid `g`, placed on the FLOOR's grid
as `at = cellOf(cell, g, T)` (the same placement every wall body has), `walls` the floor's `wallBodies()` (every map on
the floor, each `wall` cell placed by `cellOf()`, the top row and the cells under a slab already left out), `T` the
floor's cell (`floorCell()`: one cell SIZE per floor; rooms on different sizes are not defended, which is what the
`floor-walls.js` header says). It lives in `floor-walls.js`, beside the bodies it reads, because placing the cell needs
`cellOf()` and `floor-walls.js` already imports `map-tiles.js` (built so; the first drafts put it in `map-tiles.js`, which
would have made every caller place the cell itself or closed an import cycle). `RESTROOM_PLATES`, `SIDE_DOOR_BOX`,
`sceneryInput()` and `obstaclesOf()` stay in `map-tiles.js`:

- `facing`: the tile's rows run from `top = at.y + T − cell.h` to `at.y + T` — `cellOf()` answers the cell's ANCHOR row,
  the foot cell the author painted (`floor-walls.js` L68's `y`, `map-tiles.js` L116's placement), not the drawn tile's
  top (design review round 2, F1: written over `[at.y, at.y + cell.h)` the test counted the six rows below the foot and
  answered `back` for every shipped door). `right` if the DISTINCT rows `y` of the wall bodies with `x === at.x + cell.w`
  and `y` within `[top, top + cell.h)` number `cell.h ÷ T` (distinct, because two layers may paint one cell twice); else
  `left` by the same test at `x === at.x − T`; else `back`. Both → `right`. **Re-measured over the rule as written**
  (the review's `r2/facing.mjs` with the corrected top): `default.tmj` right 18 of 18, `lounge-floor.json` 18, the
  committed `restroom-corridor.json` 18, the mirrored lounge (§ 6) left 18 — every shipped side door `right`, the mirror
  `left`. Reading the wall BODIES rather than the door's own map's
  drawn pixels (design review round 1, F7) means a wall painted on another room's grid, or on a dragged layer, counts
  exactly as the 3-D floor and the door view stand it, and a wall cell under a desk's slab does not. `buildScene()`
  therefore computes `wallBodies()` BEFORE the scenery loop (today L1422–1434 run after L1386–1410; the two move).
- **The cab's door view draws the same door** (`floor/door-view.js` L205–220 stands every standing kind as an upright
  billboard from `sceneryInput()`; design review round 1, F3). Seat ruling, specified in full (round 2, F6): a side
  door is TWO billboards and a cut. ⚠ **Not built in slice 1, and open (§ 7 Q4)**: `floor/cab-paint.js`'s `drawBody()`
  draws a billboard as an axis-aligned image from `at(x1, y2, z1)` to `at(x2, y1, z1)` — it can stand an image only
  FACING the eye, so an elevation with `x1 = x2` projects to nothing, and SVG cannot warp an image onto the wall's
  perspective trapezoid. Slice 1 leaves `door-view.js` as it was (a side door's tile is billboarded face-on, today's
  picture) and builds the two documents below, which the themes' selftest draws. (1) **The elevation** (removed after
  slice 3, which drew the doorway as polygons and left it no caller; § 7 Q4) —
  `scenery(sceneryInput(cell, seed, geometry, 'elevation'))`, a `cell.w × cell.h` (64 × 144) box: the frame as today's
  face-on picture (6 px jambs, the 10 px head with its top FLUSH with the box's top — design review round 3, item 7: the
  first drafts' 22 px under the top left a see-through slot over the head, under a lintel from the tile's height — the
  4 px sill, the recess between), no pediment, no flag — stood in the wall's plane,
  `x1 = x2 = X(x_w)`, `z1 = Z(y_c + 32)` (the near jamb), `z2 = Z(y_c − 32)`, `y1 = 0`, `y2 = cell.h × SCALE`, sorted at
  the doorway's rows. (2) **The flag** — `scenery(…, 'flag')`, a 36 × 32 box facing the eye (a plate at right angles to a
  side wall faces the band): `x1 = X(x_w − 2)`, `x2 = X(x_w − 38)` (`left`: `X(x_w + 2)`, `X(x_w + 38)`), `z1 = z2 =
  Z(y_c + 32)`, `y1 = 112 × SCALE`, `y2 = 144 × SCALE`, sorted at the near jamb's row. (3) **The cut** — the door view's
  `wallBodies()` loop (L189–199) raises `y1` to `cell.h × SCALE` for each wall body of the door's column whose row lies in
  the doorway's span (a lintel, as 3-D's), AND the FOOTPRINT's side-wall row strips behind them (L177–185, one strip per
  footprint edge and row, `y1: 0, y2: ROOM_H`) get the same `y1` for those rows — without that the cut would open onto a
  solid strip. Measured nowhere yet — the door view has no restroom fixture; `door-view-leg.mjs` gains one and its
  `hrefsOf()` passes the `view` (§ 6), once Q4 is answered.
- `back`: `{ facing, face: null, doorway: null, draw: null, plates: RESTROOM_PLATES.back }` — today's values, so every
  existing consumer is unchanged for a `back` door.
- `right` (`left` mirrors every `x`): `face`, the wall face `x_w = at.x + cell.w` (`left`: `at.x`), `foot = at.y + T` —
  the PLACED cell's, as the wall bodies are (the slice-2 review's gating finding: read off the drawn `cell`, a door layer
  dragged off the grid cut its doorway beside its wall column) — `y_c = foot − cell.w ÷ 2`; `doorway: { x: x_w, y: foot − cell.w, w: T, h: cell.w }` (the strip over the span;
  `left`: `x: x_w − T`);
  `draw: { x: x_w − 40, y: foot − 146, w: 48, h: 150 }`; `plates: RESTROOM_PLATES.side` — in the draw box's px: plate
  `(2, 2, 36, 32)`, sign `(9, 20, 22, 12)`, doorway `(40, 82, 8, 64)`, leaf `(42, 88, 4, 52)`; for `left`, each `x` is
  `48 − x − w`. The flag's plate stands 112–144 px over the foot line — its top at the tile's top, flush under the 3-D
  lintel, its bottom at the head's underside — so every renderer's flag is one figure (the first draft's 122–154 stood
  above a 144 px tile and could not be drawn in the door view's box). `RESTROOM_RECESS` stays the `back` recess and the
  leaf's 52 px span.
- `sceneryInput(cell, seed, geometry = null, view = 'plan')` adds `facing`, the plates and `view` to a side door's input
  (`w`, `h` become the draw box's for `plan`, the tile's for `elevation`, 36 × 32 for `flag`); a `back` door's input, and
  a door handed no geometry, is today's byte for byte. The `view` is part of the contract — its JSDoc names the three
  members (`DOOR_VIEWS`; `plan` and `flag` since the `elevation` view's removal, § 7 Q4) and the door view would be the
  only caller of the two it adds (round 2, F6). The scene hands
  the door's geometry; `door-view.js`, `door-view-leg.mjs`'s `hrefsOf()` and `door-view.selftest.mjs` hand none until Q4
  is answered, and so draw today's `back` picture; `tools/floor-themes/selftest.mjs` draws both facings in every view (`DOOR_VIEWS`).
- **`obstaclesOf(cells, standing, geometryOf)`** — the ONE statement of the walkable model's obstacle rule (every `wall`
  cell and every standing tile's rect, less a side door's): exported here and imported by `scene.js` and by
  `walk-probe.mjs`, whose four constructions it replaced; `verify-floor.py`'s Python copy keeps a drift check (§ 6;
  design review round 1, F5).

### 3.2 The scene (`floor/scene.js`)

- **Obstacles** (L1350): a door cell whose geometry is a side door is NOT pushed.
- **`restroomDoors[]`** (L1358): carries the geometry — `facing`, `face`, `doorway`, and the plates resolved to scene px
  (`sign`, `plate`, and a side door's `bar`, the leaf's rect: `leaf` is already the door's leaf TIMELINE once the screen
  merges it in, `floor-screen.js` L854). `recess` stays on every door, the tile's face-on recess, because the 3-D floor
  stands its leaf there until slice 2 turns the door into the wall.
- **`scenery[]`** (L1400): a side door's entry keeps the cell rect in `x, y, w, h` (the 3-D piece's size and foot) and
  adds `draw` (the painter positions the theme's document there) and `facing`.
- **`thresholdOf()`** (L1942): by facing — `back` as today; side: `cellAt(x_w − d − 1, y_c)` (mirrored for `left`), open
  at `clear`, else the search walks the row into the room cell by cell, no deeper than `walkerSize().h` from the face,
  and STOPS at the first cell blocked by anything other than the door's own wall (design review round 2, F8): a cell is
  wall-blocked when its centre lies within `walkerSize().w ÷ 2 + CLEARANCE.margin` of the face (the halo's own
  arithmetic — on the shipped default the five cells x 1,740–1,772); a blocked cell past that band is another piece's or
  a desk's, and the threshold is `null` rather than a cell on the far side of it. Measured: on every map that places a
  door the first open cell, x 1,732, follows five wall-blocked cells and nothing else, so no figure moves; the stop is
  what keeps a visitor from standing past a table that an author pushes against the approach.
- **`doorLane(foot, span, character, facing = 'back', depth = body.h)`** (L742) and `restroomLane(door, threshold,
  character)` (L772): a `back` door's lane and the elevator's are UNCHANGED — from the doorway's foot line down one
  `body.h` (design review round 3, item 8: the first drafts' *one body past the threshold's line* applied to a back door
  would have deepened it by the threshold's 4 px). A side door's runs from the wall FACE to one `body.h` past the
  THRESHOLD's line: the rect spans `x ∈ [thr.x − body.h, x_w]` (`right`) and `max(doorway.h, body.w + 2 × 8)` along the
  wall, centred on `y_c` — `restroomLane()` hands `doorLane()` the facing and that depth, so it takes the threshold and
  `placed[]` computes the threshold first; a side door with no threshold has no lane (and serves nobody).
  `elevatorLane()` passes nothing new.
- **The visit's legs, the leaf, the exit and the turn-round are UNTOUCHED** (the seat's ruling of 2026-11-14 after
  design review round 2, which returned six gating findings, four of them minted by the step-through leg the first two
  drafts added and by the fixes to it: *the operator asked for the door to sit in the wall, not for a walk through it*).
  The `inside` leg at L2078 keeps its shape (`{ kind: 'inside', start, frames, at: thr }`, `D.close + dwell + D.open`
  frames at the threshold), the leaf timeline its four moves, `occupied` and `claim` their frames; `restroomExit()`
  (L532), `restroomHome()` (L590), `tripStart()` (L610), `walkEffect()`'s lead leg (L1028), the intern leave (L2906),
  `poseOf()` (L2963) and `turnRound()` (L3045, L3112) are not edited; `floor/in-flight.js` is not edited. The leaf's
  timing is byte-identical to today for every facing. What differs at a side door is DRAWING ONLY (§ 3.4): the walker at
  the threshold fades out as the leaf closes and fades back in as it opens, in place of the recess clip the leaf used to
  cover. The one scene-side addition is the door's `facing` on `scene.restroom_doors[]`, which the painter reads.
  (The four hand-built `kind: 'inside'` early-exit legs at L590, L1028, L2906 and L3045 are identical and could be one
  `exitLeg()` — a pure consolidation with no behaviour change, left OUT of this plan as a separate refactor item.)

### 3.3 The route (`floor/route.js`)

Unchanged. `pointAt()` (L747) answers an `inside` leg at its threshold with `where: 'inside'`, as today; nothing in this
plan touches `route.js`.

### 3.4 The 2-D drawing — both themes and the painter

- **Themes** (`studio/theme.js` `restroomDoor(pen, w, foot, { sign, plate, facing, view, doorway, leaf })`,
  `cottage/theme.js` likewise; `scenery()` reads `facing`, `view`, `doorway` and `leaf` off its input, `back` and `plan`
  their defaults): `back` draws today's picture whatever the view. A side door is drawn by `view`, the third member of
  `sceneryInput()`'s contract (`view ∈ { 'plan', 'elevation', 'flag' }` as designed, `{ 'plan', 'flag' }` since the
  `elevation` view's removal, § 7 Q4; default `'plan'`; design review round 2, F6):
  **`plan`** (the floor page, in the draw box's px) — the wall strip's recess (dark, over the leaf's 52 px of the
  `doorway` rect), two jamb blocks in the frame colour at the span's ends over the strip, the sill as a 3 px strip just
  inside the room across the span, and the FLAG PLATE at `plate` with a 2 px bracket to the wall face; **`elevation`**
  (the cab's door view, a `cell.w × cell.h` box, 64 × 144) — the frame as today's face-on picture draws it, 6 px jambs,
  the 10 px head with its top flush with the box's top (round 3, item 7), the 4 px sill, the dark recess between, NO
  pediment and no flag
  (the flag faces the eye and is its own billboard); **`flag`** (the door view's second billboard, a 36 × 32 box) — the
  plate alone. ONE function draws the plate for `plan` and `flag`: the pictogram (the two figures the theme already
  draws) in its upper 18 px, the slider rim at `sign` in its lower half. Nothing else — no `<text>`, no light, nothing
  that moves (AT-D3-25's form checks). The two § 10.6 table rows are amended in this commit; their palette keys are
  unchanged (the drift leg holds table and module equal by key); `tools/floor-themes/selftest.mjs` draws every view
  per theme (§ 6).
- **Painter** (`painter.js` L1318–1353): per door by facing — `back` as today; side: the leaf a `rect` at `d.bar` in a
  `restroom-leaf restroom-bar` group with `transform-origin` at the back jamb's end (the doorway's far end, its top) and
  `scaleY` keyframes (`restroom-bar-n`, the same five levels, the same runs off the same `marks` loop), the field at
  `d.sign` as today; and in place of the recess clip, **the FADE**. The `inside` walker is for a side door not moved
  anywhere: its floor element (`fx-walker-shown`, in the floor stack, depth-sorted like every walker) is shown through
  its `inside` legs as through its other floor legs, standing at the threshold, and its glides sit inside one group the
  DOOR makes — `floor.restroom(at)` answers `{ clip, fade }`, the recess clip for `back` and for a side door
  `fade(parent, fx, legs)`, which makes that group under `parent` and gives it stepped OPACITY keyframes
  (`restroom-fade-n`, opacity `n ÷ 4`) EQUAL TO THE LEAF's OWN LEVEL — 0 shut … 4 open, read off the very `marks` /
  `leafRuns` loop the leaf's `scaleY` runs come from — on every frame range of the walker's `inside` legs (moved into the
  door's frames by `(started_at − doors_at) ÷ frame`), full opacity everywhere else. The group is pinned to the door's
  instant (`doors_at`, the leaf's own origin) beside the leaf, and re-pinned whenever the depth reconcile moves the
  walker's node. So the hide and the fade come from ONE source on the same frames: a whole visit fades out as the leaf
  closes and in as it opens, and every early exit — `restroomHome()`, `tripStart()`, the intern leave, `turnRound()` —
  hands its `inside` lead the door's own exit timeline (the leaf reversing from where it is), which the fade follows.
  (Design review round 3, item 1: the first drafts generalised `laptopOver()` into a `fadeOver()` of fixed runs, 1 → 0
  over `D.close` frames from `inside.start` and 0 → 1 over the last `D.open`; a recall during the dwell opens the leaf
  from the exit leg's first frame, and those fixed runs would fade the walker OUT while the leaf opened. `laptopOver()`
  and its callers are untouched.) No clip at the wall line, no glide, no change to any leg: the stylesheet (L107–111)
  gains only the `scaleY` and the opacity keyframes.
- **Marker SVG** (`resources/floor/tiles/floor-plane/restroom-door.svg`): still 64 × 144; the lower 64 px band is
  marked as the doorway (a dashed band labelled `doorway` with arrow-heads to both side edges) over the faint upper
  outline, so an author reads *paint this cell against the wall the door is in*. Its `docs/ATTRIBUTION.md` row (L95) is
  re-hashed with the new date; `bin/asset-provenance.py` holds it.

### 3.5 The world and the 3-D room

- **`floor-world.js`** L449–462: per door by facing. `back` as today. Side: `wall_x` (world `x` of the face),
  `jambs: [z_back, z_near]`, `leaf: { x: wall_x + T ÷ 2 ÷ UNIT, z: z of y_c − 26, span: 0.52, y0: 0.05, h: 1.07, pocket:
  'back' }`, `flag: { x0: wall_x, out: −1 | +1, z: z_near, y0: 1.12, h: 0.32, w: 0.36 }` (its top at the tile's height,
  flush under the lintel; its bottom at the head's underside), `field` the sign rect lifted onto the flag (`x` from the
  face by 0.09–0.31, `y` 1.14–1.26 — the 2-D field's y 530–542 over the foot line at 656, which the slice-1 scene
  already hands the 3-D floor as `sign`; the first drafts' 1.30–1.42 was not that rect: design review round 3, F10).
  **Walls** (L497–506): a cell of the door's wall column
  whose `y` lies in the doorway's span gets its EXISTING `box.y` raised from 0 to `RESTROOM_DOOR.h ÷ UNIT` (1.44 — a
  lintel; the box already carries `y`, nothing new is minted — design review round 1, F10) and `box.h` the rest to its
  top, 0.16 for an `edge` wall; a `partition` (0.864 u) shorter than the door contributes no body there. **Pieces**
  (L507–516): a side door's piece has `at` at `(x_w, y_c)` lifted, `turn: −π ÷ 2` for `right`, `+π ÷ 2` for `left`, and
  `facing` for the theme.
- **`furniture.js`** placements (`placementsOf()`, L228–236 as of this reading; the snack slice moves it into
  `furniture-placements.js` — re-read on dev, § 8): a `piece3d` placement honours `turn` (`makeRotationY` before the
  translation) and hands `facing` to `piece3d(kind, { w, h, facing })`; the one-geometry-per-size grouping key
  (`${p.w}×${p.h}`, L347) gains `facing`, since a `right` and a `left` door of one size are two part lists (F9). The
  flag's parts are built from the shared part helpers the snack slice adds (`resources/floor/themes/parts-3d.js` on dev;
  F8), not from a second `block()`/`post()` pair in `pieces-3d.js`.
- **`pieces-3d.js`** `restroom-door` (L127): with `facing` a side door's frame has its jambs at `±w ÷ 2` in `x`, head and
  no pediment above the lintel line, and the FLAG at the near-jamb end — a plate `0.01 × 0.32 × 0.36` from `y` 1.12 to
  1.44, flush under the lintel, projecting toward `+z` (the room, after the turn), the pictogram's two figures as
  spheres, a box and a cone on BOTH faces in `P.figure`; the recess block is dropped (the wall's cut is the recess).
- **`room-paint.js`** `placeWall` (L92): translates to `box.y` (today's literal `0`) and scales to `h`. **The cut-away**
  (`applyCutaway()`, L100–135): a lowered wall's goal is `CUTAWAY_H` (0.15 u) reached over `CUTAWAY_MS` (150 ms) at the
  rate `(full − CUTAWAY_H) ÷ CUTAWAY_MS`; a lintel's goal when lowered is height 0 — it stands above 0.15, so a stub of
  it would float — and its rate `full ÷ CUTAWAY_MS`, so it drops in the same 150 ms as the wall it sits in; raised, it
  returns to `full` at the same rate. **When a lintel lowers** (design review round 2, F11): `cutaway()` (`view-limits.js`
  L119) answers the ids whose boxes the eye→target segment meets, and a lintel's 0.16 u box is met far less often than
  the column it sits in, so a lintel is lowered whenever ANY wall body of its door's column (`world.restroom_doors[].wall`
  names the column's cell ids) is in the cut set — the eye looking through that wall sees the whole doorway open, never a
  floating lintel over a lowered wall. `standRestroom` / `placeRestroom` (L353–386): side
  leaf box at `leaf` scaled along `z` from the back jamb by `shut`; field box on the flag, `0.012` thick. The browser
  leg's fixed-term formula (`floor3d.browser.mjs` L87, `2 × m.restroom`) is unchanged — one leaf, one field per door.

### 3.6 The 3-D walker (`floor3d/body-paint.js`, `floor3d/floor-paint.js`)

**Unchanged.** `c.mesh.visible = p !== null && p.where === 'floor'` (L315) hides every `inside` walker today, for every
facing, and keeps doing so: with no walk into the wall there is nothing to clip and no clipping plane
(`renderer.localClippingEnabled` is not touched; `floor-paint.js` L78's renderer is constructed as it is). The thought
bubble's visibility follows the mesh's (`b.thought.object.visible = b.creature?.mesh.visible ?? false`, L247), so it is
hidden with the body the frame the `inside` leg begins and shown again the frame the walk home begins — confirmed by
reading L247 against L315: the bubble needs no change. The 3-D side door therefore shows the walker standing at the
threshold while the leaf opens, gone through the whole `inside` leg — the closing, the dwell and the exit opening — and
standing there again from `home.start`, the walk home's first frame (design review round 3, F13: the first drafts said
*as it opens*, but `where === 'inside'` holds through the exit opening). The 2-D fade reaches full opacity on the same
frame, so the two renderers agree at both ends of the leg and differ only in between, where 2-D steps with the leaf and
3-D is hidden (a 3-D fade would be a new mechanism; the seat's ruling takes the hide as it is).

## 4. Measurement — the lay on every map, with the instrument

The design's probe (scratch, over the repository's `route.js`, `scene.js`, `map-tiles.js`, `tileset.js`,
`desk-layout.js`, mirroring `walk-probe.mjs`'s `floorOf()`): `lattice()` with the door's rect dropped, the side
threshold, the side lane, `spotsNear()` with the threshold and both snack spot 0s as `clear` points and the lane as
`avoid`, `route()` from every desk anchor (seat lattice) and stool foot (intern lattice). **Control**: with the rect
kept and the face-on rule it re-printed every figure § 6.7 item 3 ratified for the default (threshold (1,732, 660);
spots (1,660, 684), (1,668, 604), (1,580, 676), (1,636, 524); 2,076 / 1,556 / 1,163 / 2,035 / 1,101 / 769 px). The build
turns this into `walk-probe.mjs`'s facing-aware `restroom` and `walks` answers and commits nothing from the scratch.

| Map | facing, doorway | threshold | lane (one body past the threshold) | spots 1–4 (px from the threshold) | routes to the threshold |
|---|---|---|---|---|---|
| `default.tmj` | `right`, y 592–656 | (1,732, 628) | x 1,612–1,776, y 582.25–665.75 | (1,676, 572) 79.2; (1,668, 676) 80.0; (1,628, 508) 158.8; (1,588, 676) 151.8 — the same four under a 120 px lane | desks 2,000 / 1,480 / 1,087 / 1,987 / 1,467 / 947 px, all `clear`; every stool `clear`; to the snack spot 0s 217.6 / 130.2 |
| `lounge-floor.json` | `right`, y 592–656 | (1,732, 628) | the same | (1,676, 572) 79.2; (1,660, 668) 82.4; (1,700, 500) 131.9; (1,620, 516) 158.4 — spot 2 is (1,652, 644) under a 120 px lane | 1,461 / 965 / 2,008 / 1,453 / 957 px, all `clear`; every stool `clear` |
| `restroom-corridor.json` as committed | `right`, y 440–504 | (1,732, 476) | x 1,612–1,776, y 430.25–513.75 | with the lane (1,676, 420); (1,700, 348); (1,724, 556); (1,620, 364); without it (1,676, 420); (1,652, 492); (1,700, 348); (1,724, 556) — the lane moves spot 2, not spot 1 | all `clear` |
| `restroom-corridor.json` RE-CUT (§ 6) | `right`, y 592–656 | (1,732, 628) | as the default | measured by the build with both snack spot 0s as `clear` points: without the lane **(1,652, 628)** 80.0 — on the axis, inside it — then (1,644, 548), (1,612, 476), (1,612, 364); with it **(1,676, 572)** 79.2, (1,628, 508) 158.8, (1,612, 364) 290.0, (1,540, 340) 346.1 (the design's scratch run, without the spot 0s, put spot 1 at (1,684, 564), within *Queue spacing* of the low table's spot 0 (1,708, 500)) | 2,005 / 1,485 / 1,092 / 1,992 / 1,472 / 952 px, all `clear`; every stool `clear` |

Two placements of the doorway along the wall were measured — at the tile's foot end (chosen) and centred on the tile
(threshold (1,732, 588), spots (1,676, 644), (1,636, 524), …) — both open everywhere; the foot end keeps the author's
cell as the doorway's mark and keeps the threshold 130 px from the low table's spot 0 rather than 91. Two lane depths
were measured — one body's height from the face (120 px) and one body's height past the threshold (164 px): on the
default they place the same spots, on the lounge they differ at spot 2 only, and on the re-cut corridor the shallow lane
cannot move the axis spot at all (§ 2), so the deeper one — *Elevator lane*'s own derivation, from the threshold's line
— is the rule. **Correction found in passing**: with the door's rect kept and the face-on rule, the probe re-printed
every ratified figure of § 6.7 item 3's Option A but desks 4 and 5's route lengths, which print 2,063 and 1,543 px in
the committed `walk-probe.mjs` `walks` answer today against the ratified 2,035 and 1,101 (measured 2026-11-02); FLOOR.md
is corrected in this commit. The design review's bisection places the change at card#12513 (`116e9a35` → `18e5d1b2`,
the seat leaving its chair sideways or from behind — § 13 row 107, whose own text names the detour it removed), which
rewrote `route.js`'s desk cells; this plan's author did not re-run that bisection and cites the review's.

## 5. The flag sign, the plates and the door motion — figures

In § 12's rows. Scene px on the shipped default: flag plate x 1,738–1,774, y 512–544; field x 1,745–1,767, y 530–542;
leaf x 1,778–1,782, y 598–650; doorway strip x 1,776–1,784, y 592–656. The leaf's motion is *Restroom door motion*'s
4 / 1 / 4 frames unchanged, and so is every leg; a side door's walker's opacity through its `inside` legs is the leaf's
own level on every frame (§ 3.4).

## 6. Tests, fixtures and tools — what is regenerated, and the RED each must keep

- **`ACharacterTakesARestroomBreakTest`** — its § 12 reader (`section12()`) reads the rewritten *Restroom door* row (its
  drawing box), the *Restroom plates* row's `back` and side rects and the *Restroom lane* row's `back` and side figures
  (design review round 3, item 4: the design commits had broken all three regexes);
  `test_green_section_12s_figures_are_the_scenes` holds `RESTROOM_PLATES.back` and `.side` to § 12 and the default's door
  to `right` and its drawing box. The test's threshold restatement (`thresholdOf()`) and lane (`laneOf()`) are by facing,
  so every visit and lane GREEN reads the side rule; `test_green_the_restroom_lane_is_section_12s` holds the side lane to
  § 12's 83.5 × 164 and, over `back-visit`, the `back` lane to its unchanged 83.5 × 120 (round 3, item 8).
  `test_red_the_walker_through_the_door` keeps its RED exactly as it is. New:
  - **GREEN — the door in its wall** (`wallDoorDefects()`): the default's door faces `right`, its threshold is § 12's
    (1,732, 628), its plate, sign and bar are § 12's side plates in the drawing box, its theme document is drawn in that
    box, and a `facing: left` tile property (`walk-probe.mjs`'s `door_properties`) leaves it `right`. **REDs**, each
    planted in the shipped tree: the door kept as furniture (`obstaclesOf()` keeping a side door's rect → no threshold,
    the search stopping at the tile's halo, x 1,732, 44 px from the face); the facing counted from the foot cell (`top =
    at.y` → `back`); the facing from a property (the primitive reading `cell.properties.facing` → `left`).
  - **GREEN — the timeline is the formula on every run** (`timelineDefects()`, round 3, item 3, replacing *the
    regenerated fixture's legs equal today's*, which could not pass once the routes moved): over every run of
    `fx-restroom`, every whole visit's four leaf moves, `occupied`, `claim`, its door stand's and `inside` leg's lengths
    stand at today's offsets from its `atDoor`, `inside` and `home` starts. **RED**: the claim held a frame longer.
  - **GREEN — the fade** (`fadeDefects()`, round 3, item 2), through the shipped painter over a fake DOM
    (`restroom-paint-probe.mjs`): on `idle-visit` (the whole visit) and on `recall-inside` (a recall during the dwell,
    whose exit hands the walker the door's reversing timeline), every walker standing at the side door through an
    `inside` leg is on the floor stack, never clipped, its floor element shown through the leg, and its opacity at every
    frame from the paint on is the leaf's level through its `inside` legs and full elsewhere, the fade pinned to the
    leaf's instant; on `back-visit` the walker is in the recess clip, never faded. **RED — a fade on fixed frames**: the
    first drafts' 1 → 0 over the leg's first `D.close` frames and 0 → 1 over its last `D.open` → `recall-inside` reds and
    `idle-visit` does not, which is why the recall is in the GREEN.
  - **Byte-identical on the `back` fixture** (round 3, item 3's second check) — measured at the build, not a test: the
    tree before this slice and the tree after it replay `back-visit` through the floor screen, and every frame's A26
    effects (legs, leaf, `occupied`, `claim`, frames, threshold, lane, spot) and every door's timeline and rects are the
    same bytes over all 34 frames; the same comparison over `idle-visit` (a side door) differs, so it can red.
- **`TheLoungeFloorRoutesEveryWalkTest`** — re-derives the side threshold and holds the spots and every route of § 4's
  lounge row; `test_red_the_door_moved_under_a_desk` keeps its RED unchanged — it plants the door's cell inside desk 2's
  slot and reads the placement defect (*meets `desks` object id 2*), which no facing rule touches. New: **GREEN — the
  mirrored lounge** (`test_green_the_mirrored_lounge_sets_its_door_into_the_left_wall`): the lounge floor mirrored in x
  by `walk-probe.mjs`'s `mirror` faces `left`, its threshold and first four waiting spots are the lounge's mirrored
  ((52, 628), (108, 572), (124, 668), (84, 500), (164, 516)), and every walk to and from the door and the snack tables
  routes at `clear`. The elevator stands in the band, which the mirror does not move, so its walks are not this test's.
- **Fixtures for the two facings this tree has none of** (F9), both MEASURED: the `left` door is the lounge floor
  mirrored AT RUN TIME by `walk-probe.mjs`'s `mirror` (built so, in place of the first drafts' committed
  `lounge-floor-mirrored.json`: a committed mirror is a second copy of the lounge that drifts when the lounge is edited);
  and a `back` fixture **derived from card#12841's committed corridor** (round 2, F9) as a `gen-fx-snack.mjs` variant,
  `fixtures/maps/restroom-back.json`: its door five columns off the wall at `furniture` (209, 62) — tile x 1,672–1,736,
  y 360–504, touching no wall — facing `back`, face-on threshold **(1,716, 508)**, every desk routing to it at `clear`
  (2,230 / 1,710 / 1,377 / 2,217 / 1,403 / 883 px), and the run `back-visit` over it, which the lane and fade GREENs read.
- **`walk-probe.mjs`** — `restroom` and `walks` modes derive the threshold by facing (`thresholdOn()`, the test's
  restatement of § 12's rule), answer the door's `facing` (and in `restroom` its `face`, drawing box and the obstacle
  list's length); EVERY construction of an obstacle list in the file — `floorOf()`'s, the `snack` mode's, the `restroom`
  mode's and the `spots` mode's — is REPLACED by `read()`, which imports `map-tiles.js`'s `obstaclesOf()` and
  `floor-walls.js`'s `restroomDoorGeometry()`; `git grep -n "'wall' ||" -- server/tests/Feature/Floor/walk-probe.mjs`
  answers nothing. The probe reads no lane of its own: its `restroom` mode takes the lane the test's `laneOf()` derives.
- **`tools/design/verify-floor.py` G-scenery leg 4** — derives the facing from the map's `wall` cells beside the tile,
  the threshold by facing with the stop rule (round 3, item 6: the rule now in all three copies — `scene.js`,
  `walk-probe.mjs`, `verify-floor.py`), and drops a side door's rect from `blocking` as `obstaclesOf()` does; its status
  line prints the facing, the threshold and the blocking count. The DRIFT CHECK in `verify-design-docs.selftest.py`
  runs `walk-probe.mjs`'s `restroom` mode over the default and holds all three equal to leg 4's (default: `right`,
  (1,732, 628), 304), with a plant — a side door's rect left in leg 4's list — seen to move the reading. The selftest's
  leg-4 plant (the door moved five rows down) no longer reds under the side rule; it is replaced by a `wall` column at
  **x 1,712** (col 214) from row 70 to the front edge. Measured with `walk-probe.mjs`'s `walks` and `walls` under the
  stop rule: columns at x 1,688, 1,696, 1,704, 1,712 and 1,720 each leave no threshold, x 1,680 leaves (1,732, 628) open
  (the first drafts' *x 1,704 does NOT* was measured without the stop). 1,712 is chosen because only there does the red
  depend on the stop: without it the search walks past the column to (1,668, 628), outside its halo; a column at 1,688
  reds either way.
- **`floor-world-probe.mjs`** — the `restroom` leg holds the lintel cells, the leaf box, the flag and the field in the
  world against the scene's rects and facing; its control still reds over scenes with no door. **New RED**: the lintel's
  `box.y` left at 0 → a wall cell stands through the doorway.
- **`TheFloorIsTheScenesThirdRendererTest`** — its plant table (L113–115) gains *the piece placed without its turn* and
  *the lintel left solid*; nothing for the walker or the bubble, which are unchanged (§ 3.6).
- **`door-view-leg.mjs`** — once § 7 Q4 is answered: `hrefsOf()` (L199–201) passes the `view`, and the leg gains a
  restroom door fixture (F3, F6): the elevation in the wall's plane, the flag billboard facing the eye at the near jamb,
  the wall bodies over the doorway and the footprint's side strips on those rows carrying the lintel `y1`; REDs: the
  billboard stood face-on at the tile (today's placement); the side strip left whole behind the cut; the flag's href
  drawn from the `plan` document. Not in slice 1.
- **`tools/floor-themes/selftest.mjs`** — the restroom door set into the right wall and into the left, each in every
  view of `DOOR_VIEWS` (`plan` in the drawing box, `flag` at 36 × 32; `elevation` at 64 × 144 until § 7 Q4's removal), each held textless and well-formed as
  every scenery document is, through `sceneryInput()`.
- **`gen-fx-snack.mjs`** — the `restroom-corridor` variant re-cut as § 4's last row: the door kept at `furniture` (214, 81),
  the snack tables kept, `walls` painted at row 72 over cols 200–204 and 216–221 and at row 83 over cols 200–221, the left
  lamp at `furniture` (197, 82) (GID 9) removed — the variant's `from` guard holds each cell's current GID, so a wrong
  cell throws rather than silently painting floor. `restroom-sealed` RE-WALLED (round 3, item 10): its wall along y 560
  ran to the door's tile, cols 201–213, and with the tile no obstacle the forecourt opened above it, so every seat routed
  to the threshold at `feet` (measured); the run now reaches the right wall, cols 201–221, and no desk routes.
  `restroom-one-spot` re-measured and KEPT: one waiting spot, (1,668, 676), every desk at `snug`. The `restroom-back`
  variant and its `back-visit` run are new. `fx-restroom.json` regenerated (`--check` first: `fx-restroom` and the three
  maps moved, `fx-snack` did not).
- **`gen-fx-turn-back.mjs`** — `leave-inside` re-run over the new route; `AMainAgentTurnsBackToItsDeskTest`'s leaf
  comparison (L506–507) reads the moves unchanged.
- **`NoAnimationFiresWithoutItsEventTest`, `ALayoutActFiresNoAnimationTest`, `DrivesTheDeskFloor`, `AnInternRidesTheElevatorTest`,
  `AWalkerKeepsClearOfWallsAndFurnitureTest`** — read the fixtures; re-run, no edit expected.
- **`tools/design/door-view.selftest.mjs`** — the new `sceneryInput()` arguments at L163/193 (geometry and `view`), once
  § 7 Q4 is answered; unchanged in slice 1, its calls handing no geometry and drawing the `back` picture the view draws.
- **`tools/design/floor3d.browser.mjs`** — the `restroom` rows read the flag's field; the fixed term is unchanged.
- **`FLOOR.md`** landing with the slices: AT-D3-33's *the visit* GREEN and seventh RED, AT-D3-35's fixture sentence,
  AT-D3-38's restroom GREEN and seventeenth RED; § 13 row 121, § 14 item 50 (§ 7's questions and their answers),
  Appendix B row 58; § 10.6 item 6's `restroom-door` sentence (L8113).

## 7. Operator questions — each with context, an example per option, and the recommendation

1. **Where the flag hangs.** Context: the operator ruled a flag over the doorway; the design mounts it over the NEAR
   jamb because a centred flag covers the low snack table's top-right on the shipped default (measured, § 2). Option A,
   near jamb: on the default the plate stands at y 512–544 over floor; a walker at the threshold covers its lower half
   while the leaf opens and as it fades. Option B, centred: the plate at y 480–512 over the table's cups, always. **Recommendation: A**, as a seat
   ruling the operator can reverse on reading the sandbox.
2. **The leaf in 2-D — RULED by the seat: a sliding bar.** The floor's walls are plan strips, so a leaf in the wall's
   plane is a 4 px bar, sliding into the wall toward the back jamb (the elevator's motion, one mechanism with 3-D); the
   state is read off the flag's field. The swinging plan-symbol bar was weighed and not taken: a second mechanism, and in
   3-D a leaf swinging out of the wall against the seat's ruling. Reversible by the operator on reading the sandbox.
3. **What is behind the door — RULED by the seat: nothing.** The right wall is the room's edge, and the walker does not
   pass it (it fades at the threshold, § 3.4): the door is an entry into the wall, as asked, and no closet is drawn. A
   closet — a small room beyond the wall on a grown map, every room drawn from the default growing — is a later card if
   the operator asks for one after reading the floor.
4. **The doorway in the cab's door view** (opened by the build of slice 1). Context: § 3.1's elevation is an image stood
   in the side wall's plane, and `cab-paint.js` can stand an image only facing the eye (`drawBody()` projects a
   billboard's two corners at one depth); SVG cannot warp an image onto the wall's perspective trapezoid. Today the view
   still billboards the side door face-on at its tile, beside the wall — the report's picture, in the small view through
   the cab's open doors. Option A, the doorway as POLYGONS: the cut (lintel and side strips, § 3.1 (3)), the recess a
   dark polygon in the wall's plane over the doorway's span and the jambs in the wall's colour, plus the eye-facing flag
   billboard — every surface the view draws is already a polygon in its own classes, so this is one more body kind in
   `cab-paint.js` and no theme document (the `elevation` view would go unused, and was removed once slice 3 landed). Option B, an image in the wall's plane
   approximated by an affine skew to the near jamb's height, clipped to the projected trapezoid — a new drawing primitive,
   and inexact where the wall recedes steeply, which is where the doorway sits (beside the eye's line). Option C, leave
   the door view as it is until the 3-D floor is the view the operator reads. **Recommendation: A**, as slice 3, after
   slice 2. **Answered A; built as slice 3 (§ 8).** The `elevation` view it left without a caller is removed:
   `DOOR_VIEWS` and the themes' `restroomDoor()` carry `plan` and `flag` alone (`FLOOR.md` Appendix B row 65).

## 8. The slice plan, for the coder

Each slice one PR to `dev`, `card#13033 [no-close]: …`, with `docs/changelog.d/13033-<slug>.md`; every slice: its plants
seen red, the probe's figures on the card.

**Sequencing is against `dev`, never against a snapshot of the sibling worktrees** (design review round 1, F8). The
first draft listed what `wt-13033-cab`, `-chars`, `-snack` and `-eye` touched; within hours that list was false twice
over (the chars tree had gone from clean to editing `painter.js` L1011–1046, `floor-world.js`, `body-paint.js` and
`desk-layout.js`; the snack tree had edited `cottage/theme.js` and added `themes/parts-3d.js`, then emptied). All four
land on `dev` before slice 1 starts. So **each slice begins by re-reading `dev`** — `git fetch origin dev && git log
--oneline <this plan's base>..origin/dev -- <the slice's files>` for the files § 3 names, and `git diff <base>..origin/dev
-- <file>` for each hit — and records on the card what moved under this plan's line citations (every `L…` in § 3 is a
reading of `92a8b651` and is expected to drift). If a sibling has not landed by then, the coder reads that worktree's
`git status --short` and `git diff` at that moment rather than this file, and lands after it where a file overlaps.
What this plan can say durably: the files slice 1 edits that the siblings have been seen to edit are `painter.js`,
`cottage/theme.js` and `studio/theme.js`; slice 2's are `floor-world.js`, `body-paint.js`, `floor-paint.js`,
`room-paint.js`, `furniture.js` (whose `placementsOf()` the snack slice moves to `furniture-placements.js`) and
`pieces-3d.js` (whose part helpers the snack slice moves to `parts-3d.js`, which the flag's parts then use).

1. **The door in the wall, in 2-D** — `floor-walls.js`'s `restroomDoorGeometry()` (the facing from the wall bodies at
   the tile's top), `map-tiles.js`'s `obstaclesOf()` and `sceneryInput()`'s geometry and `view` (§ 3.1); `buildScene()`'s
   walls computed before its scenery, the obstacles, doors, scenery, the threshold with its stop rule and the lane — no
   leg, no exit, no pose or turn is edited (§ 3.2); `route.js` untouched (§ 3.3); both themes' three views, the painter's
   side leaf bar and the fade from the leaf's own level, the stylesheet, the marker SVG and its attribution row (§ 3.4);
   `walk-probe.mjs` on the obstacle rule, `ACharacterTakesARestroomBreakTest` with its door-in-the-wall, timeline-formula
   and fade GREENs and their REDs, `TheLoungeFloorRoutesEveryWalkTest` with the mirrored lounge, the `back` fixture and
   its run, `verify-floor.py` leg 4 with the stop rule, its drift check and the x 1,712 plant, `gen-fx-snack.mjs`'s
   re-cut corridor, re-walled sealed map and regenerated `fx-restroom`, `gen-fx-turn-back.mjs` re-run, the themes'
   selftest (§ 6); `FLOOR.md`'s AT-D3-33, AT-D3-35, § 13 row 121, § 14 item 50 and Appendix B row 58. The door view's
   billboards and cut (§ 3.1) are NOT in it: § 7 Q4. One slice because a side threshold under a face-on picture is a
   wrong floor on the sandbox.
2. **The door in the wall, in 3-D** — `floor-world.js` (lintels on `box.y`, the piece's `turn` and `facing`, the leaf,
   flag and field boxes, the door's wall-column ids), the furniture placements with `turn` and the `facing` grouping key,
   `pieces-3d.js`'s side frame and flag from the shared part helpers, `room-paint.js`'s lintel placement and its cut-away
   with the column rule, side leaf and field (§ 3.5); `body-paint.js` and `floor-paint.js` untouched (§ 3.6);
   `floor-world-probe.mjs`, `TheFloorIsTheScenesThirdRendererTest`, `floor3d.browser.mjs` (§ 6); `FLOOR.md`'s AT-D3-38
   and the row markers. Lands after slice 1 and, like it, after re-reading `dev`.

3. **The door in the wall, in the cab's door view** (§ 7 Q4's option A) — `door-view.js` reads each door's geometry off
   the view's wall bodies, cuts § 3.1 (3)'s lintel (the column's cells and the footprint's side strips on the column's
   OUTER face — the strip a `right` door's column backs onto, `doorway.x + T`, or a `left` door's, `doorway.x`; a
   partition the lintel leaves nothing of dropped, as 3-D drops it) and closes the opening with a `doorway` row strip
   per doorway row at that outer face; the flag is § 3.1 (2)'s billboard. As built, the jambs are not bodies of their
   own: the wall cell past the doorway's far end shows its near face (the jamb the eye can see; the near jamb faces away
   from it), and `cab-paint.js` draws a body's underside where it stands clear of the floor above the eye (the head).
   The recess takes each theme's `restroom-door` recess colour through a `--dv-recess` carrier. The flag carries its rim
   and no field, the door view drawing no fact (`FLOOR.md` § 6.3). The `elevation` view, left with no caller, is
   removed (§ 7 Q4).

## 9. What could falsify it, and what is not defended

- **Rooms on grids of different cell SIZES on one floor**: `floorCell()` takes the first room's tile height as the floor's
  one cell, and the facing test counts wall-body rows in that cell; a room on a 16 px grid beside the door's would count
  half the rows. Not defended — `floor-walls.js`'s header states one cell size per floor and names the mixed case as
  undefended (`plan-12987` § 7). A wall on another room's grid of the SAME size beside the tile IS seen, since the test
  reads the floor's wall bodies, not the door's own map.
- **A door touching a wall that ends part-way up the tile**: `back`, drawn face-on beside a wall stub. Not defended; the
  rule is strict so the author's intent is never guessed. An author who wants a side door extends the wall.
- **A side door whose threshold row is blocked within 120 px** (a desk slot against the wall below the doorway): no
  threshold, the door serves nobody, § 5.5's line — the existing failure, reached by `test_red_the_door_moved_under_a_desk`.
- **A door whose tile touches the footprint's top row**: `wallBodies()` leaves that row out (the band stands there), so
  the facing test counts one row fewer than the tile's height and answers `back` — the door drawn face-on against the
  side wall, its threshold under the tile. Not defended: a 144 px tile reaching the top row stands its sign plate in the
  band, which no author does; it is named so a `back` door there is not read as the rule failing.
- **The flag's lower half covered by the walker at the threshold** while the leaf opens and as the walker fades (§ 6.7
  item 2): accepted and named.
- **The walker vanishes at a threshold 44 px from the wall.** With no walk into the wall, a side-door walker fades
  out standing in the room and reappears there; a viewer watching closely sees a person dissolve a body's half-width
  short of the doorway rather than pass it. Accepted by the seat's ruling, named here: the alternative was the walk
  through the wall and the five mechanisms it needed.
- **Hazards with a check**: the facing counted from the anchor row (*the facing counted from the foot cell* RED); the fade
  on fixed frames rather than the leaf's (*a fade on fixed frames* RED, over a recall during the dwell); the obstacle kept
  (*the door kept as furniture* RED); the facing read from anywhere but the wall (*the facing from a property* RED); the
  door's timeline moved (*the door timeline moved* RED); the lane measured from the face rather than the threshold
  (AT-D3-35 over the re-cut corridor: the second walker would wait at (1,652, 628), on the axis — the RED *the lane not
  passed* reds it, and `test_green_the_restroom_lane_is_section_12s` holds the 164 px); a threshold found past another
  piece (F8's stop, in all three copies; the selftest's x 1,712 plant reds only with it); the Python copy of the obstacle
  rule drifting from `obstaclesOf()` (the selftest's drift check and its plant).
- **At a side door the lane clears with the door** (measured at the build): the walker coming out crosses one body's
  height past the threshold in the step and closing frames at *Walk speed*, so `laneFrame()` lands one frame after the
  door frees on the re-cut corridor. AT-D3-35's Second RED (*released as the leaf closes*) therefore cannot red there —
  measured: no 60 Hz instant of overlap — and runs over `lane-two-at-the-back-door` (the `back` corridor, three instants).
- **What the build did NOT cover, named**: the cab's door view (§ 7 Q4); the 3-D figures, which are lifts of the 2-D
  ones and slice 2's, held by `floor-world-probe.mjs`; and how the fade LOOKS — the probe reads the written keyframes,
  not a rendered frame (the screenshots on the card are the eye's check).

## 10. Seat rulings in this plan, reversible by the operator

The doorway at the tile's foot end (§ 4); the flag over the near jamb, 112–144 px over the foot (§ 7 Q1); the sliding
2-D leaf (§ 7 Q2); a `back` door drawn face-on wherever a door touches no side wall; `right` on a tie; the lintel from
the tile's height; no closet (§ 7 Q3); the walker fades at the threshold rather than walking through the wall (the
seat's ruling after review round 2), its opacity the leaf's own level through its `inside` legs (§ 3.4, design review
round 3).

## 11. Design review round 2 — what the ruling dissolved, and what survives

Round 2 of the design review returned six gating findings on `76c21dd7`; four of them (2–5) and one minor (10) were
minted by the step-through leg or by round 1's fixes to it. The seat's ruling drops that leg; the coordinator's message
carries the findings by number, and each is stated here by the mechanism it stood on, which this revision removes:

- **2–5 dissolve.** Everything they could attack is gone: the `inside` leg's `through`, `step_in` and `step_out` and
  `pointAt()`'s glide (§ 3.3 now says `route.js` is untouched); `exitLeg()` and the re-timed leaf rule (`restroomExit()`,
  `restroomHome()`, `tripStart()`, `poseOf()` and `turnRound()` are not edited — § 3.2); the 2-D clip at the wall line
  and its approach box (§ 3.4 draws a fade on today's `inside` element); the 3-D clipping plane, the per-walker material
  clone, `localClippingEnabled` and the bubble's special case (§ 3.6: `body-paint.js` hides the inside walker as it does
  today, and the bubble follows the mesh). A finding that named a frame, a timing, a clip or a plane of that leg has no
  subject left.
- **10 dissolves** with them — it concerned the same leg's drawing.
- **1, 6, 7, 8, 9, 11, 12 survive and are folded**: the anchor row (§ 3.1, measured on every map); the door view's
  elevation, flag, `view` contract and side strips (§ 3.1, § 3.4, § 6); `walk-probe.mjs`'s four obstacle constructions
  (§ 6, by the grep predicate); the threshold search's stop (§ 3.2); the `back` fixture's source map and measured
  threshold (§ 6); the lintel lowered with its column (§ 3.5); the citations (re-read on `92a8b651` throughout).

## 12. Design review round 3 — folded at the build of slice 1

Round 3 returned ten items; the build of slice 1 applied each and corrected this file and `FLOOR.md` in the same commits:

1. The fade is the leaf's own level, from the leaf's own runs, on a group the door owns and pins (§ 3.4); `laptopOver()`
   and its callers are untouched. The round's wording, *opacity `1 − leafLevel`*, has the sign of `leafLevel()`'s
   convention backwards — the level is OPENNESS (`leafLevel()` answers 0 shut, 1 open; the leaf's `scaleX` is 0.12 at 4)
   — so the walker's opacity EQUALS the level: it is visible while the leaf stands open and hidden while it is shut, as a
   back door's leaf shows and covers its walker. The round's item 2 states it so.
2. The fade GREEN over a recall during the dwell, and the RED planting a fixed-frame fade (§ 6).
3. The *regenerated legs equal today's* check replaced by the formula over every run and the byte-identity of the `back`
   fixture's whole timeline (§ 6).
4. AT-D3-33's three § 12 regexes rewritten; the probe takes its lane from the test (§ 6).
5. The step-through text deleted; Q2 and Q3 recorded as ruled (§ 7).
6. The stop rule in `scene.js`, `walk-probe.mjs` and `verify-floor.py`; the drift check compares facing, threshold and
   count; the plant figures re-measured (§ 6).
7. The elevation's head flush with the box's top (§ 3.1, § 3.4) — built in both themes; the view was removed with no
   caller after § 7 Q4 was answered (A).
8. The back lane unchanged, foot to foot plus a body's height; the threshold-line clause a side door's alone (§ 3.2).
9. § 2's signatures and the flag figure corrected; the door on the footprint's top row named (§ 9).
10. `restroom-sealed` re-walled (§ 6).
